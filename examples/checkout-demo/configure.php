<?php

declare(strict_types=1);

$target = $argv[1] ?? '';
if (! is_file($target.'/artisan') || ! is_file($target.'/.env')) {
    throw new RuntimeException('Expected a newly generated Laravel application.');
}
$settings = [
    'APP_ENV' => 'local', 'APP_DEBUG' => 'false',
    'DEMO_PROVIDER_URL' => 'http://127.0.0.1:8099',
    'DEMO_PROVIDER_KEY' => bin2hex(random_bytes(24)),
    'DEMO_WEBHOOK_SECRET' => bin2hex(random_bytes(24)),
    'DEMO_MERCHANT_A_TOKEN' => bin2hex(random_bytes(24)),
    'DEMO_MERCHANT_B_TOKEN' => bin2hex(random_bytes(24)),
    'DEMO_REVIEWER_A_TOKEN' => bin2hex(random_bytes(24)),
    'DEMO_REVIEWER_B_TOKEN' => bin2hex(random_bytes(24)),
];
$content = file_get_contents($target.'/.env');
foreach ($settings as $key => $value) {
    $content = preg_replace('/^'.preg_quote($key, '/').'=.*\R?/m', '', $content);
    $content .= $key.'='.$value."\n";
}
file_put_contents($target.'/.env', $content);
chmod($target.'/.env', 0600);
