<?php

/*
 * This file is part of the Fetch package.
 *
 * (c) Robert Hafner <tedivm@tedivm.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fetch;

require_once __DIR__ . '/MimeParser.php';

/**
 * This library is a wrapper around the Imap library functions included in php. This class represents a single email
 * message as retrieved from the Imap.
 *
 * @package Fetch
 * @author  Robert Hafner <tedivm@tedivm.com>
 */
class Message
{
    /**
     * This is the connection/mailbox class that the email came from.
     *
     * @var Server
     */
    protected $imapConnection;

    /**
     * This is the unique identifier for the message. This corresponds to the imap "uid", which we use instead of the
     * sequence number.
     *
     * @var int
     */
    protected $uid;

    /**
     * The connection's IMAP client (Server::getImapStream()).
     *
     * @var ImapClient
     */
    protected $imapStream;

    /**
     * This as an string which contains raw header information for the message.
     *
     * @var string
     */
    protected $rawHeaders;

    /**
     * This as an object which contains header information for the message.
     *
     * @var \stdClass
     */
    protected $headers;

    /**
     * This is an object which contains various status messages and other information about the message.
     *
     * @var \stdClass
     */
    protected $messageOverview;

    /**
     * The whole message as the server sent it (BODY.PEEK[]): headers, structure and every part
     * are read from it, so a message costs one UID FETCH.
     *
     * @var string
     */
    protected $rawMessage;

    /**
     * This is an object which contains information about the structure of the message body.
     *
     * @var \stdClass
     */
    protected $structure;

    /**
     * This is an array with the index being imap flags and the value being a boolean specifying whether that flag is
     * set or not.
     *
     * @var array
     */
    protected $status = array();

    /**
     * This is an array of the various imap flags that can be set.
     *
     * @var string
     */
    protected static $flagTypes = array(self::FLAG_RECENT, self::FLAG_FLAGGED, self::FLAG_ANSWERED, self::FLAG_DELETED, self::FLAG_SEEN, self::FLAG_DRAFT);

    /**
     * This holds the plantext email message.
     *
     * @var string
     */
    protected $plaintextMessage;

    /**
     * This holds the html version of the email.
     *
     * @var string
     */
    protected $htmlMessage;

    /**
     * This is the date the email was sent.
     *
     * @var int
     */
    protected $date;

    /**
     * This is the subject of the email.
     *
     * @var string
     */
    protected $subject;

    /**
     * This is the size of the email.
     *
     * @var int
     */
    protected $size;

    /**
     * This is an array containing information about the address the email came from.
     *
     * @var string
     */
    protected $from;

    /**
     * This is an array containing information about the address the email was sent from.
     *
     * @var string
     */
    protected $sender;

    /**
     * This is an array of arrays that contains information about the addresses the email was sent to.
     *
     * @var array
     */
    protected $to;

    /**
     * This is an array of arrays that contains information about the addresses the email was cc'd to.
     *
     * @var array
     */
    protected $cc;

    /**
     * This is an array of arrays that contains information about the addresses the email was bcc'd to.
     *
     * @var array
     */
    protected $bcc;

    /**
     * This is an array of arrays that contain information about the addresses that should receive replies to the email.
     *
     * @var array
     */
    protected $replyTo;

    /**
     * This is an array of ImapAttachments retrieved from the message.
     *
     * @var Attachment[]
     */
    protected $attachments = array();

    /**
     * Contains the mailbox that the message resides in.
     *
     * @var string
     */
    protected $mailbox;

    /**
     * This value defines the encoding we want the email message to use.
     *
     * @var string
     */
    public static $charset = 'UTF-8';

    /**
     * This value defines the flag set for encoding if the mb_convert_encoding
     * function can't be found, and in this case iconv encoding will be used.
     *
     * @var string
     */
    public static $charsetFlag = '//TRANSLIT';

