<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Models\Beneficiary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface BeneficiaryRepositoryInterface
{
    /**
     * Paginated list with Spatie QueryBuilder filtering.
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    /**
     * Soft delete a beneficiary.
     */
    public function delete(int $id): bool;

    /**
     * Find beneficiary by ID.
     */
    public function findById(int $id): ?Beneficiary;

    /**
     * Find or create beneficiary (Golden Record pattern).
     * Prevents duplicate beneficiaries by matching existing records first.
     */
    public function findOrCreate(array $data): Beneficiary;

    /**
     * Search beneficiaries using phonetic matching.
     * Used for fraud detection across municipalities.
     *
     * @param string $firstName
     * @param string $lastName
     * @param string|null $birthdate
     * @return Collection<Beneficiary>
     */
    public function searchByPhonetic(string $firstName, string $lastName, ?string $birthdate = null): Collection;

    /**
     * Get all beneficiaries for a specific municipality.
     */
    public function getByMunicipality(int $municipalityId): Collection;

    /**
     * Update beneficiary information.
     */
    public function update(int $id, array $data): Beneficiary;

    /**
     * Get beneficiaries with recent claims (fraud analytics).
     */
    public function getWithRecentClaims(int $days = 30): Collection;
}
