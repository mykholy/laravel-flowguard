<?php

declare(strict_types=1);

namespace App\Demo;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ProviderClient
{
    private function url(): string
    {
        $url = config('demo.provider_url');
        if (! is_string($url) || preg_match('~^http://127\.0\.0\.1:[0-9]{1,5}$~D', $url) !== 1 || config('demo.provider_key') === '') {
            throw new RuntimeException('The demo supports an authenticated loopback sandbox only.');
        }

        return $url;
    }

    public function accept(string $id, int $tenant, string $topic, string $subject, int $amount, string $currency): void
    {
        $result = Http::withToken(config('demo.provider_key'))->timeout(1)
            ->withHeaders(['X-Demo-Delay-Ack' => config('demo.delay_ack') ? '1' : '0'])
            ->post($this->url().'/events', compact('id', 'tenant', 'topic', 'subject', 'amount', 'currency'))
            ->throw()->json();
        if (($result['accepted'] ?? null) !== true || ($result['id'] ?? null) !== $id) {
            throw new RuntimeException('Sandbox acknowledgement did not match the request.');
        }
    }

    public function receipt(string $id): ?array
    {
        $response = Http::withToken(config('demo.provider_key'))->timeout(5)
            ->get($this->url().'/receipts/'.rawurlencode($id));
        if ($response->status() === 404) {
            return null;
        }

        return $response->throw()->json();
    }
}
