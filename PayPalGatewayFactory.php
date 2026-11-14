<?php

namespace Omnitrade\PayPal;

use Omnitrade\Config;
use Omnitrade\GatewayFactory;
use Omnitrade\PayPal\Action\AuthorizeAction;
use Omnitrade\PayPal\Action\CaptureAction;
use Omnitrade\PayPal\Action\CompleteMethodSetupAction;
use Omnitrade\PayPal\Action\FetchSavedMethodsAction;
use Omnitrade\PayPal\Action\FetchTransactionAction;
use Omnitrade\PayPal\Action\GetPaymentMethodsAction;
use Omnitrade\PayPal\Action\NotifyAction;
use Omnitrade\PayPal\Action\PurchaseAction;
use Omnitrade\PayPal\Action\RefundAction;
use Omnitrade\PayPal\Action\RemoveSavedMethodAction;
use Omnitrade\PayPal\Action\SetupMethodAction;
use Omnitrade\PayPal\Action\VoidAction;
use Symfony\Component\HttpClient\HttpClient;

/**
 * PayPal: orders approved on PayPal's page, captured when the buyer comes
 * back, authorizations, refunds, and the webhook's events - the v2 REST API.
 *
 *   options:
 *     client_id: '%env(PAYPAL_CLIENT_ID)%'
 *     secret: '%env(PAYPAL_SECRET)%'
 *     sandbox: true                        # the sandbox API, with sandbox credentials
 *     webhook_id: '%env(PAYPAL_WEBHOOK_ID)%' # the webhook's id in the developer dashboard, for notify()
 *     timeout: 15
 *     setup_method: false                  # true: SetupMethod supported, the host completing each setup (below)
 *
 * Saved methods (1.1), on PayPal's Vault v3: FetchSavedMethods lists a PayPal
 * customer's payment tokens, RemoveSavedMethod deletes one of theirs, and a
 * Payment naming one (savedMethod) is an order charged at once with that
 * token - PayPal's payer-action page, when PayPal asks its payer, as a
 * PENDING transaction's redirectUrl. SetupMethod opens a setup token's
 * approval page, but the approved token must then be turned into a payment
 * token (Request\CompleteMethodSetup, PayPal's own step, which the core's
 * SetupMethod does not ask for): supported only with setup_method: true.
 */
final class PayPalGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnitrade.factory_name' => 'paypal',
            'omnitrade.factory_title' => 'PayPal',
            'omnitrade.required_options' => ['client_id', 'secret'],
            'omnitrade.policy' => __DIR__.'/policy.json',
            'sandbox' => false,
            'webhook_id' => null,
            'timeout' => 15,
            'setup_method' => false,
            'omnitrade.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();

                return new Api($http, Data::cast($c['client_id']), Data::cast($c['secret']), (bool) $c['sandbox'], Data::cast($c['webhook_id']) ?: null, Data::integer($c['timeout']), $c->backoff());
            },
            'omnitrade.action.purchase' => new PurchaseAction(),
            'omnitrade.action.authorize' => new AuthorizeAction(),
            'omnitrade.action.capture' => new CaptureAction(),
            'omnitrade.action.void' => new VoidAction(),
            'omnitrade.action.refund' => new RefundAction(),
            'omnitrade.action.fetch' => new FetchTransactionAction(),
            'omnitrade.action.notify' => new NotifyAction(),
            'omnitrade.action.methods' => new GetPaymentMethodsAction(),
            'omnitrade.action.setup_method' => static fn (Config $c) => new SetupMethodAction((bool) $c['setup_method']),
            'omnitrade.action.complete_method_setup' => new CompleteMethodSetupAction(),
            'omnitrade.action.saved_methods' => new FetchSavedMethodsAction(),
            'omnitrade.action.remove_saved_method' => new RemoveSavedMethodAction(),
        ]);
    }
}
