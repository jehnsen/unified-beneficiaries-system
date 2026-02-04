<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBeneficiaryRequest;
use App\Http\Requests\UpdateBeneficiaryRequest;
use App\Http\Resources\BeneficiaryResource;
use App\Interfaces\BeneficiaryRepositoryInterface;
use App\Services\FraudDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BeneficiaryController extends Controller
{
    public function __construct(
        private readonly BeneficiaryRepositoryInterface $beneficiaryRepository,
        private readonly FraudDetectionService $fraudService
    ) {
    }

    /**
     * Paginated list of beneficiaries.
     * Beneficiaries are provincial assets — all users can search, but
     * cross-LGU data is masked via BeneficiaryResource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->input('per_page', 15), 100);
        $beneficiaries = $this->beneficiaryRepository->paginate($perPage);

        return BeneficiaryResource::collection($beneficiaries);
    }

    /**
     * Show a single beneficiary with cross-LGU masking.
     */
    public function show(int $id): JsonResponse
    {
        $beneficiary = $this->beneficiaryRepository->findById($id);

        if (!$beneficiary) {
            return response()->json(['message' => 'Beneficiary not found.'], 404);
        }

        return response()->json([
            'data' => new BeneficiaryResource($beneficiary),
        ]);
    }

    /**
     * Create a new beneficiary with Golden Record enforcement.
     * Checks for duplicates first; returns 409 if matches found
     * unless skip_duplicate_check is explicitly set.
     */
    public function store(StoreBeneficiaryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Golden Record: check for duplicates before creating
        if (!$request->boolean('skip_duplicate_check')) {
            $duplicates = $this->fraudService->findDuplicates(
                $validated['first_name'],
                $validated['last_name'],
                $validated['birthdate']
            );

            if ($duplicates->isNotEmpty()) {
                return response()->json([
                    'message' => 'Potential duplicate beneficiaries found. Review matches or set skip_duplicate_check to proceed.',
                    'duplicates' => BeneficiaryResource::collection($duplicates),
                ], 409);
            }
        }

        $validated['created_by'] = auth()->id();

        $beneficiary = $this->beneficiaryRepository->findOrCreate($validated);

        return response()->json([
            'data' => new BeneficiaryResource($beneficiary->load('homeMunicipality')),
            'message' => 'Beneficiary created successfully.',
        ], 201);
    }

    /**
     * Update a beneficiary record.
     * Authorization handled by UpdateBeneficiaryRequest.
     */
    public function update(int $id, UpdateBeneficiaryRequest $request): JsonResponse
    {
        $beneficiary = $this->beneficiaryRepository->findById($id);

        if (!$beneficiary) {
            return response()->json(['message' => 'Beneficiary not found.'], 404);
        }

        $validated = $request->validated();
        $validated['updated_by'] = auth()->id();

        $updated = $this->beneficiaryRepository->update($id, $validated);

        return response()->json([
            'data' => new BeneficiaryResource($updated->load('homeMunicipality')),
            'message' => 'Beneficiary updated successfully.',
        ]);
    }

    /**
     * Soft delete a beneficiary (provincial admin only).
     */
    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();

        if (!$user->isProvincialStaff() || !$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized. Provincial admin access required.'], 403);
        }

        $beneficiary = $this->beneficiaryRepository->findById($id);

        if (!$beneficiary) {
            return response()->json(['message' => 'Beneficiary not found.'], 404);
        }

        $this->beneficiaryRepository->delete($id);

        return response()->json(['message' => 'Beneficiary deleted successfully.']);
    }
}
