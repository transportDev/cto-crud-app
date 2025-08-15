<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_admin_panel(): void
    {
        $response = $this->get('/admin/crud');
        $response->assertStatus(302);
        $response->assertRedirect('/admin/login');
    }

    public function test_non_admin_user_forbidden_from_admin_panel(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->get('/admin/crud');

        $response->assertStatus(403);
    }

    public function test_admin_user_can_access_admin_panel(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        // Ensure admin role exists and assign it
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user->assignRole($role);

        $response = $this->actingAs($user, 'web')->get('/admin/crud');

        $response->assertOk();
    }
}


