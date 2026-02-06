<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * System Setting Resource
 *
 * Transforms SystemSetting model into JSON:API compliant responses.
 *
 * Features:
 * - Uses UUID as public 'id'
 * - Returns type-safe casted value
 * - Includes validation rules for client-side validation
 * - Includes audit trail (updated_by, updated_at)
 *
 * @mixin \App\Models\SystemSetting
 */
class SystemSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Public identifier (UUID, not auto-increment ID)
            'id' => $this->uuid,

            // Setting identification
            'key' => $this->key,

            // Type-safe casted value (integer/float/boolean/json/string)
            'value' => $this->getCastedValue(),

            // Metadata
            'data_type' => $this->data_type,
            'description' => $this->description,
            'category' => $this->category,
            'is_editable' => $this->is_editable,

            // Validation rules for client-side validation
            'validation_rules' => $this->validation_rules,

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Audit trail - who last updated this setting
            'updated_by' => $this->whenLoaded('updater', function () {
                if ($this->updater) {
                    return [
                        'id' => $this->updater->uuid,
                        'name' => $this->updater->name,
                        'email' => $this->updater->email,
                    ];
                }
                return null;
            }),

            // Optional: Creator info for audit trail
            'created_by' => $this->whenLoaded('creator', function () {
                if ($this->creator) {
                    return [
                        'id' => $this->creator->uuid,
                        'name' => $this->creator->name,
                    ];
                }
                return null;
            }),
        ];
    }
}
