<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BeneficiaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Implements data masking for Inter-LGU visibility:
     * - If beneficiary is from another municipality, mask sensitive details
     * - Provincial staff can see all details
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isSameMunicipality = $user->hasGlobalAccess()
            || $this->home_municipality_id === $user->municipality_id;

        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'middle_name' => $this->middle_name,
            'suffix' => $this->suffix,
            'birthdate' => $this->birthdate->format('Y-m-d'),
            'age' => $this->age,
            'gender' => $this->gender,

            // Conditional fields (masked for cross-municipality viewing)
            'contact_number' => $isSameMunicipality ? $this->contact_number : '***-****-****',
            'address' => $isSameMunicipality ? $this->address : '[Hidden - Different Municipality]',
            'barangay' => $isSameMunicipality ? $this->barangay : null,
            'id_type' => $isSameMunicipality ? $this->id_type : null,
            'id_number' => $isSameMunicipality ? $this->id_number : '****',

            // Municipality info
            'home_municipality' => [
                'id' => $this->homeMunicipality->id,
                'name' => $this->homeMunicipality->name,
                'code' => $this->homeMunicipality->code,
            ],

            // Metadata
            'is_active' => $this->is_active,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
