<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Beneficiary;
use App\Models\Claim;
use App\Models\Municipality;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ReportController - Handles report generation for the Reports page.
 *
 * Provides downloadable/viewable reports for:
 * - Monthly Disbursement Summary
 * - Beneficiary Demographics
 * - Fraud Detection Analysis
 * - Quarterly Budget Utilization
 */
class ReportController extends Controller
{
    /**
     * Monthly Disbursement Summary Report
     *
     * Complete breakdown of all assistance disbursements for the current month.
     *
     * @route GET /api/reports/monthly-disbursement
     */
    public function monthlyDisbursement(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        // Get month from query param or use current month
        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $startDate = Carbon::parse($month)->startOfMonth();
        $endDate = Carbon::parse($month)->endOfMonth();

        $claimQuery = Claim::withoutGlobalScopes()
            ->where('status', 'DISBURSED')
            ->whereBetween('disbursed_at', [$startDate, $endDate]);

        if (!$isProvincial) {
            $claimQuery->where('municipality_id', $municipalityId);
        }

        // Total disbursed amount
        $totalDisbursed = (float) $claimQuery->sum('amount');
        $totalClaims = $claimQuery->count();
        $uniqueBeneficiaries = $claimQuery->distinct('beneficiary_id')->count('beneficiary_id');

        // By assistance type
        $byAssistanceType = (clone $claimQuery)
            ->select('assistance_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('assistance_type')
            ->orderBy('total', 'desc')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->assistance_type,
                'count' => (int) $row->count,
                'total_amount' => (float) $row->total,
                'percentage' => $totalDisbursed > 0 ? round(($row->total / $totalDisbursed) * 100, 1) : 0,
            ]);

        // By municipality (for provincial staff)
        $byMunicipality = [];
        if ($isProvincial) {
            $byMunicipality = Claim::withoutGlobalScopes()
                ->join('municipalities', 'claims.municipality_id', '=', 'municipalities.id')
                ->where('claims.status', 'DISBURSED')
                ->whereBetween('claims.disbursed_at', [$startDate, $endDate])
                ->select('municipalities.name', DB::raw('COUNT(claims.id) as count'), DB::raw('SUM(claims.amount) as total'))
                ->groupBy('municipalities.name')
                ->orderBy('total', 'desc')
                ->get()
                ->map(fn ($row) => [
                    'municipality' => $row->name,
                    'count' => (int) $row->count,
                    'total_amount' => (float) $row->total,
                ]);
        }

