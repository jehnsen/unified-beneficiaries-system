<?php

declare(strict_types=1);

namespace App\Services;

use App\Interfaces\BeneficiaryRepositoryInterface;
use App\Interfaces\ClaimRepositoryInterface;
use App\Models\Beneficiary;
use Illuminate\Support\Collection;

class FraudDetectionService
{
    private const RISK_THRESHOLD_DAYS = 90;
    private const SAME_TYPE_THRESHOLD_DAYS = 30;
    private const HIGH_FREQUENCY_THRESHOLD = 3; // More than 3 claims in 90 days = suspicious

    public function __construct(
        private readonly BeneficiaryRepositoryInterface $beneficiaryRepository,
        private readonly ClaimRepositoryInterface $claimRepository
    ) {
    }

    /**
     * Check if a beneficiary poses a fraud risk.
     * This is the core fraud detection engine for the Provincial Grid.
     *
     * Risk Factors:
     * 1. Same person claimed from multiple municipalities (Inter-LGU check)
     * 2. Same assistance type within 30 days (Double-dipping)
     * 3. High frequency of claims (More than 3 in 90 days)
     * 4. Phonetic name matches (Catches spelling variations)
     *
     * @return RiskAssessmentResult
     */
    public function checkRisk(
        string $firstName,
        string $lastName,
        string $birthdate,
        ?string $assistanceType = null
    ): RiskAssessmentResult {
        // Sanitize inputs
        $firstName = trim($firstName);
        $lastName = trim($lastName);

        // Step 1: Search for phonetically similar beneficiaries (catches spelling variations)
        $potentialMatches = $this->beneficiaryRepository->searchByPhonetic(
            $firstName,
            $lastName,
            $birthdate
        );

        if ($potentialMatches->isEmpty()) {
            return new RiskAssessmentResult(
                isRisky: false,
                riskLevel: 'LOW',
                details: 'No matching beneficiaries found in the Provincial Grid.'
            );
        }

        // Step 2: For each match, check their claim history across ALL municipalities
        $riskFlags = [];
        $allClaims = collect();

        foreach ($potentialMatches as $beneficiary) {
            $recentClaims = $this->claimRepository->getRecentClaimsForBeneficiary(
                $beneficiary->id,
                self::RISK_THRESHOLD_DAYS,
                null // Check all assistance types
            );

            if ($recentClaims->isNotEmpty()) {
                $allClaims = $allClaims->merge($recentClaims);

                // Risk Flag 1: Inter-LGU claims (Same person claiming from multiple towns)
                $municipalityCount = $recentClaims->pluck('municipality_id')->unique()->count();
                if ($municipalityCount > 1) {
                    $riskFlags[] = "Claimed assistance from {$municipalityCount} different municipalities";
                }

                // Risk Flag 2: Same assistance type within 30 days (Double-dipping)
                if ($assistanceType) {
                    $sameTypeClaims = $recentClaims
                        ->where('assistance_type', $assistanceType)
                        ->filter(fn($claim) => $claim->created_at->gte(now()->subDays(self::SAME_TYPE_THRESHOLD_DAYS)));

                    if ($sameTypeClaims->isNotEmpty()) {
                        $lastClaim = $sameTypeClaims->first();
                        $daysAgo = now()->diffInDays($lastClaim->created_at);
                        $municipality = $lastClaim->municipality->name ?? 'Unknown';

                        $riskFlags[] = "Received {$assistanceType} assistance {$daysAgo} days ago from {$municipality}";
                    }
                }

                // Risk Flag 3: High frequency of claims
                if ($recentClaims->count() >= self::HIGH_FREQUENCY_THRESHOLD) {
                    $riskFlags[] = "High frequency: {$recentClaims->count()} claims in the last 90 days";
                }
            }
        }

        // Determine risk level
        $riskLevel = $this->calculateRiskLevel(count($riskFlags), $allClaims);

        return new RiskAssessmentResult(
            isRisky: !empty($riskFlags),
            riskLevel: $riskLevel,
            details: empty($riskFlags)
                ? 'No fraud risk detected.'
                : implode(' | ', $riskFlags),
            matchingBeneficiaries: $potentialMatches,
            recentClaims: $allClaims
        );
    }

    /**
     * Calculate risk level based on flags and claim history.
     */
    private function calculateRiskLevel(int $flagCount, Collection $claims): string
    {
        if ($flagCount === 0) {
            return 'LOW';
        }

        if ($flagCount >= 3 || $claims->count() >= 5) {
            return 'HIGH';
        }

        return 'MEDIUM';
    }

