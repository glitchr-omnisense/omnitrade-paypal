<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Status;
use Omnitrade\PayPal\Api;
use Omnitrade\PayPal\Orders;
use Omnitrade\Request\Capture;
use Omnitrade\Request\Request;

/**
 * Take the money of an approved order (the buyer is back): an order made with
 * intent CAPTURE is captured; one made with intent AUTHORIZE is authorized
 * then its authorization captured, for $amount or all of it. An order already
 * captured is answered as it is - capture() may be called twice (the return
 * page and the webhook).
 */
final class CaptureAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Capture;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Capture);
        $headers = $request->idempotencyKey ? ['PayPal-Request-Id' => $request->reference.'-capture-'.$request->idempotencyKey] : [];
        $order = $this->api->call('GET', '/v2/checkout/orders/'.rawurlencode($request->reference));
        $transaction = Orders::transaction($order);
        if ($transaction->isPaid() || Status::REFUNDED === $transaction->status || Status::PARTIALLY_REFUNDED === $transaction->status) {
            $request->setResult($transaction);

            return;
        }
        if (!\in_array($order['status'] ?? null, ['APPROVED', 'COMPLETED'], true)) {
            throw new ProviderException('paypal', sprintf('The order is %s: the buyer has not approved it.', $order['status'] ?? 'unknown'), $order['status'] ?? null);
        }

        if ('AUTHORIZE' === ($order['intent'] ?? 'CAPTURE')) {
            $authorization = $this->api->authorized($order)['purchase_units'][0]['payments']['authorizations'][0]['id'] ?? throw new ProviderException('paypal', 'PayPal authorized nothing.');
            $body = $request->amount ? ['amount' => ['currency_code' => $request->amount->currency, 'value' => $request->amount->decimal()], 'final_capture' => true] : [];
            $this->api->call('POST', '/v2/payments/authorizations/'.rawurlencode((string) $authorization).'/capture', $body, $headers);
        } else {
            $this->api->call('POST', '/v2/checkout/orders/'.rawurlencode($request->reference).'/capture', [], $headers);
        }

        $request->setResult(Orders::transaction($this->api->call('GET', '/v2/checkout/orders/'.rawurlencode($request->reference))));
    }
}