    /**
     * These constants can be used to easily access available flags
     */
    const FLAG_RECENT = 'recent';
    const FLAG_FLAGGED = 'flagged';
    const FLAG_ANSWERED = 'answered';
    const FLAG_DELETED = 'deleted';
    const FLAG_SEEN = 'seen';
    const FLAG_DRAFT = 'draft';

    /**
     * This constructor takes in the uid for the message and the Imap class representing the mailbox the
     * message should be opened from. This constructor should generally not be called directly, but rather retrieved
     * through the apprioriate Imap functions.
     *
     * @param int    $messageUniqueId
     * @param Server $mailbox
     */
    public function __construct($messageUniqueId, Server $connection)
    {
        $this->imapConnection = $connection;
        $this->mailbox        = $connection->getMailBox();
        $this->uid            = $messageUniqueId;
        $this->imapStream     = $this->imapConnection->getImapStream();
        if($this->loadMessage() !== true)
            throw new \RuntimeException('Message with ID ' . $messageUniqueId . ' not found.');
    }

    /**
     * This function is called when the message class is loaded. It loads general information about the message from the
     * imap server.
     *
     */
    protected function loadMessage()
    {

        /* First load the message overview information */

        if(!is_object($messageOverview = $this->getOverview()))

            return false;

        $this->subject = MIME::decode($messageOverview->subject, self::$charset);
        $this->date    = isset($messageOverview->date) ? strtotime($messageOverview->date) : false;
        $this->size    = $messageOverview->size;

        foreach (self::$flagTypes as $flag)
            $this->status[$flag] = ($messageOverview->$flag == 1);

        /* Next load in all of the header information */

        $headers = $this->getHeaders();

        if (isset($headers->to))
            $this->to = $this->processAddressObject($headers->to);

        if (isset($headers->cc))
            $this->cc = $this->processAddressObject($headers->cc);

        if (isset($headers->bcc))
            $this->bcc = $this->processAddressObject($headers->bcc);

        if (isset($headers->sender))
            $this->sender = $this->processAddressObject($headers->sender);

        $this->from    = isset($headers->from) ? $this->processAddressObject($headers->from) : array('');
        $this->replyTo = isset($headers->reply_to) ? $this->processAddressObject($headers->reply_to) : $this->from;

        /* Finally load the structure itself */

        $structure = $this->getStructure();

        if (!isset($structure->parts)) {
            // not multipart
            $this->processStructure($structure);
        } else {
            // multipart
            foreach ($structure->parts as $id => $part)
                $this->processStructure($part, $id + 1);
        }

        return true;
    }

    /**
     * This function returns an object containing information about the message. This output is similar to that over the
     * imap_fetch_overview function, only instead of an array of message overviews only a single result is returned. The
     * results are only retrieved from the server once unless passed true as a parameter.
     *
     * @param  bool      $forceReload
     * @return \stdClass
     */
    public function getOverview($forceReload = false)
    {
        if ($forceReload || !isset($this->messageOverview)) {
            // returns an array, and since we just want one message we can grab the only result
            $uid     = (int) $this->uid;
            $results = $this->imapStream->uidFetch($uid, '(UID FLAGS RFC822.SIZE BODY.PEEK[])');
            if (!isset($results[$uid]['BODY[]'])) {
                throw new \RuntimeException('Error fetching overview');
            }
            $item             = $results[$uid];
            $this->rawMessage = (string) $item['BODY[]'];

            // the imap_fetch_overview() fields: raw (still encoded) header values, size, flags 0/1
            list($header) = MimeParser::splitMessage($this->rawMessage);
            $fields   = MimeParser::parseHeaders($header);
            $overview = new \stdClass();
            foreach (array('subject', 'from', 'to', 'date', 'message_id', 'references', 'in_reply_to') as $name) {
                $overview->$name = MimeParser::headerValue($fields, str_replace('_', '-', $name));
            }
            $overview->size = isset($item['RFC822.SIZE']) ? (int) $item['RFC822.SIZE'] : strlen($this->rawMessage);
            $overview->uid  = $uid;
            $flags = array_map('strtolower', isset($item['FLAGS']) ? (array) $item['FLAGS'] : array());
            foreach (self::$flagTypes as $flag) {
                $overview->$flag = in_array('\\' . $flag, $flags, true) ? 1 : 0;
            }
            $this->messageOverview = $overview;
        }

        return $this->messageOverview;
    }

