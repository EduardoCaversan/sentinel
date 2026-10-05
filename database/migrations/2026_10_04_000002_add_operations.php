<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->timestamp('first_failed_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acknowledged_by_key')->nullable()->constrained('organization_api_keys')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->index(['monitor_id', 'started_at']);
        });
        Schema::create('incident_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained('organization_api_keys')->nullOnDelete();
            $table->string('type', 32);
            $table->text('message')->nullable();
            $table->timestamp('occurred_at');
            $table->index(['incident_id', 'occurred_at', 'id']);
        });
        Schema::table('monitor_checks', function (Blueprint $table): void {
            $table->boolean('in_maintenance')->default(false);
            $table->index(['monitor_id', 'checked_at']);
            $table->index('checked_at');
        });
        Schema::create('check_executions', function (Blueprint $table): void {
            $table->uuid('execution_id')->primary();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->index();
        });
        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('description', 1000);
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->timestamps();
            $table->index(['organization_id', 'end_at', 'start_at']);
        });
        Schema::create('maintenance_monitor', function (Blueprint $table): void {
            $table->foreignId('maintenance_window_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->primary(['maintenance_window_id', 'monitor_id']);
            $table->index(['monitor_id', 'maintenance_window_id']);
        });
        Schema::create('notification_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 16);
            $table->text('endpoint');
            $table->text('signing_secret')->nullable();
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('notification_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('event', 32);
            $table->uuid('execution_id');
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_channel_id', 'execution_id', 'event'], 'delivery_event_unique');
            $table->index(['status', 'next_attempt_at']);
            $table->index(['organization_id', 'id']);
        });
        Schema::create('notification_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_delivery_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('attempt');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_type', 32)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('attempted_at');
            $table->unique(['notification_delivery_id', 'attempt']);
        });
        Schema::create('status_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });
        Schema::create('status_page_monitor', function (Blueprint $table): void {
            $table->foreignId('status_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('position');
            $table->primary(['status_page_id', 'monitor_id']);
        });
    }

    public function down(): void
    {
        foreach (['status_page_monitor', 'status_pages', 'notification_attempts', 'notification_deliveries', 'notification_channels', 'maintenance_monitor', 'maintenance_windows', 'check_executions', 'incident_events'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('monitor_checks', function (Blueprint $table): void {
            $table->dropIndex(['monitor_id', 'checked_at']);
            $table->dropIndex(['checked_at']);
            $table->dropColumn('in_maintenance');
        });
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropConstrainedForeignId('acknowledged_by_key');
            $table->dropColumn('acknowledged_at');
            $table->dropColumn('first_failed_at');
            $table->dropIndex(['monitor_id', 'started_at']);
        });
    }
};
