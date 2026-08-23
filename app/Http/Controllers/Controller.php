<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Abort unless the user holds at least one of the given permissions.
     *
     * Admins bypass every check, matching the source system where the Admin
     * role carries no explicit permissions.
     */
    protected function permit(string ...$permissions): void
    {
        $user = auth()->user();

        if (empty($user)) {
            abort(403, __('lang_v1.unauthorized'));
        }

        if ($user->isAdmin()) {
            return;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return;
            }
        }

        abort(403, __('lang_v1.unauthorized'));
    }

    /**
     * True when the user holds any of the given permissions (or is an admin).
     */
    protected function allows(string ...$permissions): bool
    {
        $user = auth()->user();

        if (empty($user)) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Abort unless the tenant has the given optional module switched on.
     */
    protected function requireModule(string $module): void
    {
        $enabled = (array) session('business.enabled_modules');

        if (! in_array($module, $enabled, true)) {
            abort(403, __('lang_v1.module_not_enabled'));
        }
    }

    /**
     * Standard success payload for the AJAX screens.
     *
     * @return array{success: int, msg: string}
     */
    protected function ok(?string $message = null): array
    {
        return [
            'success' => 1,
            'msg' => $message ?? __('lang_v1.saved_successfully'),
        ];
    }

    /**
     * Standard failure payload. Logs the cause with enough context to trace
     * it, and never leaks an internal message to the browser.
     *
     * @return array{success: int, msg: string}
     */
    protected function failed(?\Throwable $e = null, ?string $message = null): array
    {
        if ($e instanceof \Throwable) {
            Log::error(
                sprintf('%s:%d %s', $e->getFile(), $e->getLine(), $e->getMessage()),
                ['exception' => $e]
            );
        }

        return [
            'success' => 0,
            'msg' => $message
                ?? ($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : __('lang_v1.something_went_wrong')),
        ];
    }

    /**
     * Redirect back to an index with a status banner.
     */
    protected function backToIndex(string $route, array $output)
    {
        return redirect()->route($route)->with('status', $output);
    }
}
