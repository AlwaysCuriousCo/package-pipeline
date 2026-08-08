<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // The login page reads the active authentication sources for its SSO
    // buttons, so even this smoke test needs a migrated database.
    use RefreshDatabase;

    public function test_the_root_url_redirects_to_the_filament_login_page(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/admin/login');
    }

    public function test_the_filament_login_page_returns_a_successful_response(): void
    {
        $response = $this->get('/admin/login');

        $response->assertStatus(200);
    }
}
