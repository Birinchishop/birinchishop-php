<?php

// Test mode (sandbox): submits an order with a TEST key — every validation runs, NOTHING is created
// (no order, no call-center call). Then plays the status to "delivered" so your webhooks receive test events.
// Run: BSK_TEST_KEY=bsk_test_... php examples/sandbox_order.php <link_id>
// It refuses to run with a live key.

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Birinchishop\Birinchishop;
use Birinchishop\Exception\BirinchishopException;

$key = getenv('BSK_TEST_KEY') ?: '';
if (!str_starts_with($key, Birinchishop::TEST_KEY_PREFIX) || !isset($argv[1])) {
    fwrite(STDERR, "Usage: BSK_TEST_KEY=bsk_test_... php examples/sandbox_order.php <link_id>\n(only a test key is accepted)\n");
    exit(1);
}

try {
    $bs = new Birinchishop($key);
    $order = $bs->createOrder([
        'link_id' => (int) $argv[1],
        'phone' => '+998900000000', // placeholder: test orders are never called
        'name' => 'Test customer',
        'click_id' => 'sandbox-click-1',
    ], 'sandbox-' . date('YmdHis'))['data'];
    printf("Test order #%d (test=%s), live orders allowed: %s\n", $order['id'], var_export($order['test'] ?? false, true), var_export($order['live_orders_allowed'] ?? null, true));

    foreach (['confirmed', 'shipping', 'delivered'] as $status) {
        $r = $bs->setTestOrderStatus($order['id'], $status)['data'];
        printf("  %s -> %s, webhooks sent: %d\n", $r['previous_status'], $r['order']['status'], $r['webhooks']['sent'] ?? 0);
    }
} catch (BirinchishopException $e) {
    // e.g. duplicate_order when the same phone + offer was already used in this test session
    fwrite(STDERR, "Error {$e->status} {$e->errorCode}: {$e->getMessage()} (request_id {$e->requestId})\n");
    exit(1);
}
