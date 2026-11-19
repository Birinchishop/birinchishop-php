<?php

// Read-only: poll the order event feed (run it from cron every few minutes).
// The cursor is stored in a local file so every event is processed once.
// Run: BSK_KEY=bsk_live_... php examples/poll_events.php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Birinchishop\Birinchishop;
use Birinchishop\Exception\BirinchishopException;

$cursorFile = getenv('BSK_CURSOR_FILE') ?: sys_get_temp_dir() . '/birinchishop-events.cursor';
$cursor = is_file($cursorFile) ? (int) file_get_contents($cursorFile) : 0;

try {
    $bs = new Birinchishop();
    $n = 0;
    foreach ($bs->iterEvents(['after' => $cursor, 'include_order' => true]) as $ev) {
        // Your CRM / tracker update goes here. conversion_status "sale" = delivered = payout earned.
        printf("%s order #%d: %s -> %s (%s)\n", $ev['type'], $ev['order_id'], $ev['previous_status'] ?? '-', $ev['status'], $ev['conversion_status']);
        $cursor = (int) $ev['id'];
        file_put_contents($cursorFile, (string) $cursor); // save after each event
        $n++;
    }
    echo "{$n} new event(s), cursor {$cursor}\n";
} catch (BirinchishopException $e) {
    fwrite(STDERR, "Error {$e->status} {$e->errorCode}: {$e->getMessage()} (request_id {$e->requestId})\n");
    exit(1);
}
