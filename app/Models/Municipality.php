<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Municipality extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
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
}
