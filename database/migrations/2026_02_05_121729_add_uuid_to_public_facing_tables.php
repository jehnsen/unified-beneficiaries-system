<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        // Step 2: Backfill existing records with UUIDs (MySQL 8.0 UUID() function)
        DB::statement('UPDATE municipalities SET uuid = UUID() WHERE uuid IS NULL');
        DB::statement('UPDATE users SET uuid = UUID() WHERE uuid IS NULL');
        DB::statement('UPDATE beneficiaries SET uuid = UUID() WHERE uuid IS NULL');
        DB::statement('UPDATE claims SET uuid = UUID() WHERE uuid IS NULL');

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
