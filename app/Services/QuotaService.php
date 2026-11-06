<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\MessageUsage;
use App\Models\Notification;
use App\Models\PaymentSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Plan enforcement for outbound WhatsApp. Every send path funnels through
 * WhatsAppService::sendMessage(), so a single check here covers manual
 * messages, reminders, scheduled messages and broadcasts.
 *
 * Policy (v1, no payment processor):
 *  - HARD BLOCK: once the cycle's included messages are used, further
 *    Utility sends are rejected with a clear error the UI shows as
 *    "WhatsApp quota reached for this cycle". Overage is not allowed because
 *    there is no way to bill it. `config('billing.hard_block')` flips this to
 *    "allow and flag for manual review".
 *  - Usage is counted on the *delivered* webhook (Meta only charges for
 *    delivered messages). Messages already queued/sent but not yet delivered
 *    are reserved against the quota so a burst cannot overshoot.
 *  - Marketing (broadcasts, promotional templates) is metered separately and
 *    never draws from the Utility quota. No v1 plan includes it.
 *  - Authentication (OTP-style templates) is priced like Utility by Meta and
 *    draws from the same quota, but is tracked as its own category.
 */
class QuotaService
{
    public const CATEGORIES = MessageUsage::CATEGORIES;

    /** Categories that consume the plan's included ("utility") quota. */
    private const UTILITY_POOL = ['utility', 'authentication'];

    private const MARKETING_TEMPLATE_CATEGORIES = ['marketing', 'promotion', 'promotional', 'promo', 'campaign'];
    private const AUTHENTICATION_TEMPLATE_CATEGORIES = ['authentication', 'auth', 'otp', 'verification'];

    public function __construct(private BillingService $billing, private NotificationService $notifications)
    {
    }

    // ------------------------------------------------------------ classification

    /**
     * Which bucket a message will be billed in.
     *
     * - A reply inside the 24-hour customer service window is free.
     * - Broadcasts and marketing-category templates are marketing.
     * - Authentication-category templates are authentication.
     * - Everything else (invoices, reminders, receipts) is utility.
     */
    public function categoryFor(?Conversation $conversation, ?MessageTemplate $template, string $source = 'manual'): string
    {
        if ($conversation && $this->inServiceWindow($conversation)) {
            return 'service';
        }

        if ($source === 'broadcast') {
            return 'marketing';
        }

        $category = strtolower((string) ($template?->category ?? ''));
        if ($category !== '') {
            if (in_array($category, self::MARKETING_TEMPLATE_CATEGORIES, true)) {
                return 'marketing';
            }
            if (in_array($category, self::AUTHENTICATION_TEMPLATE_CATEGORIES, true)) {
                return 'authentication';
            }
        }

        return 'utility';
    }

    /** The quota pool a category is charged to (null = free). */
    public static function poolFor(string $category): ?string
    {
        return match (true) {
            in_array($category, self::UTILITY_POOL, true) => 'utility',
            $category === 'marketing' => 'marketing',
            default => null,
        };
    }

    // ------------------------------------------------------------ enforcement

    /**
     * Throw a validation error if the current tenant cannot send `$count`
     * more messages of this category.
     */
    public function assertCanSend(string $category, int $count = 1): void
    {
        $this->determineBillingSource($category, $count);
    }

