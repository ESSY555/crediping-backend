<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\SendBroadcastMessages;
use App\Jobs\SendScheduledMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Broadcast;
use App\Models\CustomerGroup;
use App\Models\Message;
use App\Models\ReminderLog;
use App\Models\ScheduledMessage;
use App\Services\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase, CreatesTenants;

    // ------------------------------------------------------------ broadcasts

    public function test_broadcast_to_groups_and_customers_is_processed_by_queue(): void
    {
        Queue::fake([SendWhatsAppMessage::class]);
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $group = $this->asTenant($business->id, fn () => CustomerGroup::create(['name' => 'VIP']));
        $a = $this->customerFor($business, ['name' => 'A']);
        $b = $this->customerFor($business, ['name' => 'B']);
        $c = $this->customerFor($business, ['name' => 'C']);
        $this->asTenant($business->id, fn () => $a->groups()->attach($group->id, ['business_id' => $business->id]));

        $broadcast = $this->actingAs($user)->postJson('/api/v1/broadcasts', [
            'name' => 'Promo', 'content' => 'Big sale!', 'group_ids' => [$group->id], 'customer_ids' => [$b->id, $a->id],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.total_recipients', 2)->json('data');

        $this->actingAs($user)->postJson("/api/v1/broadcasts/{$broadcast['id']}/recipients", ['customer_ids' => [$c->id, $a->id]])
            ->assertOk()->assertJsonPath('data.total_recipients', 3);

        // Starting only queues the batch job; the request itself sends nothing.
        Queue::fake([SendWhatsAppMessage::class, SendBroadcastMessages::class]);
        $this->actingAs($user)->postJson("/api/v1/broadcasts/{$broadcast['id']}/start")->assertOk()->assertJsonPath('data.status', 'processing');
        Queue::assertPushed(SendBroadcastMessages::class);
        $this->assertSame(0, Message::withoutGlobalScopes()->count());

        (new SendBroadcastMessages($broadcast['id']))->handle(app(\App\Services\BroadcastService::class));

        $this->assertSame(3, Message::withoutGlobalScopes()->where('business_id', $business->id)->count());
        $this->assertDatabaseHas('broadcasts', ['id' => $broadcast['id'], 'status' => 'completed', 'sent_count' => 3, 'failed_count' => 0]);
        $this->assertDatabaseHas('broadcast_recipients', ['broadcast_id' => $broadcast['id'], 'customer_id' => $c->id, 'status' => 'sent']);
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'broadcast_completed']);

        $show = $this->actingAs($user)->getJson("/api/v1/broadcasts/{$broadcast['id']}")->assertOk();
        $show->assertJsonPath('data.stats.sent_count', 3)->assertJsonCount(3, 'data.recipients.data');
        $this->actingAs($user)->postJson("/api/v1/broadcasts/{$broadcast['id']}/recipients", ['customer_ids' => [$a->id]])->assertUnprocessable();
    }

    public function test_broadcast_validation_scheduling_and_tenant_isolation(): void
    {
        Queue::fake();
        [$user, $business] = $this->membership();
        [$otherUser, $otherBusiness] = $this->membership('Other');
        $customer = $this->customerFor($business);
        $foreign = $this->customerFor($otherBusiness);

        $this->actingAs($user)->postJson('/api/v1/broadcasts', ['name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('content');
        $this->actingAs($user)->postJson('/api/v1/broadcasts', ['name' => 'x', 'content' => 'y', 'customer_ids' => [$foreign->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('customer_ids.0');

        $broadcast = $this->actingAs($user)->postJson('/api/v1/broadcasts', [
            'name' => 'Later', 'content' => 'y', 'customer_ids' => [$customer->id], 'scheduled_at' => now()->addDay()->toDateTimeString(),
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data');

        $this->actingAs($otherUser)->getJson("/api/v1/broadcasts/{$broadcast['id']}")->assertNotFound();
        $this->actingAs($otherUser)->postJson("/api/v1/broadcasts/{$broadcast['id']}/start")->assertNotFound();

        $this->actingAs($user)->postJson("/api/v1/broadcasts/{$broadcast['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'failed');
        $this->actingAs($user)->deleteJson("/api/v1/broadcasts/{$broadcast['id']}")->assertUnprocessable();

        // The scheduler claims due broadcasts and dispatches the job.
        Broadcast::withoutGlobalScopes()->whereKey($broadcast['id'])->update(['status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);
        $this->artisan('messages:dispatch-due')->assertSuccessful();
        Queue::assertPushed(SendBroadcastMessages::class, fn ($job) => $job->broadcastId === $broadcast['id']);
        $this->assertDatabaseHas('broadcasts', ['id' => $broadcast['id'], 'status' => 'processing']);
    }

    // ----------------------------------------------------- scheduled messages

    public function test_scheduled_message_lifecycle(): void
    {
        Queue::fake();
        [$user, $business] = $this->membership();
        [$otherUser] = $this->membership('Other');
        $this->connectionFor($business);
        $customer = $this->customerFor($business);

        $this->actingAs($user)->postJson('/api/v1/scheduled-messages', ['customer_id' => $customer->id, 'content' => 'x', 'scheduled_at' => now()->subHour()->toDateTimeString()])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');

        $scheduled = $this->actingAs($user)->postJson('/api/v1/scheduled-messages', [
            'customer_id' => $customer->id, 'content' => 'See you tomorrow', 'scheduled_at' => now()->addHour()->toDateTimeString(),
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data');

        $this->actingAs($otherUser)->getJson("/api/v1/scheduled-messages/{$scheduled['id']}")->assertNotFound();
        $this->actingAs($user)->putJson("/api/v1/scheduled-messages/{$scheduled['id']}", ['content' => 'Updated'])->assertOk()->assertJsonPath('data.content', 'Updated');
        $this->actingAs($user)->getJson('/api/v1/scheduled-messages/stats')->assertOk()->assertJsonPath('data.scheduled', 1);

        // Nothing is dispatched before the scheduled time.
        $this->artisan('messages:dispatch-due')->assertSuccessful();
        Queue::assertNotPushed(SendScheduledMessage::class);

        ScheduledMessage::withoutGlobalScopes()->whereKey($scheduled['id'])->update(['scheduled_at' => now()->subMinute()]);
        $this->artisan('messages:dispatch-due')->assertSuccessful();
        Queue::assertPushed(SendScheduledMessage::class, 1);
        $this->assertDatabaseHas('scheduled_messages', ['id' => $scheduled['id'], 'status' => 'processing']);

        (new SendScheduledMessage($scheduled['id']))->handle(app(\App\Services\ScheduledMessageService::class));
        $this->assertDatabaseHas('scheduled_messages', ['id' => $scheduled['id'], 'status' => 'sent']);
        $this->assertDatabaseHas('messages', ['business_id' => $business->id, 'customer_id' => $customer->id, 'body' => "Updated\n\nfrom {$business->name}", 'status' => 'queued']);
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'scheduled_message_sent']);

        $this->actingAs($user)->postJson("/api/v1/scheduled-messages/{$scheduled['id']}/cancel")->assertUnprocessable();

        $pending = $this->actingAs($user)->postJson('/api/v1/scheduled-messages', [
            'customer_id' => $customer->id, 'content' => 'Cancel me', 'scheduled_at' => now()->addHour()->toDateTimeString(),
        ])->json('data');
        $this->actingAs($user)->postJson("/api/v1/scheduled-messages/{$pending['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_scheduled_message_without_connection_fails_gracefully(): void
    {
        Queue::fake();
        [$user, $business] = $this->membership();
        $customer = $this->customerFor($business);
        $scheduled = $this->asTenant($business->id, fn () => ScheduledMessage::create([
            'customer_id' => $customer->id, 'content' => 'x', 'scheduled_at' => now()->subMinute(), 'status' => 'scheduled',
        ]));

        (new SendScheduledMessage($scheduled->id))->handle(app(\App\Services\ScheduledMessageService::class));

        $this->assertDatabaseHas('scheduled_messages', ['id' => $scheduled->id, 'status' => 'failed']);
        $this->assertStringContainsString('Connect a WhatsApp', $scheduled->fresh()->error_message);
    }

    // ------------------------------------------------------------- reminders

    public function test_reminder_rules_select_invoices_and_never_duplicate(): void
    {
        Queue::fake();
        [$user, $business] = $this->membership();
        $this->connectionFor($business);
        $customer = $this->customerFor($business);
        $template = $this->templateFor($business);

        $dueIn3 = $this->invoiceFor($business, $customer, $user, 100, ['due_date' => today()->addDays(3)->toDateString()]);
        $dueToday = $this->invoiceFor($business, $customer, $user, 100, ['due_date' => today()->toDateString()]);
        $overdue7 = $this->invoiceFor($business, $customer, $user, 100, ['due_date' => today()->subDays(7)->toDateString()]);
        $paid = $this->invoiceFor($business, $customer, $user, 100, ['due_date' => today()->addDays(3)->toDateString()]);
        $this->asTenant($business->id, fn () => app(\App\Services\PaymentService::class)->record(['invoice_id' => $paid->id, 'amount' => 100, 'payment_method' => 'cash'], $user->id));

        $rules = [
            ['name' => '3 days before', 'trigger_type' => 'before_due', 'days_offset' => 3],
            ['name' => 'Due today', 'trigger_type' => 'on_due'],
            ['name' => '7 days overdue', 'trigger_type' => 'after_due', 'days_offset' => 7],
        ];
        foreach ($rules as $rule) {
            $this->actingAs($user)->postJson('/api/v1/reminders', $rule + ['template_id' => $template->id])->assertCreated();
        }
        $this->actingAs($user)->postJson('/api/v1/reminders', ['name' => 'x', 'trigger_type' => 'nope', 'template_id' => $template->id])->assertUnprocessable();

        $this->actingAs($user)->getJson('/api/v1/reminders/preview')->assertOk()->assertJsonCount(3, 'data');

        $summary = $this->actingAs($user)->postJson('/api/v1/reminders/run')->assertOk()->json('data');
        $this->assertSame(['rules' => 3, 'sent' => 3, 'failed' => 0, 'skipped' => 0], $summary);

        foreach ([$dueIn3, $dueToday, $overdue7] as $invoice) {
            $this->assertDatabaseHas('reminder_logs', ['invoice_id' => $invoice->id, 'status' => 'sent']);
        }
        $this->assertDatabaseMissing('reminder_logs', ['invoice_id' => $paid->id]);
        $this->assertDatabaseHas('messages', ['customer_id' => $customer->id, 'template_id' => $template->id, 'body' => "Hello {$customer->name}, invoice {$overdue7->invoice_number} balance USD 100.00."]);

        // Running again the same day skips everything already reminded.
        $again = $this->asTenant($business->id, fn () => app(ReminderService::class)->run());
        $this->assertSame(0, $again['sent']);
        $this->assertSame(3, $again['skipped']);
        $this->assertSame(3, ReminderLog::withoutGlobalScopes()->count());

        $this->actingAs($user)->getJson('/api/v1/reminders/logs')->assertOk()->assertJsonCount(3, 'data.data');
        $this->artisan('reminders:process --sync')->assertSuccessful();
        $this->assertSame(3, ReminderLog::withoutGlobalScopes()->count());
    }

    public function test_reminder_rules_are_tenant_isolated_and_editable(): void
    {
        [$user, $business] = $this->membership();
        [$otherUser, $otherBusiness] = $this->membership('Other');
        $template = $this->templateFor($business);
        $foreignTemplate = $this->templateFor($otherBusiness);

        $this->actingAs($user)->postJson('/api/v1/reminders', ['name' => 'x', 'trigger_type' => 'on_due', 'template_id' => $foreignTemplate->id])
            ->assertUnprocessable()->assertJsonValidationErrors('template_id');

        $rule = $this->actingAs($user)->postJson('/api/v1/reminders', ['name' => 'x', 'trigger_type' => 'before_due', 'days_offset' => 2, 'template_id' => $template->id])->json('data');
        $this->actingAs($otherUser)->getJson("/api/v1/reminders/{$rule['id']}")->assertNotFound();
        $this->actingAs($otherUser)->getJson('/api/v1/reminders')->assertOk()->assertJsonCount(0, 'data.data');

        $this->actingAs($user)->putJson("/api/v1/reminders/{$rule['id']}", ['enabled' => false, 'days_offset' => 5])->assertOk()
            ->assertJsonPath('data.enabled', false)->assertJsonPath('data.days_offset', 5);
        $this->actingAs($user)->deleteJson("/api/v1/whatsapp/templates/{$template->id}")->assertUnprocessable();
        $this->actingAs($user)->deleteJson("/api/v1/reminders/{$rule['id']}")->assertOk();
        $this->assertDatabaseMissing('reminder_rules', ['id' => $rule['id']]);
    }

    public function test_overdue_command_flips_status_and_notifies(): void
    {
        [$user, $business] = $this->membership();
        $customer = $this->customerFor($business);
        $invoice = $this->invoiceFor($business, $customer, $user, 50, ['due_date' => today()->addDay()->toDateString()]);
        $this->assertSame('sent', $invoice->status);

        \App\Models\Invoice::withoutGlobalScopes()->whereKey($invoice->id)->update(['due_date' => today()->subDay()]);
        $this->artisan('invoices:mark-overdue')->assertSuccessful();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'overdue']);
        $this->assertDatabaseHas('notifications', ['business_id' => $business->id, 'type' => 'invoice_overdue']);
    }
}
