<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClaimResource;
use App\Interfaces\ClaimRepositoryInterface;
use App\Models\Claim;
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
     * Route model binding automatically injects the claim via UUID.
     */
    public function show(Claim $claim): JsonResponse
    {
        // Laravel automatically injects the model via UUID route binding
        // TenantScope is automatically applied during route binding
        $claim->load(['beneficiary.homeMunicipality', 'municipality', 'processedBy', 'disbursementProofs']);

        return response()->json([
            'data' => new ClaimResource($claim),
        ]);
    }
}
