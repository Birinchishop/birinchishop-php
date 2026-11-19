<?php

declare(strict_types=1);

namespace Birinchishop\Http;

/**
 * Default transport (ext-curl). TLS certificates are always verified.
 */
final class CurlTransport implements HttpTransport
{
    /**
     * @param array<int, mixed> $curlOptions extra CURLOPT_* options (e.g. CURLOPT_PROXY)
     */
    public function __construct(private readonly array $curlOptions = [])
    {
        if (!\function_exists('curl_init')) {
            throw new \LogicException('ext-curl is required for CurlTransport (or pass your own HttpTransport).');
        }
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new TransportException('curl_init failed');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $lines[] = 'Expect:'; // no "100-continue" round trip
        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round(min($timeout, 10.0) * 1000)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $len = \strlen($line);
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new response (e.g. after 100 Continue)
                } elseif (($i = strpos($line, ':')) !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $i)))] = trim(substr($line, $i + 1));
                }
                return $len;
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $this->curlOptions + $options);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            throw new TransportException($error !== '' ? $error : 'cURL error ' . $errno, $errno === CURLE_OPERATION_TIMEDOUT);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch); // CurlHandle is freed here (curl_close() is a no-op since PHP 8.0)

        return new HttpResponse($status, (string) $raw, $responseHeaders);
    }
}
