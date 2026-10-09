<?php
require_once __DIR__ . '/../../config/cors.php';

require_once __DIR__ . '/../../config/proxy.php';

// Response Helper
function respond(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    respond([
        'status' => 'error',
        'message' => 'Method Not Allowed.'
    ], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$otpCode = trim($input['otp'] ?? $input['otp_code'] ?? '');


if (empty($otpCode)) {
    respond([
        'status' => 'error',
        'message' => 'Please enter a valid 6-digit verification code.'
    ], 400);
}

$apiBaseUrl = getenv('EXPO_PUBLIC_API_BASE_URL') ?: 'https://civentral.tech/api/employee';
$remoteUrl = rtrim($apiBaseUrl, '/') . '/verify-otp.php';

$result = proxyRequest($remoteUrl, 'POST', [
    'otp' => $otpCode
]);

if (isset($result['body']['status']) && $result['body']['status'] === 'success') {
    $user = $result['body']['user'] ?? [];
    $_SESSION['user_id'] = $user['user_id'] ?? null;
    $_SESSION['employee_id'] = $user['employee_id'] ?? null;
    $_SESSION['email'] = $user['email'] ?? ($user['employee_id'] ?? null);
    $_SESSION['first_name'] = $user['first_name'] ?? null;
    $_SESSION['last_name'] = $user['last_name'] ?? null;
    $_SESSION['role_id'] = $user['role_id'] ?? null;

    // Fetch full profile details from get-profile.php
    $profileUrl = rtrim($apiBaseUrl, '/') . '/get-profile.php';
    $profileResult = proxyRequest($profileUrl, 'GET', null);
    if (isset($profileResult['body']['status']) && $profileResult['body']['status'] === 'success') {
        $_SESSION['current_user_details'] = $profileResult['body']['data'];
    }
}

respond($result['body'], $result['code']);
?>
