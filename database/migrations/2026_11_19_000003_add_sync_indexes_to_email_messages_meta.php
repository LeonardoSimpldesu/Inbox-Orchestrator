<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_messages_meta', function (Blueprint $table) {
            $table->index('email_sender_id');
            $table->index(['email_account_id', 'received_at']);
            $table->index(['email_account_id', 'sender_domain']);
        });
    }

    public function down(): void
    {
        Schema::table('email_messages_meta', function (Blueprint $table) {
            $table->dropIndex(['email_sender_id']);
            $table->dropIndex(['email_account_id', 'received_at']);
            $table->dropIndex(['email_account_id', 'sender_domain']);
        });
    }
};
