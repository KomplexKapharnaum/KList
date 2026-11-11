<?php
/**
 * Tests for lib/Fetch/ImapClient.php against scripted IMAP exchanges.
 *
 * Each case pairs a client with one end of a local socket pair. The server's whole reply script is
 * written up front (the client's tags are deterministic: A0001, A0002 …), the client runs its calls,
 * then the test reads back every byte the client sent and compares it with the expected transcript.
 * Run: php tests/imap_client_test.php   (exit 0 = all green)
 */

require_once __DIR__ . '/../lib/Fetch/ImapClient.php';

use Fetch\ImapClient;
use Fetch\ImapException;

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

/** Lines given with "\n" go on the wire as CRLF; literal payloads are passed already CRLF'd. */
function crlf($text)
{
    return str_replace("\n", "\r\n", $text);
}

/**
 * Runs $fn(ImapClient) against a server that answers with $script, closing its end after.
 * Returns array(result of $fn or the ImapException it threw, bytes the client sent).
 */
function exchange($script, $fn, $greet = true)
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    list($client, $server) = $pair;
    fwrite($server, $script);
    stream_socket_shutdown($server, STREAM_SHUT_WR); // a read past the script = connection lost
    stream_set_timeout($client, 2);
    try {
        $c = new ImapClient($client);
        if ($greet) {
            $c->readGreeting();
        }
        $result = $fn($c);
    } catch (ImapException $e) {
        $result = $e;
    }
    stream_set_blocking($server, false);
    $sent = (string) stream_get_contents($server);
    fclose($server);
    if (is_resource($client)) {
        fclose($client);
    }
    return array($result, $sent);
}

$greeting = "* OK [CAPABILITY IMAP4rev1 LITERAL+] Dovecot ready.\r\n";

// --- login: quoted, then a literal for an 8-bit password -------------------------------------
list($r, $sent) = exchange($greeting . crlf("A0001 OK Logged in\n"), function ($c) {
    return $c->login('listes@kxkm.net', 'p"a\\ss');
});
check('login quoted: sent', crlf("A0001 LOGIN \"listes@kxkm.net\" \"p\\\"a\\\\ss\"\n"), $sent);
check('login quoted: status', 'OK', $r['status']);

list($r, $sent) = exchange($greeting . crlf("+ OK\nA0001 OK Logged in\n"), function ($c) {
    return $c->login('listes', "mot-de-passe-\xc3\xa9t\xc3\xa9");
});
check('login literal: sent', crlf("A0001 LOGIN \"listes\" {18}\nmot-de-passe-\xc3\xa9t\xc3\xa9\n"), $sent);
check('login literal: status', 'OK', $r['status']);

list($r, $sent) = exchange($greeting . crlf("A0001 NO [AUTHENTICATIONFAILED] Authentication failed.\n"), function ($c) {
    return $c->login('listes', 'wrong');
});
check('login refused: throws', true, $r instanceof ImapException);
check('login refused: message', 'IMAP LOGIN failed: NO [AUTHENTICATIONFAILED] Authentication failed.', $r instanceof ImapException ? $r->getMessage() : null);

list($r, $sent) = exchange("* BYE Too many connections\r\n", function ($c) {
    return 'not reached';
});
check('greeting BYE: throws', true, $r instanceof ImapException);
check('greeting BYE: nothing sent', '', $sent);

// --- LIST with modified UTF-7 names, a quoted name and a literal name ------------------------
$literalName = 'Caf&AOk- & Co';
$list = crlf("* LIST (\\HasNoChildren) \".\" INBOX\n"
    . "* LIST (\\HasNoChildren \\UnMarked) \".\" \"INBOX.Envoy&AOk-s\"\n"
    . "* LIST (\\HasChildren) \".\" \"PENDING\"\n"
    . "* LIST () NIL {" . strlen($literalName) . "}\n")
    . $literalName
    . crlf("\nA0001 OK List completed (0.001 + 0.000 secs).\n");
