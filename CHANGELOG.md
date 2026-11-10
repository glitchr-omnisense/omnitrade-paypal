# Changelog

## Unreleased (1.x)

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