    /**
     * This function returns an object containing the raw headers of the message.
     *
     * @param  bool   $forceReload
     * @return string
     */
    public function getRawHeaders($forceReload = false)
    {
        if ($forceReload || !isset($this->rawHeaders)) {
            // raw headers (since imap_headerinfo doesn't use the unique id)
            list($this->rawHeaders) = MimeParser::splitMessage($this->getRawMessage());
        }

        return $this->rawHeaders;
    }

    /**
     * This function returns an object containing the headers of the message. This is done by taking the raw headers
     * and running them through the imap_rfc822_parse_headers function. The results are only retrieved from the server
     * once unless passed true as a parameter.
     *
     * @param  bool      $forceReload
     * @return \stdClass
     */
    public function getHeaders($forceReload = false)
    {
        if ($forceReload || !isset($this->headers)) {
            // raw headers (since imap_headerinfo doesn't use the unique id)
            $rawHeaders = $this->getRawHeaders();

            // convert raw header string into a usable object
            $headerObject = MimeParser::headerObject($rawHeaders);

            // to keep this object as close as possible to the original header object we add the udate property
            if (isset($headerObject->date)) {
                $headerObject->udate = strtotime($headerObject->date);
            } else {
                $headerObject->date = null;
                $headerObject->udate = null;
            }

            $this->headers = $headerObject;
        }

        return $this->headers;
    }

    /**
     * This function returns an object containing the structure of the message body. This is the same object thats
     * returned by imap_fetchstructure. The results are only retrieved from the server once unless passed true as a
     * parameter.
     *
     * @param  bool      $forceReload
     * @return \stdClass
     */
    public function getStructure($forceReload = false)
    {
        if ($forceReload || !isset($this->structure)) {
            $this->structure = MimeParser::structure($this->getRawMessage());
        }

        return $this->structure;
    }

    /**
     * This function returns the message body of the email. By default it returns the plaintext version. If a plaintext
     * version is requested but not present, the html version is stripped of tags and returned. If the opposite occurs,
     * the plaintext version is given some html formatting and returned. If neither are present the return value will be
     * false.
     *
     * @param  bool        $html Pass true to receive an html response.
     * @return string|bool Returns false if no body is present.
     */
    public function getMessageBody($html = false)
    {
        if ($html) {
            if (!isset($this->htmlMessage) && isset($this->plaintextMessage)) {
                $output = nl2br($this->plaintextMessage);

                return $output;

            } elseif (isset($this->htmlMessage)) {
                return $this->htmlMessage;
            }
        } else {
            if (!isset($this->plaintextMessage) && isset($this->htmlMessage)) {
                $output = preg_replace('/\s*\<br\s*\/?\>/i', PHP_EOL, trim($this->htmlMessage) );
                $output = strip_tags($output);

                return $output;
            } elseif (isset($this->plaintextMessage)) {
                return $this->plaintextMessage;
            }
        }

        return false;
    }

    /**
     * This function returns the plain text body of the email or false if not present.
     * @return string|bool Returns false if not present
     */
    public function getPlainTextBody()
    {
        return isset($this->plaintextMessage) ? $this->plaintextMessage : false;
    }

    /**
     * This function returns the HTML body of the email or false if not present.
     * @return string|bool Returns false if not present
     */
    public function getHtmlBody()
    {
        return isset($this->htmlMessage) ? $this->htmlMessage : false;
    }

