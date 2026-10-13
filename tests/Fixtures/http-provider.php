<?php

declare(strict_types=1);

// An isolated HTTP fixture, not a webhook verifier or a real payment gateway.
$path = getenv('FLOWGUARD_TEST_PROVIDER_STATE');

if (is_string($path) === false || $path === '') {
    http_response_code(500);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $_SERVER['REQUEST_URI'] === '/health') {
    header('Content-Type: application/json');
    echo '{"ready":true}';
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $_SERVER['REQUEST_URI'] !== '/events') {
    http_response_code(404);
    exit;
}

try {
    $data = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

    if (is_array($data) === false
        || is_string($data['id'] ?? null) === false
        || preg_match('/^[a-f0-9-]{36}$/D', $data['id']) !== 1
        || is_string($data['scope'] ?? null) === false
        || is_string($data['topic'] ?? null) === false) {
        throw new InvalidArgumentException('Invalid provider fixture event.');
    }

    $handle = fopen($path, 'c+');

    if ($handle === false || flock($handle, LOCK_EX) === false) {
        throw new RuntimeException('Could not lock provider fixture state.');
    }

    try {
        rewind($handle);
        $contents = stream_get_contents($handle);
        $state = $contents === '' ? ['requests' => 0, 'events' => []]
            : json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);

        if (is_array($state) === false || is_array($state['events'] ?? null) === false) {
            throw new RuntimeException('Invalid provider fixture state.');
        }

        $state['requests'] = (int) ($state['requests'] ?? 0) + 1;
        $duplicate = array_key_exists($data['id'], $state['events']);

        if ($duplicate) {
            if ($state['events'][$data['id']] !== [
                'scope' => $data['scope'],
                'topic' => $data['topic'],
            ]) {
                http_response_code(409);
                exit;
            }
        } else {
            $state['events'][$data['id']] = [
                'scope' => $data['scope'],
                'topic' => $data['topic'],
            ];
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    header('Content-Type: application/json');
    echo json_encode(['accepted' => true, 'duplicate' => $duplicate], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(500);
    echo '{"accepted":false}';
}
