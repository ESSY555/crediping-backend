<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    /**
     * Generate PDF for an invoice
     */
    public function generate(Invoice $invoice): string
    {
        $invoice->load(['customer', 'items', 'business.settings']);

        $html = $this->renderInvoiceHtml($invoice);

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isHtml5ParserEnabled', true);

        return $pdf->output();
    }

    /**
     * Generate and store PDF for an invoice
     */
    public function generateAndStore(Invoice $invoice): string
    {
        $pdfContent = $this->generate($invoice);

        $fileName = sprintf(
            'invoices/business_%d/invoice_%s.pdf',
            $invoice->business_id,
            $invoice->invoice_number
        );

        Storage::disk('local')->put($fileName, $pdfContent);

        return $fileName;
    }

    /**
     * Render invoice HTML for PDF
     */
    private function renderInvoiceHtml(Invoice $invoice): string
    {
        $business = $invoice->business;
        $customer = $invoice->customer;
        $items = $invoice->items;

        // Pre-calculate values for string interpolation
        $dueDateFormatted = $invoice->due_date ? $invoice->due_date->format('M d, Y') : 'N/A';
        $discountLine = $invoice->discount > 0 ? "<div class=\"total-row\"><span>Discount:</span><span class=\"amount\">{$this->formatCurrency($invoice->discount, $business->currency)}</span></div>" : '';
        $notesSection = $invoice->notes ? "<div class=\"section\"><div class=\"section-title\">Notes</div><div class=\"section-content\">" . htmlspecialchars($invoice->notes) . "</div></div>" : '';
        $logo = $this->logoTag($business);
        $footerNote = htmlspecialchars((string) $business->setting('invoice_footer', 'Thank you for choosing us!'));
        $businessName = htmlspecialchars($business->name);
        $customerName = htmlspecialchars((string) $customer?->name);
        $customerBusiness = htmlspecialchars((string) $customer?->business_name);
        $customerAddress = htmlspecialchars((string) $customer?->address);
        $customerEmail = htmlspecialchars((string) $customer?->email);
        $customerPhone = htmlspecialchars((string) $customer?->phone);

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: Arial, sans-serif;
                    color: #333;
                    line-height: 1.6;
                }
                
                .container {
                    max-width: 800px;
                    margin: 0 auto;
                    padding: 20px;
                }
                
                .header {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 30px;
                    border-bottom: 3px solid #007bff;
                    padding-bottom: 20px;
                }
                
                .business-info h1 {
                    font-size: 28px;
                    margin-bottom: 10px;
                    color: #007bff;
                }
                
                .business-info p {
                    font-size: 12px;
                    color: #666;
                    margin: 3px 0;
                }
                
                .invoice-details {
                    text-align: right;
                    font-size: 12px;
                }
                
                .invoice-details h2 {
                    font-size: 20px;
                    color: #007bff;
                    margin-bottom: 10px;
                }
                
                .invoice-details p {
                    margin: 3px 0;
                }
                
                .section {
                    margin-bottom: 30px;
                }
                
                .section-title {
                    font-size: 12px;
                    font-weight: bold;
                    color: #007bff;
                    text-transform: uppercase;
                    border-bottom: 1px solid #ddd;
                    padding-bottom: 5px;
                    margin-bottom: 10px;
                }
                
                .section-content {
                    font-size: 12px;
                    line-height: 1.8;
                }
                
                .row {
                    display: flex;
                    justify-content: space-between;
                }
                
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 20px 0;
                    font-size: 12px;
                }
                
                table th {
                    background-color: #f8f9fa;
                    border-bottom: 2px solid #007bff;
                    padding: 10px;
                    text-align: left;
                    font-weight: bold;
                }
                
                table td {
                    border-bottom: 1px solid #ddd;
                    padding: 10px;
                }
                
                table tr:nth-child(even) {
                    background-color: #f8f9fa;
                }
                
                .text-right {
                    text-align: right;
                }
                
                .text-center {
                    text-align: center;
                }
                
                .totals {
                    width: 50%;
                    margin-left: auto;
                    font-size: 12px;
                    margin-top: 20px;
                }
                
                .total-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 8px 0;
                    border-bottom: 1px solid #ddd;
                }
                
                .total-row.final {
                    border-bottom: 3px solid #007bff;
                    font-weight: bold;
                    font-size: 14px;
                    padding: 12px 0;
                }
                
                .footer {
                    text-align: center;
                    font-size: 11px;
                    color: #666;
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                }
                
                .amount {
                    text-align: right;
                    font-variant-numeric: tabular-nums;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <!-- Header -->
                <div class="header">
                    <div class="business-info">
                        {$logo}
                        <h1>{$businessName}</h1>
                        <p>{$business->address}</p>
                        <p>{$business->city}, {$business->state} {$business->country}</p>
                        <p>Email: {$business->email}</p>
                        <p>Phone: {$business->phone}</p>
                    </div>
                    <div class="invoice-details">
                        <h2>INVOICE</h2>
                        <p><strong>Invoice #:</strong> {$invoice->invoice_number}</p>
                        <p><strong>Date:</strong> {$invoice->issue_date->format('M d, Y')}</p>
                        <p><strong>Due Date:</strong> {$dueDateFormatted}</p>
                        <p><strong>Status:</strong> <strong style="color: #007bff;">{$this->statusBadge($invoice->status)}</strong></p>
                    </div>
                </div>

                <!-- Customer Info -->
                <div class="section">
                    <div class="section-title">Bill To</div>
                    <div class="section-content">
                        <p><strong>{$customerName}</strong></p>
                        <p>{$customerBusiness}</p>
                        <p>{$customerAddress}</p>
                        <p>Email: {$customerEmail}</p>
                        <p>Phone: {$customerPhone}</p>
                    </div>
                </div>

                <!-- Line Items -->
                <table>
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="text-center">Qty</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$this->renderLineItems($items)}
                    </tbody>
                </table>

                <!-- Totals -->
                <div class="totals">
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <span class="amount">{$this->formatCurrency($invoice->subtotal, $business->currency)}</span>
                    </div>
                    {$discountLine}
                    <div class="total-row final">
                        <span>Total:</span>
                        <span class="amount">{$this->formatCurrency($invoice->total, $business->currency)}</span>
                    </div>
                    <div class="total-row">
                        <span>Amount Paid:</span>
                        <span class="amount">{$this->formatCurrency($invoice->amount_paid, $business->currency)}</span>
                    </div>
                    <div class="total-row">
                        <span><strong>Balance Due:</strong></span>
                        <span class="amount"><strong>{$this->formatCurrency($invoice->balance_due, $business->currency)}</strong></span>
                    </div>
                </div>

                <!-- Notes -->
                {$notesSection}

                <!-- Footer -->
                <div class="footer">
                    <p>{$footerNote}</p>
                    <p style="margin-top: 10px; font-size: 10px;">Generated on {now()->format('M d, Y \a\t H:i A')}</p>
                </div>
            </div>
        </body>
        </html>
        HTML;
    }

    /**
     * Inline the business logo as a data URI so dompdf needs no remote fetch.
     */
    private function logoTag($business): string
    {
        if (!$business->logo || str_starts_with($business->logo, 'http')) {
            return '';
        }
        $disk = Storage::disk('public');
        if (!$disk->exists($business->logo)) {
            return '';
        }
        $mime = $disk->mimeType($business->logo) ?: 'image/png';
        $data = base64_encode($disk->get($business->logo));

        return "<img src=\"data:{$mime};base64,{$data}\" style=\"max-height:60px;max-width:180px;margin-bottom:8px;\" alt=\"logo\">";
    }

    /**
     * Render line items table rows
     */
    private function renderLineItems($items): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= sprintf(
                '<tr>
                    <td>%s</td>
                    <td class="text-center">%s</td>
                    <td class="text-right">%s</td>
                    <td class="text-right">%s</td>
                </tr>',
                htmlspecialchars($item->description),
                number_format($item->quantity, 2),
                number_format($item->unit_price, 2),
                number_format($item->subtotal, 2)
            );
        }
        return $html;
    }

    /**
     * Format currency value
     */
    private function formatCurrency($amount, $currency = 'USD'): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'INR' => '₹',
            'NGN' => '₦',
            'GHS' => 'GH₵',
            'KES' => 'KSh',
            'ZAR' => 'R',
        ];

        $symbol = $symbols[$currency] ?? $currency;

        return sprintf('%s %s', $symbol, number_format($amount, 2));
    }

    /**
     * Render status badge
     */
    private function statusBadge($status): string
    {
        $statusMap = [
            'draft' => 'DRAFT',
            'sent' => 'SENT',
            'paid' => 'PAID',
            'partially_paid' => 'PARTIALLY PAID',
            'overdue' => 'OVERDUE',
            'cancelled' => 'CANCELLED',
        ];

        return $statusMap[$status] ?? strtoupper($status);
    }
}
