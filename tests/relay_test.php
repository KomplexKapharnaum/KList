<?php
/**
 * Tests for the relay (#t-072, decided 2026-11-12 "relay, don't rebuild"): a list copy is the
 * original message with its headers rewritten (ListRelay), sent to one de-duplicated envelope per
 * list, recorded in the journal (RelayJournal), by MailProcessor::forward().
 *
 * The fixtures under tests/fixtures/relay/ — an inline image referenced by cid:, an RFC 2231 file
 * name, three pictures all called image.png — must reach DATA byte for byte. forward() runs against
 * a scripted SMTP server (as in smtp_refusal_test.php) with an in-memory journal; the journal's SQL
 * runs on an in-memory SQLite when pdo_sqlite is there (the box), and is reported skipped otherwise.
 * Run: php tests/relay_test.php   (exit 0 = all green)
 */

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/lib/PHPMailer/load.php';
require_once ROOT_PATH . '/lib/Fetch/MimeParser.php';
require_once ROOT_PATH . '/src/TrackingSMTP.php';
require_once ROOT_PATH . '/src/Database.php';
require_once ROOT_PATH . '/src/BlocklistManager.php';
require_once ROOT_PATH . '/src/ListManager.php';
require_once ROOT_PATH . '/src/ListRelay.php';
require_once ROOT_PATH . '/src/RelayJournal.php';
require_once ROOT_PATH . '/src/MailProcessor.php';

use Fetch\MimeParser;

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

/** A fixture as the mailbox gives it: CRLF line ends. */
function fixture($name)
{
    return str_replace("\n", "\r\n", file_get_contents(__DIR__ . '/fixtures/relay/' . $name));
}

/** Every field of a header block named $name (case-insensitive), raw. */
function fields($raw, $name)
{
    list($fields) = ListRelay::split($raw);
    $out = array();
    foreach ($fields as $f) {
        if (strcasecmp($f[0], $name) === 0) {
            $out[] = $f[1];
        }
    }
    return $out;
}

/** The one value of a field, unfolded and decoded; null when absent. */
function hdr($raw, $name)
{
    $f = fields($raw, $name);
    if (!$f) {
        return null;
    }
    $value = substr($f[0], strpos($f[0], ':') + 1);
    return ListRelay::decode(trim(preg_replace('/\r?\n(?=[ \t])/', '', $value)));
}

function body($raw)
{
    list(, $body) = ListRelay::split($raw);
    return $body;
}

// ============================================================================ headers ==========

// --- subject: [list] once, Re: kept in front, the old rebuild's tag gone ---------------------------
$cases = array(
    'Repas jeudi' => '[equipe] Repas jeudi',
    '[equipe] Repas jeudi' => '[equipe] Repas jeudi',
    'Re: [equipe] Repas jeudi' => 'Re: [equipe] Repas jeudi',
    '[equipe] Re: Repas jeudi' => 'Re: [equipe] Repas jeudi',
    'RE : Repas' => 'RE : [equipe] Repas',
    'Re: Re: [Equipe] Repas' => 'Re: Re: [equipe] Repas',
    '[Alice sur equipe] Repas' => '[equipe] Repas',
    'Re: [ sur equipe] Repas' => 'Re: [equipe] Repas',
    '[equipe] [equipe] Repas' => '[equipe] Repas',
    '[tous] Repas' => '[equipe] [tous] Repas',
    'Fwd: Repas' => '[equipe] Fwd: Repas',
    'Été à la ferme' => '[equipe] Été à la ferme',
    '' => '[equipe]',
);
foreach ($cases as $in => $out) {
    check("subject '$in'", $out, ListRelay::subject($in, 'equipe'));
}

