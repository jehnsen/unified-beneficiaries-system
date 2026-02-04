<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Models\Municipality;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MunicipalityRepositoryInterface
{
    public function paginate(int $perPage = 15): LengthAwarePaginator;

    public function findById(int $id): ?Municipality;

    public function create(array $data): Municipality;

    public function update(int $id, array $data): Municipality;

    public function delete(int $id): bool;
}
