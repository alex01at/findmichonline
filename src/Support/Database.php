<?php

declare(strict_types=1);

namespace Kartenlink\App\Support;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(array $config): PDO
    {
        if (self::$connection === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['name']
            );

            self::$connection = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // The app's PHP process runs in UTC (default timezone, never
                // changed) - without this, MySQL's NOW()/DATE_ADD() use the
                // server's local system timezone instead, so anything
                // computed in SQL (trial_ends_at, demo_expires_at, ...) and
                // later read back through PHP's strtotime() (UTC) is off by
                // the host's UTC offset. Forcing the session to UTC here
                // keeps both sides of every "how much time is left" call
                // using the same clock.
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
            ]);
        }

        return self::$connection;
    }
}
