<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the request in the tenant's timezone so that "today" on a report means
 * today at the shop, not on the server.
 */
class Timezone
{
    public function handle(Request $request, Closure $next): Response
    {
        $timezone = $request->session()->get('business.time_zone');

        if (! empty($timezone) && in_array($timezone, timezone_identifiers_list(), true)) {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        }

        return $next($request);
    }
}
