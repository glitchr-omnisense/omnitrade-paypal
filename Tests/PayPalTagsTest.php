<?php

namespace Omnitrade\PayPal\Tests;

use Omnitrade\GatewayInterface;
use Omnitrade\Model\Money;
use Omnitrade\PayPal\Data;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Omnitrade\Tests\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The gateway's site (the "tags" option) at PayPal, which keeps no metadata
 * map: the order's purchase unit's reference_id and the refund's custom_id
 * name it, on recorded requests; the order and refund events give it back.
 * custom_id stays the merchant's reference on the order.
 */
final class PayPalTagsTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $calls = [];

    public function testAnOrderAndARefundNameTheSite(): void
    {
        $gateway = $this->gateway(['site' => 'solstay', 'env' => 'demo']);
        $gateway->purchase(Fixtures::payment());
        $gateway->refund('CAP-3C679366HH908993F', Money::of(500, 'EUR'), 'refund-42');

        $unit = Data::of($this->sent('/v2/checkout/orders'))->data('purchase_units.0');
        self::assertSame('solstay', $unit->get('reference_id'));
        self::assertSame('ORDER-1042', $unit->get('custom_id'), 'the merchant\'s reference, as before');
        self::assertSame('solstay', $this->sent('/v2/payments/captures/CAP-3C679366HH908993F/refund')['custom_id']);
    }

    public function testWithoutASiteTheOrderIsAsBefore(): void
    {
        $gateway = $this->gateway([]);
        $gateway->purchase(Fixtures::payment());
        $gateway->refund('CAP-3C679366HH908993F', Money::of(500, 'EUR'));

        self::assertSame('default', Data::of($this->sent('/v2/checkout/orders'))->get('purchase_units.0.reference_id'));
        self::assertArrayNotHasKey('custom_id', $this->sent('/v2/payments/captures/CAP-3C679366HH908993F/refund'));
    }

    public function testTheEventsGiveTheSiteBack(): void
    {
        $gateway = $this->gateway(['site' => 'solstay']);

        $order = $gateway->notify(...self::event('CHECKOUT.ORDER.APPROVED', ['id' => '5O190127TN364715T', 'status' => 'APPROVED', 'purchase_units' => [['reference_id' => 'wineseller', 'custom_id' => 'W-1']]]));
        self::assertTrue($order->isFromAnotherSite('solstay'));

        $refund = $gateway->notify(...self::event('PAYMENT.CAPTURE.REFUNDED', ['id' => '1JU08902781691411', 'custom_id' => 'solstay', 'supplementary_data' => ['related_ids' => ['order_id' => '5O190127TN364715T']]]));
        self::assertSame('solstay', $refund->site());

        $capture = $gateway->notify(...self::event('PAYMENT.CAPTURE.COMPLETED', ['id' => '3C679366HH908993F', 'custom_id' => 'ORDER-1042']));
        self::assertSame([], $capture->tags, 'a capture\'s custom_id is the merchant\'s reference: no site');
        self::assertFalse($capture->isFromAnotherSite('solstay'));

        $untagged = $gateway->notify(...self::event('CHECKOUT.ORDER.APPROVED', ['id' => 'X', 'purchase_units' => [['reference_id' => 'default']]]));
        self::assertSame([], $untagged->tags);
    }

    /** @param array<string, mixed> $tags */
    private function gateway(array $tags): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $body = $options['body'] ?? '';
            $sent = \is_string($body) && str_starts_with($body, '{') ? json_decode($body, true) : [];
            $this->calls[] = [$method, $path, Data::map($sent)];

            return new MockResponse((string) json_encode(match (true) {
                '/v1/oauth2/token' === $path => ['access_token' => 'A21AA', 'expires_in' => 32400],
                '/v2/checkout/orders' === $path => ['id' => '5O190127TN364715T', 'status' => 'CREATED', 'intent' => 'CAPTURE', 'purchase_units' => [['amount' => ['currency_code' => 'EUR', 'value' => '12.50']]], 'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T']]],
                str_ends_with($path, '/refund') => ['id' => '1JU08902781691411', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '5.00']],
                '/v1/notifications/verify-webhook-signature' === $path => ['verification_status' => 'SUCCESS'],
                default => ['name' => 'RESOURCE_NOT_FOUND'],
            }, \JSON_THROW_ON_ERROR));
        });

        return (new PayPalGatewayFactory($http))->create(['client_id' => 'cid', 'secret' => 'sec', 'sandbox' => true, 'webhook_id' => 'WH-1'] + ([] === $tags ? [] : ['tags' => $tags]));
    }

    /** @return array<string, mixed> */
    private function sent(string $path): array
    {
        $calls = array_values(array_filter($this->calls, static fn (array $call) => 'POST' === $call[0] && $call[1] === $path));
        self::assertCount(1, $calls, $path);

        return $calls[0][2];
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return array{string, array<string, string>}
     */
    private static function event(string $type, array $resource): array
    {
        return [(string) json_encode(['id' => 'WH-EVT-'.$type, 'event_type' => $type, 'resource' => $resource], \JSON_THROW_ON_ERROR), [
            'PAYPAL-TRANSMISSION-ID' => 't-1', 'PAYPAL-TRANSMISSION-TIME' => '2026-11-10T00:00:00Z', 'PAYPAL-TRANSMISSION-SIG' => 'SUCCESS',
            'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
        ]];
    }
}
