<?php
// =======================================================
// GameCloud Unified Seamless Wallet & Game Launch Service
// =======================================================

if (!defined('GAMECLOUD_API_URL')) {
    define('GAMECLOUD_API_URL', 'https://api.gamecloudapi.com');
}
if (!defined('GAMECLOUD_RESELLER_ID')) {
    define('GAMECLOUD_RESELLER_ID', 279);
}
if (!defined('GAMECLOUD_API_TOKEN')) {
    define('GAMECLOUD_API_TOKEN', 'd1257d7ba93246929a94765cc8f67136');
}
if (!defined('GAMECLOUD_SECRET_KEY')) {
    define('GAMECLOUD_SECRET_KEY', 'ad22ad1d07c33ebf91acfe83fe6cd56a9e33b76e91ed859d0');
}
if (!defined('GAMECLOUD_PREFIX')) {
    define('GAMECLOUD_PREFIX', 'RS_YOURWINS');
}
if (!defined('GAMECLOUD_CALLBACK_URL')) {
    define('GAMECLOUD_CALLBACK_URL', 'https://yourwinstreak.com/callback.php');
}
if (!defined('GAMECLOUD_DEFAULT_CURRENCY')) {
    define('GAMECLOUD_DEFAULT_CURRENCY', 'INR');
}

/**
 * Retrieve active GameCloud configuration from database settings or fallback to constants.
 */
function gamecloud_config(): array
{
    $settings = function_exists('site_settings') ? site_settings() : [];

    return [
        'api_url' => trim($settings['gamecloud_api_url'] ?? GAMECLOUD_API_URL),
        'reseller_id' => (int)($settings['gamecloud_reseller_id'] ?? GAMECLOUD_RESELLER_ID),
        'api_token' => trim($settings['gamecloud_api_token'] ?? GAMECLOUD_API_TOKEN),
        'secret_key' => trim($settings['gamecloud_secret_key'] ?? GAMECLOUD_SECRET_KEY),
        'prefix' => trim($settings['gamecloud_prefix'] ?? GAMECLOUD_PREFIX),
        'callback_url' => trim($settings['gamecloud_callback_url'] ?? GAMECLOUD_CALLBACK_URL),
        'currency' => trim($settings['gamecloud_currency'] ?? (defined('APP_CURRENCY') ? APP_CURRENCY : GAMECLOUD_DEFAULT_CURRENCY)),
    ];
}

/**
 * Normalize game code and vendor to GameCloud catalog game_uid.
 * Example: SPRIBE + aviator => spribe_aviator
 * Example: TB_Chess + 800 => spribe_aviator
 * Example: JILI + 109 => jili_109
 */
