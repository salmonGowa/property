<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Journal entries are append-only: corrections are made by posting a reversing entry.
     */
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sequence')->unique();
            $table->date('entry_date')->index();
            $table->foreignId('fiscal_period_id')->constrained()->restrictOnDelete();
            $table->string('description');
            $table->string('reference')->nullable();
            $table->nullableMorphs('source');
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
