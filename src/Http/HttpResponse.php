<?php

declare(strict_types=1);

namespace Birinchishop\Http;

/**
 * What a transport returns. Header names are compared case-insensitively.
 */
final class HttpResponse
{
    /** @var array<string, string> lower-cased header name => value */
    private array $headers = [];

    /**
     * @param array<string, string|string[]> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> lower-cased header name => value */
    public function headers(): array
    {
        return $this->headers;
    }
}
