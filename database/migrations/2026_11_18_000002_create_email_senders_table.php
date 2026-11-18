<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_senders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('domain')->index();
            $table->unsignedInteger('total_messages_received')->default(0);
            $table->unsignedInteger('total_messages_unread')->default(0);
            $table->string('unsubscribe_mailto')->nullable();
            $table->text('unsubscribe_url')->nullable();
            $table->string('unsubscribe_status')->default('none'); // none, requested, unsubscribed, failed
            $table->timestamp('last_email_received_at')->nullable();
            $table->timestamps();

            $table->unique(['email_account_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_senders');
    }
};
