<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Notifications\LowStockAlert;
use App\Services\StockService;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Nightly low-stock alerts.
 *
 * Iterates businesses explicitly and binds each one before querying. That is not
 * defensive habit — it is the contract {@see \App\Scopes\BusinessScope} states in
 * its own docblock: in the console an *unbound* tenant means "all businesses",
 * and the caller is responsible for scoping. A command that skipped the binding
 * would hand every shop every other shop's stock levels.
 */
class SendLowStockAlerts extends Command
{
    protected $signature = 'souqly:stock-alerts
                            {--business= : only this business id}
                            {--dry-run : report what would be sent, notify nobody}
                            {--force : notify again even if today\'s alert already went out}';

    protected $description = 'Notify staff about products at or below their alert quantity';

    /**
     * Who is entitled to see the stock report is who is entitled to be told the
     * stock is low; inventing a second permission for the same fact would let
     * the two drift apart.
     */
    private const PERMISSION = 'stock_report.view';

    public function handle(StockService $stock): int
    {
        $businesses = $this->businesses();

        if ($businesses->isEmpty()) {
            $this->components->warn('No active business to check.');

            return self::SUCCESS;
        }

        $alerting = 0;
        $notified = 0;
        $failed = 0;

        foreach ($businesses as $business) {
            try {
                /*
                 * One tenant's broken data must not stop the others being
                 * alerted. A scheduled command that aborts halfway leaves the
                 * remaining shops silently uncovered, and "no notification" is
                 * indistinguishable from "nothing is low".
                 */
                [$rows, $sent] = Tenancy::for(
                    $business->id,
                    fn () => $this->sendAlertsFor($business, $stock)
                );

                $alerting += $rows;
                $notified += $sent;
            } catch (\Throwable $e) {
                $failed++;
                report($e);
                $this->components->error("Business {$business->id}: {$e->getMessage()}");
            }
        }

        $this->components->info(sprintf(
            '%d product/location rows below alert level; %d notification(s) %s.',
            $alerting,
            $notified,
            $this->option('dry-run') ? 'would be sent' : 'sent'
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Sends one business's alert. Named `sendAlertsFor` rather than the obvious
     * `alert`, because `Command` already has a public `alert()` output helper
     * (Concerns/InteractsWithIO.php) and a `private alert()` here narrows it —
     * which PHP rejects at class-load time with a fatal error, not a warning.
     * `php -l` cannot see it: the file parses fine, the inheritance is what
     * breaks. Do not rename this back.
     *
     * @return array{0: int, 1: int} rows found, notifications sent
     */
    private function sendAlertsFor(Business $business, StockService $stock): array
    {
        $rows = $stock->lowStock($business->id);

        if ($rows->isEmpty()) {
            $this->line("  {$business->name}: nothing below alert level.");

            return [0, 0];
        }

        $recipients = $this->recipients($business);

        if ($recipients->isEmpty()) {
            // Worth a warning rather than silence: the stock *is* low and nobody
            // is configured to hear about it, which is a setup problem the owner
            // can only fix if it is stated.
            $this->components->warn(
                "{$business->name}: {$rows->count()} row(s) below alert level, but nobody holds "
                .self::PERMISSION.' and the business has no owner to fall back on.'
            );

            return [$rows->count(), 0];
        }

        /*
         * The once-a-day filter is applied here rather than inside recipients()
         * so the two empty cases stay tellable apart. Collapsing them would print
         * "nobody holds the permission" at every hand-run of the command,
         * sending the operator after a misconfiguration that is not there.
         */
        $fresh = $recipients->reject(fn (User $user) => $this->alreadyToldToday($user))->values();

        if ($fresh->isEmpty()) {
            $this->line(
                "  {$business->name}: {$rows->count()} row(s) below alert level; "
                ."all {$recipients->count()} recipient(s) were already told today (--force to repeat)."
            );

            return [$rows->count(), 0];
        }

        $this->line("  {$business->name}: {$rows->count()} row(s) → {$fresh->count()} recipient(s).");

        if ($this->option('dry-run')) {
            return [$rows->count(), $fresh->count()];
        }

        Notification::send($fresh, new LowStockAlert($rows));

        return [$rows->count(), $fresh->count()];
    }

    /**
     * Active, login-capable staff who hold the stock-report permission, plus the
     * owner.
     *
     * The owner is included unconditionally so the alert always has somewhere to
     * land: a tenant whose roles were never configured would otherwise get a
     * perfectly working alert command that notifies nobody, forever.
     *
     * The tenant filter is a plain `where('business_id', ...)`, not `forBusiness()`.
     * `User` deliberately does not use {@see \App\Traits\BelongsToBusiness} —
     * authentication has to find a user before a tenant exists, so a global scope
     * would break login (its own docblock says so at User.php:74). That means the
     * scope helpers simply do not exist on this model, and there is no global scope
     * here to bypass for the owner lookup either.
     *
     * Permission is resolved with `whereHas` over both the direct and the
     * role-granted side — the same shape as {@see User::scopeOnlyPermittedLocations()}
     * — rather than with spatie's `permission()` scope, which throws when the
     * permission row does not exist and excludes an administrator whose access
     * comes from `Gate::before()` instead of a stored grant.
     *
     * @return Collection<int, User>
     */
    private function recipients(Business $business): Collection
    {
        $recipients = User::query()
            ->where('business_id', $business->id)
            ->user()
            ->where('status', 'active')
            ->where('allow_login', 1)
            ->where(fn ($q) => $q
                ->whereHas('permissions', fn ($p) => $p->where('name', self::PERMISSION))
                ->orWhereHas('roles.permissions', fn ($p) => $p->where('name', self::PERMISSION)))
            ->get()
            ->keyBy('id');

        /*
         * By owner_id and nothing else. The business row is what names its owner,
         * so that id is already authoritative — and filtering it by `business_id`
         * as well would drop a legitimate owner whose column was never backfilled,
         * which is the one account that must never be missed.
         */
        $owner = User::query()
            ->where('allow_login', 1)
            ->find($business->owner_id);

        if ($owner) {
            $recipients->put($owner->id, $owner);
        }

        /*
         * Deliberately NOT filtered by alreadyToldToday() here. That filter
         * lives in sendAlertsFor(), so "nobody is configured to hear about this"
         * and "everybody already heard about it today" stay tellable apart —
         * applied here, a second hand-run of the command would print a
         * permissions-misconfiguration warning for a setup that is fine.
         */
        return $recipients->values();
    }

    /**
     * Has this user already been told today?
     *
     * The scheduler runs once a day, but a person running the command by hand to
     * see what it does should not push a second identical row into everybody's
     * bell. `--force` is the way past this.
     */
    private function alreadyToldToday(User $user): bool
    {
        if ($this->option('force')) {
            return false;
        }

        return $user->notifications()
            ->where('type', LowStockAlert::class)
            ->whereDate('created_at', today())
            ->exists();
    }

    /**
     * @return Collection<int, Business>
     */
    private function businesses(): Collection
    {
        return Business::query()
            ->where('is_active', 1)
            ->when($this->option('business'), fn ($q) => $q->whereKey((int) $this->option('business')))
            ->orderBy('id')
            ->get();
    }
}
