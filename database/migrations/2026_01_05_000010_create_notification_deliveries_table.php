<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('notification_id')->nullable();
            $table->string('notification_type', 200);
            $table->string('channel', 40);
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->nullableMorphs('notifiable');
            $table->string('recipient', 200)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('summary', 250)->nullable();
            $table->json('data')->nullable();
            $table->text('error_message')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index('status', 'notification_deliveries_status_idx');
            $table->index('created_at', 'notification_deliveries_created_at_idx');
            $table->index(['notification_id', 'channel', 'status'], 'notification_deliveries_notification_idx');

            // Foreign key added later once notification_messages exists.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
