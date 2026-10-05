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
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('personal_user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('retention_days')->default(30);
            $table->timestamps();
        });
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12);
            $table->timestamps();
            $table->primary(['organization_id', 'user_id']);
            $table->index(['user_id', 'organization_id']);
        });
        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 12);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['organization_id', 'email']);
        });
        Schema::create('organization_api_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->char('secret_hash', 64)->unique();
            $table->json('scopes');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::table('monitors', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('slo_target', 6, 3)->default(99.9);
            $table->index(['organization_id', 'id']);
            $table->dropForeign(['user_id']);
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
        // Data migration preserves monitor/check/incident IDs and ownership.
        DB::table('users')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $organization = DB::table('organizations')->insertGetId(['name' => mb_substr($user->name.' Personal', 0, 120), 'owner_id' => $user->id, 'personal_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('organization_user')->insert(['organization_id' => $organization, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('monitors')->where('user_id', $user->id)->update(['organization_id' => $organization]);
            }
        });
        Schema::table('monitors', fn (Blueprint $table) => $table->unsignedBigInteger('organization_id')->nullable(false)->change());
    }

    public function down(): void
    {
        // Removing tenancy after shared monitors exist would silently lose ownership.
        throw new RuntimeException('Restore a pre-V2 backup to downgrade tenancy safely.');
    }
};
