<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SimpleCrudController;
use App\Models\BusinessLocation;
use App\Models\InvoiceLayout;
use App\Services\UploadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Invoice / receipt layouts.
 *
 * The widest table in the settings area — ~90 columns, almost all of them
 * label overrides and `show_*` toggles that decide what prints on a receipt.
 * A flat generic form of ninety controls is unusable, so this rides
 * {@see SimpleCrudController} for the CRUD verbs but supplies its own grouped
 * form under `invoice-layout/`.
 *
 * The field set is defined once in {@see fieldGroups()} and consumed twice —
 * by {@see rules()} to validate and by {@see formViewData()} to render — so the
 * two can never drift. Every string field is `nullable`; every `show_*` is a
 * checkbox coerced with {@see \Illuminate\Http\Request::boolean()} in
 * {@see prepare()}, because an unchecked box sends nothing and the columns are
 * NOT NULL with defaults.
 *
 * Gated by the flat `invoice_settings.access`, like invoice schemes.
 *
 * `logo` and `letter_head` are uploads, handled in {@see prepare()} through
 * {@see UploadService}. They were text paths until item 9, because nothing in
 * the app printed them and there was no upload layer to put a file anywhere.
 */
class InvoiceLayoutController extends SimpleCrudController
{
    /**
     * Upload fields, column => `constants` path key.
     *
     * Both land in `business_logo_path`: they are the same kind of artefact —
     * one business's printed identity — and splitting them across two
     * directories would mean two config keys and two places to look when a logo
     * stops appearing.
     *
     * @var array<string, string>
     */
    protected const UPLOADS = [
        'logo' => 'business_logo_path',
        'letter_head' => 'business_logo_path',
    ];

    protected string $model = InvoiceLayout::class;

    protected string $viewPath = 'invoice-layout';

    protected string $routePrefix = 'invoice-layouts';

    protected string $permission = 'invoice_settings.access';

    protected string $label = 'lang_v1.invoice_layout';

    protected array $columns = [
        'name' => 'lang_v1.name',
        'design' => 'lang_v1.design',
        'is_default' => 'lang_v1.default',
    ];

    public function __construct(private UploadService $uploads) {}

    protected function ability(string $action): string
    {
        return $this->permission;
    }

    protected function rules(Request $request, ?Model $record = null): array
    {
        $rules = ['name' => 'required|string|max:255'];

        foreach ($this->flatFields() as $field) {
            $name = $field['name'];

            if ($name === 'name') {
                continue;
            }

            $rules[$name] = match ($field['type']) {
                'checkbox' => 'nullable|boolean',
                'select' => 'nullable|in:'.implode(',', array_keys($field['options'] ?? [])),
                'number' => 'nullable|integer',
                // `image` and not `mimes:png,jpg`: it checks the file's contents,
                // so a PHP script renamed to `logo.png` is rejected here rather
                // than landing in a web-served directory. 2 MB is generous for a
                // letterhead and small enough that a 40 MB phone photo is refused
                // with a validation message instead of a memory error.
                'file' => 'nullable|image|max:2048',
                default => 'nullable|string|max:255',
            };
        }

        // A couple of free-text blocks are longer than a label.
        $rules['header_text'] = 'nullable|string|max:1000';
        $rules['footer_text'] = 'nullable|string|max:1000';
        $rules['highlight_color'] = 'nullable|string|max:10';

        return $rules;
    }

    protected function prepare(array $validated, Request $request, ?Model $record = null): array
    {
        // Every checkbox column is NOT NULL; an unchecked box omits the key, so
        // each is resolved explicitly from the request rather than left missing.
        foreach ($this->flatFields() as $field) {
            if ($field['type'] === 'checkbox') {
                $validated[$field['name']] = $request->boolean($field['name']);
            }
        }

        foreach (self::UPLOADS as $column => $pathKey) {
            // The key has to come *out* first. A file input that nobody touched
            // validates as null, and writing that null back would erase the
            // tenant's logo every time they saved a label three panels away.
            unset($validated[$column]);

            $current = $record->{$column} ?? null;

            if ($request->hasFile($column)) {
                $validated[$column] = $this->uploads->store(
                    $request->file($column),
                    $pathKey,
                    $current
                );
            } elseif ($request->boolean('remove_'.$column)) {
                $this->uploads->delete($pathKey, $current);
                $validated[$column] = null;
            }
        }

        return parent::prepare($validated, $request, $record);
    }

    protected function afterSave(Model $record, Request $request, bool $created): void
    {
        // Exactly one default layout per tenant, mirroring InvoiceScheme.
        if ($record->is_default) {
            InvoiceLayout::where('id', '!=', $record->id)->update(['is_default' => false]);
        }
    }

    protected function deletionBlockedBy(Model $record): ?string
    {
        // Locations reference a layout through a NOT NULL foreign key.
        $inUse = BusinessLocation::where('invoice_layout_id', $record->id)
            ->orWhere('sale_invoice_layout_id', $record->id)
            ->exists();

        return $inUse
            ? __('lang_v1.cannot_delete_in_use', ['name' => __('lang_v1.business_locations')])
            : null;
    }

