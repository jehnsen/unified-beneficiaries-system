<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds UUID columns to public-facing tables to prevent auto-increment ID exposure.
     * Strategy: Keep integer IDs for internal FKs, add UUIDs for API routes.
     */
    public function up(): void
    {
        // Step 1: Add uuid columns (nullable initially for backfilling)
        Schema::table('municipalities', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id')->comment('Public-facing identifier');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id')->comment('Public-facing identifier');
        });

        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id')->comment('Public-facing identifier');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->after('id')->comment('Public-facing identifier');
        });

        // Step 2: Backfill existing records with UUIDs.
        // Uses PHP-level generation so the migration works on both MySQL and SQLite
        // (SQLite has no native UUID() function). For large production tables this
        // runs in chunks to avoid locking the table for the full duration.
        foreach (['municipalities', 'users', 'beneficiaries', 'claims'] as $table) {
            DB::table($table)->whereNull('uuid')->chunkById(200, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
                }
            });
        }

        // Step 3: Make uuid NOT NULL and UNIQUE after backfilling
        Schema::table('municipalities', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });

        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Safe rollback: Drop UUID columns only. Foreign keys and relationships untouched.
     */
    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });

        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
