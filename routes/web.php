<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CashRegisterController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CustomerGroupController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImportProductsController;
use App\Http\Controllers\LabelsController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseRequisitionController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SellController;
use App\Http\Controllers\SellingPriceGroupController;
use App\Http\Controllers\SellPosController;
use App\Http\Controllers\SellReturnController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\TaxonomyController;
use App\Http\Controllers\TaxRateController;
use App\Http\Controllers\TransactionPaymentController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VariationTemplateController;
use App\Http\Controllers\WarrantyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| Middleware groups (see bootstrap/app.php):
|   tenant     — session data + language + timezone. Used by print/download
|                and notification endpoints.
|   tenant.ui  — the above plus the login-eligibility gate. Every screen.
|
| The sidebar renders each entry only when `Route::has()` finds its route, so
| routes can be added incrementally without breaking navigation.
*/

/* ---------------------------------------------------------------- *
 | Public
 * ---------------------------------------------------------------- */
Route::get('/', fn () => redirect()->route('home'));

// Connectivity probe for the offline-capable POS. Deliberately unauthenticated
// and dependency-free so it answers even when the session store is down.
Route::get('/api/ping', fn () => response()->json([
    'ok' => true,
    'time' => now()->toIso8601String(),
]))->name('api.ping');

Route::get('/pwa/manifest.json', function () {
    return response()->json(array_merge(config('pwa.manifest'), [
        'icons' => [
            ['src' => asset('img/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => asset('img/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
        ],
    ]));
})->name('pwa.manifest');

/* ---------------------------------------------------------------- *
 | Authentication
 * ---------------------------------------------------------------- */
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:6,1');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

/* ---------------------------------------------------------------- *
 | Application
 * ---------------------------------------------------------------- */
Route::middleware(['auth', 'tenant.ui'])->group(function () {

    /* --- Dashboard --- */
    Route::get('/home', [HomeController::class, 'index'])->name('home');
    Route::get('/clear-status-message', [HomeController::class, 'clearStatusMessage'])
        ->name('status.clear');

    /* --- Own profile --- */
    Route::get('/user/profile', [UserController::class, 'getProfile'])->name('user.profile');
    Route::post('/user/update', [UserController::class, 'updateProfile'])->name('user.update');
    Route::post('/user/update-password', [UserController::class, 'updatePassword'])
        ->name('user.updatePassword');
    Route::post('/user/switch-language', [UserController::class, 'switchLanguage'])
        ->name('user.switchLanguage');

    /* --- Notifications --- */
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('notifications.unreadCount');
    Route::get('/notifications/{id}', [NotificationController::class, 'show'])
        ->name('notifications.show');
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])
        ->name('notifications.markAllRead');

    /* --- Settings: reference data --- */
    Route::resource('brands', BrandController::class)->except(['show']);
    Route::resource('units', UnitController::class)->except(['show']);
    Route::resource('tax-rates', TaxRateController::class)->except(['show']);
    Route::resource('warranties', WarrantyController::class)->except(['show']);
    Route::resource('taxonomies', TaxonomyController::class)->except(['show']);
    Route::resource('variation-templates', VariationTemplateController::class)->except(['show']);

    Route::get('/selling-price-group/{id}/toggle', [SellingPriceGroupController::class, 'activateDeactivate'])
        ->name('selling-price-group.toggle');
    Route::resource('selling-price-group', SellingPriceGroupController::class)->except(['show']);

    Route::get('/discount/{id}/toggle', [DiscountController::class, 'activate'])->name('discount.toggle');
    Route::resource('discount', DiscountController::class)->except(['show']);

    /* --- Products --- */
    Route::controller(ProductController::class)->group(function () {
        // AJAX helpers must precede the resource so `products/list` is not
        // captured by `products/{product}`.
        Route::get('/products/list', 'getProducts')->name('products.list');
        Route::post('/products/sub-categories', 'getSubCategories')->name('products.subCategories');
        Route::get('/products/sub-units', 'getSubUnits')->name('products.subUnits');
        Route::post('/products/variation-template', 'getVariationTemplate')->name('products.variationTemplate');
        Route::post('/products/check-sku', 'checkProductSku')->name('products.checkSku');
        Route::post('/products/mass-deactivate', 'massDeactivate')->name('products.massDeactivate');
        Route::get('/products/{id}/activate', 'activate')->name('products.activate');
        Route::get('/products/{id}/selling-prices', 'addSellingPrices')->name('products.addSellingPrices');
        Route::post('/products/{id}/selling-prices', 'saveSellingPrices')->name('products.saveSellingPrices');
        Route::get('/products/{id}/stock-history', 'productStockHistory')->name('products.stockHistory');
        Route::get('/products/price-history/{variation}', 'priceHistory')->name('products.priceHistory');
    });
    Route::resource('products', ProductController::class);

    /* --- Labels --- */
    Route::get('/labels', [LabelsController::class, 'show'])->name('labels.show');
    Route::get('/labels/products', [LabelsController::class, 'getProductsByFilters'])->name('labels.products');
    Route::post('/labels/preview', [LabelsController::class, 'preview'])->name('labels.preview');

    /* --- Product import --- */
    Route::get('/import-products', [ImportProductsController::class, 'index'])->name('import-products.index');
    Route::get('/import-products/template', [ImportProductsController::class, 'template'])
        ->name('import-products.template');
    Route::post('/import-products', [ImportProductsController::class, 'store'])->name('import-products.store');

    /* --- Contacts --- */
    Route::controller(ContactController::class)->group(function () {
        // Fixed segments before the resource, so they aren't eaten by {contact}.
        Route::get('/contacts/search', 'search')->name('contacts.search');
        Route::get('/contacts/import', 'importForm')->name('contacts.import.form');
        Route::get('/contacts/import/template', 'importTemplate')->name('contacts.import.template');
        Route::post('/contacts/import', 'import')->name('contacts.import');
        Route::get('/contacts/{id}/ledger', 'ledger')->name('contacts.ledger');
        Route::get('/contacts/{id}/due', 'due')->name('contacts.due');
        Route::get('/contacts/{id}/status', 'updateStatus')->name('contacts.status');
        Route::get('/contacts/{id}/opening-balance', 'editOpeningBalance')
            ->name('contacts.openingBalance.edit');
        Route::post('/contacts/{id}/opening-balance', 'updateOpeningBalance')
            ->name('contacts.openingBalance.update');
    });
    Route::resource('contacts', ContactController::class);

    Route::resource('customer-group', CustomerGroupController::class)->except(['show']);

    /* --- Purchases --- */
    Route::controller(PurchaseController::class)->group(function () {
        Route::post('/purchases/{id}/status', 'updateStatus')->name('purchases.updateStatus');
        Route::get('/purchases/order-lines/{order}', 'orderLines')->name('purchases.orderLines');
        Route::get('/purchases/supplier-orders/{contact}', 'ordersForSupplier')
            ->name('purchases.supplierOrders');
    });
    Route::resource('purchases', PurchaseController::class);

    /* --- Purchase orders --- */
    Route::get('/purchase-order/{id}/pdf', [PurchaseOrderController::class, 'downloadPdf'])
        ->name('purchase-order.pdf');
    Route::post('/purchase-order/{id}/status', [PurchaseOrderController::class, 'updateOrderStatus'])
        ->name('purchase-order.updateStatus');
    Route::resource('purchase-order', PurchaseOrderController::class);

    /* --- Purchase requisitions --- */
    Route::get('/purchase-requisition/outstanding-lines',
        [PurchaseRequisitionController::class, 'outstandingLines'])
        ->name('purchase-requisition.outstandingLines');
    Route::resource('purchase-requisition', PurchaseRequisitionController::class);

    /* --- Purchase returns --- */
    Route::get('/purchase-return', [PurchaseReturnController::class, 'index'])
        ->name('purchase-return.index');
    Route::get('/purchase-return/create/{purchase}', [PurchaseReturnController::class, 'create'])
        ->name('purchase-return.create');
    Route::post('/purchase-return/{purchase}', [PurchaseReturnController::class, 'store'])
        ->name('purchase-return.store');
    Route::get('/purchase-return/{id}', [PurchaseReturnController::class, 'show'])
        ->name('purchase-return.show');

    /* --- POS --- *
     |
     | Two routes and no resource: the terminal has no listing, no edit and no
     | show of its own. What it creates is an ordinary sale, so `sells.show` is
     | where a POS receipt is read.
     */
    Route::get('/pos', [SellPosController::class, 'create'])->name('pos.create');
    Route::post('/pos', [SellPosController::class, 'store'])->name('pos.store');

    /* --- Sales --- */
    Route::controller(SellController::class)->group(function () {
        // Fixed segments and AJAX helpers must precede the resource, or
        // `sells/drafts` is swallowed by `sells/{sell}`.
        Route::get('/sells/drafts', 'drafts')->name('sells.drafts');
        Route::get('/sells/quotations', 'quotations')->name('sells.quotations');
        Route::get('/sells/order-lines/{order}', 'orderLines')->name('sells.orderLines');
        Route::get('/sells/customer-orders/{contact}', 'ordersForCustomer')
            ->name('sells.customerOrders');
        // POST, not GET: finalising consumes stock.
        Route::post('/sells/{id}/convert', 'convert')->name('sells.convert');
        Route::post('/sells/{id}/shipping', 'updateShipping')->name('sells.updateShipping');
    });
    Route::resource('sells', SellController::class);

    /* --- Sales orders --- */
    Route::post('/sales-order/{id}/status', [SalesOrderController::class, 'updateOrderStatus'])
        ->name('sales-order.updateStatus');
    Route::resource('sales-order', SalesOrderController::class);

    /* --- Sell returns --- */
    Route::get('/sell-return', [SellReturnController::class, 'index'])
        ->name('sell-return.index');
    Route::get('/sell-return/create/{sell}', [SellReturnController::class, 'create'])
        ->name('sell-return.create');
    Route::post('/sell-return/{sell}', [SellReturnController::class, 'store'])
        ->name('sell-return.store');
    Route::get('/sell-return/{id}', [SellReturnController::class, 'show'])
        ->name('sell-return.show');

    /* --- Shipments --- *
     |
     | Read-only: a shipment is a sale seen through its shipping columns, and the
     | one write lives on sells.updateShipping.
     */
    Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');

    /* ================================================================ *
     | Payments & finance
     ================================================================ */

    /* --- Payments --- *
     |
     | `payments.create` takes its target from the query string
     | (`?transaction_id=` to pay one document, `?contact_id=` to settle a
     | balance) rather than from the path. Two reasons: `purchase/show` and
     | `sell/show` already link to it that way, and a payment's target is a
     | choice the form can change — moving it into the URL would mean a redirect
     | on every switch.
     */
    Route::resource('payments', TransactionPaymentController::class)
        ->parameters(['payments' => 'id']);

    /* --- Expenses --- */
    Route::resource('expenses', ExpenseController::class)
        ->parameters(['expenses' => 'id']);

    Route::resource('expense-categories', ExpenseCategoryController::class)
        ->parameters(['expense-categories' => 'id'])
        ->except(['show']);

    /* --- Cash register --- *
     |
     | `cash-register/close/{id}` puts the verb before the id so the fixed segment
     | is not eaten by `cash-register/{id}`. The GET renders the counting form and
     | the POST commits it: closing a drawer is a write, and a link that closes a
     | shift on hover is exactly the kind of thing a browser prefetch does.
     */
    Route::controller(CashRegisterController::class)->group(function () {
        Route::get('/cash-register/create', 'create')->name('cash-register.create');
        Route::post('/cash-register', 'store')->name('cash-register.store');
        Route::get('/cash-register/close/{id}', 'closeForm')->name('cash-register.closeForm');
        Route::post('/cash-register/close/{id}', 'close')->name('cash-register.close');
        Route::get('/cash-register/{id}', 'show')->name('cash-register.show');
        Route::get('/cash-register', 'index')->name('cash-register.index');
    });

    /* --- Payment accounts --- *
     |
     | Movements hang off the account rather than living at the top level: an
     | account transaction has no meaning apart from the account it is on, and
     | nesting keeps the ownership check in one place — the account is loaded
     | first, then the entry is looked up within it.
     */
    Route::controller(AccountController::class)->group(function () {
        Route::post('/accounts/{id}/deposit', 'deposit')->name('accounts.deposit');
        Route::post('/accounts/{id}/withdraw', 'withdraw')->name('accounts.withdraw');
        Route::post('/accounts/{id}/transfer', 'transfer')->name('accounts.transfer');
        Route::post('/accounts/{id}/closed', 'setClosed')->name('accounts.setClosed');
        Route::put('/accounts/{id}/transactions/{entry}', 'updateTransaction')
            ->name('accounts.transactions.update');
        Route::delete('/accounts/{id}/transactions/{entry}', 'destroyTransaction')
            ->name('accounts.transactions.destroy');
    });
    Route::resource('accounts', AccountController::class)
        ->parameters(['accounts' => 'id'])
        ->except(['destroy']);
});
