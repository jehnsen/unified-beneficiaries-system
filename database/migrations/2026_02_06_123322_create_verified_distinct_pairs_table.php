<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the verified_distinct_pairs table to store pairs of beneficiaries
     * that have been manually verified as distinct (not duplicates) or confirmed duplicates.
     * This prevents the fraud detection system from repeatedly flagging the same pairs.
     */
    public function up(): void
    {
        Schema::create('verified_distinct_pairs', function (Blueprint $table) {
            // Primary key
            $table->id();
            $table->uuid('uuid')->unique();

            // The pair of beneficiaries (normalized: smaller ID first)
            $table->foreignId('beneficiary_a_id')
                ->constrained('beneficiaries')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('First beneficiary in the pair (smaller ID)');

            $table->foreignId('beneficiary_b_id')
                ->constrained('beneficiaries')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('Second beneficiary in the pair (larger ID)');

            // Verification status
            $table->enum('verification_status', [
                'VERIFIED_DISTINCT',    // Confirmed as different people
                'VERIFIED_DUPLICATE',   // Confirmed as same person
                'UNDER_REVIEW',         // Being investigated
                'REVOKED'              // Verification removed
            ])->default('VERIFIED_DISTINCT');

            // Audit trail for similarity metrics (snapshot at verification time)
            $table->integer('similarity_score')->nullable()
                ->comment('Similarity score when verified (0-100)');
            $table->integer('levenshtein_distance')->nullable()
                ->comment('Levenshtein distance when verified');

            // Verification metadata
            $table->text('verification_reason')
                ->comment('Why this pair was verified as distinct/duplicate');
            $table->text('notes')->nullable();

            // Audit trail - who verified and when
            $table->foreignId('verified_by_user_id')
                ->constrained('users')
                ->cascadeOnUpdate()
                ->restrictOnDelete()
                ->comment('User who performed verification');
            $table->timestamp('verified_at');

            // Revocation metadata
            $table->foreignId('revoked_by_user_id')->nullable()
                ->constrained('users')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // CRITICAL INDEXES for performance
            // Bidirectional lookup (handles both A→B and B→A)
            $table->index(['beneficiary_a_id', 'beneficiary_b_id', 'verification_status'], 'vdp_pair_ab');
            $table->index(['beneficiary_b_id', 'beneficiary_a_id', 'verification_status'], 'vdp_pair_ba');

            // Index for finding all pairs for a beneficiary
            $table->index(['beneficiary_a_id', 'verification_status'], 'vdp_ben_a_status');
            $table->index(['beneficiary_b_id', 'verification_status'], 'vdp_ben_b_status');

            // Index for audit queries
            $table->index(['verified_by_user_id', 'verified_at'], 'vdp_verified_by_at');
            $table->index(['verification_status', 'created_at'], 'vdp_status_created');

            // Prevent duplicate pair entries (A,B) and (B,A) are logically the same
            // Application logic ensures beneficiary_a_id < beneficiary_b_id
            $table->unique(['beneficiary_a_id', 'beneficiary_b_id'], 'vdp_unique_pair');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('verified_distinct_pairs');
    }
};
