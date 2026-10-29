# Changelog

## Unreleased (1.x)

### Fixed
- `paymentMethods($for, $country)` reads them: Pay Later is listed only where PayPal offers it
  (Australia, France, Germany, Italy, Spain, the United Kingdom, the United States) and in that
  country's currency; with neither given, it is listed as before.
- An intent AUTHORIZE order the buyer approved is authorized by `fetch()` and `void()` too, not
  only inside `capture()` (`POST /v2/checkout/orders/{id}/authorize`, `PayPal-Request-Id:
  <order>-authorize`, once): after `authorize()` and the approval, `fetch()` reads it AUTHORIZED
  where it read PENDING, and `void()` lets it go where it threw "The order holds no authorization
  to void.". `capture()` asks for the authorization with that request id too (it reused the
  capture's).

### Added
- `Omnitrade\PayPal\Api::authorized()`: an order as it stands once authorized.
