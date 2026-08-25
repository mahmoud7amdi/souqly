<?php

namespace App\Http\Controllers;

use App\Models\BusinessLocation;
use App\Models\CashRegister;
use App\Models\CashRegisterTransaction;
use App\Models\User;
use App\Services\CashRegisterService;
use App\Services\FormattingService;
use App\Support\TenantRules;
use Illuminate\Http\Request;

/**
 * The cashier's till session.
 *
 * Three screens and one rule. The screens: the history of sessions, one session
 * in detail, and the close form that counts the drawer. The rule: a user has at
 * most one open register, and it is theirs — `view_cash_register` lets a manager
 * read everyone's sessions, but closing one is done by the person who opened it.
 *
 * Opening is deliberately cheap (a location and an optional float) and closing is
 * deliberately not (a denomination count, card slips, cheques, a note). The
 * asymmetry is the point: starting a shift should never be a reason to delay
 * serving a customer, while ending one is the moment the money is verified.
 */
class CashRegisterController extends Controller
{
    public function __construct(
        private CashRegisterService $registers,
        private FormattingService $format,
    ) {}

    /* ================================================================
     | History
     ================================================================ */

    public function index(Request $request)
    {
        $this->permit('view_cash_register');

        $registers = CashRegister::with(['user', 'location'])
            ->when($this->ownSessionsOnly(), fn ($q) => $q->where('user_id', auth()->id()))
            ->when($request->filled('user_id'),
                fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('location_id'),
                fn ($q) => $q->where('location_id', $request->integer('location_id')))
            ->when($request->filled('status'),
                fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('start_date'),
                fn ($q) => $q->where('created_at', '>=',
                    $this->format->ufDate($request->input('start_date')).' 00:00:00'))
            ->when($request->filled('end_date'),
                fn ($q) => $q->where('created_at', '<=',
                    $this->format->ufDate($request->input('end_date')).' 23:59:59'))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('cash_register.index', [
            'registers' => $registers,
            // Loaded explicitly, not through the relation: the banner at the top
            // names the location of the open session, and `currentFor()` returns a
            // bare model — lazy loading is barred, and making the service eager
            // load it would put an extra query in the POS sale path for the sake
            // of one line on one screen.
            'current' => $this->registers->currentFor()?->load('location'),
            // One grouped query for the whole page rather than a summary() per
            // row: the list shows a collected figure per session and there can
            // be 25 of them.
            'collected' => $this->collectedFor(collect($registers->items())->pluck('id')->all()),
            'users' => ['' => __('lang_v1.all')] + User::forDropdown(),
            'locations' => BusinessLocation::forDropdown(true),
            'statuses' => [
                '' => __('lang_v1.all'),
                'open' => __('lang_v1.open'),
                'close' => __('lang_v1.closed'),
            ],
        ]);
    }

    public function show(int $id)
    {
        $this->permit('view_cash_register');

        $register = $this->findRegister($id, ['user', 'location']);

        return view('cash_register.show', [
            'register' => $register,
            'summary' => $this->registers->summary($register),
            'entries' => CashRegisterTransaction::where('cash_register_id', $register->id)
                // `ref_no` as well as `invoice_no`: a register row can point at a
                // sell return, which has no invoice number, and the document link
                // falls back to the reference before it falls back to the id.
                ->with(['transaction' => fn ($q) => $q->select('id', 'invoice_no', 'ref_no', 'type', 'final_total')])
                ->latest('id')
                ->paginate(50),
            /*
             * Three conditions, and all three matter: an already-counted drawer
             * cannot be counted again, `close_cash_register` is the permission the
             * close screens actually demand, and ownership narrows it further. The
             * flag is computed here so the button is offered only where it leads
             * somewhere — a Close button that answers 403 is worse than none.
             */
            'canClose' => $register->isOpen()
                && $this->allows('close_cash_register')
                && $this->canClose($register),
        ]);
    }

    /* ================================================================
     | Opening
     ================================================================ */

    public function create()
    {
        $this->permit('view_cash_register');

        // Already open: there is nothing to decide, so show the register rather
        // than a form that can only fail.
        if ($current = $this->registers->currentFor()) {
            return redirect()->route('cash-register.show', $current->id);
        }

        return view('cash_register.create', [
            'locations' => BusinessLocation::forDropdown(),
        ]);
    }

