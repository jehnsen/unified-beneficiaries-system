<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * MySQL ENUM columns cannot be altered with Schema::table() in a portable way.
     * A raw ALTER TABLE is required to add a new enum value without rebuilding the column.
     *
     * PENDING_FRAUD_CHECK: Transient status assigned immediately on claim creation.
     * The RunFraudCheckJob reads this status to confirm the job is processing
     * a fresh claim, then transitions the claim to PENDING (clean) or
     * PENDING + is_flagged=true (risky) once the async scan completes.
     */
    public function up(): void
    {
        DB::statement("
            ALTER TABLE claims
            MODIFY COLUMN status ENUM(
                'PENDING_FRAUD_CHECK',
                'PENDING',
                'UNDER_REVIEW',
                'APPROVED',
                'DISBURSED',
                'REJECTED',
                'CANCELLED'
            ) NOT NULL DEFAULT 'PENDING_FRAUD_CHECK'
        ");
    }

    public function down(): void
    {
        // Restore the original enum without PENDING_FRAUD_CHECK.
        // Any rows still in PENDING_FRAUD_CHECK (job never ran) are set to PENDING first
        // to avoid a constraint violation on the MODIFY.
        DB::statement("UPDATE claims SET status = 'PENDING' WHERE status = 'PENDING_FRAUD_CHECK'");

        DB::statement("
            ALTER TABLE claims
            MODIFY COLUMN status ENUM(
                'PENDING',
                'UNDER_REVIEW',
                'APPROVED',
                'DISBURSED',
                'REJECTED',
                'CANCELLED'
            ) NOT NULL DEFAULT 'PENDING'
        ");
    }
};
