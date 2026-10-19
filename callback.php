<?php
ini_set('serialize_precision', -1);
// =======================================================
// GameCloud Seamless Wallet Webhook Callback Handler
// URL: https://yourwinstreak.com/callback.php
// =======================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['status' => 'SUCCESS']);
    exit;
}

require_once __DIR__ . '/api/_core/bootstrap.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '', true);

if (!is_array($input) || empty($input)) {
    $input = !empty($_POST) ? $_POST : $_GET;
}

if (!is_array($input) || empty($input)) {
    http_response_code(400);
    $errRes = ['status' => 'FAILED', 'error' => 'EMPTY_PAYLOAD'];
    @file_put_contents(__DIR__ . '/callback.log', date('Y-m-d H:i:s') . " | EMPTY PAYLOAD | IP: " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n", FILE_APPEND | LOCK_EX);
    echo json_encode($errRes);
    exit;
}

// Auto-detect provider
$providerParam = strtolower(trim((string)($_GET['provider'] ?? '')));
$isPreferApi = ($providerParam === 'preferapi' || $providerParam === 'nexus')
    || isset($input['serial_number']) || isset($input['serialNumber'])
    || (isset($input['bet_amount']) && !isset($input['action']) && !isset($input['operation']));

if ($isPreferApi && function_exists('preferapi_process_callback')) {
    $res = preferapi_process_callback($input, $raw ?: '');
} else {
    $res = seamless_process_webhook($input, $raw ?: '');
}

$httpCode = 200;
if (isset($res['http_code'])) {
    $httpCode = (int)$res['http_code'];
    unset($res['http_code']);
}

$respJson = json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@file_put_contents(__DIR__ . '/callback.log', date('Y-m-d H:i:s') . " | REQ: " . ($raw ?: json_encode($input)) . " | RES ($httpCode): " . $respJson . "\n", FILE_APPEND | LOCK_EX);

http_response_code($httpCode);
echo $respJson;
exit;

