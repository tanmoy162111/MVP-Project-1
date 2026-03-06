<?php

namespace Tests\Feature\Auth;

use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    // ── REGISTER ─────────────────────────────────────────────────────────────

    public function test_customer_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Test Customer',
            'email'                 => 'customer@test.com',
            'password'              => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertStatus(201)
                 ->assertJsonStructure([
                     'success', 'message',
                     'data' => ['user' => ['id', 'name', 'email', 'type', 'roles'], 'token'],
                 ]);

        $this->assertDatabaseHas('users', ['email' => 'customer@test.com', 'type' => 'customer']);
    }

    public function test_register_fails_with_duplicate_email(): void
    {
        User::factory()->create(['email' => 'existing@test.com']);

        $response = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Another User',
            'email'                 => 'existing@test.com',
            'password'              => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('errors.email.0', 'This email address is already registered.');
    }

    public function test_register_fails_with_weak_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name'                  => 'Test User',
            'email'                 => 'test@test.com',
            'password'              => '1234',
            'password_confirmation' => '1234',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);
    }

    // ── LOGIN ─────────────────────────────────────────────────────────────────

    public function test_user_can_login(): void
    {
        $user = User::factory()->create([
            'email'    => 'login@test.com',
            'password' => bcrypt('Password1!'),
            'type'     => 'customer',
            'status'   => 'active',
        ]);
        $user->assignRole('customer');

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'login@test.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create(['email' => 'user@test.com', 'password' => bcrypt('CorrectPass1!')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'user@test.com',
            'password' => 'WrongPassword1!',
        ]);

        $response->assertStatus(400)
                 ->assertJsonPath('success', false);
    }

    public function test_suspended_user_cannot_login(): void
    {
        User::factory()->create([
            'email'    => 'suspended@test.com',
            'password' => bcrypt('Password1!'),
            'status'   => 'suspended',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'suspended@test.com',
            'password' => 'Password1!',
        ]);

        $response->assertStatus(400);
    }

    // ── ME / LOGOUT ───────────────────────────────────────────────────────────

    public function test_authenticated_user_can_get_profile(): void
    {
        $user = User::factory()->create(['type' => 'customer', 'status' => 'active']);
        $user->assignRole('customer');

        $response = $this->actingAs($user, 'sanctum')
                         ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
                 ->assertJsonPath('data.email', $user->email)
                 ->assertJsonStructure(['data' => ['id', 'name', 'email', 'roles', 'permissions']]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/auth/me');
        $response->assertStatus(401);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('customer');

        $response = $this->actingAs($user, 'sanctum')
                         ->postJson('/api/v1/auth/logout');

        $response->assertStatus(204);
    }
}
