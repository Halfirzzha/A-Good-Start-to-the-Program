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
            $table->string('project_name', 120)->default('System');
            $table->text('project_description')->nullable();
            $table->string('project_url', 191)->nullable();

            $table->string('branding_logo_disk', 50)->nullable();
            $table->string('branding_logo_path', 255)->nullable();
            $table->string('branding_logo_fallback_disk', 50)->nullable();
            $table->string('branding_logo_fallback_path', 255)->nullable();
            $table->string('branding_logo_status', 50)->default('unset');
            $table->timestamp('branding_logo_updated_at')->nullable();

            $table->string('branding_cover_disk', 50)->nullable();
            $table->string('branding_cover_path', 255)->nullable();
            $table->string('branding_cover_fallback_disk', 50)->nullable();
            $table->string('branding_cover_fallback_path', 255)->nullable();
            $table->string('branding_cover_status', 50)->default('unset');
            $table->timestamp('branding_cover_updated_at')->nullable();

            $table->string('branding_favicon_disk', 50)->nullable();
            $table->string('branding_favicon_path', 255)->nullable();
            $table->string('branding_favicon_fallback_disk', 50)->nullable();
            $table->string('branding_favicon_fallback_path', 255)->nullable();
            $table->string('branding_favicon_status', 50)->default('unset');
            $table->timestamp('branding_favicon_updated_at')->nullable();

            $table->string('storage_primary_disk', 50)->default('google');
            $table->string('storage_fallback_disk', 50)->default('public');
            $table->string('storage_drive_root', 120)->default('Warex-System');
            $table->string('storage_drive_folder_branding', 120)->default('branding');
            $table->string('storage_drive_folder_favicon', 120)->default('branding');

            $table->boolean('email_enabled')->default(true);
            $table->string('email_provider', 100)->default('SMTP');
            $table->string('email_from_name', 120)->nullable();
            $table->string('email_from_address', 191)->nullable();
            $table->string('email_auth_from_name', 120)->nullable();
            $table->string('email_auth_from_address', 191)->nullable();
            $table->json('email_recipients')->nullable();

            $table->string('smtp_mailer', 20)->default('smtp');
            $table->string('smtp_host', 191)->nullable();
            $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->string('smtp_encryption', 10)->default('tls');
            $table->string('smtp_username', 191)->nullable();
            $table->longText('smtp_password')->nullable();

            $table->boolean('telegram_enabled')->default(false);
            $table->string('telegram_chat_id', 50)->nullable();
            $table->longText('telegram_bot_token')->nullable();

            $table->longText('google_drive_service_account_json')->nullable();
            $table->string('google_drive_client_id', 191)->nullable();
            $table->string('google_drive_client_secret', 191)->nullable();
            $table->string('google_drive_refresh_token', 191)->nullable();

            // AI Configuration - OpenAI Integration
            $table->boolean('ai_enabled')->default(false);
            $table->string('ai_provider', 50)->default('openai');
            $table->longText('ai_api_key')->nullable();
            $table->string('ai_organization', 100)->nullable();
            $table->string('ai_model', 50)->default('gpt-4o');
            $table->unsignedSmallInteger('ai_max_tokens')->default(4096);
            $table->decimal('ai_temperature', 3, 2)->default(0.30);
            $table->unsignedSmallInteger('ai_timeout')->default(30);
            $table->unsignedSmallInteger('ai_retry_attempts')->default(3);

            // AI Rate Limiting
            $table->unsignedInteger('ai_rate_limit_rpm')->default(60);
            $table->unsignedInteger('ai_rate_limit_tpm')->default(90000);
            $table->unsignedInteger('ai_rate_limit_tpd')->default(1000000);
            $table->unsignedInteger('ai_daily_usage')->default(0);
            $table->date('ai_usage_reset_date')->nullable();

            // AI Feature Toggles
            $table->boolean('ai_feature_security_analysis')->default(true);
            $table->boolean('ai_feature_anomaly_detection')->default(true);
            $table->boolean('ai_feature_threat_classification')->default(true);
            $table->boolean('ai_feature_log_summarization')->default(true);
            $table->boolean('ai_feature_smart_alerts')->default(true);
            $table->boolean('ai_feature_auto_response')->default(false);
            $table->boolean('ai_feature_chat_assistant')->default(false);

            // AI Alert Triggers
            $table->unsignedTinyInteger('ai_alert_high_risk_score')->default(8);
            $table->unsignedTinyInteger('ai_alert_suspicious_patterns')->default(5);
            $table->unsignedTinyInteger('ai_alert_failed_logins')->default(10);
            $table->decimal('ai_alert_anomaly_confidence', 4, 2)->default(0.85);

            // AI Response Actions
            $table->boolean('ai_action_auto_block_ip')->default(false);
            $table->boolean('ai_action_auto_lock_user')->default(false);
            $table->boolean('ai_action_notify_admin')->default(true);
            $table->boolean('ai_action_create_incident')->default(true);
            $table->json('ai_action_custom_rules')->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('updated_ip', 45)->nullable();
            $table->string('updated_user_agent', 255)->nullable();
            $table->timestamps();

            $table->index('updated_by', 'system_settings_updated_by_idx');
            $table->index('project_name', 'system_settings_project_name_idx');
            $table->index('project_url', 'system_settings_project_url_idx');
            $table->index('storage_primary_disk', 'system_settings_storage_primary_idx');
            $table->index('storage_fallback_disk', 'system_settings_storage_fallback_idx');
            $table->index('email_enabled', 'system_settings_email_enabled_idx');
            $table->index('email_provider', 'system_settings_email_provider_idx');
            $table->index('smtp_host', 'system_settings_smtp_host_idx');
            $table->index('telegram_enabled', 'system_settings_telegram_enabled_idx');
            $table->index('smtp_username', 'system_settings_smtp_username_idx');
            $table->index('email_from_address', 'system_settings_email_from_address_idx');
            $table->index('email_auth_from_address', 'system_settings_email_auth_from_address_idx');

            $table->foreign('updated_by', 'system_settings_updated_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
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
