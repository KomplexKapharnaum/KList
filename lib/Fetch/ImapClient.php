<?php

namespace Fetch;

/**
 * Raised for a failed IMAP exchange: a NO or BAD reply, a lost connection, an unreadable response.
 */
class ImapException extends \RuntimeException
{
}

/**
 * Plain-PHP IMAP4rev1 client (RFC 3501): the network half of what Fetch took from PHP's imap
 * extension. One stream, one command at a time, UID commands throughout.
 *
 *   connect()          opens ssl://host:993 (peer certificate verified) and reads the greeting
 *   login()            LOGIN, with a literal when the password cannot be quoted
 *   listMailboxes()    LIST, names decoded from modified UTF-7
 *   select()/examine() SELECT (read-write) / EXAMINE (read-only), returns EXISTS, UIDVALIDITY …
 *   create()/delete()  CREATE / DELETE a mailbox
 *   uidSearch()        UID SEARCH, returns UIDs
 *   fetch()/uidFetch() FETCH by sequence number / by UID, literals read byte-exact
 *   uidCopy()          UID COPY
 *   uidStore()         UID STORE (+FLAGS / -FLAGS / FLAGS)
 *   expunge()          EXPUNGE
 *
 * Needs nothing beyond PHP core (openssl for TLS, iconv for mailbox names).
 */
class ImapClient
{
    /** @var resource */
    private $stream;

    /** @var int */
    private $tagCounter = 0;

    /** @var string greeting line as the server sent it */
    public $greeting = '';