    /**
     * Determine whether an outbound message is billed from the subscription quota,
     * the tenant wallet, or is free (service window).
     *
     * Order:
     * 1. Service window -> free.
     * 2. Remaining subscription quota -> subscription (quota).
     * 3. Wallet -> debit at current utility rate (utility category only).
     *
     * Throws ValidationException if both quota and wallet balance are insufficient.
     */
    public function determineBillingSource(string $category, int $count = 1): array
    {
        $business = $this->business();
        if (!$business) {
            return ['source' => 'subscription', 'unit_price' => null];
        }

        $pool = self::poolFor($category);
        if ($pool === null) {
            return ['source' => 'free', 'unit_price' => null]; // service-window replies are free
        }

        $plan = $this->billing->planFor($business);

        // Marketing category: broadcasts & campaigns.
        if ($pool === 'marketing') {
            if (!$plan) {
                throw ValidationException::withMessages(['plan' => [$this->noPlanMessage($business)]]);
            }
            if (!$plan->allows_broadcasts) {
                throw ValidationException::withMessages(['plan' => [$this->marketingNotIncludedMessage($plan)]]);
            }

            // If an explicit isolated marketing_quota > 0 is configured on the plan, charge against it;
            // otherwise, marketing/bulk messages share the plan's included messages quota (utility pool).
            $poolToCharge = ($plan->marketing_quota !== null && $plan->marketing_quota > 0) ? 'marketing' : 'utility';

            $remaining = $this->remaining($business, $plan, $poolToCharge);
            if ($remaining !== null && $remaining < $count) {
                $this->notifyExhausted($business, $poolToCharge, $plan);
                if (!config('billing.hard_block', true)) {
                    return ['source' => 'subscription', 'unit_price' => null];
                }
                $quota = $plan->quotaFor($poolToCharge);
                $message = $remaining === 0
                    ? sprintf('WhatsApp quota reached for this cycle: all %d included messages on your %s plan are used. Upgrade your plan or wait for the next cycle.', $quota, $plan->name)
                    : sprintf('This needs %d messages but only %d remain in this cycle on your %s plan.', $count, $remaining, $plan->name);
                throw ValidationException::withMessages(['plan' => [$message]]);
            }

            return ['source' => 'subscription', 'unit_price' => null];
        }

        // Authentication category: billed from subscription utility pool. Not covered by wallet in v1.
        // TODO: In future phase, allow authentication templates to draw from wallet at utility/auth rate.
        if ($category === 'authentication') {
            if (!$plan) {
                throw ValidationException::withMessages(['plan' => [$this->noPlanMessage($business)]]);
            }

            $remaining = $this->remaining($business, $plan, 'utility');
            if ($remaining !== null && $remaining < $count) {
                $this->notifyExhausted($business, 'utility', $plan);
                if (!config('billing.hard_block', true)) {
                    return ['source' => 'subscription', 'unit_price' => null];
                }
                $quota = $plan->quotaFor('utility');
                $message = $remaining === 0
                    ? sprintf('WhatsApp quota reached for this cycle: all %d included messages on your %s plan are used. Upgrade your plan or wait for the next cycle.', $quota, $plan->name)
                    : sprintf('This needs %d messages but only %d remain in this cycle on your %s plan.', $count, $remaining, $plan->name);
                throw ValidationException::withMessages(['plan' => [$message]]);
            }

            return ['source' => 'subscription', 'unit_price' => null];
        }

        // Utility category:
        // 1. Consume subscription quota first if available
        if ($plan) {
            $remaining = $this->remaining($business, $plan, 'utility');
            if ($remaining === null || $remaining >= $count) {
                return ['source' => 'subscription', 'unit_price' => null];
            }
            $this->notifyExhausted($business, 'utility', $plan);
        }

        // 2. Subscription quota exhausted (or expired / grace / no plan) -> evaluate wallet
        $setting = PaymentSetting::current();
        $unitPrice = $setting->calculatePricePerMessage('utility');
        $totalCost = round($unitPrice * $count, 2);

        $wallet = app(WalletService::class)->getOrCreateWallet($business);
        $balance = (float) $wallet->balance;

        if ($balance >= $totalCost) {
            return ['source' => 'wallet', 'unit_price' => $unitPrice];
        }

        // 3. Both subscription quota and wallet are insufficient
        if ($plan) {
            if (!config('billing.hard_block', true)) {
                return ['source' => 'subscription', 'unit_price' => null];
            }
            $quota = $plan->quotaFor('utility');
            $remaining = $this->remaining($business, $plan, 'utility');
            $message = $remaining === 0
                ? sprintf('WhatsApp quota reached for this cycle: all %d included messages on your %s plan are used, and your wallet balance (₦%s) is insufficient (₦%s required). Top up your wallet or upgrade your plan.', $quota, $plan->name, number_format($balance, 2), number_format($totalCost, 2))
                : sprintf('This needs %d messages but only %d remain in this cycle on your %s plan, and your wallet balance (₦%s) is insufficient (₦%s required).', $count, $remaining, $plan->name, number_format($balance, 2), number_format($totalCost, 2));
            throw ValidationException::withMessages(['plan' => [$message], 'wallet' => [$message]]);
        }

        // Expired or no plan
        $message = sprintf('No active plan quota and your wallet balance (₦%s) is insufficient (₦%s required). Top up your wallet under Billing to send messages.', number_format($balance, 2), number_format($totalCost, 2));
        throw ValidationException::withMessages(['wallet' => [$message], 'plan' => [$message]]);
    }

    public function assertBroadcastsAllowed(): void
    {
        $business = $this->business();
        if (!$business) {
            return;
        }

        $plan = $this->billing->planFor($business);
        if (!$plan) {
            throw ValidationException::withMessages(['plan' => [$this->noPlanMessage($business)]]);
        }
        if (!$plan->allows_broadcasts) {
            throw ValidationException::withMessages(['plan' => [$this->marketingNotIncludedMessage($plan)]]);
        }
    }

