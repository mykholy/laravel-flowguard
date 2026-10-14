<?php

declare(strict_types=1);

use App\Demo\Checkout;
use App\Demo\StripeWebhook;
use App\Http\Middleware\DemoIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mykholy\Flowguard\Inbox\DatabaseInboxRecorder;

Route::post('/demo/stripe/webhook', StripeWebhook::class);

Route::middleware(DemoIdentity::class)->prefix('demo')->group(function (): void {
    Route::post('/orders', function (Request $request, Checkout $checkout) {
        abort_unless($request->attributes->get('demo_role') === 'merchant', 403);
        $data = $request->validate(['amount' => ['required', 'integer', 'min:1', 'max:1000000'], 'currency' => ['required', 'in:USD,EUR']]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && $key !== '' && strlen($key) <= 512, 422);
        $result = $checkout->create($request->attributes->get('demo_tenant'), $key, (int) $data['amount'], $data['currency']);

        return response()->json($result, $result['duplicate'] ? 200 : 201);
    });
    Route::get('/orders/{id}', function (Request $request, Checkout $checkout, string $id) {
        return response()->json($checkout->order($request->attributes->get('demo_tenant'), $id));
    });
    Route::post('/orders/{id}/charge', function (Request $request, Checkout $checkout, string $id) {
        abort_unless($request->attributes->get('demo_role') === 'merchant', 403);
        $result = $checkout->charge($request->attributes->get('demo_tenant'), $id);

        return response()->json($result, $result['status'] === 'requires_reconciliation' ? 202 : 200);
    });
    Route::post('/operations/{id}/evidence', function (Request $request, Checkout $checkout, string $id) {
        abort_unless($request->attributes->get('demo_role') === 'reviewer', 403);

        return response()->json(['id' => $checkout->evidence($request->attributes->get('demo_tenant'), $id, $request->attributes->get('demo_actor'))], 201);
    });
    Route::post('/operations/{id}/approvals', function (Request $request, Checkout $checkout, string $id) {
        abort_unless($request->attributes->get('demo_role') === 'reviewer', 403);
        $data = $request->validate(['evidence_id' => ['required', 'uuid']]);

        return response()->json(['id' => $checkout->requestApproval($request->attributes->get('demo_tenant'), $id, $data['evidence_id'], $request->attributes->get('demo_actor'))], 201);
    });
    Route::post('/approvals/{id}/approve', function (Request $request, Checkout $checkout, string $id) {
        abort_unless($request->attributes->get('demo_role') === 'reviewer', 403);
        $checkout->approve($request->attributes->get('demo_tenant'), $id, $request->attributes->get('demo_actor'));

        return response()->json(['resolved' => true]);
    });
});

Route::post('/demo/webhooks/{tenant}', function (Request $request, string $tenant) {
    abort_unless(in_array($tenant, ['1', '2'], true), 404);
    $body = $request->getContent();
    abort_if(strlen($body) > 65536, 413);
    $timestamp = $request->header('X-Demo-Timestamp', '');
    $signature = $request->header('X-Demo-Signature', '');
    $secret = config('demo.webhook_secret');
    abort_unless(is_string($secret) && $secret !== '' && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 403);
    $expected = hash_hmac('sha256', $tenant.'.'.$timestamp.'.'.$body, $secret);
    abort_unless(hash_equals($expected, $signature), 403);
    $data = $request->validate(['event_id' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9._:-]+$/D'], 'order_id' => ['required', 'uuid'], 'amount' => ['required', 'integer'], 'currency' => ['required', 'in:USD,EUR']]);
    $order = DB::table('demo_orders')->where('id', $data['order_id'])->where('tenant_id', (int) $tenant)->firstOrFail();
    abort_unless((int) $order->amount === (int) $data['amount'] && $order->currency === $data['currency'], 409);
    $receipt = (new DatabaseInboxRecorder(DB::connection()))->receive('tenant.'.$tenant.':orders', $data['event_id'], $data, 'payment.received', $order->id);

    return response()->json(['id' => $receipt->id, 'duplicate' => $receipt->duplicate]);
});
