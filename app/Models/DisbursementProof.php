<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisbursementProof extends Model
{
    use HasFactory;

    protected $fillable = [
        'claim_id',
        'photo_url',
        'signature_url',
        'id_photo_url',
        'additional_documents',
        'latitude',
        'longitude',
        'location_accuracy',
        'captured_at',
        'captured_by_user_id',
        'device_info',
        'ip_address',
    ];

    protected $casts = [
        'additional_documents' => 'array',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'captured_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Claim this proof belongs to.
     */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /**
     * User who captured this proof.
     */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    /**
     * Check if geolocation data is available.
     */
    public function hasGeolocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Get Google Maps URL for the location.
     */
    public function getGoogleMapsUrlAttribute(): ?string
    {
        if (!$this->hasGeolocation()) {
            return null;
        }

        return "https://www.google.com/maps?q={$this->latitude},{$this->longitude}";
    }
}
