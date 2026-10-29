<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Request\GetPaymentMethods;
use Omnitrade\Request\Request;

/**
 * What PayPal's page offers: the PayPal account, the cards it takes as a
 * guest, and Pay Later where PayPal offers it - Australia, France, Germany,
 * Italy, Spain, the United Kingdom and the United States, each in its own
 * currency (PayPal's Pay Later documentation). A country or an amount's
 * currency outside those leaves Pay Later out.
 */
final class GetPaymentMethodsAction implements ActionInterface
{
    /** Pay Later's countries, and the currency it is offered in there. */
    private const PAY_LATER = ['AU' => 'AUD', 'FR' => 'EUR', 'DE' => 'EUR', 'IT' => 'EUR', 'ES' => 'EUR', 'GB' => 'GBP', 'US' => 'USD'];

    public function supports(Request $request): bool
    {
        return $request instanceof GetPaymentMethods;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof GetPaymentMethods);
        $methods = [
            new PaymentMethod('paypal', 'PayPal', PaymentMethod::WALLET),
            new PaymentMethod('card', 'Card (guest checkout)', PaymentMethod::CARD),
        ];
        $country = null !== $request->country ? strtoupper($request->country) : null;
        $currency = $request->for?->currency;
        $offered = match (true) {
            null !== $country => isset(self::PAY_LATER[$country]) && (null === $currency || self::PAY_LATER[$country] === $currency),
            null !== $currency => \in_array($currency, self::PAY_LATER, true),
            default => true,
        };
        if ($offered) {
            $methods[] = new PaymentMethod('paylater', 'Pay Later', PaymentMethod::BUY_NOW_PAY_LATER);
        }
        $request->setResult($methods);
    }
}
