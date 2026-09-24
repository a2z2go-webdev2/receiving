<?php

use App\Jobs\SyncPurchaseOrderSheet;
use App\Models\GoogleSheetConfig;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->artisan('db:seed', ['--force' => true]);
});

test('webhook endpoint rejects requests without secret or with invalid secret', function () {
    $config = GoogleSheetConfig::query()->where('slug', 'a2z2go')->first();
    $config->update(['webhook_secret' => 'whsec_test_secret_12345']);

    // 1. Missing secret
    $response = $this->postJson('/api/webhooks/sheets/a2z2go', [
        'serial_number' => 10,
    ]);
    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
        ]);

    // 2. Invalid secret
    $response = $this->withHeaders(['X-Webhook-Secret' => 'wrong_secret'])
        ->postJson('/api/webhooks/sheets/a2z2go', [
            'serial_number' => 10,
        ]);
    $response->assertStatus(401)
        ->assertJson([
            'success' => false,
        ]);
});

test('webhook endpoint rejects invalid or zero serial number in direct payload', function () {
    $config = GoogleSheetConfig::query()->where('slug', 'a2z2go')->first();
    $secret = 'whsec_test_secret_12345';
    $config->update(['webhook_secret' => $secret]);

    $response = $this->withHeaders(['X-Webhook-Secret' => $secret])
        ->postJson('/api/webhooks/sheets/a2z2go', [
            'serial_number' => 0,
            'log' => ['Serial Number' => '0'],
            'files' => [],
        ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => 'Invalid serial number provided in webhook payload.',
        ]);
});

test('admin can save sheet configuration with slug in request body', function () {
    $admin = User::query()->where('email', 'admin@example.com')->first();
    if (! $admin) {
        $admin = User::factory()->create();
        $admin->syncRoles(['admin']);
    }

    $response = $this->actingAs($admin)
        ->withSession(['admin.otp_verified_at' => now()->getTimestamp()])
        ->postJson('/admin/sheets-sync/config', [
            'slug' => 'a2z2go',
            'spreadsheet_id' => 'https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit',
            'webhook_secret' => 'whsec_new_secret_abc123',
            'auto_sync_on_webhook' => true,
        ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
        ]);

    $config = GoogleSheetConfig::query()->where('slug', 'a2z2go')->first();
    expect($config->spreadsheet_id)->toBe('1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms')
        ->and($config->webhook_secret)->toBe('whsec_new_secret_abc123')
        ->and($config->auto_sync_on_webhook)->toBeTrue();
});

test('purchase order webhook triggers sync job with valid secret', function () {
    Queue::fake();

    $config = GoogleSheetConfig::query()->create([
        'slug' => 'purchase-orders',
        'name' => 'Purchase Orders Master',
        'sheet_type' => 'purchase_order',
        'webhook_secret' => 'whsec_po_secret_xyz',
    ]);

    $response = $this->withHeaders(['X-Webhook-Secret' => 'whsec_po_secret_xyz'])
        ->postJson('/api/webhooks/sheets/purchase-orders', [
            'tab' => 'Purchase Orders KEYSYS',
        ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
            'target_slug' => 'keysys',
        ]);

    Queue::assertPushed(SyncPurchaseOrderSheet::class, function ($job) {
        return $job->configOrSlug === 'keysys';
    });
});
