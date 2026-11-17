<?php

namespace App\Services;

use App\Mail\ChangePasswordOtpMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordOtpService
{
    public const TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public function send(User $user): void
    {
        if ($user->password_otp_hash && $user->password_otp_expires_at?->isFuture() && $user->password_otp_sent_at?->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            $wait = max(1, self::RESEND_SECONDS - $user->password_otp_sent_at->diffInSeconds(now()));
            Log::info('Password change OTP send skipped: cooldown.', [
                'user_id' => $user->id,
                'wait_seconds' => $wait,
            ]);
            throw ValidationException::withMessages([
                'otp' => ["Please wait {$wait} seconds before requesting another code."],
            ]);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'password_otp_hash' => Hash::make($code),
            'password_otp_expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'password_otp_sent_at' => now(),
            'password_otp_attempts' => 0,
        ])->save();

        Log::info('Password change OTP generated.', [
            'user_id' => $user->id,
            'expires_at' => $user->password_otp_expires_at?->toDateTimeString(),
        ]);

        try {
            Mail::to($user->email)->send(new ChangePasswordOtpMail($user, $code));
            Log::info('Password change OTP sent.', [
                'user_id' => $user->id,
                'mailer' => config('mail.default'),
            ]);
        } catch (Throwable $e) {
            Log::error('Password change OTP failed to send.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            throw $e;
        }
    }

    public function verify(User $user, string $code): void
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($user->password_otp_hash === null || $user->password_otp_expires_at === null) {
            Log::warning('Password change OTP verify failed: no pending code.', [
                'user_id' => $user->id,
            ]);
            throw ValidationException::withMessages([
                'otp' => ['No verification code is pending. Request a new code.'],
            ]);
        }

        if ($user->password_otp_expires_at->isPast()) {
            Log::warning('Password change OTP verify failed: expired.', [
                'user_id' => $user->id,
            ]);
            throw ValidationException::withMessages([
                'otp' => ['That code has expired. Request a new one.'],
            ]);
        }

        if ((int) $user->password_otp_attempts >= self::MAX_ATTEMPTS) {
            Log::warning('Password change OTP verify failed: too many attempts.', [
                'user_id' => $user->id,
                'attempts' => $user->password_otp_attempts,
            ]);
            throw ValidationException::withMessages([
                'otp' => ['Too many attempts. Request a new code.'],
            ]);
        }

        if (! Hash::check($code, $user->password_otp_hash)) {
            $user->increment('password_otp_attempts');
            Log::warning('Password change OTP verify failed: incorrect code.', [
                'user_id' => $user->id,
                'attempts' => $user->password_otp_attempts,
            ]);
            throw ValidationException::withMessages([
                'otp' => ['The verification code is incorrect.'],
            ]);
        }
    }

    public function clear(User $user): void
    {
        $user->forceFill([
            'password_otp_hash' => null,
            'password_otp_expires_at' => null,
            'password_otp_sent_at' => null,
            'password_otp_attempts' => 0,
        ])->save();
    }
}
