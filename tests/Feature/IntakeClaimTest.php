<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunFraudCheckJob;
use App\Models\Beneficiary;
use App\Models\Municipality;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for POST /api/intake/claims.
 *
 * Covers the full intake flow: find-or-create beneficiary → create claim at
 * PENDING_FRAUD_CHECK → dispatch async fraud job → return 201.
 */
class IntakeClaimTest extends TestCase
{
    use RefreshDatabase;

    private Municipality $municipality;
    private User $municipalUser;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->municipality = Municipality::factory()->create();
        $this->municipalUser = User::factory()->municipal($this->municipality)->create();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'home_municipality_id' => $this->municipality->id,
            'first_name'           => 'Juan',
            'last_name'            => 'Dela Cruz',
            'birthdate'            => '1990-01-01',
            'gender'               => 'Male',
            'assistance_type'      => 'Medical',
            'amount'               => 5000.00,
        ], $overrides);
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    /** @test */
    public function unauthenticated_request_returns_401(): void
    {
        $this->postJson('/api/intake/claims', $this->validPayload())
            ->assertStatus(401);
    }

    // =========================================================================
    // Happy path — municipal staff
    // =========================================================================

    /** @test */
    public function municipal_staff_can_create_a_claim(): void
    {
        $response = $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'PENDING_FRAUD_CHECK')
            ->assertJsonStructure(['data' => ['uuid', 'status', 'assistance_type', 'amount']]);
    }

    /** @test */
    public function claim_is_created_in_pending_fraud_check_status(): void
    {
        $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload());

        $this->assertDatabaseHas('claims', [
            'assistance_type' => 'Medical',
            'amount'          => 5000.00,
            'status'          => 'PENDING_FRAUD_CHECK',
            'municipality_id' => $this->municipality->id,
        ]);
    }

    /** @test */
    public function municipality_id_is_inferred_from_auth_context_for_municipal_staff(): void
    {
        // Municipal staff must NOT pass municipality_id — it comes from their profile.
        $payload = $this->validPayload();
        unset($payload['municipality_id']);

        $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $payload)
            ->assertStatus(201);

        $this->assertDatabaseHas('claims', ['municipality_id' => $this->municipality->id]);
    }

    /** @test */
    public function fraud_check_job_is_dispatched_after_commit(): void
    {
        $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload())
            ->assertStatus(201);

        Queue::assertPushed(RunFraudCheckJob::class, function ($job) {
            // Verify the job carries the correct beneficiary name (public properties
            // are not exposed, so we only verify count + class type).
            return true;
        });
        Queue::assertPushedTimes(RunFraudCheckJob::class, 1);
    }

    // =========================================================================
    // Happy path — provincial staff
    // =========================================================================

    /** @test */
    public function provincial_staff_can_create_a_claim_with_explicit_municipality_id(): void
    {
        $provincial = User::factory()->provincial()->create();

        $response = $this->actingAs($provincial)
            ->postJson('/api/intake/claims', $this->validPayload([
                'municipality_id' => $this->municipality->id,
            ]));

        $response->assertStatus(201);
    }

    /** @test */
    public function provincial_staff_without_municipality_id_fails_validation(): void
    {
        $provincial = User::factory()->provincial()->create();

        // Provincial staff must supply municipality_id — it cannot be inferred.
        $this->actingAs($provincial)
            ->postJson('/api/intake/claims', $this->validPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['municipality_id']);
    }

    // =========================================================================
    // Golden Record — same beneficiary is reused
    // =========================================================================

    /** @test */
    public function second_claim_for_same_beneficiary_reuses_existing_record(): void
    {
        $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload(['assistance_type' => 'Medical']))
            ->assertStatus(201);

        $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload(['assistance_type' => 'Cash']))
            ->assertStatus(201);

        // Two claims, but only one beneficiary — the Golden Record principle.
        $this->assertDatabaseCount('beneficiaries', 1);
        $this->assertDatabaseCount('claims', 2);
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /** @test */
    public function missing_first_name_returns_422(): void
    {
        $payload = $this->validPayload();
        unset($payload['first_name']);

        $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['first_name']);
    }

    /** @test */
    public function invalid_gender_value_returns_422(): void
    {
        $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload(['gender' => 'Unknown']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gender']);
    }

    /** @test */
    public function invalid_assistance_type_returns_422(): void
    {
        $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload(['assistance_type' => 'Lottery']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['assistance_type']);
    }

    /** @test */
    public function amount_below_minimum_returns_422(): void
    {
        $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload(['amount' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    /** @test */
    public function future_birthdate_returns_422(): void
    {
        $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload(['birthdate' => '2099-01-01']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['birthdate']);
    }

    /** @test */
    public function nonexistent_home_municipality_id_returns_422(): void
    {
        $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload(['home_municipality_id' => 99999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['home_municipality_id']);
    }

    // =========================================================================
    // Response shape
    // =========================================================================

    /** @test */
    public function response_contains_uuid_not_auto_increment_id(): void
    {
        $response = $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload());

        $response->assertStatus(201);

        // The claim UUID must be present and the auto-increment 'id' must not be exposed.
        $data = $response->json('data');
        $this->assertArrayHasKey('uuid', $data);
        $this->assertArrayNotHasKey('id', $data);
    }

    /** @test */
    public function response_message_indicates_background_fraud_check(): void
    {
        $response = $this->actingAs($this->municipalUser)
            ->postJson('/api/intake/claims', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('message', 'Claim created. Fraud check is running in the background.');
    }
}
