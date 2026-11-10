<?php

namespace Omnitrade\PayPal;

use Omnitrade\Config;
use Omnitrade\GatewayFactory;
use Omnitrade\PayPal\Action\AuthorizeAction;
use Omnitrade\PayPal\Action\CaptureAction;
use Omnitrade\PayPal\Action\FetchTransactionAction;
use Omnitrade\PayPal\Action\GetPaymentMethodsAction;
use Omnitrade\PayPal\Action\NotifyAction;
use Omnitrade\PayPal\Action\PurchaseAction;
use Omnitrade\PayPal\Action\RefundAction;
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
            'omnitrade.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();

                return new Api($http, (string) $c['client_id'], (string) $c['secret'], (bool) $c['sandbox'], $c['webhook_id'] ?: null, (int) $c['timeout'], $c->backoff());
            },
            'omnitrade.action.purchase' => new PurchaseAction(),
            'omnitrade.action.authorize' => new AuthorizeAction(),
            'omnitrade.action.capture' => new CaptureAction(),
            'omnitrade.action.void' => new VoidAction(),
            'omnitrade.action.refund' => new RefundAction(),
            'omnitrade.action.fetch' => new FetchTransactionAction(),
            'omnitrade.action.notify' => new NotifyAction(),
            'omnitrade.action.methods' => new GetPaymentMethodsAction(),
        ]);
    }
}
