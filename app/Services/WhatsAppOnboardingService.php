<?php

namespace App\Services;

use App\Models\Business;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Connect WhatsApp": turns the code from Meta's Embedded Signup popup into
 * a working connection. The business only ever saw a phone number and an
 * OTP; everything below happens on the server:
 *
 *   1. code → business token (scoped to the WABA the user shared)
 *   2. find the phone number on that WABA
 *   3. subscribe the WABA to our webhook
 *   4. register the number for Cloud API sending
 *   5. bill the WABA to the platform's credit line
 *   6. store the connection
 *   7. submit the platform's standard templates to the WABA
 *
 * Steps 5 and 7 are best-effort: a failure is recorded on the connection and
 * reported, but sending still works and the step can be retried.
 */
class WhatsAppOnboardingService
{
    public function __construct(private WhatsAppCloudApi $api, private NotificationService $notifications)
    {
    }

    public static function isConfigured(): bool
    {
        return (bool) config('services.whatsapp.app_id')
            && (bool) config('services.whatsapp.app_secret')
            && (bool) config('services.whatsapp.embedded_signup_config_id');
    }

    /**
     * @param  array{code: string, waba_id?: string|null, phone_number_id?: string|null}  $input
     */
    public function connect(Business $business, User $user, array $input): WhatsAppConnection
    {
        if (!self::isConfigured()) {
            throw ValidationException::withMessages(['code' => ['WhatsApp connection is not available yet. Please contact support.']]);
        }

        // 1. Token
        try {
            $token = $this->api->exchangeCode($input['code']);
        } catch (WhatsAppApiException $e) {
            throw ValidationException::withMessages(['code' => ['WhatsApp did not accept the connection. Please try again.']]);
        }

        // 2. WABA + phone number
        $wabaId = $input['waba_id'] ?? null;
        if (!$wabaId) {
            $wabaId = $this->api->grantedWabaIds($token)[0] ?? null;
        }
        if (!$wabaId) {
            throw ValidationException::withMessages(['code' => ['No WhatsApp Business Account was shared. Please complete all steps in the connection window.']]);
        }

        $phone = $this->resolvePhone($token, $wabaId, $input['phone_number_id'] ?? null);
        if (!$phone) {
            throw ValidationException::withMessages(['code' => ['No phone number was found on the connected WhatsApp Business Account.']]);
        }

        $this->assertNotClaimedElsewhere($business, $phone['id']);

        // 3. Webhook (also used by retry-setup so inbound replies keep arriving)
        $this->api->subscribeApp($token, $wabaId);

        // 4. Registration (two-step PIN generated and kept for re-registration)
        $existing = WhatsAppConnection::query()->where('phone_number_id', $phone['id'])->first();
        $pin = $existing?->pin_encrypted ?: str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->api->registerPhone($token, $phone['id'], $pin);

        // 6. Store (before the best-effort steps so nothing is lost if they fail)
        $connection = WhatsAppConnection::updateOrCreate(
            ['phone_number_id' => $phone['id']],
            [
                'business_account_id' => $wabaId,
                'display_phone_number' => $phone['display_phone_number'] ?? null,
                'verified_name' => $phone['verified_name'] ?? null,
                'quality_rating' => $phone['quality_rating'] ?? null,
                'messaging_limit_tier' => $phone['messaging_limit_tier'] ?? null,
                'platform_type' => $phone['platform_type'] ?? null,
                'access_token_encrypted' => $token,
                'pin_encrypted' => $pin,
                'status' => 'active',
                'onboarding_source' => WhatsAppConnection::SOURCE_EMBEDDED,
                'connected_by' => $user->id,
                'connected_at' => now(),
                'webhook_subscribed_at' => now(),
                'last_verified_at' => now(),
                'last_error' => null,
            ],
        );

        // Any older number for this business is superseded.
        WhatsAppConnection::query()->where('id', '!=', $connection->id)->where('status', 'active')
            ->update(['status' => 'disconnected']);

        // 5. Billing to the platform
        $this->attachCreditLine($connection);

        // 7. Templates
        $this->syncTemplates($connection);

        return $connection->fresh();
    }

    public function resubscribeWebhooks(WhatsAppConnection $connection): void
    {
        if (!$connection->business_account_id || !$connection->access_token_encrypted) {
            return;
        }

        $this->api->subscribeApp($connection->access_token_encrypted, $connection->business_account_id);
        $connection->update(['webhook_subscribed_at' => now()]);
    }

    /** Best-effort: bill this WABA to the platform's credit line. */
    public function attachCreditLine(WhatsAppConnection $connection): bool
    {
        try {
            $this->api->attachCreditLine($connection->business_account_id);
            $connection->update(['credit_line_attached_at' => now()]);
            return true;
        } catch (Throwable $e) {
            Log::warning("Credit line attach failed for WABA {$connection->business_account_id}: {$e->getMessage()}");
            $connection->update(['last_error' => 'Billing setup pending: ' . $e->getMessage()]);
            return false;
        }
    }

