<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\PayPal\Api;
use Omnitrade\PayPal\Orders;
use Omnitrade\Request\Request;
use Omnitrade\Request\VoidAuthorization;

/** Let an order's authorization go: the reference is the order's, or the authorization's own. */
final class VoidAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof VoidAuthorization;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof VoidAuthorization);
        $order = null;
        try {
            $order = $this->api->call('GET', '/v2/checkout/orders/'.rawurlencode($request->reference));
            $order = $this->api->authorized($order); // approved, not yet authorized: authorized, then voided
            $authorization = $order['purchase_units'][0]['payments']['authorizations'][0]['id'] ?? throw new ProviderException('paypal', 'The order holds no authorization to void.');
        } catch (ProviderException $e) {
            if (null !== $order) {
                throw $e;
            }
            $authorization = $request->reference; // not an order: an authorization id
        }
        $this->api->call('POST', '/v2/payments/authorizations/'.rawurlencode((string) $authorization).'/void');
        if (null !== $order) {
            $request->setResult(Orders::transaction($this->api->call('GET', '/v2/checkout/orders/'.rawurlencode($request->reference))));
        } else {
            $request->setResult(new \Omnitrade\Model\Transaction('paypal', $request->reference, \Omnitrade\Model\Status::CANCELLED, message: 'VOIDED'));
        }
    }
}