function gamecloud_normalize_game_uid(string $gameCode, string $vendorCode = ''): string
{
    $gameCode = trim($gameCode);
    $vendorCode = trim($vendorCode);
    $codeLower = strtolower($gameCode);
    $vendorUpper = strtoupper($vendorCode);

    // If gameCode is already a 32-character hex Game UID from GameCloud
    if (preg_match('/^[a-f0-9]{32}$/i', $gameCode)) {
        return strtolower($gameCode);
    }

    // Direct platform alias map
    $aliasMap = [
        // Spribe / TopBet Aviator (GameCloud UID: c8db07594c7a54891531a4a09e0a956b)
        '800' => 'c8db07594c7a54891531a4a09e0a956b',
        '804' => 'c8db07594c7a54891531a4a09e0a956b',
        'aviator' => 'c8db07594c7a54891531a4a09e0a956b',
        'spribe_aviator' => 'c8db07594c7a54891531a4a09e0a956b',
        'topbet_aviator' => 'c8db07594c7a54891531a4a09e0a956b',

        // Mini games
        '100' => 'spribe_mines',
        'mines' => 'spribe_mines',
        '811' => 'spribe_mines',
        '103' => 'spribe_plinko',
        'plinko' => 'spribe_plinko',
        '110' => 'spribe_limbo',
        'limbo' => 'spribe_limbo',
        '105' => 'spribe_goal',
        'goal' => 'spribe_goal',
        '102' => 'spribe_dice',
        'dice' => 'spribe_dice',
        '107' => 'spribe_hotline',
        'hotline' => 'spribe_hotline',
        '106' => 'spribe_keno',
        'keno' => 'spribe_keno',
        '104' => 'spribe_mini-roulette',
        'mini-roulette' => 'spribe_mini-roulette',
        '101' => 'spribe_hilo',
        'hilo' => 'spribe_hilo',

        // JILI Slots & Fishing
        '51' => 'jili_51',
        '109' => 'jili_109',
        '224' => 'jili_224',
        '223' => 'jili_223',
        '229' => 'jili_229',
        '235' => 'jili_235',
        '236' => 'jili_236',
        '1' => 'jili_1',
        '74' => 'jili_74',
        '82' => 'jili_82',
        '32' => 'jili_32',
        '20' => 'jili_20',
        '119' => 'jili_119',

        // JDB Fishing & Slots
        '14036' => 'jdb_14036',
        '7001' => 'jdb_7001',
        '7002' => 'jdb_7002',
        '7003' => 'jdb_7003',
        '7004' => 'jdb_7004',
        '7005' => 'jdb_7005',
        '7007' => 'jdb_7007',

        // CQ9 Fishing
        'at01' => 'cq9_at01',
        'at05' => 'cq9_at05',
        'go02' => 'cq9_go02',
    ];

    $final_id = $codeLower;

    if (isset($aliasMap[$codeLower])) {
        $final_id = $aliasMap[$codeLower];
    } elseif (isset($aliasMap[$gameCode])) {
        $final_id = $aliasMap[$gameCode];
    } else {
        // Check if custom mapped in site settings
        $settings = function_exists('site_settings') ? site_settings() : [];
        $customMaps = $settings['gamecloud_game_uids'] ?? [];
        if (is_array($customMaps)) {
            if (!empty($customMaps[$codeLower])) $final_id = trim($customMaps[$codeLower]);
            elseif (!empty($customMaps[$gameCode])) $final_id = trim($customMaps[$gameCode]);
        }
        
        if ($final_id === $codeLower) {
            // If gameCode is already prefixed with vendor (e.g. jili_109, pgsoft_mahjong)
            if (strpos($gameCode, '_') !== false && !preg_match('/^(WinGo|TrxWinGo|K3|5D|D5|MotoRace)_/i', $gameCode)) {
                $final_id = strtolower($gameCode);
            } else {
                if ($vendorUpper === 'SPRIBE') {
                    $final_id = 'spribe_' . $codeLower;
                } elseif ($vendorUpper === 'JILI') {
                    $final_id = 'jili_' . $codeLower;
                } elseif ($vendorUpper === 'JDB') {
                    $final_id = 'jdb_' . $codeLower;
                } elseif ($vendorUpper === 'CQ9') {
                    $final_id = 'cq9_' . $codeLower;
                } elseif (in_array($vendorUpper, ['PG', 'PGSOFT'], true)) {
                    $final_id = 'pgsoft_' . $codeLower;
                } elseif (in_array($vendorUpper, ['EVO', 'EVOLUTION'], true)) {
                    $final_id = 'evolution_' . $codeLower;
                } elseif ($vendorCode !== '') {
                    $v = strtolower($vendorCode);
                    $v = str_replace(['_video', '_chess', '_card'], '', $v);
                    $final_id = $v . '_' . $codeLower;
                }
            }
        }
    }
    
    // NOW OVERRIDE WITH JSON SQL MAPPING
    $map_file = __DIR__ . '/game_uid_map.php';
    if (file_exists($map_file)) {
        $map = include($map_file);
        if (is_array($map) && isset($map[$final_id])) {
            return $map[$final_id];
        }
    }

    return $final_id;
}

function gamecloud_player_id(array $user): string
{
    $uid = (int)($user['id'] ?? 0);
    if ($uid > 0) {
        // Offset by 100000 to keep ID at 5 digits (GameCloud bug truncates to 5 chars)
        if ($uid > 100000 && $uid < 200000) {
            return (string)($uid - 100000);
        }
        return (string)$uid;
    }
    return (string)($user['tenant_user_id'] ?? '101');
}

