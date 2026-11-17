<?php

namespace Tests\Feature\Auth;

use App\Mail\VerifyEmailOtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_verify_their_signed_email_link(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('success', true);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_user_cannot_verify_another_users_email_link(): void
    {
        $user = User::factory()->unverified()->create();
        $otherUser = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $otherUser->id,
            'hash' => sha1($otherUser->getEmailForVerification()),
        ]);

        $this->actingAs($user)->getJson($url)->assertForbidden();
        $this->assertNull($otherUser->fresh()->email_verified_at);
    }

    public function test_registration_otp_verifies_email_and_unlocks_the_api(): void
    {
        Mail::fake();

        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Ada Ltd',
        ])->assertCreated()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/business')->assertForbidden();

        $code = '';
        Mail::assertSent(VerifyEmailOtpMail::class, function (VerifyEmailOtpMail $mail) use (&$code) {
            $code = $mail->code;
            return $mail->hasTo('ada@example.com');
        });

        $this->withToken($token)->postJson('/api/v1/auth/email/verify-otp', ['otp' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.email_verified_at', fn ($value) => $value !== null);

        $this->withToken($token)->getJson('/api/v1/business')->assertOk();
    }

    public function test_wrong_otp_is_rejected(): void
    {
        Mail::fake();
        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'ada2@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Ada Two',
        ])->assertCreated()->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/email/verify-otp', ['otp' => '000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('otp');
    }

    public function test_resend_sends_a_new_otp_for_unverified_users(): void
    {
        Mail::fake();
        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'ada3@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Ada Three',
        ])->assertCreated()->json('data.token');

        Mail::assertSent(VerifyEmailOtpMail::class, 1);

        $this->withToken($token)->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'A new verification code was sent to your email.');

        Mail::assertSent(VerifyEmailOtpMail::class, 2);
    }

    public function test_resend_reports_when_email_is_already_verified(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('message', 'Email is already verified.')
            ->assertJsonPath('data.user.email_verified_at', fn ($value) => $value !== null);

        Mail::assertNothingSent();
    }
}
