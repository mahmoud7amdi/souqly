<?php

namespace Tests\Feature;

use App\Models\BusinessLocation;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Models\Variation;
use App\Models\VariationTemplate;
use App\Models\VariationValueTemplate;
use App\Services\BusinessService;
use App\Support\Permissions;
use App\Support\Tenancy;
use App\Support\TransactionTypes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The product form's two hard shapes: `variable` and `combo`.
 *
 * These exist because of a bug that had no test anywhere near it. `products.store`
 * accepted a variable product, validated its name, its unit and its barcode type,
 * and then created it with **zero variations** — nothing to stock, nothing to
 * price, nothing to sell — and flashed "added successfully". The form had no
 * variations editor at all, so there was no way to supply them and no rule that
 * asked. Combo had the identical defect.
 *
 * Nothing caught it because nothing posted to `products.store`: the only variable
 * product in the suite was built directly through the model in
 * {@see ScreensRenderTest}, which is exactly the path that skips the controller.
 *
 * So the tests here are deliberately split three ways, and the split is the point:
 *
 * - **The editor exists.** `renders_*` asserts the markup and its clone templates
 *   are on the page. A missing section is not a wrong figure — no arithmetic test
 *   can see it — and it is what actually shipped.
 * - **The refusal.** `cannot_be_created_without_*` pins the loud failure that
 *   replaced the silent success. This is the assertion that would have caught the
 *   original bug, and it has to keep failing for the right reason.
 * - **The arithmetic.** Values become variations, sub-SKUs stay unique across an
 *   append, prices derive their missing side, and pruning drops the editor's
 *   trailing blank row instead of rejecting it.
 */
class ProductsTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $businessId;

    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Seeded inside this test's transaction, so anything spatie cached during
        // an earlier test points at ids that no longer exist.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $currency = \App\Models\Currency::firstOrCreate(
            ['code' => 'EGP'],
            ['country' => 'Egypt', 'currency' => 'Egyptian Pound', 'symbol' => 'ج.م',
                'thousand_separator' => ',', 'decimal_separator' => '.']
        );

        // `register()` and not `createTenant()`: "admin" is a role, and only
        // register() seeds it. `permit()` short-circuits on isAdmin(), so a
        // tenant without the role turns every assertion here into a 403.
        ['owner' => $owner, 'business' => $business] = app(BusinessService::class)->register(
            ['name' => 'Products Co.', 'currency_id' => $currency->id],
            ['first_name' => 'Admin', 'username' => 'products_'.uniqid(),
                'password' => 'secret-pass', 'language' => 'ar']
        );

        $this->admin = $owner;
        $this->businessId = $business->id;

        Tenancy::bind($this->businessId);

        $this->unitId = Unit::create([
            'business_id' => $this->businessId,
            'actual_name' => 'Pieces',
            'short_name' => 'Pc',
            'allow_decimal' => 0,
            'created_by' => $this->admin->id,
        ])->id;

        $this->actingAs($this->admin);
    }

    /* ================================================================
     | The editor is on the page
     |
     | The class of bug that shipped was not a wrong number — it was absent
     | markup, which every behavioural test in the suite is blind to. These
     | assertions are ugly on purpose: they name the ids and hooks the script
     | block queries, so deleting either half breaks the other's test.
     ================================================================ */

    #[Test]
    public function the_create_screen_carries_the_variations_editor_and_its_clone_templates(): void
    {
        $response = $this->get(route('products.create'))->assertOk();

        foreach ([
            // Revealed when type = variable, and already open on a bounced submit.
            'id="variations-section"',
            'id="group-template"',
            'id="value-template"',

            // One group offered up front: the shape of the thing should be visible
            // without a click.
            'name="variations[0][name]"',
            'name="variations[0][template_id]"',
            'name="variations[0][variations][0][name]"',
            'name="variations[0][variations][0][dpp]"',
            'name="variations[0][variations][0][dsp]"',

            /*
             * And the placeholders the script substitutes. Asserted as the `name`
             * attributes rather than as bare hook words, because the script block
             * on the same page mentions every selector it queries — an assertion on
             * `data-groups` alone would pass with the markup deleted.
             */
            'name="variations[__g__][name]"',
            'name="variations[__g__][variations][__v__][name]"',
        ] as $markup) {
            $response->assertSee($markup, false);
        }
    }

    #[Test]
    public function the_create_screen_carries_the_combo_picker_and_its_row_template(): void
    {
        $response = $this->get(route('products.create'))->assertOk();

        foreach ([
            'id="combo-section"',
            'id="combo-search"',
            'id="combo-add"',
            'id="combo-body"',
            'id="combo-template"',
            'name="combo[__c__][variation_id]"',
            'name="combo[__c__][quantity]"',
        ] as $markup) {
            $response->assertSee($markup, false);
        }
    }

    /**
     * The variable branch of the type toggle, server-side.
     *
     * `applyType()` in the form's script does this live, but the *first* paint has
     * to be right without it — a bounced submit and an edit both arrive with the
     * type already decided, and a variations section that only opens after a
     * `change` event would stay shut on both.
     */
    #[Test]
    public function editing_a_variable_product_opens_the_editor_and_drops_single_pricing(): void
    {
        $product = $this->createVariable([['name' => 'Size', 'values' => ['S']]]);

        $this->get(route('products.edit', $product->id))
            ->assertOk()
            ->assertSee('id="variations-section"', false)
            // Per-value pricing and one product-wide price are two answers to the
            // same question, so the panel is not rendered at all here.
            ->assertDontSee('id="single-pricing"', false);
    }

    #[Test]
    public function editing_a_single_product_keeps_single_pricing(): void
    {
        $product = $this->storeProduct(['name' => 'Plain', 'type' => 'single', 'single_dsp' => 50]);

        $this->get(route('products.edit', $product->id))
            ->assertOk()
            ->assertSee('id="single-pricing"', false);
    }

    /* ================================================================
     | The refusal that replaced the silent success
     ================================================================ */

    #[Test]
    public function a_variable_product_cannot_be_created_without_variations(): void
    {
        $this->post(route('products.store'), $this->payload(['type' => 'variable']))
            ->assertSessionHasErrors('variations');

        $this->assertSame(0, Product::where('name', 'Test item')->count());
    }

    /**
     * The same refusal, one level down — and the one that pruning makes possible
     * to reach. A group whose every value row is blank is dropped entirely, which
     * leaves `variations` as an empty array, which `required` then catches. Without
     * that second step a form full of empty rows would pass `min:1` and create a
     * product with no variations all over again.
     */
    #[Test]
    public function a_variable_product_whose_every_value_row_is_blank_is_refused(): void
    {
        $this->post(route('products.store'), $this->payload([
            'type' => 'variable',
            'variations' => [
                ['name' => 'Size', 'template_id' => '', 'variations' => [
                    ['name' => '', 'dpp' => '', 'dsp' => ''],
                    ['name' => '', 'dpp' => '', 'dsp' => ''],
                ]],
            ],
        ]))->assertSessionHasErrors('variations');

        $this->assertSame(0, Product::where('name', 'Test item')->count());
    }

    #[Test]
    public function a_variation_group_must_be_named(): void
    {
        $this->post(route('products.store'), $this->payload([
            'type' => 'variable',
            'variations' => [
                ['name' => '', 'variations' => [['name' => 'S']]],
            ],
        ]))->assertSessionHasErrors('variations.0.name');
    }

    #[Test]
    public function a_variation_template_that_does_not_exist_is_refused(): void
    {
        $this->post(route('products.store'), $this->payload([
            'type' => 'variable',
            'variations' => [
                ['name' => 'Size', 'template_id' => 987654, 'variations' => [['name' => 'S']]],
            ],
        ]))->assertSessionHasErrors('variations.0.template_id');
    }

    #[Test]
    public function a_combo_product_cannot_be_created_without_components(): void
    {
        $this->post(route('products.store'), $this->payload(['type' => 'combo', 'single_dsp' => 100]))
            ->assertSessionHasErrors('combo');

        $this->assertSame(0, Product::where('name', 'Test item')->count());
    }

    #[Test]
    public function a_combo_component_must_reference_a_real_variation_and_a_positive_quantity(): void
    {
        // Created first: a successful store between the two failing ones would
        // leave the assertion depending on flash-data ageing to clear the bag.
        $component = $this->variationOfStored('Component A');

        $this->post(route('products.store'), $this->payload([
            'type' => 'combo',
            'single_dsp' => 100,
            'combo' => [['variation_id' => 987654, 'quantity' => 1]],
        ]))->assertSessionHasErrors('combo.0.variation_id');

        $this->post(route('products.store'), $this->payload([
            'type' => 'combo',
            'single_dsp' => 100,
            'combo' => [['variation_id' => $component->id, 'quantity' => 0]],
        ]))->assertSessionHasErrors('combo.0.quantity');
    }

    /**
     * The refusal no interface can reach, which is the reason it needs a test.
     *
     * `variations` carries no `business_id` — its tenant is its product's — so a
     * bare `exists:variations,id` accepts every row in the table, and the picker
     * being scoped proves nothing, because a posted id never went through the
     * picker. Left open, another shop's variation sits inside this shop's combo and
     * selling the combo draws down their stock.
     */
    #[Test]
    public function a_combo_component_belonging_to_another_business_is_refused(): void
    {
        $theirs = $this->variationOfStored('Their item');

        // A real business row: `products.business_id` is a foreign key, so an
        // invented id would fail the test for the wrong reason.
        ['business' => $other] = app(BusinessService::class)->register(
            ['name' => 'Other Co.',
                'currency_id' => \App\Models\Currency::where('code', 'EGP')->value('id')],
            ['first_name' => 'Other', 'username' => 'other_'.uniqid(),
                'password' => 'secret-pass', 'language' => 'ar']
        );

        // register() binds tenancy to what it just created; the rest of this test
        // is tenant one.
        Tenancy::bind($this->businessId);

        // Moving the product is the whole fixture: a variation's tenant is its
        // product's, which is precisely why the rule has to join through products.
        // Scopes off, so the update cannot depend on which tenant is bound.
        Product::withoutGlobalScopes()
            ->whereKey($theirs->product_id)
            ->update(['business_id' => $other->id]);

        $this->post(route('products.store'), $this->payload([
            'name' => 'Cross-tenant combo',
            'type' => 'combo',
            'single_dsp' => 100,
            'combo' => [['variation_id' => $theirs->id, 'quantity' => 1]],
        ]))->assertSessionHasErrors('combo.0.variation_id');
    }

    /* ================================================================
     | Values in, variations out
     ================================================================ */

    /**
     * The scenario the whole retrofit is for: pick a template, get S/M/L, add a
     * second attribute, save, and every value is a variation you can stock.
     */
    #[Test]
    public function every_value_the_editor_sent_becomes_a_variation(): void
    {
        $product = $this->storeProduct([
            'name' => 'Shirt',
            'type' => 'variable',
            'variations' => [
                ['name' => 'المقاس', 'template_id' => $this->sizeTemplate()->id, 'variations' => [
                    ['name' => 'S', 'dpp' => 100, 'dsp' => 150],
                    ['name' => 'M', 'dpp' => 110, 'dsp' => 165],
                    ['name' => 'L', 'dpp' => 120, 'dsp' => 180],
                ]],
                ['name' => 'اللون', 'variations' => [
                    ['name' => 'أحمر', 'dpp' => 100, 'dsp' => 150],
                    ['name' => 'أزرق', 'dpp' => 100, 'dsp' => 150],
                ]],
            ],
        ]);

        $this->assertSame('variable', $product->type);
        $this->assertSame(5, $product->variations()->count());

        // The groups are `product_variations` rows, and the template travels with
        // the group it filled — that is what lets the edit screen say where a
        // value came from instead of showing five loose rows.
        $groups = $product->product_variations()->orderBy('id')->get();

        $this->assertSame(['المقاس', 'اللون'], $groups->pluck('name')->all());
        $this->assertSame($this->sizeTemplate()->id, (int) $groups->first()->variation_template_id);
        $this->assertNull($groups->last()->variation_template_id);
        $this->assertSame([0, 0], $groups->pluck('is_dummy')->map(fn ($f) => (int) $f)->all());

        $values = $product->variations()->orderBy('id')->get();

        $this->assertSame(['S', 'M', 'L', 'أحمر', 'أزرق'], $values->pluck('name')->all());

        // Every variation needs its own barcode target. `sub_sku` is indexed but
        // *not* unique (see the products migration), so a counter that restarted
        // would mint a duplicate silently rather than erroring — the assertion has
        // to be here because the database will not make it.
        $this->assertSame(5, $values->pluck('sub_sku')->unique()->count());
        $this->assertSame(
            [1, 2, 3, 4, 5],
            $values->pluck('sub_sku')->map(fn ($sku) => (int) substr((string) $sku, strrpos((string) $sku, '-') + 1))->all()
        );
    }

    /**
     * Prices are per value, and the side the form left blank is derived.
     *
     * The editor asks for purchase price, margin and sell price but only ever
     * needs two of the three, so `normalisePrices()` fills the rest. A variable
     * product goes through the same path as a single one — if it did not, the
     * same shirt would price differently depending on how it was typed.
     */
    #[Test]
    public function each_value_is_priced_on_its_own_and_the_missing_side_is_derived(): void
    {
        $product = $this->storeProduct([
            'type' => 'variable',
            'variations' => [
                ['name' => 'Size', 'variations' => [
                    // Cost and margin given; sell price derived.
                    ['name' => 'S', 'dpp' => 100, 'profit_percent' => 25, 'dsp' => ''],
                    // Cost and sell price given; margin derived.
                    ['name' => 'M', 'dpp' => 200, 'dsp' => 260],
                ]],
            ],
        ]);

        $s = $product->variations()->where('name', 'S')->firstOrFail();
        $m = $product->variations()->where('name', 'M')->firstOrFail();

        $this->assertSame(125.0, (float) $s->default_sell_price);
        $this->assertSame(25.0, (float) $s->profit_percent);

        $this->assertSame(260.0, (float) $m->default_sell_price);
        $this->assertSame(30.0, (float) $m->profit_percent);

        // Tax-exclusive tenant with no tax rate on the product: the inc-tax
        // columns still have to be filled, because every sell screen reads them.
        $this->assertSame(100.0, (float) $s->dpp_inc_tax);
        $this->assertSame(125.0, (float) $s->sell_price_inc_tax);
    }

    /**
     * The editor always shows one more empty row than has been filled, and a
     * mistyped combo search leaves a half-populated row behind. Both are interface
     * noise, not input. Failing validation on them would teach people to distrust
     * the Save button, so they are pruned before any rule runs.
     */
    #[Test]
    public function the_editors_trailing_blank_rows_are_pruned_rather_than_rejected(): void
    {
        $product = $this->storeProduct([
            'type' => 'variable',
            'variations' => [
                ['name' => 'Size', 'variations' => [
                    ['name' => 'S', 'dpp' => 10, 'dsp' => 15],
                    ['name' => '', 'dpp' => '', 'dsp' => ''],
                ]],
                // A whole group the user opened and never filled.
                ['name' => '', 'template_id' => '', 'variations' => [['name' => '']]],
            ],
        ]);

        $this->assertSame(1, $product->variations()->count());
        $this->assertSame(1, $product->product_variations()->count());
    }

    #[Test]
    public function a_single_product_still_gets_exactly_one_dummy_variation(): void
    {
        $product = $this->storeProduct([
            'type' => 'single',
            'single_dpp' => 40,
            'profit_percent' => 50,
        ]);

        $variation = $product->variations()->sole();

        $this->assertSame('DUMMY', $variation->name);
        $this->assertSame($product->sku, $variation->sub_sku);
        $this->assertSame(60.0, (float) $variation->default_sell_price);
        $this->assertSame(1, (int) $product->product_variations()->sole()->is_dummy);
    }

    /* ================================================================
     | Appending on edit
     ================================================================ */

    /**
     * A shop that starts stocking XL in March adds the row; it does not rebuild
     * the product. Deleting and recreating the variations would orphan every FIFO
     * lot pointing at the old ids, so the edit path is append-only by
     * construction — and this test is what keeps it that way.
     */
    #[Test]
    public function editing_a_variable_product_appends_values_without_disturbing_the_existing_ones(): void
    {
        $product = $this->createVariable([['name' => 'Size', 'values' => ['S', 'M']]]);

        $before = $product->variations()->orderBy('id')->get()
            ->mapWithKeys(fn (Variation $v) => [$v->id => $v->sub_sku])->all();

        $this->put(route('products.update', $product->id), $this->payload([
            'name' => 'Shirt',
            'type' => 'variable',
            'variations' => [
                ['name' => 'Size', 'variations' => [['name' => 'XL', 'dpp' => 130, 'dsp' => 200]]],
            ],
        ]))->assertRedirect(route('products.index'))->assertSessionHas('status.success', 1);

        $after = $product->fresh()->variations()->orderBy('id')->get();

        $this->assertSame(3, $after->count());

        // Same ids, same sub-SKUs: nothing was rewritten on the way through.
        foreach ($before as $id => $subSku) {
            $this->assertSame($subSku, $after->firstWhere('id', $id)?->sub_sku);
        }

        /*
         * And the new one collides with neither. The counter is seeded from the
         * variations already on the product rather than from zero — restart it and
         * the appended row is handed `SKU-1`, which the first value already owns,
         * and a scanned barcode then resolves to two variations.
         */
        $this->assertSame(3, $after->pluck('sub_sku')->unique()->count());
        $this->assertSame('XL', $after->last()->name);
    }

    /**
     * `variations` is required on create and merely allowed on update, because an
     * edit that only renames the product has no reason to resend the whole editor.
     */
    #[Test]
    public function editing_a_variable_product_without_resending_its_variations_is_not_an_error(): void
    {
        $product = $this->createVariable([['name' => 'Size', 'values' => ['S', 'M']]]);

        $this->put(route('products.update', $product->id), $this->payload([
            'name' => 'Renamed shirt',
            'type' => 'variable',
        ]))->assertRedirect(route('products.index'))->assertSessionHas('status.success', 1);

        $this->assertSame('Renamed shirt', $product->fresh()->name);
        $this->assertSame(2, $product->fresh()->variations()->count());
    }

    /* ================================================================
     | Combos
     ================================================================ */

    #[Test]
    public function a_combo_product_holds_its_components_on_its_own_variation(): void
    {
        $a = $this->variationOfStored('Component A');
        $b = $this->variationOfStored('Component B');

        $product = $this->storeProduct([
            'name' => 'Meal deal',
            'type' => 'combo',
            'single_dpp' => 60,
            'single_dsp' => 90,
            'combo' => [
                ['variation_id' => $a->id, 'quantity' => 2, 'name' => 'Component A'],
                ['variation_id' => $b->id, 'quantity' => 1, 'name' => 'Component B'],
            ],
        ]);

        $variation = $product->variations()->sole();

        // A bundle is priced as itself, not as the sum of its parts — so a combo
        // keeps the single-price fields, and they land on the one variation.
        $this->assertSame(90.0, (float) $variation->default_sell_price);

        $this->assertSame(
            [['variation_id' => $a->id, 'quantity' => 2.0], ['variation_id' => $b->id, 'quantity' => 1.0]],
            collect($variation->combo_variations)
                ->map(fn ($c) => ['variation_id' => (int) $c['variation_id'], 'quantity' => (float) $c['quantity']])
                ->all()
        );
    }

    /**
     * `combo[…][name]` exists only so a bounced submit can redraw the component's
     * name instead of its id. It is validated and then dropped — it must never
     * reach the product row, where `$guarded = ['id']` would turn it into a SQL
     * error, nor the component list, where it would be a second source of truth
     * for a name that lives on the variation.
     */
    #[Test]
    public function the_combo_rows_display_name_never_reaches_the_database(): void
    {
        $a = $this->variationOfStored('Component A');

        $product = $this->storeProduct([
            'type' => 'combo',
            'single_dsp' => 90,
            'combo' => [['variation_id' => $a->id, 'quantity' => 1, 'name' => 'Whatever the picker showed']],
        ]);

        $stored = $product->variations()->sole()->combo_variations;

        $this->assertSame(['variation_id', 'quantity', 'unit_id'], array_keys($stored[0]));
    }

    /* ================================================================
     | The template endpoint the editor fills rows from
     ================================================================ */

    #[Test]
    public function the_variation_template_endpoint_returns_the_values_the_editor_fills_rows_with(): void
    {
        $template = $this->sizeTemplate();

        $this->post(route('products.variationTemplate'), ['template_id' => $template->id])
            ->assertOk()
            ->assertExactJson(['name' => 'Size', 'values' => ['S', 'M', 'L']]);
    }

    /**
     * A template id that no longer exists must not be a 500. The select is filled
     * from the same tenant's templates, so this only happens when one is deleted
     * in another tab — and the right answer is an empty payload the editor leaves
     * the rows alone for, not a stack trace.
     */
    #[Test]
    public function a_missing_variation_template_answers_empty_rather_than_erroring(): void
    {
        $this->post(route('products.variationTemplate'), ['template_id' => 987654])
            ->assertOk()
            ->assertExactJson(['name' => '', 'values' => []]);
    }

    #[Test]
    public function the_variation_template_endpoint_requires_a_template_id(): void
    {
        $this->post(route('products.variationTemplate'), [])
            ->assertSessionHasErrors('template_id');
    }

    /* ================================================================
     | "Save & add opening stock"
     ================================================================ */

    /**
     * The second submit button, which had no test and did not work.
     *
     * `products.store` redirected to `route('opening-stock.add', ...)`. No such
     * route is registered — the opening-stock routes are `index`, `edit`, `update`
     * and `destroy` — so the button threw `RouteNotFoundException` *after* the
     * product had already been committed. The save succeeded and the user saw a
     * stack trace.
     *
     * This is the same lesson as the class docblock above, one branch further in:
     * the suite posted to `products.store` plenty by then, but only ever with the
     * default `submit_type`, so the whole second exit from the action was
     * unexercised. A route-name typo is invisible to every render test, because
     * `ScreensRenderTest` walks GET routes and this path is only reachable through
     * a POST redirect.
     */
    #[Test]
    public function saving_a_stocked_product_with_the_second_button_lands_on_the_opening_stock_screen(): void
    {
        $payload = $this->payload(['name' => 'Stocked item', 'submit_type' => 'submit_n_add_opening_stock']);

        $response = $this->post(route('products.store'), $payload);

        $product = Product::where('name', 'Stocked item')->latest('id')->firstOrFail();

        $response->assertRedirect(route('opening-stock.edit', $product->id))
            ->assertSessionHas('status.success', 1);
    }

    /**
     * The same button on a product that does not track stock.
     *
     * The button is rendered unconditionally, because `enable_stock` is chosen in
     * the same form and cannot be known before the form is submitted. Since
     * `OpeningStockController::edit()` filters on `where('enable_stock', 1)`,
     * following the redirect blindly would turn a successful save into a 404 —
     * so the product index, with the success message intact, is where this has to
     * land.
     */
    #[Test]
    public function saving_an_unstocked_product_with_the_second_button_falls_back_to_the_index(): void
    {
        $payload = $this->payload([
            'name' => 'Service item',
            'enable_stock' => 0,
            'submit_type' => 'submit_n_add_opening_stock',
        ]);

        $this->post(route('products.store'), $payload)
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('status.success', 1);
    }

    /* ================================================================
     | "Save & add group selling price"
     ================================================================ */

    /**
     * The button exists on the screen, and only for someone who can use it.
     *
     * Both halves are markup, and markup is what no redirect test can see: a
     * `@can` written against the wrong permission name fails closed and silently,
     * leaving the feature invisible on a page that still renders and still saves.
     */
    #[Test]
    public function the_opening_stock_screen_offers_the_group_price_button(): void
    {
        $product = $this->createdProduct('Buttoned item');
        $url = route('opening-stock.edit', $product->id);

        $this->get($url)
            ->assertOk()
            ->assertSee('submit_n_add_selling_prices', false)
            ->assertSee(__('lang_v1.save_and_add_selling_prices'), false);

        // Same screen, same save, no onward button: the group-price grid is not
        // theirs to open.
        $this->actingAs($this->stockClerk($this->locationId()))
            ->get($url)
            ->assertOk()
            ->assertDontSee('submit_n_add_selling_prices', false);
    }

    /**
     * The third link in the same chain: product → opening position → group prices.
     *
     * `opening-stock.update` had exactly one exit, so the button had to be given a
     * branch of its own rather than a route of its own — the stock is committed
     * either way and only the landing changes. The assertion that matters is the
     * pair: the redirect went to the group-price screen *and* the opening-stock
     * document exists, because a branch taken before the save would look identical
     * from the redirect alone.
     */
    #[Test]
    public function saving_opening_stock_with_the_group_price_button_lands_on_the_group_price_screen(): void
    {
        $product = $this->createdProduct('Chained item');
        $variation = $product->variations->firstOrFail();
        $locationId = $this->locationId();

        $response = $this->put(route('opening-stock.update', $product->id), [
            'location_id' => $locationId,
            'quantities' => [$variation->id => 5],
            'prices' => [$variation->id => 20],
            'submit_type' => 'submit_n_add_selling_prices',
        ]);

        $response->assertRedirect(route('products.addSellingPrices', $product->id))
            ->assertSessionHas('status.success', 1);

        $this->assertDatabaseHas('transactions', [
            'opening_stock_product_id' => $product->id,
            'type' => TransactionTypes::OPENING_STOCK,
            'location_id' => $locationId,
        ]);
    }

    /**
     * The same button pressed by someone who may state opening stock but may not
     * edit products.
     *
     * The two screens ask for different permissions — `product.opening_stock` here,
     * `product.update` on the group-price grid — so following the redirect blindly
     * would answer a successful save with a 403, which reads as "the stock was not
     * saved" when it was. The index, with the message intact, is the honest place
     * to land. Hiding the button is not enough on its own: the value is a form
     * field and the route is open to anyone who can reach this screen.
     */
    #[Test]
    public function the_group_price_button_falls_back_to_the_index_without_product_update(): void
    {
        $product = $this->createdProduct('Clerk item');
        $variation = $product->variations->firstOrFail();
        $locationId = $this->locationId();

        $this->actingAs($this->stockClerk($locationId))
            ->put(route('opening-stock.update', $product->id), [
                'location_id' => $locationId,
                'quantities' => [$variation->id => 5],
                'prices' => [$variation->id => 20],
                'submit_type' => 'submit_n_add_selling_prices',
            ])
            ->assertRedirect(route('opening-stock.index', ['location_id' => $locationId]))
            ->assertSessionHas('status.success', 1);

        $this->assertDatabaseHas('transactions', [
            'opening_stock_product_id' => $product->id,
            'type' => TransactionTypes::OPENING_STOCK,
        ]);
    }

    /* ================================================================
     | Helpers
     ================================================================ */

    /**
     * A stocked product created the way the form creates one, returned with its
     * variations loaded.
     */
    private function createdProduct(string $name): Product
    {
        $this->post(route('products.store'), $this->payload(['name' => $name]))
            ->assertSessionHas('status.success', 1);

        return Product::with('variations')->where('name', $name)->latest('id')->firstOrFail();
    }

    /**
     * A user who may state opening stock and nothing else about a product.
     *
     * Explicit location access rather than `access_all_locations`, because
     * `OpeningStockController::edit()` resolves the shop through
     * `BusinessLocation::forDropdown()` and bounces to the index with
     * "no permitted location" when that comes back empty.
     */
    private function stockClerk(int $locationId): User
    {
        $role = Role::create([
            'name' => Role::nameFor('Stock clerk', $this->businessId),
            'business_id' => $this->businessId, 'is_default' => false, 'guard_name' => 'web',
        ]);
        $role->givePermissionTo(Permission::findOrCreate('product.opening_stock', 'web'));

        $clerk = User::create([
            'user_type' => 'user', 'business_id' => $this->businessId,
            'first_name' => 'Stock', 'last_name' => 'Clerk',
            'username' => 'clerk_'.uniqid(), 'password' => 'secret-pass',
            'language' => 'ar', 'status' => 'active', 'allow_login' => 1,
        ]);
        $clerk->assignRole($role);
        $clerk->givePermissionTo(Permission::findOrCreate(
            Permissions::forLocation($locationId), 'web'
        ));

        return $clerk;
    }

    /**
     * The location `BusinessService::register()` seeded for this tenant.
     */
    private function locationId(): int
    {
        return (int) BusinessLocation::where('business_id', $this->businessId)->value('id');
    }

    /**
     * A complete product submit. Every `required` rule is satisfied, so a test can
     * vary one key and know the rest is not what failed.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test item',
            'type' => 'single',
            'unit_id' => $this->unitId,
            'tax_type' => 'exclusive',
            'barcode_type' => 'C128',
            'alert_quantity' => 0,
            'enable_stock' => 1,
        ], $overrides);
    }

    /**
     * POST the form and return the product it created, failing loudly with the
     * validation errors if it did not — a bare `sole()` here would otherwise
     * report "no rows" for what is really a rejected field.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function storeProduct(array $overrides = []): Product
    {
        $payload = $this->payload($overrides);

        $response = $this->post(route('products.store'), $payload);

        $response->assertSessionHasNoErrors()->assertSessionHas('status.success', 1);

        return Product::where('name', $payload['name'])->latest('id')->firstOrFail();
    }

    /**
     * A variable product created through the form, so its variations exist exactly
     * as a real one's would.
     *
     * @param  array<int, array{name: string, values: array<int, string>}>  $groups
     */
    private function createVariable(array $groups): Product
    {
        return $this->storeProduct([
            'name' => 'Shirt',
            'type' => 'variable',
            'variations' => collect($groups)->map(fn (array $group) => [
                'name' => $group['name'],
                'variations' => collect($group['values'])
                    ->map(fn (string $value) => ['name' => $value, 'dpp' => 100, 'dsp' => 150])
                    ->all(),
            ])->all(),
        ]);
    }

    /**
     * A single product's variation, for use as a combo component.
     */
    private function variationOfStored(string $name): Variation
    {
        return $this->storeProduct(['name' => $name, 'single_dpp' => 30, 'single_dsp' => 45])
            ->variations()->sole();
    }

    /**
     * The four variation templates the user already has are seeded per-tenant, so
     * the test seeds its own once and reuses it.
     */
    private function sizeTemplate(): VariationTemplate
    {
        $template = VariationTemplate::firstOrCreate(['name' => 'Size']);

        if ($template->values()->count() === 0) {
            foreach (['S', 'M', 'L'] as $value) {
                VariationValueTemplate::create([
                    'variation_template_id' => $template->id,
                    'name' => $value,
                ]);
            }
        }

        return $template->fresh('values');
    }
}
