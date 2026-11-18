<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retention_rule_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('affected_messages_count')->default(0);
            $table->string('status')->default('success'); // success, simulated, failed
            $table->text('error_message')->nullable();
            $table->timestamp('executed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_execution_logs');
    }
};
