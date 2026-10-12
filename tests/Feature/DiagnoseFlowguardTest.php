<?php

declare(strict_types=1);

namespace Mykholy\Flowguard\Tests\Feature;

use Illuminate\Support\Facades\Schema;

final class DiagnoseFlowguardTest extends TestCase
{
    public function test_complete_migrations_pass_diagnostic_checks(): void
    {
        $this->artisan('flowguard:doctor')
            ->expectsOutputToContain('Flowguard storage checks passed.')
            ->assertExitCode(0);
    }

    public function test_missing_inbox_migration_fails_closed(): void
    {
        Schema::dropIfExists('flowguard_inbox');

        $this->artisan('flowguard:doctor')
            ->expectsOutputToContain('Incomplete Flowguard migrations')
            ->assertExitCode(1);
    }
}
