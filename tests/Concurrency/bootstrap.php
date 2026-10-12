<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function flowguardConcurrencyConnection(): Connection
{
    if (getenv('FLOWGUARD_CONCURRENCY_TEST') !== '1' || getenv('FLOWGUARD_TEST_DATABASE') !== 'flowguard_ci') {
        throw new InvalidArgumentException('Concurrency tests require explicit opt-in and the disposable flowguard_ci database.');
    }

    $driver = getenv('FLOWGUARD_TEST_DRIVER');
    if (! in_array($driver, ['mysql', 'pgsql'], true)) {
        throw new InvalidArgumentException('Only mysql or pgsql concurrency tests are supported.');
    }

    $capsule = new Capsule;
    $capsule->addConnection([
        'driver' => $driver,
        'host' => getenv('FLOWGUARD_TEST_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('FLOWGUARD_TEST_PORT') ?: ($driver === 'mysql' ? '3306' : '5432')),
        'database' => 'flowguard_ci',
        'username' => getenv('FLOWGUARD_TEST_USERNAME') ?: 'flowguard',
        'password' => getenv('FLOWGUARD_TEST_PASSWORD') ?: 'flowguard',
        'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
        'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
        'prefix' => '',
    ]);

    return $capsule->getConnection();
}
