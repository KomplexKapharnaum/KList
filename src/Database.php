<?php
/**
 * Database - PDO SQLite wrapper with singleton pattern
 */

declare(strict_types=1);

class Database {
    private static ?Database $instance = null;
    private PDO $pdo;
    private array $settingsCache = [];

    private function __construct() {
        $dbFile = DB_FILE;
        $isNewDb = !file_exists($dbFile);
        
        // Ensure data directory exists
        $dataDir = dirname($dbFile);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        
        $this->pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        
        // Enable foreign keys
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        
        // Create tables if new database
        if ($isNewDb) {
            $this->createTables();
        }
        
        // Run migrations for existing databases
        $this->runMigrations();
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    private function createTables(): void {
        // Settings table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Listes table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS listes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nom TEXT NOT NULL UNIQUE,
                description TEXT,
                moderation INTEGER NOT NULL DEFAULT 0,
                reponse INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                last_used DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Subscribers table (normalized - no more semicolon-separated strings!)
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS subscribers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                liste_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (liste_id) REFERENCES listes(id) ON DELETE CASCADE,
                UNIQUE(liste_id, email)
            )
        ");

        // Blocklist table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS blocklist (
                email TEXT PRIMARY KEY,
                code INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create index for faster subscriber lookups
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_subscribers_liste ON subscribers(liste_id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_subscribers_email ON subscribers(email)");

        // Insert default settings
        $this->insertDefaultSettings();
    }

    private function runMigrations(): void {
        // Check if last_used column exists in listes table
        $columns = $this->pdo->query("PRAGMA table_info(listes)")->fetchAll();
        $hasLastUsed = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'last_used') {
                $hasLastUsed = true;
                break;
            }
        }
        
        if (!$hasLastUsed) {
            $this->pdo->exec("ALTER TABLE listes ADD COLUMN last_used DATETIME");
        }
    }

    private function insertDefaultSettings(): void {
        $defaults = [
            'site_title' => 'Mailing List Manager',
            'admin_email' => 'admin@example.com',
            'admin_password' => password_hash('changeme', PASSWORD_DEFAULT),
            'imap_host' => 'mail.example.com',
            'imap_port' => '993',
            'imap_user' => 'listes@example.com',
            'imap_password' => '',
            'smtp_host' => 'mail.example.com',
            'smtp_port' => '587',
            'smtp_user' => 'listes@example.com',
            'smtp_password' => '',
            'domains' => 'example.com',
            'cron_key' => bin2hex(random_bytes(16)),
        ];

        $stmt = $this->pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        foreach ($defaults as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    }

    // Settings methods
    public function getSettings(): array {
        if (empty($this->settingsCache)) {
            $stmt = $this->pdo->query("SELECT key, value FROM settings");
            while ($row = $stmt->fetch()) {
                $this->settingsCache[$row['key']] = $row['value'];
            }
        }
        return $this->settingsCache;
    }

    public function getSetting(string $key, $default = null): ?string {
        $settings = $this->getSettings();
        return $settings[$key] ?? $default;
    }

    public function setSetting(string $key, string $value): void {
        // Use INSERT OR REPLACE for SQLite compatibility (works on all versions)
        $stmt = $this->pdo->prepare("
            INSERT OR REPLACE INTO settings (key, value, updated_at) 
            VALUES (?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([$key, $value]);
        $this->settingsCache[$key] = $value;
    }

    public function setSettings(array $settings): void {
        foreach ($settings as $key => $value) {
            $this->setSetting($key, $value);
        }
    }

    // Generic query methods
    public function query(string $sql, array $params = []): PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    public function execute(string $sql, array $params = []): int {
        return $this->query($sql, $params)->rowCount();
    }

    public function lastInsertId(): string {
        return $this->pdo->lastInsertId();
    }
}
