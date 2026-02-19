<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\Claim;
use App\Models\Municipality;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service layer for dashboard analytics and reporting.
 *
 * Encapsulates the tenant-scoped query logic that was previously duplicated
 * across DashboardController endpoints. Handles the Provincial vs Municipal
 * data access pattern in a single place.
 */
class DashboardService
{
    private bool $isProvincial;
    private ?int $municipalityId;

    /**
     * Initialize the service with user context for tenant scoping.
     */
    public function forUser(User $user): self
    {
        $this->isProvincial = $user->isProvincialStaff();
        $this->municipalityId = $user->municipality_id;

        return $this;
    }

    /**
     * Get a tenant-scoped claim query builder.
     * Provincial users see all claims; Municipal users see only their municipality's claims.
     */
    public function claimQuery(): Builder
    {
        $query = Claim::withoutGlobalScopes();

        if (!$this->isProvincial) {
            $query->where('municipality_id', $this->municipalityId);
        }

        return $query;
    }

    /**
     * Get a tenant-scoped beneficiary query builder.
     */
    public function beneficiaryQuery(): Builder
    {
        $query = Beneficiary::query();

        if (!$this->isProvincial) {
            $query->where('home_municipality_id', $this->municipalityId);
        }

        return $query;
    }

    public function isProvincial(): bool
    {
        return $this->isProvincial;
    }

    public function getMunicipalityId(): ?int
    {
        return $this->municipalityId;
    }

    /**
     * Get summary statistics for beneficiaries and claims.
     */
    public function getSummary(): array
    {
        $beneficiaryQuery = $this->beneficiaryQuery();
        $claimQuery = $this->claimQuery();

        $totalBeneficiaries = $beneficiaryQuery->count();
        $activeBeneficiaries = (clone $beneficiaryQuery)->where('is_active', true)->count();

        $totalClaims = (clone $claimQuery)->count();
        $totalDisbursedAmount = (float) (clone $claimQuery)->where('status', 'DISBURSED')->sum('amount');
        $flaggedClaimsCount = (clone $claimQuery)->where('is_flagged', true)->count();

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

        $data['budget'] = $this->getBudgetSummary();

        if ($this->isProvincial) {
            $data['municipalities'] = $this->getMunicipalitiesSummary();
        }

        return $data;
    }

    /**
     * Get budget summary based on user scope.
     */
    public function getBudgetSummary(): array
    {
        if ($this->isProvincial) {
            $municipalities = Municipality::select('allocated_budget', 'used_budget')->get();
            $totalAllocated = (float) $municipalities->sum('allocated_budget');
            $totalUsed = (float) $municipalities->sum('used_budget');

            return [
                'total_allocated' => $totalAllocated,
                'total_used' => $totalUsed,
                'total_remaining' => $totalAllocated - $totalUsed,
            ];
        }

        $municipality = Municipality::find($this->municipalityId);

        return [
            'allocated' => (float) $municipality->allocated_budget,
            'used' => (float) $municipality->used_budget,
            'remaining' => (float) ($municipality->allocated_budget - $municipality->used_budget),
        ];
    }

