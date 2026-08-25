<?php

namespace App\Services;

use App\Modules\AssetManagement\Models\Asset;
use App\Modules\AssetManagement\Models\AssetMaintenance;
use App\Modules\AssetManagement\Models\AssetTransaction;
use App\Modules\AssetManagement\Models\AssetWarranty;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The fixed-asset register: what the business owns, who is holding it, and what it
 * is worth now.
 *
 * This is not stock. Stock is consumed and re-bought and its quantity is the whole
 * point; an asset is bought once, held for years, handed to people and taken back,
 * and its quantity almost never changes. So none of the FIFO machinery applies and
 * there is no `Transaction` document — an allocation is a row in
 * `asset_transactions` and nothing else, and nothing here touches the ledger.
 *
 * Three rules live here rather than in the controller, because all three are about
 * arithmetic the request cannot see:
 *
 * - **You cannot allocate what is already out.** `available_quantity` is derived
 *   from the transaction rows, so no unique index can catch a double allocation
 *   after the fact. {@see self::allocate()} takes a row lock on the asset first.
 * - **You cannot return more than went out.** Same shape, one level down:
 *   {@see self::revoke()} locks the allocation it is closing.
 * - **You cannot shrink or lock an asset out from under an outstanding
 *   allocation.** A register that says five exist while six are signed out, or that
 *   stops offering a Return button while a laptop is on somebody's desk, is worse
 *   than one that refuses the edit.
 *
 * Everything that refuses does it with {@see \RuntimeException}, whose message
 * `Controller::failed()` surfaces to the user — these are decisions a person can
 * act on, not faults.
 */
class AssetService
{
    public function __construct(
        private ReferenceService $references,
        private FormattingService $format,
    ) {}

    /* ================================================================
     | The register
     ================================================================ */

    public function create(array $data): Asset
    {
        $businessId = Tenancy::id();

        return Asset::create($this->columns($data) + [
            'business_id' => $businessId,
            /*
             * Typed codes are kept when given. An asset register is usually being
             * transcribed from an existing one — a sticker on the back of the
             * machine, a line in a spreadsheet — and renumbering it on import is
             * how the register stops matching the stickers.
             */
            'asset_code' => ($data['asset_code'] ?? null) ?: $this->references->generate('asset', $businessId),
            'created_by' => auth()->id(),
        ]);
    }

    public function update(Asset $asset, array $data): Asset
    {
        $allocated = $asset->allocated_quantity;

        if (array_key_exists('quantity', $data) && (float) $data['quantity'] < $allocated) {
            throw new \RuntimeException(__('assetmanagement.quantity_below_allocated', [
                'allocated' => $this->format->quantity($allocated),
            ]));
        }

        /*
         * Clearing `is_allocatable` while something is still out would orphan the
         * outstanding rows: the asset stops offering a Return, and the only way to
         * close the allocation becomes editing the database. Refuse it and say so.
         */
        if ($allocated > 0 && ! ($data['is_allocatable'] ?? false)) {
            throw new \RuntimeException(__('assetmanagement.cannot_disable_allocation'));
        }

        $asset->fill($this->columns($data));

        if (! empty($data['asset_code'])) {
            $asset->asset_code = $data['asset_code'];
        }

        $asset->save();

        return $asset;
    }

    public function delete(Asset $asset): void
    {
        if ($asset->allocated_quantity > 0) {
            throw new \RuntimeException(__('assetmanagement.cannot_delete_allocated'));
        }

        DB::transaction(function () use ($asset) {
            /*
             * `asset_warranties` and `asset_maintenances` index `asset_id` and stop
             * there — no foreign key, so no cascade — while `asset_transactions`
             * does cascade, and its own revocations cascade behind it through
             * `parent_id`. Two of the four children have to go by hand, and which
             * two is not guessable from the models.
             */
            $asset->warranties()->delete();
            $asset->maintenances()->delete();
            $asset->delete();
        });
    }

    /* ================================================================
     | Allocation
     ================================================================ */

