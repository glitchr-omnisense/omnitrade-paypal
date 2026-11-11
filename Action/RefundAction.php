<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Action\TagsAwareInterface;
use Omnitrade\Action\TagsAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Money;
use Omnitrade\Model\Refund as RefundModel;
use Omnitrade\Model\Status;
use Omnitrade\PayPal\Api;
use Omnitrade\PayPal\Data;
use Omnitrade\Request\Refund;
use Omnitrade\Request\Request;

/** Money back on a capture: the reference is the order's (its first capture) or the capture's own. */
final class RefundAction implements ActionInterface, ApiAwareInterface, TagsAwareInterface
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
        return $request instanceof Refund;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Refund);
        $capture = $request->reference;
        $currency = $request->amount?->currency;
        if (!str_starts_with($capture, 'CAP-') && 17 === \strlen($capture)) {
            // An order id and a capture id look alike: ask for the order first; a capture answers 404 there.
            try {
                $order = $this->api->call('GET', '/v2/checkout/orders/'.rawurlencode($capture));
                $first = Data::of($order)->get('purchase_units.0.payments.captures.0');
                if (null !== $first) {
                    $captured = Data::of($first);
                    $capture = $captured->text('id');
                    $currency ??= strtoupper(Data::cast($captured->get('amount.currency_code') ?? 'EUR'));
                }
            } catch (ProviderException) {
                // Not an order: the reference is the capture's.
            }
        }
        $headers = $request->idempotencyKey ? ['PayPal-Request-Id' => $capture.'-refund-'.$request->idempotencyKey] : [];
        $body = array_filter([
            'amount' => $request->amount ? ['currency_code' => $request->amount->currency, 'value' => $request->amount->decimal()] : null,
            'note_to_payer' => $request->reason ? mb_substr($request->reason, 0, 255) : null,
            // PayPal keeps no metadata: the refund's custom_id (127 characters) names the gateway's site.
            'custom_id' => null !== ($site = $this->tags()->site()) ? mb_substr($site, 0, 127) : null,
        ]);
        $data = $this->api->call('POST', '/v2/payments/captures/'.rawurlencode($capture).'/refund', $body, $headers);
        if (empty($data['id'])) {
            throw new ProviderException('paypal', 'PayPal recorded no refund.');
        }
        $amount = $data['amount'] ?? null;

        $request->setResult(new RefundModel(
            provider: 'paypal',
            reference: Data::cast($data['id']),
            amount: \is_array($amount) && isset($amount['value']) ? Money::fromDecimal(Data::cast($amount['value']), Data::cast($amount['currency_code'] ?? $currency ?? 'EUR')) : ($request->amount ?? Money::of(0, $currency ?? 'EUR')),
            status: match ($data['status'] ?? '') { 'COMPLETED' => Status::REFUNDED, 'CANCELLED', 'FAILED' => Status::REFUSED, default => Status::PENDING },
            transactionReference: $capture,
            message: Data::castOrNull($data['status'] ?? null),
            raw: $data,
        ));
    }
}
