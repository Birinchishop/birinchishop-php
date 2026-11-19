<?php

// Read-only: account summary and the best-selling offers in stock.
// Run: BSK_KEY=bsk_live_... php examples/list_offers.php [search]

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Birinchishop\Birinchishop;
use Birinchishop\Exception\BirinchishopException;

try {
    $bs = new Birinchishop(); // reads BSK_KEY
    $me = $bs->getAccount()['data'];
    printf("%s, balance %s %s%s\n", $me['name'] ?? '-', $me['balance'] ?? 0, $me['currency'] ?? 'UZS', $bs->isTestMode() ? ' (test key)' : '');

    $params = ['sort' => 'best_selling', 'in_stock_only' => true, 'limit' => 10, 'lang' => 'en'];
    if (isset($argv[1])) {
        $params['search'] = $argv[1];
    }
    foreach ($bs->listOffers($params)['data'] as $o) {
        printf("#%-6d %-50s payout %8s UZS  sale rate %s\n", $o['id'], mb_strimwidth((string) $o['name'], 0, 50, '…'), $o['payout'], $o['sale_rate_percent'] === null ? 'n/a' : $o['sale_rate_percent'] . '%');
    }
} catch (BirinchishopException $e) {
    fwrite(STDERR, "Error {$e->status} {$e->errorCode}: {$e->getMessage()} (request_id {$e->requestId})\n");
    exit(1);
}
