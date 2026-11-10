<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\ProcessWhatsAppWebhook;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Message;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WhatsAppMessagingTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    // ------------------------------------------------------------ templates

    public function test_template_crud_search_and_filters(): void
    {
        [$user, $business] = $this->membership();

        $created = $this->actingAs($user)->postJson('/api/v1/whatsapp/templates', [
            'name' => 'Welcome', 'category' => 'greeting',
            'content' => 'Hello {{customer_name}}, welcome!', 'variables' => ['customer_name' => 'Customer name'],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

        $this->actingAs($user)->postJson('/api/v1/whatsapp/templates', ['name' => 'Reminder', 'content' => 'Pay up', 'status' => 'approved'])->assertCreated();

        $this->actingAs($user)->getJson('/api/v1/whatsapp/templates')->assertOk()->assertJsonCount(2, 'data.data');
        $this->actingAs($user)->getJson('/api/v1/whatsapp/templates?status=approved')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.name', 'Reminder');
        $this->actingAs($user)->getJson('/api/v1/whatsapp/templates?search=welcome')->assertOk()->assertJsonCount(1, 'data.data');
        $this->actingAs($user)->getJson('/api/v1/templates')->assertOk()->assertJsonCount(2, 'data.data');

        $this->actingAs($user)->putJson("/api/v1/whatsapp/templates/{$created['id']}", ['status' => 'approved', 'name' => 'Welcome v2'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.name', 'Welcome v2');

        // Duplicate names are rejected within a business but allowed across businesses.
        $this->actingAs($user)->postJson('/api/v1/whatsapp/templates', ['name' => 'Reminder', 'content' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('name');
        [$otherUser] = $this->membership('Other');
        $this->actingAs($otherUser)->postJson('/api/v1/whatsapp/templates', ['name' => 'Reminder', 'content' => 'x'])->assertCreated();

        $this->actingAs($user)->deleteJson("/api/v1/whatsapp/templates/{$created['id']}")->assertOk();
        $this->assertDatabaseMissing('message_templates', ['id' => $created['id']]);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'template.deleted']);
    }

    public function test_templates_are_tenant_isolated(): void
    {
        [$userA, $businessA] = $this->membership('A');
        [$userB] = $this->membership('B');
        $template = $this->templateFor($businessA);

        $this->actingAs($userB)->getJson("/api/v1/whatsapp/templates/{$template->id}")->assertNotFound();
        $this->actingAs($userB)->putJson("/api/v1/whatsapp/templates/{$template->id}", ['name' => 'x'])->assertNotFound();
        $this->actingAs($userA)->getJson("/api/v1/whatsapp/templates/{$template->id}")->assertOk();
    }

    // ------------------------------------------------------------- sending

    public function test_message_is_queued_and_delivered_through_cloud_api(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business, ['phone' => '+234 803 000 0001']);

        $response = $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id, 'body' => 'Hello there',
        ])->assertCreated()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.direction', 'outbound');

        $messageId = $response->json('data.id');
        Queue::assertPushed(SendWhatsAppMessage::class, fn ($job) => $job->messageId === $messageId);
        $this->assertDatabaseHas('conversations', ['business_id' => $business->id, 'customer_id' => $customer->id, 'status' => 'open']);

        // Run the job against a faked Cloud API.
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.123']]], 200)]);
        (new SendWhatsAppMessage($messageId))->handle(app(\App\Services\WhatsAppCloudApi::class), app(\App\Services\NotificationService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/phone-1/messages')
            && $request['to'] === '2348030000001'
            && str_contains($request['text']['body'], 'Hello there')
            && str_contains($request['text']['body'], $business->name)
            && $request->hasHeader('Authorization', 'Bearer secret-token'));

        $this->assertDatabaseHas('messages', ['id' => $messageId, 'status' => 'sent', 'whatsapp_message_id' => 'wamid.123']);
    }

    public function test_inbox_free_text_accepts_content_alias_with_conversation_id(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business);

        $conversation = $this->actingAs($user)->postJson('/api/v1/conversations', [
            'customer_id' => $customer->id,
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson('/api/v1/messages', [
            'conversation_id' => $conversation['id'],
            'customer_id' => $customer->id,
            'content' => 'Hello from the inbox',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.type', 'text');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation['id'],
            'customer_id' => $customer->id,
            'type' => 'text',
        ]);
        $this->assertStringContainsString('Hello from the inbox', (string) \App\Models\Message::withoutGlobalScopes()->latest('id')->value('body'));
    }

    public function test_inbox_free_text_inside_service_window_stays_session_text(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business);
        $this->templateFor($business, [
            'name' => 'flowcrm_custom_message',
            'whatsapp_template_id' => 'flowcrm_custom_message',
            'category' => 'utility',
            'content' => 'Hello {{customer_name}}, {{business_name}} sent you a message: {{message}}.',
        ]);

        $conversation = $this->actingAs($user)->postJson('/api/v1/conversations', [
            'customer_id' => $customer->id,
        ])->assertCreated()->json('data');

        $this->asTenant($business->id, fn () => \App\Models\Message::create([
            'conversation_id' => $conversation['id'],
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Hi',
            'status' => 'delivered',
        ]));

        $this->actingAs($user)->postJson('/api/v1/messages', [
            'conversation_id' => $conversation['id'],
            'content' => 'Thanks, I will send the invoice',
        ])->assertCreated()->assertJsonPath('data.type', 'text');
    }

    public function test_inbox_free_text_is_sent_as_custom_template(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business, ['name' => 'Amaka', 'phone' => '+234 803 000 0001']);
        $this->templateFor($business, [
            'name' => 'flowcrm_custom_message',
            'whatsapp_template_id' => 'flowcrm_custom_message',
            'category' => 'utility',
            'content' => 'Hello {{customer_name}}, {{business_name}} sent you a message: {{message}}. Reply to this message if you have any questions.',
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'content' => 'Please call me back',
        ])->assertCreated();

        $this->assertSame('template', $response->json('data.type'));
        $this->assertStringContainsString('Please call me back', (string) $response->json('data.body'));

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.custom.1']]], 200)]);
        (new SendWhatsAppMessage((int) $response->json('data.id')))->handle(
            app(\App\Services\WhatsAppCloudApi::class),
            app(\App\Services\NotificationService::class),
        );

        Http::assertSent(fn ($request) => $request['type'] === 'template'
            && $request['template']['name'] === 'flowcrm_custom_message'
            && collect($request['template']['components'][0]['parameters'] ?? [])->contains(fn ($p) => ($p['text'] ?? '') === 'Please call me back'));
    }

    public function test_cloud_api_failure_marks_message_failed_and_notifies(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business);

        $messageId = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Hi'])->json('data.id');

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 401)]);
        (new SendWhatsAppMessage($messageId))->handle(app(\App\Services\WhatsAppCloudApi::class), app(\App\Services\NotificationService::class));

        $this->assertDatabaseHas('messages', ['id' => $messageId, 'status' => 'failed', 'error_message' => 'Invalid OAuth access token']);
        $this->assertDatabaseHas('whatsapp_connections', ['business_id' => $business->id, 'status' => 'error']);
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'message_failed']);
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'whatsapp_connection_failure']);

        // A failed message can be retried, which re-queues it.
        $this->actingAs($user)->postJson("/api/v1/messages/{$messageId}/retry")->assertOk()->assertJsonPath('data.status', 'queued');
        $this->actingAs($user)->postJson("/api/v1/messages/{$messageId}/retry")->assertUnprocessable();
    }

    public function test_sending_requires_a_whatsapp_connection(): void
    {
        [$user, $business] = $this->membership();
        $customer = $this->customerFor($business);

        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Hi'])
            ->assertUnprocessable()->assertJsonValidationErrors('connection');
    }

    public function test_template_message_renders_variables_and_requires_approval(): void
    {
        Queue::fake();
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business, ['name' => 'Amaka']);
        $approved = $this->templateFor($business, ['content' => 'Hi {{customer_name}}, code {{code}}.']);
        $draft = $this->templateFor($business, ['status' => 'draft']);

        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'template_id' => $approved->id, 'template_variables' => ['code' => '42']])
            ->assertCreated()->assertJsonPath('data.body', "Hi Amaka, code 42.\n\nfrom {$business->name}")->assertJsonPath('data.template_id', $approved->id);

        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'template_id' => $draft->id])
            ->assertUnprocessable()->assertJsonValidationErrors('template_id');
    }

    public function test_message_signature_includes_business_phone(): void
    {
        Queue::fake();
        [$user, $business] = $this->membership();
        $business->update(['phone' => '+234 801 234 5678']);
        $this->connectionFor($business);
        $customer = $this->customerFor($business, ['name' => 'Amaka']);
        $template = $this->templateFor($business, ['content' => 'Hi {{customer_name}}.']);

        $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'template_id' => $template->id,
        ])->assertCreated()->assertJsonPath(
            'data.body',
            "Hi Amaka.\n\nfrom {$business->name}\n+234 801 234 5678",
        );
    }

    public function test_template_message_auto_populates_invoice_and_payment_variables(): void
    {
        Queue::fake();
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business, ['name' => 'Israel']);

        // Create an invoice for this customer
        $invoice = \App\Tenancy\TenantManager::runAs($business->id, function () use ($customer) {
            $inv = new \App\Models\Invoice([
                'customer_id' => $customer->id,
                'invoice_number' => 'INV-2026-001',
                'status' => 'sent',
                'issue_date' => now(),
                'due_date' => now()->addDays(7),
            ]);
            $inv->total = 15000;
            $inv->subtotal = 15000;
            $inv->balance_due = 15000;
            $inv->save();
            return $inv;
        });

        $paymentTpl = $this->templateFor($business, [
            'name' => 'flowcrm_payment_received',
            'whatsapp_template_id' => 'flowcrm_payment_received',
            'content' => 'Hello {{customer_name}}, {{business_name}} has received your payment of {{amount}} for invoice {{invoice_number}}. Thank you for your business.',
        ]);

        // Sending without explicitly passing template_variables or invoice_id auto-links customer's invoice
        $response = $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'template_id' => $paymentTpl->id,
        ])->assertCreated();

        $body = $response->json('data.body');
        $this->assertStringContainsString('INV-2026-001', $body);
        $this->assertStringContainsString('15,000.00', $body);
        $this->assertStringContainsString('Israel', $body);
        $this->assertStringContainsString($business->name, $body);

        // Also test retrying a failed message that had empty variables: it re-populates the body!
        $failedMessage = \App\Tenancy\TenantManager::runAs($business->id, function () use ($business, $customer, $paymentTpl) {
            return \App\Models\Message::create([
                'business_id' => $business->id,
                'customer_id' => $customer->id,
                'conversation_id' => $customer->conversations()->first()->id,
                'direction' => 'outbound',
                'type' => 'template',
                'template_id' => $paymentTpl->id,
                'billing_category' => 'utility',
                'billing_source' => 'included',
                'unit_price' => 0,
                'body' => 'Hello Israel, ' . $business->name . ' has received your payment of  for invoice . Thank you for your business.',
                'status' => 'failed',
                'error_message' => '(#131008) Required parameter is missing',
                'metadata' => ['template_variables' => []],
            ]);
        });

        $retryResponse = $this->actingAs($user)->postJson("/api/v1/messages/{$failedMessage->id}/retry")->assertOk();
        $retriedBody = $retryResponse->json('data.body');
        $this->assertStringContainsString('INV-2026-001', $retriedBody);
        $this->assertStringContainsString('15,000.00', $retriedBody);
    }

    public function test_overdue_template_uses_calendar_days_past_due(): void
    {
        Queue::fake();
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business, ['name' => 'Adewumi Israel']);
        $this->invoiceFor($business, $customer, $user, 5000, [
            'invoice_number' => 'INV-2026-000001',
            'due_date' => today()->subDays(11)->toDateString(),
            'status' => 'partially_paid',
        ]);

        $template = $this->templateFor($business, [
            'name' => 'flowcrm_payment_overdue',
            'whatsapp_template_id' => 'flowcrm_payment_overdue',
            'content' => 'Hello {{customer_name}}, invoice {{invoice_number}} from {{business_name}} is {{days_overdue}} day(s) overdue with a balance of {{balance_due}}. Please make payment or reply to arrange one.',
        ]);

        $body = $this->actingAs($user)->postJson('/api/v1/messages', [
            'customer_id' => $customer->id,
            'template_id' => $template->id,
        ])->assertCreated()->json('data.body');

        $this->assertStringContainsString('is 11 day(s) overdue', $body);
        $this->assertStringContainsString('INV-2026-000001', $body);
    }

    // -------------------------------------------------------- conversations

    public function test_conversations_list_show_update_and_message_listing(): void
    {
        Queue::fake();
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business, ['name' => 'John Doe']);
        $other = $this->customerFor($business, ['name' => 'Jane']);

        $conversation = $this->actingAs($user)->postJson('/api/v1/conversations', ['customer_id' => $customer->id])->assertCreated()->json('data');
        $this->actingAs($user)->postJson('/api/v1/conversations', ['customer_id' => $customer->id])->assertOk()->assertJsonPath('data.id', $conversation['id']);
        $this->actingAs($user)->postJson('/api/v1/messages', ['conversation_id' => $conversation['id'], 'body' => 'First'])->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $other->id, 'body' => 'Second'])->assertCreated();

        $this->actingAs($user)->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(2, 'data.data');
        $this->actingAs($user)->getJson('/api/v1/conversations?search=john')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.customer.name', 'John Doe');
        $this->actingAs($user)->getJson("/api/v1/conversations/{$conversation['id']}")->assertOk()->assertJsonCount(1, 'data.messages');
        $this->actingAs($user)->getJson("/api/v1/conversations/{$conversation['id']}/messages?direction=outbound")->assertOk()->assertJsonCount(1, 'data.data');
        $this->actingAs($user)->getJson("/api/v1/messages?conversation_id={$conversation['id']}")->assertOk()->assertJsonCount(1, 'data.data');

        $this->actingAs($user)->putJson("/api/v1/conversations/{$conversation['id']}", ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actingAs($user)->putJson("/api/v1/conversations/{$conversation['id']}", ['status' => 'bogus'])->assertUnprocessable();
        $this->actingAs($user)->getJson('/api/v1/conversations?status=closed')->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_conversations_and_messages_are_tenant_isolated(): void
    {
        Queue::fake();
        [$userA, $businessA] = $this->membership('A');
        [$userB] = $this->membership('B');
        $this->connectionFor($businessA);
        $customer = $this->customerFor($businessA);
        $message = $this->actingAs($userA)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Private'])->json('data');

        $this->actingAs($userB)->getJson("/api/v1/conversations/{$message['conversation_id']}")->assertNotFound();
        $this->actingAs($userB)->getJson("/api/v1/messages/{$message['id']}")->assertNotFound();
        $this->actingAs($userB)->getJson('/api/v1/messages')->assertOk()->assertJsonCount(0, 'data.data');
        $this->actingAs($userB)->postJson('/api/v1/messages', ['conversation_id' => $message['conversation_id'], 'body' => 'Hijack'])->assertUnprocessable();
    }

    // -------------------------------------------------------------- webhooks

    public function test_webhook_verification_signature_and_queueing(): void
    {
        config(['services.whatsapp.verify_token' => 'verify-me', 'services.whatsapp.app_secret' => 'app-secret']);

        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=challenge')->assertOk()->assertSee('challenge');
        $this->get('/api/v1/webhooks/whatsapp?hub_verify_token=wrong&hub_challenge=challenge')->assertForbidden();

        Queue::fake();
        $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(401);
        Queue::assertNothingPushed();

        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'app-secret');
        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature], $payload)->assertStatus(200);
    }

    public function test_http_webhook_stores_a_customer_reply_on_the_existing_conversation(): void
    {
        config(['services.whatsapp.app_secret' => 'app-secret']);
        [$user, $business] = $this->membership();
        $this->connectionFor($business, 'phone-9');
        $customer = $this->customerFor($business, ['phone' => '+234 816 674 0794']);
        $this->actingAs($user)->postJson('/api/v1/conversations', ['customer_id' => $customer->id])->assertCreated();

        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => 'phone-9'],
            'messages' => [['from' => '2348166740794', 'id' => 'wamid.reply.1', 'type' => 'text', 'text' => ['body' => 'Yes I got it']]],
        ]]]]]], JSON_THROW_ON_ERROR);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'app-secret');

        $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body)->assertOk();

        $this->assertDatabaseHas('messages', [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'whatsapp_message_id' => 'wamid.reply.1',
            'direction' => 'inbound',
            'body' => 'Yes I got it',
        ]);
    }

    public function test_inbound_reply_reuses_open_conversation_for_local_phone_format(): void
    {
        [$user, $business] = $this->membership();
        $this->connectionFor($business, 'phone-old');
        $connection = $this->connectionFor($business, 'phone-9');
        $customer = $this->customerFor($business, ['phone' => '08166740794']);
        $previous = \App\Models\WhatsAppConnection::withoutGlobalScopes()->where('phone_number_id', 'phone-old')->firstOrFail();
        $conversation = $this->asTenant($business->id, fn () => \App\Models\Conversation::create([
            'customer_id' => $customer->id,
            'whatsapp_connection_id' => $previous->id,
            'status' => 'open',
        ]));

        $message = $this->asTenant($business->id, fn () => app(\App\Services\WhatsAppService::class)->handleIncoming($connection, [
            'from' => '2348166740794',
            'id' => 'wamid.reply.split',
            'type' => 'text',
            'text' => ['body' => 'I will pay tomorrow'],
        ]));

        $this->assertNotNull($message);
        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame($customer->id, $message->customer_id);
        $this->assertSame('I will pay tomorrow', $message->body);
        $this->assertSame(1, \App\Models\Conversation::withoutGlobalScopes()->where('customer_id', $customer->id)->count());
        $this->assertSame($connection->id, \App\Models\Conversation::withoutGlobalScopes()->find($conversation->id)?->whatsapp_connection_id);
    }

    public function test_webhook_processing_creates_inbound_messages_and_updates_statuses_idempotently(): void
    {
        Queue::fake();
        [$user, $business] = $this->membership();
        $this->connectionFor($business, 'phone-9');
        $customer = $this->customerFor($business, ['phone' => '+234 803 000 0009']);
        $outbound = $this->actingAs($user)->postJson('/api/v1/messages', ['customer_id' => $customer->id, 'body' => 'Hi'])->json('data');
        Message::withoutGlobalScopes()->whereKey($outbound['id'])->update(['status' => 'sent', 'whatsapp_message_id' => 'wamid.out']);

        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'phone-9'],
            'messages' => [['from' => '2348030000009', 'id' => 'wamid.in', 'type' => 'text', 'text' => ['body' => 'Paid today']]],
            'statuses' => [['id' => 'wamid.out', 'status' => 'read']],
        ]]]]]];

        (new ProcessWhatsAppWebhook($payload))->handle(app(\App\Services\WhatsAppService::class), app(\App\Services\WhatsAppOnboardingService::class));
        (new ProcessWhatsAppWebhook($payload))->handle(app(\App\Services\WhatsAppService::class), app(\App\Services\WhatsAppOnboardingService::class));

        $this->assertSame(1, Message::withoutGlobalScopes()->where('whatsapp_message_id', 'wamid.in')->count());
        $this->assertDatabaseHas('messages', ['whatsapp_message_id' => 'wamid.in', 'direction' => 'inbound', 'customer_id' => $customer->id, 'body' => 'Paid today', 'business_id' => $business->id]);
        $this->assertDatabaseHas('messages', ['id' => $outbound['id'], 'status' => 'read']);

        // A late "delivered" status must not downgrade "read"; "failed" is recorded with its reason.
        $late = ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => 'phone-9'], 'statuses' => [['id' => 'wamid.out', 'status' => 'delivered']]]]]]]];
        (new ProcessWhatsAppWebhook($late))->handle(app(\App\Services\WhatsAppService::class), app(\App\Services\WhatsAppOnboardingService::class));
        $this->assertDatabaseHas('messages', ['id' => $outbound['id'], 'status' => 'read']);

        $failed = ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => 'phone-9'], 'statuses' => [['id' => 'wamid.out', 'status' => 'failed', 'errors' => [['title' => 'Number not on WhatsApp']]]]]]]]]];
        (new ProcessWhatsAppWebhook($failed))->handle(app(\App\Services\WhatsAppService::class), app(\App\Services\WhatsAppOnboardingService::class));
        $this->assertDatabaseHas('messages', ['id' => $outbound['id'], 'status' => 'failed', 'error_message' => 'Number not on WhatsApp']);
        $this->assertSame(1, Notification::withoutGlobalScopes()->where('business_id', $business->id)->where('type', 'message_failed')->count());

        // Unknown phone ids are ignored.
        (new ProcessWhatsAppWebhook(['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => 'nope'], 'messages' => [['from' => '1', 'id' => 'x']]]]]]]]))->handle(app(\App\Services\WhatsAppService::class), app(\App\Services\WhatsAppOnboardingService::class));
        $this->assertDatabaseMissing('messages', ['whatsapp_message_id' => 'x']);
    }

    public function test_connection_management_and_role_restrictions(): void
    {
        [$owner, $business] = $this->membership();
        $manager = \App\Models\User::factory()->create();
        $business->users()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);

        $connection = $this->actingAs($owner)->postJson('/api/v1/whatsapp/connections', [
            'phone_number_id' => 'phone-1', 'business_account_id' => 'waba', 'access_token' => 'super-secret-token',
        ])->assertCreated()->assertJsonMissing(['access_token_encrypted' => 'super-secret-token'])->json('data');
        $this->assertArrayNotHasKey('access_token_encrypted', $connection);
        $this->assertDatabaseMissing('whatsapp_connections', ['access_token_encrypted' => 'super-secret-token']);

        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'phone-1'], 200)]);
        $this->actingAs($manager)->postJson("/api/v1/whatsapp/connections/{$connection['id']}/verify")->assertOk()->assertJsonPath('data.status', 'active');

        $this->actingAs($owner)->deleteJson("/api/v1/whatsapp/connections/{$connection['id']}")->assertOk();
        $this->assertDatabaseHas('whatsapp_connections', ['id' => $connection['id'], 'status' => 'disconnected']);
        $this->assertDatabaseHas('audit_logs', ['business_id' => $business->id, 'action' => 'whatsapp_connection.disconnected']);
    }
}
