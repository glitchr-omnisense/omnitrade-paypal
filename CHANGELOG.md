# Changelog

## [Unreleased]

## [1.1.0] - 2026-11-14

Saved methods (omnisense/omnitrade 1.1) on PayPal's Vault v3, and their charge without a page.
Additive: nothing that worked before changes, except that a declined capture now carries PayPal's
reason as `providerCode` (it had none). Nothing was checked against PayPal (no sandbox): the
answers follow PayPal's REST reference; docs/index.md lists what remains to verify.

### Added
- `FetchSavedMethods`: a PayPal customer's payment tokens (`GET /v3/vault/payment-tokens`, every
  page of 20), as `SavedMethod`s: "PayPal alice@example.org", "Visa •••• 1111" with its expiry,
  "Venmo @user".
- `RemoveSavedMethod`: the token read, then deleted only when it is that customer's; another's, or
  one PayPal does not know, is a `ProviderException` with code `not_this_customer`, nothing deleted.
- `Purchase` of a `Payment` with `savedMethod`: the token read (it must be the payment's
  `customer->reference`'s, which is required), then an order with intent `CAPTURE` and
  `payment_source.<source>.vault_id` (a card's `stored_credential`: merchant-initiated,
  unscheduled, subsequent), under the payment's `PayPal-Request-Id`. COMPLETED is PAID, a capture
  DECLINED REFUSED, a 422 refusal (`Vault::REFUSALS`: `INSTRUMENT_DECLINED`, `PAYER_CANNOT_PAY`...)
  a REFUSED transaction with the issue as `providerCode`. PayPal asking its payer
  (`PAYER_ACTION_REQUIRED`) is PENDING with the payer-action page as `redirectUrl` (REFUSED without
  a `returnUrl`); `fetch()` and the `CHECKOUT.ORDER.APPROVED` webhook then capture the approved
  order (`Api::completed()`, `PayPal-Request-Id: <order>-capture`).
- `SetupMethod`: a setup token for a PayPal account (`POST /v3/vault/setup-tokens`, usage type
  `MERCHANT`), its approval page and the customer PayPal made (or the one named) as a
  `MethodSetup`. Supported only with the `setup_method: true` option (below).
- `Omnitrade\PayPal\Request\CompleteMethodSetup`: PayPal's own step once the buyer approved the
  setup - the setup token turned into a payment token (`POST /v3/vault/payment-tokens`, once per
  setup), answered as the `SavedMethod`.
- `notify()`: `VAULT.PAYMENT-TOKEN.CREATED`, `.DELETED` and `.DELETION-INITIATED` name the token
  (`reference`, no status).
- `Api::attempt()` (a 422 answered as PayPal's body), `Api::completed()`, `Vault` (tokens read as
  `SavedMethod`s).
- Tests: `PayPalSavedMethodsTest` (12).

### Not supported, and why
- `SetupMethod` by default: omnisense/omnitrade 1.1 promises the method is listed once the buyer
  is back on `returnUrl`; at PayPal the approved setup token must still be turned into a payment
  token, a step the core has no request for. A host that asks `CompleteMethodSetup` on its
  `returnUrl` sets `setup_method: true`; any other sees `supports(SetupMethod::class)` false.
- Saving a card on a hosted page: PayPal vaults a card through its card fields (JavaScript on the
  site's page), not a page of its own. `SetupMethod` with a `method` other than `paypal` is a
  `ProviderException` (`method_not_supported`), nothing sent.
- The request's metadata, the gateway's tags and the customer's e-mail and name on a setup:
  PayPal's vault keeps none of them; the customer is PayPal's own.

### Changed
- Requires `omnisense/omnitrade` `^1.1`.

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Release
- Requires `omnisense/contracts` `^1.0` and `omnisense/omnitrade` `^1.0`, stable versions, where it
  required `@dev` ones; `extra.branch-alias` `dev-1.x` → `1.x-dev`.
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml: the package installed alone,
  PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml` (that CI on the tag,
  then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the package's own tests.
- README: the install line and a first call on a recorded answer (no account, nothing sent).

### Added
- `policy.json` (`extra.omnisense.policy`): the rate limits unknown (PayPal does not publish them);
  verified 2026-11-10.
- The gateway's Backoff (omnisense/omnitrade's `Http\Throttle`): no call while PayPal's wait lasts; a
  429 `RATE_LIMIT_REACHED` is a `RateLimitedException` with its `Retry-After`, never retried.
  Requires omnisense/contracts.
- The gateway's `site` tag (omnisense/omnitrade's `tags` option): the purchase unit's
  `reference_id` (`default` without one) and the refund's `custom_id`; `notify()` gives it back on the
  `CHECKOUT.ORDER.*` events and on `PAYMENT.CAPTURE.REFUNDED` (`Notification::$tags`). PayPal keeps
  no metadata map: the other tags are not sent, and the capture and authorization events carry no
  site. Tests on recorded requests (`PayPalTagsTest`).
- `Omnitrade\PayPal\Api::authorized()`: an order as it stands once authorized.

### Changed
- PHPStan at level max with an empty baseline (151 errors that predated it): PayPal's answers read
  through `Data` (internal: omnisense/contracts' `Http\Data` with reads that keep PHP's `(string)` and
  `(int)` of a scalar), the arrays typed. Types only: no signature changed, no behaviour either.

### Fixed
- A `ProviderException` no longer carries the client secret and the token. The message quoted the
  HTTP client's (the whole URL), the previous was the client's own exception, and every trace of the
  chain kept the arguments of the calls under it (the request's options, the headers with the
  credentials) when `zend.exception_ignore_args` is off: a log of the whole chain wrote them. The
  message masks them as `***`, the previous is a copy of the client's exception (the same class and
  code), and no exception `Api` throws keeps its trace's arguments (`Omnitrade\Http\Redaction`).
- `paymentMethods($for, $country)` reads them: Pay Later is listed only where PayPal offers it
  (Australia, France, Germany, Italy, Spain, the United Kingdom, the United States) and in that
  country's currency; with neither given, it is listed as before.
- An intent AUTHORIZE order the buyer approved is authorized by `fetch()` and `void()` too, not
  only inside `capture()` (`POST /v2/checkout/orders/{id}/authorize`, `PayPal-Request-Id:
  <order>-authorize`, once): after `authorize()` and the approval, `fetch()` reads it AUTHORIZED
  where it read PENDING, and `void()` lets it go where it threw "The order holds no authorization
  to void.". `capture()` asks for the authorization with that request id too (it reused the
  capture's).
