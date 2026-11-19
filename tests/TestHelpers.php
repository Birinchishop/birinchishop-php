<?php

declare(strict_types=1);

namespace Birinchishop\Tests;

use Birinchishop\Birinchishop;
use Birinchishop\Http\HttpResponse;

trait TestHelpers
{
    /** @var list<float> seconds the client asked to sleep */
    protected array $sleeps = [];

    /** @param list<HttpResponse|\Throwable> $responses */
    protected function make(array $responses, string $key = Fixtures::KEY, int $maxRetries = 2, float $timeout = 30.0): array
    {
        $t = new FakeTransport($responses);
        $this->sleeps = [];
        $c = new Birinchishop(
            apiKey: $key,
            timeout: $timeout,
            maxRetries: $maxRetries,
            transport: $t,
            sleeper: function (float $s): void {
                $this->sleeps[] = $s;
            },
        );

        return [$c, $t];
    }

    protected static function ok(mixed $data, ?array $meta = null, int $status = 200, array $headers = ['Content-Type' => 'application/json']): HttpResponse
    {
        $body = ['data' => $data, 'request_id' => 'req_1'];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return new HttpResponse($status, json_encode($body), $headers);
    }

    protected static function err(int $status, string $code, string $message = 'x', array $headers = [], ?array $details = null): HttpResponse
    {
        $e = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $e['details'] = $details;
        }

        return new HttpResponse($status, json_encode(['error' => $e, 'request_id' => 'req_e']), $headers);
    }

    protected static function pathOf(array $call): string
    {
        return (string) parse_url($call['url'], PHP_URL_PATH);
    }

    /** @return array<string, string> */
    protected static function queryOf(array $call): array
    {
        $out = [];
        $q = (string) parse_url($call['url'], PHP_URL_QUERY);
        foreach ($q === '' ? [] : explode('&', $q) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $out[rawurldecode($k)] = rawurldecode($v);
        }

        return $out;
    }
}
