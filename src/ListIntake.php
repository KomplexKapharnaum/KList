<?php
/**
 * ListIntake - who may post to a list, and what a moderation link proves
 *
 * Decided 2026-11-12 (audit of the list logic, finding 3, 5, 6, 7, 8 and "an outside recipient can
 * answer"): the From: header alone proves nothing, so a message is trusted only when the provider's
 * own stamp says so; an outside participant may answer a thread the list relayed; automatic mail
 * never reaches a list; STOP works on every list; a moderation link only opens a page, and the
 * POST from that page acts.
 *
 *   verified    the TOP Authentication-Results field — the one the receiving MX added last — is
 *               the provider's (authserv-id, mx.infomaniak.com by default) and says dmarc=pass for
 *               the From domain. Any lower one, or one below another host's, may come from the
 *               sender: never read.
 *   automatic   Auto-Submitted other than "no", Precedence bulk/junk/list, X-Autoreply,
 *               X-Autorespond, or an X-Loop naming one of our own addresses: filed, never answered.
 *   thread      the Message-IDs named by In-Reply-To and References (the newest first): a verified
 *               non-subscriber answering one of them that the list relayed is a participant.
 *
 * decide() is the whole intake rule, as data: no IMAP, no SMTP, no database. MailProcessor::
 * dispatch() runs it and does what it says.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/Fetch/MimeParser.php';
require_once __DIR__ . '/ListRelay.php';

use Fetch\MimeParser;

final class ListIntake {
    public const AUTHSERV_ID = 'mx.infomaniak.com';

    /** How many thread ids a message may name before the rest is ignored (References grows). */
    public const MAX_THREAD_IDS = 30;

    /**
     * Does the provider's own stamp say the From domain passed DMARC?
     *
     * Only the first Authentication-Results field counts: the receiving MX prepends its own, so
     * anything below it was there before the message reached the provider — written by whoever
     * sent it. When that top field is not the provider's, nothing is trusted.
     */
    public static function dmarcPass(string $raw, string $authservId = self::AUTHSERV_ID, ?string $fromAddress = null): bool {
        [$fields] = ListRelay::split($raw);
        $top = null;
        foreach ($fields as [$name, $chunk]) {
            if (strcasecmp($name, 'Authentication-Results') === 0) {
                $top = substr($chunk, strpos($chunk, ':') + 1);
                break;
            }
        }
        if ($top === null) {
            return false;
        }

        $value = self::stripComments(preg_replace('/\r?\n[ \t]+/', ' ', $top) ?? $top);
        $parts = array_map('trim', explode(';', $value));
        $id = preg_split('/\s+/', array_shift($parts) ?? '')[0] ?? '';
        if (strcasecmp($id, $authservId) !== 0) {
            return false;
        }

        $domain = null;
        if ($fromAddress !== null && str_contains($fromAddress, '@')) {
            $domain = strtolower(substr($fromAddress, strrpos($fromAddress, '@') + 1));
        }
        foreach ($parts as $resinfo) {
            if (!preg_match('/^dmarc\s*=\s*([a-z]+)\b(.*)$/is', $resinfo, $m)) {
                continue;
            }
            if (strtolower($m[1]) !== 'pass') {
                return false;
            }
            // header.from names the domain DMARC was checked for: it must be the sender's own.
            if ($domain !== null && preg_match('/\bheader\.from\s*=\s*"?([^\s";]+)/i', $m[2], $h)
                && strtolower(rtrim($h[1], '.')) !== $domain) {
                return false;
            }
            return true;
        }
        return false;
    }

    /**
     * Why this message was written by a machine, or null for a person's message: an
     * out-of-office reply, a bulk mailing, another list's copy, or our own copy coming back.
     *
     * @param string[] $own our own addresses (the lists', the mailbox)
     */
    public static function automatic(string $raw, array $own): ?string {
        [$fields] = ListRelay::split($raw);
        $own = array_flip(array_map('strtolower', $own));
        foreach ($fields as [$name, $chunk]) {
            $lower = strtolower($name);
            $value = strtolower(trim(self::stripComments(preg_replace('/\r?\n[ \t]+/', ' ', substr($chunk, strpos($chunk, ':') + 1)) ?? '')));
            switch ($lower) {
                case 'auto-submitted':
                    if ($value !== '' && $value !== 'no') {
                        return 'auto-submitted: ' . $value;
                    }
                    break;
                case 'precedence':
                    if (in_array($value, ['bulk', 'junk', 'list'], true)) {
                        return 'precedence: ' . $value;
                    }
                    break;
                case 'x-autoreply':
                case 'x-autorespond':
                    return $lower;
                case 'x-loop':
                    foreach (MimeParser::parseAddressList($value) as $a) {
                        if (isset($a->mailbox, $a->host) && isset($own[strtolower($a->mailbox . '@' . $a->host)])) {
                            return 'x-loop: ' . strtolower($a->mailbox . '@' . $a->host);
                        }
                    }
                    if (isset($own[$value])) {
                        return 'x-loop: ' . $value;
                    }
                    break;
            }
        }
        return null;
    }

    /** The Message-IDs this message answers: In-Reply-To first, then References newest first. */
    public static function threadIds(string $raw): array {
        [$fields] = ListRelay::split($raw);
        $ids = [];
        foreach (['in-reply-to', 'references'] as $wanted) {
            foreach ($fields as [$name, $chunk]) {
                if (strtolower($name) !== $wanted) {
                    continue;
                }
                preg_match_all('/<[^<>\s]+>/', substr($chunk, strpos($chunk, ':') + 1), $m);
                $found = $wanted === 'references' ? array_reverse($m[0]) : $m[0];
                foreach ($found as $id) {
                    $ids[$id] = true;
                }
            }
        }
        return array_slice(array_keys($ids), 0, self::MAX_THREAD_IDS);
    }

    /** A subject asking to leave the list: "STOP", any case, nothing else. */
    public static function isStop(string $subject): bool {
        return strcasecmp(trim($subject), 'STOP') === 0;
    }

    /**
     * The intake rule. One message, the lists it names; returns what to do:
     *
     *   folder       PENDING | APPROVED | DISCARDED | DONE | OTHERS
     *   why          a short reason, for the log and the moderator (unverified, moderated,
     *                outside-broadcast, outside-other-list, not-subscriber, inactive, automatic…)
     *   notify       a notice for the SENDER ('not-subscriber' | 'inactive'), only ever to a
     *                verified one — or null
     *   inactive     the inactive lists named (their notice, when one is due)
     *   unsubscribe  the lists a verified STOP leaves
     *   stopUnverified the lists an unverified STOP named: nobody is removed, the admin is told
     *
     * @param array    $matched     the lists the message names, each with nom, active, moderation,
     *                              reponse, abonnes — one entry per list
     * @param string[] $allAbonnes  everyone on a list without moderation (who may post to any list)
     * @param callable $relayed     fn(string $nom): bool — did this list relay a message this one answers?
     */
    public static function decide(string $from, bool $verified, ?string $automatic, bool $stop,
                                  array $matched, array $allAbonnes, callable $relayed): array {
        $out = ['folder' => null, 'why' => '', 'notify' => null, 'inactive' => [], 'unsubscribe' => [], 'stopUnverified' => []];
        $from = strtolower($from);

        if (empty($matched)) {
            return ['folder' => 'OTHERS', 'why' => 'no-list'] + $out;
        }
        if ($automatic !== null) {
            return ['folder' => 'OTHERS', 'why' => 'automatic: ' . $automatic] + $out;
        }

        if ($stop) {
            $member = [];
            foreach ($matched as $l) {
                if (in_array($from, array_map('strtolower', $l['abonnes']), true)) {
                    $member[] = $l['nom'];
                }
            }
            if (empty($member)) {
                return ['folder' => 'DISCARDED', 'why' => 'stop-not-member'] + $out;
            }
            if (!$verified) {
                return ['folder' => 'DISCARDED', 'why' => 'stop-unverified', 'stopUnverified' => $member] + $out;
            }
            return ['folder' => 'DONE', 'why' => 'stop', 'unsubscribe' => $member] + $out;
        }

        $subscriber = in_array($from, $allAbonnes, true);
        $verdicts = [];
        foreach ($matched as $l) {
            if (!$l['active']) {
                $out['inactive'][] = $l['nom'];
                continue;
            }
            if ($subscriber) {
                $verdicts[] = !$verified ? ['PENDING', 'unverified']
                    : ($l['moderation'] ? ['PENDING', 'moderated'] : ['APPROVED', '']);
            } elseif (!$verified || !$relayed($l['nom'])) {
                $verdicts[] = ['REFUSE', 'not-subscriber'];
            } elseif ($l['moderation']) {
                $verdicts[] = ['PENDING', 'moderated'];
            } elseif (!$l['reponse']) {
                $verdicts[] = ['PENDING', 'outside-broadcast'];
            } else {
                $verdicts[] = ['APPROVED', ''];
            }
        }
        if ($out['inactive'] && $verified) {
            $out['notify'] = 'inactive';
        }

        if (empty($verdicts)) {
            return ['folder' => 'DISCARDED', 'why' => 'inactive'] + $out;
        }
        $folders = array_column($verdicts, 0);
        if (!in_array('REFUSE', $folders, true)) {
            $pending = array_search('PENDING', $folders, true);
            return $pending === false
                ? ['folder' => 'APPROVED', 'why' => ''] + $out
                : ['folder' => 'PENDING', 'why' => $verdicts[$pending][1]] + $out;
        }
        if (count(array_unique($folders)) > 1) {
            // a thread reply that also names a list it is not part of: forward() would relay it
            // to every list named, so a person decides
            return ['folder' => 'PENDING', 'why' => 'outside-other-list'] + $out;
        }
        return ['folder' => 'DISCARDED', 'why' => 'not-subscriber', 'notify' => $verified ? 'not-subscriber' : null] + $out;
    }

    /** The id a moderation link names: a hash of the message key (its Message-ID). */
    public static function moderationId(string $messageKey): string {
        return sha1($messageKey);
    }

    /** An HMAC over what a link may do; the secret never leaves the database. */
    public static function token(string $secret, string $purpose, string ...$parts): string {
        return hash_hmac('sha256', $purpose . "\0" . implode("\0", $parts), $secret);
    }

    public static function checkToken(string $secret, string $token, string $purpose, string ...$parts): bool {
        return $secret !== '' && $token !== '' && hash_equals(self::token($secret, $purpose, ...$parts), $token);
    }

    /** The link secret, made on first use; a new one voids every link already sent. */
    public static function secret(Database $db): string {
        $secret = (string) $db->getSetting('link_secret', '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $db->setSetting('link_secret', $secret);
        }
        return $secret;
    }

    /** RFC 5322 comments out: "(…)", nested, and quoted text left alone. */
    private static function stripComments(string $value): string {
        $out = '';
        $depth = 0;
        $quoted = false;
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $c = $value[$i];
            if ($c === '\\' && $i + 1 < $len) {
                if ($depth === 0) {
                    $out .= $c . $value[$i + 1];
                }
                $i++;
                continue;
            }
            if ($depth === 0 && $c === '"') {
                $quoted = !$quoted;
            } elseif (!$quoted && $c === '(') {
                $depth++;
                continue;
            } elseif (!$quoted && $c === ')' && $depth > 0) {
                $depth--;
                continue;
            }
            if ($depth === 0) {
                $out .= $c;
            }
        }
        return $out;
    }
}
