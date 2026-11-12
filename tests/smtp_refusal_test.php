<?php
/**
 * Tests for a recipient refused at RCPT TO (audit 2026-11-12, finding 2): once the server has taken
 * the message for the others, MailProcessor::sendMailWithRetry() counts it as sent and never sends
 * it to everyone again; an unknown address (550 5.1.x) is blocked and the admin told, any other
 * refusal blocks nobody; a send nobody but our own address took is still retried.
 *
 * Each case runs the real PHPMailer + TrackingSMTP against one end of a local socket pair whose
 * server side is a script written up front, then reads back every byte the client sent.
 * Run: php tests/smtp_refusal_test.php   (exit 0 = all green)
 */

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/lib/PHPMailer/load.php';
require_once ROOT_PATH . '/src/TrackingSMTP.php';
require_once ROOT_PATH . '/src/Database.php';
require_once ROOT_PATH . '/src/BlocklistManager.php';
require_once ROOT_PATH . '/src/MailProcessor.php';

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

/** TrackingSMTP whose "server" is a socket the test holds; false = the connection is refused. */
class ScriptedSMTP extends TrackingSMTP
{
    public $socket = false;

    protected function getSMTPConnection($host, $port = null, $timeout = 30, $options = [])
    {
        return $this->socket;
    }
}

/** Records what would be blocked, no database. */
class FakeBlocklist extends BlocklistManager
{
    public $blocked = [];

    public function __construct()
    {
    }

    public function block(string $email, int $code = 550): bool
    {
        $this->blocked[$email] = $code;
        return true;
    }
}

/** Answers getSetting() with '', no database. */
class FakeDatabase extends Database
{
    public function __construct()
    {
    }

    public function getSetting(string $key, $default = null): ?string
    {
        return '';
    }
}

const ADMIN = 'admin@kxkm.net';
const LISTE = 'equipe@kxkm.net';

/** A MailProcessor with its settings set by hand: no database, no IMAP, no pause between retries. */
function processor(FakeBlocklist $blocklist)
{
    $rc = new ReflectionClass('MailProcessor');
    $mp = $rc->newInstanceWithoutConstructor();
    $set = array(
        'startTime' => microtime(true),
        'lastLogTime' => microtime(true),
        'retryDelay' => 0.0,
        'blocklistManager' => $blocklist,
        'db' => new FakeDatabase(),
        'smtpHost' => 'localhost',
        'smtpPort' => 25,
        'username' => 'listes@kxkm.net',
        'adminmail' => ADMIN,
        'domains' => array('kxkm.net'),
    );
    foreach ($set as $name => $value) {
        $rc->getProperty($name)->setValue($mp, $value);
    }
    return $mp;
}

function call($mp, $method, ...$args)
{
    $m = new ReflectionMethod('MailProcessor', $method);
    ob_start(); // log() prints HTML
    try {
        return $m->invoke($mp, ...$args);
    } finally {
        ob_end_clean();
    }
}

/** The processor's own connection, its SMTP talking to $script; returns [mail, server end]. */
function connection($mp, $script)
{
    list($client, $server) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, $script);
    stream_socket_shutdown($server, STREAM_SHUT_WR); // a read past the script = connection lost
    $mail = call($mp, 'getSmtpConnection');
    $smtp = new ScriptedSMTP();
    $smtp->socket = $client;
    $mail->setSMTPInstance($smtp);
    $mail->SMTPAuth = false;
    $mail->SMTPAutoTLS = false;
    $mail->Timeout = 2;
    return array($mail, $server);
}

/** A list copy as forward() builds it: From the list, one recipient per address. */
function listCopy($mp, array $to, array $bcc = array())
{
    $mail = call($mp, 'mail');
    $mail->setFrom(LISTE, 'Equipe KXKM');
    foreach ($to as $a) {
        $mail->addAddress($a);
    }
    foreach ($bcc as $a) {
        $mail->addBCC($a);
    }
    $mail->Subject = '[Alice sur equipe] Repas jeudi';
    $mail->msgHTML('<p>On mange à 19h.</p>');
    return $mail;
}

function sent($server)
{
    stream_set_blocking($server, false);
    $bytes = (string) stream_get_contents($server);
    fclose($server);
    return $bytes;
}

function dataCount($bytes)
{
    return substr_count($bytes, "\r\nDATA\r\n");
}

function rcpts($bytes)
{
    preg_match_all('/^RCPT TO:<([^>]+)>/m', $bytes, $m);
    return $m[1];
}

$greet = "220 mail.test ESMTP\r\n250-mail.test\r\n250 8BITMIME\r\n";
/**
 * One transaction's replies: MAIL, one per RCPT, DATA + end of data unless every RCPT failed, then
 * smtpSend()'s RSET, and postSend()'s second one when a refusal made the send fail.
 */
function txn(array $rcptReplies)
{
    $s = "250 2.1.0 Ok\r\n" . implode('', $rcptReplies);
    $okCount = count(array_filter($rcptReplies, function ($r) {
        return $r[0] === '2';
    }));
    if ($okCount > 0) {
        $s .= "354 End data with <CR><LF>.<CR><LF>\r\n250 2.0.0 Ok: queued as 4ABC\r\n";
    }
    return $s . str_repeat("250 2.0.0 Ok\r\n", $okCount === count($rcptReplies) ? 1 : 2);
}
$ok = "250 2.1.5 Ok\r\n";
$unknown = "550 5.1.1 <b@kxkm.net>: Recipient address rejected: User unknown in virtual mailbox table\r\n";
$policy = "554 5.7.1 <b@kxkm.net>: Recipient address rejected: Access denied\r\n";
$later = "450 4.2.0 <b@kxkm.net>: Recipient address rejected: try again later\r\n";

