<?php

// Webhook endpoint (kind "json"): verifies X-Birinchishop-Signature on the raw body.
// Local try-out: BSK_WEBHOOK_SECRET=whsec_... php -S 127.0.0.1:8080 examples/webhook_receiver.php
// In production put it behind HTTPS and register the URL with createWebhook().

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Birinchishop\Exception\SignatureVerificationException;
use Birinchishop\Webhook;

$secret = getenv('BSK_WEBHOOK_SECRET') ?: '';
if ($secret === '' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code($secret === '' ? 500 : 405);
    exit;
}

$raw = (string) file_get_contents('php://input');
try {
    $event = Webhook::constructEvent($raw, Webhook::signatureFromGlobals(), $secret);
} catch (SignatureVerificationException | \JsonException | \UnexpectedValueException $e) {
    http_response_code(401);
    exit;
}

// Events may arrive twice or out of order: deduplicate by $event['id'] in your database.
// Answer within 10 s; do slow work in a queue.
$data = $event['data'];
error_log(sprintf(
    '[birinchishop]%s %s %s order #%s %s/%s',
    !empty($event['test']) ? ' TEST' : '',
    $event['id'],
    $event['type'],
    $data['order_id'] ?? '-',
    $data['status'] ?? '-',
    $data['conversion_status'] ?? '-',
));

http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';
