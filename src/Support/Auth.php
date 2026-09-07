<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

use Kartenlink\App\Model\User;
use PDO;

final class Auth
{
    public function __construct(private PDO $db)
    {
    }

    public function login(array $user): void
    {
        Session::regenerate();
        Session::set('user_id', $user['id']);
    }

    public function logout(): void
    {
        Session::destroy();
    }

    public function check(): bool
    {
        return Session::get('user_id') !== null;
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
}
