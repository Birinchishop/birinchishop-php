<?php

declare(strict_types=1);

namespace Birinchishop\Http;

/**
 * Pluggable HTTP layer. Implement it to use your own HTTP stack (proxies, PSR-18 clients, tests).
 *
 * A transport must return every HTTP response (including 4xx/5xx) as an HttpResponse and
 * throw TransportException only when no response was received (DNS, connection, TLS, timeout).
 */
interface HttpTransport
{
    /**
     * @param string                $method  GET | POST | PATCH | DELETE
     * @param string                $url     absolute URL including the query string
     * @param array<string, string> $headers header name => value
     * @param string|null           $body    raw request body (JSON) or null
     * @param float                 $timeout total timeout in seconds
     *
     * @throws TransportException
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse;
}
