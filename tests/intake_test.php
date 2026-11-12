<?php
/**
 * Tests for the intake (#t-073, decided 2026-11-12 with the relay): who may post (ListIntake),
 * what MailProcessor::dispatch() does with it, and the moderation links.
 *
 *   ① a subscriber posts only with dmarc=pass in the TOP Authentication-Results of the provider;
 *     no pass → PENDING, and no notice to an unverified sender
 *   ② a verified non-subscriber answering a message the list relayed: relayed on a discussion
 *     list, PENDING on a broadcast list
 *   ③ automatic mail → OTHERS, silently; STOP works for every list's subscribers
 *   ④ the moderation mail links to a page (no cron key, an HMAC token); the page's POST acts
 *
 * dispatch() runs on a processor with no database, IMAP or SMTP: notices are captured.
 * Run: php tests/intake_test.php   (exit 0 = all green)
 */

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/lib/PHPMailer/load.php';
require_once ROOT_PATH . '/lib/Fetch/MimeParser.php';
require_once ROOT_PATH . '/src/TrackingSMTP.php';
require_once ROOT_PATH . '/src/Database.php';
require_once ROOT_PATH . '/src/BlocklistManager.php';
require_once ROOT_PATH . '/src/ListManager.php';
require_once ROOT_PATH . '/src/ListRelay.php';
require_once ROOT_PATH . '/src/ListIntake.php';
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

const PASS = "Authentication-Results: mx.infomaniak.com; dkim=pass (2048-bit key) header.d=example.org;\r\n spf=pass smtp.mailfrom=example.org;\r\n dmarc=pass (p=NONE sp=NONE dis=NONE) header.from=example.org";
const FAIL = "Authentication-Results: mx.infomaniak.com; spf=fail smtp.mailfrom=example.org; dmarc=fail (p=REJECT) header.from=example.org";

/** A message as the mailbox holds it: CRLF, the given header lines first. */
function msg(array $headers, $subject = 'Repas jeudi', $body = "Bonjour\r\n")
{
    $defaults = array(
        'From' => 'From: Alice <alice@example.org>',
        'To' => 'To: equipe@kxkm.net',
        'Subject' => 'Subject: ' . $subject,
        'Message-ID' => 'Message-ID: <m1@example.org>',
        'Date' => 'Date: Thu, 12 Nov 2026 09:00:00 +0100',
    );
    $lines = array();
    foreach ($headers as $k => $h) {
        if (is_string($k)) {
            $defaults[$k] = $h;
        } else {
            $lines[] = $h;
        }
    }
    foreach ($defaults as $h) {
        if ($h !== null) {
            $lines[] = $h;
        }
    }
    return implode("\r\n", $lines) . "\r\n\r\n" . $body;
}

// ============================================================================ ① DMARC ==========

check('dmarc: the provider\'s pass on top', true, ListIntake::dmarcPass(msg(array(PASS)), 'mx.infomaniak.com', 'alice@example.org'));
check('dmarc: no stamp at all', false, ListIntake::dmarcPass(msg(array())));
check('dmarc: the provider says fail', false, ListIntake::dmarcPass(msg(array(FAIL))));
check('dmarc: dmarc=none (no record) is not a pass', false,
    ListIntake::dmarcPass(msg(array('Authentication-Results: mx.infomaniak.com; dmarc=none header.from=example.org'))));
check('dmarc: a forged pass BELOW the provider\'s fail', false, ListIntake::dmarcPass(msg(array(FAIL, PASS))));
check('dmarc: a pass written by another host on top', false,
    ListIntake::dmarcPass(msg(array('Authentication-Results: mx.example.org; dmarc=pass header.from=example.org', PASS))));
check('dmarc: an authserv-id that only starts like the provider\'s', false,
    ListIntake::dmarcPass(msg(array('Authentication-Results: mx.infomaniak.com.evil.org; dmarc=pass header.from=example.org'))));
check('dmarc: "dmarc=pass" inside a comment only', false,
    ListIntake::dmarcPass(msg(array('Authentication-Results: mx.infomaniak.com; spf=fail (dmarc=pass) smtp.mailfrom=example.org'))));
