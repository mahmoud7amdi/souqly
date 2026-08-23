<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query to the current tenant.
 *
 * Resolution order for the tenant id:
 *   1. An explicitly bound tenant (see App\Support\Tenancy) — works in
 *      console commands, queued jobs and tests where no session exists.
 *   2. The session, populated by the SetSessionData middleware.
 *
 * If neither is available the scope forces an empty result set rather than
 * leaking another tenant's rows. The one exception is the console, where an
 * unbound tenant means "operate across all businesses" (used by the
 * scheduler and maintenance commands) — those code paths iterate businesses
 * explicitly.
 */
class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $businessId = \App\Support\Tenancy::id();

        if (! is_null($businessId)) {
            $builder->where($model->getTable().'.business_id', $businessId);

            return;
        }

        // No tenant bound. In the console this means "all businesses"; the
        // caller is responsible for scoping. In HTTP it is a bug — fail closed.
        if (! app()->runningInConsole()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
