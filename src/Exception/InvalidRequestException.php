<?php

declare(strict_types=1);

namespace Birinchishop\Exception;

/**
 * HTTP 400/413/415/422 (invalid_request, unsupported_media_type...) and client-side argument checks (status 0, code invalid_request).
 */
class InvalidRequestException extends BirinchishopException
{
}
