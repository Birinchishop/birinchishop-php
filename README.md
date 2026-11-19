# birinchishop/birinchishop — PHP client for the Birinchi Shop Partner API

Official PHP (8.1+) client for the **[Birinchi Shop](https://birinchishop.uz) Partner API v1** —
a COD (cash on delivery) CPA affiliate network in Uzbekistan. Partners promote ready products; the network runs the
call center, warehouse and nationwide delivery and pays a fixed amount for every **delivered** order.

- No dependencies besides `ext-curl` and `ext-json`; pluggable HTTP transport
- Every REST endpoint of `/api/v1` (offers, links, orders, stats, events, AI insights, Meta ads, webhooks, test mode)
- Automatic retries for `429 rate_limited`, `5xx` and network errors (GET requests and `createOrder` with an `Idempotency-Key`)
- Pagination generators, typed exceptions, webhook signature verification, tracking-link builder

API docs: https://birinchishop.uz/api/v1/docs · OpenAPI: https://birinchishop.uz/api/v1/openapi.json ·
Guide for AI assistants: https://birinchishop.uz/api/v1/guide.md · More examples: https://github.com/Birinchishop/birinchishop-api-examples

## Install

```bash
composer require birinchishop/birinchishop
```

## Quick start

Create a key in the partner panel (**Dashboard → API**) with only the scopes you need. The key is a **server-side secret**:
keep it in an environment variable, never in a repository or client-side code.

```php
<?php
require 'vendor/autoload.php';

use Birinchishop\Birinchishop;

$bs = new Birinchishop(); // reads BSK_KEY from the environment, or new Birinchishop('bsk_live_...')

$me = $bs->getAccount()['data'];
echo $me['name'], ' ', $me['balance'], ' ', $me['currency'], PHP_EOL;

// Best-selling offers in stock with a good sale rate
foreach ($bs->listOffers(['sort' => 'best_selling', 'min_sale_rate' => 25, 'limit' => 10, 'lang' => 'en'])['data'] as $o) {
    echo $o['id'], ' ', $o['name'], ' ', $o['payout'], ' ', $o['sale_rate_percent'], PHP_EOL;
}

// A tracking link (check my_links first — do not create duplicates)
$offer = $bs->getOffer(432)['data'];
$link = $offer['my_links'][0] ?? $bs->createLink(432, 'Instagram reels')['data'];
echo $link['url'], ' ', $link['tracking_url_template'], PHP_EOL;
```

Every method returns the API envelope unchanged as an associative array: `['data' => ..., 'meta' => ... (lists), 'request_id' => ...]`.
Method names are the OpenAPI `operationId`s; filters and bodies keep the API's field names (`snake_case`).
New fields the API adds later simply appear in the arrays — nothing breaks.

## Offers and links

```php
foreach ($bs->iterOffers(['in_stock_only' => true, 'sort' => 'highest_payout']) as $offer) { /* every page */ }

$bs->getOffer(432, 'ru');          // variants, my_links; lang: uz | ru | en
$bs->listLinks(['offer_id' => 432]);
$bs->getLink(2001);
$bs->createLink(432, 'Telegram channel');

use Birinchishop\TrackingUrl;
TrackingUrl::fill($link['tracking_url_template'], ['click_id' => '{clickid}', 'sub1' => '{campaign_id}']);
// https://birinchishop.uz/l/<link>?click_id={clickid}&sub1={campaign_id}   (unused sub2..sub5 removed)
```

## Orders

```php
// All delivered orders (payout earned), every page
foreach ($bs->iterOrders(['conversion_status' => 'sale', 'date_from' => '2026-11-01']) as $order) {
    echo $order['id'], ' ', $order['payout'], ' ', $order['tracking']['click_id'] ?? '-', PHP_EOL;
}

$bs->getOrder(123456);
$bs->getOrderSaleChance(123456);   // 0-100 %, informational (scope insights:read)
```

`date_from` / `date_to` accept `'YYYY-MM-DD'` or a `DateTimeInterface` (Asia/Tashkent dates);
`updated_since` accepts an ISO 8601 string or a `DateTimeInterface`.

### Submitting an order — read this first

> **`createOrder` with a live key creates a REAL order.** A call center phones the customer and a courier delivers the product.
> Use it only for a real customer who asked to order, with their correct phone number.
> **Never send test, sample or fake orders — the account gets blocked.** Use a [test key](#test-mode-sandbox) to try your integration.
> It needs the `orders:write` scope and an account with at least 3 delivered orders (or admin approval).

```php
$res = $bs->createOrder([
    'link_id' => 2001,
    'phone' => $customerPhone,      // +998XXXXXXXXX
    'name' => $customerName,
    'region' => $customerRegion,
    'external_id' => $leadId,
    'sub1' => 'reels',
], idempotencyKey: "lead-{$leadId}"); // same key on retry => same order, never a duplicate

echo $res['data']['id'], ' ', $res['data']['status'], !empty($res['replayed']) ? ' (already existed)' : '', PHP_EOL;
```

Without `idempotencyKey` the SDK generates one per call, so its own retries are safe.
The key may contain `A-Z a-z 0-9 . _ : -` (max 128 characters).

## Stats, events and the AI manager

```php
$bs->getStats(['group_by' => 'offer', 'date_from' => '2026-11-01', 'date_to' => '2026-11-18']); // day | offer | link
$bs->getInsights();                // yesterday's results, offers, campaigns and recommendations
$bs->listEvents(['after' => 0, 'type' => 'order.status_changed', 'include_order' => true]);
$bs->getReference();               // enums, scopes, statuses and business rules (no scope needed)
```

## Meta ads

```php
$bs->getAdsStatus();
$bs->listCampaigns(['period' => '7d']);              // 7d | 30d | 90d
$bs->pauseCampaign(55);                              // immediate
$action = $bs->resumeCampaign(55)['data'];           // the partner must press Confirm (Telegram bot / panel)
$bs->changeCampaignBudget(55, 150000);               // UZS per day, also needs confirmation
$bs->getAction($action['id']);                       // pending_confirmation -> done | failed | cancelled | expired
```

## Errors

```php
use Birinchishop\Exception\BirinchishopException;
use Birinchishop\Exception\ConflictException;
use Birinchishop\Exception\NetworkException;
use Birinchishop\Exception\RateLimitException;

try {
    $bs->createOrder(['link_id' => 2001, 'phone' => $customerPhone]);
} catch (ConflictException $e) {           // 409, e.g. duplicate_order: same phone + offer within 24 h — do not retry
    // ...
} catch (RateLimitException $e) {          // 429 after the SDK's own retries; see $e->retryAfter
    // ...
} catch (NetworkException $e) {            // no response: network_error | timeout
    // ...
} catch (BirinchishopException $e) {       // everything else
    if ($e->errorCode === 'orders_write_not_allowed') { /* needs 3 delivered orders or admin approval */ }
    error_log("{$e->status} {$e->errorCode} {$e->getMessage()} {$e->requestId}"); // send request_id to support
}
```

Branch on `$e->errorCode` (stable), never on the message. Properties (also as getters): `status` (HTTP, `0` for
network errors), `errorCode`, `details`, `requestId`, `retryAfter`, `body`.

| Exception | When |
|---|---|
| `InvalidRequestException` | 400 / 413 / 415 / 422, or a client-side check (`invalid_request`) |
| `AuthenticationException` | 401 (`unauthorized`, `key_revoked`, `key_expired`), or no key (`missing_api_key`) |
| `PermissionException` | 403 (`insufficient_scope`, `orders_write_not_allowed`, `ip_not_allowed`, `not_available_in_test_mode`, `test_mode_only`, ...) |
| `NotFoundException` | 404 (`offer_not_found`, `order_not_found`, ...) |
| `ConflictException` | 409 (`duplicate_order`, `link_limit_reached`, ...) |
| `RateLimitException` | 429 (`rate_limited` is retried automatically; `daily_order_limit` is not) |
| `ServerException` | 5xx (`internal_error`, `offer_temporarily_unavailable`, `http_502`, ...) |
| `NetworkException` | no HTTP response (`network_error`, `timeout`) |

All of them extend `BirinchishopException`. Other client-side codes: `invalid_response`, `http_<status>`.

Retries: GET requests and `createOrder` are retried up to `maxRetries` times (default 2) on `429 rate_limited`,
`5xx` (except `offer_temporarily_unavailable`) and network errors — waiting `Retry-After` seconds (max 60) or
0.5 s, 1 s, 2 s … (max 8 s) plus jitter. Other POST/PATCH/DELETE requests are never retried automatically.

## Events and webhooks

Poll the event feed with a cursor:

```php
$cursor = loadCursor(); // 0 the first time
foreach ($bs->iterEvents(['after' => $cursor, 'include_order' => true]) as $ev) {
    echo $ev['type'], ' ', $ev['order_id'], ' ', $ev['previous_status'], ' -> ', $ev['status'], ' ', $ev['conversion_status'], PHP_EOL;
    $cursor = $ev['id'];
}
saveCursor($cursor);
```

Or receive signed webhooks (`kind` `json`). Verify `X-Birinchishop-Signature` on the **raw** body:

```php
use Birinchishop\Webhook;
use Birinchishop\Exception\SignatureVerificationException;

$raw = file_get_contents('php://input');            // Laravel: $request->getContent()
try {
    $event = Webhook::constructEvent($raw, Webhook::signatureFromGlobals(), getenv('BSK_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(401);
    exit;
}
// Events may arrive twice or out of order: deduplicate by $event['id']. Answer within 10 s.
if (!empty($event['test'])) { /* test event from a bsk_test_ key */ }
error_log($event['type'] . ' ' . $event['data']['order_id'] . ' ' . $event['data']['conversion_status']);
http_response_code(200);
```

`Webhook::verifySignature($raw, $header, $secret, tolerance: 300)` returns a bool if you prefer.
Register endpoints with `createWebhook($url, ['kind' => 'json' | 'postback', 'events' => [...]])` (scope `webhooks:manage`);
the signing `secret` is returned **once**. Also: `listWebhooks()`, `updateWebhook($id, ['is_active' => false])`,
`deleteWebhook($id)`, `testWebhook($id)`, `listWebhookDeliveries($id, ['limit' => 20])`.
For S2S postbacks to Keitaro/Binom see
[integrations](https://github.com/Birinchishop/birinchishop-api-examples/tree/main/integrations); macros are in `Birinchishop::POSTBACK_MACROS`.

## Test mode (sandbox)

Create a **test key** in the panel (Dashboard → API → "Test key"). It looks like `bsk_test_<id>_<secret>`:

- reads (`/offers`, `/links`, `/orders`, `/stats`, ...) return your real data;
- `createOrder` and `createLink` run **every** live validation (phone, link, offer, stock, duplicates, rate limits,
  `Idempotency-Key`) but create **nothing** — no order, no call-center call, no payout. The response has
  `'test' => true`, a fake id ≥ 2 000 000 000 and, for orders, `live_orders_allowed`;
- your active webhooks/postbacks receive signed test events (`'test' => true`, header `X-Birinchishop-Test: true`);
- `ads:write` is not available to test keys.

```php
$bs = new Birinchishop(getenv('BSK_TEST_KEY'));
$bs->isTestMode();                                                    // true
$order = $bs->createOrder(['link_id' => 2001, 'phone' => '+998900000000'])['data']; // nothing is created
$bs->getOrder($order['id']);                                          // kept in memory for 24 h
$bs->setTestOrderStatus($order['id'], 'delivered');                   // fires order.status_changed to your webhooks
```

Test objects are not listed in `/orders`, `/stats` or `/events`. `setTestOrderStatus()` throws
`PermissionException` (`test_mode_only`) with a live key, without sending a request.

## All methods

| Method | Endpoint | Scope |
|---|---|---|
| `getAccount()` | `GET /me` | `account:read` |
| `listOffers($params)` / `iterOffers($params)` | `GET /offers` | `offers:read` |
| `getOffer($offerId, $lang)` | `GET /offers/{offer_id}` | `offers:read` |
| `listLinks($params)` / `iterLinks($params)` | `GET /links` | `links:read` |
| `getLink($linkId)` | `GET /links/{link_id}` | `links:read` |
| `createLink($offerId, $name)` | `POST /links` | `links:write` |
| `listOrders($params)` / `iterOrders($params)` | `GET /orders` | `orders:read` |
| `getOrder($orderId)` | `GET /orders/{order_id}` | `orders:read` |
| `createOrder($order, $idempotencyKey)` | `POST /orders` — **real order** with a live key | `orders:write` |
| `setTestOrderStatus($orderId, $status)` | `POST /sandbox/orders/{order_id}/status` — test key only | `orders:write` |
| `getOrderSaleChance($orderId)` | `GET /orders/{order_id}/sale-chance` | `insights:read` |
| `getStats($params)` | `GET /stats` | `stats:read` |
| `listEvents($params)` / `iterEvents($params)` | `GET /events` | `events:read` |
| `getInsights()` | `GET /insights` | `insights:read` |
| `getAdsStatus()` | `GET /ads/status` | `ads:read` |
| `listCampaigns($params)` | `GET /ads/campaigns` | `ads:read` |
| `pauseCampaign($id)` | `POST /ads/campaigns/{id}/pause` (immediate) | `ads:write` |
| `resumeCampaign($id)` | `POST /ads/campaigns/{id}/resume` (partner confirms) | `ads:write` |
| `changeCampaignBudget($id, $dailyBudget, $unit)` | `POST /ads/campaigns/{id}/budget` (partner confirms) | `ads:write` |
| `getAction($actionId)` | `GET /actions/{action_id}` | `ads:read` |
| `listWebhooks()` | `GET /webhooks` | `webhooks:manage` |
| `createWebhook($url, $options)` | `POST /webhooks` | `webhooks:manage` |
| `updateWebhook($id, $changes)` | `PATCH /webhooks/{id}` | `webhooks:manage` |
| `deleteWebhook($id)` | `DELETE /webhooks/{id}` | `webhooks:manage` |
| `testWebhook($id)` | `POST /webhooks/{id}/test` | `webhooks:manage` |
| `listWebhookDeliveries($id, $params)` | `GET /webhooks/{id}/deliveries` | `webhooks:manage` |
| `getReference()` | `GET /reference` | — |
| `request($method, $path, $query, $body, $headers, $retry)` | any endpoint (low level) | — |

Options (named arguments):
`new Birinchishop(apiKey: null, baseUrl: 'https://birinchishop.uz/api/v1', timeout: 30.0, maxRetries: 2, transport: null, userAgent: null, sleeper: null)`.

`transport` takes any `Birinchishop\Http\HttpTransport` — wrap your own HTTP stack (proxy, PSR-18 client, tests):

```php
use Birinchishop\Http\CurlTransport;

$bs = new Birinchishop(transport: new CurlTransport([CURLOPT_PROXY => 'http://proxy.local:3128']));
```

Constants: `Birinchishop::VERSION`, `API_VERSION`, `ORDER_STATUSES`, `CONVERSION_STATUSES`, `PAYOUT_STATUSES`,
`EVENT_TYPES`, `OFFER_SORTS`, `SCOPES`, `POSTBACK_MACROS`.

## Rules that matter

- Money is integer UZS. Date filters use Asia/Tashkent (UTC+5); timestamps are ISO 8601 UTC.
- Payout is earned only when an order is **delivered** (`conversion_status === 'sale'`).
- One phone number cannot order the same offer twice within 24 hours, and at most 3 different offers per 24 hours.
- Customer phone numbers are always masked.
- Rate limits: 120 requests/minute per key, 30 orders/minute per account.

## Development

```bash
php tests/run.php                    # dependency-free test runner (no network)
composer install && vendor/bin/phpunit   # the same tests under PHPUnit
```

Examples: [`examples/`](examples) (`BSK_KEY=... php examples/list_offers.php`).

---

**O'zbekcha.** Birinchi Shop hamkorlik API'si uchun rasmiy PHP kutubxonasi (PHP 8.1+, faqat ext-curl va ext-json).
O'rnatish: `composer require birinchishop/birinchishop`. Kalitni panelda yarating (Dashboard → API) va faqat serverdagi `BSK_KEY` muhit o'zgaruvchisida saqlang.
`createOrder` — **haqiqiy zakaz**: call-center mijozga qo'ng'iroq qiladi. Sinov yoki soxta zakaz yubormang — akkaunt bloklanadi.
Integratsiyani sinash uchun `bsk_test_` sinov kalitidan foydalaning: tekshiruvlar ishlaydi, lekin hech narsa yaratilmaydi.

**Русский.** Официальный клиент PHP для партнёрского API Birinchi Shop (PHP 8.1+, только ext-curl и ext-json).
Установка: `composer require birinchishop/birinchishop`. Ключ создаётся в панели (Dashboard → API) и хранится только на сервере (`BSK_KEY`).
`createOrder` создаёт **реальный заказ**: колл-центр звонит клиенту. Не отправляйте тестовые или фейковые заказы — аккаунт блокируется.
Для проверки интеграции используйте тестовый ключ `bsk_test_`: все проверки выполняются, но ничего не создаётся.

Registration is free: https://birinchishop.uz/register?utm_source=packagist&utm_campaign=sdk-php ·
Support: https://t.me/birinchishop_support

## License

MIT. Using the API itself is subject to the partner agreement on birinchishop.uz.
