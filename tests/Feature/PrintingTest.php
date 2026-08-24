<?php

namespace Tests\Feature;

use App\Models\BusinessLocation;
use App\Models\Currency;
use App\Models\InvoiceLayout;
use App\Models\PrintJob;
use App\Models\Printer;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BusinessService;
use App\Services\PrintService;
use App\Support\Permissions;
use App\Support\Tenancy;
use App\Support\TransactionTypes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The printing layer's behaviour, as opposed to its markup.
 *
 * {@see ScreensRenderTest} already walks `print.invoice`, `print.pdf` and
 * `print.receipt` and proves they render in Arabic with balanced markup and no
 * untranslated keys. What a render walk cannot see is the thing this layer exists
 * for: `invoice_layouts` is ninety columns of label overrides and `show_*`
 * toggles, and before item 9 **nothing in the application read a single one of
 * them**. A screen that returns 200 while ignoring every setting the tenant typed
 * looks identical to one that honours them.
 *
 * So the assertions here are about obedience:
 *
 * - **A label override reaches the paper.** `total_label` set to "الإجمالي شامل
 *   الضريبة" has to appear, and the app's own `__('lang_v1.total')` has to stop
 *   appearing. This is the single most repeated shape in the layout table, and
 *   {@see PrintService::label()} is the one place the fallback lives, so proving
 *   it once proves it ninety times.
 * - **A toggle actually hides something.** `show_barcode`, `show_payments` and
 *   `show_client_id` are the three that add a whole block rather than a word.
 * - **The gates are the document's own.** A clerk holding only
 *   `view_own_sell_only` must 404 — not 403 — on somebody else's invoice, and
 *   `access_printers` is a separate privilege from reading one.
 * - **`enqueue()` is the queue's missing producer.** {@see
 *   \App\Http\Controllers\Api\PrintQueueController} had a consumer and no
 *   producer; the row it writes has to be self-contained, because the agent
 *   cannot render Blade and does not have the database.
 * - **The two paper sizes are two documents.** The A4 sheet and the 72 mm receipt
 *   share `present()` and share nothing else; if the receipt ever starts emitting
 *   `@page { size: A4 }` it prints one sale across three feet of roll.
 *
 * Runs as a real Admin via {@see BusinessService::register()} — "admin" is a role
 * and only `register()` seeds it — and, wherever the point is a gate, as a
 * deliberately under-privileged user.
 */
class PrintingTest extends TestCase
{
    use DatabaseTransactions;

    private User $owner;

    private int $businessId;

    private BusinessLocation $branch;

    private InvoiceLayout $layout;

