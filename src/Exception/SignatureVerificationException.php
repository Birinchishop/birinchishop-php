<?php

declare(strict_types=1);

namespace Birinchishop\Exception;

/**
 * Webhook signature is missing, invalid or too old.
 */
class SignatureVerificationException extends \UnexpectedValueException
{
}
