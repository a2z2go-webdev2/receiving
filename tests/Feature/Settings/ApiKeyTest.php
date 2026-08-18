<?php

namespace Tests\Feature\Settings;

use App\Models\ApiKey;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_can_generate_a_hash_only_expiring_key_and_see_it_once(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('api-keys.store'), ['name' => 'Accounting', 'expires_in_days' => 90]);

        $plainText = $response->viewData('page')['props']['newApiKey'] ?? null;
        $this->assertIsString($plainText);
        $this->assertMatchesRegularExpression('/^rcv_[A-Za-z0-9]{16}\.[A-Za-z0-9_-]{43}$/', $plainText);
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('settings/api-keys')
            ->where('newApiKey', $plainText));

        $stored = ApiKey::query()->sole();
        [, $secret] = explode('.', $plainText, 2);
        $this->assertSame(hash('sha256', $secret), $stored->getRawOriginal('token_hash'));
        $this->assertArrayNotHasKey('token_hash', $stored->toArray());
        $this->assertEqualsWithDelta(90, now()->diffInDays($stored->expires_at), 1);

        $this->get(route('api-keys.index'))
            ->assertInertia(fn ($page) => $page->where('newApiKey', null));
    }

    public function test_user_cannot_revoke_someone_elses_key(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $other = User::factory()->create();
        $key = ApiKey::query()->create([
            'user_id' => $other->getKey(),
            'name' => 'Other owner',
            'public_id' => 'AbCdEfGhIjKlMnOp',
            'token_hash' => hash('sha256', str_repeat('a', 43)),
            'abilities' => [ApiKey::ABILITY_READ_CORRECTED_DATA],
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('api-keys.destroy', $key))
            ->assertNotFound();

        $this->assertNull($key->fresh()->revoked_at);
    }

    public function test_admin_can_explicitly_create_a_never_expiring_key(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('api-keys.store'), ['name' => 'Long-lived integration', 'expires_in_days' => 'never'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/api-keys')
                ->where('apiKeys.0.expires_at', null));

        $this->assertNull(ApiKey::query()->sole()->expires_at);
    }

    public function test_api_key_expiry_rejects_unknown_values(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('api-keys.store'), ['name' => 'Unsafe', 'expires_in_days' => 'forever-ish'])
            ->assertSessionHasErrors('expires_in_days');

        $this->assertDatabaseEmpty('api_keys');
    }

    public function test_uploader_cannot_manage_api_keys(): void
    {
        $uploader = User::factory()->create();
        $uploader->assignRole('uploader');

        $this->actingAs($uploader)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('api-keys.index'))
            ->assertForbidden();
    }
}
