<?php

namespace Tests\Feature;

use App\Models\BusinessLocation;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\User;
use App\Services\BusinessService;
use App\Support\Permissions;
use App\Support\Tenancy;
use App\Support\TenantRules;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The `location_id` seam: fifteen write paths that take a branch from the request
 * body, and the one rule that has to make that safe.
 *
 * `Rule::exists()` compiles to the **query builder**. A global scope is an
 * *Eloquent* feature, and so is `SoftDeletes`, so neither runs for an `exists`
 * rule: `exists:business_locations,id` accepts every row in the table, of every
 * tenant, including branches deleted years ago — while reading, at the call site,
 * exactly like a rule that does not. Fourteen of the fifteen sites were written
 * that way, and the fifteenth was missing the soft-delete half.
 *
 * The dropdown beside each of those fields proves nothing about it. A dropdown is
 * scoped; a POST does not have to come from one. Being the thing that holds when
 * the form is bypassed is the entire job of a validation rule.
 *
 * So the tests here are deliberately of three different kinds, because the defect
 * had three different faces:
 *
 * - **The rule.** {@see TenantRules::location()} is checked directly against every
 *   state a branch row can be in — ours, ours-but-closed, ours-but-deleted,
 *   somebody else's, and never-existed. This is where the `is_active` decision is
 *   pinned: a closed branch still validates, on purpose.
 * - **The sites.** Every write path is submitted twice — once with a rival
 *   tenant's branch id and once with our own — and the pair is the point. A single
 *   negative would also pass if the field were simply broken in some unrelated
 *   way; the control run proves the refusal came from the tenancy clause.
 * - **The shape of the code.** A static sweep of `app/` for the unscoped idiom,
 *   because the sixteenth site will be written by somebody who has not read any of
 *   this, and the only durable defence is that the old spelling fails the suite.
 */
class TenantScopeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Sites whose refusal is not a validation response, and where that verdict is
     * asserted instead.
     *
     * Not an excuse list: each entry names the test that covers it. What it exists
     * for is the census below, which otherwise reads "this site has no coverage"
     * when the truth is "this site's coverage does not look like a 422".
     */
    private const COVERED_ELSEWHERE = [
        // Answers 200 with a per-sale verdict, because failing the batch would
        // lose the verdicts of the sales that already synced.
        'Api/OfflineSyncController.php:location_id'
            => 'a_queued_offline_sale_naming_another_tenants_branch_is_rejected',

        // Behind the `inventorymanagement` module switch, which this tenant does
        // not have on. Submitted in the stock-count module's own suite.
        'InventoryController.php:branch_id'
            => 'the stock-count module suite (item 11)',

        // Behind the `assetmanagement` module switch, same as above: this tenant
        // gets a 403 from `requireModule()` before validation runs at all, so the
        // 422 this test looks for could never arrive.
        'AssetController.php:location_id'
            => 'AssetsTest::an_asset_cannot_be_parked_at_another_tenants_branch',

        /*
         * Deferred in full by Decision #9 (NOTES §1): the accounting module's
         * routes are not registered on `main`, so there is no URL to submit and a
         * sites() entry is impossible rather than merely inconvenient. The
         * controller itself stays in the tree — deleting a finished module to
         * satisfy a census would be the wrong trade — and its `location_id`
         * declarations are the reason this entry exists at all.
         *
         * Stated plainly so the exemption is not mistaken for coverage: while the
         * module is unrouted, nothing exercises these declarations. They come back
         * under this test the moment the routes are restored, which is exactly what
         * the census is for.
         */
        'AccountingController.php:location_id'
            => 'unreachable on main — module deferred, no routes registered (NOTES §1 #9)',
    ];

    private User $owner;

    private int $businessId;

    private int $ownLocationId;

    private int $secondLocationId;

    private int $foreignLocationId;

    private int $productId;

    private int $contactId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Seeded inside this test's transaction, so anything spatie cached during
        // an earlier test points at ids that no longer exist.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        /*
         * The rival goes first. `register()` binds the tenant it has just built,
         * so registering ours second leaves the right one bound — and reading the
         * rival's branch id while its own binding is still active means this test
         * never has to disable a global scope to set up its fixture. If it did,
         * the fixture would be exercising the very mechanism under test.
         */
        $rival = $this->tenant('Rival Co.');
        $this->foreignLocationId = (int) BusinessLocation::query()->firstOrFail()->id;

        $own = $this->tenant('Scope Co.');
        $this->owner = $own['owner'];
        $this->businessId = (int) $own['business']->id;

        // TestCase::createProduct() reads these three.
        $this->business = $own['business'];
        $this->user = $own['owner'];
        $this->location = BusinessLocation::query()->firstOrFail();

        $this->ownLocationId = (int) $this->location->id;
        $this->productId = (int) $this->createProduct()->id;
        $this->contactId = (int) Contact::query()->where('type', 'customer')->value('id');

        /*
         * A second branch of ours, for one reason only: `transfer_location_id`
         * carries `different:location_id`, so its control run cannot reuse the
         * branch already sitting in `location_id` — the pair would fail on
         * `different` and the control would prove nothing.
         */
        $this->secondLocationId = (int) $this->makeLocation('Second Branch')->id;

        $this->assertNotSame(
            $this->foreignLocationId,
            $this->ownLocationId,
            'Fixture check: the rival must be a different tenant with a branch of its own.'
        );

        $this->actingAs($this->owner);
    }

    /* ================================================================
     | The rule
     ================================================================ */

    #[Test]
    public function the_location_rule_accepts_only_this_tenants_undeleted_branches(): void
    {
        $closed = $this->makeLocation('Closed Branch', ['is_active' => false]);

        $removed = $this->makeLocation('Removed Branch');
        $removed->delete();

        $this->assertSoftDeleted('business_locations', ['id' => $removed->id]);

        foreach ([
            // Ours and open: the ordinary case.
            'our own open branch' => [$this->ownLocationId, true],

            /*
             * Ours and closed — and it passes, deliberately. Inactive is a
             * business state, not a tenancy invariant: a branch shut in March is
             * still ours, and gating validation on it would make every document
             * already recorded there un-editable. Keeping *new* documents away
             * from a closed branch is BusinessLocation::forDropdown()'s job, and
             * it already does it.
             */
            'our own closed branch' => [$closed->id, true],

            /*
             * Ours and deleted, and this one must fail. It is the half that
             * `ManageUserController` was missing even after being written as the
             * example to copy: `Rule::exists` loses `SoftDeletes` for exactly the
             * same reason it loses `BusinessScope`, and `business_locations`
             * soft-deletes.
             */
            'our own deleted branch' => [$removed->id, false],

            // The whole point.
            'the rival tenant’s branch' => [$this->foreignLocationId, false],

            // And the ordinary typo, which must not pass either.
            'an id that was never a branch' => [2_100_000_000, false],
        ] as $label => [$id, $shouldPass]) {
            $passes = Validator::make(
                ['location_id' => $id],
                ['location_id' => [TenantRules::location()]]
            )->passes();

            $this->assertSame($shouldPass, $passes, sprintf(
                'TenantRules::location() %s %s.',
                $shouldPass ? 'rejected' : 'accepted',
                $label
            ));
        }
    }

    /* ================================================================
     | The sites
     ================================================================ */

    #[Test]
    public function no_write_path_accepts_another_tenants_branch(): void
    {
        foreach ($this->sites() as $site => $spec) {
            $rejected = $this->submit($spec, $this->foreignLocationId);

            $this->assertRefused($rejected, $spec, sprintf(
                '%s accepted the rival tenant’s branch id in `%s`.',
                $site,
                $spec['path']
            ));

            /*
             * The control, and the reason a single negative is not enough: a field
             * that is simply broken — misspelled, dropped from the payload,
             * shadowed by another rule — also produces an error under the first
             * assertion. Submitting our own branch id through the same path proves
             * the refusal above came from the tenancy clause and nothing else.
             */
            $accepted = $this->submit($spec, $spec['ok'] ?? $this->ownLocationId);

            $this->assertAccepted($accepted, $spec, sprintf(
                '%s rejected our own branch id in `%s`, so the refusal above was '
                .'not about tenancy.',
                $site,
                $spec['path']
            ));
        }
    }

    #[Test]
    public function no_write_path_accepts_a_branch_we_have_deleted(): void
    {
        $removed = $this->makeLocation('Removed Branch');
        $removed->delete();

        foreach ($this->sites() as $site => $spec) {
            $this->assertRefused(
                $this->submit($spec, (int) $removed->id),
                $spec,
                sprintf('%s accepted a soft-deleted branch id in `%s`.', $site, $spec['path'])
            );
        }
    }

    #[Test]
    public function a_queued_offline_sale_naming_another_tenants_branch_is_rejected(): void
    {
        $tempId = 'offline-'.uniqid();

        $response = $this->postJson(route('offline.sync'), [
            'device_id' => 'till-1',
            'sales' => [[
                'temp_id' => $tempId,
                'location_id' => $this->foreignLocationId,
                'contact_id' => $this->contactId,
                'lines' => [[
                    'variation_id' => $this->variationOf($this->createProduct())->id,
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ]],
        ])->assertOk();

        /*
         * A verdict, not a 422: the batch endpoint answers per sale, because
         * failing the request would throw away the verdicts of the sales that
         * already went in and the till would resend them.
         */
        $this->assertSame('rejected', $response->json('results.0.status'));

        $this->assertDatabaseMissing('transactions', ['offline_temp_id' => $tempId]);
    }

    /* ================================================================
     | The shape of the code
     |
     | The sixteenth site will be written by somebody who has not read any of
     | the above, and `exists:business_locations,id` will look right to them —
     | it looks right in every other repository. These two are the only things
     | that make the old spelling loud.
     ================================================================ */

    #[Test]
    public function nothing_validates_a_branch_with_an_unscoped_exists_rule(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = $this->relative($file->getPathname(), app_path());

            // The one file allowed to name the table: it is where the clauses live.
            if ($relative === 'Support/TenantRules.php') {
                continue;
            }

            if (preg_match('/exists:business_locations|exists\(\s*[\'"]business_locations[\'"]/', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These files validate a branch id against `business_locations` directly.',
            '`Rule::exists` compiles to the query builder, so neither BusinessScope',
            'nor SoftDeletes runs: the rule accepts every tenant’s branches and every',
            'deleted one. Use TenantRules::location() instead.',
        ]));
    }

    #[Test]
    public function every_site_that_validates_a_branch_is_covered_by_this_test(): void
    {
        $covered = array_merge(array_keys($this->sites()), array_keys(self::COVERED_ELSEWHERE));

        $uncovered = array_values(array_diff($this->siteDeclarations(), $covered));

        $this->assertSame([], $uncovered, implode("\n", [
            'These call sites take a branch id from the request and are not',
            'submitted by this test. Add each one to sites(), or — if its refusal',
            'is not a validation response — to COVERED_ELSEWHERE, naming the test',
            'that does cover it.',
        ]));
    }

    /* ================================================================
     | Fixtures
     ================================================================ */

    /**
     * Every write path that takes a branch id, keyed by the declaration it guards.
     *
     * The key is `<controller file>:<declared field>`, which is what the census
     * above compares against the source tree — so this array is not a list
     * somebody remembered to update, it is the list, and a site missing from it
     * fails.
     *
     * `path` is where the id goes in the payload and, because they are the same
     * string, the error key it comes back as. Every other field is left invalid on
     * purpose: these tests are about one field, and satisfying the rest would mean
     * fifteen fixtures to maintain and fifteen documents written per run.
     *
     * @return array<string, array{method: string, url: string, path: string, payload?: array<string, mixed>, ok?: int}>
     */
    private function sites(): array
    {
        return [
            'CashRegisterController.php:location_id' => [
                'method' => 'post',
                'url' => route('cash-register.store'),
                'path' => 'location_id',
            ],
            'DiscountController.php:location_id' => [
                'method' => 'post',
                'url' => route('discount.store'),
                'path' => 'location_id',
            ],
            'ExpenseController.php:location_id' => [
                'method' => 'post',
                'url' => route('expenses.store'),
                'path' => 'location_id',
            ],
            'ManageUserController.php:location_ids.*' => [
                'method' => 'post',
                'url' => route('users.store'),
                // The field is an array, so the error comes back on the element.
                'path' => 'location_ids.0',
            ],
            'OpeningStockController.php:location_id' => [
                'method' => 'put',
                // Keyed by product, with the branch in the body — the one site
                // here that resolves a record before it validates, so the product
                // has to be a real one of ours or the 404 arrives first.
                'url' => route('opening-stock.update', $this->productId),
                'path' => 'location_id',
            ],
            'PurchaseController.php:location_id' => [
                'method' => 'post',
                'url' => route('purchases.store'),
                'path' => 'location_id',
            ],
            'SellController.php:location_id' => [
                'method' => 'post',
                'url' => route('sells.store'),
                'path' => 'location_id',
            ],
            'SellPosController.php:location_id' => [
                'method' => 'post',
                'url' => route('pos.store'),
                'path' => 'location_id',
            ],
            'StockAdjustmentController.php:location_id' => [
                'method' => 'post',
                'url' => route('stock-adjustments.store'),
                'path' => 'location_id',
            ],
            'StockTransferController.php:location_id' => [
                'method' => 'post',
                'url' => route('stock-transfers.store'),
                'path' => 'location_id',
                // `different:location_id` sits on the other field, so the other
                // field has to hold something else for either run to be about the
                // rule under test.
                'payload' => ['transfer_location_id' => $this->secondLocationId],
            ],
            'StockTransferController.php:transfer_location_id' => [
                'method' => 'post',
                'url' => route('stock-transfers.store'),
                'path' => 'transfer_location_id',
                'payload' => ['location_id' => $this->ownLocationId],
                // And the control cannot be our first branch, for the same reason.
                'ok' => $this->secondLocationId,
            ],
            'Api/OfflineDataController.php:location_id' => [
                'method' => 'getJson',
                'url' => route('offline.data'),
                'path' => 'location_id',
            ],
        ];
    }

    /**
     * `<controller file>:<declared field>` for every `TenantRules::location()` in
     * the controller tree.
     *
     * Read from the source rather than from a list, because a list is the thing
     * that goes stale. The field is the nearest `'key' =>` before the call, which
     * is how `location_ids.*` — whose rule array puts the call on its own line —
     * is picked up with the right name.
     *
     * @return array<int, string>
     */
    private function siteDeclarations(): array
    {
        $root = app_path('Http/Controllers');
        $found = [];

        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $chunks = explode('TenantRules::location()', $source);
            array_pop($chunks);

            foreach ($chunks as $before) {
                preg_match_all('/[\'"]([A-Za-z0-9_.*]+)[\'"]\s*=>/', $before, $matches);

                $field = empty($matches[1]) ? '?' : end($matches[1]);

                $found[] = $this->relative($file->getPathname(), $root).':'.$field;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Submit one site with `$locationId` in its branch field.
     */
    private function submit(array $spec, int $locationId): \Illuminate\Testing\TestResponse
    {
        $payload = $spec['payload'] ?? [];
        Arr::set($payload, $spec['path'], $locationId);

        if ($spec['method'] === 'getJson') {
            return $this->getJson($spec['url'].'?'.http_build_query($payload));
        }

        return $this->{$spec['method']}($spec['url'], $payload);
    }

    /**
     * The field came back as a validation error.
     *
     * The framework's own assertions do the reading — they know where a
     * `ViewErrorBag` lives and this test should not have an opinion about it — but
     * they are wrapped so the failure names the site. These run in a loop of
     * twelve, and "Session missing error: location_id" does not say which one.
     */
    private function assertRefused(\Illuminate\Testing\TestResponse $response, array $spec, string $message): void
    {
        $this->named($message, fn () => $spec['method'] === 'getJson'
            ? $response->assertJsonValidationErrors([$spec['path']])
            : $response->assertSessionHasErrors([$spec['path']]));
    }

    /**
     * The field came back clean — whatever else the submission failed on.
     */
    private function assertAccepted(\Illuminate\Testing\TestResponse $response, array $spec, string $message): void
    {
        $this->named($message, fn () => $spec['method'] === 'getJson'
            ? $response->assertJsonMissingValidationErrors([$spec['path']])
            : $response->assertSessionDoesntHaveErrors([$spec['path']]));
    }

    private function named(string $message, callable $assertion): void
    {
        try {
            $assertion();
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            $this->fail($message."\n\n".$e->getMessage());
        }
    }

    /**
     * A branch of ours, reusing the scheme and layout of the one `register()`
     * built — both columns are NOT NULL behind foreign keys.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeLocation(string $name, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $this->businessId,
            'name' => $name,
            'invoice_scheme_id' => $this->location->invoice_scheme_id,
            'invoice_layout_id' => $this->location->invoice_layout_id,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * A tenant whose owner really holds the Admin role.
     *
     * `createTenant()` builds a business and an owner but no roles, and "admin" is
     * a role: `permit()` short-circuits on `isAdmin()`, so without it every
     * assertion here would be measuring a 403 instead of a validation rule.
     *
     * @return array{business: \App\Models\Business, owner: User}
     */
    private function tenant(string $name): array
    {
        $currency = Currency::firstOrCreate(
            ['code' => 'EGP'],
            ['country' => 'Egypt', 'currency' => 'Egyptian Pound', 'symbol' => 'ج.م',
                'thousand_separator' => ',', 'decimal_separator' => '.']
        );

        $registered = app(BusinessService::class)->register(
            ['name' => $name, 'currency_id' => $currency->id],
            ['first_name' => 'Owner', 'username' => 'owner'.uniqid(),
                'password' => 'secret-pass', 'language' => 'ar']
        );

        Tenancy::bind($registered['business']->id);

        return $registered;
    }

    private function relative(string $path, string $root): string
    {
        return str_replace('\\', '/', substr($path, strlen($root) + 1));
    }
}
