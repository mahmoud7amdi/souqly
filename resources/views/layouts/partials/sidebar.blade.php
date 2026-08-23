{{--
    Sidebar navigation.

    Rebuilt from AdminSidebarMenu (866 lines of imperative menu-building) as a
    declarative list: each entry declares the permissions that reveal it and,
    where relevant, the tenant module it belongs to. Nothing renders that the
    user cannot reach.
--}}
@php
    /**
     * @param  array<int,string>  $permissions  any-of
     */
    $can = function (array $permissions = []) {
        $user = auth()->user();
        if (! $user) { return false; }
        if ($user->isAdmin()) { return true; }
        if (empty($permissions)) { return true; }
        foreach ($permissions as $permission) {
            if ($user->can($permission)) { return true; }
        }
        return false;
    };

    $modules = (array) session('business.enabled_modules');

    $sections = [
        [
            'label' => null,
            'items' => [
                ['route' => 'home', 'label' => __('lang_v1.dashboard'), 'icon' => 'home', 'can' => []],
                /* `cta` promotes the entry to a filled accent button. The POS
                   terminal is the single most-opened screen in the product, so
                   it sits above everything and reads as the primary action
                   rather than one more link in a list. */
                ['route' => 'pos.create', 'label' => __('lang_v1.pos'), 'icon' => 'pos',
                 'can' => ['sell.create'], 'cta' => true],
            ],
        ],
        [
            'label' => __('lang_v1.contacts'),
            'items' => [
                ['route' => 'contacts.index', 'label' => __('lang_v1.contacts'), 'icon' => 'users',
                 'can' => ['supplier.view', 'customer.view', 'supplier.view_own', 'customer.view_own']],
                ['route' => 'customer-group.index', 'label' => __('lang_v1.customer_groups'), 'icon' => 'tag',
                 'can' => ['customer.view']],
            ],
        ],
        [
            'label' => __('lang_v1.products'),
            'items' => [
                ['route' => 'products.index', 'label' => __('lang_v1.products'), 'icon' => 'box', 'can' => ['product.view']],
                ['route' => 'products.create', 'label' => __('lang_v1.add_product'), 'icon' => 'plus', 'can' => ['product.create']],
                ['route' => 'labels.show', 'label' => __('lang_v1.print_labels'), 'icon' => 'barcode', 'can' => ['product.view']],
                ['route' => 'taxonomies.index', 'label' => __('lang_v1.categories'), 'icon' => 'folder', 'can' => ['category.view']],
                ['route' => 'brands.index', 'label' => __('lang_v1.brands'), 'icon' => 'star', 'can' => ['brand.view']],
                ['route' => 'units.index', 'label' => __('lang_v1.units'), 'icon' => 'scale', 'can' => ['unit.view']],
                ['route' => 'variation-templates.index', 'label' => __('lang_v1.variations'), 'icon' => 'layers', 'can' => ['product.view']],
                ['route' => 'warranties.index', 'label' => __('lang_v1.warranties'), 'icon' => 'shield', 'can' => ['product.view']],
                ['route' => 'selling-price-group.index', 'label' => __('lang_v1.selling_price_groups'), 'icon' => 'tag', 'can' => ['product.view']],
                ['route' => 'discount.index', 'label' => __('lang_v1.discounts'), 'icon' => 'discount', 'can' => ['discount.access']],
                ['route' => 'import-products.index', 'label' => __('lang_v1.import_products'), 'icon' => 'upload', 'can' => ['product.create']],
            ],
        ],
        [
            'label' => __('lang_v1.purchases'),
            'items' => [
                ['route' => 'purchase-requisition.index', 'label' => __('lang_v1.purchase_requisitions'), 'icon' => 'clipboard',
                 'can' => ['purchase_requisition.view_all', 'purchase_requisition.view_own'], 'module' => 'purchase_requisition'],
                ['route' => 'purchase-order.index', 'label' => __('lang_v1.purchase_orders'), 'icon' => 'clipboard',
                 'can' => ['purchase_order.view_all', 'purchase_order.view_own'], 'module' => 'purchase_order'],
                ['route' => 'purchases.index', 'label' => __('lang_v1.purchases'), 'icon' => 'truck', 'can' => ['purchase.view', 'view_own_purchase']],
                ['route' => 'purchases.create', 'label' => __('lang_v1.add_purchase'), 'icon' => 'plus', 'can' => ['purchase.create']],
                ['route' => 'purchase-return.index', 'label' => __('lang_v1.purchase_returns'), 'icon' => 'undo', 'can' => ['purchase.view']],
            ],
        ],
        [
            'label' => __('lang_v1.sales'),
            'items' => [
                ['route' => 'sells.index', 'label' => __('lang_v1.all_sales'), 'icon' => 'receipt',
                 'can' => ['sell.view', 'direct_sell.view', 'view_own_sell_only']],
                ['route' => 'sells.create', 'label' => __('lang_v1.add_sale'), 'icon' => 'plus', 'can' => ['direct_sell.access']],
                ['route' => 'sells.drafts', 'label' => __('lang_v1.drafts'), 'icon' => 'document',
                 'can' => ['draft.view_all', 'draft.view_own']],
                ['route' => 'sells.quotations', 'label' => __('lang_v1.quotations'), 'icon' => 'document',
                 'can' => ['quotation.view_all', 'quotation.view_own']],
                ['route' => 'sales-order.index', 'label' => __('lang_v1.sales_orders'), 'icon' => 'clipboard',
                 'can' => ['so.view_all', 'so.view_own'], 'module' => 'sales_order'],
                ['route' => 'sell-return.index', 'label' => __('lang_v1.sell_returns'), 'icon' => 'undo',
                 'can' => ['access_sell_return', 'access_own_sell_return']],
                ['route' => 'shipments.index', 'label' => __('lang_v1.shipments'), 'icon' => 'truck',
                 'can' => ['access_shipping', 'access_own_shipping']],
            ],
        ],
        [
            'label' => __('lang_v1.stock'),
            'items' => [
                ['route' => 'stock-transfers.index', 'label' => __('lang_v1.stock_transfers'), 'icon' => 'transfer', 'can' => ['stock_transfer.view']],
                ['route' => 'stock-adjustments.index', 'label' => __('lang_v1.stock_adjustments'), 'icon' => 'adjust', 'can' => ['stock_adjustment.view']],
                ['route' => 'opening-stock.index', 'label' => __('lang_v1.opening_stock'), 'icon' => 'box', 'can' => ['product.opening_stock']],
                ['route' => 'inventory.index', 'label' => __('lang_v1.stock_count'), 'icon' => 'clipboard',
                 'can' => ['inventorymanagement.view'], 'module' => 'inventorymanagement'],
            ],
        ],
        [
            'label' => __('lang_v1.finance'),
            'items' => [
                ['route' => 'payments.index', 'label' => __('lang_v1.payments'), 'icon' => 'wallet',
                 'can' => ['sell.payments', 'purchase.payments']],
                ['route' => 'expenses.index', 'label' => __('lang_v1.expenses'), 'icon' => 'minus-circle',
                 'can' => ['all_expense.access', 'view_own_expense']],
                ['route' => 'expense-categories.index', 'label' => __('lang_v1.expense_categories'), 'icon' => 'folder',
                 'can' => ['expense.add']],
                ['route' => 'cash-register.index', 'label' => __('lang_v1.cash_register'), 'icon' => 'coins', 'can' => ['view_cash_register']],
                ['route' => 'accounts.index', 'label' => __('lang_v1.payment_accounts'), 'icon' => 'bank',
                 'can' => ['account.access'], 'module' => 'account'],
                ['route' => 'accounting.dashboard', 'label' => __('lang_v1.accounting'), 'icon' => 'book',
                 'can' => ['accounting.journal_entries.create'], 'module' => 'accounting'],
            ],
        ],
        [
            'label' => __('lang_v1.reports'),
            'items' => [
                ['route' => 'reports.index', 'label' => __('lang_v1.reports'), 'icon' => 'chart',
                 'can' => ['purchase_n_sell_report.view', 'stock_report.view', 'profit_loss_report.view',
                           'tax_report.view', 'expense_report.view', 'register_report.view']],
            ],
        ],
        [
            'label' => __('lang_v1.hrm'),
            'items' => [
                ['route' => 'hrm.dashboard', 'label' => __('lang_v1.hrm'), 'icon' => 'users',
                 'can' => ['essentials.crud_all_attendance', 'essentials.view_own_attendance'], 'module' => 'essentials'],
                ['route' => 'assets.index', 'label' => __('lang_v1.assets'), 'icon' => 'box',
                 'can' => ['asset.view'], 'module' => 'assetmanagement'],
            ],
        ],
        [
            'label' => __('lang_v1.settings'),
            'items' => [
                ['route' => 'users.index', 'label' => __('lang_v1.users'), 'icon' => 'users', 'can' => ['user.view']],
                ['route' => 'roles.index', 'label' => __('lang_v1.roles'), 'icon' => 'key', 'can' => ['roles.view']],
                ['route' => 'business-location.index', 'label' => __('lang_v1.business_locations'), 'icon' => 'store',
                 'can' => ['business_settings.access']],
                ['route' => 'tax-rates.index', 'label' => __('lang_v1.tax_rates'), 'icon' => 'percent', 'can' => ['tax_rate.view']],
                ['route' => 'invoice-schemes.index', 'label' => __('lang_v1.invoice_schemes'), 'icon' => 'hash',
                 'can' => ['invoice_settings.access']],
                ['route' => 'invoice-layouts.index', 'label' => __('lang_v1.invoice_layouts'), 'icon' => 'document',
                 'can' => ['invoice_settings.access']],
                ['route' => 'barcodes.index', 'label' => __('lang_v1.barcode_settings'), 'icon' => 'barcode',
                 'can' => ['barcode_settings.access']],
                ['route' => 'printers.index', 'label' => __('lang_v1.printers'), 'icon' => 'printer', 'can' => ['access_printers']],
                ['route' => 'notification-templates.index', 'label' => __('lang_v1.notification_templates'), 'icon' => 'mail',
                 'can' => ['business_settings.access']],
                ['route' => 'business.settings', 'label' => __('lang_v1.business_settings'), 'icon' => 'cog',
                 'can' => ['business_settings.access']],
            ],
        ],
    ];
@endphp

@foreach ($sections as $section)
    @php
        $visible = collect($section['items'])->filter(function ($item) use ($can, $modules) {
            if (! empty($item['module']) && ! in_array($item['module'], $modules, true)) {
                return false;
            }
            if (! \Illuminate\Support\Facades\Route::has($item['route'])) {
                return false;
            }
            return $can($item['can']);
        });
    @endphp

    @if ($visible->isNotEmpty())
        @if (! empty($section['label']))
            <p class="nav-section">{{ $section['label'] }}</p>
        @endif

        @foreach ($visible as $item)
            @if (! empty($item['cta']))
                <a href="{{ route($item['route']) }}" class="btn-accent btn-block my-1.5">
                    <x-nav-icon :name="$item['icon']" />
                    <span class="truncate">{{ $item['label'] }}</span>
                </a>
            @else
                <a href="{{ route($item['route']) }}"
                   class="nav-link {{ request()->routeIs($item['route']) ? 'nav-link-active' : '' }}"
                   @if (request()->routeIs($item['route'])) aria-current="page" @endif>
                    <x-nav-icon :name="$item['icon']" />
                    <span class="truncate">{{ $item['label'] }}</span>
                </a>
            @endif
        @endforeach
    @endif
@endforeach
