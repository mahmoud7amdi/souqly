<?php

namespace App\Support;

/**
 * The canonical permission catalogue.
 *
 * Names are preserved byte-for-byte from the source system, because the
 * sidebar, the report screens and every `can()` check key off them.
 *
 * Excluded on purpose (see NOTES.md):
 *   - `manufacturing.*` — module removed at your request.
 *   - `repair.*`, `client.clients.*` — modules absent from the source repo.
 *   - Indian GST report permissions — out of scope for the Arab market.
 *
 * Location permissions (`location.<id>`) are generated per location at
 * runtime and are deliberately not listed here.
 */
final class Permissions
{
    /**
     * Grouped for the role editor UI. Keys are group labels (translation
     * keys), values are the permission names in that group.
     *
     * @return array<string, array<int, string>>
     */
    public static function grouped(): array
    {
        return [
            'user_management' => [
                'user.view', 'user.create', 'user.update', 'user.delete',
                'roles.view', 'roles.create', 'roles.update', 'roles.delete',
            ],

            'contact' => [
                'supplier.view', 'supplier.create', 'supplier.update',
                'supplier.delete', 'supplier.view_own',
                'customer.view', 'customer.create', 'customer.update',
                'customer.delete', 'customer.view_own',
            ],

            'product' => [
                'product.view', 'product.create', 'product.update',
                'product.delete', 'product.opening_stock',
                'brand.view', 'brand.create', 'brand.update', 'brand.delete',
                'unit.view', 'unit.create', 'unit.update', 'unit.delete',
                'category.view', 'category.create', 'category.update', 'category.delete',
                'tax_rate.view', 'tax_rate.create', 'tax_rate.update', 'tax_rate.delete',
                'view_purchase_price', 'view_product_stock_value',
                'access_default_selling_price',
                'discount.access',
            ],

            'purchase' => [
                'purchase.view', 'purchase.create', 'purchase.update',
                'purchase.delete', 'purchase.payments', 'purchase.update_status',
                'view_own_purchase',
                'purchase_order.view_all', 'purchase_order.view_own',
                'purchase_order.create', 'purchase_order.update', 'purchase_order.delete',
                'purchase_requisition.view_all', 'purchase_requisition.view_own',
                'purchase_requisition.create', 'purchase_requisition.delete',
                'edit_purchase_payment', 'delete_purchase_payment',
            ],

            'sell' => [
                'sell.view', 'sell.create', 'sell.update', 'sell.delete',
                'sell.payments', 'sell.store',
                'direct_sell.access', 'direct_sell.view', 'direct_sell.update',
                'direct_sell.delete',
                'view_own_sell_only', 'view_paid_sells_only', 'view_due_sells_only',
                'view_partial_sells_only', 'view_overdue_sells_only',
                'access_sell_return', 'access_own_sell_return',
                'draft.view_all', 'draft.view_own', 'draft.update', 'draft.delete',
                'quotation.view_all', 'quotation.view_own', 'quotation.update',
                'quotation.delete',
                'so.view_all', 'so.view_own', 'so.create', 'so.update', 'so.delete',
                'edit_invoice_number',
                'edit_product_price_from_sale_screen',
                'edit_product_discount_from_sale_screen',
                'edit_sell_payment', 'delete_sell_payment',
                'access_shipping', 'access_own_shipping',
                'access_commission_agent_shipping', 'access_pending_shipments_only',
                'view_commission_agent_sell',
                'access_types_of_service',
            ],

            'pos' => [
                'edit_pos_payment',
                'edit_product_price_from_pos_screen',
                'edit_product_discount_from_pos_screen',
                'view_cash_register', 'close_cash_register',
            ],

            'stock' => [
                'stock_transfer.view', 'stock_transfer.create',
                'stock_transfer.update', 'stock_transfer.delete',
                'stock_adjustment.view', 'stock_adjustment.create',
                'stock_adjustment.update', 'stock_adjustment.delete',
                'inventorymanagement.view',
            ],

            'expense' => [
                'all_expense.access', 'view_own_expense',
                'expense.add', 'expense.edit', 'expense.delete',
            ],

            'account' => [
                'account.access', 'view_account_balance',
                'edit_account_transaction', 'delete_account_transaction',
            ],

            'report' => [
                'purchase_n_sell_report.view', 'contacts_report.view',
                'stock_report.view', 'tax_report.view',
                'trending_product_report.view', 'expense_report.view',
                'register_report.view', 'sales_representative.view',
                'profit_loss_report.view', 'report.stock_details',
                'customer_group_report.view', 'user_performance_report.view',
                'view_export_buttons', 'dashboard.data',
            ],

            'settings' => [
                'business_settings.access', 'barcode_settings.access',
                'invoice_settings.access', 'access_printers',
                'access_all_locations',
            ],

            'essentials' => [
                'essentials.access_sales_target',
                'essentials.add_todos', 'essentials.edit_todos',
                'essentials.delete_todos', 'essentials.assign_todos',
                'essentials.view_message', 'essentials.create_message',
                'essentials.crud_department', 'essentials.crud_designation',
                'essentials.crud_leave_type',
                'essentials.crud_own_leave', 'essentials.crud_all_leave',
                'essentials.approve_leave',
                'essentials.view_own_attendance', 'essentials.crud_all_attendance',
                'essentials.allow_users_for_attendance_from_web',
                'essentials.view_allowance_and_deduction',
                'essentials.add_allowance_and_deduction',
                'essentials.create_payroll', 'essentials.update_payroll',
                'essentials.delete_payroll', 'essentials.view_all_payroll',
                'edit_essentials_settings',
            ],

            'accounting' => [
                'accounting.chart_of_accounts.create',
                'accounting.journal_entries.create',
                'accounting.journal_entries.reverse',
                'accounting.transfers.create',
                'accounting.cost_centers.create',
                'accounting.cost_centers.edit',
            ],

            'asset' => [
                'asset.view', 'asset.create', 'asset.update', 'asset.delete',
                'asset.view_own_maintenance', 'asset.view_all_maintenance',
            ],

            'superadmin' => [
                'superadmin.access_package_subscriptions',
                'access_package_subscriptions',
            ],
        ];
    }

