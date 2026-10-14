<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Demo\Checkout;
use App\Demo\ReviewPolicy;
use App\Demo\StripeSandbox;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Flowguard;
use Mykholy\Flowguard\Infrastructure\DatabaseEvidenceJournal;
use Mykholy\Flowguard\Reconciliation\ReconciliationEvidence;
use Mykholy\Flowguard\Reconciliation\ReconciliationOutcome;
use RuntimeException;
use Throwable;

final class DemoStripePilot extends Command
{
    protected $signature = 'demo:stripe-pilot {--resume= : Existing unknown operation; GET-only recovery} {--report= : Sanitized report path}';

    protected $description = 'Exercise one fixed $5 Stripe test payment with deliberate application acknowledgement loss';

    public function handle(Checkout $checkout, Flowguard $guard, StripeSandbox $stripe, ReviewPolicy $policy): int
    {
        $report = ['provider' => 'stripe', 'mode' => 'sandbox', 'status' => 'blocked', 'external_provider_verified' => false,
            'fault' => 'application_acknowledgement_discarded', 'full_provider_pilot_complete' => false];
        try {
            $database = DB::connection()->getDatabaseName();
            if (! app()->environment('testing') || config('demo.stripe_pilot_confirm') !== 'sandbox-only'
                || (! in_array($database, ['flowguard_demo_ci', 'flowguard_demo_restore_ci'], true)
                    && ! in_array(basename($database), ['flowguard-stripe-pilot.sqlite', 'flowguard-demo-test.sqlite'], true))) {
                throw new RuntimeException('stripe_pilot_disposable_host_required');
            }
            $stripe->assertConfigured();
            $resume = $this->option('resume');
            if (is_string($resume) && $resume !== '') {
                if (! Str::isUuid($resume)) {
                    throw new RuntimeException('stripe_pilot_resume_identifier_invalid');
                }
                $payment = DB::table('demo_payments')->where('operation_id', $resume)->firstOrFail();
                $operation = DB::table('flowguard_operations')->where('id', $resume)->firstOrFail();
                if ($operation->scope !== 'tenant.1:payments' || $operation->status !== OperationStatus::RequiresReconciliation->value
                    || ! is_string($payment->encrypted_provider_reference)) {
                    throw new RuntimeException('stripe_pilot_resume_not_unknown');
                }
                $id = $payment->order_id;
                $operationId = $resume;
                $intent = Crypt::decryptString($payment->encrypted_provider_reference);
                $checkout->order(1, $id);
            } else {
                $id = $checkout->create(1, 'stripe-pilot:'.Str::uuid(), 500, 'USD')['id'];
                $acquisition = DB::transaction(function () use ($guard, $id) {
                    $acquisition = $guard->begin('tenant.1:payments', $id, ['order' => $id, 'amount' => 500, 'currency' => 'USD']);
                    DB::table('demo_payments')->insert(['operation_id' => $acquisition->operation->id, 'order_id' => $id,
                        'status' => 'processing', 'created_at' => now(), 'updated_at' => now()]);

                    return $acquisition;
                });
                $operationId = $acquisition->operation->id;
                $report['operation_id'] = $operationId;
                try {
                    // Creation is unconfirmed: persist its encrypted reference BEFORE confirmation.
                    $intent = $stripe->create($operationId, $id);
                    DB::table('demo_payments')->where('operation_id', $operationId)->update([
                        'encrypted_provider_reference' => Crypt::encryptString($intent), 'updated_at' => now()]);
                    $stripe->confirm($intent, $operationId);
                    // Deliberately discard the successful application acknowledgement.
                    // This is NOT claimed to be a network timeout or a worker kill.
                } finally {
                    DB::transaction(function () use ($guard, $acquisition, $operationId): void {
                        $guard->requireReconciliation($acquisition->claim(), 'stripe_pilot_outcome_unknown');
                        DB::table('demo_payments')->where('operation_id', $operationId)->update(['status' => 'unknown', 'updated_at' => now()]);
                    });
                }
            }
            $report['operation_id'] = $operationId;
            $duplicate = $guard->begin('tenant.1:payments', $id, ['order' => $id, 'amount' => 500, 'currency' => 'USD']);
            if ($duplicate->acquired() || $duplicate->operation->status !== OperationStatus::RequiresReconciliation) {
                throw new RuntimeException('stripe_pilot_duplicate_not_fenced');
            }
            // Recovery is strictly read-only at the provider boundary, including --resume.
            $observation = $stripe->observe($intent, $operationId, $id);
            if (($observation['status'] ?? null) !== 'succeeded' || ! is_string($observation['latest_charge'] ?? null)
                || preg_match('/^ch_[a-zA-Z0-9]+$/D', $observation['latest_charge']) !== 1) {
                throw new RuntimeException('stripe_pilot_success_not_observed');
            }
            $entry = (new DatabaseEvidenceJournal(DB::connection(), $policy))->append($operationId,
                new ReconciliationEvidence(ReconciliationOutcome::ConfirmedSuccess, 'stripe.sandbox', 'payment_intent.succeeded', new DateTimeImmutable, $intent), 'reviewer-a');
            $approval = $checkout->requestApproval(1, $operationId, $entry->id, 'reviewer-a');
            $checkout->approve(1, $approval, 'reviewer-b');
            $checkout->charge(1, $id); // Completed duplicate exits before any publisher/provider call.
            $after = $stripe->observe($intent, $operationId, $id);
            if (($after['status'] ?? null) !== 'succeeded' || ($after['latest_charge'] ?? null) !== $observation['latest_charge'] || $checkout->order(1, $id)->status !== 'paid') {
                throw new RuntimeException('stripe_pilot_effect_changed');
            }
            $report = array_merge($report, ['status' => 'passed', 'external_provider_verified' => true,
                'live_mode' => false, 'duplicate_claim_rejected' => true, 'read_only_recovery' => true,
                'charge_identity_unchanged' => true, 'independent_principals_approved' => true]);
        } catch (Throwable $exception) {
            $report['status'] = 'blocked_or_failed';
            $code = $exception->getMessage();
            $report['reason'] = preg_match('/^stripe_[a-z0-9_]+$/D', $code) === 1 ? $code : 'stripe_pilot_validation_failed';
        }
        $path = $this->option('report');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
            chmod($path, 0600);
        }
        $this->line('Stripe sandbox pilot: '.$report['status'].(isset($report['reason']) ? ' ('.$report['reason'].')' : ''));

        return $report['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
    }
}
