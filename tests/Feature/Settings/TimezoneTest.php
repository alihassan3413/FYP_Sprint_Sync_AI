<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_save_a_timezone(): void
    {
        $user = User::factory()->create(['timezone' => null]);

        $this->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => 'Asia/Karachi',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertSame('Asia/Karachi', $user->refresh()->timezone);
    }

    public function test_an_invalid_timezone_is_rejected(): void
    {
        $user = User::factory()->create(['timezone' => 'Asia/Karachi']);

        $this->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => 'Mars/Olympus_Mons',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertSame('Asia/Karachi', $user->refresh()->timezone);
    }

    public function test_a_timezone_can_be_cleared_back_to_the_application_default(): void
    {
        $user = User::factory()->create(['timezone' => 'Asia/Karachi']);

        $this->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => null,
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertNull($user->timezone);
        $this->assertSame(config('app.timezone'), $user->resolvedTimezone());
    }

    public function test_the_resolved_timezone_is_shared_with_the_frontend(): void
    {
        $user = User::factory()->create(['timezone' => 'Asia/Karachi']);

        $this->actingAs($user)
            ->get('/settings/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.timezone', 'Asia/Karachi'));
    }

    public function test_a_user_without_a_timezone_is_shared_the_application_default(): void
    {
        $user = User::factory()->create(['timezone' => null]);

        $this->actingAs($user)
            ->get('/settings/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.timezone', config('app.timezone')));
    }
}
