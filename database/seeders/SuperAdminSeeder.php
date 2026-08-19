<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates (or promotes) the platform administrator.
 *
 * Idempotent: re-running never duplicates the account and never resets the
 * password of an existing one, so it is safe to include in a seed that runs
 * more than once. Credentials come from the environment; the fallbacks are
 * for local development only.
 */
final class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('SUPER_ADMIN_EMAIL', 'superadmin@example.com');
        $name = (string) env('SUPER_ADMIN_NAME', 'Super Admin');
        $password = (string) env('SUPER_ADMIN_PASSWORD', 'password');

        $user = User::query()->firstOrNew(['email' => $email]);

        // Only set on first creation, so re-seeding never clobbers a password
        // the administrator has since changed.
        if (! $user->exists) {
            $user->name = $name;
            $user->password = Hash::make($password);
            $user->email_verified_at = now();
        }

        $user->is_super_admin = true;
        $user->save();

        $this->command?->info("Super admin ready: {$email}");

        if (! app()->environment('production') && $password === 'password') {
            $this->command?->warn('Using the default development password. Set SUPER_ADMIN_PASSWORD before seeding anywhere real.');
        }
    }
}
