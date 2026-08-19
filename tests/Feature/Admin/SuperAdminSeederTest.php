<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SuperAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_super_admin(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $admin = User::query()->where('email', 'superadmin@example.com')->firstOrFail();

        $this->assertTrue($admin->isSuperAdmin());
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('password', $admin->password));
    }

    public function test_running_it_twice_does_not_create_a_second_account(): void
    {
        $this->seed(SuperAdminSeeder::class);
        $this->seed(SuperAdminSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'superadmin@example.com')->count());
    }

    public function test_re_seeding_never_resets_an_existing_password(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $admin = User::query()->where('email', 'superadmin@example.com')->firstOrFail();
        $admin->forceFill(['password' => Hash::make('a-password-they-chose')])->save();

        $this->seed(SuperAdminSeeder::class);

        $admin->refresh();
        $this->assertTrue(Hash::check('a-password-they-chose', $admin->password));
        $this->assertTrue($admin->isSuperAdmin());
    }

    public function test_it_promotes_an_existing_account_rather_than_duplicating_it(): void
    {
        $existing = User::factory()->create([
            'email' => 'superadmin@example.com',
            'is_super_admin' => false,
        ]);

        $this->seed(SuperAdminSeeder::class);

        $this->assertSame(1, User::query()->where('email', 'superadmin@example.com')->count());
        $this->assertTrue($existing->fresh()->isSuperAdmin());
    }

    public function test_the_seeded_admin_can_reach_the_panel(): void
    {
        $this->seed(SuperAdminSeeder::class);

        $this->actingAs(User::query()->where('email', 'superadmin@example.com')->firstOrFail())
            ->get(route('admin.dashboard'))
            ->assertOk();
    }
}
