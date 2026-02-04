<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Beneficiary;
use App\Models\Claim;
use App\Models\Municipality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Provincial or municipal summary dashboard.
     *
     * Provincial staff gets aggregate stats across all municipalities.
     * Municipal staff gets stats scoped to their own municipality.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        // Beneficiary counts
        $beneficiaryQuery = Beneficiary::query();
        if (!$isProvincial) {
            $beneficiaryQuery->where('home_municipality_id', $municipalityId);
        }
        $totalBeneficiaries = $beneficiaryQuery->count();
        $activeBeneficiaries = (clone $beneficiaryQuery)->where('is_active', true)->count();

        // Claim counts — bypass TenantScope for provincial, respect it for municipal
        $claimQuery = Claim::withoutGlobalScopes();
        if (!$isProvincial) {
            $claimQuery->where('municipality_id', $municipalityId);
        }

        $totalClaims = (clone $claimQuery)->count();
        $totalDisbursedAmount = (float) (clone $claimQuery)->where('status', 'DISBURSED')->sum('amount');
        $flaggedClaimsCount = (clone $claimQuery)->where('is_flagged', true)->count();

        // Claims by status
        $claimsByStatus = (clone $claimQuery)
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total_amount'))
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->status => [
                    'count' => (int) $row->count,
                    'total_amount' => (float) $row->total_amount,
                ],
            ]);

        // Claims by assistance type
        $claimsByType = (clone $claimQuery)
            ->select('assistance_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total_amount'))
            ->groupBy('assistance_type')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->assistance_type => [
                    'count' => (int) $row->count,
                    'total_amount' => (float) $row->total_amount,
                ],
            ]);

        $data = [
            'beneficiaries' => [
                'total' => $totalBeneficiaries,
                'active' => $activeBeneficiaries,
            ],
            'claims' => [
                'total' => $totalClaims,
                'total_disbursed_amount' => $totalDisbursedAmount,
                'flagged_count' => $flaggedClaimsCount,
                'by_status' => $claimsByStatus,
                'by_assistance_type' => $claimsByType,
            ],
        ];

        // Budget summary
        if ($isProvincial) {
            $municipalities = Municipality::select(
                'id', 'name', 'code', 'allocated_budget', 'used_budget'
            )->withCount(['beneficiaries', 'claims'])->get();

            $data['budget'] = [
                'total_allocated' => (float) $municipalities->sum('allocated_budget'),
                'total_used' => (float) $municipalities->sum('used_budget'),
                'total_remaining' => (float) ($municipalities->sum('allocated_budget') - $municipalities->sum('used_budget')),
            ];

            $data['municipalities'] = $municipalities->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'code' => $m->code,
                'allocated_budget' => (float) $m->allocated_budget,
                'used_budget' => (float) $m->used_budget,
                'remaining_budget' => (float) ($m->allocated_budget - $m->used_budget),
                'beneficiaries_count' => $m->beneficiaries_count,
                'claims_count' => $m->claims_count,
            ]);
        } else {
            $municipality = Municipality::find($municipalityId);
            $data['budget'] = [
                'allocated' => (float) $municipality->allocated_budget,
                'used' => (float) $municipality->used_budget,
                'remaining' => (float) ($municipality->allocated_budget - $municipality->used_budget),
            ];
        }

        return response()->json(['data' => $data]);
    }
}
