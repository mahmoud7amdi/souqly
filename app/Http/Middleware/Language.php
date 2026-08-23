<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the user's chosen language and shares the text direction.
 *
 * Arabic (and Pashto) are RTL — every Blade layout reads `$text_direction`
 * to set `dir` on <html> and to pick directional utilities.
 */
class Language
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('user.language')
            ?: ($request->user()?->language ?: config('app.locale'));

        if (! array_key_exists($locale, config('constants.langs', []))) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        $isRtl = in_array($locale, config('constants.langs_rtl', []), true);

        view()->share('text_direction', $isRtl ? 'rtl' : 'ltr');
        view()->share('is_rtl', $isRtl);
        view()->share('current_locale', $locale);

        return $next($request);
    }
}
