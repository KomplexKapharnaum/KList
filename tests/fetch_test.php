<?php
/**
 * Tests for lib/Fetch (Server, Message, Attachment, MIME) on top of ImapClient + MimeParser,
 * the calls MailProcessor and index.php make, against one scripted IMAP session.
 * Run: php tests/fetch_test.php   (exit 0 = all green)
 */

require_once __DIR__ . '/../lib/Fetch/Server.php';
require_once __DIR__ . '/../lib/Fetch/Message.php';
require_once __DIR__ . '/../lib/Fetch/MIME.php';
require_once __DIR__ . '/../lib/Fetch/Attachment.php';

use Fetch\ImapClient;
use Fetch\Server;

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

function crlf($text)
{
    return str_replace("\n", "\r\n", $text);
}

$raw = crlf(file_get_contents(__DIR__ . '/fixtures/mime/mixed-alternative-attachment.eml'));
$select = "* 2 EXISTS\n* 0 RECENT\n* OK [UIDVALIDITY 7] UIDs valid\n* OK [UIDNEXT 43] Predicted next UID\n* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)\n";

$script = "* OK [CAPABILITY IMAP4rev1] Dovecot ready.\r\n" . crlf(
    "A0001 OK Logged in\n"
    . $select . "A0002 OK [READ-WRITE] Select completed\n"
    . "* 1 FETCH (UID 41)\n* 2 FETCH (UID 42)\nA0003 OK Fetch completed\n"
    . "* 2 FETCH (UID 42 FLAGS (\\Seen) RFC822.SIZE " . strlen($raw) . " BODY[] {" . strlen($raw) . "}\n"
) . $raw . crlf(")\nA0004 OK Fetch completed\n"
    . "A0005 OK Fetch completed\n"                                         // UID 99: nothing
    . "* LIST (\\HasNoChildren) \"/\" INBOX\nA0006 OK List completed\n"    // moveToMailBox: setMailBox('')
    . $select . "A0007 OK [READ-WRITE] Select completed\n"
    . "A0008 OK Copy completed\n"
    . "A0009 OK Store completed\n"
    . "* 2 EXPUNGE\nA0010 OK Expunge completed\n"
    . "* LIST (\\HasNoChildren) \"/\" INBOX\nA0011 OK List completed\n"
    . $select . "A0012 OK [READ-WRITE] Select completed\n"
    . "A0013 NO [ALREADYEXISTS] Mailbox exists\n"                          // createMailBox
    . "* BYE Logging out\nA0014 OK Logout completed\n");

list($clientEnd, $serverEnd) = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
fwrite($serverEnd, $script);
stream_socket_shutdown($serverEnd, STREAM_SHUT_WR);
stream_set_timeout($clientEnd, 2);

$client = new ImapClient($clientEnd);
$client->readGreeting();
$server = new Server('imap.example.net', 993);
$server->setAuthentication('listes@kxkm.net', 'secret');
$server->useClient($client);

check('getMessageUids: first n by sequence', array(41, 42), $server->getMessageUids(20));
check('numMessages from SELECT', 2, $server->numMessages());

$m = $server->getMessageByUid(42);
check('getMessageByUid: a Message', true, $m instanceof \Fetch\Message);
check('subject', 'Programme', $m->getSubject());
check('date', strtotime('Wed, 11 Nov 2026 18:00:00 +0100'), $m->getDate());
check('from: RFC 2047 name decoded', array('address' => 'rene@example.fr', 'name' => "Ren\u{e9} Fran\u{e7}ois"), $m->getAddresses('from'));
check('to', array(array('address' => 'liste@listes.kxkm.net')), $m->getAddresses('to'));
check('reply-to falls back to from', 'Ren' . "\u{e9}" . ' Fran' . "\u{e7}" . 'ois <rene@example.fr>', $m->getAddresses('reply-to', true));
check('plain body: QP + iso-8859-1 → UTF-8', "Caf\u{e9} cr\u{e8}me ce soir.", $m->getMessageBody());
check('html body: base64', "<p>Caf\u{e9} cr\u{e8}me</p>", $m->getMessageBody(true));
list($expectedHeader) = explode("\r\n\r\n", $raw, 2);
check('raw headers = header block + blank line', $expectedHeader . "\r\n\r\n", $m->getRawHeaders());
check('seen flag', true, $m->checkFlag('seen'));
check('flagged flag', false, $m->checkFlag('flagged'));
check('overview size', strlen($raw), $m->getOverview()->size);

$atts = $m->getAttachments();
check('one attachment', 1, is_array($atts) ? count($atts) : $atts);
$a = $atts[0];
$pdf = base64_decode('JVBERi0xLjQKJcOkw7zDtsOfCg==');
check('attachment name', 'programme 2026.pdf', $a->getFileName());
check('attachment type', 'application/pdf', $a->getMimeType());
check('attachment data decoded', $pdf, $a->getData());
$dir = sys_get_temp_dir() . '/klist-fetch-test-' . getmypid();
mkdir($dir);
check('saveToDirectory', true, $a->saveToDirectory($dir));
check('saved bytes decoded', $pdf, @file_get_contents($dir . '/programme 2026.pdf'));
@unlink($dir . '/programme 2026.pdf');
rmdir($dir);

check('getMessageByUid: unknown UID → false', false, $server->getMessageByUid(99));
check('moveToMailBox', true, $m->moveToMailBox('Archive'));
check('createMailBox: NO → false', false, $server->createMailBox('Archive'));
$server->close();

stream_set_blocking($serverEnd, false);
$sent = (string) stream_get_contents($serverEnd);
fclose($serverEnd);

$expected = crlf("A0001 LOGIN \"listes@kxkm.net\" \"secret\"\n"
    . "A0002 SELECT \"INBOX\"\n"
    . "A0003 FETCH 1:2 (UID)\n"
    . "A0004 UID FETCH 42 (UID FLAGS RFC822.SIZE BODY.PEEK[])\n"
    . "A0005 UID FETCH 99 (UID FLAGS RFC822.SIZE BODY.PEEK[])\n"
    . "A0006 LIST \"\" \"INBOX\"\n"
    . "A0007 SELECT \"INBOX\"\n"
    . "A0008 UID COPY 42 \"Archive\"\n"
    . "A0009 UID STORE 42 +FLAGS.SILENT (\\Deleted)\n"
    . "A0010 EXPUNGE\n"
    . "A0011 LIST \"\" \"INBOX\"\n"
    . "A0012 SELECT \"INBOX\"\n"
    . "A0013 CREATE \"Archive\"\n"
    . "A0014 LOGOUT\n");
check('client transcript', $expected, $sent);

echo "fetch_test: $checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
