<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessLocation;
use App\Models\Transaction;
use App\Services\FormattingService;
use App\Services\SellService;
use App\Support\TransactionTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Replays sales a terminal took while it had no uplink.
 *
 * THE ONE HARD REQUIREMENT: EXACTLY ONCE
 *
 * The failure this endpoint exists to survive is not a lost request, it is a lost
 * *reply*. The till POSTs a batch, the server commits it, the response dies on the
 * way back, and the till — which cannot distinguish that from "the request never
 * arrived" — sends the same batch again. Anything that records the second attempt
 * as a second sale overstates the day's takings and understates the stock on the
 * shelf, and both errors reconcile to money.
 *
 * So every sale carries a `temp_id` minted on the device when the cashier
 * finalised, and this endpoint answers per sale rather than per batch:
 *
 *   accepted  — recorded now; here is the server's id and invoice number
 *   duplicate — already recorded, here is the id of the sale that exists
 *   rejected  — refused on its merits; here is why, and a person must look
 *
 * `duplicate` is a SUCCESS. The till drops the sale from its queue on either of
 * the first two, because both mean "the server has this". Returning an error for a
 * replay is how you build a queue that can never be emptied.
 *
 * TWO LAYERS, NOT ONE
 *
 * The lookup below is the layer that produces a useful answer. The unique index on
 * `(business_id, offline_temp_id)` is the layer that makes the answer true when two
 * requests race: both can pass the lookup before either commits, and only the
 * database can settle which one wins. The loser's insert throws, this catches it,
 * re-reads, and reports `duplicate` — the same answer it would have given a second
 * later. See the migration that adds the index for why it is composite and why it
 * was safe to add to a live table.
 *
 * BATCH, BUT NOT ATOMIC
 *
 * One request carries many sales and each is committed on its own — `SellService`
 * wraps each in its own transaction. Making the batch atomic would mean one
 * product deleted while the till was offline could hold up an entire shift's
 * takings, and the till would have no way to make progress. Independent verdicts
 * let the good sales land and leave exactly the broken ones to be looked at.
 *
 * WHY THIS AUTHENTICATES WITH THE SESSION AND LIVES IN web.php
 *
 * The sale is being made by a signed-in cashier on a browser that already holds
 * the session cookie it took the sale with — there is no second credential to
 * issue and no reason to invent a device token. It also means the sale is
 * attributed to whoever is signed in when it syncs, which is documented on
 * `attribute()` below.
 */
class OfflineSyncController extends Controller
{
    /**
     * Sales accepted per request.
     *
     * Not a policy about how many a till may queue (that is
     * `pwa.max_queued_documents`, enforced on the device) — a bound on how much
     * work one HTTP request may do. Each sale writes a document, its lines, the
     * stock cache, the FIFO map and its payments; five hundred of those in one
     * request would run past any sensible execution limit and fail *after*
     * committing some of them, which is the shape of an unanswerable support
     * question. The client sends its queue in chunks of this size.
     */
    private const MAX_PER_REQUEST = 25;

    public function __construct(
        private SellService $sells,
        private FormattingService $format,
    ) {}

    /**
     * Replay a batch of queued sales.
     */
    public function replay(Request $request): JsonResponse
    {
        $this->permit('sell.create', 'direct_sell.access');

        $request->validate([
            'device_id' => 'nullable|string|max:64',
            'sales' => 'required|array|min:1|max:'.self::MAX_PER_REQUEST,
        ]);

        $results = [];

        foreach ($request->input('sales', []) as $index => $sale) {
            $results[] = $this->replayOne(
                is_array($sale) ? $sale : [],
                (string) $request->input('device_id', ''),
                $index
            );
        }

        return response()->json([
            'synced_at' => now()->toIso8601String(),
            'results' => $results,
        ]);
    }

