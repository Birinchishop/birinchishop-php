<?php

declare(strict_types=1);

namespace Birinchishop\Exception;

/**
 * API error (`{"error": {"code", "message", "details"}, "request_id"}`) or a client-side failure.
 *
 * Branch on $errorCode (stable, machine-readable), never on the message.
 * $status is the HTTP status (0 for network errors, timeouts and client-side checks).
 * Client-side codes: network_error, timeout, missing_api_key, invalid_request, test_mode_only,
 * invalid_response, http_<status>.
 *
 * Subclasses group errors by HTTP status (see BirinchishopException::fromResponse());
 * catching this base class catches every API error.
 */
class BirinchishopException extends \RuntimeException
{
    /**
     * @param list<mixed>|array<string, mixed>|null $details e.g. [["field" => "phone", "message" => "..."]]
     * @param float|null $retryAfter seconds, from Retry-After / RateLimit-Reset
     * @param mixed      $body decoded JSON body (array) or the raw body (string)
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $errorCode = 'unknown_error',
        public readonly ?array $details = null,
        public readonly ?string $requestId = null,
        public readonly ?float $retryAfter = null,
        public readonly mixed $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return list<mixed>|array<string, mixed>|null */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function getRetryAfter(): ?float
    {
        return $this->retryAfter;
    }

    /**
     * Picks the subclass for an HTTP status.
     *
     * @param list<mixed>|array<string, mixed>|null $details
     */
    public static function fromResponse(
        int $status,
        string $errorCode,
        string $message,
        ?array $details = null,
        ?string $requestId = null,
        ?float $retryAfter = null,
        mixed $body = null,
    ): self {
        $class = match (true) {
            $status === 400, $status === 413, $status === 415, $status === 422 => InvalidRequestException::class,
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServerException::class,
            default => self::class,
        };

        return new $class($message, $status, $errorCode, $details, $requestId, $retryAfter, $body);
    }

    public function __toString(): string
    {
        $rid = $this->requestId !== null ? " (request_id={$this->requestId})" : '';
        return static::class . ": [{$this->status} {$this->errorCode}] {$this->getMessage()}{$rid}";
    }
}
