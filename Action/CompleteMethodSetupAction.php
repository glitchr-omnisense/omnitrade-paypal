<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\PayPal\Api;
use Omnitrade\PayPal\Data;
use Omnitrade\PayPal\Request\CompleteMethodSetup;
use Omnitrade\PayPal\Vault;
use Omnitrade\Request\Request;

/**
 * A setup token the buyer approved, turned into a payment token: the setup read first (GET
 * /v3/vault/setup-tokens/{id}) - it must be APPROVED (or VAULTED, already turned) and, when the
 * request names a customer, that customer's - then POST /v3/vault/payment-tokens with the setup
 * token as its source, its PayPal-Request-Id "<setup>-payment-token" so that the same setup
 * completed twice makes one token.
 */
final class CompleteMethodSetupAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CompleteMethodSetup;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof CompleteMethodSetup);
        if (!Vault::isId($request->reference)) {
            throw new ProviderException('paypal', 'Not a setup token\'s id.', 'not_this_customer');
        }
        $setup = $this->api->call('GET', '/v3/vault/setup-tokens/'.rawurlencode($request->reference));
        $owner = Vault::customer($setup);
        if (null !== $request->customer && '' !== $request->customer && $owner !== $request->customer) {
            throw new ProviderException('paypal', 'This setup is not the customer\'s.', 'not_this_customer');
        }
        $status = Data::of($setup)->text('status');
        if (!\in_array($status, ['APPROVED', 'VAULTED'], true)) {
            throw new ProviderException('paypal', sprintf('The setup is %s: the buyer has not approved it.', '' !== $status ? $status : 'unknown'), '' !== $status ? $status : null);
        }
        $token = $this->api->call('POST', '/v3/vault/payment-tokens', [
            'payment_source' => ['token' => ['id' => $request->reference, 'type' => 'SETUP_TOKEN']],
        ], ['PayPal-Request-Id' => $request->reference.'-payment-token']);
        if ('' === Data::of($token)->text('id')) {
            throw new ProviderException('paypal', 'PayPal made no payment token.');
        }

        $request->setResult(Vault::savedMethod($token, $owner ?? $request->customer));
    }
}
