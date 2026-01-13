<?php
/**
 * ListManager - Handles mailing list operations
 */

declare(strict_types=1);

class ListManager {
    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    // Get all lists with subscriber count
    public function getAll(): array {
        return $this->db->fetchAll("
            SELECT l.*, 
                   (SELECT COUNT(*) FROM subscribers s WHERE s.liste_id = l.id AND s.active = 1) as subscriber_count
            FROM listes l 
            ORDER BY l.nom ASC
        ");
    }

    // Get all list names for sidebar
    public function getAllLabels(): array {
        return $this->db->fetchAll("
            SELECT l.id, l.nom, 
                   (SELECT COUNT(*) FROM subscribers s WHERE s.liste_id = l.id AND s.active = 1) as count
            FROM listes l 
            ORDER BY l.nom ASC
        ");
    }

    // Get single list by ID
    public function getById(int $id): ?array {
        return $this->db->fetchOne("SELECT * FROM listes WHERE id = ?", [$id]);
    }

    // Get single list by name
    public function getByName(string $name): ?array {
        return $this->db->fetchOne("SELECT * FROM listes WHERE nom = ?", [$name]);
    }

    // Create new list
    public function create(array $data): array {
        $errors = $this->validate($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Check for duplicate name
        if ($this->getByName($data['nom'])) {
            return ['success' => false, 'errors' => ['Une liste avec ce nom existe déjà.']];
        }

        $this->db->execute("
            INSERT INTO listes (nom, description, moderation, reponse, active) 
            VALUES (?, ?, ?, ?, ?)
        ", [
            strtolower($data['nom']),
            $data['description'] ?? '',
            (int)($data['moderation'] ?? 0),
            (int)($data['reponse'] ?? 0),
            (int)($data['active'] ?? 1),
        ]);

        return ['success' => true, 'id' => (int)$this->db->lastInsertId()];
    }

    // Update existing list
    public function update(int $id, array $data): array {
        $errors = $this->validate($data, $id);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Check for duplicate name (excluding current list)
        $existing = $this->getByName($data['nom']);
        if ($existing && $existing['id'] !== $id) {
            return ['success' => false, 'errors' => ['Une liste avec ce nom existe déjà.']];
        }

        $this->db->execute("
            UPDATE listes SET 
                nom = ?, 
                description = ?, 
                moderation = ?, 
                reponse = ?, 
                active = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [
            strtolower($data['nom']),
            $data['description'] ?? '',
            (int)($data['moderation'] ?? 0),
            (int)($data['reponse'] ?? 0),
            (int)($data['active'] ?? 1),
            $id
        ]);

        return ['success' => true];
    }

    // Delete list
    public function delete(int $id): bool {
        // Subscribers will be cascade deleted due to foreign key
        return $this->db->execute("DELETE FROM listes WHERE id = ?", [$id]) > 0;
    }

    // Validate list data
    private function validate(array $data, ?int $excludeId = null): array {
        $errors = [];

        if (empty($data['nom'])) {
            $errors[] = 'Le nom de la liste est obligatoire.';
        } elseif (!preg_match('/^[a-z0-9_\-\.]+$/i', $data['nom'])) {
            $errors[] = 'Le nom ne peut contenir que des lettres, chiffres, tirets, underscores et points.';
        }

        return $errors;
    }

    // Get subscribers for a list
    public function getSubscribers(int $listeId): array {
        return $this->db->fetchAll(
            "SELECT * FROM subscribers WHERE liste_id = ? ORDER BY email ASC",
            [$listeId]
        );
    }

    // Get active subscriber emails for a list
    public function getActiveSubscriberEmails(int $listeId): array {
        $rows = $this->db->fetchAll(
            "SELECT email FROM subscribers WHERE liste_id = ? AND active = 1 ORDER BY email ASC",
            [$listeId]
        );
        return array_column($rows, 'email');
    }

    // Add subscribers to a list (from text input with multiple formats)
    public function addSubscribers(int $listeId, string $input): array {
        $added = 0;
        $errors = [];

        // Split by newline, semicolon, or comma
        $emails = array_map('trim', preg_split('/[\n;,]+/', $input));
        $emails = array_filter($emails);
        $emails = array_unique(array_map('strtolower', $emails));

        foreach ($emails as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Email invalide: {$email}";
                continue;
            }

            try {
                $this->db->execute(
                    "INSERT OR IGNORE INTO subscribers (liste_id, email) VALUES (?, ?)",
                    [$listeId, $email]
                );
                $added++;
            } catch (PDOException $e) {
                $errors[] = "Erreur pour {$email}: " . $e->getMessage();
            }
        }

        return ['added' => $added, 'errors' => $errors];
    }

    // Remove subscriber from a list
    public function removeSubscriber(int $listeId, string $email): bool {
        return $this->db->execute(
            "DELETE FROM subscribers WHERE liste_id = ? AND email = ?",
            [$listeId, strtolower($email)]
        ) > 0;
    }

    // Remove subscriber by email from all lists (for unsubscribe)
    public function removeSubscriberFromList(string $listName, string $email): bool {
        $list = $this->getByName($listName);
        if (!$list) {
            return false;
        }
        return $this->removeSubscriber($list['id'], $email);
    }

    // Check if email is subscribed to any non-moderated list
    public function isSubscriberOfAnyList(string $email): bool {
        $result = $this->db->fetchOne("
            SELECT 1 FROM subscribers s
            JOIN listes l ON s.liste_id = l.id
            WHERE s.email = ? AND s.active = 1 AND l.moderation = 0
            LIMIT 1
        ", [strtolower($email)]);
        
        return $result !== null;
    }

    // Export subscribers as CSV
    public function exportSubscribersCSV(int $listeId): string {
        $subscribers = $this->getActiveSubscriberEmails($listeId);
        
        $output = fopen('php://temp', 'r+');
        foreach ($subscribers as $email) {
            fputcsv($output, [$email]);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    // Get all lists with their subscribers (for mail processing)
    public function getAllWithSubscribers(): array {
        $lists = $this->getAll();
        foreach ($lists as &$list) {
            $list['abonnes'] = $this->getActiveSubscriberEmails($list['id']);
        }
        return $lists;
    }

    // Update last_used timestamp for a list
    public function markAsUsed(int $id): void {
        $this->db->execute(
            "UPDATE listes SET last_used = CURRENT_TIMESTAMP WHERE id = ?",
            [$id]
        );
    }

    // Update last_used by list name
    public function markAsUsedByName(string $name): void {
        $this->db->execute(
            "UPDATE listes SET last_used = CURRENT_TIMESTAMP WHERE nom = ?",
            [strtolower($name)]
        );
    }

    // Search subscribers by email (partial match)
    public function searchSubscriber(string $query): array {
        $query = strtolower(trim($query));
        if (empty($query)) {
            return [];
        }
        
        return $this->db->fetchAll("
            SELECT s.email, s.liste_id, l.nom as liste_nom, s.created_at
            FROM subscribers s
            JOIN listes l ON s.liste_id = l.id
            WHERE s.email LIKE ? AND s.active = 1
            ORDER BY s.email, l.nom
        ", ['%' . $query . '%']);
    }

    // Unsubscribe email from all lists
    public function unsubscribeFromAll(string $email): int {
        return $this->db->execute(
            "DELETE FROM subscribers WHERE email = ?",
            [strtolower($email)]
        );
    }

    // Get total unique subscriber count (across all lists)
    public function getUniqueSubscriberCount(): int {
        $result = $this->db->fetchOne(
            "SELECT COUNT(DISTINCT email) as count FROM subscribers WHERE active = 1"
        );
        return (int)($result['count'] ?? 0);
    }
}