/**
 * Launch third-party game via GameCloud API.
 */
function gamecloud_launch_game(array $user, string $gameCode, string $vendorCode = '', string $homeUrl = ''): array
{
    $cfg = gamecloud_config();
    $gameUid = gamecloud_normalize_game_uid($gameCode, $vendorCode);
    $playerId = gamecloud_player_id($user);

    if ($homeUrl === '') {
        $origin = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourwinstreak.com');
        $homeUrl = $origin . '/';
    }

    $payload = [
        'reseller_id' => $cfg['reseller_id'],
        'player_id' => $playerId,
        'game_uid' => $gameUid,
        'mode' => 'seamless',
        'currency_code' => $cfg['currency'],
        'home_url' => $homeUrl,
    ];

    $endpoint = rtrim($cfg['api_url'], '/') . '/api/v1/game/launch';
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $cfg['api_token'],
        'X-Api-Key: ' . $cfg['api_token'],
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    // Log request & response
    gamecloud_log('launch', $gameUid, $jsonPayload, (string)$response, $httpCode);

    if ($curlErr) {
        return [
            'success' => false,
            'error' => 'Connection error: ' . $curlErr,
            'game_uid' => $gameUid,
        ];
    }

    $data = json_decode($response ?: '', true);

    if (is_array($data)) {
        if ((isset($data['status']) && strtoupper($data['status']) === 'SUCCESS') && !empty($data['game_launch_url'])) {
            return [
                'success' => true,
                'game_launch_url' => $data['game_launch_url'],
                'url' => $data['game_launch_url'],
                'game_uid' => $gameUid,
                'raw' => $data,
            ];
        }

        $errorMsg = $data['error'] ?? $data['message'] ?? $data['msg'] ?? 'Game launch failed';
        return [
            'success' => false,
            'error' => $errorMsg,
            'game_uid' => $gameUid,
            'raw' => $data,
        ];
    }

    return [
        'success' => false,
        'error' => 'Invalid response from GameCloud API (HTTP ' . $httpCode . ')',
        'raw_response' => $response,
        'game_uid' => $gameUid,
    ];
}

/**
 * Log GameCloud API actions to database for monitoring & auditing.
 */
function gamecloud_log(string $type, string $action, string $req, string $res, int $httpCode = 200): void
{
    $conn = function_exists('db') ? db() : null;
    if (!$conn) return;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = @$conn->prepare('INSERT INTO gamecloud_logs(type, action, request_payload, response_payload, ip, created_at) VALUES(?,?,?,?,?,NOW())');
    if ($stmt) {
        $stmt->bind_param('sssss', $type, $action, $req, $res, $ip);
        @$stmt->execute();
    }
}

/**
 * Find user by player_id (supports user ID, tenant_user_id, username, and prefixed strings).
 */
/**
 * Find user by player_id (supports user ID, tenant_user_id, username, mobile, and fallback).
 */
function gamecloud_find_user(string $playerId, ?mysqli $conn = null): ?array
{
    $conn = $conn ?: (function_exists('db') ? db() : null);
    if (!$conn) return null;

    $playerId = trim($playerId);
    if ($playerId === '') return null;

    // Direct numeric ID (and handle offset restoration)
    if (ctype_digit($playerId) && (int)$playerId > 0) {
        $id = (int)$playerId;
        // If we receive a 4 or 5 digit ID, it might be an offset ID from gamecloud_player_id
        if ($id > 0 && $id < 100000) {
            // Check if the offset ID exists, since all our IDs start at 117224
            $id += 100000;
        }
        
        $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if ($user) return $user;
        }
        
        // Fallback: try without offset just in case
        $stmt2 = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        if ($stmt2) {
            $rawId = (int)$playerId;
            $stmt2->bind_param('i', $rawId);
            $stmt2->execute();
            $user = $stmt2->get_result()->fetch_assoc();
            if ($user) return $user;
        }
    }



    // Strip prefix like player_101, user_101
    if (preg_match('/^(?:player|user|u)_?(\d+)$/i', $playerId, $m)) {
        $id = (int)$m[1];
        $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if ($user) return $user;
        }
    }

    // Search by tenant_user_id
    $stmt = $conn->prepare('SELECT * FROM users WHERE tenant_user_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $playerId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if ($user) return $user;
    }

    // Search by username
    $stmt = $conn->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $playerId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if ($user) return $user;
    }

    // Search by mobile
    $stmt = $conn->prepare('SELECT * FROM users WHERE mobile = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $playerId);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if ($user) return $user;
    }

    return null;
}


