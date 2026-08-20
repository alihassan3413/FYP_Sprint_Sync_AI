<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_sees_the_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));
    }

    public function test_the_landing_page_needs_no_authentication_or_workspace(): void
    {
        $this->get('/')->assertOk();

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk();
    }
}
