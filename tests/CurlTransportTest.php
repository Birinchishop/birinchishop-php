<?php

declare(strict_types=1);

namespace Birinchishop\Tests;

use Birinchishop\Birinchishop;
use Birinchishop\Exception\ConflictException;
use Birinchishop\Exception\NetworkException;
use Birinchishop\Http\CurlTransport;
use Birinchishop\Http\TransportException;

/**
 * The real cURL transport against a local `php -S` server on 127.0.0.1 (no external network).
 * Skipped silently when proc_open or a free port is unavailable.
 */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $proc = null;
    private static int $port = 0;

    public static function startServer(): bool
    {
        if (self::$proc !== null) {
            return true;
        }
        if (!\function_exists('proc_open') || !\function_exists('curl_init')) {
            return false;
        }
        $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            return false;
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        self::$port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);
        $cmd = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fixtures/server.php'];
        $null = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        if (!\is_resource($proc)) {
            return false;
        }
        self::$proc = $proc;
        register_shutdown_function(static function (): void {
            if (self::$proc !== null) {
                proc_terminate(self::$proc);
                self::$proc = null;
            }
        });
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($c !== false) {
                fclose($c);
                return true;
            }
            usleep(50000);
        }

        return false;
    }

    private function client(int $maxRetries = 0, float $timeout = 5.0): ?Birinchishop
    {
        if (!self::startServer()) {
            return null;
        }

        return new Birinchishop(Fixtures::KEY, baseUrl: 'http://127.0.0.1:' . self::$port . '/api/v1', timeout: $timeout, maxRetries: $maxRetries, transport: new CurlTransport());
    }

    public function testSendsHeadersQueryAndJsonBody(): void
    {
        $c = $this->client();
        if ($c === null) {
            $this->assertTrue(true);
            return;
        }
        $r = $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE, 'name' => "Ali O'g'li"], 'lead-7');
        $d = $r['data'];
        $this->assertSame('POST', $d['method']);
        $this->assertSame('/api/v1/orders', $d['uri']);
        $this->assertSame('lead-7', $d['headers']['idempotency-key']);
        $this->assertSame('Bearer ' . Fixtures::KEY, $d['headers']['authorization']);
        $this->assertSame('application/json', $d['headers']['content-type']);
        $this->assertSame('birinchishop-php/' . Birinchishop::VERSION, $d['headers']['user-agent']);
        $this->assertSame(['link_id' => 1, 'phone' => Fixtures::PHONE, 'name' => "Ali O'g'li"], json_decode($d['body'], true));

        $r = $c->listOffers(['search' => 'soat', 'in_stock_only' => true]);
        $this->assertSame('GET', $r['data']['method']);
        $this->assertSame('/api/v1/offers?search=soat&in_stock_only=true', $r['data']['uri']);
        $this->assertSame('', $r['data']['body']);
        $r = $c->updateWebhook(3, ['is_active' => false]);
        $this->assertSame('PATCH', $r['data']['method']);
        $r = $c->deleteWebhook(3);
        $this->assertSame('DELETE', $r['data']['method']);
    }

    public function testErrorResponsesAreReturnedNotThrownByTheTransport(): void
    {
        $c = $this->client();
        if ($c === null) {
            $this->assertTrue(true);
            return;
        }
        $e = $this->assertThrows(ConflictException::class, fn () => $c->request('GET', '/error'));
        $this->assertSame('duplicate_order', $e->errorCode);
        $this->assertSame('req_loopback', $e->requestId);
        $res = (new CurlTransport())->send('GET', 'http://127.0.0.1:' . self::$port . '/error', [], null, 5.0);
        $this->assertSame(409, $res->status);
        $this->assertSame('req_loopback', $res->header('x-request-id'));
        $this->assertSame('application/json', $res->header('Content-Type'));
    }

    public function testTimeoutAndConnectionErrors(): void
    {
        $c = $this->client(0, 0.3);
        if ($c === null) {
            $this->assertTrue(true);
            return;
        }
        $e = $this->assertThrows(NetworkException::class, fn () => $c->request('GET', '/slow'));
        $this->assertSame('timeout', $e->errorCode);

        $e = $this->assertThrows(TransportException::class, fn () => (new CurlTransport())->send('GET', 'http://127.0.0.1:1/', [], null, 2.0));
        $this->assertFalse($e->timedOut);
    }
}
