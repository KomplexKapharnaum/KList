<?php
/**
 * Tests for Attachment's file name (audit 2026-11-12, finding 1): a sender-supplied name, plain or
 * MIME-encoded, can never make saveToDirectory() write outside the directory it is given.
 * Run: php tests/attachment_name_test.php   (exit 0 = all green)
 */

require_once __DIR__ . '/../lib/Fetch/Server.php';
require_once __DIR__ . '/../lib/Fetch/Message.php';
require_once __DIR__ . '/../lib/Fetch/MIME.php';
require_once __DIR__ . '/../lib/Fetch/Attachment.php';

use Fetch\Attachment;
use Fetch\Message;

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

/** A message that serves one fixed part, so an Attachment can be saved without a server. */
class FixedPartMessage extends Message
{
    public function __construct()
    {
    }

    public function getUid()
    {
        return 1;
    }

    public function fetchSection($partIdentifier = null)
    {
        return 'payload';
    }
}

function attachmentNamed($name)
{
    $parameter = (object) array('attribute' => 'filename', 'value' => $name);
    $structure = (object) array('type' => 3, 'subtype' => 'OCTET-STREAM', 'bytes' => 7, 'encoding' => 0,
        'dparameters' => array($parameter));

    return new Attachment(new FixedPartMessage(), $structure, '2');
}

// The name as read: [label, raw header value, expected name].
$cases = array(
    array('plain name', 'report.pdf', 'report.pdf'),
    array('parent directory', '../x.php', 'x.php'),
    array('deep traversal', '../../../../etc/passwd', 'passwd'),
    array('absolute path', '/var/www/listes/x.php', 'x.php'),
    array('backslash path', '..\\..\\x.php', 'x.php'),
    array('dot file', '.htaccess', 'htaccess'),
    array('only dots', '..', ''),
    array('control characters', "a\r\nb\x00c\x7F.txt", 'abc.txt'),
    array('encoded traversal', '=?UTF-8?B?' . base64_encode('../../x.php') . '?=', 'x.php'),
    array('encoded control characters', '=?UTF-8?Q?a=0A=09b.txt?=', 'ab.txt'),
    array('raw UTF-8 kept', "Caf\xC3\xA9 cr\xC3\xA8me.pdf", "Caf\xC3\xA9 cr\xC3\xA8me.pdf"),
    array('encoded latin-1', '=?ISO-8859-1?Q?caf=E9.txt?=', "caf\xC3\xA9.txt"),
    array('encoded UTF-8 kept', '=?UTF-8?B?' . base64_encode("\xC3\xA9t\xC3\xA9.jpg") . '?=', "\xC3\xA9t\xC3\xA9.jpg"),
);

foreach ($cases as $case) {
    list($label, $raw, $expected) = $case;
    check("safeFileName: $label", $expected, Attachment::safeFileName($raw));
    check("getFileName: $label", $expected, attachmentNamed($raw)->getFileName());
}

// saveToDirectory() end to end: whatever the name, the bytes land inside tmp/ and nowhere else.
$root = sys_get_temp_dir() . '/klist-attachment-' . bin2hex(random_bytes(4));
mkdir($root . '/tmp', 0700, true);

check('save: traversal name saved', true, attachmentNamed('../x.php')->saveToDirectory($root . '/tmp/'));
check('save: nothing outside tmp/', false, file_exists($root . '/x.php'));
check('save: file inside tmp/', 'payload', @file_get_contents($root . '/tmp/x.php'));
check('save: a name with nothing left is refused', false, attachmentNamed('../..')->saveToDirectory($root . '/tmp/'));
check('save: dot file lands undotted', true, attachmentNamed('.htaccess')->saveToDirectory($root . '/tmp'));
check('save: no .htaccess written', false, file_exists($root . '/tmp/.htaccess'));

foreach (glob($root . '/tmp/*') as $file)
    unlink($file);
rmdir($root . '/tmp');
rmdir($root);

echo ($failures ? "attachment_name: $failures/$checks FAILED" : "attachment_name: $checks checks green") . "\n";
exit($failures ? 1 : 0);
