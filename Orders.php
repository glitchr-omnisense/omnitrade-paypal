<?php

namespace Omnitrade\PayPal;

use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Status;
use Omnitrade\Model\Tags;
use Omnitrade\Model\Transaction;

/** A v2 order, as PayPal answers it, read as a Transaction; and a Payment written as one. */
final class Orders
{
    /** @param array<string, mixed> $order */
    public static function transaction(array $order): Transaction
    {
        $read = Data::of($order);
        $unit = $read->data('purchase_units.0');
        $capture = $unit->get('payments.captures.0');
        $authorization = $unit->get('payments.authorizations.0');
        $captured = Data::of($capture);
        $authorized = Data::of($authorization);
        $amount = $captured->get('amount') ?? $authorized->get('amount') ?? $unit->get('amount');
        $currency = strtoupper(Data::cast(Data::of($amount)->get('currency_code') ?? 'EUR'));
        $status = match ($read->text('status')) {
            'COMPLETED' => match (Data::cast($captured->get('status') ?? 'COMPLETED')) {
                'REFUNDED' => Status::REFUNDED,
                'PARTIALLY_REFUNDED' => Status::PARTIALLY_REFUNDED,
                'DECLINED', 'FAILED' => Status::REFUSED,
                'PENDING' => Status::PENDING,
                // An authorization not captured: where it stands (a voided one leaves the order COMPLETED).
                default => $authorization && !$capture ? match (Data::cast($authorized->get('status') ?? 'CREATED')) {
                    'VOIDED' => Status::CANCELLED,
                    'EXPIRED' => Status::EXPIRED,
                    'DENIED' => Status::REFUSED,
                    default => Status::AUTHORIZED,
                } : Status::PAID,
            },
            'VOIDED' => Status::CANCELLED,
            default => Status::PENDING,
        };
        if (Status::PAID === $status && $authorization && !$capture && 'CREATED' === $authorized->get('status')) {
            $status = Status::AUTHORIZED;
        }
        $approve = null;
        foreach ($read->list('links') as $link) {
            if (\in_array($link->get('rel'), ['payer-action', 'approve'], true)) {
                $approve = $link->textOrNull('href');
            }
        }

        return new Transaction(
            provider: 'paypal',
            reference: $read->text('id'),
            status: $status,
            amount: \is_array($amount) && isset($amount['value']) ? Money::fromDecimal(Data::cast($amount['value']), $currency) : null,
            redirectUrl: Status::PENDING === $status ? $approve : null,
            message: $read->textOrNull('status'),
            method: isset($order['payment_source']) ? (string) array_key_first((array) $order['payment_source']) : null,
            metadata: array_filter([
                'capture' => $captured->get('id'),
                'authorization' => $authorized->get('id'),
                'custom_id' => $unit->get('custom_id'),
                'payer_email' => $read->get('payer.email_address'),
            ]),
            createdAt: $read->has('create_time') ? new \DateTimeImmutable($read->text('create_time')) : null,
            raw: $order,
        );
    }

    /**
     * A v2 order to create. PayPal keeps no metadata map: the gateway's site
     * (Tags::site(), the "tags" option) is the purchase unit's reference_id
     * (256 characters, "default" without one) - custom_id carries the
     * merchant's reference already. Other tags are not sent.
     *
     * @return array<string, mixed>
     */
    public static function create(Payment $payment, string $intent, ?Tags $tags = null): array
    {
        $currency = $payment->amount->currency;
        $amount = ['currency_code' => $currency, 'value' => $payment->amount->decimal()];
        $items = [];
        if ($payment->linesAddUp()) {
            $itemTotal = 0;
            foreach ($payment->lines as $line) {
                $items[] = array_filter([
                    'name' => mb_substr($line->label, 0, 127),
                    'quantity' => (string) $line->quantity,
                    'unit_amount' => ['currency_code' => $currency, 'value' => $line->unitAmount->decimal()],
                    'sku' => $line->sku ? mb_substr($line->sku, 0, 127) : null,
                    'category' => $line->physical ? 'PHYSICAL_GOODS' : 'DIGITAL_GOODS',
                ]);
                $itemTotal += $line->total()->amount;
            }
            $amount['breakdown'] = array_filter([
                'item_total' => ['currency_code' => $currency, 'value' => Money::of($itemTotal, $currency)->decimal()],
                'shipping' => $payment->shipping ? ['currency_code' => $currency, 'value' => $payment->shipping->decimal()] : null,
                'discount' => $payment->discount ? ['currency_code' => $currency, 'value' => $payment->discount->decimal()] : null,
            ]);
        }
        $unit = array_filter([
            'reference_id' => null !== ($site = $tags?->site()) ? mb_substr($site, 0, 256) : 'default',
            'custom_id' => mb_substr($payment->reference, 0, 127),
            'invoice_id' => $payment->idempotencyKey ? mb_substr($payment->reference.'-'.$payment->idempotencyKey, 0, 127) : null,
            'description' => $payment->description ? mb_substr($payment->description, 0, 127) : null,
            'amount' => $amount,
            'items' => $items ?: null,
        ]);
        $context = array_filter([
            'return_url' => $payment->returnUrl,
            'cancel_url' => $payment->cancelUrl,
            'user_action' => 'PAY_NOW',
            'locale' => $payment->locale ? str_replace('_', '-', $payment->locale) : null,
            'shipping_preference' => 'NO_SHIPPING',
        ]);
        $source = $payment->customer?->email
            ? ['paypal' => ['email_address' => $payment->customer->email, 'experience_context' => $context]]
            : ['paypal' => ['experience_context' => $context]];

        return ['intent' => $intent, 'purchase_units' => [$unit], 'payment_source' => $source];
    }
}
