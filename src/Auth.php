<?php
/**
 * Auth - Simple session-based admin authentication
 */

declare(strict_types=1);

class Auth {
    private Database $db;
    
    // Rate limiting: max attempts and lockout duration
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 900; // 15 minutes in seconds

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function login(string $email, string $password): bool|string {
        // Check for rate limiting
        if ($this->isLockedOut()) {
            $remaining = $this->getLockoutRemaining();
            return "Trop de tentatives. Réessayez dans " . ceil($remaining / 60) . " minute(s).";
        }
        
        $storedEmail = $this->db->getSetting('admin_email');
        $storedPassword = $this->db->getSetting('admin_password');

        if (strtolower($email) === strtolower($storedEmail) && 
            password_verify($password, $storedPassword)) {
            
            // Clear failed attempts on successful login
            $this->clearLoginAttempts();
            
            // Regenerate session ID on login
            session_regenerate_id(true);
            
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_email'] = $storedEmail;
            $_SESSION['login_time'] = time();
            
            return true;
        }
        
        // Record failed attempt
        $this->recordFailedAttempt();
        
        return false;
    }
    
    /**
     * Check if login is currently locked out
     */
    private function isLockedOut(): bool {
        $attempts = $_SESSION['login_attempts'] ?? 0;
        $lockoutTime = $_SESSION['lockout_time'] ?? 0;
        
        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            if (time() - $lockoutTime < self::LOCKOUT_DURATION) {
                return true;
            }
            // Lockout expired, reset
            $this->clearLoginAttempts();
        }
        
        return false;
    }
    
    /**
     * Get remaining lockout time in seconds
     */
    private function getLockoutRemaining(): int {
        $lockoutTime = $_SESSION['lockout_time'] ?? 0;
        return max(0, self::LOCKOUT_DURATION - (time() - $lockoutTime));
    }
    
    /**
     * Record a failed login attempt
     */
    private function recordFailedAttempt(): void {
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = 0;
        }
        $_SESSION['login_attempts']++;
        
        if ($_SESSION['login_attempts'] >= self::MAX_LOGIN_ATTEMPTS) {
            $_SESSION['lockout_time'] = time();
        }
    }
    
    /**
     * Clear login attempts (after successful login or lockout expiry)
     */
    private function clearLoginAttempts(): void {
        unset($_SESSION['login_attempts']);
        unset($_SESSION['lockout_time']);
    }

    public function logout(): void {
        $_SESSION = [];
        
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        
        session_destroy();
    }

    public function isLoggedIn(): bool {
        return is_logged_in();
    }

    public function changePassword(string $currentPassword, string $newPassword): bool {
        $storedPassword = $this->db->getSetting('admin_password');
        
        if (!password_verify($currentPassword, $storedPassword)) {
            return false;
        }
        
        $this->db->setSetting('admin_password', password_hash($newPassword, PASSWORD_DEFAULT));
        return true;
    }

    public function updateCredentials(string $email, ?string $newPassword = null): void {
        $this->db->setSetting('admin_email', $email);
        
        if ($newPassword !== null && $newPassword !== '') {
            $this->db->setSetting('admin_password', password_hash($newPassword, PASSWORD_DEFAULT));
        }
    }
}
