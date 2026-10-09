<?php
require_once __DIR__ . '/../../config/cors.php';

require_once __DIR__ . '/../../config/proxy.php';

function respond(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    respond([
        'status' => 'error',
        'message' => 'Method Not Allowed.'
    ], 405);
}

$apiBaseUrl = getenv('EXPO_PUBLIC_API_BASE_URL') ?: 'https://civentral.tech/api/employee';
$remoteUrl = rtrim($apiBaseUrl, '/') . '/get-profile.php';

$result = proxyRequest($remoteUrl, 'GET', null);

respond($result['body'], $result['code']);
