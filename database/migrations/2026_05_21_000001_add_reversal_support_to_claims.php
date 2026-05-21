<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the status enum to include REVERSED.
        // MySQL requires a full MODIFY COLUMN to change an enum's allowed values.
        // Existing rows are unaffected; only the permitted set widens.
        DB::statement("ALTER TABLE claims MODIFY COLUMN status ENUM(
            'PENDING',
            'UNDER_REVIEW',
            'APPROVED',
            'DISBURSED',
            'REJECTED',
            'CANCELLED',
            'REVERSED'
        ) NOT NULL DEFAULT 'PENDING'");

        Schema::table('claims', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('rejected_at');
            $table->text('reversal_reason')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });

        DB::statement("ALTER TABLE claims MODIFY COLUMN status ENUM(
            'PENDING',
            'UNDER_REVIEW',
            'APPROVED',
            'DISBURSED',
            'REJECTED',
            'CANCELLED'
        ) NOT NULL DEFAULT 'PENDING'");
    }
};
