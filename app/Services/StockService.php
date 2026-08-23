<?php

namespace App\Services;

use App\Models\PurchaseLine;
use App\Models\TransactionSellLine;
use App\Models\TransactionSellLinesPurchaseLines;
use App\Models\VariationLocationDetails;
use Illuminate\Support\Facades\DB;

/**
 * Stock movement + FIFO lot mapping.
 *
 * This is the integrity core of the system. Two records must always agree:
 *
 *   1. `variation_location_details.qty_available` — the fast stock cache.
 *   2. `transaction_sell_lines_purchase_lines`    — the FIFO map that says
 *      which lot each outgoing unit came from.
 *
 * The original code updated these in separate places, which is why the source
 * repo ships ~30 `fix:*`/`stock:*` repair commands (§15.3 of the audit). Here
 * every public method that moves stock does both updates, and every one of
 * them asserts it is running inside a database transaction so a partial
 * movement can never be committed.
 */
class StockService
{
    public function __construct(private FormattingService $format) {}

    /* ====================================================================
     | Stock cache
     ==================================================================== */

    /**
     * Add to (or subtract from) the stock cache for a variation/location.
     *
     * @param  float  $quantity  positive to increase, negative to decrease
     */
    public function adjustCachedQuantity(
        int $locationId,
        int $productId,
        int $variationId,
        float $quantity
    ): VariationLocationDetails {
        $this->assertInTransaction();

        // lockForUpdate serialises concurrent POS sales of the same product.
        $details = VariationLocationDetails::where('variation_id', $variationId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (empty($details)) {
            $variation = \App\Models\Variation::findOrFail($variationId);

            $details = new VariationLocationDetails([
                'product_id' => $productId,
                'product_variation_id' => $variation->product_variation_id,
                'variation_id' => $variationId,
                'location_id' => $locationId,
                'qty_available' => 0,
            ]);
        }

        $details->qty_available = round((float) $details->qty_available + $quantity, 4);
        $details->save();

        return $details;
    }

    /**
     * Current cached stock for a variation at a location.
     */
    public function currentStock(int $variationId, ?int $locationId = null): float
    {
        $query = VariationLocationDetails::where('variation_id', $variationId);

        if (! is_null($locationId)) {
            $query->where('location_id', $locationId);
        }

        return round((float) $query->sum('qty_available'), 4);
    }

    /* ====================================================================
     | FIFO mapping
     ==================================================================== */

    /**
     * Consume stock for an outgoing line, oldest lot first.
     *
     * Writes the FIFO map rows, bumps the consumption counters on each lot,
     * and returns the weighted purchase cost of the quantity taken so the
     * caller can record profit.
     *
     * @param  string  $consumerType  'sell' or 'stock_adjustment'
     * @param  int|null  $preferredLotId  honour an explicitly chosen lot first
     * @return array{cost: float, allocated: float, shortfall: float}
     */
    public function consume(
        int $variationId,
        int $locationId,
        float $quantity,
        int $consumerId,
        string $consumerType = 'sell',
        ?int $preferredLotId = null
    ): array {
        $this->assertInTransaction();

        $quantity = round($quantity, 4);

        if ($quantity <= 0) {
            return ['cost' => 0.0, 'allocated' => 0.0, 'shortfall' => 0.0];
        }

        $remaining = $quantity;
        $totalCost = 0.0;

        foreach ($this->availableLots($variationId, $locationId, $preferredLotId) as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $lotAvailable = $this->lotRemaining($lot);

            if ($lotAvailable <= 0) {
                continue;
            }

            $take = min($lotAvailable, $remaining);

            $this->writeMapRow($consumerType, $consumerId, $lot->id, $take);
            $this->incrementLotUsage($lot, $consumerType, $take);

            $totalCost += $take * (float) $lot->purchase_price_inc_tax;
            $remaining = round($remaining - $take, 4);
        }

        return [
            'cost' => round($totalCost, 4),
            'allocated' => round($quantity - $remaining, 4),
            // > 0 means the sale went beyond available stock (overselling).
            'shortfall' => round($remaining, 4),
        ];
    }

    /**
     * Release a previously consumed quantity back to its lots.
     *
     * Used when an outgoing document is edited or deleted. Reverses the map
     * rows newest-first so the lot ordering stays intuitive.
     */
    public function release(int $consumerId, string $consumerType = 'sell'): float
    {
        $this->assertInTransaction();

        $column = $consumerType === 'sell' ? 'sell_line_id' : 'stock_adjustment_line_id';

        $mapRows = TransactionSellLinesPurchaseLines::where($column, $consumerId)
            ->orderByDesc('id')
            ->get();

        $released = 0.0;

        foreach ($mapRows as $row) {
            $lot = PurchaseLine::lockForUpdate()->find($row->purchase_line_id);

            if (! empty($lot)) {
                $this->decrementLotUsage($lot, $consumerType, (float) $row->quantity);
            }

            $released = round($released + (float) $row->quantity, 4);
            $row->delete();
        }

        return $released;
    }

    /**
     * Record a return against an outgoing line: puts quantity back into the
     * lots it came from without deleting the original mapping.
     */
    public function returnToLots(int $consumerId, float $quantity, string $consumerType = 'sell'): float
    {
        $this->assertInTransaction();

        $quantity = round($quantity, 4);

        if ($quantity <= 0) {
            return 0.0;
        }

        $column = $consumerType === 'sell' ? 'sell_line_id' : 'stock_adjustment_line_id';

        $mapRows = TransactionSellLinesPurchaseLines::where($column, $consumerId)
            ->orderByDesc('id')
            ->get();

        $remaining = $quantity;

        foreach ($mapRows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $returnable = round((float) $row->quantity - (float) $row->qty_returned, 4);

            if ($returnable <= 0) {
                continue;
            }

            $take = min($returnable, $remaining);

            $row->qty_returned = round((float) $row->qty_returned + $take, 4);
            $row->save();

            $lot = PurchaseLine::lockForUpdate()->find($row->purchase_line_id);

            if (! empty($lot)) {
                $this->decrementLotUsage($lot, $consumerType, $take);
            }

            $remaining = round($remaining - $take, 4);
        }

        return round($quantity - $remaining, 4);
    }

    /* ====================================================================
     | Purchase-side (lot) helpers
     ==================================================================== */

    /**
     * Reduce a lot's quantity, refusing to go below what has been consumed.
     *
     * Guards against editing a purchase down to less than what was already
     * sold out of it.
     */
    public function reduceLotQuantity(PurchaseLine $lot, float $newQuantity): void
    {
        $this->assertInTransaction();

        $used = $this->lotUsed($lot);

        if (round($newQuantity, 4) < $used) {
            throw new \RuntimeException(__('lang_v1.quantity_already_sold', [
                'used' => $this->format->quantity($used),
            ]));
        }

        $lot->quantity = round($newQuantity, 4);
        $lot->save();
    }

    /**
     * Quantity of a lot still unconsumed.
     */
    public function lotRemaining(PurchaseLine $lot): float
    {
        return round((float) $lot->quantity - $this->lotUsed($lot), 4);
    }

    /**
     * Quantity of a lot already consumed by sales, adjustments or returns.
     */
    public function lotUsed(PurchaseLine $lot): float
    {
        return round(
            (float) $lot->quantity_sold
            + (float) $lot->quantity_adjusted
            + (float) $lot->quantity_returned,
            4
        );
    }

    /* ====================================================================
     | Integrity
     ==================================================================== */

    /**
     * Compare the stock cache against the FIFO map for one variation.
     *
     * @return array{cached: float, fifo: float, difference: float}
     */
    public function reconcile(int $variationId, int $locationId): array
    {
        $cached = $this->currentStock($variationId, $locationId);

        // Everything that came in at this location…
        $purchased = (float) PurchaseLine::query()
            ->join('transactions as t', 't.id', '=', 'purchase_lines.transaction_id')
            ->where('purchase_lines.variation_id', $variationId)
            ->where('t.location_id', $locationId)
            ->whereIn('t.type', \App\Support\TransactionTypes::stockIn())
            ->where('t.status', '!=', 'pending')
            ->sum('purchase_lines.quantity');

        // …minus everything consumed out of those lots.
        $consumed = (float) PurchaseLine::query()
            ->join('transactions as t', 't.id', '=', 'purchase_lines.transaction_id')
            ->where('purchase_lines.variation_id', $variationId)
            ->where('t.location_id', $locationId)
            ->whereIn('t.type', \App\Support\TransactionTypes::stockIn())
            ->sum(DB::raw(
                'purchase_lines.quantity_sold
                 + purchase_lines.quantity_adjusted
                 + purchase_lines.quantity_returned'
            ));

        $fifo = round($purchased - $consumed, 4);

        return [
            'cached' => $cached,
            'fifo' => $fifo,
            'difference' => round($cached - $fifo, 4),
        ];
    }

    /* ====================================================================
     | Internals
     ==================================================================== */

    /**
     * Lots with stock left, oldest first — honouring an explicit lot choice.
     *
     * @return \Illuminate\Support\Collection<int, PurchaseLine>
     */
    protected function availableLots(
        int $variationId,
        int $locationId,
        ?int $preferredLotId = null
    ) {
        $query = PurchaseLine::query()
            ->select('purchase_lines.*')
            ->join('transactions as t', 't.id', '=', 'purchase_lines.transaction_id')
            ->where('purchase_lines.variation_id', $variationId)
            ->where('t.location_id', $locationId)
            ->whereIn('t.type', \App\Support\TransactionTypes::stockIn())
            ->where('t.status', '!=', 'pending')
            ->whereRaw(
                'purchase_lines.quantity >
                 (purchase_lines.quantity_sold
                  + purchase_lines.quantity_adjusted
                  + purchase_lines.quantity_returned)'
            )
            ->lockForUpdate();

        // FIFO order: by document date, then by lot id. An explicitly chosen
        // lot jumps the queue.
        if (! empty($preferredLotId)) {
            $query->orderByRaw('purchase_lines.id = ? DESC', [$preferredLotId]);
        }

        return $query->orderBy('t.transaction_date')
            ->orderBy('purchase_lines.id')
            ->get();
    }

    protected function writeMapRow(
        string $consumerType,
        int $consumerId,
        int $lotId,
        float $quantity
    ): void {
        TransactionSellLinesPurchaseLines::create([
            'sell_line_id' => $consumerType === 'sell' ? $consumerId : null,
            'stock_adjustment_line_id' => $consumerType === 'sell' ? null : $consumerId,
            'purchase_line_id' => $lotId,
            'quantity' => $quantity,
            'qty_returned' => 0,
        ]);
    }

    protected function incrementLotUsage(PurchaseLine $lot, string $consumerType, float $quantity): void
    {
        $column = $consumerType === 'sell' ? 'quantity_sold' : 'quantity_adjusted';

        $lot->{$column} = round((float) $lot->{$column} + $quantity, 4);
        $lot->save();
    }

    protected function decrementLotUsage(PurchaseLine $lot, string $consumerType, float $quantity): void
    {
        $column = $consumerType === 'sell' ? 'quantity_sold' : 'quantity_adjusted';

        $lot->{$column} = round(max(0, (float) $lot->{$column} - $quantity), 4);
        $lot->save();
    }

    /**
     * Stock movements are never safe outside a transaction — the cache and
     * the FIFO map would be able to diverge.
     */
    protected function assertInTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(
                static::class.': stock movements must run inside a database transaction.'
            );
        }
    }
}
