<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Beneficiary extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
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
     * Automatically set phonetic hash when last_name is set.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($beneficiary) {
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
}
