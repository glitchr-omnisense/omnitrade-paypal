<?php

namespace Omnitrade\PayPal\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Notification;
use Omnitrade\Model\Status;
use Omnitrade\Model\Tags;
use Omnitrade\PayPal\Api;
use Omnitrade\PayPal\Data;
use Omnitrade\PayPal\Orders;
use Omnitrade\Request\Notify;
use Omnitrade\Request\Request;

/**
 * A PayPal webhook, verified by PayPal itself: the order and capture events
 * read as what they mean for the order. CHECKOUT.ORDER.APPROVED is still
 * PENDING (the money comes with capture()); PAYMENT.CAPTURE.COMPLETED is PAID;
 * DENIED is REFUSED; REFUNDED is REFUNDED. The reference is the order's id,
 * taken from the capture's related ids when the event is a capture's.
 *
 * $tags: the site the order was made for (its purchase unit's reference_id,
 * set from the gateway's "tags" option) on the order events, and the
 * refund's custom_id on PAYMENT.CAPTURE.REFUNDED. A capture's or an
 * authorization's event carries neither: no tags.
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $this->api->verifyWebhook($request->body, [
            'transmission_id' => (string) $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_time' => (string) $request->header('PAYPAL-TRANSMISSION-TIME'),
            'transmission_sig' => (string) $request->header('PAYPAL-TRANSMISSION-SIG'),
            'cert_url' => (string) $request->header('PAYPAL-CERT-URL'),
            'auth_algo' => (string) $request->header('PAYPAL-AUTH-ALGO'),
        ]);
        $event = json_decode($request->body, true);
        if (!\is_array($event) || !\is_string($event['event_type'] ?? null)) {
            throw new ProviderException('paypal', 'The notification is not a PayPal event.');
        }
        $resource = Data::of($event)->fields('resource');
        $type = $event['event_type'];
        $isOrder = str_starts_with($type, 'CHECKOUT.ORDER.');
        $reference = $isOrder ? ($resource['id'] ?? null) : Data::of($resource)->get('supplementary_data.related_ids.order_id');

        $request->setResult(new Notification(
            provider: 'paypal',
            event: $type,
            reference: Data::castOrNull($reference),
            status: match ($type) {
                'CHECKOUT.ORDER.APPROVED' => Status::PENDING,
                'CHECKOUT.ORDER.COMPLETED', 'PAYMENT.CAPTURE.COMPLETED' => Status::PAID,
                'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => Status::REFUSED,
                'PAYMENT.CAPTURE.REFUNDED' => Status::REFUNDED,
                'PAYMENT.CAPTURE.REVERSED' => Status::REFUNDED,
                'PAYMENT.AUTHORIZATION.CREATED' => Status::AUTHORIZED,
                'PAYMENT.AUTHORIZATION.VOIDED', 'CHECKOUT.ORDER.VOIDED' => Status::CANCELLED,
                default => null,
            },
            transaction: $isOrder && isset($resource['id']) ? Orders::transaction($resource) : null,
            id: Data::of($event)->textOrNull('id'),
            raw: $event,
            tags: self::tags($type, $resource),
        ));
    }

    /**
     * @param array<string, mixed> $resource
     *
     * @return array<string, string>
     */
    public static function tags(string $type, array $resource): array
    {
        $site = match (true) {
            str_starts_with($type, 'CHECKOUT.ORDER.') => Data::of($resource)->get('purchase_units.0.reference_id'),
            'PAYMENT.CAPTURE.REFUNDED' === $type => $resource['custom_id'] ?? null,
            default => null,
        };

        return \is_string($site) && '' !== $site && 'default' !== $site ? [Tags::SITE => $site] : [];
    }
}
