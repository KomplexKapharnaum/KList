<?php
/**
 * Tests for the web UI's lists (kxkm-infrastructure#t-077):
 *  - Erreurs and Refusés show the NEWEST messages first: Server::getMessageUids($n, true) asks for the
 *    last n sequence numbers (the folder runs oldest first), MailProcessor::newestFirst() sorts by date;
 *  - the sidebar's subscriber count reads ONE key, `subscriber_count`, that both list queries return
 *    (the dashboard's getAll() had it, the sidebar read `count`: one warning per list, counts blank);
 *  - a dashboard row opens its list: every cell holds a real link to the edit page, Modifier stays;
 *  - a Refusés card names the list(s) the message named and why (MailProcessor::refusalReason, in
 *    dispatch()'s order).
 * Run: php tests/ui_order_count_test.php   (exit 0 = all green)
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

// --- getMessageUids: which sequence range is fetched ---------------------------------------------
class RangeStream
{
    public $ranges = array();
    public function fetch($range, $items)
    {
        $this->ranges[] = $range;
        list($a, $b) = explode(':', $range);
        $out = array();
        for ($i = (int) $a; $i <= (int) $b; $i++)
            $out[$i] = array('UID' => 100 + $i);
        return $out;
    }
}

class CountedServer extends \Fetch\Server
{
    public $count;
    public $stream;
    public function numMessages($mailbox = '') { return $this->count; }
    public function getImapStream() { return $this->stream; }
}

$s = new CountedServer('imap.example.net', 993);
$s->stream = new RangeStream();

$s->count = 85;
check('newest: the last 20 of 85', range(166, 185), $s->getMessageUids(20, true));
check('newest: range asked', '66:85', end($s->stream->ranges));
check('default: still the first 20', range(101, 120), $s->getMessageUids(20));
check('default: range asked', '1:20', end($s->stream->ranges));

$s->count = 5;
check('newest, fewer than the limit: all of them', range(101, 105), $s->getMessageUids(20, true));
check('newest, fewer than the limit: range', '1:5', end($s->stream->ranges));

$s->count = 20;
check('newest, exactly the limit: all of them', '1:20', ($s->getMessageUids(20, true) && true) ? end($s->stream->ranges) : null);

$s->count = 0;
check('empty folder', array(), $s->getMessageUids(20, true));

// --- newestFirst: date descending, unknown date last ---------------------------------------------
$sorted = MailProcessor::newestFirst(array(
    array('uid' => 1, 'date' => 1000),
    array('uid' => 2, 'date' => false),
    array('uid' => 3, 'date' => 3000),
    array('uid' => 4, 'date' => 2000),
));
check('newestFirst', array(3, 4, 1, 2), array_column($sorted, 'uid'));

// --- sidebar count: one key ---------------------------------------------------------------------
$src = file_get_contents(ROOT_PATH . '/src/ListManager.php');
preg_match('/function getAll\(\).*?\}/s', $src, $all);
preg_match('/function getAllLabels\(\).*?\}/s', $src, $labels);
check('getAll() returns subscriber_count', 1, preg_match('/\bas subscriber_count\b/', $all[0] ?? ''));
check('getAllLabels() returns subscriber_count', 1, preg_match('/\bas subscriber_count\b/', $labels[0] ?? ''));

function url($p) { return $p; }
function asset($p) { return $p; }
function setting($k, $d = null) { return array('domains' => 'kxkm.net', 'imap_user' => 'listes@kxkm.net')[$k] ?? $d; }
function csrf_token() { return 'tok'; }
function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$warnings = array();
set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
$content = '';
$currentPage = 'subscribers';
$flash = null;
$pageTitle = 'Test';
$siteTitle = 'KList';
$lists = array(array('id' => 1, 'nom' => 'atelier', 'subscriber_count' => 7));
ob_start();
include ROOT_PATH . '/templates/layout.php';
$html = ob_get_clean();
restore_error_handler();
check('layout: no warning', array(), $warnings);
check('layout: the count shows', 1, preg_match('#<span class="count">7</span>#', $html));

// --- dashboard: the row is a link -----------------------------------------------------------------
$warnings = array();
set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
$lists = array(
    array('id' => 1, 'nom' => 'atelier', 'subscriber_count' => 7, 'last_used' => null, 'active' => 1, 'moderation' => 0),
    array('id' => 2, 'nom' => 'bar', 'subscriber_count' => 3, 'last_used' => '2026-11-10 12:00:00', 'active' => 0, 'moderation' => 1),
);
$lastCronRun = null;
$cronKey = 'k';
$uniqueSubscribers = 9;
$currentPage = 'dashboard';
ob_start();
include ROOT_PATH . '/templates/dashboard.php';
$html = ob_get_clean();
restore_error_handler();
check('dashboard: no warning', array(), $warnings);
preg_match_all('#<tr class="row-clickable">(.*?)</tr>#s', $html, $rows);
check('dashboard: one clickable row per list', 2, count($rows[1]));
foreach (array('atelier', 'bar') as $i => $name) {
    $row = $rows[1][$i] ?? '';
    preg_match_all('#<td class="row-cell">\s*<a class="row-link" href="([^"]*)"#', $row, $cells);
    check("dashboard $name: four cells link", 4, count($cells[1]));
    check("dashboard $name: all to its edit page", array_fill(0, 4, '?page=list&action=edit&name=' . $name), $cells[1]);
    check("dashboard $name: one tab stop besides Modifier", 3, substr_count($row, 'tabindex="-1"'));
    check("dashboard $name: Modifier stays", 1, preg_match('#href="\?page=list&action=edit&name=' . $name . '"\s+class="btn btn-sm btn-secondary"#', $row));
}
check('dashboard: never checked → no address line', 0, preg_match('~address-(missing|unverifiable)~', $html));
check('dashboard: the Vérifier button posts with the token', 1,
    preg_match('~name="csrf_token" value="tok">\s*<button type="submit" name="check_addresses"~', $html));

// --- dashboard: a list the mail host does not know is red, an unverifiable one grey (#t-077 ④) ------
$warnings = array();
set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
$lists = array(
    array('id' => 1, 'nom' => 'atelier', 'subscriber_count' => 7, 'last_used' => null, 'active' => 1, 'moderation' => 0,
        'address_check' => 'missing', 'address_missing' => 'atelier@kxkm.net', 'address_checked_at' => '2026-11-12 08:00:00'),
    array('id' => 2, 'nom' => 'bar', 'subscriber_count' => 3, 'last_used' => null, 'active' => 1, 'moderation' => 0,
        'address_check' => 'unverifiable', 'address_missing' => '', 'address_checked_at' => '2026-11-12 08:00:00'),
    array('id' => 3, 'nom' => 'cafe', 'subscriber_count' => 3, 'last_used' => null, 'active' => 1, 'moderation' => 0,
        'address_check' => 'ok', 'address_missing' => '', 'address_checked_at' => '2026-11-12 08:00:00'),
);
ob_start();
include ROOT_PATH . '/templates/dashboard.php';
$html = ob_get_clean();
restore_error_handler();
check('address: no warning', array(), $warnings);
preg_match_all('#<tr class="row-clickable([^"]*)">(.*?)</tr>#s', $html, $rows);
check('address: only the missing row is red', array(' row-address-missing', '', ''), $rows[1]);
check('address: the red line names the address and the alias to add', 1, preg_match(
    "~class=\"address-missing\" title=\"vérifié le 12/11/2026 à 08:00\">\s*atelier@kxkm.net n'existe pas chez l'hébergeur mail : ajoutez l'alias vers listes@kxkm.net~",
    $rows[2][0] ?? ''));
check('address: unverifiable is grey, never red', array(0, 1), array(
    preg_match('~address-missing~', $rows[2][1] ?? ''), preg_match('~class="address-unverifiable"~', $rows[2][1] ?? '')));
check('address: ok says nothing', 0, preg_match('~address-~', $rows[2][2] ?? ''));

// --- Refusés: the reason, in dispatch()'s order ----------------------------------------------------
$open = array('nom' => 'atelier', 'active' => 1, 'moderation' => 0);
$off = array('nom' => 'ancien', 'active' => 0, 'moderation' => 0);
$mod = array('nom' => 'annonces', 'active' => 1, 'moderation' => 1);
$block = array('spam@x.org' => 1);
$abos = array('rene@example.fr');
check('reason: blocked first, whatever the list', 'blocked', MailProcessor::refusalReason('Spam@X.org', array($open), $block, $abos));
check('reason: not on any discussion list', 'not-subscriber', MailProcessor::refusalReason('inconnu@x.org', array($open), $block, $abos));
check('reason: not-subscriber before inactive', 'not-subscriber', MailProcessor::refusalReason('inconnu@x.org', array($off), $block, $abos));
check('reason: inactive list', 'inactive', MailProcessor::refusalReason('Rene@example.fr', array($off), $block, $abos));
check('reason: moderated list = the moderator refused it', 'moderator', MailProcessor::refusalReason('rene@example.fr', array($mod), $block, $abos));
check('reason: inactive + moderated → moderator (PENDING won)', 'moderator', MailProcessor::refusalReason('rene@example.fr', array($off, $mod), $block, $abos));
check('reason: would be approved today → unknown', 'unknown', MailProcessor::refusalReason('rene@example.fr', array($open), $block, $abos));
check('reason: no list named → unknown', 'unknown', MailProcessor::refusalReason('rene@example.fr', array(), $block, $abos));

$warnings = array();
set_error_handler(function ($no, $str) use (&$warnings) { $warnings[] = $str; return true; });
$errorMsg = null;
$errors = array(array('uid' => 9, 'from' => 'mailer-daemon@x.org', 'subject' => 'Undelivered', 'date' => 1000, 'body_preview' => ''));
$discarded = array(
    array('uid' => 7, 'from' => 'inconnu@x.org', 'subject' => 'Salut', 'date' => 3000, 'body_preview' => '', 'lists' => array('atelier', 'bar'), 'reason' => 'not-subscriber'),
    array('uid' => 6, 'from' => 'rene@example.fr', 'subject' => 'Annonce', 'date' => false, 'body_preview' => '', 'lists' => array(), 'reason' => 'unknown'),
);
$lists = array();
$currentPage = 'errors';
ob_start();
include ROOT_PATH . '/templates/errors.php';
$html = ob_get_clean();
restore_error_handler();
check('errors page: no warning', array(), $warnings);
check('Refusés: lists named, and why', 1, preg_match('~atelier, bar\s*—\s*<strong>l&#039;expéditeur n&#039;est abonné à aucune liste de discussion</strong>~', $html));
check('Refusés: no list, unknown reason', 1, preg_match('#aucune liste</span>\s*—\s*<strong>raison inconnue#', $html));
check('a reason line per Refusés card, none on Erreurs', 2, preg_match_all('~<span class="material-icons"[^>]*>mail</span>~', $html));

echo "ui order/count: $checks checks, $failures failed\n";
exit($failures ? 1 : 0);
