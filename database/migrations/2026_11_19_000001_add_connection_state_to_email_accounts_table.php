<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->timestamp('authorized_at')->nullable()->after('token_expires_at');
            $table->timestamp('reauth_required_at')->nullable()->after('authorized_at');
            $table->softDeletes();
        });

        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['authorized_at', 'reauth_required_at']);
        });
    }
};