// --- inline image, discussion list: the name in From, Reply-To the list, To/Cc kept -----------------
$raw = fixture('inline-cid-image.eml');
$copy = ListRelay::copy($raw, 'equipe', 'equipe@kxkm.net', true);
check('cid: From names the sender via the list', 'René François via equipe <equipe@kxkm.net>', hdr($copy, 'From'));
check('cid: Reply-To is the list', 'equipe@kxkm.net', hdr($copy, 'Reply-To'));
check('cid: one From', 1, count(fields($copy, 'From')));
check('cid: one Reply-To', 1, count(fields($copy, 'Reply-To')));
check('cid: To kept byte for byte', fields($raw, 'To'), fields($copy, 'To'));
check('cid: Cc kept byte for byte', fields($raw, 'Cc'), fields($copy, 'Cc'));
check('cid: subject already right, kept as written', fields($raw, 'Subject'), fields($copy, 'Subject'));
foreach (array('Received', 'Date', 'Message-ID', 'In-Reply-To', 'References', 'MIME-Version', 'Content-Type') as $kept) {
    check("cid: $kept kept byte for byte", fields($raw, $kept), fields($copy, $kept));
}
foreach (array('DKIM-Signature', 'ARC-Seal', 'Return-Path', 'Delivered-To') as $dropped) {
    check("cid: $dropped dropped", array(), fields($copy, $dropped));
}
check('cid: List-Id', '<equipe.kxkm.net>', hdr($copy, 'List-Id'));
check('cid: List-Post', '<mailto:equipe@kxkm.net>', hdr($copy, 'List-Post'));
check('cid: List-Unsubscribe by mail', '<mailto:equipe@kxkm.net?subject=STOP>', hdr($copy, 'List-Unsubscribe'));
check('cid: no one-click header without a URL', null, hdr($copy, 'List-Unsubscribe-Post'));
check('cid: Precedence', 'list', hdr($copy, 'Precedence'));
check('cid: X-Loop', 'equipe@kxkm.net', hdr($copy, 'X-Loop'));
check('cid: X-list-relay (dispatch round-back)', 'equipe@kxkm.net', hdr($copy, 'X-list-relay'));
check('cid: X-Original-From', 'René François <Rene@Example.FR>', hdr($copy, 'X-Original-From'));
check('cid: body byte for byte', body($raw), body($copy));
check('cid: the body still holds the cid: reference', true, strpos(body($copy), 'cid:montage-1@example.fr') !== false);
check('cid: no "Message envoyé par" header', false, strpos($copy, 'Message envoy') !== false);
check('cid: every header line ends CRLF', 0, preg_match('/[^\r]\n/', substr($copy, 0, strlen($copy) - strlen(body($copy)))));
check('cid: original To/Cc', array('equipe@kxkm.net', 'ext@other.org'), ListRelay::originalRecipients($raw));
check('cid: journal key is the Message-ID', '<photos-1@example.fr>', ListRelay::messageKey($raw));

// --- RFC 2231 name, no display name, encoded subject without the tag --------------------------------
$raw = fixture('rfc2231-name.eml');
$copy = ListRelay::copy($raw, 'equipe', 'equipe@kxkm.net', true, 'https://listes.kxkm.net/u/abc');
check('2231: From falls back to the address', '"alice@example.org via equipe" <equipe@kxkm.net>', trim(substr(fields($copy, 'From')[0], 5)));
check('2231: subject gets the tag, re-encoded', "[equipe] Programme de l'été", hdr($copy, 'Subject'));
check('2231: the new subject is RFC 2047, ASCII only', 0, preg_match('/[^\x00-\x7F]/', fields($copy, 'Subject')[0]));
check('2231: one-click URL first, mailto after', '<https://listes.kxkm.net/u/abc>, <mailto:equipe@kxkm.net?subject=STOP>', hdr($copy, 'List-Unsubscribe'));
check('2231: List-Unsubscribe-Post with a URL', 'List-Unsubscribe=One-Click', hdr($copy, 'List-Unsubscribe-Post'));
check('2231: Reply-To added after From', 1, preg_match('/^From:[^\r]*\r\nReply-To: equipe@kxkm\.net\r\n/m', $copy));
check('2231: body byte for byte', body($raw), body($copy));
check('2231: the RFC 2231 name is still in the body', true, strpos(body($copy), "filename*=UTF-8''programme%20%C3%A9t%C3%A9%202026.pdf") !== false);