    protected function formViewData(?Model $record = null): array
    {
        return ['groups' => $this->fieldGroups()];
    }

    /* ================================================================
     | Field definitions — the single source both rules() and the form use
     ================================================================ */

    /**
     * The layout form, grouped into panels. Each group is a titled block of
     * fields in the generic `crud/_form` shape.
     *
     * @return array<int, array{title: string, icon: string, fields: array<int, array<string, mixed>>}>
     */
    protected function fieldGroups(): array
    {
        return [
            [
                'title' => __('lang_v1.design'),
                'icon' => 'sliders',
                'fields' => [
                    ['name' => 'name', 'label' => __('lang_v1.name'), 'type' => 'text', 'required' => true],
                    ['name' => 'design', 'label' => __('lang_v1.design'), 'type' => 'select', 'options' => [
                        'classic' => __('lang_v1.classic'),
                        'elegant' => __('lang_v1.elegant'),
                    ]],
                    ['name' => 'highlight_color', 'label' => __('lang_v1.highlight_color'), 'type' => 'text',
                     'placeholder' => '#2563eb'],
                    ['name' => 'is_default', 'label' => __('lang_v1.set_as_default'), 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => __('lang_v1.header_and_letterhead'),
                'icon' => 'document',
                'fields' => [
                    ['name' => 'header_text', 'label' => __('lang_v1.header_text'), 'type' => 'textarea'],
                    ['name' => 'show_logo', 'label' => __('lang_v1.show_logo'), 'type' => 'checkbox'],
                    ['name' => 'logo', 'label' => __('lang_v1.logo'), 'type' => 'file',
                     'hint' => __('lang_v1.logo_hint'), 'pathKey' => 'business_logo_path'],
                    ['name' => 'show_letter_head', 'label' => __('lang_v1.show_letter_head'), 'type' => 'checkbox'],
                    ['name' => 'letter_head', 'label' => __('lang_v1.letter_head'), 'type' => 'file',
                     'hint' => __('lang_v1.letter_head_hint'), 'pathKey' => 'business_logo_path'],
                ],
            ],
            [
                'title' => __('lang_v1.business_details_block'),
                'icon' => 'store',
                'fields' => [
                    ['name' => 'show_business_name', 'label' => __('lang_v1.show_business_name'), 'type' => 'checkbox'],
                    ['name' => 'show_location_name', 'label' => __('lang_v1.show_location_name'), 'type' => 'checkbox'],
                    ['name' => 'show_landmark', 'label' => __('lang_v1.show_landmark'), 'type' => 'checkbox'],
                    ['name' => 'show_city', 'label' => __('lang_v1.show_city'), 'type' => 'checkbox'],
                    ['name' => 'show_state', 'label' => __('lang_v1.show_state'), 'type' => 'checkbox'],
                    ['name' => 'show_country', 'label' => __('lang_v1.show_country'), 'type' => 'checkbox'],
                    ['name' => 'show_zip_code', 'label' => __('lang_v1.show_zip_code'), 'type' => 'checkbox'],
                    ['name' => 'show_mobile_number', 'label' => __('lang_v1.show_mobile_number'), 'type' => 'checkbox'],
                    ['name' => 'show_alternate_number', 'label' => __('lang_v1.show_alternate_number'), 'type' => 'checkbox'],
                    ['name' => 'show_email', 'label' => __('lang_v1.show_email'), 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => __('lang_v1.headings'),
                'icon' => 'tag',
                'fields' => [
                    ['name' => 'invoice_heading', 'label' => __('lang_v1.invoice_heading'), 'type' => 'text'],
                    ['name' => 'invoice_heading_paid', 'label' => __('lang_v1.invoice_heading_paid'), 'type' => 'text'],
                    ['name' => 'invoice_heading_not_paid', 'label' => __('lang_v1.invoice_heading_not_paid'), 'type' => 'text'],
                    ['name' => 'quotation_heading', 'label' => __('lang_v1.quotation_heading'), 'type' => 'text'],
                    ['name' => 'invoice_no_prefix', 'label' => __('lang_v1.invoice_no_prefix'), 'type' => 'text'],
                    ['name' => 'quotation_no_prefix', 'label' => __('lang_v1.quotation_no_prefix'), 'type' => 'text'],
                    ['name' => 'sub_heading_line1', 'label' => __('lang_v1.sub_heading_line', ['n' => 1]), 'type' => 'text'],
                    ['name' => 'sub_heading_line2', 'label' => __('lang_v1.sub_heading_line', ['n' => 2]), 'type' => 'text'],
                    ['name' => 'sub_heading_line3', 'label' => __('lang_v1.sub_heading_line', ['n' => 3]), 'type' => 'text'],
                    ['name' => 'sub_heading_line4', 'label' => __('lang_v1.sub_heading_line', ['n' => 4]), 'type' => 'text'],
                    ['name' => 'sub_heading_line5', 'label' => __('lang_v1.sub_heading_line', ['n' => 5]), 'type' => 'text'],
                ],
            ],
            [
                'title' => __('lang_v1.amount_labels'),
                'icon' => 'calculator',
                'fields' => [
                    ['name' => 'sub_total_label', 'label' => __('lang_v1.sub_total_label'), 'type' => 'text'],
                    ['name' => 'discount_label', 'label' => __('lang_v1.discount_label'), 'type' => 'text'],
                    ['name' => 'tax_label', 'label' => __('lang_v1.tax_label'), 'type' => 'text'],
                    ['name' => 'total_label', 'label' => __('lang_v1.total_label'), 'type' => 'text'],
                    ['name' => 'total_due_label', 'label' => __('lang_v1.total_due_label'), 'type' => 'text'],
                    ['name' => 'paid_label', 'label' => __('lang_v1.paid_label'), 'type' => 'text'],
                    ['name' => 'round_off_label', 'label' => __('lang_v1.round_off_label'), 'type' => 'text'],
                ],
            ],
            [
                'title' => __('lang_v1.customer_block'),
                'icon' => 'user',
                'fields' => [
                    ['name' => 'show_customer', 'label' => __('lang_v1.show_customer'), 'type' => 'checkbox'],
                    ['name' => 'customer_label', 'label' => __('lang_v1.customer_label'), 'type' => 'text'],
                    ['name' => 'show_client_id', 'label' => __('lang_v1.show_client_id'), 'type' => 'checkbox'],
                    ['name' => 'client_id_label', 'label' => __('lang_v1.client_id_label'), 'type' => 'text'],
                    ['name' => 'client_tax_label', 'label' => __('lang_v1.client_tax_label'), 'type' => 'text'],
                    ['name' => 'date_label', 'label' => __('lang_v1.date_label'), 'type' => 'text'],
                    ['name' => 'show_time', 'label' => __('lang_v1.show_time'), 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => __('lang_v1.product_table'),
                'icon' => 'list',
                'fields' => [
                    ['name' => 'table_product_label', 'label' => __('lang_v1.table_product_label'), 'type' => 'text'],
                    ['name' => 'table_qty_label', 'label' => __('lang_v1.table_qty_label'), 'type' => 'text'],
                    ['name' => 'table_unit_price_label', 'label' => __('lang_v1.table_unit_price_label'), 'type' => 'text'],
                    ['name' => 'table_subtotal_label', 'label' => __('lang_v1.table_subtotal_label'), 'type' => 'text'],
                    ['name' => 'cat_code_label', 'label' => __('lang_v1.cat_code_label'), 'type' => 'text'],
                    ['name' => 'show_brand', 'label' => __('lang_v1.show_brand'), 'type' => 'checkbox'],
                    ['name' => 'show_sku', 'label' => __('lang_v1.show_sku'), 'type' => 'checkbox'],
                    ['name' => 'show_cat_code', 'label' => __('lang_v1.show_cat_code'), 'type' => 'checkbox'],
                    ['name' => 'show_expiry', 'label' => __('lang_v1.show_expiry'), 'type' => 'checkbox'],
                    ['name' => 'show_lot', 'label' => __('lang_v1.show_lot'), 'type' => 'checkbox'],
                    ['name' => 'show_sale_description', 'label' => __('lang_v1.show_sale_description'), 'type' => 'checkbox'],
                    ['name' => 'show_barcode', 'label' => __('lang_v1.show_barcode'), 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => __('lang_v1.totals_and_extras'),
                'icon' => 'receipt',
                'fields' => [
                    ['name' => 'show_tax_1', 'label' => __('lang_v1.show_tax_1'), 'type' => 'checkbox'],
                    ['name' => 'show_tax_2', 'label' => __('lang_v1.show_tax_2'), 'type' => 'checkbox'],
                    ['name' => 'show_payments', 'label' => __('lang_v1.show_payments'), 'type' => 'checkbox'],
                    ['name' => 'show_previous_bal', 'label' => __('lang_v1.show_previous_bal'), 'type' => 'checkbox'],
                    ['name' => 'prev_bal_label', 'label' => __('lang_v1.prev_bal_label'), 'type' => 'text'],
                    ['name' => 'show_reward_point', 'label' => __('lang_v1.show_reward_point'), 'type' => 'checkbox'],
                    ['name' => 'show_sales_person', 'label' => __('lang_v1.show_sales_person'), 'type' => 'checkbox'],
                    ['name' => 'sales_person_label', 'label' => __('lang_v1.sales_person_label'), 'type' => 'text'],
                    ['name' => 'show_qr_code', 'label' => __('lang_v1.show_qr_code'), 'type' => 'checkbox'],
                ],
            ],
            [
                'title' => __('lang_v1.footer'),
                'icon' => 'document',
                'fields' => [
                    ['name' => 'footer_text', 'label' => __('lang_v1.footer_text'), 'type' => 'textarea'],
                ],
            ],
        ];
    }

    /**
     * Every field across all groups, flattened.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function flatFields(): array
    {
        return array_merge(...array_column($this->fieldGroups(), 'fields'));
    }
}
