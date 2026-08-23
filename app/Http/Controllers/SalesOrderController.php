<?php

namespace App\Http\Controllers;

use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * Sales orders — a customer's commitment to buy. No stock moves and nothing is
 * reserved; fulfilment is tracked as invoices are raised against the order and
 * the status is derived by SellService (ordered → partial → completed).
 *
 * The purchase-side twin is PurchaseOrderController; the two read the same on
 * purpose.
 */
class SalesOrderController extends SellController
{
    protected function type(): string
    {
        return TransactionTypes::SALES_ORDER;
    }

    protected function prefix(): string
    {
        return 'sales-order';
    }

    protected function permission(): string
    {
        return 'so';
    }

    protected function permitView(): void
    {
        $this->permit('so.view_all', 'so.view_own');
    }

    protected function permitCreate(): void
    {
        $this->permit('so.create');
    }

    protected function permitUpdate(): void
    {
        $this->permit('so.update');
    }

    protected function permitDelete(): void
    {
        $this->permit('so.delete');
    }

    protected function viewOwnOnly(): bool
    {
        return ! $this->allows('so.view_all') && $this->allows('so.view_own');
    }

    /**
     * Orders progress ordered → partial → completed as invoices consume them.
     * `partial` and `completed` are written by SellService::refreshOrderStatus()
     * from the invoiced quantities, never chosen by hand on the form.
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

    protected function headingFor(string $variant): string
    {
        return __('lang_v1.sales_orders');
    }

    /**
     * Manually close or reopen an order — e.g. the customer cancels the
     * remainder, so it should stop appearing as outstanding.
     *
     * Written straight to the column rather than through the service: this is
     * the one case where the derived status is deliberately overridden, and
     * nothing about the lines or stock changes.
     */
    public function updateOrderStatus(Request $request, int $id)
    {
        $this->permitUpdate();

        $request->validate([
            'status' => 'required|in:'.implode(',', array_keys($this->statusOptions())),
        ]);

        $order = $this->findDocument($id);
        $order->status = $request->string('status');
        $order->save();

        return back()->with('status', $this->ok(__('lang_v1.updated_successfully')));
    }
}
