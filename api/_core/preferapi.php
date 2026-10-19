<?php
// =======================================================
// PreferAPI / Nexus Gaming Unified Integration Module
// =======================================================

if (!defined('PREFERAPI_LAUNCH_URL')) {
    define('PREFERAPI_LAUNCH_URL', 'https://panel.preferapi.info/api/v1/launch');
}
if (!defined('PREFERAPI_API_KEY')) {
    define('PREFERAPI_API_KEY', 'YOUR_MERCHANT_API_KEY');
}

/**
 * Get active PreferAPI configuration.
 */
function preferapi_config(): array
{
    $settings = function_exists('site_settings') ? site_settings() : [];
    $origin = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return [
        'api_url' => trim($settings['preferapi_api_url'] ?? PREFERAPI_LAUNCH_URL),
        'api_key' => trim($settings['preferapi_api_key'] ?? PREFERAPI_API_KEY),
        'return_url' => trim($settings['preferapi_return_url'] ?? ($origin . '/#/')),
        'enabled' => !empty($settings['preferapi_enabled']),
    ];
}

/**
 * Ensure PreferAPI tables exist in database.
 */
function preferapi_ensure_tables(?mysqli $conn = null): void
{
    $conn = $conn ?: (function_exists('db') ? db() : null);
    if (!$conn) return;

    static $tablesChecked = false;
    if ($tablesChecked) return;
    $tablesChecked = true;

    @$conn->query("CREATE TABLE IF NOT EXISTS game_bet_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(255) NOT NULL,
        vendor_code VARCHAR(64) DEFAULT '',
        game_code VARCHAR(64) DEFAULT '',
        game_name_en VARCHAR(160) DEFAULT '',
        bet_amount DECIMAL(15,2) DEFAULT 0.00,
        win_amount DECIMAL(15,2) DEFAULT 0.00,
        profit_loss DECIMAL(15,2) DEFAULT 0.00,
        balance_before DECIMAL(15,2) DEFAULT 0.00,
        balance_after DECIMAL(15,2) DEFAULT 0.00,
        serial_number VARCHAR(160) UNIQUE,
        game_round VARCHAR(160) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_serial (serial_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Launch game using PreferAPI.
 */
function preferapi_launch_game(array $user, string $gameCode, string $vendorCode = '', string $returnUrl = ''): array
{
    $cfg = preferapi_config();
    $userId = (string)($user['id'] ?? '0');
    $userBalance = round((float)($user['balance'] ?? 0), 2);

    if ($returnUrl === '') {
        $returnUrl = $cfg['return_url'];
    }

    $payload = [
        'gameCode'    => (string)$gameCode,
        'vendorCode'  => (string)($vendorCode ?: '20'),
        'userId'      => (string)$userId,
        'userBalance' => (float)$userBalance,
        'language'    => 0,
        'phonetype'   => 1,
        'returnUrl'   => $returnUrl,
    ];

    $endpoint = $cfg['api_url'];
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $cfg['api_key'],
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if (function_exists('gamecloud_log')) {
        gamecloud_log('preferapi_launch', $gameCode, $jsonPayload, (string)$response, $httpCode);
    }

    if ($curlErr) {
        return [
            'success' => false,
            'error' => 'PreferAPI connection error: ' . $curlErr,
            'game_code' => $gameCode,
        ];
    }

    $data = json_decode($response ?: '', true);

    if (is_array($data)) {
        $url = $data['data']['url'] ?? ($data['url'] ?? ($data['gameUrl'] ?? ''));
        if (!empty($url)) {
            return [
                'success' => true,
                'url' => $url,
                'game_launch_url' => $url,
                'game_uid' => $gameCode,
                'provider' => 'preferapi',
                'raw' => $data,
            ];
        }

        $msg = $data['msg'] ?? ($data['message'] ?? ($data['error'] ?? 'PreferAPI launch failed'));
        return [
            'success' => false,
            'error' => $msg,
            'game_code' => $gameCode,
            'raw' => $data,
        ];
    }

    return [
        'success' => false,
        'error' => 'Invalid response from PreferAPI (HTTP ' . $httpCode . ')',
        'raw_response' => $response,
        'game_code' => $gameCode,
    ];
}

/**
 * Process PreferAPI Seamless Webhook Callback.
 */
function preferapi_process_callback(array $in, string $rawBody = ''): array
{
    $conn = function_exists('db') ? db() : null;
    if (!$conn) {
        return ['code' => 1, 'msg' => 'Database connection failed'];
    }

    preferapi_ensure_tables($conn);

    $playerId = trim((string)($in['user_id'] ?? ($in['playerId'] ?? ($in['userId'] ?? ''))));
    $bet = floatval($in['bet_amount'] ?? ($in['betAmount'] ?? 0));
    $win = floatval($in['win_amount'] ?? ($in['winAmount'] ?? 0));
    $serial = trim((string)($in['serial_number'] ?? ($in['serialNumber'] ?? ($in['txn_id'] ?? ''))));
    $round = trim((string)($in['game_round'] ?? ($in['roundId'] ?? '')));
    $gc = trim((string)($in['game_code'] ?? ($in['gameCode'] ?? '')));
    $vc = trim((string)($in['vendor_code'] ?? ($in['vendorCode'] ?? '')));
    $gn = trim((string)($in['game_name'] ?? ($in['gameName'] ?? 'Game')));

    if ($playerId === '') {
        return ['code' => 1, 'msg' => 'Missing user_id'];
    }

    // Find user using common finder
    $user = function_exists('gamecloud_find_user') ? gamecloud_find_user($playerId, $conn) : null;
    if (!$user && ctype_digit($playerId)) {
        $uid = (int)$playerId;
        $st = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        if ($st) {
            $st->bind_param('i', $uid);
            $st->execute();
            $user = $st->get_result()->fetch_assoc();
        }
    }

    if (!$user) {
        return ['code' => 1, 'msg' => 'User not found'];
    }

    $userId = (int)$user['id'];
    $cur = round((float)($user['balance'] ?? 0), 2);

    // Idempotency check on serial_number
    if ($serial !== '') {
        $chk = $conn->prepare("SELECT balance_after FROM game_bet_logs WHERE serial_number = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("s", $serial);
            $chk->execute();
            $chkRes = $chk->get_result()->fetch_assoc();
            if ($chkRes) {
                $savedBal = round((float)$chkRes['balance_after'], 2);
                return [
                    'code' => 0,
                    'msg' => 'Duplicate',
                    'balance' => $savedBal,
                    'balance_inr' => $savedBal,
                ];
            }
        }
    }

    // Atomic transaction with row-level lock
    $conn->begin_transaction();
    try {
        $lockStmt = $conn->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
        $lockStmt->bind_param("i", $userId);
        $lockStmt->execute();
        $row = $lockStmt->get_result()->fetch_assoc();
        $currentBalance = floatval($row['balance'] ?? 0);

        if ($currentBalance - $bet < 0) {
            $conn->rollback();
            return [
                'code' => 1,
                'msg' => 'Insufficient balance',
                'balance' => $currentBalance,
            ];
        }

        $newBal = round($currentBalance - $bet + $win, 2);

        $upStmt = $conn->prepare("UPDATE users SET balance = ?, total_bet = COALESCE(total_bet, 0) + ? WHERE id = ?");
        $upStmt->bind_param("ddi", $newBal, $bet, $userId);
        $upStmt->execute();

        $pl = round($win - $bet, 2);
        $now = date('Y-m-d H:i:s');

        // Insert into game_bet_logs
        $insLog = $conn->prepare("INSERT INTO game_bet_logs (user_id, vendor_code, game_code, game_name_en, bet_amount, win_amount, profit_loss, balance_before, balance_after, serial_number, game_round, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        if ($insLog) {
            $uStr = (string)$userId;
            $insLog->bind_param("ssssdddddsss", $uStr, $vc, $gc, $gn, $bet, $win, $pl, $currentBalance, $newBal, $serial, $round, $now);
            @$insLog->execute();
        }

        // Insert into seamless_transactions
        try {
            $rawJson = $rawBody ?: json_encode($in);
            $action = ($bet > 0 && $win > 0) ? 'bet_win' : ($bet > 0 ? 'bet' : 'win');
            $stIns = $conn->prepare("INSERT INTO seamless_transactions (user_id, action, amount, currency, provider_txn_id, game_code, balance_before, balance_after, raw_payload, created_at) VALUES (?, ?, ?, 'INR', ?, ?, ?, ?, ?, NOW())");
            if ($stIns) {
                $amt = $bet > 0 ? $bet : $win;
                $stIns->bind_param("isdssdds", $userId, $action, $amt, $serial, $gc, $currentBalance, $newBal, $rawJson);
                @$stIns->execute();
            }
        } catch (Throwable $e) {}

        // Insert into financial_records
        try {
            $recNo = 'NXT_' . date('YmdHis') . '_' . $userId . '_' . rand(100, 999);
            $finStmt = $conn->prepare("INSERT INTO financial_records (user_id, record_no, order_no, vendor_code, type, sub_type, amount, back_amount, remark, created_at) VALUES (?, ?, ?, 'PreferAPI', 'GameBet', ?, ?, ?, ?, NOW())");
            if ($finStmt) {
                $negPl = $pl;
                $remark = "PreferAPI Bet: " . ($gn ?: $gc);
                $finStmt->bind_param("isssdds", $userId, $recNo, $serial, $gc, $negPl, $newBal, $remark);
                @$finStmt->execute();
            }
        } catch (Throwable $e) {}

        $conn->commit();

        return [
            'code' => 0,
            'msg' => 'Success',
            'balance' => $newBal,
            'balance_inr' => $newBal,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        return [
            'code' => 1,
            'msg' => 'Transaction failed: ' . $e->getMessage(),
            'balance' => $cur,
        ];
    }
}