    /**
     * Flat list of every permission name.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_values(array_unique(
            array_merge(...array_values(static::grouped()))
        ));
    }

    /**
     * Permissions granted to the default Cashier role on business creation.
     *
     * @return array<int, string>
     */
    public static function cashierDefaults(): array
    {
        return [
            'sell.view', 'sell.create', 'sell.update', 'sell.delete',
            'access_all_locations', 'view_cash_register', 'close_cash_register',
        ];
    }

    /**
     * Permissions that belong to an optional module, keyed by the module name
     * in `business.enabled_modules`. Used to hide irrelevant permissions from
     * the role editor.
     *
     * @return array<string, string>  permission prefix => module
     */
    public static function moduleMap(): array
    {
        return [
            'essentials.' => 'essentials',
            'accounting.' => 'accounting',
            'asset.' => 'assetmanagement',
            'inventorymanagement.' => 'inventorymanagement',
            'superadmin.' => 'superadmin',
            'purchase_order.' => 'purchase_order',
            'purchase_requisition.' => 'purchase_requisition',
            'so.' => 'sales_order',
            'account.' => 'account',
        ];
    }

    /**
     * The permission name granting access to one location.
     */
    public static function forLocation(int $locationId): string
    {
        return 'location.'.$locationId;
    }

    /**
     * Human label for one permission, for the role editor's checkbox grid.
     *
     * The labels live in a `lang_v1.perm` sub-array rather than as ~180
     * top-level `perm_<name>` keys, for two reasons. It keeps the lang file
     * navigable, and it side-steps the dot in every permission name: `__()`
     * splits `lang_v1.perm.user.view` on dots and would look for a nested
     * `user` → `view`, never finding the flat `'user.view'` entry. Reading the
     * whole map once with {@see trans()} and indexing it directly is both
     * correct and one translator call instead of a hundred and eighty.
     *
     * The fallback matters more than it looks. A permission with no label would
     * otherwise render the raw string `lang_v1.perm_user.view` on screen — the
     * exact failure the render walk's untranslated-key guard exists to catch.
     * Degrading to "User View" keeps a new permission readable the moment it is
     * added to {@see grouped()}, before anyone has written its translation.
     */
    public static function label(string $name): string
    {
        $labels = trans('lang_v1.perm');

        return is_array($labels) && isset($labels[$name])
            ? $labels[$name]
            : static::humanise($name);
    }

    /**
     * Human label for a permission group heading.
     *
     * Same lookup and same fallback as {@see label()}; the keys here are the
     * group names returned by {@see grouped()}.
     */
    public static function groupLabel(string $group): string
    {
        $labels = trans('lang_v1.perm_group');

        return is_array($labels) && isset($labels[$group])
            ? $labels[$group]
            : static::humanise($group);
    }

    /**
     * Last-resort readable form of a permission or group name.
     */
    protected static function humanise(string $name): string
    {
        return \Illuminate\Support\Str::headline(
            str_replace(['.', '_'], ' ', $name)
        );
    }
}
