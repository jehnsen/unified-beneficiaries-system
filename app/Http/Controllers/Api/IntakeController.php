<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CheckDuplicateRequest;
use App\Http\Requests\RevokePairRequest;
use App\Http\Requests\StoreClaimRequest;
use App\Http\Requests\WhitelistPairRequest;
use App\Http\Resources\BeneficiaryResource;
use App\Http\Resources\ClaimResource;
use App\Interfaces\BeneficiaryRepositoryInterface;
use App\Interfaces\ClaimRepositoryInterface;
use App\Interfaces\VerifiedDistinctPairRepositoryInterface;
use App\Models\Beneficiary;
use App\Models\VerifiedDistinctPair;
use App\Services\FraudDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        private readonly FraudDetectionService $fraudService,
        private readonly VerifiedDistinctPairRepositoryInterface $verifiedPairRepository
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

    /**
     * Whitelist a pair of beneficiaries as verified distinct or duplicate.
     *
     * This endpoint allows admins to manually verify that two phonetically similar
     * beneficiaries are actually distinct (different people) or duplicates (same person).
     * Once whitelisted as VERIFIED_DISTINCT, the fraud detection system will stop
     * flagging this pair.
     *
     * Authorization:
     * - Provincial staff: Can whitelist any pair
     * - Municipal staff: Can only whitelist pairs where AT LEAST ONE beneficiary
     *   belongs to their municipality
     *
     * @route POST /api/intake/whitelist-pair
     */
    public function whitelistPair(WhitelistPairRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        try {
            DB::beginTransaction();

            // Fetch beneficiaries by UUID
            $beneficiaryA = Beneficiary::where('uuid', $validated['beneficiary_a_uuid'])->firstOrFail();
            $beneficiaryB = Beneficiary::where('uuid', $validated['beneficiary_b_uuid'])->firstOrFail();

            // AUTHORIZATION CHECK
            if ($user->isMunicipalStaff()) {
                $userMunicipalityId = $user->municipality_id;

                // Municipal staff can only whitelist if at least one beneficiary is from their municipality
                $hasAccess = $beneficiaryA->home_municipality_id === $userMunicipalityId
                          || $beneficiaryB->home_municipality_id === $userMunicipalityId;

                if (!$hasAccess) {
                    return response()->json([
                        'error' => 'Authorization denied. You can only whitelist beneficiaries from your municipality.',
                    ], 403);
                }
            }

            // Check if pair already exists
            $existingPair = $this->verifiedPairRepository->findPair($beneficiaryA->id, $beneficiaryB->id);

            if ($existingPair && $existingPair->isActive()) {
                return response()->json([
                    'error' => 'This pair has already been verified.',
                    'data' => [
                        'existing_status' => $existingPair->verification_status,
                        'verified_at' => $existingPair->verified_at->toIso8601String(),
                        'verified_by' => $existingPair->verifiedBy->name,
                    ],
                ], 409); // Conflict
            }

            // Create the verified pair
            $pair = $this->verifiedPairRepository->create([
                'beneficiary_a_id' => $beneficiaryA->id,
                'beneficiary_b_id' => $beneficiaryB->id,
                'verification_status' => $validated['verification_status'],
                'verification_reason' => $validated['verification_reason'],
                'notes' => $validated['notes'] ?? null,
                'similarity_score' => $validated['similarity_score'] ?? null,
                'levenshtein_distance' => $validated['levenshtein_distance'] ?? null,
                'verified_by_user_id' => $user->id,
                'verified_at' => now(),
            ]);

            DB::commit();

            Log::info('Beneficiary pair verified', [
                'pair_id' => $pair->id,
                'beneficiary_a_id' => $beneficiaryA->id,
                'beneficiary_b_id' => $beneficiaryB->id,
                'status' => $pair->verification_status,
                'verified_by' => $user->id,
            ]);

            return response()->json([
                'data' => [
                    'pair_id' => $pair->uuid,
                    'beneficiary_a' => [
                        'uuid' => $beneficiaryA->uuid,
                        'name' => $beneficiaryA->first_name . ' ' . $beneficiaryA->last_name,
                    ],
                    'beneficiary_b' => [
                        'uuid' => $beneficiaryB->uuid,
                        'name' => $beneficiaryB->first_name . ' ' . $beneficiaryB->last_name,
                    ],
                    'verification_status' => $pair->verification_status,
                    'verified_at' => $pair->verified_at->toIso8601String(),
                    'verified_by' => $user->name,
                ],
                'message' => 'Beneficiary pair successfully verified.',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to whitelist pair', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to verify pair.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Revoke a verified pair (undo whitelist).
     *
     * This removes the whitelist, allowing the fraud detection system to
     * flag this pair again if they appear to be duplicates.
     *
     * Authorization:
     * - Provincial staff: Can revoke any pair
     * - Municipal staff: Can only revoke pairs where at least one beneficiary
     *   belongs to their municipality
     *
     * @route DELETE /api/intake/whitelist-pair/{pair:uuid}
     */
    public function revokePair(RevokePairRequest $request, VerifiedDistinctPair $pair): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        // AUTHORIZATION CHECK
        if ($user->isMunicipalStaff()) {
            $userMunicipalityId = $user->municipality_id;

            // Load beneficiaries if not already loaded
            $beneficiaryA = $pair->beneficiaryA ?? Beneficiary::find($pair->beneficiary_a_id);
            $beneficiaryB = $pair->beneficiaryB ?? Beneficiary::find($pair->beneficiary_b_id);

            $hasAccess = $beneficiaryA->home_municipality_id === $userMunicipalityId
                      || $beneficiaryB->home_municipality_id === $userMunicipalityId;

            if (!$hasAccess) {
                return response()->json([
                    'error' => 'Authorization denied.',
                ], 403);
            }
        }

        // Revoke the pair
        $success = $this->verifiedPairRepository->revoke(
            $pair->id,
            $user->id,
            $validated['revocation_reason']
        );

        if ($success) {
            Log::info('Verified pair revoked', [
                'pair_id' => $pair->id,
                'revoked_by' => $user->id,
            ]);

            return response()->json([
                'message' => 'Pair verification revoked successfully.',
            ], 200);
        }

        return response()->json([
            'error' => 'Failed to revoke pair verification.',
        ], 500);
    }

    /**
     * Get list of verified pairs (admin view).
     *
     * Returns a paginated list of all verified beneficiary pairs.
     * Municipal staff only see pairs involving their municipality.
     *
     * @route GET /api/intake/verified-pairs
     */
    public function getVerifiedPairs(Request $request): JsonResponse
    {
        $user = auth()->user();
        $perPage = (int) $request->input('per_page', 15);
        $status = $request->input('status');

        // Get paginated pairs
        $pairs = $this->verifiedPairRepository->paginate($perPage, $status);

        // Filter by municipality for municipal staff
        if ($user->isMunicipalStaff()) {
            $pairs->getCollection()->transform(function ($pair) use ($user) {
                // Only include pairs where at least one beneficiary is from their municipality
                if ($pair->beneficiaryA->home_municipality_id === $user->municipality_id
                    || $pair->beneficiaryB->home_municipality_id === $user->municipality_id) {
                    return $pair;
                }
                return null;
            })->filter(); // Remove nulls
        }

        return response()->json([
            'data' => $pairs->map(function ($pair) {
                return [
                    'pair_id' => $pair->uuid,
                    'beneficiary_a' => [
                        'uuid' => $pair->beneficiaryA->uuid,
                        'name' => $pair->beneficiaryA->first_name . ' ' . $pair->beneficiaryA->last_name,
                        'municipality' => $pair->beneficiaryA->homeMunicipality->name,
                    ],
                    'beneficiary_b' => [
                        'uuid' => $pair->beneficiaryB->uuid,
                        'name' => $pair->beneficiaryB->first_name . ' ' . $pair->beneficiaryB->last_name,
                        'municipality' => $pair->beneficiaryB->homeMunicipality->name,
                    ],
                    'verification_status' => $pair->verification_status,
                    'similarity_score' => $pair->similarity_score,
                    'verified_at' => $pair->verified_at->toIso8601String(),
                    'verified_by' => $pair->verifiedBy->name,
                ];
            }),
            'meta' => [
                'current_page' => $pairs->currentPage(),
                'total' => $pairs->total(),
                'per_page' => $pairs->perPage(),
            ],
        ], 200);
    }
}
