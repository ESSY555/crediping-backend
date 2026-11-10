<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = [
        'name', 'business_name', 'phone', 'email', 'address', 'category',
        'notes', 'status',
    ];

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            $customer->phone_normalized = $customer->whatsappNumber();
        });
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'customer_group_members')
            ->withTimestamps();
    }

    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function conversations(): HasMany { return $this->hasMany(Conversation::class); }
    public function messages(): HasMany { return $this->hasMany(Message::class); }

    /**
     * Digits-only canonical phone in international format, as expected by the WhatsApp Cloud API.
     * Correctly handles Nigerian formats (080..., 80..., +234..., 234...) to canonical 234...
     * while preserving valid international numbers.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $trimmed = trim($phone);
        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $trimmed);
        if ($digits === '') {
            return null;
        }

        // Strip leading international call prefix "00"
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // Nigerian local format with leading zero: 11 digits starting with 0 (e.g. 08031234567, 070..., 090..., 081..., 091...)
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return '234' . substr($digits, 1);
        }

        // Nigerian 10-digit format without leading zero (e.g. 8031234567, 703..., 901...)
        if (strlen($digits) === 10 && in_array($digits[0], ['7', '8', '9'], true)) {
            return '234' . $digits;
        }

        // Nigerian number entered with 234 and accidental local 0: 23408031234567 (14 digits)
        if (strlen($digits) === 14 && str_starts_with($digits, '2340')) {
            return '234' . substr($digits, 4);
        }

        // Standard Nigerian international format (13 digits starting with 234)
        if (strlen($digits) === 13 && str_starts_with($digits, '234')) {
            return $digits;
        }

        // Valid international numbers
        return $digits;
    }

    /**
     * Digits-only phone in international format, as expected by the WhatsApp Cloud API.
     */
    public function whatsappNumber(): ?string
    {
        return self::normalizePhone($this->phone);
    }

    /**
     * Find the workspace customer Meta is writing from. Matches canonical
     * 234… ids, +234…, and local 080… numbers stored on the customer record.
     */
    public static function findByWhatsAppPhone(string $from): ?self
    {
        $normalized = self::normalizePhone($from) ?? preg_replace('/\D+/', '', $from);
        if ($normalized === '') {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $normalized,
            $from,
            '+' . $normalized,
            '+' . ltrim($from, '+'),
            (str_starts_with($normalized, '234') && strlen($normalized) === 13) ? '0' . substr($normalized, 3) : null,
        ])));

        return static::query()
            ->where(function ($q) use ($candidates) {
                $q->whereIn('phone_normalized', $candidates)->orWhereIn('phone', $candidates);
            })
            ->first();
    }
}
