<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Beneficiary extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'uuid',
        'home_municipality_id',
        'first_name',
        'last_name',
        'last_name_phonetic',
        'middle_name',
        'suffix',
        'birthdate',
        'gender',
        'contact_number',
        'address',
        'barangay',
        'id_type',
        'id_number',
        'fingerprint_hash',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Configure activity logging for beneficiary records.
     *
     * Name and identity fields are logged because changes to these are the primary
     * indicator of record tampering. Phonetic hash is derived, not user-supplied, so excluded.
     * PII fields like contact_number and fingerprint_hash are excluded to limit log exposure.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'first_name',
                'last_name',
                'middle_name',
                'suffix',
                'birthdate',
                'gender',
                'address',
                'barangay',
                'id_type',
                'id_number',
                'home_municipality_id',
                'is_active',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('beneficiaries');
    }

    /**
     * Automatically set phonetic hash when last_name is set.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($beneficiary) {
            // Auto-generate UUID for new records
            if (empty($beneficiary->uuid)) {
                $beneficiary->uuid = (string) Str::uuid();
            }

            // Auto-generate phonetic hash
            if ($beneficiary->last_name && !$beneficiary->last_name_phonetic) {
                $beneficiary->last_name_phonetic = soundex($beneficiary->last_name);
            }
        });

        static::updating(function ($beneficiary) {
            if ($beneficiary->isDirty('last_name')) {
                $beneficiary->last_name_phonetic = soundex($beneficiary->last_name);
            }
        });
    }

    /**
     * Home municipality where beneficiary resides.
     */
    public function homeMunicipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class, 'home_municipality_id');
    }

    /**
     * All claims filed by this beneficiary across ANY municipality.
     */
    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    /**
     * User who created this beneficiary record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who last updated this record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get full name.
     */
    public function getFullNameAttribute(): string
    {
        $name = trim("{$this->first_name} {$this->middle_name} {$this->last_name}");

        if ($this->suffix) {
            $name .= " {$this->suffix}";
        }

        return $name;
    }

    /**
     * Get age from birthdate.
     */
    public function getAgeAttribute(): int
    {
        return $this->birthdate->age;
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
