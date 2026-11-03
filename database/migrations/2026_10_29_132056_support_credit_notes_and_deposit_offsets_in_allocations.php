<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An allocation settles part of an invoice from either a payment or a credit note.
     * Deposit offsets are payments linked to the lease whose deposit was used.
     */
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->change();
            $table->foreignId('credit_note_id')->nullable()->after('payment_id')->constrained()->cascadeOnDelete();

            $table->unique(['credit_note_id', 'invoice_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('lease_id')->nullable()->after('tenant_id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lease_id');
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropUnique(['credit_note_id', 'invoice_id']);
            $table->dropConstrainedForeignId('credit_note_id');
            $table->foreignId('payment_id')->nullable(false)->change();
        });
    }
};
