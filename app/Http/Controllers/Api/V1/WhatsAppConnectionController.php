<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\WhatsApp\StoreConnectionRequest;
use App\Models\Business;
use App\Models\MessageTemplate;
use App\Models\WhatsAppConnection;
use App\Services\AuditLogger;
use App\Services\WhatsAppCloudApi;
use App\Services\WhatsAppOnboardingService;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class WhatsAppConnectionController extends ApiController
{
    public function __construct(private AuditLogger $audit, private WhatsAppOnboardingService $onboarding)
    {
    }

    public function index()
    {
        $connections = WhatsAppConnection::query()->with('connector:id,name')->latest()->get()
            ->map(fn (WhatsAppConnection $c) => $this->present($c));

        return $this->successResponse('WhatsApp connections retrieved successfully.', $connections->values());
    }

    /**
     * What the "Connect WhatsApp" button needs, and whether it is available.
     */
    public function signupConfig()
    {
        $configured = WhatsAppOnboardingService::isConfigured();

        return $this->successResponse('WhatsApp connection options retrieved successfully.', [
            'embedded_signup' => [
                'enabled' => $configured,
                'app_id' => $configured ? config('services.whatsapp.app_id') : null,
                'config_id' => $configured ? config('services.whatsapp.embedded_signup_config_id') : null,
                'graph_version' => $this->graphVersion(),
            ],
            'manual_connect' => (bool) config('services.whatsapp.manual_connect'),
        ]);
    }

    /**
     * Complete Embedded Signup: the popup's code (plus the ids it reported)
     * becomes a working connection. Everything technical happens here.
     */
    public function embeddedSignup(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:2000'],
            'waba_id' => ['nullable', 'string', 'max:64'],
            'phone_number_id' => ['nullable', 'string', 'max:64'],
        ]);

        $business = Business::query()->findOrFail(TenantManager::businessId());
        $connection = $this->onboarding->connect($business, $request->user(), $validated);

        $this->audit->log('whatsapp_connection.connected', $connection, null, null,
            $connection->only('phone_number_id', 'business_account_id', 'display_phone_number', 'verified_name'));

        return $this->successResponse('WhatsApp connected.', $this->present($connection), 201);
    }

    /**
     * Local-development fallback: paste a phone number id and token by hand.
     */
    public function store(StoreConnectionRequest $request)
    {
        if (!config('services.whatsapp.manual_connect')) {
            return $this->errorResponse('Manual WhatsApp connection is disabled. Use "Connect WhatsApp".', 403);
        }

        $connection = WhatsAppConnection::updateOrCreate(
            ['phone_number_id' => $request->validated('phone_number_id')],
            [
                'business_account_id' => $request->validated('business_account_id'),
                'access_token_encrypted' => $request->validated('access_token'),
                'status' => 'active',
                'onboarding_source' => WhatsAppConnection::SOURCE_MANUAL,
                'connected_by' => $request->user()->id,
                'connected_at' => Carbon::now(),
                'last_verified_at' => null,
                'last_error' => null,
            ],
        );

        $this->audit->log(
            $connection->wasRecentlyCreated ? 'whatsapp_connection.created' : 'whatsapp_connection.updated',
            $connection, null, null, $connection->only('phone_number_id', 'business_account_id', 'status'),
        );

        return $this->successResponse('WhatsApp connection saved successfully.', $this->present($connection), 201);
    }

    /**
     * Check the stored credentials against the Cloud API and refresh what
     * Meta knows about the number.
     */
    public function verify(int $connection, WhatsAppCloudApi $api)
    {
        $connection = WhatsAppConnection::query()->findOrFail($connection);

        try {
            $phone = $api->phoneNumber($connection->access_token_encrypted, $connection->phone_number_id);
            $ok = !empty($phone['id']);
        } catch (Throwable $e) {
            $ok = false;
            $phone = [];
            $connection->update(['last_error' => $e->getMessage()]);
        }

        $connection->update($ok
            ? array_filter([
                'status' => 'active',
                'last_verified_at' => now(),
                'last_error' => null,
                'display_phone_number' => $phone['display_phone_number'] ?? null,
                'verified_name' => $phone['verified_name'] ?? null,
                'quality_rating' => $phone['quality_rating'] ?? null,
                'messaging_limit_tier' => $phone['messaging_limit_tier'] ?? null,
            ], fn ($v) => $v !== null)
            : ['status' => 'error']);

        return $ok
            ? $this->successResponse('WhatsApp connection verified.', $this->present($connection->fresh()))
            : $this->errorResponse('WhatsApp rejected the stored credentials. Reconnect your number.', 422);
    }

    /**
     * Re-run the best-effort setup steps (billing, templates) for a
     * connection made through Embedded Signup.
     */
    public function retrySetup(int $connection)
    {
        $connection = WhatsAppConnection::query()->findOrFail($connection);
        if (!$connection->isEmbedded() || !$connection->business_account_id) {
            return $this->errorResponse('Setup can only be retried for numbers connected with "Connect WhatsApp".', 422);
        }

        try {
            $this->onboarding->resubscribeWebhooks($connection);
        } catch (\Throwable) {
            // Sending still works; inbound may stay on the app-level callback.
        }

        $billing = $connection->credit_line_attached_at ? true : $this->onboarding->attachCreditLine($connection);
        $templates = $this->onboarding->syncTemplates($connection);

        return $this->successResponse(
            $billing ? 'Setup complete.' : 'Templates synced; billing setup is still pending.',
            $this->present($connection->fresh()) + ['templates_submitted' => $templates],
        );
    }

    public function destroy(int $connection)
    {
        $connection = WhatsAppConnection::query()->findOrFail($connection);
        $connection->update(['status' => 'disconnected', 'access_token_encrypted' => 'revoked']);
        $this->audit->log('whatsapp_connection.disconnected', $connection, null, null, ['status' => 'disconnected']);

        return $this->successResponse('WhatsApp connection disconnected successfully.');
    }

    private function present(WhatsAppConnection $connection): array
    {
        $templates = MessageTemplate::query()->where('is_platform', true)->get(['name', 'status', 'rejection_reason']);

        return $connection->toArray() + [
            'quality' => $connection->qualityLabel(),
            'connector' => $connection->relationLoaded('connector') && $connection->connector
                ? ['id' => $connection->connector->id, 'name' => $connection->connector->name]
                : null,
            'templates' => [
                'total' => $templates->count(),
                'approved' => $templates->where('status', 'approved')->count(),
                'pending' => $templates->where('status', 'pending')->count(),
                'rejected' => $templates->where('status', 'rejected')->values()->map(fn ($t) => ['name' => $t->name, 'reason' => $t->rejection_reason]),
            ],
        ];
    }

    private function graphVersion(): string
    {
        preg_match('#/(v\d+\.\d+)/?$#', (string) config('services.whatsapp.graph_url'), $m);

        return $m[1] ?? 'v22.0';
    }
}
