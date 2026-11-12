<?php
/**
 * Tests for lib/Fetch/MimeParser.php against the raw messages in tests/fixtures/mime/.
 * Fixtures are stored with LF; each test runs on the CRLF form an IMAP server sends, and on LF.
 * Run: php tests/mime_test.php   (exit 0 = all green)
 */

require_once __DIR__ . '/../lib/Fetch/MimeParser.php';

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

function fixture($name, $eol)
{
    $raw = file_get_contents(__DIR__ . '/fixtures/mime/' . $name);
    return $eol === "\r\n" ? str_replace("\n", "\r\n", $raw) : $raw;
}

function decoded($text)
{
    $out = '';
    foreach (MimeParser::mimeHeaderDecode($text) as $w) {
        $out .= iconv($w->charset === 'default' ? 'ascii' : $w->charset, 'UTF-8', $w->text);
    }
    return $out;
}

function params($p)
{
    $out = array();
    foreach ($p as $o) {
        $out[$o->attribute] = $o->value;
    }
    return $out;
}

foreach (array("\r\n" => 'crlf', "\n" => 'lf') as $eol => $tag) {
    // --- plain message, no Content-Type -------------------------------------------------------
    $raw = fixture('plain-no-content-type.eml', $eol);
    list($header, $body) = MimeParser::splitMessage($raw);
    check("$tag plain: header ends with blank line", true, substr($header, -2 * strlen($eol)) === $eol . $eol);
    check("$tag plain: header + body = raw", $raw, $header . $body);
    check("$tag plain: body", "Hello list,{$eol}second line.{$eol}", $body);

    $s = MimeParser::structure($raw);
    check("$tag plain: type text", 0, $s->type);
    check("$tag plain: subtype", 'PLAIN', $s->subtype);
    check("$tag plain: encoding 7bit", 0, $s->encoding);
    check("$tag plain: default charset", array('CHARSET' => 'us-ascii'), params($s->parameters));
    check("$tag plain: no parts", false, isset($s->parts));
    check("$tag plain: bytes", strlen($body), $s->bytes);
    check("$tag plain: section 1 = body", $body, MimeParser::section($raw, '1'));

    $h = MimeParser::headerObject($header);
    check("$tag plain: date", 'Tue, 10 Nov 2026 09:15:00 +0100', $h->date);
    check("$tag plain: subject decodes", 'Réunion de lundi ok', decoded($h->subject));
    check("$tag plain: from mailbox", 'alice', $h->from[0]->mailbox);
    check("$tag plain: from host", 'example.org', $h->from[0]->host);
    check("$tag plain: from personal unquoted", 'Dupont, Alice', $h->from[0]->personal);
    check("$tag plain: to = group open + close", 2, count($h->to));
    check("$tag plain: to group name", 'undisclosed-recipients', $h->to[0]->mailbox);
    check("$tag plain: to group end has no mailbox", false, property_exists($h->to[1], 'mailbox'));
    check("$tag plain: cc count", 2, count($h->cc));
    check("$tag plain: cc comment is personal", 'Bob B', $h->cc[0]->personal);
    check("$tag plain: cc dotted local part", 'carol.c', $h->cc[1]->mailbox);
    check("$tag plain: cc host", 'example.com', $h->cc[1]->host);
    check("$tag plain: no bcc", false, isset($h->bcc));
}

