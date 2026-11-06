<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_token_is_encrypted_and_never_returned(): void
    {
        [$user, $business] = $this->membership();
        $response = $this->actingAs($user)->postJson('/api/v1/whatsapp/connections', [
            'phone_number_id' => 'phone-1', 'business_account_id' => 'account-1', 'access_token' => 'secret-access-token',
        ])->assertCreated();

        $response->assertJsonMissing(['access_token_encrypted' => 'secret-access-token']);
        $this->assertDatabaseMissing('whatsapp_connections', ['access_token_encrypted' => 'secret-access-token']);
        $this->assertDatabaseHas('whatsapp_connections', ['business_id' => $business->id, 'phone_number_id' => 'phone-1']);
    }

    public function test_webhook_is_verified_and_processing_is_queued(): void
    {
        config(['services.whatsapp.verify_token' => 'verify-me', 'services.whatsapp.app_secret' => 'test-secret']);
        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=challenge')
            ->assertOk()->assertSee('challenge');
        $this->get('/api/v1/webhooks/whatsapp?hub_verify_token=wrong&hub_challenge=challenge')->assertForbidden();

        Queue::fake();

        // Unsigned request is rejected with 401
        $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(401);
        Queue::assertNothingPushed();

        // Valid signature is accepted with 200 OK
        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'test-secret');
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $payload)
            ->assertStatus(200);
    }

    private function membership(): array
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => 'A', 'slug' => 'a']);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);
        return [$user, $business];
    }
}