check('dmarc: a pass hidden in a comment before the real result', false,
    ListIntake::dmarcPass(msg(array('Authentication-Results: mx.infomaniak.com (relay; dmarc=pass) ; dmarc=fail header.from=example.org'))));
check('dmarc: a comment after the id', true,
    ListIntake::dmarcPass(msg(array('Authentication-Results: mx.infomaniak.com (Infomaniak "MX"); dmarc=pass (p=none) header.from=example.org')), 'mx.infomaniak.com', 'alice@example.org'));
check('dmarc: pass for another domain than the sender\'s', false,
    ListIntake::dmarcPass(msg(array('Authentication-Results: mx.infomaniak.com; dmarc=pass header.from=evil.org')), 'mx.infomaniak.com', 'alice@example.org'));
check('dmarc: header name in any case, version after the id', true,
    ListIntake::dmarcPass(msg(array('authentication-results: MX.Infomaniak.com 1; DMARC=Pass header.from=Example.ORG')), 'mx.infomaniak.com', 'alice@example.org'));
check('dmarc: no header.from property: the pass stands', true,
    ListIntake::dmarcPass(msg(array('Authentication-Results: mx.infomaniak.com; dmarc=pass')), 'mx.infomaniak.com', 'alice@example.org'));
check('dmarc: another authserv-id configured', true,
    ListIntake::dmarcPass(msg(array('Authentication-Results: mx.ovh.net; dmarc=pass header.from=example.org')), 'mx.ovh.net', 'alice@example.org'));
check('dmarc: a stamp in the body is not a header', false,
    ListIntake::dmarcPass(msg(array(), 'x', PASS . "\r\n")));

// ============================================================================ ③ automatic ==========

$own = array('equipe@kxkm.net', 'tous@kxkm.net', 'listes@kxkm.net');
$auto = array(
    'Auto-Submitted: auto-replied' => 'auto-submitted: auto-replied',
    'Auto-Submitted: auto-generated (vacation)' => 'auto-submitted: auto-generated',
    'Precedence: bulk' => 'precedence: bulk',
    'Precedence: junk' => 'precedence: junk',
    'Precedence: list' => 'precedence: list',
    'X-Autoreply: yes' => 'x-autoreply',
    'X-Autorespond: Out of office' => 'x-autorespond',
    'X-Loop: equipe@kxkm.net' => 'x-loop: equipe@kxkm.net',
    'X-Loop: <Tous@KXKM.net>' => 'x-loop: tous@kxkm.net',
);
foreach ($auto as $h => $why) {
    check("automatic: $h", $why, ListIntake::automatic(msg(array($h)), $own));
}
foreach (array('Auto-Submitted: no', 'Auto-Submitted: No (a person)', 'Precedence: first-class', 'X-Loop: other@example.net') as $h) {
    check("not automatic: $h", null, ListIntake::automatic(msg(array($h)), $own));
}
check('not automatic: a plain message', null, ListIntake::automatic(msg(array()), $own));

// ============================================================================ thread, STOP ==========

check('thread: In-Reply-To first, then References newest first, each once',
    array('<c@x>', '<b@x>', '<a@x>'),
    ListIntake::threadIds(msg(array("References: <a@x>\r\n <b@x> <c@x>", 'In-Reply-To: <c@x> (Alice)'))));
check('thread: none', array(), ListIntake::threadIds(msg(array())));
$many = 'References: ' . implode(' ', array_map(fn($i) => "<r$i@x>", range(1, 50)));
check('thread: capped', ListIntake::MAX_THREAD_IDS, count(ListIntake::threadIds(msg(array($many)))));
foreach (array('STOP' => true, ' stop ' => true, 'Stop' => true, 'Re: STOP' => false, 'STOP please' => false, '' => false) as $s => $want) {
    check("stop: '$s'", $want, ListIntake::isStop($s));
}

// ============================================================================ decide() ==========