    /**
     * Perform a comprehensive duplicate check before creating a new beneficiary.
     * This enforces the "Golden Record" principle - never create duplicates.
     */
    public function findDuplicates(
        string $firstName,
        string $lastName,
        string $birthdate
    ): Collection {
        return $this->beneficiaryRepository->searchByPhonetic(
            $firstName,
            $lastName,
            $birthdate
        );
    }

    /**
     * Check for duplicate beneficiaries using the Hybrid Search Strategy.
     * Used by fraud alert detail page to show matched results.
     *
     * @return array
     */
    public function checkDuplicates(
        string $firstName,
        string $lastName,
        string $birthdate,
        ?int $excludeBeneficiaryId = null
    ): array {
        // Use phonetic search to find potential matches
        $potentialMatches = $this->beneficiaryRepository->searchByPhonetic(
            $firstName,
            $lastName,
            $birthdate
        );

        // Exclude the current beneficiary if specified
        if ($excludeBeneficiaryId) {
            $potentialMatches = $potentialMatches->filter(
                fn($b) => $b->id !== $excludeBeneficiaryId
            );
        }

        // Calculate similarity scores using Levenshtein distance
        $matches = [];
        $searchName = strtolower(trim($firstName . ' ' . $lastName));

        foreach ($potentialMatches as $beneficiary) {
            $matchName = strtolower(trim($beneficiary->first_name . ' ' . $beneficiary->last_name));
            $distance = levenshtein($searchName, $matchName);

            // Flag as risk if distance < 3 (very similar names)
            if ($distance < 5) {
                $matches[] = [
                    'beneficiary' => $beneficiary,
                    'similarity_score' => max(0, 100 - ($distance * 10)), // Convert distance to percentage
                    'levenshtein_distance' => $distance,
                ];
            }
        }

        // Sort by similarity score (highest first)
        usort($matches, fn($a, $b) => $b['similarity_score'] <=> $a['similarity_score']);

        // Determine risk level
        $riskLevel = 'LOW';
        if (count($matches) > 0) {
            $highestScore = $matches[0]['similarity_score'];
            if ($highestScore >= 90 || count($matches) >= 3) {
                $riskLevel = 'HIGH';
            } elseif ($highestScore >= 70 || count($matches) >= 2) {
                $riskLevel = 'MEDIUM';
            }
        }

        return [
            'matches' => $matches,
            'risk_level' => $riskLevel,
            'total_matches' => count($matches),
        ];
    }

    /**
     * Generate a detailed fraud risk report for a specific beneficiary.
     */
    public function generateRiskReport(int $beneficiaryId): array
    {
        $beneficiary = $this->beneficiaryRepository->findById($beneficiaryId);

        if (!$beneficiary) {
            return ['error' => 'Beneficiary not found'];
        }

        $claims = $this->claimRepository->getRecentClaimsForBeneficiary(
            $beneficiaryId,
            self::RISK_THRESHOLD_DAYS
        );

        $municipalities = $claims->pluck('municipality.name')->unique()->values()->toArray();
        $assistanceTypes = $claims->pluck('assistance_type')->unique()->values()->toArray();
        $totalAmount = $claims->sum('amount');

        return [
            'beneficiary' => [
                'id' => $beneficiary->id,
                'name' => $beneficiary->first_name . ' ' . $beneficiary->last_name,
                'birthdate' => $beneficiary->birthdate,
                'home_municipality' => $beneficiary->homeMunicipality->name ?? 'Unknown',
            ],
            'risk_summary' => [
                'total_claims' => $claims->count(),
                'municipalities_involved' => count($municipalities),
                'municipality_names' => $municipalities,
                'assistance_types' => $assistanceTypes,
                'total_amount_received' => $totalAmount,
                'risk_level' => $this->calculateRiskLevel(
                    count($municipalities) > 1 ? 2 : 0,
                    $claims
                ),
            ],
            'recent_claims' => $claims->map(function ($claim) {
                return [
                    'id' => $claim->id,
                    'municipality' => $claim->municipality->name ?? 'Unknown',
                    'assistance_type' => $claim->assistance_type,
                    'amount' => $claim->amount,
                    'status' => $claim->status,
                    'date' => $claim->created_at->format('Y-m-d'),
                    'days_ago' => $claim->created_at->diffInDays(now()),
                ];
            })->toArray(),
        ];
    }
}

/**
 * Value object for risk assessment results.
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
