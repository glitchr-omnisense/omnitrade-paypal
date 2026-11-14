# omnitrade/paypal

PayPal for [omnisense/omnitrade](https://github.com/glitchr-omnisense/omnitrade): orders approved on
PayPal's page and captured when the buyer comes back, authorizations, refunds, and the webhook's
events - PayPal's REST API (Orders v2, Payments v2, webhook verification v1) on an OAuth 2
client-credentials token, over the application's HTTP client. The gateway calls:

| Operation | PayPal API | Path |
|---|---|---|
| token | OAuth 2 | `POST /v1/oauth2/token` |
| purchase, authorize | Orders v2 | `POST /v2/checkout/orders` |
| fetch | Orders v2 | `GET /v2/checkout/orders/{id}` (an approved intent `AUTHORIZE` order: `POST /v2/checkout/orders/{id}/authorize` and the order again) |
| capture | Orders v2, Payments v2 | `GET /v2/checkout/orders/{id}`, then `POST /v2/checkout/orders/{id}/capture`, or `POST /v2/checkout/orders/{id}/authorize` and `POST /v2/payments/authorizations/{id}/capture`, then the order again |
| void | Orders v2, Payments v2 | `GET /v2/checkout/orders/{id}`, `POST /v2/payments/authorizations/{id}/void`, the order again |
| refund | Orders v2, Payments v2 | `GET /v2/checkout/orders/{id}` (to find its capture), `POST /v2/payments/captures/{id}/refund` |
| notify | Webhooks v1 | `POST /v1/notifications/verify-webhook-signature` |
| setupMethod (1.1) | Vault v3 | `POST /v3/vault/setup-tokens` |
| CompleteMethodSetup (1.1) | Vault v3 | `GET /v3/vault/setup-tokens/{id}`, `POST /v3/vault/payment-tokens` |
| fetchSavedMethods (1.1) | Vault v3 | `GET /v3/vault/payment-tokens?customer_id=...` (every page) |
| removeSavedMethod (1.1) | Vault v3 | `GET` then `DELETE /v3/vault/payment-tokens/{id}` |
| purchase with a saved method (1.1) | Vault v3, Orders v2 | `GET /v3/vault/payment-tokens/{id}`, `POST /v2/checkout/orders` (`vault_id`); after the payer's page, `POST /v2/checkout/orders/{id}/capture` |

on `https://api-m.paypal.com` (live, `Omnitrade\PayPal\Api::LIVE`) or
`https://api-m.sandbox.paypal.com` (`sandbox: true`, `Api::SANDBOX`): the same API at two
addresses.

## Installation

```sh
composer require omnitrade/paypal
```

Requires PHP 8.2, `omnisense/omnitrade` (1.1 or later 1.x) and `symfony/http-client` (6.4, 7 or 8). In a
Symfony application with `Omnitrade\Bridge\Symfony\OmnitradeBundle`, the factory is registered as
soon as the package is installed.

```php
$gateway = (new PayPalGatewayFactory($http))->create(['client_id' => '...', 'secret' => '...', 'sandbox' => true]);   // $http: the application's HTTP client; none given, the factory makes its own
```

## Credentials

- A **REST app** in PayPal's Developer Dashboard: its **client id** and **secret**. Sandbox and
  live apps are separate, each with its own credentials; a sandbox app's work with
  `sandbox: true` only.
- A **webhook** on that app, its **id** as `webhook_id`, for the events below
  (`CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`,
  `PAYMENT.CAPTURE.REFUNDED`; with saved methods also `VAULT.PAYMENT-TOKEN.CREATED`,
  `VAULT.PAYMENT-TOKEN.DELETED`).
- **Saved methods (1.1)**: the app's **Vault** feature ("Save payment methods") turned on in the
  Developer Dashboard. In live mode, vaulting PayPal accounts for merchant-initiated payments needs
  PayPal to approve the business account for it (PayPal's support); the sandbox does not ask.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | yes | the REST app's client id |
| `secret` | string | - | yes | the REST app's secret |
| `sandbox` | bool | `false` | no | `true`: the sandbox API, with sandbox credentials |
| `webhook_id` | ?string | `null` | for `notify()` | the webhook's id, sent to PayPal's verification; without it every webhook is an `InvalidNotificationException` |
| `timeout` | int | `15` | no | seconds, for every call |
| `setup_method` | bool | `false` | no | `true`: `SetupMethod` supported - set it only when the host asks `CompleteMethodSetup` on its `returnUrl` (see Saved methods) |

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

## Capabilities

| Request | Supported |
|---|---|
| `Purchase` | yes (an order, intent `CAPTURE`) |
| `Authorize` | yes (an order, intent `AUTHORIZE`) |
| `Capture` | yes |
| `VoidAuthorization` | yes, once the order holds an authorization (see below) |
| `Refund` | yes |
| `FetchTransaction` | yes (the order) |
| `GetPaymentMethods` | yes (a fixed list, Pay Later by country and currency) |
| `Notify` | yes (verified by PayPal) |
| `FetchSavedMethods`, `RemoveSavedMethod` (1.1) | yes (a PayPal customer's payment tokens) |
| `Purchase` with `Payment::$savedMethod` (1.1) | yes (an order paid with the token's `vault_id`; PayPal's payer-action page when it asks) |
| `SetupMethod` (1.1) | only with `setup_method: true`: the approved setup needs `Omnitrade\PayPal\Request\CompleteMethodSetup`, a step the core does not have; a PayPal account only, no card |
| `FetchOrder`, the catalogue, `AffiliateLink`, connected accounts, subscriptions | no |

## Behaviour

**Token.** Asked for on the first call, not when the gateway is built: `POST /v1/oauth2/token`
with HTTP basic authentication (client id and secret) and `grant_type=client_credentials`. It is
kept in the API client until 60 seconds before it expires (`expires_in`, 3600 seconds when PayPal
does not say). Every call then sends `Authorization: Bearer`, `Content-Type: application/json` and
`Prefer: return=representation`.

**Purchase and authorize.** One order, intent `CAPTURE` for `purchase()`, `AUTHORIZE` for
`authorize()`, answered PENDING with the order's `payer-action` (or `approve`) link as
`redirectUrl`. A payment without `returnUrl` is a `ProviderException` before any call. The order:

| Payment | Order |
|---|---|
| `amount` | `purchase_units[0].amount` (`currency_code`, `value` as a decimal) |
| `reference` | `custom_id` (127 characters); `reference_id` is always `default` |
| `idempotencyKey` | `invoice_id` = `<reference>-<key>` (127), and the `PayPal-Request-Id` header, the same |
| `description` | `description` (127) |
| `lines`, when they add up | `items`: `name` (127), `quantity`, `unit_amount`, `sku` (127), `category` `PHYSICAL_GOODS` or `DIGITAL_GOODS` by `physical`; `amount.breakdown`: `item_total`, `shipping`, `discount` |
| `customer->email` | `payment_source.paypal.email_address` |
| `returnUrl`, `cancelUrl`, `locale` | `payment_source.paypal.experience_context`: `return_url`, `cancel_url`, `locale` (`fr_FR` written `fr-FR`), with `user_action: PAY_NOW` and `shipping_preference: NO_SHIPPING` |

Not sent: `method`, `notice`, `metadata`, the customer's other fields, `destination`,
`applicationFee`. PayPal brings the buyer back to `returnUrl?token=<order id>`.

**Capture.** The order is read first. An order already PAID, REFUNDED or PARTIALLY_REFUNDED is
answered as it is - `capture()` may be called twice, by the return page and by the webhook. An
order that is neither `APPROVED` nor `COMPLETED` is a `ProviderException` (`The order is <status>:
the buyer has not approved it.`, the status as `providerCode`). An intent `CAPTURE` order is
captured (`POST .../capture`); an intent `AUTHORIZE` order is authorized when it holds no
authorization yet (below), then its authorization captured, for `$amount` (`final_capture: true`)
or all of it. The answer is the order read again. The capture's `PayPal-Request-Id` is
`<order>-capture-<key>` with an idempotency key.

**The authorization.** An intent `AUTHORIZE` order the buyer approved holds no authorization
until the merchant asks for it ([Authorize payment for
order](https://developer.paypal.com/docs/api/orders/v2/#orders_authorize)). `fetch()`, `capture()`
and `void()` ask for it when they read such an order (`POST /v2/checkout/orders/{id}/authorize`,
`PayPal-Request-Id: <order>-authorize`, so it is made once), then read the order again: after
`authorize()` and the buyer's approval, `fetch()` reads it AUTHORIZED and `void()` lets it go.

**Void.** The reference is an order's or an authorization's. The order is read; its first
authorization is voided, and the answer is the order read again. A reference that is not an order
(PayPal answers an error) is taken as an authorization id, and the answer is a CANCELLED
transaction with `message: VOIDED`.

**Refund.** A reference of 17 characters not starting with `CAP-` is first read as an order: its
first capture is refunded (an order id and a capture id look alike; a capture's id answers an
error there and is then refunded as it is). `POST /v2/payments/captures/{id}/refund` with the
amount when given (else PayPal refunds the rest) and `reason` as `note_to_payer` (255
characters); `PayPal-Request-Id` is `<capture>-refund-<key>`. PayPal's `COMPLETED` is REFUNDED,
`CANCELLED` and `FAILED` REFUSED, anything else PENDING; `transactionReference` is the capture.

**Fetch.** The order, read as a transaction:

| Order `status` | Its payment | `Status` |
|---|---|---|
| `COMPLETED` | first capture `COMPLETED` (or none and no authorization) | PAID |
| `COMPLETED` | capture `REFUNDED` / `PARTIALLY_REFUNDED` | REFUNDED / PARTIALLY_REFUNDED |
| `COMPLETED` | capture `DECLINED`, `FAILED` | REFUSED |
| `COMPLETED` | capture `PENDING` | PENDING |
| `COMPLETED` | an authorization, no capture: `CREATED` (or another) / `VOIDED` / `EXPIRED` / `DENIED` | AUTHORIZED / CANCELLED / EXPIRED / REFUSED |
| `VOIDED` | | CANCELLED |
| anything else (`CREATED`, `PAYER_ACTION_REQUIRED`, `APPROVED`) | | PENDING, with the approval link |

The amount is the capture's, else the authorization's, else the unit's; `message` the order's
status; `method` the first key of `payment_source` (`paypal`, `card`); `metadata` holds `capture`,
`authorization`, `custom_id` and `payer_email` when present; `createdAt` its `create_time`.

**Payment methods.** No call: always `paypal` (PayPal, wallet) and `card` (Card (guest checkout),
card); `paylater` (Pay Later, buy now pay later) where PayPal offers it - Australia, France,
Germany, Italy, Spain, the United Kingdom and the United States, each in its currency (AUD, EUR,
GBP, USD; [Pay Later](https://developer.paypal.com/docs/checkout/pay-later/)). A country outside
those, or an amount in another currency than the country's (or than all of theirs, without a
country), leaves it out; with neither, it is listed.

**Notify.** The five `PAYPAL-TRANSMISSION-ID`, `PAYPAL-TRANSMISSION-TIME`,
`PAYPAL-TRANSMISSION-SIG`, `PAYPAL-CERT-URL`, `PAYPAL-AUTH-ALGO` headers are required (one missing:
`The notification lacks its <name> header.`); they and the event are posted to
`POST /v1/notifications/verify-webhook-signature` with `webhook_id`, and anything but
`verification_status: SUCCESS` is refused. PayPal checks the signature itself: the package holds
no certificate and makes one more call per webhook.

| Event | `Status` |
|---|---|
| `CHECKOUT.ORDER.APPROVED` | PENDING (the money comes with `capture()`) |
| `CHECKOUT.ORDER.COMPLETED`, `PAYMENT.CAPTURE.COMPLETED` | PAID |
| `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.DECLINED` | REFUSED |
| `PAYMENT.CAPTURE.REFUNDED`, `PAYMENT.CAPTURE.REVERSED` | REFUNDED |
| `PAYMENT.AUTHORIZATION.CREATED` | AUTHORIZED |
| `PAYMENT.AUTHORIZATION.VOIDED`, `CHECKOUT.ORDER.VOIDED` | CANCELLED |
| `CHECKOUT.ORDER.APPROVED` of a saved method's charge (1.1) | the order captured, then PAID (or what the capture became) |
| `VAULT.PAYMENT-TOKEN.CREATED`, `.DELETED`, `.DELETION-INITIATED` (1.1) | none; `reference` the token's id |
| any other | none |

`reference` is the order's id: the resource's for a `CHECKOUT.ORDER.*` event (with the order as
`transaction`), the capture's `supplementary_data.related_ids.order_id` otherwise. `id` is the
event's.

## Saved methods (1.1)

PayPal keeps a buyer's PayPal account (or a card vaulted elsewhere through its card fields) as a
**payment token** of a PayPal **customer**; the site keeps the customer's id and the token's,
never more.

**Saving: two steps at PayPal, one more than the core asks.** `SetupMethod($customer, $returnUrl,
...)` makes a setup token (`POST /v3/vault/setup-tokens`): `customer.id` the request's
`$customer->reference` when the buyer already is one (else PayPal makes a customer and gives its
id), `payment_source.paypal` with `usage_type: MERCHANT`, `usage_pattern: IMMEDIATE`,
`customer_type: CONSUMER` and `experience_context` (`return_url`, `cancel_url` - `returnUrl` when
none - `locale` written `fr-FR`, `shipping_preference: NO_SHIPPING`); `PayPal-Request-Id:
setup-<key>` with an idempotency key. The answer is a `MethodSetup`: `reference` the setup token,
`redirectUrl` its `approve` link, `customer` the PayPal customer to keep. Once the buyer approved
on that page, PayPal sends them back to `returnUrl` - and the setup token is not yet a saved
method: `FetchSavedMethods` does not list it until it is turned into a payment token. The host
asks for that on its `returnUrl`:

```php
use Omnitrade\PayPal\Request\CompleteMethodSetup;

// $setup->reference kept from SetupMethod, or PayPal's approval_token_id on the returnUrl
$method = $paypal->execute(new CompleteMethodSetup($setup->reference, $setup->customer))->getMethod();   // "PayPal alice@example.org"
```

`CompleteMethodSetup` reads the setup (`GET /v3/vault/setup-tokens/{id}`): another customer's is
`not_this_customer`, one not `APPROVED` (nor `VAULTED`, already turned) a `ProviderException`
with its status as `providerCode` (`PAYER_ACTION_REQUIRED`: the buyer did not approve). Then
`POST /v3/vault/payment-tokens` with `payment_source.token: {id, type: SETUP_TOKEN}` and
`PayPal-Request-Id: <setup>-payment-token`, so that asking twice makes one token. Because
omnisense/omnitrade 1.1's `SetupMethod` promises the method is listed once the buyer is back and
has no such step, `SetupMethod` is supported only with the `setup_method: true` option: a host
that does not make the step (a generic one, as the Forge 1.x) sees `supports(SetupMethod::class)`
false rather than setups that never end. Only a PayPal account is saved this way: `method` other
than `paypal` is `method_not_supported`, nothing sent (PayPal vaults a card through its card
fields, on the site's page). PayPal's vault keeps no metadata: the request's metadata, the tags
and the customer's e-mail and name are not sent.

**Listing and removing.** `FetchSavedMethods($customer)` reads `GET
/v3/vault/payment-tokens?customer_id=...&page_size=20&page=N&total_required=true` until the last
page (50 at most), in PayPal's order: `paypal` "PayPal <email_address>" (wallet), `card` "<Brand>
•••• <last_digits>" with `brand` lower-cased and `expiry` `YYYY-MM` read as month and year,
`venmo` "Venmo @<user_name>", `apple_pay`, others by their name. `RemoveSavedMethod($customer,
$reference)` reads the token and deletes it (`DELETE /v3/vault/payment-tokens/{id}`) only when its
`customer.id` is `$customer`; another's, an id PayPal does not know (`RESOURCE_NOT_FOUND`) or one
that is not an id is a `ProviderException` with code `not_this_customer`, nothing deleted.

**Charging a saved method.** A `Payment` with `savedMethod` must name its customer
(`customer->reference`: PayPal's token ids say nothing of whose they are; none, or another's, is
`not_this_customer` and no order is made). The token is read (`GET
/v3/vault/payment-tokens/{id}`) for its owner and its source, then the order is made as a purchase
is (intent `CAPTURE`, the same purchase unit, `PayPal-Request-Id: <reference>-<key>`) with
`payment_source.<source>.vault_id` - a card with `stored_credential: {payment_initiator: MERCHANT,
payment_type: UNSCHEDULED, usage: SUBSEQUENT}` - and, with a `returnUrl`, its `experience_context`
(`return_url`, `cancel_url`, `locale`). PayPal processes such an order at once:

| PayPal's answer | Transaction |
|---|---|
| `COMPLETED`, capture `COMPLETED` | PAID, the capture in `metadata` |
| `COMPLETED`, capture `DECLINED` / `FAILED` | REFUSED, the capture's `status_details.reason` as `providerCode` |
| `APPROVED` (nothing captured) | captured at once, then as above |
| HTTP 422 with an issue of `Vault::REFUSALS` (`INSTRUMENT_DECLINED`, `PAYER_CANNOT_PAY`, `TRANSACTION_REFUSED`...) | REFUSED, the issue as `providerCode`, `reference` PayPal's `debug_id` (no order was made) |
| any other error | `ProviderException`, as every call |
| `PAYER_ACTION_REQUIRED` | PENDING, its `payer-action` link as `redirectUrl`; REFUSED with code `PAYER_ACTION_REQUIRED` without a `returnUrl` |

**PayPal asking its payer** (a funding source to choose again, a card's 3-D Secure) is the
"requires action" case: the buyer goes to `redirectUrl`, approves, and PayPal sends them back to
`returnUrl?token=<order id>`. The order is then `APPROVED` and holds no money yet: `fetch()`
captures it (`POST /v2/checkout/orders/{id}/capture`, `PayPal-Request-Id: <order>-capture`, so
once), and so does `notify()` on its `CHECKOUT.ORDER.APPROVED`, answering PAID (or what the
capture became). Only a saved method's charge is captured that way - an order whose
`payment_source` names a `vault_id` or `attributes.vault.id`; an order paid on PayPal's page is
still taken by `capture()`, as before.

## Tags

PayPal keeps no metadata map. Of the `tags` option, only `site` is sent: as the purchase unit's
`reference_id` (`default` without one; `custom_id` stays the merchant's reference) and as the
refund's `custom_id`. `notify()` gives it back (`Notification::$tags`) on the `CHECKOUT.ORDER.*`
events and on `PAYMENT.CAPTURE.REFUNDED`. The other capture and authorization events carry no
site. See omnisense/omnitrade's [Several sites in one sandbox](https://github.com/glitchr-omnisense/omnitrade/blob/1.x/docs/several-sites.md).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\PayPal\PayPalGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$paypal = (new PayPalGatewayFactory(HttpClient::create()))->create([
    'client_id' => getenv('PAYPAL_CLIENT_ID'),
    'secret' => getenv('PAYPAL_SECRET'),
    'webhook_id' => getenv('PAYPAL_WEBHOOK_ID'),
    'sandbox' => true,
]);

$transaction = $paypal->purchase(new Payment(
    amount: Money::of(2750, 'EUR'),
    reference: 'ORDER-1042',
    description: 'Commande 1042',
    customer: new Customer(email: 'camille@example.org'),
    lines: [new Line('Margaux 2019', Money::of(1200, 'EUR'), 2, physical: true)],
    shipping: Money::of(350, 'EUR'),
    returnUrl: 'https://shop.example/paypal/back',
    cancelUrl: 'https://shop.example/cart',
    idempotencyKey: 'attempt-1',
    locale: 'fr-FR',
));
header('Location: '.$transaction->redirectUrl);

// on https://shop.example/paypal/back?token=<order id>
$paid = $paypal->capture($_GET['token'], idempotencyKey: 'attempt-1');
$paid->isPaid();
$paid->metadata['capture'];   // the capture's id
```

The core's manual runs a PayPal purchase offline, on answers shaped as PayPal's documentation
gives them, with its output ([installation](https://github.com/glitchr-omnisense/omnitrade/blob/1.x/docs/installation.md)).
In the core's Docker harness: `docker compose run --rm omnitrade purchase paypal --amount 1250`,
then `capture paypal <order id>`.

## Usage policy

`policy.json` (named in `composer.json`'s `extra.omnisense.policy`) leaves the rate limits
unknown: PayPal does not publish them. A 429 `RATE_LIMIT_REACHED` is a `RateLimitedException`,
never retried, and PayPal's next calls wait for its `Retry-After`, unsent. Orders, authorizations,
captures and refunds carry `PayPal-Request-Id` when the caller gives an idempotency key. See omnisense/omnitrade's [Usage policies and rate limits](https://github.com/glitchr-omnisense/omnitrade/blob/1.x/docs/policies.md).

## Errors

Every failure is an `Omnitrade\Exception\ProviderException` whose `provider` is `paypal`:

- an HTTP status of 400 or more: the message is PayPal's `details[0].description`, else its
  `message`, else `error_description`; `providerCode` its `name` (`RESOURCE_NOT_FOUND`,
  `UNPROCESSABLE_ENTITY`), else `details[0].issue`, else `error`;
- a body that is not JSON: `PayPal answered HTTP <status> with a body that is not JSON.`; a
  network failure: `PayPal request failed: ...`, a copy of the HTTP client's exception as `previous`;
- no token: `PayPal did not answer for a token: ...`, or PayPal's `error_description` (`PayPal
  gave no token: check the client id and secret.` when it gives none), `error` as `providerCode`;
- `PayPal created no order.`, `PayPal recorded no refund.`, `PayPal authorized nothing.`, `The order
  holds no authorization to void.`, a capture before approval (above);
- a webhook: `InvalidNotificationException` without `webhook_id`, for a body that is not JSON, a
  header missing, or PayPal's refusal (`PayPal does not recognise the notification's
  signature.`). A verified body that is not a PayPal event is a `ProviderException`.

A missing `client_id` or `secret` is an `InvalidConfigException` when the gateway is built;
`FetchOrder`, the catalogue's requests, `AffiliateLink`, connected accounts and subscriptions raise
`RequestNotSupportedException` before any call.

No failure carries the client secret and the token: a network failure's message has them masked as
`***` (the key parameters of a URL too), its previous is a copy of the HTTP client's exception (the
same class and code, without its request), and no trace of the chain keeps its arguments, where the
request's options stood ([No secret in a
failure](https://github.com/glitchr-omnisense/omnitrade/blob/1.x/docs/errors.md#no-secret-in-a-failure)).

## Verified, and not

Tested offline, 29 tests: `Tests/PayPalSavedMethodsTest.php` (1.1: the setup offered only with
`setup_method`, a PayPal account saved on PayPal's page then completed once, a setup not approved
or another customer's refused, a known customer's setup, no card setup; the customer's tokens
listed over two pages; only their own token deleted; a token charged at once, a card as a
merchant-initiated payment, a 422 refusal, a declined capture, an invalid request; another
customer's token never charged; PayPal's payer-action page then `fetch()` capturing once; no
page to come back to; the vault and approval webhooks), `Tests/PayPalGatewayTest.php` (Pay Later by country and currency, an order approved on PayPal's page then
captured, a refund on the order's capture, a webhook verified by PayPal then read, an approved
AUTHORIZE order authorized once, fetched AUTHORIZED, then voided) and
`Tests/PayPalContractTest.php`, the family's conformance suite on an order authorized, then
captured or voided, with webhooks verified on PayPal's side. The suite found a voided
authorization (the order still `COMPLETED`, its authorization `VOIDED`) read as AUTHORIZED; it now
reads CANCELLED. The recorded answers follow PayPal's Orders v2 documentation, with its example
ids.

Not verified: no call was made to PayPal, sandbox or live; that needs a sandbox app's credentials
(the authorization asked by `fetch()` and `void()` follows Orders v2's documentation). The saved
methods (1.1) follow PayPal's Vault v3 and Orders v2 references as known when written, not a
recorded answer; to verify in a sandbox:

- `POST /v3/vault/setup-tokens` without `customer` answers a `customer.id` PayPal made; the
  `approve` link's rel; `experience_context` accepting `locale` and `shipping_preference` there;
  `usage_pattern: IMMEDIATE` being the right pattern for one-click purchases;
- PayPal adding `approval_token_id` to the `returnUrl` after the approval;
- the setup token's status once turned (`VAULTED` assumed) and `POST /v3/vault/payment-tokens`
  with the same `PayPal-Request-Id` answering the same token;
- whether PayPal sends an event when a setup token is approved (none is used: if one exists,
  `notify()` could make the completion and `SetupMethod` be supported by default);
- the list's query (`customer_id`, `page_size` 20 as the maximum, `page`, `total_required`) and
  answer (`payment_tokens`, `total_pages`, each token's `customer` or the list's), a card token's
  `brand`, `last_digits`, `expiry`;
- the token read answering 404 `RESOURCE_NOT_FOUND` for an unknown id, `DELETE` answering 204;
- an order with `payment_source.paypal.vault_id` (and `experience_context`) being processed at once
  (`COMPLETED` with its capture) for intent `CAPTURE`; whether `stored_credential` is wanted, or
  accepted, on a PayPal-account source (sent on cards only);
- the 422 issues a declined vaulted payment answers (`Vault::REFUSALS`) and the `debug_id`;
- `PAYER_ACTION_REQUIRED` with a `payer-action` link on a vaulted charge, the order `APPROVED`
  after it, and the order (`GET`, the webhook's resource) naming `vault_id` or
  `attributes.vault.id` in its `payment_source` - what tells `fetch()` and `notify()` to capture it;
- the `VAULT.PAYMENT-TOKEN.*` events' resource (the token, its `id`).

## Responsibility

An independent, unofficial client, not affiliated with, endorsed by or sponsored by PayPal, Inc.
Whoever runs it complies with PayPal's terms ([PayPal Developer
Agreement](https://www.paypal.com/us/legalhub/paypal/xdeveloper-full)) and obtains what they
require: see the README's Responsibility section.
