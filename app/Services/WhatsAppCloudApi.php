<?php

namespace App\Services;

use App\Models\Message;
use App\Models\WhatsAppConnection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client around the Meta WhatsApp Cloud API. Every call is made with the
 * connection's own access token, which never leaves the server.
 */
class WhatsAppCloudApi
{
    public function __construct(private ?string $baseUrlOverride = null)
    {
    }

    /** Read on every call so configuration changes (and tests) take effect immediately. */
    private function baseUrl(): string
    {
        return rtrim($this->baseUrlOverride ?? config('services.whatsapp.graph_url', 'https://graph.facebook.com/v20.0'), '/');
    }

    /**
     * Send an outbound message and return the WhatsApp message id.
     *
     * @throws WhatsAppApiException
     */
    public function send(WhatsAppConnection $connection, string $to, Message $message): string
    {
        $payload = $this->payloadFor($to, $message);

        try {
            $response = Http::withToken($connection->access_token_encrypted)
                ->acceptJson()
                ->timeout(20)
                ->post("{$this->baseUrl()}/{$connection->phone_number_id}/messages", $payload)
                ->throw();
        } catch (RequestException $e) {
            $error = $e->response?->json('error') ?? [];
            $retryAfter = $e->response?->header('Retry-After');
            $code = (int) ($error['code'] ?? ($e->response ? $e->response->status() : 0));
            throw new WhatsAppApiException(
                self::humanizeError((string) ($error['message'] ?? $e->getMessage()), $code),
                $code,
                $e->response ? $e->response->status() : 0,
                $retryAfter !== null && is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        $id = $response->json('messages.0.id');
        if (!$id) {
            throw new WhatsAppApiException('WhatsApp API returned no message id.', 0, $response->status());
        }

        return $id;
    }

    /**
     * Verify a connection's credentials by reading the phone number record.
     */
    public function verify(WhatsAppConnection $connection): bool
    {
        $response = Http::withToken($connection->access_token_encrypted)
            ->acceptJson()
            ->timeout(15)
            ->get("{$this->baseUrl()}/{$connection->phone_number_id}");

        return $response->successful();
    }

    // ------------------------------------------------------ Embedded Signup

    /**
     * Swap the code returned by the Embedded Signup popup for a business
     * token scoped to the WABA the user just shared with us.
     */
    public function exchangeCode(string $code): string
    {
        $response = $this->call(fn () => Http::acceptJson()->timeout(20)->get("{$this->baseUrl()}/oauth/access_token", [
            'client_id' => config('services.whatsapp.app_id'),
            'client_secret' => config('services.whatsapp.app_secret'),
            'code' => $code,
        ]));

        $token = $response->json('access_token');
        if (!$token) {
            throw new WhatsAppApiException('Meta did not return an access token for this connection.', 0, $response->status());
        }

        return $token;
    }

    /**
     * WABA ids the token was granted for (used when the popup did not tell
     * us which account the user picked).
     */
    public function grantedWabaIds(string $token): array
    {
        $response = $this->call(fn () => Http::acceptJson()->timeout(15)->get("{$this->baseUrl()}/debug_token", [
            'input_token' => $token,
            'access_token' => config('services.whatsapp.app_id') . '|' . config('services.whatsapp.app_secret'),
        ]));

        $ids = [];
        foreach ($response->json('data.granular_scopes', []) as $scope) {
            if (in_array($scope['scope'] ?? '', ['whatsapp_business_management', 'whatsapp_business_messaging'], true)) {
                $ids = array_merge($ids, $scope['target_ids'] ?? []);
            }
        }

        return array_values(array_unique($ids));
    }

    /** Phone numbers on a WABA, newest first. */
    public function wabaPhoneNumbers(string $token, string $wabaId): array
    {
        $response = $this->call(fn () => Http::withToken($token)->acceptJson()->timeout(15)
            ->get("{$this->baseUrl()}/{$wabaId}/phone_numbers", ['fields' => 'id,display_phone_number,verified_name,quality_rating,messaging_limit_tier,platform_type,code_verification_status']));

        return $response->json('data', []);
    }

    /** Details for one phone number id. */
    public function phoneNumber(string $token, string $phoneNumberId): array
    {
        $response = $this->call(fn () => Http::withToken($token)->acceptJson()->timeout(15)
            ->get("{$this->baseUrl()}/{$phoneNumberId}", ['fields' => 'id,display_phone_number,verified_name,quality_rating,messaging_limit_tier,platform_type,code_verification_status']));

        return $response->json() ?? [];
    }

    /** Route the WABA's webhooks (messages, statuses, template updates) to this app. */
    public function subscribeApp(string $token, string $wabaId): void
    {
        $this->call(fn () => Http::withToken($token)->acceptJson()->timeout(15)->post("{$this->baseUrl()}/{$wabaId}/subscribed_apps"));
    }

    /**
     * Register the number for Cloud API sending with a two-step PIN. A number
     * that is already registered (e.g. via Coexistence) is not an error.
     */
    public function registerPhone(string $token, string $phoneNumberId, string $pin): void
    {
        try {
            $this->call(fn () => Http::withToken($token)->acceptJson()->timeout(20)
                ->post("{$this->baseUrl()}/{$phoneNumberId}/register", ['messaging_product' => 'whatsapp', 'pin' => $pin]));
        } catch (WhatsAppApiException $e) {
            if (!str_contains(strtolower($e->getMessage()), 'already')) {
                throw $e;
            }
        }
    }

    /**
     * Bill the WABA's conversations to the platform's credit line so the
     * business never sees a Meta payment screen. Uses the platform token.
     */
    public function attachCreditLine(string $wabaId): void
    {
        $creditLine = config('services.whatsapp.credit_line_id');
        $platformToken = config('services.whatsapp.platform_token');
        if (!$creditLine || !$platformToken) {
            throw new WhatsAppApiException('Credit line sharing is not configured.', 0, 0);
        }

        $this->call(fn () => Http::withToken($platformToken)->acceptJson()->timeout(20)
            ->post("{$this->baseUrl()}/{$creditLine}/whatsapp_credit_sharing_and_attach", [
                'waba_id' => $wabaId,
                'waba_currency' => config('services.whatsapp.credit_line_currency', 'NGN'),
            ]));
    }

    /** Submit a template to the WABA; returns Meta's template id and status. */
    public function createTemplate(string $token, string $wabaId, string $name, string $language, string $category, string $body, array $examples = []): array
    {
        $component = ['type' => 'BODY', 'text' => $body];
        if ($examples) {
            $component['example'] = ['body_text' => [$examples]];
        }

        $response = $this->call(fn () => Http::withToken($token)->acceptJson()->timeout(20)
            ->post("{$this->baseUrl()}/{$wabaId}/message_templates", [
                'name' => $name,
                'language' => $language,
                'category' => strtoupper($category),
                'components' => [$component],
            ]));

        return ['id' => $response->json('id'), 'status' => strtolower((string) $response->json('status', 'pending'))];
    }

    /** Existing templates on a WABA keyed by name. */
    public function templates(string $token, string $wabaId): array
    {
        $response = $this->call(fn () => Http::withToken($token)->acceptJson()->timeout(20)
            ->get("{$this->baseUrl()}/{$wabaId}/message_templates", ['fields' => 'id,name,status,language,category', 'limit' => 200]));

        return collect($response->json('data', []))->keyBy('name')->all();
    }

    /**
     * Run a Graph call and turn HTTP errors into WhatsAppApiException.
     *
     * @param  callable(): \Illuminate\Http\Client\Response  $request
     */
    private function call(callable $request): \Illuminate\Http\Client\Response
    {
        try {
            return $request()->throw();
        } catch (RequestException $e) {
            $error = $e->response?->json('error') ?? [];
            $retryAfter = $e->response?->header('Retry-After');
            throw new WhatsAppApiException(
                $error['message'] ?? $e->getMessage(),
                (int) ($error['code'] ?? ($e->response ? $e->response->status() : 0)),
                $e->response ? $e->response->status() : 0,
                $retryAfter !== null && is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }
    }

    private function payloadFor(string $to, Message $message): array
    {
        $template = $message->template;

        // Meta-approved templates are sent as template messages so they can be
        // delivered outside the 24h customer service window.
        if ($template && $template->whatsapp_template_id) {
            $variables = $message->metadata['template_variables'] ?? [];
            $parameters = [];
            foreach ($template->placeholders() as $name) {
                $val = trim((string) ($variables[$name] ?? ''));
                if ($val === '') {
                    // Meta strictly disallows empty parameter text with error #131008
                    $val = '-';
                }
                $parameters[] = ['type' => 'text', 'text' => $val];
            }

            return [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => $template->whatsapp_template_id,
                    'language' => ['code' => $template->language ?: 'en'],
                    'components' => $parameters ? [['type' => 'body', 'parameters' => $parameters]] : [],
                ],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => (string) $message->body],
        ];
    }

    /**
     * Turn Meta error codes that operators hit with inbox free-text into a
     * sentence they can act on. Template / broadcast payloads are unchanged.
     */
    public static function humanizeError(string $message, int $code): string
    {
        if ($code === 131047 || str_contains(strtolower($message), 'more than 24 hours')) {
            return 'WhatsApp only allows free-text replies within 24 hours of the customer\'s last message. Choose an approved template to message them now.';
        }

        return $message;
    }
}

class WhatsAppApiException extends RuntimeException
{
    public function __construct(string $message, int $apiCode = 0, public readonly int $httpStatus = 0, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, $apiCode);
    }

    /**
     * Auth/permission errors mean the stored credentials are bad, not the message.
     */
    public function isCredentialError(): bool
    {
        return in_array($this->httpStatus, [401, 403], true) || in_array($this->getCode(), [190, 10, 200], true);
    }

    /**
     * Rate limit or server errors are transient and should trigger queue retries.
     */
    public function isTransient(): bool
    {
        return in_array($this->httpStatus, [429, 500, 502, 503, 504], true)
            || in_array($this->getCode(), [130429, 131056, 131051, 131052, 131053, 1, 2], true);
    }
}
