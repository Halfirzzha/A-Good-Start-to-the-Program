<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('hash', 64)->nullable()->after('context');
            $table->string('previous_hash', 64)->nullable()->after('hash');

            $table->index('hash', 'audit_logs_hash_index');
            $table->index('previous_hash', 'audit_logs_previous_hash_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_hash_index');
            $table->dropIndex('audit_logs_previous_hash_index');
            $table->dropColumn(['hash', 'previous_hash']);
        });
    }
};
