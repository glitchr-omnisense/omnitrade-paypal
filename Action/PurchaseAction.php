<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Action\TagsAwareInterface;
use Omnitrade\Action\TagsAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\PayPal\Api;
use Omnitrade\PayPal\Data;
use Omnitrade\PayPal\Orders;
use Omnitrade\PayPal\Vault;
use Omnitrade\Request\Purchase;
use Omnitrade\Request\Request;

/**
 * An order with intent CAPTURE: the buyer approves it on PayPal's page
 * (PENDING with the page's URL), comes back to returnUrl with ?token=<order id>,
 * and capture() takes the money. PayPal-Request-Id carries the idempotency key.
 *
 * A payment naming a saved method (Payment::$savedMethod, a payment token) is charged at once,
 * with no page: see withSavedMethod().
 */
final class PurchaseAction implements ActionInterface, ApiAwareInterface, TagsAwareInterface
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
        return $request instanceof Purchase;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Purchase);
        $payment = $request->payment;
        if (null !== $payment->savedMethod && '' !== $payment->savedMethod) {
            $request->setResult($this->withSavedMethod($payment));

            return;
        }
        if (null === $payment->returnUrl) {
            throw new ProviderException('paypal', 'A PayPal order needs a returnUrl: where PayPal sends the buyer back.');
        }
        $headers = $payment->idempotencyKey ? ['PayPal-Request-Id' => $payment->reference.'-'.$payment->idempotencyKey] : [];
        $order = $this->api->call('POST', '/v2/checkout/orders', Orders::create($payment, 'CAPTURE', $this->tags()), $headers);
        if (empty($order['id'])) {
            throw new ProviderException('paypal', 'PayPal created no order.');
        }
        $request->setResult(Orders::transaction($order));
    }

    /**
     * A saved method charged with no page: the payment token read first (GET
     * /v3/vault/payment-tokens/{id}) - it must be the payment's customer's ($customer->reference,
     * required: PayPal's token ids say nothing of whose they are), its source tells how it is
     * charged - then an order with intent CAPTURE whose payment_source is that token
     * (<source>.vault_id; a card's stored_credential says the merchant initiates it, unscheduled,
     * a later use), under the payment's PayPal-Request-Id. PayPal processes such an order at once:
     * COMPLETED with its capture is PAID, a capture DECLINED is REFUSED. A 422 refusing the
     * payment (Vault::REFUSALS: INSTRUMENT_DECLINED, PAYER_CANNOT_PAY...) is a REFUSED transaction
     * with the issue as providerCode, not an exception.
     *
     * When PayPal needs its payer (PAYER_ACTION_REQUIRED: a new funding source to choose, a card's
     * 3-D Secure), the transaction is PENDING with the payer-action page as redirectUrl; the payer
     * comes back to returnUrl, and fetch() (or the CHECKOUT.ORDER.APPROVED webhook) captures the
     * approved order and says how it ended. Without a returnUrl there is nowhere to come back to:
     * REFUSED, code PAYER_ACTION_REQUIRED.
     */
    private function withSavedMethod(Payment $payment): Transaction
    {
        $reference = (string) $payment->savedMethod;
        $customer = $payment->customer?->reference;
        if (null === $customer || '' === $customer) {
            throw new ProviderException('paypal', 'A saved PayPal method is charged for its customer: the payment\'s customer->reference names none.', 'not_this_customer');
        }
        $token = RemoveSavedMethodAction::token($this->api, $reference);
        if (Vault::customer($token) !== $customer) {
            throw new ProviderException('paypal', 'This payment method is not the customer\'s.', 'not_this_customer');
        }
        $type = Vault::type($token);
        $source = ['vault_id' => $reference];
        if ('card' === $type) {
            $source['stored_credential'] = ['payment_initiator' => 'MERCHANT', 'payment_type' => 'UNSCHEDULED', 'usage' => 'SUBSEQUENT'];
        }
        if (null !== $payment->returnUrl) {
            $source['experience_context'] = array_filter([
                'return_url' => $payment->returnUrl,
                'cancel_url' => $payment->cancelUrl ?? $payment->returnUrl,
                'locale' => $payment->locale ? str_replace('_', '-', $payment->locale) : null,
                'shipping_preference' => 'NO_SHIPPING',
            ]);
        }
        $body = Orders::create($payment, 'CAPTURE', $this->tags());
        $body['payment_source'] = [$type => $source];
        $headers = $payment->idempotencyKey ? ['PayPal-Request-Id' => $payment->reference.'-'.$payment->idempotencyKey] : [];

        $order = $this->api->attempt('POST', '/v2/checkout/orders', $body, $headers);
        $read = Data::of($order);
        if (!$read->has('id')) {
            $issue = $read->textOrNull('details.0.issue');
            if (null === $issue || !\in_array($issue, Vault::REFUSALS, true)) {
                throw new ProviderException('paypal', Data::cast($read->get('details.0.description') ?? $read->get('message') ?? 'PayPal refused the order.'), $issue ?? Data::castOrNull($read->get('name')));
            }

            return new Transaction(
                provider: 'paypal',
                reference: $read->text('debug_id'),
                status: Status::REFUSED,
                amount: $payment->amount,
                message: Data::castOrNull($read->get('details.0.description') ?? $read->get('message')),
                providerCode: $issue,
                method: $type,
                raw: $order,
            );
        }
        // Approved without asking anyone (PayPal did not capture it on its own): captured now.
        $order = $this->api->completed($order, true);
        $transaction = Orders::transaction($order);
        if (Status::PENDING === $transaction->status && null === $payment->returnUrl && 'PAYER_ACTION_REQUIRED' === $read->textOrNull('status')) {
            return new Transaction(
                provider: 'paypal',
                reference: $transaction->reference,
                status: Status::REFUSED,
                amount: $transaction->amount,
                message: 'PayPal asks its payer to confirm, and there is no page to send them back to.',
                providerCode: 'PAYER_ACTION_REQUIRED',
                method: $type,
                metadata: $transaction->metadata,
                createdAt: $transaction->createdAt,
                raw: $order,
            );
        }

        return $transaction;
    }
}
