<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('mode', 20)->default('global');
            $table->string('title', 160)->nullable();
            $table->text('summary')->nullable();
            $table->longText('note_html')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->unsignedInteger('retry_after')->nullable();
            $table->json('allow_roles')->nullable();
            $table->json('allow_ips')->nullable();
            $table->json('allow_paths')->nullable();
            $table->json('deny_paths')->nullable();
            $table->json('allow_routes')->nullable();
            $table->json('deny_routes')->nullable();
            $table->boolean('allow_api')->default(false);
            $table->boolean('allow_developer_bypass')->default(false);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('updated_ip', 45)->nullable();
            $table->string('updated_user_agent', 255)->nullable();
            $table->timestamps();

            $table->index('enabled', 'maintenance_settings_enabled_idx');
            $table->index(['start_at', 'end_at'], 'maintenance_settings_window_idx');
            $table->index('updated_by', 'maintenance_settings_updated_by_idx');

            $table->foreign('updated_by', 'maintenance_settings_updated_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::create('maintenance_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('token_hash');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['revoked_at', 'expires_at']);
            $table->index('created_by', 'maintenance_tokens_created_by_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_tokens');
        Schema::dropIfExists('maintenance_settings');
    }
};
