<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckDuplicateRequest;
use App\Http\Requests\StoreClaimRequest;
use App\Http\Resources\BeneficiaryResource;
use App\Http\Resources\ClaimResource;
use App\Interfaces\BeneficiaryRepositoryInterface;
use App\Interfaces\ClaimRepositoryInterface;
use App\Models\Beneficiary;
use App\Services\FraudDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * IntakeController - Handles the "Search & Assess" workflow.
 *
 * This is the entry point for processing new assistance requests.
 * Flow: Check Duplicates -> Assess Fraud Risk -> Create/Link Beneficiary -> Create Claim
 */
class IntakeController extends Controller
{
    public function __construct(
        private readonly BeneficiaryRepositoryInterface $beneficiaryRepository,
        private readonly ClaimRepositoryInterface $claimRepository,
        private readonly FraudDetectionService $fraudService
    ) {
    }

    /**
     * Step 1: Check for duplicate beneficiaries (Golden Record enforcement).
     *
     * This searches the ENTIRE Provincial Grid using phonetic matching.
     * Used BEFORE creating a new beneficiary to prevent duplicates.
     *
     * @route POST /api/intake/check-duplicate
     */
    public function checkDuplicate(CheckDuplicateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Search across ALL municipalities for phonetic matches
        $potentialMatches = $this->fraudService->findDuplicates(
            $validated['first_name'],
            $validated['last_name'],
            $validated['birthdate']
        );

        if ($potentialMatches->isEmpty()) {
            return response()->json([
                'data' => [
                    'has_duplicates' => false,
                    'message' => 'No existing beneficiary found. Safe to create new record.',
                    'matches' => [],
                ],
            ], 200);
        }

        return response()->json([
            'data' => [
                'has_duplicates' => true,
                'message' => 'Potential duplicate(s) found in the Provincial Grid.',
                'matches' => BeneficiaryResource::collection($potentialMatches),
            ],
        ], 200);
    }

    /**
     * Step 2: Assess fraud risk for a beneficiary.
     *
     * Checks:
     * - Inter-LGU claims (same person claiming from multiple municipalities)
     * - Double-dipping (same assistance type within 30 days)
     * - High frequency claims
     *
     * @route POST /api/intake/assess-risk
     */
    public function assessRisk(CheckDuplicateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $riskResult = $this->fraudService->checkRisk(
            $validated['first_name'],
            $validated['last_name'],
            $validated['birthdate'],
            $validated['assistance_type'] ?? null
        );

        return response()->json([
            'data' => $riskResult->toArray(),
        ], 200);
    }

    /**
     * Step 3: Create a new claim (with automatic fraud detection).
     *
     * Flow:
     * 1. Find or create beneficiary (Golden Record)
     * 2. Run fraud detection
     * 3. Create claim with risk flags
     * 4. Return claim details
     *
     * @route POST /api/intake/claims
     */
    public function storeClaim(StoreClaimRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            // Step 1: Find or create beneficiary (Golden Record pattern)
            $beneficiary = $this->beneficiaryRepository->findOrCreate([
                'home_municipality_id' => $validated['home_municipality_id'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'middle_name' => $validated['middle_name'] ?? null,
                'suffix' => $validated['suffix'] ?? null,
                'birthdate' => $validated['birthdate'],
                'gender' => $validated['gender'],
                'contact_number' => $validated['contact_number'] ?? null,
                'address' => $validated['address'] ?? null,
                'barangay' => $validated['barangay'] ?? null,
                'id_type' => $validated['id_type'] ?? null,
                'id_number' => $validated['id_number'] ?? null,
                'created_by' => auth()->id(),
            ]);

            // Step 2: Run fraud detection
            $riskResult = $this->fraudService->checkRisk(
                $beneficiary->first_name,
                $beneficiary->last_name,
                $beneficiary->birthdate->format('Y-m-d'),
                $validated['assistance_type']
            );

            // Step 3: Create claim with risk assessment
            $claim = $this->claimRepository->create([
                'beneficiary_id' => $beneficiary->id,
                'municipality_id' => auth()->user()->municipality_id ?? $validated['municipality_id'],
                'assistance_type' => $validated['assistance_type'],
                'amount' => $validated['amount'],
                'purpose' => $validated['purpose'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'status' => 'PENDING',
                'is_flagged' => $riskResult->isRisky,
                'flag_reason' => $riskResult->isRisky ? $riskResult->details : null,
                'risk_assessment' => $riskResult->toArray(),
            ]);

            DB::commit();

            Log::info('Claim created', [
                'claim_id' => $claim->id,
                'beneficiary_id' => $beneficiary->id,
                'is_flagged' => $claim->is_flagged,
                'risk_level' => $riskResult->riskLevel,
            ]);

            return response()->json([
                'data' => new ClaimResource($claim->load(['beneficiary', 'municipality'])),
                'message' => $claim->is_flagged
                    ? 'Claim created but flagged for review due to fraud risk.'
                    : 'Claim created successfully.',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create claim', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to create claim.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get detailed fraud risk report for a specific beneficiary.
     * Route model binding automatically injects the beneficiary via UUID.
     *
     * @route GET /api/intake/beneficiaries/{beneficiary:uuid}/risk-report
     */
    public function getRiskReport(Beneficiary $beneficiary): JsonResponse
    {
        // Laravel automatically injects the model via UUID route binding
        $report = $this->fraudService->generateRiskReport($beneficiary->id);

        if (isset($report['error'])) {
            return response()->json([
                'error' => $report['error'],
            ], 404);
        }

        return response()->json([
            'data' => $report,
        ], 200);
    }

    /**
     * Get flagged claims for review (Municipal view).
     *
     * @route GET /api/intake/flagged-claims
     */
    public function getFlaggedClaims(): JsonResponse
    {
        $user = auth()->user();

        if ($user->isMunicipalStaff()) {
            $flaggedClaims = $this->claimRepository->getFlaggedClaims($user->municipality_id);
        } else {
            // Provincial staff can see all flagged claims
            $flaggedClaims = $this->claimRepository->getFlaggedClaims(0);
        }

        return response()->json([
            'data' => ClaimResource::collection($flaggedClaims),
        ], 200);
    }
}
