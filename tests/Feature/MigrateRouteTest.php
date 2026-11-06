<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrateRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrate_route_is_hidden_without_token(): void
    {
        $this->get('/migrate')->assertNotFound();
    }

    public function test_migrate_route_is_hidden_with_wrong_token(): void
    {
        $this->get('/migrate?token=wrong-token')->assertNotFound();
    }

    public function test_migrate_route_runs_migrations_with_valid_token(): void
    {
        $response = $this->get('/migrate?token=testing-migrate-token');

        $response->assertOk();
        $response->assertSee('Nothing to migrate.');
        $response->assertSee('Migration name');
    }

    public function test_migrate_route_accepts_token_as_path(): void
    {
        $this->get('/migrate/testing-migrate-token')
            ->assertOk()
            ->assertSee('Nothing to migrate.');
    }
}
