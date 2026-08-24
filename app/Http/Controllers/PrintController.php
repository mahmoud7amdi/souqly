<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\PrintService;
use App\Support\TransactionTypes;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Printing a sale document: on screen, as a PDF, on a 72 mm roll, or through the
 * shop's thermal printer.
 *
 * Before this, "print" meant `window.print()` on `sell/show` — the application
 * screen with its chrome hidden by `.no-print`. That gives a customer a page with
 * the app's spacing, the app's table, no letterhead, no tax number and no
 * layout: the whole `invoice_layouts` table, which the settings screens let a
 * tenant fill in with ninety fields of labels and toggles, was never read by
 * anything. These four actions are what reads it.
 *
 * **Scope: sale documents only** — `sell`, `sales_order`, `sell_return`. That is
 * not an arbitrary line. `invoice_layouts` is a sale artefact: it carries
 * `sale_invoice_layout_id`, credit-note labels, a customer block and a
 * `table_product_label`. Purchase paperwork already has its own template and its
 * own route ({@see PurchaseOrderController::downloadPdf()}), and a supplier's
 * purchase order is not the shop's invoice layout.
 *
 * **One Blade per design, two output paths.** `print/invoice` renders standalone
 * — its own `<html>`, its own styles, no app layout — because it has to survive
 * DomPDF, which never sees the compiled stylesheet. Both the browser view and the
 * PDF load the same view with the same data, so the PDF cannot drift from what
 * the clerk previewed. The single difference is `$forPdf`, which switches image
 * references from URLs to filesystem paths; DomPDF has no HTTP client, so an
 * `asset()` URL prints as a broken-image glyph.
 *
 * **Gates are the document's own.** A print view is a read of the document, so it
 * is gated exactly as its screen is — including the own-records-only variants.
 * `$this->document()` re-applies `permittedLocations()` and the `created_by`
 * narrowing, so a clerk who cannot open a sale on the sell screen cannot print it
 * by guessing a URL either. That is the whole reason the gate map is duplicated
 * here as data rather than reached for through the sell controllers: those
 * methods are `protected`, and a controller that instantiated another controller
 * to borrow a gate would be worse than a table.
 */
class PrintController extends Controller
{
    /**
     * type => [view permissions, own-only permission].
     *
     * Read straight off the three controllers that own these documents, and the
     * asymmetry is theirs, not a simplification: sells answer to three names
     * because `direct_sell.view` exists for the POS-only roles, sales orders use
     * an explicit `view_all`/`view_own` pair, and returns are gated by an
     * `access_*` name because a return is an action before it is a record.
     *
     * @var array<string, array{0: array<int, string>, 1: ?string}>
     */
    protected const GATES = [
        TransactionTypes::SELL => [['sell.view', 'direct_sell.view', 'view_own_sell_only'], 'view_own_sell_only'],
        TransactionTypes::SALES_ORDER => [['so.view_all', 'so.view_own'], 'so.view_own'],
        TransactionTypes::SELL_RETURN => [['access_sell_return', 'access_own_sell_return'], 'access_own_sell_return'],
    ];

    public function __construct(private PrintService $print) {}

    /**
     * The full-page invoice, ready to print.
     *
     * `?auto=1` fires the browser's print dialog on load. It is opt-in because
     * the same URL is also how somebody *looks* at an invoice — opening a print
     * dialog on a page a clerk only wanted to read is the kind of small hostility
     * that teaches people to avoid a feature.
     */
    public function invoice(Request $request, int $id)
    {
        $document = $this->document($id);

        return view('print.invoice', $this->print->present($document) + $this->chrome($document) + [
            'autoPrint' => $request->boolean('auto'),
            'forPdf' => false,
        ]);
    }

    /**
     * The same invoice as a PDF download.
     */
    public function pdf(int $id)
    {
        $document = $this->document($id);

        $pdf = Pdf::loadView('print.invoice', $this->print->present($document, true) + [
            'autoPrint' => false,
            'forPdf' => true,
        ])->setPaper('a4');

        return $pdf->download($this->fileName($document));
    }

    /**
     * The 72 mm receipt, for the roll printer behind the counter.
     *
     * A separate action rather than a query flag on `invoice()`: the receipt is a
     * different document, not a narrow invoice. It drops the address block, the
     * per-line tax column and the payment table, because on a 42-character line
     * those do not shrink — they wrap into noise.
     */
    public function receipt(Request $request, int $id)
    {
        $document = $this->document($id);

        return view('print.receipt', $this->print->present($document) + $this->chrome($document) + [
            'autoPrint' => $request->boolean('auto'),
            'forPdf' => false,
        ]);
    }

