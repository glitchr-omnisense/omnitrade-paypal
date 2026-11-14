<?php

namespace Omnitrade\PayPal;

use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\PaymentMethod;
use Omnitrade\Model\SavedMethod;

/**
 * PayPal's Vault (v3), as PayPal answers it, read as Omnitrade's models: a payment token as a
 * SavedMethod, a setup token's approval page; and what tells a saved method's charge from an
 * order paid on PayPal's page.
 */
final class Vault
{
    /**
     * The issues of an HTTP 422 to an order that are a refusal of the payment, not a mistake in
     * the request: the order's transaction is REFUSED with the issue as its providerCode.
     */
    public const REFUSALS = [
        'INSTRUMENT_DECLINED',
        'PAYER_CANNOT_PAY',
        'PAYER_ACCOUNT_RESTRICTED',
        'PAYER_ACCOUNT_LOCKED_OR_CLOSED',
        'PAYEE_BLOCKED_TRANSACTION',
        'TRANSACTION_REFUSED',
        'COMPLIANCE_VIOLATION',
        'DECLINED_DUE_TO_RELATED_TXN',
        'CARD_EXPIRED',
        'PAYMENT_DENIED',
        'PAYMENT_SOURCE_DECLINED_BY_PROCESSOR',
        'PAYMENT_SOURCE_INFO_CANNOT_BE_VERIFIED',
        'PAYMENT_SOURCE_CANNOT_BE_USED',
        'MAX_NUMBER_OF_PAYMENT_ATTEMPTS_EXCEEDED',
    ];

    /** What a token's or a setup's id may be made of: PayPal's ids are letters and digits; anything else is sent nowhere. */
    public static function isId(string $id): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id);
    }

    /**
     * A payment token (GET /v3/vault/payment-tokens/{id}, or one of a customer's list): a card by
     * its brand and last digits, a PayPal or Venmo account by its e-mail or user name, anything
     * else by its source's name. $customer is the one the list was asked for, when the token
     * does not repeat it.
     *
     * @param array<string, mixed> $token
     */
    public static function savedMethod(array $token, ?string $customer = null): SavedMethod
    {
        $read = Data::of($token);
        $type = self::type($token);
        $source = $read->data('payment_source.'.$type);
        $brand = null;
        $last4 = null;
        $month = null;
        $year = null;
        if ('card' === $type) {
            $brand = null !== ($named = $source->textOrNull('brand')) && '' !== $named ? strtolower($named) : null;
            $last4 = $source->textOrNull('last_digits');
            // PayPal writes a card's expiry "YYYY-MM".
            if (1 === preg_match('/^(\d{4})-(\d{2})$/', $source->text('expiry'), $expiry)) {
                $year = (int) $expiry[1];
                $month = (int) $expiry[2];
            }
        }
        $email = $source->textOrNull('email_address');
        $label = match (true) {
            'card' === $type && null !== $last4 => sprintf('%s •••• %s', self::brand($brand), $last4),
            'card' === $type => self::brand($brand),
            'paypal' === $type => 'PayPal'.(null !== $email ? ' '.$email : ''),
            'venmo' === $type => 'Venmo'.(null !== ($user = $source->textOrNull('user_name')) ? ' @'.ltrim($user, '@') : (null !== $email ? ' '.$email : '')),
            'apple_pay' === $type => 'Apple Pay',
            default => ucfirst(str_replace('_', ' ', $type)),
        };

        return new SavedMethod(
            reference: $read->text('id'),
            customer: $read->textOrNull('customer.id') ?? $customer,
            code: $type,
            label: $label,
            kind: match ($type) {
                'card' => PaymentMethod::CARD,
                'paypal', 'venmo', 'apple_pay' => PaymentMethod::WALLET,
                default => PaymentMethod::OTHER,
            },
            brand: $brand,
            last4: $last4,
            expiryMonth: $month,
            expiryYear: $year,
            raw: $token,
        );
    }

    /**
     * The source a token holds - "paypal", "card", "venmo", "apple_pay" - its payment_source's
     * first key ("paypal" when PayPal gives none).
     *
     * @param array<string, mixed> $token
     */
    public static function type(array $token): string
    {
        $source = $token['payment_source'] ?? null;

        return \is_array($source) && [] !== $source ? (string) array_key_first($source) : 'paypal';
    }

    /**
     * The customer a token or a setup token belongs to at PayPal (customer.id), null when PayPal
     * names none.
     *
     * @param array<string, mixed> $token
     */
    public static function customer(array $token): ?string
    {
        $customer = Data::of($token)->textOrNull('customer.id');

        return null !== $customer && '' !== $customer ? $customer : null;
    }

    /**
     * The page where the buyer approves a setup token: its "approve" link.
     *
     * @param array<string, mixed> $setup
     *
     * @throws ProviderException when PayPal gives none
     */
    public static function approval(array $setup): string
    {
        foreach (Data::of($setup)->list('links') as $link) {
            if (\in_array($link->get('rel'), ['approve', 'payer-action'], true) && null !== ($href = $link->textOrNull('href'))) {
                return $href;
            }
        }

        throw new ProviderException('paypal', 'PayPal gave no page to approve the setup.', Data::castOrNull($setup['status'] ?? null));
    }

    /**
     * Whether an order is a saved method's charge: its payment source names a vaulted token
     * (vault_id as the order was made, or attributes.vault.id as PayPal may read it back).
     *
     * @param array<string, mixed> $order
     */
    public static function charged(array $order): bool
    {
        $read = Data::of($order);
        foreach (array_keys(Data::map($order['payment_source'] ?? null)) as $type) {
            foreach (['vault_id', 'attributes.vault.id'] as $field) {
                $id = $read->textOrNull('payment_source.'.$type.'.'.$field);
                if (null !== $id && '' !== $id) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function brand(?string $brand): string
    {
        return match ($brand) {
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'amex' => 'American Express',
            'discover' => 'Discover',
            'diners' => 'Diners Club',
            'jcb' => 'JCB',
            'maestro' => 'Maestro',
            'unionpay', 'china_union_pay' => 'UnionPay',
            'cb_nationale' => 'Cartes Bancaires',
            'elo' => 'Elo',
            'hiper' => 'Hiper',
            null, '', 'unknown' => 'Card',
            default => ucwords(strtolower(str_replace('_', ' ', $brand))),
        };
    }
}
