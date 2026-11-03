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
        Schema::create('mpesa_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('trans_id', 30)->unique();
            $table->string('transaction_type')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('bill_ref_number')->nullable();
            $table->string('msisdn', 20)->nullable();
            $table->string('payer_name')->nullable();
            $table->string('business_short_code', 20)->nullable();
            $table->dateTime('transacted_at');
            $table->string('status')->default('unmatched')->index();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('payload');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mpesa_transactions');
    }
};
