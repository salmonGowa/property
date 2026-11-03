<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('penalty_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('method');
            $table->decimal('rate', 12, 4)->comment('Percent for percentage methods, KES for flat fees');
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->string('recurrence')->default('once');
            $table->unsignedSmallInteger('recurrence_days')->nullable();
            $table->json('charge_types');
            $table->decimal('minimum_overdue', 14, 2)->default(0);
            $table->string('cap_type')->default('none');
            $table->decimal('cap_value', 14, 2)->nullable();
            $table->date('effective_from');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penalty_rules');
    }
};
