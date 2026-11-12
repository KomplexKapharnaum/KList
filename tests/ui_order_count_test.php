<?php
/**
 * Tests for the web UI's lists (kxkm-infrastructure#t-077):
 *  - Erreurs and Refusés show the NEWEST messages first: Server::getMessageUids($n, true) asks for the
 *    last n sequence numbers (the folder runs oldest first), MailProcessor::newestFirst() sorts by date;
 *  - the sidebar's subscriber count reads ONE key, `subscriber_count`, that both list queries return
 *    (the dashboard's getAll() had it, the sidebar read `count`: one warning per list, counts blank).
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

echo "ui order/count: $checks checks, $failures failed\n";
exit($failures ? 1 : 0);
