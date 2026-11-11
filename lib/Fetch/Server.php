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

require_once __DIR__ . '/ImapClient.php';

/**
 * This library is a wrapper around the Imap library functions included in php. This class in particular manages a
 * connection to the server (imap, pop, etc) and allows for the easy retrieval of stored messages.
 *
 * @package Fetch
 * @author  Robert Hafner <tedivm@tedivm.com>
 */
class Server
{
    /**
     * When SSL isn't compiled into PHP we need to make some adjustments to prevent soul crushing annoyances.
     *
     * @var bool
     */
    public static $sslEnable = true;

    /**
     * These are the flags that depend on ssl support being compiled into imap.
     *
     * @var array
     */
    public static $sslFlags = array('ssl', 'validate-cert', 'novalidate-cert', 'tls', 'notls');

    /**
     * This is used to prevent the class from putting up conflicting tags. Both directions- key to value, value to key-
     * are checked, so if "novalidate-cert" is passed then "validate-cert" is removed, and vice-versa.
     *
     * @var array
     */
    public static $exclusiveFlags = array('validate-cert' => 'novalidate-cert', 'tls' => 'notls');

    /**
     * This is the domain or server path the class is connecting to.
     *
     * @var string
     */
    protected $serverPath;

    /**
     * This is the name of the current mailbox the connection is using.
     *
     * @var string
     */
    protected $mailbox = '';

    /**
     * This is the username used to connect to the server.
     *
     * @var string
     */
    protected $username;

    /**
     * This is the password used to connect to the server.
     *
     * @var string
     */
    protected $password;

    /**
     * This is an array of flags that modify how the class connects to the server. Examples include "ssl" to enforce a
     * secure connection or "novalidate-cert" to allow for self-signed certificates.
     *
     * @link http://us.php.net/manual/en/function.imap-open.php
     * @var array
     */
    protected $flags = array();

    /**
     * This is the port used to connect to the server
     *
     * @var int
     */
    protected $port;

    /**
     * This is the set of options, represented by a bitmask, to be passed to the server during connection.
     *
     * @var int
     */
    protected $options = 0;

    /**
     * This is the set of connection parameters
     *
     * @var array
     */
    protected $params = array();

    /**
     * This is the resource connection to the server. It is required by a number of imap based functions to specify how
     * to connect.
     *
     * @var resource
     */
    protected $imapStream;

    /**
     * This is the name of the service currently being used. Imap is the default, although pop3 and nntp are also
     * options
     *
     * @var string
     */
    protected $service = 'imap';

    /** @var array what the last SELECT reported: exists, recent, uidvalidity, uidnext, flags, readonly */
    protected $selected = array();

    /** @var int seconds, for the connect and every read */
    protected $timeout = 30;

    /** @var ImapClient|null a connected client to use instead of opening one (tests) */
    protected $pendingClient;

    /**
     * This constructor takes the location and service thats trying to be connected to as its arguments.
     *
     * @param string      $serverPath
     * @param null|int    $port
     * @param null|string $service
     */
    public function __construct($serverPath, $port = 143, $service = 'imap')
    {
        $this->serverPath = $serverPath;

        $this->port = $port;

        switch ($port) {
            case 143:
                $this->setFlag('novalidate-cert');
                break;

            case 993:
                $this->setFlag('ssl');
                break;
        }

        $this->service = $service;
    }

    /**
     * This function sets the username and password used to connect to the server.
     *
     * @param string $username
     * @param string $password
     * @param bool   $tryFasterAuth tries to auth faster by disabling GSSAPI & NTLM auth methods (set to false if you use either of these auth methods)
     */
    public function setAuthentication($username, $password, $tryFasterAuth=true)
    {
        $this->username = $username;
        $this->password = $password;
        if ($tryFasterAuth) {
            $this->setParam('DISABLE_AUTHENTICATOR', array('GSSAPI','NTLM'));
        }
    }

    /**
     * This function sets the mailbox to connect to.
     *
     * @param  string $mailbox
     * @return bool
     */
    public function setMailBox($mailbox = '')
    {
        if (!$this->hasMailBox($mailbox)) {
            return false;
        }

        $this->mailbox = $mailbox;
        if (isset($this->imapStream)) {
            $this->setImapStream();
        }

        return true;
    }

    public function getMailBox()
    {
        return $this->mailbox;
    }

    /**
     * This function sets or removes flag specifying connection behavior. In many cases the flag is just a one word
     * deal, so the value attribute is not required. However, if the value parameter is passed false it will clear that
     * flag.
     *
     * @param string           $flag
     * @param null|string|bool $value
     */
    public function setFlag($flag, $value = null)
    {
        if (!self::$sslEnable && in_array($flag, self::$sslFlags))
            return;

        if (isset(self::$exclusiveFlags[$flag])) {
            $kill = self::$exclusiveFlags[$flag];
        } elseif ($index = array_search($flag, self::$exclusiveFlags)) {
            $kill = $index;
        }

        if (isset($kill) && false !== $index = array_search($kill, $this->flags))
            unset($this->flags[$index]);

        $index = array_search($flag, $this->flags);
        if (isset($value) && $value !== true) {
            if ($value == false && $index !== false) {
                unset($this->flags[$index]);
            } elseif ($value != false) {
                $match = preg_grep('/' . $flag . '/', $this->flags);
                if (reset($match)) {
                    $this->flags[key($match)] = $flag . '=' . $value;
                } else {
                    $this->flags[] = $flag . '=' . $value;
                }
            }
        } elseif ($index === false) {
            $this->flags[] = $flag;
        }
    }

