<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Mykholy\Flowguard\Contracts\OutboxPublisher;
use Mykholy\Flowguard\Outbox\DatabaseOutboxLeaseStore;
use Mykholy\Flowguard\Outbox\OutboxDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/** One bounded delivery attempt; never starts a daemon or a remote integration. */
final class DispatchOutboxOnce extends Command
{
    protected $signature = 'flowguard:dispatch-once {--lease=60 : Reservation lifetime in seconds} {--retry=60 : Retry delay in seconds} {--max-attempts=10 : Maximum reservations per message} {--scope= : Limit delivery to this server-authorized tenant scope}';

    protected $description = 'Publish at most one pending outbox message using an application-bound publisher';

    public function handle(DatabaseManager $database, LoggerInterface $logger): int
    {
        $lease = $this->option('lease');
        $retry = $this->option('retry');
        $maximum = $this->option('max-attempts');
        $scope = $this->option('scope');

        if (! is_string($lease) || ! ctype_digit($lease) || (int) $lease < 5 || (int) $lease > 3600
            || ! is_string($retry) || ! ctype_digit($retry) || (int) $retry > 86400
            || ! is_string($maximum) || ! ctype_digit($maximum) || (int) $maximum < 1 || (int) $maximum > 1000) {
            $this->error('Lease must be 5-3600, retry 0-86400, and max-attempts 1-1000.');

            return self::FAILURE;
        }

        if ($scope !== null && (! is_string($scope)
            || preg_match('/^[a-z0-9][a-z0-9:._-]{0,119}$/D', $scope) !== 1)) {
            $this->error('Scope must be a bounded tenant-aware identifier.');

            return self::FAILURE;
        }

        if (! app()->bound(OutboxPublisher::class)) {
            $this->error('Bind OutboxPublisher in your application service provider before dispatching.');

            return self::FAILURE;
        }

        try {
            /** @var string|null $name */
            $name = config('flowguard.connection');

            $dispatcher = new OutboxDispatcher(
                new DatabaseOutboxLeaseStore($database->connection($name), maxAttempts: (int) $maximum, scope: $scope),
                app(OutboxPublisher::class),
                $logger,
                leaseSeconds: (int) $lease,
                retryAfterSeconds: (int) $retry,
            );

            if (! $dispatcher->dispatchOne()) {
                $this->line('No eligible outbox message.');

                return self::SUCCESS;
            }

            $this->info('One outbox message delivered.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Do not expose provider exception messages or credential-bearing payloads.
            $this->error('Outbox delivery failed: '.$exception::class);

            return self::FAILURE;
        }
    }
}
