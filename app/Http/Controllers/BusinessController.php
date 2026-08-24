<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Currency;
use App\Models\TaxRate;
use App\Services\UploadService;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Business-wide settings — the one row in `business` that every other screen
 * reads its defaults from.
 *
 * Deliberately a *subset* of the ~100 columns on that table, not a field per
 * column. The rest fall into three buckets that do not belong on this screen:
 *
 * - Module settings (`essentials_settings`, `asset_settings`, reward points)
 *   belong with the modules that own them.
 * - Credentials (`email_settings`, `sms_settings`) are gateway secrets. Putting
 *   an SMTP password in a form that posts back through a browser session is a
 *   different security question from the rest of this screen, and it is answered
 *   properly in `.env` today.
 *
 * `logo` was the third bucket until item 9 — it needed an upload layer that did
 * not exist. It is here now, stored through {@see UploadService} into
 * `constants.business_logo_path`, which is where the invoice renderer looks for
 * it when a layout has no logo of its own.
 *
 * Two things make this controller more than an `update()`:
 *
 * - The session caches the whole settings row ({@see \App\Http\Middleware\SetSessionData}),
 *   so a save that does not refresh it leaves every screen reading the old
 *   currency precision until the next login.
 * - `currency_id` and the precision fields change how every figure in the app is
 *   *formatted*, not what it means. They are editable, but the screen says so.
 */
class BusinessController extends Controller
{
    /**
     * Where the business logo lives, and the only upload on this screen.
     *
     * Shared with the invoice-layout logo on purpose — see
     * {@see InvoiceLayoutController::UPLOADS}. A layout's own logo overrides
     * this one; this is the fallback every layout that has none falls back to
     * ({@see \App\Services\PrintService::present()}).
     */
    protected const LOGO_PATH_KEY = 'business_logo_path';

    public function __construct(private UploadService $uploads) {}