    /**
     * This function returns either an array of email addresses and names or, optionally, a string that can be used in
     * mail headers.
     *
     * @param  string            $type     Should be 'to', 'cc', 'bcc', 'from', 'sender', or 'reply-to'.
     * @param  bool              $asString
     * @return array|string|bool
     */
    public function getAddresses($type, $asString = false)
    {
        $type = ( $type == 'reply-to' ) ? 'replyTo' : $type;
        $addressTypes = array('to', 'cc', 'bcc', 'from', 'sender', 'replyTo');

        if (!in_array($type, $addressTypes) || !isset($this->$type) || count($this->$type) < 1)
            return false;

        if (!$asString) {
            if ($type == 'from')
                return $this->from[0];
            elseif ($type == 'sender')
                return $this->sender[0];

            return $this->$type;
        } else {
            $outputString = '';
            foreach ($this->$type as $address) {
                if (isset($set))
                    $outputString .= ', ';
                if (!isset($set))
                    $set = true;

                $outputString .= isset($address['name']) ?
                    $address['name'] . ' <' . $address['address'] . '>'
                    : $address['address'];
            }

            return $outputString;
        }
    }

    /**
     * This function returns the date, as a timestamp, of when the email was sent.
     *
     * @return int
     */
    public function getDate()
    {
        return isset($this->date) ? $this->date : false;
    }

    /**
     * This returns the subject of the message.
     *
     * @return string
     */
    public function getSubject()
    {
        return isset($this->subject) ? $this->subject : null;
    }

    /**
     * This function marks a message for deletion. It is important to note that the message will not be deleted form the
     * mailbox until the Imap->expunge it run.
     *
     * @return bool
     */
    public function delete()
    {
        try {
            $this->imapStream->uidStore((int) $this->uid, '+FLAGS', array('\\Deleted'));
        } catch (ImapException $e) {
            return false;
        }

        return true;
    }

    /**
     * This function returns Imap this message came from.
     *
     * @return Server
     */
    public function getImapBox()
    {
        return $this->imapConnection;
    }

    /**
     * This function takes in a structure and identifier and processes that part of the message. If that portion of the
     * message has its own subparts, those are recursively processed using this function.
     *
     * @param \stdClass $structure
     * @param string    $partIdentifier
     */
    protected function processStructure($structure, $partIdentifier = null)
    {
        $parameters = self::getParametersFromStructure($structure);

        if ((isset($parameters['name']) || isset($parameters['filename']))
            || (isset($structure->subtype) && strtolower($structure->subtype) == 'rfc822')
        ) {
            $attachment          = new Attachment($this, $structure, $partIdentifier);
            $this->attachments[] = $attachment;
        } elseif ($structure->type == 0 || $structure->type == 1) {
            $messageBody = $this->fetchSection($partIdentifier);

            $messageBody = self::decode($messageBody, $structure->encoding);

            if (!empty($parameters['charset']))
                $messageBody = self::convertCharset($messageBody, $parameters['charset'], $structure->encoding);

            if (strtolower($structure->subtype) === 'plain' || ($structure->type == 1 && strtolower($structure->subtype) !== 'alternative')) {
                if (isset($this->plaintextMessage)) {
                    $this->plaintextMessage .= PHP_EOL . PHP_EOL;
                } else {
                    $this->plaintextMessage = '';
                }

                $this->plaintextMessage .= trim($messageBody);
            } elseif (strtolower($structure->subtype) === 'html') {
                if (isset($this->htmlMessage)) {
                    $this->htmlMessage .= '<br><br>';
                } else {
                    $this->htmlMessage = '';
                }

                $this->htmlMessage .= $messageBody;
            }
        }

        if (isset($structure->parts)) { // multipart: iterate through each part

            foreach ($structure->parts as $partIndex => $part) {
                $partId = $partIndex + 1;

                if (isset($partIdentifier))
                    $partId = $partIdentifier . '.' . $partId;

                $this->processStructure($part, $partId);
            }
        }
    }

