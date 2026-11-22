<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_senders', function (Blueprint $table) {
            $table->timestamp('label_applying_at')->nullable()->after('unsubscribe_error');
        });
    }

    public function down(): void
    {
        Schema::table('email_senders', function (Blueprint $table) {
            $table->dropColumn('label_applying_at');
        });
    }
};
