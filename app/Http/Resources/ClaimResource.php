<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,

            // Beneficiary info
            'beneficiary' => new BeneficiaryResource($this->whenLoaded('beneficiary')),

            // Municipality info
            'municipality' => [
                'id' => $this->municipality->uuid,
                'name' => $this->municipality->name,
                'code' => $this->municipality->code,
            ],

            // Claim details
            'assistance_type' => $this->assistance_type,
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'purpose' => $this->purpose,
            'notes' => $this->notes,
            'status' => $this->status,

            // Fraud flags
            'is_flagged' => $this->is_flagged,
            'flag_reason' => $this->flag_reason,
            'risk_assessment' => $this->risk_assessment,

            // Processing info
            'processed_by' => $this->whenLoaded('processedBy', function () {
                return [
                    'id' => $this->processedBy->uuid,
                    'name' => $this->processedBy->name,
                    'role' => $this->processedBy->role,
                ];
            }),

            // Timestamps
            'approved_at' => $this->approved_at?->toIso8601String(),
            'disbursed_at' => $this->disbursed_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
