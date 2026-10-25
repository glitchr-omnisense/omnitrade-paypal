<?php

namespace Omnitrade\PayPal\Tests;

use Omnitrade\GatewayFactoryInterface;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Omnitrade\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Omnitrade's conformance suite, on PayPal's recorded answers: an order authorized, then captured or voided. */
final class PayPalContractTest extends TestCase
{
    use GatewayContractTests;

    private const ORDER = '5O190127TN364715T';

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

    /** The capture: an order only authorized has nothing to refund. */
    protected function refundReference(): string
    {
        return 'CAP-3C679366HH908993F';
    }

    protected function recordedAnswers(): array
    {
        $authorization = 'CREATED';
        $orders = '/v2/checkout/orders';

        return [
            'POST /v1/oauth2/token' => ['access_token' => 'A21AA', 'expires_in' => 32400],
            "POST $orders" => static fn (string $method, string $url, array $options) => new MockResponse(json_encode(self::order('CREATED', json_decode($options['body'], true)['intent'] ?? 'CAPTURE'))),
            "GET $orders/".self::ORDER => static function () use (&$authorization): MockResponse {
                return new MockResponse(json_encode(self::order('COMPLETED', 'AUTHORIZE', $authorization)));
            },
            'POST /v2/payments/authorizations/0VF52814937998046/capture' => static function () use (&$authorization): MockResponse {
                $authorization = 'CAPTURED';

                return new MockResponse(json_encode(['id' => '3C679366HH908993F', 'status' => 'COMPLETED']));
            },
            'POST /v2/payments/authorizations/0VF52814937998046/void' => static function () use (&$authorization): MockResponse {
                $authorization = 'VOIDED';

                return new MockResponse('', ['http_code' => 204]);
            },
            'POST /v2/payments/captures/CAP-3C679366HH908993F/refund' => ['id' => '1JU08902781691411', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '5.00']],
            'POST /v1/notifications/verify-webhook-signature' => static fn (string $method, string $url, array $options) => new MockResponse(json_encode(['verification_status' => 'SUCCESS' === (json_decode($options['body'], true)['transmission_sig'] ?? null) ? 'SUCCESS' : 'FAILURE'])),
        ];
    }

    protected function signedNotification(): ?array
    {
        return [self::event(), self::headers('SUCCESS')];
    }

    protected function tamperedNotification(): array
    {
        return [self::event(), self::headers('forged')];
    }

    private static function event(): string
    {
        return json_encode(['id' => 'WH-EVT-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => '3C679366HH908993F', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '12.50'], 'supplementary_data' => ['related_ids' => ['order_id' => self::ORDER]]]]);
    }

    private static function headers(string $signature): array
    {
        return ['PAYPAL-TRANSMISSION-ID' => 't1', 'PAYPAL-TRANSMISSION-TIME' => '2026-10-01T10:00:00Z', 'PAYPAL-TRANSMISSION-SIG' => $signature, 'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/cert', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA'];
    }

    /** An order; AUTHORIZE ones once approved carry their authorization, CAPTURED along with its capture. */
    private static function order(string $status, string $intent, ?string $authorization = null): array
    {
        $amount = ['currency_code' => 'EUR', 'value' => '12.50'];
        $unit = ['reference_id' => 'default', 'custom_id' => 'ORDER-1042', 'amount' => $amount];
        if (null !== $authorization) {
            $unit['payments']['authorizations'] = [['id' => '0VF52814937998046', 'status' => $authorization, 'amount' => $amount]];
            if ('CAPTURED' === $authorization) {
                $unit['payments']['captures'] = [['id' => '3C679366HH908993F', 'status' => 'COMPLETED', 'amount' => $amount]];
            }
        }

        return ['id' => self::ORDER, 'intent' => $intent, 'status' => $status, 'create_time' => '2026-10-01T10:00:00Z', 'purchase_units' => [$unit],
            'links' => [['href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.self::ORDER, 'rel' => 'payer-action']]];
    }
}
