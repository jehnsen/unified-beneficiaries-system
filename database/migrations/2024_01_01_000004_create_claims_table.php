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
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')
                ->constrained('beneficiaries')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('municipality_id')
                ->constrained('municipalities')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('Where the assistance was released');

            // Claim Details
            $table->enum('assistance_type', [
                'Medical',
                'Cash',
                'Burial',
                'Educational',
                'Food',
                'Disaster Relief'
            ]);
            $table->decimal('amount', 10, 2)->comment('Amount in PHP');
            $table->text('purpose')->nullable()->comment('Detailed reason for assistance');
            $table->text('notes')->nullable();

            // Workflow Status
            $table->enum('status', [
                'PENDING',
                'UNDER_REVIEW',
                'APPROVED',
                'DISBURSED',
                'REJECTED',
                'CANCELLED'
            ])->default('PENDING');

            // Processing Information
            $table->foreignId('processed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('User who approved/rejected');

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            // Fraud Detection Flags
            $table->boolean('is_flagged')->default(false);
            $table->text('flag_reason')->nullable();
            $table->json('risk_assessment')->nullable()->comment('Fraud detection results');

            $table->timestamps();
            $table->softDeletes();

            // Performance indexes for reporting and fraud checks
            $table->index(['beneficiary_id', 'created_at']); // Timeline of claims per beneficiary
            $table->index(['municipality_id', 'status', 'created_at']); // Municipal reporting
            $table->index(['status', 'created_at']); // Status-based queries
            $table->index(['is_flagged', 'status']); // Flagged claims review
            $table->index(['assistance_type', 'created_at']); // Type-based analytics

            // Critical for fraud detection: Find recent claims across municipalities
            $table->index(['beneficiary_id', 'created_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