// --- one of three refused as unknown: sent once, b blocked, the admin told ------------------------
// (The script would serve the old code's three attempts: it shows DATA 3 times there.)
$bl = new FakeBlocklist();
$mp = processor($bl);
list($mail, $server) = connection($mp, $greet . str_repeat(txn(array($ok, $unknown, $ok)), 3));
$r = call($mp, 'sendMailWithRetry', listCopy($mp, array('a@kxkm.net', 'b@kxkm.net', 'c@example.org')), 'forward to ' . LISTE);
$bytes = sent($server);
check('unknown: send counts as sent', true, $r);
check('unknown: DATA sent once', 1, dataCount($bytes));
check('unknown: blocked', array('b@kxkm.net' => 550), $bl->blocked);
check('unknown: in-run blocklist updated', array('b@kxkm.net' => 550), (new ReflectionProperty('MailProcessor', 'blocklist'))->getValue($mp));
// the end of the run: the admin notice, on a fresh connection
$mail->getSMTPInstance()->close();
list($mail, $server) = connection($mp, $greet . txn(array($ok)));
try {
    call($mp, 'notifyBlockedRecipients');
} catch (ReflectionException $e) {
    check('unknown: notifyBlockedRecipients exists', '', $e->getMessage());
}
$bytes = sent($server);
check('unknown: one notice, to the admin', array(ADMIN), rcpts($bytes));
check('unknown: the notice names the address', true, strpos($bytes, '<strong>b@kxkm.net</strong>') !== false);

// --- refused by policy (5.7.1) or for now (4xx): sent once, nobody blocked ------------------------
foreach (array('policy' => $policy, 'temporary' => $later) as $label => $reply) {
    $bl = new FakeBlocklist();
    $mp = processor($bl);
    list($mail, $server) = connection($mp, $greet . str_repeat(txn(array($ok, $reply)), 3));
    $r = call($mp, 'sendMailWithRetry', listCopy($mp, array('a@kxkm.net', 'b@kxkm.net')), 'forward to ' . LISTE);
    $bytes = sent($server);
    check("$label: send counts as sent", true, $r);
    check("$label: DATA sent once", 1, dataCount($bytes));
    check("$label: nobody blocked", array(), $bl->blocked);
}

// --- a broadcast list where only the list's own address was taken: not sent, retried, no block ----
$bl = new FakeBlocklist();
$mp = processor($bl);
list($mail, $server) = connection($mp, $greet . str_repeat(txn(array($ok, $unknown, $unknown)), 3));
$r = call($mp, 'sendMailWithRetry', listCopy($mp, array(LISTE), array('b@kxkm.net', 'd@kxkm.net')), 'forward to ' . LISTE);
$bytes = sent($server);
check('own address only: not sent', false, $r);
check('own address only: tried 3 times', 3, dataCount($bytes));
check('own address only: nobody blocked', array(), $bl->blocked);

// --- the message itself refused at the end of DATA, one recipient refused before: retried ---------
$bl = new FakeBlocklist();
$mp = processor($bl);
$dataRefused = "250 2.1.0 Ok\r\n" . $ok . $unknown . "354 End data with <CR><LF>.<CR><LF>\r\n"
    . "554 5.7.1 Message rejected\r\n250 2.0.0 Ok\r\n"; // postSend()'s RSET only
list($mail, $server) = connection($mp, $greet . str_repeat($dataRefused, 3));
$r = call($mp, 'sendMailWithRetry', listCopy($mp, array('a@kxkm.net', 'b@kxkm.net')), 'forward to ' . LISTE);
$bytes = sent($server);
check('DATA refused: not sent', false, $r);
check('DATA refused: tried 3 times', 3, dataCount($bytes));
check('DATA refused: nobody blocked', array(), $bl->blocked);

// --- every recipient refused (no DATA): not sent, retried, nobody blocked -------------------------
$bl = new FakeBlocklist();
$mp = processor($bl);
list($mail, $server) = connection($mp, $greet . str_repeat(txn(array($unknown)), 3));
$r = call($mp, 'sendMailWithRetry', listCopy($mp, array('b@kxkm.net')), 'unauthorized notification');
$bytes = sent($server);
check('all refused: not sent', false, $r);
check('all refused: 3 attempts', 3, count(rcpts($bytes)));
check('all refused: no DATA', 0, dataCount($bytes));
check('all refused: nobody blocked', array(), $bl->blocked);

// --- a clean send, then one that cannot connect: the first send's outcome is not reused ------------
$bl = new FakeBlocklist();
$mp = processor($bl);
list($mail, $server) = connection($mp, $greet . txn(array($ok, $ok)));
$r = call($mp, 'sendMailWithRetry', listCopy($mp, array('a@kxkm.net', 'c@example.org')), 'forward to ' . LISTE);
check('clean: sent', true, $r);
check('clean: DATA once', 1, dataCount(sent($server)));
$mail->getSMTPInstance()->close();          // the server is gone:
$mail->getSMTPInstance()->socket = false;    // connect() fails
$r = call($mp, 'sendMailWithRetry', listCopy($mp, array('a@kxkm.net')), 'forward to ' . LISTE);
check('after a clean send, a refused connection: not sent', false, $r);
check('clean then refused connection: nobody blocked', array(), $bl->blocked);

// --- production wiring: the processor's own connection carries a TrackingSMTP ---------------------
$mp = processor(new FakeBlocklist());
check('getSmtpConnection uses TrackingSMTP', true, call($mp, 'getSmtpConnection')->getSMTPInstance() instanceof TrackingSMTP);

echo "smtp_refusal_test: $checks checks, $failures failure(s)\n";
exit($failures ? 1 : 0);
