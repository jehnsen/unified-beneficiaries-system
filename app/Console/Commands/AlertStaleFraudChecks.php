<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Claim;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Notifications\StaleFraudCheckNotification;

/**
 * AlertStaleFraudChecks
 *
 * A failed RunFraudCheckJob leaves the claim in PENDING_FRAUD_CHECK indefinitely —
 * intentionally, so it signals manual review rather than silently passing through.
 * This command surfaces those stuck claims to supervisors before they age further
 * and block disbursement queues.
 *
 * Runs every 30 minutes via the scheduler (see bootstrap/app.php).
 */
class AlertStaleFraudChecks extends Command
{
    protected $signature = 'ubis:alert-stale-fraud-checks
                            {--threshold=60 : Minutes a claim can sit in PENDING_FRAUD_CHECK before alerting}';

    protected $description = 'Alert supervisors about claims stuck in PENDING_FRAUD_CHECK for longer than the threshold.';

    public function handle(): int
    {
        $thresholdMinutes = (int) $this->option('threshold');
        $cutoff = now()->subMinutes($thresholdMinutes);

        // withoutGlobalScopes so we find stuck claims across ALL municipalities,
        // not just the one belonging to the scheduler's (null) user context.
        $staleClaims = Claim::withoutGlobalScopes()
            ->where('status', 'PENDING_FRAUD_CHECK')
            ->where('updated_at', '<=', $cutoff)
            ->with(['beneficiary:id,first_name,last_name', 'municipality:id,name'])
            ->get();

        if ($staleClaims->isEmpty()) {
            $this->info('No stale fraud checks found.');
            return self::SUCCESS;
        }

        Log::warning('Stale PENDING_FRAUD_CHECK claims detected', [
            'count' => $staleClaims->count(),
            'threshold_minutes' => $thresholdMinutes,
            'claim_ids' => $staleClaims->pluck('id')->toArray(),
        ]);

        // Notify all Provincial Admin supervisors so they can trigger manual review
        // or re-queue the job. Municipal staff lack the cross-LGU context needed.
        $supervisors = User::withoutGlobalScopes()
            ->whereNull('municipality_id')
            ->where('role', 'ADMIN')
            ->where('is_active', true)
            ->get();

        if ($supervisors->isNotEmpty()) {
            Notification::send($supervisors, new StaleFraudCheckNotification($staleClaims));
        }

        $this->warn("Found {$staleClaims->count()} stale claim(s) stuck in PENDING_FRAUD_CHECK for over {$thresholdMinutes} minutes.");
        $this->table(
            ['Claim UUID', 'Beneficiary', 'Municipality', 'Stuck Since'],
            $staleClaims->map(fn ($claim) => [
                $claim->uuid,
                optional($claim->beneficiary)->first_name . ' ' . optional($claim->beneficiary)->last_name,
                optional($claim->municipality)->name ?? 'N/A',
                $claim->updated_at->diffForHumans(),
            ])
        );

        return self::SUCCESS;
    }
}
