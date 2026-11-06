<?php

namespace App\Jobs;

use App\Models\WhatsAppConnection;
use App\Services\WhatsAppOnboardingService;
use App\Services\WhatsAppService;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Processes one WhatsApp webhook payload. Each entry/change is resolved to a
 * tenant — message events through the receiving phone number id, account
 * level events (templates, quality, restrictions) through the WABA id — so
 * cross-tenant leakage is impossible even though the webhook itself is
 * unauthenticated.
 */
class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public array $payload)
    {
        $this->onQueue('whatsapp');
    }

    public function handle(WhatsAppService $service, WhatsAppOnboardingService $onboarding): void
    {
        foreach (data_get($this->payload, 'entry', []) as $entry) {
            $wabaId = (string) data_get($entry, 'id', '');

            foreach (data_get($entry, 'changes', []) as $change) {
                $field = (string) data_get($change, 'field', 'messages');
                $value = data_get($change, 'value', []);
                if (! is_array($value)) {
                    $value = [];
                }

                if ($field === '' || $field === 'messages') {
                    $this->handleMessages($service, $value);
                    continue;
                }

                $this->handleAccountEvent($onboarding, $field, $wabaId, $value);
            }
        }
    }

    private function handleMessages(WhatsAppService $service, array $value): void
    {
        $phoneId = data_get($value, 'metadata.phone_number_id');
        if (!$phoneId) {
            return;
        }

        $connection = WhatsAppConnection::withoutGlobalScopes()->where('phone_number_id', $phoneId)->first();
        if (!$connection) {
            Log::warning("WhatsApp webhook for unknown phone_number_id {$phoneId}");
            return;
        }

        if (!$connection->isActive()) {
            Log::info("WhatsApp webhook received for inactive connection {$connection->id} ({$phoneId})");
            TenantManager::runAs($connection->business_id, function () use ($service, $value) {
                foreach (data_get($value, 'statuses', []) as $status) {
                    $service->applyStatus(
                        (string) data_get($status, 'id'),
                        (string) data_get($status, 'status'),
                        data_get($status, 'errors.0.title') ?? data_get($status, 'errors.0.message'),
                    );
                }
            });
            return;
        }

        TenantManager::runAs($connection->business_id, function () use ($service, $connection, $value) {
            foreach (data_get($value, 'messages', []) as $incoming) {
                $service->handleIncoming($connection, $incoming);
            }

            foreach (data_get($value, 'statuses', []) as $status) {
                $service->applyStatus(
                    (string) data_get($status, 'id'),
                    (string) data_get($status, 'status'),
                    data_get($status, 'errors.0.title') ?? data_get($status, 'errors.0.message'),
                );
            }
        });
    }

    private function handleAccountEvent(WhatsAppOnboardingService $onboarding, string $field, string $wabaId, array $value): void
    {
        if ($wabaId === '') {
            return;
        }

        $connections = WhatsAppConnection::withoutGlobalScopes()
            ->where('business_account_id', $wabaId)
            ->where('status', '!=', 'disconnected')
            ->get();

        if ($connections->isEmpty()) {
            Log::info("WhatsApp {$field} webhook for unknown WABA {$wabaId}");
            return;
        }

        foreach ($connections as $connection) {
            // Quality updates name the phone number; apply only to that one.
            if ($field === 'phone_number_quality_update' && !empty($value['display_phone_number'])
                && $connection->display_phone_number && $connection->display_phone_number !== $value['display_phone_number']) {
                continue;
            }

            TenantManager::runAs($connection->business_id, function () use ($onboarding, $field, $connection, $value) {
                match ($field) {
                    'message_template_status_update' => $onboarding->applyTemplateStatus($connection, $value),
                    'phone_number_quality_update' => $onboarding->applyQualityUpdate($connection, $value),
                    'account_update' => $onboarding->applyAccountUpdate($connection, $value),
                    default => Log::info("Unhandled WhatsApp webhook field {$field}"),
                };
            });
        }
    }
}