    /**
     * Get municipalities summary for provincial users.
     */
    public function getMunicipalitiesSummary(): Collection
    {
        return Municipality::select('id', 'name', 'code', 'allocated_budget', 'used_budget')
            ->withCount(['beneficiaries', 'claims'])
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'code' => $m->code,
                'allocated_budget' => (float) $m->allocated_budget,
                'used_budget' => (float) $m->used_budget,
                'remaining_budget' => (float) ($m->allocated_budget - $m->used_budget),
                'beneficiaries_count' => $m->beneficiaries_count,
                'claims_count' => $m->claims_count,
            ]);
    }

    /**
     * Get metrics cards data with month-over-month percentage changes.
     */
    public function getMetricsCards(): array
    {
        $claimQuery = $this->claimQuery();

        $currentMonthStart = Carbon::now()->startOfMonth();
        $currentMonthEnd = Carbon::now()->endOfMonth();
        $lastMonthStart = Carbon::now()->subMonth()->startOfMonth();
        $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth();

        // Total Disbursed
        $currentMonthDisbursed = (float) (clone $claimQuery)
            ->where('status', 'DISBURSED')
            ->whereBetween('disbursed_at', [$currentMonthStart, $currentMonthEnd])
            ->sum('amount');

        $lastMonthDisbursed = (float) (clone $claimQuery)
            ->where('status', 'DISBURSED')
            ->whereBetween('disbursed_at', [$lastMonthStart, $lastMonthEnd])
            ->sum('amount');

        // Beneficiaries Served
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

        // Fraud Attempts Blocked
        $currentMonthFlagged = (clone $claimQuery)
            ->where('is_flagged', true)
            ->whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])
            ->count();

        $lastMonthFlagged = (clone $claimQuery)
            ->where('is_flagged', true)
            ->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
            ->count();

        // Remaining Budget
        $budget = $this->getBudgetForMetrics();

        return [
            'total_disbursed' => [
                'value' => $currentMonthDisbursed,
                'change_percentage' => $this->calculatePercentageChange($lastMonthDisbursed, $currentMonthDisbursed),
                'label' => 'vs. last month',
            ],
            'beneficiaries_served' => [
                'value' => $currentMonthBeneficiaries,
                'change_percentage' => $this->calculatePercentageChange($lastMonthBeneficiaries, $currentMonthBeneficiaries),
                'label' => 'families assisted',
            ],
            'fraud_attempts_blocked' => [
                'value' => $currentMonthFlagged,
                'change_percentage' => $this->calculatePercentageChange($lastMonthFlagged, $currentMonthFlagged),
                'label' => 'detected this month',
            ],
            'remaining_budget' => $budget,
        ];
    }

    /**
     * Get budget data formatted for metrics cards.
     */
    private function getBudgetForMetrics(): array
    {
        if ($this->isProvincial) {
            $municipalities = Municipality::select('allocated_budget', 'used_budget')->get();
            $totalAllocated = (float) $municipalities->sum('allocated_budget');
            $totalUsed = (float) $municipalities->sum('used_budget');
        } else {
            $municipality = Municipality::find($this->municipalityId);
            $totalAllocated = (float) $municipality->allocated_budget;
            $totalUsed = (float) $municipality->used_budget;
        }

        $remainingBudget = $totalAllocated - $totalUsed;
        $percentageRemaining = $totalAllocated > 0 ? ($remainingBudget / $totalAllocated) * 100 : 0;

        return [
            'value' => $remainingBudget,
            'total_budget' => $totalAllocated,
            'percentage_remaining' => round($percentageRemaining, 1),
            'label' => number_format($percentageRemaining, 1) . '% remaining',
        ];
    }

    /**
     * Get assistance distribution by category.
     */
    public function getAssistanceDistribution(): array
    {
        $distribution = $this->claimQuery()
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

        return [
            'categories' => $distribution,
            'total_amount' => (float) $distribution->sum('amount'),
            'total_claims' => $distribution->sum('count'),
        ];
    }

    /**
     * Get disbursement velocity over the last 7 days.
     */
    public function getDisbursementVelocity(): array
    {
        $startDate = Carbon::now()->subDays(6)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $dailyData = $this->claimQuery()
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

        $velocity = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateKey = $date->toDateString();
            $dayData = $dailyData->get($dateKey);

            $velocity[] = [
                'date' => $dateKey,
                'day_label' => $date->format('D'),
                'disbursed_amount' => $dayData ? (float) $dayData->total_amount : 0,
                'beneficiary_count' => $dayData ? (int) $dayData->beneficiary_count : 0,
            ];
        }

        return [
            'daily_data' => $velocity,
            'period' => 'Last 7 days',
            'total_disbursed' => array_sum(array_column($velocity, 'disbursed_amount')),
            'total_beneficiaries' => array_sum(array_column($velocity, 'beneficiary_count')),
        ];
    }

    /**
     * Get recent transactions (last 10 claims).
     */
    public function getRecentTransactions(): Collection
    {
        return $this->claimQuery()
            ->with(['beneficiary', 'municipality'])
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($claim) => [
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
            ]);
    }

    /**
     * Get fraud alerts summary with actionable metrics.
     */
    public function getFraudAlerts(): array
    {
        $claimQuery = $this->claimQuery();
        $currentMonthStart = Carbon::now()->startOfMonth();

        $summary = [
            'pending_review' => (clone $claimQuery)
                ->where('is_flagged', true)
                ->where('status', 'PENDING')
                ->count(),
            'under_investigation' => (clone $claimQuery)
                ->where('is_flagged', true)
                ->where('status', 'UNDER_REVIEW')
                ->count(),
            'blocked_this_month' => (clone $claimQuery)
                ->where('is_flagged', true)
                ->where('status', 'REJECTED')
                ->where('rejected_at', '>=', $currentMonthStart)
                ->count(),
        ];

        $alerts = (clone $claimQuery)
            ->where('is_flagged', true)
            ->whereIn('status', ['PENDING', 'UNDER_REVIEW'])
            ->with(['beneficiary', 'municipality'])
            ->orderByRaw("FIELD(status, 'PENDING', 'UNDER_REVIEW')")
            ->orderByRaw("JSON_EXTRACT(risk_assessment, '$.risk_level') = 'HIGH' DESC")
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($claim) => $this->formatFraudAlert($claim));

        return [
            'summary' => $summary,
            'alerts' => $alerts,
        ];
    }

    /**
     * Format a claim as a fraud alert.
     */
    private function formatFraudAlert(Claim $claim): array
    {
        $riskLevel = $claim->risk_assessment['risk_level'] ?? 'MEDIUM';
        $alertCode = 'ALT-' . str_pad((string) $claim->id, 3, '0', STR_PAD_LEFT);

        $alertType = $claim->getAlertType();

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
    }

    /**
     * Get savings ticker data.
     */
    public function getSavingsTicker(): array
    {
        $claimQuery = $this->claimQuery();

        $totalSaved = (float) (clone $claimQuery)
            ->where('is_flagged', true)
            ->where('status', 'REJECTED')
            ->sum('amount');

        $blockedCount = (clone $claimQuery)
            ->where('is_flagged', true)
            ->where('status', 'REJECTED')
            ->count();

        return [
            'total_saved' => $totalSaved,
            'blocked_claims_count' => $blockedCount,
            'label' => 'Saved from Fraud Detection',
            'description' => 'Calculated by summing up all blocked/rejected claims due to double-dipping. This proves the system paid for itself.',
        ];
    }

    /**
     * Get double dipper leaderboard (provincial only).
     */
    public function getDoubleDipperLeaderboard(): Collection
    {
        return Municipality::select('municipalities.id', 'municipalities.name', 'municipalities.code')
            ->join('claims', 'claims.municipality_id', '=', 'municipalities.id')
            ->where('claims.is_flagged', true)
            ->groupBy('municipalities.id', 'municipalities.name', 'municipalities.code')
            ->selectRaw('COUNT(claims.id) as fraud_attempts')
            ->selectRaw('SUM(CASE WHEN claims.status = "REJECTED" THEN 1 ELSE 0 END) as blocked_count')
            ->selectRaw('SUM(claims.amount) as total_amount_flagged')
            ->orderBy('fraud_attempts', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($municipality, $index) => [
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
            ]);
    }

    /**
     * Get top assistance types distribution.
     */
    public function getTopAssistanceTypes(): array
    {
        $distribution = $this->claimQuery()
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

        return [
            'categories' => $categories,
            'total_claims' => $totalCount,
            'total_amount' => (float) $totalAmount,
            'description' => 'Pie Chart showing distribution by assistance type. Helps in budget planning.',
        ];
    }

    /**
     * Calculate percentage change between two values.
     */
    public function calculatePercentageChange(float $oldValue, float $newValue): float
    {
        if ($oldValue == 0) {
            return $newValue > 0 ? 100.0 : 0.0;
        }

        return round((($newValue - $oldValue) / $oldValue) * 100, 1);
    }
}