function L($nom, array $abonnes, $reponse = 1, $moderation = 0, $active = 1)
{
    return array('nom' => $nom, 'email' => $nom . '@kxkm.net', 'reponse' => $reponse, 'moderation' => $moderation,
        'active' => $active, 'abonnes' => $abonnes);
}

$equipe = L('equipe', array('alice@example.org', 'bob@example.org'));             // discussion
$annonces = L('annonces', array('alice@example.org', 'carol@example.org'), 0);    // broadcast
$bureau = L('bureau', array('dan@example.org'), 1, 1);                            // moderated
$all = array('alice@example.org', 'bob@example.org', 'carol@example.org');        // the unmoderated lists'
$none = fn($nom) => false;
$yes = fn($nom) => true;

function d($from, $verified, $matched, $relayed, $auto = null, $stop = false)
{
    global $all;
    $v = ListIntake::decide($from, $verified, $auto, $stop, $matched, $all, $relayed);
    return array($v['folder'], $v['why'], $v['notify']);
}

check('① subscriber verified → APPROVED', array('APPROVED', '', null), d('alice@example.org', true, array($equipe), $none));
check('① subscriber unverified → PENDING, no notice', array('PENDING', 'unverified', null), d('Alice@Example.org', false, array($equipe), $none));
check('① subscriber verified, moderated list → PENDING', array('PENDING', 'moderated', null), d('alice@example.org', true, array($bureau), $none));
check('① subscriber of another list (cross-list send) → APPROVED', array('APPROVED', '', null), d('carol@example.org', true, array($equipe), $none));
check('① non-subscriber verified → DISCARDED, notice', array('DISCARDED', 'not-subscriber', 'not-subscriber'), d('eve@other.org', true, array($equipe), $none));
check('① non-subscriber unverified → DISCARDED, NO notice', array('DISCARDED', 'not-subscriber', null), d('eve@other.org', false, array($equipe), $none));
check('② thread reply, discussion list → APPROVED', array('APPROVED', '', null), d('ext@other.org', true, array($equipe), $yes));
check('② thread reply, broadcast list → PENDING', array('PENDING', 'outside-broadcast', null), d('ext@other.org', true, array($annonces), $yes));
check('② thread reply, moderated list → PENDING', array('PENDING', 'moderated', null), d('ext@other.org', true, array($bureau), $yes));
check('② thread reply, unverified → DISCARDED, no notice', array('DISCARDED', 'not-subscriber', null), d('ext@other.org', false, array($equipe), $yes));
check('② thread of one list, also names another → PENDING', array('PENDING', 'outside-other-list', null),
    d('ext@other.org', true, array($equipe, L('tous', array())), fn($nom) => $nom === 'equipe'));
check('③ automatic → OTHERS, silently', array('OTHERS', 'automatic: precedence: bulk', null), d('alice@example.org', true, array($equipe), $none, 'precedence: bulk'));
check('no list named → OTHERS', array('OTHERS', 'no-list', null), d('alice@example.org', true, array(), $none));
check('inactive only, verified → DISCARDED, inactive notice', array('DISCARDED', 'inactive', 'inactive'),
    d('alice@example.org', true, array(L('vieux', array('alice@example.org'), 1, 0, 0)), $none));
check('inactive only, unverified → DISCARDED, no notice', array('DISCARDED', 'inactive', null),
    d('alice@example.org', false, array(L('vieux', array('alice@example.org'), 1, 0, 0)), $none));
check('active + inactive → APPROVED, inactive notice', array('APPROVED', '', 'inactive'),
    d('alice@example.org', true, array($equipe, L('vieux', array(), 1, 0, 0)), $none));

$stop = ListIntake::decide('dan@example.org', true, null, true, array($bureau), $all, $none);
check('③ STOP from a moderated list\'s subscriber → DONE, unsubscribed', array('DONE', array('bureau'), array()),
    array($stop['folder'], $stop['unsubscribe'], $stop['stopUnverified']));
$stop = ListIntake::decide('dan@example.org', false, null, true, array($bureau), $all, $none);
check('③ STOP unverified → DISCARDED, nobody removed', array('DISCARDED', array(), array('bureau'), null),
    array($stop['folder'], $stop['unsubscribe'], $stop['stopUnverified'], $stop['notify']));
