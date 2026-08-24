<?php

namespace App\Http\Middleware;

use App\Models\Business;
use App\Services\FormattingService;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loads the tenant into the session once per session, and binds it for the
 * request so global scopes work everywhere (including in jobs dispatched
 * during the request).
 *
 * Everything downstream — Blade directives, services, reports — reads
 * `session('business.*')` rather than re-querying.
 */
class SetSessionData
{
    public function __construct(private FormattingService $format) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (empty($user)) {
            return $next($request);
        }

        if ($this->needsHydrating($request, $user)) {
            $this->hydrate($request, $user);
        }

        Tenancy::bind($request->session()->get('user.business_id'));

        return $next($request);
    }

    /**
     * Does the session need (re)building on this request?
     *
     * Three cases, and the third is the one that is easy to miss:
     *
     * 1. Nothing cached yet — the first request after logging in.
     * 2. A different user — "sign in as user" swaps the authenticated user
     *    without starting a new session.
     * 3. **The business cache was deliberately dropped.**
     *    {@see \App\Http\Controllers\BusinessController::updateSettings()} calls
     *    `session()->forget(['business', 'currency', 'financial_year'])` after a
     *    save, precisely so the next request picks the new values up. It leaves
     *    `user` alone, because the user has not changed — so without this clause
     *    the first two conditions are both false, nothing is rebuilt, and
     *    `session('business')` stays empty until the user logs out. Every figure
     *    on every screen then formats with a null precision and no currency
     *    symbol, and `session('business.enabled_modules')` reads as an empty
     *    list, which silently collapses the sidebar's module groups and the
     *    permission list in the role editor. Saving your settings would break
     *    the rest of your session.
     */
    protected function needsHydrating(Request $request, $user): bool
    {
        $session = $request->session();

        if (empty($session->get('user'))
            || (int) $session->get('user.id') !== (int) $user->id) {
            return true;
        }

        return ! empty($user->business_id) && empty($session->get('business'));
    }

    /**
     * Populate the session with the user, their business, currency and the
     * current financial year.
     */
    protected function hydrate(Request $request, $user): void
    {
        $request->session()->put('user', [
            'id' => $user->id,
            'business_id' => $user->business_id,
            'surname' => $user->surname,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'username' => $user->username,
            'email' => $user->email,
            'language' => $user->language ?: config('app.locale'),
            'user_type' => $user->user_type,
            'is_cmmsn_agnt' => (bool) $user->is_cmmsn_agnt,
            'cmmsn_percent' => $user->cmmsn_percent,
            'selected_contacts' => (bool) $user->selected_contacts,
            'max_sales_discount_percent' => $user->max_sales_discount_percent,
            'status' => $user->status,
        ]);

        if (empty($user->business_id)) {
            return;
        }

        $business = Business::with('currency')
            ->find($user->business_id);

        if (empty($business)) {
            return;
        }

        // The whole settings row is cached in the session, as the original did.
        $request->session()->put('business', $business->toArray());

        $request->session()->put('currency', [
            'id' => $business->currency_id,
            'symbol' => $business->currency->symbol ?? '',
            'code' => $business->currency->code ?? '',
            'thousand_separator' => $business->currency->thousand_separator ?? ',',
            'decimal_separator' => $business->currency->decimal_separator ?? '.',
            'symbol_placement' => $business->currency_symbol_placement ?? 'before',
            'currency_precision' => $business->currency_precision ?? 2,
            'quantity_precision' => $business->quantity_precision ?? 2,
        ]);

        $request->session()->put('financial_year', $this->financialYear($business));
    }

    /**
     * Financial year window derived from business.fy_start_month.
     *
     * @return array{start: string, end: string}
     */
    protected function financialYear(Business $business): array
    {
        $startMonth = (int) ($business->fy_start_month ?: 1);

        $start = now()->startOfMonth()->month($startMonth);

        if (now()->lessThan($start)) {
            $start->subYear();
        }

        return [
            'start' => $start->toDateString(),
            'end' => $start->copy()->addYear()->subDay()->toDateString(),
        ];
    }
}
