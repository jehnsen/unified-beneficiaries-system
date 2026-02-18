<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Municipality extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'logo_path',
        'address',
        'contact_phone',
        'contact_email',
        'status',
        'is_active',
        'allocated_budget',
        'used_budget',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'allocated_budget' => 'decimal:2',
        'used_budget' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Configure activity logging for municipalities.
     *
     * Budget and status changes are audited — used_budget is incremented
     * automatically on disbursement (see EloquentClaimRepository), so its log
     * entries provide a secondary budget ledger for compliance reconciliation.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code', 'status', 'is_active', 'allocated_budget', 'used_budget'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('municipalities');
    }

    /**
     * Boot method to auto-generate UUID on creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($municipality) {
            if (empty($municipality->uuid)) {
                $municipality->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Users assigned to this municipality.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Beneficiaries whose home is in this municipality.
     */
    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class, 'home_municipality_id');
    }

    /**
     * Claims processed/paid by this municipality.
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * Check if municipality is operational.
     */
    public function isOperational(): bool
    {
        return $this->is_active && $this->status === 'ACTIVE';
    }

    /**
     * Get remaining budget.
     */
    public function getRemainingBudgetAttribute(): float
    {
        return (float) ($this->allocated_budget - $this->used_budget);
    }

    /**
     * Get the route key name for Laravel route model binding.
     * This tells Laravel to use 'uuid' instead of 'id' for route binding.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
