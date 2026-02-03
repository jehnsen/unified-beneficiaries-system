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
        Schema::create('disbursement_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')
                ->constrained('claims')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            // Document Uploads (S3 paths)
            $table->string('photo_url')->nullable()->comment('Beneficiary photo during disbursement');
            $table->string('signature_url')->nullable()->comment('Digital signature');
            $table->string('id_photo_url')->nullable()->comment('Valid ID photo');
            $table->json('additional_documents')->nullable()->comment('Other supporting documents');

            // Geolocation (Audit Trail)
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('location_accuracy', 20)->nullable()->comment('GPS accuracy in meters');

            // Metadata
            $table->timestamp('captured_at')->comment('When proof was captured');
            $table->foreignId('captured_by_user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('device_info')->nullable()->comment('Mobile device or workstation info');
            $table->ipAddress('ip_address')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['claim_id', 'captured_at']);
            $table->index('captured_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('disbursement_proofs');
    }
};
