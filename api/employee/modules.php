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
$result = proxyRequest($remoteUrl, $method, $body);

// AUTO-GRANT ACCESS & CREATE RESOURCE ON NEW MODULE CREATION
if ($method === 'POST' && ($result['code'] >= 200 && $result['code'] < 300)) {
    try {
        $bodyData = json_decode($body, true) ?? [];
        $modName = $bodyData['module_name'] ?? null;
        $modDesc = $bodyData['description'] ?? '';
        
        $modData = $result['body']['data'] ?? $result['body'] ?? null;
        $newModuleId = null;
        if (is_array($modData)) {
            $newModuleId = $modData['module_id'] ?? $modData['id'] ?? null;
        }
        if (!$newModuleId && is_array($result['body'])) {
            $newModuleId = $result['body']['module_id'] ?? $result['body']['id'] ?? null;
        }
        
        // If ID not directly in creation response, fetch modules list to resolve ID by name
        if (!$newModuleId && !empty($modName)) {
            $listMod = proxyRequest($remoteUrl, 'GET');
            if (($listMod['code'] >= 200 && $listMod['code'] < 300) && isset($listMod['body']['data']) && is_array($listMod['body']['data'])) {
                foreach ($listMod['body']['data'] as $m) {
                    if (strcasecmp(trim($m['module_name'] ?? ''), trim($modName)) === 0) {
                        $newModuleId = intval($m['module_id'] ?? $m['id']);
                        break;
                    }
                }
            }
        }
        
        if ($newModuleId) {
            // Create default resource for this new module via resources API to trigger permissions auto-grant
            $resourcesUrl = rtrim($apiBaseUrl, '/') . '/resources.php';
            $resReqBody = json_encode([
                'module_id' => $newModuleId,
                'resource_name' => $modName . ' Management',
                'resource_route' => '/pages/rolespermission/module-management.php',
                'description' => 'Default management resource for ' . $modName,
                'status' => 'Active'
            ]);
            proxyRequest($resourcesUrl, 'POST', $resReqBody);
        }
    } catch (\Throwable $e) {
        error_log('Error auto-granting permissions on module creation: ' . $e->getMessage());
    }
}

respond($result['body'], $result['code']);
