<?php

namespace App\Http\Controllers\Api\V1;

use App\Jobs\ProcessWhatsAppWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends ApiController
{
    /**
     * Meta's subscription handshake.
     */
    public function verify(Request $request)
    {
        $expected = config('services.whatsapp.verify_token');

        if (!$expected || $request->query('hub_mode', 'subscribe') !== 'subscribe' || !hash_equals((string) $expected, (string) $request->query('hub_verify_token'))) {
            return response('Invalid verification token.', 403);
        }

        return response($request->query('hub_challenge', ''), 200);
    }

    /**
     * Accept a webhook, verify its signature and hand it to the queue. Nothing
     * expensive happens inside the request.
     */
    public function receive(Request $request)
    {
        if (!$this->signatureIsValid($request)) {
            Log::warning('WhatsApp webhook rejected: invalid signature.');
            return $this->errorResponse('Invalid webhook signature.', 401);
        }

        $payload = $this->payload($request);
        if (($payload['object'] ?? 'whatsapp_business_account') !== 'whatsapp_business_account') {
            return response()->json(['success' => true], 200);
        }

        Log::info('WhatsApp webhook received', ['entries' => count($payload['entry'] ?? [])]);

        // Handle inbound replies in the request so they are stored even when
        // no queue worker is listening on the "whatsapp" queue.
        try {
            (new ProcessWhatsAppWebhook($payload))->handle(
                app(\App\Services\WhatsAppService::class),
                app(\App\Services\WhatsAppOnboardingService::class),
            );
        } catch (\Throwable $e) {
            Log::error('WhatsApp webhook processing failed; queued for retry.', ['error' => $e->getMessage()]);
            ProcessWhatsAppWebhook::dispatch($payload);
        }

        return response()->json(['success' => true], 200);
    }

    /**
     * Meta posts JSON. Prefer the raw body so an empty/missing Content-Type
     * cannot drop customer replies.
     */
    private function payload(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);
        if (is_array($decoded) && $decoded !== []) {
            return $decoded;
        }

        $json = $request->json()->all();
        if ($json !== []) {
            return $json;
        }

        return $request->all();
    }

    /**
     * Meta signs the raw body with the app secret (X-Hub-Signature-256).
     * Validation is enforced whenever an app secret is configured.
     */
    private function signatureIsValid(Request $request): bool
    {
        $secret = config('services.whatsapp.app_secret');
        if (!$secret) {
            return true;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        if (!str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, substr($header, 7));
    }
}
