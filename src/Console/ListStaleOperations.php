<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Mykholy\Flowguard\Domain\OperationStatus;

/** Read-only operator inspection. Never infers safety of replay from age alone. */
final class ListStaleOperations extends Command
{
    protected $signature = 'flowguard:stale {--minutes=15 : Minimum age of a processing claim} {--limit=50 : Maximum rows (up to 100)}';

    protected $description = 'List stale processing operations requiring manual investigation; never retries them';

    public function handle(DatabaseManager $database): int
    {
        $minutesOption = $this->option('minutes');
        $limitOption = $this->option('limit');

        if (! is_string($minutesOption) || ! is_string($limitOption) ||
            ctype_digit($minutesOption) === false || (int) $minutesOption < 1 || (int) $minutesOption > 10080 ||
            ctype_digit($limitOption) === false || (int) $limitOption < 1 || (int) $limitOption > 100) {
            $this->error('Minutes must be 1-10080; limit must be 1-100.');

            return self::FAILURE;
        }

        /** @var string|null $connection */
        $connection = config('flowguard.connection');
        /** @var string $table */
        $table = config('flowguard.table', 'flowguard_operations');
        $rows = $database->connection($connection)->table($table)
            ->select(['id', 'scope', 'status', 'created_at'])
            ->where('status', OperationStatus::Processing->value)
            ->where('created_at', '<=', now()->subMinutes((int) $minutesOption))
            ->orderBy('created_at')
            ->limit((int) $limitOption)
            ->get()
            ->map(fn ($row): array => [(string) $row->id, (string) $row->scope, (string) $row->status, (string) $row->created_at])
            ->all();

        $this->table(['ID', 'Scope', 'Status', 'Created at'], $rows);
        $this->warn('Do not blindly replay these operations. Reconcile with the external provider first.');

        return self::SUCCESS;
    }
}
