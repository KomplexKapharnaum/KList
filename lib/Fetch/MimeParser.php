<?php

namespace Fetch;

/**
 * Plain-PHP stand-in for the parsing half of PHP's imap extension.
 *
 * Works on the raw bytes of a message (what an IMAP server returns for BODY.PEEK[]) and yields the
 * same shapes the ext-imap functions gave Fetch, so Message, Attachment and MIME keep their logic:
 *
 *   structure()          ~ imap_fetchstructure()  (type/encoding ids, upper-case subtype, parameters)
 *   section()            ~ imap_fetchbody()       (section ids "1", "1.2", "2.1" …, raw part bytes)
 *   splitMessage()       ~ imap_fetchheader() + imap_body()
 *   headerObject()       ~ imap_rfc822_parse_headers()  (date, subject, to, cc, bcc, from, sender, reply_to)
 *   parseAddressList()   ~ imap_rfc822_parse_adrlist()
 *   mimeHeaderDecode()   ~ imap_mime_header_decode()
 *
 * Needs nothing beyond PHP core (pcre, standard); charset conversion stays with the callers (iconv).
 */
final class MimeParser
{
    const TYPES = array(
        'text' => 0, 'multipart' => 1, 'message' => 2, 'application' => 3,
        'audio' => 4, 'image' => 5, 'video' => 6, 'model' => 7,
    );

    const ENCODINGS = array(
        '7bit' => 0, '8bit' => 1, 'binary' => 2, 'base64' => 3, 'quoted-printable' => 4,
    );

    /**
     * Splits a raw message into its header block (up to and including the blank line, the bytes
     * imap_fetchheader returned) and its body (imap_body).
     *
     * @param  string $raw
     * @return array  [string $header, string $body]
     */
    public static function splitMessage($raw)
    {
        if (preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $cut = $m[0][1] + strlen($m[0][0]);

            return array(substr($raw, 0, $cut), (string) substr($raw, $cut));
        }

        return array($raw, '');
    }

    /**
     * Unfolds a header block into an ordered list of [name, value] pairs; names keep their case.
     *
     * @param  string $header
     * @return array
     */
    public static function parseHeaders($header)
    {
        $fields = array();
        foreach (preg_split('/\r?\n(?![ \t])/', rtrim($header, "\r\n")) as $line) {
            if (false === ($colon = strpos($line, ':')))
                continue;
            $value = preg_replace('/\r?\n(?=[ \t])/', '', substr($line, $colon + 1));
            $fields[] = array(rtrim(substr($line, 0, $colon)), trim($value));
        }

        return $fields;
    }

    /**
     * First value of a header field, case-insensitive, or null.
     */
    public static function headerValue(array $fields, $name)
    {
        foreach ($fields as $field)
            if (strcasecmp($field[0], $name) === 0)
                return $field[1];

        return null;
    }

    /**
     * The fields of imap_rfc822_parse_headers() that Fetch reads.
     *
     * @param  string $header
     * @param  string $defaultHost
     * @return \stdClass
     */
    public static function headerObject($header, $defaultHost = 'UNKNOWN')
    {
        $fields = self::parseHeaders($header);
        $object = new \stdClass();

        foreach (array('date' => 'date', 'subject' => 'subject', 'message_id' => 'message-id') as $prop => $name)
            if (null !== ($value = self::headerValue($fields, $name)))
                $object->$prop = $value;

        $addressFields = array('to' => 'to', 'cc' => 'cc', 'bcc' => 'bcc', 'from' => 'from',
            'sender' => 'sender', 'reply_to' => 'reply-to');
        foreach ($addressFields as $prop => $name) {
            $value = self::headerValue($fields, $name);
            if (null !== $value && '' !== $value)
                $object->$prop = self::parseAddressList($value, $defaultHost);
        }

        return $object;
    }

