<?php
/**
 * BlocklistManager - Handles email blocklist operations
 */

declare(strict_types=1);

class BlocklistManager {
    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Get all blocked emails
    public function getAll(): array {
        return $this->db->fetchAll("SELECT * FROM blocklist ORDER BY email ASC");
    }

    // Get blocked emails as associative array (email => code)
    public function getAllAsMap(): array {
        $rows = $this->getAll();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['email']] = (int)$row['code'];
        }
        return $map;
    }

    // Check if email is blocked
    public function isBlocked(string $email): bool {
        $result = $this->db->fetchOne(
            "SELECT 1 FROM blocklist WHERE email = ?",
            [strtolower($email)]
        );
        return $result !== null;
    }

    // Get block code for email
    public function getCode(string $email): ?int {
        $result = $this->db->fetchOne(
            "SELECT code FROM blocklist WHERE email = ?",
            [strtolower($email)]
        );
        return $result ? (int)$result['code'] : null;
    }

    // Add email to blocklist
    public function block(string $email, int $code = 550): bool {
        try {
            // Delete first to handle upsert
            $this->db->execute("DELETE FROM blocklist WHERE email = ?", [strtolower($email)]);
            $this->db->execute(
                "INSERT INTO blocklist (email, code) VALUES (?, ?)",
                [strtolower($email), $code]
            );
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    // Remove email from blocklist
    public function unblock(string $email): bool {
        return $this->db->execute(
            "DELETE FROM blocklist WHERE email = ?",
            [strtolower($email)]
        ) > 0;
    }
}
