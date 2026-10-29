<?php

namespace Omnitrade\PayPal\Tests;

use Omnitrade\PayPal\Api;
use Omnitrade\Exception\ProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Nothing of PayPal's credentials anywhere in what a log of a failed call writes: the messages,
 * the previous exception's (a copy of the HTTP client's, the secrets masked) and the traces'
 * arguments, which PHP keeps unless zend.exception_ignore_args is on.
 */
final class UnreachableChainTest extends TestCase
{
    private const SECRETS = ['paypal-secret-6f2a81', 'issued-token-paypal-9d3e57'];

    private string|false $ignoreArgs = false;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', false !== $this->ignoreArgs ? $this->ignoreArgs : '1');
    }

    /** @return iterable<string, array{\Closure(Api): mixed}> */
    public static function calls(): iterable
    {
        yield 'order' => [static fn (Api $api): mixed => $api->call('GET', '/v2/checkout/orders/5O190127TN364715T')];
    }

    #[DataProvider('calls')]
    public function testNoPartOfAnUnreachableChainHoldsTheSecrets(\Closure $call): void
    {
        self::assertClean($call, static function (string $method, string $url): never {
            throw new TransportException(\sprintf('Could not resolve host: x for "%s".', $url), 6);
        });
    }

    #[DataProvider('calls')]
    public function testAnUnreachableCallAfterTheTokenHoldsNoSecret(\Closure $call): void
    {
        self::assertClean($call, static function (string $method, string $url): MockResponse {
            if (str_contains($url, '/v1/oauth2/token')) {
                return new MockResponse(json_encode(['access_token' => 'issued-token-paypal-9d3e57', 'expires_in' => 3600]));
            }

            throw new TransportException(\sprintf('Could not resolve host: x for "%s".', $url), 6);
        });
    }

    #[DataProvider('calls')]
    public function testAnErrorAnswerKeepsNoSecretInTheTraces(\Closure $call): void
    {
        self::assertClean($call, static fn (string $method, string $url): MockResponse => str_contains($url, '/v1/oauth2/token') ? new MockResponse(json_encode(['access_token' => 'issued-token-paypal-9d3e57', 'expires_in' => 3600])) : new MockResponse('{"message":"down"}', ['http_code' => 500]));
    }

    private static function assertClean(\Closure $call, \Closure $responses): void
    {
        $api = new Api(new MockHttpClient($responses), 'paypal-client-id', 'paypal-secret-6f2a81', true);
        try {
            $call($api);
            self::fail('no exception');
        } catch (ProviderException $e) {
            self::assertNotEmpty($e->getTrace());
            for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                // A boolean, not assertStringNotContainsString(): a trace with its arguments is too large to export.
                $trace = print_r($one->getTrace(), true).$one->getTraceAsString();
                foreach (self::SECRETS as $secret) {
                    self::assertStringNotContainsString($secret, $one->getMessage());
                    self::assertFalse(str_contains($trace, $secret), \sprintf('The trace of %s holds "%s".', $one::class, $secret));
                }
            }
        }
    }
}
