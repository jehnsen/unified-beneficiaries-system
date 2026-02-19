<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Models\Beneficiary;
use App\Models\Municipality;
use App\Repositories\EloquentBeneficiaryRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for EloquentBeneficiaryRepository::findOrCreate() — the "Golden Record" method.
 *
 * The critical invariant: no matter how many concurrent callers submit data for the
 * same person, exactly one Beneficiary row must exist afterwards. The method uses
 * DB::transaction() + lockForUpdate() to prevent the TOCTOU race condition.
 */
class BeneficiaryFindOrCreateTest extends TestCase
{
    use RefreshDatabase;

    private EloquentBeneficiaryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EloquentBeneficiaryRepository();
    }

    private function baseData(Municipality $municipality): array
    {
        return [
            'home_municipality_id' => $municipality->id,
            'first_name'           => 'Juan',
            'last_name'            => 'Dela Cruz',
            'birthdate'            => '1990-01-01',
            'gender'               => 'Male',
        ];
    }

    // =========================================================================
    // Creation
    // =========================================================================

    /** @test */
    public function it_creates_a_new_beneficiary_when_no_match_exists(): void
    {
        $municipality = Municipality::factory()->create();

        $beneficiary = $this->repository->findOrCreate($this->baseData($municipality));

        $this->assertInstanceOf(Beneficiary::class, $beneficiary);
        $this->assertDatabaseHas('beneficiaries', [
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'birthdate'  => '1990-01-01',
        ]);
    }

    /** @test */
    public function it_auto_computes_phonetic_hash_on_creation(): void
    {
        $municipality = Municipality::factory()->create();

        $beneficiary = $this->repository->findOrCreate($this->baseData($municipality));

        $this->assertSame(soundex('Dela Cruz'), $beneficiary->last_name_phonetic);
        $this->assertDatabaseHas('beneficiaries', [
            'last_name'          => 'Dela Cruz',
            'last_name_phonetic' => soundex('Dela Cruz'),
        ]);
    }

    /** @test */
    public function it_auto_assigns_a_uuid_on_creation(): void
    {
        $municipality = Municipality::factory()->create();

        $beneficiary = $this->repository->findOrCreate($this->baseData($municipality));

        $this->assertNotEmpty($beneficiary->uuid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $beneficiary->uuid,
        );
    }

    // =========================================================================
    // Golden Record — returns existing record on duplicate submission
    // =========================================================================

    /** @test */
    public function it_returns_existing_beneficiary_when_exact_match_found(): void
    {
        $municipality = Municipality::factory()->create();

        // First submission creates the record.
        $first = $this->repository->findOrCreate($this->baseData($municipality));

        // Second submission with the same identifying triple must return the same record.
        $second = $this->repository->findOrCreate($this->baseData($municipality));

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('beneficiaries', 1);
    }

    /** @test */
    public function it_does_not_create_duplicate_when_only_birthdate_matches(): void
    {
        $municipality = Municipality::factory()->create();

        $this->repository->findOrCreate($this->baseData($municipality));

        // Different name, same birthdate — must create a new record.
        $different = $this->repository->findOrCreate(array_merge($this->baseData($municipality), [
            'first_name' => 'Maria',
            'last_name'  => 'Santos',
        ]));

        $this->assertDatabaseCount('beneficiaries', 2);
        $this->assertNotSame(
            Beneficiary::where('first_name', 'Juan')->first()->id,
            $different->id,
        );
    }

    /** @test */
    public function it_does_not_create_duplicate_when_only_name_matches(): void
    {
        $municipality = Municipality::factory()->create();

        $this->repository->findOrCreate($this->baseData($municipality));

        // Same name, different birthdate — different person.
        $this->repository->findOrCreate(array_merge($this->baseData($municipality), [
            'birthdate' => '1985-07-20',
        ]));

        $this->assertDatabaseCount('beneficiaries', 2);
    }

    // =========================================================================
    // Concurrency guard (sequential simulation)
    // =========================================================================

    /** @test */
    public function it_returns_existing_record_on_rapid_sequential_submissions(): void
    {
        $municipality = Municipality::factory()->create();
        $data         = $this->baseData($municipality);

        // Simulate what happens if two intake forms for the same person are
        // submitted back-to-back before either completes.
        $results = collect(range(1, 5))->map(fn() => $this->repository->findOrCreate($data));

        // Every call must return the same beneficiary ID.
        $ids = $results->pluck('id')->unique();
        $this->assertCount(1, $ids);
        $this->assertDatabaseCount('beneficiaries', 1);
    }

    // =========================================================================
    // searchByPhonetic() — Levenshtein layer
    // =========================================================================

    /** @test */
    public function search_by_phonetic_finds_beneficiary_with_exact_name(): void
    {
        Beneficiary::factory()->create([
            'first_name'         => 'Juan',
            'last_name'          => 'Dela Cruz',
            'last_name_phonetic' => soundex('Dela Cruz'),
            'birthdate'          => '1990-01-01',
        ]);

        $results = $this->repository->searchByPhonetic('Juan', 'Dela Cruz', '1990-01-01');

        $this->assertCount(1, $results);
        $this->assertSame('Juan', $results->first()->first_name);
    }

    /** @test */
    public function search_by_phonetic_finds_near_spelling_variant(): void
    {
        // "Dela Cruz" and "De la Cruz" share the same SOUNDEX and differ by 1 char.
        Beneficiary::factory()->create([
            'first_name'         => 'Juan',
            'last_name'          => 'Dela Cruz',
            'last_name_phonetic' => soundex('Dela Cruz'),
            'birthdate'          => '1990-01-01',
        ]);

        // Levenshtein distance between "juan dela cruz" and "juan de la cruz" is 1 — within threshold.
        $results = $this->repository->searchByPhonetic('Juan', 'De la Cruz', '1990-01-01');

        $this->assertCount(1, $results);
    }

    /** @test */
    public function search_by_phonetic_excludes_phonetically_dissimilar_names(): void
    {
        Beneficiary::factory()->create([
            'first_name'         => 'Maria',
            'last_name'          => 'Santos',
            'last_name_phonetic' => soundex('Santos'),
            'birthdate'          => '1985-03-10',
        ]);

        // "Garcia" has a completely different SOUNDEX from "Santos" — no match.
        $results = $this->repository->searchByPhonetic('Maria', 'Garcia', '1985-03-10');

        $this->assertCount(0, $results);
    }
}