    /**
     * Converts a decoded body from its declared charset to self::$charset: mbstring when loaded,
     * iconv otherwise. Charset names are matched as MIME means them, case-insensitively and by
     * alias ("iso-8859-1", "latin1"); a charset mbstring does not know falls back to US-ASCII for
     * a 7bit body, UTF-8 otherwise.
     *
     * @param  string $body
     * @param  string $charset
     * @param  int    $encoding
     * @return string
     */
    public static function convertCharset($body, $charset, $encoding)
    {
        if ($charset === self::$charset)
            return $body;

        if (function_exists('mb_convert_encoding')) {
            if (!self::mbKnowsCharset($charset))
                $charset = $encoding === 0 ? 'US-ASCII' : 'UTF-8';

            return @mb_convert_encoding($body, self::$charset, $charset);
        }

        $converted = @iconv($charset, self::$charset . self::$charsetFlag, $body);

        return $converted !== false ? $converted : $body;
    }

    /**
     * Whether mbstring knows a charset, by name or alias, in any case.
     *
     * @param  string $charset
     * @return bool
     */
    protected static function mbKnowsCharset($charset)
    {
        static $known = null;
        if ($known === null) {
            // mbstring's transfer encodings are not charsets, and any use of them —
            // mb_encoding_aliases() included — is deprecated in PHP 8.5.
            $notCharsets = array('base64', 'uuencode', 'html-entities', 'quoted-printable');
            $known = array();
            foreach (mb_list_encodings() as $name) {
                if (in_array(strtolower($name), $notCharsets, true))
                    continue;
                $known[strtolower($name)] = true;
                foreach (mb_encoding_aliases($name) as $alias)
                    $known[strtolower($alias)] = true;
            }
        }

        return isset($known[strtolower($charset)]);
    }

    /**
     * This function takes in the message data and encoding type and returns the decoded data.
     *
     * @param  string     $data
     * @param  int|string $encoding
     * @return string
     */
    public static function decode($data, $encoding)
    {
        if (!is_numeric($encoding)) {
            $encoding = strtolower($encoding);
        }

        switch (true) {
            case $encoding === 'quoted-printable':
            case $encoding === 4:
                return quoted_printable_decode($data);

            case $encoding === 'base64':
            case $encoding === 3:
                return base64_decode($data);

            default:
                return $data;
        }
    }

    /**
     * This function returns the body type that an imap integer maps to.
     *
     * @param  int    $id
     * @return string
     */
    public static function typeIdToString($id)
    {
        switch ($id) {
            case 0:
                return 'text';

            case 1:
                return 'multipart';

            case 2:
                return 'message';

            case 3:
                return 'application';

            case 4:
                return 'audio';

            case 5:
                return 'image';

            case 6:
                return 'video';

            default:
            case 7:
                return 'other';
        }
    }

    /**
     * Takes in a section structure and returns its parameters as an associative array.
     *
     * @param  \stdClass $structure
     * @return array
     */
    public static function getParametersFromStructure($structure)
    {
        $parameters = array();
        if (isset($structure->parameters))
            foreach ($structure->parameters as $parameter)
                $parameters[strtolower($parameter->attribute)] = $parameter->value;

        if (isset($structure->dparameters))
            foreach ($structure->dparameters as $parameter)
                $parameters[strtolower($parameter->attribute)] = $parameter->value;

        return $parameters;
    }

    /**
     * This function takes in an array of the address objects generated by the message headers and turns them into an
     * associative array.
     *
     * @param  array $addresses
     * @return array
     */
    protected function processAddressObject($addresses)
    {
        $outputAddresses = array();
        if (is_array($addresses))
            foreach ($addresses as $address) {
                if (property_exists($address, 'mailbox') && $address->mailbox != 'undisclosed-recipients') {
                    $currentAddress = array();
                    $currentAddress['address'] = $address->mailbox . '@' . $address->host;
                    if (isset($address->personal)) {
                        $currentAddress['name'] = MIME::decode($address->personal, self::$charset);
                    }
                    $outputAddresses[] = $currentAddress;
                }
            }

        return $outputAddresses;
    }

