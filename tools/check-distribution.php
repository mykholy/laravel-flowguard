<?php

declare(strict_types=1);

// Verify what Composer will actually install, rather than the source checkout.
$archive = $argv[1] ?? '';
$zip = new ZipArchive;
if ($archive === '' || $zip->open($archive) !== true) {
    throw new RuntimeException('Pass a readable Composer ZIP archive.');
}

$required = ['composer.json', 'LICENSE', 'README.md', 'CHANGELOG.md', 'SECURITY.md',
    'src/FlowguardServiceProvider.php', 'config/flowguard.php', 'docs/OPERATIONS.md'];
foreach ($required as $path) {
    if ($zip->locateName($path) === false) {
        throw new RuntimeException('Distribution is missing '.$path);
    }
}

$migrations = 0;
for ($index = 0; $index < $zip->numFiles; $index++) {
    $path = $zip->getNameIndex($index);
    if ($path === false || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
        throw new RuntimeException('Unsafe archive entry.');
    }
    if (preg_match('~^(?:vendor|tests|benchmarks|tools|dist|\.git|\.github)/|^\.env(?:\.|$)|^composer\.lock$~', $path) === 1) {
        throw new RuntimeException('Development or private file included: '.$path);
    }
    if (str_starts_with($path, 'database/migrations/') && str_ends_with($path, '.php')) {
        $migrations++;
    }
}

foreach (['src', 'config', 'database/migrations'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/'.$directory));
    foreach ($files as $file) {
        if ($file->isFile() === false || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen(dirname(__DIR__)) + 1);
        $content = $zip->getFromName($relative);
        if ($content === false || hash('sha256', $content) !== hash_file('sha256', $file->getPathname())) {
            throw new RuntimeException('Missing or changed runtime file: '.$relative);
        }
    }
}

$manifest = json_decode((string) $zip->getFromName('composer.json'), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['name'] ?? null) !== 'mykholy/laravel-flowguard' || $migrations < 1) {
    throw new RuntimeException('Invalid package identity or missing migrations.');
}
foreach ($manifest['autoload']['psr-4'] ?? [] as $directory) {
    $found = false;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $path = $zip->getNameIndex($index);
        if (is_string($path) && str_starts_with($path, $directory) && str_ends_with($path, '.php')) {
            $found = true;
        }
    }
    if ($found === false) {
        throw new RuntimeException('Autoload source is absent from the distribution.');
    }
}
$zip->close();
echo 'Composer distribution verified; '.$migrations." migration files included.\n";
