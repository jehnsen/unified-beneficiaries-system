<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->create(config('activitylog.table_name'), function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('log_name')->nullable()->index();
                $table->text('description');
                // The model being changed (Claim, Beneficiary, etc.)
                $table->nullableMorphs('subject', 'subject');
                // Event name (created, updated, deleted)
                $table->string('event')->nullable();
                // The user who made the change
                $table->nullableMorphs('causer', 'causer');
                // Before/after state stored as JSON
                $table->json('properties')->nullable();
                // Groups related log entries from a single request (e.g. approve + notify)
                $table->uuid('batch_uuid')->nullable();
                $table->timestamps();
            });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->dropIfExists(config('activitylog.table_name'));
    }
};
