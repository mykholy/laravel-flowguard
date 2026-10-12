<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Mykholy\Flowguard\Infrastructure\UtcDatabaseClock;
use Throwable;

/** Read-only inspection for exhausted deliveries and expired worker leases. */
final class InspectOutbox extends Command
{
    protected $signature = 'flowguard:outbox {--limit=50 : Maximum rows (1-100)} {--max-attempts=10 : Delivery limit used by your lease store}';

    protected $description = 'List stuck outbox messages without retrying, modifying or exposing payloads';

    public function handle(DatabaseManager $database): int
    {
        $limit = $this->option('limit');
        $maximum = $this->option('max-attempts');

        if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100 ||
            ! is_string($maximum) || ! ctype_digit($maximum) || (int) $maximum < 1 || (int) $maximum > 1000) {
            $this->error('Limit must be 1-100, max-attempts must be 1-1000.');

            return self::FAILURE;
        }

        try {
            /** @var string|null $connectionName */
            $connectionName = config('flowguard.connection');
            $connection = $database->connection($connectionName);
            $now = UtcDatabaseClock::now($connection)->format('Y-m-d H:i:s.u');

            $records = $connection->table('flowguard_outbox')
                ->select(['id', 'scope', 'status', 'delivery_attempts', 'lease_expires_at', 'last_failure_code'])
                ->where('status', '<>', 'delivered')
                ->where(function (Builder $query) use ($now, $maximum): void {
                    $query->where('delivery_attempts', '>=', (int) $maximum)
                        ->orWhere(function (Builder $expired) use ($now): void {
                            $expired->where('status', 'leased')
                                ->where('lease_expires_at', '<=', $now);
                        });
                })
                ->orderBy('created_at')
                ->limit((int) $limit)
                ->get();

            $rows = $records->map(static function ($record) use ($maximum): array {
                $problem = (int) $record->delivery_attempts >= (int) $maximum
                    ? 'attempts_exhausted'
                    : 'lease_expired';

                return [
                    (string) $record->id,
                    (string) $record->scope,
                    $problem,
                    (int) $record->delivery_attempts,
                    (string) ($record->last_failure_code ?? ''),
                ];
            })->all();

            $this->table(['Message ID', 'Scope', 'Problem', 'Attempts', 'Failure code'], $rows);
            $this->warn('Inspection only. Verify delivery outcome before replay or reset.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Outbox inspection failed: '.$exception::class);

            return self::FAILURE;
        }
    }
}