    public function settings()
    {
        $this->permit('business_settings.access');

        return view('business.settings', [
            'business' => $this->currentBusiness(),
            'currencies' => Currency::forDropdown(),
            'taxRates' => ['' => __('lang_v1.none')] + TaxRate::pluck('name', 'id')->all(),
            'timezones' => timezone_identifiers_list(),
            'modules' => $this->availableModules(),
            // Resolved here rather than in the Blade because UploadService also
            // answers "is the file actually on disk", and a settings screen
            // showing a broken-image glyph is how a tenant concludes their logo
            // is corrupt when the row is merely stale.
            'logoUrl' => $this->uploads->url(
                self::LOGO_PATH_KEY,
                $this->currentBusiness()->logo
            ),
            // Passed rather than repeated in the Blade: the same list drives the
            // checkboxes and the boolean coercion in updateSettings(), and a
            // toggle present in one but not the other would silently never save.
            'productToggles' => $this->productToggles(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $this->permit('business_settings.access');

        $business = $this->currentBusiness();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'nullable|date',
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')],
            'time_zone' => 'required|string|in:'.implode(',', timezone_identifiers_list()),
            'fy_start_month' => 'required|integer|between:1,12',
            'accounting_method' => 'required|in:fifo,lifo,avco',

            'tax_label_1' => 'nullable|string|max:10',
            'tax_number_1' => 'nullable|string|max:100',
            'tax_label_2' => 'nullable|string|max:10',
            'tax_number_2' => 'nullable|string|max:100',
            'default_sales_tax' => ['nullable', 'integer', Rule::exists('tax_rates', 'id')
                ->where('business_id', Tenancy::id())],
            'sell_price_tax' => 'required|in:includes,excludes',

            'date_format' => 'required|string|in:'.implode(',', array_keys($this->dateFormats())),
            'time_format' => 'required|in:12,24',
            'currency_symbol_placement' => 'required|in:before,after',
            'currency_precision' => 'required|integer|between:0,4',
            'quantity_precision' => 'required|integer|between:0,4',

            'default_profit_percent' => 'nullable|numeric|min:0|max:999',
            'default_sales_discount' => 'nullable|numeric|min:0|max:100',
            'sku_prefix' => 'nullable|string|max:255',
            'transaction_edit_days' => 'required|integer|min:0|max:3650',
            'stock_expiry_alert_days' => 'required|integer|min:0|max:3650',

            'enabled_modules' => 'nullable|array',
            'enabled_modules.*' => 'string|in:'.implode(',', array_keys($this->availableModules())),

            // `image`, not `mimes:` — it inspects the file's contents, so a PHP
            // script renamed `logo.png` never reaches a web-served directory.
            'logo' => 'nullable|image|max:2048',
        ]);

        try {
            DB::transaction(function () use ($business, $validated, $request) {
                // Out of the validated array before `fill()`: an untouched file
                // input validates as null, and writing that null back would erase
                // the logo every time somebody saved a time zone.
                unset($validated['logo']);

                $business->fill($validated);

                if ($request->hasFile('logo')) {
                    $business->logo = $this->uploads->store(
                        $request->file('logo'),
                        self::LOGO_PATH_KEY,
                        $business->logo
                    );
                } elseif ($request->boolean('remove_logo')) {
                    $this->uploads->delete(self::LOGO_PATH_KEY, $business->logo);
                    $business->logo = null;
                }

                // Absent from the payload when unticked, so read from the request
                // rather than the validated array.
                foreach ($this->productToggles() as $toggle) {
                    $business->{$toggle} = $request->boolean($toggle);
                }

                $business->enabled_modules = array_values((array) ($validated['enabled_modules'] ?? []));
                $business->save();
            });

            // The whole settings row is cached in the session. Without this,
            // every figure on every screen keeps the old precision and the old
            // currency symbol until the user logs out and back in.
            $request->session()->forget(['business', 'currency', 'financial_year']);

            $output = $this->ok(__('lang_v1.updated_successfully'));
        } catch (\Throwable $e) {
            $output = $this->failed($e);
        }

        return back()->with('status', $output);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * The active tenant's settings row.
     *
     * `business` has no tenant global scope — it *is* the tenant — so this is
     * the one place the id is resolved, and it is resolved from the session
     * rather than from anything the request can influence.
     */
    protected function currentBusiness(): Business
    {
        return Business::findOrFail(Tenancy::id());
    }

    /**
     * Optional modules a tenant may switch on, label => translation.
     *
     * These are the module names {@see \App\Support\Permissions::moduleMap()}
     * maps permissions onto, and the same names the sidebar filters on. A module
     * switched off here disappears from the sidebar and its permissions vanish
     * from the role editor.
     *
     * `superadmin` is absent on purpose: it is not a tenant feature, and
     * offering it here would let a business grant itself the permission group
     * that governs other businesses' subscriptions.
     *
     * @return array<string, string>
     */
    protected function availableModules(): array
    {
        return [
            'purchase_order' => __('lang_v1.module_purchase_order'),
            'purchase_requisition' => __('lang_v1.module_purchase_requisition'),
            'sales_order' => __('lang_v1.module_sales_order'),
            'inventorymanagement' => __('lang_v1.module_inventorymanagement'),
            'account' => __('lang_v1.module_account'),
            'accounting' => __('lang_v1.module_accounting'),
            'essentials' => __('lang_v1.module_essentials'),
            'assetmanagement' => __('lang_v1.module_assetmanagement'),
        ];
    }

    /**
     * Boolean product/stock feature flags rendered as checkboxes.
     *
     * @return array<int, string>
     */
    protected function productToggles(): array
    {
        return [
            'enable_brand', 'enable_category', 'enable_sub_category',
            'enable_price_tax', 'enable_purchase_status', 'enable_inline_tax',
            'enable_product_expiry', 'enable_lot_number', 'enable_sub_units',
            'enable_racks', 'enable_row', 'enable_position', 'enable_tooltip',
            'enable_editing_product_from_purchase',
        ];
    }

    /**
     * Date formats offered, PHP format => example.
     *
     * A whitelist rather than a free-text field: the value is fed to date()
     * everywhere a date is printed, and a typo would corrupt every date on every
     * screen at once.
     *
     * @return array<string, string>
     */
    public static function dateFormats(): array
    {
        return [
            'd/m/Y' => '31/12/2026',
            'm/d/Y' => '12/31/2026',
            'd-m-Y' => '31-12-2026',
            'm-d-Y' => '12-31-2026',
            'Y-m-d' => '2026-12-31',
            'd.m.Y' => '31.12.2026',
        ];
    }
}
