<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('url', 2048);
            $table->string('method', 8)->default('GET');
            $table->unsignedSmallInteger('expected_status_code')->default(200);
            $table->unsignedInteger('interval_seconds')->default(60);
            $table->unsignedTinyInteger('timeout_seconds')->default(5);
            $table->unsignedTinyInteger('failure_threshold')->default(3);
            $table->unsignedTinyInteger('recovery_threshold')->default(2);
            $table->string('status', 16)->default('unknown');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedInteger('consecutive_successes')->default(0);
            $table->unsignedInteger('configuration_version')->default(1);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'next_check_at']);
            $table->index(['user_id', 'id']);
        });
        Schema::create('monitor_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->uuid('execution_id')->unique();
            $table->string('status', 16);
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->unsignedInteger('response_time_ms');
            $table->string('error_type', 32)->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('checked_at');
            $table->timestamp('created_at');
            $table->index(['monitor_id', 'id']);
        });
        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('open');
            $table->string('severity', 16)->default('critical');
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('failure_count');
            $table->unsignedInteger('recovery_count')->default(0);
            // NULL allows multiple resolved incidents; only one open slot can exist.
            $table->unsignedTinyInteger('open_slot')->nullable()->virtualAs("CASE WHEN status = 'open' THEN 1 ELSE NULL END");
            $table->unique(['monitor_id', 'open_slot']);
            $table->index(['monitor_id', 'id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('monitor_checks');
        Schema::dropIfExists('monitors');
    }
};