    /**
     * Hand the receipt to the shop's thermal printer.
     *
     * This is the producer the print queue never had. {@see
     * \App\Http\Controllers\Api\PrintQueueController} describes the flow as "the
     * browser POSTs a job → the agent polls with its location token → prints →
     * reports back", and implements every step of that except the first: nothing
     * in the application had ever written a `print_jobs` row. The agent side was
     * complete — claiming, completing, requeueing a job whose agent died
     * mid-print — and idle.
     *
     * `access_printers` is checked on top of the document's own gate, matching
     * `routes/channels.php`, where the same permission guards the
     * `print-queue.{locationId}` broadcast channel the agent listens on. Reading
     * an invoice and driving the hardware are two different privileges.
     */
    public function enqueue(int $id)
    {
        $document = $this->document($id);

        $this->permit('access_printers');

        try {
            $this->print->enqueue($document);
        } catch (\Throwable $e) {
            return back()->with('status', $this->failed($e));
        }

        return back()->with('status', $this->ok(__('lang_v1.sent_to_printer')));
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * The screen furniture around the document — everything the toolbar needs and
     * the page itself does not.
     *
     * Kept out of {@see PrintService::present()} on purpose: `present()` describes
     * the document, and it is also what the ESC/POS payload is built from, where a
     * "back" link is meaningless. This is chrome, and chrome belongs to the
     * request.
     *
     * @return array<string, mixed>
     */
    protected function chrome(Transaction $document): array
    {
        $location = $document->location;

        return [
            'backUrl' => $this->backUrl($document),
            // Both halves have to be true. `access_printers` is the privilege;
            // `receipt_printer_type` plus a configured printer is whether there is
            // any hardware for the job to reach. A branch left on the default
            // 'browser' setting has an empty queue and an agent nobody installed,
            // so the button would only ever produce a refusal.
            'canEnqueue' => $this->allows('access_printers')
                && ($location->receipt_printer_type ?? null) === 'printer'
                && ! empty($location->printer),
        ];
    }

    /**
     * Where the "back" link goes: the document's own screen, per type.
     */
    protected function backUrl(Transaction $document): string
    {
        return match ($document->type) {
            TransactionTypes::SALES_ORDER => route('sales-order.show', $document->id),
            TransactionTypes::SELL_RETURN => route('sell-return.show', $document->id),
            default => route('sells.show', $document->id),
        };
    }

    /**
     * Load a printable document, or fail the way its own screen would.
     *
     * The order is deliberate. The row is fetched by id first, *then* gated,
     * because the permission depends on the document's type — there is no way to
     * know whether to ask for `sell.view` or `access_sell_return` before knowing
     * what was asked for. The fetch is scoped to `permittedLocations()` from the
     * outset, so the pre-gate read can never reach another tenant's branch, and
     * an unprintable type 404s rather than falling through to a default gate.
     */
    protected function document(int $id): Transaction
    {
        $document = Transaction::with([
            'contact', 'location.printer', 'sell_lines.variations.product.brand',
            'sell_lines.variations.product.category', 'sell_lines.variations.product.unit',
            'sell_lines.sub_unit', 'sell_lines.lot_details', 'sell_lines.line_tax',
            'payment_lines', 'tax', 'sales_person', 'sale_commission_agent',
        ])
            ->permittedLocations()
            ->findOrFail($id);

        if (! isset(self::GATES[$document->type])) {
            // A stock transfer or an expense has no invoice layout and no
            // printable shape; there is no default gate to fall through to.
            abort(404);
        }

        [$view, $ownOnly] = self::GATES[$document->type];

        $this->permit(...$view);

        // The own-records-only roles: holding *only* the `own` permission means
        // somebody else's sale is invisible, and invisible has to mean 404 rather
        // than 403 — a 403 confirms the invoice exists, which is the fact the
        // permission is there to withhold.
        $restricted = ! $this->allows(...array_filter(
            $view,
            fn (string $permission): bool => $permission !== $ownOnly
        ));

        if ($restricted && (int) $document->created_by !== (int) auth()->id()) {
            abort(404);
        }

        return $document;
    }

    /**
     * A filename a customer can find again in their downloads folder.
     */
    protected function fileName(Transaction $document): string
    {
        $prefix = match ($document->type) {
            TransactionTypes::SELL_RETURN => 'CN',
            TransactionTypes::SALES_ORDER => 'SO',
            default => 'INV',
        };

        // Anything a filesystem might object to, out. `invoice_no` is generated
        // from a tenant-editable prefix, so it can contain a slash.
        $number = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $document->invoice_no);

        return $prefix.'-'.trim((string) $number, '-').'.pdf';
    }
}
