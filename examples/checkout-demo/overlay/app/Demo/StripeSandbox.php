<?php

declare(strict_types=1);

namespace App\Demo;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Opt-in pilot transport; never selected by the interactive synthetic checkout. */
final class StripeSandbox
{
    public const API_VERSION = '2026-09-30.endive';

    public function assertConfigured(): void
    {
        $key = config('demo.stripe_test_key');
        if (! is_string($key) || ! str_starts_with($key, 'sk_test_') || strlen($key) < 16) {
            throw new RuntimeException('stripe_test_credentials_required');
        }
    }

    public function create(string $operation, string $order): string
    {
        $intent = $this->request('POST', '/payment_intents', [
            'amount' => 500, 'currency' => 'usd', 'capture_method' => 'automatic',
            'allowed_payment_method_types' => ['card'], 'confirm' => 'false',
            'metadata' => ['flowguard_operation' => $operation, 'demo_order' => $order, 'demo_tenant' => '1'],
        ], 'flowguard:'.$operation.':prepare');
        $this->assertMatches($intent, $operation, $order);
        if (! in_array($intent['status'] ?? null, ['requires_payment_method', 'requires_confirmation'], true)) {
            throw new RuntimeException('stripe_intent_not_unconfirmed');
        }

        return $intent['id'];
    }

    public function confirm(string $intent, string $operation): void
    {
        $this->assertIntentId($intent);
        // Fixed test PaymentMethod: no card numbers, customers, or arbitrary payment methods.
        $this->request('POST', '/payment_intents/'.$intent.'/confirm', ['payment_method' => 'pm_card_visa'], 'flowguard:'.$operation.':confirm');
    }

    public function observe(string $intent, string $operation, string $order): array
    {
        $this->assertIntentId($intent);
        $data = $this->request('GET', '/payment_intents/'.$intent);
        $this->assertMatches($data, $operation, $order);
        if ($data['id'] !== $intent) {
            throw new RuntimeException('stripe_observation_identity_mismatch');
        }

        return $data;
    }

    private function request(string $method, string $path, array $payload = [], ?string $key = null): array
    {
        $this->assertConfigured();
        try {
            $request = Http::asForm()->withBasicAuth(config('demo.stripe_test_key'), '')
                ->withHeaders(['Stripe-Version' => self::API_VERSION])
                ->connectTimeout(10)->timeout(30)->withoutRedirecting();
            if ($key !== null) {
                $request = $request->withHeaders(['Idempotency-Key' => $key]);
            }
            $response = $method === 'GET'
                ? $request->get('https://api.stripe.com/v1'.$path)
                : $request->post('https://api.stripe.com/v1'.$path, $payload);
        } catch (Throwable) {
            // Never attach the credential-bearing request/response as a previous exception.
            throw new RuntimeException('stripe_transport_failed');
        }
        if (! $response->successful()) {
            throw new RuntimeException('stripe_http_'.$response->status());
        }
        $data = $response->json();
        if (! is_array($data) || ($data['object'] ?? null) !== 'payment_intent' || ($data['livemode'] ?? null) !== false) {
            throw new RuntimeException('stripe_test_intent_required');
        }
        $this->assertIntentId($data['id'] ?? '');

        // Deliberately discard client_secret, card details, and all other response fields.
        return array_intersect_key($data, array_flip(['id', 'object', 'livemode', 'status', 'amount', 'currency', 'metadata', 'latest_charge']));
    }

    private function assertMatches(array $intent, string $operation, string $order): void
    {
        if (($intent['amount'] ?? null) !== 500 || ($intent['currency'] ?? null) !== 'usd'
            || ($intent['metadata']['flowguard_operation'] ?? null) !== $operation
            || ($intent['metadata']['demo_order'] ?? null) !== $order
            || ($intent['metadata']['demo_tenant'] ?? null) !== '1') {
            throw new RuntimeException('stripe_observation_binding_mismatch');
        }
    }

    private function assertIntentId(string $id): void
    {
        if (preg_match('/^pi_[a-zA-Z0-9]{1,120}$/D', $id) !== 1) {
            throw new RuntimeException('stripe_intent_identifier_invalid');
        }
    }
}
