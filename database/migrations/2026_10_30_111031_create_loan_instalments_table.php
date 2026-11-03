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
        Schema::create('loan_instalments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_date');
            $table->decimal('opening_balance', 16, 2);
            $table->decimal('principal', 16, 2);
            $table->decimal('interest', 14, 2);
            $table->decimal('total', 16, 2);
            $table->decimal('closing_balance', 16, 2);
            $table->decimal('interest_rate', 6, 3);
            $table->foreignId('loan_repayment_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['loan_id', 'sequence']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_instalments');
    }
};
