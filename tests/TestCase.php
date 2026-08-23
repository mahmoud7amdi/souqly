<?php

namespace Tests;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Currency;
use App\Models\InvoiceLayout;
use App\Models\InvoiceScheme;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Variation;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected ?Business $business = null;

    protected ?BusinessLocation $location = null;

    protected ?User $user = null;

    protected function tearDown(): void
    {
        Tenancy::forget();

        parent::tearDown();
    }

    /**
     * Build a minimal but complete tenant: currency, business, owner, unit,
     * tax rate, invoice scheme/layout and one location. Binds it as the active
     * tenant so global scopes behave as they do in a real request.
     */
    protected function createTenant(array $businessOverrides = []): Business
    {
        $currency = Currency::create([
            'country' => 'Egypt',
            'currency' => 'Egyptian Pound',
            'code' => 'EGP',
            'symbol' => 'ج.م',
            'thousand_separator' => ',',
            'decimal_separator' => '.',
        ]);

        // Owner must exist before business (business.owner_id is a FK), and
        // business must exist before we can set the owner's business_id.
        $owner = User::create([
            'user_type' => 'user',
            'first_name' => 'Owner',
            'username' => 'owner'.uniqid(),
            'password' => 'secret',
            'language' => 'ar',
            'status' => 'active',
        ]);

        $this->business = Business::create(array_merge([
            'name' => 'Souqly Test Co.',
            'currency_id' => $currency->id,
            'owner_id' => $owner->id,
            'start_date' => now()->subYear()->toDateString(),
            'accounting_method' => 'fifo',
            'sell_price_tax' => 'excludes',
            'currency_precision' => 2,
            'quantity_precision' => 2,
            'enabled_modules' => ['account', 'purchase_order'],
        ], $businessOverrides));

        $owner->business_id = $this->business->id;
        $owner->save();

        $this->user = $owner;
        Tenancy::bind($this->business->id);

        $scheme = InvoiceScheme::create([
            'business_id' => $this->business->id,
            'name' => 'Default',
            'scheme_type' => 'blank',
            'prefix' => 'INV',
            'start_number' => 1,
            'invoice_count' => 0,
            'total_digits' => 4,
            'is_default' => true,
        ]);

        $layout = InvoiceLayout::create([
            'business_id' => $this->business->id,
            'name' => 'Default',
            'is_default' => true,
        ]);

        $this->location = BusinessLocation::create([
            'business_id' => $this->business->id,
            'name' => 'Main Store',
            'invoice_scheme_id' => $scheme->id,
            'invoice_layout_id' => $layout->id,
            'is_active' => true,
        ]);

        return $this->business;
    }

    /**
     * A single, stock-tracked product with one variation.
     */
    protected function createProduct(array $overrides = []): Product
    {
        $unit = Unit::firstOrCreate(
            ['business_id' => $this->business->id, 'short_name' => 'Pc'],
            [
                'actual_name' => 'Pieces',
                'allow_decimal' => 0,
                'created_by' => $this->user->id,
            ]
        );

        $product = Product::create(array_merge([
            'name' => 'Test Product',
            'business_id' => $this->business->id,
            'type' => 'single',
            'unit_id' => $unit->id,
            'tax_type' => 'exclusive',
            'enable_stock' => 1,
            'alert_quantity' => 0,
            'sku' => 'SKU'.uniqid(),
            'barcode_type' => 'C128',
            'created_by' => $this->user->id,
        ], $overrides));

        $productVariation = ProductVariation::create([
            'product_id' => $product->id,
            'name' => 'DUMMY',
            'is_dummy' => 1,
        ]);

        Variation::create([
            'product_id' => $product->id,
            'product_variation_id' => $productVariation->id,
            'name' => 'DUMMY',
            'sub_sku' => $product->sku,
            'default_purchase_price' => 0,
            'dpp_inc_tax' => 0,
            'profit_percent' => 0,
            'default_sell_price' => 0,
            'sell_price_inc_tax' => 0,
        ]);

        return $product->fresh('variations');
    }

    /**
     * The (single) variation of a product.
     */
    protected function variationOf(Product $product): Variation
    {
        return $product->variations()->firstOrFail();
    }

    protected function taxRate(float $percent = 15.0): TaxRate
    {
        return TaxRate::firstOrCreate(
            ['business_id' => $this->business->id, 'name' => 'VAT '.$percent],
            [
                'calculation_type' => 'percentage',
                'amount' => $percent,
                'created_by' => $this->user->id,
            ]
        );
    }
}