/**
 * Process Seamless Wallet Webhook Callback from GameCloud.
 */
function seamless_process_webhook(array $input, string $rawPayload = ''): array
{
    $rawAction = trim((string)($input['action'] ?? $input['operation'] ?? $input['type'] ?? $input['event'] ?? $input['op'] ?? ''));
    $action = strtolower($rawAction);
    $playerId = trim((string)($input['player_id'] ?? $input['playerId'] ?? $input['user_id'] ?? $input['userId'] ?? $input['player'] ?? $input['uid'] ?? ''));
    $amount = floatval($input['amount'] ?? $input['bet_amount'] ?? $input['win_amount'] ?? 0);
    $currency = trim((string)($input['currency'] ?? $input['currency_code'] ?? 'INR'));
    $providerTxnId = trim((string)($input['provider_txn_id'] ?? $input['providerTxnId'] ?? $input['txn_id'] ?? $input['txnid'] ?? $input['transaction_id'] ?? ''));
    $gameCode = trim((string)($input['game_code'] ?? $input['game_uid'] ?? $input['gameCode'] ?? $input['gameId'] ?? ''));

    // Alias action normalizer
    if (in_array($action, ['getbalance', 'get_balance', 'user_balance', 'check_balance'], true)) {
        $action = 'balance';
    } elseif (in_array($action, ['debit', 'placebet', 'betting', 'play'], true)) {
        $action = 'bet';
    } elseif (in_array($action, ['credit', 'settle', 'award', 'payout'], true)) {
        $action = 'win';
    } elseif (in_array($action, ['rollback', 'cancel', 'void', 'cancel_bet', 'cancelbet'], true)) {
        $action = 'refund';
    }

    if ($action === '') {
        $res = ['status' => 'FAILED', 'error' => 'EMPTY_ACTION', 'http_code' => 400];
        gamecloud_log('webhook', 'unknown', $rawPayload ?: json_encode($input), json_encode($res), 400);
        return $res;
    }

    $conn = function_exists('db') ? db() : null;
    if (!$conn) {
        $res = ['status' => 'FAILED', 'error' => 'DATABASE_ERROR', 'http_code' => 500];
        gamecloud_log('webhook', $action, $rawPayload ?: json_encode($input), json_encode($res), 500);
        return $res;
    }

    $user = gamecloud_find_user($playerId, $conn);
    if (!$user) {
        $res = ['status' => 'FAILED', 'error' => 'USER_NOT_FOUND', 'http_code' => 400];
        gamecloud_log('webhook', $action, $rawPayload ?: json_encode($input), json_encode($res), 400);
        return $res;
    }

    $userId = (int)$user['id'];

    // 1. BALANCE ACTION
    if ($action === 'balance') {
        $currentBalance = round((float)$user['balance'], 2);
        $res = ['status' => 'SUCCESS', 'balance' => (float)number_format($currentBalance, 2, '.', '')];
        gamecloud_log('webhook', 'balance', $rawPayload ?: json_encode($input), json_encode($res), 200);
        return $res;
    }



    // 2. TIE / DRAW / PUSH ACTION
    if (in_array($action, ['tie', 'draw', 'push'], true)) {
        $curBal = round((float)$user['balance'], 2);
        if ($amount > 0) {
            $curBal = round($curBal + $amount, 2);
            $conn->query("UPDATE users SET balance = $curBal WHERE id = $userId");
        }
        $res = ['status' => 'SUCCESS', 'balance' => (float)number_format($curBal, 2, '.', '')];
        gamecloud_log('webhook', 'tie', $rawPayload ?: json_encode($input), json_encode($res), 200);
        return $res;
    }

    // 3. PING / HEALTH ACTION
    if (in_array($action, ['ping', 'health', 'check'], true)) {
        return ['status' => 'SUCCESS', 'msg' => 'OK'];
    }

    // 4. BET ACTION
    if ($action === 'bet') {
        if ($amount < 0) {
            $res = ['status' => 'FAILED', 'error' => 'INVALID_AMOUNT', 'http_code' => 400];
            gamecloud_log('webhook', 'bet', $rawPayload ?: json_encode($input), json_encode($res), 400);
            return $res;
        }

        if ($providerTxnId === '') {
            $providerTxnId = 'TXN_' . date('YmdHis') . '_' . rand(1000, 9999);
        }


        // Check Idempotency
        $checkStmt = $conn->prepare('SELECT balance_after FROM seamless_transactions WHERE provider_txn_id = ? AND action = "bet" LIMIT 1');
        if ($checkStmt) {
            $checkStmt->bind_param('s', $providerTxnId);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();
            if ($existing) {
                $res = ['status' => 'SUCCESS', 'balance' => round((float)$existing['balance_after'], 2)];
                gamecloud_log('webhook', 'bet_duplicate', $rawPayload ?: json_encode($input), json_encode($res), 200);
                return $res;
            }
        }

        // Execute Bet deduction inside database transaction
        $conn->begin_transaction();
        try {
            $lockStmt = $conn->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
            $lockStmt->bind_param('i', $userId);
            $lockStmt->execute();
            $lockedUser = $lockStmt->get_result()->fetch_assoc();
            $curBal = (float)($lockedUser['balance'] ?? 0);

            if ($curBal < $amount) {
                $conn->rollback();
                $res = ['status' => 'FAILED', 'error' => 'INSUFFICIENT_FUNDS', 'http_code' => 400];
                gamecloud_log('webhook', 'bet_insufficient', $rawPayload ?: json_encode($input), json_encode($res), 400);
                return $res;
            }

            $newBal = round($curBal - $amount, 2);
            $upStmt = $conn->prepare('UPDATE users SET balance = ? WHERE id = ?');
            $upStmt->bind_param('di', $newBal, $userId);
            $upStmt->execute();



            @ $conn->query("UPDATE users SET total_bet = COALESCE(total_bet, 0) + " . (float)$amount . " WHERE id = " . (int)$userId);


            // Insert seamless transaction log safely
            try {
                $rawJson = $rawPayload ?: json_encode($input);
                $actionName = 'bet';
                $insTxn = $conn->prepare('INSERT INTO seamless_transactions (user_id, action, amount, currency, provider_txn_id, game_code, balance_before, balance_after, raw_payload, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                if ($insTxn) {
                    $insTxn->bind_param('isdsssdds', $userId, $actionName, $amount, $currency, $providerTxnId, $gameCode, $curBal, $newBal, $rawJson);
                    $insTxn->execute();
                }
            } catch (Throwable $ignore) {}

            // Insert financial record safely
            try {
                $recNo = 'SB_' . date('YmdHis') . '_' . $userId . '_' . rand(100, 999);
                $finType = 'GameBet';
                $vendor = 'GameCloud';
                $negAmount = -$amount;
                $remark = 'GameCloud Bet: ' . ($gameCode ?: 'Game');
                $finStmt = $conn->prepare('INSERT INTO financial_records (user_id, record_no, order_no, vendor_code, type, sub_type, amount, back_amount, remark, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                if ($finStmt) {
                    $finStmt->bind_param('isssssdds', $userId, $recNo, $providerTxnId, $vendor, $finType, $gameCode, $negAmount, $newBal, $remark);
                    $finStmt->execute();
                }
            } catch (Throwable $ignore) {}

            $conn->commit();

            $res = ['status' => 'SUCCESS', 'balance' => (float)number_format($newBal, 2, '.', '')];
            gamecloud_log('webhook', 'bet', $rawPayload ?: json_encode($input), json_encode($res), 200);
            return $res;
        } catch (Throwable $e) {
            $conn->rollback();
            $res = ['status' => 'FAILED', 'error' => 'TRANSACTION_FAILED: ' . $e->getMessage(), 'http_code' => 500];
            gamecloud_log('webhook', 'bet_error', $rawPayload ?: json_encode($input), json_encode($res), 500);
            return $res;
        }
    }

    // 3. WIN ACTION
    if ($action === 'win') {
        if ($amount < 0) {
            $res = ['status' => 'FAILED', 'error' => 'INVALID_AMOUNT', 'http_code' => 400];
            gamecloud_log('webhook', 'win', $rawPayload ?: json_encode($input), json_encode($res), 400);
            return $res;
        }

        if ($providerTxnId === '') {
            $res = ['status' => 'FAILED', 'error' => 'MISSING_PROVIDER_TXN_ID', 'http_code' => 400];
            gamecloud_log('webhook', 'win', $rawPayload ?: json_encode($input), json_encode($res), 400);
            return $res;
        }

        // Check Idempotency
        $checkStmt = $conn->prepare('SELECT balance_after FROM seamless_transactions WHERE provider_txn_id = ? AND action = "win" LIMIT 1');
        if ($checkStmt) {
            $checkStmt->bind_param('s', $providerTxnId);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();
            if ($existing) {
                $res = ['status' => 'SUCCESS', 'balance' => round((float)$existing['balance_after'], 2)];
                gamecloud_log('webhook', 'win_duplicate', $rawPayload ?: json_encode($input), json_encode($res), 200);
                return $res;
            }
        }

        // Execute Win credit inside database transaction
        $conn->begin_transaction();
        try {
            $lockStmt = $conn->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
            $lockStmt->bind_param('i', $userId);
            $lockStmt->execute();
            $lockedUser = $lockStmt->get_result()->fetch_assoc();
            $curBal = (float)($lockedUser['balance'] ?? 0);

            $newBal = round($curBal + $amount, 2);
            $upStmt = $conn->prepare('UPDATE users SET balance = ? WHERE id = ?');
            $upStmt->bind_param('di', $newBal, $userId);
            $upStmt->execute();



            // Insert seamless transaction log safely
            try {
                $rawJson = $rawPayload ?: json_encode($input);
                $actionName = 'win';
                $insTxn = $conn->prepare('INSERT INTO seamless_transactions (user_id, action, amount, currency, provider_txn_id, game_code, balance_before, balance_after, raw_payload, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                if ($insTxn) {
                    $insTxn->bind_param('isdsssdds', $userId, $actionName, $amount, $currency, $providerTxnId, $gameCode, $curBal, $newBal, $rawJson);
                    $insTxn->execute();
                }
            } catch (Throwable $ignore) {}

            // Insert financial record safely
            try {
                $recNo = 'SW_' . date('YmdHis') . '_' . $userId . '_' . rand(100, 999);
                $finType = 'GameWin';
                $vendor = 'GameCloud';
                $remark = 'GameCloud Win: ' . ($gameCode ?: 'Game');
                $finStmt = $conn->prepare('INSERT INTO financial_records (user_id, record_no, order_no, vendor_code, type, sub_type, amount, back_amount, remark, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                if ($finStmt) {
                    $finStmt->bind_param('isssssdds', $userId, $recNo, $providerTxnId, $vendor, $finType, $gameCode, $amount, $newBal, $remark);
                    $finStmt->execute();
                }
            } catch (Throwable $ignore) {}

            $conn->commit();

            $res = ['status' => 'SUCCESS', 'balance' => (float)number_format($newBal, 2, '.', '')];
            gamecloud_log('webhook', 'win', $rawPayload ?: json_encode($input), json_encode($res), 200);
            return $res;
        } catch (Throwable $e) {
            $conn->rollback();
            $res = ['status' => 'FAILED', 'error' => 'TRANSACTION_FAILED: ' . $e->getMessage(), 'http_code' => 500];
            gamecloud_log('webhook', 'win_error', $rawPayload ?: json_encode($input), json_encode($res), 500);
            return $res;
        }
    }

    // 4. REFUND ACTION
    if ($action === 'refund') {
        if ($amount < 0) {
            $res = ['status' => 'FAILED', 'error' => 'INVALID_AMOUNT', 'http_code' => 400];
            gamecloud_log('webhook', 'refund', $rawPayload ?: json_encode($input), json_encode($res), 400);
            return $res;
        }

        if ($providerTxnId === '') {
            $res = ['status' => 'FAILED', 'error' => 'MISSING_PROVIDER_TXN_ID', 'http_code' => 400];
            gamecloud_log('webhook', 'refund', $rawPayload ?: json_encode($input), json_encode($res), 400);
            return $res;
        }

        // Check Idempotency
        $checkStmt = $conn->prepare('SELECT balance_after FROM seamless_transactions WHERE provider_txn_id = ? AND action = "refund" LIMIT 1');
        if ($checkStmt) {
            $checkStmt->bind_param('s', $providerTxnId);
            $checkStmt->execute();
            $existing = $checkStmt->get_result()->fetch_assoc();
            if ($existing) {
                $res = ['status' => 'SUCCESS', 'balance' => round((float)$existing['balance_after'], 2)];
                gamecloud_log('webhook', 'refund_duplicate', $rawPayload ?: json_encode($input), json_encode($res), 200);
                return $res;
            }
        }

        // Execute Refund inside database transaction
        $conn->begin_transaction();
        try {
            $lockStmt = $conn->prepare('SELECT balance FROM users WHERE id = ? FOR UPDATE');
            $lockStmt->bind_param('i', $userId);
            $lockStmt->execute();
            $lockedUser = $lockStmt->get_result()->fetch_assoc();
            $curBal = (float)($lockedUser['balance'] ?? 0);

            $newBal = round($curBal + $amount, 2);
            $upStmt = $conn->prepare('UPDATE users SET balance = ? WHERE id = ?');
            $upStmt->bind_param('di', $newBal, $userId);
            $upStmt->execute();

            @ $conn->query("UPDATE users SET total_bet = GREATEST(0, COALESCE(total_bet, 0) - " . (float)$amount . ") WHERE id = " . (int)$userId);

            // Insert seamless transaction log safely
            try {
                $rawJson = $rawPayload ?: json_encode($input);
                $actionName = 'refund';
                $insTxn = $conn->prepare('INSERT INTO seamless_transactions (user_id, action, amount, currency, provider_txn_id, game_code, balance_before, balance_after, raw_payload, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                if ($insTxn) {
                    $insTxn->bind_param('isdsssdds', $userId, $actionName, $amount, $currency, $providerTxnId, $gameCode, $curBal, $newBal, $rawJson);
                    $insTxn->execute();
                }
            } catch (Throwable $ignore) {}

            // Insert financial record safely
            try {
                $recNo = 'SR_' . date('YmdHis') . '_' . $userId . '_' . rand(100, 999);
                $finType = 'GameRefund';
                $vendor = 'GameCloud';
                $remark = 'GameCloud Refund: ' . ($providerTxnId ?: 'Bet');
                $finStmt = $conn->prepare('INSERT INTO financial_records (user_id, record_no, order_no, vendor_code, type, sub_type, amount, back_amount, remark, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
                if ($finStmt) {
                    $finStmt->bind_param('isssssdds', $userId, $recNo, $providerTxnId, $vendor, $finType, $gameCode, $amount, $newBal, $remark);
                    $finStmt->execute();
                }
            } catch (Throwable $ignore) {}

            $conn->commit();

            $res = ['status' => 'SUCCESS', 'balance' => (float)number_format($newBal, 2, '.', '')];
            gamecloud_log('webhook', 'refund', $rawPayload ?: json_encode($input), json_encode($res), 200);
            return $res;
        } catch (Throwable $e) {
            $conn->rollback();
            $res = ['status' => 'FAILED', 'error' => 'TRANSACTION_FAILED: ' . $e->getMessage(), 'http_code' => 500];
            gamecloud_log('webhook', 'refund_error', $rawPayload ?: json_encode($input), json_encode($res), 500);
            return $res;
        }
    }


    // INVALID ACTION
    $res = ['status' => 'FAILED', 'error' => 'INVALID_ACTION', 'http_code' => 400];
    gamecloud_log('webhook', 'invalid_action', $rawPayload ?: json_encode($input), json_encode($res), 400);
    return $res;
}

