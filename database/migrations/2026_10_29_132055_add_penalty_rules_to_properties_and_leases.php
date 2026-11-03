<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A lease uses its own rule, else its property's, else the company default;
     * penalties_exempt switches penalties off for one lease regardless.
     */
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('penalty_rule_id')->nullable()->after('manager_id')->constrained()->nullOnDelete();
        });

        Schema::table('leases', function (Blueprint $table) {
            $table->foreignId('penalty_rule_id')->nullable()->after('escalation_interval_months')->constrained()->nullOnDelete();
            $table->boolean('penalties_exempt')->default(false)->after('penalty_rule_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('penalty_rule_id');
            $table->dropColumn('penalties_exempt');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('penalty_rule_id');
        });
    }
};