    /**
     * @param resource $stream an open, connected stream (tests pass one end of a socket pair)
     */
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            throw new ImapException('IMAP: no stream');
        }
        $this->stream = $stream;
    }

    /**
     * Opens a connection and reads the server greeting.
     *
     * @param  string $host
     * @param  int    $port    993 = implicit TLS; any other port is plain TCP
     * @param  int    $timeout seconds, for the connect and for every read after it
     * @return ImapClient
     */
    public static function connect($host, $port = 993, $timeout = 30, array $sslOptions = array())
    {
        $scheme = ((int) $port === 993) ? 'ssl' : 'tcp';
        $context = stream_context_create(array('ssl' => $sslOptions + array(
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        )));
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($scheme . '://' . $host . ':' . (int) $port, $errno, $errstr,
            $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            throw new ImapException("IMAP: cannot connect to $host:$port ($errno $errstr)");
        }
        stream_set_timeout($stream, $timeout);
        $client = new self($stream);
        $client->readGreeting();
        return $client;
    }

    /**
     * Reads the untagged greeting. "* OK" or "* PREAUTH" pass; "* BYE" (or anything else) throws.
     *
     * @return string the greeting line
     */
    public function readGreeting()
    {
        $line = rtrim($this->readLine(), "\r\n");
        if (!preg_match('/^\* (OK|PREAUTH)\b/i', $line)) {
            throw new ImapException('IMAP: server refused the connection: ' . $line);
        }
        $this->greeting = $line;
        return $line;
    }

    public function login($user, $password)
    {
        return $this->command('LOGIN', array($user, $password));
    }

    public function logout()
    {
        try {
            $this->command('LOGOUT');
        } catch (ImapException $e) {
            // the server may close before the tagged OK; nothing to recover
        }
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function noop()
    {
        return $this->command('NOOP');
    }

    /**
     * @return array list of array('name' => UTF-8 name, 'raw' => name on the wire,
     *               'delimiter' => string|null, 'attributes' => string[])
     */
    public function listMailboxes($reference = '', $pattern = '*')
    {
        $resp = $this->command('LIST', array(self::encodeMailbox($reference), self::encodeMailbox($pattern)));
        $boxes = array();
        foreach ($resp['untagged'] as $u) {
            if (strtoupper((string) $u[0]) !== 'LIST' || count($u) < 4) {
                continue;
            }
            $raw = (string) $u[3];
            $boxes[] = array(
                'name' => self::decodeMailbox($raw),
                'raw' => $raw,
                'delimiter' => $u[2],
                'attributes' => is_array($u[1]) ? $u[1] : array(),
            );
        }
        return $boxes;
    }

    /** @return array ('exists', 'recent', 'uidvalidity', 'uidnext', 'flags', 'readonly') */
    public function select($mailbox)
    {
        return $this->openMailbox('SELECT', $mailbox);
    }

    /** Read-only open: nothing is flagged by a later fetch, nothing can be expunged. */
    public function examine($mailbox)
    {
        return $this->openMailbox('EXAMINE', $mailbox);
    }

    public function create($mailbox)
    {
        return $this->command('CREATE', array(self::encodeMailbox($mailbox)));
    }

    public function delete($mailbox)
    {
        return $this->command('DELETE', array(self::encodeMailbox($mailbox)));
    }

    /**
     * @param  string $criteria raw search criteria, e.g. "ALL" or "UNSEEN"
     * @return int[]  UIDs, in the server's order
     */
    public function uidSearch($criteria = 'ALL')
    {
        $resp = $this->command('UID SEARCH ' . $criteria);
        $uids = array();
        foreach ($resp['untagged'] as $u) {
            if (strtoupper((string) $u[0]) === 'SEARCH') {
                foreach (array_slice($u, 1) as $n) {
                    $uids[] = (int) $n;
                }
            }
        }
        return $uids;
    }

    /**
     * FETCH by sequence set. Keys of the result are sequence numbers.
     *
     * @param  string $set   e.g. "1:20"
     * @param  string $items e.g. "(UID FLAGS)"
     * @return array  seq => array(ITEM => value); a BODY.PEEK[x] item comes back as BODY[x]
     */
    public function fetch($set, $items)
    {
        return $this->collectFetch($this->command('FETCH ' . $set . ' ' . $items), false);
    }

    /**
     * UID FETCH. Keys of the result are UIDs; a UID the box does not hold is simply absent.
     */
    public function uidFetch($set, $items)
    {
        return $this->collectFetch($this->command('UID FETCH ' . $set . ' ' . $items), true);
    }

    public function uidCopy($set, $mailbox)
    {
        return $this->command('UID COPY ' . $set, array(self::encodeMailbox($mailbox)));
    }

    /**
     * @param string   $set
     * @param string   $mode  "+FLAGS", "-FLAGS" or "FLAGS" (".SILENT" is appended)
     * @param string[] $flags e.g. array('\Deleted')
     */
    public function uidStore($set, $mode, array $flags)
    {
        return $this->command('UID STORE ' . $set . ' ' . $mode . '.SILENT (' . implode(' ', $flags) . ')');
    }

    public function expunge()
    {
        return $this->command('EXPUNGE');
    }

    /**
     * Sends one tagged command and reads up to its completion.
     *
     * @param  string $command the command name, plus any raw (already-safe) arguments
     * @param  array  $strings arguments sent as IMAP strings: quoted when safe, else a literal
     * @return array  ('status' => 'OK', 'text' => …, 'untagged' => list of token lists)
     * @throws ImapException on NO, BAD, BYE or a broken stream
     */
    public function command($command, array $strings = array())
    {
        $tag = sprintf('A%04d', ++$this->tagCounter);
        $line = $tag . ' ' . $command;
        foreach ($strings as $s) {
            $s = (string) $s;
            if (self::canQuote($s)) {
                $line .= ' "' . addcslashes($s, '"\\') . '"';
                continue;
            }
            // literal: send the size, wait for the continuation, then the bytes
            $this->write($line . ' {' . strlen($s) . "}\r\n");
            $this->awaitContinuation($tag);
            $line = $s;
        }
        $this->write($line . "\r\n");

        $untagged = array();
        while (true) {
            $raw = $this->readResponse();
            if (strncmp($raw, '* ', 2) === 0) {
                $tokens = self::parseUntagged($raw);
                if (strtoupper((string) $tokens[0]) === 'BYE' && strtoupper($command) !== 'LOGOUT') {
                    throw new ImapException('IMAP: server closed the connection: ' . rtrim($raw));
                }
                $untagged[] = $tokens;
                continue;
            }
            if (strncmp($raw, $tag . ' ', strlen($tag) + 1) === 0) {
                $rest = rtrim(substr($raw, strlen($tag) + 1), "\r\n");
                $sp = strpos($rest, ' ');
                $status = strtoupper($sp === false ? $rest : substr($rest, 0, $sp));
                $text = $sp === false ? '' : substr($rest, $sp + 1);
                if ($status !== 'OK') {
                    $verb = strtok($command, ' ');
                    throw new ImapException("IMAP $verb failed: $status $text");
                }
                return array('status' => $status, 'text' => $text, 'untagged' => $untagged);
            }
            // "+ …" outside a literal, or another tag: not ours to answer, skip it
        }
    }

    private function openMailbox($verb, $mailbox)
    {
        $resp = $this->command($verb, array(self::encodeMailbox($mailbox)));
        $info = array('exists' => 0, 'recent' => 0, 'uidvalidity' => null, 'uidnext' => null,
            'flags' => array(), 'readonly' => ($verb === 'EXAMINE'));
        foreach ($resp['untagged'] as $u) {
            $second = isset($u[1]) && is_string($u[1]) ? strtoupper($u[1]) : '';
            if ($second === 'EXISTS') {
                $info['exists'] = (int) $u[0];
            } elseif ($second === 'RECENT') {
                $info['recent'] = (int) $u[0];
            } elseif (strtoupper((string) $u[0]) === 'FLAGS' && is_array($u[1])) {
                $info['flags'] = $u[1];
            } elseif (strtoupper((string) $u[0]) === 'OK' && isset($u['code'])) {
                if (preg_match('/^UIDVALIDITY (\d+)/i', $u['code'], $m)) {
                    $info['uidvalidity'] = (int) $m[1];
                } elseif (preg_match('/^UIDNEXT (\d+)/i', $u['code'], $m)) {
                    $info['uidnext'] = (int) $m[1];
                }
            }
        }
        if (preg_match('/^\[READ-ONLY\]/i', $resp['text'])) {
            $info['readonly'] = true;
        }
        return $info;
    }

    private function collectFetch(array $resp, $byUid)
    {
        $out = array();
        foreach ($resp['untagged'] as $u) {
            if (!isset($u[1]) || !is_string($u[1]) || strtoupper($u[1]) !== 'FETCH' || !is_array($u[2])) {
                continue;
            }
            $attrs = array();
            $list = $u[2];
            for ($i = 0; $i + 1 < count($list); $i += 2) {
                $attrs[strtoupper((string) $list[$i])] = $list[$i + 1];
            }
            if ($byUid) {
                if (!isset($attrs['UID'])) {
                    continue; // an unsolicited flag update for some other message
                }
                $key = (int) $attrs['UID'];
            } else {
                $key = (int) $u[0];
            }
            $out[$key] = isset($out[$key]) ? $attrs + $out[$key] : $attrs;
        }
        return $out;
    }

    private function awaitContinuation($tag)
    {
        while (true) {
            $raw = $this->readResponse();
            if ($raw[0] === '+') {
                return;
            }
            if (strncmp($raw, $tag . ' ', strlen($tag) + 1) === 0) {
                throw new ImapException('IMAP: literal refused: ' . rtrim($raw));
            }
        }
    }

    private function write($data)
    {
        $len = strlen($data);
        for ($done = 0; $done < $len; $done += $n) {
            $n = @fwrite($this->stream, substr($data, $done));
            if ($n === false || $n === 0) {
                throw new ImapException('IMAP: connection lost while writing');
            }
        }
    }

    private function readLine()
    {
        $line = fgets($this->stream);
        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);
            throw new ImapException(!empty($meta['timed_out']) ? 'IMAP: read timed out' : 'IMAP: connection lost');
        }
        return $line;
    }

    private function readBytes($n)
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->stream, $n - strlen($buf));
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                throw new ImapException('IMAP: connection lost inside a literal');
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                if (!empty($meta['timed_out'])) {
                    throw new ImapException('IMAP: read timed out inside a literal');
                }
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /**
     * Reads one whole response: a line, plus every literal it announces and the line after each.
     * The result keeps the wire form ("{n}\r\n" + n bytes) for the tokenizer.
     */
    private function readResponse()
    {
        $raw = '';
        while (true) {
            $line = $this->readLine();
            $raw .= $line;
            if (!preg_match('/\{(\d+)\+?\}\r?\n$/', $line, $m)) {
                return $raw;
            }
            $raw .= $this->readBytes((int) $m[1]);
        }
    }

    /**
     * Tokenizes an untagged response ("* …"). Status responses (OK/NO/BAD/BYE/PREAUTH) keep their
     * text whole, with the bracketed response code under 'code'.
     */
    public static function parseUntagged($raw)
    {
        $body = rtrim(substr($raw, 2), "\r\n");
        if (preg_match('/^(OK|NO|BAD|BYE|PREAUTH)\b ?(.*)$/is', $body, $m)) {
            $tokens = array(strtoupper($m[1]), $m[2]);
            if (preg_match('/^\[([^\]]*)\]/', $m[2], $c)) {
                $tokens['code'] = $c[1];
            }
            return $tokens;
        }
        $pos = 2;
        return self::parseList($raw, $pos, null);
    }

    /**
     * Parses IMAP values from $raw at $pos until $close (")") or the end of the line.
     * Atoms come back as strings, NIL as null, quoted strings and literals as their bytes,
     * parenthesised lists as arrays. "BODY[HEADER.FIELDS (A B)]<0>" stays one atom.
     */
    public static function parseList($raw, &$pos, $close)
    {
        $out = array();
        $len = strlen($raw);
        while ($pos < $len) {
            $c = $raw[$pos];
            if ($c === ' ') {
                $pos++;
            } elseif ($c === "\r" || $c === "\n") {
                if ($close === null) {
                    $pos = $len;
                    return $out;
                }
                $pos++;
            } elseif ($c === ')') {
                $pos++;
                if ($close === ')') {
                    return $out;
                }
                throw new ImapException('IMAP: unbalanced ")" in response');
            } elseif ($c === '(') {
                $pos++;
                $out[] = self::parseList($raw, $pos, ')');
            } elseif ($c === '"') {
                $pos++;
                $s = '';
                while ($pos < $len && $raw[$pos] !== '"') {
                    if ($raw[$pos] === '\\' && $pos + 1 < $len) {
                        $pos++;
                    }
                    $s .= $raw[$pos++];
                }
                $pos++;
                $out[] = $s;
            } elseif ($c === '{' && preg_match('/\G\{(\d+)\+?\}\r?\n/', $raw, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                $out[] = (string) substr($raw, $pos, (int) $m[1]);
                $pos += (int) $m[1];
            } else {
                $start = $pos;
                $depth = 0;
                while ($pos < $len) {
                    $ch = $raw[$pos];
                    if ($ch === '[') {
                        $depth++;
                    } elseif ($ch === ']') {
                        $depth--;
                    } elseif ($depth <= 0 && ($ch === ' ' || $ch === '(' || $ch === ')' || $ch === "\r" || $ch === "\n")) {
                        break;
                    }
                    $pos++;
                }
                $atom = substr($raw, $start, $pos - $start);
                $out[] = (strtoupper($atom) === 'NIL') ? null : $atom;
            }
        }
        if ($close !== null) {
            throw new ImapException('IMAP: response ended inside a list');
        }
        return $out;
    }

    /** A quoted string may hold any 7-bit text but CR, LF and NUL. */
    private static function canQuote($s)
    {
        return !preg_match('/[\x00\r\n\x80-\xff]/', $s);
    }

    /**
     * UTF-8 mailbox name → modified UTF-7 (RFC 3501 §5.1.3), the form every command takes.
     */
    public static function encodeMailbox($name)
    {
        $out = '';
        $pending = '';
        $flush = function () use (&$out, &$pending) {
            if ($pending !== '') {
                $b64 = base64_encode(iconv('UTF-8', 'UTF-16BE', $pending));
                $out .= '&' . str_replace('/', ',', rtrim($b64, '=')) . '-';
                $pending = '';
            }
        };
        foreach (preg_split('//u', (string) $name, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $o = ord($ch);
            if (strlen($ch) === 1 && $o >= 0x20 && $o <= 0x7e) {
                $flush();
                $out .= ($ch === '&') ? '&-' : $ch;
            } else {
                $pending .= $ch;
            }
        }
        $flush();
        return $out;
    }

    /**
     * Modified UTF-7 mailbox name → UTF-8. A malformed shift sequence is left as it came.
     */
    public static function decodeMailbox($name)
    {
        return preg_replace_callback('/&([A-Za-z0-9+,]*)-/', function ($m) {
            if ($m[1] === '') {
                return '&';
            }
            $bin = base64_decode(str_replace(',', '/', $m[1]), true);
            if ($bin === false || strlen($bin) % 2 !== 0) {
                return $m[0];
            }
            $utf8 = @iconv('UTF-16BE', 'UTF-8', $bin);
            return $utf8 === false ? $m[0] : $utf8;
        }, (string) $name);
    }
}
