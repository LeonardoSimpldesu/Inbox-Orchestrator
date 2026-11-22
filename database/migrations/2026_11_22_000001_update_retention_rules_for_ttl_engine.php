<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retention_rules', function (Blueprint $table) {
            $table->boolean('archive_after_label')->default(false)->after('target_label');
            $table->timestamp('simulated_at')->nullable()->after('is_active');
            $table->boolean('is_active')->default(false)->change();
            $table->index(['email_account_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('retention_rules', function (Blueprint $table) {
            $table->dropIndex(['email_account_id', 'is_active']);
            $table->boolean('is_active')->default(true)->change();
            $table->dropColumn(['archive_after_label', 'simulated_at']);
        });
    }
};