    /**
     * Submit the platform template library to the WABA (skipping ones that
     * already exist there) and mirror them as the business's templates so
     * invoices and reminders can use them as soon as Meta approves.
     */
    public function syncTemplates(WhatsAppConnection $connection): int
    {
        $language = (string) config('whatsapp_templates.language', 'en');
        $submitted = 0;

        try {
            $existing = $this->api->templates($connection->access_token_encrypted, $connection->business_account_id);
        } catch (Throwable $e) {
            Log::warning("Template listing failed for WABA {$connection->business_account_id}: {$e->getMessage()}");
            $connection->update(['last_error' => 'Template setup pending: ' . $e->getMessage()]);
            return 0;
        }

        foreach (config('whatsapp_templates.templates', []) as $definition) {
            $template = MessageTemplate::query()->firstOrNew(['name' => $definition['name']]);
            $template->fill([
                'category' => $definition['category'],
                'language' => $language,
                'content' => $definition['content'],
                'whatsapp_template_id' => $definition['name'],
                'is_platform' => true,
            ]);

            $remote = $existing[$definition['name']] ?? null;
            if ($remote) {
                $template->meta_template_id = $remote['id'] ?? $template->meta_template_id;
                $template->status = $this->mapStatus($remote['status'] ?? 'PENDING');
                if (!empty($remote['language'])) {
                    $template->language = $remote['language'];
                }
                $template->save();
                continue;
            }

            try {
                $examples = array_map(fn ($name) => $this->exampleFor($name), $template->placeholders());
                $result = $this->api->createTemplate(
                    $connection->access_token_encrypted, $connection->business_account_id,
                    $definition['name'], $language, $definition['category'], $template->metaBody(), $examples,
                );
                $template->meta_template_id = $result['id'];
                $template->status = $this->mapStatus($result['status']);
                $submitted++;
            } catch (Throwable $e) {
                Log::warning("Template {$definition['name']} submission failed: {$e->getMessage()}");
                $template->status = $template->exists ? $template->status : 'draft';
                $template->rejection_reason = mb_substr($e->getMessage(), 0, 255);
            }
            $template->save();
        }

        $connection->update(['templates_synced_at' => now()]);

        return $submitted;
    }

    /** Apply a message_template_status_update webhook. */
    public function applyTemplateStatus(WhatsAppConnection $connection, array $value): void
    {
        $name = $value['message_template_name'] ?? null;
        if (!$name) {
            return;
        }

        $template = MessageTemplate::query()->where('name', $name)->first();
        if (!$template) {
            return;
        }

        $template->update([
            'meta_template_id' => $value['message_template_id'] ?? $template->meta_template_id,
            'status' => $this->mapStatus($value['event'] ?? ''),
            'rejection_reason' => $value['reason'] ?? null,
        ]);
    }

    /** Apply a phone_number_quality_update webhook. */
    public function applyQualityUpdate(WhatsAppConnection $connection, array $value): void
    {
        // Events: ONBOARDING, UPGRADE, DOWNGRADE, FLAGGED, UNFLAGGED; current_limit e.g. TIER_1K.
        $event = strtoupper((string) ($value['event'] ?? ''));
        $updates = [];
        if (!empty($value['current_limit'])) {
            $updates['messaging_limit_tier'] = $value['current_limit'];
        }
        if ($event === 'FLAGGED') {
            $updates['quality_rating'] = 'RED';
        } elseif ($event === 'UNFLAGGED') {
            $updates['quality_rating'] = 'GREEN';
        }
        if ($updates) {
            $connection->update($updates);
        }

        if ($event === 'FLAGGED') {
            $this->notifications->notify(
                'whatsapp_connection_failure',
                'WhatsApp number flagged',
                'Meta flagged the quality of your WhatsApp number. Sending may be limited until it recovers — avoid messaging customers who did not ask to hear from you.',
                ['connection_id' => $connection->id],
            );
        }
    }

    /** Apply an account_update webhook (bans, restrictions, verification). */
    public function applyAccountUpdate(WhatsAppConnection $connection, array $value): void
    {
        $event = $value['event'] ?? '';
        if (in_array($event, ['DISABLED_UPDATE', 'ACCOUNT_RESTRICTION', 'ACCOUNT_VIOLATION'], true)) {
            $connection->update(['status' => 'error', 'last_error' => 'Meta restricted this account: ' . ($value['ban_info']['waba_ban_state'] ?? $event)]);
            $this->notifications->connectionFailure($connection, 'Meta restricted your WhatsApp Business Account. Contact support.');
        }
    }

    // ------------------------------------------------------------ helpers

    private function resolvePhone(string $token, string $wabaId, ?string $phoneNumberId): ?array
    {
        if ($phoneNumberId) {
            try {
                $phone = $this->api->phoneNumber($token, $phoneNumberId);
                if (!empty($phone['id'])) {
                    return $phone;
                }
            } catch (Throwable) {
                // fall through to listing
            }
        }

        $numbers = $this->api->wabaPhoneNumbers($token, $wabaId);

        return $numbers[0] ?? null;
    }

    /** One number can only belong to one Crediping business. */
    private function assertNotClaimedElsewhere(Business $business, string $phoneNumberId): void
    {
        $claimed = WhatsAppConnection::withoutGlobalScopes()
            ->where('phone_number_id', $phoneNumberId)
            ->where('business_id', '!=', $business->id)
            ->where('status', '!=', 'disconnected')
            ->exists();

        if ($claimed) {
            throw ValidationException::withMessages(['code' => ['This WhatsApp number is already connected to another Crediping workspace.']]);
        }
    }

    private function mapStatus(string $metaStatus): string
    {
        return match (strtoupper($metaStatus)) {
            'APPROVED', 'ACTIVE' => 'approved',
            'REJECTED' => 'rejected',
            'DISABLED', 'PAUSED' => 'disabled',
            default => 'pending',
        };
    }

    /** Sample values Meta requires for template review. */
    private function exampleFor(string $placeholder): string
    {
        return match ($placeholder) {
            'customer_name', 'name' => 'Kemi Adebayo',
            'business_name', 'company' => 'Pavizs Store',
            'invoice_number' => 'INV-0042',
            'amount', 'total', 'balance_due', 'balance', 'amount_paid' => 'NGN 45,000.00',
            'due_date' => 'Sep 30, 2026',
            'days_overdue' => '5',
            'message' => 'Please confirm if you received invoice INV-0042.',
            default => 'example',
        };
    }
}
