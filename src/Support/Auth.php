<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

use Kartenlink\App\Model\User;
use PDO;

final class Auth
{
    private const REMEMBER_COOKIE = 'remember_token';
    private const REMEMBER_TTL_SECONDS = 60 * 60 * 24 * 30; // 30 days

    public function __construct(private PDO $db)
    {
    }

    public function login(array $user, bool $remember = false): void
    {
        Session::regenerate();
        Session::set('user_id', $user['id']);

        if ($remember) {
            $this->issueRememberToken((int) $user['id']);
        }
    }

    public function logout(): void
    {
        $userId = Session::get('user_id');
        if ($userId !== null) {
            (new User($this->db))->clearRememberToken((int) $userId);
        }

        $this->clearRememberCookie();
        Session::destroy();
    }

    public function check(): bool
    {
        return Session::get('user_id') !== null;
    }

    /**
     * Logs the user in transparently from a "remember me" cookie if there's
     * no active session but a valid, unexpired remember token. The token is
     * rotated on every use, so a copied/leaked cookie stops working the next
     * time the legitimate user's browser uses (and replaces) it.
     */
    public function attemptRememberLogin(): void
    {
        if ($this->check() || !isset($_COOKIE[self::REMEMBER_COOKIE])) {
            return;
        }

        $token = (string) $_COOKIE[self::REMEMBER_COOKIE];
        $user = (new User($this->db))->findByValidRememberTokenHash(hash('sha256', $token));

        if ($user === null) {
            $this->clearRememberCookie();
            return;
        }

        $this->login($user, remember: true);
    }

    public function user(): ?array
    {
        $userId = Session::get('user_id');
        if ($userId === null) {
            return null;
        }

        return (new User($this->db))->findById((int) $userId);
    }

    public function plan(): string
    {
        $user = $this->user();
        return $user['plan'] ?? Features::FREE;
    }

    public function can(string $feature): bool
    {
        return Features::allows($this->plan(), $feature);
    }

    public function isAdmin(): bool
    {
        $user = $this->user();
        return $user !== null && (bool) $user['is_admin'];
    }

    private function issueRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        (new User($this->db))->setRememberToken($userId, hash('sha256', $token), self::REMEMBER_TTL_SECONDS);

        setcookie(self::REMEMBER_COOKIE, $token, [
            'expires' => time() + self::REMEMBER_TTL_SECONDS,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearRememberCookie(): void
    {
        if (!isset($_COOKIE[self::REMEMBER_COOKIE])) {
            return;
        }

        unset($_COOKIE[self::REMEMBER_COOKIE]);
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
