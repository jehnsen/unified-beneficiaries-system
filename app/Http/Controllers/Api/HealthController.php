<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Claim;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class HealthController extends Controller
{
    /**
     * Liveness probe — public, consumed by load balancers and uptime monitors.
     *
     * Returns 200 when DB + storage are reachable, 503 when either is degraded.
     * Intentionally minimal: no auth, no queue stats, fast response time.
     *
     * @route GET /api/v1/health
     */
    public function liveness(): JsonResponse
    {
        $checks = [];
        $allOk  = true;

        // A dead DB connection silently breaks every authenticated endpoint — surface it
        // here so load balancers can pull the instance out of rotation immediately.
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'error';
            $allOk = false;
        }

        // Disbursement proof uploads go to this disk; if it is unwritable the proof
        // endpoint will fail silently mid-transaction. Detect it before a real upload.
        try {
            Storage::disk('local')->exists('.gitkeep');
            $checks['storage'] = 'ok';
        } catch (\Throwable) {
            $checks['storage'] = 'error';
            $allOk = false;
        }

        return response()->json([
            'status'    => $allOk ? 'ok' : 'degraded',
            'service'   => 'Provincial UBIS API',
            'version'   => '1.0.0',
            'timestamp' => now()->toIso8601String(),
            'checks'    => $checks,
        ], $allOk ? 200 : 503);
    }

    /**
     * Operational metrics — auth-protected, Provincial Admin only.
     *
     * Returns queue depth, failed job breakdown, and the fraud-scan backlog so that
     * Provincial Admins can detect worker outages and stuck claims before the
     * AlertStaleFraudChecks scheduler fires its next 30-minute cycle.
     *
     * Response status:
     *   ok       — no failed jobs, no stale fraud checks
     *   warning  — any failed jobs OR any stale fraud checks
     *   critical — failed jobs > 10 OR stale fraud checks > 5
     *
     * @route GET /api/v1/admin/health/metrics
     */
    public function metrics(): JsonResponse
    {
        $queue        = $this->queueMetrics();
        $fraudBacklog = $this->fraudScanBacklog();

        $status = $this->resolveStatus($queue, $fraudBacklog);

        return response()->json([
            'status'        => $status,
            'timestamp'     => now()->toIso8601String(),
            'queue'         => $queue,
            'fraud_backlog' => $fraudBacklog,
        ], $status === 'critical' ? 503 : 200);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function queueMetrics(): array
    {
        // Pending: available_at <= now(), not yet reserved
        $pendingRows = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as count'))
            ->where('available_at', '<=', now()->timestamp)
            ->whereNull('reserved_at')
            ->groupBy('queue')
            ->get();

        $pendingByQueue = $pendingRows->pluck('count', 'queue')->toArray();
        $pendingTotal   = array_sum($pendingByQueue);

        // Oldest unprocessed job age — useful for detecting a stalled worker
        $oldestPending = DB::table('jobs')
            ->whereNull('reserved_at')
            ->min('created_at');

        $oldestPendingSeconds = $oldestPending
            ? now()->timestamp - $oldestPending
            : null;

        // Currently processing: reserved_at is set
        $processingTotal = DB::table('jobs')->whereNotNull('reserved_at')->count();

        // Failed jobs
        $failedRows = DB::table('failed_jobs')
            ->select('queue', DB::raw('COUNT(*) as count'))
            ->groupBy('queue')
            ->get();

        $failedByQueue = $failedRows->pluck('count', 'queue')->toArray();
        $failedTotal   = array_sum($failedByQueue);

        // Most recent failures with enough detail to act on without leaking full stack traces
        $recentFailed = DB::table('failed_jobs')
            ->select('uuid', 'queue', 'payload', 'exception', 'failed_at')
            ->orderByDesc('failed_at')
            ->limit(5)
            ->get()
            ->map(function (object $row) {
                $payload  = json_decode($row->payload, true);
                $jobClass = $payload['displayName'] ?? ($payload['job'] ?? 'Unknown');

                return [
                    'uuid'               => $row->uuid,
                    'queue'              => $row->queue,
                    'job'                => $jobClass,
                    'failed_at'          => $row->failed_at,
                    // First 300 chars of the exception — enough to identify the error class
                    // without exposing a full stack trace to the API response.
                    'exception_summary'  => mb_substr($row->exception, 0, 300),
                ];
            });

        return [
            'pending' => [
                'total'                 => $pendingTotal,
                'by_queue'              => $pendingByQueue,
                'oldest_pending_seconds' => $oldestPendingSeconds,
            ],
            'processing' => [
                'total' => $processingTotal,
            ],
            'failed' => [
                'total'    => $failedTotal,
                'by_queue' => $failedByQueue,
                'recent'   => $recentFailed,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function fraudScanBacklog(): array
    {
        // 60 minutes matches the AlertStaleFraudChecks command default threshold
        $thresholdMinutes = 60;
        $cutoff           = now()->subMinutes($thresholdMinutes);

        $staleClaims = Claim::withoutGlobalScopes()
            ->where('status', 'PENDING_FRAUD_CHECK')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->get(['uuid', 'updated_at', 'municipality_id']);

        $oldestStuckMinutes = $staleClaims->isNotEmpty()
            ? (int) now()->diffInMinutes($staleClaims->first()->updated_at)
            : null;

        // All claims currently waiting for the fraud scan job (not just stale ones)
        $totalPendingFraudCheck = Claim::withoutGlobalScopes()
            ->where('status', 'PENDING_FRAUD_CHECK')
            ->count();

        return [
            'threshold_minutes'       => $thresholdMinutes,
            'total_pending_scan'      => $totalPendingFraudCheck,
            'stale_count'             => $staleClaims->count(),
            'oldest_stuck_minutes'    => $oldestStuckMinutes,
            'stale_claim_uuids'       => $staleClaims->pluck('uuid'),
        ];
    }

    /**
     * @param array<string, mixed> $queue
     * @param array<string, mixed> $fraudBacklog
     */
    private function resolveStatus(array $queue, array $fraudBacklog): string
    {
        $failedTotal = $queue['failed']['total'];
        $staleCount  = $fraudBacklog['stale_count'];

        if ($failedTotal > 10 || $staleCount > 5) {
            return 'critical';
        }

        if ($failedTotal > 0 || $staleCount > 0) {
            return 'warning';
        }

        return 'ok';
    }
}
