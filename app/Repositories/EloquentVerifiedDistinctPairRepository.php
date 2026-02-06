<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Interfaces\VerifiedDistinctPairRepositoryInterface;
use App\Models\VerifiedDistinctPair;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * EloquentVerifiedDistinctPairRepository
 *
 * Eloquent implementation of the VerifiedDistinctPairRepositoryInterface.
 * Handles all database operations for verified beneficiary pairs.
 */
class EloquentVerifiedDistinctPairRepository implements VerifiedDistinctPairRepositoryInterface
{
    /**
     * Find a pair of beneficiaries (bidirectional lookup).
     *
     * CRITICAL: This method handles bidirectional lookup automatically.
     * It normalizes the pair order before querying, so (5, 10) and (10, 5)
     * both search for the same record.
     *
     * @param int $beneficiaryAId First beneficiary ID
     * @param int $beneficiaryBId Second beneficiary ID
     * @return VerifiedDistinctPair|null The pair if found
     */
    public function findPair(int $beneficiaryAId, int $beneficiaryBId): ?VerifiedDistinctPair
    {
        // Normalize order for efficient lookup (smaller ID first)
        [$smallerId, $largerId] = $beneficiaryAId < $beneficiaryBId
            ? [$beneficiaryAId, $beneficiaryBId]
            : [$beneficiaryBId, $beneficiaryAId];

        return VerifiedDistinctPair::where('beneficiary_a_id', $smallerId)
            ->where('beneficiary_b_id', $largerId)
            ->whereIn('verification_status', ['VERIFIED_DISTINCT', 'VERIFIED_DUPLICATE'])
            ->first();
    }

    /**
     * Create a new verified pair.
     *
     * The model's boot method automatically:
     * - Normalizes the pair order (smaller ID first)
     * - Generates UUID
     * - Sets verified_at timestamp
     *
     * @param array $data Pair data
     * @return VerifiedDistinctPair The created pair
     */
    public function create(array $data): VerifiedDistinctPair
    {
        return VerifiedDistinctPair::create($data);
    }

    /**
     * Get all verified pairs for a beneficiary (bidirectional).
     *
     * Returns all pairs where the beneficiary appears as either A or B.
     *
     * @param int $beneficiaryId The beneficiary ID
     * @param string|null $status Optional status filter
     * @return Collection Collection of pairs
     */
    public function getPairsForBeneficiary(int $beneficiaryId, ?string $status = null): Collection
    {
        $query = VerifiedDistinctPair::where(function ($q) use ($beneficiaryId) {
            $q->where('beneficiary_a_id', $beneficiaryId)
              ->orWhere('beneficiary_b_id', $beneficiaryId);
        });

        if ($status) {
            $query->where('verification_status', $status);
        }

        return $query->with(['beneficiaryA', 'beneficiaryB', 'verifiedBy'])
            ->orderBy('verified_at', 'desc')
            ->get();
    }

    /**
     * Revoke a verification.
     *
     * Changes the status to REVOKED and records who revoked it and when.
     *
     * @param int $pairId The pair ID
     * @param int $userId The user performing revocation
     * @param string $reason The revocation reason
     * @return bool True if successful
     */
    public function revoke(int $pairId, int $userId, string $reason): bool
    {
        $pair = VerifiedDistinctPair::findOrFail($pairId);

        return (bool) $pair->update([
            'verification_status' => 'REVOKED',
            'revoked_by_user_id' => $userId,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);
    }

    /**
     * Get paginated list of all pairs.
     *
     * @param int $perPage Number of results per page
     * @param string|null $status Optional status filter
     * @return LengthAwarePaginator Paginated results
     */
    public function paginate(int $perPage = 15, ?string $status = null): LengthAwarePaginator
    {
        $query = VerifiedDistinctPair::with(['beneficiaryA', 'beneficiaryB', 'verifiedBy']);

        if ($status) {
            $query->where('verification_status', $status);
        }

        return $query->latest('verified_at')->paginate($perPage);
    }

    /**
     * Get the verification status of a pair.
     *
     * @param int $beneficiaryAId First beneficiary ID
     * @param int $beneficiaryBId Second beneficiary ID
     * @return string|null The status or null if not found
     */
    public function getPairStatus(int $beneficiaryAId, int $beneficiaryBId): ?string
    {
        $pair = $this->findPair($beneficiaryAId, $beneficiaryBId);
        return $pair?->verification_status;
    }
}