$stop = ListIntake::decide('eve@other.org', true, null, true, array($equipe), $all, $none);
check('③ STOP from someone not on the list → DISCARDED, silently', array('DISCARDED', 'stop-not-member', array(), null),
    array($stop['folder'], $stop['why'], $stop['unsubscribe'], $stop['notify']));
$stop = ListIntake::decide('alice@example.org', true, null, true, array($equipe, $annonces, $bureau), $all, $none);
check('③ STOP to three lists leaves the two that have the sender', array('equipe', 'annonces'), $stop['unsubscribe']);
$stop = ListIntake::decide('alice@example.org', true, 'auto-submitted: auto-replied', true, array($equipe), $all, $none);
check('③ an automatic "STOP" removes nobody', array('OTHERS', array()), array($stop['folder'], $stop['unsubscribe']));

// ============================================================================ ④ tokens ==========

$id = ListIntake::moderationId('<m1@example.org>');
$t = ListIntake::token('s3cret', 'moderate', $id);
check('token: verifies', true, ListIntake::checkToken('s3cret', $t, 'moderate', $id));
check('token: another message', false, ListIntake::checkToken('s3cret', $t, 'moderate', ListIntake::moderationId('<m2@example.org>')));
check('token: another purpose', false, ListIntake::checkToken('s3cret', $t, 'unsubscribe', $id));
check('token: another secret', false, ListIntake::checkToken('other', $t, 'moderate', $id));
check('token: empty secret never verifies', false, ListIntake::checkToken('', ListIntake::token('', 'moderate', $id), 'moderate', $id));
check('token: empty token', false, ListIntake::checkToken('s3cret', '', 'moderate', $id));
check('token: tampered', false, ListIntake::checkToken('s3cret', substr($t, 0, -1) . ($t[63] === '0' ? '1' : '0'), 'moderate', $id));

// ============================================================================ dispatch() ==========

/** dispatch() on no SMTP: what it would have mailed is kept. */
class CapturingProcessor extends MailProcessor
{
    public $notices = array();

    protected function notify(string $subject, string $to, string $html, string $context): void
    {
        $this->notices[] = array('to' => $to, 'subject' => $subject, 'html' => $html);
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
    public $removed = array();

    public function __construct()
    {
    }

    public function removeSubscriberFromList(string $listName, string $email): bool
    {
        $this->removed[] = "$listName:$email";
        return true;
    }
}

class FakeImap
{
    public function hasMailBox($box)
    {
        return true;
    }
}

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

/** What dispatch() reads of a Fetch\Message, from the raw bytes. */
class FakeMessage
{
    public $moved = array();
    private $raw;
    private $h;
    private $header;
    private $body;

    public function __construct($raw)
    {
        $this->raw = $raw;
        list($this->header, $this->body) = MimeParser::splitMessage($raw);
        $this->h = MimeParser::headerObject($this->header);
    }

    public function getRawMessage()
    {
        return $this->raw;
    }

    public function getRawHeaders()
    {
        return $this->header;
    }

    public function getMessageBody($html = false)
    {
        return $this->body;
    }

