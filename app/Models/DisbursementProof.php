<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentType;
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

    /**
     * Return all submitted documents with their explicit DocumentType labels.
     *
     * Named URL columns (photo_url, signature_url, id_photo_url) have deterministic
     * types from their column names — no guessing required.
     *
     * Items in additional_documents use the {url, document_type} shape stored at
     * upload time. Legacy rows that stored bare URL strings fall back to
     * DocumentType::SupportingDocument so old data never breaks the response.
     *
     * @return array<int, array{type: string, url: string}>
     */
    public function getSubmittedDocuments(): array
    {
        $docs = [];

        if ($this->photo_url) {
            $docs[] = ['type' => DocumentType::BeneficiaryPhoto->value, 'url' => $this->photo_url];
        }
        if ($this->signature_url) {
            $docs[] = ['type' => DocumentType::Signature->value, 'url' => $this->signature_url];
        }
        if ($this->id_photo_url) {
            $docs[] = ['type' => DocumentType::ValidId->value, 'url' => $this->id_photo_url];
        }

        foreach ($this->additional_documents ?? [] as $doc) {
            // New format: {url, document_type} — type was captured at upload time.
            // Legacy format: bare URL string — fall back to SupportingDocument.
            if (is_array($doc)) {
                $type = DocumentType::tryFrom($doc['document_type'] ?? '') ?? DocumentType::SupportingDocument;
                $docs[] = ['type' => $type->value, 'url' => $doc['url']];
            } else {
                $docs[] = ['type' => DocumentType::SupportingDocument->value, 'url' => (string) $doc];
            }
        }

        return $docs;
    }
}
