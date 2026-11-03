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
        Schema::create('penalty_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete()->comment('The overdue invoice penalised');
            $table->foreignId('penalty_invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('penalty_rule_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('occurrence')->comment('1 for the first assessment, 2 for the next...');
            $table->date('assessed_on');
            $table->decimal('basis', 14, 2)->comment('Overdue amount the penalty was calculated on');
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['invoice_id', 'penalty_rule_id', 'occurrence']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penalty_charges');
    }
};
