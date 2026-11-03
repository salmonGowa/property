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
        Schema::create('lenders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('branch')->nullable();
            $table->string('contact_person')->nullable()->comment('Relationship manager');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->decimal('interest_withholding_rate', 5, 2)->default(0)->comment('Percent of interest withheld for KRA; 0 for resident banks');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lenders');
    }
};