        // Daily disbursement trend
        $dailyTrend = (clone $claimQuery)
            ->select(DB::raw('DATE(disbursed_at) as date'), DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'count' => (int) $row->count,
                'total_amount' => (float) $row->total,
            ]);

        return response()->json([
            'report_type' => 'Monthly Disbursement Summary',
            'period' => $month,
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total_disbursed' => $totalDisbursed,
                'total_claims' => $totalClaims,
                'unique_beneficiaries' => $uniqueBeneficiaries,
                'average_per_claim' => $totalClaims > 0 ? round($totalDisbursed / $totalClaims, 2) : 0,
            ],
            'by_assistance_type' => $byAssistanceType,
            'by_municipality' => $byMunicipality,
            'daily_trend' => $dailyTrend,
        ]);
    }

    /**
     * Beneficiary Demographics Report
     *
     * Statistical analysis of beneficiary demographics by barangay.
     *
     * @route GET /api/reports/beneficiary-demographics
     */
    public function beneficiaryDemographics(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        $beneficiaryQuery = Beneficiary::query();
        if (!$isProvincial) {
            $beneficiaryQuery->where('home_municipality_id', $municipalityId);
        }

        $totalBeneficiaries = $beneficiaryQuery->count();
        $activeBeneficiaries = (clone $beneficiaryQuery)->where('is_active', true)->count();

        // By gender
        $byGender = (clone $beneficiaryQuery)
            ->select('gender', DB::raw('COUNT(*) as count'))
            ->groupBy('gender')
            ->get()
            ->map(fn ($row) => [
                'gender' => $row->gender,
                'count' => (int) $row->count,
                'percentage' => $totalBeneficiaries > 0 ? round(($row->count / $totalBeneficiaries) * 100, 1) : 0,
            ]);

        // By age group
        $byAgeGroup = (clone $beneficiaryQuery)
            ->select(
                DB::raw("CASE
                    WHEN TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) < 18 THEN 'Under 18'
                    WHEN TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) BETWEEN 18 AND 35 THEN '18-35'
                    WHEN TIMESTAMPDIFF(YEAR, birthdate, CURDATE()) BETWEEN 36 AND 60 THEN '36-60'
                    ELSE '60+' END as age_group"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('age_group')
            ->orderByRaw("FIELD(age_group, 'Under 18', '18-35', '36-60', '60+')")
            ->get()
            ->map(fn ($row) => [
                'age_group' => $row->age_group,
                'count' => (int) $row->count,
                'percentage' => $totalBeneficiaries > 0 ? round(($row->count / $totalBeneficiaries) * 100, 1) : 0,
            ]);

        // By barangay
        $byBarangay = (clone $beneficiaryQuery)
            ->select('barangay', DB::raw('COUNT(*) as count'))
            ->groupBy('barangay')
            ->orderBy('count', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'barangay' => $row->barangay,
                'count' => (int) $row->count,
            ]);

        // By municipality (for provincial staff)
        $byMunicipality = [];
        if ($isProvincial) {
            $byMunicipality = Beneficiary::query()
                ->join('municipalities', 'beneficiaries.home_municipality_id', '=', 'municipalities.id')
                ->select('municipalities.name', DB::raw('COUNT(beneficiaries.id) as count'))
                ->groupBy('municipalities.name')
                ->orderBy('count', 'desc')
                ->get()
                ->map(fn ($row) => [
                    'municipality' => $row->name,
                    'count' => (int) $row->count,
                ]);
        }

        return response()->json([
            'report_type' => 'Beneficiary Demographics Report',
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total_beneficiaries' => $totalBeneficiaries,
                'active_beneficiaries' => $activeBeneficiaries,
                'inactive_beneficiaries' => $totalBeneficiaries - $activeBeneficiaries,
            ],
            'by_gender' => $byGender,
            'by_age_group' => $byAgeGroup,
            'by_barangay' => $byBarangay,
            'by_municipality' => $byMunicipality,
        ]);
    }

    /**
     * Fraud Detection Analysis Report
     *
     * Summary of flagged transactions and fraud prevention metrics.
     *
     * @route GET /api/reports/fraud-detection
     */
    public function fraudDetection(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        // Get month from query param or use current month
        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $startDate = Carbon::parse($month)->startOfMonth();
        $endDate = Carbon::parse($month)->endOfMonth();

        $claimQuery = Claim::withoutGlobalScopes()
            ->where('is_flagged', true)
            ->whereBetween('created_at', [$startDate, $endDate]);

        if (!$isProvincial) {
            $claimQuery->where('municipality_id', $municipalityId);
        }

        $totalFlagged = $claimQuery->count();
        $totalBlocked = (clone $claimQuery)->where('status', 'REJECTED')->count();
        $totalApprovedDespiteFlag = (clone $claimQuery)->whereIn('status', ['APPROVED', 'DISBURSED'])->count();
        $underReview = (clone $claimQuery)->whereIn('status', ['PENDING', 'UNDER_REVIEW'])->count();

        // Fraud prevention amount (sum of rejected flagged claims)
        $preventedAmount = (float) (clone $claimQuery)
            ->where('status', 'REJECTED')
            ->sum('amount');

        // By fraud type
        $byFraudType = (clone $claimQuery)
            ->select(
                DB::raw(Claim::alertTypeSqlCase()),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('fraud_type')
            ->get()
            ->map(fn ($row) => [
                'fraud_type' => $row->fraud_type,
                'count' => (int) $row->count,
            ]);

        // By risk level
        $byRiskLevel = (clone $claimQuery)
            ->select(
                DB::raw("JSON_EXTRACT(risk_assessment, '$.risk_level') as risk_level"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('risk_level')
            ->get()
            ->map(fn ($row) => [
                'risk_level' => $row->risk_level ? trim($row->risk_level, '"') : 'UNKNOWN',
                'count' => (int) $row->count,
            ]);

        return response()->json([
            'report_type' => 'Fraud Detection Analysis',
            'period' => $month,
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total_flagged' => $totalFlagged,
                'total_blocked' => $totalBlocked,
                'approved_despite_flag' => $totalApprovedDespiteFlag,
                'under_review' => $underReview,
                'prevented_amount' => $preventedAmount,
                'detection_rate' => $totalFlagged > 0 ? round(($totalBlocked / $totalFlagged) * 100, 1) : 0,
            ],
            'by_fraud_type' => $byFraudType,
            'by_risk_level' => $byRiskLevel,
        ]);
    }

    /**
     * Quarterly Budget Utilization Report
     *
     * Budget allocation vs actual spending across all assistance categories.
     *
     * @route GET /api/reports/budget-utilization
     */
    public function budgetUtilization(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        // Get quarter from query param or use current quarter
        $quarter = $request->input('quarter', 'Q' . ceil(Carbon::now()->month / 3));
        $year = $request->input('year', Carbon::now()->year);

        // Calculate quarter dates
        $quarterMonth = ((int) substr($quarter, 1) - 1) * 3 + 1;
        $startDate = Carbon::create($year, $quarterMonth, 1)->startOfMonth();
        $endDate = $startDate->copy()->addMonths(2)->endOfMonth();

        // Get municipality budget data
        if ($isProvincial) {
            $municipalities = Municipality::select('id', 'name', 'allocated_budget', 'used_budget')->get();
            $totalAllocated = (float) $municipalities->sum('allocated_budget');
            $totalUsed = (float) $municipalities->sum('used_budget');

            $byMunicipality = $municipalities->map(fn ($m) => [
                'municipality' => $m->name,
                'allocated' => (float) $m->allocated_budget,
                'used' => (float) $m->used_budget,
                'remaining' => (float) ($m->allocated_budget - $m->used_budget),
                'utilization_rate' => $m->allocated_budget > 0 ? round(($m->used_budget / $m->allocated_budget) * 100, 1) : 0,
            ]);
        } else {
            $municipality = Municipality::find($municipalityId);
            $totalAllocated = (float) $municipality->allocated_budget;
            $totalUsed = (float) $municipality->used_budget;
            $byMunicipality = [];
        }

        // Spending by assistance type for the quarter
        $claimQuery = Claim::withoutGlobalScopes()
            ->where('status', 'DISBURSED')
            ->whereBetween('disbursed_at', [$startDate, $endDate]);

        if (!$isProvincial) {
            $claimQuery->where('municipality_id', $municipalityId);
        }

        $byAssistanceType = $claimQuery
            ->select('assistance_type', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('assistance_type')
            ->orderBy('total', 'desc')
            ->get()
            ->map(fn ($row) => [
                'assistance_type' => $row->assistance_type,
                'spent' => (float) $row->total,
                'count' => (int) $row->count,
            ]);

        return response()->json([
            'report_type' => 'Quarterly Budget Utilization',
            'period' => "$quarter $year",
            'quarter_dates' => [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
            ],
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total_allocated' => $totalAllocated,
                'total_used' => $totalUsed,
                'total_remaining' => $totalAllocated - $totalUsed,
                'utilization_rate' => $totalAllocated > 0 ? round(($totalUsed / $totalAllocated) * 100, 1) : 0,
            ],
            'by_assistance_type' => $byAssistanceType,
            'by_municipality' => $byMunicipality,
        ]);
    }
}
