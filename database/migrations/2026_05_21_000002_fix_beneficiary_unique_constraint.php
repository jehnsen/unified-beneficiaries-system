<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the municipality-scoped unique constraint with a global one.
     *
     * The old constraint ['first_name', 'last_name', 'birthdate', 'home_municipality_id']
     * allowed a fraudster to register identical personal details under a second municipality
     * and bypass duplicate detection entirely — the phonetic + Levenshtein scan would still
     * flag them, but the DB-level guard would not block the INSERT.
     *
     * The new constraint ['first_name', 'last_name', 'birthdate'] enforces the "Golden Record"
     * principle at the database level: one beneficiary record, globally unique.
     *
     * NOTE: If existing data already has duplicate (first_name, last_name, birthdate) tuples
     * across different municipalities, this migration will fail. Resolve duplicates via the
     * FraudDetectionService / VerifiedDistinctPairs workflow before running in production.
     */
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropUnique('unique_beneficiary');
            $table->unique(['first_name', 'last_name', 'birthdate'], 'unique_beneficiary_global');
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropUnique('unique_beneficiary_global');
            $table->unique(
                ['first_name', 'last_name', 'birthdate', 'home_municipality_id'],
                'unique_beneficiary'
            );
        });
    }
};
