<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_senders', function (Blueprint $table) {
            $table->unsignedInteger('total_messages_promotional')->default(0)->after('total_messages_unread');
            $table->string('unsubscribe_method')->nullable()->after('unsubscribe_status');
            $table->timestamp('unsubscribe_requested_at')->nullable()->after('unsubscribe_method');
            $table->string('unsubscribe_error')->nullable()->after('unsubscribe_requested_at');
            $table->index(['email_account_id', 'unsubscribe_status']);
        });

        DB::table('email_senders')->update([
            'total_messages_promotional' => DB::raw("(select count(*) from email_messages_meta m where m.email_sender_id = email_senders.id and m.is_deleted = 0 and m.labels like '%\"CATEGORY_PROMOTIONS\"%')"),
        ]);
    }

    public function down(): void
    {
        Schema::table('email_senders', function (Blueprint $table) {
            $table->dropIndex(['email_account_id', 'unsubscribe_status']);
            $table->dropColumn(['total_messages_promotional', 'unsubscribe_method', 'unsubscribe_requested_at', 'unsubscribe_error']);
        });
    }
};
