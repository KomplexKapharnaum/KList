<?php
/**
 * KXKM Mailing List Manager - Configuration
 * Pure PHP version with SQLite database
 */

declare(strict_types=1);

// PHP 8.0+ compatibility: polyfill for removed functions
// get_magic_quotes_runtime() was removed in PHP 8.0
// It always returned false since PHP 5.4 anyway
if (!function_exists('get_magic_quotes_runtime')) {
    function get_magic_quotes_runtime(): bool {
        return false;
    }
}

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Session configuration
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.use_strict_mode', '1');

// Timezone
date_default_timezone_set('Europe/Paris');

// Paths
define('ROOT_PATH', __DIR__);
define('DATA_PATH', ROOT_PATH . '/data');
define('SRC_PATH', ROOT_PATH . '/src');
define('TEMPLATES_PATH', ROOT_PATH . '/templates');
define('ASSETS_PATH', ROOT_PATH . '/assets');

// Database file
define('DB_FILE', DATA_PATH . '/listes.db');

// Autoloader for src classes
spl_autoload_register(function ($class) {
    $file = SRC_PATH . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if application is installed
function is_installed(): bool {
    return Database::isInstalled();
}

// Initialize database connection (only if installed)
$db = null;
$settings = [];

if (is_installed()) {
    $db = Database::getInstance();
    $settings = $db->getSettings();
}

// Helper function to get setting value
function setting(string $key, $default = null) {
    global $settings;
    return $settings[$key] ?? $default;
}

// Helper function for URL generation
function url(string $path = ''): string {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $base . '/' . ltrim($path, '/');
}

// Helper function for asset URLs
function asset(string $path): string {
    return url('assets/' . ltrim($path, '/'));
}

// Helper function for CSRF token
function csrf_token(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Helper function to verify CSRF token
function verify_csrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Helper function for escaping HTML
function e(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Helper function for flash messages
function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

// Helper function to check if user is logged in
function is_logged_in(): bool {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// Helper function to require login
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . url('?page=login'));
        exit;
    }
}
