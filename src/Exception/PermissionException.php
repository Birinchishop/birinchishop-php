<?php

declare(strict_types=1);

namespace Birinchishop\Exception;

/**
 * HTTP 403 (insufficient_scope, orders_write_not_allowed, account_blocked, ip_not_allowed, not_available_in_test_mode, test_mode_only).
 */
class PermissionException extends BirinchishopException
{
}
