<?php

namespace App\Services;

use App\Models\Notification;
use App\Tenancy\TenantManager;

/**
 * In-app notifications for the current tenant. A notification with no user_id
 * is visible to every member of the business.
 */
class NotificationService
{
    public function notify(string $type, string $title, ?string $message = null, array $data = [], ?int $userId = null): ?Notification
    {
        if (!TenantManager::has()) {
            return null;
        }

        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data ?: null,
        ]);
    }

    public function paymentReceived(\App\Models\Payment $payment): ?Notification
    {
        $invoice = $payment->invoice;
        $customer = $payment->customer;

        return $this->notify(
            'payment_received',
            'Payment received',
            sprintf('%s paid %s on %s.', $customer?->name ?? 'A customer', number_format((float) $payment->amount, 2), $invoice?->invoice_number ?? 'an invoice'),
            ['payment_id' => $payment->id, 'invoice_id' => $payment->invoice_id, 'customer_id' => $payment->customer_id, 'amount' => (float) $payment->amount],
        );
    }

    public function invoiceOverdue(\App\Models\Invoice $invoice): ?Notification
    {
        return $this->notify(
            'invoice_overdue',
            'Invoice overdue',
            sprintf('%s for %s is %d day(s) past due.', $invoice->invoice_number, $invoice->customer?->name ?? 'a customer', $invoice->daysOverdue()),
            ['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'balance_due' => (float) $invoice->balance_due],
        );
    }

    public function messageReceived(\App\Models\Message $message): ?Notification
    {
        $already = Notification::query()
            ->where('type', 'message_received')
            ->where('data->message_id', $message->id)
            ->exists();
        if ($already) {
            return null;
        }

        $name = $message->customer?->name ?? 'A customer';
        $preview = trim((string) $message->body);
        if (mb_strlen($preview) > 140) {
            $preview = mb_substr($preview, 0, 137).'...';
        }

        return $this->notify(
            'message_received',
            'New WhatsApp message',
            $preview !== '' ? sprintf('%s: %s', $name, $preview) : sprintf('%s sent you a message.', $name),
            [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'customer_id' => $message->customer_id,
                'whatsapp_message_id' => $message->whatsapp_message_id,
            ],
        );
    }

    public function messageFailed(\App\Models\Message $message): ?Notification
    {
        return $this->notify(
            'message_failed',
            'WhatsApp delivery failed',
            sprintf('Message to %s could not be delivered%s.', $message->customer?->name ?? 'a customer', $message->error_message ? ': ' . rtrim($message->error_message, '.') : ''),
            ['message_id' => $message->id, 'customer_id' => $message->customer_id, 'conversation_id' => $message->conversation_id],
        );
    }

    public function broadcastCompleted(\App\Models\Broadcast $broadcast): ?Notification
    {
        return $this->notify(
            'broadcast_completed',
            'Broadcast completed',
            sprintf('"%s" reached %d of %d recipients (%d failed).', $broadcast->name, $broadcast->sent_count, $broadcast->total_recipients, $broadcast->failed_count),
            ['broadcast_id' => $broadcast->id],
        );
    }

    public function scheduledMessageSent(\App\Models\ScheduledMessage $scheduled): ?Notification
    {
        return $this->notify(
            'scheduled_message_sent',
            'Scheduled message sent',
            sprintf('Scheduled message to %s was dispatched.', $scheduled->customer?->name ?? 'a customer'),
            ['scheduled_message_id' => $scheduled->id, 'customer_id' => $scheduled->customer_id],
        );
    }

    public function connectionFailure(\App\Models\WhatsAppConnection $connection, string $reason): ?Notification
    {
        return $this->notify(
            'whatsapp_connection_failure',
            'WhatsApp connection problem',
            sprintf('Connection %s reported an error: %s', $connection->phone_number_id, $reason),
            ['connection_id' => $connection->id],
        );
    }

    public function walletTopupApproved(\App\Models\TopupRequest $topup): ?Notification
    {
        return $this->notify(
            'wallet_topup_approved',
            'Wallet top-up approved',
            sprintf('%s %s has been credited to your workspace wallet.', $topup->currency, number_format((float) $topup->amount, 2)),
            ['topup_request_id' => $topup->id, 'amount' => (float) $topup->amount],
        );
    }

    public function walletTopupRejected(\App\Models\TopupRequest $topup): ?Notification
    {
        return $this->notify(
            'wallet_topup_rejected',
            'Wallet top-up rejected',
            sprintf('Your top-up of %s %s was rejected: %s', $topup->currency, number_format((float) $topup->amount, 2), $topup->rejection_reason),
            ['topup_request_id' => $topup->id, 'reason' => $topup->rejection_reason],
        );
    }

    public function lowWalletBalance(\App\Models\Wallet $wallet, float $threshold): ?Notification
    {
        return $this->notify(
            'wallet_low_balance',
            'Low wallet balance',
            sprintf('Your wallet balance is %s %s, which is below the ₦%s threshold. Top up to keep sending WhatsApp messages.', $wallet->currency, number_format((float) $wallet->balance, 2), number_format($threshold, 2)),
            ['balance' => (float) $wallet->balance, 'threshold' => $threshold],
        );
    }
}
