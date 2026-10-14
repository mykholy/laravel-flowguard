<?php

declare(strict_types=1);

namespace App\Demo;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mykholy\Flowguard\Exceptions\IdempotencyConflict;
use Mykholy\Flowguard\Inbox\DatabaseInboxRecorder;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

/** Host-owned sandbox example: authenticates and records; never settles or charges. */
final class StripeWebhook
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 403);
        $secret = config('demo.stripe_webhook_secret');
        abort_unless(is_string($secret) && str_starts_with($secret, 'whsec_') && strlen($secret) >= 16, 503);
        $body = $request->getContent();
        abort_if(strlen($body) > 65536, 413);
        $signature = $request->header('Stripe-Signature', '');
        abort_if(strlen($signature) > 4096, 403);
        try {
            $event = Webhook::constructEvent($body, $signature, $secret, 300)->toArray();
        } catch (SignatureVerificationException|UnexpectedValueException) {
            abort(403);
        }
        // This demo is one test account mapped to tenant 1 by server configuration.
        // Connected-account events cannot select a tenant through metadata or a URL.
        abort_unless(($event['object'] ?? null) === 'event' && ($event['livemode'] ?? null) === false
            && ! isset($event['account']) && ! isset($event['context'])
            && is_string($event['id'] ?? null) && preg_match('/^evt_[a-zA-Z0-9]{1,120}$/D', $event['id']) === 1, 422);
        $type = $event['type'] ?? null;
        abort_unless(in_array($type, ['payment_intent.succeeded', 'payment_intent.payment_failed'], true), 422);
        $intent = $event['data']['object'] ?? [];
        abort_unless(is_array($intent), 422);
        $metadata = $intent['metadata'] ?? [];
        abort_unless(is_array($metadata), 422);
        abort_unless(($intent['object'] ?? null) === 'payment_intent' && ($intent['livemode'] ?? null) === false
            && is_string($intent['id'] ?? null) && preg_match('/^pi_[a-zA-Z0-9]{1,120}$/D', $intent['id']) === 1
            && ($metadata['demo_tenant'] ?? null) === '1'
            && is_string($metadata['flowguard_operation'] ?? null) && Str::isUuid($metadata['flowguard_operation'])
            && is_string($metadata['demo_order'] ?? null) && Str::isUuid($metadata['demo_order']), 422);
        $payment = DB::table('demo_payments')->where('operation_id', $metadata['flowguard_operation'])
            ->where('order_id', $metadata['demo_order'])->firstOrFail();
        $order = DB::table('demo_orders')->where('id', $payment->order_id)->where('tenant_id', 1)->firstOrFail();
        $operation = DB::table('flowguard_operations')->where('id', $payment->operation_id)
            ->where('scope', 'tenant.1:payments')->firstOrFail();
        abort_unless(($intent['amount'] ?? null) === (int) $order->amount
            && ($intent['currency'] ?? null) === strtolower($order->currency)
            && ($intent['status'] ?? null) === ($type === 'payment_intent.succeeded' ? 'succeeded' : 'requires_payment_method'), 409);
        try {
            $reference = Crypt::decryptString($payment->encrypted_provider_reference ?? '');
        } catch (Throwable) {
            abort(409);
        }
        abort_unless(hash_equals($reference, $intent['id']), 409);
        // Delivery bookkeeping can change while the immutable event data stays the same.
        // Authenticate the full raw body above, but exclude this counter from identity.
        unset($event['pending_webhooks']);
        try {
            $receipt = (new DatabaseInboxRecorder(DB::connection()))->receive(
                'tenant.1:stripe', $event['id'], $event, 'stripe.'.$type, $operation->id);
        } catch (IdempotencyConflict) {
            abort(409);
        }

        return response()->json(['id' => $receipt->id, 'duplicate' => $receipt->duplicate]);
    }
}
