<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'municipality_id',
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Municipality this user belongs to.
     * NULL = Provincial Staff (Global Access).
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /**
     * Claims processed by this user.
     */
    public function processedClaims(): HasMany
    {
        return $this->hasMany(Claim::class, 'processed_by_user_id');
    }

    /**
     * Disbursement proofs captured by this user.
     */
    public function capturedProofs(): HasMany
    {
        return $this->hasMany(DisbursementProof::class, 'captured_by_user_id');
    }

    /**
     * Check if user is Provincial Staff (Global Access).
     */
    public function isProvincialStaff(): bool
    {
        return $this->municipality_id === null;
    }

    /**
     * Check if user is Municipal Staff (Tenant-Scoped).
     */
    public function isMunicipalStaff(): bool
    {
        return $this->municipality_id !== null;
    }

    /**
     * Check if user can access all municipalities.
     */
    public function hasGlobalAccess(): bool
    {
        return $this->isProvincialStaff();
    }

    /**
     * Check if user has admin privileges.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'ADMIN';
    }
}
