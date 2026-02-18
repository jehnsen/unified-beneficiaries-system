<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the old pattern of appending timestamped strings to claims.notes.
     * Storing notes as discrete rows makes them queryable, pageable, and auditable.
     * The claims.notes column is retained for its original purpose (claim description/purpose).
     */
    public function up(): void
    {
        Schema::create('claim_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('claim_id')
                ->constrained('claims')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('note');

            // created_at only — notes are immutable once written
            $table->timestamp('created_at')->useCurrent();

            $table->index(['claim_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_notes');
    }
};
