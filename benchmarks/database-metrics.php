<?php

declare(strict_types=1);

require dirname(__DIR__).'/tests/Concurrency/bootstrap.php';

// Aggregate counters only. Never output connection configuration or SQL text.
$db = flowguardConcurrencyConnection();
$metrics = ['driver' => $db->getDriverName(), 'php_version' => PHP_VERSION];

if ($db->getDriverName() === 'mysql') {
    $metrics['server'] = $db->selectOne('SELECT VERSION() AS version, @@max_connections AS max_connections');
    $metrics['counters'] = $db->select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected', 'Max_used_connections', 'Innodb_row_lock_waits', 'Innodb_row_lock_time', 'Innodb_deadlocks', 'Connection_errors_max_connections')");
} else {
    $metrics['server'] = $db->selectOne("SELECT version() AS version, current_setting('max_connections') AS max_connections");
    $metrics['counters'] = $db->selectOne('SELECT numbackends, deadlocks, conflicts, xact_commit, xact_rollback, stats_reset FROM pg_stat_database WHERE datname = current_database()');
}

echo json_encode($metrics, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
