<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Models\Claim;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ClaimRepositoryInterface
{
    /**
     * Paginated list with Spatie QueryBuilder filtering.
     * TenantScope applies automatically for municipal staff.
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    /**
     * Create a new claim.
     */
    public function create(array $data): Claim;

    /**
     * Find claim by ID.
     */
    public function findById(int $id): ?Claim;

    /**
     * Get recent claims for a beneficiary across ALL municipalities.
     * Critical for fraud detection - checks if the same person received assistance recently.
     *
     * @param int $beneficiaryId
     * @param int $days Look back period
     * @param string|null $assistanceType Specific type to check (null = all types)
     * @return Collection<Claim>
     */
    public function getRecentClaimsForBeneficiary(
        int $beneficiaryId,
        int $days = 90,
        ?string $assistanceType = null
    ): Collection;

    /**
     * Get all claims for a specific municipality.
     */
    public function getByMunicipality(int $municipalityId, ?string $status = null): Collection;

    /**
     * Update claim status with audit trail.
     */
    public function updateStatus(int $claimId, string $status, int $processedByUserId, ?string $reason = null): Claim;

    /**
     * Get flagged claims for review.
     */
    public function getFlaggedClaims(int $municipalityId): Collection;

    /**
     * Mark claim as disbursed with timestamp.
     */
    public function markAsDisbursed(int $claimId, int $userId): Claim;
}
