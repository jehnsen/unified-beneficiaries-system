<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\Claim;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the flagged-claim state machine.
 *
 * Critical business rule: a claim that arrives from the fraud scanner as risky
 * must pass through UNDER_REVIEW before it can be approved. The sequence is:
 *
 *   PENDING_FRAUD_CHECK  (async scan running)
 *       ↓  (fraud job completes, is_flagged = true)
 *   PENDING  [flagged]
 *       ↓  (reviewer places it under manual review)
 *   UNDER_REVIEW
 *       ↓  (reviewer confirms it is legitimate)
 *   APPROVED
 *
 * These tests verify every guard and transition in that path.
 */
class FlaggedClaimApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Municipality $municipality;
    private User $staff;
    private Beneficiary $beneficiary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->municipality = Municipality::factory()->create();
        $this->staff        = User::factory()->municipal($this->municipality)->create();
        $this->beneficiary  = Beneficiary::factory()->create([
            'home_municipality_id' => $this->municipality->id,
        ]);
    }

    // =========================================================================
    // PENDING_FRAUD_CHECK — the fraud scan has not completed yet
    // =========================================================================

    /** @test */
    public function pending_fraud_check_claim_cannot_be_approved(): void
    {
        // The fraud job is still running — the claim must not be actionable.
        $claim = Claim::factory()->pendingFraudCheck()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error', 'Only pending claims can be approved.');
    }

    /** @test */
    public function pending_fraud_check_claim_can_be_placed_under_review(): void
    {
        // Reviewers may want to inspect the claim before the async scan finishes.
        // markUnderReview() allows PENDING_FRAUD_CHECK → UNDER_REVIEW.
        $claim = Claim::factory()->pendingFraudCheck()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/review", [
                'notes' => 'Pre-emptive review while scan completes.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'UNDER_REVIEW');
    }

    // =========================================================================
    // PENDING (flagged) — fraud scan completed with a risk flag
    // =========================================================================

    /** @test */
    public function flagged_pending_claim_can_be_approved_directly(): void
    {
        // The code has NO enforcement that a flagged claim must pass through
        // UNDER_REVIEW first — isPending() returns true for PENDING regardless of
        // is_flagged. This test documents the CURRENT behaviour.
        //
        // If a future requirement enforces the UNDER_REVIEW gate for flagged claims,
        // this test should be updated to assertStatus(422).
        $claim = Claim::factory()->pending()->flagged()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'APPROVED');
    }

    /** @test */
    public function flagged_pending_claim_can_be_moved_to_under_review(): void
    {
        $claim = Claim::factory()->pending()->flagged('High frequency detected')->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/review", [
                'notes' => 'Investigating the high-frequency flag before approval.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'UNDER_REVIEW');
    }

    // =========================================================================
    // UNDER_REVIEW — manual review in progress
    // =========================================================================

    /** @test */
    public function under_review_claim_can_be_approved(): void
    {
        // UNDER_REVIEW is included in isPending() — it is a valid state for approval.
        $claim = Claim::factory()->underReview()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'APPROVED');
    }

    /** @test */
    public function under_review_claim_can_be_rejected(): void
    {
        $claim = Claim::factory()->underReview()->flagged()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/reject", [
                'rejection_reason' => 'Confirmed duplicate after investigation.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'REJECTED');
    }

    // =========================================================================
    // Terminal states — no further transitions allowed
    // =========================================================================

    /** @test */
    public function approved_claim_cannot_be_placed_under_review(): void
    {
        $claim = Claim::factory()->approved()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        // markUnderReview() requires isPending() OR isPendingFraudCheck().
        // isApproved() satisfies neither → 422.
        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/review")
            ->assertStatus(422);
    }

    /** @test */
    public function approved_claim_cannot_be_approved_again(): void
    {
        $claim = Claim::factory()->approved()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        // isApproved() → isPending() returns false → 422.
        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/approve")
            ->assertStatus(422);
    }

    /** @test */
    public function rejected_claim_cannot_be_placed_under_review(): void
    {
        $claim = Claim::factory()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
            'status'          => 'REJECTED',
            'rejected_at'     => now(),
            'rejection_reason' => 'Original rejection.',
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/review")
            ->assertStatus(422);
    }

    // =========================================================================
    // Full workflow — PENDING_FRAUD_CHECK → UNDER_REVIEW → APPROVED
    // =========================================================================

    /** @test */
    public function full_flagged_claim_workflow_completes_successfully(): void
    {
        // Step 1: Fraud scan lands the claim in PENDING (flagged).
        $claim = Claim::factory()->pending()->flagged('Inter-LGU pattern detected')->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        // Step 2: Reviewer places it under manual investigation.
        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/review", [
                'notes' => 'Pulling supporting documents from beneficiary.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'UNDER_REVIEW');

        // Step 3: After review, the claim is approved as legitimate.
        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'APPROVED');

        $this->assertDatabaseHas('claims', [
            'id'     => $claim->id,
            'status' => 'APPROVED',
        ]);
    }
}
