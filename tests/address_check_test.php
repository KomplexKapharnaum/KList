<?php
/**
 * Tests for AddressCheck (#t-077 ④): one SMTP session that asks the mail host whether each list
 * address exists — RCPT only, never DATA — and calls a list missing only on an unknown-address
 * refusal, never because the host could not be asked.
 *
 * Each case runs the real AddressCheck against one end of a local socket pair whose server side
 * is a script written up front, then reads back every byte the client sent.
 * Run: php tests/address_check_test.php   (exit 0 = all green)
 */

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/src/AddressCheck.php';

$failures = 0;
$checks = 0;

function check($label, $expected, $actual)
{
    global $failures, $checks;
    $checks++;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL $label\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
    }
}

/** AddressCheck whose "MX" is a socket the test holds; false = nobody answers. */
class ScriptedCheck extends AddressCheck
{
    public $socket = false;
    public $asked = [];

    protected function mxHosts(string $domain): array
    {
        return ["mx1.$domain", "mx2.$domain"];
    }

    protected function connect(string $host)
    {
        $this->asked[] = $host;
        return $this->socket;
    }

    protected function heloName(): string
    {
        return 'klist.test';
    }
}

const BOX = 'listes@kxkm.net';

/** Runs one check against a scripted server; returns [result, what the client sent]. */
function run(array $script, array $addresses, string $mailbox = BOX)
{
    list($client, $server) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, implode('', array_map(fn($l) => $l . "\r\n", $script)));
    stream_set_timeout($client, 1);
    $ac = new ScriptedCheck();
    $ac->socket = $client;
    $result = $ac->check('kxkm.net', $addresses, $mailbox);
    stream_set_blocking($server, false);
    $sent = (string) stream_get_contents($server);
    fclose($server);
    return [$result, $sent];
}

$greet = ['220 mx.kxkm.net ESMTP', '250-mx.kxkm.net', '250-PIPELINING', '250 8BITMIME', '250 2.1.0 Ok'];
$tail = ['250 2.0.0 Ok', '221 2.0.0 Bye'];

// 1. The box's own case: one list unknown (550 5.1.1), one known; the mailbox (control) known.
list($r, $sent) = run(array_merge($greet, [
    '250 2.1.5 Ok',
    '550 5.1.1 <ghost@kxkm.net>: Recipient address rejected: User unknown',
    '250 2.1.5 Ok',
], $tail), ['equipe@kxkm.net', 'ghost@kxkm.net']);
check('known list ok', AddressCheck::OK, $r['equipe@kxkm.net']);
check('unknown list missing', AddressCheck::MISSING, $r['ghost@kxkm.net']);
check('mailbox not in the result', ['equipe@kxkm.net', 'ghost@kxkm.net'], array_keys($r));
check('session order', "EHLO klist.test\r\nMAIL FROM:<listes@kxkm.net>\r\nRCPT TO:<equipe@kxkm.net>\r\n"
    . "RCPT TO:<ghost@kxkm.net>\r\nRCPT TO:<listes@kxkm.net>\r\nRSET\r\nQUIT\r\n", $sent);
check('never DATA', false, stripos($sent, 'DATA') !== false);

// 2. No MX answers: everything unverifiable, never missing; every MX was tried.
$ac = new ScriptedCheck();
$ac->socket = false;
check('no MX → unverifiable', ['a@kxkm.net' => 'unverifiable'], $ac->check('kxkm.net', ['a@kxkm.net'], BOX));
check('both MX tried', ['mx1.kxkm.net', 'mx2.kxkm.net'], $ac->asked);

// 3. The server refuses the mailbox too: it is refusing us, not the list → unverifiable.
list($r) = run(array_merge($greet, [
    '550 5.1.1 User unknown',
    '550 5.1.1 User unknown',
], $tail), ['ghost@kxkm.net']);
check('control refused → unverifiable', ['ghost@kxkm.net' => 'unverifiable'], $r);

// 4. A temporary refusal (greylisting) is not a missing address.
list($r) = run(array_merge($greet, ['450 4.7.1 Greylisted, try later', '250 2.1.5 Ok'], $tail), ['a@kxkm.net']);
check('4xx → unverifiable', ['a@kxkm.net' => 'unverifiable'], $r);

// 5. The connection drops mid-session: what was answered stands, the rest is unverifiable.
list($r) = run(array_merge($greet, ['250 2.1.5 Ok']), ['a@kxkm.net', 'b@kxkm.net']);
check('drop mid-session', ['a@kxkm.net' => 'ok', 'b@kxkm.net' => 'unverifiable'], $r);
list($r) = run(array_merge($greet, ['550 5.1.1 User unknown']), ['a@kxkm.net', 'b@kxkm.net']);
check('drop before the control: no red', ['a@kxkm.net' => 'unverifiable', 'b@kxkm.net' => 'unverifiable'], $r);

// 6. Greeting refused (554): nothing asked, nothing red.
list($r, $sent) = run(['554 5.7.1 Go away'], ['a@kxkm.net']);
check('554 greeting → unverifiable', ['a@kxkm.net' => 'unverifiable'], $r);
check('554 greeting → no RCPT sent', false, strpos($sent, 'RCPT') !== false);

// 7. EHLO refused → HELO fallback.
list($r, $sent) = run(['220 old', '502 no EHLO', '250 hi', '250 Ok', '250 Ok', '250 Ok', '250 Ok', '221 Bye'], ['a@kxkm.net']);
check('HELO fallback ok', ['a@kxkm.net' => 'ok'], $r);
check('HELO sent', true, strpos($sent, "HELO klist.test\r\n") !== false);

// 8. A mailbox on another domain is not used as the control.
list($r, $sent) = run(array_merge($greet, ['550 User unknown'], $tail), ['a@kxkm.net'], 'box@other.org');
check('foreign mailbox: bare 550 → missing', ['a@kxkm.net' => 'missing'], $r);
check('foreign mailbox not RCPT-ed', false, strpos($sent, 'RCPT TO:<box@other.org>') !== false);

// 9. classify(): only unknown-address refusals are missing.
check('classify 250', 'ok', AddressCheck::classify("250 2.1.5 Ok\r\n"));
check('classify 551 5.1.6', 'missing', AddressCheck::classify("551 5.1.6 moved\r\n"));
check('classify 550 5.7.1 policy', 'unverifiable', AddressCheck::classify("550 5.7.1 Relay access denied\r\n"));
check('classify bare 550', 'missing', AddressCheck::classify("550 No such user\r\n"));
check('classify bare 554', 'unverifiable', AddressCheck::classify("554 Rejected\r\n"));
check('classify 421', 'unverifiable', AddressCheck::classify("421 4.3.2 Shutting down\r\n"));
check('classify empty', 'unverifiable', AddressCheck::classify(''));

// 10. Nothing to ask: no connection opened.
$ac = new ScriptedCheck();
check('empty list → empty result', [], $ac->check('kxkm.net', [], BOX));
check('empty list → no connect', [], $ac->asked);

echo "address_check_test: $checks checks, $failures failure(s)\n";
exit($failures ? 1 : 0);