    public function getSubject()
    {
        return isset($this->h->subject) ? ListRelay::decode($this->h->subject) : null;
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

function processor(array $listes, MemoryJournal $journal, FakeListManager $lm)
{
    $rc = new ReflectionClass('MailProcessor');
    $mp = (new ReflectionClass('CapturingProcessor'))->newInstanceWithoutConstructor();
    $byAddress = array();
    $all = array();
    foreach ($listes as $l) {
        $byAddress[$l['email']] = $l;
        if (!$l['moderation']) {
            $all = array_merge($all, $l['abonnes']);
        }
    }
    $set = array(
        'startTime' => microtime(true),
        'lastLogTime' => microtime(true),
        'retryDelay' => 0.0,
        'blocklistManager' => new FakeBlocklist(),
        'listManager' => $lm,
        'journal' => $journal,
        'imap' => new FakeImap(),
        'db' => new FakeDatabase(),
        'username' => 'listes@kxkm.net',
        'adminmail' => 'admin@kxkm.net',
        'domains' => array('kxkm.net'),
        'listes' => $byAddress,
        'allAbonnes' => array_values(array_unique($all)),
        'blocklist' => array(),
        'linkSecret' => 's3cret',
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

/** dispatch() one message: [folder(s) it moved to, who got a notice, lists left] */
function dispatch(array $listes, $raw, ?MemoryJournal $journal = null)
{
    $lm = new FakeListManager();
    $mp = processor($listes, $journal ?: new MemoryJournal(), $lm);
    $msg = new FakeMessage($raw);
    call($mp, 'dispatch', $msg);
    return array($msg->moved, array_map(fn($n) => $n['to'] . ' ' . $n['subject'], $mp->notices), $lm->removed, $mp);
}

$listes = array($equipe, $annonces, $bureau);

list($moved, $notices) = dispatch($listes, msg(array(PASS)));
check('dispatch ①: verified subscriber → APPROVED, nobody mailed', array(array('APPROVED'), array()), array($moved, $notices));

list($moved, $notices, , $mp) = dispatch($listes, msg(array(FAIL)));
check('dispatch ①: forged subscriber → PENDING, only the admin mailed', array(array('PENDING'), array('admin@kxkm.net [Moderation] equipe')), array($moved, $notices));
$html = $mp->notices[0]['html'];
check('dispatch ④: the moderation mail says why', true, str_contains($html, 'être vérifiée (DMARC)'));
check('dispatch ④: no cron key, no action in the link', array(false, false, false), array(str_contains($html, 'key='), str_contains($html, 'cron.php'), str_contains($html, 'action=')));
check('dispatch ④: the link is the moderation page, signed', 1, preg_match(
    '#moderate\.php\?id=' . ListIntake::moderationId('<m1@example.org>') . '&amp;t=' . ListIntake::token('s3cret', 'moderate', ListIntake::moderationId('<m1@example.org>')) . '"#', $html));
check('dispatch ④: the link checks out', true, $mp->checkModerationToken(ListIntake::moderationId('<m1@example.org>'), ListIntake::token('s3cret', 'moderate', ListIntake::moderationId('<m1@example.org>'))));
check('dispatch ④: a made-up token does not', false, $mp->checkModerationToken(ListIntake::moderationId('<m1@example.org>'), str_repeat('0', 64)));

list($moved, $notices, , $mp) = dispatch($listes, msg(array(PASS), 'Hello', "<script>alert(1)</script>\r\n"));
// (verified, approved: no moderation mail) — the same body held for moderation is escaped:
list(, , , $mp) = dispatch($listes, msg(array(FAIL), '<b>x</b>', "<script>alert(1)</script>\r\n"));
check('dispatch ④: the moderation mail escapes subject and body', array(false, false), array(
    str_contains($mp->notices[0]['html'], '<script>'), str_contains($mp->notices[0]['html'], '<b>x</b>')));

list($moved, $notices) = dispatch($listes, msg(array(FAIL, 'From' => 'From: eve@other.org')));
check('dispatch ①: unverified stranger → DISCARDED, nobody mailed', array(array('DISCARDED'), array()), array($moved, $notices));

list($moved, $notices) = dispatch($listes, msg(array('Authentication-Results: mx.infomaniak.com; dmarc=pass header.from=other.org', 'From' => 'From: eve@other.org')));
check('dispatch ①: verified stranger → DISCARDED, told', array(array('DISCARDED'), array('eve@other.org [Listes] envoi non autorisé')), array($moved, $notices));

// ② the thread: equipe relayed <photos-1@example.fr>; ext answers it
$journal = new MemoryJournal();
$journal->markSent('<photos-1@example.fr>', 'equipe', array('bob@example.org'), array());
$journal->markSent('<annonce-1@example.fr>', 'annonces', array('carol@example.org'), array());
$extPass = 'Authentication-Results: mx.infomaniak.com; dmarc=pass header.from=other.org';
list($moved, $notices) = dispatch($listes, msg(array($extPass, 'From' => 'From: ext@other.org', 'In-Reply-To: <photos-1@example.fr>')), $journal);
check('dispatch ②: thread reply on a discussion list → APPROVED', array(array('APPROVED'), array()), array($moved, $notices));
list($moved, $notices) = dispatch($listes, msg(array($extPass, 'From' => 'From: ext@other.org', 'References: <old@x> <photos-1@example.fr>')), $journal);
check('dispatch ②: References is enough', array('APPROVED'), $moved);
list($moved, $notices) = dispatch($listes, msg(array($extPass, 'From' => 'From: ext@other.org', 'To' => 'To: annonces@kxkm.net', 'In-Reply-To: <annonce-1@example.fr>')), $journal);
check('dispatch ②: thread reply on a broadcast list → PENDING', array(array('PENDING'), array('admin@kxkm.net [Moderation] annonces')), array($moved, $notices));
list($moved, $notices) = dispatch($listes, msg(array($extPass, 'From' => 'From: ext@other.org', 'In-Reply-To: <annonce-1@example.fr>')), $journal);
check('dispatch ②: answering ANOTHER list\'s message is not this list\'s thread', array('DISCARDED'), $moved);
list($moved, $notices) = dispatch($listes, msg(array(FAIL, 'From' => 'From: ext@other.org', 'In-Reply-To: <photos-1@example.fr>')), $journal);
check('dispatch ②: unverified thread reply → DISCARDED, nobody mailed', array(array('DISCARDED'), array()), array($moved, $notices));

// ③ automatic and STOP
list($moved, $notices) = dispatch($listes, msg(array(PASS, 'Auto-Submitted: auto-replied'), 'Absent du bureau'));
check('dispatch ③: out-of-office → OTHERS, nobody mailed', array(array('OTHERS'), array()), array($moved, $notices));
list($moved, $notices) = dispatch($listes, msg(array(FAIL, 'From' => 'From: eve@other.org', 'X-Autoreply: yes')));
check('dispatch ③: an autoreply from a stranger → OTHERS, nobody mailed', array(array('OTHERS'), array()), array($moved, $notices));
list($moved, $notices, $removed) = dispatch($listes, msg(array('Authentication-Results: mx.infomaniak.com; dmarc=pass header.from=example.org',
    'From' => 'From: dan@example.org', 'To' => 'To: bureau@kxkm.net'), 'STOP'));
check('dispatch ③: STOP on a moderated list works', array(array('DONE'), array('admin@kxkm.net [Desinscription] bureau'), array('bureau:dan@example.org')),
    array($moved, $notices, $removed));
list($moved, $notices, $removed) = dispatch($listes, msg(array(FAIL, 'From' => 'From: dan@example.org', 'To' => 'To: bureau@kxkm.net'), 'STOP'));
check('dispatch ③: unverified STOP: nobody removed, the admin told, not the sender',
    array(array('DISCARDED'), array('admin@kxkm.net [Desinscription non vérifiée] bureau'), array()), array($moved, $notices, $removed));

// round-back and bounces, as before
list($moved, $notices) = dispatch($listes, msg(array('X-list-relay: equipe@kxkm.net', 'Precedence: list', 'X-Loop: equipe@kxkm.net', 'From' => 'From: "Alice via equipe" <equipe@kxkm.net>')));
check('dispatch: our own copy back → ARCHIVE', array(array('ARCHIVE'), array()), array($moved, $notices));
list($moved, $notices) = dispatch($listes, msg(array('Auto-Submitted: auto-replied', 'From' => 'From: MAILER-DAEMON@mx.example.net'), 'Undelivered',
    "550 5.1.1 <gone@example.net>: Recipient address rejected\r\n"));
check('dispatch: a bounce still blocks and reaches ERRORS', array(array('ERRORS'), array('admin@kxkm.net [Listes] adresse inconnue')), array($moved, $notices));

echo "intake_test: $checks checks, $failures failure(s)\n";
exit($failures ? 1 : 0);
