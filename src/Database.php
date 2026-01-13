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
        
        if (!file_exists($dbFile)) {
            throw new Exception('Database not initialized. Run setup first.');
        }
        
        $this->pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        
        // Enable foreign keys
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        
        // Run migrations for existing databases
        $this->runMigrations();
    }

    /**
     * Check if the application is installed (database exists)
     */
    public static function isInstalled(): bool {
        return file_exists(DB_FILE);
    }

    /**
     * Initialize the database with user-provided settings
     */
    public static function initialize(array $settings): void {
        $dbFile = DB_FILE;
        
        // Ensure data directory exists
        $dataDir = dirname($dbFile);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        
        // Enable foreign keys
        $pdo->exec('PRAGMA foreign_keys = ON');
        
        // Create tables
        self::createTablesOn($pdo);
        
        // Insert user-provided settings
        $stmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?)");
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }
    }

    private static function createTablesOn(PDO $pdo): void {
        // Settings table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Listes table
        $pdo->exec("
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

        // Subscribers table
        $pdo->exec("
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
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS blocklist (
                email TEXT PRIMARY KEY,
                code INTEGER NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create indexes
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_subscribers_liste ON subscribers(liste_id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_subscribers_email ON subscribers(email)");
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