    /**
     * This funtion is used to set various options for connecting to the server.
     *
     * @param  int        $bitmask
     * @throws \Exception
     */
    public function setOptions($bitmask = 0)
    {
        if (!is_numeric($bitmask))
            throw new \RuntimeException('Function requires numeric argument.');

        $this->options = $bitmask;
    }

    /**
     * This function is used to set connection parameters
     *
     * @param string $key
     * @param string $value
     */
    public function setParam($key, $value)
    {
        $this->params[$key] = $value;
    }

    /**
     * This function gets the current saved imap resource and returns it.
     *
     * @return resource
     */
    public function getImapStream()
    {
        if (empty($this->imapStream))
            $this->setImapStream();

        return $this->imapStream;
    }

    /**
     * This function takes in all of the connection date (server, port, service, flags, mailbox) and creates the string
     * thats passed to the imap_open function.
     *
     * @return string
     */
    public function getServerString()
    {
        $mailboxPath = $this->getServerSpecification();

        if (isset($this->mailbox))
            $mailboxPath .= $this->mailbox;

        return $mailboxPath;
    }

    /**
     * Returns the server specification, without adding any mailbox.
     *
     * @return string
     */
    protected function getServerSpecification()
    {
        $mailboxPath = '{' . $this->serverPath;

        if (isset($this->port))
            $mailboxPath .= ':' . $this->port;

        if ($this->service != 'imap')
            $mailboxPath .= '/' . $this->service;

        foreach ($this->flags as $flag) {
            $mailboxPath .= '/' . $flag;
        }

        $mailboxPath .= '}';

        return $mailboxPath;
    }

    /**
     * This function creates or reopens an imapStream when called.
     *
     */
    protected function setImapStream()
    {
        // ImapException extends \RuntimeException and carries the server's NO/BAD text, as
        // imap_last_error() did.
        if (empty($this->imapStream)) {
            if (isset($this->pendingClient)) {
                $client = $this->pendingClient;
                $this->pendingClient = null;
            } else {
                $sslOptions = in_array('novalidate-cert', $this->flags)
                    ? array('verify_peer' => false, 'verify_peer_name' => false) : array();
                $client = ImapClient::connect($this->serverPath, $this->port, $this->timeout, $sslOptions);
            }
            $client->login($this->username, $this->password);
            $this->imapStream = $client;
        }
        $this->selected = $this->imapStream->select($this->mailbox === '' ? 'INBOX' : $this->mailbox);
    }

    /**
     * Hands the server an already connected client (it still logs in and selects). Tests use it
     * to run Fetch against a scripted IMAP exchange.
     *
     * @param ImapClient $client
     */
    public function useClient(ImapClient $client)
    {
        $this->pendingClient = $client;
    }

    /**
     * Sends LOGOUT if a session is open.
     */
    public function close()
    {
        if ($this->imapStream instanceof ImapClient) {
            $this->imapStream->logout();
        }
        $this->imapStream = null;
    }

    /**
     * This returns the number of messages that the current mailbox contains.
     *
     * @param  string $mailbox
     * @return int
     */
    public function numMessages($mailbox='')
    {
        $cnt = 0;
        if ($mailbox==='') {
            $this->getImapStream();
            $cnt = isset($this->selected['exists']) ? $this->selected['exists'] : 0;
        } elseif ($this->hasMailbox($mailbox) && $mailbox !== '') {
            $oldMailbox = $this->getMailBox();
            $this->setMailbox($mailbox);
            $cnt = $this->numMessages();
            $this->setMailbox($oldMailbox);
        }

        return ((int) $cnt);
    }

    /**
     * This function returns an array of ImapMessage object for emails that fit the criteria passed. The criteria string
     * should be formatted according to the imap search standard, which can be found on the php "imap_search" page or in
     * section 6.4.4 of RFC 2060
     *
     * @link http://us.php.net/imap_search
     * @link http://www.faqs.org/rfcs/rfc2060
     * @param  string   $criteria
     * @param  null|int $limit
     * @return array    An array of ImapMessage objects
     */
    public function search($criteria = 'ALL', $limit = null)
    {
        if ($results = $this->getImapStream()->uidSearch($criteria)) {
            if (isset($limit) && count($results) > $limit)
                $results = array_slice($results, 0, $limit);

            $messages = array();

            foreach ($results as $messageId)
                $messages[] = new Message($messageId, $this);

            return $messages;
        } else {
            return array();
        }
    }

    /**
     * This function returns the recently received emails as an array of ImapMessage objects.
     *
     * @param  null|int $limit
     * @return array    An array of ImapMessage objects for emails that were recently received by the server.
     */
    public function getRecentMessages($limit = null)
    {
        return $this->search('Recent', $limit);
    }

