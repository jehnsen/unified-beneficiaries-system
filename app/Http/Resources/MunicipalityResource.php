<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MunicipalityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'logo_path' => $this->logo_path,
            'address' => $this->address,
            'contact_phone' => $this->contact_phone,
            'contact_email' => $this->contact_email,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'allocated_budget' => (float) $this->allocated_budget,
            'used_budget' => (float) $this->used_budget,
            'remaining_budget' => (float) ($this->allocated_budget - $this->used_budget),
            'beneficiaries_count' => $this->whenCounted('beneficiaries'),
            'claims_count' => $this->whenCounted('claims'),
            'users_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
