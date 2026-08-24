<?php

namespace App\Services;

use App\Models\InvoiceLayout;
use App\Models\PrintJob;
use App\Models\Transaction;
use App\Support\Tenancy;
use App\Support\TransactionTypes;
use Illuminate\Support\Carbon;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;

/**
 * Turns a sale document plus its invoice layout into something a template can
 * render without knowing anything about either.
 *
 * `invoice_layouts` is ninety-odd columns of label overrides and visibility
 * toggles. Read naively, every template would carry the same ninety
 * `$layout->table_qty_label ?: __('lang_v1.quantity')` expressions — and there
 * are three templates (classic A4, elegant A4, 72 mm receipt) plus a PDF path,
 * so the same fallback would be written four times and would drift three of
 * them. {@see present()} resolves the layout once and hands back a flat view
 * model: a list of columns, a list of rows keyed by those columns, a list of
 * total lines. The templates loop; they do not decide.
 *
 * Two things in here are decisions rather than mechanics.
 *
 * **A label column is an override, not a value.** Every `*_label` column is
 * nullable and starts null, which means "use the app's own translation". So the
 * fallback has to be `__('lang_v1.…')` and not an English literal — the moment
 * one of these reads `?: 'Quantity'`, an Arabic invoice prints an English column
 * heading, and it prints it for every tenant who never opened the layout editor.
 * That is Decision #3 (full Arabic on every screen *and report*) applied to the
 * one screen a customer actually takes home. {@see label()} is the only place
 * the fallback exists.
 *
 * **The seller block is assembled from the location, not the business.** A
 * business has one name; a receipt has to carry the branch that made the sale,
 * its phone number and its tax number, because that is the address a customer
 * returns goods to. So `show_business_name` and `show_location_name` are
 * separate toggles and both are honoured.
 */
class PrintService
{
    public function __construct(
        private FormattingService $format,
        private PaymentService $payments,
        private UploadService $uploads,
    ) {}

    /**
     * Which layout prints this document.
     *
     * The resolution order matters and is the reason this is a method rather
     * than an inline `$document->location->invoice_layout`:
     *
     * 1. For a sale, the location's `sale_invoice_layout_id` — a shop can print
     *    sales on a different layout from its purchase paperwork, which is the
     *    whole point of the column existing. It is nullable, so it is a genuine
     *    "if set".
     * 2. The location's `invoice_layout_id`, which is NOT NULL in the schema and
     *    is seeded by {@see \App\Services\BusinessService::register()}, so in
     *    practice this is where every tenant lands.
     * 3. The tenant's default layout, then any layout at all. Both are
     *    unreachable through the UI — a location cannot be saved without a
     *    layout — but a hand-edited row or a partially restored backup should
     *    print a plain invoice rather than throw on the counter.
     * 4. An unsaved default. Every column is nullable or has a DB default, so an
     *    empty model resolves every label through {@see label()} and prints a
     *    correct, unstyled invoice. Returning null instead would push a
     *    null-check into all four templates.
     */
    public function layoutFor(Transaction $document): InvoiceLayout
    {
        $location = $document->location;

        if (! empty($location)) {
            $isSale = $document->type !== TransactionTypes::PURCHASE
                && $document->type !== TransactionTypes::PURCHASE_ORDER;

            $preferred = $isSale
                ? ($location->sale_invoice_layout_id ?: $location->invoice_layout_id)
                : $location->invoice_layout_id;

            $layout = InvoiceLayout::find($preferred);

            if (! empty($layout)) {
                return $layout;
            }
        }

        return InvoiceLayout::where('is_default', 1)->first()
            ?? InvoiceLayout::orderBy('id')->first()
            ?? new InvoiceLayout;
    }

