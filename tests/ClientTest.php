<?php

declare(strict_types=1);

namespace Birinchishop\Tests;

use Birinchishop\Birinchishop;
use Birinchishop\Exception\AuthenticationException;
use Birinchishop\Exception\BirinchishopException;
use Birinchishop\Exception\ConflictException;
use Birinchishop\Exception\InvalidRequestException;
use Birinchishop\Exception\NetworkException;
use Birinchishop\Exception\NotFoundException;
use Birinchishop\Exception\PermissionException;
use Birinchishop\Exception\RateLimitException;
use Birinchishop\Exception\ServerException;
use Birinchishop\Http\HttpResponse;
use Birinchishop\Http\TransportException;

/**
 * No network: every test injects FakeTransport and a fake sleeper.
 */
final class ClientTest extends TestCase
{
    private ?string $savedEnv = null;

    protected function setUp(): void
    {
        $v = getenv('BSK_KEY');
        $this->savedEnv = $v === false ? null : $v;
        putenv('BSK_KEY');
        unset($_ENV['BSK_KEY'], $_SERVER['BSK_KEY']);
    }

    protected function tearDown(): void
    {
        $this->savedEnv === null ? putenv('BSK_KEY') : putenv('BSK_KEY=' . $this->savedEnv);
    }

    // ── construction ───────────────────────────────────────────────────

    public function testRequiresAnApiKey(): void
    {
        $e = $this->assertThrows(AuthenticationException::class, fn () => new Birinchishop(transport: new FakeTransport([])));
        $this->assertSame('missing_api_key', $e->errorCode);
        $this->assertSame(0, $e->status);
        $this->assertThrows(AuthenticationException::class, fn () => new Birinchishop('  ', transport: new FakeTransport([])));
    }

    public function testReadsBskKeyFromEnvironment(): void
    {
        putenv('BSK_KEY=' . Fixtures::KEY);
        $c = new Birinchishop(transport: new FakeTransport([]));
        $this->assertSame(Fixtures::KEY, $c->apiKey);
        $this->assertSame(Birinchishop::DEFAULT_BASE_URL, $c->baseUrl);
        $this->assertSame(30.0, $c->timeout);
        $this->assertSame(2, $c->maxRetries);
    }

    public function testDefaultTransportIsCurl(): void
    {
        $c = new Birinchishop(Fixtures::KEY);
        $p = (new \ReflectionClass($c))->getProperty('transport');
        $this->assertInstanceOf(\Birinchishop\Http\CurlTransport::class, $p->getValue($c));
    }

    public function testGetAccountSendsAuthAcceptUserAgentAndTimeout(): void
    {
        [$c, $t] = $this->make([self::ok(['id' => 7, 'balance' => 1000, 'currency' => 'UZS'])], timeout: 12.5);
        $r = $c->getAccount();
        $this->assertSame(7, $r['data']['id']);
        $this->assertSame('req_1', $r['request_id']);
        $call = $t->calls[0];
        $this->assertSame('https://birinchishop.uz/api/v1/me', $call['url']);
        $this->assertSame('GET', $call['method']);
        $this->assertSame('Bearer ' . Fixtures::KEY, $call['headers']['Authorization']);
        $this->assertSame('application/json', $call['headers']['Accept']);
        $this->assertSame('birinchishop-php/' . Birinchishop::VERSION, $call['headers']['User-Agent']);
        $this->assertArrayNotHasKey('Content-Type', $call['headers']);
        $this->assertNull($call['raw']);
        $this->assertSame(12.5, $call['timeout']);
    }

    public function testUserAgentSuffixAndBaseUrl(): void
    {
        $t = new FakeTransport([self::ok([])]);
        $c = new Birinchishop(Fixtures::KEY, baseUrl: 'https://example.test/api/v1///', transport: $t, userAgent: 'my-crm/2.0');
        $c->getReference();
        $this->assertSame('https://example.test/api/v1/reference', $t->calls[0]['url']);
        $this->assertSame('birinchishop-php/0.1.0 my-crm/2.0', $t->calls[0]['headers']['User-Agent']);
    }

