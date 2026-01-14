<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        $driver = Schema::getConnection()->getDriverName();
        try {
            if ($driver === 'mysql') {
                DB::statement('CREATE INDEX notifications_data_format_idx ON notifications ((JSON_UNQUOTE(JSON_EXTRACT(data, "$.format"))))');
            } elseif ($driver === 'pgsql') {
                DB::statement('CREATE INDEX notifications_data_format_idx ON notifications ((data->>\'format\'))');
            }
        } catch (\Throwable) {
            // Ignore if unsupported.
        }
    }

    public function down(): void
    {
        try {
            DB::statement('DROP INDEX notifications_data_format_idx ON notifications');
        } catch (\Throwable) {
            // Ignore if index missing.
        }

        Schema::dropIfExists('notifications');
    }
};