    /**
     * The whole invoice as data.
     *
     * `$forPdf` exists for exactly one reason: DomPDF has no HTTP client. An
     * `<img src="{{ asset(...) }}">` that renders perfectly in the browser comes
     * out of the PDF as a broken-image glyph, so images have to be handed over
     * as absolute filesystem paths instead. Everything else on both paths is
     * identical, which is what keeps one Blade file serving both.
     *
     * @return array<string, mixed>
     */
    public function present(Transaction $document, bool $forPdf = false): array
    {
        $layout = $this->layoutFor($document);
        $columns = $this->columns($layout, $document);

        return [
            'document' => $document,
            'layout' => $layout,
            'design' => in_array($layout->design, ['classic', 'elegant'], true)
                ? $layout->design
                : 'classic',
            'accent' => $this->accent($layout),
            'heading' => $this->heading($document, $layout),
            'headerText' => $layout->header_text,
            'letterHead' => $layout->show_letter_head
                ? $this->image($layout->letter_head, $forPdf)
                : null,
            'logo' => $layout->show_logo
                ? $this->image($layout->logo ?: session('business.logo'), $forPdf)
                : null,
            'seller' => $this->seller($document, $layout),
            'client' => $this->client($document, $layout),
            'meta' => $this->meta($document, $layout),
            'columns' => $columns,
            'rows' => $this->rows($document, $layout, $columns, $forPdf),
            'totals' => $this->totals($document, $layout),
            'paymentLines' => $layout->show_payments ? $this->paymentLines($document) : [],
            'barcode' => $layout->show_barcode ? $this->barcode((string) $document->invoice_no) : null,
            'qrFields' => $layout->show_qr_code ? $this->qrFields($document, $layout) : [],
            'notes' => $document->additional_notes,
            'footer' => $layout->footer_text,
        ];
    }

    /**
     * Queue this document for the location's thermal printer.
     *
     * This closes a hole that has been open since the print agent was written:
     * {@see \App\Http\Controllers\Api\PrintQueueController} documents the flow as
     * "the browser POSTs a job → the agent polls" and implements the agent half
     * completely — polling, claiming, completing, requeueing a job whose agent
     * died — while nothing in the app ever created a job. The queue had a
     * consumer and no producer.
     *
     * Both refusals are `RuntimeException` on purpose: {@see
     * \App\Http\Controllers\Controller::failed()} passes that class's message
     * through to the user, where a generic exception becomes "something went
     * wrong". "This branch is set to print in the browser" is a fixable
     * misconfiguration and the person at the counter should be told which one it
     * is.
     */
    public function enqueue(Transaction $document): PrintJob
    {
        $location = $document->location;

        if (empty($location)) {
            throw new \RuntimeException(__('lang_v1.print_location_missing'));
        }

        if ($location->receipt_printer_type !== 'printer') {
            throw new \RuntimeException(__('lang_v1.print_location_uses_browser'));
        }

        $printer = $location->printer;

        if (empty($printer)) {
            throw new \RuntimeException(__('lang_v1.print_no_printer_configured'));
        }

        return PrintJob::create([
            'business_id' => Tenancy::id(),
            'location_id' => $location->id,
            'status' => 'pending',
            'payload' => $this->thermalPayload($document, $printer),
        ]);
    }

    /**
     * The receipt as flat text blocks for the ESC/POS agent.
     *
     * The agent cannot render Blade and does not have the database, so the
     * payload carries both the content and the printer it is for — a job is
     * self-contained, and re-reading a job stuck in `printing` for an hour (which
     * {@see \App\Http\Controllers\Api\PrintQueueController::cleanup()} requeues)
     * still prints what the clerk saw, not what the settings say today.
     *
     * @return array<string, mixed>
     */
    public function thermalPayload(Transaction $document, ?\App\Models\Printer $printer = null): array
    {
        $view = $this->present($document);

        return [
            'type' => 'receipt',
            'invoice_no' => $document->invoice_no,
            'printer' => empty($printer) ? null : [
                'connection_type' => $printer->connection_type,
                'capability_profile' => $printer->capability_profile,
                'char_per_line' => (int) ($printer->char_per_line ?: 42),
                'ip_address' => $printer->ip_address,
                'port' => $printer->port,
                'path' => $printer->path,
            ],
            'header' => array_values(array_filter(array_merge(
                [$view['heading']],
                [$view['seller']['name']],
                $view['seller']['lines'],
            ))),
            'meta' => $view['meta'],
            'client' => array_values(array_filter(array_merge(
                [$view['client']['name']],
                $view['client']['lines'],
            ))),
            'lines' => array_map(fn (array $row): array => [
                'name' => $row['product'] ?? '',
                'quantity' => $row['quantity'] ?? '',
                'unit_price' => $row['unit_price'] ?? '',
                'subtotal' => $row['subtotal'] ?? '',
            ], $view['rows']),
            'totals' => $view['totals'],
            'footer' => array_values(array_filter([$view['notes'], $view['footer']])),
        ];
    }