    public function testUnknownResponseFieldsArePreserved(): void
    {
        [$c] = $this->make([new HttpResponse(200, '{"data":{"id":1,"brand_new_field":{"x":[1,2]}},"request_id":"r","extra_top_level":true}')]);
        $r = $c->getOffer(1);
        $this->assertSame(['x' => [1, 2]], $r['data']['brand_new_field']);
        $this->assertTrue($r['extra_top_level']);
    }

    // ── query & path ───────────────────────────────────────────────────

    public function testListOffersSerializesQueryParams(): void
    {
        [$c, $t] = $this->make([self::ok([], ['page' => 1, 'limit' => 20, 'total' => 0, 'total_pages' => 0])]);
        $c->listOffers(['search' => "soat qo'l", 'sort' => 'best_selling', 'in_stock_only' => false, 'ids' => [1, 2], 'min_payout' => null, 'min_sale_rate' => '', 'lang' => 'ru']);
        $call = $t->calls[0];
        $q = self::queryOf($call);
        $this->assertSame('/api/v1/offers', self::pathOf($call));
        $this->assertSame("soat qo'l", $q['search']);
        $this->assertSame('best_selling', $q['sort']);
        $this->assertSame('false', $q['in_stock_only']);
        $this->assertSame('1,2', $q['ids']);
        $this->assertSame('ru', $q['lang']);
        $this->assertArrayNotHasKey('min_payout', $q);
        $this->assertArrayNotHasKey('min_sale_rate', $q);
        $this->assertTrue(str_contains($call['url'], 'search=soat%20qo%27l'));
    }

    public function testDateObjectsAreFormatted(): void
    {
        [$c, $t] = $this->make([self::ok([])]);
        $c->listOrders([
            'date_from' => new \DateTimeImmutable('2026-11-01 13:00:00', new \DateTimeZone('Asia/Tashkent')),
            'date_to' => '2026-11-18',
            'updated_since' => new \DateTimeImmutable('2026-11-18T10:00:00Z'),
            'include_order' => true,
        ]);
        $q = self::queryOf($t->calls[0]);
        $this->assertSame('2026-11-01', $q['date_from']);
        $this->assertSame('2026-11-18', $q['date_to']);
        $this->assertSame('2026-11-18T10:00:00+00:00', $q['updated_since']);
        $this->assertSame('true', $q['include_order']);
    }

    public function testPathIdsAreValidated(): void
    {
        [$c, $t] = $this->make([self::ok(['id' => 5]), self::ok(['percent' => 40]), self::ok(['id' => 3])]);
        $c->getOffer(5, 'en');
        $c->getOrderSaleChance(9);
        $c->getLink('3');
        $this->assertSame('https://birinchishop.uz/api/v1/offers/5?lang=en', $t->calls[0]['url']);
        $this->assertSame('https://birinchishop.uz/api/v1/orders/9/sale-chance', $t->calls[1]['url']);
        $this->assertSame('https://birinchishop.uz/api/v1/links/3', $t->calls[2]['url']);
        foreach (['1/../me', '0', '-1', '', '1.5', ' 7'] as $bad) {
            $e = $this->assertThrows(InvalidRequestException::class, fn () => $c->getOrder($bad));
            $this->assertSame('invalid_request', $e->errorCode);
        }
        $this->assertThrows(InvalidRequestException::class, fn () => $c->getOrder(0));
        $this->assertThrows(InvalidRequestException::class, fn () => $c->deleteWebhook(-3));
        $this->assertThrows(InvalidRequestException::class, fn () => $c->getAction(2147483648));
        $this->assertCount(3, $t->calls, 'invalid ids never reach the network');
    }

    // ── links & orders ─────────────────────────────────────────────────

