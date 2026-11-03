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
        Schema::create('covenant_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_covenant_id')->constrained()->cascadeOnDelete();
            $table->date('tested_on');
            $table->decimal('actual', 10, 3)->nullable()->comment('Null when the inputs were missing, e.g. no valuation');
            $table->decimal('threshold', 8, 3);
            $table->boolean('passed')->nullable();
            $table->json('inputs');
            $table->unique(['loan_covenant_id', 'tested_on']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('covenant_tests');
    }
};
