<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->json('data')->nullable();
            $table->longText('secrets')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('updated_ip', 45)->nullable();
            $table->string('updated_user_agent', 255)->nullable();
            $table->timestamps();

            $table->index('updated_by', 'system_settings_updated_by_idx');
        });

        Schema::create('system_setting_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('system_setting_id');
            $table->string('action', 50)->default('updated');
            $table->json('snapshot')->nullable();
            $table->json('changed_keys')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('request_id', 36)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('system_setting_id', 'system_setting_versions_setting_idx');
            $table->index('actor_id', 'system_setting_versions_actor_idx');
            $table->index(['action', 'created_at'], 'system_setting_versions_action_created_idx');

            $table->foreign('system_setting_id', 'system_setting_versions_setting_fk')
                ->references('id')
                ->on('system_settings')
                ->cascadeOnDelete();
            $table->foreign('actor_id', 'system_setting_versions_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_setting_versions');
        Schema::dropIfExists('system_settings');
    }
};
