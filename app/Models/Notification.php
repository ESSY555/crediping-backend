<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use BelongsToBusiness;

    public const TYPES = [
        'payment_received', 'invoice_overdue', 'message_received', 'message_failed', 'broadcast_completed',
        'scheduled_message_sent', 'whatsapp_connection_failure',
        'payment_approved', 'payment_declined', 'subscription_expiring', 'subscription_expired', 'quota_exhausted',
    ];

    protected $fillable = ['user_id', 'type', 'title', 'message', 'data', 'read_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $userId));
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }
}
