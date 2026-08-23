<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission the application checks for.
 *
 * Idempotent — safe to re-run after adding permissions to
 * App\Support\Permissions.
 */
class PermissionsTableSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $existing = Permission::pluck('name')->all();
        $created = 0;

        foreach (Permissions::all() as $name) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            Permission::create(['name' => $name, 'guard_name' => 'web']);
            $created++;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info("Permissions: {$created} created, ".count($existing).' already present.');
    }
}
