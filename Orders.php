<?php

namespace Omnitrade\PayPal;

use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;

/** A v2 order, as PayPal answers it, read as a Transaction; and a Payment written as one. */
final class Orders
{
    /** @param array<string, mixed> $order */
    public static function transaction(array $order): Transaction
    {
        $unit = $order['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? null;
        $authorization = $unit['payments']['authorizations'][0] ?? null;
        $amount = $capture['amount'] ?? $authorization['amount'] ?? $unit['amount'] ?? null;
        $currency = strtoupper((string) ($amount['currency_code'] ?? 'EUR'));
        $status = match ((string) ($order['status'] ?? '')) {
            'COMPLETED' => match ((string) ($capture['status'] ?? 'COMPLETED')) {
                'REFUNDED' => Status::REFUNDED,
                'PARTIALLY_REFUNDED' => Status::PARTIALLY_REFUNDED,
                'DECLINED', 'FAILED' => Status::REFUSED,
                'PENDING' => Status::PENDING,
                // An authorization not captured: where it stands (a voided one leaves the order COMPLETED).
                default => $authorization && !$capture ? match ((string) ($authorization['status'] ?? 'CREATED')) {
                    'VOIDED' => Status::CANCELLED,
                    'EXPIRED' => Status::EXPIRED,
                    'DENIED' => Status::REFUSED,
                    default => Status::AUTHORIZED,
                } : Status::PAID,
            },
            'VOIDED' => Status::CANCELLED,
            default => Status::PENDING,
        };
        if (Status::PAID === $status && $authorization && !$capture && 'CREATED' === ($authorization['status'] ?? null)) {
            $status = Status::AUTHORIZED;
        }
        $approve = null;
        foreach ($order['links'] ?? [] as $link) {
            if (\in_array($link['rel'] ?? null, ['payer-action', 'approve'], true)) {
                $approve = $link['href'] ?? null;
            }
        }

        return new Transaction(
            provider: 'paypal',
            reference: (string) $order['id'],
            status: $status,
            amount: \is_array($amount) && isset($amount['value']) ? Money::fromDecimal((string) $amount['value'], $currency) : null,
            redirectUrl: Status::PENDING === $status ? $approve : null,
            message: $order['status'] ?? null,
            method: isset($order['payment_source']) ? (string) array_key_first((array) $order['payment_source']) : null,
            metadata: array_filter([
                'capture' => $capture['id'] ?? null,
                'authorization' => $authorization['id'] ?? null,
                'custom_id' => $unit['custom_id'] ?? null,
                'payer_email' => $order['payer']['email_address'] ?? null,
            ]),
            createdAt: isset($order['create_time']) ? new \DateTimeImmutable((string) $order['create_time']) : null,
            raw: $order,
        );
    }

    /** @return array<string, mixed> a v2 order to create */
    public static function create(Payment $payment, string $intent): array
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
            'reference_id' => 'default',
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
