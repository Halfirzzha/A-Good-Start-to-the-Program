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
        Schema::table('system_settings', function (Blueprint $table) {
            // Add missing AI columns that don't exist yet
            if (!Schema::hasColumn('system_settings', 'ai_provider')) {
                $table->string('ai_provider', 50)->default('openai')->after('ai_enabled');
            }
            if (!Schema::hasColumn('system_settings', 'ai_api_key')) {
                $table->longText('ai_api_key')->nullable()->after('ai_provider');
            }
            if (!Schema::hasColumn('system_settings', 'ai_organization')) {
                $table->string('ai_organization', 100)->nullable()->after('ai_api_key');
            }
            if (!Schema::hasColumn('system_settings', 'ai_model')) {
                $table->string('ai_model', 50)->default('gpt-4o')->after('ai_organization');
            }
            if (!Schema::hasColumn('system_settings', 'ai_max_tokens')) {
                $table->unsignedSmallInteger('ai_max_tokens')->default(4096)->after('ai_model');
            }
            if (!Schema::hasColumn('system_settings', 'ai_temperature')) {
                $table->decimal('ai_temperature', 3, 2)->default(0.30)->after('ai_max_tokens');
            }
            if (!Schema::hasColumn('system_settings', 'ai_timeout')) {
                $table->unsignedSmallInteger('ai_timeout')->default(30)->after('ai_temperature');
            }
            if (!Schema::hasColumn('system_settings', 'ai_retry_attempts')) {
                $table->unsignedSmallInteger('ai_retry_attempts')->default(3)->after('ai_timeout');
            }

            // AI Rate Limiting
            if (!Schema::hasColumn('system_settings', 'ai_rate_limit_rpm')) {
                $table->unsignedInteger('ai_rate_limit_rpm')->default(60)->after('ai_retry_attempts');
            }
            if (!Schema::hasColumn('system_settings', 'ai_rate_limit_tpm')) {
                $table->unsignedInteger('ai_rate_limit_tpm')->default(90000)->after('ai_rate_limit_rpm');
            }
            if (!Schema::hasColumn('system_settings', 'ai_rate_limit_tpd')) {
                $table->unsignedInteger('ai_rate_limit_tpd')->default(1000000)->after('ai_rate_limit_tpm');
            }
            if (!Schema::hasColumn('system_settings', 'ai_daily_usage')) {
                $table->unsignedInteger('ai_daily_usage')->default(0)->after('ai_rate_limit_tpd');
            }
            if (!Schema::hasColumn('system_settings', 'ai_usage_reset_date')) {
                $table->date('ai_usage_reset_date')->nullable()->after('ai_daily_usage');
            }

            // AI Feature Toggles
            if (!Schema::hasColumn('system_settings', 'ai_feature_security_analysis')) {
                $table->boolean('ai_feature_security_analysis')->default(true)->after('ai_usage_reset_date');
            }
            if (!Schema::hasColumn('system_settings', 'ai_feature_anomaly_detection')) {
                $table->boolean('ai_feature_anomaly_detection')->default(true)->after('ai_feature_security_analysis');
            }
            if (!Schema::hasColumn('system_settings', 'ai_feature_threat_classification')) {
                $table->boolean('ai_feature_threat_classification')->default(true)->after('ai_feature_anomaly_detection');
            }
            if (!Schema::hasColumn('system_settings', 'ai_feature_log_summarization')) {
                $table->boolean('ai_feature_log_summarization')->default(true)->after('ai_feature_threat_classification');
            }
            if (!Schema::hasColumn('system_settings', 'ai_feature_smart_alerts')) {
                $table->boolean('ai_feature_smart_alerts')->default(true)->after('ai_feature_log_summarization');
            }
            if (!Schema::hasColumn('system_settings', 'ai_feature_auto_response')) {
                $table->boolean('ai_feature_auto_response')->default(false)->after('ai_feature_smart_alerts');
            }
            if (!Schema::hasColumn('system_settings', 'ai_feature_chat_assistant')) {
                $table->boolean('ai_feature_chat_assistant')->default(false)->after('ai_feature_auto_response');
            }

            // AI Alert Triggers
            if (!Schema::hasColumn('system_settings', 'ai_alert_high_risk_score')) {
                $table->unsignedTinyInteger('ai_alert_high_risk_score')->default(8)->after('ai_feature_chat_assistant');
            }
            if (!Schema::hasColumn('system_settings', 'ai_alert_suspicious_patterns')) {
                $table->unsignedTinyInteger('ai_alert_suspicious_patterns')->default(5)->after('ai_alert_high_risk_score');
            }
            if (!Schema::hasColumn('system_settings', 'ai_alert_failed_logins')) {
                $table->unsignedTinyInteger('ai_alert_failed_logins')->default(10)->after('ai_alert_suspicious_patterns');
            }
            if (!Schema::hasColumn('system_settings', 'ai_alert_anomaly_confidence')) {
                $table->decimal('ai_alert_anomaly_confidence', 4, 2)->default(0.85)->after('ai_alert_failed_logins');
            }

            // AI Response Actions
            if (!Schema::hasColumn('system_settings', 'ai_action_auto_block_ip')) {
                $table->boolean('ai_action_auto_block_ip')->default(false)->after('ai_alert_anomaly_confidence');
            }
            if (!Schema::hasColumn('system_settings', 'ai_action_auto_lock_user')) {
                $table->boolean('ai_action_auto_lock_user')->default(false)->after('ai_action_auto_block_ip');
            }
            if (!Schema::hasColumn('system_settings', 'ai_action_notify_admin')) {
                $table->boolean('ai_action_notify_admin')->default(true)->after('ai_action_auto_lock_user');
            }
            if (!Schema::hasColumn('system_settings', 'ai_action_create_incident')) {
                $table->boolean('ai_action_create_incident')->default(true)->after('ai_action_notify_admin');
            }
            if (!Schema::hasColumn('system_settings', 'ai_action_custom_rules')) {
                $table->json('ai_action_custom_rules')->nullable()->after('ai_action_create_incident');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $columns = [
                'ai_provider',
                'ai_api_key',
                'ai_organization',
                'ai_model',
                'ai_max_tokens',
                'ai_temperature',
                'ai_timeout',
                'ai_retry_attempts',
                'ai_rate_limit_rpm',
                'ai_rate_limit_tpm',
                'ai_rate_limit_tpd',
                'ai_daily_usage',
                'ai_usage_reset_date',
                'ai_feature_security_analysis',
                'ai_feature_anomaly_detection',
                'ai_feature_threat_classification',
                'ai_feature_log_summarization',
                'ai_feature_smart_alerts',
                'ai_feature_auto_response',
                'ai_feature_chat_assistant',
                'ai_alert_high_risk_score',
                'ai_alert_suspicious_patterns',
                'ai_alert_failed_logins',
                'ai_alert_anomaly_confidence',
                'ai_action_auto_block_ip',
                'ai_action_auto_lock_user',
                'ai_action_notify_admin',
                'ai_action_create_incident',
                'ai_action_custom_rules',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('system_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
