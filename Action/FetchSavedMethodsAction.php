<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\PayPal\Api;
use Omnitrade\PayPal\Data;
use Omnitrade\PayPal\Vault;
use Omnitrade\Request\FetchSavedMethods;
use Omnitrade\Request\Request;

/**
 * The payment tokens PayPal keeps for one of its customers (GET
 * /v3/vault/payment-tokens?customer_id=..., every page), in PayPal's order: PayPal accounts,
 * and cards or Venmo accounts vaulted otherwise, each a SavedMethod.
 */
final class FetchSavedMethodsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    /** PayPal's largest page, and how many pages are read at most. */
    private const PAGE_SIZE = 20;
    private const PAGES = 50;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchSavedMethods;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchSavedMethods);
        if (!Vault::isId($request->customer)) {
            throw new ProviderException('paypal', 'Not a PayPal customer\'s id.', 'not_this_customer');
        }
        $methods = [];
        for ($page = 1; $page <= self::PAGES; ++$page) {
            $answer = $this->api->call('GET', '/v3/vault/payment-tokens?'.http_build_query(['customer_id' => $request->customer, 'page_size' => self::PAGE_SIZE, 'page' => $page, 'total_required' => 'true']));
            $tokens = Data::maps($answer['payment_tokens'] ?? []);
            foreach ($tokens as $token) {
                $methods[] = Vault::savedMethod($token, $request->customer);
            }
            if (\count($tokens) < self::PAGE_SIZE || $page >= Data::of($answer)->number('total_pages')) {
                break;
            }
        }

        $request->setResult($methods);
    }
}
