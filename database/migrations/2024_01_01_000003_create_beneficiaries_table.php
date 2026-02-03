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
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('home_municipality_id')
                ->constrained('municipalities')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('Primary residence municipality');

            // Personal Information
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->string('last_name_phonetic', 10)->nullable()->comment('SOUNDEX for fraud detection');
            $table->string('middle_name', 50)->nullable();
            $table->string('suffix', 10)->nullable()->comment('Jr., Sr., III, etc.');
            $table->date('birthdate');
            $table->enum('gender', ['Male', 'Female', 'Other']);

            // Contact Information
            $table->string('contact_number', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('barangay', 100)->nullable();

            // Identification
            $table->string('id_type', 50)->nullable()->comment('PhilSys, UMID, etc.');
            $table->string('id_number', 100)->nullable();

            // Biometric (Future expansion)
            $table->string('fingerprint_hash', 255)->nullable();

            // Metadata
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Critical indexes for fraud detection and search performance
            $table->index(['last_name', 'birthdate']); // Primary search pattern
            $table->index('last_name_phonetic'); // Fast phonetic matching for fraud detection
            $table->index(['first_name', 'last_name']); // Name-based search
            $table->index(['home_municipality_id', 'is_active']);
            $table->index('birthdate');

            // Unique constraint to prevent exact duplicates
            $table->unique(['first_name', 'last_name', 'birthdate', 'home_municipality_id'], 'unique_beneficiary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('beneficiaries');
    }
};
