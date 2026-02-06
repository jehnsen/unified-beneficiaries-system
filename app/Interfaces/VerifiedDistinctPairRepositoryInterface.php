<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Models\VerifiedDistinctPair;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * VerifiedDistinctPairRepositoryInterface
 *
 * Repository interface for managing verified beneficiary pairs (whitelist/blacklist).
 * Handles bidirectional pair lookups and verification status management.
 */
interface VerifiedDistinctPairRepositoryInterface
{
    /**
     * Find a pair of beneficiaries (handles bidirectional lookup).
     *
     * Automatically normalizes the order, so findPair(5, 10) === findPair(10, 5).
     * Only returns active pairs (VERIFIED_DISTINCT or VERIFIED_DUPLICATE status).
     *
     * @param int $beneficiaryAId First beneficiary ID
     * @param int $beneficiaryBId Second beneficiary ID
     * @return VerifiedDistinctPair|null The pair if found, null otherwise
     */
    public function findPair(int $beneficiaryAId, int $beneficiaryBId): ?VerifiedDistinctPair;

    /**
     * Create a new verified pair.
     *
     * The model's boot method will automatically normalize the pair order
     * (smaller ID first) and generate UUID.
     *
     * @param array $data Pair data (beneficiary_a_id, beneficiary_b_id, verification_status, etc.)
     * @return VerifiedDistinctPair The created pair
     */
    public function create(array $data): VerifiedDistinctPair;

    /**
     * Get all verified pairs for a beneficiary (bidirectional).
     *
     * Returns all pairs where the beneficiary is either A or B.
     *
     * @param int $beneficiaryId The beneficiary ID
     * @param string|null $status Optional status filter (e.g., 'VERIFIED_DISTINCT')
     * @return Collection Collection of VerifiedDistinctPair models
     */
    public function getPairsForBeneficiary(int $beneficiaryId, ?string $status = null): Collection;

    /**
     * Revoke a verification (change status to REVOKED).
     *
     * @param int $pairId The pair ID
     * @param int $userId The user performing the revocation
     * @param string $reason The reason for revocation
     * @return bool True if successful
     */
    public function revoke(int $pairId, int $userId, string $reason): bool;

    /**
     * Get paginated list of all pairs with optional status filter.
     *
     * @param int $perPage Number of results per page
     * @param string|null $status Optional status filter
     * @return LengthAwarePaginator Paginated results
     */
    public function paginate(int $perPage = 15, ?string $status = null): LengthAwarePaginator;

    /**
     * Get the verification status of a pair.
     *
     * Returns null if pair doesn't exist.
     *
     * @param int $beneficiaryAId First beneficiary ID
     * @param int $beneficiaryBId Second beneficiary ID
     * @return string|null The verification status or null
     */
    public function getPairStatus(int $beneficiaryAId, int $beneficiaryBId): ?string;
}
