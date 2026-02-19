<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\DTOs\RiskAssessmentResult;
use App\Interfaces\BeneficiaryRepositoryInterface;
use App\Interfaces\ClaimRepositoryInterface;
use App\Interfaces\VerifiedDistinctPairRepositoryInterface;
use App\Models\Beneficiary;
use App\Models\Claim;
use App\Models\Municipality;
use App\Models\User;
use App\Models\VerifiedDistinctPair;
use App\Services\ConfigurationService;
use App\Services\FraudDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Unit tests for FraudDetectionService::checkRisk().
 *
 * Uses RefreshDatabase rather than pure Mockery because the service makes one
 * direct Eloquent call (Beneficiary::where(...)->first()) that cannot be mocked
 * through the repository interface. All other dependencies are mocked so tests
 * remain fast and isolated from infrastructure concerns.
 *
 * Auth context is set to a Provincial user so TenantScope does not restrict
 * the underlying Claim queries during risk calculation.
 */
class FraudDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private FraudDetectionService $service;
    private ConfigurationService $configService;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub ConfigurationService with fixed thresholds so test assertions
        // are deterministic regardless of what system_settings contains in the DB.
        $this->configService = \Mockery::mock(ConfigurationService::class);
        $this->configService->shouldReceive('getInt')
            ->with('RISK_THRESHOLD_DAYS', 90)
            ->andReturn(90);
        $this->configService->shouldReceive('getInt')
            ->with('SAME_TYPE_THRESHOLD_DAYS', 30)
            ->andReturn(30);
        $this->configService->shouldReceive('getInt')
            ->with('HIGH_FREQUENCY_THRESHOLD', 3)
            ->andReturn(3);

        $this->service = new FraudDetectionService(
            app(BeneficiaryRepositoryInterface::class),
            app(ClaimRepositoryInterface::class),
            app(VerifiedDistinctPairRepositoryInterface::class),
            $this->configService,
        );

        // Authenticate as provincial staff so TenantScope allows cross-municipality
        // claim queries inside the service.
        $provincial = User::factory()->provincial()->create();
        $this->actingAs($provincial);
    }

    // =========================================================================
    // checkRisk() — base cases
    // =========================================================================

    /** @test */
    public function it_returns_low_risk_when_no_phonetic_matches_exist(): void
    {
        // Empty DB → searchByPhonetic returns nothing.
        $result = $this->service->checkRisk('Juan', 'Dela Cruz', '1990-01-01', 'Medical');

        $this->assertInstanceOf(RiskAssessmentResult::class, $result);
        $this->assertFalse($result->isRisky);
        $this->assertSame('LOW', $result->riskLevel);
        $this->assertStringContainsString('No matching', $result->details);
    }

    /** @test */
    public function it_returns_low_risk_when_phonetic_match_has_no_recent_claims(): void
    {
        // Create a phonetically similar beneficiary with no claims.
        Beneficiary::factory()->create([
            'first_name'         => 'Juan',
            'last_name'          => 'Dela Cruz',
            'last_name_phonetic' => soundex('Dela Cruz'),
            'birthdate'          => '1990-01-01',
        ]);

        // The match exists but has zero recent claims → no risk flags → LOW.
        $result = $this->service->checkRisk('Juan', 'Dela Cruz', '1990-01-01', 'Medical');

        $this->assertFalse($result->isRisky);
        $this->assertSame('LOW', $result->riskLevel);
    }

    // =========================================================================
    // checkRisk() — individual risk flags
    // =========================================================================

    /** @test */
    public function it_flags_inter_lgu_claims_from_multiple_municipalities(): void
    {
        $municipalityA = Municipality::factory()->create();
        $municipalityB = Municipality::factory()->create();

        $beneficiary = Beneficiary::factory()->create([
            'first_name'         => 'Juan',
            'last_name'          => 'Dela Cruz',
            'last_name_phonetic' => soundex('Dela Cruz'),
            'birthdate'          => '1990-01-01',
        ]);

        // Two approved claims from two different municipalities within the 90-day window.
        Claim::factory()->create([
            'beneficiary_id'  => $beneficiary->id,
            'municipality_id' => $municipalityA->id,
            'assistance_type' => 'Food',
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(10),
        ]);
        Claim::factory()->create([
            'beneficiary_id'  => $beneficiary->id,
            'municipality_id' => $municipalityB->id,
            'assistance_type' => 'Cash',
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(20),
        ]);

        $result = $this->service->checkRisk('Juan', 'Dela Cruz', '1990-01-01', 'Food');

        $this->assertTrue($result->isRisky);
        $this->assertStringContainsString('municipalities', $result->details);
    }

    /** @test */
    public function it_flags_double_dipping_same_assistance_type_within_threshold(): void
    {
        $municipality = Municipality::factory()->create();
        $beneficiary  = Beneficiary::factory()->create([
            'first_name'         => 'Maria',
            'last_name'          => 'Santos',
            'last_name_phonetic' => soundex('Santos'),
            'birthdate'          => '1985-06-15',
        ]);

        // Already received Medical assistance 15 days ago (within the 30-day window).
        Claim::factory()->create([
            'beneficiary_id'  => $beneficiary->id,
            'municipality_id' => $municipality->id,
            'assistance_type' => 'Medical',
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(15),
        ]);

        $result = $this->service->checkRisk('Maria', 'Santos', '1985-06-15', 'Medical');

        $this->assertTrue($result->isRisky);
        $this->assertStringContainsString('Medical', $result->details);
    }

    /** @test */
    public function it_does_not_flag_same_type_claim_outside_threshold_window(): void
    {
        $municipality = Municipality::factory()->create();
        $beneficiary  = Beneficiary::factory()->create([
            'first_name'         => 'Maria',
            'last_name'          => 'Santos',
            'last_name_phonetic' => soundex('Santos'),
            'birthdate'          => '1985-06-15',
        ]);

        // Same type but 45 days ago — outside the 30-day SAME_TYPE_THRESHOLD_DAYS.
        Claim::factory()->create([
            'beneficiary_id'  => $beneficiary->id,
            'municipality_id' => $municipality->id,
            'assistance_type' => 'Medical',
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(45),
        ]);

        $result = $this->service->checkRisk('Maria', 'Santos', '1985-06-15', 'Medical');

        // Inter-LGU check: only one municipality → no inter-LGU flag.
        // Frequency check: only one claim → below threshold of 3.
        // Double-dip check: 45 days > 30-day window → no flag.
        $this->assertFalse($result->isRisky);
    }

    /** @test */
    public function it_flags_high_frequency_when_claim_count_meets_threshold(): void
    {
        $municipality = Municipality::factory()->create();
        $beneficiary  = Beneficiary::factory()->create([
            'first_name'         => 'Pedro',
            'last_name'          => 'Reyes',
            'last_name_phonetic' => soundex('Reyes'),
            'birthdate'          => '1970-03-22',
        ]);

        // Exactly 3 claims within 90 days → meets HIGH_FREQUENCY_THRESHOLD of 3.
        Claim::factory()->count(3)->create([
            'beneficiary_id'  => $beneficiary->id,
            'municipality_id' => $municipality->id,
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(5),
        ]);

        $result = $this->service->checkRisk('Pedro', 'Reyes', '1970-03-22');

        $this->assertTrue($result->isRisky);
        $this->assertStringContainsString('High frequency', $result->details);
    }

    // =========================================================================
    // checkRisk() — risk level calculation
    // =========================================================================

    /** @test */
    public function it_returns_medium_risk_for_one_or_two_flags(): void
    {
        $municipalityA = Municipality::factory()->create();
        $municipalityB = Municipality::factory()->create();
        $beneficiary   = Beneficiary::factory()->create([
            'first_name'         => 'Ana',
            'last_name'          => 'Garcia',
            'last_name_phonetic' => soundex('Garcia'),
            'birthdate'          => '1992-09-10',
        ]);

        // Two claims from two municipalities → inter-LGU flag (1 flag only).
        Claim::factory()->create([
            'beneficiary_id'  => $beneficiary->id,
            'municipality_id' => $municipalityA->id,
            'assistance_type' => 'Cash',
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(5),
        ]);
        Claim::factory()->create([
            'beneficiary_id'  => $beneficiary->id,
            'municipality_id' => $municipalityB->id,
            'assistance_type' => 'Food',
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(10),
        ]);

        $result = $this->service->checkRisk('Ana', 'Garcia', '1992-09-10');

        $this->assertTrue($result->isRisky);
        $this->assertSame('MEDIUM', $result->riskLevel);
    }

    /** @test */
    public function it_returns_high_risk_when_three_or_more_flags_are_raised(): void
    {
        $municipalityA = Municipality::factory()->create();
        $municipalityB = Municipality::factory()->create();
        $beneficiary   = Beneficiary::factory()->create([
            'first_name'         => 'Luis',
            'last_name'          => 'Fernandez',
            'last_name_phonetic' => soundex('Fernandez'),
            'birthdate'          => '1980-11-05',
        ]);

        // 3 claims from 2 municipalities with the same type within 30 days.
        // This triggers all three flags: inter-LGU + double-dip + high-frequency.
        Claim::factory()->count(2)->create([
            'beneficiary_id'  => $beneficiary->id,
            'municipality_id' => $municipalityA->id,
            'assistance_type' => 'Medical',
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(5),
        ]);
        Claim::factory()->create([
            'beneficiary_id'  => $beneficiary->id,
            'municipality_id' => $municipalityB->id,
            'assistance_type' => 'Medical',
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(10),
        ]);

        $result = $this->service->checkRisk('Luis', 'Fernandez', '1980-11-05', 'Medical');

        $this->assertTrue($result->isRisky);
        $this->assertSame('HIGH', $result->riskLevel);
    }

    /** @test */
    public function it_returns_high_risk_when_claim_count_reaches_five(): void
    {
        $municipality = Municipality::factory()->create();
        $beneficiary  = Beneficiary::factory()->create([
            'first_name'         => 'Carlo',
            'last_name'          => 'Bautista',
            'last_name_phonetic' => soundex('Bautista'),
            'birthdate'          => '1975-02-28',
        ]);

        // 5 claims triggers the "5+ recent claims → HIGH" branch in calculateRiskLevel.
        Claim::factory()->count(5)->create([
            'beneficiary_id'  => $beneficiary->id,
            'municipality_id' => $municipality->id,
            'assistance_type' => 'Food',
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(3),
        ]);

        $result = $this->service->checkRisk('Carlo', 'Bautista', '1975-02-28');

        $this->assertTrue($result->isRisky);
        $this->assertSame('HIGH', $result->riskLevel);
    }

    // =========================================================================
    // checkRisk() — whitelist / false-positive suppression
    // =========================================================================

    /** @test */
    public function it_excludes_whitelisted_pairs_from_risk_flags(): void
    {
        $municipality = Municipality::factory()->create();

        // The "real" beneficiary whose details are being submitted.
        $targetBeneficiary = Beneficiary::factory()->create([
            'first_name'         => 'Jose',
            'last_name'          => 'Rizal',
            'last_name_phonetic' => soundex('Rizal'),
            'birthdate'          => '1861-06-19',
        ]);

        // A phonetically identical record that would normally trigger a flag.
        $matchBeneficiary = Beneficiary::factory()->create([
            'first_name'         => 'Jose',
            'last_name'          => 'Rizal',
            'last_name_phonetic' => soundex('Rizal'),
            'birthdate'          => '1861-06-19',
        ]);

        // The match has a recent claim that would otherwise produce a risk flag.
        Claim::factory()->create([
            'beneficiary_id'  => $matchBeneficiary->id,
            'municipality_id' => $municipality->id,
            'assistance_type' => 'Medical',
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(5),
        ]);

        // Normalize pair IDs as the VerifiedDistinctPair model boot() does.
        $minId = min($targetBeneficiary->id, $matchBeneficiary->id);
        $maxId = max($targetBeneficiary->id, $matchBeneficiary->id);

        $verifier = User::factory()->provincial()->create();
        VerifiedDistinctPair::create([
            'beneficiary_a_id'    => $minId,
            'beneficiary_b_id'    => $maxId,
            'verification_status' => 'VERIFIED_DISTINCT',
            'verification_reason' => 'Same name, confirmed different persons',
            'verified_by_user_id' => $verifier->id,
            'verified_at'         => now(),
        ]);

        // Submitting details for the target beneficiary — the matching record is whitelisted.
        $result = $this->service->checkRisk('Jose', 'Rizal', '1861-06-19', 'Medical');

        $this->assertFalse($result->isRisky);
        $this->assertSame('LOW', $result->riskLevel);
        $this->assertStringContainsString('whitelisted', $result->details);
    }

    /** @test */
    public function it_still_flags_pairs_marked_as_under_review_not_verified_distinct(): void
    {
        $municipality = Municipality::factory()->create();

        $targetBeneficiary = Beneficiary::factory()->create([
            'first_name'         => 'Rosa',
            'last_name'          => 'Dela Cruz',
            'last_name_phonetic' => soundex('Dela Cruz'),
            'birthdate'          => '1990-05-15',
        ]);

        $matchBeneficiary = Beneficiary::factory()->create([
            'first_name'         => 'Rosa',
            'last_name'          => 'Dela Cruz',
            'last_name_phonetic' => soundex('Dela Cruz'),
            'birthdate'          => '1990-05-15',
        ]);

        Claim::factory()->create([
            'beneficiary_id'  => $matchBeneficiary->id,
            'municipality_id' => $municipality->id,
            'status'          => 'APPROVED',
            'created_at'      => now()->subDays(5),
        ]);

        $minId = min($targetBeneficiary->id, $matchBeneficiary->id);
        $maxId = max($targetBeneficiary->id, $matchBeneficiary->id);
        $verifier = User::factory()->provincial()->create();

        // UNDER_REVIEW does NOT suppress fraud flags — only VERIFIED_DISTINCT does.
        VerifiedDistinctPair::create([
            'beneficiary_a_id'    => $minId,
            'beneficiary_b_id'    => $maxId,
            'verification_status' => 'UNDER_REVIEW',
            'verification_reason' => 'Investigating...',
            'verified_by_user_id' => $verifier->id,
            'verified_at'         => now(),
        ]);

        $result = $this->service->checkRisk('Rosa', 'Dela Cruz', '1990-05-15');

        // UNDER_REVIEW pair is NOT whitelisted → the matching beneficiary's claims still trigger flags.
        $this->assertTrue($result->isRisky);
    }

    // =========================================================================
    // checkDuplicates() — similarity scoring
    // =========================================================================

    /** @test */
    public function check_duplicates_returns_high_risk_for_exact_name_match(): void
    {
        Beneficiary::factory()->create([
            'first_name'         => 'Juan',
            'last_name'          => 'Santos',
            'last_name_phonetic' => soundex('Santos'),
            'birthdate'          => '1990-01-01',
        ]);

        $result = $this->service->checkDuplicates('Juan', 'Santos', '1990-01-01');

        $this->assertSame('HIGH', $result['risk_level']);
        $this->assertGreaterThan(0, $result['total_matches']);
        $this->assertSame(100, $result['matches'][0]['similarity_score']);
        $this->assertSame(0, $result['matches'][0]['levenshtein_distance']);
    }

    /** @test */
    public function check_duplicates_returns_low_risk_when_no_similar_names_found(): void
    {
        Beneficiary::factory()->create([
            'first_name'         => 'Completely',
            'last_name'          => 'Different',
            'last_name_phonetic' => soundex('Different'),
            'birthdate'          => '1990-01-01',
        ]);

        // Search for a name with a completely different phonetic — no DB match.
        $result = $this->service->checkDuplicates('Unique', 'Zxyqwerty', '1990-01-01');

        $this->assertSame('LOW', $result['risk_level']);
        $this->assertSame(0, $result['total_matches']);
    }
}
