<?php
// =======================================================
// PreferAPI / Nexus Game Launch Endpoint
// =======================================================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['code' => 0, 'msg' => 'OK']);
    exit;
}

require_once __DIR__ . '/../api/_core/bootstrap.php';
require_once __DIR__ . '/../api/_core/preferapi.php';

$rawBody = file_get_contents("php://input");
$jsonData = json_decode($rawBody ?: '', true) ?: [];
$request = array_merge($jsonData, $_POST, $_GET);

$gameCode = (string)($request['gameCode'] ?? ($request['game_code'] ?? ($request['gameId'] ?? '')));
$vendorCode = (string)($request['vendorCode'] ?? ($request['vendor_code'] ?? ''));

if ($gameCode === '' || $gameCode === 'null') {
    echo json_encode(['code' => 7, 'msg' => 'Param is Invalid (gameCode missing)']);
    exit;
}

$u = current_user() ?: maybe_user();
if (empty($u['id'])) {
    http_response_code(401);
    echo json_encode(['code' => 4, 'msg' => 'No operation permission']);
    exit;
}

$origin = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$returnUrl = $origin . '/';

// Self-hosted ARLottery bypass
if (strtoupper($vendorCode) === 'ARLOTTERY' || preg_match('/^(WinGo|TrxWinGo|K3|5D|D5|MotoRace)_/i', $gameCode)) {
    $token = make_token($u);
    $route = function_exists('lottery_route_from_game_code') ? lottery_route_from_game_code($gameCode) : 'WinGo';
    $target = $origin . '/' . $route . '/' . rawurlencode($gameCode) . '?Token=' . rawurlencode($token);
    echo json_encode([
        'code' => 0,
        'msg' => 'Game launched successfully',
        'data' => [
            'url' => $target,
            'gameUrl' => $target,
            'returnUrl' => $returnUrl,
            'returnType' => 'game_url',
        ]
    ]);
    exit;
}

$launch = preferapi_launch_game($u, $gameCode, $vendorCode, $returnUrl);

if (!empty($launch['success']) && !empty($launch['url'])) {
    echo json_encode([
        'code' => 0,
        'msg' => 'Game launched successfully',
        'data' => [
            'url' => $launch['url'],
            'gameUrl' => $launch['url'],
            'returnUrl' => $returnUrl,
            'returnType' => 'game_url',
        ]
    ]);
    exit;
}

http_response_code(500);
echo json_encode([
    'code' => 8,
    'msg' => $launch['error'] ?? 'Failed to connect to gateway',
]);
exit;
