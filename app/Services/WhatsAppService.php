<?php

namespace App\Services;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\WhatsAppConnection;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Outbound and inbound message handling for the current tenant. Sending is
 * always two-step: persist the message as "queued", then let a queue job talk
 * to the WhatsApp Cloud API.
 */
class WhatsAppService
{
    public function __construct(
        private NotificationService $notifications,
        private QuotaService $quota,
        private WalletService $wallet,
    ) {
    }

    /**
     * Create and queue an outbound message.
     *
     * Accepts either conversation_id or customer_id, and either body or
     * template_id (+ template_variables).
     */
    public function sendMessage(array $data): Message
    {
        $conversation = isset($data['conversation_id'])
            ? Conversation::query()->findOrFail($data['conversation_id'])
            : $this->conversationFor(Customer::query()->findOrFail($data['customer_id']));

        $template = null;
        $variables = $data['template_variables'] ?? [];
        $invoice = $data['invoice'] ?? null;

        if (!empty($data['template_id'])) {
            $template = MessageTemplate::query()->findOrFail($data['template_id']);
            if (!$template->isApproved()) {
                throw ValidationException::withMessages(['template_id' => ["Template '{$template->name}' is not approved for sending."]]);
            }

            if (!$invoice && !empty($data['invoice_id'])) {
                $invoice = Invoice::query()->find($data['invoice_id']);
            }
            if (!$invoice && $conversation->customer) {
                $invoice = $conversation->customer->invoices()
                    ->whereIn('status', ['sent', 'partially_paid', 'overdue', 'paid'])
                    ->latest('id')
                    ->first()
                    ?? $conversation->customer->invoices()->latest('id')->first();
            }

            $defaults = $this->defaultVariables($conversation->customer, $invoice);
            if (str_contains($template->name, 'payment_received') && !empty($defaults['payment_amount']) && empty($variables['amount'])) {
                $defaults['amount'] = $defaults['payment_amount'];
            }

            $variables = array_merge($defaults, $variables);
            $body = $template->render($variables);
        } else {
            $body = trim((string) ($data['body'] ?? $data['content'] ?? ''));
            if ($body === '') {
                throw ValidationException::withMessages(['body' => ['A message body or template is required.']]);
            }

            // Inbox typed messages are sent as the platform custom-message
            // template. Session text is accepted by Meta but often never
            // delivered; approved templates are what actually reach the phone.
            // Broadcasts keep their own template / free-text path.
            $source = $data['source'] ?? 'manual';
            if ($source !== 'broadcast' && $conversation->customer) {
                $conversation->loadMissing('connection');
                $freeTextTemplate = $this->freeTextTemplate($conversation);
                if ($freeTextTemplate) {
                    $template = $freeTextTemplate;
                    $variables = array_merge(
                        $this->defaultVariables($conversation->customer, $invoice),
                        $variables,
                        ['message' => trim(preg_replace("/[\r\n]+/", ' ', $body))],
                    );
                    $body = $template->render($variables);
                }
            }
        }

        $business = $conversation->customer?->business ?: Business::query()->find(TenantManager::businessId());
        $companyName = $business?->name;
        $body = $this->appendSignature($body, $companyName);

        // Plan and wallet enforcement happens here so every send path is covered.
        $category = $this->quota->categoryFor($conversation, $template, $data['source'] ?? 'manual');
        $billingInfo = $this->quota->determineBillingSource($category);

        return DB::transaction(function () use ($data, $conversation, $template, $variables, $body, $category, $billingInfo, $business, $invoice) {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'customer_id' => $conversation->customer_id,
                'direction' => 'outbound',
                'type' => $template?->whatsapp_template_id ? 'template' : 'text',
                'billing_category' => $category,
                'billing_source' => $billingInfo['source'],
                'unit_price' => $billingInfo['unit_price'],
                'template_id' => $template?->id,
                'body' => $body,
                'status' => 'queued',
                'metadata' => array_filter([
                    'template_variables' => $template ? $variables : null,
                    'source' => $data['source'] ?? 'manual',
                    'invoice_id' => $invoice?->id ?? (isset($data['invoice']) ? $data['invoice']->id : null),
                ]),
            ]);

            // If billed from wallet, atomically debit the wallet within the transaction
            if ($billingInfo['source'] === 'wallet' && $billingInfo['unit_price'] > 0 && $business) {
                $this->wallet->debit(
                    $business,
                    (float) $billingInfo['unit_price'],
                    sprintf('WhatsApp %s message to %s', $category, $conversation->customer?->phone ?? 'customer'),
                    (float) $billingInfo['unit_price'],
                    "msg_{$message->id}",
                    ['message_id' => $message->id, 'category' => $category]
                );
            }

            $conversation->update(['last_message_at' => now(), 'status' => 'open']);

            DB::afterCommit(fn () => SendWhatsAppMessage::dispatch($message->id));

            return $message->load('customer');
        });
    }

    /**
     * Re-queue a failed message.
     */
    public function retryMessage(Message $message): Message
    {
        if ($message->status !== 'failed') {
            throw ValidationException::withMessages(['status' => ['Only failed messages can be retried.']]);
        }

        // If this message was a template message with missing or empty variables:
        if ($message->type === 'template' && $message->template) {
            $template = $message->template;
            $customer = $message->customer;
            $existingVars = $message->metadata['template_variables'] ?? [];

            $hasMissing = false;
            foreach ($template->placeholders() as $p) {
                if (!isset($existingVars[$p]) || trim((string) $existingVars[$p]) === '') {
                    $hasMissing = true;
                    break;
                }
            }

            if ($hasMissing && $customer) {
                $invoice = null;
                if (!empty($message->metadata['invoice_id'])) {
                    $invoice = Invoice::query()->find($message->metadata['invoice_id']);
                }
                if (!$invoice) {
                    $invoice = $customer->invoices()
                        ->whereIn('status', ['sent', 'partially_paid', 'overdue', 'paid'])
                        ->latest('id')
                        ->first()
                        ?? $customer->invoices()->latest('id')->first();
                }

                $defaults = $this->defaultVariables($customer, $invoice);
                if (str_contains($template->name, 'payment_received') && !empty($defaults['payment_amount']) && empty($existingVars['amount'])) {
                    $defaults['amount'] = $defaults['payment_amount'];
                }

                $newVars = array_merge($defaults, array_filter($existingVars, fn ($v) => trim((string) $v) !== ''));
                $body = $template->render($newVars);
                $business = $customer->business ?: Business::query()->find(TenantManager::businessId());
                $body = $this->appendSignature($body, $business?->name);

                $metadata = $message->metadata ?? [];
                $metadata['template_variables'] = $newVars;
                if ($invoice) {
                    $metadata['invoice_id'] = $invoice->id;
                }

                $message->update([
                    'body' => $body,
                    'metadata' => $metadata,
                ]);
            }
        }

        $message->update(['status' => 'queued', 'error_message' => null]);
        SendWhatsAppMessage::dispatch($message->id);

        return $message;
    }

    /**
     * Find or open the customer's conversation on the business's active connection.
     */
    public function conversationFor(Customer $customer): Conversation
    {
        $existing = $customer->conversations()->latest('last_message_at')->first();
        if ($existing) {
            return $existing;
        }

        $connection = WhatsAppConnection::query()->active()->latest()->first();
        if (!$connection) {
            throw ValidationException::withMessages(['connection' => ['Connect a WhatsApp Business account before sending messages.']]);
        }

        return Conversation::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'whatsapp_connection_id' => $connection->id],
            ['status' => 'open'],
        );
    }

    /**
     * Placeholder values every template can rely on.
     */
    public function defaultVariables(Customer $customer, ?Invoice $invoice = null): array
    {
        $business = Business::query()->find(TenantManager::businessId());
        $currency = $business?->currency ?? '';
        $money = fn ($v) => trim($currency . ' ' . number_format((float) $v, 2));

        $vars = [
            'customer_name' => $customer->name,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'customer_business' => $customer->business_name,
            'business_name' => $business?->name,
            'company' => $business?->name,
            'company_name' => $business?->name,
            'currency' => $currency,
        ];

        if ($invoice) {
            $latestPayment = $invoice->payments()->where('status', 'completed')->latest('id')->first();
            $paymentAmount = $latestPayment ? (float) $latestPayment->amount : ((float) $invoice->amount_paid > 0 ? (float) $invoice->amount_paid : (float) $invoice->total);

            $vars += [
                'invoice_number' => $invoice->invoice_number,
                'subtotal' => $money($invoice->subtotal),
                'discount' => $money($invoice->discount),
                'tax' => $money($invoice->tax),
                'amount' => $money($invoice->total),
                'payment_amount' => $money($paymentAmount),
                'total' => $money($invoice->total),
                'amount_paid' => $money($invoice->amount_paid),
                'balance_due' => $money($invoice->balance_due),
                'balance' => $money($invoice->balance_due),
                'due_date' => $invoice->due_date?->format('M d, Y') ?? '',
                'days_overdue' => (string) $invoice->daysOverdue(),
            ];
        } else {
            $vars += [
                'invoice_number' => 'N/A',
                'subtotal' => $money(0),
                'discount' => $money(0),
                'tax' => $money(0),
                'amount' => $money(0),
                'payment_amount' => $money(0),
                'total' => $money(0),
                'amount_paid' => $money(0),
                'balance_due' => $money(0),
                'balance' => $money(0),
                'due_date' => now()->format('M d, Y'),
                'days_overdue' => '0',
            ];
        }

        return $vars;
    }

    /**
     * Apply a delivery status reported by a webhook. Statuses never move backwards,
     * but late delivered/read confirmations reconcile messages previously marked failed
     * due to local network timeouts.
     */
    public function applyStatus(string $whatsappMessageId, string $status, ?string $errorMessage = null): ?Message
    {
        $message = Message::query()->where('whatsapp_message_id', $whatsappMessageId)->first();
        if (!$message || !isset(Message::STATUS_RANK[$status])) {
            return null;
        }

        $currentStatus = $message->status;

        // Prevent stale events from regressing higher delivery states:
        // 1. 'read' cannot be downgraded to 'delivered' or 'sent'.
        if ($currentStatus === 'read' && in_array($status, ['delivered', 'sent', 'sending', 'queued'], true)) {
            return $message;
        }

        // 2. 'delivered' cannot be downgraded to 'sent'.
        if ($currentStatus === 'delivered' && in_array($status, ['sent', 'sending', 'queued'], true)) {
            return $message;
        }

        // 3. 'sent' cannot be regressed to 'sending' or 'queued'.
        if ($currentStatus === 'sent' && in_array($status, ['sending', 'queued'], true)) {
            return $message;
        }

        // Duplicate failed events don't re-trigger failure notifications
        if ($currentStatus === 'failed' && $status === 'failed') {
            if ($errorMessage && !$message->error_message) {
                $message->update(['error_message' => $errorMessage]);
            }
            return $message;
        }

        $updates = ['status' => $status];
        if (in_array($status, ['sent', 'delivered', 'read'], true)) {
            $updates[$status . '_at'] = $message->{$status . '_at'} ?? now();
            // If reconciling from a timeout failure to confirmed delivery, clear the error message
            if ($currentStatus === 'failed') {
                $updates['error_message'] = null;
            }
        }
        if ($status === 'failed') {
            $updates['error_message'] = $errorMessage ?? $message->error_message;
        }

        $message->update($updates);

        // Meta bills delivered messages, so that is when quota is consumed.
        // "read" implies delivered in case the delivered event was missed.
        if (in_array($status, ['delivered', 'read'], true)) {
            $this->quota->recordDelivered($message);
        }

        if ($status === 'failed' && $currentStatus !== 'failed') {
            $this->quota->recordFailed($message);
            $this->wallet->refundMessage($message, $errorMessage ?? 'Delivery failed');
            $this->notifications->messageFailed($message);
        }

        // Resolved lazily: BroadcastService depends on this service.
        app(BroadcastService::class)->syncRecipientStatus($message);

        return $message;
    }

    /**
     * Store an inbound message. Idempotent on the WhatsApp message id.
     */
    public function handleIncoming(WhatsAppConnection $connection, array $incoming): ?Message
    {
        $from = Customer::normalizePhone((string) data_get($incoming, 'from'));
        $waId = data_get($incoming, 'id');
        if (!$from || !$waId) {
            return null;
        }

        $customer = Customer::query()
            ->where(function ($q) use ($from) {
                $q->where('phone_normalized', $from)
                    ->orWhere('phone', $from)
                    ->orWhere('phone', '+' . $from)
                    ->orWhere('phone_normalized', '+' . $from);
            })
            ->first();

        if (!$customer) {
            // Unknown senders become customers so the conversation is not lost.
            $customer = Customer::create(['name' => '+' . $from, 'phone' => '+' . $from, 'category' => 'walk-in']);
        } elseif (!$customer->phone_normalized) {
            $customer->update(['phone_normalized' => $from]);
        }

        $conversation = Conversation::query()->firstOrCreate(
            ['customer_id' => $customer->id, 'whatsapp_connection_id' => $connection->id],
            ['status' => 'open'],
        );

        $type = data_get($incoming, 'type', 'text');
        $body = data_get($incoming, 'text.body')
            ?? data_get($incoming, 'button.text')
            ?? data_get($incoming, 'interactive.button_reply.title')
            ?? data_get($incoming, "{$type}.caption")
            ?? "[{$type}]";

        $message = Message::query()->firstOrCreate(
            ['whatsapp_message_id' => $waId],
            [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'direction' => 'inbound',
                'type' => $type,
                'body' => $body,
                'status' => 'delivered',
                'sent_at' => now(),
                'delivered_at' => now(),
                'metadata' => ['raw_type' => $type],
            ],
        );

        $conversation->update(['last_message_at' => now(), 'status' => 'open']);

        return $message;
    }

    /**
     * Ensure every outgoing message automatically ends with the sending company's name,
     * avoiding duplicate signatures.
     */
    public function appendSignature(string $body, ?string $companyName): string
    {
        $companyName = trim((string) $companyName);
        if ($companyName === '') {
            return $body;
        }

        $trimmed = rtrim($body);
        $escaped = preg_quote($companyName, '/');

        // Check if the body already ends with the company's name as a signature:
        // e.g. "— Company", "-- Company", "- Company", "\nCompany"
        if (preg_match('/(?:[\—\-\–]{1,2}\s*' . $escaped . '|\n\s*' . $escaped . ')$/iu', $trimmed)) {
            // If it ends with raw "\nCompanyName" without the dash, normalize to "\n\n— CompanyName"
            if (preg_match('/(?<![\—\-\–])\n+\s*' . $escaped . '$/iu', $trimmed)) {
                $withoutName = preg_replace('/\n+\s*' . $escaped . '$/iu', '', $trimmed);
                return rtrim($withoutName) . "\n\n— {$companyName}";
            }
            return $trimmed;
        }

        return $trimmed . "\n\n— {$companyName}";
    }

    /**
     * Approved Meta template that can carry an operator-typed inbox message
     * outside the 24-hour customer service window.
     */
    private function freeTextTemplate(Conversation $conversation): ?MessageTemplate
    {
        $usable = fn (?MessageTemplate $row) => ($row?->isApproved() && filled($row->whatsapp_template_id)) ? $row : null;

        $row = MessageTemplate::query()->where('name', 'flowcrm_custom_message')->first();
        if ($found = $usable($row)) {
            return $found;
        }

        if ($row?->status === 'pending' && $conversation->connection?->isActive()) {
            try {
                app(WhatsAppOnboardingService::class)->syncTemplates($conversation->connection);
            } catch (\Throwable) {
                return null;
            }

            return $usable(MessageTemplate::query()->where('name', 'flowcrm_custom_message')->first());
        }

        return null;
    }
}
