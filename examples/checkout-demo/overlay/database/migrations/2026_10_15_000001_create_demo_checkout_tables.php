<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_tenants', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->string('name');
        });
        Schema::create('demo_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('tenant_id');
            $table->unsignedInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 20)->default('pending');
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('demo_tenants')->restrictOnDelete();
        });
        Schema::create('demo_payments', function (Blueprint $table): void {
            $table->uuid('operation_id')->primary();
            $table->uuid('order_id')->unique();
            $table->string('status', 30);
            $table->timestamps();
            $table->foreign('operation_id')->references('id')->on('flowguard_operations')->restrictOnDelete();
            $table->foreign('order_id')->references('id')->on('demo_orders')->restrictOnDelete();
        });
        Schema::create('demo_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedInteger('tenant_id');
            $table->uuid('operation_id');
            $table->uuid('evidence_id');
            $table->string('initiator');
            $table->string('approver')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('demo_tenants')->restrictOnDelete();
            $table->foreign('operation_id')->references('id')->on('flowguard_operations')->restrictOnDelete();
            $table->foreign('evidence_id')->references('id')->on('flowguard_reconciliation_evidence')->restrictOnDelete();
        });
        DB::table('demo_tenants')->insert([['id' => 1, 'name' => 'Demo A'], ['id' => 2, 'name' => 'Demo B']]);
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_approvals');
        Schema::dropIfExists('demo_payments');
        Schema::dropIfExists('demo_orders');
        Schema::dropIfExists('demo_tenants');
    }
};
