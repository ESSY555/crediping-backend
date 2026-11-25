<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\RequestPasswordOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\User;
use App\Services\EmailOtpService;
use App\Services\PasswordOtpService;
use App\Tenancy\TenantManager;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends ApiController
{
    /** Every new business starts on a free trial with Starter quotas and no broadcasts. */
    public const TRIAL_DAYS = 14;

    /**
     * Register a business and its owner in one transaction.
     */
    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $business = Business::create([
                'name' => $validated['business_name'],
                'slug' => $this->uniqueSlug($validated['business_name']),
                'email' => $validated['business_email'] ?? $validated['email'],
                'phone' => $validated['business_phone'] ?? $validated['phone'] ?? null,
                'currency' => $validated['currency'] ?? 'USD',
                'country' => $validated['country'] ?? null,
                'timezone' => $validated['timezone'] ?? 'UTC',
                'status' => 'trial',
                'trial_ends_at' => now()->addDays((int) config('billing.trial_days', self::TRIAL_DAYS)),
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
            ]);

            $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);

            $defaults = [
                'setup_completed' => 'true',
                'invoice_prefix' => 'INV-',
                'payment_terms_days' => '14',
                'invoice_footer' => 'Thank you for choosing us.',
                'reminders_enabled' => 'true',
                'payment_receipts_enabled' => 'false',
            ];
            if (!empty($validated['industry'])) {
                $defaults['industry'] = $validated['industry'];
            }
            TenantManager::runAs($business->id, function () use ($defaults) {
                foreach ($defaults as $key => $value) {
                    BusinessSetting::create(['key' => $key, 'value' => $value]);
                }
            });

            return $user;
        });

        event(new Registered($user));
        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->successResponse('Successfully registered. Check your email for a verification code.', $this->authPayload($user->fresh(), $token), 201);
    }

    public function login(LoginRequest $request)
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Console-only accounts have no workspace to land in.
        if ($user->isSuperAdmin() && !$user->businesses()->wherePivot('status', 'active')->exists()) {
            return $this->errorResponse('This is an administrator account. Sign in at /admin/login instead.', 403);
        }

        if (! $user->hasVerifiedEmail() && ! $user->isSuperAdmin()) {
            app(EmailOtpService::class)->send($user, false);
        }

        $token = $user->createToken($validated['device_name'] ?? 'web')->plainTextToken;

        return $this->successResponse('Successfully logged in.', $this->authPayload($user, $token));
    }

    /**
     * Sign-in for the platform admin console. Same credentials as a normal
     * account, but only super admins get a token, so a stolen business
     * login can never reach /admin.
     */
    public function adminLogin(LoginRequest $request)
    {
        $validated = $request->validated();

        // The console accepts exactly one identity: the Super Admin configured
        // in .env (SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD). Nothing in the
        // users table — not even another account flagged is_super_admin — can
        // authenticate here, so a leaked business password never reaches /admin.
        $configuredEmail = (string) config('billing.super_admin.email');
        $configuredPassword = (string) config('billing.super_admin.password');

        $emailMatches = $configuredEmail !== '' && hash_equals(strtolower($configuredEmail), strtolower((string) $validated['email']));
        $passwordMatches = $configuredPassword !== '' && hash_equals($configuredPassword, (string) $validated['password']);

        if (!$emailMatches || !$passwordMatches) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // The matching database row must exist and carry the super admin flag
        // (SuperAdminSeeder creates it); anything else is a misconfiguration.
        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($configuredEmail)])->first();
        if (!$user || !$user->isSuperAdmin()) {
            return $this->errorResponse('This account does not have administrator access.', 403);
        }

        $token = $user->createToken(EnsureSuperAdmin::TOKEN_NAME)->plainTextToken;

        return $this->successResponse('Successfully logged in.', $this->authPayload($user, $token));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->successResponse('Successfully logged out.');
    }

    public function user(Request $request)
    {
        return $this->successResponse('Authenticated user retrieved successfully.', $this->authPayload($request->user())['user']);
    }

    public function forgotPassword(ForgotPasswordRequest $request)
    {
        Password::sendResetLink($request->only('email'));

        // Do not disclose whether the email is registered.
        return $this->successResponse('If the email address exists, a password reset link has been sent.');
    }

    public function resetPassword(ResetPasswordRequest $request)
    {
        $status = Password::reset($request->validated(), function (User $user, string $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return $this->successResponse('Password reset successfully. Please log in again.');
    }

    public function requestPasswordOtp(RequestPasswordOtpRequest $request, PasswordOtpService $otp)
    {
        $user = $request->user();
        $this->assertCurrentPassword($user, $request->validated('current_password'));
        $otp->send($user);

        return $this->successResponse('A verification code was sent to your email.');
    }

    public function changePassword(ChangePasswordRequest $request, PasswordOtpService $otp)
    {
        $user = $request->user();
        $validated = $request->validated();

        $this->assertCurrentPassword($user, $validated['current_password']);

        if (Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Choose a password that is different from your current password.'],
            ]);
        }

        $otp->verify($user, $validated['otp']);

        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();

        $otp->clear($user);

        $current = $user->currentAccessToken();
        $user->tokens()
            ->when(
                $current instanceof \Laravel\Sanctum\PersonalAccessToken,
                fn ($query) => $query->where('id', '!=', $current->id)
            )
            ->delete();

        event(new PasswordReset($user));

        return $this->successResponse('Password updated successfully.');
    }

    private function assertCurrentPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }
    }

    public function verifyEmail(Request $request, int $id, string $hash)
    {
        $user = $request->user();

        if (!$user || $user->id !== $id || !hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->errorResponse('You do not have permission to perform this action.', 403);
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $this->successResponse('Email verified successfully.');
    }

    public function verifyOtp(Request $request, EmailOtpService $otp)
    {
        $validated = $request->validate([
            'otp' => ['required', 'string', 'size:6'],
        ]);

        $otp->verify($request->user(), $validated['otp']);

        return $this->successResponse('Email verified successfully.', $this->authPayload($request->user()->fresh()));
    }

    public function resendVerification(Request $request)
    {
        $user = $request->user();

        Log::info('Email OTP resend requested.', [
            'user_id' => $user->id,
            'email' => $user->email,
            'already_verified' => $user->hasVerifiedEmail(),
        ]);

        if ($user->hasVerifiedEmail()) {
            return $this->successResponse('Email is already verified.', $this->authPayload($user));
        }

        $user->sendEmailVerificationNotification();

        return $this->successResponse('A new verification code was sent to your email.');
    }

    /**
     * The identity payload the frontend keeps: user, memberships and the
     * business the tenant middleware will resolve by default.
     */
    public function authPayload(User $user, ?string $token = null): array
    {
        $user->load('businesses');
        $active = $user->businesses->firstWhere('pivot.status', 'active');

        $data = [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'email_verified_at' => $user->email_verified_at,
                'is_super_admin' => $user->isSuperAdmin(),
                'created_at' => $user->created_at,
                'businesses' => $user->businesses->map(fn (Business $b) => [
                    'id' => $b->id, 'name' => $b->name, 'slug' => $b->slug, 'status' => $b->status,
                    'currency' => $b->currency, 'logo' => $b->logo,
                    'role' => $b->pivot->role, 'membership_status' => $b->pivot->status,
                ])->values(),
                'business' => $active ? [
                    'id' => $active->id, 'name' => $active->name, 'slug' => $active->slug, 'status' => $active->status,
                    'currency' => $active->currency, 'logo' => $active->logo,
                    'role' => $active->pivot->role,
                ] : null,
            ],
        ];
        if ($token) {
            $data['token'] = $token;
        }

        return $data;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        while (Business::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . Str::lower(Str::random(5));
        }
        return $slug;
    }
}