foreach (array("\r\n" => 'crlf', "\n" => 'lf') as $eol => $tag) {
    // --- multipart/mixed > alternative + attachment ------------------------------------------
    $raw = fixture('mixed-alternative-attachment.eml', $eol);
    $s = MimeParser::structure($raw);
    check("$tag mixed: type multipart", 1, $s->type);
    check("$tag mixed: subtype", 'MIXED', $s->subtype);
    check("$tag mixed: boundary param", array('BOUNDARY' => 'outer-b'), params($s->parameters));
    check("$tag mixed: two parts", 2, count($s->parts));
    $alt = $s->parts[0];
    check("$tag mixed: alternative", array(1, 'ALTERNATIVE', 2), array($alt->type, $alt->subtype, count($alt->parts)));
    check("$tag mixed: plain part", array(0, 'PLAIN', 4), array($alt->parts[0]->type, $alt->parts[0]->subtype, $alt->parts[0]->encoding));
    check("$tag mixed: plain charset verbatim", array('CHARSET' => 'iso-8859-1'), params($alt->parts[0]->parameters));
    check("$tag mixed: html part", array(0, 'HTML', 3), array($alt->parts[1]->type, $alt->parts[1]->subtype, $alt->parts[1]->encoding));
    $pdf = $s->parts[1];
    check("$tag mixed: pdf type/subtype/encoding", array(3, 'PDF', 3), array($pdf->type, $pdf->subtype, $pdf->encoding));
    check("$tag mixed: pdf disposition", array(1, 'ATTACHMENT'), array($pdf->ifdisposition, $pdf->disposition));
    check("$tag mixed: pdf dparameters", array('FILENAME' => 'programme 2026.pdf'), params($pdf->dparameters));
    check("$tag mixed: pdf name param", array('NAME' => 'programme 2026.pdf'), params($pdf->parameters));
    check("$tag mixed: pdf content-id", '<pdf1@example.fr>', $pdf->id);

    $qp = MimeParser::section($raw, '1.1');
    check("$tag mixed: section 1.1 raw", "Caf=E9 cr=E8me ={$eol}ce soir.", $qp);
    check("$tag mixed: section 1.1 decodes via iconv", "Café crème ce soir.",
        iconv('iso-8859-1', 'UTF-8', quoted_printable_decode($qp)));
    check("$tag mixed: section 1.2 base64", "<p>Café crème</p>", base64_decode(MimeParser::section($raw, '1.2')));
    $pdfBody = MimeParser::section($raw, '2');
    check("$tag mixed: section 2 bytes = structure bytes", strlen($pdfBody), $pdf->bytes);
    check("$tag mixed: section 2 decodes", "%PDF-1.4\n%äüöß\n", base64_decode($pdfBody));
    check("$tag mixed: missing section", '', MimeParser::section($raw, '3'));

    list($header) = MimeParser::splitMessage($raw);
    $h = MimeParser::headerObject($header);
    check("$tag mixed: latin-1 personal decodes", 'René François', decoded($h->from[0]->personal));
    check("$tag mixed: to", array('liste', 'listes.kxkm.net'), array($h->to[0]->mailbox, $h->to[0]->host));
}

foreach (array("\r\n" => 'crlf', "\n" => 'lf') as $eol => $tag) {
    // --- forwarded message/rfc822 -------------------------------------------------------------
    $raw = fixture('forwarded-rfc822.eml', $eol);
    $s = MimeParser::structure($raw);
    $fw = $s->parts[1];
    check("$tag rfc822: type message", array(2, 'RFC822'), array($fw->type, $fw->subtype));
    check("$tag rfc822: no-charset text part gets us-ascii", array('CHARSET' => 'us-ascii'), params($s->parts[0]->parameters));
    check("$tag rfc822: encapsulated body is one part", 1, count($fw->parts));
    check("$tag rfc822: encapsulated alternative", array(1, 'ALTERNATIVE'), array($fw->parts[0]->type, $fw->parts[0]->subtype));
    check("$tag rfc822: section 2 = whole message", true, strpos(MimeParser::section($raw, '2'), "From: carol@example.com") === 0);
    check("$tag rfc822: section 2.1 = inner plain", "inner plain", MimeParser::section($raw, '2.1'));
    check("$tag rfc822: section 2.2 = inner html", "<p>inner html</p>", MimeParser::section($raw, '2.2'));
    // Fetch numbers the encapsulated alternative "2.1" and its parts "2.1.1"/"2.1.2"; IMAP has no
    // such sections (2.1 is already text/plain), so ext-imap returned "" and the body took no text.
    check("$tag rfc822: 2.1.1 past a leaf = nothing", '', MimeParser::section($raw, '2.1.1'));
    check("$tag rfc822: 2.1.2 past a leaf = nothing", '', MimeParser::section($raw, '2.1.2'));
    check("$tag rfc822: 1.1 past a leaf = nothing", '', MimeParser::section($raw, '1.1'));
    check("$tag rfc822: section 0 = nothing", '', MimeParser::section($raw, '0'));
    check("$tag rfc822: section 2. = nothing", '', MimeParser::section($raw, '2.'));
}

