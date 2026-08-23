<?php

namespace App\Http\Controllers;

use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * Purchase requisitions — an internal request to buy, raised before a supplier
 * is chosen. No stock and no money; it feeds a purchase order.
 */
class PurchaseRequisitionController extends PurchaseController
{
    protected function type(): string
    {
        return TransactionTypes::PURCHASE_REQUISITION;
    }

    protected function prefix(): string
    {
        return 'purchase-requisition';
    }

    protected function permission(): string
    {
        return 'purchase_requisition';
    }

    protected function permitView(): void
    {
        $this->permit('purchase_requisition.view_all', 'purchase_requisition.view_own');
    }

    protected function viewOwnOnly(): bool
    {
        return ! $this->allows('purchase_requisition.view_all')
            && $this->allows('purchase_requisition.view_own');
    }

    /**
     * @return array<string, string>
     */
    protected function statusOptions(): array
    {
        return [
            TransactionTypes::STATUS_ORDERED => __('lang_v1.requested'),
            TransactionTypes::STATUS_PARTIAL => __('lang_v1.partial'),
            TransactionTypes::STATUS_COMPLETED => __('lang_v1.completed'),
        ];
    }

    /**
     * A requisition has no supplier yet, so `contact_id` is optional here.
     *
     * @return array<string, mixed>
     */
    protected function validateDocument(Request $request, ?Transaction $document = null): array
    {
        $rules = parent::validateDocument($request, $document);

        return $rules;
    }

    /**
     * Outstanding requisition lines across the tenant, for building an order.
     */
    public function outstandingLines(Request $request)
    {
        $this->permitView();

        $lines = PurchaseLine::query()
            ->select('purchase_lines.*')
            ->join('transactions as t', 't.id', '=', 'purchase_lines.transaction_id')
            ->with('variations.product')
            ->where('t.type', TransactionTypes::PURCHASE_REQUISITION)
            ->whereIn('t.status', [
                TransactionTypes::STATUS_ORDERED,
                TransactionTypes::STATUS_PARTIAL,
            ])
            ->when($request->filled('location_id'),
                fn ($q) => $q->where('t.location_id', $request->integer('location_id')))
            // Only lines not yet fully converted into a purchase.
            ->whereRaw('purchase_lines.quantity > purchase_lines.po_quantity_purchased')
            ->get();

        return response()->json($lines->map(fn ($line) => [
            'purchase_requisition_line_id' => $line->id,
            'variation_id' => $line->variation_id,
            'name' => $line->variations->full_name,
            'sku' => $line->variations->sub_sku,
            'requested' => (float) $line->quantity,
            'quantity' => round((float) $line->quantity - (float) $line->po_quantity_purchased, 4),
            'purchase_price' => (float) $line->purchase_price,
            'purchase_price_inc_tax' => (float) $line->purchase_price_inc_tax,
        ]));
    }
}
