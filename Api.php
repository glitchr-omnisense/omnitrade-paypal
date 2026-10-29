<?php

namespace Omnitrade\PayPal;

use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Exception\ProviderException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PayPal's REST API (v2 Orders, v2 Payments, v1 webhook verification), on an
 * OAuth2 client-credentials token fetched once and kept until it expires.
 * The sandbox and the live site are the same API at two addresses.
 */
final class Api
{
    public const LIVE = 'https://api-m.paypal.com';
    public const SANDBOX = 'https://api-m.sandbox.paypal.com';

    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clientId,
        private readonly string $secret,
        public readonly bool $sandbox = false,
        private readonly ?string $webhookId = null,
        private readonly int $timeout = 15,
    ) {
    }

    public function base(): string
    {
        return $this->sandbox ? self::SANDBOX : self::LIVE;
    }

    /**
     * An order as it stands once authorized: an intent AUTHORIZE order the
     * buyer approved holds no authorization until the merchant asks for it
     * (POST /v2/checkout/orders/{id}/authorize, once per order: its
     * PayPal-Request-Id is "<order>-authorize"); then the order read again.
     * Any other order is given back as it is.
     *
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function authorized(array $order): array
    {
        $id = (string) ($order['id'] ?? '');
        if ('' === $id || 'AUTHORIZE' !== ($order['intent'] ?? null) || 'APPROVED' !== ($order['status'] ?? null) || isset($order['purchase_units'][0]['payments']['authorizations'][0]['id'])) {
            return $order;
        }
        $this->call('POST', '/v2/checkout/orders/'.rawurlencode($id).'/authorize', [], ['PayPal-Request-Id' => $id.'-authorize']);

        return $this->call('GET', '/v2/checkout/orders/'.rawurlencode($id));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed> the JSON answer (empty for 204)
     *
     * @throws ProviderException
     */
    public function call(string $method, string $path, ?array $body = null, array $headers = []): array
    {
        try {
            $response = $this->http->request($method, $this->base().$path, [
                'headers' => ['Authorization' => 'Bearer '.$this->token(), 'Content-Type' => 'application/json', 'Prefer' => 'return=representation'] + $headers,
                'body' => null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
                'timeout' => $this->timeout,
            ]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface|\JsonException $e) {
            throw new ProviderException('paypal', 'PayPal request failed: '.$e->getMessage(), null, $e);
        }
        $data = '' === $content ? [] : json_decode($content, true);
        if (!\is_array($data)) {
            throw new ProviderException('paypal', sprintf('PayPal answered HTTP %d with a body that is not JSON.', $status));
        }
        if ($status >= 400) {
            $detail = $data['details'][0]['description'] ?? $data['message'] ?? $data['error_description'] ?? sprintf('HTTP %d', $status);
            throw new ProviderException('paypal', (string) $detail, $data['name'] ?? $data['details'][0]['issue'] ?? $data['error'] ?? null);
        }

        return $data;
    }

    /**
     * Whether a webhook was sent by PayPal to this webhook id: PayPal itself
     * checks the transmission's signature (POST /v1/notifications/verify-webhook-signature).
     *
     * @param array<string, string> $transmission the PAYPAL-* headers
     *
     * @throws InvalidNotificationException
     */
    public function verifyWebhook(string $body, array $transmission): void
    {
        if (null === $this->webhookId || '' === $this->webhookId) {
            throw new InvalidNotificationException('paypal', 'No webhook_id: the notification cannot be checked.');
        }
        $event = json_decode($body, true);
        if (!\is_array($event)) {
            throw new InvalidNotificationException('paypal', 'The notification is not JSON.');
        }
        foreach (['transmission_id', 'transmission_time', 'transmission_sig', 'cert_url', 'auth_algo'] as $key) {
            if ('' === (string) ($transmission[$key] ?? '')) {
                throw new InvalidNotificationException('paypal', sprintf('The notification lacks its %s header.', $key));
            }
        }
        $answer = $this->call('POST', '/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $transmission['auth_algo'],
            'cert_url' => $transmission['cert_url'],
            'transmission_id' => $transmission['transmission_id'],
            'transmission_sig' => $transmission['transmission_sig'],
            'transmission_time' => $transmission['transmission_time'],
            'webhook_id' => $this->webhookId,
            'webhook_event' => $event,
        ]);
        if ('SUCCESS' !== ($answer['verification_status'] ?? null)) {
            throw new InvalidNotificationException('paypal', 'PayPal does not recognise the notification\'s signature.');
        }
    }

    private function token(): string
    {
        if (null !== $this->token && time() < $this->tokenExpiresAt - 60) {
            return $this->token;
        }
        try {
            $response = $this->http->request('POST', $this->base().'/v1/oauth2/token', [
                'auth_basic' => [$this->clientId, $this->secret],
                'body' => ['grant_type' => 'client_credentials'],
                'timeout' => $this->timeout,
            ]);
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException('paypal', 'PayPal did not answer for a token: '.$e->getMessage(), null, $e);
        }
        if (empty($data['access_token'])) {
            throw new ProviderException('paypal', (string) ($data['error_description'] ?? 'PayPal gave no token: check the client id and secret.'), $data['error'] ?? null);
        }
        $this->token = (string) $data['access_token'];
        $this->tokenExpiresAt = time() + (int) ($data['expires_in'] ?? 3600);

        return $this->token;
    }
}
