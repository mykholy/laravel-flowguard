<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class DiagnoseFlowguardTest extends TestCase
{
    public function test_complete_migrations_pass_diagnostic_checks(): void
    {
        $this->artisan('flowguard:doctor')
            ->expectsOutputToContain('Flowguard storage checks passed.')
            ->assertExitCode(0);
    }

    public function test_missing_inbox_unique_index_fails_closed(): void
    {
        Schema::table('flowguard_inbox', static function (Blueprint $table): void {
            $table->dropUnique('flowguard_inbox_scope_event_unique');
        });

        $this->artisan('flowguard:doctor')
            ->expectsOutputToContain('flowguard_inbox UNIQUE(scope, event_key_hash)')
            ->assertExitCode(1);
    }

    public function test_missing_inbox_migration_fails_closed(): void
    {
        Schema::dropIfExists('flowguard_inbox');

        $this->artisan('flowguard:doctor')
            ->expectsOutputToContain('Incomplete Flowguard migrations')
            ->assertExitCode(1);
    }
}
