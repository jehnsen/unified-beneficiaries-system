<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Claim extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'beneficiary_id',
        'municipality_id',
        'assistance_type',
        'amount',
        'purpose',
        'notes',
        'status',
        'processed_by_user_id',
        'approved_at',
        'disbursed_at',
        'rejected_at',
        'rejection_reason',
        'is_flagged',
        'flag_reason',
        'risk_assessment',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_flagged' => 'boolean',
        'risk_assessment' => 'array',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Apply tenant scope automatically.
     * Claims are scoped to the user's municipality UNLESS bypassed for fraud checks.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(new TenantScope());
    }

    /**
     * Beneficiary who filed this claim.
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    /**
     * Municipality that processed/paid this claim.
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * User who processed (approved/rejected) this claim.
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }

    /**
     * Disbursement proofs for this claim.
     */
    public function disbursementProofs(): HasMany
    {
        return $this->hasMany(DisbursementProof::class);
    }

    /**
     * Check if claim is pending approval.
     */
    public function isPending(): bool
    {
        return in_array($this->status, ['PENDING', 'UNDER_REVIEW']);
    }

    /**
     * Check if claim was approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'APPROVED';
    }

    /**
     * Check if claim was disbursed.
     */
    public function isDisbursed(): bool
    {
        return $this->status === 'DISBURSED';
    }

    /**
     * Check if claim was rejected.
     */
    public function isRejected(): bool
    {
        return in_array($this->status, ['REJECTED', 'CANCELLED']);
    }
}
