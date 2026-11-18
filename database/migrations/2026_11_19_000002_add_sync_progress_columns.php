<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->timestamp('oldest_synced_at')->nullable()->after('last_synced_at');
            $table->timestamp('history_completed_at')->nullable()->after('oldest_synced_at');
        });

        Schema::table('email_senders', function (Blueprint $table) {
            $table->boolean('unsubscribe_one_click')->default(false)->after('unsubscribe_url');
        });
    }

    public function down(): void
    {
        Schema::table('email_senders', function (Blueprint $table) {
            $table->dropColumn('unsubscribe_one_click');
        });

        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn(['oldest_synced_at', 'history_completed_at']);
        });
    }
};
