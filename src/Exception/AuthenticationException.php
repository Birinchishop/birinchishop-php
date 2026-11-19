<?php

declare(strict_types=1);

namespace Birinchishop\Exception;

/**
 * HTTP 401 (unauthorized, key_revoked, key_expired) and a missing API key (status 0, code missing_api_key).
 */
class AuthenticationException extends BirinchishopException
{
}
