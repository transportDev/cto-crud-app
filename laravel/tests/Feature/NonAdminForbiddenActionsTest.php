<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NonAdminForbiddenActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    public function test_non_admin_is_forbidden_from_admin_pages(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->get('/admin/crud')
            ->assertStatus(403);
    }

    public function test_admin_is_allowed(): void
    {
        /** @var User $user */
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $user->assignRole($role);

        $this->actingAs($user, 'web')
            ->get('/admin/crud')
            ->assertStatus(200);
    }
}
