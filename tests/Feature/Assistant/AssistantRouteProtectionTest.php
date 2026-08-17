<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AssistantRouteProtectionTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function middlewareFor(string $name): array
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "Route [{$name}] is not registered.");

        return $route->gatherMiddleware();
    }

    public function test_the_chat_endpoint_requires_authentication_and_verification(): void
    {
        $middleware = $this->middlewareFor('assistant.chat');

        $this->assertContains('auth', $middleware);
        $this->assertContains('verified', $middleware);
        $this->assertContains('throttle:assistant-chat', $middleware);
    }

    public function test_the_confirm_endpoint_requires_authentication_and_verification(): void
    {
        $middleware = $this->middlewareFor('assistant.confirm');

        $this->assertContains('auth', $middleware);
        $this->assertContains('verified', $middleware);
        $this->assertContains('throttle:assistant-chat', $middleware);
    }

    public function test_the_assistant_matches_the_auth_gate_used_by_tenant_routes(): void
    {
        $tenantGate = array_values(array_intersect(
            $this->middlewareFor('workspace.projects.index'),
            ['auth', 'verified'],
        ));

        foreach (['assistant.chat', 'assistant.confirm'] as $name) {
            $this->assertSame(
                $tenantGate,
                array_values(array_intersect($this->middlewareFor($name), ['auth', 'verified'])),
                "Route [{$name}] does not match the tenant-route auth gate.",
            );
        }
    }
}
