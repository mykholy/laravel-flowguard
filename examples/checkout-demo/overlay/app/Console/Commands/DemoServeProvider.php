<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\Process;

final class DemoServeProvider extends Command
{
    protected $signature = 'demo:provider';

    protected $description = 'Run the independent loopback-only synthetic provider';

    public function handle(): int
    {
        $url = config('demo.provider_url');
        if (! app()->environment(['local', 'testing']) || ! is_string($url)
            || preg_match('~^http://127\.0\.0\.1:[0-9]{1,5}$~D', $url) !== 1) {
            throw new RuntimeException('The synthetic provider must bind a loopback HTTP port.');
        }
        $process = new Process([PHP_BINARY, '-S', substr($url, 7), base_path('demo-sandbox/router.php')], base_path(), [
            'DEMO_PROVIDER_KEY' => config('demo.provider_key'),
            'DEMO_PROVIDER_STATE' => storage_path('app/demo-provider.sqlite'),
        ]);
        $process->setTimeout(null);

        return $process->run(fn (string $type, string $output) => $this->output->write($output));
    }
}
