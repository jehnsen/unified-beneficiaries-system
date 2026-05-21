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
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, LogsActivity;

    protected $fillable = [
        'uuid',
        'municipality_id',
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'email_verified_at',
        'failed_login_attempts',
        'locked_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'locked_at' => 'datetime',
        'password' => 'hashed',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'failed_login_attempts' => 'integer',
    ];

    /**
     * Configure activity logging for user accounts.
     *
     * Role and is_active changes are the highest-risk user events — a deactivated
     * account being silently reactivated, or a REVIEWER being promoted to ADMIN,
     * are exactly the kind of privilege escalation this log must capture.
     * Password is excluded; it's hashed and never meaningful in an audit log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'role', 'is_active', 'municipality_id', 'locked_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('users');
    }

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
     * Check if the account is locked due to too many failed login attempts.
     * Locked accounts require explicit admin intervention via POST /users/{uuid}/unlock.
     */
    public function isLocked(): bool
    {
        return $this->locked_at !== null;
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
