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
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('lender_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('lender_reference')->nullable()->comment('The bank\'s loan account number');
            $table->string('type');
            $table->string('status');
            $table->decimal('facility_amount', 16, 2);
            $table->date('start_date');
            $table->date('first_payment_date');
            $table->date('maturity_date');
            $table->string('payment_frequency');
            $table->string('repayment_type');
            $table->unsignedSmallInteger('interest_only_periods')->default(0)->comment('Grace instalments at the start that pay interest only');
            $table->decimal('balloon_amount', 16, 2)->default(0);
            $table->string('rate_type');
            $table->string('base_rate_name')->nullable();
            $table->decimal('base_rate', 6, 3)->nullable();
            $table->decimal('margin', 6, 3)->nullable();
            $table->decimal('interest_rate', 6, 3)->comment('Current all-in annual rate');
            $table->foreignId('liability_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('accounts')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
