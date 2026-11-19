<?php

declare(strict_types=1);

namespace Birinchishop\Tests;

use Birinchishop\Exception\SignatureVerificationException;
use Birinchishop\TrackingUrl;
use Birinchishop\Webhook;

final class WebhookTest extends TestCase
{
    private const T = 1800000000; // fixed clock

    private static function sign(string $body, int $t, string $secret = Fixtures::SECRET): string
    {
        return "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$body}", $secret);
    }

    private static function body(): string
    {
        return json_encode([
            'id' => 'evt_1', 'type' => 'order.status_changed', 'api_version' => '2026-10-23', 'created_at' => '2026-11-18T10:00:00Z',
            'data' => ['order_id' => 2, 'status' => 'delivered', 'conversion_status' => 'sale', 'customer' => ['name' => "O'zbek", 'region' => 'Тошкент']],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function testAcceptsAValidSignature(): void
    {
        $b = self::body();
        $this->assertTrue(Webhook::verifySignature($b, self::sign($b, self::T), Fixtures::SECRET, now: self::T));
        $this->assertTrue(Webhook::verifySignature($b, self::sign($b, self::T), Fixtures::SECRET, now: self::T + 300));
        $this->assertTrue(Webhook::verifySignature($b, self::sign($b, self::T), Fixtures::SECRET, now: self::T - 300));
        // spaces after commas, upper-case hex
        $sig = 't=' . self::T . ', v1=' . strtoupper(hash_hmac('sha256', self::T . '.' . $b, Fixtures::SECRET));
        $this->assertTrue(Webhook::verifySignature($b, $sig, Fixtures::SECRET, now: self::T));
    }

    public function testAcceptsTheCurrentTimeByDefault(): void
    {
        $b = '{"id":"evt_now"}';
        $this->assertTrue(Webhook::verifySignature($b, self::sign($b, time()), Fixtures::SECRET));
    }

    public function testRejectsTamperedBodyAndWrongSecret(): void
    {
        $b = self::body();
        $h = self::sign($b, self::T);
        $this->assertFalse(Webhook::verifySignature($b . ' ', $h, Fixtures::SECRET, now: self::T));
        $this->assertFalse(Webhook::verifySignature(str_replace('delivered', 'returned', $b), $h, Fixtures::SECRET, now: self::T));
        $this->assertFalse(Webhook::verifySignature(json_encode(json_decode($b)), $h, Fixtures::SECRET, now: self::T), 're-encoded JSON is not the raw body');
        $this->assertFalse(Webhook::verifySignature($b, $h, 'whsec_other', now: self::T));
        // timestamp swapped while keeping the signature
        $this->assertFalse(Webhook::verifySignature($b, str_replace('t=' . self::T, 't=' . (self::T + 1), $h), Fixtures::SECRET, now: self::T));
    }

    public function testRejectsStaleAndFutureTimestamps(): void
    {
        $b = self::body();
        $this->assertFalse(Webhook::verifySignature($b, self::sign($b, self::T - 301), Fixtures::SECRET, now: self::T));
        $this->assertFalse(Webhook::verifySignature($b, self::sign($b, self::T + 301), Fixtures::SECRET, now: self::T));
        $this->assertTrue(Webhook::verifySignature($b, self::sign($b, self::T - 301), Fixtures::SECRET, 600, self::T));
        $this->assertFalse(Webhook::verifySignature($b, self::sign($b, self::T - 61), Fixtures::SECRET, 60, self::T));
    }

    public function testRejectsJunkHeaders(): void
    {
        $b = '{"id":"evt_1"}';
        $good = hash_hmac('sha256', self::T . '.' . $b, Fixtures::SECRET);
        foreach (['', 'garbage', 't=' . self::T, 't=' . self::T . ',v1=zz', 'v1=' . $good, 't=abc,v1=' . $good,
                  't=-' . self::T . ',v1=' . $good, 't=' . self::T . ',v0=' . $good, 't=' . self::T . ',v1=' . substr($good, 0, 63)] as $h) {
            $this->assertFalse(Webhook::verifySignature($b, $h, Fixtures::SECRET, now: self::T), "header '{$h}'");
        }
        $this->assertFalse(Webhook::verifySignature($b, null, Fixtures::SECRET, now: self::T));
    }

    public function testAcceptsAnyMatchingV1DuringSecretRotation(): void
    {
        $b = '{"id":"evt_1"}';
        $h = 't=' . self::T . ',v1=' . str_repeat('a', 64) . ',v1=' . hash_hmac('sha256', self::T . '.' . $b, Fixtures::SECRET);
        $this->assertTrue(Webhook::verifySignature($b, $h, Fixtures::SECRET, now: self::T));
    }

    public function testRequiresASecret(): void
    {
        $this->assertThrows(\InvalidArgumentException::class, fn () => Webhook::verifySignature('{}', 't=1,v1=' . str_repeat('a', 64), ''));
    }

    public function testConstructEventReturnsTheDecodedEvent(): void
    {
        $b = self::body();
        $e = Webhook::constructEvent($b, self::sign($b, self::T), Fixtures::SECRET, now: self::T + 10);
        $this->assertSame('evt_1', $e['id']);
        $this->assertSame('sale', $e['data']['conversion_status']);
        $this->assertSame("O'zbek", $e['data']['customer']['name']);
    }

    public function testConstructEventThrowsOnInvalidOrStaleSignature(): void
    {
        $b = self::body();
        $this->assertThrows(SignatureVerificationException::class, fn () => Webhook::constructEvent($b, self::sign($b, self::T), Fixtures::SECRET, now: self::T + 1000));
        $this->assertThrows(SignatureVerificationException::class, fn () => Webhook::constructEvent($b, null, Fixtures::SECRET));
        $this->assertThrows(SignatureVerificationException::class, fn () => Webhook::constructEvent($b . 'x', self::sign($b, self::T), Fixtures::SECRET, now: self::T));
    }

    public function testConstructEventRejectsNonObjectBodies(): void
    {
        $this->assertThrows(\JsonException::class, fn () => Webhook::constructEvent('not json', self::sign('not json', self::T), Fixtures::SECRET, now: self::T));
        $this->assertThrows(\UnexpectedValueException::class, fn () => Webhook::constructEvent('42', self::sign('42', self::T), Fixtures::SECRET, now: self::T));
    }

    public function testTestEventsAreRecognisable(): void
    {
        $b = '{"id":"evt_test_abc","type":"order.created","test":true,"data":{"order_id":2000000001}}';
        $e = Webhook::constructEvent($b, self::sign($b, self::T), Fixtures::SECRET, now: self::T);
        $this->assertTrue($e['test']);
        $this->assertSame('X-Birinchishop-Test', Webhook::TEST_HEADER);
    }

    public function testSignatureFromGlobals(): void
    {
        $old = $_SERVER['HTTP_X_BIRINCHISHOP_SIGNATURE'] ?? null;
        unset($_SERVER['HTTP_X_BIRINCHISHOP_SIGNATURE']);
        $this->assertNull(Webhook::signatureFromGlobals());
        $_SERVER['HTTP_X_BIRINCHISHOP_SIGNATURE'] = 't=1,v1=ab';
        $this->assertSame('t=1,v1=ab', Webhook::signatureFromGlobals());
        if ($old === null) {
            unset($_SERVER['HTTP_X_BIRINCHISHOP_SIGNATURE']);
        } else {
            $_SERVER['HTTP_X_BIRINCHISHOP_SIGNATURE'] = $old;
        }
        $this->assertSame('X-Birinchishop-Signature', Webhook::SIGNATURE_HEADER);
    }

    public function testFillTrackingUrl(): void
    {
        $tpl = 'https://birinchishop.uz/l/abc123?click_id={click_id}&sub1={sub1}&sub2={sub2}&sub3={sub3}&sub4={sub4}&sub5={sub5}';
        $this->assertSame('https://birinchishop.uz/l/abc123?click_id={clickid}&sub1=camp%201%26x',
            TrackingUrl::fill($tpl, ['click_id' => '{clickid}', 'sub1' => 'camp 1&x']));
        $this->assertSame('https://birinchishop.uz/l/abc123', TrackingUrl::fill($tpl));
        $this->assertSame('https://birinchishop.uz/l/abc123?sub2=5', TrackingUrl::fill($tpl, ['sub2' => 5, 'sub3' => '', 'sub4' => null]));
        $this->assertSame('https://birinchishop.uz/l/x?keep=1&sub1=a', TrackingUrl::fill('https://birinchishop.uz/l/x?keep=1&sub1={sub1}', ['sub1' => 'a']));
        $this->assertSame('https://birinchishop.uz/l/x', TrackingUrl::fill('https://birinchishop.uz/l/x', ['sub1' => 'a']));
    }
}
