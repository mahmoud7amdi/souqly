<?php

namespace Tests\Feature\Auth;

use Database\Seeders\AdminUserSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Authentication is by USERNAME, never email. These tests pin that contract —
 * the login form, the credential validation and the seeded dev account — so a
 * later refactor cannot quietly reintroduce email-based auth.
 */
class UsernameLoginTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_login_form_asks_for_a_username_and_not_an_email(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('name="username"', false);
        $response->assertDontSee('name="email"', false);
        $response->assertDontSee('type="email"', false);
    }

    #[Test]
    public function the_seeded_admin_signs_in_with_its_username(): void
    {
        $this->seed(PermissionsTableSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $response = $this->post(route('login'), [
            'username' => AdminUserSeeder::USERNAME,
            'password' => AdminUserSeeder::password(),
        ]);

        $response->assertRedirect(route('home'));
        $this->assertAuthenticated();

        $admin = auth()->user();
        $this->assertSame(AdminUserSeeder::USERNAME, $admin->username);
        $this->assertNull($admin->email, 'The dev account must not depend on an email.');
    }

    #[Test]
    public function the_seeded_admin_is_unrestricted(): void
    {
        $this->seed(PermissionsTableSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $admin = \App\Models\User::where('username', AdminUserSeeder::USERNAME)->firstOrFail();

        $this->assertTrue($admin->isAdmin());

        // Gate::before grants an admin every ability even though the Admin role
        // itself carries no explicit permissions.
        $this->assertSame([], $admin->getAllPermissions()->pluck('name')->all());

        foreach (['purchase.view', 'sell.create', 'access_all_locations',
            'user.create', 'business_settings.access', 'roles.view'] as $ability) {
            $this->assertTrue($admin->can($ability), "Admin should be allowed: {$ability}");
        }

        // …and therefore resolves to every location, not none.
        $this->actingAs($admin);
        $this->assertSame('all', \App\Models\BusinessLocation::permittedLocations());
    }

    #[Test]
    public function an_email_cannot_be_used_as_the_credential(): void
    {
        $this->seed(PermissionsTableSeeder::class);
        $this->seed(AdminUserSeeder::class);

        \App\Models\User::where('username', AdminUserSeeder::USERNAME)
            ->update(['email' => 'admin@souqly.test']);

        $response = $this->post(route('login'), [
            'username' => 'admin@souqly.test',
            'password' => AdminUserSeeder::password(),
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    #[Test]
    public function a_wrong_password_is_rejected(): void
    {
        $this->seed(PermissionsTableSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $response = $this->post(route('login'), [
            'username' => AdminUserSeeder::USERNAME,
            'password' => 'not-the-password',
        ]);

        $response->assertSessionHasErrors('username');
        $this->assertGuest();
    }
}
