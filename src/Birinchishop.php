<?php

declare(strict_types=1);

namespace Birinchishop;

use Birinchishop\Exception\AuthenticationException;
use Birinchishop\Exception\BirinchishopException;
use Birinchishop\Exception\InvalidRequestException;
use Birinchishop\Exception\NetworkException;
use Birinchishop\Exception\PermissionException;
use Birinchishop\Http\CurlTransport;
use Birinchishop\Http\HttpResponse;
use Birinchishop\Http\HttpTransport;
use Birinchishop\Http\TransportException;

/**
 * Official PHP client for the Birinchi Shop Partner API v1.
 *
 * Docs: https://birinchishop.uz/api/v1/docs  OpenAPI: https://birinchishop.uz/api/v1/openapi.json
 *
 * Every method returns the API envelope unchanged, as an associative array:
 * `["data" => ..., "meta" => ... (lists only), "request_id" => "req_..."]`.
 * Unknown fields are kept as they are, so new API fields never break your code.
 * Errors throw BirinchishopException (or one of its subclasses); branch on $e->errorCode.
 */
final class Birinchishop
{
    public const VERSION = '0.1.0';
    public const API_VERSION = '2026-10-23';
    public const DEFAULT_BASE_URL = 'https://birinchishop.uz/api/v1';

    public const ORDER_STATUSES = ['new', 'processing', 'callback', 'confirmed', 'packed', 'shipping', 'delivered', 'returned', 'cancelled'];
    public const CONVERSION_STATUSES = ['lead', 'approved', 'sale', 'rejected'];
    public const PAYOUT_STATUSES = ['pending', 'earned', 'under_review', 'none'];
    public const EVENT_TYPES = ['order.created', 'order.status_changed'];
    public const OFFER_SORTS = ['recommended', 'newest', 'best_selling', 'highest_payout', 'highest_stock', 'highest_lead_bonus'];
    public const SCOPES = [
        'account:read', 'offers:read', 'links:read', 'links:write', 'orders:read', 'orders:write',
        'stats:read', 'events:read', 'webhooks:manage', 'insights:read', 'ads:read', 'ads:write',
    ];
    public const POSTBACK_MACROS = [
        'click_id', 'sub1', 'sub2', 'sub3', 'sub4', 'sub5', 'order_id', 'external_id', 'status',
        'conversion_status', 'payout', 'payout_earned', 'customer_price', 'currency', 'offer_id', 'link_id', 'event', 'event_id', 'timestamp', 'test',
    ];
    /** Key prefixes: live keys create real orders, test keys (sandbox) never create anything. */
    public const LIVE_KEY_PREFIX = 'bsk_live_';
    public const TEST_KEY_PREFIX = 'bsk_test_';
    /** Test (sandbox) objects get ids from this value up. */
    public const TEST_ID_BASE = 2000000000;

    private const MAX_RETRY_AFTER = 60.0;
    private const PAGINATED = ['listOffers', 'listLinks', 'listOrders'];

    public readonly string $apiKey;
    public readonly string $baseUrl;
    public readonly string $userAgent;
    private HttpTransport $transport;
    /** @var callable(float): void */
    private $sleeper;

