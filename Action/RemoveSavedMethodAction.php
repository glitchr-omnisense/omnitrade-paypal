<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\PayPal\Api;
use Omnitrade\PayPal\Vault;
use Omnitrade\Request\RemoveSavedMethod;
use Omnitrade\Request\Request;

/**
 * A payment token deleted from PayPal's vault: it can no longer be charged. Read first (GET
 * /v3/vault/payment-tokens/{id}), and deleted (DELETE, the same path) only when it is the
 * request's customer's: another customer's token, or one PayPal does not know, is a
 * ProviderException with code "not_this_customer", nothing deleted.
 */
final class RemoveSavedMethodAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof RemoveSavedMethod;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof RemoveSavedMethod);
        $token = self::token($this->api, $request->reference);
        if (Vault::customer($token) !== $request->customer) {
            throw new ProviderException('paypal', 'This payment method is not the customer\'s.', 'not_this_customer');
        }
        $this->api->call('DELETE', '/v3/vault/payment-tokens/'.rawurlencode($request->reference));

        $request->setResult(true);
    }

    /**
     * A payment token as PayPal has it; an id that is none, or that PayPal does not know, is "not_this_customer".
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public static function token(Api $api, string $reference): array
    {
        if (!Vault::isId($reference)) {
            throw new ProviderException('paypal', 'Not a payment token\'s id.', 'not_this_customer');
        }
        try {
            return $api->call('GET', '/v3/vault/payment-tokens/'.rawurlencode($reference));
        } catch (ProviderException $e) {
            if ('RESOURCE_NOT_FOUND' !== $e->providerCode && 'INVALID_RESOURCE_ID' !== $e->providerCode) {
                throw $e;
            }

            throw new ProviderException('paypal', 'PayPal keeps no such payment method.', 'not_this_customer', $e);
        }
    }
}
