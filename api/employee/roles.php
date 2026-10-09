<?php
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/proxy.php';
function respond(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}
$method = $_SERVER['REQUEST_METHOD'];
$apiBaseUrl = getenv('EXPO_PUBLIC_API_BASE_URL') ?: 'https://civentral.tech/api/employee';
$fileName = basename(__FILE__);
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$remoteUrl = rtrim($apiBaseUrl, '/') . '/' . $fileName . ($queryString !== '' ? '?' . $queryString : '');
$body = null;
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
    $body = file_get_contents('php://input');
}

if (in_array($method, ['PUT', 'PATCH']) && !empty($body)) {
    $data = json_decode($body, true);
    $targetRoleId = intval($data['role_id'] ?? 0);
    $currentUserRoleId = intval($_SESSION['role_id'] ?? 0);
    $newStatus = strtolower(trim($data['status'] ?? ''));
    if ($targetRoleId > 0 && $currentUserRoleId > 0 && $targetRoleId === $currentUserRoleId && in_array($newStatus, ['inactive', 'deactivated', 'archived'])) {
        respond([
            'status' => 'error',
            'message' => 'Forbidden. You are not allowed to deactivate or archive your own assigned role.'
        ], 403);
    }
}

$result = proxyRequest($remoteUrl, $method, $body);
respond($result['body'], $result['code']);
