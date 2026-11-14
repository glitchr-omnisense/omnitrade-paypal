<?php

namespace Omnitrade\PayPal\Request;

use Omnitrade\Model\SavedMethod;
use Omnitrade\Request\Request;

/**
 * PayPal's own step after a SetupMethod, which omnisense/omnitrade 1.1 has no request for: the
 * buyer approved the setup token on PayPal's page, and the token is turned into a payment token
 * (the saved method) - until then FetchSavedMethods does not list it. Ask it on the returnUrl,
 * with the setup token's id: MethodSetup::$reference, or the approval_token_id PayPal adds to
 * the returnUrl. Only a setup of $customer when one is named (another's is refused: a
 * ProviderException, code "not_this_customer"). Asked twice, it answers the same method.
 * Result: the SavedMethod.
 *
 * @extends Request<SavedMethod>
 */
final class CompleteMethodSetup extends Request
{
    public function __construct(
        /** The setup token's id: MethodSetup::$reference. */
        public readonly string $reference,
        /** The PayPal customer the setup must be for: MethodSetup::$customer. */
        public readonly ?string $customer = null,
    ) {
    }

    public function getMethod(): ?SavedMethod
    {
        return $this->getResult();
    }
}