list($r, $sent) = exchange($greeting . $list, function ($c) {
    return $c->listMailboxes();
});
check('list: sent', crlf("A0001 LIST \"\" \"*\"\n"), $sent);
check('list: names', array('INBOX', 'INBOX.Envoyés', 'PENDING', 'Café & Co'), array_column($r, 'name'));
check('list: raw name', 'INBOX.Envoy&AOk-s', $r[1]['raw']);
check('list: delimiter', '.', $r[0]['delimiter']);
check('list: NIL delimiter', null, $r[3]['delimiter']);
check('list: attributes', array('\\HasNoChildren', '\\UnMarked'), $r[1]['attributes']);

// --- SELECT / EXAMINE --------------------------------------------------------------------------
$select = crlf("* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)\n"
    . "* OK [PERMANENTFLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft \\*)] Flags permitted.\n"
    . "* 3 EXISTS\n* 1 RECENT\n"
    . "* OK [UIDVALIDITY 1731350000] UIDs valid\n* OK [UIDNEXT 46] Predicted next UID\n"
    . "A0001 OK [READ-WRITE] Select completed.\n");
list($r, $sent) = exchange($greeting . $select, function ($c) {
    return $c->select('INBOX.Envoyés');
});
check('select: sent (UTF-7 name)', crlf("A0001 SELECT \"INBOX.Envoy&AOk-s\"\n"), $sent);
check('select: info', array('exists' => 3, 'recent' => 1, 'uidvalidity' => 1731350000, 'uidnext' => 46,
    'flags' => array('\\Answered', '\\Flagged', '\\Deleted', '\\Seen', '\\Draft'), 'readonly' => false), $r);

list($r, $sent) = exchange($greeting . crlf("* 0 EXISTS\nA0001 OK [READ-ONLY] Examine completed.\n"), function ($c) {
    return $c->examine('PENDING');
});
check('examine: sent', crlf("A0001 EXAMINE \"PENDING\"\n"), $sent);
check('examine: read-only, empty', array(0, true), array($r['exists'], $r['readonly']));

list($r, $sent) = exchange($greeting . crlf("A0001 NO Mailbox doesn't exist: NOPE\n"), function ($c) {
    return $c->select('NOPE');
});
check('select missing: throws', 'IMAP SELECT failed: NO Mailbox doesn\'t exist: NOPE', $r instanceof ImapException ? $r->getMessage() : null);

// --- FETCH by sequence (Server::getMessageUids), UID SEARCH ------------------------------------
list($r, $sent) = exchange($greeting . crlf("* 1 FETCH (UID 41)\n* 2 FETCH (UID 43)\n* 3 FETCH (UID 45)\nA0001 OK Fetch completed.\n"), function ($c) {
    return $c->fetch('1:20', '(UID)');
});
check('fetch seq: sent', crlf("A0001 FETCH 1:20 (UID)\n"), $sent);
check('fetch seq: uids', array(1 => '41', 2 => '43', 3 => '45'), array_map(function ($a) { return $a['UID']; }, $r));

list($r, $sent) = exchange($greeting . crlf("* SEARCH 41 43 45\nA0001 OK Search completed.\n"), function ($c) {
    return $c->uidSearch('ALL');
});
check('uid search: sent', crlf("A0001 UID SEARCH ALL\n"), $sent);
check('uid search: uids', array(41, 43, 45), $r);

// --- UID FETCH of a whole message: literals byte-exact, unsolicited updates ignored ------------
$raw = crlf(file_get_contents(__DIR__ . '/fixtures/mime/mixed-alternative-attachment.eml'));
list($header) = explode("\r\n\r\n", $raw, 2);
$header .= "\r\n\r\n";
$fetch = crlf("* 2 FETCH (FLAGS (\\Seen))\n")
    . '* 2 FETCH (UID 43 FLAGS (\\Seen $Forwarded) RFC822.SIZE ' . strlen($raw)
    . ' INTERNALDATE "11-Nov-2026 19:15:02 +0100" BODY[HEADER] {' . strlen($header) . "}\r\n" . $header
    . ' BODY[] {' . strlen($raw) . "}\r\n" . $raw . ")\r\n"
    . crlf("A0001 OK Fetch completed.\n");
