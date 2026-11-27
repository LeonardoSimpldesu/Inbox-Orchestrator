<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retention_rules', function (Blueprint $table) {
            $table->dropColumn(['filter_type', 'filter_value']);
        });
    }

    /**
     * The old model holds one filter: rules get back their first condition and
     * lose the others (down is only for development).
     */
    public function down(): void
    {
        Schema::table('retention_rules', function (Blueprint $table) {
            $table->string('filter_type')->nullable()->after('name');
            $table->string('filter_value')->nullable()->after('filter_type');
        });

        $first = fn (string $column) => DB::table('retention_rule_conditions')->select($column)
            ->whereColumn('retention_rule_conditions.retention_rule_id', 'retention_rules.id')
            ->orderBy('id')->limit(1);

        DB::table('retention_rules')->update([
            'filter_type' => $first('type'),
            'filter_value' => $first('value'),
        ]);
    }
};
