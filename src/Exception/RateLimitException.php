<?php

declare(strict_types=1);

namespace Birinchishop\Exception;

/**
 * HTTP 429. Only code rate_limited is retried automatically; daily_order_limit and too_many_failed_attempts are not. See $retryAfter.
 */
class RateLimitException extends BirinchishopException
{
}
