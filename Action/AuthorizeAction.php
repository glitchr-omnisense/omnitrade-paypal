<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Action\TagsAwareInterface;
use Omnitrade\Action\TagsAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\PayPal\Api;
use Omnitrade\PayPal\Orders;
use Omnitrade\Request\Authorize;
use Omnitrade\Request\Request;

/**
 * An order with intent AUTHORIZE: approved on PayPal's page (PENDING with the
 * page's URL); capture() then reserves and takes the money (or void() lets it go).
 */
final class AuthorizeAction implements ActionInterface, ApiAwareInterface, TagsAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;
    use TagsAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Authorize;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Authorize);
        $payment = $request->payment;
        if (null === $payment->returnUrl) {
            throw new ProviderException('paypal', 'A PayPal order needs a returnUrl: where PayPal sends the buyer back.');
        }
        $headers = $payment->idempotencyKey ? ['PayPal-Request-Id' => $payment->reference.'-'.$payment->idempotencyKey] : [];
        $order = $this->api->call('POST', '/v2/checkout/orders', Orders::create($payment, 'AUTHORIZE', $this->tags()), $headers);
        if (empty($order['id'])) {
            throw new ProviderException('paypal', 'PayPal created no order.');
        }
        $request->setResult(Orders::transaction($order));
    }
}