    /* ================================================================
     | Resolution
     ================================================================ */

    /**
     * A layout override, or the app's own translation when the tenant never set
     * one. The whole reason this class exists — see the class docblock.
     */
    protected function label(?string $override, string $key): string
    {
        return filled($override) ? $override : __('lang_v1.'.$key);
    }

    /**
     * The document's title, plus the payment stamp the layout appends to it.
     *
     * `invoice_heading_paid` / `invoice_heading_not_paid` are suffixes, not
     * replacements — a tenant sets them to "(PAID)" and expects
     * "Invoice (PAID)", not a heading that silently loses the word "Invoice".
     */
    protected function heading(Transaction $document, InvoiceLayout $layout): string
    {
        if ($document->type === TransactionTypes::SELL_RETURN) {
            return $this->label($layout->cn_heading, 'credit_note');
        }

        if ($document->type === TransactionTypes::SALES_ORDER) {
            return __('lang_v1.sales_order');
        }

        if ($document->is_quotation) {
            return $this->label($layout->quotation_heading, 'quotation');
        }

        $heading = $this->label($layout->invoice_heading, 'invoice');

        $stamp = $document->payment_status === TransactionTypes::PAID
            ? $layout->invoice_heading_paid
            : $layout->invoice_heading_not_paid;

        return filled($stamp) ? $heading.' '.$stamp : $heading;
    }

    /**
     * Who issued this, as a name plus however many address lines the layout
     * admits to.
     *
     * @return array{name: string, lines: array<int, string>, taxes: array<int, array{label: string, value: string}>}
     */
    protected function seller(Transaction $document, InvoiceLayout $layout): array
    {
        $location = $document->location;
        $business = (array) session('business');

        $name = array_values(array_filter([
            $layout->show_business_name ? ($business['name'] ?? null) : null,
            $layout->show_location_name ? ($location->name ?? null) : null,
        ]));

        $lines = array_values(array_filter([
            // The five sub-heading lines are free text under the name — a slogan,
            // a commercial-registry number, a second language of the address.
            $layout->sub_heading_line1,
            $layout->sub_heading_line2,
            $layout->sub_heading_line3,
            $layout->sub_heading_line4,
            $layout->sub_heading_line5,
            $layout->show_landmark ? ($location->landmark ?? null) : null,
            $this->joined([
                $layout->show_city ? ($location->city ?? null) : null,
                $layout->show_state ? ($location->state ?? null) : null,
                $layout->show_zip_code ? ($location->zip_code ?? null) : null,
                $layout->show_country ? ($location->country ?? null) : null,
            ]),
            $layout->show_mobile_number && filled($location->mobile ?? null)
                ? __('lang_v1.mobile').': '.$location->mobile
                : null,
            $layout->show_alternate_number && filled($location->alternate_number ?? null)
                ? __('lang_v1.alternate_number').': '.$location->alternate_number
                : null,
            $layout->show_email && filled($location->email ?? null)
                ? $location->email
                : null,
        ]));

        // The tax identity is the business's, not the branch's: one registration
        // covers every location, and the labels are tenant-defined
        // (`tax_label_1` is "VAT"/"الرقم الضريبي"/whatever the registry calls it).
        $taxes = [];

        foreach ([1, 2] as $slot) {
            if (! $layout->{'show_tax_'.$slot} || blank($business['tax_number_'.$slot] ?? null)) {
                continue;
            }

            $taxes[] = [
                'label' => filled($business['tax_label_'.$slot] ?? null)
                    ? $business['tax_label_'.$slot]
                    : __('lang_v1.tax_number'),
                'value' => (string) $business['tax_number_'.$slot],
            ];
        }

        return [
            'name' => implode(' — ', $name) ?: (string) ($business['name'] ?? ''),
            'lines' => $lines,
            'taxes' => $taxes,
        ];
    }

