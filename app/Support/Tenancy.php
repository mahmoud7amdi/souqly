<?php

namespace App\Support;

/**
 * Holds the tenant (business) id for the current execution context.
 *
 * The original project read `session('user.business_id')` directly from
 * inside the global scope, which silently disabled tenancy in the console and
 * in queued jobs (§15.3 of the audit). This class gives the same convenience
 * while also working outside an HTTP request.
 */
class Tenancy
{
    protected static ?int $businessId = null;

    protected static bool $bound = false;

    /**
     * Bind a tenant for the current process (jobs, commands, tests).
     */
    public static function bind(?int $businessId): void
    {
        static::$businessId = $businessId;
        static::$bound = true;
    }

    /**
     * Forget the explicitly bound tenant and fall back to the session.
     */
    public static function forget(): void
    {
        static::$businessId = null;
        static::$bound = false;
    }

    /**
     * Resolve the active tenant id, or null when there is none.
     */
    public static function id(): ?int
    {
        if (static::$bound) {
            return static::$businessId;
        }

        if (app()->bound('session') && app('session')->isStarted()) {
            $fromSession = session('user.business_id');

            if (! empty($fromSession)) {
                return (int) $fromSession;
            }
        }

        if (auth()->hasUser() && ! empty(auth()->user()->business_id)) {
            return (int) auth()->user()->business_id;
        }

        return null;
    }

    /**
     * Run a callback scoped to the given tenant, then restore the previous one.
     */
    public static function for(?int $businessId, callable $callback): mixed
    {
        $previousId = static::$businessId;
        $previouslyBound = static::$bound;

        static::bind($businessId);

        try {
            return $callback();
        } finally {
            static::$businessId = $previousId;
            static::$bound = $previouslyBound;
        }
    }
}
