<?php

namespace App\Services;

use App\Models\BusinessLocation;
use App\Models\InvoiceScheme;
use App\Models\ReferenceCount;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Document numbering.
 *
 * Two independent schemes:
 *   - Sale invoice numbers come from `invoice_schemes` (per location).
 *   - Everything else (purchases, payments, transfers, …) uses a per-tenant
 *     counter in `reference_counts` plus a prefix from
 *     `business.ref_no_prefixes`.
 */
class ReferenceService
{
    /**
     * Reference types and their default prefixes.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'purchase' => 'PO',
        'purchase_order' => 'POR',
        'purchase_requisition' => 'PR',
        'purchase_return' => 'PRN',
        'stock_transfer' => 'ST',
        'stock_adjustment' => 'SA',
        'sell_return' => 'CN',
        'sales_order' => 'SO',
        'expense' => 'EP',
        'contact' => 'CO',
        'payment' => 'SP',
        'purchase_payment' => 'PP',
        'expense_payment' => 'EXP',
        'business_location' => 'BL',
        'username' => 'USR',
        'subscription' => 'SUB',
        'table' => 'TBL',
        'service_staff' => 'SS',
        'payroll' => 'PYR',
        'asset' => 'AST',
        'maintenance' => 'MNT',
        'journal_entry' => 'JE',
        'transfer' => 'TRF',
        'leave' => 'LV',
    ];

    /**
     * Increment and return the counter for a reference type.
     *
     * Uses an atomic row lock so two concurrent requests cannot be handed the
     * same number.
     */
    public function nextCount(string $refType, ?int $businessId = null): int
    {
        $businessId ??= Tenancy::id();

        return DB::transaction(function () use ($refType, $businessId) {
            $row = ReferenceCount::withoutGlobalScope(\App\Scopes\BusinessScope::class)
                ->where('ref_type', $refType)
                ->where('business_id', $businessId)
                ->lockForUpdate()
                ->first();

            if (empty($row)) {
                $row = new ReferenceCount([
                    'ref_type' => $refType,
                    'business_id' => $businessId,
                    'ref_count' => 0,
                ]);
            }

            $row->ref_count = (int) $row->ref_count + 1;
            $row->save();

            return (int) $row->ref_count;
        });
    }

    /**
     * Build a reference number, e.g. "PO2026/0042".
     *
     * @param  string|null  $prefix  overrides the tenant's configured prefix
     */
    public function generate(
        string $refType,
        ?int $businessId = null,
        ?string $prefix = null,
        int $padTo = 4
    ): string {
        $count = $this->nextCount($refType, $businessId);

        if (is_null($prefix)) {
            $prefix = $this->prefixFor($refType);
        }

        $separator = config('constants.invoice_scheme_separator', '');

        return $prefix.$separator.str_pad((string) $count, $padTo, '0', STR_PAD_LEFT);
    }

    /**
     * The tenant's configured prefix for a reference type, falling back to the
     * built-in default.
     */
    public function prefixFor(string $refType): string
    {
        $configured = session('business.ref_no_prefixes');

        if (is_array($configured) && ! empty($configured[$refType])) {
            return (string) $configured[$refType];
        }

        return static::TYPES[$refType] ?? strtoupper(substr($refType, 0, 3));
    }

    /**
     * Next sale invoice number for a location.
     *
     * Draft and quotation documents use the location's own scheme too, so
     * numbering stays continuous when a draft is converted to an invoice.
     */
    public function invoiceNumber(int $locationId, bool $isQuotation = false): string
    {
        $location = BusinessLocation::findOrFail($locationId);

        $schemeId = $isQuotation
            ? ($location->sale_invoice_scheme_id ?: $location->invoice_scheme_id)
            : ($location->sale_invoice_scheme_id ?: $location->invoice_scheme_id);

        return DB::transaction(function () use ($schemeId) {
            $scheme = InvoiceScheme::withoutGlobalScope(\App\Scopes\BusinessScope::class)
                ->lockForUpdate()
                ->find($schemeId);

            if (empty($scheme)) {
                // No scheme configured — fall back to the generic counter.
                return $this->generate('sell');
            }

            return $scheme->generateNumber();
        });
    }

    /**
     * A unique, unguessable token for public invoice / catalogue links.
     */
    public function token(int $length = 48): string
    {
        return \Illuminate\Support\Str::random($length);
    }
}
