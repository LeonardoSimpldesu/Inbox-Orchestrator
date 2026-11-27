<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_rule_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retention_rule_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('value', 255);
            $table->timestamps();
            $table->index(['type', 'value']);
        });

        Schema::table('retention_rules', function (Blueprint $table) {
            $table->string('kind', 20)->default('manual')->after('name');
        });

        // Gives each existing rule the role the app already inferred from its shape.
        DB::table('retention_rules')->where('filter_type', 'sender')->where('action', 'add_label')
            ->update(['kind' => 'sender_category']);

        DB::table('retention_rules')->where('filter_type', 'sender')->whereIn('action', ['archive', 'trash'])
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('email_senders')
                ->whereColumn('email_senders.email_account_id', 'retention_rules.email_account_id')
                ->whereColumn('email_senders.email', 'retention_rules.filter_value')
                ->where('email_senders.unsubscribe_status', 'blocked'))
            ->update(['kind' => 'hub_block']);

        DB::table('retention_rules')->where('filter_type', 'sender')->whereIn('action', ['archive', 'trash'])
            ->where('retention_days', 0)->where('kind', 'manual')
            ->update(['kind' => 'sender_cleanup']);

        DB::table('retention_rule_conditions')->insertUsing(
            ['retention_rule_id', 'type', 'value', 'created_at', 'updated_at'],
            DB::table('retention_rules')->select(['id', 'filter_type', 'filter_value', 'created_at', 'updated_at']),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_rule_conditions');

        Schema::table('retention_rules', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
