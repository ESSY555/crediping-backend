<?php

namespace Tests\Feature\Api\V1;

use App\Mail\PlatformAnnouncementMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AdminBulkEmailTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_super_admin_can_email_selected_users(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        $one = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
        $two = User::factory()->create(['name' => 'Segun', 'email' => 'segun@example.com']);
        User::factory()->create(['email' => 'left-out@example.com']);

        $this->actingAs($admin)->postJson('/api/v1/admin/emails', [
            'subject' => 'Hello {{name}}',
            'body' => 'Welcome to Crediping.',
            'user_ids' => [$one->id, $two->id],
        ])->assertOk()
            ->assertJsonPath('data.sent', 2)
            ->assertJsonPath('data.failed', []);

        Mail::assertSent(PlatformAnnouncementMail::class, function (PlatformAnnouncementMail $mail) {
            return $mail->hasTo('ada@example.com') && $mail->emailSubject === 'Hello Ada';
        });
        Mail::assertSent(PlatformAnnouncementMail::class, fn (PlatformAnnouncementMail $mail) => $mail->hasTo('segun@example.com'));
        Mail::assertNotSent(PlatformAnnouncementMail::class, fn (PlatformAnnouncementMail $mail) => $mail->hasTo('left-out@example.com'));
    }

    public function test_all_flag_emails_every_user(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        User::factory()->count(2)->create();

        $this->actingAs($admin)->postJson('/api/v1/admin/emails', [
            'subject' => 'Update',
            'body' => 'A short note.',
            'all' => true,
        ])->assertOk()->assertJsonPath('data.sent', 3);

        Mail::assertSent(PlatformAnnouncementMail::class, 3);
    }

    public function test_non_admin_cannot_send_bulk_email(): void
    {
        Mail::fake();
        [$user] = $this->membership();

        $this->actingAs($user)->postJson('/api/v1/admin/emails', [
            'subject' => 'Update',
            'body' => 'A short note.',
            'all' => true,
        ])->assertForbidden();

        Mail::assertNothingSent();
    }
}
