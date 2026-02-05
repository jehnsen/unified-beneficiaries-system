<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'uuid',
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
     * Boot method to auto-generate UUID on creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

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

    /**
     * Get the route key name for Laravel route model binding.
     * This tells Laravel to use 'uuid' instead of 'id' for route binding.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
