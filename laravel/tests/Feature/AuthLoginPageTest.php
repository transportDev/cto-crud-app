<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_filament_login_page_renders(): void
    {
        $this->artisan('migrate', ['--force' => true]);

        $response = $this->get('/admin/login');

        $response->assertStatus(200);
        $response->assertSee('Sign in');
    }
}


