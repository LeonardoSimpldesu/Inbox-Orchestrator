<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('filter_type'); // category, domain, sender, query
            $table->string('filter_value'); // ex: CATEGORY_PROMOTIONS, amazon.com.br, uber.com
            $table->unsignedInteger('retention_days')->default(15);
            $table->string('action')->default('archive'); // archive, trash, add_label
            $table->string('target_label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_rules');
    }
};