    /**
     * @param string|null                  $apiKey     `bsk_live_...` or `bsk_test_...` (default: the BSK_KEY environment variable)
     * @param float                        $timeout    per-request timeout in seconds
     * @param int                          $maxRetries retries for 429 rate_limited, 5xx and network errors
     * @param HttpTransport|null           $transport  default: CurlTransport
     * @param string|null                  $userAgent  appended to the User-Agent header
     * @param (callable(float): void)|null $sleeper    waits N seconds between retries (default: usleep); inject in tests
     */
    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        public readonly float $timeout = 30.0,
        public readonly int $maxRetries = 2,
        ?HttpTransport $transport = null,
        ?string $userAgent = null,
        ?callable $sleeper = null,
    ) {
        $key = $apiKey ?? self::env('BSK_KEY');
        $key = $key === null ? '' : trim($key);
        if ($key === '') {
            throw new AuthenticationException('API key is required: pass $apiKey or set BSK_KEY.', 0, 'missing_api_key');
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->userAgent = 'birinchishop-php/' . self::VERSION . ($userAgent !== null && $userAgent !== '' ? ' ' . $userAgent : '');
        $this->transport = $transport ?? new CurlTransport();
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1e6));
            }
        };
    }

    /**
     * True for a test (sandbox) key `bsk_test_...`: reads return real data, but POST /orders and POST /links
     * run every live validation and create NOTHING (response has "test" => true and a fake id >= 2000000000).
     */
    public function isTestMode(): bool
    {
        return str_starts_with($this->apiKey, self::TEST_KEY_PREFIX);
    }

    // ── low level ──────────────────────────────────────────────────────

    /**
     * Low-level request. Returns the decoded JSON envelope.
     *
     * @param array<string, mixed>|null  $query
     * @param array<string, mixed>|null  $body    sent as JSON
     * @param array<string, string>|null $headers
     * @param bool|null                  $retry   default: only GET requests are retried
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, ?array $query = null, ?array $body = null, ?array $headers = null, ?bool $retry = null): array
    {
        $method = strtoupper($method);
        $url = $this->baseUrl . $path . self::toQuery($query ?? []);
        $h = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        foreach ($headers ?? [] as $k => $v) {
            $h[$k] = $v;
        }
        $payload = null;
        if ($body !== null) {
            $h['Content-Type'] = 'application/json';
            // An empty PHP array must be sent as a JSON object.
            $payload = $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $canRetry = $retry ?? ($method === 'GET');

        for ($attempt = 0; ; $attempt++) {
            try {
                $res = $this->transport->send($method, $url, $h, $payload, $this->timeout);
            } catch (TransportException $e) {
                if ($canRetry && $attempt < $this->maxRetries) {
                    ($this->sleeper)($this->backoff($attempt));
                    continue;
                }
                throw new NetworkException(
                    $e->timedOut ? sprintf('Request timed out after %s s', $this->timeout) : 'Network error: ' . $e->getMessage(),
                    0,
                    $e->timedOut ? 'timeout' : 'network_error',
                    previous: $e,
                );
            }

            $json = self::decode($res->body);
            if ($res->status >= 200 && $res->status < 300 && is_array($json)) {
                if (strtolower((string) $res->header('Idempotent-Replayed')) === 'true') {
                    $json['replayed'] = true;
                }
                return $json;
            }

            $err = is_array($json) && isset($json['error']) && is_array($json['error']) ? $json['error'] : [];
            $retryAfter = self::parseRetryAfter($res);
            $code = isset($err['code']) && is_string($err['code']) && $err['code'] !== ''
                ? $err['code']
                : ($res->status >= 200 && $res->status < 300 ? 'invalid_response' : 'http_' . $res->status);
            $requestId = is_array($json) && isset($json['request_id']) && is_string($json['request_id'])
                ? $json['request_id']
                : $res->header('X-Request-Id');
            $exception = BirinchishopException::fromResponse(
                $res->status,
                $code,
                isset($err['message']) && is_string($err['message']) && $err['message'] !== '' ? $err['message'] : 'HTTP ' . $res->status,
                isset($err['details']) && is_array($err['details']) ? $err['details'] : null,
                $requestId,
                $retryAfter,
                $json ?? $res->body,
            );
            $transient = ($res->status === 429 && $code === 'rate_limited')
                || ($res->status >= 500 && $code !== 'offer_temporarily_unavailable');
            if ($canRetry && $transient && $attempt < $this->maxRetries) {
                ($this->sleeper)($retryAfter !== null ? min($retryAfter, self::MAX_RETRY_AFTER) : $this->backoff($attempt));
                continue;
            }
            throw $exception;
        }
    }

    /** Exponential backoff with jitter, in seconds: 0.5, 1, 2, 4, 8 (max) + up to 0.25. */
    public function backoff(int $attempt): float
    {
        return min(8.0, 0.5 * 2 ** $attempt) + mt_rand(0, 250) / 1000;
    }

    // ── account ────────────────────────────────────────────────────────

    /** GET /me — scope account:read. `data.api_key.mode` is "live" or "test". */
    public function getAccount(): array
    {
        return $this->request('GET', '/me');
    }

    // ── offers ─────────────────────────────────────────────────────────

    /**
     * GET /offers — scope offers:read. Paginated (page, limit <= 100).
     *
     * @param array{search?: string, sort?: string, in_stock_only?: bool, min_sale_rate?: int, min_payout?: int,
     *              ids?: string|int[], lang?: string, page?: int, limit?: int} $params
     *        sort: recommended | newest | best_selling | highest_payout | highest_stock | highest_lead_bonus; lang: uz | ru | en
     */
    public function listOffers(array $params = []): array
    {
        return $this->request('GET', '/offers', $params);
    }

    /** GET /offers/{offer_id} — scope offers:read. Includes variants and `my_links`. */
    public function getOffer(int|string $offerId, ?string $lang = null): array
    {
        return $this->request('GET', '/offers/' . self::pathId($offerId, 'offer_id'), ['lang' => $lang]);
    }

    // ── links ──────────────────────────────────────────────────────────

    /**
     * GET /links — scope links:read. Paginated.
     *
     * @param array{offer_id?: int, page?: int, limit?: int} $params
     */
    public function listLinks(array $params = []): array
    {
        return $this->request('GET', '/links', $params);
    }

    /** GET /links/{link_id} — scope links:read. */
    public function getLink(int|string $linkId): array
    {
        return $this->request('GET', '/links/' . self::pathId($linkId, 'link_id'));
    }

    /**
     * POST /links — scope links:write. Check getOffer($id)['data']['my_links'] first to avoid duplicates.
     * With a test key the link is validated but NOT created (`test` => true).
     */
    public function createLink(int|string $offerId, ?string $name = null): array
    {
        return $this->request('POST', '/links', null, self::dropNull([
            'offer_id' => self::pathId($offerId, 'offer_id'),
            'name' => $name,
        ]));
    }

    // ── orders ─────────────────────────────────────────────────────────

    /**
     * GET /orders — scope orders:read. Dates are Asia/Tashkent (YYYY-MM-DD). Paginated.
     *
     * @param array{status?: string, conversion_status?: string, date_from?: string|\DateTimeInterface,
     *              date_to?: string|\DateTimeInterface, updated_since?: string|\DateTimeInterface, link_id?: int,
     *              offer_id?: int, click_id?: string, external_id?: string, page?: int, limit?: int} $params
     */
    public function listOrders(array $params = []): array
    {
        return $this->request('GET', '/orders', $params);
    }

    /** GET /orders/{order_id} — scope orders:read. Test orders (id >= 2000000000) are returned for 24 hours. */
    public function getOrder(int|string $orderId): array
    {
        return $this->request('GET', '/orders/' . self::pathId($orderId, 'order_id'));
    }

    /**
     * POST /orders — scope orders:write.
     *
     * WARNING: with a live key this creates a REAL order. A call center phones the customer and a courier delivers.
     * Use it only for a real customer who asked to order, with their correct phone number.
     * Never send test, sample or fake orders: the account gets blocked. To test an integration use a
     * test key (`bsk_test_...`): every validation runs but nothing is created.
     * A live key needs at least 3 delivered orders on the account (or admin approval).
     *
     * An Idempotency-Key is always sent (a random UUID v4 if you do not pass one) and kept across the SDK's
     * retries, so retries never create duplicates. Pass your own stable key (e.g. your lead id) to stay safe
     * across process restarts too. A replay returns HTTP 200 and the result has `replayed` => true.
     *
     * @param array{link_id: int, phone: string, name?: string, region?: string, variant_id?: int, variant_type_id?: int,
     *              external_id?: string, click_id?: string, sub1?: string, sub2?: string, sub3?: string, sub4?: string,
     *              sub5?: string} $order API field names
     * @param string|null $idempotencyKey 1-128 characters: A-Z a-z 0-9 . _ : -
     */
    public function createOrder(array $order, ?string $idempotencyKey = null): array
    {
        if (!isset($order['link_id']) || $order['link_id'] === '') {
            throw new InvalidRequestException('link_id is required', 0, 'invalid_request', [['field' => 'link_id', 'message' => 'is required']]);
        }
        if (!isset($order['phone']) || $order['phone'] === '') {
            throw new InvalidRequestException('phone is required', 0, 'invalid_request', [['field' => 'phone', 'message' => 'is required']]);
        }
        $key = $idempotencyKey ?? self::uuid4();
        if (!preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $key)) {
            throw new InvalidRequestException(
                'Idempotency-Key must be 1-128 characters: A-Z a-z 0-9 . _ : -',
                0,
                'invalid_request',
                [['field' => 'Idempotency-Key', 'message' => 'invalid format']],
            );
        }

        return $this->request('POST', '/orders', null, self::dropNull($order), ['Idempotency-Key' => $key], true);
    }

    /** GET /orders/{order_id}/sale-chance — scope insights:read. Informational only. */
    public function getOrderSaleChance(int|string $orderId): array
    {
        return $this->request('GET', '/orders/' . self::pathId($orderId, 'order_id') . '/sale-chance');
    }

    // ── sandbox (test keys only) ───────────────────────────────────────

    /**
     * POST /sandbox/orders/{order_id}/status — scope orders:write, TEST KEY ONLY.
     * Changes an in-memory test order and sends a signed test `order.status_changed` event to your active
     * webhooks/postbacks; returns `{order, previous_status, webhooks}`. Nothing is stored.
     *
     * @param string $status processing | callback | confirmed | packed | shipping | delivered | returned | cancelled
     */
    public function setTestOrderStatus(int|string $orderId, string $status): array
    {
        if (!$this->isTestMode()) {
            throw new PermissionException('setTestOrderStatus works only with a test key (bsk_test_...).', 0, 'test_mode_only');
        }

        return $this->request('POST', '/sandbox/orders/' . self::pathId($orderId, 'order_id') . '/status', null, ['status' => $status]);
    }

    // ── stats & events ─────────────────────────────────────────────────

    /**
     * GET /stats — scope stats:read. group_by: day | offer | link. Dates are Asia/Tashkent.
     *
     * @param array{group_by?: string, date_from?: string|\DateTimeInterface, date_to?: string|\DateTimeInterface,
     *              link_id?: int, offer_id?: int} $params
     */
    public function getStats(array $params = []): array
    {
        return $this->request('GET', '/stats', $params);
    }

    /**
     * GET /events — scope events:read. Cursor-based: pass $r['meta']['next_cursor'] as `after`.
     *
     * @param array{after?: int, type?: string, include_order?: bool, limit?: int} $params
     */
    public function listEvents(array $params = []): array
    {
        return $this->request('GET', '/events', $params);
    }

    // ── AI manager ─────────────────────────────────────────────────────

    /** GET /insights — scope insights:read. */
    public function getInsights(): array
    {
        return $this->request('GET', '/insights');
    }

    // ── Meta ads ───────────────────────────────────────────────────────

    /** GET /ads/status — scope ads:read. */
    public function getAdsStatus(): array
    {
        return $this->request('GET', '/ads/status');
    }

    /**
     * GET /ads/campaigns — scope ads:read.
     *
     * @param array{period?: string, include_archived?: bool} $params period: 7d | 30d | 90d
     */
    public function listCampaigns(array $params = []): array
    {
        return $this->request('GET', '/ads/campaigns', $params);
    }

    /** POST /ads/campaigns/{campaign_id}/pause — scope ads:write. Immediate. */
    public function pauseCampaign(int|string $campaignId): array
    {
        return $this->request('POST', '/ads/campaigns/' . self::pathId($campaignId, 'campaign_id') . '/pause');
    }

    /**
     * POST /ads/campaigns/{campaign_id}/resume — scope ads:write. Returns an action in status
     * `pending_confirmation`; the partner confirms it in the Telegram bot or panel (check with getAction()).
     */
    public function resumeCampaign(int|string $campaignId): array
    {
        return $this->request('POST', '/ads/campaigns/' . self::pathId($campaignId, 'campaign_id') . '/resume');
    }

    /**
     * POST /ads/campaigns/{campaign_id}/budget — scope ads:write. Needs the partner's confirmation.
     *
     * @param string|null $unit UZS (default) | ACCOUNT
     */
    public function changeCampaignBudget(int|string $campaignId, int|float $dailyBudget, ?string $unit = null): array
    {
        return $this->request('POST', '/ads/campaigns/' . self::pathId($campaignId, 'campaign_id') . '/budget', null, self::dropNull([
            'daily_budget' => $dailyBudget,
            'unit' => $unit,
        ]));
    }

    /** GET /actions/{action_id} — scope ads:read. */
    public function getAction(int|string $actionId): array
    {
        return $this->request('GET', '/actions/' . self::pathId($actionId, 'action_id'));
    }

    // ── webhooks / postbacks ───────────────────────────────────────────

    /** GET /webhooks — scope webhooks:manage. */
    public function listWebhooks(): array
    {
        return $this->request('GET', '/webhooks');
    }

    /**
     * POST /webhooks — scope webhooks:manage. The signing `secret` is returned ONCE in this response.
     *
     * @param array{kind?: string, events?: string[], description?: string} $options
     *        kind: json (signed POST, default) | postback (GET with {macros})
     */
    public function createWebhook(string $url, array $options = []): array
    {
        return $this->request('POST', '/webhooks', null, self::dropNull(['url' => $url] + $options));
    }

    /**
     * PATCH /webhooks/{webhook_id} — scope webhooks:manage.
     *
     * @param array{url?: string, events?: string[], description?: string, is_active?: bool} $changes
     */
    public function updateWebhook(int|string $webhookId, array $changes): array
    {
        return $this->request('PATCH', '/webhooks/' . self::pathId($webhookId, 'webhook_id'), null, self::dropNull($changes));
    }

    /** DELETE /webhooks/{webhook_id} — scope webhooks:manage. */
    public function deleteWebhook(int|string $webhookId): array
    {
        return $this->request('DELETE', '/webhooks/' . self::pathId($webhookId, 'webhook_id'));
    }

    /** POST /webhooks/{webhook_id}/test — scope webhooks:manage. Sends a sample event now. */
    public function testWebhook(int|string $webhookId): array
    {
        return $this->request('POST', '/webhooks/' . self::pathId($webhookId, 'webhook_id') . '/test');
    }

    /**
     * GET /webhooks/{webhook_id}/deliveries — scope webhooks:manage.
     *
     * @param array{limit?: int} $params
     */
    public function listWebhookDeliveries(int|string $webhookId, array $params = []): array
    {
        return $this->request('GET', '/webhooks/' . self::pathId($webhookId, 'webhook_id') . '/deliveries', $params);
    }

    // ── reference ──────────────────────────────────────────────────────

    /** GET /reference — enums, scopes, statuses and business rules (no scope needed). */
    public function getReference(): array
    {
        return $this->request('GET', '/reference');
    }

    // ── pagination ─────────────────────────────────────────────────────

    /**
     * Iterate over every item of a page-based list: 'listOffers' | 'listLinks' | 'listOrders'.
     *
     * @param array<string, mixed> $params filters of that method; `limit` defaults to 100
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function paginate(string $method, array $params = []): \Generator
    {
        if (!\in_array($method, self::PAGINATED, true)) {
            throw new InvalidRequestException("paginate: unsupported method {$method}", 0, 'invalid_request');
        }
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $params['limit'] = $params['limit'] ?? 100;
        while (true) {
            $r = $this->{$method}(['page' => $page] + $params);
            $items = isset($r['data']) && is_array($r['data']) ? $r['data'] : [];
            foreach ($items as $item) {
                yield $item;
            }
            $totalPages = (int) ($r['meta']['total_pages'] ?? 0);
            if ($items === [] || $page >= $totalPages) {
                return;
            }
            $page++;
        }
    }

    /**
     * Every offer matching the filters of listOffers().
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterOffers(array $params = []): \Generator
    {
        return $this->paginate('listOffers', $params);
    }

    /**
     * Every link of yours.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterLinks(array $params = []): \Generator
    {
        return $this->paginate('listLinks', $params);
    }

    /**
     * Every order matching the filters of listOrders().
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterOrders(array $params = []): \Generator
    {
        return $this->paginate('listOrders', $params);
    }

    /**
     * Events after a cursor (`after`, default 0) until `has_more` is false.
     * Save the last processed $event['id'] and pass it as `after` next time.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterEvents(array $params = []): \Generator
    {
        $after = $params['after'] ?? 0;
        while (true) {
            $r = $this->listEvents(['after' => $after] + $params);
            foreach (isset($r['data']) && is_array($r['data']) ? $r['data'] : [] as $event) {
                yield $event;
            }
            $meta = isset($r['meta']) && is_array($r['meta']) ? $r['meta'] : [];
            $next = $meta['next_cursor'] ?? null;
            if (empty($meta['has_more']) || $next === null || $next === $after) {
                return;
            }
            $after = $next;
        }
    }

    // ── helpers ────────────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    private static function toQuery(array $params): string
    {
        $pairs = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            } elseif ($v instanceof \DateTimeInterface) {
                $v = ($k === 'date_from' || $k === 'date_to') ? $v->format('Y-m-d') : $v->format(\DateTimeInterface::ATOM);
            } elseif (is_array($v)) {
                $v = implode(',', array_map('strval', $v));
            } else {
                $v = (string) $v;
            }
            $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode($v);
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    private static function pathId(int|string $value, string $name): int
    {
        if (is_string($value)) {
            if (!preg_match('/^[0-9]{1,10}$/', $value)) {
                throw new InvalidRequestException("{$name} must be a positive integer", 0, 'invalid_request');
            }
            $value = (int) $value;
        }
        if ($value < 1 || $value > 2147483647) {
            throw new InvalidRequestException("{$name} must be a positive integer", 0, 'invalid_request');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function dropNull(array $values): array
    {
        return array_filter($values, static fn ($v) => $v !== null);
    }

    private static function decode(string $body): mixed
    {
        if ($body === '') {
            return [];
        }
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function parseRetryAfter(HttpResponse $res): ?float
    {
        $raw = $res->header('Retry-After') ?? $res->header('RateLimit-Reset');
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if (is_numeric($raw)) {
            return max(0.0, (float) $raw);
        }
        $ts = strtotime($raw); // HTTP-date
        if ($ts === false) {
            return null;
        }

        return (float) max(0, $ts - time());
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private static function env(string $name): ?string
    {
        $v = getenv($name);
        if (is_string($v) && $v !== '') {
            return $v;
        }
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }
}