    /**
     * RFC 5322 address list → c-client address objects: {mailbox, host, personal?}. A group opens
     * with {mailbox: <group name>} and closes with an empty object, as c-client reports them.
     *
     * @param  string $list
     * @param  string $defaultHost
     * @return array
     */
    public static function parseAddressList($list, $defaultHost = 'UNKNOWN')
    {
        $tokens = self::tokenizeAddresses($list);
        $result = array();
        $phrase = array();
        $current = null;
        $inAngle = false;
        $angle = '';
        $bare = '';
        $comment = null;

        $flush = function () use (&$result, &$phrase, &$current, &$angle, &$bare, &$comment, $defaultHost) {
            $spec = $angle !== '' ? $angle : $bare;
            if ($spec === '' && empty($phrase))
                return;
            if ($spec === '') {      // a bare word with no @, c-client takes it as a local mailbox
                $spec = implode(' ', $phrase);
                $phrase = array();
            }
            $spec = preg_replace('/^@[^:]*:/', '', $spec); // obsolete source route
            $at = strrpos($spec, '@');
            $address = new \stdClass();
            $address->mailbox = $at === false ? $spec : substr($spec, 0, $at);
            $address->host = $at === false ? $defaultHost : substr($spec, $at + 1);
            if (!empty($phrase))
                $address->personal = implode(' ', $phrase);
            elseif ($comment !== null && $comment !== '')
                $address->personal = $comment;
            $result[] = $address;
            $phrase = array();
            $angle = '';
            $bare = '';
            $comment = null;
        };

        foreach ($tokens as $token) {
            list($kind, $text) = $token;
            if ($inAngle) {
                if ($kind === 'special' && $text === '>')
                    $inAngle = false;
                elseif ($kind !== 'comment')
                    $angle .= $text;
                continue;
            }
            switch ($kind) {
                case 'comment':
                    $comment = $text;
                    break;
                case 'special':
                    if ($text === '<') {
                        $inAngle = true;
                    } elseif ($text === ',') {
                        $flush();
                    } elseif ($text === ':') {   // group start
                        $group = new \stdClass();
                        $group->mailbox = implode(' ', $phrase);
                        $result[] = $group;
                        $phrase = array();
                    } elseif ($text === ';') {   // group end
                        $flush();
                        $result[] = new \stdClass();
                    } elseif ($text === '@' || $text === '.') {
                        $bare .= (empty($phrase) ? '' : implode('', $phrase)) . $text;
                        $phrase = array();
                    }
                    break;
                default: // atom or quoted
                    if ($bare !== '' && substr($bare, -1) !== '@' && substr($bare, -1) !== '.') {
                        $phrase[] = $text;
                    } elseif ($bare !== '') {
                        $bare .= $text;
                    } else {
                        $phrase[] = $text;
                    }
            }
        }
        $flush();

        return $result;
    }

    /**
     * @return array of [kind, text], kind ∈ atom|quoted|comment|special
     */
    private static function tokenizeAddresses($s)
    {
        $tokens = array();
        $len = strlen($s);
        for ($i = 0; $i < $len;) {
            $c = $s[$i];
            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $i++;
            } elseif ($c === '"') {
                $text = '';
                for ($i++; $i < $len && $s[$i] !== '"'; $i++) {
                    if ($s[$i] === '\\' && $i + 1 < $len)
                        $i++;
                    $text .= $s[$i];
                }
                $i++;
                $tokens[] = array('quoted', $text);
            } elseif ($c === '(') {
                $depth = 1;
                $text = '';
                for ($i++; $i < $len && $depth > 0; $i++) {
                    if ($s[$i] === '\\' && $i + 1 < $len) {
                        $text .= $s[++$i];
                        continue;
                    }
                    if ($s[$i] === '(')
                        $depth++;
                    elseif ($s[$i] === ')' && --$depth === 0)
                        continue;
                    $text .= $s[$i];
                }
                $tokens[] = array('comment', $text);
            } elseif (strpos('<>,:;@.', $c) !== false) {
                $tokens[] = array('special', $c);
                $i++;
            } else {
                preg_match('/[^ \t\r\n"(<>,:;@.]+/A', $s, $m, 0, $i);
                $tokens[] = array('atom', $m[0]);
                $i += strlen($m[0]);
            }
        }

