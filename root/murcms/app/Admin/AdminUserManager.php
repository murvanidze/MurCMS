<?php
declare(strict_types=1);

namespace Mur\Admin;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class AdminUserManager
{
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_EDITOR = 'editor';

    private array $users;

    public function __construct(array $users)
    {
        $this->users = array_values(array_filter($users, 'is_array'));
    }

    public static function normalizeRole(string $role): string
    {
        return $role === 'content' ? self::ROLE_EDITOR : $role;
    }

    public static function roleRank(string $role): int
    {
        return match (self::normalizeRole($role)) {
            self::ROLE_EDITOR => 10,
            self::ROLE_ADMIN => 100,
            self::ROLE_SUPER_ADMIN => 1000,
            default => 0,
        };
    }

    public static function roleLabel(string $role): string
    {
        return match (self::normalizeRole($role)) {
            self::ROLE_SUPER_ADMIN => 'Супер-администратор',
            self::ROLE_ADMIN => 'Администратор',
            self::ROLE_EDITOR => 'Редактор',
            default => 'Неизвестная роль',
        };
    }

    public static function passwordPolicyError(string $password): string
    {
        if (strlen($password) < 14 || strlen($password) > 200) {
            return 'Пароль должен содержать от 14 до 200 символов.';
        }

        if (
            !preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/[0-9]/', $password)
            || !preg_match('/[^A-Za-z0-9]/', $password)
        ) {
            return 'Пароль должен содержать строчную и прописную буквы, цифру и специальный символ.';
        }

        return '';
    }

    public function users(): array
    {
        return $this->users;
    }

    public function find(string $id): ?array
    {
        foreach ($this->users as $user) {
            if ((string) ($user['id'] ?? '') === $id) {
                return $user;
            }
        }

        return null;
    }

    public function creatableRoles(array $actor): array
    {
        return match (self::normalizeRole((string) ($actor['role'] ?? ''))) {
            self::ROLE_SUPER_ADMIN => [self::ROLE_ADMIN, self::ROLE_EDITOR],
            self::ROLE_ADMIN => [self::ROLE_EDITOR],
            default => [],
        };
    }

    public function canManage(array $actor, array $target): bool
    {
        if ((string) ($actor['id'] ?? '') === (string) ($target['id'] ?? '')) {
            return false;
        }

        $actorRole = self::normalizeRole((string) ($actor['role'] ?? ''));
        $targetRole = self::normalizeRole((string) ($target['role'] ?? ''));

        return $actorRole === self::ROLE_SUPER_ADMIN
            ? in_array($targetRole, [self::ROLE_ADMIN, self::ROLE_EDITOR], true)
            : $actorRole === self::ROLE_ADMIN && $targetRole === self::ROLE_EDITOR;
    }

    public function save(array $actor, array $input): array
    {
        $targetId = trim((string) ($input['id'] ?? ''));
        $target = $targetId !== '' ? $this->find($targetId) : null;
        $creating = $target === null;

        if (!$creating && !$this->canManage($actor, $target)) {
            throw new \RuntimeException('Недостаточно прав для изменения этого пользователя.');
        }

        $allowedRoles = $this->creatableRoles($actor);
        $role = self::normalizeRole(trim((string) ($input['role'] ?? ($target['role'] ?? ''))));
        if (!in_array($role, $allowedRoles, true)) {
            throw new \RuntimeException('Выбран недоступный уровень доступа.');
        }

        $username = $creating
            ? strtolower(trim((string) ($input['username'] ?? '')))
            : (string) ($target['username'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username)) {
            throw new \RuntimeException('Логин должен содержать 3–64 латинских символа, цифры, точки, дефисы или подчёркивания.');
        }

        $displayName = trim((string) ($input['display_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        $this->validateProfileFields($displayName, $email, $phone);
        $this->assertUnique($username, $email, $targetId);

        $password = (string) ($input['password'] ?? '');
        $confirmation = (string) ($input['password_confirm'] ?? '');
        if ($creating || $password !== '' || $confirmation !== '') {
            if ($password !== $confirmation) {
                throw new \RuntimeException('Пароль и подтверждение не совпадают.');
            }
            $passwordError = self::passwordPolicyError($password);
            if ($passwordError !== '') {
                throw new \RuntimeException($passwordError);
            }
        }

        $saved = $target ?? [];
        $saved['id'] = $creating ? 'usr_' . bin2hex(random_bytes(8)) : $targetId;
        $saved['username'] = $username;
        $saved['email'] = $email;
        $saved['phone'] = $phone;
        $saved['display_name'] = $displayName;
        $saved['role'] = $role;
        $saved['admin_language'] = trim((string) ($input['admin_language'] ?? ($target['admin_language'] ?? 'ru')));
        $saved['active'] = (bool) ($input['active'] ?? false);
        if ($creating || $password !== '') {
            $saved['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        if ($creating) {
            if (count($this->users) >= 200) {
                throw new \RuntimeException('Достигнут лимит пользователей.');
            }
            $this->users[] = $saved;
        } else {
            foreach ($this->users as $index => $user) {
                if ((string) ($user['id'] ?? '') === $targetId) {
                    $this->users[$index] = $saved;
                    break;
                }
            }
        }

        return $saved;
    }

    public function updateOwnProfile(array $actor, array $input): array
    {
        $targetId = (string) ($actor['id'] ?? '');
        $target = $this->find($targetId);
        if ($target === null) {
            throw new \RuntimeException('Пользователь не найден в конфигурации.');
        }

        $displayName = trim((string) ($input['display_name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        $this->validateProfileFields($displayName, $email, $phone);
        $this->assertUnique((string) ($target['username'] ?? ''), $email, $targetId);

        $target['display_name'] = $displayName;
        $target['email'] = $email;
        $target['phone'] = $phone;

        foreach ($this->users as $index => $user) {
            if ((string) ($user['id'] ?? '') === $targetId) {
                $this->users[$index] = $target;
                break;
            }
        }

        return $target;
    }

    public function delete(array $actor, string $targetId): array
    {
        $target = $this->find($targetId);
        if ($target === null) {
            throw new \RuntimeException('Пользователь не найден.');
        }
        if (!$this->canManage($actor, $target)) {
            throw new \RuntimeException('Недостаточно прав для удаления этого пользователя.');
        }

        $this->users = array_values(array_filter(
            $this->users,
            static fn (array $user): bool => (string) ($user['id'] ?? '') !== $targetId,
        ));

        return $target;
    }

    private function validateProfileFields(string $displayName, string $email, string $phone): void
    {
        if ($displayName === '' || strlen($displayName) > 100 || preg_match('/[\x00-\x1F\x7F]/', $displayName)) {
            throw new \RuntimeException('Имя должно содержать от 1 до 100 символов.');
        }
        if ($email === '' || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Укажите корректный e-mail.');
        }
        if (strlen($phone) > 50 || preg_match('/[\x00-\x1F\x7F]/', $phone)) {
            throw new \RuntimeException('Телефон содержит недопустимые символы.');
        }
    }

    private function assertUnique(string $username, string $email, string $exceptId): void
    {
        foreach ($this->users as $user) {
            if ((string) ($user['id'] ?? '') === $exceptId) {
                continue;
            }
            if (strtolower((string) ($user['username'] ?? '')) === strtolower($username)) {
                throw new \RuntimeException('Пользователь с таким логином уже существует.');
            }
            if (strtolower((string) ($user['email'] ?? '')) === strtolower($email)) {
                throw new \RuntimeException('Пользователь с таким e-mail уже существует.');
            }
        }
    }
}
