<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    public static function isValid(string $password): bool
    {
        return strlen($password) >= self::MIN_LENGTH
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1;
    }
}
