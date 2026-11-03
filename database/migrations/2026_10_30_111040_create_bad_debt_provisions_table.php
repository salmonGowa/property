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
        Schema::create('bad_debt_provisions', function (Blueprint $table) {
            $table->id();
            $table->date('as_of');
            $table->json('rates')->comment('Percent provided against each aging bucket');
            $table->json('buckets')->comment('Arrears in each aging bucket at the time');
            $table->decimal('required', 16, 2);
            $table->decimal('previous', 16, 2);
            $table->decimal('adjustment', 16, 2)->comment('Positive raises the allowance, negative releases it');
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
        Schema::dropIfExists('bad_debt_provisions');
    }
};
