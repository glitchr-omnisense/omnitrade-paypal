<?php

namespace Omnitrade\PayPal\Tests;

use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use Omnitrade\Exception\RateLimitedException;
use Omnitrade\Exception\UsageLimitReachedException;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Omnitrade\Registry;
use Omnitrade\Tests\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * PayPal's usage policy (its limits unknown: PayPal does not publish them)
 * and a 429 RATE_LIMIT_REACHED to an order's creation: sent once with its
 * PayPal-Request-Id, surfaced, the next calls held unsent for the wait.
 */
final class PayPalPolicyTest extends TestCase
{
    /** @var list<array{string, string, array<mixed>}> method, url, headers */
    private array $calls = [];

    public function testThePolicyIsDeclaredAsData(): void
    {
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();
        self::assertCount(1, $declared, 'composer.json\'s extra.omnisense.policy names it');
        self::assertSame(['omnitrade/paypal', 'policy.json'], [$declared[0]['package'], $declared[0]['file']]);
        self::assertArrayNotHasKey('error', $declared[0], 'it reads as the schema says');
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('paypal', $policy->provider);
        self::assertNull($policy->rateLimits, 'not published: unknown');
        self::assertFalse($policy->knowsLimits());
        $registry = new Registry([new PayPalGatewayFactory(new MockHttpClient())], ['paypal' => ['factory' => 'paypal', 'options' => ['client_id' => 'c', 'secret' => 's']]]);
        self::assertEquals($policy, $registry->policy('paypal'));
    }

    public function testA429ToAnOrderIsSurfacedNotRetriedAndHoldsTheNextCalls(): void
    {
        $gateway = $this->gateway(
            new MockResponse('{"access_token":"A21AA","expires_in":32400}'),
            new MockResponse('{"name":"RATE_LIMIT_REACHED","message":"Too many requests. Blocked due to rate limiting."}', ['http_code' => 429, 'response_headers' => ['retry-after' => '60']]),
        );

        try {
            $gateway->purchase(Fixtures::payment());
            self::fail('a 429');
        } catch (RateLimitedException $e) {
            self::assertSame(60, $e->retryAfter);
            self::assertStringContainsString('rate limiting', $e->getMessage());
        }
        self::assertCount(2, $this->calls, 'the token, then the order once');
        self::assertContains('PayPal-Request-Id: ORDER-1042-attempt-1', $this->calls[1][2], 'retried with the same id, PayPal makes one order');

        $this->expectException(UsageLimitReachedException::class);
        try {
            $gateway->purchase(Fixtures::payment());
        } finally {
            self::assertCount(2, $this->calls, 'held, not sent');
        }
    }

    private function gateway(MockResponse ...$answers): \Omnitrade\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->calls[] = [$method, $url, \is_array($options['headers'] ?? null) ? $options['headers'] : []];

            return array_shift($answers) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return (new PayPalGatewayFactory($http))->create(['client_id' => 'cid', 'secret' => 'sec', 'sandbox' => true]);
    }
}
