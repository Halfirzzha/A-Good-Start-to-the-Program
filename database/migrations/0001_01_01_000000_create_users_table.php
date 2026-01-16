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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('username', 50)->unique()->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('avatar')->nullable();
            $table->string('position', 100)->nullable();
            $table->string('role', 50)->default('user');
            $table->string('phone_country_code', 5)->nullable()->default('+62');
            $table->string('phone_number', 20)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('locale', 10)->nullable();
            $table->string('security_stamp', 64)->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            $table->unsignedBigInteger('password_changed_by')->nullable();
            $table->timestamp('password_expires_at')->nullable();
            $table->string('last_password_changed_ip', 45)->nullable();
            $table->string('last_password_changed_user_agent', 255)->nullable();
            $table->timestamp('first_login_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->string('last_login_user_agent', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_ip', 45)->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('last_failed_login_at')->nullable();
            $table->string('last_failed_login_ip', 45)->nullable();
            $table->string('last_failed_login_user_agent', 255)->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('blocked_until')->nullable();
            $table->enum('account_status', ['active', 'blocked', 'suspended', 'terminated'])->default('active');
            $table->text('blocked_reason')->nullable();
            $table->unsignedBigInteger('blocked_by')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->string('two_factor_method', 20)->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->enum('created_by_type', ['system', 'admin'])->default('system');
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->string('deleted_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('username', 'users_username_index');
            $table->index('phone_number', 'users_phone_number_index');
            $table->index('role', 'users_role_index');
            $table->index('account_status', 'users_account_status_index');
            $table->index('last_login_at', 'users_last_login_at_index');
            $table->index('locked_at', 'users_locked_at_index');
            $table->index('blocked_until', 'users_blocked_until_index');
            $table->index(['email', 'account_status'], 'users_email_account_status_index');
            $table->index('deleted_at', 'users_deleted_at_index');

            $table->foreign('password_changed_by', 'users_password_changed_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('blocked_by', 'users_blocked_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by_admin_id', 'users_created_by_admin_id_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by', 'users_deleted_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
            $table->index('created_at', 'password_reset_tokens_created_at_index');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
            $table->foreign('user_id', 'sessions_user_id_foreign')
                ->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::create('user_login_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('identity', 191)->nullable();
            $table->string('event', 50);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('session_id', 100)->nullable();
            $table->string('request_id', 36)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event', 'created_at'], 'user_login_activities_event_created_at_index');
            $table->index('ip_address', 'user_login_activities_ip_address_index');
            $table->foreign('user_id', 'user_login_activities_user_id_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 191)->nullable();
            $table->string('user_email', 191)->nullable();
            $table->string('user_username', 100)->nullable();
            $table->string('role_name', 100)->nullable();
            $table->string('action', 100);
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('auditable_label', 191)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->text('url')->nullable();
            $table->string('route', 255)->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('request_id', 36)->nullable();
            $table->string('session_id', 100)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('request_referer')->nullable();
            $table->string('request_payload_hash', 64)->nullable();
            $table->json('context')->nullable();
            $table->string('hash', 64)->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('signature', 128)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at'], 'audit_logs_user_created_idx');
            $table->index('user_email', 'audit_logs_user_email_idx');
            $table->index('user_username', 'audit_logs_user_username_idx');
            $table->index(['role_name', 'created_at'], 'audit_logs_role_created_idx');
            $table->index(['action', 'created_at'], 'audit_logs_action_created_idx');
            $table->index(['auditable_type', 'auditable_id'], 'audit_logs_auditable_idx');
            $table->index('auditable_label', 'audit_logs_auditable_label_idx');
            $table->index('request_id', 'audit_logs_request_id_idx');
            $table->index('session_id', 'audit_logs_session_id_idx');
            $table->index('status_code', 'audit_logs_status_code_idx');
            $table->index('method', 'audit_logs_method_idx');
            $table->index('ip_address', 'audit_logs_ip_address_idx');
            $table->index('user_agent_hash', 'audit_logs_user_agent_hash_idx');
            $table->index('request_payload_hash', 'audit_logs_request_payload_hash_idx');
            $table->index('hash', 'audit_logs_hash_index');
            $table->index('previous_hash', 'audit_logs_previous_hash_index');
            $table->index('signature', 'audit_logs_signature_index');

            $table->foreign('user_id', 'audit_logs_user_id_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('user_login_activities');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_password_changed_by_foreign');
            $table->dropForeign('users_blocked_by_foreign');
            $table->dropForeign('users_created_by_admin_id_foreign');
            $table->dropForeign('users_deleted_by_foreign');
        });

        Schema::dropIfExists('users');
    }
};
