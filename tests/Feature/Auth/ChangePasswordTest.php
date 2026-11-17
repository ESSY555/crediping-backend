<?php

namespace Tests\Feature\Auth;

use App\Mail\ChangePasswordOtpMail;
use App\Models\User;
use App\Services\PasswordOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_request_a_password_change_otp(): void
    {
        $this->postJson('/api/v1/auth/password/otp', ['current_password' => 'password'])
            ->assertUnauthorized();
    }

    public function test_unverified_user_cannot_change_password(): void
    {
        Mail::fake();
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/password/otp', [
            'current_password' => 'password',
        ])->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_wrong_current_password_does_not_send_an_otp(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/password/otp', [
            'current_password' => 'not-the-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        Mail::assertNothingSent();
        $this->assertNull($user->fresh()->password_otp_hash);
    }

    public function test_user_can_request_otp_and_change_password(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $keep = $user->createToken('keep');
        $revoke = $user->createToken('revoke');

        $this->withToken($keep->plainTextToken)->postJson('/api/v1/auth/password/otp', [
            'current_password' => 'password',
        ])->assertOk()->assertJsonPath('success', true);

        $code = '';
        Mail::assertSent(ChangePasswordOtpMail::class, function (ChangePasswordOtpMail $mail) use ($user, &$code) {
            $code = $mail->code;

            return $mail->hasTo($user->email) && strlen($code) === 6;
        });

        $this->withToken($keep->plainTextToken)->postJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'otp' => $code,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk()->assertJsonPath('success', true);

        $user = $user->fresh();
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertNull($user->password_otp_hash);
        $this->assertNotNull($keep->accessToken->fresh());
        $this->assertNull($revoke->accessToken->fresh());
    }

    public function test_wrong_otp_is_rejected_and_does_not_change_the_password(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/password/otp', [
            'current_password' => 'password',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'otp' => '000000',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('otp');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_new_password_must_differ_from_the_current_password(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/password/otp', [
            'current_password' => 'password',
        ])->assertOk();

        $code = '';
        Mail::assertSent(ChangePasswordOtpMail::class, function (ChangePasswordOtpMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'otp' => $code,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_otp_cannot_be_reused_after_a_successful_change(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/password/otp', [
            'current_password' => 'password',
        ])->assertOk();

        $code = '';
        Mail::assertSent(ChangePasswordOtpMail::class, function (ChangePasswordOtpMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'otp' => $code,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => 'new-password',
            'otp' => $code,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('otp');
    }

    public function test_otp_resend_is_rate_limited_within_the_cooldown(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/password/otp', [
            'current_password' => 'password',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/v1/auth/password/otp', [
            'current_password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('otp');

        Mail::assertSent(ChangePasswordOtpMail::class, 1);
    }

    public function test_expired_otp_is_rejected(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/password/otp', [
            'current_password' => 'password',
        ])->assertOk();

        $code = '';
        Mail::assertSent(ChangePasswordOtpMail::class, function (ChangePasswordOtpMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->travel(PasswordOtpService::TTL_MINUTES + 1)->minutes();

        $this->withToken($token)->postJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'otp' => $code,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('otp');
    }
}
