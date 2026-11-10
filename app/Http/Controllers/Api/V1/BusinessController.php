<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Business\UpdateBusinessRequest;
use App\Http\Requests\Business\UpdateBusinessSettingsRequest;
use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\WhatsAppConnection;
use App\Services\AuditLogger;
use App\Services\BillingService;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BusinessController extends ApiController
{
    public function __construct(private AuditLogger $audit)
    {
    }

    public function show(Request $request)
    {
        $business = $this->business()->load('settings');
        $data = $business->toArray();
        $data['settings'] = $business->settings->pluck('value', 'key');
        $data['logo_url'] = $this->logoUrl($business);
        $data['role'] = $request->user()->roleIn($business->id);
        $data['whatsapp_connected'] = WhatsAppConnection::query()->active()->exists();
        $data['billing'] = $this->billingSummary($business);

        return $this->successResponse('Business retrieved successfully.', $data);
    }

    public function update(UpdateBusinessRequest $request)
    {
        $business = $this->business();
        $business->update($request->validated());
        $this->audit->logChanges('business.updated', $business);

        $data = $business->fresh()->toArray();
        $data['logo_url'] = $this->logoUrl($business);

        return $this->successResponse('Business updated successfully.', $data);
    }

    /**
     * Upload a logo (stored on the public disk, never in the database).
     */
    public function uploadLogo(Request $request)
    {
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048']]);

        $business = $this->business();
        if ($business->logo && Storage::disk('public')->exists($business->logo)) {
            Storage::disk('public')->delete($business->logo);
        }

        $path = $request->file('logo')->store("logos/business_{$business->id}", 'public');
        Storage::disk('public')->setVisibility($path, 'public');
        $business->update(['logo' => $path]);

        return $this->successResponse('Logo uploaded successfully.', ['logo' => $path, 'logo_url' => $this->logoUrl($business)]);
    }

    public function settings()
    {
        $settings = $this->business()->settings()->pluck('value', 'key');

        return $this->successResponse('Business settings retrieved successfully.', $settings);
    }

    public function updateSettings(UpdateBusinessSettingsRequest $request)
    {
        $businessId = TenantManager::businessId();
        DB::transaction(function () use ($request, $businessId) {
            foreach ($request->validated('settings') as $key => $value) {
                BusinessSetting::updateOrCreate(
                    ['business_id' => $businessId, 'key' => $key],
                    ['value' => $value],
                );
            }
        });
        $this->audit->log('business.settings_updated', 'Business', $businessId, null, $request->validated('settings'));

        return $this->settings();
    }

    private function business(): Business
    {
        return Business::query()->findOrFail(TenantManager::businessId());
    }

    /**
     * Compact entitlement snapshot for the sidebar and banners; the full
     * picture lives at GET /billing.
     */
    private function billingSummary(Business $business): array
    {
        $summary = app(BillingService::class)->summary($business);

        return [
            'state' => $summary['state'],
            'payment_state' => $summary['payment_state'],
            'cycle_ends_at' => $summary['cycle']['ends_at'],
            'grace_ends_at' => $summary['subscription']['grace_ends_at'] ?? null,
            'plan_name' => $summary['plan']['name'] ?? null,
            'plan_code' => $summary['plan']['code'] ?? null,
            'ends_at' => $summary['subscription']['ends_at'] ?? $summary['trial_ends_at'],
            'trial_days_left' => $summary['trial_days_left'],
            'has_pending_request' => $summary['pending_request'] !== null,
            'last_declined_reason' => $summary['last_declined_request']?->decline_reason,
            'usage' => $summary['usage'],
        ];
    }

    private function logoUrl(Business $business): ?string
    {
        if (!$business->logo) {
            return null;
        }
        if (str_starts_with($business->logo, 'http://') || str_starts_with($business->logo, 'https://')) {
            return $business->logo;
        }

        return url('/api/v1/media/'.$business->logo);
    }
}
