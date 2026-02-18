<?php

declare(strict_types=1);

namespace App\DTOs;

use Illuminate\Support\Collection;

/**
 * Value object for risk assessment results.
 *
 * Encapsulates the outcome of fraud detection analysis,
 * providing a typed structure for risk level, details,
 * and related beneficiary/claim data.
 */
class RiskAssessmentResult
{
    public function __construct(
        public readonly bool $isRisky,
        public readonly string $riskLevel,
        public readonly string $details,
        public readonly ?Collection $matchingBeneficiaries = null,
        public readonly ?Collection $recentClaims = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'is_risky' => $this->isRisky,
            'risk_level' => $this->riskLevel,
            'details' => $this->details,
            'matching_beneficiaries_count' => $this->matchingBeneficiaries?->count() ?? 0,
            'recent_claims_count' => $this->recentClaims?->count() ?? 0,
        ];
    }
}