    /**
     * Who it is addressed to.
     *
     * @return array{label: string, name: string, id: ?string, taxLabel: string, tax: ?string, lines: array<int, string>}
     */
    protected function client(Transaction $document, InvoiceLayout $layout): array
    {
        $contact = $document->contact;

        return [
            'label' => $this->label($layout->customer_label, 'customer'),
            'name' => (string) ($contact->full_name_with_business ?? ''),
            'id' => $layout->show_client_id ? ($contact->contact_id ?? null) : null,
            'idLabel' => $this->label($layout->client_id_label, 'customer_id'),
            'taxLabel' => $this->label($layout->client_tax_label, 'tax_number'),
            'tax' => $contact->tax_number ?? null,
            'lines' => array_values(array_filter([
                $contact->contact_address ?? null,
                filled($contact->mobile ?? null) ? __('lang_v1.mobile').': '.$contact->mobile : null,
            ])),
        ];
    }

    /**
     * The identity strip: number, date, and whoever is named on the sale.
     *
     * @return array<int, array{label: string, value: string}>
     */
    protected function meta(Transaction $document, InvoiceLayout $layout): array
    {
        $isReturn = $document->type === TransactionTypes::SELL_RETURN;

        $numberLabel = $isReturn
            ? $this->label($layout->cn_no_label, 'credit_note_no')
            : __('lang_v1.invoice_no');

        $prefix = $document->is_quotation
            ? $layout->quotation_no_prefix
            : $layout->invoice_no_prefix;

        $meta = [[
            'label' => $numberLabel,
            'value' => trim($prefix.' '.$document->invoice_no),
        ], [
            'label' => $this->label($layout->date_label, 'date'),
            'value' => $this->documentDate($document, $layout),
        ]];

        if ($layout->show_sales_person && filled($document->sales_person->user_full_name ?? null)) {
            $meta[] = [
                'label' => $this->label($layout->sales_person_label, 'sales_person'),
                'value' => $document->sales_person->user_full_name,
            ];
        }

        if ($layout->show_commission_agent
            && filled($document->sale_commission_agent->user_full_name ?? null)) {
            $meta[] = [
                'label' => $this->label($layout->commission_agent_label, 'commission_agent'),
                'value' => $document->sale_commission_agent->user_full_name,
            ];
        }

        return $meta;
    }

    /**
     * The date as the layout wants it printed.
     *
     * `date_time_format` is a raw PHP format string typed by the tenant, so it
     * is tried inside a try/catch: a typo there must degrade to the business's
     * configured format, not blank the invoice. When it is empty — which is the
     * normal case — `show_time` picks between date and date-with-time and
     * {@see FormattingService} applies the tenant's own `date_format`.
     */
    protected function documentDate(Transaction $document, InvoiceLayout $layout): string
    {
        if (filled($layout->date_time_format)) {
            try {
                return Carbon::parse($document->transaction_date)->format($layout->date_time_format);
            } catch (\Throwable) {
                // Fall through to the business format.
            }
        }

        return $this->format->formatDate($document->transaction_date, (bool) $layout->show_time);
    }

    /* ================================================================
     | The product table
     ================================================================ */

