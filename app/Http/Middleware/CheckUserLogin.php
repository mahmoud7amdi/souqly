<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks accounts that may not use the admin UI: CRM contacts and users whose
 * login has been switched off or whose employment has ended.
 */
class CheckUserLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (empty($user)) {
            return $next($request);
        }

        // /home stays reachable so the user sees why they were stopped.
        if ($request->routeIs('home')) {
            return $next($request);
        }

        $blocked = $user->user_type !== 'user'
            || ! $user->allow_login
            || $user->status === 'terminated';

        if (! $blocked) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_FORBIDDEN, __('lang_v1.login_not_allowed'));
        }

        return redirect()->route('home')
            ->with('status', [
                'success' => 0,
                'msg' => __('lang_v1.login_not_allowed'),
            ]);
    }
}
