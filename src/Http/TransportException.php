<?php

declare(strict_types=1);

namespace Birinchishop\Http;

/**
 * Thrown by a transport when no HTTP response was received.
 * The client converts it into a NetworkException (code "network_error" or "timeout").
 */
class TransportException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $timedOut = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