foreach (array("\r\n" => 'crlf', "\n" => 'lf') as $eol => $tag) {
    // --- one-part messages, and a message that is itself message/rfc822 ----------------------
    $raw = str_replace("\n", $eol, "Subject: x\nContent-Type: text/plain\n\nonly part\n");
    check("$tag single: section 1 = body", "only part$eol", MimeParser::section($raw, '1'));
    check("$tag single: section 2 = nothing", '', MimeParser::section($raw, '2'));
    $raw = str_replace("\n", $eol, "Subject: x\nContent-Type: message/rfc822\n\nSubject: in\n\ninner\n");
    check("$tag top rfc822: section 1 = whole inner message", "Subject: in{$eol}{$eol}inner$eol", MimeParser::section($raw, '1'));
    check("$tag top rfc822: section 1.1 = inner body", "inner$eol", MimeParser::section($raw, '1.1'));
    // multipart/digest: a part with no Content-Type is a message/rfc822
    $raw = str_replace("\n", $eol, "Content-Type: multipart/digest; boundary=d\n\n--d\n\nSubject: a\n\ndigest one\n--d--\n");
    check("$tag digest: 1.1 = body of the digested message", "digest one", MimeParser::section($raw, '1.1'));
    // A multipart has no encoding in BODYSTRUCTURE: ext-imap said 0 whatever the header claims.
    $raw = str_replace("\n", $eol, "Content-Type: multipart/mixed; boundary=b\nContent-Transfer-Encoding: quoted-printable\n\n--b\nContent-Type: text/plain\nContent-Transfer-Encoding: 8bit\n\nx\n--b--\n");
    $s = MimeParser::structure($raw);
    check("$tag multipart: encoding 0 despite its header", 0, $s->encoding);
    check("$tag multipart: its parts keep theirs", 1, $s->parts[0]->encoding);
}

// --- imap_mime_header_decode contract ----------------------------------------------------------
$w = MimeParser::mimeHeaderDecode('Hello =?UTF-8?Q?W=C3=B6rld?= !');
check('decode: three words', 3, count($w));
check('decode: default run', array('default', 'Hello '), array($w[0]->charset, $w[0]->text));
check('decode: encoded run', array('UTF-8', 'Wörld'), array($w[1]->charset, $w[1]->text));
check('decode: trailing run', array('default', ' !'), array($w[2]->charset, $w[2]->text));
check('decode: adjacent encoded words join', 'ab', decoded('=?utf-8?q?a?= =?utf-8?q?b?='));
check('decode: underscore is space in Q', 'a b', decoded('=?utf-8?q?a_b?='));
check('decode: RFC 2231 language tag', 'été', decoded('=?UTF-8*fr?B?w6l0w6k=?='));
check('decode: plain text untouched', 'plain words', decoded('plain words'));

// --- addresses ---------------------------------------------------------------------------------
$a = MimeParser::parseAddressList('Team: x@a.org, "Y, Z" <y@b.org>;, w@c.org');
check('addr: group open, 2 members, close, 1 more', 5, count($a));
check('addr: group name', 'Team', $a[0]->mailbox);
check('addr: quoted personal with comma', 'Y, Z', $a[2]->personal);
check('addr: after group', array('w', 'c.org'), array($a[4]->mailbox, $a[4]->host));
$a = MimeParser::parseAddressList('postmaster');
check('addr: no domain gets default host', array('postmaster', 'UNKNOWN'), array($a[0]->mailbox, $a[0]->host));
$a = MimeParser::parseAddressList('=?UTF-8?B?w4lsaXNl?= <elise@example.org>');
check('addr: encoded personal kept raw', '=?UTF-8?B?w4lsaXNl?=', $a[0]->personal);
check('addr: encoded personal decodes', 'Élise', decoded($a[0]->personal));

echo "mime: $checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
