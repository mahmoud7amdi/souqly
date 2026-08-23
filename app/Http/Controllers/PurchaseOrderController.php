<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * Purchase orders — a commitment to a supplier. No stock moves; fulfilment is
 * tracked as invoices are raised against the order.
 */
class PurchaseOrderController extends PurchaseController
{
    protected function type(): string
    {
        return TransactionTypes::PURCHASE_ORDER;
    }

    protected function prefix(): string
    {
        return 'purchase-order';
    }

    protected function permission(): string
    {
        return 'purchase_order';
    }

    protected function permitView(): void
    {
        $this->permit('purchase_order.view_all', 'purchase_order.view_own');
    }

    protected function viewOwnOnly(): bool
    {
        return ! $this->allows('purchase_order.view_all')
            && $this->allows('purchase_order.view_own');
    }

    /**
     * Orders progress ordered → partial → completed as invoices consume them;
     * `partial` and `completed` are set by the service, not chosen by hand.
     *
     * @return array<string, string>
     */
    protected function statusOptions(): array
    {
        return [
            TransactionTypes::STATUS_ORDERED => __('lang_v1.ordered'),
            TransactionTypes::STATUS_PARTIAL => __('lang_v1.partial'),
            TransactionTypes::STATUS_COMPLETED => __('lang_v1.completed'),
        ];
    }

    /**
     * Printable / downloadable PDF of the order.
     */
    public function downloadPdf(int $id)
    {
        $this->permitView();

        $order = $this->findDocument($id, [
            'contact', 'location', 'purchase_lines.variations.product', 'tax',
        ]);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('purchase.pdf', [
            'document' => $order,
            'title' => __('lang_v1.purchase_order'),
        ])->setPaper('a4');

        return $pdf->download('PO-'.$order->ref_no.'.pdf');
    }

    /**
     * Manually close or reopen an order (e.g. the supplier cannot fulfil the
     * remainder).
     */
    public function updateOrderStatus(Request $request, int $id)
    {
        $this->permit('purchase_order.update');

        $request->validate([
            'status' => 'required|in:ordered,partial,completed',
        ]);

        $order = $this->findDocument($id);
        $order->status = $request->string('status');
        $order->save();

        return back()->with('status', $this->ok(__('lang_v1.updated_successfully')));
    }
}
