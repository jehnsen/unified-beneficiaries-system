<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class DisbursementProofResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_id' => $this->claim_id,

            // Document URLs (full public URLs)
            'photo_url' => Storage::url($this->photo_url),
            'signature_url' => Storage::url($this->signature_url),
            'id_photo_url' => $this->id_photo_url ? Storage::url($this->id_photo_url) : null,
            'additional_documents' => $this->additional_documents,

            // Geolocation
            'location' => $this->hasGeolocation() ? [
                'latitude' => (float) $this->latitude,
                'longitude' => (float) $this->longitude,
                'accuracy' => $this->location_accuracy,
                'google_maps_url' => $this->google_maps_url,
            ] : null,

            // Audit trail
            'captured_at' => $this->captured_at->toIso8601String(),
            'captured_by' => $this->whenLoaded('capturedBy', function () {
                return [
                    'id' => $this->capturedBy->id,
                    'name' => $this->capturedBy->name,
                ];
            }),
            'device_info' => $this->device_info,
            'ip_address' => $this->ip_address,

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