    /**
     * Messages left in the current cycle for a pool; null means unlimited.
     * Counts delivered messages plus in-flight ones that will be charged
     * once delivered.
     */
    public function remaining(Business $business, Plan $plan, string $pool): ?int
    {
        $quota = $plan->quotaFor($pool);
        if ($quota === null) {
            return null;
        }

        $subscription = $this->billing->currentSubscription($business);
        $periodStart = MessageUsage::periodStartFor($business, $subscription);

        $sharesMarketing = !($plan->marketing_quota !== null && $plan->marketing_quota > 0);
        $categories = $pool === 'utility'
            ? ($sharesMarketing ? ['utility', 'authentication', 'marketing'] : self::UTILITY_POOL)
            : [$pool];

        $charged = (int) MessageUsage::withoutGlobalScopes()
            ->forCycle($business, $subscription)
            ->whereIn('category', $categories)
            ->sum('sent_count');

        $inFlight = $this->inFlightCount($business, $periodStart, $categories);

        return max(0, $quota - $charged - $inFlight);
    }

    /** Outbound messages created this cycle that are not yet delivered or failed. */
    public function inFlightCount(Business $business, \Illuminate\Support\Carbon $periodStart, array $categories): int
    {
        return Message::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('direction', 'outbound')
            ->whereIn('billing_category', $categories)
            ->whereNull('charged_at')
            ->whereIn('status', ['queued', 'sending', 'sent'])
            ->where('created_at', '>=', $periodStart)
            ->count();
    }

    // ------------------------------------------------------------ metering

    /**
     * Count a message the WhatsApp webhook reported as delivered (or read,
     * which implies delivered). Idempotent per message via charged_at.
     */
    public function recordDelivered(Message $message): bool
    {
        if ($message->charged_at || $message->direction !== 'outbound') {
            return false;
        }

        // Atomically claim the message for billing. Only the process/worker that
        // successfully transitions charged_at from NULL to now() proceeds to bump usage.
        $affected = Message::withoutGlobalScopes()
            ->whereKey($message->id)
            ->whereNull('charged_at')
            ->update(['charged_at' => now()]);

        if ($affected === 0) {
            return false;
        }

        $message->charged_at = now();
        $this->bump($message, 'sent_count');

        return true;
    }

    public function recordFailed(Message $message): void
    {
        if ($message->direction !== 'outbound') {
            return;
        }
        $this->bump($message, 'failed_count');
    }

    private function bump(Message $message, string $column): void
    {
        $category = $message->billing_category ?: 'utility';
        $business = Business::query()->find($message->business_id);
        if (!$business) {
            return;
        }

        $subscription = $this->billing->currentSubscription($business);
        $periodStart = MessageUsage::periodStartFor($business, $subscription);

        DB::transaction(function () use ($message, $category, $periodStart, $subscription, $column) {
            $row = MessageUsage::withoutGlobalScopes()->firstOrCreate(
                ['business_id' => $message->business_id, 'subscription_id' => $subscription?->id, 'period_start' => $periodStart, 'category' => $category],
                ['sent_count' => 0, 'failed_count' => 0],
            );
            $row->increment($column);
        });
    }

    // ------------------------------------------------------------ helpers

    public function inServiceWindow(Conversation $conversation): bool
    {
        return Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
    }

    private function business(): ?Business
    {
        $id = TenantManager::businessId();
        return $id ? Business::query()->find($id) : null;
    }

    private function noPlanMessage(Business $business): string
    {
        if ($business->status === 'suspended') {
            return 'This workspace is suspended. Contact support to restore WhatsApp sending.';
        }
        return $business->trial_ends_at
            ? 'Your plan has expired. Upload a payment receipt under Billing to keep sending WhatsApp messages.'
            : 'No active plan. Upload a payment receipt under Billing to start sending WhatsApp messages.';
    }

    private function marketingNotIncludedMessage(Plan $plan): string
    {
        return sprintf('Broadcasts and marketing messages are not included in your %s plan. Contact support to add them.', $plan->name);
    }

    /** One in-app heads-up per business, pool and cycle. */
    private function notifyExhausted(Business $business, string $pool, Plan $plan): void
    {
        $subscription = $this->billing->currentSubscription($business);
        $periodStart = MessageUsage::periodStartFor($business, $subscription);

        $alreadySent = Notification::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('type', 'quota_exhausted')
            ->where('created_at', '>=', $periodStart)
            ->where('data->category', $pool)
            ->exists();
        if ($alreadySent) {
            return;
        }

        $this->notifications->notify(
            'quota_exhausted',
            'WhatsApp quota reached',
            sprintf('You have used all %s messages included in your %s plan for this cycle.', $pool, $plan->name),
            ['category' => $pool, 'plan' => $plan->code],
        );
    }
}