    /**
     * One sale, one verdict.
     *
     * Everything that can go wrong with a single sale is caught here and turned
     * into a verdict, because the alternative — letting it bubble up — fails the
     * whole batch and loses the verdicts of the sales that already succeeded. The
     * till would then resend those, and only the unique index would stop them
     * being recorded twice.
     *
     * @param  array<string, mixed>  $sale
     * @return array<string, mixed>
     */
    private function replayOne(array $sale, string $deviceId, int $index): array
    {
        $tempId = (string) ($sale['temp_id'] ?? '');

        if ($tempId === '') {
            /*
             * Without a temp id there is no way to tell a retry from a new sale,
             * so recording it would be recording something that can be duplicated
             * on the next attempt. Refused rather than accepted-and-hoped.
             */
            return $this->rejected('#'.$index, __('lang_v1.offline_sale_missing_id'));
        }

        $existing = Transaction::query()
            ->where('offline_temp_id', $tempId)
            ->first();

        if ($existing) {
            return $this->duplicate($tempId, $existing);
        }

        $validator = Validator::make($sale, $this->rules());

        if ($validator->fails()) {
            return $this->rejected($tempId, $validator->errors()->first());
        }

        $validated = $validator->validated();

        if (! $this->permitsLocation((int) $validated['location_id'])) {
            return $this->rejected($tempId, __('lang_v1.unauthorized'));
        }

        $lines = collect($validated['lines'])
            ->filter(fn ($line) => $this->format->numUf($line['quantity'] ?? 0) > 0)
            ->values()
            ->all();

        if (empty($lines)) {
            return $this->rejected($tempId, __('lang_v1.nothing_to_sell'));
        }

        $soldAt = $this->soldAt($sale['created_at'] ?? null);

        try {
            $transaction = $this->sells->create(
                collect($validated)->except(['lines', 'payments', 'temp_id', 'invoice_no'])->all() + [
                    'status' => TransactionTypes::STATUS_FINAL,

                    /*
                     * Dated when the money changed hands, not when the network came
                     * back. A till that was offline through Tuesday evening and
                     * syncs on Wednesday morning must not report Tuesday's takings
                     * as Wednesday's: the drawer was counted on Tuesday night, and
                     * the day would never reconcile again.
                     */
                    'transaction_date' => $soldAt,

                    'created_by' => $this->attribute(),

                    'offline_temp_id' => $tempId,
                    // The number the terminal printed on the customer's receipt.
                    // Kept beside the real invoice number rather than used as it:
                    // the server's sequence is the shop's book of record, and a
                    // device-minted number would put a gap or a collision in it.
                    'offline_invoice_no' => $sale['invoice_no'] ?? null,
                    'offline_device_id' => $this->deviceFor($sale, $deviceId),
                    'offline_created_at' => $soldAt,
                ],
                $lines,
                collect($validated['payments'] ?? [])
                    ->filter(fn ($payment) => $this->format->numUf($payment['amount'] ?? 0) > 0)
                    ->values()
                    ->all()
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            /*
             * Lost the race. Another request carrying this same temp id committed
             * between the lookup above and this insert. Re-read and answer exactly
             * as the winner's request answered — the till asked "do you have this
             * sale", and the truthful answer is now yes.
             */
            $winner = Transaction::query()->where('offline_temp_id', $tempId)->first();

            if ($winner) {
                return $this->duplicate($tempId, $winner);
            }

            // A different unique constraint, then — nothing to do with the replay.
            return $this->rejected($tempId, $this->failed($e)['msg']);
        } catch (\Throwable $e) {
            // `failed()` logs the cause with a stack trace and returns a message
            // safe to show a cashier. The till keeps this sale and shows the
            // reason; it does not retry it blindly.
            return $this->rejected($tempId, $this->failed($e)['msg']);
        }

        return [
            'temp_id' => $tempId,
            'status' => 'accepted',
            'id' => $transaction->id,
            'invoice_no' => $transaction->invoice_no,
        ];
    }

    /**
     * The same contract `SellPosController::store()` enforces.
     *
     * Deliberately the same rules and deliberately not shared as a FormRequest:
     * that class would have to authorise itself, and the two callers authorise
     * differently — one aborts on a bad sale, this one records a verdict and
     * carries on with the rest of the batch. The rules are the part worth keeping
     * identical, and a divergence shows up as a test failure in both suites.
     *
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'location_id' => 'required|integer|exists:business_locations,id',
            'contact_id' => 'required|integer|exists:contacts,id',
            'tax_id' => 'nullable|integer|exists:tax_rates,id',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_amount' => 'nullable|numeric|min:0',
            'selling_price_group_id' => 'nullable|integer|exists:selling_price_groups,id',
            'additional_notes' => 'nullable|string|max:2000',

            'lines' => 'required|array|min:1',
            'lines.*.variation_id' => 'required|integer|exists:variations,id',
            'lines.*.quantity' => 'required|numeric|gt:0',
            'lines.*.unit_price' => 'required|numeric|min:0',

            'payments' => 'nullable|array',
            'payments.*.amount' => 'nullable|numeric|min:0',
            'payments.*.method' => 'nullable|string|max:50',
            'payments.*.account_id' => 'nullable|integer|exists:accounts,id',
        ];
    }

    /**
     * When the sale actually happened.
     *
     * The device's clock is the only witness, so it is believed — with one
     * asymmetry. A timestamp in the past is the entire point of the feature and is
     * accepted however old it is; a shop's uplink can be down for a week. A
     * timestamp in the *future* is a till whose clock is wrong, and honouring it
     * would file a sale into a period that has not happened yet — past the end of
     * a month that may already have been reported on. Those are clamped to now,
     * which is at worst a few hours late and always inside a period still open.
     *
     * An unparseable or absent value falls back to now for the same reason: a sale
     * with no date is worse than a sale dated a little late.
     */
    private function soldAt(mixed $claimed): Carbon
    {
        if (empty($claimed) || ! is_string($claimed)) {
            return now();
        }

        try {
            // ISO-8601 with an offset, produced by `Date.toISOString()` on the
            // device — not the business's display format, so `ufDate()` is the
            // wrong parser here and Carbon is the right one.
            $parsed = Carbon::parse($claimed);
        } catch (\Throwable) {
            return now();
        }

        return $parsed->isFuture() ? now() : $parsed;
    }

    /**
     * Who the sale is recorded against.
     *
     * The signed-in user who synced it, never a value from the payload. A
     * client-supplied `created_by` would let anyone with a session file a sale
     * under someone else's name, which matters here more than usual: these are the
     * sales nobody watched being entered. In practice it is the same person — the
     * queue lives in one browser profile on one till — and where it is not, the
     * `offline_device_id` beside it says which machine took the sale.
     */
    private function attribute(): ?int
    {
        return auth()->id();
    }

    /**
     * Which device took the sale.
     *
     * Per-sale where the device recorded one, falling back to the batch's own id.
     * They differ in exactly one case worth keeping: a queue restored onto a
     * replacement till still carries the id of the machine that made each sale.
     *
     * @param  array<string, mixed>  $sale
     */
    private function deviceFor(array $sale, string $batchDevice): ?string
    {
        $device = (string) ($sale['device_id'] ?? '') ?: $batchDevice;

        return $device === '' ? null : mb_substr($device, 0, 64);
    }

    /**
     * True when the syncing user may sell from that location.
     *
     * Checked per sale rather than once per batch: the queue on a till could have
     * been taken under one user's session and synced under another's, and the sale
     * belongs to the branch it was rung up in, not to whoever happens to be
     * standing at the keyboard now.
     */
    private function permitsLocation(int $locationId): bool
    {
        $permitted = BusinessLocation::permittedLocations();

        return $permitted === 'all' || in_array($locationId, $permitted, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function duplicate(string $tempId, Transaction $existing): array
    {
        return [
            'temp_id' => $tempId,
            'status' => 'duplicate',
            'id' => $existing->id,
            'invoice_no' => $existing->invoice_no,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rejected(string $tempId, string $message): array
    {
        return [
            'temp_id' => $tempId,
            'status' => 'rejected',
            'message' => $message,
        ];
    }
}