    /**
     * Returns the emails in the current mailbox as an array of ImapMessage objects.
     *
     * @param  null|int  $limit
     * @return Message[]
     */
    public function getMessages($limit = null)
    {
        $numMessages = $this->numMessages();

        if (isset($limit) && is_numeric($limit) && $limit < $numMessages)
            $numMessages = $limit;

        if ($numMessages < 1)
            return array();

        $messages = array();
        foreach ($this->getMessageUids($numMessages) as $uid) {
            $messages[] = new Message($uid, $this);
        }

        return $messages;
    }

    /**
     * Returns the emails in the current mailbox as an array of ImapMessage objects
     * ordered by some ordering
     *
     * @see    http://php.net/manual/en/function.imap-sort.php
     * @param  int       $orderBy
     * @param  bool      $reverse
     * @param  int       $limit
     * @return Message[]
     */
    public function getOrderedMessages($orderBy, $reverse, $limit)
    {
        // ext-imap's SORTDATE (0), SORTARRIVAL (1) and SORTSIZE (6), sorted here: no SORT extension
        // is assumed on the server. Date = the server's INTERNALDATE.
        $keys = array(0 => 'INTERNALDATE', 1 => 'UID', 6 => 'RFC822.SIZE');
        if (!isset($keys[$orderBy]))
            throw new \InvalidArgumentException('Unsupported sort criterion: ' . $orderBy);

        $msgIds = array();
        if ($this->numMessages() > 0) {
            foreach ($this->getImapStream()->fetch('1:*', '(UID INTERNALDATE RFC822.SIZE)') as $item) {
                $value = $item[$keys[$orderBy]];
                $msgIds[(int) $item['UID']] = $orderBy === 0 ? strtotime($value) : (int) $value;
            }
        }
        $reverse ? arsort($msgIds) : asort($msgIds);
        $msgIds = array_keys($msgIds);

        return array_map(array($this, 'getMessageByUid'), array_slice($msgIds, 0, $limit));
    }

    /**
     * Returns the requested email or false if it is not found.
     *
     * @param  int          $uid
     * @return Message|bool
     */
    public function getMessageByUid($uid)
    {
        try {
            $message = new \Fetch\Message($uid, $this);

            return $message;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * This function removes all of the messages flagged for deletion from the mailbox.
     *
     * @return bool
     */
    public function expunge()
    {
        $this->getImapStream()->expunge();
        $this->setImapStream();   // re-SELECT: the message count changed

        return true;
    }

    /**
     * Checks if the given mailbox exists.
     *
     * @param $mailbox
     *
     * @return bool
     */
    public function hasMailBox($mailbox)
    {
        return (bool) $this->getMailBoxDetails($mailbox);
    }

    /**
    * Return information about the mailbox or mailboxes
    *
    * @param $mailbox
    *
    * @return array
    */
    public function getMailBoxDetails($mailbox)
    {
        $details = array();
        foreach ($this->getImapStream()->listMailboxes('', $mailbox === '' ? 'INBOX' : $mailbox) as $box) {
            $details[] = (object) array(
                'name' => $this->getServerSpecification() . $box['name'],
                'attributes' => $box['attributes'],   // flag names (ext-imap gave a bitmask)
                'delimiter' => $box['delimiter'],
            );
        }

        return $details;
    }

    /**
     * Creates the given mailbox.
     *
     * @param $mailbox
     *
     * @return bool
     */
    public function createMailBox($mailbox)
    {
        try {
            $this->getImapStream()->create($mailbox);
        } catch (ImapException $e) {
            return false;
        }

        return true;
    }

    /**
     * List available mailboxes
     *
     * @param string $pattern
     *
     * @return array
     */
    public function listMailBoxes($pattern = '*')
    {
        $names = array();
        foreach ($this->getImapStream()->listMailboxes('', $pattern) as $box) {
            $names[] = $this->getServerSpecification() . $box['name'];
        }

        return $names;
    }

    /**
     * Deletes the given mailbox.
     *
     * @param $mailbox
     *
     * @return bool
     */
     public function deleteMailBox($mailbox)
     {
         try {
             $this->getImapStream()->delete($mailbox);
         } catch (ImapException $e) {
             return false;
         }

         return true;
     }

    /**
     * Returns an array of message UIDs in the current mailbox without loading message bodies.
     * This is memory-efficient for processing messages one at a time.
     *
     * @param  null|int $limit Maximum number of UIDs to return
     * @return array    Array of message UIDs
     */
    public function getMessageUids($limit = null)
    {
        $numMessages = $this->numMessages();

        if (isset($limit) && is_numeric($limit) && $limit < $numMessages)
            $numMessages = $limit;

        if ($numMessages < 1)
            return array();

        $items = $this->getImapStream()->fetch('1:' . (int) $numMessages, '(UID)');
        ksort($items);
        $uids = array();
        foreach ($items as $item) {
            $uids[] = (int) $item['UID'];
        }

        return $uids;
    }
}
