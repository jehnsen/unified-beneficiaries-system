<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApprovClaimRequest;
use App\Http\Requests\RejectClaimRequest;
use App\Http\Requests\UploadDisbursementProofRequest;
use App\Http\Resources\ClaimResource;
use App\Http\Resources\DisbursementProofResource;
use App\Interfaces\ClaimRepositoryInterface;
use App\Models\Claim;
use App\Models\DisbursementProof;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * DisbursementController - Handles claim approval, disbursement, and proof upload.
 *
 * Workflow:
 * PENDING -> UNDER_REVIEW -> APPROVED -> DISBURSED (with proof)
 */
class DisbursementController extends Controller
{
    public function __construct(
        private readonly ClaimRepositoryInterface $claimRepository
    ) {
    }

    /**
     * Approve a claim.
     *
     * @route POST /api/disbursement/claims/{id}/approve
     */
    public function approve(int $claimId, ApprovClaimRequest $request): JsonResponse
    {
        $user = auth()->user();

        try {
            $claim = Claim::findOrFail($claimId);

            // Authorization check (Municipal staff can only approve their own claims)
            if ($user->isMunicipalStaff() && $claim->municipality_id !== $user->municipality_id) {
                return response()->json([
                    'error' => 'Unauthorized. You can only approve claims from your municipality.',
                ], 403);
            }

            // Business rule check
            if (!$claim->isPending()) {
                return response()->json([
                    'error' => 'Only pending claims can be approved.',
                ], 422);
            }

            // Update claim status
            $claim = $this->claimRepository->updateStatus(
                $claimId,
                'APPROVED',
                $user->id
            );

            Log::info('Claim approved', [
                'claim_id' => $claimId,
                'approved_by' => $user->id,
            ]);

            return response()->json([
                'data' => new ClaimResource($claim->load(['beneficiary', 'municipality', 'processedBy'])),
                'message' => 'Claim approved successfully.',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to approve claim', [
                'claim_id' => $claimId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to approve claim.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reject a claim.
     *
     * @route POST /api/disbursement/claims/{id}/reject
     */
    public function reject(int $claimId, RejectClaimRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();

        try {
            $claim = Claim::findOrFail($claimId);

            // Authorization check
            if ($user->isMunicipalStaff() && $claim->municipality_id !== $user->municipality_id) {
                return response()->json([
                    'error' => 'Unauthorized. You can only reject claims from your municipality.',
                ], 403);
            }

            // Business rule check
            if ($claim->isDisbursed()) {
                return response()->json([
                    'error' => 'Cannot reject a disbursed claim.',
                ], 422);
            }

            // Update claim status
            $claim = $this->claimRepository->updateStatus(
                $claimId,
                'REJECTED',
                $user->id,
                $validated['rejection_reason']
            );

            Log::info('Claim rejected', [
                'claim_id' => $claimId,
                'rejected_by' => $user->id,
                'reason' => $validated['rejection_reason'],
            ]);

            return response()->json([
                'data' => new ClaimResource($claim->load(['beneficiary', 'municipality', 'processedBy'])),
                'message' => 'Claim rejected.',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to reject claim', [
                'claim_id' => $claimId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to reject claim.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload disbursement proof and mark claim as disbursed.
     *
     * This is the final step in the claim lifecycle.
     * Requires: Photo, Signature, GPS coordinates.
     *
     * @route POST /api/disbursement/claims/{id}/proof
     */
    public function uploadProof(int $claimId, UploadDisbursementProofRequest $request): JsonResponse
    {
        $user = auth()->user();
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            $claim = Claim::findOrFail($claimId);

            // Authorization check
            if ($user->isMunicipalStaff() && $claim->municipality_id !== $user->municipality_id) {
                return response()->json([
                    'error' => 'Unauthorized.',
                ], 403);
            }

            // Business rule check
            if (!$claim->isApproved()) {
                return response()->json([
                    'error' => 'Only approved claims can be disbursed.',
                ], 422);
            }

            // Upload files to storage (S3 or local)
            $photoPath = $request->file('photo')->store('disbursement-proofs/photos', 'public');
            $signaturePath = $request->file('signature')->store('disbursement-proofs/signatures', 'public');
            $idPhotoPath = $request->hasFile('id_photo')
                ? $request->file('id_photo')->store('disbursement-proofs/ids', 'public')
                : null;

            // Create disbursement proof record
            $proof = DisbursementProof::create([
                'claim_id' => $claimId,
                'photo_url' => $photoPath,
                'signature_url' => $signaturePath,
                'id_photo_url' => $idPhotoPath,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'location_accuracy' => $validated['location_accuracy'] ?? null,
                'captured_at' => now(),
                'captured_by_user_id' => $user->id,
                'device_info' => $request->header('User-Agent'),
                'ip_address' => $request->ip(),
            ]);

            // Mark claim as disbursed
            $claim = $this->claimRepository->markAsDisbursed($claimId, $user->id);

            DB::commit();

            Log::info('Disbursement proof uploaded', [
                'claim_id' => $claimId,
                'proof_id' => $proof->id,
                'disbursed_by' => $user->id,
            ]);

            return response()->json([
                'data' => [
                    'claim' => new ClaimResource($claim->load(['beneficiary', 'municipality'])),
                    'proof' => new DisbursementProofResource($proof),
                ],
                'message' => 'Disbursement proof uploaded successfully. Claim marked as DISBURSED.',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to upload disbursement proof', [
                'claim_id' => $claimId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to upload proof.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get disbursement proofs for a claim.
     *
     * @route GET /api/disbursement/claims/{id}/proofs
     */
    public function getProofs(int $claimId): JsonResponse
    {
        $claim = Claim::with('disbursementProofs')->findOrFail($claimId);

        return response()->json([
            'data' => DisbursementProofResource::collection($claim->disbursementProofs),
        ], 200);
    }
}
