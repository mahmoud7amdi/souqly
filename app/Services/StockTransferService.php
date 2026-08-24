<?php

namespace App\Services;

use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Models\TransactionSellLine;
use App\Models\Variation;
use App\Support\Tenancy;
use App\Support\TransactionTypes;
use Illuminate\Support\Facades\DB;

/**
 * Stock transfers between the tenant's own locations.
 *
 * A transfer is two documents, not one, and that is the whole design:
 *
 *   out-leg  `sell_transfer`     at the source      — consumes lots there
 *   in-leg   `purchase_transfer` at the destination — creates lots there
 *
 * They share a reference number and the in-leg carries `transfer_parent_id`
 * pointing back at the out-leg. Two documents because stock is tracked per
 * location: a single row cannot be simultaneously present at one location and
 * absent from another, and a shop's stock report has to be answerable from
 * that shop's own documents.
 *
 * WHAT "IN TRANSIT" MEANS. Goods on a van have left the source shelf and have
 * not reached the destination shelf, and the books say exactly that: the source
 * cache is decremented the moment the transfer is saved, while the destination
 * cache is not touched until somebody confirms receipt. The in-leg's lots exist
 * from the start (they need to, to hold the cost) but sit at status `pending`,
 * which is precisely the status `StockService::availableLots()` excludes — so
 * FIFO cannot hand out units that are still on the road, and the cache and the
 * lot map agree with each other at every point in between.
 *
 * TRANSFERS ARE AT COST. No margin is taken and no revenue is recognised —
 * moving your own goods between your own shelves is not a sale. The out-leg
 * consumes FIFO lots, and the weighted cost that comes back is what the
 * destination lot is created at, so a unit's cost basis survives the journey
 * and the receiving shop's margins stay true. Freight is recorded on the out-leg
 * as a shipping charge and deliberately NOT folded into the unit cost:
 * distributing carriage across lines is an accounting policy (by value? by
 * weight? by unit?), and quietly picking one would make every downstream margin
 * depend on an assumption nobody was shown.
 *
 * NO EDIT. Create, receive, delete — there is no update of a transfer's lines.
 * An edit would have to reverse two legs at two locations and re-consume FIFO at
 * the source, and the destination lots may by then have been sold on. Delete
 * already refuses in exactly that case, and re-entering a three-line transfer
 * costs less than a second reversal path that has to be right about lots it
 * cannot see. Correcting a transfer therefore means deleting it and entering it
 * again, which is also what the paperwork does.
 */
class StockTransferService
{
    public function __construct(
        private StockService $stock,
        private ReferenceService $references,
        private FormattingService $format,
    ) {}

    /* ====================================================================
     | Create
     ==================================================================== */

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function create(array $data, array $lines): Transaction
    {
        return DB::transaction(function () use ($data, $lines) {
            $from = (int) $data['location_id'];
            $to = (int) $data['transfer_location_id'];

            if ($from === $to) {
                throw new \RuntimeException(__('lang_v1.transfer_needs_two_locations'));
            }

            $status = in_array($data['status'] ?? null, [
                TransactionTypes::STATUS_IN_TRANSIT,
                TransactionTypes::STATUS_COMPLETED,
            ], true)
                ? $data['status']
                : TransactionTypes::STATUS_COMPLETED;

            $businessId = $data['business_id'] ?? Tenancy::id();
            $date = $data['transaction_date'] ?? now();
            $createdBy = $data['created_by'] ?? auth()->id();

            // One number on both halves: a transfer is one event to everybody
            // who handles it, and two numbers for one van would be two things to
            // reconcile for no gain.
            $reference = ! empty($data['ref_no'])
                ? $data['ref_no']
                : $this->references->generate('stock_transfer');

            $out = Transaction::create([
                'business_id' => $businessId,
                'location_id' => $from,
                'type' => TransactionTypes::SELL_TRANSFER,
                'status' => $status,
                'ref_no' => $reference,
                'transaction_date' => $date,
                'shipping_charges' => $this->format->numUf($data['shipping_charges'] ?? 0),
                'shipping_details' => $data['shipping_details'] ?? null,
                'additional_notes' => $data['additional_notes'] ?? null,
                'created_by' => $createdBy,
                'final_total' => 0,
            ]);

            $in = Transaction::create([
                'business_id' => $businessId,
                'location_id' => $to,
                'type' => TransactionTypes::PURCHASE_TRANSFER,
                // `pending` is load-bearing, not cosmetic — see the class
                // comment on what "in transit" means.
                'status' => $status === TransactionTypes::STATUS_COMPLETED
                    ? TransactionTypes::STATUS_RECEIVED
                    : TransactionTypes::STATUS_PENDING,
                'ref_no' => $reference,
                'transaction_date' => $date,
                'transfer_parent_id' => $out->id,
                'additional_notes' => $data['additional_notes'] ?? null,
                'created_by' => $createdBy,
                'final_total' => 0,
            ]);

            $goods = $this->syncLines($out, $in, $lines);

            $out->total_before_tax = round($goods, 4);
            $out->final_total = round($goods + (float) $out->shipping_charges, 4);
            $out->save();

            $in->total_before_tax = round($goods, 4);
            $in->final_total = round($goods, 4);
            $in->save();

            return $out->fresh(['sell_lines', 'transfer_child']);
        });
    }

    /* ====================================================================
     | Receive
     ==================================================================== */

