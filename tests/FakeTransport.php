<?php

declare(strict_types=1);

namespace Birinchishop\Tests;

use Birinchishop\Http\HttpResponse;
use Birinchishop\Http\HttpTransport;

/**
 * Records requests and returns canned responses. No network.
 */
final class FakeTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: mixed, raw: ?string, timeout: float}> */
    public array $calls = [];

    /** @param list<HttpResponse|\Throwable> $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body !== null ? json_decode($body, true) : null,
            'raw' => $body,
            'timeout' => $timeout,
        ];
        if ($this->responses === []) {
            throw new \LogicException('unexpected request ' . $method . ' ' . $url);
        }
        $r = array_shift($this->responses);
        if ($r instanceof \Throwable) {
            throw $r;
        }

        return $r;
    }

    public function remaining(): int
    {
        return count($this->responses);
    }
}
