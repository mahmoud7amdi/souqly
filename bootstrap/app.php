<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    /*
     * Registers `app/Console/Commands` as a discovery path.
     *
     * Needed explicitly, and that is easy to get wrong: passing
     * `commands: routes/console.php` to withRouting() above registers only that
     * *file* — the framework partitions files into command *route* paths and
     * leaves `$commandPaths` empty (see Foundation\Console\Kernel::discoverCommands).
     * So the class-based commands in app/Console/Commands are not found unless
     * this line exists, and the failure is silent: the schedule in
     * routes/console.php would still parse, then fail at run time with
     * "command not defined".
     */
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'SetSessionData' => \App\Http\Middleware\SetSessionData::class,
            'language' => \App\Http\Middleware\Language::class,
            'timezone' => \App\Http\Middleware\Timezone::class,
            'CheckUserLogin' => \App\Http\Middleware\CheckUserLogin::class,
            'superadmin' => \App\Http\Middleware\Superadmin::class,
        ]);

        /*
         * The stack every authenticated screen runs through. Registered as a
         * group so routes/web.php reads cleanly and the order can never drift:
         * session data must load before language (which reads the user's
         * locale) and before timezone (which reads the business setting).
         */
        $middleware->appendToGroup('tenant', [
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
        ]);

        // Same, plus the login-eligibility gate. Used by all UI screens; the
        // print/download and notification routes use `tenant` alone.
        $middleware->appendToGroup('tenant.ui', [
            \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class,
            \App\Http\Middleware\Timezone::class,
            \App\Http\Middleware\CheckUserLogin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
