<?php

declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$secret = @file_get_contents('/srv/kioosk/shared/github-secret');
$payload = file_get_contents('php://input', false, null, 0, 1048577);
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (! $secret || strlen($payload) > 1048576 || ! hash_equals('sha256='.hash_hmac('sha256', $payload, trim($secret)), $signature)) {
    http_response_code(403);
    exit;
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    http_response_code(204);
    exit;
}

$data = json_decode($payload, true);
if ($event !== 'push' || ($data['repository']['full_name'] ?? '') !== 'AmirMehrabi/Kioosk-space'
    || ($data['ref'] ?? '') !== 'refs/heads/main' || ($data['deleted'] ?? false)) {
    http_response_code(204);
    exit;
}

if (! touch('/srv/kioosk/shared/triggers/deploy')) {
    http_response_code(503);
    exit;
}

http_response_code(202);
