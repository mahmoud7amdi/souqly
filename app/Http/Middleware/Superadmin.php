<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts the Superadmin module to the usernames listed in
 * config/constants.php (ADMINISTRATOR_USERNAMES).
 */
class Superadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (empty($user) || ! $user->isSuperadmin()) {
            abort(Response::HTTP_FORBIDDEN, __('lang_v1.unauthorized'));
        }

        return $next($request);
    }
}
