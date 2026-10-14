<?php

declare(strict_types=1);

// Owned protocol simulator with a separate durable database, not a payment gateway.
$key = getenv('DEMO_PROVIDER_KEY');
$path = getenv('DEMO_PROVIDER_STATE');
if (! is_string($key) || $key === '' || ! is_string($path) || $path === '') {
    http_response_code(503);
    exit;
}
header('Content-Type: application/json');
if (! hash_equals('Bearer '.$key, $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
    http_response_code(401);
    exit;
}
$db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA busy_timeout = 5000');
$db->exec('CREATE TABLE IF NOT EXISTS receipts (id TEXT PRIMARY KEY, hash TEXT NOT NULL, payload TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 1)');
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_starts_with($uri, '/receipts/')) {
    $statement = $db->prepare('SELECT payload, attempts FROM receipts WHERE id = ?');
    $statement->execute([rawurldecode(substr($uri, strlen('/receipts/')))]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        http_response_code(404);
        echo json_encode(['found' => false], JSON_THROW_ON_ERROR);
    } else {
        echo json_encode(array_merge(json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR), ['attempts' => (int) $row['attempts']]), JSON_THROW_ON_ERROR);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $uri !== '/events') {
    http_response_code(404);
    exit;
}
$raw = file_get_contents('php://input', length: 65537);
if ($raw === false || strlen($raw) > 65536) {
    http_response_code(413);
    exit;
}
try {
    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (! is_array($payload) || ! is_string($payload['id'] ?? null) || preg_match('/^[a-f0-9-]{36}$/D', $payload['id']) !== 1) {
        throw new InvalidArgumentException;
    }
} catch (Throwable) {
    http_response_code(422);
    exit;
}
$hash = hash('sha256', $raw);
$db->beginTransaction();
$find = $db->prepare('SELECT hash FROM receipts WHERE id = ?');
$find->execute([$payload['id']]);
$existing = $find->fetchColumn();
if ($existing !== false && ! hash_equals($existing, $hash)) {
    $db->rollBack();
    http_response_code(409);
    exit;
}
if ($existing === false) {
    $insert = $db->prepare('INSERT INTO receipts (id, hash, payload) VALUES (?, ?, ?)');
    $insert->execute([$payload['id'], $hash, $raw]);
} else {
    $increment = $db->prepare('UPDATE receipts SET attempts = attempts + 1 WHERE id = ?');
    $increment->execute([$payload['id']]);
}
$db->commit();
if ($existing === false && ($_SERVER['HTTP_X_DEMO_DELAY_ACK'] ?? '') === '1') {
    // Commit before waiting: the HTTP client times out while the effect is durable.
    sleep(2);
}
echo json_encode(['accepted' => true, 'id' => $payload['id'], 'duplicate' => $existing !== false], JSON_THROW_ON_ERROR);
