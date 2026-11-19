<?php

declare(strict_types=1);

namespace Birinchishop;

use Birinchishop\Exception\SignatureVerificationException;

/**
 * Webhook signature verification.
 *
 * Header: `X-Birinchishop-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>`.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-Birinchishop-Signature';
    /** Present (value "true") on test events sent for a test key. The event body also has "test": true. */
    public const TEST_HEADER = 'X-Birinchishop-Test';
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verify the signature of a webhook request (constant-time comparison).
     *
     * @param string      $rawBody   the exact bytes received (file_get_contents('php://input')), NOT re-encoded JSON
     * @param string|null $header    value of X-Birinchishop-Signature
     * @param int         $tolerance seconds; timestamps further than this from now are rejected (default 300)
     * @param int|null    $now       unix time override (tests)
     */
    public static function verifySignature(string $rawBody, ?string $header, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('secret is required');
        }
        [$t, $signatures] = self::parseHeader($header);
        if ($t === null || !preg_match('/^[0-9]{1,12}$/', $t) || $signatures === []) {
            return false;
        }
        $current = $now ?? time();
        if (abs($current - (int) $t) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        $valid = false;
        foreach ($signatures as $sig) {
            if (preg_match('/^[0-9a-fA-F]{64}$/', $sig) && hash_equals($expected, strtolower($sig))) {
                $valid = true; // keep looping: no early exit
            }
        }

        return $valid;
    }

    /**
     * Verify the signature and return the decoded event `{id, type, api_version, created_at, data, test?}`.
     * Events may arrive twice or out of order: deduplicate by $event['id'].
     *
     * @return array<string, mixed>
     *
     * @throws SignatureVerificationException if the signature is missing, invalid or too old
     * @throws \JsonException                 if the (correctly signed) body is not valid JSON
     */
    public static function constructEvent(string $rawBody, ?string $header, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): array
    {
        if (!self::verifySignature($rawBody, $header, $secret, $tolerance, $now)) {
            throw new SignatureVerificationException('Invalid or expired X-Birinchishop-Signature');
        }
        $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event)) {
            throw new \UnexpectedValueException('Webhook body is not a JSON object');
        }

        return $event;
    }

    /**
     * Read the signature header from the current PHP request ($_SERVER), for plain-PHP receivers.
     */
    public static function signatureFromGlobals(): ?string
    {
        $v = $_SERVER['HTTP_X_BIRINCHISHOP_SIGNATURE'] ?? null;

        return is_string($v) ? $v : null;
    }

    /**
     * @return array{0: ?string, 1: list<string>}
     */
    private static function parseHeader(?string $header): array
    {
        $t = null;
        $v1 = [];
        if ($header === null) {
            return [$t, $v1];
        }
        foreach (explode(',', $header) as $part) {
            $i = strpos($part, '=');
            if ($i === false) {
                continue;
            }
            $k = trim(substr($part, 0, $i));
            $v = trim(substr($part, $i + 1));
            if ($k === 't') {
                $t = $v;
            } elseif ($k === 'v1') {
                $v1[] = $v;
            }
        }

        return [$t, $v1];
    }
}
