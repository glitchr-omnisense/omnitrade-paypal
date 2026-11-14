<?php

namespace Omnitrade\PayPal\Tests;

use Omnitrade\GatewayFactoryInterface;
use Omnitrade\Model\Customer;
use Omnitrade\PayPal\Data;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Omnitrade\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnitrade's conformance suite, on PayPal's recorded answers: an order authorized, then captured or voided; a PayPal account saved, its approved setup token turned into a payment token. */
final class PayPalContractTest extends TestCase
{
    use GatewayContractTests;

    private const ORDER = '5O190127TN364715T';
    private const CUSTOMER = 'customer_4029352050';
    private const SETUP = '5C991763VB2781612';

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new PayPalGatewayFactory($http);
    }

    protected function options(): array
    {
        return ['client_id' => 'cid', 'secret' => 'sec', 'sandbox' => true, 'webhook_id' => 'WH-1'];
    }

    protected function transactionReference(): string
    {
        return self::ORDER;
    }

    protected function setupCustomer(): Customer
    {
        return new Customer(email: 'camille@example.org', name: 'Camille Durand', reference: self::CUSTOMER);
    }

    protected function setupReference(): string
    {
        return self::SETUP;
    }

    /** The capture: an order only authorized has nothing to refund. */
    protected function refundReference(): string
    {
        return 'CAP-3C679366HH908993F';
    }

    /** @return array<string, array<mixed>|MockResponse|callable> */
    protected function recordedAnswers(): array
    {
        $authorization = 'CREATED';
        $orders = '/v2/checkout/orders';

        return [
            'POST /v1/oauth2/token' => ['access_token' => 'A21AA', 'expires_in' => 32400],
            "POST $orders" => static fn (string $method, string $url, array $options) => new MockResponse(json_encode(self::order('CREATED', Data::cast(Data::of(json_decode(Data::cast($options['body'] ?? null), true))->get('intent') ?? 'CAPTURE')), \JSON_THROW_ON_ERROR)),
            "GET $orders/".self::ORDER => static function () use (&$authorization): MockResponse {
                return new MockResponse(json_encode(self::order('COMPLETED', 'AUTHORIZE', $authorization), \JSON_THROW_ON_ERROR));
            },
            'POST /v2/payments/authorizations/0VF52814937998046/capture' => static function () use (&$authorization): MockResponse {
                $authorization = 'CAPTURED';

                return new MockResponse(json_encode(['id' => '3C679366HH908993F', 'status' => 'COMPLETED'], \JSON_THROW_ON_ERROR));
            },
            'POST /v2/payments/authorizations/0VF52814937998046/void' => static function () use (&$authorization): MockResponse {
                $authorization = 'VOIDED';

                return new MockResponse('', ['http_code' => 204]);
            },
            // Vault v3: the setup token made, approved by the buyer, then turned into a payment token.
            'POST /v3/vault/setup-tokens' => self::setupToken('PAYER_ACTION_REQUIRED'),
            'GET /v3/vault/setup-tokens/'.self::SETUP => self::setupToken('APPROVED'),
            'POST /v3/vault/payment-tokens' => ['id' => '8kk8451t', 'customer' => ['id' => self::CUSTOMER], 'payment_source' => ['paypal' => ['usage_type' => 'MERCHANT', 'customer_type' => 'CONSUMER', 'email_address' => 'camille@example.org', 'payer_id' => 'QYR5Z8XDVJNXQ']]],
            'POST /v2/payments/captures/CAP-3C679366HH908993F/refund' => ['id' => '1JU08902781691411', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '5.00']],
            'POST /v1/notifications/verify-webhook-signature' => static fn (string $method, string $url, array $options) => new MockResponse(json_encode(['verification_status' => 'SUCCESS' === Data::of(json_decode(Data::cast($options['body'] ?? null), true))->get('transmission_sig') ? 'SUCCESS' : 'FAILURE'], \JSON_THROW_ON_ERROR)),
        ];
    }

    /** @return array{0: string, 1: array<string, string>} */
    protected function signedNotification(): array
    {
        return [self::event(), self::headers('SUCCESS')];
    }

    /** @return array{0: string, 1: array<string, string>} */
    protected function tamperedNotification(): array
    {
        return [self::event(), self::headers('forged')];
    }

    /** @return array<string, mixed> */
    private static function setupToken(string $status): array
    {
        return ['id' => self::SETUP, 'customer' => ['id' => self::CUSTOMER], 'status' => $status, 'payment_source' => ['paypal' => ['usage_type' => 'MERCHANT', 'customer_type' => 'CONSUMER']],
            'links' => [['href' => 'https://www.sandbox.paypal.com/agreements/approve?approval_session_id=05C1734587219561N', 'rel' => 'approve', 'method' => 'GET']]];
    }

    private static function event(): string
    {
        return json_encode(['id' => 'WH-EVT-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => '3C679366HH908993F', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '12.50'], 'supplementary_data' => ['related_ids' => ['order_id' => self::ORDER]]]], \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private static function headers(string $signature): array
    {
        return ['PAYPAL-TRANSMISSION-ID' => 't1', 'PAYPAL-TRANSMISSION-TIME' => '2026-10-01T10:00:00Z', 'PAYPAL-TRANSMISSION-SIG' => $signature, 'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/cert', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA'];
    }

    /** An order; AUTHORIZE ones once approved carry their authorization, CAPTURED along with its capture. */
    /** @return array<string, mixed> */
    private static function order(string $status, string $intent, ?string $authorization = null): array
    {
        $amount = ['currency_code' => 'EUR', 'value' => '12.50'];
        $unit = ['reference_id' => 'default', 'custom_id' => 'ORDER-1042', 'amount' => $amount];
        if (null !== $authorization) {
            $payments = ['authorizations' => [['id' => '0VF52814937998046', 'status' => $authorization, 'amount' => $amount]]];
            if ('CAPTURED' === $authorization) {
                $payments['captures'] = [['id' => '3C679366HH908993F', 'status' => 'COMPLETED', 'amount' => $amount]];
            }
            $unit['payments'] = $payments;
        }

        return ['id' => self::ORDER, 'intent' => $intent, 'status' => $status, 'create_time' => '2026-10-01T10:00:00Z', 'purchase_units' => [$unit],
            'links' => [['href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.self::ORDER, 'rel' => 'payer-action']]];
    }
}
