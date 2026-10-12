<?php

declare(strict_types=1);

return [
    // Use a connection with durable, transactional storage. Never use SQLite memory in production.
    'connection' => env('FLOWGUARD_DB_CONNECTION', null),
    'table' => env('FLOWGUARD_DB_TABLE', 'flowguard_operations'),
];
