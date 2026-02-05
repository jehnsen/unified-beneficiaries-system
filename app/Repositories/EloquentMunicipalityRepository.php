<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Interfaces\MunicipalityRepositoryInterface;
use App\Models\Municipality;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class EloquentMunicipalityRepository implements MunicipalityRepositoryInterface
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return QueryBuilder::for(Municipality::class)
            ->allowedFilters([
                AllowedFilter::partial('name'),
                AllowedFilter::exact('code'),
                AllowedFilter::exact('status'),
                AllowedFilter::exact('is_active'),
            ])
            ->allowedSorts(['name', 'code', 'created_at', 'allocated_budget'])
            ->defaultSort('name')
            ->withCount(['beneficiaries', 'claims', 'users'])
            ->paginate($perPage);
    }

    public function findById(int $id): ?Municipality
    {
        return Municipality::withCount(['beneficiaries', 'claims', 'users'])->find($id);
    }

    public function findByUuid(string $uuid): ?Municipality
    {
        return Municipality::withCount(['beneficiaries', 'claims', 'users'])
            ->where('uuid', $uuid)
            ->first();
    }

    public function create(array $data): Municipality
    {
        return Municipality::create($data);
    }

    public function update(int $id, array $data): Municipality
    {
        $municipality = Municipality::findOrFail($id);
        $municipality->update($data);

        return $municipality->fresh();
    }

    public function updateByUuid(string $uuid, array $data): Municipality
    {
        $municipality = Municipality::where('uuid', $uuid)->firstOrFail();
        $municipality->update($data);

        return $municipality->fresh();
    }

    public function delete(int $id): bool
    {
        return (bool) Municipality::findOrFail($id)->delete();
    }

    public function deleteByUuid(string $uuid): bool
    {
        $municipality = Municipality::where('uuid', $uuid)->firstOrFail();
        return (bool) $municipality->delete();
    }
}
