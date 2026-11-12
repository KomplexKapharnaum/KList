<?php
/**
 * RelayJournal - what went out for each message, per list
 *
 * One row per (Message-ID, list): `sent` once the provider took the copy (its RCPT outcomes kept:
 * who took it, who was refused and why), `failed` while it could not go out (attempts counted).
 * MailProcessor::forward() moves a message to DONE only when every active list it names is `sent`,
 * skips a list already `sent` when it tries the message again, and leaves out of a list's copy
 * anyone another list of the same message already tried — so nobody gets two copies, and a retry
 * never re-sends what went out (audit 2026-11-12, finding 2 and "no duplicates across lists").
 * The intake reads it too: a reply to a message a list relayed is part of that list's thread.
 *
 * load()/save() are the only storage calls: the tests swap them for an array.
 */

declare(strict_types=1);

class RelayJournal {
    public const TABLE_SQL = "
        CREATE TABLE IF NOT EXISTS relay_journal (
            message_id TEXT NOT NULL,
            liste TEXT NOT NULL,
            state TEXT NOT NULL,
            recipients TEXT NOT NULL DEFAULT '[]',
            refused TEXT NOT NULL DEFAULT '{}',
            attempts INTEGER NOT NULL DEFAULT 0,
            error TEXT NOT NULL DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (message_id, liste)
        )
    ";

    public function __construct(private ?PDO $pdo = null) {
    }

    public static function createTable(PDO $pdo): void {
        $pdo->exec(self::TABLE_SQL);
    }

    public function isSent(string $key, string $liste): bool {
        return ($this->load($key)[$liste]['state'] ?? '') === 'sent';
    }

    /**
     * Did this list relay one of these messages? The thread test of the intake (ListIntake): an
     * outside participant may answer a message the list sent out, and only that.
     */
    public function relayedAny(array $keys, string $liste): bool {
        foreach ($keys as $key) {
            if ($this->isSent($key, $liste)) {
                return true;
            }
        }
        return false;
    }

    /** Every address a `sent` list of this message already tried, taken or refused. */
    public function reached(string $key): array {
        $out = [];
        foreach ($this->load($key) as $row) {
            if ($row['state'] !== 'sent') {
                continue;
            }
            foreach ($row['recipients'] as $a) {
                $out[$a] = true;
            }
            foreach (array_keys($row['refused']) as $a) {
                $out[$a] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * @param string[]                $accepted who the provider took the copy for
     * @param array<string,string>    $refused  address => "550 5.1.1 …"
     */
    public function markSent(string $key, string $liste, array $accepted, array $refused): void {
        $row = $this->load($key)[$liste] ?? ['attempts' => 0];
        $this->save($key, $liste, [
            'state' => 'sent',
            'recipients' => array_values($accepted),
            'refused' => $refused,
            'attempts' => $row['attempts'] + 1,
            'error' => '',
        ]);
    }

    public function markFailed(string $key, string $liste, string $error): void {
        $row = $this->load($key)[$liste] ?? ['attempts' => 0];
        $this->save($key, $liste, [
            'state' => 'failed',
            'recipients' => [],
            'refused' => [],
            'attempts' => $row['attempts'] + 1,
            'error' => $error,
        ]);
    }

    /** @return array<string,array{state:string,recipients:string[],refused:array<string,string>,attempts:int,error:string}> */
    protected function load(string $key): array {
        $stmt = $this->pdo->prepare(
            "SELECT liste, state, recipients, refused, attempts, error FROM relay_journal WHERE message_id = ?"
        );
        $stmt->execute([$key]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$r['liste']] = [
                'state' => $r['state'],
                'recipients' => json_decode((string) $r['recipients'], true) ?: [],
                'refused' => json_decode((string) $r['refused'], true) ?: [],
                'attempts' => (int) $r['attempts'],
                'error' => (string) $r['error'],
            ];
        }
        return $rows;
    }

    protected function save(string $key, string $liste, array $row): void {
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO relay_journal
                (message_id, liste, state, recipients, refused, attempts, error, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?,
                COALESCE((SELECT created_at FROM relay_journal WHERE message_id = ? AND liste = ?), CURRENT_TIMESTAMP),
                CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $key, $liste, $row['state'],
            json_encode(array_values($row['recipients'])),
            json_encode((object) $row['refused']),
            $row['attempts'], $row['error'],
            $key, $liste,
        ]);
    }
}
