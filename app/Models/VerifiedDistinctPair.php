<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * VerifiedDistinctPair Model
 *
 * Stores pairs of beneficiaries that have been manually verified as distinct (not duplicates)
 * or confirmed as duplicates. This prevents the fraud detection system from repeatedly
 * flagging the same pairs.
 *
 * CRITICAL: Pairs are normalized so that beneficiary_a_id < beneficiary_b_id.
 * This ensures (5, 10) and (10, 5) are stored as the same record: (5, 10).
 */
class VerifiedDistinctPair extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'uuid',
        'beneficiary_a_id',
        'beneficiary_b_id',
        'verification_status',
        'similarity_score',
        'levenshtein_distance',
        'verification_reason',
        'notes',
        'verified_by_user_id',
        'verified_at',
        'revoked_by_user_id',
        'revoked_at',
        'revocation_reason',
    ];

    protected $casts = [
        'similarity_score' => 'integer',
        'levenshtein_distance' => 'integer',
        'verified_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Configure activity logging for whitelist pair management.
     *
     * Every status transition (VERIFIED_DISTINCT → REVOKED) is a compliance event:
     * it directly controls which beneficiary pairs skip future fraud checks.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'verification_status',
                'verification_reason',
                'notes',
                'verified_by_user_id',
                'verified_at',
                'revoked_by_user_id',
                'revoked_at',
                'revocation_reason',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('verified_pairs');
    }

    /**
     * Boot method - Auto-generate UUID and normalize pair order.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($pair) {
            // Auto-generate UUID if not provided
            if (empty($pair->uuid)) {
                $pair->uuid = (string) Str::uuid();
            }

            // CRITICAL: Normalize pair order (smaller ID first)
            // This ensures (5, 10) and (10, 5) are stored as (5, 10)
            if ($pair->beneficiary_a_id > $pair->beneficiary_b_id) {
                $temp = $pair->beneficiary_a_id;
                $pair->beneficiary_a_id = $pair->beneficiary_b_id;
                $pair->beneficiary_b_id = $temp;
            }

            // Auto-set verified_at if not provided
            if (empty($pair->verified_at)) {
                $pair->verified_at = now();
            }
        });
    }

    /**
     * Get the first beneficiary in the pair.
     */
    public function beneficiaryA(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_a_id');
    }

    /**
     * Get the second beneficiary in the pair.
     */
    public function beneficiaryB(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class, 'beneficiary_b_id');
    }

    /**
     * Get the user who verified this pair.
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * Get the user who revoked this verification.
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /**
     * Check if the pair is active (not revoked or under review).
     */
    public function isActive(): bool
    {
        return in_array($this->verification_status, ['VERIFIED_DISTINCT', 'VERIFIED_DUPLICATE']);
    }

    /**
     * Check if the pair is verified as distinct (different people).
     */
    public function isDistinct(): bool
    {
        return $this->verification_status === 'VERIFIED_DISTINCT';
    }

    /**
     * Check if the pair is verified as duplicate (same person).
     */
    public function isDuplicate(): bool
    {
        return $this->verification_status === 'VERIFIED_DUPLICATE';
    }

    /**
     * Use UUID for route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
