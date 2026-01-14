<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_messages', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('title', 200);
            $table->text('message');
            $table->string('category', 30)->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->string('status', 20)->default('draft')->index();
            $table->boolean('target_all')->default(false);
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at'], 'notification_messages_status_schedule_idx');
            $table->index(['category', 'priority'], 'notification_messages_category_priority_idx');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::create('notification_targets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('notification_id');
            $table->string('target_type', 30);
            $table->string('target_value', 120)->nullable();
            $table->timestamps();

            $table->index(['notification_id', 'target_type'], 'notification_targets_notification_idx');

            $table->foreign('notification_id')
                ->references('id')
                ->on('notification_messages')
                ->cascadeOnDelete();
        });

        Schema::create('notification_channels', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('notification_id');
            $table->string('channel', 40);
            $table->boolean('enabled')->default(true);
            $table->string('provider', 60)->nullable();
            $table->string('from_name', 120)->nullable();
            $table->string('from_address', 200)->nullable();
            $table->string('reply_to', 200)->nullable();
            $table->string('chat_id', 120)->nullable();
            $table->string('sms_sender', 120)->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->unsignedSmallInteger('retry_after_seconds')->default(60);
            $table->timestamp('scheduled_at')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['notification_id', 'channel'], 'notification_channels_notification_idx');

            $table->foreign('notification_id')
                ->references('id')
                ->on('notification_messages')
                ->cascadeOnDelete();
        });

        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('notification_id');
            $table->unsignedBigInteger('user_id');
            $table->string('channel', 32)->default('inapp');
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'user_id'], 'user_notifications_unique');
            $table->index(['user_id', 'is_read'], 'user_notifications_user_read_idx');

            $table->foreign('notification_id')
                ->references('id')
                ->on('notification_messages')
                ->cascadeOnDelete();
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('notification_channels');
        Schema::dropIfExists('notification_targets');
        Schema::dropIfExists('notification_messages');
    }
};
