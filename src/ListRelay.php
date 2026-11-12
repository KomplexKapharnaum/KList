<?php
/**
 * ListRelay - the list copy of a message is the ORIGINAL message, headers rewritten, body untouched
 *
 * Decided 2026-11-12 (audit of the list logic, "relay, don't rebuild"): rebuilding each message
 * lost inline images, RFC 2231 file names and same-named pictures, and the original To/Cc. A
 * relayed copy keeps the body byte for byte, so whatever the sender's client wrote reaches the
 * subscribers as written. Only the header block changes:
 *
 *   From      "<display name, else the address> via <list>" <list@…>  (our server cannot send as
 *             alice@gmail.com: DMARC)
 *   Reply-To  the list on a discussion list; the sender (their own Reply-To if they set one) on a
 *             broadcast list, so a reply to an announcement does not reach everyone
 *   Subject   "[<list>]" once, after any "Re:" the sender's client put in front
 *   To, Cc    kept as written, so a reply-all reaches whoever the sender wrote to
 *   added     List-Id, List-Post, List-Unsubscribe (+ List-Unsubscribe-Post once a one-click URL
 *             exists), Precedence: list, X-Loop, X-list-relay (dispatch()'s round-back watermark),
 *             X-Original-From
 *   dropped   the original DKIM-Signature and ARC-* (our provider signs the copy), Sender,
 *             Return-Path and Delivered-To (the delivery trace of OUR mailbox), the list headers
 *             of any list the message came through
 *
 * No IMAP, no database, no SMTP here: MailProcessor::forward() does the I/O.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/Fetch/MimeParser.php';

use Fetch\MimeParser;

final class ListRelay {
    /** Header fields the copy never carries over (lowercase); ARC-* is matched by prefix. */
    private const DROPPED = [
        'from', 'reply-to', 'subject', 'sender', 'errors-to',
        'dkim-signature', 'return-path', 'delivered-to',
        'list-id', 'list-post', 'list-unsubscribe', 'list-unsubscribe-post', 'list-help',
        'list-subscribe', 'list-owner', 'list-archive',
        'precedence', 'x-loop', 'x-list-relay', 'x-original-from',
    ];

    /**
     * The copy sent for one list.
     *
     * @param string      $raw            the whole original message, as the mailbox holds it
     * @param string      $nom            the list's name
     * @param string      $listAddress    the list's address (From, Reply-To, List-*)
     * @param bool        $discussion     discussion list (reponse = 1): Reply-To is the list
     * @param string|null $unsubscribeUrl an https one-click URL; null = mailto STOP only
     */
    public static function copy(string $raw, string $nom, string $listAddress, bool $discussion, ?string $unsubscribeUrl = null): string {
        [$fields, $body] = self::split($raw);

        $fromValue = self::value($fields, 'from') ?? '';
        $replyToValue = self::value($fields, 'reply-to');
        $subjectValue = self::value($fields, 'subject');
        $sender = self::firstAddress($fromValue);

        $display = ($sender['name'] ?? '') !== '' ? $sender['name'] : ($sender['address'] ?? 'inconnu');
        $from = 'From: ' . self::phrase($display . ' via ' . $nom) . ' <' . $listAddress . '>';

        if ($discussion) {
            $replyTo = 'Reply-To: ' . $listAddress;
        } else {
            $replyTo = 'Reply-To:' . ($replyToValue ?? $fromValue);    // the raw value: its encoding kept
        }

        $original = self::decode(self::unfold($subjectValue ?? ''));
        $subject = self::subject($original, $nom);
        $subjectLine = ($subjectValue !== null && $subject === $original)
            ? 'Subject:' . $subjectValue                               // already right: kept as written
            : 'Subject: ' . self::text($subject);

        $domain = substr($listAddress, strrpos($listAddress, '@') + 1);
        $unsubscribe = '<mailto:' . $listAddress . '?subject=STOP>';
        $added = [
            'List-Id: <' . $nom . '.' . $domain . '>',
            'List-Post: <mailto:' . $listAddress . '>',
            'List-Unsubscribe: ' . ($unsubscribeUrl !== null ? '<' . $unsubscribeUrl . '>, ' : '') . $unsubscribe,
        ];
        if ($unsubscribeUrl !== null) {
            $added[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        }
        $added[] = 'Precedence: list';
        $added[] = 'X-Loop: ' . $listAddress;
        $added[] = 'X-list-relay: ' . $listAddress;
        if ($fromValue !== '') {
            $added[] = 'X-Original-From:' . $fromValue;
        }

        // Kept fields stay where they were, byte for byte; From, Reply-To and Subject take the
        // place of the first original one (Reply-To goes after From when there was none).
        $out = [];
        $placed = ['from' => false, 'reply-to' => false, 'subject' => false];
        $lines = ['from' => $from, 'reply-to' => $replyTo, 'subject' => $subjectLine];
        foreach ($fields as [$name, $chunk]) {
            $lower = strtolower($name);
            if (isset($placed[$lower])) {
                if (!$placed[$lower]) {
                    $out[] = $lines[$lower] . "\r\n";
                    $placed[$lower] = true;
                    if ($lower === 'from' && $replyToValue === null) {
                        $out[] = $replyTo . "\r\n";
                        $placed['reply-to'] = true;
                    }
                }
                continue;
            }
            if (in_array($lower, self::DROPPED, true) || str_starts_with($lower, 'arc-')) {
                continue;
            }
            $out[] = $chunk;
        }
        foreach ($placed as $name => $done) {
            if (!$done) {
                $out[] = $lines[$name] . "\r\n";
            }
        }
        foreach ($added as $line) {
            $out[] = $line . "\r\n";
        }

        return implode('', $out) . "\r\n" . $body;
    }

    /**
     * The subject of a copy: "[<list>]" exactly once, and any "Re:" the sender's client put in
     * front stays in front. The tag the old rebuild wrote ("[<name> sur <list>]") goes too.
     */
    public static function subject(string $subject, string $nom): string {
        $tag = '[' . $nom . ']';
        $rest = preg_replace('/\[(?:[^\[\]]* sur )?' . preg_quote($nom, '/') . '\][ \t]*/iu', '', $subject) ?? $subject;
        $rest = trim($rest);
        $prefix = '';
        if (preg_match('/^((?:(?:re|aw|sv|antw|r[ée]f)[ \t]*(?:\[\d+\])?[ \t]*:[ \t]*)+)(.*)$/isu', $rest, $m)) {
            $prefix = rtrim($m[1]) . ' ';
            $rest = $m[2];
        }
        return $prefix . $tag . ($rest !== '' ? ' ' . $rest : '');
    }

    /**
     * Who gets one list's copy: its subscribers, minus the sender, minus whoever the original To or
     * Cc already reached, minus the blocklist, minus anyone another list of the same message already
     * tried (the journal's reached()), minus our own addresses (a list's own address or the mailbox
     * would only bring the copy back). Order kept, each address once, lowercased.
     *
     * @return array{0: string[], 1: array<string,string>} [recipients, skipped address => why]
     */
    public static function recipients(array $subscribers, string $sender, array $original, array $blocklist, array $reached, array $own): array {
        $why = [];
        foreach ($own as $a) {
            $why[strtolower($a)] = 'own';
        }
        foreach ($reached as $a) {
            $why[strtolower($a)] = 'other-list';
        }
        foreach ($original as $a) {
            $why[strtolower($a)] = 'original';
        }
        $why[strtolower($sender)] = 'sender';

        $recipients = [];
        $skipped = [];
        foreach ($subscribers as $a) {
            $a = strtolower(trim($a));
            if ($a === '' || isset($recipients[$a]) || isset($skipped[$a])) {
                continue;
            }
            if (isset($why[$a])) {
                $skipped[$a] = $why[$a];
            } elseif (isset($blocklist[$a])) {
                $skipped[$a] = 'blocked';
            } else {
                $recipients[$a] = true;
            }
        }
        return [array_keys($recipients), $skipped];
    }

    /** The addresses of the original To and Cc, lowercased. */
    public static function originalRecipients(string $raw): array {
        [$fields] = self::split($raw);
        $out = [];
        foreach ($fields as [$name, $chunk]) {
            $lower = strtolower($name);
            if ($lower !== 'to' && $lower !== 'cc') {
                continue;
            }
            foreach (MimeParser::parseAddressList(self::unfold(substr($chunk, strpos($chunk, ':') + 1))) as $a) {
                if (isset($a->mailbox, $a->host) && $a->host !== 'UNKNOWN') {
                    $out[strtolower($a->mailbox . '@' . $a->host)] = true;
                }
            }
        }
        return array_keys($out);
    }

    /** The journal's key: the Message-ID, else a hash of the whole message. */
    public static function messageKey(string $raw): string {
        [$fields] = self::split($raw);
        $id = trim(self::unfold(self::value($fields, 'message-id') ?? ''));
        if (preg_match('/<[^<>\s]+>/', $id, $m)) {
            return $m[0];
        }
        return $id !== '' ? $id : 'sha1:' . sha1($raw);
    }

    /**
     * Header block → [[name, raw field text with its own line ends], …] and the body, the bytes
     * after the blank line exactly as they were.
     *
     * @return array{0: array<int,array{0:string,1:string}>, 1: string}
     */
    public static function split(string $raw): array {
        if (preg_match('/\r?\n\r?\n/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $header = substr($raw, 0, $m[0][1]);
            $body = (string) substr($raw, $m[0][1] + strlen($m[0][0]));
        } else {
            $header = rtrim($raw, "\r\n");
            $body = '';
        }

        $fields = [];
        foreach (preg_split('/(?<=\n)/', $header . "\r\n") as $line) {
            if ($line === '') {
                continue;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $fields) {
                $fields[count($fields) - 1][1] .= $line;            // a folded continuation
                continue;
            }
            $colon = strpos($line, ':');
            $name = $colon === false ? '' : rtrim(substr($line, 0, $colon));
            $fields[] = [$name, $line];
        }
        return [$fields, $body];
    }

    /** The raw value (after the colon, folds and line end kept but the last one) of a field. */
    private static function value(array $fields, string $name): ?string {
        foreach ($fields as [$n, $chunk]) {
            if (strcasecmp($n, $name) === 0) {
                return rtrim(substr($chunk, strpos($chunk, ':') + 1), "\r\n");
            }
        }
        return null;
    }

    private static function unfold(string $value): string {
        return trim(preg_replace('/\r?\n(?=[ \t])/', '', $value) ?? $value);
    }

    /** The first address of a From value: ['address' => lowercase, 'name' => decoded or '']. */
    private static function firstAddress(string $value): array {
        foreach (MimeParser::parseAddressList(self::unfold($value)) as $a) {
            if (isset($a->mailbox, $a->host) && $a->host !== 'UNKNOWN') {
                return [
                    'address' => strtolower($a->mailbox . '@' . $a->host),
                    'name' => trim(self::decode($a->personal ?? '')),
                ];
            }
        }
        return [];
    }

    /** RFC 2047 words → UTF-8; raw 8-bit text that is not UTF-8 is read as Latin-1. */
    public static function decode(string $value): string {
        $out = '';
        foreach (MimeParser::mimeHeaderDecode($value) as $word) {
            $text = $word->text;
            if ($word->charset !== 'default' && strcasecmp($word->charset, 'UTF-8') !== 0) {
                $converted = @iconv($word->charset, 'UTF-8//IGNORE', $text);
                $text = $converted === false ? $text : $converted;
            }
            if (!preg_match('//u', $text)) {
                $text = (string) @iconv('ISO-8859-1', 'UTF-8', $text);
            }
            $out .= $text;
        }
        return $out;
    }

    /** A display name: a quoted string if it is plain ASCII, else RFC 2047 words. */
    private static function phrase(string $utf8): string {
        if (!preg_match('/[^\x20-\x7E]/', $utf8)) {
            return '"' . addcslashes($utf8, '"\\') . '"';
        }
        return self::words($utf8);
    }

    /** Unstructured text (a subject): as is if plain ASCII, else RFC 2047 words. */
    private static function text(string $utf8): string {
        if (!preg_match('/[^\x20-\x7E]/', $utf8)) {
            return $utf8;
        }
        return self::words($utf8);
    }

    /** UTF-8 → base64 encoded words of ≤ 39 bytes each (64 characters), one per folded line. */
    private static function words(string $utf8): string {
        $words = [];
        $chunk = '';
        foreach (preg_split('//u', $utf8, -1, PREG_SPLIT_NO_EMPTY) ?: [$utf8] as $char) {
            if (strlen($chunk) + strlen($char) > 39) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode("\r\n ", $words);
    }
}