        return $tokens;
    }

    /**
     * RFC 2047 → list of {charset, text}; unencoded runs carry charset "default", and whitespace
     * between two adjacent encoded words is dropped (imap_mime_header_decode's contract).
     *
     * @param  string $text
     * @return array
     */
    public static function mimeHeaderDecode($text)
    {
        $words = array();
        $re = '/=\?([^?*]+)(?:\*[^?]*)?\?([QqBb])\?([^?]*)\?=/';
        $offset = 0;
        $lastEncoded = false;
        while (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1];
            $between = substr($text, $offset, $start - $offset);
            if ($between !== '' && !($lastEncoded && trim($between, " \t\r\n") === ''))
                $words[] = (object) array('charset' => 'default', 'text' => $between);

            $data = $m[3][0];
            if (strtoupper($m[2][0]) === 'B') {
                $decoded = base64_decode($data);
            } else {
                $decoded = quoted_printable_decode(str_replace('_', ' ', $data));
            }
            $words[] = (object) array('charset' => $m[1][0], 'text' => $decoded);
            $offset = $start + strlen($m[0][0]);
            $lastEncoded = true;
        }
        if ($offset < strlen($text))
            $words[] = (object) array('charset' => 'default', 'text' => substr($text, $offset));

        return $words;
    }

    /**
     * imap_fetchstructure()-shaped tree of a raw message.
     *
     * @param  string $raw
     * @return \stdClass
     */
    public static function structure($raw)
    {
        list($header, $body) = self::splitMessage($raw);

        return self::partStructure(self::parseHeaders($header), $body, 'text');
    }

    private static function partStructure(array $fields, $body, $defaultType)
    {
        $part = new \stdClass();

        $contentType = self::headerValue($fields, 'content-type');
        list($type, $subtype, $parameters) = self::parseContentType($contentType, $defaultType);

        $part->type = isset(self::TYPES[$type]) ? self::TYPES[$type] : 8;
        $cte = strtolower(trim((string) self::headerValue($fields, 'content-transfer-encoding')));
        // A multipart has no encoding in BODYSTRUCTURE: ext-imap reported 0 whatever the header says.
        $part->encoding = $cte === '' || $part->type === 1 ? 0 : (isset(self::ENCODINGS[$cte]) ? self::ENCODINGS[$cte] : 5);
        $part->ifsubtype = 1;
        $part->subtype = strtoupper($subtype);
        self::setOptional($part, 'description', self::headerValue($fields, 'content-description'));
        self::setOptional($part, 'id', self::headerValue($fields, 'content-id'));

        $disposition = self::headerValue($fields, 'content-disposition');
        if (null !== $disposition && '' !== trim($disposition)) {
            list($dtype, $dparams) = self::parseParameterized($disposition);
            $part->ifdisposition = 1;
            $part->disposition = strtoupper($dtype);
            $part->ifdparameters = empty($dparams) ? 0 : 1;
            if (!empty($dparams))
                $part->dparameters = $dparams;
        } else {
            $part->ifdisposition = 0;
            $part->ifdparameters = 0;
        }

        $part->ifparameters = empty($parameters) ? 0 : 1;
        $part->parameters = empty($parameters) ? new \stdClass() : $parameters;

        if ($part->type === 1) {
            $boundary = self::paramValue($parameters, 'boundary');
            $part->parts = array();
            foreach (self::splitMultipart($body, $boundary) as $chunk) {
                list($h, $b) = self::splitPart($chunk);
                $childDefault = strtolower($subtype) === 'digest' ? 'message/rfc822' : 'text';
                $part->parts[] = self::partStructure(self::parseHeaders($h), $b, $childDefault);
            }
        } else {
            $part->bytes = strlen($body);
            if ($part->type === 0)
                $part->lines = $body === '' ? 0 : substr_count($body, "\n") + (substr($body, -1) === "\n" ? 0 : 1);
            if ($part->type === 2 && strtolower($subtype) === 'rfc822') {
                list($h, $b) = self::splitMessage($body);
                $part->lines = substr_count($body, "\n");
                $part->parts = array(self::partStructure(self::parseHeaders($h), $b, 'text'));
            }
        }

        return $part;
    }

    private static function setOptional(\stdClass $part, $name, $value)
    {
        $flag = 'if' . $name;
        if (null !== $value && '' !== $value) {
            $part->$flag = 1;
            $part->$name = $value;
        } else {
            $part->$flag = 0;
        }
    }

    /**
     * @return array [type, subtype, parameters]; text/plain with charset us-ascii when absent, as an
     *               IMAP server reports it in BODYSTRUCTURE.
     */
    private static function parseContentType($value, $defaultType)
    {
        if (null !== $value && preg_match('#^\s*([^/;\s]+)\s*/\s*([^;\s]+)#', $value)) {
            list($full, $parameters) = self::parseParameterized($value);
            list($type, $subtype) = array_map('trim', explode('/', $full, 2));
            $type = strtolower($type);
            if ($type === 'text' && null === self::paramValue($parameters, 'charset'))
                $parameters[] = (object) array('attribute' => 'CHARSET', 'value' => 'us-ascii');

            return array($type, $subtype, $parameters);
        }
        if ($defaultType === 'message/rfc822')
            return array('message', 'RFC822', array());

        return array('text', 'PLAIN', array((object) array('attribute' => 'CHARSET', 'value' => 'us-ascii')));
    }

    /**
     * "value; a=b; c=\"d\"" → [value, [{attribute: A, value: b}, …]]; attribute names upper-cased
     * (c-client), values kept verbatim, RFC 2231 continuations NOT merged (neither did ext-imap).
     */
    private static function parseParameterized($value)
    {
        $value = preg_replace('/\([^()]*\)/', '', $value);
        $semi = strpos($value, ';');
        $head = trim($semi === false ? $value : substr($value, 0, $semi));
        $params = array();
        if ($semi !== false) {
            preg_match_all('/;\s*([^=;\s]+)\s*=\s*("((?:[^"\\\\]|\\\\.)*)"|[^;]*)/s', $value, $all, PREG_SET_ORDER);
            foreach ($all as $p) {
                $v = isset($p[3]) && $p[2] !== '' && $p[2][0] === '"' ? stripslashes($p[3]) : trim($p[2]);
                $params[] = (object) array('attribute' => strtoupper($p[1]), 'value' => $v);
            }
        }

        return array($head, $params);
    }

    private static function paramValue(array $params, $name)
    {
        foreach ($params as $p)
            if (strcasecmp($p->attribute, $name) === 0)
                return $p->value;

        return null;
    }

    /**
     * Body of a multipart → raw chunks between its boundaries (preamble and epilogue dropped).
     */
    private static function splitMultipart($body, $boundary)
    {
        if (null === $boundary || '' === $boundary)
            return array();
        $parts = preg_split('/(?:^|\r?\n)--' . preg_quote($boundary, '/') . '(--|)[ \t]*(?=\r?\n|$)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        // [preamble, (closer?, chunk)…]
        $chunks = array();
        for ($i = 1; $i < count($parts); $i += 2) {
            if ($parts[$i] === '--')
                break;
            $chunk = isset($parts[$i + 1]) ? $parts[$i + 1] : '';
            $chunks[] = preg_replace('/^\r?\n/', '', $chunk);
        }

        return $chunks;
    }

    /**
     * Part chunk → [header, body]; a part with no header starts with its blank line.
     */
    private static function splitPart($chunk)
    {
        if (preg_match('/^\r?\n/', $chunk, $m))
            return array('', substr($chunk, strlen($m[0])));

        return self::splitMessage($chunk);
    }

    /**
     * imap_fetchbody(): the raw bytes of a section ("1", "1.2", …), resolved the way c-client's
     * mail_body() resolved it before ext-imap fetched anything. A multipart takes the part's
     * number; any other part answers only to 1; a message/rfc822 section is the whole
     * encapsulated message and only it may be descended into, its numbers counting from its
     * body. A number past a leaf ("3.1.1" where 3.1 is text) is no section → "", as ext-imap gave.
     *
     * @param  string $raw
     * @param  string $section
     * @return string
     */
    public static function section($raw, $section)
    {
        list($header, $body) = self::splitMessage($raw);
        $fields = self::parseHeaders($header);
        $default = 'text';
        $numbers = explode('.', (string) $section);

        foreach ($numbers as $k => $n) {
            if (!ctype_digit($n) || (int) $n < 1)
                return '';
            $n = (int) $n;
            list($type, $subtype, $params) = self::parseContentType(self::headerValue($fields, 'content-type'), $default);
            if ($type === 'multipart') {
                $chunks = self::splitMultipart($body, self::paramValue($params, 'boundary'));
                if (!isset($chunks[$n - 1]))
                    return '';
                list($header, $body) = self::splitPart($chunks[$n - 1]);
                $fields = self::parseHeaders($header);
                $default = strtolower($subtype) === 'digest' ? 'message/rfc822' : 'text';
                list($type, $subtype) = self::parseContentType(self::headerValue($fields, 'content-type'), $default);
            } elseif ($n !== 1) {
                return '';
            }
            if ($k === count($numbers) - 1)
                break;
            if ($type === 'message' && strtolower($subtype) === 'rfc822') {
                list($header, $body) = self::splitMessage($body);
                $fields = self::parseHeaders($header);
                $default = 'text';
            } elseif ($type !== 'multipart') {
                return '';
            }
        }

        return $body;
    }
}
