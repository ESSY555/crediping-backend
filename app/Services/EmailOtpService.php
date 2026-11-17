<?php

namespace App\Services;

use App\Mail\VerifyEmailOtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailOtpService
{
    public const TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public function send(User $user, bool $force = true): void
    {
        if ($user->hasVerifiedEmail()) {
            Log::info('Email OTP send skipped: already verified.', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
            return;
        }

        if (! $force && $user->email_otp_hash && $user->email_otp_expires_at?->isFuture()) {
            Log::info('Email OTP send skipped: unexpired code already exists.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'expires_at' => $user->email_otp_expires_at?->toDateTimeString(),
            ]);
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'email_otp_hash' => Hash::make($code),
            'email_otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'email_otp_attempts' => 0,
        ])->save();

        Log::info('Email OTP generated.', [
            'user_id' => $user->id,
            'email' => $user->email,
            'otp' => $code,
            'expires_at' => $user->email_otp_expires_at?->toDateTimeString(),
            'forced' => $force,
        ]);

        try {
            Mail::to($user->email)->send(new VerifyEmailOtpMail($user, $code));
            Log::info('Email OTP sent.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'otp' => $code,
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'from' => config('mail.from.address'),
                'ehlo' => config('mail.mailers.smtp.local_domain'),
            ]);
        } catch (Throwable $e) {
            Log::error('Email OTP failed to send.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'otp' => $code,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            throw $e;
        }
    }

    public function verify(User $user, string $code): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($user->email_otp_hash === null || $user->email_otp_expires_at === null) {
            Log::warning('Email OTP verify failed: no pending code.', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
            throw ValidationException::withMessages([
                'otp' => ['No verification code is pending. Request a new code.'],
            ]);
        }

        if ($user->email_otp_expires_at->isPast()) {
            Log::warning('Email OTP verify failed: expired.', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);
            throw ValidationException::withMessages([
                'otp' => ['That code has expired. Request a new one.'],
            ]);
        }

        if ((int) $user->email_otp_attempts >= self::MAX_ATTEMPTS) {
            Log::warning('Email OTP verify failed: too many attempts.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'attempts' => $user->email_otp_attempts,
            ]);
            throw ValidationException::withMessages([
                'otp' => ['Too many attempts. Request a new code.'],
            ]);
        }

        if (! Hash::check($code, $user->email_otp_hash)) {
            $user->increment('email_otp_attempts');
            Log::warning('Email OTP verify failed: incorrect code.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'attempts' => $user->email_otp_attempts,
            ]);
            throw ValidationException::withMessages([
                'otp' => ['The verification code is incorrect.'],
            ]);
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'email_otp_hash' => null,
            'email_otp_expires_at' => null,
            'email_otp_attempts' => 0,
        ])->save();

        Log::info('Email OTP verified.', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
    }
}
