<?php

namespace Tests\Feature\Auth;

use App\Mail\VerifyEmailOtpMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_creates_business()
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'business_name' => 'Doe Inc',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['success', 'message', 'data' => ['user', 'token']]);
        
        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
        $this->assertDatabaseHas('businesses', ['name' => 'Doe Inc']);
        
        $user = \App\Models\User::where('email', 'john@example.com')->first();
        $this->assertNull($user->email_verified_at);
        Mail::assertSent(VerifyEmailOtpMail::class, fn (VerifyEmailOtpMail $mail) => $mail->hasTo('john@example.com'));
        $this->assertDatabaseHas('business_users', [
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        
        $business = \App\Models\Business::where('name', 'Doe Inc')->first();
        $this->assertDatabaseHas('business_settings', [
            'business_id' => $business->id,
        ]);
    }

    public function test_registration_requires_all_fields()
    {
        $response = $this->postJson('/api/v1/auth/register', []);
        
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email', 'password', 'business_name']);
    }
}
