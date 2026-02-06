<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings Migration
 *
 * Creates the system_settings table for dynamic runtime configuration.
 * This enables Provincial admins to adjust fraud detection thresholds,
 * system parameters, and feature flags without code redeployment.
 *
 * Design Pattern: Follows UBIS migration pattern with comprehensive indexing,
 * UUID support, audit trail, and SoftDeletes.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();

            // Public-facing identifier (UUID for route binding)
            $table->char('uuid', 36)->unique()->comment('Public-facing unique identifier');

            // Setting identification
            $table->string('key', 100)->unique()->comment('Unique setting key (e.g., RISK_THRESHOLD_DAYS)');

            // Value storage (TEXT for flexibility - can store JSON, numbers, strings)
            $table->text('value')->comment('Setting value stored as string');

            // Type enforcement metadata
            $table->enum('data_type', ['integer', 'float', 'string', 'boolean', 'json'])
                ->default('string')
                ->comment('Data type for type-safe casting');

            // Human-readable documentation
            $table->text('description')->nullable()
                ->comment('Human-readable explanation of what this setting controls');

            // Dynamic validation rules (stored as JSON array)
            $table->json('validation_rules')->nullable()
                ->comment('Laravel validation rules as JSON array');

            // Organizational grouping
            $table->string('category', 50)->default('system')
                ->comment('Setting category (fraud_detection, system, notifications)');

            // Protection flag for critical settings
            $table->boolean('is_editable')->default(true)
                ->comment('Whether this setting can be modified via UI');

            // Audit trail - track who created/updated settings
            $table->foreignId('created_by')->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('User who created this setting');

            $table->foreignId('updated_by')->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->comment('User who last updated this setting');

            // Timestamps
            $table->timestamps();

            // Soft deletes (prevent accidental permanent deletion)
            $table->softDeletes();

            // ===== INDEXES FOR PERFORMANCE =====

            // Fast lookup by category for grouped queries
            $table->index(['category', 'is_editable'], 'ss_category_editable');

            // Audit trail queries (who changed what when)
            $table->index(['updated_by', 'updated_at'], 'ss_audit_trail');

            // Category-based queries
            $table->index('category', 'ss_category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
