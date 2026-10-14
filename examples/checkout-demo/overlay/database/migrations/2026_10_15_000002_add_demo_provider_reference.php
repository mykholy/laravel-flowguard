<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_payments', function (Blueprint $table): void {
            $table->text('encrypted_provider_reference')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('demo_payments', function (Blueprint $table): void {
            $table->dropColumn('encrypted_provider_reference');
        });
    }
};