    public function testCreateLinkPostsJson(): void
    {
        [$c, $t] = $this->make([
            self::ok(['id' => 11, 'offer_id' => 432], status: 201),
            self::ok(['id' => 12], status: 201),
        ]);
        $r = $c->createLink(432, 'Reels');
        $this->assertSame(11, $r['data']['id']);
        $this->assertSame('POST', $t->calls[0]['method']);
        $this->assertSame('application/json', $t->calls[0]['headers']['Content-Type']);
        $this->assertSame(['offer_id' => 432, 'name' => 'Reels'], $t->calls[0]['body']);
        $c->createLink('433');
        $this->assertSame('{"offer_id":433}', $t->calls[1]['raw']);
    }

    public function testCreateLinkIsNotRetried(): void
    {
        [$c, $t] = $this->make([self::err(500, 'internal_error')]);
        $this->assertThrows(ServerException::class, fn () => $c->createLink(1));
        $this->assertCount(1, $t->calls);
    }

    public function testCreateOrderAlwaysSendsAnIdempotencyKeyKeptAcrossRetries(): void
    {
        [$c, $t] = $this->make([
            self::err(500, 'internal_error'),
            new TransportException('connection reset'),
            self::ok(['id' => 99, 'status' => 'new'], status: 201),
        ]);
        $r = $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE, 'name' => null]);
        $this->assertSame(99, $r['data']['id']);
        $this->assertArrayNotHasKey('replayed', $r);
        $k = $t->calls[0]['headers']['Idempotency-Key'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $k);
        $this->assertSame($k, $t->calls[1]['headers']['Idempotency-Key']);
        $this->assertSame($k, $t->calls[2]['headers']['Idempotency-Key']);
        $this->assertSame(['link_id' => 1, 'phone' => Fixtures::PHONE], $t->calls[0]['body'], 'null fields are dropped');
        $this->assertCount(2, $this->sleeps);
    }

    public function testCreateOrderGeneratesADifferentKeyPerCall(): void
    {
        [$c, $t] = $this->make([self::ok(['id' => 1], status: 201), self::ok(['id' => 2], status: 201)]);
        $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE]);
        $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE]);
        $this->assertTrue($t->calls[0]['headers']['Idempotency-Key'] !== $t->calls[1]['headers']['Idempotency-Key']);
    }

    public function testCreateOrderUsesTheGivenKeyAndReportsReplays(): void
    {
        [$c, $t] = $this->make([self::ok(['id' => 99], headers: ['content-type' => 'application/json', 'idempotent-replayed' => 'true'])]);
        $r = $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE, 'external_id' => 'crm-1', 'sub1' => 'reels'], 'lead-crm-1');
        $this->assertSame('lead-crm-1', $t->calls[0]['headers']['Idempotency-Key']);
        $this->assertTrue($r['replayed']);
        $this->assertSame('crm-1', $t->calls[0]['body']['external_id']);
    }

    public function testCreateOrderValidatesRequiredFieldsAndKeyFormat(): void
    {
        [$c, $t] = $this->make([]);
        $e = $this->assertThrows(InvalidRequestException::class, fn () => $c->createOrder(['phone' => Fixtures::PHONE]));
        $this->assertSame('link_id', $e->details[0]['field']);
        $e = $this->assertThrows(InvalidRequestException::class, fn () => $c->createOrder(['link_id' => 1]));
        $this->assertSame('phone', $e->details[0]['field']);
        $this->assertThrows(InvalidRequestException::class, fn () => $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE], 'has spaces'));
        $this->assertThrows(InvalidRequestException::class, fn () => $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE], str_repeat('a', 129)));
        $this->assertThrows(InvalidRequestException::class, fn () => $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE], ''));
        $this->assertCount(0, $t->calls);
    }

    // ── errors ─────────────────────────────────────────────────────────

    public function testApiErrorsMapToTypedExceptions(): void
    {
        $cases = [
            [400, 'invalid_request', InvalidRequestException::class],
            [415, 'unsupported_media_type', InvalidRequestException::class],
            [401, 'unauthorized', AuthenticationException::class],
            [401, 'key_revoked', AuthenticationException::class],
            [403, 'insufficient_scope', PermissionException::class],
            [403, 'orders_write_not_allowed', PermissionException::class],
            [404, 'order_not_found', NotFoundException::class],
            [409, 'duplicate_order', ConflictException::class],
            [429, 'daily_order_limit', RateLimitException::class],
            [503, 'offer_temporarily_unavailable', ServerException::class],
            [418, 'teapot', BirinchishopException::class],
        ];
        foreach ($cases as [$status, $code, $class]) {
            [$c, $t] = $this->make([self::err($status, $code, 'msg ' . $code, details: [['field' => 'phone', 'message' => 'bad']])]);
            $e = $this->assertThrows($class, fn () => $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE]));
            $this->assertSame($class, $e::class, "{$status} {$code}");
            $this->assertInstanceOf(BirinchishopException::class, $e);
            $this->assertSame($status, $e->status);
            $this->assertSame($status, $e->getStatus());
            $this->assertSame($status, $e->getCode());
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($code, $e->getErrorCode());
            $this->assertSame('msg ' . $code, $e->getMessage());
            $this->assertSame('req_e', $e->requestId);
            $this->assertSame('req_e', $e->getRequestId());
            $this->assertSame([['field' => 'phone', 'message' => 'bad']], $e->getDetails());
            $this->assertSame($code, $e->body['error']['code']);
            $this->assertCount(1, $t->calls, "business error {$code} is never retried");
        }
    }

    public function testNonJsonErrorBodyUsesHttpStatusCodeAndRequestIdHeader(): void
    {
        [$c, $t] = $this->make([new HttpResponse(502, '<html>Bad gateway</html>', ['X-Request-Id' => 'req_hdr'])]);
        $e = $this->assertThrows(ServerException::class, fn () => $c->resumeCampaign(4));
        $this->assertSame('http_502', $e->errorCode);
        $this->assertSame('HTTP 502', $e->getMessage());
        $this->assertSame('req_hdr', $e->requestId);
        $this->assertSame('<html>Bad gateway</html>', $e->body);
        $this->assertCount(1, $t->calls, 'other POSTs are not retried');
    }

    public function testSuccessStatusWithInvalidJsonIsInvalidResponse(): void
    {
        [$c] = $this->make([new HttpResponse(200, 'not json')], maxRetries: 0);
        $e = $this->assertThrows(BirinchishopException::class, fn () => $c->getInsights());
        $this->assertSame('invalid_response', $e->errorCode);
        $this->assertSame(200, $e->status);
    }

    public function testExceptionStringIncludesStatusCodeAndRequestId(): void
    {
        $e = new ConflictException('dup', 409, 'duplicate_order', null, 'req_9');
        $this->assertTrue(str_contains((string) $e, '[409 duplicate_order] dup (request_id=req_9)'));
        $this->assertNull($e->getRetryAfter());
    }

    // ── retries ────────────────────────────────────────────────────────

    public function testRateLimitedIsRetriedAfterRetryAfter(): void
    {
        [$c, $t] = $this->make([self::err(429, 'rate_limited', headers: ['Retry-After' => '3']), self::ok([])]);
        $r = $c->listOrders();
        $this->assertSame([], $r['data']);
        $this->assertCount(2, $t->calls);
        $this->assertSame([3.0], $this->sleeps);
    }

    public function testRetryAfterIsCappedAtSixtySeconds(): void
    {
        [$c] = $this->make([self::err(429, 'rate_limited', headers: ['retry-after' => '600']), self::ok([])]);
        $c->getStats();
        $this->assertSame([60.0], $this->sleeps);
    }

    public function testRetryAfterHttpDateAndRateLimitResetFallback(): void
    {
        $date = gmdate('D, d M Y H:i:s', time() + 5) . ' GMT';
        [$c] = $this->make([
            self::err(503, 'internal_error', headers: ['Retry-After' => $date]),
            self::err(429, 'rate_limited', headers: ['RateLimit-Reset' => '7']),
            self::ok([]),
        ]);
        $c->getAdsStatus();
        $this->assertCount(2, $this->sleeps);
        $this->assertGreaterThanOrEqual(3.0, $this->sleeps[0]);
        $this->assertLessThanOrEqual(5.0, $this->sleeps[0]);
        $this->assertSame(7.0, $this->sleeps[1]);
    }

    public function testRateLimitExceptionCarriesRetryAfterWhenRetriesAreExhausted(): void
    {
        [$c, $t] = $this->make([
            self::err(429, 'rate_limited', headers: ['Retry-After' => '1']),
            self::err(429, 'rate_limited', headers: ['Retry-After' => '1']),
            self::err(429, 'rate_limited', headers: ['Retry-After' => '2']),
        ]);
        $e = $this->assertThrows(RateLimitException::class, fn () => $c->listLinks());
        $this->assertSame(2.0, $e->retryAfter);
        $this->assertCount(3, $t->calls, 'maxRetries=2 means 3 attempts');
        $this->assertSame([1.0, 1.0], $this->sleeps);
    }

    public function testServerErrorsUseExponentialBackoffWithJitter(): void
    {
        [$c, $t] = $this->make([self::err(500, 'internal_error'), self::err(502, 'http_502'), self::err(500, 'internal_error'), self::ok([])], maxRetries: 3);
        $c->getOrder(1);
        $this->assertCount(4, $t->calls);
        $this->assertCount(3, $this->sleeps);
        foreach ([[0.5, 0.75], [1.0, 1.25], [2.0, 2.25]] as $i => [$min, $max]) {
            $this->assertGreaterThanOrEqual($min, $this->sleeps[$i]);
            $this->assertLessThanOrEqual($max, $this->sleeps[$i]);
        }
    }

    public function testBackoffIsCappedAtEightSeconds(): void
    {
        [$c] = $this->make([]);
        for ($i = 0; $i < 10; $i++) {
            $b = $c->backoff($i);
            $this->assertGreaterThanOrEqual(min(8.0, 0.5 * 2 ** $i), $b);
            $this->assertLessThanOrEqual(min(8.0, 0.5 * 2 ** $i) + 0.25, $b);
        }
    }

    public function testDailyOrderLimitAndOfferUnavailableAreNotRetried(): void
    {
        [$c, $t] = $this->make([self::err(429, 'daily_order_limit')]);
        $this->assertThrows(RateLimitException::class, fn () => $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE]));
        $this->assertCount(1, $t->calls);
        [$c, $t] = $this->make([self::err(503, 'offer_temporarily_unavailable')]);
        $this->assertThrows(ServerException::class, fn () => $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE]));
        $this->assertCount(1, $t->calls);
        $this->assertSame([], $this->sleeps);
    }

    public function testNetworkErrorsOnGetAreRetriedThenRaised(): void
    {
        [$c, $t] = $this->make([new TransportException('could not resolve host'), new TransportException('could not resolve host')], maxRetries: 1);
        $e = $this->assertThrows(NetworkException::class, fn () => $c->getInsights());
        $this->assertSame('network_error', $e->errorCode);
        $this->assertSame(0, $e->status);
        $this->assertInstanceOf(TransportException::class, $e->getPrevious());
        $this->assertCount(2, $t->calls);
    }

    public function testTimeoutIsRaisedAsCodeTimeout(): void
    {
        [$c] = $this->make([new TransportException('Operation timed out', true)], maxRetries: 0, timeout: 5.0);
        $e = $this->assertThrows(NetworkException::class, fn () => $c->getAccount());
        $this->assertSame('timeout', $e->errorCode);
        $this->assertSame('Request timed out after 5 s', $e->getMessage());
    }

    public function testNetworkErrorsOnOtherPostsAreNotRetried(): void
    {
        [$c, $t] = $this->make([new TransportException('reset')]);
        $this->assertThrows(NetworkException::class, fn () => $c->pauseCampaign(4));
        $this->assertCount(1, $t->calls);
    }

    public function testRequestCanForceRetry(): void
    {
        [$c, $t] = $this->make([self::err(500, 'internal_error'), self::ok(['ok' => true])]);
        $r = $c->request('post', '/webhooks/1/test', retry: true);
        $this->assertTrue($r['data']['ok']);
        $this->assertSame('POST', $t->calls[0]['method']);
        $this->assertCount(2, $t->calls);
    }

    // ── coverage of every endpoint ─────────────────────────────────────

    public function testAllEndpointsMapToTheDocumentedMethodAndPath(): void
    {
        $expected = [
            ['getAccount', [], 'GET', '/me', null],
            ['listOffers', [], 'GET', '/offers', null],
            ['getOffer', [1], 'GET', '/offers/1', null],
            ['listLinks', [['offer_id' => 1]], 'GET', '/links', null],
            ['createLink', [1], 'POST', '/links', ['offer_id' => 1]],
            ['getLink', [2], 'GET', '/links/2', null],
            ['listOrders', [], 'GET', '/orders', null],
            ['getOrder', [3], 'GET', '/orders/3', null],
            ['createOrder', [['link_id' => 2, 'phone' => Fixtures::PHONE]], 'POST', '/orders', ['link_id' => 2, 'phone' => Fixtures::PHONE]],
            ['getStats', [['group_by' => 'offer']], 'GET', '/stats', null],
            ['listEvents', [['after' => 5]], 'GET', '/events', null],
            ['getOrderSaleChance', [3], 'GET', '/orders/3/sale-chance', null],
            ['getInsights', [], 'GET', '/insights', null],
            ['getAdsStatus', [], 'GET', '/ads/status', null],
            ['listCampaigns', [['period' => '30d']], 'GET', '/ads/campaigns', null],
            ['pauseCampaign', [4], 'POST', '/ads/campaigns/4/pause', null],
            ['resumeCampaign', [4], 'POST', '/ads/campaigns/4/resume', null],
            ['changeCampaignBudget', [4, 50000], 'POST', '/ads/campaigns/4/budget', ['daily_budget' => 50000]],
            ['changeCampaignBudget', [4, 12.5, 'ACCOUNT'], 'POST', '/ads/campaigns/4/budget', ['daily_budget' => 12.5, 'unit' => 'ACCOUNT']],
            ['getAction', [6], 'GET', '/actions/6', null],
            ['listWebhooks', [], 'GET', '/webhooks', null],
            ['createWebhook', ['https://example.com/h'], 'POST', '/webhooks', ['url' => 'https://example.com/h']],
            ['createWebhook', ['https://example.com/p?c={click_id}', ['kind' => 'postback', 'events' => ['order.status_changed'], 'description' => null]], 'POST', '/webhooks',
                ['url' => 'https://example.com/p?c={click_id}', 'kind' => 'postback', 'events' => ['order.status_changed']]],
            ['updateWebhook', [8, ['is_active' => false]], 'PATCH', '/webhooks/8', ['is_active' => false]],
            ['deleteWebhook', [8], 'DELETE', '/webhooks/8', null],
            ['testWebhook', [8], 'POST', '/webhooks/8/test', null],
            ['listWebhookDeliveries', [8, ['limit' => 10]], 'GET', '/webhooks/8/deliveries', null],
            ['getReference', [], 'GET', '/reference', null],
        ];
        [$c, $t] = $this->make(array_map(fn () => self::ok([]), $expected));
        foreach ($expected as [$m, $args]) {
            $c->{$m}(...$args);
        }
        foreach ($expected as $i => [$m, , $method, $path, $body]) {
            $this->assertSame($method, $t->calls[$i]['method'], $m);
            $this->assertSame('/api/v1' . $path, self::pathOf($t->calls[$i]), $m);
            $this->assertSame($body, $t->calls[$i]['body'], $m . ' body');
        }
        $this->assertSame(['limit' => '10'], self::queryOf($t->calls[26]));
        $this->assertSame(['period' => '30d'], self::queryOf($t->calls[14]));
    }

    public function testEmptyBodyIsSentAsJsonObject(): void
    {
        [$c, $t] = $this->make([self::ok([])]);
        $c->updateWebhook(8, ['description' => null]);
        $this->assertSame('{}', $t->calls[0]['raw']);
    }

    public function testEveryOperationIdInOpenApiHasAMethod(): void
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/fixtures/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $skip = ['mcp']; // MCP is JSON-RPC for AI clients, not a REST resource
        $seen = 0;
        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $op) {
                if (!is_array($op) || !isset($op['operationId']) || in_array($op['operationId'], $skip, true)) {
                    continue;
                }
                $this->assertTrue(method_exists(Birinchishop::class, $op['operationId']), strtoupper($method) . " {$path} -> {$op['operationId']}");
                $seen++;
            }
        }
        $this->assertGreaterThanOrEqual(27, $seen);
    }

    public function testPostbackMacrosMatchTheSpec(): void
    {
        $spec = json_decode((string) file_get_contents(__DIR__ . '/fixtures/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($spec['x-postback-macros'], Birinchishop::POSTBACK_MACROS);
        $this->assertSame($spec['info']['version'], Birinchishop::API_VERSION);
    }

    // ── pagination ─────────────────────────────────────────────────────

    public function testIterOrdersWalksAllPages(): void
    {
        [$c, $t] = $this->make([
            self::ok([['id' => 1], ['id' => 2]], ['page' => 1, 'limit' => 2, 'total' => 3, 'total_pages' => 2]),
            self::ok([['id' => 3]], ['page' => 2, 'limit' => 2, 'total' => 3, 'total_pages' => 2]),
        ]);
        $ids = [];
        foreach ($c->iterOrders(['status' => 'delivered', 'limit' => 2]) as $o) {
            $ids[] = $o['id'];
        }
        $this->assertSame([1, 2, 3], $ids);
        $q = self::queryOf($t->calls[1]);
        $this->assertSame('2', $q['page']);
        $this->assertSame('2', $q['limit']);
        $this->assertSame('delivered', $q['status']);
    }

    public function testIterOffersAndLinksDefaultToLimit100AndStopOnEmptyPage(): void
    {
        [$c, $t] = $this->make([
            self::ok([['id' => 1]], ['total_pages' => 5]),
            self::ok([], ['total_pages' => 5]),
            self::ok([['id' => 9]], ['total_pages' => 1]),
        ]);
        $this->assertCount(1, iterator_to_array($c->iterOffers(['sort' => 'newest']), false));
        $this->assertSame('100', self::queryOf($t->calls[0])['limit']);
        $this->assertSame('1', self::queryOf($t->calls[0])['page']);
        $this->assertCount(2, $t->calls, 'stops on an empty page');
        $this->assertSame([['id' => 9]], iterator_to_array($c->iterLinks(['page' => 3]), false));
        $this->assertSame('3', self::queryOf($t->calls[2])['page']);
        $this->assertSame('/api/v1/links', self::pathOf($t->calls[2]));
    }

    public function testPaginateIsLazyAndRejectsUnsupportedMethods(): void
    {
        [$c, $t] = $this->make([]);
        $gen = $c->iterOrders();
        $this->assertCount(0, $t->calls, 'no request until iteration starts');
        $this->assertThrows(InvalidRequestException::class, fn () => iterator_to_array($c->paginate('listEvents')));
    }

    public function testIterEventsFollowsNextCursorUntilHasMoreIsFalse(): void
    {
        [$c, $t] = $this->make([
            self::ok([['id' => 10], ['id' => 11]], ['next_cursor' => 11, 'has_more' => true]),
            self::ok([['id' => 12]], ['next_cursor' => 12, 'has_more' => false]),
        ]);
        $ids = [];
        foreach ($c->iterEvents(['after' => 9, 'include_order' => true]) as $e) {
            $ids[] = $e['id'];
        }
        $this->assertSame([10, 11, 12], $ids);
        $this->assertSame('9', self::queryOf($t->calls[0])['after']);
        $this->assertSame('11', self::queryOf($t->calls[1])['after']);
        $this->assertSame('true', self::queryOf($t->calls[1])['include_order']);
    }

    public function testIterEventsStopsWhenTheCursorDoesNotMove(): void
    {
        [$c, $t] = $this->make([self::ok([], ['next_cursor' => 0, 'has_more' => true])]);
        $this->assertSame([], iterator_to_array($c->iterEvents(), false));
        $this->assertSame('0', self::queryOf($t->calls[0])['after']);
        $this->assertCount(1, $t->calls);
    }

    // ── test mode (bsk_test_) ──────────────────────────────────────────

    public function testIsTestModeFollowsTheKeyPrefix(): void
    {
        [$live] = $this->make([]);
        [$test] = $this->make([], Fixtures::TEST_KEY);
        $this->assertFalse($live->isTestMode());
        $this->assertTrue($test->isTestMode());
    }

    public function testSetTestOrderStatusRefusesLiveKeysWithoutARequest(): void
    {
        [$c, $t] = $this->make([]);
        $e = $this->assertThrows(PermissionException::class, fn () => $c->setTestOrderStatus(2000000001, 'delivered'));
        $this->assertSame('test_mode_only', $e->errorCode);
        $this->assertCount(0, $t->calls);
    }

    public function testSetTestOrderStatusWithATestKey(): void
    {
        [$c, $t] = $this->make([self::ok(['order' => ['id' => 2000000001, 'status' => 'delivered', 'test' => true], 'previous_status' => 'new', 'webhooks' => ['matched' => 0, 'sent' => 0]])], Fixtures::TEST_KEY);
        $r = $c->setTestOrderStatus('2000000001', 'delivered');
        $this->assertSame('delivered', $r['data']['order']['status']);
        $this->assertSame('POST', $t->calls[0]['method']);
        $this->assertSame('/api/v1/sandbox/orders/2000000001/status', self::pathOf($t->calls[0]));
        $this->assertSame(['status' => 'delivered'], $t->calls[0]['body']);
        $this->assertSame('Bearer ' . Fixtures::TEST_KEY, $t->calls[0]['headers']['Authorization']);
        $this->assertCount(0, $this->sleeps, 'sandbox status changes are not retried');
    }

    public function testCreateOrderWithATestKeyIsTheSameRequest(): void
    {
        $resp = self::ok(['id' => 2000000123, 'status' => 'new', 'test' => true, 'live_orders_allowed' => false], status: 201,
            headers: ['Content-Type' => 'application/json', 'X-Birinchishop-Mode' => 'test']);
        [$c, $t] = $this->make([$resp], Fixtures::TEST_KEY);
        $r = $c->createOrder(['link_id' => 1, 'phone' => Fixtures::PHONE], 'lead-1');
        $this->assertTrue($r['data']['test']);
        $this->assertGreaterThanOrEqual(Birinchishop::TEST_ID_BASE, $r['data']['id']);
        $this->assertSame('lead-1', $t->calls[0]['headers']['Idempotency-Key']);
        $this->assertSame('/api/v1/orders', self::pathOf($t->calls[0]));
    }

    public function testTestModeOnlyScopesMapToPermissionException(): void
    {
        [$c] = $this->make([self::err(403, 'not_available_in_test_mode')], Fixtures::TEST_KEY);
        $e = $this->assertThrows(PermissionException::class, fn () => $c->pauseCampaign(1));
        $this->assertSame('not_available_in_test_mode', $e->errorCode);
    }

    public function testVersionConstant(): void
    {
        $this->assertSame('0.1.0', Birinchishop::VERSION);
        $this->assertTrue(str_contains((string) file_get_contents(__DIR__ . '/../CHANGELOG.md'), '## [0.1.0]'));
    }
}
