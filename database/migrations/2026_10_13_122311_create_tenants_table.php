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
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('account_number', 20)->unique()->comment('Quoted as the M-Pesa Paybill account number');
            $table->string('type');
            $table->string('name');
            $table->text('id_number')->nullable()->comment('Encrypted national ID or passport number');
            $table->string('registration_number')->nullable();
            $table->string('kra_pin', 11)->nullable()->index();
            $table->string('email')->nullable();
            $table->string('phone', 20)->index();
            $table->string('alternate_phone', 20)->nullable();
            $table->string('postal_address')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('contact_person_phone', 20)->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();
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
        Schema::dropIfExists('tenants');
    }
};
