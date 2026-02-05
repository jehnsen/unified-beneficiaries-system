<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMunicipalityRequest;
use App\Http\Requests\UpdateMunicipalityRequest;
use App\Http\Resources\MunicipalityResource;
use App\Interfaces\MunicipalityRepositoryInterface;
use App\Models\Municipality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MunicipalityController extends Controller
{
    public function __construct(
        private readonly MunicipalityRepositoryInterface $municipalityRepository
    ) {
    }

    /**
     * List all municipalities (any authenticated user).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->input('per_page', 15), 100);
        $municipalities = $this->municipalityRepository->paginate($perPage);

        return MunicipalityResource::collection($municipalities);
    }

    /**
     * Show a single municipality with aggregate counts.
     * Route model binding automatically injects the municipality via UUID.
     */
    public function show(Municipality $municipality): JsonResponse
    {
        // Laravel automatically injects the model via UUID route binding
        return response()->json([
            'data' => new MunicipalityResource($municipality),
        ]);
    }

    /**
     * Create a new municipality (provincial admin only).
     * Authorization handled by StoreMunicipalityRequest.
     */
    public function store(StoreMunicipalityRequest $request): JsonResponse
    {
        $municipality = $this->municipalityRepository->create($request->validated());

        return response()->json([
            'data' => new MunicipalityResource($municipality),
            'message' => 'Municipality created successfully.',
        ], 201);
    }

    /**
     * Update a municipality.
     * Authorization handled by UpdateMunicipalityRequest.
     */
    public function update(Municipality $municipality, UpdateMunicipalityRequest $request): JsonResponse
    {
        // Laravel automatically injects the model via UUID route binding
        $updated = $this->municipalityRepository->update($municipality->id, $request->validated());

        return response()->json([
            'data' => new MunicipalityResource($updated->loadCount(['beneficiaries', 'claims', 'users'])),
            'message' => 'Municipality updated successfully.',
        ]);
    }

    /**
     * Soft delete a municipality (provincial admin only).
     */
    public function destroy(Municipality $municipality): JsonResponse
    {
        // Laravel automatically injects the model via UUID route binding
        $user = auth()->user();

        if (!$user->isProvincialStaff() || !$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized. Provincial admin access required.'], 403);
        }

        $this->municipalityRepository->delete($municipality->id);

        return response()->json(['message' => 'Municipality deleted successfully.']);
    }
}
