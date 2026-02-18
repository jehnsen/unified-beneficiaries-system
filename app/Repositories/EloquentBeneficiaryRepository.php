<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Interfaces\BeneficiaryRepositoryInterface;
use App\Models\Beneficiary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class EloquentBeneficiaryRepository implements BeneficiaryRepositoryInterface
{
    /**
     * Paginated list with filtering, sorting, and includes.
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return QueryBuilder::for(Beneficiary::class)
            ->allowedFilters([
                AllowedFilter::partial('first_name'),
                AllowedFilter::partial('last_name'),
                AllowedFilter::exact('home_municipality_id'),
                AllowedFilter::exact('gender'),
                AllowedFilter::partial('barangay'),
                AllowedFilter::exact('is_active'),
            ])
            ->allowedSorts(['last_name', 'first_name', 'birthdate', 'created_at'])
            ->allowedIncludes(['homeMunicipality', 'claims'])
            ->defaultSort('last_name')
            ->with('homeMunicipality')
            ->paginate($perPage);
    }

    /**
     * Find beneficiary by ID.
     */
    public function findById(int $id): ?Beneficiary
    {
        return Beneficiary::with('homeMunicipality')->find($id);
    }

    /**
     * Find beneficiary by UUID (public-facing identifier).
     */
    public function findByUuid(string $uuid): ?Beneficiary
    {
        return Beneficiary::with('homeMunicipality')
            ->where('uuid', $uuid)
            ->first();
    }

    /**
     * Update beneficiary by UUID.
     */
    public function updateByUuid(string $uuid, array $data): Beneficiary
    {
        $beneficiary = Beneficiary::where('uuid', $uuid)->firstOrFail();

        // Recalculate phonetic if last name changed
        if (isset($data['last_name']) && $data['last_name'] !== $beneficiary->last_name) {
            $data['last_name_phonetic'] = soundex($data['last_name']);
        }

        $beneficiary->update($data);
        return $beneficiary->fresh('homeMunicipality');
    }

    /**
     * Soft delete a beneficiary by UUID.
     */
    public function deleteByUuid(string $uuid): bool
    {
        $beneficiary = Beneficiary::where('uuid', $uuid)->firstOrFail();
        return (bool) $beneficiary->delete();
    }

    /**
     * Soft delete a beneficiary.
     */
    public function delete(int $id): bool
    {
        return (bool) Beneficiary::findOrFail($id)->delete();
    }

    /**
     * Find or create beneficiary (Golden Record pattern).
     * Prevents duplicate beneficiaries by matching existing records first.
     *
     * Uses a transaction with a pessimistic lock to close the TOCTOU race condition.
     * Without lockForUpdate(), two concurrent intake submissions for the same person
     * both pass the ->first() check and race to create(), breaking the Golden Record.
     *
     * InnoDB gap locks — acquired by SELECT FOR UPDATE on an empty result set — prevent
     * any concurrent transaction from inserting into the same (first_name, last_name,
     * birthdate) key range until this transaction commits.
     */
    public function findOrCreate(array $data): Beneficiary
    {
        return DB::transaction(function () use ($data) {
            $existing = Beneficiary::where('first_name', $data['first_name'])
                ->where('last_name', $data['last_name'])
                ->where('birthdate', $data['birthdate'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            // Calculate phonetic hash for new beneficiary
            $data['last_name_phonetic'] = soundex($data['last_name']);

            return Beneficiary::create($data);
        });
    }

    /**
     * Search beneficiaries using phonetic matching.
     * Layer 1: DB filter using SOUNDEX index (fast)
     * Layer 2: PHP levenshtein ranking (accurate)
     */
    public function searchByPhonetic(string $firstName, string $lastName, ?string $birthdate = null): Collection
    {
        $lastNamePhonetic = soundex($lastName);

        $query = Beneficiary::where('last_name_phonetic', $lastNamePhonetic);

        // Add birthdate filter if provided for higher precision
        if ($birthdate) {
            $query->where('birthdate', $birthdate);
        }

        $results = $query->get();

        // Layer 2: Rank by Levenshtein distance (PHP-level filtering)
        return $results->filter(function ($beneficiary) use ($firstName, $lastName) {
            $fullName = strtolower($firstName . ' ' . $lastName);
            $beneficiaryFullName = strtolower($beneficiary->first_name . ' ' . $beneficiary->last_name);

            $distance = levenshtein($fullName, $beneficiaryFullName);

            // Flag as potential match if distance < 3 (catches typos like Enrique/Enrike)
            return $distance < 3;
        })->sortBy(function ($beneficiary) use ($firstName, $lastName) {
            $fullName = strtolower($firstName . ' ' . $lastName);
            $beneficiaryFullName = strtolower($beneficiary->first_name . ' ' . $beneficiary->last_name);
            return levenshtein($fullName, $beneficiaryFullName);
        });
    }

    /**
     * Get all beneficiaries for a specific municipality.
     */
    public function getByMunicipality(int $municipalityId): Collection
    {
        return Beneficiary::where('home_municipality_id', $municipalityId)
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * Update beneficiary information.
     */
    public function update(int $id, array $data): Beneficiary
    {
        $beneficiary = Beneficiary::findOrFail($id);

        // Recalculate phonetic if last name changed
        if (isset($data['last_name']) && $data['last_name'] !== $beneficiary->last_name) {
            $data['last_name_phonetic'] = soundex($data['last_name']);
        }

        $beneficiary->update($data);

        return $beneficiary->fresh();
    }

    /**
     * Get beneficiaries with recent claims (fraud analytics).
     */
    public function getWithRecentClaims(int $days = 30): Collection
    {
        return Beneficiary::whereHas('claims', function ($query) use ($days) {
            $query->where('created_at', '>=', now()->subDays($days))
                ->where('status', '!=', 'REJECTED');
        })
            ->with(['claims' => function ($query) use ($days) {
                $query->where('created_at', '>=', now()->subDays($days))
                    ->where('status', '!=', 'REJECTED')
                    ->orderBy('created_at', 'desc');
            }])
            ->get();
    }
}
