<?php
declare(strict_types=1);

namespace Mur\Admin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class AdminAuth
{
    private const USER_KEY = 'mur_admin_user';
    private const CSRF_KEY = 'mur_admin_csrf';
    private const ATTEMPTS_KEY = 'mur_admin_login_attempts';

    public function __construct(private array $config)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name((string) ($this->config['session_name'] ?? 'mur_admin'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function user(): ?array
    {
        $this->start();
        $sessionUser = $_SESSION[self::USER_KEY] ?? null;
        if (!is_array($sessionUser)) {
            return null;
        }

        $user = $this->findUserById((string) ($sessionUser['id'] ?? ''));
        if ($user === null || ($user['active'] ?? false) !== true) {
            unset($_SESSION[self::USER_KEY]);
            return null;
        }

        $authVersion = $this->authVersion($user);
        $sessionVersion = (string) ($sessionUser['auth_version'] ?? '');
        if ($sessionVersion !== '' && !hash_equals($sessionVersion, $authVersion)) {
            unset($_SESSION[self::USER_KEY]);
            return null;
        }

        $_SESSION[self::USER_KEY] = $this->sessionUser($user, $authVersion);
        $safeUser = $_SESSION[self::USER_KEY];
        unset($safeUser['auth_version']);

        return $safeUser;
    }

    public function isLoggedIn(): bool
    {
        return $this->user() !== null;
    }

    public function hasSessionCookie(): bool
    {
        $name = (string) ($this->config['session_name'] ?? 'mur_admin');

        return isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) && $_COOKIE[$name] !== '';
    }

    public function login(string $login, string $password): bool
    {
        $this->start();

        if (!$this->canAttemptLogin()) {
            return false;
        }

        $user = $this->findUser($login);

        if ($user === null || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            $this->recordFailedAttempt();
            return false;
        }

        session_regenerate_id(true);
        unset($_SESSION[self::ATTEMPTS_KEY]);
        $_SESSION[self::USER_KEY] = $this->sessionUser($user, $this->authVersion($user));

        return true;
    }

    public function updateSessionUser(array $fields): void
    {
        $this->start();

        if (!is_array($_SESSION[self::USER_KEY] ?? null)) {
            return;
        }

        $_SESSION[self::USER_KEY] = array_replace($_SESSION[self::USER_KEY], $fields);
    }

    public function syncUserRecord(array $record): void
    {
        $recordId = (string) ($record['id'] ?? '');
        foreach (($this->config['users'] ?? []) as $index => $user) {
            if (is_array($user) && (string) ($user['id'] ?? '') === $recordId) {
                $this->config['users'][$index] = $record;
                break;
            }
        }

        $this->start();
        if ((string) ($_SESSION[self::USER_KEY]['id'] ?? '') === $recordId) {
            $_SESSION[self::USER_KEY] = $this->sessionUser($record, $this->authVersion($record));
        }
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }

        session_destroy();
    }

    public function csrfToken(): string
    {
        $this->start();

        if (empty($_SESSION[self::CSRF_KEY]) || !is_string($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_KEY];
    }

    public function verifyCsrf(string $token): bool
    {
        $this->start();
        $current = $_SESSION[self::CSRF_KEY] ?? '';

        return is_string($current) && $current !== '' && hash_equals($current, $token);
    }

    public function can(array $user, string $requiredRole): bool
    {
        $requiredRank = AdminUserManager::roleRank($requiredRole);

        return $requiredRank > 0
            && AdminUserManager::roleRank((string) ($user['role'] ?? '')) >= $requiredRank;
    }

    public function verifyCurrentPassword(array $sessionUser, string $password): bool
    {
        $userId = (string) ($sessionUser['id'] ?? '');

        foreach (($this->config['users'] ?? []) as $user) {
            if (!is_array($user) || (string) ($user['id'] ?? '') !== $userId) {
                continue;
            }

            return password_verify($password, (string) ($user['password_hash'] ?? ''));
        }

        return false;
    }

    public function renewSecurityContext(): void
    {
        $this->start();
        session_regenerate_id(true);
        $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
    }

    public function canAttemptLogin(): bool
    {
        $attempts = $_SESSION[self::ATTEMPTS_KEY] ?? ['count' => 0, 'time' => 0];

        if (!is_array($attempts)) {
            return true;
        }

        $count = (int) ($attempts['count'] ?? 0);
        $time = (int) ($attempts['time'] ?? 0);

        return $count < 8 || (time() - $time) > 300;
    }

    private function findUser(string $login): ?array
    {
        $needle = strtolower(trim($login));

        foreach (($this->config['users'] ?? []) as $user) {
            if (!is_array($user) || ($user['active'] ?? false) !== true) {
                continue;
            }

            $username = strtolower((string) ($user['username'] ?? ''));
            $email = strtolower((string) ($user['email'] ?? ''));

            if ($needle !== '' && ($needle === $username || $needle === $email)) {
                return $user;
            }
        }

        return null;
    }

    private function findUserById(string $id): ?array
    {
        foreach (($this->config['users'] ?? []) as $user) {
            if (is_array($user) && (string) ($user['id'] ?? '') === $id) {
                return $user;
            }
        }

        return null;
    }

    private function authVersion(array $user): string
    {
        return hash('sha256', (string) ($user['password_hash'] ?? ''));
    }

    private function sessionUser(array $user, string $authVersion): array
    {
        return [
            'id' => (string) ($user['id'] ?? ''),
            'username' => (string) ($user['username'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'phone' => (string) ($user['phone'] ?? ''),
            'display_name' => (string) ($user['display_name'] ?? $user['username'] ?? ''),
            'role' => AdminUserManager::normalizeRole((string) ($user['role'] ?? '')),
            'admin_language' => (string) ($user['admin_language'] ?? $this->config['ui']['default_language'] ?? 'ru'),
            'auth_version' => $authVersion,
        ];
    }

    private function recordFailedAttempt(): void
    {
        $attempts = $_SESSION[self::ATTEMPTS_KEY] ?? ['count' => 0, 'time' => time()];

        if (!is_array($attempts)) {
            $attempts = ['count' => 0, 'time' => time()];
        }

        $_SESSION[self::ATTEMPTS_KEY] = [
            'count' => (int) ($attempts['count'] ?? 0) + 1,
            'time' => time(),
        ];
    }
}
