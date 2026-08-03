<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
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