    /**
     * The columns this layout prints, in order.
     *
     * Returned as data rather than decided in the template because there are
     * four renderers and eleven optional columns. A template that asked
     * `@if ($layout->show_sku)` in its `<thead>` would have to ask again in its
     * `<tbody>`, and the two would disagree the first time somebody edited one
     * of them — a header shifted one cell left of its data, which is the kind of
     * bug that only shows up on a customer's copy.
     *
     * `align` is `start`/`end`, never `left`/`right`: the same table prints
     * right-to-left in Arabic, and a hard `right` would put the figures on the
     * wrong edge (Decision #3).
     *
     * @return array<int, array{key: string, label: string, align: string}>
     */
    protected function columns(InvoiceLayout $layout, Transaction $document): array
    {
        $columns = [
            ['key' => 'index', 'label' => '#', 'align' => 'start'],
        ];

        if ($layout->show_image) {
            $columns[] = ['key' => 'image', 'label' => '', 'align' => 'start'];
        }

        $columns[] = [
            'key' => 'product',
            'label' => $this->label($layout->table_product_label, 'product'),
            'align' => 'start',
        ];

        $optional = [
            'sku' => ['show_sku', null, 'sku'],
            'brand' => ['show_brand', null, 'brand'],
            'cat_code' => ['show_cat_code', 'cat_code_label', 'category_code'],
            'expiry' => ['show_expiry', null, 'expiry'],
            'lot' => ['show_lot', null, 'lot_number'],
        ];

        foreach ($optional as $key => [$toggle, $labelColumn, $langKey]) {
            if ($layout->{$toggle}) {
                $columns[] = [
                    'key' => $key,
                    'label' => $this->label($labelColumn ? $layout->{$labelColumn} : null, $langKey),
                    'align' => 'start',
                ];
            }
        }

        $columns[] = [
            'key' => 'quantity',
            'label' => $this->label($layout->table_qty_label, 'quantity'),
            'align' => 'end',
        ];
        $columns[] = [
            'key' => 'unit_price',
            'label' => $this->label($layout->table_unit_price_label, 'unit_price'),
            'align' => 'end',
        ];

        // The tax column earns its width only when something on the document is
        // actually taxed. A shop selling exempt goods should not print a column
        // of zeros down the middle of every invoice.
        if ($this->hasLineTax($document)) {
            $columns[] = [
                'key' => 'tax',
                'label' => $this->taxHeading($layout),
                'align' => 'end',
            ];
        }

        $columns[] = [
            'key' => 'subtotal',
            'label' => $this->label($layout->table_subtotal_label, 'subtotal'),
            'align' => 'end',
        ];

        return $columns;
    }

