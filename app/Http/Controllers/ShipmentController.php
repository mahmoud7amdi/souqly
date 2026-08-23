<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FormattingService;
use App\Support\TransactionTypes;
use Illuminate\Http\Request;

/**
 * Shipments — the delivery view of sales that have left the counter.
 *
 * Not a document type of its own: a shipment IS a sale, seen through its
 * shipping columns. So this controller only lists and filters; the one write
 * lives on SellController::updateShipping(), next to the service call that keeps
 * the document's totals derived in one place.
 *
 * Shipping never moves stock — the goods left when the invoice was finalised.
 */
class ShipmentController extends Controller
{
    public function __construct(private FormattingService $format) {}

    public function index(Request $request)
    {
        $this->permit('access_shipping', 'access_own_shipping');

        $shipments = Transaction::with(['contact', 'location'])
            ->where('type', TransactionTypes::SELL)
            ->where('status', TransactionTypes::STATUS_FINAL)
            // A sale with no shipping status was collected at the counter. It is
            // not a shipment that has yet to be packed, and listing it as one
            // would bury the deliveries that do need attention.
            ->whereNotNull('shipping_status')
            ->permittedLocations()
            ->when($this->viewOwnOnly(), fn ($q) => $q->where('created_by', auth()->id()))
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search').'%';
                $query->where(fn ($q) => $q->where('invoice_no', 'like', $term)
                    ->orWhere('shipping_details', 'like', $term)
                    ->orWhere('delivered_to', 'like', $term));
            })
            ->when($request->filled('location_id'),
                fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('shipping_status'),
                fn ($q) => $q->where('shipping_status', $request->string('shipping_status')))
            ->when($request->filled('delivery_person'),
                fn ($q) => $q->where('delivery_person', $request->integer('delivery_person')))
            /*
             * Oldest first, which is the opposite of every other listing here and
             * deliberately so: this screen is a work queue. The parcel that has
             * been waiting longest is the one to pack next, so it belongs at the
             * top rather than three pages down.
             */
            ->orderBy('transaction_date')
            ->paginate(25)
            ->withQueryString();

        return view('shipment.index', [
            'shipments' => $shipments,
            'locations' => BusinessLocation::forDropdown(true),
            'shippingStatuses' => ['' => __('lang_v1.all')] + collect(
                TransactionTypes::shippingStatuses()
            )->mapWithKeys(fn ($label, $value) => [$value => __($label)])->all(),
            'deliveryPeople' => ['' => __('lang_v1.all')] + User::forDropdown(),
        ]);
    }

    protected function viewOwnOnly(): bool
    {
        return ! $this->allows('access_shipping')
            && $this->allows('access_own_shipping');
    }
}
