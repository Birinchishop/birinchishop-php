<?php

declare(strict_types=1);

namespace Birinchishop\Exception;

/**
 * HTTP 409 (duplicate_order, link_limit_reached, webhook_limit_reached, ads_unavailable...).
 */
class ConflictException extends BirinchishopException
{
}
