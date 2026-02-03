<?php

declare(strict_types=1);

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
        Schema::create('municipalities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique()->comment('Municipality name');
            $table->string('code', 20)->unique()->comment('Unique code (e.g., MUN-001)');
            $table->string('logo_path')->nullable()->comment('Official logo file path');

            // Contact Information
            $table->string('address')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email', 100)->nullable();

            // Status Management
            $table->enum('status', ['ACTIVE', 'SUSPENDED', 'INACTIVE'])->default('ACTIVE');
            $table->boolean('is_active')->default(true);

            // Budget Tracking (For fiscal monitoring)
            $table->decimal('allocated_budget', 15, 2)->default(0)->comment('Annual assistance budget');
            $table->decimal('used_budget', 15, 2)->default(0)->comment('Total disbursed');

            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['status', 'is_active']);
            $table->index('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('municipalities');
    }
};
