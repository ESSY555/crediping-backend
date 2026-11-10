<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\ReminderLog;
use App\Models\ScheduledMessage;
use App\Services\BroadcastService;
use App\Services\NotificationService;
use App\Services\QuotaService;
use App\Services\WhatsAppApiException;
use App\Services\WhatsAppCloudApi;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one queued outbound message through the WhatsApp Cloud API.
 * Safe to retry: respects tries and backoff, handles transient network/429 errors.
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $messageId)
    {
        $this->onQueue('whatsapp');
    }

    public function handle(WhatsAppCloudApi $api, NotificationService $notifications): void
    {
        $message = Message::withoutGlobalScopes()->find($this->messageId);
        if (!$message) {
            return;
        }

        TenantManager::runAs($message->business_id, function () use ($message, $api, $notifications) {
            $message->loadMissing(['template', 'conversation.connection', 'customer']);

            // Already delivered or sent to WhatsApp
            if (in_array($message->status, ['sent', 'delivered', 'read'], true) || $message->direction !== 'outbound') {
                return;
            }

            // If a previous attempt already obtained a WhatsApp message ID, avoid resending
            if (!empty($message->whatsapp_message_id)) {
                $message->update(['status' => 'sent']);
                return;
            }

            // On initial attempt (attempts <= 1), status must be 'queued'.
            // On subsequent attempts (attempts > 1), status may already be 'sending'.
            $allowedStatuses = $this->attempts() > 1 ? ['queued', 'sending'] : ['queued'];
            if (!in_array($message->status, $allowedStatuses, true)) {
                return;
            }

            $connection = $message->conversation?->connection;
            $to = $message->customer?->whatsappNumber();

            if (!$connection || !$connection->isActive()) {
                $this->fail($message, 'WhatsApp connection is not active.', $notifications);
                return;
            }
            if (!$to) {
                $this->fail($message, 'Customer has no valid WhatsApp phone number.', $notifications);
                return;
            }

            // Atomically mark sending
            if ($message->status !== 'sending') {
                $affected = Message::whereKey($message->id)
                    ->where('status', 'queued')
                    ->update(['status' => 'sending']);

                if ($affected === 0 && $this->attempts() === 1) {
                    return; // Another worker claimed this message
                }
                $message->status = 'sending';
            }

            try {
                $waId = $this->deliver($api, $connection, $to, $message);
                $message->update([
                    'status' => 'sent',
                    'whatsapp_message_id' => $waId,
                    'sent_at' => now(),
                    'error_message' => null,
                ]);
                $connection->update(['last_verified_at' => now()]);

                if (!empty($message->metadata['scheduled_message_id'])) {
                    ScheduledMessage::withoutGlobalScopes()
                        ->whereKey($message->metadata['scheduled_message_id'])
                        ->update(['status' => 'sent', 'sent_at' => now(), 'error_message' => null]);
                }
                if (!empty($message->metadata['reminder_rule_id'])) {
                    ReminderLog::withoutGlobalScopes()
                        ->where('message_id', $message->id)
                        ->update(['status' => 'sent', 'sent_at' => now()]);
                }
            } catch (WhatsAppApiException $e) {
                if ($e->isCredentialError()) {
                    $connection->update(['status' => 'error']);
                    $notifications->connectionFailure($connection, $e->getMessage());
                    $this->fail($message, $e->getMessage(), $notifications);
                    return;
                }

                if ($e->isTransient()) {
                    if ($this->attempts() < $this->tries) {
                        Log::warning("Transient WhatsApp send failure for message {$message->id} (attempt {$this->attempts()}): {$e->getMessage()}");
                        Message::whereKey($message->id)->update(['status' => 'queued']);
                        $message->status = 'queued';
                        $delay = $e->retryAfter ?? ($this->backoff * $this->attempts());
                        $this->release($delay);
                        return;
                    }
                }

                $this->fail($message, $e->getMessage(), $notifications);
            } catch (Throwable $e) {
                if ($this->attempts() < $this->tries) {
                    Log::warning("Network/system error sending WhatsApp message {$message->id} (attempt {$this->attempts()}): {$e->getMessage()}");
                    Message::whereKey($message->id)->update(['status' => 'queued']);
                    $message->status = 'queued';
                    $delay = $this->backoff * $this->attempts();
                    $this->release($delay);
                    return;
                }

                Log::error("WhatsApp send failed for message {$message->id} after {$this->attempts()} attempts: {$e->getMessage()}");
                $this->fail($message, 'Network error while contacting WhatsApp.', $notifications);
            }
        });
    }

    /**
     * Send through Cloud API. Inbox session text that Meta rejects for the
     * 24h window is retried once as the platform custom-message template.
     */
    private function deliver(WhatsAppCloudApi $api, \App\Models\WhatsAppConnection $connection, string $to, Message $message): string
    {
        try {
            return $api->send($connection, $to, $message);
        } catch (WhatsAppApiException $e) {
            if (! $this->isSessionWindowError($e) || ($message->metadata['source'] ?? 'manual') === 'broadcast' || $message->type === 'template') {
                throw $e;
            }

            $template = \App\Models\MessageTemplate::query()
                ->where('name', 'flowcrm_custom_message')
                ->where('status', 'approved')
                ->whereNotNull('whatsapp_template_id')
                ->first();
            if (! $template) {
                throw $e;
            }

            $typed = trim((string) preg_replace('/\n\n— .+$/u', '', (string) $message->body));
            $variables = array_merge(
                app(\App\Services\WhatsAppService::class)->defaultVariables($message->customer, null),
                ['message' => \App\Services\WhatsAppCloudApi::sanitizeTemplateParam($typed !== '' ? $typed : (string) $message->body)],
            );
            $message->update([
                'type' => 'template',
                'template_id' => $template->id,
                'metadata' => array_merge($message->metadata ?? [], [
                    'template_variables' => $variables,
                    'resent_as_template' => true,
                ]),
            ]);
            $message->setRelation('template', $template);

            Log::info("Re-sending message {$message->id} as flowcrm_custom_message after session-window rejection.");

            return $api->send($connection, $to, $message);
        }
    }

    private function isSessionWindowError(WhatsAppApiException $e): bool
    {
        return $e->getCode() === 131047 || str_contains(strtolower($e->getMessage()), '24 hours');
    }

    private function fail(Message $message, string $reason, NotificationService $notifications): void
    {
        $message->update(['status' => 'failed', 'error_message' => $reason]);
        app(QuotaService::class)->recordFailed($message);
        app(\App\Services\WalletService::class)->refundMessage($message, $reason);
        $notifications->messageFailed($message);
        // Keep broadcast recipient rows and counters in step with the failure.
        app(BroadcastService::class)->syncRecipientStatus($message);

        if (!empty($message->metadata['scheduled_message_id'])) {
            ScheduledMessage::withoutGlobalScopes()
                ->whereKey($message->metadata['scheduled_message_id'])
                ->whereIn('status', ['processing', 'scheduled'])
                ->update(['status' => 'failed', 'failed_at' => now(), 'error_message' => $reason]);
        }

        if (!empty($message->metadata['reminder_rule_id'])) {
            ReminderLog::withoutGlobalScopes()
                ->where('message_id', $message->id)
                ->update(['status' => 'failed']);
        }
    }
}
