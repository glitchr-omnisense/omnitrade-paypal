<?php

namespace Omnitrade\PayPal\Tests;

use Omnitrade\Exception\ProviderException;
use Omnitrade\GatewayInterface;
use Omnitrade\Model\Customer;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Model\Status;
use Omnitrade\PayPal\Data;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Omnitrade\Request\CompleteMethodSetup;
use Omnitrade\Request\FetchSavedMethods;
use Omnitrade\Request\RemoveSavedMethod;
use Omnitrade\Request\SetupMethod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Saved methods (omnisense/omnitrade 1.1, the setup completed by 1.2's CompleteMethodSetup) on PayPal's Vault v3, on answers shaped as PayPal's
 * REST reference gives them (setup tokens, payment tokens, an order paid with a vault_id): no
 * call to PayPal. The ids are the reference's examples.
 */
final class PayPalSavedMethodsTest extends TestCase
{
    private const CUSTOMER = 'customer_4029352050';
    private const SETUP = '5C991763VB2781612';
    private const TOKEN = '8kk8451t';
    private const CARD = '3ff7xq3a';
    private const OTHERS = '9nn2zt4b';
    private const ORDER = '5O190127TN364715T';

    /** @var list<array{method: string, path: string, query: string, body: array<mixed>, headers: list<string>}> */
    private array $calls = [];
    private string $setupStatus = 'PAYER_ACTION_REQUIRED';
    /** What the order made with a saved method answers: COMPLETED, DECLINED, PAYER_ACTION_REQUIRED or a 422's issue. */
    private string $charge = 'COMPLETED';
    private string $orderStatus = 'PAYER_ACTION_REQUIRED';
    private int $listed = 2;

    /** @param array<string, mixed> $options */
    private function gateway(array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $query = (string) parse_url($url, \PHP_URL_QUERY);
            $body = $options['body'] ?? '';
            $sent = \is_string($body) && str_starts_with($body, '{') ? json_decode($body, true) : [];
            $this->calls[] = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => \is_array($sent) ? $sent : [], 'headers' => Data::texts($options['headers'] ?? [])];
            $json = static fn (array $answer, int $code = 200) => new MockResponse(json_encode($answer, \JSON_THROW_ON_ERROR), ['http_code' => $code]);

            return match (true) {
                '/v1/oauth2/token' === $path => $json(['access_token' => 'A21AA', 'expires_in' => 32400]),
                'POST' === $method && '/v3/vault/setup-tokens' === $path => $json(self::setupToken('PAYER_ACTION_REQUIRED', Data::of($sent)->textOrNull('customer.id') ?? self::CUSTOMER), 201),
                'GET' === $method && '/v3/vault/setup-tokens/'.self::SETUP === $path => $json(self::setupToken($this->setupStatus, self::CUSTOMER)),
                'POST' === $method && '/v3/vault/payment-tokens' === $path => (function () use ($json) { $this->setupStatus = 'VAULTED'; return $json(self::paypalToken(self::TOKEN, self::CUSTOMER), 201); })(),
                'GET' === $method && '/v3/vault/payment-tokens' === $path => $json($this->page($query)),
                'GET' === $method && '/v3/vault/payment-tokens/'.self::TOKEN === $path => $json(self::paypalToken(self::TOKEN, self::CUSTOMER)),
                'GET' === $method && '/v3/vault/payment-tokens/'.self::CARD === $path => $json(self::cardToken(self::CARD, self::CUSTOMER)),
                'GET' === $method && '/v3/vault/payment-tokens/'.self::OTHERS === $path => $json(self::paypalToken(self::OTHERS, 'customer_7700000001')),
                'DELETE' === $method && str_starts_with($path, '/v3/vault/payment-tokens/') => new MockResponse('', ['http_code' => 204]),
                'POST' === $method && '/v2/checkout/orders' === $path => $this->charged(\is_array($sent) ? $sent : []),
                'GET' === $method && '/v2/checkout/orders/'.self::ORDER === $path => $json(self::order($this->orderStatus, 'PAYER_ACTION_REQUIRED' === $this->orderStatus ? null : ('COMPLETED' === $this->orderStatus ? 'COMPLETED' : null))),
                'POST' === $method && '/v2/checkout/orders/'.self::ORDER.'/capture' === $path => (function () use ($json) { $this->orderStatus = 'COMPLETED'; return $json(self::order('COMPLETED', 'COMPLETED'), 201); })(),
                'POST' === $method && '/v1/notifications/verify-webhook-signature' === $path => $json(['verification_status' => 'SUCCESS']),
                default => $json(['name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID', 'description' => 'Specified resource ID does not exist.']], 'message' => 'The specified resource does not exist.', 'debug_id' => 'f2c5a1b3e4d6'], 404),
            };
        });

        return (new PayPalGatewayFactory($http))->create(['client_id' => 'cid', 'secret' => 'sec', 'sandbox' => true, 'webhook_id' => 'WH-1'] + $options);
    }

    public function testTheSetupAndItsCompletionAreSupportedByDefault(): void
    {
        $gateway = $this->gateway();
        foreach ([SetupMethod::class, CompleteMethodSetup::class, FetchSavedMethods::class, RemoveSavedMethod::class] as $request) {
            self::assertTrue($gateway->supports($request), $request);
        }
        foreach (['setupMethod', 'completeMethodSetup', 'fetchSavedMethods', 'removeSavedMethod'] as $operation) {
            self::assertContains($operation, $gateway->capabilities()->operations);
        }
        self::assertFalse(class_exists('Omnitrade\\PayPal\\Request\\CompleteMethodSetup'), 'the core\'s request, no PayPal-own one');
    }

    public function testAPayPalAccountIsSavedOnPayPalsPageThenCompleted(): void
    {
        $gateway = $this->gateway();

        $setup = $gateway->execute(new SetupMethod(new Customer('alice@example.org', 'Alice Exemple'), 'https://shop.example/saved', idempotencyKey: 'setup-42', locale: 'fr_FR'))->getSetup();
        self::assertNotNull($setup);
        self::assertSame(self::SETUP, $setup->reference);
        self::assertSame(self::CUSTOMER, $setup->customer, 'the customer PayPal made for the occasion');
        self::assertSame('https://www.sandbox.paypal.com/agreements/approve?approval_session_id=05C1734587219561N', $setup->redirectUrl);
        $made = $this->call('POST', '/v3/vault/setup-tokens');
        self::assertSame('MERCHANT', Data::of($made['body'])->get('payment_source.paypal.usage_type'));
        self::assertSame('https://shop.example/saved', Data::of($made['body'])->get('payment_source.paypal.experience_context.return_url'));
        self::assertSame('https://shop.example/saved', Data::of($made['body'])->get('payment_source.paypal.experience_context.cancel_url'));
        self::assertSame('fr-FR', Data::of($made['body'])->get('payment_source.paypal.experience_context.locale'));
        self::assertArrayNotHasKey('customer', $made['body'], 'no customer yet: PayPal makes one');
        self::assertContains('PayPal-Request-Id: setup-setup-42', $made['headers']);

        // Back before approving: nothing to save.
        try {
            $gateway->execute(new CompleteMethodSetup(self::SETUP, self::CUSTOMER));
            self::fail('a setup not approved is not completed');
        } catch (ProviderException $e) {
            self::assertSame(CompleteMethodSetup::NOT_COMPLETED, $e->providerCode);
            self::assertStringContainsString('PAYER_ACTION_REQUIRED', $e->getMessage(), 'PayPal\'s status in the message');
        }
        self::assertNull($this->called('POST', '/v3/vault/payment-tokens'));

        $this->setupStatus = 'APPROVED';
        $method = $gateway->execute(new CompleteMethodSetup(self::SETUP, self::CUSTOMER))->getMethod();
        self::assertNotNull($method);
        self::assertSame(self::TOKEN, $method->reference);
        self::assertSame(self::CUSTOMER, $method->customer);
        self::assertSame('PayPal alice@example.org', $method->label);
        self::assertSame(PaymentMethod::WALLET, $method->kind);
        $converted = $this->call('POST', '/v3/vault/payment-tokens');
        self::assertSame(['token' => ['id' => self::SETUP, 'type' => 'SETUP_TOKEN']], $converted['body']['payment_source']);
        self::assertContains('PayPal-Request-Id: '.self::SETUP.'-payment-token', $converted['headers']);

        self::assertSame(self::TOKEN, $gateway->execute(new CompleteMethodSetup(self::SETUP))->getMethod()?->reference, 'completed twice: the same token (PayPal-Request-Id)');

        $this->expectExceptionObject(new ProviderException('paypal', 'This setup is not the customer\'s.', 'not_this_customer'));
        $gateway->execute(new CompleteMethodSetup(self::SETUP, 'customer_7700000001'));
    }

    public function testASetupForAKnownCustomerAndOnlyForAPayPalAccount(): void
    {
        $gateway = $this->gateway();
        $setup = $gateway->execute(new SetupMethod(new Customer(reference: self::CUSTOMER), 'https://shop.example/saved', 'https://shop.example/account'))->getSetup();

        self::assertSame(self::CUSTOMER, $setup?->customer);
        $made = $this->call('POST', '/v3/vault/setup-tokens');
        self::assertSame(['id' => self::CUSTOMER], $made['body']['customer']);
        self::assertSame('https://shop.example/account', Data::of($made['body'])->get('payment_source.paypal.experience_context.cancel_url'));
        self::assertEmpty(array_filter($made['headers'], static fn (string $h) => str_starts_with($h, 'PayPal-Request-Id')), 'no key, no PayPal-Request-Id');

        $calls = \count($this->calls);
        try {
            $gateway->execute(new SetupMethod(new Customer(reference: self::CUSTOMER), 'https://shop.example/saved', method: 'card'));
            self::fail('a card is vaulted through the card fields');
        } catch (ProviderException $e) {
            self::assertSame('method_not_supported', $e->providerCode);
        }
        self::assertCount($calls, $this->calls, 'nothing sent');
    }

    public function testTheCustomersMethodsAreListedEveryPage(): void
    {
        $methods = $this->gateway()->execute(new FetchSavedMethods(self::CUSTOMER))->getMethods();

        self::assertCount(2, $methods);
        self::assertSame('PayPal alice@example.org', $methods[0]->label);
        $card = $methods[1];
        self::assertSame(self::CARD, $card->reference);
        self::assertSame('Visa •••• 1111', $card->label);
        self::assertSame(['card', PaymentMethod::CARD, 'visa', '1111', 2, 2030], [$card->code, $card->kind, $card->brand, $card->last4, $card->expiryMonth, $card->expiryYear]);
        self::assertSame(self::CUSTOMER, $card->customer);
        parse_str($this->call('GET', '/v3/vault/payment-tokens')['query'], $query);
        self::assertSame(['customer_id' => self::CUSTOMER, 'page_size' => '20', 'page' => '1', 'total_required' => 'true'], $query);

        $this->calls = [];
        $this->listed = 21;
        self::assertCount(21, $this->gateway()->execute(new FetchSavedMethods(self::CUSTOMER))->getMethods());
        self::assertCount(2, array_filter($this->calls, static fn (array $c) => '/v3/vault/payment-tokens' === $c['path']), 'two pages');
    }

    public function testOnlyTheCustomersOwnMethodIsRemoved(): void
    {
        $gateway = $this->gateway();

        self::assertTrue($gateway->execute(new RemoveSavedMethod(self::CUSTOMER, self::TOKEN))->isRemoved());
        self::assertNotNull($this->called('DELETE', '/v3/vault/payment-tokens/'.self::TOKEN));

        foreach ([self::OTHERS, 'unknown1', '../setup-tokens'] as $reference) {
            try {
                $gateway->execute(new RemoveSavedMethod(self::CUSTOMER, $reference));
                self::fail($reference.' is not the customer\'s');
            } catch (ProviderException $e) {
                self::assertSame('not_this_customer', $e->providerCode, $reference);
            }
        }
        self::assertCount(1, array_filter($this->calls, static fn (array $c) => 'DELETE' === $c['method']), 'only their own deleted');
    }

    public function testASavedMethodIsChargedAtOnceWithItsToken(): void
    {
        $paid = $this->gateway(['tags' => ['site' => 'atelier-exemple']])->purchase($this->payment(self::TOKEN));

        self::assertSame(Status::PAID, $paid->status);
        self::assertSame(self::ORDER, $paid->reference);
        self::assertNull($paid->redirectUrl, 'no page');
        self::assertSame('3C679366HH908993F', $paid->metadata['capture']);
        $order = $this->call('POST', '/v2/checkout/orders');
        self::assertSame('CAPTURE', $order['body']['intent']);
        self::assertSame(['paypal' => ['vault_id' => self::TOKEN, 'experience_context' => ['return_url' => 'https://shop.example/orders/7', 'cancel_url' => 'https://shop.example/orders/7', 'shipping_preference' => 'NO_SHIPPING']]], $order['body']['payment_source']);
        self::assertSame('19.90', Data::of($order['body'])->get('purchase_units.0.amount.value'));
        self::assertSame('ORDER-7', Data::of($order['body'])->get('purchase_units.0.custom_id'));
        self::assertSame('atelier-exemple', Data::of($order['body'])->get('purchase_units.0.reference_id'));
        self::assertContains('PayPal-Request-Id: ORDER-7-attempt-1', $order['headers']);
        self::assertNull($this->called('POST', '/v2/checkout/orders/'.self::ORDER.'/capture'), 'PayPal took it with the order');
    }

    public function testASavedCardIsChargedAsAMerchantInitiatedPayment(): void
    {
        $this->gateway()->purchase($this->payment(self::CARD, null));

        $source = $this->call('POST', '/v2/checkout/orders')['body']['payment_source'];
        self::assertSame(['card' => ['vault_id' => self::CARD, 'stored_credential' => ['payment_initiator' => 'MERCHANT', 'payment_type' => 'UNSCHEDULED', 'usage' => 'SUBSEQUENT']]], $source);
    }

    public function testARefusalIsARefusedTransactionWithPayPalsIssue(): void
    {
        $this->charge = 'INSTRUMENT_DECLINED';
        $refused = $this->gateway()->purchase($this->payment(self::TOKEN));
        self::assertSame(Status::REFUSED, $refused->status);
        self::assertSame('INSTRUMENT_DECLINED', $refused->providerCode);
        self::assertSame('f2c5a1b3e4d6', $refused->reference, 'no order: PayPal\'s debug id');
        self::assertSame(1990, $refused->amount?->amount);

        $this->charge = 'DECLINED';
        $declined = $this->gateway()->purchase($this->payment(self::TOKEN));
        self::assertSame(Status::REFUSED, $declined->status);
        self::assertSame(self::ORDER, $declined->reference);
        self::assertSame('DECLINED_BY_RISK_FRAUD_FILTERS', $declined->providerCode);

        $this->charge = 'INVALID_PARAMETER_VALUE';
        $this->expectException(ProviderException::class);
        $this->gateway()->purchase($this->payment(self::TOKEN));
    }

    public function testAnotherCustomersTokenIsNeverCharged(): void
    {
        foreach ([[self::OTHERS, self::CUSTOMER], [self::TOKEN, null]] as [$token, $customer]) {
            try {
                $this->gateway()->purchase(new Payment(Money::of(1990, 'EUR'), 'ORDER-7', customer: new Customer(reference: $customer), returnUrl: 'https://shop.example/orders/7', savedMethod: $token));
                self::fail('charged '.$token);
            } catch (ProviderException $e) {
                self::assertSame('not_this_customer', $e->providerCode);
            }
        }
        self::assertNull($this->called('POST', '/v2/checkout/orders'), 'no order made');
    }

    public function testPayPalAskingItsPayerSendsThemToItsPageThenFetchCaptures(): void
    {
        $this->charge = 'PAYER_ACTION_REQUIRED';
        $gateway = $this->gateway();
        $pending = $gateway->purchase($this->payment(self::TOKEN));

        self::assertTrue($pending->isRedirect());
        self::assertSame('https://www.sandbox.paypal.com/checkoutnow?token='.self::ORDER, $pending->redirectUrl);
        self::assertSame(Status::PENDING, $gateway->fetch(self::ORDER)->status, 'not approved yet: nothing captured');
        self::assertNull($this->called('POST', '/v2/checkout/orders/'.self::ORDER.'/capture'));

        // The payer approved on PayPal's page and is back on returnUrl.
        $this->orderStatus = 'APPROVED';
        $paid = $gateway->fetch(self::ORDER);
        self::assertSame(Status::PAID, $paid->status);
        self::assertContains('PayPal-Request-Id: '.self::ORDER.'-capture', $this->call('POST', '/v2/checkout/orders/'.self::ORDER.'/capture')['headers']);
        self::assertTrue($gateway->fetch(self::ORDER)->isPaid());
        self::assertCount(1, array_filter($this->calls, static fn (array $c) => str_ends_with($c['path'], '/capture')), 'captured once');
    }

    public function testPayPalAskingItsPayerWithoutAPageToComeBackToIsARefusal(): void
    {
        $this->charge = 'PAYER_ACTION_REQUIRED';
        $refused = $this->gateway()->purchase($this->payment(self::TOKEN, null));

        self::assertSame(Status::REFUSED, $refused->status);
        self::assertSame('PAYER_ACTION_REQUIRED', $refused->providerCode);
        self::assertSame(self::ORDER, $refused->reference);
    }

    public function testTheWebhooksOfACharge(): void
    {
        $gateway = $this->gateway();
        $headers = ['PAYPAL-TRANSMISSION-ID' => 't1', 'PAYPAL-TRANSMISSION-TIME' => '2026-11-14T10:00:00Z', 'PAYPAL-TRANSMISSION-SIG' => 'SUCCESS', 'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/cert', 'PAYPAL-AUTH-ALGO' => 'SHA256withRSA'];
        $event = static fn (string $type, array $resource) => json_encode(['id' => 'WH-'.$type, 'event_type' => $type, 'resource' => $resource], \JSON_THROW_ON_ERROR);

        $created = $gateway->notify($event('VAULT.PAYMENT-TOKEN.CREATED', self::paypalToken(self::TOKEN, self::CUSTOMER)), $headers);
        self::assertSame(self::TOKEN, $created->reference);
        self::assertNull($created->status);
        self::assertSame(self::TOKEN, $gateway->notify($event('VAULT.PAYMENT-TOKEN.DELETED', ['id' => self::TOKEN]), $headers)->reference);

        // The payer confirmed a saved method's charge: the webhook takes the money.
        $this->orderStatus = 'APPROVED';
        $approved = $gateway->notify($event('CHECKOUT.ORDER.APPROVED', self::order('APPROVED')), $headers);
        self::assertSame(Status::PAID, $approved->status);
        self::assertSame(self::ORDER, $approved->reference);
        self::assertNotNull($this->called('POST', '/v2/checkout/orders/'.self::ORDER.'/capture'));

        // An order paid on PayPal's page is still captured by capture(), as before.
        $this->calls = [];
        $page = self::order('APPROVED');
        $page['payment_source'] = ['paypal' => ['email_address' => 'alice@example.org']];
        self::assertSame(Status::PENDING, $gateway->notify($event('CHECKOUT.ORDER.APPROVED', $page), $headers)->status);
        self::assertNull($this->called('POST', '/v2/checkout/orders/'.self::ORDER.'/capture'));

        $denied = $gateway->notify($event('PAYMENT.CAPTURE.DENIED', ['id' => '3C679366HH908993F', 'status' => 'DECLINED', 'supplementary_data' => ['related_ids' => ['order_id' => self::ORDER]]]), $headers);
        self::assertSame(Status::REFUSED, $denied->status);
        self::assertSame(self::ORDER, $denied->reference);
    }

    private function payment(string $token, ?string $returnUrl = 'https://shop.example/orders/7'): Payment
    {
        return new Payment(Money::of(1990, 'EUR'), 'ORDER-7', customer: new Customer(reference: self::CUSTOMER), returnUrl: $returnUrl, idempotencyKey: 'attempt-1', savedMethod: $token);
    }

    /**
     * The last call to $method $path, which must have been made.
     *
     * @return array{method: string, path: string, query: string, body: array<mixed>, headers: list<string>}
     */
    private function call(string $method, string $path): array
    {
        $found = $this->called($method, $path);
        self::assertNotNull($found, "no $method $path");

        return $found;
    }

    /**
     * The last call to $method $path; null when there was none.
     *
     * @return array{method: string, path: string, query: string, body: array<mixed>, headers: list<string>}|null
     */
    private function called(string $method, string $path): ?array
    {
        $found = null;
        foreach ($this->calls as $call) {
            if ($method === $call['method'] && $path === $call['path']) {
                $found = $call;
            }
        }

        return $found;
    }

    /** @param array<mixed> $sent */
    private function charged(array $sent): MockResponse
    {
        if (null === Data::of($sent)->textOrNull('payment_source.paypal.vault_id') && null === Data::of($sent)->textOrNull('payment_source.card.vault_id')) {
            return new MockResponse(json_encode(['name' => 'INVALID_REQUEST'], \JSON_THROW_ON_ERROR), ['http_code' => 400]);
        }
        $order = match ($this->charge) {
            'COMPLETED' => self::order('COMPLETED', 'COMPLETED'),
            'DECLINED' => self::order('COMPLETED', 'DECLINED'),
            'PAYER_ACTION_REQUIRED' => self::order('PAYER_ACTION_REQUIRED'),
            default => null,
        };
        if (null === $order) {
            return new MockResponse(json_encode(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => $this->charge, 'description' => 'The instrument presented was either declined by the processor or bank, or it can\'t be used for this payment.']], 'message' => 'The requested action could not be performed, semantically incorrect, or failed business validation.', 'debug_id' => 'f2c5a1b3e4d6'], \JSON_THROW_ON_ERROR), ['http_code' => 422]);
        }

        return new MockResponse(json_encode($order, \JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }

    /** @return array<string, mixed> a page of the customer's payment tokens, $listed in all */
    private function page(string $query): array
    {
        parse_str($query, $asked);
        $page = (int) Data::cast($asked['page'] ?? 1);
        $all = [self::paypalToken(self::TOKEN, null), self::cardToken(self::CARD, null)];
        for ($i = \count($all); $i < $this->listed; ++$i) {
            $all[] = self::paypalToken(sprintf('tok%05d', $i), null);
        }

        return [
            'customer' => ['id' => self::CUSTOMER],
            'payment_tokens' => \array_slice($all, ($page - 1) * 20, 20),
            'total_items' => \count($all),
            'total_pages' => (int) ceil(\count($all) / 20),
            'links' => [['href' => 'https://api-m.sandbox.paypal.com/v3/vault/payment-tokens?customer_id='.self::CUSTOMER.'&page=1', 'rel' => 'self', 'method' => 'GET']],
        ];
    }

    /** @return array<string, mixed> a setup token as POST and GET /v3/vault/setup-tokens answer it */
    private static function setupToken(string $status, string $customer): array
    {
        return [
            'id' => self::SETUP,
            'customer' => ['id' => $customer],
            'status' => $status,
            'payment_source' => ['paypal' => ['usage_type' => 'MERCHANT', 'customer_type' => 'CONSUMER']],
            'links' => [
                ['href' => 'https://www.sandbox.paypal.com/agreements/approve?approval_session_id=05C1734587219561N', 'rel' => 'approve', 'method' => 'GET'],
                ['href' => 'https://api-m.sandbox.paypal.com/v3/vault/setup-tokens/'.self::SETUP, 'rel' => 'self', 'method' => 'GET'],
                ['href' => 'https://api-m.sandbox.paypal.com/v3/vault/payment-tokens', 'rel' => 'confirm', 'method' => 'POST'],
            ],
        ];
    }

    /** @return array<string, mixed> a PayPal account's payment token */
    private static function paypalToken(string $id, ?string $customer): array
    {
        return array_filter([
            'id' => $id,
            'customer' => null !== $customer ? ['id' => $customer] : null,
            'payment_source' => ['paypal' => ['description' => 'Atelier Exemple', 'usage_pattern' => 'IMMEDIATE', 'usage_type' => 'MERCHANT', 'customer_type' => 'CONSUMER', 'email_address' => 'alice@example.org', 'payer_id' => 'QYR5Z8XDVJNXQ']],
            'links' => [['href' => 'https://api-m.sandbox.paypal.com/v3/vault/payment-tokens/'.$id, 'rel' => 'self', 'method' => 'GET']],
        ]);
    }

    /** @return array<string, mixed> a card's payment token */
    private static function cardToken(string $id, ?string $customer): array
    {
        return array_filter([
            'id' => $id,
            'customer' => null !== $customer ? ['id' => $customer] : null,
            'payment_source' => ['card' => ['name' => 'Alice Exemple', 'last_digits' => '1111', 'brand' => 'VISA', 'expiry' => '2030-02']],
        ]);
    }

    /** @return array<string, mixed> an order paid with a payment token, as Orders v2 answers it */
    private static function order(string $status, ?string $capture = null): array
    {
        $unit = ['reference_id' => 'default', 'custom_id' => 'ORDER-7', 'amount' => ['currency_code' => 'EUR', 'value' => '19.90']];
        if (null !== $capture) {
            $unit['payments'] = ['captures' => [array_filter([
                'id' => '3C679366HH908993F',
                'status' => $capture,
                'status_details' => 'DECLINED' === $capture ? ['reason' => 'DECLINED_BY_RISK_FRAUD_FILTERS'] : null,
                'amount' => ['currency_code' => 'EUR', 'value' => '19.90'],
            ])]];
        }

        return [
            'id' => self::ORDER,
            'intent' => 'CAPTURE',
            'status' => $status,
            'payment_source' => ['paypal' => ['email_address' => 'alice@example.org', 'attributes' => ['vault' => ['id' => self::TOKEN, 'status' => 'VAULTED', 'customer' => ['id' => self::CUSTOMER]]]]],
            'purchase_units' => [$unit],
            'create_time' => '2026-11-14T10:00:00Z',
            'links' => 'PAYER_ACTION_REQUIRED' === $status
                ? [['href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.self::ORDER, 'rel' => 'payer-action', 'method' => 'GET']]
                : [['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.self::ORDER, 'rel' => 'self', 'method' => 'GET']],
        ];
    }
}
