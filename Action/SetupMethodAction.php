<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\MethodSetup;
use Omnitrade\PayPal\Api;
use Omnitrade\PayPal\Data;
use Omnitrade\PayPal\Vault;
use Omnitrade\Request\CompleteMethodSetup;
use Omnitrade\Request\Request;
use Omnitrade\Request\SetupMethod;

/**
 * A PayPal account saved on PayPal's own page: a setup token (POST /v3/vault/setup-tokens) for
 * merchant-initiated payments, the buyer sent to its approval page. The customer is the
 * request's ($customer->reference, a PayPal customer id), else the one PayPal makes for the
 * occasion - kept as MethodSetup::$customer.
 *
 * Once the buyer approved, the setup token must still be turned into a payment token: the host
 * asks omnisense/omnitrade 1.2's CompleteMethodSetup on its returnUrl (CompleteMethodSetupAction),
 * as it does for every provider that supports it.
 *
 * Only a PayPal account: a card is vaulted through PayPal's card fields (the buyer types it in
 * PayPal's JavaScript, on the site's page), which no hosted page offers. The idempotency key is
 * the PayPal-Request-Id ("setup-<key>"). PayPal keeps no metadata on a token: the request's
 * metadata and the gateway's tags are not sent.
 */
final class SetupMethodAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof SetupMethod;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof SetupMethod);
        if (null !== $request->method && 'paypal' !== $request->method) {
            throw new ProviderException('paypal', sprintf('PayPal saves a PayPal account on its page, not "%s": a card is vaulted through PayPal\'s card fields.', $request->method), 'method_not_supported');
        }
        $customer = $request->customer->reference;
        if (null !== $customer && '' !== $customer && !Vault::isId($customer)) {
            throw new ProviderException('paypal', 'Not a PayPal customer\'s id.', CompleteMethodSetup::NOT_THIS_CUSTOMER);
        }
        $body = array_filter([
            'customer' => null !== $customer && '' !== $customer ? ['id' => $customer] : null,
            'payment_source' => ['paypal' => [
                'usage_type' => 'MERCHANT',
                'usage_pattern' => 'IMMEDIATE',
                'customer_type' => 'CONSUMER',
                'experience_context' => array_filter([
                    'return_url' => $request->returnUrl,
                    'cancel_url' => $request->cancelUrl ?? $request->returnUrl,
                    'locale' => $request->locale ? str_replace('_', '-', $request->locale) : null,
                    'shipping_preference' => 'NO_SHIPPING',
                ]),
            ]],
        ]);
        $headers = null !== $request->idempotencyKey ? ['PayPal-Request-Id' => 'setup-'.$request->idempotencyKey] : [];
        $setup = $this->api->call('POST', '/v3/vault/setup-tokens', $body, $headers);
        $id = Data::of($setup)->textOrNull('id');
        if (null === $id || '' === $id) {
            throw new ProviderException('paypal', 'PayPal made no setup token.');
        }

        $request->setResult(new MethodSetup('paypal', $id, Vault::approval($setup), Vault::customer($setup) ?? ($customer ?: null), $setup));
    }
}