// --- three image.png, broadcast list: Reply-To the sender's own, Sender dropped ---------------------
$raw = fixture('three-image-png.eml');
$copy = ListRelay::copy($raw, 'affiches', 'affiches@kxkm.net', false);
check('png: From', '"Bob via affiches" <affiches@kxkm.net>', hdr($copy, 'From'));
check('png: broadcast Reply-To = the sender\'s Reply-To, as written', 'Reply-To: Bob Perso <bob.perso@example.net>' . "\r\n", fields($copy, 'Reply-To')[0]);
check('png: Sender dropped', array(), fields($copy, 'Sender'));
check('png: old rebuild tag replaced', '[affiches] Les affiches', hdr($copy, 'Subject'));
check('png: body byte for byte', body($raw), body($copy));
check('png: three image.png parts', 3, substr_count(body($copy), 'filename="image.png"'));

// --- broadcast list, no Reply-To: the From as written ------------------------------------------------
$copy = ListRelay::copy(fixture('inline-cid-image.eml'), 'tous', 'tous@kxkm.net', false);
check('broadcast: Reply-To = original From, as written', 'Reply-To: =?ISO-8859-1?Q?Ren=E9_Fran=E7ois?= <Rene@Example.FR>' . "\r\n", fields($copy, 'Reply-To')[0]);
check('broadcast: the tag of another list stays, ours added', 'Re: [tous] [equipe] Photos du montage', hdr($copy, 'Subject'));

// --- a quote in a display name is escaped ----------------------------------------------------------
$copy = ListRelay::copy("From: \"Jo \\\"le chef\\\"\" <jo@x.org>\r\nSubject: x\r\n\r\nbody\r\n", 'equipe', 'equipe@kxkm.net', true);
check('quoted name escaped', 'From: "Jo \"le chef\" via equipe" <equipe@kxkm.net>' . "\r\n", fields($copy, 'From')[0]);

// --- the envelope ------------------------------------------------------------------------------------
list($rcpts, $skipped) = ListRelay::recipients(
    array('A@kxkm.net', 'rene@example.fr', 'ext@other.org', 'blocked@x.org', 'tous@kxkm.net', 'b@kxkm.net', 'a@kxkm.net', 'd@kxkm.net', 'listes@kxkm.net'),
    'Rene@Example.FR',
    array('equipe@kxkm.net', 'ext@other.org'),
    array('blocked@x.org' => 550),
    array('d@kxkm.net'),
    array('equipe@kxkm.net', 'tous@kxkm.net', 'listes@kxkm.net')
);
check('envelope: subscribers minus sender/original/blocked/other list/own, once each', array('a@kxkm.net', 'b@kxkm.net'), $rcpts);
check('envelope: why each was left out', array(
    'rene@example.fr' => 'sender', 'ext@other.org' => 'original', 'blocked@x.org' => 'blocked',
    'tous@kxkm.net' => 'own', 'd@kxkm.net' => 'other-list', 'listes@kxkm.net' => 'own',
), $skipped);
check('journal key without Message-ID', 'sha1:' . sha1("Subject: x\r\n\r\nbody\r\n"), ListRelay::messageKey("Subject: x\r\n\r\nbody\r\n"));

// ============================================================================ forward() ==========

/** TrackingSMTP whose "server" is a socket the test holds. */
class ScriptedSMTP extends TrackingSMTP
{
    public $socket = false;

    protected function getSMTPConnection($host, $port = null, $timeout = 30, $options = [])
    {
        return $this->socket;
    }
}

class FakeBlocklist extends BlocklistManager
{
    public $blocked = array();

    public function __construct()
    {
    }

    public function block(string $email, int $code = 550): bool
    {
        $this->blocked[$email] = $code;
        return true;
    }
}

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

class FakeListManager extends ListManager
{
    public $used = array();

    public function __construct()
    {
    }

    public function markAsUsedByName(string $name): void
    {
        $this->used[] = $name;
    }
}

class FakeImap
{
    public function hasMailBox($box)
    {
        return true;
    }
}

/** The journal on an array. */
class MemoryJournal extends RelayJournal
{
    public $rows = array();

    public function __construct()
    {
    }

    protected function load(string $key): array
    {
        return $this->rows[$key] ?? array();
    }

    protected function save(string $key, string $liste, array $row): void
    {
        $this->rows[$key][$liste] = $row;
    }
}

