<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\InvoiceLayout;
use App\Models\InvoiceScheme;
use App\Models\Role;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permissions;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Tenant provisioning: creates a business together with everything it needs to
 * be usable on first login.
 */
class BusinessService
{
    public function __construct(private ReferenceService $references) {}

    /**
     * Register a new business and its owner.
     *
     * @param  array<string, mixed>  $businessData
     * @param  array<string, mixed>  $ownerData
     * @return array{business: Business, owner: User}
     */
    public function register(array $businessData, array $ownerData): array
    {
        return DB::transaction(function () use ($businessData, $ownerData) {
            $owner = $this->createOwner($ownerData);

            $business = Business::create(array_merge([
                'owner_id' => $owner->id,
                'created_by' => $owner->id,
                'is_active' => true,
                'accounting_method' => 'fifo',
                'sell_price_tax' => 'includes',
                'currency_symbol_placement' => 'before',
                'currency_precision' => 2,
                'quantity_precision' => 2,
                'date_format' => 'd/m/Y',
                'time_format' => '24',
                'fy_start_month' => 1,
                'transaction_edit_days' => 30,
                'stock_expiry_alert_days' => 30,
                'enabled_modules' => ['purchase_order', 'account'],
                'pos_settings' => $this->defaultPosSettings(),
            ], $businessData));

            $owner->business_id = $business->id;
            $owner->save();

            // Bind so the global scopes stamp business_id on everything below.
            Tenancy::bind($business->id);

            $this->createDefaultResources($business, $owner);

            return ['business' => $business->fresh(), 'owner' => $owner->fresh()];
        });
    }

    /**
     * Everything a brand-new business needs: roles, the walk-in customer, an
     * invoice scheme + layout, a location, a unit and a tax rate.
     */
    public function createDefaultResources(Business $business, User $owner): void
    {
        [$adminRole] = $this->createDefaultRoles($business);

        $owner->assignRole($adminRole);

        $this->createWalkInCustomer($business, $owner);

        $scheme = InvoiceScheme::create([
            'business_id' => $business->id,
            'name' => __('lang_v1.default'),
            'scheme_type' => 'blank',
            'prefix' => '',
            'start_number' => 1,
            'invoice_count' => 0,
            'total_digits' => 4,
            'is_default' => true,
        ]);

        $layout = InvoiceLayout::create([
            'business_id' => $business->id,
            'name' => __('lang_v1.default'),
            'is_default' => true,
            'show_logo' => 0,
            'show_business_name' => 1,
            'show_location_name' => 1,
            'show_mobile_number' => 1,
            'show_tax_1' => 1,
            'show_sku' => 1,
            'show_time' => 1,
        ]);

        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'name' => $business->name,
            'location_id' => $this->references->generate('business_location', $business->id),
            'invoice_scheme_id' => $scheme->id,
            'invoice_layout_id' => $layout->id,
            'is_active' => true,
            'receipt_printer_type' => 'browser',
            'print_receipt_on_invoice' => 1,
        ]);

        // Every location gets its own permission; the admin role gets all.
        $this->createLocationPermission($location);

        Unit::create([
            'business_id' => $business->id,
            'actual_name' => __('lang_v1.pieces'),
            'short_name' => __('lang_v1.pcs'),
            'allow_decimal' => 0,
            'created_by' => $owner->id,
        ]);

        TaxRate::create([
            'business_id' => $business->id,
            'name' => 'VAT',
            'calculation_type' => 'percentage',
            'amount' => 15,
            'created_by' => $owner->id,
        ]);
    }

    /**
     * Create the two default, tenant-namespaced roles.
     *
     * Admin carries no explicit permissions — `User::isAdmin()` short-circuits
     * every check, matching the source system.
     *
     * @return array{0: Role, 1: Role}
     */
    public function createDefaultRoles(Business $business): array
    {
        $admin = Role::create([
            'name' => Role::nameFor('Admin', $business->id),
            'business_id' => $business->id,
            'is_default' => true,
            'guard_name' => 'web',
        ]);

        $cashier = Role::create([
            'name' => Role::nameFor('Cashier', $business->id),
            'business_id' => $business->id,
            'is_default' => true,
            'guard_name' => 'web',
        ]);

        $cashier->syncPermissions(Permissions::cashierDefaults());

        return [$admin, $cashier];
    }

    /**
     * The default customer used by the POS when none is chosen.
     */
    public function createWalkInCustomer(Business $business, User $owner): Contact
    {
        return Contact::create([
            'business_id' => $business->id,
            'type' => 'customer',
            'name' => __('lang_v1.walk_in_customer'),
            'first_name' => __('lang_v1.walk_in_customer'),
            'mobile' => '',
            'is_default' => 1,
            'contact_status' => 'active',
            'created_by' => $owner->id,
            'contact_id' => $this->references->generate('contact', $business->id),
        ]);
    }

    /**
     * Register the `location.<id>` permission for a location and grant it to
     * every role that already has blanket location access.
     */
    public function createLocationPermission(BusinessLocation $location): void
    {
        \Spatie\Permission\Models\Permission::findOrCreate(
            Permissions::forLocation($location->id),
            'web'
        );
    }

    /**
     * Default POS behaviour for a new tenant.
     *
     * @return array<string, mixed>
     */
    public function defaultPosSettings(): array
    {
        return [
            'amount_rounding_method' => null,
            'disable_pay_checkout' => 0,
            'disable_draft' => 0,
            'disable_express_checkout' => 0,
            'hide_product_suggestion' => 0,
            'hide_recent_trans' => 0,
            'disable_discount' => 0,
            'disable_order_tax' => 0,
            'is_pos_subtotal_editable' => 0,
            'print_invoice_on_suspend' => 1,
            'enable_msg_to_customer' => 0,
            'show_invoice_scheme' => 0,
            'show_pricing_on_product_sugesstion' => 1,
            'inline_service_staff' => 0,
            'enable_transaction_date_on_pos' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createOwner(array $data): User
    {
        return User::create([
            'user_type' => 'user',
            'surname' => $data['surname'] ?? null,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password']),
            'language' => $data['language'] ?? config('app.locale'),
            'status' => 'active',
            'allow_login' => 1,
        ]);
    }

    /**
     * Financial-year window for a business.
     *
     * @return array{start: string, end: string}
     */
    public function currentFinancialYear(Business $business): array
    {
        $start = now()->startOfMonth()->month((int) ($business->fy_start_month ?: 1));

        if (now()->lessThan($start)) {
            $start->subYear();
        }

        return [
            'start' => $start->toDateString(),
            'end' => $start->copy()->addYear()->subDay()->toDateString(),
        ];
    }
}
