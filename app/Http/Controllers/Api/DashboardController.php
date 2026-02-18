<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dashboard API endpoints for Provincial and Municipal users.
 *
 * All query logic and business rules are delegated to DashboardService,
 * which handles the tenant-scoped data access pattern (Provincial vs Municipal).
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService
    ) {
    }

    /**
     * Provincial or municipal summary dashboard.
     */
    public function summary(Request $request): JsonResponse
    {
        $data = $this->dashboardService
            ->forUser($request->user())
            ->getSummary();

        return response()->json(['data' => $data]);
    }

    /**
     * Get metrics cards data with month-over-month percentage changes.
     */
    public function metricsCards(Request $request): JsonResponse
    {
        $data = $this->dashboardService
            ->forUser($request->user())
            ->getMetricsCards();

        return response()->json(['data' => $data]);
    }

    /**
     * Get assistance distribution (breakdown by category).
     */
    public function assistanceDistribution(Request $request): JsonResponse
    {
        $data = $this->dashboardService
            ->forUser($request->user())
            ->getAssistanceDistribution();

        return response()->json(['data' => $data]);
    }

    /**
     * Get disbursement velocity (daily spending over last 7 days).
     */
    public function disbursementVelocity(Request $request): JsonResponse
    {
        $data = $this->dashboardService
            ->forUser($request->user())
            ->getDisbursementVelocity();

        return response()->json(['data' => $data]);
    }

    /**
     * Get recent transactions (last 10 claims regardless of status).
     */
    public function recentTransactions(Request $request): JsonResponse
    {
        $data = $this->dashboardService
            ->forUser($request->user())
            ->getRecentTransactions();

        return response()->json(['data' => $data]);
    }

    /**
     * Get fraud alerts summary with actionable metrics.
     */
    public function fraudAlerts(Request $request): JsonResponse
    {
        $result = $this->dashboardService
            ->forUser($request->user())
            ->getFraudAlerts();

        return response()->json($result);
    }

    /**
     * Get savings ticker - total amount saved from blocked fraud claims.
     */
    public function savingsTicker(Request $request): JsonResponse
    {
        $data = $this->dashboardService
            ->forUser($request->user())
            ->getSavingsTicker();

        return response()->json(['data' => $data]);
    }

    /**
     * Get double dipper leaderboard - Top 5 municipalities with attempted fraud.
     * Provincial staff only.
     */
    public function doubleDipperLeaderboard(Request $request): JsonResponse
    {
        $service = $this->dashboardService->forUser($request->user());

        if (!$service->isProvincial()) {
            return response()->json([
                'message' => 'This feature is only available for provincial staff.',
            ], 403);
        }

        $leaderboard = $service->getDoubleDipperLeaderboard();

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
     */
    public function topAssistanceTypes(Request $request): JsonResponse
    {
        $data = $this->dashboardService
            ->forUser($request->user())
            ->getTopAssistanceTypes();

        return response()->json(['data' => $data]);
    }
}
