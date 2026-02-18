<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveClaimRequest;
use App\Http\Requests\MarkUnderReviewRequest;
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
     * Route model binding automatically injects the claim via UUID.
     *
     * @route POST /api/disbursement/claims/{claim:uuid}/approve
     */
    public function approve(Claim $claim, ApproveClaimRequest $request): JsonResponse
    {
        // Laravel automatically injects the model via UUID route binding
        $user = auth()->user();

        try {
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
                $claim->id,
                'APPROVED',
                $user->id
            );

            Log::info('Claim approved', [
                'claim_id' => $claim->id,
                'approved_by' => $user->id,
            ]);

            return response()->json([
                'data' => new ClaimResource($claim->load(['beneficiary', 'municipality', 'processedBy'])),
                'message' => 'Claim approved successfully.',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to approve claim', [
                'claim_id' => $claim->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to approve claim.',
                // Only surface raw exception details in local/debug mode.
                // In production this would leak SQL errors, file paths, or stack traces.
                'message' => config('app.debug') ? $e->getMessage() : 'An internal error occurred.',
            ], 500);
        }
    }

    /**
     * Reject a claim.
     * Route model binding automatically injects the claim via UUID.
     *
     * @route POST /api/disbursement/claims/{claim:uuid}/reject
     */
    public function reject(Claim $claim, RejectClaimRequest $request): JsonResponse
    {
        // Laravel automatically injects the model via UUID route binding
        $user = auth()->user();
        $validated = $request->validated();

        try {
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
                $claim->id,
                'REJECTED',
                $user->id,
                $validated['rejection_reason']
            );

            Log::info('Claim rejected', [
                'claim_id' => $claim->id,
                'rejected_by' => $user->id,
                'reason' => $validated['rejection_reason'],
            ]);

            return response()->json([
                'data' => new ClaimResource($claim->load(['beneficiary', 'municipality', 'processedBy'])),
                'message' => 'Claim rejected.',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to reject claim', [
                'claim_id' => $claim->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to reject claim.',
                // Only surface raw exception details in local/debug mode.
                // In production this would leak SQL errors, file paths, or stack traces.
                'message' => config('app.debug') ? $e->getMessage() : 'An internal error occurred.',
            ], 500);
        }
    }

    /**
     * Upload disbursement proof and mark claim as disbursed.
     * Route model binding automatically injects the claim via UUID.
     *
     * This is the final step in the claim lifecycle.
     * Requires: Photo, Signature, GPS coordinates.
     *
     * @route POST /api/disbursement/claims/{claim:uuid}/proof
     */
    public function uploadProof(Claim $claim, UploadDisbursementProofRequest $request): JsonResponse
    {
        // Laravel automatically injects the model via UUID route binding
        $user = auth()->user();
        $validated = $request->validated();

        try {
            DB::beginTransaction();

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

            // Upload files to the private (default) disk, NOT the public disk.
            // These are government ID documents and biometric signatures — they must
            // never be directly accessible via a guessable public URL.
            $photoPath = $request->file('photo')->store('disbursement-proofs/photos');
            $signaturePath = $request->file('signature')->store('disbursement-proofs/signatures');
            $idPhotoPath = $request->hasFile('id_photo')
                ? $request->file('id_photo')->store('disbursement-proofs/ids')
                : null;

            // Create disbursement proof record
            $proof = DisbursementProof::create([
                'claim_id' => $claim->id,
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
            $claim = $this->claimRepository->markAsDisbursed($claim->id, $user->id);

            DB::commit();

            Log::info('Disbursement proof uploaded', [
                'claim_id' => $claim->id,
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
                'claim_id' => $claim->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to upload proof.',
                // Only surface raw exception details in local/debug mode.
                // In production this would leak SQL errors, file paths, or stack traces.
                'message' => config('app.debug') ? $e->getMessage() : 'An internal error occurred.',
            ], 500);
        }
    }

    /**
     * Place a claim under manual review.
     * Route model binding automatically injects the claim via UUID.
     *
     * This is the missing step between PENDING and APPROVED: a reviewer who
     * needs extra time or has questions can hold a claim in UNDER_REVIEW without
     * either approving or rejecting it. The fraud alert assign endpoint also
     * transitions to UNDER_REVIEW, but only for flagged claims. This endpoint
     * handles the general case (flagged or not).
     *
     * @route POST /api/disbursement/claims/{claim:uuid}/review
     */
    public function markUnderReview(Claim $claim, MarkUnderReviewRequest $request): JsonResponse
    {
        $user = auth()->user();

        if ($user->isMunicipalStaff() && $claim->municipality_id !== $user->municipality_id) {
            return response()->json([
                'error' => 'Unauthorized. You can only manage claims from your municipality.',
            ], 403);
        }

        // Only claims that are currently PENDING or PENDING_FRAUD_CHECK can move to UNDER_REVIEW.
        // Approved, disbursed, or rejected claims must not be put back into the review queue.
        if (!$claim->isPending() && !$claim->isPendingFraudCheck()) {
            return response()->json([
                'error' => 'Only pending claims can be placed under review.',
                'current_status' => $claim->status,
            ], 422);
        }

        $updateData = ['status' => 'UNDER_REVIEW'];

        if ($request->filled('notes')) {
            $updateData['notes'] = $request->input('notes');
        }

        $claim->update($updateData);

        Log::info('Claim placed under review', [
            'claim_id' => $claim->id,
            'reviewed_by' => $user->id,
        ]);

        return response()->json([
            'data' => new ClaimResource($claim->fresh()->load(['beneficiary', 'municipality', 'processedBy'])),
            'message' => 'Claim placed under review.',
        ], 200);
    }

    /**
     * Get disbursement proofs for a claim.
     * Route model binding automatically injects the claim via UUID.
     *
     * @route GET /api/disbursement/claims/{claim:uuid}/proofs
     */
    public function getProofs(Claim $claim): JsonResponse
    {
        // Laravel automatically injects the model via UUID route binding
        $claim->load('disbursementProofs');

        return response()->json([
            'data' => DisbursementProofResource::collection($claim->disbursementProofs),
        ], 200);
    }
}
