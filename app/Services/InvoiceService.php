<?php

namespace App\Services;

use App\Jobs\GenerateInvoicePdf;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Message;
use App\Models\Product;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Response;

class InvoiceService
{
    public function __construct(
        private InvoicePdfService $pdfService,
        private WhatsAppService $whatsapp,
        private NotificationService $notifications,
    ) {}

    public function create(array $data, int $userId): Invoice
    {
        return DB::transaction(function () use ($data, $userId) {
            $items = $data['items'];
            unset($data['items']);
            $data['invoice_number'] = $data['invoice_number'] ?? $this->nextNumber();
            $data['issue_date'] ??= now()->toDateString();
            $data['discount'] = round((float) ($data['discount'] ?? 0), 2);
            $data['created_by'] = $userId;
            $data['status'] = $data['status'] ?? 'draft';

            $this->assertCustomer($data['customer_id']);
            $invoice = Invoice::create($data);
            $this->replaceItems($invoice, $items);

            return $this->recalculate($invoice->load('items', 'customer'));
        });
    }

    public function update(Invoice $invoice, array $data): Invoice
    {
        if (!in_array($invoice->status, ['draft', 'sent'], true)) {
            throw ValidationException::withMessages(['status' => ['Only draft or sent invoices can be edited.']]);
        }

        return DB::transaction(function () use ($invoice, $data) {
            $items = $data['items'] ?? null;
            unset($data['items']);
            if (isset($data['customer_id'])) {
                $this->assertCustomer($data['customer_id']);
            }
            $invoice->update($data);
            if ($items !== null) {
                $invoice->items()->delete();
                $this->replaceItems($invoice, $items);
            }
            return $this->recalculate($invoice->refresh()->load('items', 'customer'));
        });
    }

