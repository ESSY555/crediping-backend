<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToBusiness;

    public const STATUSES = ['draft', 'sent', 'paid', 'partially_paid', 'overdue', 'cancelled'];

    // Statuses that carry a collectable balance.
    public const OPEN_STATUSES = ['sent', 'partially_paid', 'overdue'];

    protected $fillable = [
        'customer_id', 'invoice_number', 'issue_date', 'due_date', 'discount',
        'notes', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date', 'due_date' => 'date',
            'subtotal' => 'decimal:2', 'discount' => 'decimal:2',
            'tax' => 'decimal:2', 'total' => 'decimal:2',
            'amount_paid' => 'decimal:2', 'balance_due' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function items(): HasMany { return $this->hasMany(InvoiceItem::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function reminderLogs(): HasMany { return $this->hasMany(ReminderLog::class); }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES)->where('balance_due', '>', 0);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true) && (float) $this->balance_due > 0;
    }

    public function daysOverdue(): int
    {
        if (!$this->due_date) {
            return 0;
        }

        $due = $this->due_date->copy()->startOfDay();
        $today = today()->startOfDay();
        if ($due->greaterThanOrEqualTo($today)) {
            return 0;
        }

        return (int) $due->diffInDays($today, true);
    }
}