    /**
     * Confirm that an in-transit transfer has arrived.
     *
     * This is the moment the destination's stock exists: the in-leg leaves
     * `pending`, which both bumps the cache and makes its lots visible to FIFO.
     * Idempotent by refusal rather than by silence — clicking "received" twice
     * on a stale tab should say so, not add the quantity again.
     */
    public function markReceived(Transaction $out): Transaction
    {
        return DB::transaction(function () use ($out) {
            if ($out->status !== TransactionTypes::STATUS_IN_TRANSIT) {
                throw new \RuntimeException(__('lang_v1.transfer_not_in_transit'));
            }

            $in = $out->transfer_child;

            if (empty($in)) {
                throw new \RuntimeException(__('lang_v1.transfer_half_missing'));
            }

            foreach ($in->purchase_lines as $lot) {
                $this->stock->adjustCachedQuantity(
                    $in->location_id, $lot->product_id, $lot->variation_id, (float) $lot->quantity
                );
            }

            $in->status = TransactionTypes::STATUS_RECEIVED;
            $in->save();

            $out->status = TransactionTypes::STATUS_COMPLETED;
            $out->save();

            return $out->fresh(['sell_lines', 'transfer_child']);
        });
    }

    /* ====================================================================
     | Delete
     ==================================================================== */

    /**
     * Undo a transfer, both halves of it.
     *
     * Refused once the destination has begun selling the goods. The units are
     * physically at the other shop and something downstream is already pointing
     * at their lots; unwinding that would have to unwind those sales too. The
     * honest correction at that point is a transfer back the other way, which is
     * also what actually happened.
     */
    public function delete(Transaction $out): void
    {
        DB::transaction(function () use ($out) {
            $in = $out->transfer_child;

            if (! empty($in)) {
                foreach ($in->purchase_lines as $lot) {
                    $used = $this->stock->lotUsed($lot);

                    if ($used > 0.0001) {
                        throw new \RuntimeException(__('lang_v1.cannot_delete_transfer_stock_used', [
                            'qty' => $this->format->quantity($used),
                        ]));
                    }
                }

                // Only take the quantity back off the destination if it was ever
                // put on: an in-transit transfer never reached the cache.
                $wasReceived = $in->status === TransactionTypes::STATUS_RECEIVED;

                foreach ($in->purchase_lines as $lot) {
                    if ($wasReceived) {
                        $this->stock->adjustCachedQuantity(
                            $in->location_id,
                            $lot->product_id,
                            $lot->variation_id,
                            -(float) $lot->quantity
                        );
                    }

                    $lot->delete();
                }

                $in->delete();
            }

            foreach ($out->sell_lines as $line) {
                $released = $this->stock->release($line->id, 'sell');

                if ($released > 0) {
                    $this->stock->adjustCachedQuantity(
                        $out->location_id, $line->product_id, $line->variation_id, $released
                    );
                }

                $line->delete();
            }

            $out->delete();
        });
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * Write both halves of every line and move the stock.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return float  total goods value, at cost
     */
    protected function syncLines(Transaction $out, Transaction $in, array $lines): float
    {
        $total = 0.0;
        $written = 0;
        $received = $in->status === TransactionTypes::STATUS_RECEIVED;

        foreach ($lines as $input) {
            $variation = Variation::with('product')->findOrFail($input['variation_id']);

            $quantity = $this->format->numUf($input['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            if (! $variation->product->enable_stock) {
                throw new \RuntimeException(__('lang_v1.cannot_transfer_untracked_product', [
                    'product' => $variation->full_name,
                ]));
            }

            $line = TransactionSellLine::create([
                'transaction_id' => $out->id,
                'product_id' => $variation->product_id,
                'variation_id' => $variation->id,
                'quantity' => $quantity,
                'unit_price' => 0,
                'unit_price_inc_tax' => 0,
                'item_tax' => 0,
            ]);

            $taken = $this->stock->consume(
                $variation->id, $out->location_id, $quantity, $line->id, 'sell'
            );

            /*
             * A transfer may not overdraw the source. Unlike a POS sale there is
             * nobody waiting at a counter, and unlike an adjustment the units are
             * claimed to be arriving somewhere — sending stock the source does
             * not have would create real quantity at the destination out of a
             * counting error at the source.
             */
            if ($taken['shortfall'] > 0.0001) {
                throw new \RuntimeException(__('lang_v1.transfer_exceeds_stock', [
                    'product' => $variation->full_name,
                    'available' => $this->format->quantity($taken['allocated']),
                    'requested' => $this->format->quantity($quantity),
                ]));
            }

            $this->stock->adjustCachedQuantity(
                $out->location_id, $variation->product_id, $variation->id, -$quantity
            );

            /*
             * Cost per unit as consumed. Falls back to the variation's default
             * purchase price only when the lots genuinely carry no cost — a
             * zero-priced opening stock, say — so that a transfer of such goods
             * still lands at the destination with the price the catalogue
             * believes, rather than at zero.
             */
            $unitCost = round($taken['cost'] / $quantity, 4);

            if ($unitCost <= 0) {
                $unitCost = round((float) $variation->dpp_inc_tax, 4);
            }

            $line->unit_price = $unitCost;
            $line->unit_price_inc_tax = $unitCost;
            $line->save();

            PurchaseLine::create([
                'transaction_id' => $in->id,
                'product_id' => $variation->product_id,
                'variation_id' => $variation->id,
                'quantity' => $quantity,
                'pp_without_discount' => $unitCost,
                'purchase_price' => $unitCost,
                'purchase_price_inc_tax' => $unitCost,
                'item_tax' => 0,
            ]);

            if ($received) {
                $this->stock->adjustCachedQuantity(
                    $in->location_id, $variation->product_id, $variation->id, $quantity
                );
            }

            $total += $quantity * $unitCost;
            $written++;
        }

        // An empty transfer would leave two documents saying nothing moved, and
        // the stock reports would carry them forever.
        if ($written === 0) {
            throw new \RuntimeException(__('lang_v1.nothing_to_transfer'));
        }

        return $total;
    }
}
