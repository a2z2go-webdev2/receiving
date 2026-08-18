<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk()->assertSee('data-force-light="true"', false);
    }

    public function test_expired_browser_session_redirects_with_a_clear_popup_message(): void
    {
        $cookieName = (string) config('session.cookie');

        $this->withCookie($cookieName, 'expired-session-id')
            ->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('session_expired');
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('auth_audit_logs', [
            'event' => 'login_success',
            'target_user_id' => $user->id,
            'success' => true,
        ]);
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['email' => __('auth.failed')]);

        $this->assertDatabaseHas('auth_audit_logs', [
            'event' => 'login_failure',
            'target_user_id' => $user->id,
            'success' => false,
            'failure_reason_code' => 'invalid_credentials',
        ]);
    }

    public function test_inactive_account_states_cannot_authenticate(): void
    {
        $users = [
            User::factory()->inactive()->create(),
            User::factory()->suspended()->create(),
            User::factory()->banned()->create(),
            User::factory()->deactivated()->create(),
        ];

        foreach ($users as $user) {
            $response = $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ]);

            $this->assertGuest();
            $response->assertSessionHasErrors(['email' => __('auth.failed')]);

            $this->assertDatabaseHas('auth_audit_logs', [
                'event' => 'login_failure',
                'target_user_id' => $user->id,
                'success' => false,
                'failure_reason_code' => 'account_not_active',
            ]);
        }
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();

        $this->assertDatabaseHas('auth_audit_logs', [
            'event' => 'logout',
            'target_user_id' => $user->id,
            'success' => true,
        ]);
    }

    public function test_admin_login_and_logout_are_visible_in_activity_logs(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->post(route('login.store'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'admin_logged_in',
            'user_email' => $admin->email,
            'status' => 'success',
        ]);

        $this->post(route('logout'));

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'admin_logged_out',
            'user_email' => $admin->email,
            'status' => 'success',
        ]);
        $this->assertSame(2, ActivityLog::query()->where('user_email', $admin->email)->count());
    }

    public function test_users_are_rate_limited()
    {
        $user = User::factory()->create();

        RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }
}