    /**
     * This function returns the unique id that identifies the message on the server.
     *
     * @return int
     */
    public function getUid()
    {
        return $this->uid;
    }

    /**
     * This function returns the attachments a message contains. If a filename is passed then just that ImapAttachment
     * is returned, unless
     *
     * @param  null|string             $filename
     * @return array|bool|Attachment[]
     */
    public function getAttachments($filename = null)
    {
        if (!isset($this->attachments) || count($this->attachments) < 1)
            return false;

        if (!isset($filename))
            return $this->attachments;

        $results = array();
        foreach ($this->attachments as $attachment) {
            if ($attachment->getFileName() == $filename)
                $results[] = $attachment;
        }

        switch (count($results)) {
            case 0:
                return false;

            case 1:
                return array_shift($results);

            default:
                return $results;
                break;
        }
    }

    /**
     * This function checks to see if an imap flag is set on the email message.
     *
     * @param  string $flag Recent, Flagged, Answered, Deleted, Seen, Draft
     * @return bool
     */
    public function checkFlag($flag = self::FLAG_FLAGGED)
    {
        return (isset($this->status[$flag]) && $this->status[$flag] === true);
    }

    /**
     * This function is used to enable or disable one or more flags on the imap message.
     *
     * @param  string|array              $flag   Flagged, Answered, Deleted, Seen, Draft
     * @param  bool                      $enable
     * @throws \InvalidArgumentException
     * @return bool
     */
    public function setFlag($flag, $enable = true)
    {
        $flags = (is_array($flag)) ? $flag : array($flag);

        foreach ($flags as $i => $flag) {
            $flag = ltrim(strtolower($flag), '\\');
            if (!in_array($flag, self::$flagTypes) || $flag == self::FLAG_RECENT)
                throw new \InvalidArgumentException('Unable to set invalid flag "' . $flag . '"');

            if ($enable) {
                $this->status[$flag] = true;
            } else {
                unset($this->status[$flag]);
            }

            $flags[$i] = $flag;
        }

        $imapifiedFlags = array();
        foreach ($flags as $flag)
            $imapifiedFlags[] = '\\' . ucfirst($flag);

        try {
            $this->imapStream->uidStore((int) $this->uid, $enable === true ? '+FLAGS' : '-FLAGS', $imapifiedFlags);
        } catch (ImapException $e) {
            return false;
        }

        return true;
    }

    /**
     * This function is used to move a mail to the given mailbox.
     *
     * @param $mailbox
     *
     * @return bool
     */
    public function moveToMailBox($mailbox)
    {
        $currentBox = $this->imapConnection->getMailBox();
        $this->imapConnection->setMailBox($this->mailbox);

        // ext-imap's CP_MOVE: copy, flag the original \Deleted, then expunge
        try {
            $this->imapStream->uidCopy((int) $this->uid, $mailbox);
            $this->imapStream->uidStore((int) $this->uid, '+FLAGS', array('\\Deleted'));
            $this->imapStream->expunge();
            $returnValue = true;
        } catch (ImapException $e) {
            $returnValue = false;
        }

        $this->mailbox = $mailbox;

        $this->imapConnection->setMailBox($currentBox);

        return $returnValue;
    }
    /**
     * The whole raw message (fetched once, with the overview).
     *
     * @return string
     */
    public function getRawMessage()
    {
        if (!isset($this->rawMessage))
            $this->getOverview(true);

        return $this->rawMessage;
    }

    /**
     * Raw bytes of one section ("1", "1.2" …), still transfer-encoded, as imap_fetchbody() gave
     * them; no section = the whole body, as imap_body() gave it.
     *
     * @param  string|null $partIdentifier
     * @return string
     */
    public function fetchSection($partIdentifier = null)
    {
        if (!isset($partIdentifier)) {
            list(, $body) = MimeParser::splitMessage($this->getRawMessage());

            return $body;
        }

        return MimeParser::section($this->getRawMessage(), $partIdentifier);
    }
}
