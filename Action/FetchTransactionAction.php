<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\PayPal\Api;
use Omnitrade\PayPal\Orders;
use Omnitrade\Request\FetchTransaction;
use Omnitrade\Request\Request;

/** The order as PayPal has it now; an approved AUTHORIZE order authorized first (Api::authorized()). */
final class FetchTransactionAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchTransaction;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchTransaction);
        // An approved AUTHORIZE order is authorized here: PayPal makes the authorization when asked.
        $request->setResult(Orders::transaction($this->api->authorized($this->api->call('GET', '/v2/checkout/orders/'.rawurlencode($request->reference)))));
    }
}
