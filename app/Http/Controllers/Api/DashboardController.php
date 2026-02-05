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

    /**
     * Get metrics cards data with month-over-month percentage changes.
     *
     * Returns data for 4 metric cards:
     * - Total Disbursed (with % change vs last month)
     * - Beneficiaries Served (unique beneficiaries with claims this month)
     * - Fraud Attempts Blocked (flagged claims this month)
     * - Remaining Budget
     */
    public function metricsCards(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        // Base claim query with tenant scoping
        $claimQuery = Claim::withoutGlobalScopes();
        if (!$isProvincial) {
            $claimQuery->where('municipality_id', $municipalityId);
        }

        // Current month boundaries
        $currentMonthStart = Carbon::now()->startOfMonth();
        $currentMonthEnd = Carbon::now()->endOfMonth();

        // Last month boundaries
        $lastMonthStart = Carbon::now()->subMonth()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();

        // ========================================
        // 1. TOTAL DISBURSED
        // ========================================
        $currentMonthDisbursed = (float) (clone $claimQuery)
            ->where('status', 'DISBURSED')
            ->whereBetween('disbursed_at', [$currentMonthStart, $currentMonthEnd])
            ->sum('amount');

        $lastMonthDisbursed = (float) (clone $claimQuery)
            ->where('status', 'DISBURSED')
            ->whereBetween('disbursed_at', [$lastMonthStart, $lastMonthEnd])
            ->sum('amount');

        $disbursedChange = $this->calculatePercentageChange($lastMonthDisbursed, $currentMonthDisbursed);

        // ========================================
        // 2. BENEFICIARIES SERVED (Unique beneficiaries with disbursed claims this month)
        // ========================================
        $currentMonthBeneficiaries = (clone $claimQuery)
            ->where('status', 'DISBURSED')
            ->whereBetween('disbursed_at', [$currentMonthStart, $currentMonthEnd])
            ->distinct('beneficiary_id')
            ->count('beneficiary_id');

        $lastMonthBeneficiaries = (clone $claimQuery)
            ->where('status', 'DISBURSED')
            ->whereBetween('disbursed_at', [$lastMonthStart, $lastMonthEnd])
            ->distinct('beneficiary_id')
            ->count('beneficiary_id');

        $beneficiariesChange = $this->calculatePercentageChange($lastMonthBeneficiaries, $currentMonthBeneficiaries);

        // ========================================
        // 3. FRAUD ATTEMPTS BLOCKED (Flagged claims this month)
        // ========================================
        $currentMonthFlagged = (clone $claimQuery)
            ->where('is_flagged', true)
            ->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])
            ->count();

        $lastMonthFlagged = (clone $claimQuery)
            ->where('is_flagged', true)
            ->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
            ->count();

        $fraudChange = $this->calculatePercentageChange($lastMonthFlagged, $currentMonthFlagged);

        // ========================================
        // 4. REMAINING BUDGET
        // ========================================
        if ($isProvincial) {
            $municipalities = Municipality::select('allocated_budget', 'used_budget')->get();
            $totalAllocated = (float) $municipalities->sum('allocated_budget');
            $totalUsed = (float) $municipalities->sum('used_budget');
            $remainingBudget = $totalAllocated - $totalUsed;
            $percentageRemaining = $totalAllocated > 0 ? ($remainingBudget / $totalAllocated) * 100 : 0;
        } else {
            $municipality = Municipality::find($municipalityId);
            $totalAllocated = (float) $municipality->allocated_budget;
            $totalUsed = (float) $municipality->used_budget;
            $remainingBudget = $totalAllocated - $totalUsed;
            $percentageRemaining = $totalAllocated > 0 ? ($remainingBudget / $totalAllocated) * 100 : 0;
        }

        return response()->json([
            'data' => [
                'total_disbursed' => [
                    'value' => $currentMonthDisbursed,
                    'change_percentage' => $disbursedChange,
                    'label' => 'vs. last month',
                ],
                'beneficiaries_served' => [
                    'value' => $currentMonthBeneficiaries,
                    'change_percentage' => $beneficiariesChange,
                    'label' => 'families assisted',
                ],
                'fraud_attempts_blocked' => [
                    'value' => $currentMonthFlagged,
                    'change_percentage' => $fraudChange,
                    'label' => 'detected this month',
                ],
                'remaining_budget' => [
                    'value' => $remainingBudget,
                    'total_budget' => $totalAllocated,
                    'percentage_remaining' => round($percentageRemaining, 1),
                    'label' => number_format($percentageRemaining, 1) . '% remaining',
                ],
            ],
        ]);
    }

    /**
     * Get assistance distribution (breakdown by category).
     * Returns data suitable for horizontal bar chart.
     */
    public function assistanceDistribution(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        $claimQuery = Claim::withoutGlobalScopes();
        if (!$isProvincial) {
            $claimQuery->where('municipality_id', $municipalityId);
        }

        // Get disbursed claims only for accurate spending by category
        $distribution = $claimQuery
            ->where('status', 'DISBURSED')
            ->select('assistance_type', DB::raw('SUM(amount) as total_amount'), DB::raw('COUNT(*) as count'))
            ->groupBy('assistance_type')
            ->orderBy('total_amount', 'desc')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->assistance_type,
                'amount' => (float) $row->total_amount,
                'count' => (int) $row->count,
            ]);

        return response()->json([
            'data' => [
                'categories' => $distribution,
                'total_amount' => (float) $distribution->sum('amount'),
                'total_claims' => $distribution->sum('count'),
            ],
        ]);
    }

    /**
     * Get disbursement velocity (daily spending over last 7 days).
     * Returns data suitable for dual-axis line chart.
     */
    public function disbursementVelocity(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        $claimQuery = Claim::withoutGlobalScopes();
        if (!$isProvincial) {
            $claimQuery->where('municipality_id', $municipalityId);
        }

        // Last 7 days
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        // Get disbursed claims grouped by day
        $dailyData = $claimQuery
            ->where('status', 'DISBURSED')
            ->whereBetween('disbursed_at', [$startDate, $endDate])
            ->select(
                DB::raw('DATE(disbursed_at) as date'),
                DB::raw('SUM(amount) as total_amount'),
                DB::raw('COUNT(DISTINCT beneficiary_id) as beneficiary_count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Fill in missing days with zero values
        $velocity = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateKey = $date->toDateString();

            $dayData = $dailyData->get($dateKey);

            $velocity[] = [
                'date' => $dateKey,
                'day_label' => $date->format('D'), // Mon, Tue, Wed, etc.
                'disbursed_amount' => $dayData ? (float) $dayData->total_amount : 0,
                'beneficiary_count' => $dayData ? (int) $dayData->beneficiary_count : 0,
            ];
        }

        return response()->json([
            'data' => [
                'daily_data' => $velocity,
                'period' => 'Last 7 days',
                'total_disbursed' => array_sum(array_column($velocity, 'disbursed_amount')),
                'total_beneficiaries' => array_sum(array_column($velocity, 'beneficiary_count')),
            ],
        ]);
    }

    /**
     * Get recent transactions (last 10 claims regardless of status).
     * Shows a real-time feed of all claim activities.
     */
    public function recentTransactions(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        $claimQuery = Claim::withoutGlobalScopes();
        if (!$isProvincial) {
            $claimQuery->where('municipality_id', $municipalityId);
        }

        // Get last 10 claims ordered by most recent activity
        $recentClaims = $claimQuery
            ->with(['beneficiary', 'municipality'])
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($claim) {
                return [
                    'id' => $claim->id,
                    'beneficiary_name' => $claim->beneficiary->full_name,
                    'barangay' => $claim->beneficiary->barangay,
                    'municipality' => $claim->municipality->name,
                    'assistance_type' => $claim->assistance_type,
                    'amount' => (float) $claim->amount,
                    'status' => $claim->status,
                    'is_flagged' => $claim->is_flagged,
                    'flag_reason' => $claim->flag_reason,
                    'risk_assessment' => $claim->risk_assessment,
                    'created_at' => $claim->created_at->toIso8601String(),
                    'updated_at' => $claim->updated_at->toIso8601String(),
                ];
            });

        return response()->json([
            'data' => $recentClaims,
        ]);
    }

    /**
     * Get fraud alerts summary with actionable metrics.
     * Provides data for the Fraud Alerts page with pending, investigating, and blocked counts.
     */
    public function fraudAlerts(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        $claimQuery = Claim::withoutGlobalScopes();
        if (!$isProvincial) {
            $claimQuery->where('municipality_id', $municipalityId);
        }

        // Get current month boundaries for "Blocked This Month"
        $currentMonthStart = Carbon::now()->startOfMonth();

        // Summary cards
        $pendingReview = (clone $claimQuery)
            ->where('is_flagged', true)
            ->where('status', 'PENDING')
            ->count();

        $underInvestigation = (clone $claimQuery)
            ->where('is_flagged', true)
            ->where('status', 'UNDER_REVIEW')
            ->count();

        $blockedThisMonth = (clone $claimQuery)
            ->where('is_flagged', true)
            ->where('status', 'REJECTED')
            ->where('rejected_at', '>=', $currentMonthStart)
            ->count();

        // Get detailed fraud alerts with priority ordering
        $alerts = (clone $claimQuery)
            ->where('is_flagged', true)
            ->whereIn('status', ['PENDING', 'UNDER_REVIEW'])
            ->with(['beneficiary', 'municipality'])
            ->orderByRaw("FIELD(status, 'PENDING', 'UNDER_REVIEW')")
            ->orderByRaw("JSON_EXTRACT(risk_assessment, '$.risk_level') = 'HIGH' DESC")
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($claim) {
                $riskLevel = $claim->risk_assessment['risk_level'] ?? 'MEDIUM';

                // Generate alert type based on flag reason
                $alertType = 'Unknown';
                $alertCode = 'ALT-' . str_pad((string) $claim->id, 3, '0', STR_PAD_LEFT);

                if (str_contains($claim->flag_reason ?? '', 'DUPLICATE') ||
                    str_contains($claim->flag_reason ?? '', 'SAME TYPE')) {
                    $alertType = 'Duplicate Claim';
                } elseif (str_contains($claim->flag_reason ?? '', 'HIGH FREQUENCY') ||
                          str_contains($claim->flag_reason ?? '', 'Multiple claims')) {
                    $alertType = 'Multiple Claims';
                } elseif (str_contains($claim->flag_reason ?? '', 'IDENTITY') ||
                          str_contains($claim->flag_reason ?? '', 'mismatch')) {
                    $alertType = 'Identity Mismatch';
                }

                return [
                    'id' => $claim->id,
                    'alert_code' => $alertCode,
                    'alert_type' => $alertType,
                    'severity' => $riskLevel,
                    'status' => $claim->status,
                    'description' => $claim->flag_reason,
                    'beneficiary' => [
                        'id' => $claim->beneficiary->id,
                        'name' => $claim->beneficiary->full_name,
                    ],
                    'municipality' => $claim->municipality->name,
                    'amount' => (float) $claim->amount,
                    'assistance_type' => $claim->assistance_type,
                    'created_at' => $claim->created_at->toIso8601String(),
                    'risk_assessment' => $claim->risk_assessment,
                ];
            });

        return response()->json([
            'summary' => [
                'pending_review' => $pendingReview,
                'under_investigation' => $underInvestigation,
                'blocked_this_month' => $blockedThisMonth,
            ],
            'alerts' => $alerts,
        ]);
    }

    /**
     * Get savings ticker - total amount saved from blocked/rejected fraud claims.
     * The "Hero Stat" showing how much money the system prevented from being paid out fraudulently.
     */
    public function savingsTicker(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        $claimQuery = Claim::withoutGlobalScopes();
        if (!$isProvincial) {
            $claimQuery->where('municipality_id', $municipalityId);
        }

        // Calculate total from blocked/rejected claims that were flagged for fraud
        $totalSaved = (float) $claimQuery
            ->where('is_flagged', true)
            ->where('status', 'REJECTED')
            ->sum('amount');

        // Get count of blocked claims for context
        $blockedCount = $claimQuery
            ->where('is_flagged', true)
            ->where('status', 'REJECTED')
            ->count();

        return response()->json([
            'data' => [
                'total_saved' => $totalSaved,
                'blocked_claims_count' => $blockedCount,
                'label' => 'Saved from Fraud Detection',
                'description' => 'Calculated by summing up all blocked/rejected claims due to double-dipping. This proves the system paid for itself.',
            ],
        ]);
    }

    /**
     * Get double dipper leaderboard - Top 5 municipalities with attempted fraud.
     * Shows which municipalities have the highest number of fraud attempts (flagged claims).
     */
    public function doubleDipperLeaderboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();

        // Only provincial staff can see the leaderboard across municipalities
        if (!$isProvincial) {
            return response()->json([
                'message' => 'This feature is only available for provincial staff.',
            ], 403);
        }

        // Get top 5 municipalities by fraud attempt count
        $leaderboard = Municipality::select('municipalities.id', 'municipalities.name', 'municipalities.code')
            ->join('claims', 'claims.municipality_id', '=', 'municipalities.id')
            ->where('claims.is_flagged', true)
            ->groupBy('municipalities.id', 'municipalities.name', 'municipalities.code')
            ->selectRaw('COUNT(claims.id) as fraud_attempts')
            ->selectRaw('SUM(CASE WHEN claims.status = "REJECTED" THEN 1 ELSE 0 END) as blocked_count')
            ->selectRaw('SUM(claims.amount) as total_amount_flagged')
            ->orderBy('fraud_attempts', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($municipality, $index) {
                return [
                    'rank' => $index + 1,
                    'municipality' => [
                        'id' => $municipality->id,
                        'name' => $municipality->name,
                        'code' => $municipality->code,
                    ],
                    'fraud_attempts' => (int) $municipality->fraud_attempts,
                    'blocked_count' => (int) $municipality->blocked_count,
                    'total_amount_flagged' => (float) $municipality->total_amount_flagged,
                    'status' => $municipality->fraud_attempts > 10 ? 'Critical' : ($municipality->fraud_attempts > 5 ? 'Warning' : 'Normal'),
                ];
            });

        return response()->json([
            'data' => [
                'leaderboard' => $leaderboard,
                'title' => 'Top 5 Municipalities with Attempted Fraud',
                'description' => 'Why does Municipality X have 500 fraud attempts? Call the Mayor.',
            ],
        ]);
    }

    /**
     * Get top assistance types distribution.
     * Returns data for pie chart showing percentage breakdown by assistance category.
     * Helps in budget planning: "Sir, too many people are dying; we need more burial funds."
     */
    public function topAssistanceTypes(Request $request): JsonResponse
    {
        $user = $request->user();
        $isProvincial = $user->isProvincialStaff();
        $municipalityId = $user->municipality_id;

        $claimQuery = Claim::withoutGlobalScopes();
        if (!$isProvincial) {
            $claimQuery->where('municipality_id', $municipalityId);
        }

        // Get all claims regardless of status to show demand
        $distribution = $claimQuery
            ->select('assistance_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total_amount'))
            ->groupBy('assistance_type')
            ->orderBy('count', 'desc')
            ->get();

        $totalCount = $distribution->sum('count');
        $totalAmount = $distribution->sum('total_amount');

        $categories = $distribution->map(function ($row) use ($totalCount, $totalAmount) {
            $percentage = $totalCount > 0 ? round(($row->count / $totalCount) * 100, 1) : 0;
            $amountPercentage = $totalAmount > 0 ? round(($row->total_amount / $totalAmount) * 100, 1) : 0;

            return [
                'assistance_type' => $row->assistance_type,
                'count' => (int) $row->count,
                'total_amount' => (float) $row->total_amount,
                'percentage' => $percentage,
                'amount_percentage' => $amountPercentage,
                'label' => "{$row->assistance_type} ({$percentage}%)",
            ];
        });

        return response()->json([
            'data' => [
                'categories' => $categories,
                'total_claims' => $totalCount,
                'total_amount' => (float) $totalAmount,
                'description' => 'Pie Chart showing distribution by assistance type. Helps in budget planning.',
            ],
        ]);
    }

    /**
     * Calculate percentage change between two values.
     * Returns positive for increase, negative for decrease.
     */
    private function calculatePercentageChange(float $oldValue, float $newValue): float
    {
        if ($oldValue == 0) {
            return $newValue > 0 ? 100.0 : 0.0;
        }

        return round((($newValue - $oldValue) / $oldValue) * 100, 1);
    }
}
