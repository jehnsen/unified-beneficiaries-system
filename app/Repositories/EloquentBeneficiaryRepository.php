<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Interfaces\BeneficiaryRepositoryInterface;
use App\Models\Beneficiary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EloquentBeneficiaryRepository implements BeneficiaryRepositoryInterface
{
    /**
     * Find beneficiary by ID.
     */
    public function findById(int $id): ?Beneficiary
    {
        return Beneficiary::find($id);
    }

    /**
     * Find or create beneficiary (Golden Record pattern).
     * Prevents duplicate beneficiaries by matching existing records first.
     */
    public function findOrCreate(array $data): Beneficiary
    {
        // Try to find existing beneficiary by exact match
        $existing = Beneficiary::where('first_name', $data['first_name'])
            ->where('last_name', $data['last_name'])
            ->where('birthdate', $data['birthdate'])
            ->first();

        if ($existing) {
            return $existing;
        }

        // Calculate phonetic hash for new beneficiary
        $data['last_name_phonetic'] = soundex($data['last_name']);

        return Beneficiary::create($data);
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