    /**
     * Hand an asset, or some of it, to a person.
     */
    public function allocate(Asset $asset, array $data): AssetTransaction
    {
        if (! $asset->is_allocatable) {
            throw new \RuntimeException(__('assetmanagement.asset_not_allocatable'));
        }

        return DB::transaction(function () use ($asset, $data) {
            /*
             * Re-read under a row lock and check availability against *that*, not
             * against the model the request arrived with. Two people allocating the
             * last unit at the same moment is the one race this screen genuinely
             * has, and because availability is derived from the transaction rows
             * there is no constraint that would catch it afterwards.
             */
            $locked = Asset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();

            $quantity = (float) $data['quantity'];
            $available = $locked->available_quantity;

            if ($quantity > $available) {
                throw new \RuntimeException(__('assetmanagement.quantity_exceeds_available', [
                    'available' => $this->format->quantity($available),
                ]));
            }

            return AssetTransaction::create([
                'business_id' => Tenancy::id(),
                'asset_id' => $locked->id,
                'transaction_type' => 'allocate',
                'ref_no' => $this->references->generate('asset_transaction'),
                'receiver' => (int) $data['receiver'],
                'quantity' => $quantity,
                'transaction_datetime' => $data['transaction_datetime'] ?? now(),
                'allocated_upto' => $data['allocated_upto'] ?? null,
                'reason' => $data['reason'] ?? null,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Take it back — all of it, or part.
     *
     * Partial returns exist because allocations are quantified: three of the five
     * tablets came back and two did not, and forcing that to be recorded as one
     * return of five would make the register lie about where two tablets are.
     */
    public function revoke(AssetTransaction $allocation, array $data = []): AssetTransaction
    {
        if ($allocation->transaction_type !== 'allocate') {
            throw new \InvalidArgumentException(__('assetmanagement.not_an_allocation'));
        }

        return DB::transaction(function () use ($allocation, $data) {
            $locked = AssetTransaction::query()
                ->whereKey($allocation->id)->lockForUpdate()->firstOrFail();

            $outstanding = $locked->quantity_outstanding;

            if ($outstanding <= 0) {
                throw new \RuntimeException(__('assetmanagement.already_returned'));
            }

            // An empty quantity means "all of it", which is what the button on the
            // allocation row submits and what the common case actually is.
            $quantity = (float) (($data['quantity'] ?? null) ?: $outstanding);

            if ($quantity > $outstanding) {
                throw new \RuntimeException(__('assetmanagement.quantity_exceeds_outstanding', [
                    'outstanding' => $this->format->quantity($outstanding),
                ]));
            }

            return AssetTransaction::create([
                'business_id' => $locked->business_id,
                'asset_id' => $locked->asset_id,
                'transaction_type' => 'revoke',
                'ref_no' => $this->references->generate('asset_transaction'),
                /*
                 * The same person as the allocation, deliberately. `receiver` on a
                 * revocation reads as who it came back *from*, which is the only
                 * reading that lets the pair be understood as one movement of one
                 * object rather than two unrelated rows.
                 */
                'receiver' => $locked->receiver,
                'quantity' => $quantity,
                'transaction_datetime' => $data['transaction_datetime'] ?? now(),
                'reason' => $data['reason'] ?? null,
                'parent_id' => $locked->id,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /* ================================================================
     | Warranty
     ================================================================ */

    public function addWarranty(Asset $asset, array $data): AssetWarranty
    {
        return $asset->warranties()->create([
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'additional_cost' => (float) ($data['additional_cost'] ?? 0),
            'additional_note' => $data['additional_note'] ?? null,
        ]);
    }

    /* ================================================================
     | Maintenance
     ================================================================ */

    public function createMaintenance(Asset $asset, array $data): AssetMaintenance
    {
        return AssetMaintenance::create($this->maintenanceColumns($data) + [
            'business_id' => Tenancy::id(),
            'asset_id' => $asset->id,
            /*
             * `maitenance_id` is misspelled in the schema and is left that way on
             * purpose: renaming a column to fix a typo is a migration against a
             * table that already exists in every install, and the comment costs
             * nothing. It holds the job's reference number — MNT0001 — not a
             * foreign key, which the name also fails to suggest.
             */
            'maitenance_id' => ($data['maintenance_ref'] ?? null) ?: $this->references->generate('maintenance'),
            'created_by' => auth()->id(),
        ]);
    }

    public function updateMaintenance(AssetMaintenance $maintenance, array $data): AssetMaintenance
    {
        $maintenance->fill($this->maintenanceColumns($data));

        if (! empty($data['maintenance_ref'])) {
            $maintenance->maitenance_id = $data['maintenance_ref'];
        }

        $maintenance->save();

        return $maintenance;
    }

    public function deleteMaintenance(AssetMaintenance $maintenance): void
    {
        $maintenance->delete();
    }

    /* ================================================================
     | Figures
     ================================================================ */

    /**
     * Assets with something still signed out, as `asset_id => quantity still out`.
     *
     * One grouped pass over the movements rather than a `whereHas` per asset:
     * "still out" is a sum across two transaction types, not the existence of one,
     * so it cannot be asked as an existence check. Both the list screen's filter
     * and the tiles read it, which is the reason it is public and lives here rather
     * than being written twice.
     *
     * @param  mixed  $assetIds  ids, or a query selecting them; null means all ours
     * @return \Illuminate\Support\Collection<int, float>
     */
    public function outstandingByAsset(mixed $assetIds = null): \Illuminate\Support\Collection
    {
        return AssetTransaction::query()
            ->forBusiness()
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->selectRaw(
                "asset_id, SUM(CASE WHEN transaction_type = 'allocate' THEN quantity ELSE -quantity END) AS net"
            )
            ->groupBy('asset_id')
            ->havingRaw('net > 0')
            ->pluck('net', 'asset_id')
            ->map(fn ($net) => round((float) $net, 4));
    }

    /**
     * The four figures the register's list screen leads with.
     *
     * Takes the screen's own filtered query so the tiles describe what is on
     * screen — and takes it *before* the list aggregates are attached, because
     * `COUNT(*)` beside a correlated subquery is how a query stops being valid
     * under `ONLY_FULL_GROUP_BY`.
     *
     * Acquisition cost, not book value, and that is a decision: book value is
     * per-asset arithmetic over each asset's own purchase date, defined once in
     * {@see Asset::getCurrentValueAttribute()}. Re-expressing straight-line
     * depreciation as a SQL aggregate would give a headline figure that disagreed
     * with the sum of the rows under it by a rounding-of-a-year, which is exactly
     * the class of bug this codebase keeps finding. Book value stays on the row and
     * on the asset's own screen, where one function computes it.
     *
     * @param  Builder<Asset>  $query
     * @return array<string, float|int>
     */
    public function summary(Builder $query): array
    {
        $assetIds = $query->clone()->select('assets.id');

        $totals = $query->clone()->reorder()->selectRaw(
            'COUNT(*) AS assets, COALESCE(SUM(quantity * unit_price), 0) AS cost'
        )->first();

        $outstanding = $this->outstandingByAsset($assetIds->clone());

        return [
            'assets' => (int) ($totals->assets ?? 0),
            'cost' => round((float) ($totals->cost ?? 0), 4),
            'allocated_assets' => $outstanding->count(),
            'allocated_qty' => round((float) $outstanding->sum(), 4),
            'open_maintenance' => AssetMaintenance::query()
                ->forBusiness()
                ->whereIn('asset_id', $assetIds->clone())
                // `status` is nullable in the schema, and NULL fails a NOT IN — so
                // a row with no status would silently drop out of the count.
                ->where(fn ($q) => $q->whereNull('status')
                    ->orWhereNotIn('status', ['completed', 'cancelled']))
                ->count(),
        ];
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return [
            'name' => $data['name'],
            'quantity' => (float) ($data['quantity'] ?? 0),
            'model' => $data['model'] ?? null,
            'serial_no' => $data['serial_no'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'purchase_date' => $data['purchase_date'] ?? null,
            'purchase_type' => $data['purchase_type'] ?? null,
            'unit_price' => (float) ($data['unit_price'] ?? 0),
            'depreciation' => $data['depreciation'] ?? null,
            'is_allocatable' => (bool) ($data['is_allocatable'] ?? false),
            'description' => $data['description'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function maintenanceColumns(array $data): array
    {
        return [
            'status' => $data['status'] ?? 'scheduled',
            'priority' => $data['priority'] ?? 'medium',
            'assigned_to' => $data['assigned_to'] ?? null,
            'details' => $data['details'] ?? null,
            'maintenance_note' => $data['maintenance_note'] ?? null,
        ];
    }
}
