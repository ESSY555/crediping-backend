<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private function membership(string $role = 'owner'): array
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => 'Original', 'slug' => 'original']);
        $user->businesses()->attach($business->id, ['role' => $role, 'status' => 'active']);

        return [$user, $business];
    }

    public function test_owner_can_update_business_profile_but_not_status_or_slug(): void
    {
        [$user, $business] = $this->membership();

        $this->actingAs($user)->putJson('/api/v1/business', [
            'name' => 'Updated Name',
            'phone' => '+234 809 111 2222',
            'slug' => 'attacker-slug',
            'status' => 'cancelled',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Name')->assertJsonPath('data.phone', '+234 809 111 2222');

        $this->assertDatabaseHas('businesses', [
            'id' => $business->id,
            'name' => 'Updated Name',
            'phone' => '+234 809 111 2222',
            'slug' => 'original',
            'status' => 'trial',
        ]);
    }

    public function test_manager_can_update_settings_and_settings_are_tenant_scoped(): void
    {
        [$user, $business] = $this->membership('manager');

        $this->actingAs($user)->putJson('/api/v1/business/settings', [
            'settings' => ['invoice_prefix' => 'INV', 'reminders_enabled' => '1'],
        ])->assertOk()->assertJsonPath('data.invoice_prefix', 'INV');

        $this->assertDatabaseHas('business_settings', [
            'business_id' => $business->id,
            'key' => 'invoice_prefix',
            'value' => 'INV',
        ]);
    }

    public function test_owner_can_upload_and_view_business_logo(): void
    {
        Storage::fake('public');
        [$user] = $this->membership();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $file = UploadedFile::fake()->createWithContent('logo.png', $png);

        $uploaded = $this->actingAs($user)->post('/api/v1/business/logo', ['logo' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data');

        $this->assertNotEmpty($uploaded['logo']);
        $this->assertStringContainsString('/api/v1/media/'.$uploaded['logo'], $uploaded['logo_url']);
        Storage::disk('public')->assertExists($uploaded['logo']);

        $this->get('/api/v1/media/'.$uploaded['logo'])->assertOk();
        $this->actingAs($user)->getJson('/api/v1/business')->assertOk()->assertJsonPath('data.logo_url', $uploaded['logo_url']);
    }

    public function test_inactive_membership_cannot_access_business_data(): void
    {
        [$user, $business] = $this->membership();
        $user->businesses()->updateExistingPivot($business->id, ['status' => 'suspended']);

        $this->actingAs($user)->getJson('/api/v1/business')->assertForbidden();
    }
}
