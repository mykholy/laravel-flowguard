<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use RuntimeException;
use stdClass;

final class UtcDatabaseClock
{
    public static function expression(Connection $connection): string
    {
        return match ($connection->getDriverName()) {
            'mysql' => 'UTC_TIMESTAMP(6)',
            'pgsql' => "(clock_timestamp() AT TIME ZONE 'UTC')",
            'sqlite' => "strftime('%Y-%m-%d %H:%M:%f', 'now')",
            default => throw new RuntimeException('Unsupported database clock driver.'),
        };
    }

    public static function now(Connection $connection): DateTimeImmutable
    {
        /** @var stdClass|null $row */
        $row = $connection->selectOne('SELECT '.self::expression($connection).' AS db_now');

        if ($row === null || ! is_string($row->db_now)) {
            throw new RuntimeException('Could not read database UTC clock.');
        }

        return new DateTimeImmutable($row->db_now, new DateTimeZone('UTC'));
    }
}
