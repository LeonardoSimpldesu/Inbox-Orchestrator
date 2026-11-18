<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_messages_meta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_id')->index();
            $table->string('provider_thread_id')->index();
            $table->foreignId('email_sender_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sender_email');
            $table->string('sender_domain')->index();
            $table->string('subject')->nullable();
            $table->json('labels')->nullable();
            $table->timestamp('received_at')->index();
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();

            $table->unique(['email_account_id', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages_meta');
    }
};
