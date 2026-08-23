<?php

namespace Tests\Feature;

use App\Models\Brands;
use App\Models\Role;
use App\Services\BusinessService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Smoke tests over the HTTP layer: the app boots, auth works, tenancy is
 * enforced, permissions gate the screens, and the Arabic/RTL chrome renders.
 */
class ApplicationSmokeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are normally seeded; create the ones these tests assert.
        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    #[Test]
    public function the_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('name="username"', false);
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get('/home')->assertRedirect('/login');
        $this->get('/brands')->assertRedirect('/login');
    }

    #[Test]
    public function a_registered_owner_can_sign_in_and_reach_the_dashboard(): void
    {
        ['owner' => $owner] = app(BusinessService::class)->register(
            ['name' => 'Souqly Cairo', 'currency_id' => $this->seedCurrency()->id],
            ['first_name' => 'Mostafa', 'username' => 'mostafa_'.uniqid(), 'password' => 'secret-pass', 'language' => 'ar']
        );

        $this->post('/login', [
            'username' => $owner->username,
            'password' => 'secret-pass',
        ])->assertRedirect('/home');

        $this->assertAuthenticatedAs($owner);

        $this->get('/home')
            ->assertOk()
            ->assertSee('dir="rtl"', false)   // Arabic user → RTL document
            ->assertSee('lang="ar"', false)
            ->assertSee(__('lang_v1.net_sales'));
    }

    #[Test]
    public function the_owner_gets_the_default_tenant_resources(): void
    {
        ['business' => $business, 'owner' => $owner] = app(BusinessService::class)->register(
            ['name' => 'Souqly Alex', 'currency_id' => $this->seedCurrency()->id],
            ['first_name' => 'Nour', 'username' => 'nour_'.uniqid(), 'password' => 'secret-pass']
        );

        // Tenant-namespaced roles.
        $this->assertTrue($owner->hasRole(Role::nameFor('Admin', $business->id)));
        $this->assertTrue($owner->isAdmin());
        $this->assertDatabaseHas('roles', [
            'name' => Role::nameFor('Cashier', $business->id),
            'business_id' => $business->id,
            'is_default' => 1,
        ]);

        // A location, a walk-in customer, a unit, a tax rate and the numbering.
        $this->assertDatabaseHas('business_locations', ['business_id' => $business->id]);
        $this->assertDatabaseHas('contacts', ['business_id' => $business->id, 'is_default' => 1]);
        $this->assertDatabaseHas('units', ['business_id' => $business->id]);
        $this->assertDatabaseHas('tax_rates', ['business_id' => $business->id]);
        $this->assertDatabaseHas('invoice_schemes', ['business_id' => $business->id, 'is_default' => 1]);
    }

    #[Test]
    public function an_admin_can_create_a_brand_and_it_is_stamped_with_the_tenant(): void
    {
        ['business' => $business, 'owner' => $owner] = app(BusinessService::class)->register(
            ['name' => 'Souqly Giza', 'currency_id' => $this->seedCurrency()->id],
            ['first_name' => 'Salma', 'username' => 'salma_'.uniqid(), 'password' => 'secret-pass']
        );

        $this->actingAs($owner)
            ->post('/brands', ['name' => 'Juhayna', 'description' => 'ألبان'])
            ->assertRedirect(route('brands.index'));

        $this->assertDatabaseHas('brands', [
            'name' => 'Juhayna',
            'business_id' => $business->id,
            'created_by' => $owner->id,
        ]);
    }

    #[Test]
    public function one_tenant_cannot_see_another_tenants_records(): void
    {
        $currency = $this->seedCurrency();

        ['business' => $businessA, 'owner' => $ownerA] = app(BusinessService::class)->register(
            ['name' => 'Tenant A', 'currency_id' => $currency->id],
            ['first_name' => 'A', 'username' => 'a_'.uniqid(), 'password' => 'secret-pass']
        );

        ['owner' => $ownerB] = app(BusinessService::class)->register(
            ['name' => 'Tenant B', 'currency_id' => $currency->id],
            ['first_name' => 'B', 'username' => 'b_'.uniqid(), 'password' => 'secret-pass']
        );

        // A brand belonging to tenant A only.
        \App\Support\Tenancy::bind($businessA->id);
        Brands::create(['name' => 'Tenant A Brand', 'created_by' => $ownerA->id]);
        \App\Support\Tenancy::forget();

        $this->actingAs($ownerA)->get('/brands')->assertOk()->assertSee('Tenant A Brand');

        // Tenant B must not see it.
        $this->flushSession();
        $this->actingAs($ownerB)->get('/brands')->assertOk()->assertDontSee('Tenant A Brand');
    }

    #[Test]
    public function a_user_without_the_permission_is_refused(): void
    {
        ['business' => $business, 'owner' => $owner] = app(BusinessService::class)->register(
            ['name' => 'Souqly Tanta', 'currency_id' => $this->seedCurrency()->id],
            ['first_name' => 'Owner', 'username' => 'owner_'.uniqid(), 'password' => 'secret-pass']
        );

        // A plain cashier: no brand.view permission.
        $cashier = \App\Models\User::create([
            'user_type' => 'user',
            'first_name' => 'Cashier',
            'username' => 'cashier_'.uniqid(),
            'password' => 'secret-pass',
            'business_id' => $business->id,
            'status' => 'active',
            'allow_login' => 1,
        ]);
        $cashier->assignRole(Role::nameFor('Cashier', $business->id));

        $this->actingAs($cashier)->get('/brands')->assertForbidden();

        // The owner (Admin) is allowed.
        $this->flushSession();
        $this->actingAs($owner)->get('/brands')->assertOk();
    }

    #[Test]
    public function a_user_with_login_disabled_cannot_reach_a_screen(): void
    {
        ['business' => $business] = app(BusinessService::class)->register(
            ['name' => 'Souqly Suez', 'currency_id' => $this->seedCurrency()->id],
            ['first_name' => 'Owner', 'username' => 'owner_'.uniqid(), 'password' => 'secret-pass']
        );

        $blocked = \App\Models\User::create([
            'user_type' => 'user',
            'first_name' => 'Blocked',
            'username' => 'blocked_'.uniqid(),
            'password' => 'secret-pass',
            'business_id' => $business->id,
            'status' => 'active',
            'allow_login' => 0,
        ]);

        $this->actingAs($blocked)->get('/brands')->assertRedirect(route('home'));
    }

    #[Test]
    public function the_ping_endpoint_answers_without_authentication(): void
    {
        $this->get('/api/ping')->assertOk()->assertJson(['ok' => true]);
    }

    #[Test]
    public function the_print_agent_api_rejects_a_forged_token(): void
    {
        $this->getJson('/api/print-queue/pending', ['X-Print-Token' => '1:deadbeef'])
            ->assertUnauthorized();

        $this->getJson('/api/print-queue/pending')->assertUnauthorized();
    }

    private function seedCurrency(): \App\Models\Currency
    {
        return \App\Models\Currency::firstOrCreate(
            ['code' => 'EGP'],
            [
                'country' => 'Egypt',
                'currency' => 'Egyptian Pound',
                'symbol' => 'ج.م',
                'thousand_separator' => ',',
                'decimal_separator' => '.',
            ]
        );
    }
}
