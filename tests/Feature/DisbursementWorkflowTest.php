<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Beneficiary;
use App\Models\Claim;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature tests for the full claim disbursement lifecycle:
 *   PENDING → APPROVED → DISBURSED (with proof upload)
 *
 * Also covers: authorization scope, rejection, and budget tracking.
 */
class DisbursementWorkflowTest extends TestCase
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
    // PENDING → APPROVED
    // =========================================================================

    /** @test */
    public function pending_claim_can_be_approved_by_authorised_staff(): void
    {
        $claim = Claim::factory()->pending()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $response = $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'APPROVED');

        $this->assertDatabaseHas('claims', [
            'id'     => $claim->id,
            'status' => 'APPROVED',
        ]);
    }

    /** @test */
    public function approving_claim_records_processing_user_and_timestamp(): void
    {
        $claim = Claim::factory()->pending()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/approve")
            ->assertStatus(200);

        $this->assertDatabaseHas('claims', [
            'id'                   => $claim->id,
            'processed_by_user_id' => $this->staff->id,
        ]);
        $this->assertNotNull($claim->fresh()->approved_at);
    }

    /** @test */
    public function under_review_claim_can_also_be_approved(): void
    {
        // isPending() returns true for UNDER_REVIEW — both states are actionable.
        $claim = Claim::factory()->underReview()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'APPROVED');
    }

    // =========================================================================
    // APPROVED → DISBURSED (proof upload)
    // =========================================================================

    /** @test */
    public function approved_claim_can_be_disbursed_with_proof(): void
    {
        // Give the fake local disk a temporaryUrl() generator so DisbursementProofResource
        // does not throw RuntimeException when building the JSON response.
        $disk = Storage::fake('local');
        $disk->buildTemporaryUrlsUsing(
            fn(string $path) => 'http://localhost/signed/' . ltrim($path, '/')
        );

        $claim = Claim::factory()->approved()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
            'amount'          => 5000.00,
        ]);

        $response = $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/proof", [
                'photo'     => UploadedFile::fake()->image('photo.jpg'),
                'signature' => UploadedFile::fake()->image('sig.png'),
                'latitude'  => 14.5995,
                'longitude' => 120.9842,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.claim.status', 'DISBURSED');

        $this->assertDatabaseHas('claims', [
            'id'     => $claim->id,
            'status' => 'DISBURSED',
        ]);
    }

    /** @test */
    public function disbursement_increments_municipality_used_budget(): void
    {
        $disk = Storage::fake('local');
        $disk->buildTemporaryUrlsUsing(fn(string $path) => 'http://localhost/signed/' . $path);

        $claim = Claim::factory()->approved()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
            'amount'          => 7500.00,
        ]);

        $initialBudget = (float) $this->municipality->used_budget;

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/proof", [
                'photo'     => UploadedFile::fake()->image('p.jpg'),
                'signature' => UploadedFile::fake()->image('s.png'),
            ])
            ->assertStatus(201);

        $updatedBudget = (float) $this->municipality->fresh()->used_budget;
        $this->assertEqualsWithDelta($initialBudget + 7500.00, $updatedBudget, 0.01);
    }

    /** @test */
    public function proof_files_are_stored_on_private_disk_not_public(): void
    {
        $disk = Storage::fake('local');
        $disk->buildTemporaryUrlsUsing(fn(string $path) => 'http://localhost/signed/' . $path);

        $claim = Claim::factory()->approved()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/proof", [
                'photo'     => UploadedFile::fake()->image('p.jpg'),
                'signature' => UploadedFile::fake()->image('s.png'),
            ])
            ->assertStatus(201);

        // Verify the proof record uses a path from the private disk, not "public/".
        $proof = $claim->fresh()->disbursementProofs()->first();
        $this->assertNotNull($proof);
        $this->assertStringNotContainsString('public/', $proof->photo_url);
        $this->assertStringStartsWith('disbursement-proofs/', $proof->photo_url);
    }

    // =========================================================================
    // State machine violations
    // =========================================================================

    /** @test */
    public function pending_fraud_check_claim_cannot_be_approved(): void
    {
        $claim = Claim::factory()->pendingFraudCheck()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        // isPending() returns false for PENDING_FRAUD_CHECK → 422.
        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error', 'Only pending claims can be approved.');
    }

    /** @test */
    public function pending_claim_cannot_be_directly_disbursed_without_approval(): void
    {
        $disk = Storage::fake('local');
        $disk->buildTemporaryUrlsUsing(fn(string $path) => 'http://localhost/signed/' . $path);

        $claim = Claim::factory()->pending()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/proof", [
                'photo'     => UploadedFile::fake()->image('p.jpg'),
                'signature' => UploadedFile::fake()->image('s.png'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Only approved claims can be disbursed.');
    }

    /** @test */
    public function disbursed_claim_cannot_be_rejected(): void
    {
        $claim = Claim::factory()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
            'status'          => 'DISBURSED',
            'disbursed_at'    => now(),
        ]);

        $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/reject", [
                'rejection_reason' => 'Attempted retroactive rejection',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Cannot reject a disbursed claim.');
    }

    // =========================================================================
    // Authorization scope
    // =========================================================================

    /** @test */
    public function municipal_staff_cannot_approve_another_municipalitys_claim(): void
    {
        $otherMunicipality = Municipality::factory()->create();
        $claim = Claim::factory()->pending()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $otherMunicipality->id,
        ]);

        // TenantScope prevents this user from seeing the claim at all, which produces
        // a 404. Either 403 or 404 is acceptable here — the claim must not be approved.
        $response = $this->actingAs($this->staff)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/approve");

        $this->assertContains($response->status(), [403, 404]);
    }

    /** @test */
    public function provincial_staff_can_approve_any_municipalitys_claim(): void
    {
        $provincial = User::factory()->provincial()->create();

        $claim = Claim::factory()->pending()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->actingAs($provincial)
            ->postJson("/api/disbursement/claims/{$claim->uuid}/approve")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'APPROVED');
    }

    /** @test */
    public function unauthenticated_request_to_approve_returns_401(): void
    {
        $claim = Claim::factory()->pending()->create([
            'beneficiary_id'  => $this->beneficiary->id,
            'municipality_id' => $this->municipality->id,
        ]);

        $this->postJson("/api/disbursement/claims/{$claim->uuid}/approve")
            ->assertStatus(401);
    }
}