    public function store(Request $request)
    {
        $this->permit('view_cash_register');

        $validated = $request->validate([
            'location_id' => ['required', 'integer', TenantRules::location()],
            'opening_amount' => 'nullable|numeric|min:0',
        ]);

        try {
            $register = $this->registers->open($validated + ['user_id' => auth()->id()]);

            $output = $this->ok(__('lang_v1.register_opened'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        /*
         * A register is opened in order to start selling, so the natural next
         * screen is the POS — not the register's own detail page, which the
         * cashier has no reason to read at the start of a shift.
         */
        $next = $request->has('open_and_view')
            ? route('cash-register.show', $register->id)
            : route('pos.create');

        return redirect()->to($next)->with('status', $output);
    }

    /* ================================================================
     | Closing
     ================================================================ */

    public function closeForm(int $id)
    {
        $this->permit('close_cash_register');

        $register = $this->findRegister($id, ['user', 'location']);

        if (! $register->isOpen()) {
            return redirect()->route('cash-register.show', $register->id)
                ->with('status', $this->failed(null, __('lang_v1.register_closed')));
        }

        abort_unless($this->canClose($register), 403, __('lang_v1.unauthorized'));

        return view('cash_register.close', [
            'register' => $register,
            'summary' => $this->registers->summary($register),
            'denominations' => $this->suggestedDenominations(),
        ]);
    }

    public function close(Request $request, int $id)
    {
        $this->permit('close_cash_register');

        $register = $this->findRegister($id);

        abort_unless($this->canClose($register), 403, __('lang_v1.unauthorized'));

        $validated = $request->validate([
            'closing_amount' => 'nullable|numeric|min:0',
            'total_card_slips' => 'nullable|integer|min:0',
            'total_cheques' => 'nullable|integer|min:0',
            'closing_note' => 'nullable|string|max:1000',
            'denominations' => 'nullable|array',
            'denominations.*' => 'nullable|integer|min:0',
        ]);

        try {
            $this->registers->close($register, $validated);

            $output = $this->ok(__('lang_v1.register_closed_successfully'));
        } catch (\Throwable $e) {
            return back()->withInput()->with('status', $this->failed($e));
        }

        return $request->has('close_and_pos')
            ? redirect()->route('cash-register.create')->with('status', $output)
            : redirect()->route('cash-register.show', $register->id)->with('status', $output);
    }

    /* ================================================================
     | Internals
     ================================================================ */

    protected function findRegister(int $id, array $with = []): CashRegister
    {
        $register = CashRegister::with($with)
            ->where('business_id', \App\Support\Tenancy::id())
            ->findOrFail($id);

        if ($this->ownSessionsOnly() && (int) $register->user_id !== auth()->id()) {
            abort(403, __('lang_v1.unauthorized'));
        }

        return $register;
    }

    /**
     * A cashier reads their own drawer; a manager reads the floor.
     *
     * `view_all_cash_register` is not in the catalogue, so the wider view is the
     * one that comes with managing users. Anything narrower would leave a shop
     * owner unable to check a till they are responsible for.
     */
    protected function ownSessionsOnly(): bool
    {
        return ! $this->allows('user.view', 'close_cash_register');
    }

    /**
     * Who may count this drawer.
     *
     * The cashier who opened it, or someone who can close registers generally —
     * a shift that ends with the cashier already gone still has to be closed by
     * somebody, and refusing that would leave the register open forever.
     */
    protected function canClose(CashRegister $register): bool
    {
        return (int) $register->user_id === auth()->id()
            || $this->allows('close_cash_register');
    }

    /**
     * Cash collected per session, for the history list.
     *
     * @param  array<int, int>  $registerIds
     * @return array<int, float>
     */
    protected function collectedFor(array $registerIds): array
    {
        if (empty($registerIds)) {
            return [];
        }

        return CashRegisterTransaction::whereIn('cash_register_id', $registerIds)
            ->where('transaction_type', '!=', 'initial')
            ->selectRaw("cash_register_id, SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END) AS total")
            ->groupBy('cash_register_id')
            ->pluck('total', 'cash_register_id')
            ->map(fn ($total) => round((float) $total, 4))
            ->all();
    }

    /**
     * The note and coin values the close form offers to count.
     *
     * Taken from the tenant's own POS settings when they have set them, because
     * the denominations of a currency are not something the app can guess: EGP has
     * a 200 note, SAR does not, and a form listing the wrong ones makes counting
     * slower than a blank box would. With nothing configured the form falls back
     * to a free-entry grid rather than inventing values.
     *
     * @return array<int, string>
     */
    protected function suggestedDenominations(): array
    {
        $settings = (array) session('business.pos_settings', []);

        $configured = $settings['cash_denominations'] ?? '';

        $values = is_array($configured) ? $configured : explode(',', (string) $configured);

        return collect($values)
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => is_numeric($value) && (float) $value > 0)
            ->map(fn ($value) => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.'))
            ->unique()
            ->sortByDesc(fn ($value) => (float) $value)
            ->values()
            ->all();
    }
}
