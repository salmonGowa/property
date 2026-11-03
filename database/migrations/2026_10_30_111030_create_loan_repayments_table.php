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
        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->restrictOnDelete();
            $table->date('paid_on');
            $table->boolean('is_prepayment')->default(false);
            $table->decimal('principal', 16, 2);
            $table->decimal('interest', 14, 2)->default(0);
            $table->decimal('withholding_tax', 14, 2)->default(0);
            $table->decimal('amount_paid', 16, 2)->comment('Cash paid to the lender');
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('reference')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');
    }
};
