<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Interfaces\ClaimRepositoryInterface;
use App\Models\Claim;
use App\Models\Municipality;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class EloquentClaimRepository implements ClaimRepositoryInterface
{
    /**
     * Paginated list with filtering. TenantScope applies automatically.
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return QueryBuilder::for(Claim::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('assistance_type'),
                AllowedFilter::exact('municipality_id'),
                AllowedFilter::exact('beneficiary_id'),
                AllowedFilter::exact('is_flagged'),
            ])
            ->allowedSorts(['created_at', 'amount', 'status', 'assistance_type'])
            ->allowedIncludes(['beneficiary', 'municipality', 'processedBy', 'disbursementProofs'])
            ->defaultSort('-created_at')
            ->with(['beneficiary.homeMunicipality', 'municipality'])
            ->paginate($perPage);
    }

    /**
     * Create a new claim.
     */
    public function create(array $data): Claim
    {
        return Claim::create($data);
    }

    /**
     * Find claim by ID.
     */
    public function findById(int $id): ?Claim
    {
        return Claim::with(['beneficiary', 'municipality', 'processedBy'])->find($id);
    }

    /**
     * Find claim by UUID (public-facing identifier).
     */
    public function findByUuid(string $uuid): ?Claim
    {
        return Claim::with(['beneficiary', 'municipality', 'processedBy'])
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * Get recent claims for a beneficiary across ALL municipalities.
     * Critical for fraud detection - ignores tenant scope.
     */
    public function getRecentClaimsForBeneficiary(
        int $beneficiaryId,
        int $days = 90,
        ?string $assistanceType = null
    ): Collection {
        $query = Claim::where('beneficiary_id', $beneficiaryId)
            ->where('created_at', '>=', now()->subDays($days))
            ->whereIn('status', ['APPROVED', 'DISBURSED', 'PENDING', 'UNDER_REVIEW']);

        if ($assistanceType) {
            $query->where('assistance_type', $assistanceType);
        }

        return $query->with('municipality')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get all claims for a specific municipality.
     */
    public function getByMunicipality(int $municipalityId, ?string $status = null): Collection
    {
        $query = Claim::where('municipality_id', $municipalityId);

        if ($status) {
            $query->where('status', $status);
        }

        return $query->with(['beneficiary', 'processedBy'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Update claim status with audit trail.
     */
    public function updateStatus(int $claimId, string $status, int $processedByUserId, ?string $reason = null): Claim
    {
        $claim = Claim::findOrFail($claimId);

        $updateData = [
            'status' => $status,
            'processed_by_user_id' => $processedByUserId,
        ];

        // Set appropriate timestamp based on status
        switch ($status) {
            case 'APPROVED':
                $updateData['approved_at'] = now();
                break;
            case 'REJECTED':
            case 'CANCELLED':
                $updateData['rejected_at'] = now();
                if ($reason) {
                    $updateData['rejection_reason'] = $reason;
                }
                break;
            case 'DISBURSED':
                $updateData['disbursed_at'] = now();
                break;
        }

        $claim->update($updateData);

        return $claim->fresh();
    }

    /**
     * Get flagged claims for review.
     *
     * Provincial staff (municipalityId = 0) can see ALL flagged claims.
     * Municipal staff only see their own municipality's flagged claims.
     */
    public function getFlaggedClaims(int $municipalityId): Collection
    {
        $query = Claim::query()
            ->where('is_flagged', true)
            ->whereIn('status', ['PENDING', 'UNDER_REVIEW'])
            ->with(['beneficiary', 'municipality', 'processedBy'])
            ->orderBy('created_at', 'desc');

        // Filter by municipality for municipal staff only
        if ($municipalityId > 0) {
            $query->where('municipality_id', $municipalityId);
        }

        return $query->get();
    }

    /**
     * Mark claim as disbursed and atomically increment the municipality's used_budget.
     *
     * The lockForUpdate() on Municipality prevents a race condition where two concurrent
     * disbursements for the same municipality could both read the same used_budget value
     * and overwrite each other — using increment() is atomic at the DB level, but the
     * lock ensures the claim's own amount is stable before we read it.
     *
     * This method is always called from within a DB::transaction() in DisbursementController,
     * so the lock will be released automatically when the outer transaction commits or rolls back.
     */
    public function markAsDisbursed(int $claimId, int $userId): Claim
    {
        $claim = $this->updateStatus($claimId, 'DISBURSED', $userId);

        // Lock the municipality row to prevent concurrent disbursements from producing
        // a stale used_budget (e.g., two officers disbursing different claims simultaneously).
        Municipality::where('id', $claim->municipality_id)
            ->lockForUpdate()
            ->firstOrFail();

        Municipality::where('id', $claim->municipality_id)
            ->increment('used_budget', $claim->amount);

        return $claim;
    }

    /**
     * Write back the async fraud-check result onto a PENDING_FRAUD_CHECK claim.
     *
     * The idempotency guard (`where status = PENDING_FRAUD_CHECK`) ensures that if the
     * job is retried after a partial failure, a second write cannot overwrite a status
     * that has already moved forward (e.g. manually set to PENDING by an admin).
     */
    public function updateFraudResult(int $claimId, bool $isRisky, ?string $flagReason, array $riskAssessment): Claim
    {
        $claim = Claim::findOrFail($claimId);

        // Guard: only update if still in the transient holding state.
        if ($claim->status !== 'PENDING_FRAUD_CHECK') {
            return $claim->fresh();
        }

        $claim->update([
            'status' => 'PENDING',
            'is_flagged' => $isRisky,
            'flag_reason' => $isRisky ? $flagReason : null,
            'risk_assessment' => $riskAssessment,
        ]);

        return $claim->fresh();
    }
}
