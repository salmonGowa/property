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
        Schema::create('leases', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('billing_frequency')->default('monthly');
            $table->decimal('rent_amount', 14, 2)->comment('Monthly rent before escalation');
            $table->decimal('service_charge', 14, 2)->default(0)->comment('Monthly service charge');
            $table->decimal('deposit_amount', 14, 2)->default(0);
            $table->boolean('charge_vat')->default(false);
            $table->unsignedTinyInteger('due_day')->default(5);
            $table->decimal('escalation_rate', 5, 2)->default(0);
            $table->unsignedSmallInteger('escalation_interval_months')->default(12);
            $table->timestamp('activated_at')->nullable();
            $table->date('terminated_on')->nullable();
            $table->string('termination_reason')->nullable();
            $table->foreignId('renewed_from_id')->nullable()->constrained('leases')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['unit_id', 'status']);
            $table->index(['status', 'start_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leases');
    }
};
