<?php

declare(strict_types=1);

// Zero-dependency domain smoke test. NOT a substitute for PHPUnit + database CI.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Mykholy\\Flowguard\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) {
        require $path;
    }
});

use Mykholy\Flowguard\Contracts\OperationStore;
use Mykholy\Flowguard\Domain\Acquisition;
use Mykholy\Flowguard\Domain\Claim;
use Mykholy\Flowguard\Domain\OperationIdentity;
use Mykholy\Flowguard\Domain\OperationSnapshot;
use Mykholy\Flowguard\Domain\OperationStatus;
use Mykholy\Flowguard\Flowguard;

$assertions = 0;
function expect(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
}

$a = OperationIdentity::from('payments.capture', 'k', ['b' => 2, 'a' => ['z' => 3, 'x' => 1]]);
$b = OperationIdentity::from('payments.capture', 'k', ['a' => ['x' => 1, 'z' => 3], 'b' => 2]);
expect($a->payloadHash === $b->payloadHash, 'canonical payload hashing');
expect($a->keyHash === $b->keyHash, 'stable key hashing');
try {
    OperationIdentity::from('payments', 'key', ['object' => new stdClass]);
    throw new RuntimeException('Expected invalid object exception.');
} catch (InvalidArgumentException $expected) {
    $assertions++;
}
try {
    OperationIdentity::from('payments', 'key', ['too_large' => str_repeat('z', 70000)]);
    throw new RuntimeException('Expected payload limit exception.');
} catch (InvalidArgumentException $expected) {
    $assertions++;
}

$store = new class implements OperationStore
{
    public int $acquisitions = 0;

    public function acquire(OperationIdentity $identity): Acquisition
    {
        $this->acquisitions++;

        return new Acquisition(new OperationSnapshot('id', $identity->scope, OperationStatus::Processing, null, null), new Claim('id', 'secret'));
    }

    public function complete(Claim $claim, array $result): OperationSnapshot
    {
        return new OperationSnapshot($claim->operationId, 'payment', OperationStatus::Completed, $result, null);
    }

    public function requireReconciliation(Claim $claim, string $reason): OperationSnapshot
    {
        return new OperationSnapshot($claim->operationId, 'payment', OperationStatus::RequiresReconciliation, null, $reason);
    }
};
$guard = new Flowguard($store);
$acq = $guard->begin('payments.capture', 'k', ['amount' => 10]);
expect($acq->acquired(), 'claim is owned');
expect($store->acquisitions === 1, 'delegates acquisition');
expect($guard->complete($acq->claim(), ['reference' => 'x'])->status === OperationStatus::Completed, 'complete transition delegated');
expect($guard->requireReconciliation($acq->claim(), 'provider_timeout')->reconciliationReason === 'provider_timeout', 'reconciliation delegated');
try {
    $guard->requireReconciliation($acq->claim(), 'secret 123');
    throw new RuntimeException('Expected invalid reason exception.');
} catch (InvalidArgumentException $expected) {
    $assertions++;
}

echo "Domain smoke checks passed ({$assertions} assertions). Database/CI tests not run.\n";
