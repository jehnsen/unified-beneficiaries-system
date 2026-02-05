<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Interfaces\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class EloquentUserRepository implements UserRepositoryInterface
{
    public function paginate(?int $municipalityId, int $perPage = 15): LengthAwarePaginator
    {
        $query = QueryBuilder::for(User::class)
            ->allowedFilters([
                AllowedFilter::partial('name'),
                AllowedFilter::partial('email'),
                AllowedFilter::exact('role'),
                AllowedFilter::exact('municipality_id'),
                AllowedFilter::exact('is_active'),
            ])
            ->allowedSorts(['name', 'email', 'role', 'created_at'])
            ->defaultSort('name')
            ->with('municipality');

        // Municipal admins only see their own municipality's users
        if ($municipalityId !== null) {
            $query->where('municipality_id', $municipalityId);
        }

        return $query->paginate($perPage);
    }

    public function findById(int $id): ?User
    {
        return User::with('municipality')->find($id);
    }

    public function findByUuid(string $uuid): ?User
    {
        return User::with('municipality')
            ->where('uuid', $uuid)
            ->first();
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(int $id, array $data): User
    {
        $user = User::findOrFail($id);
        $user->update($data);

        return $user->fresh()->load('municipality');
    }

    public function updateByUuid(string $uuid, array $data): User
    {
        $user = User::where('uuid', $uuid)->firstOrFail();
        $user->update($data);

        return $user->fresh()->load('municipality');
    }

    public function delete(int $id): bool
    {
        $user = User::findOrFail($id);
        // Revoke all API tokens on deletion
        $user->tokens()->delete();

        return (bool) $user->delete();
    }

    public function deleteByUuid(string $uuid): bool
    {
        $user = User::where('uuid', $uuid)->firstOrFail();
        // Revoke all API tokens on deletion
        $user->tokens()->delete();

        return (bool) $user->delete();
    }
}
