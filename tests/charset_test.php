<?php
/**
 * Message::convertCharset on the mbstring path, which production takes (listes has mbstring)
 * and dev37 cannot (no mbstring): without the extension, mb_* stand-ins are defined here so the
 * same path runs. Their encoding list carries mbstring's own canonical names and case, so a
 * case-sensitive lookup fails here exactly as it did on the box (`Caf? cr?me`, 12/11).
 * Run: php tests/charset_test.php   (exit 0 = all green)
 */

require_once __DIR__ . '/../lib/Fetch/Server.php';
require_once __DIR__ . '/../lib/Fetch/Message.php';

$stubbed = !function_exists('mb_convert_encoding');
$mbCalls = 0;

if ($stubbed) {
    function mb_list_encodings()
    {
        return array('BASE64', 'UTF-8', 'ASCII', 'ISO-8859-1', 'ISO-8859-15', 'Windows-1252', 'UTF-16BE');
    }

    function mb_encoding_aliases($name)
    {
        $aliases = array(
            'ASCII' => array('ANSI_X3.4-1968', 'iso-ir-6', 'ANSI_X3.4-1986', 'ISO_646.irv:1991', 'US-ASCII', 'us', 'IBM367', 'IBM-367', 'cp367', 'csASCII'),
            'ISO-8859-1' => array('ISO8859-1', 'latin1'),
            'ISO-8859-15' => array('ISO8859-15', 'LATIN-9'),
            'Windows-1252' => array('cp1252'),
            'UTF-8' => array('utf8'),
        );

        return isset($aliases[$name]) ? $aliases[$name] : array();
    }

    // mbstring replaces every byte that is invalid in the source charset with '?'.
    function mb_convert_encoding($string, $to, $from)
    {
        global $mbCalls;
        $mbCalls++;
        $known = array();
        foreach (mb_list_encodings() as $n) {
            $known[strtolower($n)] = $n;
            foreach (mb_encoding_aliases($n) as $a)
                $known[strtolower($a)] = $n;
        }
        if (!isset($known[strtolower($from)]))
            throw new \ValueError("mb_convert_encoding(): Argument #3 (\$from_encoding) contains invalid encoding \"$from\"");
        $from = $known[strtolower($from)];
        if ($from === 'UTF-8')
            return preg_replace_callback(
                '/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}|(.)/s',
                function ($m) { return isset($m[1]) ? '?' : $m[0]; }, $string);
        if ($from === 'ASCII')
            return preg_replace('/[\x80-\xFF]/', '?', $string);

        return iconv($from, $to, $string);
    }
}

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

use Fetch\Message;

$latin1 = "Caf\xE9 cr\xE8me";
$cp1252 = "\x93Caf\xE9\x94 \x80";
foreach (array('ISO-8859-1', 'iso-8859-1', 'Iso-8859-1', 'latin1', 'LATIN1', 'iso8859-1') as $cs) {
    check("latin-1 body, charset $cs, QP (4)", 'Café crème', Message::convertCharset($latin1, $cs, 4));
    check("latin-1 body, charset $cs, base64 (3)", 'Café crème', Message::convertCharset($latin1, $cs, 3));
}
check('windows-1252 lowercase', '“Café” €', Message::convertCharset($cp1252, 'windows-1252', 4));
check('cp1252 alias', '“Café” €', Message::convertCharset($cp1252, 'cp1252', 4));
check('iso-8859-15 lowercase', 'Caf€', Message::convertCharset("Caf\xA4", 'iso-8859-15', 1));
check('utf-8 lowercase is left as UTF-8', 'Café', Message::convertCharset('Café', 'utf-8', 4));
check('UTF-8 untouched', "Caf\xE9", Message::convertCharset("Caf\xE9", 'UTF-8', 4));
check('us-ascii lowercase', 'plain text', Message::convertCharset('plain text', 'us-ascii', 0));
// a charset mbstring does not know: 7bit → US-ASCII, else UTF-8 (unchanged fallback)
check('unknown charset, 8bit → read as UTF-8', 'Café', Message::convertCharset('Café', 'x-unknown', 1));
check('unknown charset, 7bit → read as US-ASCII', 'Caf??', Message::convertCharset('Café', 'x-unknown', 0));

if ($stubbed)
    check('the mbstring path ran', true, $mbCalls > 0);

echo "charset_test: $checks checks, $failures failed" . ($stubbed ? ' (mbstring stand-ins)' : ' (mbstring)') . "\n";
exit($failures ? 1 : 0);