$items = '(FLAGS RFC822.SIZE INTERNALDATE BODY.PEEK[HEADER] BODY.PEEK[])';
list($r, $sent) = exchange($greeting . $fetch, function ($c) use ($items) {
    return $c->uidFetch('43', $items);
});
check('uid fetch: sent', crlf("A0001 UID FETCH 43 $items\n"), $sent);
check('uid fetch: keyed by UID', array(43), array_keys($r));
check('uid fetch: flags', array('\\Seen', '$Forwarded'), $r[43]['FLAGS']);
check('uid fetch: size', (string) strlen($raw), $r[43]['RFC822.SIZE']);
check('uid fetch: internaldate', '11-Nov-2026 19:15:02 +0100', $r[43]['INTERNALDATE']);
check('uid fetch: header bytes', $header, $r[43]['BODY[HEADER]']);
check('uid fetch: message bytes', $raw, $r[43]['BODY[]']);

list($r, $sent) = exchange($greeting . crlf("A0001 OK Fetch completed.\n"), function ($c) use ($items) {
    return $c->uidFetch('99', $items);
});
check('uid fetch missing: empty', array(), $r);

// a literal holding ")" and "{3}" + CRLF must not end the list or open another literal
$tricky = "a) b\r\n{3}\r\nxyz";
list($r) = exchange($greeting . '* 1 FETCH (UID 7 BODY[1] {' . strlen($tricky) . "}\r\n" . $tricky . " BODY[HEADER.FIELDS (SUBJECT FROM)] \"\")\r\n"
    . crlf("A0001 OK done\n"), function ($c) {
    return $c->uidFetch('7', '(BODY.PEEK[1] BODY.PEEK[HEADER.FIELDS (SUBJECT FROM)])');
});
check('uid fetch: tricky literal', $tricky, $r[7]['BODY[1]']);
check('uid fetch: bracketed atom with spaces', '', $r[7]['BODY[HEADER.FIELDS (SUBJECT FROM)]']);

// --- move = UID COPY + \Deleted + EXPUNGE (Message::moveToMailBox), CREATE ---------------------
list($r, $sent) = exchange($greeting . crlf("A0001 OK [COPYUID 1731350001 43 7] Copy completed.\n"
        . "A0002 OK Store completed.\n* 2 EXPUNGE\nA0003 OK Expunge completed.\nA0004 OK Create completed.\n"), function ($c) {
    $c->uidCopy('43', 'APPROVED');
    $c->uidStore('43', '+FLAGS', array('\\Deleted'));
    $c->expunge();
    return $c->create('Archivé');
});
check('move + create: sent', crlf("A0001 UID COPY 43 \"APPROVED\"\nA0002 UID STORE 43 +FLAGS.SILENT (\\Deleted)\n"
    . "A0003 EXPUNGE\nA0004 CREATE \"Archiv&AOk-\"\n"), $sent);
check('move + create: last status', 'OK', $r['status']);

// --- a dropped connection, inside a literal and between responses ------------------------------
list($r) = exchange($greeting . "* 1 FETCH (UID 5 BODY[] {100}\r\nonly a few bytes", function ($c) {
    return $c->uidFetch('5', '(BODY.PEEK[])');
});
check('cut inside literal: throws', true, $r instanceof ImapException);

list($r) = exchange($greeting, function ($c) {
    return $c->noop();
});
check('cut before reply: throws', 'IMAP: connection lost', $r instanceof ImapException ? $r->getMessage() : null);

list($r) = exchange($greeting . crlf("* BYE Server shutting down\n"), function ($c) {
    return $c->noop();
});
check('BYE mid-session: throws', true, $r instanceof ImapException);

// --- modified UTF-7 both ways ------------------------------------------------------------------
foreach (array(
    'INBOX' => 'INBOX',
    'Envoyés' => 'Envoy&AOk-s',
    'A&B' => 'A&-B',
    '日本語' => '&ZeVnLIqe-',
    'Brouillons/été 2026' => 'Brouillons/&AOk-t&AOk- 2026',
    "\xf0\x9f\x8e\xad masques" => '&2DzfrQ- masques',
) as $utf8 => $utf7) {
    check("utf7 encode $utf8", $utf7, ImapClient::encodeMailbox($utf8));
    check("utf7 decode $utf7", $utf8, ImapClient::decodeMailbox($utf7));
}
check('utf7 malformed kept', '&###-', ImapClient::decodeMailbox('&###-'));

echo "imap_client_test: $checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
