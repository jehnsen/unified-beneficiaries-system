<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimResource;
use App\Interfaces\ClaimRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClaimController extends Controller
{
    public function __construct(
        private readonly ClaimRepositoryInterface $claimRepository
    ) {
    }

    /**
     * Paginated list of claims.
     * TenantScope automatically restricts municipal staff to their own claims.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = min((int) $request->input('per_page', 15), 100);
        $claims = $this->claimRepository->paginate($perPage);

        return ClaimResource::collection($claims);
    }

    /**
     * Show a single claim with full details.
     */
    public function show(int $id): JsonResponse
    {
        $claim = $this->claimRepository->findById($id);

        if (!$claim) {
            return response()->json(['message' => 'Claim not found.'], 404);
        }

        // Municipal staff authorization is handled by TenantScope;
        // if TenantScope filters it out, findById returns null above.
        $claim->load(['beneficiary.homeMunicipality', 'municipality', 'processedBy', 'disbursementProofs']);

        return response()->json([
            'data' => new ClaimResource($claim),
        ]);
    }
}
