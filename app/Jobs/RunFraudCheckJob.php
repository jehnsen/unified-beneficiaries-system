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
        private readonly string $firstName,
        private readonly string $lastName,
        private readonly string $birthdate,
        private readonly string $assistanceType
    ) {
    }

    /**
     * Execute the fraud scan and write the result back to the claim.
     *
     * ClaimRepositoryInterface and FraudDetectionService are resolved from the
     * container at dispatch time (not serialized), so repository/service state
     * is always fresh when the worker picks up the job.
     */
    public function handle(
        ClaimRepositoryInterface $claimRepository,
        FraudDetectionService $fraudService
    ): void {
        $riskResult = $fraudService->checkRisk(
            $this->firstName,
            $this->lastName,
            $this->birthdate,
            $this->assistanceType
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
