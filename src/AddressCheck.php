<?php
/**
 * Does the mail host know a list's address? One SMTP session per domain, to the domain's MX:
 * EHLO, MAIL FROM the mailbox, one RCPT TO per address, RSET, QUIT — never DATA, so nothing is
 * ever sent. Proved on the box 12/11: the MX answers an unknown @kxkm.net with 550 5.1.1 at RCPT.
 *
 * Each address comes back as one of:
 *   ok            — the server took the RCPT (2xx)
 *   missing       — refused as unknown: 5.1.x, or a bare 550 with no enhanced code
 *   unverifiable  — anything else: no MX reachable, a timeout, a 4xx, a policy refusal (5.7.x),
 *                   or the mailbox itself refused (the server is refusing us, not the address)
 * Only `missing` ever turns a list red; a host we could not ask is never a reason to.
 */

class AddressCheck
{
    public const OK = 'ok';
    public const MISSING = 'missing';
    public const UNVERIFIABLE = 'unverifiable';

    private int $timeout;

    public function __construct(int $timeout = 10)
    {
        $this->timeout = $timeout;
    }

    /**
     * @param string[] $addresses  every address of $domain to check
     * @return array<string,string> address => ok|missing|unverifiable
     */
    public function check(string $domain, array $addresses, string $mailbox): array
    {
        $result = array_fill_keys($addresses, self::UNVERIFIABLE);
        if (!$addresses) {
            return $result;
        }

        $socket = false;
        foreach ($this->mxHosts($domain) as $host) {
            $socket = $this->connect($host);
            if ($socket) {
                break;
            }
        }
        if (!$socket) {
            return $result;
        }

        $answers = [];
        try {
            if ($this->code($this->read($socket)) !== 220) {
                return $result;
            }
            $helo = $this->heloName();
            $this->write($socket, "EHLO $helo");
            if ($this->code($this->read($socket)) !== 250) {
                $this->write($socket, "HELO $helo");
                if ($this->code($this->read($socket)) !== 250) {
                    return $result;
                }
            }
            $this->write($socket, "MAIL FROM:<$mailbox>");
            if ($this->code($this->read($socket)) !== 250) {
                return $result;
            }

            // The mailbox is the control: if the server refuses it too, it is refusing us.
            $control = strcasecmp(substr(strrchr($mailbox, '@') ?: '', 1), $domain) === 0
                && !isset($result[$mailbox]);
            $toAsk = $control ? array_merge($addresses, [$mailbox]) : $addresses;

            foreach ($toAsk as $address) {
                $this->write($socket, "RCPT TO:<$address>");
                $answers[$address] = self::classify($this->read($socket));
            }
            if ($control && $answers[$mailbox] !== self::OK) {
                $answers = [];
            }
            foreach ($addresses as $address) {
                if (isset($answers[$address])) {
                    $result[$address] = $answers[$address];
                }
            }

            $this->write($socket, 'RSET');
            $this->read($socket);
            $this->write($socket, 'QUIT');
            $this->read($socket);
        } catch (RuntimeException $e) {
            // A dropped or silent connection: an accepted address stands; a refusal does not, since
            // the control that tells « unknown » from « refusing us » never came. The rest stays
            // unverifiable.
            foreach ($answers as $address => $answer) {
                if ($answer === self::OK && isset($result[$address])) {
                    $result[$address] = self::OK;
                }
            }
        } finally {
            fclose($socket);
        }

        return $result;
    }

    /** One RCPT reply → ok|missing|unverifiable (see the class header). */
    public static function classify(string $reply): string
    {
        $code = (int) substr($reply, 0, 3);
        if ($code >= 200 && $code < 300) {
            return self::OK;
        }
        if ($code >= 500 && $code < 600) {
            if (preg_match('/^\d{3}[ -](\d)\.(\d{1,3})\.\d{1,3}\b/', $reply, $m)) {
                return ($m[1] === '5' && $m[2] === '1') ? self::MISSING : self::UNVERIFIABLE;
            }
            return $code === 550 ? self::MISSING : self::UNVERIFIABLE;
        }
        return self::UNVERIFIABLE;
    }

    /** The domain's MX hosts, best first; none → the domain itself (RFC 5321 §5.1). */
    protected function mxHosts(string $domain): array
    {
        $hosts = [];
        $weights = [];
        if (function_exists('getmxrr') && @getmxrr($domain, $hosts, $weights) && $hosts) {
            array_multisort($weights, SORT_ASC, SORT_NUMERIC, $hosts);
            return $hosts;
        }
        return [$domain];
    }

    /** @return resource|false */
    protected function connect(string $host)
    {
        $socket = @stream_socket_client("tcp://$host:25", $errno, $errstr, $this->timeout);
        if ($socket) {
            stream_set_timeout($socket, $this->timeout);
        }
        return $socket;
    }

    protected function heloName(): string
    {
        return gethostname() ?: 'localhost';
    }

    private function write($socket, string $line): void
    {
        if (@fwrite($socket, $line . "\r\n") === false) {
            throw new RuntimeException('write failed');
        }
    }

    /** One whole reply (multi-line `250-…` continuations folded in); throws on EOF or timeout. */
    private function read($socket): string
    {
        $reply = '';
        while (true) {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new RuntimeException('no reply');
            }
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                return $reply;
            }
        }
    }

    private function code(string $reply): int
    {
        return (int) substr($reply, 0, 3);
    }
}
