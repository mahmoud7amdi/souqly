<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a replayed offline sale impossible to record twice.
 *
 * `offline_temp_id` has been on `transactions` since the first migration, and it
 * has been *indexed* rather than *unique* the whole time. That distinction is the
 * entire correctness story of the offline queue.
 *
 * The queue's failure mode is not "the request was lost", it is "the request
 * arrived and the answer did not". A till on a shop's flaky uplink POSTs a batch
 * of sales, the server commits them, the response times out on the way back, and
 * the client — correctly, because it has no way to tell a lost request from a lost
 * reply — sends the same batch again. With a plain index the second attempt writes
 * a second sale: the day's takings are overstated, and the stock the shop thinks
 * it has is lower than what is on the shelf. That is a bug that reconciles to
 * money, so it is not one to leave to application code alone.
 *
 * So there are two layers, and they are not redundant:
 *
 *   1. {@see \App\Http\Controllers\Api\OfflineSyncController::replay()} looks the
 *      temp id up first and answers `duplicate` with the id of the sale that
 *      already exists. This is the layer that produces a *useful* answer — the
 *      till needs to know the sale landed so it can drop it from the queue.
 *   2. This index. It is what makes layer 1 true under concurrency. Two requests
 *      carrying the same temp id can both pass the lookup before either commits;
 *      only the index can settle which one wins. The controller catches the
 *      violation and re-reads, so the loser reports `duplicate` as well.
 *
 * COMPOSITE ON (business_id, offline_temp_id), NOT ON THE COLUMN ALONE. Temp ids
 * are generated on the client (`crypto.randomUUID()`), so a collision across two
 * unrelated businesses is vanishingly unlikely — but "vanishingly unlikely" is the
 * wrong standard for a constraint that would refuse a real sale in shop B because
 * shop A once made one. Scoping it to the business also matches how every read of
 * the column already works: `Transaction` uses BelongsToBusiness, so the lookup in
 * layer 1 is business-scoped and the constraint now agrees with it.
 *
 * NULLS ARE WHY THIS IS SAFE ON A LIVE TABLE. MySQL permits any number of rows
 * whose indexed columns include a NULL, so every sale ever entered on the screen —
 * all of them, since nothing has ever written this column — is exempt. The index
 * constrains only rows that came from the queue.
 *
 * The pre-existing single-column index is left in place. It is not made redundant
 * by this one: a composite index serves lookups on its leftmost prefix, and the
 * prefix here is `business_id`, not the temp id. Dropping it would cost the
 * duplicate check its index.
 */
return new class extends Migration
{
    private const INDEX = 'transactions_business_offline_temp_id_unique';

    /**
     * Refuse to create the index while it would silently discard the very rows it
     * exists to prevent.
     *
     * `Schema::create` would fail loudly here, but this runs against a table that
     * already holds a shop's history. If duplicates are somehow present, the right
     * outcome is a migration that stops and names them — not one that fails with a
     * bare MySQL error nobody can act on, and certainly not one that "fixes" the
     * data by deleting sales.
     */
    public function up(): void
    {
        $duplicates = DB::table('transactions')
            ->select('business_id', 'offline_temp_id', DB::raw('COUNT(*) AS total'))
            ->whereNotNull('offline_temp_id')
            ->groupBy('business_id', 'offline_temp_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $sample = $duplicates->take(5)
                ->map(fn ($row) => "business {$row->business_id} / {$row->offline_temp_id} ×{$row->total}")
                ->implode('; ');

            throw new \RuntimeException(
                'Cannot add the unique index: '.$duplicates->count()
                .' offline temp id(s) are already recorded more than once ('.$sample.'). '
                .'Each group is one real sale that was replayed; keep the earliest '
                .'transaction, delete the rest through the application so stock and '
                .'payments are reversed with them, then run this migration again.'
            );
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->unique(['business_id', 'offline_temp_id'], self::INDEX);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(self::INDEX);
        });
    }
};