/** What forward() reads of a Fetch\Message, from the raw bytes. */
class FakeMessage
{
    public $moved = array();
    private $raw;
    private $h;

    public function __construct($raw)
    {
        $this->raw = $raw;
        list($header) = MimeParser::splitMessage($raw);
        $this->h = MimeParser::headerObject($header);
    }

    public function getRawMessage()
    {
        return $this->raw;
    }

    public function getAddresses($type)
    {
        if (!isset($this->h->$type)) {
            return false;
        }
        $out = array();
        foreach ($this->h->$type as $a) {
            if (isset($a->mailbox, $a->host)) {
                $out[] = array('address' => $a->mailbox . '@' . $a->host) + (isset($a->personal) ? array('name' => $a->personal) : array());
            }
        }
        return $type === 'from' ? $out[0] : $out;
    }

    public function moveToMailBox($box)
    {
        $this->moved[] = $box;
        return true;
    }
}

function liste($nom, $reponse, array $abonnes, $active = 1)
{
    return array('nom' => $nom, 'email' => $nom . '@kxkm.net', 'reponse' => $reponse, 'moderation' => 0,
        'active' => $active, 'abonnes' => $abonnes);
}

/** A processor with no database, IMAP or pause between retries; its lists indexed as loadListes() does. */
function processor(array $listes, MemoryJournal $journal, FakeBlocklist $blocklist, array $blocked = array())
{
    $rc = new ReflectionClass('MailProcessor');
    $mp = $rc->newInstanceWithoutConstructor();
    $byAddress = array();
    foreach ($listes as $l) {
        $byAddress[$l['email']] = $l;
    }
    $set = array(
        'startTime' => microtime(true),
        'lastLogTime' => microtime(true),
        'retryDelay' => 0.0,
        'blocklistManager' => $blocklist,
        'listManager' => new FakeListManager(),
        'journal' => $journal,
        'imap' => new FakeImap(),
        'db' => new FakeDatabase(),
        'smtpHost' => 'localhost',
        'smtpPort' => 25,
        'username' => 'listes@kxkm.net',
        'adminmail' => 'admin@kxkm.net',
        'domains' => array('kxkm.net'),
        'listes' => $byAddress,
        'blocklist' => $blocked,
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

/** Points the processor's connection at a fresh scripted server; returns the server end. */
function server($mp, $script)
{
    list($client, $server) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, $script);
    stream_socket_shutdown($server, STREAM_SHUT_WR);
    $mail = call($mp, 'getSmtpConnection');
    $smtp = new ScriptedSMTP();
    $smtp->socket = $client;
    $mail->setSMTPInstance($smtp);
    $mail->SMTPAuth = false;
    $mail->SMTPAutoTLS = false;
    $mail->Timeout = 2;
    return $server;
}

function sent($server)
{
    stream_set_blocking($server, false);
    $bytes = (string) stream_get_contents($server);
    fclose($server);
    return $bytes;
}

/** Each transaction the client sent: ['from' => MAIL FROM, 'rcpt' => [...], 'data' => message bytes as received, or null]. */
function transactions($bytes)
{
    $out = array();
    foreach (array_slice(explode("MAIL FROM:", $bytes), 1) as $t) {
        preg_match_all('/^RCPT TO:<([^>]+)>/m', $t, $m);
        $data = null;
        if (preg_match('/\r\nDATA\r\n(.*?\r\n)\.\r\n/s', $t, $d)) {
            $data = preg_replace('/(^|\r\n)\./', '$1', $d[1]);   // undo the dot-stuffing
        }
        preg_match('/^<([^>]*)>/', $t, $f);
        $out[] = array('from' => $f[1] ?? null, 'rcpt' => $m[1], 'data' => $data);
    }
    return $out;
}

$greet = "220 mail.test ESMTP\r\n250-mail.test\r\n250 8BITMIME\r\n";
$ok = "250 2.1.5 Ok\r\n";
$unknown = "550 5.1.1 <b@kxkm.net>: Recipient address rejected: User unknown\r\n";
$later = "450 4.2.0 <b@kxkm.net>: Recipient address rejected: try again later\r\n";
/** Replies to one transaction: MAIL, each RCPT, DATA + end of data if any RCPT was taken, then RSET. */
function txn(array $rcptReplies, $dataReply = "250 2.0.0 Ok: queued as 4ABC\r\n")
{
    $s = "250 2.1.0 Ok\r\n" . implode('', $rcptReplies);
    foreach ($rcptReplies as $r) {
        if ($r[0] === '2') {
            return $s . "354 End data with <CR><LF>.<CR><LF>\r\n" . $dataReply . "250 2.0.0 Ok\r\n";
        }
    }
    return $s . "250 2.0.0 Ok\r\n";
}

// --- one list: the copy goes out once, to the right envelope, byte for byte ------------------------
$raw = fixture('inline-cid-image.eml');
$journal = new MemoryJournal();
$bl = new FakeBlocklist();
$mp = processor(array(liste('equipe', 1, array('rene@example.fr', 'a@kxkm.net', 'ext@other.org', 'blocked@x.org', 'tous@kxkm.net', 'b@kxkm.net', 'listes@kxkm.net')),
    liste('tous', 0, array())), $journal, $bl, array('blocked@x.org' => 550));
$server = server($mp, $greet . txn(array($ok, $ok)));
$msg = new FakeMessage($raw);
call($mp, 'forward', $msg);
$t = transactions(sent($server));
check('one list: one transaction', 1, count($t));
check('one list: envelope = subscribers minus sender, Cc, blocked, own', array('a@kxkm.net', 'b@kxkm.net'), $t[0]['rcpt']);
check('one list: DATA is the relay copy, byte for byte', ListRelay::copy($raw, 'equipe', 'equipe@kxkm.net', true), $t[0]['data']);
check('one list: the body reached DATA byte for byte', body($raw), body((string) $t[0]['data']));
check('one list: moved to DONE', array('DONE'), $msg->moved);
check('one list: journal', array('state' => 'sent', 'recipients' => array('a@kxkm.net', 'b@kxkm.net'), 'refused' => array(), 'attempts' => 1, 'error' => ''),
    $journal->rows['<photos-1@example.fr>']['equipe']);
check('one list: MAIL FROM the mailbox', 'listes@kxkm.net', $t[0]['from']);

// --- the three image.png and the RFC 2231 name reach DATA untouched too -------------------------------
foreach (array('three-image-png.eml' => 'affiches', 'rfc2231-name.eml' => 'equipe') as $file => $nom) {
    $raw = fixture($file);
    $journal = new MemoryJournal();
    $mp = processor(array(liste($nom, 0, array('a@kxkm.net'))), $journal, new FakeBlocklist());
    $server = server($mp, $greet . txn(array($ok)));
    call($mp, 'forward', new FakeMessage($raw));
    $t = transactions(sent($server));
    check("$file: body reached DATA byte for byte", body($raw), body((string) ($t[0]['data'] ?? '')));
}

// --- two lists: whoever is on both gets one copy, each list its own headers ---------------------------
$raw = fixture('rfc2231-name.eml');    // To: equipe@, tous@
$journal = new MemoryJournal();
$listes = array(liste('equipe', 1, array('a@kxkm.net', 'b@kxkm.net', 'c@kxkm.net')), liste('tous', 0, array('b@kxkm.net', 'c@kxkm.net', 'd@kxkm.net')));
$mp = processor($listes, $journal, new FakeBlocklist());
$server = server($mp, $greet . txn(array($ok, $ok, $ok)) . txn(array($ok)));
$msg = new FakeMessage($raw);
call($mp, 'forward', $msg);
$t = transactions(sent($server));
check('two lists: two transactions', 2, count($t));
check('two lists: equipe gets its subscribers', array('a@kxkm.net', 'b@kxkm.net', 'c@kxkm.net'), $t[0]['rcpt']);
check('two lists: tous only those equipe did not reach', array('d@kxkm.net'), $t[1]['rcpt']);
check('two lists: equipe copy From', '"alice@example.org via equipe" <equipe@kxkm.net>', hdr((string) $t[0]['data'], 'From'));
check('two lists: tous copy From', '"alice@example.org via tous" <tous@kxkm.net>', hdr((string) $t[1]['data'], 'From'));
check('two lists: tous copy Reply-To the sender', 'alice@example.org', hdr((string) $t[1]['data'], 'Reply-To'));
check('two lists: moved to DONE once', array('DONE'), $msg->moved);

// --- list 2 fails: no DONE; the retry sends list 2 only, still without list 1's recipients ------------
$journal = new MemoryJournal();
$mp = processor($listes, $journal, new FakeBlocklist());
$refusedData = "554 5.7.1 Message rejected\r\n";
$server = server($mp, $greet . txn(array($ok, $ok, $ok)) . str_repeat(txn(array($ok), $refusedData), 3));
$msg = new FakeMessage($raw);
call($mp, 'forward', $msg);
$t = transactions(sent($server));
check('list 2 fails: equipe once, tous tried 3 times', array(3, 1, 1, 1), array(count($t[0]['rcpt']), count($t[1]['rcpt']), count($t[2]['rcpt']), count($t[3]['rcpt'])));
check('list 2 fails: stays in APPROVED', array(), $msg->moved);
check('list 2 fails: journal', array('sent', 'failed', 1), array(
    $journal->rows['<programme-ete@example.org>']['equipe']['state'],
    $journal->rows['<programme-ete@example.org>']['tous']['state'],
    $journal->rows['<programme-ete@example.org>']['tous']['attempts'],
));
// the next run: a new connection, the same journal
$mp = processor($listes, $journal, new FakeBlocklist());
$server = server($mp, $greet . txn(array($ok)));
call($mp, 'forward', $msg);
$t = transactions(sent($server));
check('retry: one transaction, tous only', 1, count($t));
check('retry: tous still without equipe\'s recipients', array('d@kxkm.net'), $t[0]['rcpt']);
check('retry: tous copy', '"alice@example.org via tous" <tous@kxkm.net>', hdr((string) $t[0]['data'], 'From'));
check('retry: moved to DONE', array('DONE'), $msg->moved);
check('retry: tous sent on its 2nd attempt', array('sent', 2), array(
    $journal->rows['<programme-ete@example.org>']['tous']['state'],
    $journal->rows['<programme-ete@example.org>']['tous']['attempts'],
));
// and a third run sends nothing
$mp = processor($listes, $journal, new FakeBlocklist());
$server = server($mp, '');
$msg->moved = array();
call($mp, 'forward', $msg);
check('third run: nothing sent', '', sent($server));
check('third run: DONE', array('DONE'), $msg->moved);

// --- one refused RCPT: sent once to the others, logged in the journal, unknown blocked -----------------
$raw = fixture('three-image-png.eml');
$journal = new MemoryJournal();
$bl = new FakeBlocklist();
$mp = processor(array(liste('affiches', 0, array('a@kxkm.net', 'b@kxkm.net', 'c@kxkm.net'))), $journal, $bl);
$server = server($mp, $greet . str_repeat(txn(array($ok, $unknown, $ok)), 3));
$msg = new FakeMessage($raw);
call($mp, 'forward', $msg);
$t = transactions(sent($server));
check('refused: one transaction', 1, count($t));
check('refused: DATA once', true, $t[0]['data'] !== null);
check('refused: journal has who took it and who was refused', array(
    'recipients' => array('a@kxkm.net', 'c@kxkm.net'),
    'refused' => array('b@kxkm.net' => '550 5.1.1 <b@kxkm.net>: Recipient address rejected: User unknown'),
), array_intersect_key($journal->rows['<affiches-3@example.net>']['affiches'], array('recipients' => 1, 'refused' => 1)));
check('refused: unknown blocked', array('b@kxkm.net' => 550), $bl->blocked);
check('refused: DONE', array('DONE'), $msg->moved);

// --- every recipient refused for good: nothing to resend, DONE; for now (4xx): retried, kept --------------
$journal = new MemoryJournal();
$bl = new FakeBlocklist();
$mp = processor(array(liste('affiches', 0, array('b@kxkm.net'))), $journal, $bl);
$server = server($mp, $greet . str_repeat(txn(array($unknown)), 3));
$msg = new FakeMessage($raw);
call($mp, 'forward', $msg);
$t = transactions(sent($server));
check('all refused (5xx): tried once, no DATA', array(1, null), array(count($t), $t[0]['data']));
check('all refused (5xx): DONE', array('DONE'), $msg->moved);
check('all refused (5xx): blocked', array('b@kxkm.net' => 550), $bl->blocked);

$journal = new MemoryJournal();
$bl = new FakeBlocklist();
$mp = processor(array(liste('affiches', 0, array('b@kxkm.net'))), $journal, $bl);
$server = server($mp, $greet . str_repeat(txn(array($later)), 3));
$msg = new FakeMessage($raw);
call($mp, 'forward', $msg);
check('all refused (4xx): tried 3 times', 3, count(transactions(sent($server))));
check('all refused (4xx): kept in APPROVED', array(), $msg->moved);
check('all refused (4xx): journal failed', 'failed', $journal->rows['<affiches-3@example.net>']['affiches']['state']);
check('all refused (4xx): nobody blocked', array(), $bl->blocked);

// --- nobody left to send to (the sender is the only subscriber): no SMTP at all, DONE ------------------
$journal = new MemoryJournal();
$mp = processor(array(liste('affiches', 0, array('bob@example.net'))), $journal, new FakeBlocklist());
$server = server($mp, '');
$msg = new FakeMessage($raw);
call($mp, 'forward', $msg);
check('nobody: nothing sent', '', sent($server));
check('nobody: DONE', array('DONE'), $msg->moved);
check('nobody: journal sent to no one', array('sent', array()), array(
    $journal->rows['<affiches-3@example.net>']['affiches']['state'],
    $journal->rows['<affiches-3@example.net>']['affiches']['recipients'],
));

// --- an inactive list only: kept in APPROVED, as before -------------------------------------------------
$journal = new MemoryJournal();
$mp = processor(array(liste('affiches', 0, array('a@kxkm.net'), 0)), $journal, new FakeBlocklist());
$server = server($mp, '');
$msg = new FakeMessage($raw);
call($mp, 'forward', $msg);
check('inactive: nothing sent', '', sent($server));
check('inactive: kept in APPROVED', array(), $msg->moved);

// ============================================================================ journal SQL ==========

if (extension_loaded('pdo_sqlite')) {
    $pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    RelayJournal::createTable($pdo);
    RelayJournal::createTable($pdo);    // twice: a migration on a database that has it
    $j = new RelayJournal($pdo);
    $j->markFailed('<m@x>', 'tous', 'DATA refused');
    check('sql: failed is not sent', false, $j->isSent('<m@x>', 'tous'));
    $created = $pdo->query("SELECT created_at FROM relay_journal")->fetchColumn();
    $j->markSent('<m@x>', 'tous', array('d@kxkm.net'), array('e@kxkm.net' => '550 5.1.1 unknown'));
    $j->markSent('<m@x>', 'equipe', array('a@kxkm.net', 'b@kxkm.net'), array());
    check('sql: sent', true, $j->isSent('<m@x>', 'tous'));
    check('sql: another message is not', false, $j->isSent('<n@x>', 'tous'));
    $reached = $j->reached('<m@x>');
    sort($reached);
    check('sql: reached = taken + refused of the sent lists', array('a@kxkm.net', 'b@kxkm.net', 'd@kxkm.net', 'e@kxkm.net'), $reached);
    check('sql: one row per message and list', 2, (int) $pdo->query("SELECT COUNT(*) FROM relay_journal")->fetchColumn());
    check('sql: attempts counted', 2, (int) $pdo->query("SELECT attempts FROM relay_journal WHERE liste = 'tous'")->fetchColumn());
    check('sql: created_at kept', $created, $pdo->query("SELECT created_at FROM relay_journal WHERE liste = 'tous'")->fetchColumn());
    check('sql: refused stored', '{"e@kxkm.net":"550 5.1.1 unknown"}', $pdo->query("SELECT refused FROM relay_journal WHERE liste = 'tous'")->fetchColumn());
} else {
    echo "relay_test: no pdo_sqlite here, the journal's SQL is not run (the array journal stands in)\n";
}

echo "relay_test: $checks checks, $failures failure(s)\n";
exit($failures ? 1 : 0);
