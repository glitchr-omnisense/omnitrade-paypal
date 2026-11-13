# omnitrade/paypal

PayPal for [omnisense/omnitrade](https://github.com/glitchr-omnisense/omnitrade): orders approved on
PayPal's page and captured when the buyer comes back, authorizations, refunds, and the webhook's
events - PayPal's v2 REST API, over the application's HTTP client.

## Install and a first call

```sh
composer require omnitrade/paypal
```

No account for this first call: PayPal's answer is recorded (Symfony's `MockHttpClient` answers in
place of the API), so nothing leaves the process. With your own credentials, give the factory your
HTTP client (or none: it makes its own).

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// PayPal's two answers (a token, then the order), recorded: no account, and nothing leaves the process.
$http = new MockHttpClient([
    new MockResponse('{"access_token": "not-a-token", "token_type": "Bearer", "expires_in": 32400}'),
    new MockResponse('{"id": "5O190127TN364715T", "status": "PAYER_ACTION_REQUIRED", "links": [{"rel": "payer-action", "href": "https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T"}]}'),
]);
$gateway = (new PayPalGatewayFactory($http))->create(['client_id' => 'id', 'secret' => 'secret', 'sandbox' => true]);

$transaction = $gateway->purchase(new Payment(amount: Money::of(1250, 'EUR'), reference: 'ORDER-1042', returnUrl: 'https://shop.example/back'));
echo $transaction->status->value, ' ', $transaction->redirectUrl, "\n";   // pending https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T
```

A real payment needs a PayPal business account: a REST app's client id and secret (below), the
sandbox's first.

## Configuration

```php
$gateway = (new PayPalGatewayFactory($http))->create(['client_id' => '...', 'secret' => '...', 'sandbox' => true]);   // $http: the application's HTTP client; none given, the factory makes its own
```

No framework needed: the package requires `omnisense/omnitrade` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnitrade:
    gateways:
        paypal:
            factory: paypal
            options:
                client_id: '%env(PAYPAL_CLIENT_ID)%'
                secret: '%env(PAYPAL_SECRET)%'
                sandbox: true                          # sandbox API and credentials
                webhook_id: '%env(PAYPAL_WEBHOOK_ID)%'  # for notify(): PayPal verifies the signature itself
```

`purchase()` creates an order (intent CAPTURE) and answers PENDING with PayPal's page; PayPal
brings the buyer back to `returnUrl?token=<order id>`, and `capture($orderId)` takes the money -
twice is fine, an order already captured is answered as it is. `authorize()` does the same with
intent AUTHORIZE: once the buyer approved it, `fetch()` reads it AUTHORIZED (the authorization is
asked for then), `capture()` takes the money, `void()` lets it go. `refund()` pays back on
the order's capture; `fetch()` reads the order; `notify()` reads `CHECKOUT.ORDER.*` and
`PAYMENT.CAPTURE.*` events. The idempotency key goes in `PayPal-Request-Id`.

Credentials: a REST app in the Developer Dashboard (its client id and secret, sandbox and live
are separate), and a webhook on it for `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`,
`PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED` - its id is `webhook_id`.

The manual, [docs/index.md](docs/index.md): every option, what each operation calls and how PayPal's
answers are read, the webhook events, errors, and what is verified.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by PayPal, Inc. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence covers
this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with PayPal's terms ([PayPal
Developer Agreement](https://www.paypal.com/us/legalhub/paypal/xdeveloper-full)), for obtaining
whatever they require (an account, a key, a licence or subscription, an attribution or a citation, a
rate limit to respect), and for any use made of what it returns. The authors publish it free of
charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