    /**
     * Mark a draft invoice as sent and, optionally, deliver it over WhatsApp.
     */
    public function send(Invoice $invoice, bool $viaWhatsApp = false, ?string $customMessage = null, ?int $templateId = null): array
    {
        if ($invoice->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => ['Cancelled invoices cannot be sent.']]);
        }

        $message = null;
        if ($viaWhatsApp) {
            $message = $this->whatsapp->sendMessage([
                'customer_id' => $invoice->customer_id,
                'template_id' => $templateId,
                'body' => $customMessage ?: $this->defaultInvoiceMessage($invoice),
                'invoice' => $invoice,
                'source' => 'invoice',
            ]);
        }

        if ($invoice->status === 'draft') {
            $invoice->update(['status' => 'sent']);
        }
        $this->recalculate($invoice->load('items', 'customer'));

        return ['invoice' => $invoice->fresh(['items', 'customer']), 'message' => $message];
    }

    /**
     * Send a manual payment reminder for an open invoice.
     */
    public function remind(Invoice $invoice, ?string $customMessage = null, ?int $templateId = null): Message
    {
        if (!$invoice->isOpen() && $invoice->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only unpaid invoices can be reminded.']]);
        }

        return $this->whatsapp->sendMessage([
            'customer_id' => $invoice->customer_id,
            'template_id' => $templateId,
            'body' => $customMessage ?: $this->defaultReminderMessage($invoice),
            'invoice' => $invoice,
            'source' => 'reminder',
        ]);
    }

    public function cancel(Invoice $invoice): Invoice
    {
        if ((float) $invoice->amount_paid > 0) {
            throw ValidationException::withMessages(['status' => ['Invoices with recorded payments cannot be cancelled. Reverse the payments first.']]);
        }
        if ($invoice->status === 'cancelled') {
            return $invoice;
        }

        $invoice->update(['status' => 'cancelled']);

        return $invoice->fresh(['items', 'customer']);
    }

    /**
     * Flip open invoices past their due date to "overdue". Returns the invoices changed.
     */
    public function markOverdue(): \Illuminate\Support\Collection
    {
        $changed = collect();

        Invoice::query()
            ->whereIn('status', ['sent', 'partially_paid'])
            ->where('balance_due', '>', 0)
            ->whereDate('due_date', '<', today())
            ->with('customer')
            ->each(function (Invoice $invoice) use ($changed) {
                $invoice->update(['status' => 'overdue']);
                $this->notifications->invoiceOverdue($invoice);
                $changed->push($invoice);
            });

        return $changed;
    }

    public function generatePdf(Invoice $invoice): Response
    {
        $fileName = sprintf('invoice_%s.pdf', $invoice->invoice_number);
        $pdfContent = $this->pdfService->generate($invoice);

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Content-Length' => strlen($pdfContent),
        ]);
    }

    public function queuePdfGeneration(Invoice $invoice): void
    {
        dispatch(new GenerateInvoicePdf($invoice));
    }

    /**
     * Recompute money fields from items and payments, then derive the status.
     */
    public function recalculate(Invoice $invoice): Invoice
    {
        $subtotal = round((float) $invoice->items->sum('subtotal'), 2);
        $tax = round((float) $invoice->items->sum('tax_amount'), 2);
        $discount = min(round((float) $invoice->discount, 2), round($subtotal + $tax, 2));
        $total = round(max(0, $subtotal + $tax - $discount), 2);
        $paid = round((float) $invoice->payments()->sum('amount'), 2);
        $balance = round(max(0, $total - $paid), 2);

        $invoice->forceFill([
            'subtotal' => $subtotal, 'tax' => $tax, 'discount' => $discount,
            'total' => $total, 'amount_paid' => $paid, 'balance_due' => $balance,
            'status' => $this->deriveStatus($invoice, $total, $paid, $balance),
        ])->save();

        return $invoice->fresh(['items', 'customer']);
    }

    private function deriveStatus(Invoice $invoice, float $total, float $paid, float $balance): string
    {
        if (in_array($invoice->status, ['draft', 'cancelled'], true) && $paid <= 0) {
            return $invoice->status;
        }
        if ($total > 0 && $balance <= 0) {
            return 'paid';
        }
        if ($paid > 0) {
            return $invoice->due_date && $invoice->due_date->lt(today()) ? 'overdue' : 'partially_paid';
        }
        if ($invoice->due_date && $invoice->due_date->lt(today())) {
            return 'overdue';
        }
        return 'sent';
    }

    private function replaceItems(Invoice $invoice, array $items): void
    {
        foreach ($items as $item) {
            $product = isset($item['product_id']) ? Product::query()->find($item['product_id']) : null;
            $hasPrice = isset($item['unit_price']) && $item['unit_price'] !== '';
            if (!$product && !$hasPrice) {
                throw ValidationException::withMessages(['items' => ['Custom invoice lines require a unit_price.']]);
            }
            // Catalogue products always price the line; only custom lines take client values.
            $quantity = (float) $item['quantity'];
            $unitPrice = round((float) ($product ? $product->unit_price : $item['unit_price']), 2);
            $taxRate = round((float) ($product ? $product->tax_rate : ($item['tax_rate'] ?? 0)), 2);
            $subtotal = round($quantity * $unitPrice, 2);
            $tax = round($subtotal * $taxRate / 100, 2);

            $invoice->items()->create([
                'business_id' => TenantManager::businessId(),
                'product_id' => $product?->id,
                'description' => $item['description'] ?? $product?->name ?? 'Item',
                'quantity' => $quantity, 'unit_price' => $unitPrice,
                'tax_rate' => $taxRate, 'tax_amount' => $tax,
                'subtotal' => $subtotal, 'total' => round($subtotal + $tax, 2),
            ]);
        }
    }

    private function assertCustomer(int $id): void
    {
        if (!Customer::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['customer_id' => ['The selected customer is invalid.']]);
        }
    }

    private function nextNumber(): string
    {
        $prefix = 'INV-' . now()->format('Y') . '-';
        $last = Invoice::query()->where('invoice_number', 'like', $prefix . '%')->lockForUpdate()->orderByDesc('id')->value('invoice_number');
        $sequence = $last ? ((int) substr($last, -6)) + 1 : 1;
        return $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    private function defaultInvoiceMessage(Invoice $invoice): string
    {
        $v = $this->whatsapp->defaultVariables($invoice->customer, $invoice);

        return "Hello {$v['customer_name']},\n\nInvoice {$v['invoice_number']} from {$v['business_name']} is ready.\n"
            . "Total: {$v['total']}\nAmount paid: {$v['amount_paid']}\nBalance due: {$v['balance_due']}\n"
            . ($v['due_date'] ? "Due date: {$v['due_date']}\n" : '')
            . "\nThank you for your business.";
    }

    private function defaultReminderMessage(Invoice $invoice): string
    {
        $v = $this->whatsapp->defaultVariables($invoice->customer, $invoice);
        $overdue = $invoice->daysOverdue();

        return "Hello {$v['customer_name']},\n\nThis is a friendly reminder that invoice {$v['invoice_number']} "
            . ($overdue > 0 ? "is {$overdue} day(s) overdue" : ($v['due_date'] ? "is due on {$v['due_date']}" : 'is awaiting payment'))
            . ".\nOutstanding balance: {$v['balance_due']}\n\nKindly arrange payment at your earliest convenience.";
    }
}