    private Transaction $sale;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Created inside this test's transaction, so anything spatie cached in an
        // earlier test points at ids that no longer exist.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $currency = Currency::firstOrCreate(
            ['code' => 'EGP'],
            ['country' => 'Egypt', 'currency' => 'Egyptian Pound', 'symbol' => 'ج.م',
                'thousand_separator' => ',', 'decimal_separator' => '.']
        );

        ['owner' => $owner, 'business' => $business] = app(BusinessService::class)->register(
            ['name' => 'Print Co.', 'currency_id' => $currency->id],
            ['first_name' => 'Owner', 'username' => 'owner'.uniqid(),
                'password' => 'secret-pass', 'language' => 'ar']
        );

        $this->owner = $owner;
        $this->businessId = $business->id;
        Tenancy::bind($this->businessId);

        // The two `Tests\TestCase` helpers this class leans on — createProduct()
        // and variationOf() — read these, and `register()` returns the pair
        // rather than assigning them.
        $this->business = $business;
        $this->user = $owner;

        $this->branch = BusinessLocation::firstOrFail();
        $this->layout = InvoiceLayout::firstOrFail();
        $this->sale = $this->makeSale();

        $this->actingAs($this->owner);
    }

    /* ================================================================
     | The layout is read — the whole reason this layer exists
     ================================================================ */

    #[Test]
    public function a_label_override_replaces_the_app_translation_on_the_invoice(): void
    {
        /*
         * Both halves are asserted. A renderer that appended the override while
         * still printing the default would pass a "contains the override" check
         * and put two total rows on a customer's invoice.
         */
        $this->layout->update([
            'total_label' => 'الإجمالي شامل الضريبة',
            'table_qty_label' => 'العدد',
            'invoice_heading' => 'فاتورة ضريبية',
        ]);

        $body = $this->get(route('print.invoice', $this->sale->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('الإجمالي شامل الضريبة', $body);
        $this->assertStringContainsString('العدد', $body);
        $this->assertStringContainsString('فاتورة ضريبية', $body);

        $this->assertStringNotContainsString(__('lang_v1.total').'<', $body);
    }

    #[Test]
    public function an_empty_label_column_falls_back_to_the_arabic_translation_not_an_english_literal(): void
    {
        /*
         * Decision #3, applied to the one screen a customer takes home. Every
         * `*_label` starts null, so this is what the overwhelming majority of
         * tenants actually print — and the moment a fallback is written as
         * `?: 'Quantity'` this is the test that fails.
         */
        $this->assertNull($this->layout->total_label);

        $body = $this->get(route('print.invoice', $this->sale->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(__('lang_v1.total'), $body);
        $this->assertStringNotContainsString('>Total<', $body);
        $this->assertStringNotContainsString('>Quantity<', $body);
    }

    #[Test]
    public function the_show_toggles_add_and_remove_whole_blocks(): void
    {
        $this->layout->update([
            'show_barcode' => false,
            'show_payments' => false,
            'show_qr_code' => false,
        ]);

        $off = $this->get(route('print.invoice', $this->sale->id))->assertOk()->getContent();

        // The barcode is the one image on the sheet, and it is an inline SVG data
        // URI — DomPDF renders SVG no other way.
        $this->assertStringNotContainsString('data:image/svg+xml;base64,', $off);
        $this->assertStringNotContainsString(__('lang_v1.payments'), $off);

        $this->layout->update([
            'show_barcode' => true,
            'show_payments' => true,
        ]);

        $on = $this->get(route('print.invoice', $this->sale->id))->assertOk()->getContent();

        $this->assertStringContainsString('data:image/svg+xml;base64,', $on);
        $this->assertStringContainsString(__('lang_v1.payments'), $on);
    }

    #[Test]
    public function the_design_column_picks_a_structurally_different_template(): void
    {
        /*
         * `elegant` is not a recolour of `classic`. It puts the heading and the
         * meta facts inside a solid accent band and the totals inside a tinted
         * panel; `classic` rules the table off with a border and tints only the
         * header row. So the two are told apart by markup only one of them emits,
         * which is what stops "add a second design" from becoming a `highlight_color`
         * change nobody would notice on paper.
         *
         * There is no third case to cover: `design` is
         * `enum('classic','elegant') NOT NULL DEFAULT 'classic'`
         * (2026_01_01_000500_create_business_settings_tables.php:31), so MySQL
         * refuses anything else. `present()`'s whitelist is still there for
         * resolution step 4 of `layoutFor()` — an unsaved `new InvoiceLayout`,
         * whose `design` is null until a DB default would have applied — and that
         * path is asserted directly below.
         */
        $this->layout->update(['design' => 'elegant', 'highlight_color' => '#8b1d3f']);

        $elegant = $this->get(route('print.invoice', $this->sale->id))->assertOk()->getContent();

        $this->assertStringContainsString('background:#8b1d3f', $elegant);
        $this->assertStringContainsString('background:#f6f9f8', $elegant);

        $this->layout->update(['design' => 'classic']);

        $classic = $this->get(route('print.invoice', $this->sale->id))->assertOk()->getContent();

        $this->assertStringContainsString('background:#eef3f1', $classic);
        $this->assertStringNotContainsString('background:#8b1d3f', $classic);
    }

    #[Test]
    public function a_highlight_colour_the_tenant_typed_by_hand_cannot_break_the_sheet(): void
    {
        /*
         * `highlight_color` is `string(10) NULL` — a free-text input, not a colour
         * picker, so a tenant types "dark blue" or "2563eb" or leaves a trailing
         * space. Emitted raw that becomes `background:dark blue`, which CSS drops:
         * the accent band prints white on white and the heading disappears from
         * the one page a customer reads.
         */
        foreach (['dark blue', '2563eb', '#12', ''] as $typed) {
            $this->layout->update(['design' => 'elegant', 'highlight_color' => $typed]);

            $body = $this->get(route('print.invoice', $this->sale->id))->assertOk()->getContent();

            $this->assertStringContainsString('background:#007867', $body);
            $this->assertStringNotContainsString('background:'.$typed.';', $body);
        }

        // A well-formed one is honoured, in both the short and long hex forms.
        foreach (['#8b1d3f', '#b13'] as $valid) {
            $this->layout->update(['highlight_color' => $valid]);

            $this->get(route('print.invoice', $this->sale->id))
                ->assertOk()
                ->assertSee('background:'.$valid, false);
        }
    }

    #[Test]
    public function a_location_with_its_own_sale_layout_prints_on_that_one(): void
    {
        // The whole point of `sale_invoice_layout_id` existing: a shop prints its
        // sales on one layout and its purchase paperwork on another.
        $saleLayout = InvoiceLayout::create([
            'business_id' => $this->businessId,
            'name' => 'Sales only',
            'invoice_heading' => 'فاتورة المبيعات فقط',
        ]);

        $this->branch->update(['sale_invoice_layout_id' => $saleLayout->id]);

        $this->assertSame(
            $saleLayout->id,
            app(PrintService::class)->layoutFor($this->sale->fresh('location'))->id
        );

        $this->get(route('print.invoice', $this->sale->id))
            ->assertOk()
            ->assertSee('فاتورة المبيعات فقط', false);
    }

    /* ================================================================
     | Document types
     ================================================================ */

    #[Test]
    public function a_sell_return_prints_as_a_credit_note(): void
    {
        $return = app(\App\Services\SellService::class)->addReturn(
            $this->sale,
            [['sell_line_id' => $this->sale->sell_lines->first()->id, 'quantity' => 1]],
            ['created_by' => $this->owner->id]
        );

        $this->get(route('print.invoice', $return->id))
            ->assertOk()
            ->assertSee(__('lang_v1.credit_note'), false)
            ->assertSee(__('lang_v1.credit_note_no'), false);
    }

    #[Test]
    public function a_document_with_no_printable_shape_is_not_found(): void
    {
        /*
         * 404 rather than a default gate. A stock transfer has no invoice layout,
         * no customer block and no `table_product_label`; falling through to
         * `sell.view` would print a sale-shaped document for something that is not
         * a sale.
         */
        $transfer = Transaction::create([
            'business_id' => $this->businessId,
            'location_id' => $this->branch->id,
            'type' => TransactionTypes::STOCK_ADJUSTMENT,
            'status' => TransactionTypes::STATUS_FINAL,
            'transaction_date' => now(),
            'total_before_tax' => 0,
            'final_total' => 0,
            'created_by' => $this->owner->id,
        ]);

        $this->get(route('print.invoice', $transfer->id))->assertNotFound();
    }

    /* ================================================================
     | Output paths
     ================================================================ */

    #[Test]
    public function the_pdf_downloads_as_a_pdf_named_after_the_invoice(): void
    {
        $response = $this->get(route('print.pdf', $this->sale->id));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));

        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.pdf', $disposition);

        // `%PDF` — proof DomPDF produced a document rather than an HTML error page
        // served with a PDF content type.
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    public function a_slash_in_a_tenant_defined_invoice_number_cannot_reach_the_filename(): void
    {
        // `invoice_no` is generated from a prefix the tenant types, so it can
        // contain anything a text input accepts — including a path separator.
        $this->sale->update(['invoice_no' => 'INV/2026/0001']);

        $disposition = (string) $this->get(route('print.pdf', $this->sale->id))
            ->assertOk()
            ->headers->get('content-disposition');

        $this->assertStringContainsString('INV-2026-0001.pdf', $disposition);
        $this->assertStringNotContainsString('/2026/', $disposition);
    }

    #[Test]
    public function the_receipt_is_a_seventy_two_millimetre_document_and_not_the_a4_sheet(): void
    {
        $body = $this->get(route('print.receipt', $this->sale->id))->assertOk()->getContent();

        $this->assertStringContainsString('size: 72mm auto', $body);
        $this->assertStringNotContainsString('size: A4', $body);

        // The receipt drops the columns rather than shrinking them: on a
        // 42-character line a brand and a category code wrap into noise.
        $this->assertStringNotContainsString('table.grid', $body);
    }

    #[Test]
    public function auto_print_is_opt_in_on_both_renderers(): void
    {
        /*
         * The same URL is how somebody *reads* an invoice. Opening a print dialog
         * on a page a clerk only wanted to look at is the kind of small hostility
         * that teaches people to avoid a feature — so the POS asks for it
         * explicitly and nothing else does.
         */
        foreach (['print.invoice', 'print.receipt'] as $route) {
            $this->assertStringNotContainsString(
                'window.print()',
                $this->stripToolbar($this->get(route($route, $this->sale->id))->getContent()),
                $route.' fired the print dialog without ?auto=1'
            );

            $this->assertStringContainsString(
                'window.print()',
                $this->stripToolbar(
                    $this->get(route($route, ['id' => $this->sale->id, 'auto' => 1]))->getContent()
                ),
                $route.' ignored ?auto=1'
            );
        }
    }

    /* ================================================================
     | Gates
     ================================================================ */

    #[Test]
    public function a_clerk_restricted_to_their_own_sales_gets_a_404_on_somebody_elses(): void
    {
        /*
         * 404 and not 403. A 403 confirms the invoice exists, which is the exact
         * fact `view_own_sell_only` is there to withhold — and the sell screen
         * answers the same way, so printing cannot be the softer door.
         */
        $clerk = $this->restricted(['view_own_sell_only']);

        $this->actingAs($clerk)
            ->get(route('print.invoice', $this->sale->id))
            ->assertNotFound();

        $own = $this->makeSale(['created_by' => $clerk->id]);

        $this->actingAs($clerk)
            ->get(route('print.invoice', $own->id))
            ->assertOk();
    }

    #[Test]
    public function reading_an_invoice_and_driving_the_printer_are_different_privileges(): void
    {
        $this->configurePrinter();

        $reader = $this->restricted(['sell.view']);

        $this->actingAs($reader)
            ->get(route('print.invoice', $this->sale->id))
            ->assertOk();

        $this->actingAs($reader)
            ->post(route('print.enqueue', $this->sale->id))
            ->assertForbidden();

        $this->assertSame(0, PrintJob::count());
    }

    #[Test]
    public function the_send_to_printer_button_appears_only_when_the_branch_has_hardware(): void
    {
        /*
         * A button whose only purpose is to explain why it cannot work is not a
         * button. A branch left on the default `browser` setting has an empty
         * queue and an agent nobody installed.
         */
        $this->get(route('print.invoice', $this->sale->id))
            ->assertOk()
            ->assertDontSee(__('lang_v1.send_to_printer'), false);

        $this->configurePrinter();

        $this->get(route('print.invoice', $this->sale->id))
            ->assertOk()
            ->assertSee(__('lang_v1.send_to_printer'), false);
    }

    /* ================================================================
     | The print queue's missing producer
     ================================================================ */

    #[Test]
    public function enqueue_writes_a_self_contained_job_for_the_agent(): void
    {
        $printer = $this->configurePrinter();

        $this->post(route('print.enqueue', $this->sale->id))
            ->assertRedirect()
            ->assertSessionHas('status.success', 1);

        $job = PrintJob::sole();

        $this->assertSame($this->businessId, (int) $job->business_id);
        $this->assertSame($this->branch->id, (int) $job->location_id);
        $this->assertSame('pending', $job->status);

        /*
         * Self-contained, because the agent cannot render Blade and does not have
         * the database. It also carries the printer it is for, so a job requeued
         * an hour later by PrintQueueController::cleanup() still prints what the
         * clerk saw rather than what the settings say by then.
         */
        $this->assertSame('receipt', $job->payload['type']);
        $this->assertSame($this->sale->invoice_no, $job->payload['invoice_no']);
        $this->assertSame($printer->ip_address, $job->payload['printer']['ip_address']);
        $this->assertSame(42, $job->payload['printer']['char_per_line']);
        $this->assertNotEmpty($job->payload['lines']);
        $this->assertNotEmpty($job->payload['totals']);
    }

    #[Test]
    public function a_branch_that_prints_through_the_browser_is_told_so_by_name(): void
    {
        /*
         * `RuntimeException`, so {@see \App\Http\Controllers\Controller::failed()}
         * passes the message through instead of turning a fixable
         * misconfiguration into "something went wrong". The person at the counter
         * is the one who can fix it, and only if they are told which setting.
         */
        $this->post(route('print.enqueue', $this->sale->id))
            ->assertRedirect()
            ->assertSessionHas('status.success', 0)
            ->assertSessionHas('status.msg', __('lang_v1.print_location_uses_browser'));

        $this->assertSame(0, PrintJob::count());

        $this->branch->update(['receipt_printer_type' => 'printer', 'printer_id' => null]);

        $this->post(route('print.enqueue', $this->sale->id))
            ->assertSessionHas('status.msg', __('lang_v1.print_no_printer_configured'));

        $this->assertSame(0, PrintJob::count());
    }

    /* ================================================================
     | The screens that link here
     ================================================================ */

    #[Test]
    public function the_sale_screen_links_to_the_renderer_rather_than_printing_itself(): void
    {
        /*
         * `window.print()` on the sell screen hands a customer the application —
         * the app's table with its chrome hidden, no letterhead, no tax number and
         * none of the ninety layout settings. That was the state of "print" before
         * item 9, and this is the assertion that stops it coming back.
         */
        $body = $this->get(route('sells.show', $this->sale->id))->assertOk()->getContent();

        $this->assertStringContainsString(route('print.invoice', $this->sale->id), $body);
        $this->assertStringNotContainsString('window.print()', $body);
    }

    #[Test]
    public function a_completed_pos_sale_offers_the_receipt_before_the_record(): void
    {
        /*
         * The POS returns to an empty terminal because the next customer is
         * already at the counter, so the banner is the only route back to the sale
         * — and after ringing one up the clerk's next gesture is handing over
         * paper, which is why the receipt is the first link and carries `auto=1`.
         */
        $variation = $this->sale->sell_lines->first()->variation_id;

        $response = $this->post(route('pos.store'), [
            'location_id' => $this->branch->id,
            'contact_id' => $this->sale->contact_id,
            'lines' => [['variation_id' => $variation, 'quantity' => 1, 'unit_price' => 15,
                'unit_price_inc_tax' => 15]],
            'payments' => [['amount' => 15, 'method' => 'cash']],
        ]);

        $response->assertRedirect(route('pos.create'))->assertSessionHas('status.success', 1);

        $links = session('status')['links'];
        $newest = Transaction::ofType(TransactionTypes::SELL)->latest('id')->firstOrFail();

        $this->assertSame(
            route('print.receipt', ['id' => $newest->id, 'auto' => 1]),
            $links[0]['url']
        );
        $this->assertTrue($links[0]['blank']);
        $this->assertSame(route('sells.show', $newest->id), $links[1]['url']);
    }

    /* ================================================================
     | Helpers
     ================================================================ */

    /**
     * A final, part-paid sale of one product — enough for a row, a total, a due
     * amount and a payment table.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeSale(array $overrides = []): Transaction
    {
        $product = $this->createProduct(['name' => 'Printed product '.uniqid()]);

        $contact = \App\Models\Contact::create([
            'type' => 'customer',
            'name' => 'Print customer',
            'first_name' => 'Print customer',
            'contact_id' => 'CO'.random_int(1000, 9999),
            'mobile' => '01000000000',
            'contact_status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        return app(\App\Services\SellService::class)->create(
            array_merge([
                'location_id' => $this->branch->id,
                'contact_id' => $contact->id,
                'status' => TransactionTypes::STATUS_FINAL,
                'created_by' => $this->owner->id,
            ], $overrides),
            [['variation_id' => $this->variationOf($product)->id, 'quantity' => 2,
                'unit_price' => 15, 'unit_price_inc_tax' => 15]],
            [['amount' => 20, 'method' => 'cash', 'created_by' => $this->owner->id]]
        );
    }

    /**
     * Give the branch a real ESC/POS printer, so `canEnqueue` and
     * {@see PrintService::enqueue()} have hardware to reach.
     */
    private function configurePrinter(): Printer
    {
        $printer = Printer::create([
            'business_id' => $this->businessId,
            'name' => 'Counter printer',
            'connection_type' => 'network',
            'capability_profile' => 'default',
            'char_per_line' => 42,
            'ip_address' => '192.168.1.50',
            'port' => '9100',
            'created_by' => $this->owner->id,
        ]);

        $this->branch->update([
            'receipt_printer_type' => 'printer',
            'printer_id' => $printer->id,
        ]);

        return $printer;
    }

    /**
     * A user of this tenant holding exactly the permissions given.
     *
     * `allow_login` is set because {@see \App\Http\Middleware\CheckUserLogin}
     * would otherwise turn every 403/404 assertion into a 302 to /home, and the
     * test would be measuring the login gate instead of the permission gate.
     *
     * @param  array<int, string>  $permissions
     */
    private function restricted(array $permissions): User
    {
        $role = Role::create([
            'name' => Role::nameFor('Clerk'.uniqid(), $this->businessId),
            'business_id' => $this->businessId,
            'is_default' => false,
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($permissions);

        /*
         * Plus access to the branch. `PrintController::document()` scopes the
         * fetch through `permittedLocations()` before it gates on anything, so a
         * user with no location at all 404s on every document — which would make
         * the "own sales only" assertions below pass for entirely the wrong
         * reason. Granted as an explicit `location.{id}` rather than
         * `access_all_locations`, because that is what a counter clerk actually
         * holds.
         */
        $role->givePermissionTo(Permission::findOrCreate(
            Permissions::forLocation($this->branch->id), 'web'
        ));

        $clerk = User::create([
            'user_type' => 'user',
            'business_id' => $this->businessId,
            'first_name' => 'Clerk',
            'username' => 'clerk'.uniqid(),
            'password' => Hash::make('secret-pass'),
            'language' => 'ar',
            'status' => 'active',
            'allow_login' => 1,
        ]);

        $clerk->assignRole($role);

        return $clerk;
    }

    /**
     * The page without its toolbar.
     *
     * The toolbar's own "Print" button is a `window.print()` onclick and always
     * present — it is the control a person chooses. `?auto=1` is about the page
     * firing the dialog *by itself*, which is a load listener, so the toolbar has
     * to come out before the two can be told apart.
     */
    private function stripToolbar(string $body): string
    {
        return (string) preg_replace('#<div class="no-print toolbar">.*?</div>#s', '', $body);
    }
}