    /**
     * One row per sell line, keyed by the same keys {@see columns()} returned, so
     * the template can loop the columns and index the row.
     *
     * @param  array<int, array{key: string, label: string, align: string}>  $columns
     * @return array<int, array<string, string>>
     */
    protected function rows(
        Transaction $document,
        InvoiceLayout $layout,
        array $columns,
        bool $forPdf
    ): array {
        $keys = array_column($columns, 'key');
        $rows = [];
        $index = 0;

        foreach ($document->sell_lines ?? [] as $line) {
            $product = $line->variations->product ?? null;
            $quantity = (float) $line->quantity - (float) $line->quantity_returned;
            $index++;

            $row = [
                'index' => (string) $index,
                'product' => $this->productCell($line, $layout),
                'quantity' => $this->format->quantity($quantity)
                    .' '.($line->sub_unit->short_name ?? $product->unit->short_name ?? ''),
                'unit_price' => $this->format->currencyF($line->unit_price_inc_tax),
                'subtotal' => $this->format->currencyF($quantity * (float) $line->unit_price_inc_tax),
            ];

            if (in_array('image', $keys, true)) {
                $row['image'] = ! empty($product) && $product->hasImage()
                    ? ($forPdf ? $product->image_path : $product->image_url)
                    : '';
            }

            if (in_array('sku', $keys, true)) {
                $row['sku'] = (string) ($line->variations->sub_sku ?? $product->sku ?? '');
            }

            if (in_array('brand', $keys, true)) {
                $row['brand'] = (string) ($product->brand->name ?? '');
            }

            if (in_array('cat_code', $keys, true)) {
                $row['cat_code'] = (string) ($product->category->short_code ?? '');
            }

            if (in_array('expiry', $keys, true)) {
                $row['expiry'] = filled($line->lot_details->exp_date ?? null)
                    ? $this->format->formatDate($line->lot_details->exp_date)
                    : '';
            }

            if (in_array('lot', $keys, true)) {
                $row['lot'] = (string) ($line->lot_details->lot_number ?? '');
            }

            if (in_array('tax', $keys, true)) {
                $row['tax'] = $this->format->currencyF($quantity * (float) $line->item_tax);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The product cell: name, and whatever the layout wants underneath it.
     *
     * Newline-separated rather than markup so the same string survives into the
     * thermal payload, where there is no markup at all.
     */
    protected function productCell(mixed $line, InvoiceLayout $layout): string
    {
        $parts = [$line->variations->full_name ?? ''];

        if ($layout->show_sale_description && filled($line->sell_line_note)) {
            $parts[] = $line->sell_line_note;
        }

        return implode("\n", array_filter($parts));
    }

    /**
     * True when any line on this document carries tax.
     */
    protected function hasLineTax(Transaction $document): bool
    {
        foreach ($document->sell_lines ?? [] as $line) {
            if ((float) $line->item_tax > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tax column's heading.
     *
     * `table_tax_headings` is a list, because the source system let a tenant
     * split tax into several columns (CGST/SGST — an Indian arrangement).
     * Decision #2 rules that out for this market, so the list is read for its
     * first entry only and the rest is ignored rather than silently dropped: a
     * tenant who somehow has three headings stored gets the first one, which is
     * the one their invoice was designed around.
     */
    protected function taxHeading(InvoiceLayout $layout): string
    {
        $headings = array_values(array_filter((array) $layout->table_tax_headings));

        return filled($headings[0] ?? null)
            ? $headings[0]
            : $this->label($layout->tax_label, 'tax');
    }

    /* ================================================================
     | Money
     ================================================================ */

    /**
     * The totals ladder, bottom-right of the invoice.
     *
     * Only lines that carry a figure are returned — a sale with no discount does
     * not print a "Discount 0.00" row. `strong` marks the two lines a customer
     * looks for, so the templates emphasise the same two without re-deriving
     * which they are.
     *
     * @return array<int, array{label: string, value: string, strong: bool}>
     */
    protected function totals(Transaction $document, InvoiceLayout $layout): array
    {
        $paid = $this->payments->amountPaid($document);
        $due = $this->payments->amountDue($document);

        $lines = [[
            'label' => $this->label($layout->sub_total_label, 'subtotal'),
            'value' => $this->format->currencyF($document->total_before_tax),
            'strong' => false,
            'always' => true,
        ], [
            'label' => $this->label($layout->discount_label, 'discount'),
            'value' => $this->format->currencyF($this->discountAmount($document)),
            'strong' => false,
            'always' => false,
            'raw' => $this->discountAmount($document),
        ], [
            'label' => $this->label($layout->tax_label, 'tax'),
            'value' => $this->format->currencyF($document->tax_amount),
            'strong' => false,
            'always' => false,
            'raw' => (float) $document->tax_amount,
        ], [
            'label' => __('lang_v1.shipping_charges'),
            'value' => $this->format->currencyF($document->shipping_charges),
            'strong' => false,
            'always' => false,
            'raw' => (float) $document->shipping_charges,
        ], [
            'label' => $this->label($layout->round_off_label, 'round_off'),
            'value' => $this->format->currencyF($document->round_off_amount),
            'strong' => false,
            'always' => false,
            'raw' => (float) $document->round_off_amount,
        ], [
            'label' => $this->label($layout->total_label, 'total'),
            'value' => $this->format->currencyF($document->final_total),
            'strong' => true,
            'always' => true,
        ], [
            'label' => $this->label($layout->paid_label, 'paid'),
            'value' => $this->format->currencyF($paid),
            'strong' => false,
            'always' => false,
            'raw' => $paid,
        ], [
            'label' => $this->label($layout->total_due_label, 'due'),
            'value' => $this->format->currencyF($due),
            'strong' => true,
            'always' => false,
            'raw' => $due,
        ]];

        return array_values(array_map(
            fn (array $line): array => [
                'label' => $line['label'],
                'value' => $line['value'],
                'strong' => $line['strong'],
            ],
            array_filter(
                $lines,
                // `round()` before comparing: a float total that is 0.0000001 off
                // from a discount that cancelled out would otherwise print a row
                // reading "0.00", which looks like a mistake on the invoice.
                fn (array $line): bool => ($line['always'] ?? false)
                    || round((float) ($line['raw'] ?? 0), 4) != 0.0
            )
        ));
    }

    /**
     * The invoice discount as an amount, whatever unit it was entered in.
     *
     * A percentage discount is stored as the percentage, so printing
     * `discount_amount` raw would put "10.00" on an invoice where the customer
     * saved 250.
     */
    protected function discountAmount(Transaction $document): float
    {
        if ($document->discount_type === 'percentage') {
            return $this->format->calcPercentage(
                (float) $document->total_before_tax,
                (float) $document->discount_amount
            );
        }

        return (float) $document->discount_amount;
    }

    /**
     * Each payment against this document, when the layout asks for them.
     *
     * @return array<int, array{date: string, method: string, amount: string}>
     */
    protected function paymentLines(Transaction $document): array
    {
        $lines = [];

        foreach ($document->payment_lines ?? [] as $payment) {
            if ($payment->is_return) {
                continue;
            }

            $lines[] = [
                'date' => $this->format->formatDate($payment->paid_on),
                // `method_label`, not `__('lang_v1.'.$method)`. Its own docblock
                // explains why: seven of the thirteen methods are stored under a
                // key that is not their translation key, so the obvious version
                // prints `custom_pay_1` on the customer's receipt.
                'method' => $payment->method_label,
                'amount' => $this->format->currencyF($payment->amount),
            ];
        }

        return $lines;
    }

    /* ================================================================
     | Images and codes
     ================================================================ */

    /**
     * Accent colour for the design, validated before it reaches a `style`
     * attribute.
     *
     * `highlight_color` is a free-text column a tenant types into. Interpolating
     * it into inline CSS unchecked is how a settings field becomes a way to
     * inject a stylesheet, so anything that is not a plain hex colour falls back
     * to the brand green — which is also the colour `purchase/pdf.blade.php`
     * already uses, so the two documents match.
     */
    protected function accent(InvoiceLayout $layout): string
    {
        $colour = trim((string) $layout->highlight_color);

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $colour)
            ? $colour
            : '#007867';
    }

    /**
     * A stored upload as whichever kind of reference the renderer can follow —
     * see the `$forPdf` note on {@see present()}. Null when the row points at a
     * file that is not on disk.
     */
    protected function image(?string $fileName, bool $forPdf): ?string
    {
        return $forPdf
            ? $this->uploads->path('business_logo_path', $fileName)
            : $this->uploads->url('business_logo_path', $fileName);
    }

    /**
     * The invoice number as a scannable Code 128, as a data URI.
     *
     * A data URI rather than inline `<svg>`, and the same one on both paths:
     * DomPDF renders SVG only through `<img>`, and having the browser and the PDF
     * take different routes to the same barcode is how one of them silently
     * stops scanning.
     */
    protected function barcode(string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            $renderer = new SvgRenderer;
            $renderer->setForegroundColor([0, 0, 0]);

            $svg = $renderer->render((new TypeCode128)->getBarcode($value), 240, 48);
        } catch (\Throwable) {
            // An invoice must print even if its number cannot be encoded.
            return null;
        }

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * The fields the layout wants in its QR block.
     *
     * Deliberately text, not a QR image. The column exists for e-invoicing, and
     * which standard applies is an open question for this market (Egypt's ETA
     * and Saudi ZATCA encode different payloads and neither is a free choice) —
     * so printing a QR now would mean guessing a spec and shipping a code that
     * scans to the wrong thing. The data is printed as labelled text, which is
     * legible and correct; the machine-readable form waits for the decision.
     *
     * @return array<int, array{label: string, value: string}>
     */
    protected function qrFields(Transaction $document, InvoiceLayout $layout): array
    {
        $business = (array) session('business');

        $available = [
            'business_name' => [__('lang_v1.business_name'), $business['name'] ?? null],
            'location_name' => [__('lang_v1.business_location'), $document->location->name ?? null],
            'invoice_no' => [__('lang_v1.invoice_no'), $document->invoice_no],
            'invoice_date' => [__('lang_v1.date'), $this->documentDate($document, $layout)],
            'total_amount' => [__('lang_v1.total'), $this->format->currencyF($document->final_total)],
            'total_tax' => [__('lang_v1.tax'), $this->format->currencyF($document->tax_amount)],
            'tax_number_1' => [
                filled($business['tax_label_1'] ?? null)
                    ? $business['tax_label_1'] : __('lang_v1.tax_number'),
                $business['tax_number_1'] ?? null,
            ],
        ];

        $wanted = array_values(array_filter((array) $layout->qr_code_fields));
        $fields = [];

        foreach ($wanted as $key) {
            [$label, $value] = $available[$key] ?? [null, null];

            if (blank($value)) {
                continue;
            }

            $fields[] = ['label' => $label, 'value' => (string) $value];
        }

        return $fields;
    }

    /**
     * Comma-join whatever is present, or null when nothing is.
     */
    protected function joined(array $parts): ?string
    {
        $parts = array_values(array_filter($parts, fn ($part) => filled($part)));

        return empty($parts) ? null : implode(', ', $parts);
    }
}
