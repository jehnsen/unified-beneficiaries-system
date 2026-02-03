<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * TenantScope - Implements the "Provincial Grid" multi-tenancy model.
 *
 * Key Logic:
 * - Provincial Staff (user.municipality_id = NULL) -> See ALL records (Global Access)
 * - Municipal Staff (user.municipality_id = SET) -> See ONLY their municipality's records
 *
 * Exception: Fraud checks bypass this scope to search the entire Provincial Grid.
 * Use Model::withoutGlobalScope(TenantScope::class) when needed.
 */
class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        // Provincial Staff has global access (municipality_id is NULL)
        if (!$user || $user->municipality_id === null) {
            return;
        }

        // Municipal Staff - Restrict to their municipality
        $builder->where($model->getTable() . '.municipality_id', $user->municipality_id);
    }
}
