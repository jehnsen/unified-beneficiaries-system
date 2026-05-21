<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Interfaces\ClaimRepositoryInterface;
use App\Services\FraudDetectionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RunFraudCheckJob
 *
 * Runs the full phonetic + Levenshtein fraud scan asynchronously after a claim
 * is created. Decoupling the scan from the HTTP request prevents timeouts as the
 * beneficiary table grows — the intake officer gets an immediate 201 response and
 * the claim appears in the queue UI as PENDING_FRAUD_CHECK until this job finishes.
 *
 * On completion the claim transitions to PENDING (clean) or PENDING + is_flagged=true
 * (risky), entering the normal disbursement workflow from there.
 *
 * PII note: only the claim ID is stored in the queue payload. Beneficiary name and
 * birthdate are re-fetched from the database inside handle() to avoid persisting
 * personal information in the jobs table (RA 10173 compliance).
 */
class RunFraudCheckJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * Retry up to 3 times in case of a transient DB or service failure.
     * The updateFraudResult() idempotency guard makes retries safe.
     */
    public int $tries = 3;

    /**
     * Wait 10 seconds before the first retry, 30 before the second.
     * Gives the DB time to recover from a brief connection blip.
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function __construct(
        private readonly int $claimId,
    ) {
    }

    /**
     * Execute the fraud scan and write the result back to the claim.
     *
     * Beneficiary PII is loaded here (not stored in the job payload) to prevent
     * personal data from being persisted in plaintext in the queue's jobs table.
     */
    public function handle(
        ClaimRepositoryInterface $claimRepository,
        FraudDetectionService $fraudService
    ): void {
        $claim = $claimRepository->findById($this->claimId);

        if (!$claim || !$claim->beneficiary) {
            Log::error('RunFraudCheckJob: claim or beneficiary not found, cannot run fraud check', [
                'claim_id' => $this->claimId,
            ]);

            return;
        }

        $beneficiary = $claim->beneficiary;

        $riskResult = $fraudService->checkRisk(
            $beneficiary->first_name,
            $beneficiary->last_name,
            $beneficiary->birthdate->format('Y-m-d'),
            $claim->assistance_type
        );

        $claimRepository->updateFraudResult(
            $this->claimId,
            $riskResult->isRisky,
            $riskResult->isRisky ? $riskResult->details : null,
            $riskResult->toArray()
        );

        Log::info('Fraud check completed', [
            'claim_id' => $this->claimId,
            'is_risky' => $riskResult->isRisky,
            'risk_level' => $riskResult->riskLevel,
        ]);
    }

    /**
     * If all retries are exhausted, log the failure.
     * The claim will remain in PENDING_FRAUD_CHECK indefinitely — this is intentional:
     * it signals to supervisors that manual review is needed rather than silently
     * passing a claim through without a fraud check.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('RunFraudCheckJob permanently failed — claim stuck in PENDING_FRAUD_CHECK', [
            'claim_id' => $this->claimId,
            'error' => $exception->getMessage(),
        ]);
    }
}
