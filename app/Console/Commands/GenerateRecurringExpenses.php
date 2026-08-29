<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\ExpenseService;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The cron the recurring-expense engine has been missing.
 *
 * {@see ExpenseService::generateDueRecurring()} was written, tested by hand and
 * exposed in the UI — `expense/_form.blade.php` offers the interval, the index
 * badges the parents, the show screen lists the children — and then had no
 * caller anywhere in the codebase. Every user who ticked "recurring" was
 * promised something the system never did. This command is the twelve lines that
 * make the promise true, which is why it survived the item-12 cull (NOTES §19.2)
 * while genuinely-unbuilt features did not.
 *
 * The per-tenant binding is not optional. `generateDueRecurring()` queries
 * `Transaction` through {@see \App\Scopes\BusinessScope}, which in the console
 * with no tenant bound matches *every* business at once — and the reference
 * number for each generated child comes from
 * {@see \App\Services\ReferenceService::nextCount()}, which resolves its business
 * id from `Tenancy::id()`. Run unbound, this would sweep all tenants together
 * and then write their counters against a null business.
 */
class GenerateRecurringExpenses extends Command
{
    protected $signature = 'souqly:recurring-expenses
                            {--business= : only this business id}';

    protected $description = 'Create the recurring expenses that have come due';

    public function handle(ExpenseService $expenses): int
    {
        $businesses = $this->businesses();

        if ($businesses->isEmpty()) {
            $this->components->warn('No active business to process.');

            return self::SUCCESS;
        }

        $created = 0;
        $failed = 0;

        foreach ($businesses as $business) {
            try {
                $made = Tenancy::for($business->id, fn () => $expenses->generateDueRecurring());

                if ($made > 0) {
                    $this->line("  {$business->name}: {$made} expense(s) generated.");
                }

                $created += $made;
            } catch (\Throwable $e) {
                // Same reasoning as the stock alerts: one tenant's bad row must
                // not cost every other tenant their month's expenses.
                $failed++;
                report($e);
                $this->components->error("Business {$business->id}: {$e->getMessage()}");
            }
        }

        $this->components->info("{$created} recurring expense(s) generated.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
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
