<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\ReferenceCount;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VariationLocationDetails;
use App\Notifications\LowStockAlert;
use App\Services\BackupService;
use App\Services\ExpenseService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The three scheduled commands (NOTES §19.2, §20).
 *
 * These are the only pieces of the system with no user in front of them, which
 * changes what the tests have to prove. A screen that breaks gets reported by
 * whoever was looking at it; a nightly command that breaks reports nothing,
 * and "no notification arrived" is indistinguishable from "nothing was wrong".
 * So the assertions here are mostly about the failure modes that look like
 * success:
 *
 *   - a tenant-blind query, because {@see \App\Scopes\BusinessScope} deliberately
 *     matches *every* business when nothing is bound and the process is the
 *     console. Unbound, these commands would work perfectly and leak.
 *   - a reference counter written against a null business, which is what
 *     {@see \App\Services\ReferenceService::nextCount()} does when it resolves
 *     its tenant from an unbound `Tenancy`.
 *   - a database password on the `mysqldump` command line, readable by any other
 *     account on the box for as long as the dump runs.
 *
 * None of the three is visible in a passing run. All three are asserted below.
 */
class ScheduledCommandsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Not a real connection: these tests must assert how the dump is *invoked*
     * without a live server or a `mysqldump` on the box. The password carries a
     * quote and a backslash on purpose — both are legal in my.cnf and both need
     * escaping.
     */
    private const FAKE_CONNECTION = [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'souqly_fake',
        'username' => 'souqly_user',
        'password' => 'p@ss"word\\with-specials',
        'charset' => 'utf8mb4',
    ];

    private ?string $scratch = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTenant();
    }

    protected function tearDown(): void
    {
        if ($this->scratch && File::isDirectory($this->scratch)) {
            File::deleteDirectory($this->scratch);
        }

        parent::tearDown();
    }

    /* ================================================================
     | Low-stock alerts
     ================================================================ */

    #[Test]
    public function the_owner_and_staff_holding_the_stock_permission_are_notified(): void
    {
        $this->stockRow(available: 2, alert: 10);

        $watcher = $this->staff('watcher', withPermission: true);
        $bystander = $this->staff('bystander', withPermission: false);

        $this->artisan('souqly:stock-alerts')->assertSuccessful();

        $notification = $this->user->fresh()->notifications()->first();

        $this->assertNotNull($notification, 'The owner was not notified.');
        $this->assertSame(LowStockAlert::class, $notification->type);
        $this->assertSame(1, $notification->data['count']);

        // The three keys the UI actually reads: the list renders `title` and
        // `body`, and NotificationController::show() redirects to `url`.
        $this->assertSame(__('lang_v1.stock_alerts'), $notification->data['title']);
        $this->assertNotEmpty($notification->data['body']);
        $this->assertSame(route('reports.stock'), $notification->data['url']);

        $this->assertSame(1, $watcher->fresh()->notifications()->count(),
            'A user holding stock_report.view was not notified.');
        $this->assertSame(0, $bystander->fresh()->notifications()->count(),
            'A user with no stock permission was notified anyway.');
    }

    #[Test]
    public function nothing_is_sent_when_no_product_is_below_its_alert_level(): void
    {
        // Comfortably above the threshold, and one with alerting switched off
        // entirely — `alert_quantity` of 0 means "do not alert", not "alert at 0".
        $this->stockRow(available: 80, alert: 10);
        $this->stockRow(available: 0, alert: 0);

        $this->artisan('souqly:stock-alerts')->assertSuccessful();

        $this->assertSame(0, $this->user->fresh()->notifications()->count());
    }

    #[Test]
    public function the_same_day_is_not_alerted_twice_unless_forced(): void
    {
        $this->stockRow(available: 1, alert: 10);

        $this->artisan('souqly:stock-alerts')->assertSuccessful();
        $this->artisan('souqly:stock-alerts')->assertSuccessful();

        $this->assertSame(1, $this->user->fresh()->notifications()->count(),
            'Running twice in one day pushed a duplicate into the bell.');

        $this->artisan('souqly:stock-alerts --force')->assertSuccessful();

        $this->assertSame(2, $this->user->fresh()->notifications()->count(),
            '--force did not override the once-a-day guard.');
    }

    #[Test]
    public function a_dry_run_notifies_nobody(): void
    {
        $this->stockRow(available: 1, alert: 10);

        $this->artisan('souqly:stock-alerts --dry-run')->assertSuccessful();

        $this->assertSame(0, $this->user->fresh()->notifications()->count());
    }

    /**
     * The one that matters.
     *
     * `variation_location_details` has no `business_id`, so the tenant is
     * reached through the location join — and the command runs in the console,
     * where an unbound tenant means "all businesses" by design. Get this wrong
     * and every owner is told every other shop's stock levels, in a command that
     * exits 0 and looks like it worked.
     */
    #[Test]
    public function one_tenants_low_stock_never_reaches_another_tenants_staff(): void
    {
        $firstOwner = $this->user;
        $this->stockRow(available: 2, alert: 10);

        // createTenant() rebinds Tenancy and swaps $this->business/user/location,
        // so everything for the second tenant is built after this line.
        $this->createTenant();
        $secondOwner = $this->user;
        $this->stockRow(available: 1, alert: 10);
        $this->stockRow(available: 3, alert: 10);

        $this->artisan('souqly:stock-alerts')->assertSuccessful();

        $this->assertSame(
            1,
            $firstOwner->fresh()->notifications()->first()?->data['count'],
            "The first tenant's alert counted rows that are not theirs."
        );

        $this->assertSame(
            2,
            $secondOwner->fresh()->notifications()->first()?->data['count'],
            "The second tenant's alert counted rows that are not theirs."
        );
    }

    /* ================================================================
     | Recurring expenses
     ================================================================ */

    #[Test]
    public function a_recurring_expense_that_has_come_due_is_generated(): void
    {
        $parent = $this->recurringExpense(monthsAgo: 2);

        $this->artisan('souqly:recurring-expenses')->assertSuccessful();

        $children = Transaction::where('recur_parent_id', $parent->id)->get();

        $this->assertCount(1, $children, 'The due recurring expense was not generated.');
        $this->assertSame(0, (int) $children->first()->is_recurring,
            'The generated child is itself marked recurring, which would compound every night.');
        $this->assertSame($this->business->id, (int) $children->first()->business_id);
    }

    #[Test]
    public function a_recurring_expense_that_is_not_yet_due_is_left_alone(): void
    {
        $parent = $this->recurringExpense(monthsAgo: 0);

        $this->artisan('souqly:recurring-expenses')->assertSuccessful();

        $this->assertSame(0, Transaction::where('recur_parent_id', $parent->id)->count(),
            'An expense that is not due yet was generated early.');
    }

    /**
     * The generated document must number against its own business.
     *
     * This is the concrete cost of skipping the per-tenant binding:
     * `generateDueRecurring()` reaches `ReferenceService::nextCount()`, which
     * resolves its business id from `Tenancy::id()`. In the console with nothing
     * bound that is `null`, and the expense counter is written against no
     * business at all — silently corrupting numbering for every tenant at once.
     */
    #[Test]
    public function the_generated_expense_numbers_against_its_own_business(): void
    {
        $this->recurringExpense(monthsAgo: 2);

        $this->artisan('souqly:recurring-expenses')->assertSuccessful();

        $this->assertSame(
            0,
            ReferenceCount::withoutBusinessScope()->whereNull('business_id')->count(),
            'A reference counter was written against no business.'
        );

        $this->assertSame(
            1,
            ReferenceCount::withoutBusinessScope()
                ->where('ref_type', 'expense')
                ->where('business_id', $this->business->id)
                ->count(),
            "The expense counter was not written against the generated expense's own business."
        );
    }

    /* ================================================================
     | Backup
     ================================================================ */

    #[Test]
    public function the_password_never_appears_in_the_argument_list(): void
    {
        $service = $this->backupService();
        $arguments = $service->dumpArguments('/tmp/whatever.cnf');

        foreach ($arguments as $argument) {
            $this->assertStringNotContainsString(
                self::FAKE_CONNECTION['password'],
                $argument,
                'The database password reached the mysqldump command line, where any '
                .'other account on the machine can read it out of the process list.'
            );
        }

        // mysqldump honours --defaults-extra-file in first position only, and
        // ignores it silently anywhere else — so position is behaviour, not style.
        $this->assertStringStartsWith('--defaults-extra-file=', $arguments[1]);
        $this->assertSame(self::FAKE_CONNECTION['database'], end($arguments));
    }

    #[Test]
    public function the_credential_file_is_written_with_the_password_and_escaped(): void
    {
        $service = $this->backupService();
        $path = $service->writeDefaultsFile();

        $contents = File::get($path);

        $this->assertStringContainsString('[client]', $contents);
        $this->assertStringContainsString('user="souqly_user"', $contents);
        // Backslash and quote both escaped, so my.cnf reads back the real password.
        $this->assertStringContainsString('password="p@ss\\"word\\\\with-specials"', $contents);

        File::delete($path);
    }

    #[Test]
    public function a_failed_dump_leaves_behind_neither_a_credential_file_nor_a_half_written_backup(): void
    {
        // A binary that cannot exist: the dump fails at the first hurdle, which
        // is precisely when the cleanup in the `finally` has to hold.
        $service = new BackupService(
            self::FAKE_CONNECTION,
            'souqly-definitely-not-a-real-mysqldump',
            $this->scratchDirectory()
        );

        try {
            $service->run(keep: 5);
            $this->fail('A dump with a nonexistent binary reported success.');
        } catch (\RuntimeException) {
            // expected
        }

        /*
         * scandir, not glob. The credential file is named `.my-<hex>.cnf`, and
         * glob() does not match a leading dot unless the pattern has one — so
         * `glob('*.cnf')` here would return an empty array whether the file was
         * cleaned up or not, and this assertion would pass by measuring nothing.
         */
        $leftovers = array_values(array_filter(
            scandir($this->scratchDirectory()) ?: [],
            fn (string $entry) => str_ends_with($entry, '.cnf')
        ));

        $this->assertSame([], $leftovers,
            'The file holding the database password outlived the failed dump.');
        $this->assertSame([], $service->backups(),
            'A truncated .sql file was left behind, looking like a backup.');
    }

    #[Test]
    public function pruning_keeps_the_newest_dumps_and_deletes_the_rest(): void
    {
        $service = $this->backupService();

        $older = [];

        foreach (['2026-08-20-020000', '2026-08-21-020000', '2026-08-22-020000', '2026-08-23-020000'] as $stamp) {
            $path = $this->scratchDirectory().DIRECTORY_SEPARATOR.'souqly_fake-'.$stamp.'.sql';
            File::put($path, '-- dump');
            $older[$stamp] = $path;
        }

        $pruned = $service->prune(2);

        $this->assertCount(2, $pruned);
        // Oldest two go; newest two stay. Filenames sort chronologically by
        // construction, which is what prune() relies on instead of mtime.
        $this->assertFileDoesNotExist($older['2026-08-20-020000']);
        $this->assertFileDoesNotExist($older['2026-08-21-020000']);
        $this->assertFileExists($older['2026-08-22-020000']);
        $this->assertFileExists($older['2026-08-23-020000']);
    }

    #[Test]
    public function pruning_with_a_nonsense_keep_count_deletes_nothing(): void
    {
        $service = $this->backupService();

        $path = $this->scratchDirectory().DIRECTORY_SEPARATOR.'souqly_fake-2026-08-20-020000.sql';
        File::put($path, '-- dump');

        // "Keep zero" must not mean "delete everything": a mistyped --keep is
        // not permission to destroy the backups.
        $this->assertSame([], $service->prune(0));
        $this->assertFileExists($path);
    }

    /* ================================================================
     | Scheduling
     ================================================================ */

    #[Test]
    public function all_three_commands_are_scheduled_daily(): void
    {
        // Running any artisan command is what loads routes/console.php, which is
        // where the schedule is declared; without it the list below is empty and
        // this test would pass by describing nothing.
        $this->artisan('schedule:list')->assertSuccessful();

        $scheduled = [];

        foreach (app(Schedule::class)->events() as $event) {
            foreach (['souqly:recurring-expenses', 'souqly:backup', 'souqly:stock-alerts'] as $command) {
                if (str_contains((string) $event->command, $command)) {
                    $scheduled[$command] = $event->expression;
                }
            }
        }

        $this->assertSame([
            'souqly:recurring-expenses' => '20 0 * * *',
            'souqly:backup' => '40 2 * * *',
            'souqly:stock-alerts' => '10 7 * * *',
        ], $scheduled);
    }

    /* ================================================================
     | Fixtures
     ================================================================ */

    /**
     * One (variation × location) stock row for a fresh product.
     *
     * Written straight into the cache table rather than purchased in: what is
     * under test is the alert query, and a real purchase would drag FIFO lots
     * and tax into a test about a `<=` comparison.
     */
    private function stockRow(float $available, float $alert): void
    {
        $product = $this->createProduct(['alert_quantity' => $alert]);
        $variation = $this->variationOf($product);

        VariationLocationDetails::create([
            'product_id' => $product->id,
            'product_variation_id' => $variation->product_variation_id,
            'variation_id' => $variation->id,
            'location_id' => $this->location->id,
            'qty_available' => $available,
        ]);
    }

    /**
     * A member of staff, optionally holding the stock-report permission through
     * a role — which is how a real user holds it.
     */
    private function staff(string $name, bool $withPermission): User
    {
        $user = User::create([
            'user_type' => 'user',
            'business_id' => $this->business->id,
            'first_name' => $name,
            'username' => $name.uniqid(),
            'password' => 'secret',
            'language' => 'ar',
            'status' => 'active',
            'allow_login' => 1,
        ]);

        if ($withPermission) {
            Permission::findOrCreate('stock_report.view', 'web');
            $role = Role::findOrCreate('Stock watcher '.$this->business->id, 'web');
            $role->givePermissionTo('stock_report.view');
            $user->assignRole($role);
        }

        return $user;
    }

    /**
     * A monthly recurring expense whose last occurrence was `$monthsAgo` months
     * back — so `monthsAgo: 2` is overdue and `monthsAgo: 0` is not.
     */
    private function recurringExpense(int $monthsAgo): Transaction
    {
        $category = ExpenseCategory::create([
            'name' => 'Rent '.uniqid(),
            'business_id' => $this->business->id,
        ]);

        return app(ExpenseService::class)->create([
            'location_id' => $this->location->id,
            'expense_category_id' => $category->id,
            'transaction_date' => now()->subMonths($monthsAgo)->toDateTimeString(),
            'total_before_tax' => 1500,
            'created_by' => $this->user->id,
            'is_recurring' => 1,
            'recur_interval' => 1,
            'recur_interval_type' => 'months',
        ]);
    }

    private function backupService(): BackupService
    {
        return new BackupService(self::FAKE_CONNECTION, 'mysqldump', $this->scratchDirectory());
    }

    /**
     * A throwaway directory, removed in tearDown. Never the configured backup
     * directory: these tests delete files by pattern, and pointing that at a
     * real installation's dumps would be an unpleasant way to learn the
     * difference.
     */
    private function scratchDirectory(): string
    {
        if (is_null($this->scratch)) {
            $this->scratch = storage_path('app/private/backup-test-'.uniqid());
            File::ensureDirectoryExists($this->scratch);
        }

        return $this->scratch;
    }
}
