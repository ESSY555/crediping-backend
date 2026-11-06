<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\MessageTemplate;
use App\Models\WhatsAppConnection;
use App\Services\WhatsAppOnboardingService;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * "Connect WhatsApp" (Meta Embedded Signup): the business supplies only the
 * popup's code; the backend exchanges it, registers the number, subscribes
 * the webhook, attaches billing and pushes the template library.
 */
class WhatsAppEmbeddedSignupTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    private function configure(): void
    {
        config([
            'services.whatsapp.app_id' => '123456',
            'services.whatsapp.app_secret' => 'app-secret',
            'services.whatsapp.embedded_signup_config_id' => 'cfg-1',
            'services.whatsapp.platform_token' => 'platform-token',
            'services.whatsapp.credit_line_id' => 'cl-1',
            'services.whatsapp.graph_url' => 'https://graph.facebook.com/v22.0',
            'services.whatsapp.manual_connect' => false,
        ]);
    }

    /** A Graph API stand-in for the whole connect sequence. */
    private function fakeGraph(array $overrides = []): void
    {
        Http::fake(array_merge([
            'graph.facebook.com/v22.0/oauth/access_token*' => Http::response(['access_token' => 'biz-token']),
            'graph.facebook.com/v22.0/waba-9/phone_numbers*' => Http::response(['data' => [[
                'id' => 'phone-9', 'display_phone_number' => '+234 801 234 5678', 'verified_name' => 'Pavizs Store',
                'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K', 'platform_type' => 'CLOUD_API',
            ]]]),
            'graph.facebook.com/v22.0/phone-9?*' => Http::response([
                'id' => 'phone-9', 'display_phone_number' => '+234 801 234 5678', 'verified_name' => 'Pavizs Store',
                'quality_rating' => 'GREEN', 'messaging_limit_tier' => 'TIER_1K', 'platform_type' => 'CLOUD_API',
            ]),
            'graph.facebook.com/v22.0/waba-9/subscribed_apps' => Http::response(['success' => true]),
            'graph.facebook.com/v22.0/phone-9/register' => Http::response(['success' => true]),
            'graph.facebook.com/v22.0/cl-1/whatsapp_credit_sharing_and_attach*' => Http::response(['waba_id' => 'waba-9', 'allocation_config_id' => 'alloc-1']),
            'graph.facebook.com/v22.0/waba-9/message_templates?*' => Http::response(['data' => [
                ['id' => 'tpl-existing', 'name' => 'flowcrm_payment_received', 'status' => 'APPROVED', 'language' => 'en', 'category' => 'UTILITY'],
            ]]),
            'graph.facebook.com/v22.0/waba-9/message_templates' => Http::response(['id' => 'tpl-new', 'status' => 'PENDING', 'category' => 'UTILITY']),
            'graph.facebook.com/v22.0/debug_token*' => Http::response(['data' => ['granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['waba-9']]]]]),
        ], $overrides));
    }

    public function test_signup_config_reports_availability(): void
    {
        [$user] = $this->membership();

        $this->actingAs($user)->getJson('/api/v1/whatsapp/signup-config')->assertOk()
            ->assertJsonPath('data.embedded_signup.enabled', false)->assertJsonPath('data.embedded_signup.config_id', null);

        $this->configure();
        $this->actingAs($user)->getJson('/api/v1/whatsapp/signup-config')->assertOk()
            ->assertJsonPath('data.embedded_signup.enabled', true)
            ->assertJsonPath('data.embedded_signup.app_id', '123456')
            ->assertJsonPath('data.embedded_signup.config_id', 'cfg-1')
            ->assertJsonPath('data.embedded_signup.graph_version', 'v22.0')
            ->assertJsonPath('data.manual_connect', false);
    }

    public function test_connect_turns_the_popup_code_into_a_working_connection(): void
    {
        $this->configure();
        $this->fakeGraph();
        [$user, $business] = $this->membership();

        $res = $this->actingAs($user)->postJson('/api/v1/whatsapp/embedded-signup', ['code' => 'popup-code', 'waba_id' => 'waba-9', 'phone_number_id' => 'phone-9'])
            ->assertCreated();

        $res->assertJsonPath('data.phone_number_id', 'phone-9')
            ->assertJsonPath('data.business_account_id', 'waba-9')
            ->assertJsonPath('data.display_phone_number', '+234 801 234 5678')
            ->assertJsonPath('data.verified_name', 'Pavizs Store')
            ->assertJsonPath('data.quality', 'green')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.onboarding_source', 'embedded')
            ->assertJsonPath('data.templates.total', 5)
            ->assertJsonPath('data.templates.approved', 1)
            ->assertJsonPath('data.templates.pending', 4)
            ->assertJsonMissingPath('data.access_token_encrypted')
            ->assertJsonMissingPath('data.pin_encrypted');

        $connection = WhatsAppConnection::withoutGlobalScopes()->where('phone_number_id', 'phone-9')->firstOrFail();
        $this->assertSame($business->id, $connection->business_id);
        $this->assertSame('biz-token', $connection->access_token_encrypted);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $connection->pin_encrypted);
        $this->assertNotNull($connection->webhook_subscribed_at);
        $this->assertNotNull($connection->credit_line_attached_at);
        $this->assertNotNull($connection->templates_synced_at);

        // Every Graph step ran, with the right tokens.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/oauth/access_token') && $r['client_secret'] === 'app-secret' && $r['code'] === 'popup-code');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/waba-9/subscribed_apps') && $r->hasHeader('Authorization', 'Bearer biz-token'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/phone-9/register') && $r['pin'] === $connection->pin_encrypted);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/cl-1/whatsapp_credit_sharing_and_attach') && $r->hasHeader('Authorization', 'Bearer platform-token') && $r['waba_id'] === 'waba-9');
        // Four templates submitted (the fifth already existed on the WABA), with positional placeholders.
        Http::assertSentCount(1 + 1 + 1 + 1 + 1 + 1 + 4);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/waba-9/message_templates')
            && $r['name'] === 'flowcrm_invoice_sent' && $r['category'] === 'UTILITY'
            && str_contains($r['components'][0]['text'], '{{1}}') && !str_contains($r['components'][0]['text'], 'customer_name'));

        // Platform templates mirrored for the business, usable once approved.
        $this->assertSame(5, MessageTemplate::withoutGlobalScopes()->where('business_id', $business->id)->where('is_platform', true)->count());
        $received = MessageTemplate::withoutGlobalScopes()->where('business_id', $business->id)->where('name', 'flowcrm_payment_received')->firstOrFail();
        $this->assertSame('approved', $received->status);
        $this->assertSame('tpl-existing', $received->meta_template_id);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'whatsapp_connection.connected']);

        // The business can now send.
        $this->actingAs($user)->getJson('/api/v1/business')->assertJsonPath('data.whatsapp_connected', true);
    }

    public function test_connect_survives_billing_and_template_failures(): void
    {
        $this->configure();
        // First attempt fails at billing and template listing; the retry succeeds.
        $this->fakeGraph([
            'graph.facebook.com/v22.0/cl-1/whatsapp_credit_sharing_and_attach*' => Http::sequence()
                ->push(['error' => ['message' => 'Credit line not shareable', 'code' => 100]], 400)
                ->push(['waba_id' => 'waba-9', 'allocation_config_id' => 'alloc-1']),
            'graph.facebook.com/v22.0/waba-9/message_templates?*' => Http::sequence()
                ->push(['error' => ['message' => 'Temporarily unavailable', 'code' => 2]], 500)
                ->push(['data' => [['id' => 'tpl-existing', 'name' => 'flowcrm_payment_received', 'status' => 'APPROVED', 'language' => 'en', 'category' => 'UTILITY']]]),
        ]);
        [$user] = $this->membership();

        $res = $this->actingAs($user)->postJson('/api/v1/whatsapp/embedded-signup', ['code' => 'popup-code', 'waba_id' => 'waba-9'])->assertCreated();
        $res->assertJsonPath('data.status', 'active')->assertJsonPath('data.credit_line_attached_at', null)->assertJsonPath('data.templates_synced_at', null);
        $this->assertStringContainsString('pending', (string) $res->json('data.last_error'));

        // Retry once Meta is happy again.
        $this->actingAs($user)->postJson("/api/v1/whatsapp/connections/{$res->json('data.id')}/retry-setup")->assertOk()
            ->assertJsonPath('message', 'Setup complete.')->assertJsonPath('data.templates_submitted', 4);
        $this->assertNotNull(WhatsAppConnection::withoutGlobalScopes()->find($res->json('data.id'))->credit_line_attached_at);
    }

    public function test_connect_rejects_bad_codes_unconfigured_platform_and_claimed_numbers(): void
    {
        [$user] = $this->membership();
        $this->actingAs($user)->postJson('/api/v1/whatsapp/embedded-signup', ['code' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->configure();
        $this->fakeGraph(['graph.facebook.com/v22.0/oauth/access_token*' => Http::sequence()
            ->push(['error' => ['message' => 'Invalid code', 'code' => 100]], 400)
            ->push(['access_token' => 'biz-token'])]);
        $this->actingAs($user)->postJson('/api/v1/whatsapp/embedded-signup', ['code' => 'bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        // A number already connected to another workspace cannot be claimed twice.
        [$otherUser, $otherBusiness] = $this->membership('Other');
        $this->connectionFor($otherBusiness, 'phone-9');
        $this->actingAs($user)->postJson('/api/v1/whatsapp/embedded-signup', ['code' => 'popup-code', 'waba_id' => 'waba-9'])
            ->assertUnprocessable()->assertJsonPath('errors.code.0', 'This WhatsApp number is already connected to another Crediping workspace.');

        // Managers cannot connect; only owners.
        $manager = \App\Models\User::factory()->create();
        $manager->businesses()->attach($otherBusiness->id, ['role' => 'manager', 'status' => 'active']);
        $this->actingAs($manager)->postJson('/api/v1/whatsapp/embedded-signup', ['code' => 'popup-code'])->assertForbidden();
    }

    public function test_manual_connection_is_disabled_unless_configured_for_development(): void
    {
        [$user] = $this->membership();
        config(['services.whatsapp.manual_connect' => false]);
        $this->actingAs($user)->postJson('/api/v1/whatsapp/connections', ['phone_number_id' => 'p1', 'access_token' => 'token-1234567890'])->assertForbidden();

        config(['services.whatsapp.manual_connect' => true]);
        $this->actingAs($user)->postJson('/api/v1/whatsapp/connections', ['phone_number_id' => 'p1', 'access_token' => 'token-1234567890'])
            ->assertCreated()->assertJsonPath('data.onboarding_source', 'manual');
    }

    public function test_account_level_webhooks_update_templates_quality_and_restrictions(): void
    {
        $this->configure();
        $this->fakeGraph();
        [$user, $business] = $this->membership();
        $this->actingAs($user)->postJson('/api/v1/whatsapp/embedded-signup', ['code' => 'popup-code', 'waba_id' => 'waba-9'])->assertCreated();
        $connection = WhatsAppConnection::withoutGlobalScopes()->where('phone_number_id', 'phone-9')->firstOrFail();

        $webhook = fn (string $field, array $value) => (new ProcessWhatsAppWebhook([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'waba-9', 'changes' => [['field' => $field, 'value' => $value]]]],
        ]))->handle(app(WhatsAppService::class), app(WhatsAppOnboardingService::class));

        // Template approved / rejected.
        $webhook('message_template_status_update', ['event' => 'APPROVED', 'message_template_id' => 'tpl-new', 'message_template_name' => 'flowcrm_invoice_sent', 'message_template_language' => 'en']);
        $webhook('message_template_status_update', ['event' => 'REJECTED', 'message_template_id' => 'tpl-new', 'message_template_name' => 'flowcrm_payment_overdue', 'reason' => 'INVALID_FORMAT']);
        $this->assertSame('approved', MessageTemplate::withoutGlobalScopes()->where('business_id', $business->id)->where('name', 'flowcrm_invoice_sent')->value('status'));
        $overdue = MessageTemplate::withoutGlobalScopes()->where('business_id', $business->id)->where('name', 'flowcrm_payment_overdue')->first();
        $this->assertSame('rejected', $overdue->status);
        $this->assertSame('INVALID_FORMAT', $overdue->rejection_reason);

        // Quality flagged -> RED + notification; tier upgrade recorded.
        $webhook('phone_number_quality_update', ['display_phone_number' => '+234 801 234 5678', 'event' => 'FLAGGED', 'current_limit' => 'TIER_1K']);
        $this->assertSame('RED', $connection->fresh()->quality_rating);
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'whatsapp_connection_failure', 'title' => 'WhatsApp number flagged']);
        $webhook('phone_number_quality_update', ['display_phone_number' => '+234 801 234 5678', 'event' => 'UPGRADE', 'current_limit' => 'TIER_10K']);
        $this->assertSame('TIER_10K', $connection->fresh()->messaging_limit_tier);

        // Account restricted -> connection in error, sending blocked.
        $webhook('account_update', ['event' => 'DISABLED_UPDATE', 'ban_info' => ['waba_ban_state' => 'DISABLE']]);
        $this->assertSame('error', $connection->fresh()->status);
        $this->actingAs($user)->getJson('/api/v1/business')->assertJsonPath('data.whatsapp_connected', false);

        // Unknown WABA is ignored quietly.
        (new ProcessWhatsAppWebhook(['entry' => [['id' => 'waba-unknown', 'changes' => [['field' => 'account_update', 'value' => ['event' => 'DISABLED_UPDATE']]]]]]))
            ->handle(app(WhatsAppService::class), app(WhatsAppOnboardingService::class));
        $this->assertTrue(true);
    }
}
