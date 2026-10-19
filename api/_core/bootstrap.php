<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wallet.php';
require_once __DIR__ . '/hotfix_helpers.php';
require_once __DIR__ . '/lottery_engine.php';
require_once __DIR__ . '/workorder.php';

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) { return $needle === '' || strpos($haystack, $needle) !== false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) { return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0; }
}

if (!defined('PUBLIC_ROOT')) {
    define('PUBLIC_ROOT', dirname(__DIR__, 2));
}
if (!defined('API_ROOT')) {
    define('API_ROOT', dirname(__DIR__));
}

function db(): ?mysqli
{
    static $conn = null;
    static $attempted = false;
    if (class_exists('mysqli') && $conn instanceof mysqli) return $conn;
    if ($attempted) return null;
    $attempted = true;
    if (!class_exists('mysqli')) { error_log('mysqli extension is not enabled'); return null; }
    if (function_exists('mysqli_report')) { mysqli_report(MYSQLI_REPORT_OFF); }
    $candidate = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($candidate->connect_errno) {
        error_log('DB connection failed: ' . $candidate->connect_error);
        return null;
    }
    $conn = $candidate;
    $conn->set_charset('utf8mb4');
    if (defined('AUTO_INSTALL_TABLES') && AUTO_INSTALL_TABLES) { ensure_database($conn); }
    return $conn;
}

function now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function api_success($data = null, string $msg = 'Succeed', array $extra = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    $out = array_merge([
        'data' => $data,
        'code' => 0,
        'msg' => $msg,
        'msgCode' => 0,
        'result' => true,
        'serverTime' => now_ms(),
    ], $extra);
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $msg = 'Failure', int $code = 1, int $msgCode = 1, $data = null): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'data' => $data,
        'code' => $code,
        'msg' => $msg,
        'msgCode' => $msgCode,
        'serverTime' => now_ms(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function request_data(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        api_success(null);
    }
    $raw = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    if (is_array($json)) return $json;
    return array_merge($_GET ?? [], $_POST ?? []);
}

function static_json(string $path): ?array
{
    $path = trim($path, '/');
    foreach (['.html', '.json', '.php'] as $ext) {
        $file = API_ROOT . '/' . $path . $ext;
        if (is_file($file)) {
            $raw = file_get_contents($file);
            // Some old .php files are raw JSON, not PHP code.
            $raw = trim($raw);
            if (str_starts_with($raw, '<?php')) return null;
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
    }
    return null;
}

function api_static_or_empty(string $path): void
{
    $data = static_json($path);
    if ($data !== null) {
        if (isset($data['serverTime'])) $data['serverTime'] = now_ms();
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    api_success(empty_page_data($path));
}

function empty_page_data(string $path)
{
    if (stripos($path, 'PageList') !== false || stripos($path, 'Record') !== false || stripos($path, 'History') !== false || stripos($path, 'List') !== false) {
        return ['list' => [], 'pageNo' => 1, 'totalPage' => 0, 'totalCount' => 0];
    }
    return null;
}

function bearer_token(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization']
        ?? $headers['authorization']
        ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
        ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')
        ?? ($_SERVER['Authorization'] ?? '');
    if (!$auth && isset($_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
    return '';
}

function make_token(array $user): string
{
    $payload = [
        'uid' => (int) $user['id'],
        'username' => $user['username'] ?? '',
        'tenantId' => APP_TENANT_ID,
        'iat' => time(),
        'exp' => time() + 86400,
        'sid' => (string)($user['login_session_token'] ?? ''),
    ];
    $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $body, APP_SECRET);
    return $body . '.' . $sig;
}

function parse_token(string $token): ?array
{
    if (!$token || strpos($token, '.') === false) return null;
    [$body, $sig] = explode('.', $token, 2);
    $expected = hash_hmac('sha256', $body, APP_SECRET);
    if (!hash_equals($expected, $sig)) return null;
    $json = base64_decode(strtr($body, '-_', '+/'));
    $payload = json_decode($json ?: '', true);
    if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

function current_user(): ?array
{
    $conn = db();
    $token = bearer_token();
    $payload = parse_token($token);
    if ($token && !$payload) {
        api_error('Login expired. Please login again.', 401, 401);
    }
    if (!$conn) {
        if ($token !== '') api_error('Database unavailable', 500, 500);
        return demo_user();
    }
    $uid = (int)($payload['uid'] ?? 0);
    if ($uid <= 0) {
        // keep app usable in demo even before login
        $uid = 117224;
    }
    $stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        if ($token !== '') api_error('Account lookup unavailable', 500, 500);
        return demo_user();
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $user = $res ? $res->fetch_assoc() : null;
    if (!$user) return $token ? api_error('Login expired. Please login again.', 401, 401) : demo_user();
    if ((int)($user['status'] ?? 1) !== 1) api_error('Account disabled', 401, 401);

    // One account one active device/session: new login invalidates old token.
    if ($token && !empty($payload['sid'])) {
        $activeSid = (string)($user['login_session_token'] ?? '');
        if ($activeSid !== '' && !hash_equals($activeSid, (string)$payload['sid'])) {
            api_error('Your account has logged in on another device. Please login again.', 401, 401);
        }
    }
    return $user;
}



function require_login_user(): array
{
    $token = bearer_token();
    if ($token === '') {
        api_error('Login expired. Please login again.', 401, 401);
    }
    $payload = parse_token($token);
    if (!$payload || (int)($payload['uid'] ?? 0) <= 0 || empty($payload['sid'])) {
        api_error('Login expired. Please login again.', 401, 401);
    }
    $user = current_user();
    if (!$user || (int)($user['id'] ?? 0) <= 0) {
        api_error('Login expired. Please login again.', 401, 401);
    }
    $conn = db();
    if ($conn) {
        $uid = (int)$user['id'];
        $sid = (string)$payload['sid'];
        $stmt = @$conn->prepare('SELECT id FROM user_sessions WHERE user_id=? AND session_token=? AND is_active=1 LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('is', $uid, $sid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if (!$row) {
                // Auto-heal session if valid
                $stmt2 = @$conn->prepare('INSERT IGNORE INTO user_sessions (user_id, session_token, ip, user_agent, is_active, created_at, last_seen_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())');
                if ($stmt2) {
                    $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
                    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
                    $stmt2->bind_param('isss', $uid, $sid, $ip, $ua);
                    $stmt2->execute();
                }
                if (empty($user['login_session_token'])) {
                    $stmt3 = @$conn->prepare('UPDATE users SET login_session_token=? WHERE id=?');
                    if ($stmt3) { $stmt3->bind_param('si', $sid, $uid); $stmt3->execute(); }
                }
            } else {
                $up = @$conn->prepare('UPDATE user_sessions SET last_seen_at=NOW() WHERE user_id=? AND session_token=?');
                if ($up) { $up->bind_param('is', $uid, $sid); $up->execute(); }
            }
        }
    }
    return $user;
}

function client_device_id(array $d = []): string
{
    $v = first_value($d, ['deviceId','deviceid','deviceNo','deviceType','fingerprint','fp','clientId'], '');
    if ($v !== '') return substr((string)$v, 0, 120);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return substr(hash('sha256', $ua . '|' . $ip), 0, 40);
}

function normalize_withdraw_type(string $type): string
{
    $type = trim($type);
    $map = [
        'bank' => 'BankCard',
        'bankcard' => 'BankCard',
        'bank_card' => 'BankCard',
        'upi' => 'UPI',
        'arupi' => 'UPI',
        'usdt' => 'USDT',
        'trc20' => 'USDT',
        'ewallet' => 'EWallet',
        'e_wallet' => 'EWallet',
        'arpay' => 'ARPay',
    ];
    $key = strtolower(str_replace([' ', '-'], ['', '_'], $type));
    return $map[$key] ?? ($type !== '' ? $type : 'BankCard');
}

function infer_withdraw_type(array $d): string
{
    $type = (string)first_value($d, ['withdrawType','walletType','type','activeType'], '');
    if ($type !== '') return normalize_withdraw_type($type);
    if (first_value($d, ['networkType','usdtAddress','walletAddress','aliasAddress'], '') !== '') return 'USDT';
    if (first_value($d, ['pixWalletType','cpf'], '') !== '') return 'PIX';
    $account = (string)first_value($d, ['accountNo','bankAccountNo','cardNo','upiId','address'], '');
    $bankCode = (string)first_value($d, ['bankCode','ifsc','ifscCode'], '');
    if ($bankCode !== '') return 'BankCard';
    if (strpos($account, '@') !== false) return 'UPI';
    return 'BankCard';
}

function admin_audit_log(string $action, string $targetType = '', int $targetId = 0, array $data = [], int $adminUserId = 0): void
{
    $conn = db();
    if (!$conn) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = @$conn->prepare('INSERT INTO admin_audit_logs(admin_user_id, action, target_type, target_id, ip, user_agent, data_json, created_at) VALUES(?,?,?,?,?,?,?,NOW())');
    if ($stmt) { $stmt->bind_param('ississs', $adminUserId, $action, $targetType, $targetId, $ip, $ua, $json); $stmt->execute(); }
}

function bank_code_options(string $type = 'BankCard'): array
{
    $type = normalize_withdraw_type($type);
    if ($type === 'USDT') {
        return [
            ['name'=>'TRC20', 'code'=>'TRC20', 'walletType'=>'USDT', 'walletName'=>'TRC20'],
        ];
    }
    if ($type === 'UPI') {
        return [
            ['name'=>'UPI', 'code'=>'UPI', 'walletType'=>'UPI', 'walletName'=>'UPI'],
        ];
    }
    if ($type === 'EWallet') {
        return [
            ['name'=>'EWallet', 'code'=>'EWALLET', 'walletType'=>'EWallet', 'walletName'=>'EWallet'],
        ];
    }
    return [
        ['name'=>'State Bank of India', 'code'=>'SBIN', 'walletType'=>'BankCard', 'walletName'=>'State Bank of India'],
        ['name'=>'Punjab National Bank', 'code'=>'PUNB', 'walletType'=>'BankCard', 'walletName'=>'Punjab National Bank'],
        ['name'=>'HDFC Bank', 'code'=>'HDFC', 'walletType'=>'BankCard', 'walletName'=>'HDFC Bank'],
        ['name'=>'ICICI Bank', 'code'=>'ICIC', 'walletType'=>'BankCard', 'walletName'=>'ICICI Bank'],
        ['name'=>'Axis Bank', 'code'=>'UTIB', 'walletType'=>'BankCard', 'walletName'=>'Axis Bank'],
        ['name'=>'Kotak Mahindra Bank', 'code'=>'KKBK', 'walletType'=>'BankCard', 'walletName'=>'Kotak Mahindra Bank'],
        ['name'=>'Bank of Baroda', 'code'=>'BARB', 'walletType'=>'BankCard', 'walletName'=>'Bank of Baroda'],
        ['name'=>'Canara Bank', 'code'=>'CNRB', 'walletType'=>'BankCard', 'walletName'=>'Canara Bank'],
        ['name'=>'Union Bank of India', 'code'=>'UBIN', 'walletType'=>'BankCard', 'walletName'=>'Union Bank of India'],
        ['name'=>'Indian Bank', 'code'=>'IDIB', 'walletType'=>'BankCard', 'walletName'=>'Indian Bank'],
        ['name'=>'Yes Bank', 'code'=>'YESB', 'walletType'=>'BankCard', 'walletName'=>'Yes Bank'],
        ['name'=>'IDFC FIRST Bank', 'code'=>'IDFB', 'walletType'=>'BankCard', 'walletName'=>'IDFC FIRST Bank'],
        ['name'=>'Airtel Payments Bank', 'code'=>'AIRP', 'walletType'=>'BankCard', 'walletName'=>'Airtel Payments Bank'],
        ['name'=>'Paytm Payments Bank', 'code'=>'PYTM', 'walletType'=>'BankCard', 'walletName'=>'Paytm Payments Bank'],
        ['name'=>'Other Bank', 'code'=>'OTHER', 'walletType'=>'BankCard', 'walletName'=>'Other Bank'],
    ];
}

function bank_name_by_code(string $code): string
{
    $code = strtoupper(trim($code));
    foreach (bank_code_options('BankCard') as $row) {
        if (strtoupper((string)$row['code']) === $code) return (string)$row['name'];
    }
    if ($code === 'TRC20') return 'TRC20';
    if ($code === 'UPI') return 'UPI';
    return $code;
}

function valid_upi_id(string $upi): bool
{
    return (bool)preg_match('/^[a-zA-Z0-9._\-]{2,64}@[a-zA-Z]{2,32}$/', $upi);
}

function normalize_account_no(string $account): string
{
    return strtolower(preg_replace('/\s+/', '', trim($account)));
}

function maybe_user(): array
{
    $conn = db();
    $token = bearer_token();
    $payload = parse_token($token);
    if ($conn && $payload && (int)($payload['uid'] ?? 0) > 0) {
        $uid = (int)$payload['uid'];
        $stmt = @$conn->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row && (int)($row['status'] ?? 1) === 1) return $row;
        }
    }
    return demo_user();
}

function json_setting(string $key, array $default = []): array
{
    $conn = db();
    if (!$conn) return $default;
    return v12_get_setting_json($conn, $key, $default);
}

function save_json_setting(string $key, array $data): void
{
    $conn = db();
    if ($conn) v12_save_setting_json($conn, $key, $data);
}

function demo_user(): array
{
    return [
        'id' => 117224,
        'tenant_user_id' => '60070000117224',
        'username' => '919119098026',
        'mobile' => '919119098026',
        'email' => '',
        'nickname' => 'MemberNNGI5P66',
        'photo' => '1',
        'balance' => '5000.00',
        'safe_box' => '0.00',
        'vip_level' => 1,
        'invite_code' => '37L3UFN',
        'role' => 'user',
        'status' => 1,
    ];
}

function first_value(array $data, array $keys, $default = '')
{
    foreach ($keys as $k) {
        if (isset($data[$k]) && $data[$k] !== '') return $data[$k];
    }
    return $default;
}

function user_response(array $u): array
{
    return [
        'userId' => (int)$u['id'],
        'nickName' => $u['nickname'] ?: ('Member' . $u['id']),
        'userPhoto' => (string)($u['photo'] ?: '1'),
        'userType' => ($u['role'] ?? '') === 'admin' ? 1 : 0,
        'lastLoginTime' => now_ms(),
        'isOpenVip' => true,
        'vipLevel' => (int)($u['vip_level'] ?? 1),
        'rechargeLevel' => 1,
        'walletBalance' => (float)($u['balance'] ?? 0),
        'safeBoxAmount' => (float)($u['safe_box'] ?? 0),
        'boolAttr' => 132611,
        'hasNoReadMessage' => false,
        'registerType' => 1,
        'verifyMethods' => ['email' => $u['email'] ?? '', 'phone' => $u['mobile'] ?? $u['username'], 'google' => '0'],
        'bindGoogleVerifyMethod' => 2,
        'lastLoginSysLanguage' => 'en',
        'inviteCode' => $u['invite_code'] ?? '37L3UFN',
        'yesterdayRebateAmount' => 0.0,
        'userUnGrandMsgCount' => 0,
        'userUnreadInmailCount' => 0,
        'userUnreceiveInmailRewardCount' => 0,
        'canSetPassword' => false,
        'isShowL3ReceiveCommission' => false,
        'lossReliefConfigIds' => '',
        'hasReceivedOpenPushGuideReward' => false,
    ];
}

function lottery_issue(string $gameCode): array
{
    $interval = le_game_interval($gameCode);
    $now = time();
    $dt = new DateTime('@' . $now);
    $dt->setTimezone(new DateTimeZone('UTC'));
    $ymd = $dt->format('Ymd');
    $sec = (int)$dt->format('H') * 3600 + (int)$dt->format('i') * 60 + (int)$dt->format('s');
    
    $periodIdx = intdiv($sec, $interval) + 1;
    $dayStart = strtotime($ymd . ' 00:00:00 UTC');
    $startSec = $dayStart + ($periodIdx - 1) * $interval;
    $endSec = $startSec + $interval;
    
    $curIssue = function_exists('le_calc_issue_number') ? le_calc_issue_number($gameCode, $now) : le_issue_by_offset($gameCode, 0);
    $prevIssue = function_exists('le_calc_issue_number') ? le_calc_issue_number($gameCode, $now - $interval) : le_issue_by_offset($gameCode, 1);
    $nextIssue = function_exists('le_calc_issue_number') ? le_calc_issue_number($gameCode, $now + $interval) : le_issue_by_offset($gameCode, -1);
    
    $start = $startSec * 1000;
    $end = $endSec * 1000;
    $nowMs = $now * 1000;
    $countdown = max(0, $endSec - $now);
    $intervalMinute = round($interval / 60, 2);

    $previous = [
        'issueNumber' => $prevIssue,
        'startTime' => $start - ($interval * 1000),
        'endTime' => $start,
    ];
    $current = [
        'issueNumber' => $curIssue,
        'startTime' => $start,
        'endTime' => $end,
    ];
    $next = [
        'issueNumber' => $nextIssue,
        'startTime' => $end,
        'endTime' => $end + ($interval * 1000),
        'countdown' => $interval,
    ];

    $result = [
        'gameCode' => $gameCode,
        'intervalMinute' => $intervalMinute,
        'state' => 1,
        'previous' => $previous,
        'current' => $current,
        'next' => $next,
        'startTime' => $start,
        'endTime' => $end,
        'issueNumber' => $curIssue,
        'intervalSecond' => $interval,
        'duration' => $interval,
        'diif' => 0,
        'countdown' => $countdown,
        'remainTime' => $countdown,
        'timeRemaining' => $countdown,
        'currentTime' => $nowMs,
        'serviceTime' => $nowMs,
        'serverTime' => $nowMs,
        'isOpen' => true,
    ];

    $reference = mw_fetch_reference_issue($gameCode);
    if ($reference) {
        if (!empty($reference['issueNumber'])) {
            $result['issueNumber'] = (string)$reference['issueNumber'];
            $result['current']['issueNumber'] = (string)$reference['issueNumber'];
        }
        if (!empty($reference['startTime'])) {
            $result['startTime'] = (int)$reference['startTime'];
            $result['current']['startTime'] = (int)$reference['startTime'];
        }
        if (!empty($reference['endTime'])) {
            $result['endTime'] = (int)$reference['endTime'];
            $result['current']['endTime'] = (int)$reference['endTime'];
            $result['countdown'] = max(0, (int)ceil(((int)$reference['endTime'] - now_ms()) / 1000));
            $result['remainTime'] = $result['countdown'];
            $result['timeRemaining'] = $result['countdown'];
        }
    }
    return $result;
}

function random_premium(string $gameCode): string
{
    return le_random_premium($gameCode);
}

function game_name_from_code(string $code): string
{
    $map = [
        'WinGo_30S' => 'WinGo 30sec', 'WinGo_1M' => 'WinGo 1 Min', 'WinGo_3M' => 'WinGo 3 Min', 'WinGo_5M' => 'WinGo 5 Min', 'WinGo_1Min' => 'WinGo 1 Min', 'WinGo_3Min' => 'WinGo 3 Min', 'WinGo_5Min' => 'WinGo 5 Min',
        'K3_1M' => 'K3 1 Min', 'K3_3M' => 'K3 3 Min', 'K3_5M' => 'K3 5 Min', 'K3_10M' => 'K3 10 Min', 'K3_1Min' => 'K3 1 Min', 'K3_3Min' => 'K3 3 Min', 'K3_5Min' => 'K3 5 Min', 'K3_10Min' => 'K3 10 Min', 'D5_1M' => '5D 1 Min', 'D5_3M' => '5D 3 Min', 'D5_5M' => '5D 5 Min', 'D5_10M' => '5D 10 Min', '5D_1Min' => '5D 1 Min', '5D_3Min' => '5D 3 Min', '5D_5Min' => '5D 5 Min', '5D_10Min' => '5D 10 Min', 'MotoRace_1M' => 'Moto Racing', 'MotoRace_1Min' => 'Moto Racing', 'TrxWinGo_1M' => 'Trx WinGo 1 Min', 'TrxWinGo_3M' => 'Trx WinGo 3 Min', 'TrxWinGo_5M' => 'Trx WinGo 5 Min', 'TrxWinGo_10M' => 'Trx WinGo 10 Min', 'TrxWinGo_1Min' => 'Trx WinGo 1 Min', 'TrxWinGo_3Min' => 'Trx WinGo 3 Min', 'TrxWinGo_5Min' => 'Trx WinGo 5 Min', 'TrxWinGo_10Min' => 'Trx WinGo 10 Min',
    ];
    return $map[$code] ?? $code;
}

function api_success_no_data(string $msg = 'Succeed', array $extra = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    $out = array_merge([
        'code' => 0,
        'msg' => $msg,
        'msgCode' => 0,
        'serverTime' => now_ms(),
    ], $extra);
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ensure_database(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $version = (defined('AUTO_INSTALL_VERSION') ? AUTO_INSTALL_VERSION : 'v1') . '_support_result_20261017_v10';
    $hasSettings = @$conn->query("SELECT setting_value FROM settings WHERE setting_key='auto_install_version' LIMIT 1");
    if ($hasSettings instanceof mysqli_result) {
        $row = $hasSettings->fetch_assoc();
        // AUTO_INSTALL_VERSION is the migration contract. Re-running every
        // CREATE/ALTER/index probe on each API request makes login and draw
        // history block behind metadata locks on shared cPanel hosting.
        if (($row['setting_value'] ?? '') === $version) {
            return;
        }
    }

    $schema = get_auto_install_sql($version);
    foreach (explode(";\n", $schema) as $sql) {
        $sql = trim($sql);
        if ($sql === '') continue;
        if (!@$conn->query($sql)) {
            error_log('AUTO_INSTALL SQL failed: ' . $conn->error . ' SQL=' . substr($sql, 0, 300));
        }
    }
    ensure_lottery_columns($conn);
    ensure_bonus_vip_tables($conn);
    ensure_v12_tables($conn);
    ensure_v13_tables($conn);
    ensure_v14_tables($conn);
    ensure_v19_tables($conn);
    ensure_v22_tables($conn);
    ensure_v24_tables($conn);
    ensure_v32_wallet_tables($conn);
    ensure_v33_tables($conn);
    ensure_v34_tables($conn);

    // V7 payment/admin self-heal: verify the small set of schema pieces used by
    // admin login and UTR review before marking the migration complete.
    $criticalReady = ensure_payment_admin_critical_schema_v7($conn);
    if ($criticalReady) {
        $stmt = @$conn->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('auto_install_version',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        if ($stmt) { $stmt->bind_param('s', $version); $stmt->execute(); }
    } else {
        error_log('AUTO_INSTALL V7 critical schema verification failed; migration will retry on the next request.');
    }
}

function ensure_payment_admin_critical_schema_v7(mysqli $conn): bool
{
    // These CREATE/ALTER operations are idempotent. They let an old/partial cPanel
    // database repair itself without a manual SQL import.
    @$conn->query("CREATE TABLE IF NOT EXISTS settings (setting_key VARCHAR(120) NOT NULL, setting_value LONGTEXT, PRIMARY KEY(setting_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    @$conn->query("CREATE TABLE IF NOT EXISTS admin_login_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, admin_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, username VARCHAR(120) NOT NULL, ip VARCHAR(80) DEFAULT '', user_agent VARCHAR(255) DEFAULT '', was_successful TINYINT NOT NULL DEFAULT 0, failure_reason VARCHAR(190) DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), KEY idx_admin_login_user_time(username,created_at), KEY idx_admin_login_ip_time(ip,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @$conn->query("CREATE TABLE IF NOT EXISTS recharge_orders (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, user_id BIGINT UNSIGNED NOT NULL, order_no VARCHAR(80) NOT NULL, recharge_category_id BIGINT DEFAULT NULL, channel_name VARCHAR(120) DEFAULT '', recharge_type VARCHAR(80) DEFAULT '', amount DECIMAL(18,2) NOT NULL DEFAULT 0.00, gift_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00, pay_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00, status ENUM('Wait','PendingReview','Payed','Cancel') NOT NULL DEFAULT 'Wait', utr VARCHAR(120) DEFAULT '', utr_submit_at DATETIME DEFAULT NULL, user_submit_note VARCHAR(255) DEFAULT '', payment_proof VARCHAR(500) DEFAULT '', admin_note VARCHAR(255) DEFAULT '', raw_data LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT NULL, customer_info LONGTEXT DEFAULT NULL, recharge_info LONGTEXT DEFAULT NULL, PRIMARY KEY(id), UNIQUE KEY uq_recharge_order(order_no), KEY idx_recharge_user(user_id), KEY idx_recharge_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    @$conn->query("CREATE TABLE IF NOT EXISTS admin_permissions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, admin_user VARCHAR(120) NOT NULL, permissions LONGTEXT DEFAULT NULL, status TINYINT NOT NULL DEFAULT 1, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT NULL, PRIMARY KEY(id), UNIQUE KEY uq_admin_user(admin_user)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    @$conn->query("CREATE TABLE IF NOT EXISTS admin_audit_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, admin_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, action VARCHAR(120) NOT NULL, target_type VARCHAR(80) DEFAULT '', target_id BIGINT UNSIGNED NOT NULL DEFAULT 0, ip VARCHAR(80) DEFAULT '', user_agent VARCHAR(255) DEFAULT '', data_json LONGTEXT DEFAULT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), KEY idx_action_created(action,created_at), KEY idx_target(target_type,target_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    wallet_ensure_column($conn, 'users', 'must_change_password', "ALTER TABLE users ADD COLUMN must_change_password TINYINT NOT NULL DEFAULT 0 AFTER status");
    wallet_ensure_column($conn, 'recharge_orders', 'utr', "ALTER TABLE recharge_orders ADD COLUMN utr VARCHAR(120) DEFAULT ''");
    wallet_ensure_column($conn, 'recharge_orders', 'utr_submit_at', "ALTER TABLE recharge_orders ADD COLUMN utr_submit_at DATETIME DEFAULT NULL");
    wallet_ensure_column($conn, 'recharge_orders', 'user_submit_note', "ALTER TABLE recharge_orders ADD COLUMN user_submit_note VARCHAR(255) DEFAULT ''");
    wallet_ensure_column($conn, 'recharge_orders', 'payment_proof', "ALTER TABLE recharge_orders ADD COLUMN payment_proof VARCHAR(500) DEFAULT ''");
    wallet_ensure_column($conn, 'recharge_orders', 'raw_data', "ALTER TABLE recharge_orders ADD COLUMN raw_data LONGTEXT");
    wallet_ensure_column($conn, 'recharge_orders', 'updated_at', "ALTER TABLE recharge_orders ADD COLUMN updated_at DATETIME DEFAULT NULL");

    $required = [
        ['users','role'], ['users','status'], ['users','password_hash'], ['users','must_change_password'],
        ['recharge_orders','status'], ['recharge_orders','utr'], ['recharge_orders','utr_submit_at'],
        ['recharge_orders','user_submit_note'], ['recharge_orders','payment_proof'], ['recharge_orders','raw_data'],
    ];
    foreach ($required as [$table,$column]) {
        $tableSafe = str_replace('`', '', $table);
        $columnEsc = $conn->real_escape_string($column);
        $res = @$conn->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnEsc}'");
        if (!($res instanceof mysqli_result) || $res->num_rows < 1) return false;
    }
    return true;
}

function lottery_public_result(string $gameCode, string $issueNumber, array $result, int $openTimeMs): array
{
    $number = (string)($result['number'] ?? '');
    $premium = le_is_k3($gameCode) ? $number : (string)($result['premium'] ?? $number);
    $hash = hash('sha256', $gameCode.'|'.$issueNumber.'|'.$premium);
    $block = 84600000 + ((int)substr(preg_replace('/\D+/', '', $issueNumber), -7) % 900000);
    return [
        'issueNumber'=>$issueNumber,
        'issueNo'=>$issueNumber,
        'period'=>$issueNumber,
        'gameCode'=>$gameCode,
        'premium'=>$premium,
        'number'=>$number,
        'result'=>$number,
        'dice'=>le_is_k3($gameCode) ? array_map('intval', str_split($number)) : ($result['dice'] ?? []),
        'color'=>(string)($result['color'] ?? ''),
        'colour'=>(string)($result['color'] ?? ''),
        'bigSmall'=>(string)($result['bigSmall'] ?? ''),
        'sum'=>(int)($result['sum'] ?? 0),
        'openTime'=>$openTimeMs,
        'block'=>$block,
        'blockNumber'=>$block,
        'blockTime'=>$openTimeMs,
        'blockTimeText'=>date('H:i:s', (int)floor($openTimeMs / 1000)),
        'hash'=>$hash,
        'hashValue'=>$hash,
    ];
}




function ensure_v19_tables(mysqli $conn): void
{
    @ $conn->query("CREATE TABLE IF NOT EXISTS recharge_wheel_records (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      order_no VARCHAR(80) NOT NULL,
      recharge_wheel_type TINYINT NOT NULL DEFAULT 1,
      reward_type TINYINT NOT NULL DEFAULT 1,
      reward_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_recharge_wheel_order (order_no),
      KEY idx_recharge_wheel_user (user_id),
      KEY idx_recharge_wheel_type (recharge_wheel_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $default = [
        'enabled' => true,
        'rewardUpAmount' => 20000,
        'specialWheelUnlockAmount' => 50000,
        'noticeList' => [
            ['message' => 'Me**H win ₹10'],
            ['message' => 'Get free spins after deposit'],
            ['message' => 'Complete deposit task to unlock wheel rewards']
        ],
        'wheels' => [
            1 => ['name'=>'Silver Spin','label'=>'silver','remainSpinCount'=>0,'tasks'=>[[15000,2],[30000,2],[50000,2]],'rewards'=>[218,888,588,2888,128,388,688,188]],
            2 => ['name'=>'Gold Spin','label'=>'gold','remainSpinCount'=>0,'tasks'=>[[15000,2],[30000,2],[50000,2]],'rewards'=>[599,1888,8888,199,388,777,999,2999]],
            3 => ['name'=>'Diamond Spin','label'=>'diamond','remainSpinCount'=>0,'tasks'=>[[100000,3],[300000,3],[500000,3]],'rewards'=>[999,2999,9999,1999,399,667,888,4999]],
            4 => ['name'=>'Special Spin','label'=>'special','remainSpinCount'=>0,'tasks'=>[[500000,5],[1000000,5],[2000000,5]],'rewards'=>[1999,9999,20000,5000,888,1888,7777,2999]],
        ],
    ];
    $key = 'recharge_wheel_config';
    $rs = @ $conn->query("SELECT setting_value FROM settings WHERE setting_key='" . $conn->real_escape_string($key) . "' LIMIT 1");
    if (!($rs instanceof mysqli_result) || $rs->num_rows === 0) {
        $json = $conn->real_escape_string(json_encode($default, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @ $conn->query("INSERT INTO settings(setting_key, setting_value) VALUES('{$key}','{$json}')");
    }
}


// ===============================
// V22: admin finance filters, recharge wheel requirement, admin permissions
// ===============================
function ensure_v22_tables(mysqli $conn): void
{
    @ $conn->query("CREATE TABLE IF NOT EXISTS admin_permissions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      admin_user VARCHAR(120) NOT NULL,
      permissions LONGTEXT,
      status TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT NULL,
      PRIMARY KEY(id),
      UNIQUE KEY uq_admin_user(admin_user)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @ $conn->query("CREATE TABLE IF NOT EXISTS site_operation_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      admin_user VARCHAR(120) DEFAULT '',
      module_name VARCHAR(80) DEFAULT '',
      action_name VARCHAR(120) DEFAULT '',
      target_id VARCHAR(120) DEFAULT '',
      detail TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_module_time(module_name, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @ $conn->query("CREATE TABLE IF NOT EXISTS license_popup_cache (
      id TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
      last_message TEXT,
      last_payload LONGTEXT,
      show_until DATETIME DEFAULT NULL,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $site = v12_get_setting_json($conn, 'site_settings', v13_default_site_settings());
    $site = array_merge([
      'result_history_enabled' => true,
      'result_history_limit' => 10,
      'recharge_history_filter_enabled' => true,
      'withdraw_history_filter_enabled' => true,
      'recharge_wheel_need_enabled' => true,
      'recharge_wheel_need_amount' => 100.00,
      'license_popup_enabled' => false
    ], $site);
    v12_save_setting_json($conn, 'site_settings', $site);

    $rw = v12_get_setting_json($conn, 'recharge_wheel_config', ['enabled'=>true,'rewardUpAmount'=>20000,'specialWheelUnlockAmount'=>50000,'wheels'=>[]]);
    $rw = array_replace_recursive([
      'requireApprovedRecharge' => true,
      'needRechargeAmount' => 100.00,
      'historyLimit' => 10,
      'adminNote' => 'User must complete approved recharge before wheel spin.',
    ], $rw);
    v12_save_setting_json($conn, 'recharge_wheel_config', $rw);

    $rs = @$conn->query("SELECT COUNT(*) c FROM admin_permissions WHERE admin_user='admin'");
    $cnt = $rs ? (int)($rs->fetch_assoc()['c'] ?? 0) : 0;
    if ($cnt === 0) {
        $perms = json_encode(['*'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $stmt = @$conn->prepare("INSERT INTO admin_permissions(admin_user,permissions,status,created_at) VALUES('admin',?,1,NOW())");
        if ($stmt) { $stmt->bind_param('s', $perms); $stmt->execute(); }
    }
}

function admin_permissions_for(string $adminUser): array
{
    $conn = db();
    if (!$conn || $adminUser === 'admin') return ['*'];
    $stmt = @$conn->prepare('SELECT permissions,status FROM admin_permissions WHERE admin_user=? LIMIT 1');
    if (!$stmt) return [];
    $stmt->bind_param('s', $adminUser); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || (int)($row['status'] ?? 1) !== 1) return [];
    $p = json_decode((string)($row['permissions'] ?? '[]'), true);
    return is_array($p) ? $p : [];
}

function admin_can_access_tab(string $adminUser, string $tab): bool
{
    $perms = admin_permissions_for($adminUser);
    return in_array('*', $perms, true) || in_array($tab, $perms, true);
}

function site_log(string $module, string $action, string $target='', string $detail=''): void
{
    $conn = db(); if (!$conn) return;
    $admin = $_SESSION['admin_user'] ?? 'system';
    $stmt = @$conn->prepare('INSERT INTO site_operation_logs(admin_user,module_name,action_name,target_id,detail,created_at) VALUES(?,?,?,?,?,NOW())');
    if ($stmt) { $stmt->bind_param('sssss', $admin, $module, $action, $target, $detail); $stmt->execute(); }
}

function ensure_lottery_columns(mysqli $conn): void
{
    @$conn->query("CREATE TABLE IF NOT EXISTS lottery_results (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      game_code VARCHAR(60) NOT NULL,
      issue_number VARCHAR(60) NOT NULL,
      premium VARCHAR(120) NOT NULL,
      number VARCHAR(120) DEFAULT '',
      color VARCHAR(80) DEFAULT '',
      big_small VARCHAR(20) DEFAULT '',
      sum_value INT DEFAULT 0,
      open_time DATETIME DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_result (game_code, issue_number),
      KEY idx_result_game (game_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @$conn->query("CREATE TABLE IF NOT EXISTS lottery_bets (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      order_no VARCHAR(80) NOT NULL,
      game_code VARCHAR(60) NOT NULL,
      issue_number VARCHAR(60) NOT NULL,
      bet_content VARCHAR(255) NOT NULL,
      amount DECIMAL(18,2) NOT NULL,
      bet_multiple INT NOT NULL DEFAULT 1,
      real_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      fee DECIMAL(18,2) NOT NULL DEFAULT 0,
      premium VARCHAR(80) DEFAULT '',
      state TINYINT NOT NULL DEFAULT 2,
      win_lose_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_bet_user (user_id),
      KEY idx_bet_issue (issue_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    @$conn->query("CREATE TABLE IF NOT EXISTS financial_records (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      record_no VARCHAR(80) NOT NULL,
      order_no VARCHAR(80) NOT NULL,
      vendor_code VARCHAR(80) DEFAULT '',
      type VARCHAR(80) NOT NULL,
      sub_type VARCHAR(80) DEFAULT '',
      amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      back_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      remark VARCHAR(255) DEFAULT '',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_fin_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $checks = [
        "fee" => "ALTER TABLE lottery_bets ADD COLUMN fee DECIMAL(18,2) NOT NULL DEFAULT 0",
        "premium" => "ALTER TABLE lottery_bets ADD COLUMN premium VARCHAR(80) DEFAULT ''",
        "state" => "ALTER TABLE lottery_bets ADD COLUMN state TINYINT NOT NULL DEFAULT 2",
        "win_lose_amount" => "ALTER TABLE lottery_bets ADD COLUMN win_lose_amount DECIMAL(18,2) NOT NULL DEFAULT 0",
    ];
    foreach ($checks as $col => $sql) {
        $res = @$conn->query("SHOW COLUMNS FROM lottery_bets LIKE '" . $conn->real_escape_string($col) . "'");
        if ($res instanceof mysqli_result && $res->num_rows > 0) continue;
        @$conn->query($sql);
    }

    // Repair old result schemas in place. Never drop the result table or erase history.
    $resultChecks = [
        "game_code" => "ALTER TABLE lottery_results ADD COLUMN game_code VARCHAR(60) NOT NULL DEFAULT ''",
        "issue_number" => "ALTER TABLE lottery_results ADD COLUMN issue_number VARCHAR(60) NOT NULL DEFAULT ''",
        "premium" => "ALTER TABLE lottery_results ADD COLUMN premium VARCHAR(120) NOT NULL DEFAULT ''",
        "number" => "ALTER TABLE lottery_results ADD COLUMN number VARCHAR(120) DEFAULT ''",
        "color" => "ALTER TABLE lottery_results ADD COLUMN color VARCHAR(80) DEFAULT ''",
        "big_small" => "ALTER TABLE lottery_results ADD COLUMN big_small VARCHAR(20) DEFAULT ''",
        "sum_value" => "ALTER TABLE lottery_results ADD COLUMN sum_value INT DEFAULT 0",
        "open_time" => "ALTER TABLE lottery_results ADD COLUMN open_time DATETIME DEFAULT NULL",
        "created_at" => "ALTER TABLE lottery_results ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($resultChecks as $col => $sql) {
        $res = @$conn->query("SHOW COLUMNS FROM lottery_results LIKE '" . $conn->real_escape_string($col) . "'");
        if ($res instanceof mysqli_result && $res->num_rows > 0) continue;
        @$conn->query($sql);
    }
    $idx = @$conn->query("SHOW INDEX FROM lottery_bets WHERE Key_name='idx_bet_issue'");
    if (!($idx instanceof mysqli_result) || $idx->num_rows === 0) { @$conn->query("ALTER TABLE lottery_bets ADD INDEX idx_bet_issue (issue_number)"); }
    $resultIdx = @$conn->query("SHOW INDEX FROM lottery_results WHERE Key_name='idx_result_game'");
    if (!($resultIdx instanceof mysqli_result) || $resultIdx->num_rows === 0) { @$conn->query("ALTER TABLE lottery_results ADD INDEX idx_result_game (game_code)"); }

    $uniqueIndexes = [];
    $resultIndexes = @$conn->query('SHOW INDEX FROM lottery_results');
    if ($resultIndexes instanceof mysqli_result) {
        while ($index = $resultIndexes->fetch_assoc()) {
            if ((int)($index['Non_unique'] ?? 1) !== 0 || ($index['Key_name'] ?? '') === 'PRIMARY') continue;
            $uniqueIndexes[(string)$index['Key_name']][(int)$index['Seq_in_index']] = (string)$index['Column_name'];
        }
    }
    $hasGameIssueUnique = false;
    foreach ($uniqueIndexes as $columns) {
        ksort($columns);
        if (array_values($columns) === ['game_code', 'issue_number']) { $hasGameIssueUnique = true; break; }
    }
    if (!$hasGameIssueUnique) {
        // Keep the newest row: an admin override is inserted after any automatic/reference result.
        @$conn->query('DELETE older FROM lottery_results older INNER JOIN lottery_results newer ON older.game_code=newer.game_code AND older.issue_number=newer.issue_number AND older.id<newer.id');
        @$conn->query('ALTER TABLE lottery_results ADD UNIQUE KEY uq_game_issue (game_code,issue_number)');
    }
}


function ensure_bonus_vip_tables(mysqli $conn): void
{
    $sqls = [];
    $sqls[] = "CREATE TABLE IF NOT EXISTS recharge_orders (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      order_no VARCHAR(80) NOT NULL,
      recharge_category_id BIGINT DEFAULT NULL,
      channel_name VARCHAR(120) DEFAULT '',
      recharge_type VARCHAR(80) DEFAULT '',
      amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      gift_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      pay_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      status ENUM('Wait','PendingReview','Payed','Cancel') NOT NULL DEFAULT 'Wait',
      utr VARCHAR(120) DEFAULT '',
      admin_note VARCHAR(255) DEFAULT '',
      raw_data LONGTEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT NULL,
      PRIMARY KEY(id),
      UNIQUE KEY uq_recharge_order (order_no),
      KEY idx_recharge_user (user_id),
      KEY idx_recharge_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS vip_levels (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      level INT NOT NULL,
      name VARCHAR(80) NOT NULL,
      deposit_required DECIMAL(18,2) NOT NULL DEFAULT 0,
      bet_required DECIMAL(18,2) NOT NULL DEFAULT 0,
      level_reward DECIMAL(18,2) NOT NULL DEFAULT 0,
      weekly_reward DECIMAL(18,2) NOT NULL DEFAULT 0,
      monthly_reward DECIMAL(18,2) NOT NULL DEFAULT 0,
      icon_url VARCHAR(255) DEFAULT '',
      status TINYINT NOT NULL DEFAULT 1,
      PRIMARY KEY(id),
      UNIQUE KEY uq_vip_level (level)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS vip_rewards (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      level INT NOT NULL,
      reward_type VARCHAR(40) NOT NULL,
      amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      status VARCHAR(30) NOT NULL DEFAULT 'Received',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_vip_reward (user_id, level, reward_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS activity_tasks (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      code VARCHAR(80) NOT NULL,
      title VARCHAR(190) NOT NULL,
      reward DECIMAL(18,2) NOT NULL DEFAULT 0,
      task_type VARCHAR(60) DEFAULT 'manual',
      target_value DECIMAL(18,2) NOT NULL DEFAULT 1,
      jump_url VARCHAR(500) DEFAULT '',
      sort INT NOT NULL DEFAULT 0,
      status TINYINT NOT NULL DEFAULT 1,
      PRIMARY KEY(id),
      UNIQUE KEY uq_activity_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS user_activity_tasks (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      task_code VARCHAR(80) NOT NULL,
      progress DECIMAL(18,2) NOT NULL DEFAULT 0,
      completed TINYINT NOT NULL DEFAULT 0,
      claimed TINYINT NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT NULL,
      PRIMARY KEY(id),
      UNIQUE KEY uq_user_task (user_id, task_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS gift_codes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      code VARCHAR(80) NOT NULL,
      amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      max_claim INT NOT NULL DEFAULT 1,
      claimed_count INT NOT NULL DEFAULT 0,
      expires_at DATETIME DEFAULT NULL,
      status TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_gift_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS gift_code_claims (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      gift_code_id BIGINT UNSIGNED NOT NULL,
      user_id BIGINT UNSIGNED NOT NULL,
      amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_gift_user (gift_code_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS notifications (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      title VARCHAR(190) NOT NULL,
      content TEXT,
      type VARCHAR(50) NOT NULL DEFAULT 'notice',
      jump_url VARCHAR(500) DEFAULT '',
      status TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_notice_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS user_login_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      ip VARCHAR(80) DEFAULT '',
      user_agent VARCHAR(255) DEFAULT '',
      device_id VARCHAR(120) DEFAULT '',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_ip (ip),
      KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS agent_salary_records (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      agent_user_id BIGINT UNSIGNED NOT NULL,
      period_date DATE NOT NULL,
      team_deposit DECIMAL(18,2) NOT NULL DEFAULT 0,
      team_bet DECIMAL(18,2) NOT NULL DEFAULT 0,
      salary_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_agent_salary (agent_user_id, period_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS admin_audit_log (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      admin_user VARCHAR(120) DEFAULT '',
      action VARCHAR(120) NOT NULL,
      target VARCHAR(190) DEFAULT '',
      detail TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sqls[] = "CREATE TABLE IF NOT EXISTS payment_methods (
      id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(120) NOT NULL,
      recharge_type VARCHAR(60) NOT NULL DEFAULT 'UPI',
      state TINYINT NOT NULL DEFAULT 1,
      sort INT NOT NULL DEFAULT 0,
      icon_url VARCHAR(255) DEFAULT '',
      selected_icon_url VARCHAR(255) DEFAULT '',
      rate DECIMAL(18,4) NOT NULL DEFAULT 1,
      min_amount DECIMAL(18,2) NOT NULL DEFAULT 100,
      max_amount DECIMAL(18,2) NOT NULL DEFAULT 50000,
      gift_ratio DECIMAL(10,2) NOT NULL DEFAULT 2,
      quick_config_json LONGTEXT,
      upi_id VARCHAR(190) DEFAULT '',
      upi_name VARCHAR(190) DEFAULT '',
      qr_image VARCHAR(255) DEFAULT '',
      note VARCHAR(255) DEFAULT '',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT NULL,
      PRIMARY KEY(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    foreach ($sqls as $sql) { if (!@$conn->query($sql)) error_log('extra table sql failed: '.$conn->error); }

    $columns = [
      'users' => [
        'agent_parent_id' => "ALTER TABLE users ADD COLUMN agent_parent_id BIGINT UNSIGNED DEFAULT NULL",
        'is_agent' => "ALTER TABLE users ADD COLUMN is_agent TINYINT NOT NULL DEFAULT 0",
        'agent_salary' => "ALTER TABLE users ADD COLUMN agent_salary DECIMAL(18,2) NOT NULL DEFAULT 0",
        'ip_last' => "ALTER TABLE users ADD COLUMN ip_last VARCHAR(80) DEFAULT ''",
        'device_id' => "ALTER TABLE users ADD COLUMN device_id VARCHAR(120) DEFAULT ''",
        'total_deposit' => "ALTER TABLE users ADD COLUMN total_deposit DECIMAL(18,2) NOT NULL DEFAULT 0",
        'total_withdraw' => "ALTER TABLE users ADD COLUMN total_withdraw DECIMAL(18,2) NOT NULL DEFAULT 0",
        'total_bet' => "ALTER TABLE users ADD COLUMN total_bet DECIMAL(18,2) NOT NULL DEFAULT 0"
      ],
      'recharge_orders' => [
        'customer_info' => "ALTER TABLE recharge_orders ADD COLUMN customer_info LONGTEXT",
        'recharge_info' => "ALTER TABLE recharge_orders ADD COLUMN recharge_info LONGTEXT",
        'utr' => "ALTER TABLE recharge_orders ADD COLUMN utr VARCHAR(120) DEFAULT ''",
        'admin_note' => "ALTER TABLE recharge_orders ADD COLUMN admin_note VARCHAR(255) DEFAULT ''"
      ],
      'withdraw_requests' => [
        'admin_note' => "ALTER TABLE withdraw_requests ADD COLUMN admin_note VARCHAR(255) DEFAULT ''",
        'updated_at' => "ALTER TABLE withdraw_requests ADD COLUMN updated_at DATETIME DEFAULT NULL"
      ]
    ];
    foreach ($columns as $table => $cols) {
        foreach ($cols as $col => $sql) {
            $res = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '".$conn->real_escape_string($col)."'");
            if ($res instanceof mysqli_result && $res->num_rows > 0) continue;
            @ $conn->query($sql);
        }
    }

    $vipIcon = '/assets/vip/icon_VIP';
    for ($i=0; $i<=20; $i++) {
        $deposit = $i === 0 ? 0 : $i * 500;
        $bet = $i === 0 ? 0 : $i * 2500;
        $levelReward = $i === 0 ? 0 : max(10, $i * 10);
        $weekly = $i === 0 ? 0 : $i * 10;
        $monthly = $i === 0 ? 0 : $i * 50;
        $icon = $vipIcon . $i . '.webp';
        $stmt = @$conn->prepare('INSERT INTO vip_levels(level,name,deposit_required,bet_required,level_reward,weekly_reward,monthly_reward,icon_url,status) VALUES(?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name), deposit_required=VALUES(deposit_required), bet_required=VALUES(bet_required), level_reward=VALUES(level_reward), weekly_reward=VALUES(weekly_reward), monthly_reward=VALUES(monthly_reward), icon_url=VALUES(icon_url), status=1');
        if ($stmt) { $name = 'VIP'.$i; $stmt->bind_param('isddddds', $i, $name, $deposit, $bet, $levelReward, $weekly, $monthly, $icon); $stmt->execute(); }
    }
    $defaultTasks = [
        ['first_deposit','Complete first deposit',1.29,'deposit',1,'/wallet/recharge',10],
        ['first_withdraw','Complete first withdrawal',1.29,'withdraw',1,'/wallet/withdraw',9],
        ['join_telegram','Join the official channel',1.29,'telegram',1,'',8],
        ['second_deposit','Complete second deposit',1.29,'deposit_count',2,'/wallet/recharge',7],
    ];
    foreach ($defaultTasks as $t) {
        $stmt = @$conn->prepare('INSERT INTO activity_tasks(code,title,reward,task_type,target_value,jump_url,sort,status) VALUES(?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE title=VALUES(title), reward=VALUES(reward), task_type=VALUES(task_type), target_value=VALUES(target_value), jump_url=VALUES(jump_url), sort=VALUES(sort), status=1');
        if ($stmt) { $stmt->bind_param('ssdsdsi', $t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $t[6]); $stmt->execute(); }
    }
    @ $conn->query("INSERT IGNORE INTO notifications(title, content, type, jump_url, status) VALUES('Welcome to 9K.Club','Deposit, VIP and bonus system is active.','popup','',1)");

    $seedPaymentMethodsV10 = [
      [400088,'UPI-QR','UPI',45,'/img/6007/bankLogo/081619759-31176-file_20260414081619737.webp','/img/6007/bankLogo/081625196-31177-file_20260414081625174.webp',1,100,50000,2,'demo@ybl','9K.Club','/img/6007/bankLogo/081619759-31176-file_20260414081619737.webp','Pay using UPI QR, then submit UTR.'],
      [400084,'UPI*QR','UPI',40,'/img/6007/bankLogo/033005260-32516-file_20260422153005258.webp','/img/6007/bankLogo/033017566-32517-file_20260422153017564.webp',1,100,50000,2,'demo2@upi','9K.Club','/img/6007/bankLogo/033005260-32516-file_20260422153005258.webp','Pay exact amount only.'],
      [400086,'EWallet','BankCard',30,'/img/6007/bankLogo/081307018-31172-file_20260414081307017.webp','/img/6007/bankLogo/081312092-31173-file_20260414081312091.webp',1,100,50000,2,'wallet@upi','9K.Club EWallet','/img/6007/bankLogo/081307018-31172-file_20260414081307017.webp','Manual wallet payment.'],
      [400085,'Paytm*QR','BankCard',25,'/img/6007/bankLogo/032817571-32514-file_20260422152817570.webp','/img/6007/bankLogo/032830034-32515-file_20260422152830031.webp',1,100,5000,2,'paytm@upi','9K.Club Paytm','/img/6007/bankLogo/032817571-32514-file_20260422152817570.webp','Paytm QR payment.'],
      [400087,'USDT','USDT',10,'/img/6007/bankLogo/032547277-32512-file_20260422152547275.webp','/img/6007/bankLogo/032559385-32513-file_20260422152559383.webp',97,10,100000,2,'','USDT TRC20','','Contact admin for USDT address.'],
      [400095,'ARPAY','ARPay',5,'/img/6007/bankLogo/032439802-32510-file_20260422152439790.webp','/img/6007/bankLogo/032453856-32511-file_20260422152453843.webp',1,100,100000,2,'arpay@upi','9K.Club ARPay','/img/6007/bankLogo/032439802-32510-file_20260422152439790.webp','ARPay manual payment.']
    ];
    // One-time versioned migration: upgrade only the old bundled placeholder.
    // After this migration, normal seed upserts do not overwrite admin-edited UPI/QR values.
    @ $conn->query("UPDATE payment_methods SET upi_id='demo@ybl' WHERE id=400088 AND (upi_id='' OR upi_id='demo@upi')");

    foreach ($seedPaymentMethodsV10 as $pm) {
        $quick = json_encode([["rechargeAmount"=>100,"giftAmount"=>0],["rechargeAmount"=>300,"giftAmount"=>0],["rechargeAmount"=>500,"giftAmount"=>10],["rechargeAmount"=>1000,"giftAmount"=>30],["rechargeAmount"=>2000,"giftAmount"=>40],["rechargeAmount"=>3000,"giftAmount"=>50],["rechargeAmount"=>5000,"giftAmount"=>60],["rechargeAmount"=>8000,"giftAmount"=>80],["rechargeAmount"=>10000,"giftAmount"=>100],["rechargeAmount"=>20000,"giftAmount"=>200],["rechargeAmount"=>30000,"giftAmount"=>300],["rechargeAmount"=>50000,"giftAmount"=>500]], JSON_UNESCAPED_SLASHES);
        $stmt = @$conn->prepare('INSERT INTO payment_methods(id,name,recharge_type,sort,icon_url,selected_icon_url,rate,min_amount,max_amount,gift_ratio,quick_config_json,upi_id,upi_name,qr_image,note,state) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name), recharge_type=VALUES(recharge_type), sort=VALUES(sort), icon_url=VALUES(icon_url), selected_icon_url=VALUES(selected_icon_url), rate=VALUES(rate), min_amount=VALUES(min_amount), max_amount=VALUES(max_amount), gift_ratio=VALUES(gift_ratio), note=VALUES(note), state=1');
        if ($stmt) { $stmt->bind_param('ississddddsssss', $pm[0],$pm[1],$pm[2],$pm[3],$pm[4],$pm[5],$pm[6],$pm[7],$pm[8],$pm[9],$quick,$pm[10],$pm[11],$pm[12],$pm[13]); $stmt->execute(); }
    }
}



// ===============================
// V12: one-device session, user-isolated withdraw wallets, invited wheel settings
// ===============================
function ensure_v12_tables(mysqli $conn): void
{
    $sqls = [];
    $sqls[] = "CREATE TABLE IF NOT EXISTS withdraw_wallets (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      wallet_type VARCHAR(80) NOT NULL,
      wallet_name VARCHAR(120) DEFAULT '',
      account_no VARCHAR(120) DEFAULT '',
      ifsc_code VARCHAR(80) DEFAULT '',
      mobile_no VARCHAR(80) DEFAULT '',
      wallet_data TEXT,
      status TINYINT NOT NULL DEFAULT 1,
      login_session_token VARCHAR(80) DEFAULT '',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_wallet_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS user_sessions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      session_token VARCHAR(80) NOT NULL,
      device_id VARCHAR(120) DEFAULT '',
      ip VARCHAR(80) DEFAULT '',
      user_agent VARCHAR(255) DEFAULT '',
      is_active TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME DEFAULT NULL,
      PRIMARY KEY(id),
      UNIQUE KEY uq_session_token(session_token),
      KEY idx_user_session(user_id,is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS invited_wheel_cycles (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      spin_count INT NOT NULL DEFAULT 0,
      first_opened TINYINT NOT NULL DEFAULT 0,
      started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME DEFAULT NULL,
      status ENUM('active','expired','cashed') NOT NULL DEFAULT 'active',
      PRIMARY KEY(id),
      KEY idx_iwc_user(user_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS invited_wheel_prizes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      probability DECIMAL(10,4) NOT NULL DEFAULT 1,
      sort INT NOT NULL DEFAULT 0,
      status TINYINT NOT NULL DEFAULT 1,
      PRIMARY KEY(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS invited_wheel_records (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      cycle_id BIGINT UNSIGNED NOT NULL,
      prize_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      spin_source VARCHAR(50) DEFAULT 'free',
      is_win TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_iwr_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS invited_wheel_withdraws (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      cycle_id BIGINT UNSIGNED NOT NULL,
      order_no VARCHAR(80) NOT NULL,
      amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      status ENUM('Pass','Pending','Reject') NOT NULL DEFAULT 'Pass',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_iww_order(order_no),
      KEY idx_iww_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ($sqls as $sql) { @ $conn->query($sql); }
    @ $conn->query("ALTER TABLE user_sessions ADD COLUMN refresh_token VARCHAR(100) DEFAULT ''");
    @ $conn->query("ALTER TABLE user_sessions ADD COLUMN refresh_expires_at DATETIME DEFAULT NULL");
    @ $conn->query("CREATE TABLE IF NOT EXISTS admin_audit_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      admin_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      action VARCHAR(120) NOT NULL,
      target_type VARCHAR(80) DEFAULT '',
      target_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      ip VARCHAR(80) DEFAULT '',
      user_agent VARCHAR(255) DEFAULT '',
      data_json TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_action_created(action,created_at),
      KEY idx_target(target_type,target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [
      'users' => [
        'login_session_token' => "ALTER TABLE users ADD COLUMN login_session_token VARCHAR(80) DEFAULT ''",
        'withdraw_password_hash' => "ALTER TABLE users ADD COLUMN withdraw_password_hash VARCHAR(255) DEFAULT ''",
        'ip_last' => "ALTER TABLE users ADD COLUMN ip_last VARCHAR(80) DEFAULT ''",
        'device_id' => "ALTER TABLE users ADD COLUMN device_id VARCHAR(120) DEFAULT ''",
      ],
      'withdraw_wallets' => [
        'wallet_name' => "ALTER TABLE withdraw_wallets ADD COLUMN wallet_name VARCHAR(120) DEFAULT ''",
        'account_no' => "ALTER TABLE withdraw_wallets ADD COLUMN account_no VARCHAR(120) DEFAULT ''",
        'ifsc_code' => "ALTER TABLE withdraw_wallets ADD COLUMN ifsc_code VARCHAR(80) DEFAULT ''",
        'mobile_no' => "ALTER TABLE withdraw_wallets ADD COLUMN mobile_no VARCHAR(80) DEFAULT ''",
      ],
      'notifications' => [
        'image_url' => "ALTER TABLE notifications ADD COLUMN image_url VARCHAR(255) DEFAULT ''",
      ],
    ];
    foreach ($columns as $table => $cols) {
        foreach ($cols as $col => $sql) {
            $res = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '".$conn->real_escape_string($col)."'");
            if ($res instanceof mysqli_result && $res->num_rows > 0) continue;
            @ $conn->query($sql);
        }
    }

    $settings = v12_wheel_default_settings();
    $json = json_encode($settings, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $stmt = @$conn->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('invited_wheel_settings',?) ON DUPLICATE KEY UPDATE setting_value=setting_value");
    if ($stmt) { $stmt->bind_param('s', $json); $stmt->execute(); }

    $count = 0; $rs = @$conn->query('SELECT COUNT(*) c FROM invited_wheel_prizes');
    if ($rs) $count = (int)($rs->fetch_assoc()['c'] ?? 0);
    if ($count === 0) {
        $default = [[498.55,25],[490.00,25],[475.00,25],[450.00,25]];
        $stmt = @$conn->prepare('INSERT INTO invited_wheel_prizes(amount,probability,sort,status) VALUES(?,?,?,1)');
        if ($stmt) {
            $sort = 100;
            foreach ($default as $p) { $amount=(float)$p[0]; $prob=(float)$p[1]; $stmt->bind_param('ddi',$amount,$prob,$sort); $stmt->execute(); $sort -= 5; }
        }
    }
}

function v12_wheel_default_settings(): array
{
    return [
        'enabled' => true,
        'target_amount' => 500.00,
        'min_withdraw_amount' => 500.00,
        'cycle_hours' => 24,
        'free_spins' => 3,
        'invite_recharge_required' => 300.00,
        'spin_per_invite' => 1,
        'cash_to_main_wallet' => true,
        'code_wash' => '0',
        'no_winning_random_amount' => [1, 15],
        'first_box_count' => 4,
    ];
}

function v12_get_setting_json(mysqli $conn, string $key, array $default): array
{
    $stmt = @$conn->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
    if (!$stmt) return $default;
    $stmt->bind_param('s',$key); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $val = json_decode((string)($row['setting_value'] ?? ''), true);
    return is_array($val) ? array_merge($default, $val) : $default;
}

function v12_save_setting_json(mysqli $conn, string $key, array $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $stmt = @$conn->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    if ($stmt) { $stmt->bind_param('ss',$key,$json); $stmt->execute(); }
}

function create_user_session(mysqli $conn, int $uid, string $deviceId = ''): string
{
    $session = bin2hex(random_bytes(24));
    $refresh = bin2hex(random_bytes(32));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250);

    // One ID one device: every new login disables all previous sessions.
    @ $conn->query('UPDATE user_sessions SET is_active=0,last_seen_at=NOW() WHERE user_id='.(int)$uid);

    $stmt = @$conn->prepare('INSERT INTO user_sessions(user_id,session_token,refresh_token,refresh_expires_at,device_id,ip,user_agent,is_active,created_at,last_seen_at) VALUES(?,?,?,DATE_ADD(NOW(), INTERVAL 30 DAY),?,?,?,1,NOW(),NOW())');
    if ($stmt) { $stmt->bind_param('isssss',$uid,$session,$refresh,$deviceId,$ip,$ua); $stmt->execute(); }

    $stmt = @$conn->prepare('UPDATE users SET login_session_token=? WHERE id=?');
    if ($stmt) { $stmt->bind_param('si',$session,$uid); $stmt->execute(); }

    $GLOBALS['API_REFRESH_TOKEN'] = $refresh;
    return $session;
}

function wallet_response_row(array $r): array
{
    $data = [];
    if (!empty($r['wallet_data'])) { $tmp = json_decode((string)$r['wallet_data'], true); if (is_array($tmp)) $data = $tmp; }
    $type = (string)($r['wallet_type'] ?? ($data['withdrawType'] ?? 'BankCard'));
    $account = (string)($r['account_no'] ?? $data['accountNo'] ?? $data['bankAccountNo'] ?? $data['upiId'] ?? $data['address'] ?? '');
    $name = (string)($r['wallet_name'] ?? $data['bankName'] ?? $data['realName'] ?? $data['name'] ?? '');
    $mobile = (string)($r['mobile_no'] ?? $data['mobileNo'] ?? $data['phone'] ?? '');
    return [
        'walletId' => (string)$r['id'],
        'id' => (int)$r['id'],
        'withdrawWalletId' => (int)$r['id'],
        'walletType' => $type,
        'withdrawType' => $type,
        'bankName' => $name ?: $type,
        'realName' => (string)($data['realName'] ?? $data['holderName'] ?? $data['name'] ?? $name),
        'accountNo' => $account,
        'bankAccountNo' => $account,
        'upiId' => (string)($data['upiId'] ?? ($type === 'UPI' ? $account : '')),
        'mobileNo' => $mobile,
        'networkType' => $data['networkType'] ?? null,
        'cpf' => $data['cpf'] ?? null,
        'aliasAddress' => (string)($data['aliasAddress'] ?? ''),
        'ifscCode' => (string)($r['ifsc_code'] ?? $data['ifscCode'] ?? ''),
    ];
}

function invited_wheel_settings(): array
{
    $conn = db();
    if (!$conn) return v12_wheel_default_settings();
    return v12_get_setting_json($conn, 'invited_wheel_settings', v12_wheel_default_settings());
}

function invited_wheel_get_cycle(mysqli $conn, int $uid): array
{
    $settings = v12_get_setting_json($conn, 'invited_wheel_settings', v12_wheel_default_settings());
    $stmt = @$conn->prepare("SELECT * FROM invited_wheel_cycles WHERE user_id=? AND status='active' ORDER BY id DESC LIMIT 1");
    if ($stmt) { $stmt->bind_param('i',$uid); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); } else $row = null;
    $now = time();
    if ($row) {
        $expires = strtotime((string)$row['expires_at']);
        if ($expires && $expires <= $now) {
            $id=(int)$row['id']; @ $conn->query("UPDATE invited_wheel_cycles SET status='expired' WHERE id=$id");
            $row = null;
        }
    }
    if (!$row) {
        $spins = (int)($settings['free_spins'] ?? 3);
        $hours = max(1, (int)($settings['cycle_hours'] ?? 24));
        $expires = date('Y-m-d H:i:s', $now + $hours * 3600);
        $stmt = @$conn->prepare('INSERT INTO invited_wheel_cycles(user_id,amount,spin_count,first_opened,started_at,expires_at,status) VALUES(?,0,?,0,NOW(),?,"active")');
        if ($stmt) { $stmt->bind_param('iis',$uid,$spins,$expires); $stmt->execute(); }
        $id = (int)$conn->insert_id;
        $stmt = @$conn->prepare('SELECT * FROM invited_wheel_cycles WHERE id=? LIMIT 1');
        $stmt->bind_param('i',$id); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc();
    }
    return $row ?: ['id'=>0,'user_id'=>$uid,'amount'=>0,'spin_count'=>0,'first_opened'=>0,'expires_at'=>date('Y-m-d H:i:s', time()+86400),'status'=>'active'];
}

function invited_wheel_prize_list(mysqli $conn): array
{
    $list=[]; $rs=@$conn->query('SELECT * FROM invited_wheel_prizes WHERE status=1 ORDER BY sort DESC,id ASC');
    if ($rs) while($r=$rs->fetch_assoc()) $list[]=$r;
    if (!$list) $list=[['id'=>1,'amount'=>400,'probability'=>1,'sort'=>1,'status'=>1]];
    return $list;
}

function invited_wheel_pick_prize(mysqli $conn): array
{
    $list = invited_wheel_prize_list($conn);
    $total = 0; foreach ($list as $p) $total += max(0.0001, (float)$p['probability']);
    $rand = mt_rand() / mt_getrandmax() * $total;
    $acc = 0;
    foreach ($list as $p) { $acc += max(0.0001, (float)$p['probability']); if ($rand <= $acc) return $p; }
    return end($list);
}

function invited_wheel_disk_amounts(mysqli $conn): array
{
    // The large ₹450+ amount is awarded through the first gift-box overlay.
    // The wheel itself is used for the remaining gap, so its wedges must show
    // the same ₹0-10 range that can actually be credited on follow-up spins.
    $vals = invited_wheel_free_amounts($conn);
    $clean = [];
    foreach ($vals as $value) {
        $amount = round((float)$value, 2);
        if ($amount >= 0.01 && $amount <= 10.00 && !in_array($amount, $clean, true)) $clean[] = $amount;
    }
    foreach ([0.50, 1.00, 2.00, 3.00, 5.00, 7.00, 8.00, 10.00] as $fallback) {
        if (count($clean) >= 8) break;
        if (!in_array($fallback, $clean, true)) $clean[] = $fallback;
    }
    sort($clean, SORT_NUMERIC);
    return array_values(array_slice($clean, 0, 8));
}

function invited_wheel_first_boxes(mysqli $conn): array
{
    $settings = v12_get_setting_json($conn, 'invited_wheel_settings', v12_wheel_default_settings());
    $large = [];
    foreach (invited_wheel_prize_list($conn) as $prize) {
        $amount = round((float)($prize['amount'] ?? 0), 2);
        if ($amount >= 450.00 && $amount < 500.00 && !in_array($amount, $large, true)) $large[] = $amount;
    }
    foreach ([498.55, 490.00, 475.00, 450.00] as $fallback) {
        if (count($large) >= 4) break;
        if (!in_array($fallback, $large, true)) $large[] = $fallback;
    }
    rsort($large, SORT_NUMERIC);
    $count = max(4, (int)($settings['first_box_count'] ?? 4));
    $out=[];
    for ($i=0; $i<$count; $i++) {
        $amt = $large[$i % max(1,count($large))] ?? 450.00;
        $out[] = ['id'=>$i+1,'amount'=>(float)$amt,'prizeAmount'=>(float)$amt,'rewardAmount'=>(float)$amt,'state'=>0];
    }
    return $out;
}

function invited_wheel_add_spin_for_inviter(mysqli $conn, int $childUid, float $rechargeAmount): void
{
    $settings = v12_get_setting_json($conn, 'invited_wheel_settings', v12_wheel_default_settings());
    if ($rechargeAmount < (float)($settings['invite_recharge_required'] ?? 300)) return;
    $stmt = @$conn->prepare('SELECT agent_parent_id FROM users WHERE id=? LIMIT 1');
    if (!$stmt) return;
    $stmt->bind_param('i',$childUid); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc();
    $parent = (int)($row['agent_parent_id'] ?? 0);
    if ($parent <= 0) return;
    $cycle = invited_wheel_get_cycle($conn, $parent);
    $spin = max(1, (int)($settings['spin_per_invite'] ?? 1));
    $cid = (int)$cycle['id'];
    @ $conn->query('UPDATE invited_wheel_cycles SET spin_count=spin_count+'.(int)$spin.' WHERE id='.(int)$cid);
}



// ===============================
// V13: UTR submit, site controls, small free-spin wheel prizes, real admin settings
// ===============================
function v13_default_site_settings(): array
{
    return [
        'captcha_enabled' => false,
        'turnstile_enabled' => false,
        'home_enabled' => true,
        'popup_enabled' => true,
        'maintenance_enabled' => false,
        'maintenance_text' => 'Site is under maintenance. Please try again later.',
        'gift_enabled' => true,
        'min_withdraw_amount' => 110.00,
        'withdraw_need_bet_multiplier' => 0.00,
        'telegram_url' => '',
        'service_url' => '',
        'home_banner_enabled' => true,
        'profile_enabled' => true,
        'bonus_enabled' => true,
        'vip_enabled' => true,
        'agent_enabled' => true,
        'workorder_enabled' => true,
        'recharge_enabled' => true,
        'withdraw_enabled' => true,
        'invite_enabled' => true,
        'lottery_enabled' => true,
        'wingo_enabled' => true,
        'k3_enabled' => true,
        'd5_enabled' => true,
        'moto_enabled' => true,
        'trx_enabled' => true,
        'game_history_page_size' => 10,
        'admin_theme' => 'neo-dark',
        'support_chat_enabled' => true,
        'license_popup_enabled' => false,
    ];
}

function site_settings(): array
{
    $conn = db();
    if (!$conn) return v13_default_site_settings();
    return v12_get_setting_json($conn, 'site_settings', v13_default_site_settings());
}

function save_site_settings(mysqli $conn, array $settings): void
{
    v12_save_setting_json($conn, 'site_settings', array_merge(v13_default_site_settings(), $settings));
}

function ensure_v13_tables(mysqli $conn): void
{
    // Safe idempotent migrations. These never touch /img assets.
    $cols = [
        'recharge_orders' => [
            'utr' => "ALTER TABLE recharge_orders ADD COLUMN utr VARCHAR(120) DEFAULT ''",
            'utr_submit_at' => "ALTER TABLE recharge_orders ADD COLUMN utr_submit_at DATETIME DEFAULT NULL",
            'user_submit_note' => "ALTER TABLE recharge_orders ADD COLUMN user_submit_note VARCHAR(255) DEFAULT ''",
            'payment_proof' => "ALTER TABLE recharge_orders ADD COLUMN payment_proof VARCHAR(500) DEFAULT ''"
        ],
        'gift_codes' => [
            'title' => "ALTER TABLE gift_codes ADD COLUMN title VARCHAR(190) DEFAULT 'Gift Code'",
            'per_user_limit' => "ALTER TABLE gift_codes ADD COLUMN per_user_limit INT NOT NULL DEFAULT 1",
            'min_recharge' => "ALTER TABLE gift_codes ADD COLUMN min_recharge DECIMAL(18,2) NOT NULL DEFAULT 0",
            'admin_note' => "ALTER TABLE gift_codes ADD COLUMN admin_note VARCHAR(255) DEFAULT ''"
        ],
        'invited_wheel_cycles' => [
            'started_at' => "ALTER TABLE invited_wheel_cycles ADD COLUMN started_at DATETIME DEFAULT CURRENT_TIMESTAMP",
            'expires_at' => "ALTER TABLE invited_wheel_cycles ADD COLUMN expires_at DATETIME DEFAULT NULL",
            'turnover_required' => "ALTER TABLE invited_wheel_cycles ADD COLUMN turnover_required DECIMAL(18,2) NOT NULL DEFAULT 0",
            'turnover_completed' => "ALTER TABLE invited_wheel_cycles ADD COLUMN turnover_completed DECIMAL(18,2) NOT NULL DEFAULT 0"
        ],
        'invited_wheel_withdraws' => [
            'admin_note' => "ALTER TABLE invited_wheel_withdraws ADD COLUMN admin_note VARCHAR(255) DEFAULT ''"
        ]
    ];
    foreach ($cols as $table=>$list) {
        foreach ($list as $col=>$sql) {
            $res = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '".$conn->real_escape_string($col)."'");
            if ($res instanceof mysqli_result && $res->num_rows > 0) continue;
            @ $conn->query($sql);
        }
    }
    @ $conn->query("CREATE TABLE IF NOT EXISTS user_sessions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      session_token VARCHAR(120) NOT NULL,
      refresh_token VARCHAR(120) NOT NULL,
      refresh_expires_at DATETIME DEFAULT NULL,
      device_id VARCHAR(120) DEFAULT '',
      ip VARCHAR(80) DEFAULT '',
      user_agent VARCHAR(255) DEFAULT '',
      is_active TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_us_user (user_id),
      KEY idx_us_session (session_token),
      KEY idx_us_refresh (refresh_token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    @ $conn->query("CREATE TABLE IF NOT EXISTS invited_wheel_free_prizes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      probability DECIMAL(10,4) NOT NULL DEFAULT 1,
      sort INT NOT NULL DEFAULT 0,
      status TINYINT NOT NULL DEFAULT 1,
      PRIMARY KEY(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    @ $conn->query("CREATE TABLE IF NOT EXISTS admin_menu_settings (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      group_name VARCHAR(80) NOT NULL,
      item_key VARCHAR(80) NOT NULL,
      title VARCHAR(120) NOT NULL,
      icon_svg TEXT,
      sort INT NOT NULL DEFAULT 0,
      status TINYINT NOT NULL DEFAULT 1,
      PRIMARY KEY(id),
      UNIQUE KEY uq_menu_item(item_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $site = v13_default_site_settings();
    $stmt = @$conn->prepare("INSERT INTO settings(setting_key,setting_value) VALUES('site_settings',?) ON DUPLICATE KEY UPDATE setting_value=setting_value");
    if ($stmt) { $json=json_encode($site, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); $stmt->bind_param('s',$json); $stmt->execute(); }

    $wheel = v12_get_setting_json($conn, 'invited_wheel_settings', v12_wheel_default_settings());
    $wheel = array_merge([
        'free_spin_min' => 0.10,
        'free_spin_max' => 3.00,
        'regular_spin_min' => 0.10,
        'regular_spin_max' => 9.00,
        'free_spin_use_small_prizes' => true,
        'turnover_multiplier' => 0.00,
        'show_first_box_popup' => true,
        'max_spin_reward' => 3.00,
    ], $wheel);
    v12_save_setting_json($conn, 'invited_wheel_settings', $wheel);

    $rs = @$conn->query('SELECT COUNT(*) c FROM invited_wheel_free_prizes');
    $count = $rs ? (int)($rs->fetch_assoc()['c'] ?? 0) : 0;
    if ($count === 0) {
        $defaults = [[0.10,20],[0.40,20],[0.90,20],[1.20,20],[2.00,12],[3.00,8]];
        $stmt = @$conn->prepare('INSERT INTO invited_wheel_free_prizes(amount,probability,sort,status) VALUES(?,?,?,1)');
        if ($stmt) { $sort=100; foreach ($defaults as $d) { $amount=(float)$d[0]; $prob=(float)$d[1]; $stmt->bind_param('ddi',$amount,$prob,$sort); $stmt->execute(); $sort-=5; } }
    }
}

function invited_wheel_free_prize_list(mysqli $conn): array
{
    $list=[]; $rs=@$conn->query('SELECT * FROM invited_wheel_free_prizes WHERE status=1 ORDER BY sort DESC,id ASC');
    if($rs) while($r=$rs->fetch_assoc()) $list[]=$r;
    if(!$list) $list=[['id'=>1,'amount'=>0.10,'probability'=>1,'sort'=>1,'status'=>1]];
    return $list;
}

function invited_wheel_pick_small_prize(mysqli $conn, array $settings): array
{
    $list = invited_wheel_free_prize_list($conn);
    $min = max(0.01, (float)($settings['free_spin_min'] ?? 0.10));
    $max = max($min, min((float)($settings['free_spin_max'] ?? 3.00), (float)($settings['max_spin_reward'] ?? 3.00)));
    $filtered = array_values(array_filter($list, function($p) use ($min,$max){ $a=(float)$p['amount']; return $a >= $min && $a <= $max; }));
    if (!$filtered) $filtered = [['id'=>0,'amount'=>$min,'probability'=>1,'sort'=>1,'status'=>1]];
    $total = 0; foreach($filtered as $p) $total += max(0.0001, (float)$p['probability']);
    $rand = mt_rand() / mt_getrandmax() * $total; $acc=0;
    foreach($filtered as $p){ $acc += max(0.0001, (float)$p['probability']); if($rand <= $acc) { $p['amount'] = min($max, max($min, (float)$p['amount'])); return $p; } }
    $p=end($filtered); $p['amount']=min($max,max($min,(float)$p['amount'])); return $p;
}

function invited_wheel_free_amounts(mysqli $conn): array
{
    // The bundled canvas uses an eight-segment atlas. Returning six or nine
    // values leaves some wedges blank even though the spin endpoint succeeds.
    $vals = array_map(fn($p)=>round((float)$p['amount'], 2), invited_wheel_free_prize_list($conn));
    $vals = array_values(array_unique(array_filter($vals, fn($v)=>$v > 0)));
    foreach ([0.10,0.20,0.40,0.70,0.90,1.20,2.00,3.00] as $fallback) {
        if (count($vals) >= 8) break;
        if (!in_array($fallback, $vals, true)) $vals[] = $fallback;
    }
    sort($vals, SORT_NUMERIC);
    return array_values(array_slice($vals, 0, 8));
}

function user_turnover_after(mysqli $conn, int $uid, string $date): float
{
    $stmt = @$conn->prepare('SELECT COALESCE(SUM(real_amount),0) s FROM lottery_bets WHERE user_id=? AND created_at>=?');
    if(!$stmt) return 0.0;
    $stmt->bind_param('is',$uid,$date); $stmt->execute();
    return (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
}


function ensure_v14_tables(mysqli $conn): void
{
    // V14 tables for real commission, tasks, support tickets and admin controls.
    $sqls = [];
    $sqls[] = "CREATE TABLE IF NOT EXISTS agent_commissions (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      from_user_id BIGINT UNSIGNED NOT NULL,
      order_no VARCHAR(80) DEFAULT '',
      source_type VARCHAR(60) DEFAULT 'lottery_bet',
      bet_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      rate DECIMAL(10,6) NOT NULL DEFAULT 0,
      commission_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      status ENUM('pending','settled','rejected') NOT NULL DEFAULT 'settled',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_agent_comm_order (user_id, from_user_id, order_no, source_type),
      KEY idx_agent_comm_user (user_id, created_at),
      KEY idx_agent_comm_from (from_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS day_week_tasks (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      task_code VARCHAR(80) NOT NULL,
      title VARCHAR(190) NOT NULL,
      period_type ENUM('day','week') NOT NULL DEFAULT 'day',
      target_type VARCHAR(60) NOT NULL DEFAULT 'bet',
      target_value DECIMAL(18,2) NOT NULL DEFAULT 0,
      reward_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      sort INT NOT NULL DEFAULT 0,
      status TINYINT NOT NULL DEFAULT 1,
      PRIMARY KEY(id),
      UNIQUE KEY uq_day_week_task (task_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS day_week_task_claims (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      task_code VARCHAR(80) NOT NULL,
      period_key VARCHAR(20) NOT NULL,
      amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_task_claim (user_id, task_code, period_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS work_orders (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      ticket_no VARCHAR(80) NOT NULL,
      form_id BIGINT DEFAULT 0,
      work_order_type_id BIGINT DEFAULT 0,
      type_name VARCHAR(120) DEFAULT '',
      title VARCHAR(190) DEFAULT '',
      content TEXT,
      contact VARCHAR(190) DEFAULT '',
      image_url VARCHAR(500) DEFAULT '',
      status ENUM('Pending','Processing','Answered','Closed','Rejected') NOT NULL DEFAULT 'Pending',
      admin_reply TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT NULL,
      PRIMARY KEY(id),
      UNIQUE KEY uq_work_ticket(ticket_no),
      KEY idx_work_user(user_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $sqls[] = "CREATE TABLE IF NOT EXISTS site_feature_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      admin_user VARCHAR(120) DEFAULT '',
      feature_key VARCHAR(120) DEFAULT '',
      old_value TEXT,
      new_value TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    foreach ($sqls as $sql) { @ $conn->query($sql); }

    $cols = [
      'users' => [
        'turnover_required' => "ALTER TABLE users ADD COLUMN turnover_required DECIMAL(18,2) NOT NULL DEFAULT 0",
        'turnover_completed' => "ALTER TABLE users ADD COLUMN turnover_completed DECIMAL(18,2) NOT NULL DEFAULT 0",
        'same_ip_flag' => "ALTER TABLE users ADD COLUMN same_ip_flag TINYINT NOT NULL DEFAULT 0"
      ],
      'recharge_orders' => [
        'utr' => "ALTER TABLE recharge_orders ADD COLUMN utr VARCHAR(120) DEFAULT ''",
        'utr_submit_at' => "ALTER TABLE recharge_orders ADD COLUMN utr_submit_at DATETIME DEFAULT NULL",
        'user_submit_note' => "ALTER TABLE recharge_orders ADD COLUMN user_submit_note VARCHAR(255) DEFAULT ''",
        'payment_proof' => "ALTER TABLE recharge_orders ADD COLUMN payment_proof VARCHAR(500) DEFAULT ''",
        'customer_info' => "ALTER TABLE recharge_orders ADD COLUMN customer_info LONGTEXT",
        'recharge_info' => "ALTER TABLE recharge_orders ADD COLUMN recharge_info LONGTEXT",
        'raw_data' => "ALTER TABLE recharge_orders ADD COLUMN raw_data LONGTEXT",
        'admin_note' => "ALTER TABLE recharge_orders ADD COLUMN admin_note VARCHAR(255) DEFAULT ''",
        'updated_at' => "ALTER TABLE recharge_orders ADD COLUMN updated_at DATETIME DEFAULT NULL"
      ],
      'withdraw_requests' => [
        'wallet_id' => "ALTER TABLE withdraw_requests ADD COLUMN wallet_id BIGINT DEFAULT NULL",
        'wallet_snapshot' => "ALTER TABLE withdraw_requests ADD COLUMN wallet_snapshot TEXT",
        'order_no' => "ALTER TABLE withdraw_requests ADD COLUMN order_no VARCHAR(80) DEFAULT '' AFTER user_id",
        'is_reserved' => "ALTER TABLE withdraw_requests ADD COLUMN is_reserved TINYINT NOT NULL DEFAULT 0 AFTER wallet_snapshot",
        'balance_before' => "ALTER TABLE withdraw_requests ADD COLUMN balance_before DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER is_reserved",
        'balance_after' => "ALTER TABLE withdraw_requests ADD COLUMN balance_after DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER balance_before",
        'processed_at' => "ALTER TABLE withdraw_requests ADD COLUMN processed_at DATETIME DEFAULT NULL AFTER admin_note"
      ],
      'work_orders' => workorder_required_columns()
    ];
    foreach ($cols as $table=>$list) {
        foreach ($list as $col=>$sql) {
            $res = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '".$conn->real_escape_string($col)."'");
            if ($res instanceof mysqli_result && $res->num_rows > 0) continue;
            @ $conn->query($sql);
        }
    }

    $defaults = [
      ['daily_bet_100','Daily bet ₹100','day','bet',100,1.00,100],
      ['daily_recharge_100','Daily recharge ₹100','day','recharge',100,1.00,95],
      ['daily_invite_1','Daily invite 1 user','day','invite',1,1.00,90],
      ['weekly_bet_1000','Weekly bet ₹1000','week','bet',1000,10.00,80],
      ['weekly_recharge_500','Weekly recharge ₹500','week','recharge',500,5.00,70]
    ];
    $stmt = @$conn->prepare('INSERT INTO day_week_tasks(task_code,title,period_type,target_type,target_value,reward_amount,sort,status) VALUES(?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE title=VALUES(title),period_type=VALUES(period_type),target_type=VALUES(target_type),target_value=VALUES(target_value),reward_amount=VALUES(reward_amount),sort=VALUES(sort),status=1');
    if ($stmt) {
        foreach($defaults as $t){ $stmt->bind_param('ssssddi', $t[0],$t[1],$t[2],$t[3],$t[4],$t[5],$t[6]); $stmt->execute(); }
    }

    $features = v12_get_setting_json($conn, 'site_settings', v13_default_site_settings());
    $features = array_merge([
      'captcha_enabled'=>false,
      'popup_enabled'=>true,
      'maintenance_enabled'=>false,
      'home_enabled'=>true,
      'gift_enabled'=>true,
      'bonus_enabled'=>true,
      'vip_enabled'=>true,
      'agent_enabled'=>true,
      'workorder_enabled'=>true,
      'recharge_enabled'=>true,
      'withdraw_enabled'=>true,
      'invite_enabled'=>true,
      'min_withdraw'=>110.00,
      'turnover_required'=>0,
      'service_telegram'=>'',
      'common_notice'=>'Welcome to 9K.Club'
    ], $features);
    v12_save_setting_json($conn, 'site_settings', $features);

    // Seed demo admin/query data so pages don't open blank on fresh install.
    $rs=@$conn->query('SELECT COUNT(*) c FROM work_orders');
    $cnt=$rs?(int)($rs->fetch_assoc()['c']??0):0;
    if($cnt===0){
      @ $conn->query("INSERT IGNORE INTO work_orders(user_id,ticket_no,form_id,type_name,title,content,contact,status,admin_reply,created_at) VALUES(117224,'WO".date('ymdHis')."',91,'Deposit Not Received','Demo support query','This is a sample query. User submitted issue will appear here.','', 'Answered','Admin reply will show here.',NOW())");
    }
}

function v14_period_key(string $period): string
{
    return $period === 'week' ? date('oW') : date('Ymd');
}

function v14_start_time(string $period): string
{
    if ($period === 'week') return date('Y-m-d 00:00:00', strtotime('monday this week'));
    return date('Y-m-d 00:00:00');
}

function v14_user_metric(mysqli $conn, int $uid, string $type, string $start): float
{
    if ($type === 'recharge') {
        $stmt=@$conn->prepare("SELECT COALESCE(SUM(amount),0) s FROM recharge_orders WHERE user_id=? AND status='Payed' AND created_at>=?");
    } elseif ($type === 'invite') {
        $stmt=@$conn->prepare("SELECT COUNT(*) s FROM users WHERE agent_parent_id=? AND created_at>=?");
    } elseif ($type === 'withdraw') {
        $stmt=@$conn->prepare("SELECT COALESCE(SUM(amount),0) s FROM withdraw_requests WHERE user_id=? AND status='approved' AND created_at>=?");
    } else {
        $stmt=@$conn->prepare("SELECT COALESCE(SUM(real_amount),0) s FROM lottery_bets WHERE user_id=? AND created_at>=?");
    }
    if(!$stmt) return 0.0;
    $stmt->bind_param('is',$uid,$start); $stmt->execute();
    return (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
}

function v34_agent_descendants(mysqli $conn, int $uid, int $maxDepth = 10, int $limit = 5000): array
{
    $rows = [];
    $levels = [];
    $seen = [$uid => true];
    $parents = [$uid];
    for ($depth = 1; $depth <= $maxDepth && $parents && count($rows) < $limit; $depth++) {
        $ids = implode(',', array_map('intval', $parents));
        $rs = @$conn->query("SELECT id,agent_parent_id,username,mobile,nickname,total_deposit,total_withdraw,total_bet,created_at,last_login_at,vip_level,status FROM users WHERE role='user' AND agent_parent_id IN($ids) ORDER BY id DESC LIMIT ".($limit-count($rows)));
        $next = [];
        if ($rs) while ($row = $rs->fetch_assoc()) {
            $id = (int)$row['id'];
            if ($id <= 0 || isset($seen[$id])) continue;
            $seen[$id] = true;
            $row['_depth'] = $depth;
            $rows[] = $row;
            $levels[$depth][] = $row;
            $next[] = $id;
        }
        $parents = $next;
    }
    return ['rows'=>$rows, 'levels'=>$levels, 'truncated'=>count($rows) >= $limit];
}

function v34_team_metrics(mysqli $conn, array $rows, ?string $start = null, ?string $end = null): array
{
    $ids = array_values(array_unique(array_map(fn($r)=>(int)$r['id'], $rows)));
    $out = ['registered'=>0,'depositUsers'=>0,'firstDepositUsers'=>0,'depositAmount'=>0.0,'betUsers'=>0,'betAmount'=>0.0,'withdrawAmount'=>0.0];
    if (!$ids) return $out;
    $in = implode(',', $ids);
    $startSql = $start ? " AND created_at>='".$conn->real_escape_string($start)."'" : '';
    $endSql = $end ? " AND created_at<'".$conn->real_escape_string($end)."'" : '';
    $q = @$conn->query("SELECT COUNT(*) c FROM users WHERE id IN($in)".$startSql.$endSql);
    if ($q) $out['registered'] = (int)($q->fetch_assoc()['c'] ?? 0);
    $q = @$conn->query("SELECT COUNT(DISTINCT user_id) users,COALESCE(SUM(amount),0) amount FROM recharge_orders WHERE status='Payed' AND user_id IN($in)".$startSql.$endSql);
    if ($q) { $r=$q->fetch_assoc(); $out['depositUsers']=(int)($r['users']??0); $out['depositAmount']=(float)($r['amount']??0); }
    $having = $start ? " HAVING first_at>='".$conn->real_escape_string($start)."'".($end ? " AND first_at<'".$conn->real_escape_string($end)."'" : '') : '';
    $q = @$conn->query("SELECT COUNT(*) c FROM (SELECT user_id,MIN(created_at) first_at FROM recharge_orders WHERE status='Payed' AND user_id IN($in) GROUP BY user_id".$having.") first_deposits");
    if ($q) $out['firstDepositUsers']=(int)($q->fetch_assoc()['c']??0);
    $q = @$conn->query("SELECT COUNT(DISTINCT user_id) users,COALESCE(SUM(real_amount),0) amount FROM lottery_bets WHERE user_id IN($in)".$startSql.$endSql);
    if ($q) { $r=$q->fetch_assoc(); $out['betUsers']=(int)($r['users']??0); $out['betAmount']=(float)($r['amount']??0); }
    $q = @$conn->query("SELECT COALESCE(SUM(amount),0) amount FROM withdraw_requests WHERE status='approved' AND user_id IN($in)".$startSql.$endSql);
    if ($q) $out['withdrawAmount']=(float)($q->fetch_assoc()['amount']??0);
    return $out;
}

function v34_commission_total(mysqli $conn, int $uid, ?string $start = null, ?string $end = null, ?string $status = null): float
{
    $where = 'user_id='.(int)$uid;
    $where .= $status ? " AND status='".$conn->real_escape_string($status)."'" : " AND status<>'rejected'";
    if ($start) $where .= " AND created_at>='".$conn->real_escape_string($start)."'";
    if ($end) $where .= " AND created_at<'".$conn->real_escape_string($end)."'";
    $rs = @$conn->query("SELECT COALESCE(SUM(commission_amount),0) amount FROM agent_commissions WHERE $where");
    return $rs ? round((float)($rs->fetch_assoc()['amount'] ?? 0), 2) : 0.0;
}

function v34_record_agent_commissions(mysqli $conn, int $fromUserId, string $orderNo, float $betAmount): void
{
    if ($fromUserId <= 0 || $betAmount <= 0 || $orderNo === '') return;
    $rates = [1=>0.006, 2=>0.003, 3=>0.001];
    $child = $fromUserId;
    $seen = [$fromUserId=>true];
    for ($level=1; $level<=3; $level++) {
        $stmt = @$conn->prepare('SELECT agent_parent_id FROM users WHERE id=? LIMIT 1');
        if (!$stmt) return;
        $stmt->bind_param('i', $child); $stmt->execute();
        $parent = (int)($stmt->get_result()->fetch_assoc()['agent_parent_id'] ?? 0);
        if ($parent <= 0 || isset($seen[$parent])) return;
        $seen[$parent] = true;
        $rate = $rates[$level];
        $amount = round($betAmount * $rate, 2);
        if ($amount > 0) {
            $source = 'lottery_bet';
            $status = 'pending';
            $stmt = @$conn->prepare('INSERT IGNORE INTO agent_commissions(user_id,from_user_id,order_no,source_type,bet_amount,rate,commission_amount,status,level_no,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())');
            if ($stmt) { $stmt->bind_param('iissdddsi', $parent,$fromUserId,$orderNo,$source,$betAmount,$rate,$amount,$status,$level); $stmt->execute(); }
        }
        $child = $parent;
    }
}

function v14_agent_summary(mysqli $conn, int $uid): array
{
    $tree = v34_agent_descendants($conn, $uid);
    $direct = $tree['levels'][1] ?? [];
    $team = $tree['rows'];
    $today = date('Y-m-d 00:00:00');
    $tomorrow = date('Y-m-d 00:00:00', strtotime('+1 day'));
    $yesterday = date('Y-m-d 00:00:00', strtotime('-1 day'));
    $week = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $directAll = v34_team_metrics($conn, $direct);
    $teamAll = v34_team_metrics($conn, $team);
    $todayDirect = v34_team_metrics($conn, $direct, $today, $tomorrow);
    $todayTeam = v34_team_metrics($conn, $team, $today, $tomorrow);
    $yesterdayDirect = v34_team_metrics($conn, $direct, $yesterday, $today);
    $yesterdayTeam = v34_team_metrics($conn, $team, $yesterday, $today);
    return [
        'direct'=>$direct,'team'=>$team,'levels'=>$tree['levels'],'truncated'=>$tree['truncated'],
        'registered'=>$directAll['registered'],'teamRegistered'=>$teamAll['registered'],
        'totalDeposit'=>$teamAll['depositAmount'],'totalBet'=>$teamAll['betAmount'],'totalWithdraw'=>$teamAll['withdrawAmount'],
        'directTotalDeposit'=>$directAll['depositAmount'],'directTotalBet'=>$directAll['betAmount'],'directTotalWithdraw'=>$directAll['withdrawAmount'],
        'todayDeposit'=>$todayTeam['depositAmount'],'todayBet'=>$todayTeam['betAmount'],
        'firstDepositUsers'=>$teamAll['firstDepositUsers'],'directFirstDepositUsers'=>$directAll['firstDepositUsers'],
        'directMetrics'=>$directAll,'teamMetrics'=>$teamAll,'todayDirect'=>$todayDirect,'todayTeam'=>$todayTeam,
        'yesterdayDirect'=>$yesterdayDirect,'yesterdayTeam'=>$yesterdayTeam,
        'todayCommission'=>v34_commission_total($conn,$uid,$today,$tomorrow),
        'yesterdayCommission'=>v34_commission_total($conn,$uid,$yesterday,$today),
        'weekCommission'=>v34_commission_total($conn,$uid,$week,$tomorrow),
        'totalCommission'=>v34_commission_total($conn,$uid),
        'pendingCommission'=>v34_commission_total($conn,$uid,null,null,'pending'),
    ];
}

function get_auto_install_sql(string $version): string
{
    $versionEsc = addslashes($version);
    return <<<SQL
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_user_id VARCHAR(32) NOT NULL,
  username VARCHAR(120) NOT NULL,
  mobile VARCHAR(40) DEFAULT NULL,
  email VARCHAR(160) DEFAULT NULL,
  password_hash VARCHAR(255) NOT NULL,
  nickname VARCHAR(120) NOT NULL,
  photo VARCHAR(255) DEFAULT '1',
  real_name VARCHAR(120) DEFAULT NULL,
  balance DECIMAL(18,2) NOT NULL DEFAULT 0,
  safe_box DECIMAL(18,2) NOT NULL DEFAULT 0,
  vip_level INT NOT NULL DEFAULT 1,
  invite_code VARCHAR(20) DEFAULT NULL,
  role ENUM('user','admin') NOT NULL DEFAULT 'user',
  status TINYINT NOT NULL DEFAULT 1,
  must_change_password TINYINT NOT NULL DEFAULT 0,
  login_session_token VARCHAR(80) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username),
  KEY idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS banners (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  icon_url VARCHAR(500) NOT NULL,
  jump_type INT NOT NULL DEFAULT 2,
  jump_detail VARCHAR(500) DEFAULT '',
  display_target INT NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  status TINYINT NOT NULL DEFAULT 1,
  login_session_token VARCHAR(80) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS game_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_code VARCHAR(80) NOT NULL,
  name_en VARCHAR(120) NOT NULL,
  name_hi VARCHAR(120) DEFAULT NULL,
  sort INT NOT NULL DEFAULT 0,
  img VARCHAR(500) DEFAULT NULL,
  selected_img VARCHAR(500) DEFAULT NULL,
  status TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY(id),
  UNIQUE KEY uq_cat (category_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS games (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_code VARCHAR(80) DEFAULT NULL,
  vendor_code VARCHAR(80) DEFAULT NULL,
  game_id BIGINT DEFAULT NULL,
  game_code VARCHAR(120) NOT NULL,
  name VARCHAR(190) NOT NULL,
  img VARCHAR(500) DEFAULT NULL,
  sort BIGINT DEFAULT 0,
  rtp DECIMAL(8,2) DEFAULT 98.00,
  is_maintenance TINYINT NOT NULL DEFAULT 0,
  status TINYINT NOT NULL DEFAULT 1,
  login_session_token VARCHAR(80) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_game (game_code, vendor_code),
  KEY idx_game_code (game_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS financial_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  record_no VARCHAR(80) NOT NULL,
  order_no VARCHAR(80) NOT NULL,
  vendor_code VARCHAR(80) DEFAULT '',
  type VARCHAR(80) NOT NULL,
  sub_type VARCHAR(80) DEFAULT '',
  amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  back_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  remark VARCHAR(255) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_fin_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS withdraw_wallets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  wallet_type VARCHAR(80) NOT NULL,
  wallet_data TEXT,
  status TINYINT NOT NULL DEFAULT 1,
  login_session_token VARCHAR(80) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_wallet_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS withdraw_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  method VARCHAR(80) DEFAULT 'BankCard',
  wallet_info TEXT,
  status ENUM('pending','approved','rejected') DEFAULT 'pending',
  admin_note VARCHAR(255) DEFAULT '',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY(id),
  KEY idx_withdraw_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS recharge_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  order_no VARCHAR(80) NOT NULL,
  recharge_category_id BIGINT DEFAULT NULL,
  channel_name VARCHAR(120) DEFAULT '',
  recharge_type VARCHAR(80) DEFAULT '',
  amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  gift_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  pay_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  status ENUM('Wait','PendingReview','Payed','Cancel') NOT NULL DEFAULT 'Wait',
  raw_data LONGTEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT NULL,
  PRIMARY KEY(id),
  UNIQUE KEY uq_recharge_order (order_no),
  KEY idx_recharge_user (user_id),
  KEY idx_recharge_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS lottery_bets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  order_no VARCHAR(80) NOT NULL,
  game_code VARCHAR(120) NOT NULL,
  issue_number VARCHAR(80) NOT NULL,
  bet_content VARCHAR(255) NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  bet_multiple INT NOT NULL DEFAULT 1,
  real_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  fee DECIMAL(18,2) NOT NULL DEFAULT 0,
  premium VARCHAR(80) DEFAULT '',
  state TINYINT NOT NULL DEFAULT 2,
  win_lose_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_bet_user (user_id),
  KEY idx_bet_issue (issue_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS lottery_results (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_code VARCHAR(120) NOT NULL,
  issue_number VARCHAR(80) NOT NULL,
  premium VARCHAR(120) NOT NULL,
  number VARCHAR(120) DEFAULT '',
  color VARCHAR(80) DEFAULT '',
  big_small VARCHAR(20) DEFAULT '',
  sum_value INT DEFAULT 0,
  open_time DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_result (game_code, issue_number),
  KEY idx_result_game (game_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS lottery_game_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  game_code VARCHAR(120) NOT NULL DEFAULT '*',
  win_rate DECIMAL(8,2) NOT NULL DEFAULT 45.00,
  force_mode ENUM('auto','win','lose') NOT NULL DEFAULT 'auto',
  force_result VARCHAR(120) DEFAULT '',
  fee_percent DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  payout_number DECIMAL(10,2) NOT NULL DEFAULT 9.00,
  payout_color DECIMAL(10,2) NOT NULL DEFAULT 2.00,
  payout_violet DECIMAL(10,2) NOT NULL DEFAULT 4.50,
  payout_bigsmall DECIMAL(10,2) NOT NULL DEFAULT 2.00,
  payout_k3 DECIMAL(10,2) NOT NULL DEFAULT 2.00,
  payout_5d DECIMAL(10,2) NOT NULL DEFAULT 9.50,
  payout_moto DECIMAL(10,2) NOT NULL DEFAULT 9.50,
  immediate_settle TINYINT NOT NULL DEFAULT 0,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  UNIQUE KEY uq_lottery_setting_game (game_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(120) NOT NULL,
  setting_value LONGTEXT,
  PRIMARY KEY(setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO banners(id,name,icon_url,jump_type,jump_detail,display_target,sort,status) VALUES
(26,'9K.Club Support','/img/6016/banner/050746856-141141-file_20260919170746848.webp',2,'/workOrder',1,9,1),
(25,'9K.Club Telegram','/img/6016/banner/050637280-141139-file_20260919170637261.webp',2,'https://t.me/www9kclubbot',1,8,1),
(24,'Daily Check-in','/img/6007/banner/035844411-31580-file_20260416155844410.webp',3,'20',1,6,1),
(23,'Recharge Reward','/img/6007/banner/035630931-31579-file_20260416155630929.webp',3,'19',1,5,1),
(22,'Week Card','/img/6007/banner/035453827-31578-file_20260416155453824.webp',3,'21',1,4,1),
(19,'Super Jackpot','/img/6007/banner/035023032-31574-file_20260416155023031.webp',3,'5',1,1,1);
INSERT IGNORE INTO game_categories(category_code,name_en,name_hi,sort,img,selected_img,status) VALUES
('Lottery','Lottery','लॉटरी',100,'/img/6007/gamecategory/013821461-35331-file_20260504133821456.webp','',1),
('Hot','Hot','हॉट',90,'/img/6007/gamecategory/015427496-35372-file_20260504135427491.webp','',1);
INSERT IGNORE INTO games(category_code,vendor_code,game_id,game_code,name,img,sort,rtp,is_maintenance,status) VALUES
('Lottery','ARLottery',1005,'WinGo_30S','WinGo 30sec','/img/6007/gamelogo/ARLottery/014807811-35339-file_20260504134807805.webp',44,98.0,0,1),
('Lottery','ARLottery',1001,'WinGo_1M','WinGo 1 Min','/img/6007/gamelogo/ARLottery/014823899-35341-file_20260504134823893.webp',43,98.0,0,1),
('Lottery','ARLottery',1003,'WinGo_3M','WinGo 3 Min','/img/6007/gamelogo/ARLottery/014841344-35343-file_20260504134841337.webp',42,98.0,0,1),
('Lottery','ARLottery',1005,'WinGo_5M','WinGo 5 Min','/img/6007/gamelogo/ARLottery/014856938-35345-file_20260504134856932.webp',41,98.0,0,1),
('Lottery','ARLottery',10501,'MotoRace_1M','Moto Racing','/img/6007/gamelogo/ARLottery/015329660-35371-file_20260504135329651.webp',36,98.0,0,1),
('Lottery','ARLottery',10101,'K3_1M','K3 1 Min','/img/6007/gamelogo/ARLottery/014920441-35347-file_20260504134920434.webp',34,98.0,0,1),
('Lottery','ARLottery',10103,'K3_3M','K3 3 Min','/img/6007/gamelogo/ARLottery/014939412-35349-file_20260504134939406.webp',33,98.0,0,1),
('Lottery','ARLottery',10201,'D5_1M','5D 1 Min','/img/6007/gamelogo/ARLottery/015032194-35355-file_20260504135032189.webp',24,98.0,0,1),
('Lottery','ARLottery',10203,'D5_3M','5D 3 Min','/img/6007/gamelogo/ARLottery/015045373-35357-file_20260504135045367.webp',23,98.0,0,1),
('Lottery','ARLottery',10301,'TrxWinGo_1M','Trx WinGo 1 Min','/img/6007/gamelogo/ARLottery/015204001-35363-file_20260504135203994.webp',14,98.0,0,1),
('Lottery','ARLottery',10303,'TrxWinGo_3M','Trx WinGo 3 Min','/img/6006/gamelogo/ARLottery/031841608-37005-file_20260507151841602.webp',13,98.0,0,1),
('Lottery','ARLottery',10305,'TrxWinGo_5M','Trx WinGo 5 Min','/img/6006/gamelogo/ARLottery/031841608-37005-file_20260507151841602.webp',12,98.0,0,1),
('Lottery','ARLottery',10105,'K3_5M','K3 5 Min','/img/6006/gamelogo/ARLottery/031236245-36983-file_20260507151236239.webp',32,98.0,0,1),
('Lottery','ARLottery',10205,'D5_5M','5D 5 Min','/img/6006/gamelogo/ARLottery/031415909-36991-file_20260507151415901.webp',22,98.0,0,1),
('Lottery','ARLottery',10503,'MotoRace_3M','Moto Racing 3 Min','/img/6006/gamelogo/ARLottery/031716231-37001-file_20260507151716224.webp',35,98.0,0,1),
('Lottery','ARLottery',10505,'MotoRace_5M','Moto Racing 5 Min','/img/6006/gamelogo/ARLottery/031716231-37001-file_20260507151716224.webp',34,98.0,0,1);
INSERT INTO lottery_game_settings(game_code, win_rate, force_mode, fee_percent, payout_number, payout_color, payout_violet, payout_bigsmall, payout_k3, payout_5d, payout_moto, immediate_settle) VALUES
('*',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('WinGo_30S',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('WinGo_1M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('WinGo_3M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('WinGo_5M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('K3_1M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('K3_3M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('D5_1M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('D5_3M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('MotoRace_1M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('TrxWinGo_1M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('TrxWinGo_3M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('TrxWinGo_5M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('K3_5M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('D5_5M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('MotoRace_3M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0),
('MotoRace_5M',45.00,'auto',0.00,9.00,2.00,4.50,2.00,2.00,9.50,9.50,0)
ON DUPLICATE KEY UPDATE win_rate=VALUES(win_rate), force_mode=VALUES(force_mode), fee_percent=VALUES(fee_percent), payout_number=VALUES(payout_number), payout_color=VALUES(payout_color), payout_violet=VALUES(payout_violet), payout_bigsmall=VALUES(payout_bigsmall), payout_k3=VALUES(payout_k3), payout_5d=VALUES(payout_5d), payout_moto=VALUES(payout_moto), immediate_settle=VALUES(immediate_settle);
SQL;
}


// ===============================
// V24: real site toggle defaults + admin pro controls
// ===============================
function ensure_v24_tables(mysqli $conn): void
{
    $site = v12_get_setting_json($conn, 'site_settings', v13_default_site_settings());
    $site = array_merge(v13_default_site_settings(), [
        'maintenance_enabled' => !empty($site['maintenance_enabled']),
        'maintenance_text' => $site['maintenance_text'] ?? 'Site is under maintenance. Please try again later.',
        'home_enabled' => array_key_exists('home_enabled',$site) ? (bool)$site['home_enabled'] : true,
        'popup_enabled' => array_key_exists('popup_enabled',$site) ? (bool)$site['popup_enabled'] : true,
        'captcha_enabled' => !empty($site['captcha_enabled']),
        'gift_enabled' => array_key_exists('gift_enabled',$site) ? (bool)$site['gift_enabled'] : true,
        'bonus_enabled' => array_key_exists('bonus_enabled',$site) ? (bool)$site['bonus_enabled'] : true,
        'vip_enabled' => array_key_exists('vip_enabled',$site) ? (bool)$site['vip_enabled'] : true,
        'agent_enabled' => array_key_exists('agent_enabled',$site) ? (bool)$site['agent_enabled'] : true,
        'workorder_enabled' => array_key_exists('workorder_enabled',$site) ? (bool)$site['workorder_enabled'] : true,
        'recharge_enabled' => array_key_exists('recharge_enabled',$site) ? (bool)$site['recharge_enabled'] : true,
        'withdraw_enabled' => array_key_exists('withdraw_enabled',$site) ? (bool)$site['withdraw_enabled'] : true,
        'invite_enabled' => array_key_exists('invite_enabled',$site) ? (bool)$site['invite_enabled'] : true,
        'lottery_enabled' => array_key_exists('lottery_enabled',$site) ? (bool)$site['lottery_enabled'] : true,
        'wingo_enabled' => array_key_exists('wingo_enabled',$site) ? (bool)$site['wingo_enabled'] : true,
        'k3_enabled' => array_key_exists('k3_enabled',$site) ? (bool)$site['k3_enabled'] : true,
        'd5_enabled' => array_key_exists('d5_enabled',$site) ? (bool)$site['d5_enabled'] : true,
        'moto_enabled' => array_key_exists('moto_enabled',$site) ? (bool)$site['moto_enabled'] : true,
        'trx_enabled' => array_key_exists('trx_enabled',$site) ? (bool)$site['trx_enabled'] : true,
        'min_withdraw_amount' => (float)($site['min_withdraw_amount'] ?? $site['min_withdraw'] ?? 110),
        'withdraw_need_bet_multiplier' => (float)($site['withdraw_need_bet_multiplier'] ?? $site['turnover_required'] ?? 0),
        'telegram_url' => (string)($site['telegram_url'] ?? $site['service_telegram'] ?? ''),
        'service_telegram' => (string)($site['service_telegram'] ?? $site['telegram_url'] ?? ''),
        'common_notice' => (string)($site['common_notice'] ?? $site['maintenance_text'] ?? ''),
        'game_history_page_size' => (int)($site['game_history_page_size'] ?? 10),
    ], $site);
    v12_save_setting_json($conn, 'site_settings', $site);

    @ $conn->query("CREATE TABLE IF NOT EXISTS admin_operation_notes (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      module_name VARCHAR(80) DEFAULT '',
      note_title VARCHAR(190) DEFAULT '',
      note_body TEXT,
      status TINYINT NOT NULL DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id), KEY idx_module(module_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ===============================
// V33: complete game variants, daily check-in and advanced payment controls
// ===============================
function ensure_v33_tables(mysqli $conn): void
{
    $sqls = [
        "CREATE TABLE IF NOT EXISTS daily_checkin_claims (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          claim_date DATE NOT NULL,
          day_index TINYINT UNSIGNED NOT NULL DEFAULT 1,
          reward_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
          order_no VARCHAR(80) NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id),
          UNIQUE KEY uq_daily_checkin_user_date(user_id,claim_date),
          UNIQUE KEY uq_daily_checkin_order(order_no),
          KEY idx_daily_checkin_user(user_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS agent_salary_records (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          agent_user_id BIGINT UNSIGNED NOT NULL,
          period_date DATE NOT NULL,
          team_deposit DECIMAL(18,2) NOT NULL DEFAULT 0,
          team_bet DECIMAL(18,2) NOT NULL DEFAULT 0,
          salary_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
          status ENUM('pending','paid') NOT NULL DEFAULT 'pending',
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id),
          UNIQUE KEY uq_agent_salary(agent_user_id,period_date),
          KEY idx_agent_salary_date(period_date,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS payment_methods (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          name VARCHAR(120) NOT NULL,
          recharge_type VARCHAR(60) NOT NULL DEFAULT 'UPI',
          method_kind VARCHAR(30) NOT NULL DEFAULT 'UPI',
          state TINYINT NOT NULL DEFAULT 1,
          deposit_enabled TINYINT NOT NULL DEFAULT 1,
          withdraw_enabled TINYINT NOT NULL DEFAULT 1,
          sort INT NOT NULL DEFAULT 0,
          icon_url VARCHAR(255) DEFAULT '',
          selected_icon_url VARCHAR(255) DEFAULT '',
          rate DECIMAL(18,4) NOT NULL DEFAULT 1,
          usdt_rate DECIMAL(18,4) NOT NULL DEFAULT 1,
          min_amount DECIMAL(18,2) NOT NULL DEFAULT 100,
          max_amount DECIMAL(18,2) NOT NULL DEFAULT 50000,
          gift_ratio DECIMAL(10,2) NOT NULL DEFAULT 0,
          quick_config_json LONGTEXT,
          upi_id VARCHAR(190) DEFAULT '',
          upi_name VARCHAR(190) DEFAULT '',
          usdt_address VARCHAR(255) DEFAULT '',
          network VARCHAR(40) DEFAULT 'TRC20',
          qr_image VARCHAR(255) DEFAULT '',
          note VARCHAR(255) DEFAULT '',
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT NULL,
          PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($sqls as $sql) {
        if (!@$conn->query($sql)) error_log('V33 table migration failed: '.$conn->error);
    }

    $columns = [
        'users' => [
            'is_demo' => "ALTER TABLE users ADD COLUMN is_demo TINYINT NOT NULL DEFAULT 0 AFTER is_agent"
        ],
        'payment_methods' => [
            'method_kind' => "ALTER TABLE payment_methods ADD COLUMN method_kind VARCHAR(30) NOT NULL DEFAULT 'UPI' AFTER recharge_type",
            'deposit_enabled' => "ALTER TABLE payment_methods ADD COLUMN deposit_enabled TINYINT NOT NULL DEFAULT 1 AFTER state",
            'withdraw_enabled' => "ALTER TABLE payment_methods ADD COLUMN withdraw_enabled TINYINT NOT NULL DEFAULT 1 AFTER deposit_enabled",
            'usdt_rate' => "ALTER TABLE payment_methods ADD COLUMN usdt_rate DECIMAL(18,4) NOT NULL DEFAULT 1 AFTER rate",
            'usdt_address' => "ALTER TABLE payment_methods ADD COLUMN usdt_address VARCHAR(255) DEFAULT '' AFTER upi_name",
            'network' => "ALTER TABLE payment_methods ADD COLUMN network VARCHAR(40) DEFAULT 'TRC20' AFTER usdt_address"
        ],
        'daily_checkin_claims' => [
            'day_index' => "ALTER TABLE daily_checkin_claims ADD COLUMN day_index TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER claim_date",
            'reward_amount' => "ALTER TABLE daily_checkin_claims ADD COLUMN reward_amount DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER day_index"
        ]
    ];
    foreach ($columns as $table => $list) {
        foreach ($list as $column => $sql) {
            $safeTable = $conn->real_escape_string($table);
            $safeColumn = $conn->real_escape_string($column);
            $result = @$conn->query("SHOW COLUMNS FROM ".$safeTable." LIKE '".$safeColumn."'");
            if ($result instanceof mysqli_result && $result->num_rows > 0) continue;
            if (!@$conn->query($sql)) error_log('V33 column migration failed: '.$conn->error);
        }
    }

    $games = [
        ['K3_10M', 10110, 'K3 10 Min', 31],
        ['D5_10M', 10210, '5D 10 Min', 21],
        ['TrxWinGo_10M', 10310, 'Trx WinGo 10 Min', 11],
    ];
    $stmt = @$conn->prepare("INSERT IGNORE INTO games(category_code,vendor_code,game_id,game_code,name,img,sort,rtp,is_maintenance,status) VALUES('Lottery','ARLottery',?,?,?,?,?,98,0,1)");
    if ($stmt) {
        foreach ($games as $game) {
            [$code,$gameId,$name,$sort] = $game;
            $img = $code === 'K3_10M'
                ? '/img/6006/gamelogo/ARLottery/031236245-36983-file_20260507151236239.webp'
                : ($code === 'D5_10M'
                    ? '/img/6006/gamelogo/ARLottery/031415909-36991-file_20260507151415901.webp'
                    : '/img/6006/gamelogo/ARLottery/031841608-37005-file_20260507151841602.webp');
            $stmt->bind_param('isssi', $gameId, $code, $name, $img, $sort);
            $stmt->execute();
        }
    }

    $stmt = @$conn->prepare("INSERT IGNORE INTO lottery_game_settings(game_code,win_rate,force_mode,fee_percent,payout_number,payout_color,payout_violet,payout_bigsmall,payout_k3,payout_5d,payout_moto,immediate_settle) VALUES(?,45,'auto',0,9,2,4.5,2,2,9.5,9.5,0)");
    if ($stmt) {
        foreach (['K3_10M','D5_10M','TrxWinGo_10M'] as $code) {
            $stmt->bind_param('s', $code);
            $stmt->execute();
        }
    }
}

// ===============================
// V34: support assets, recursive agent accounting and provider adapters
// ===============================
function ensure_v34_tables(mysqli $conn): void
{
    wallet_ensure_column($conn, 'users', 'must_change_password', "ALTER TABLE users ADD COLUMN must_change_password TINYINT NOT NULL DEFAULT 0 AFTER status");

    $sqls = [
        "CREATE TABLE IF NOT EXISTS third_party_providers (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          vendor_code VARCHAR(80) NOT NULL,
          display_name VARCHAR(120) NOT NULL,
          integration_mode ENUM('disabled','demo','official') NOT NULL DEFAULT 'disabled',
          launch_url_template VARCHAR(1000) DEFAULT '',
          api_base_url VARCHAR(500) DEFAULT '',
          merchant_id VARCHAR(190) DEFAULT '',
          secret_env_key VARCHAR(190) DEFAULT '',
          callback_secret_env_key VARCHAR(190) DEFAULT '',
          allowed_host VARCHAR(190) DEFAULT '',
          currency VARCHAR(20) DEFAULT 'INR',
          status TINYINT NOT NULL DEFAULT 0,
          notes VARCHAR(500) DEFAULT '',
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT NULL,
          PRIMARY KEY(id),
          UNIQUE KEY uq_provider_vendor(vendor_code),
          KEY idx_provider_status(status,integration_mode)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS third_party_launch_sessions (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id BIGINT UNSIGNED NOT NULL,
          provider_id BIGINT UNSIGNED NOT NULL,
          vendor_code VARCHAR(80) NOT NULL,
          game_code VARCHAR(120) NOT NULL,
          launch_token_hash CHAR(64) NOT NULL,
          client_ip VARCHAR(80) DEFAULT '',
          user_agent VARCHAR(255) DEFAULT '',
          status ENUM('created','launched','expired','closed') NOT NULL DEFAULT 'created',
          expires_at DATETIME NOT NULL,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          last_seen_at DATETIME DEFAULT NULL,
          PRIMARY KEY(id),
          UNIQUE KEY uq_provider_launch_token(launch_token_hash),
          KEY idx_provider_launch_user(user_id,created_at),
          KEY idx_provider_launch_expiry(status,expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS third_party_wallet_transactions (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          provider_id BIGINT UNSIGNED NOT NULL,
          vendor_code VARCHAR(80) NOT NULL,
          user_id BIGINT UNSIGNED NOT NULL,
          external_txn_id VARCHAR(190) NOT NULL,
          external_round_id VARCHAR(190) DEFAULT '',
          action_type ENUM('bet','win','refund','rollback','adjustment') NOT NULL,
          amount DECIMAL(18,2) NOT NULL DEFAULT 0,
          balance_before DECIMAL(18,2) NOT NULL DEFAULT 0,
          balance_after DECIMAL(18,2) NOT NULL DEFAULT 0,
          request_hash CHAR(64) DEFAULT '',
          payload_json LONGTEXT,
          status ENUM('accepted','rejected','duplicate') NOT NULL DEFAULT 'accepted',
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY(id),
          UNIQUE KEY uq_provider_external_txn(vendor_code,external_txn_id),
          KEY idx_provider_wallet_user(user_id,created_at),
          KEY idx_provider_wallet_round(vendor_code,external_round_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach ($sqls as $sql) if (!@$conn->query($sql)) error_log('V34 table migration failed: '.$conn->error);

    wallet_ensure_column($conn, 'agent_commissions', 'level_no', "ALTER TABLE agent_commissions ADD COLUMN level_no TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER status");
    wallet_ensure_column($conn, 'agent_commissions', 'settled_at', "ALTER TABLE agent_commissions ADD COLUMN settled_at DATETIME DEFAULT NULL AFTER level_no");
    wallet_ensure_index($conn, 'users', 'idx_agent_parent', 'ALTER TABLE users ADD INDEX idx_agent_parent(agent_parent_id,status,id)');

    $providers = [
        ['JILI','JILI Games'],['JDB','JDB Games'],['SPRIBE','Aviator / Spribe'],
        ['PG','PG Soft'],['EVO','Evolution'],['ARGame','AR Games']
    ];
    $stmt = @$conn->prepare("INSERT IGNORE INTO third_party_providers(vendor_code,display_name,integration_mode,status,notes,created_at) VALUES(?,?,'disabled',0,'Add official merchant credentials and an HTTPS launch template in Admin.',NOW())");
    if ($stmt) foreach ($providers as $provider) { $stmt->bind_param('ss',$provider[0],$provider[1]); $stmt->execute(); }

    // Schema upgrades never create administrators or reset their credentials.

    // V34 brand/asset migration: update only values shipped by the former
    // bundled frontend. Custom messages and admin-created promotions remain intact.
    @ $conn->query("UPDATE notifications SET title='Welcome to 9K.Club', content=REPLACE(content,'Maan Win','9K.Club') WHERE title='Welcome to Maan Win'");
    @ $conn->query("UPDATE notifications SET title=REPLACE(title,'MaanWin','9K.Club'), content=REPLACE(content,'MaanWin','9K.Club') WHERE title LIKE '%MaanWin%' OR content LIKE '%MaanWin%'");
    @ $conn->query("UPDATE banners SET name='9K.Club Support', icon_url='/img/6016/banner/050746856-141141-file_20260919170746848.webp', jump_type=2, jump_detail='/workOrder', status=1 WHERE id=26 AND (name='Maan Win Support' OR name='9K.Club Support')");
    @ $conn->query("UPDATE banners SET name='9K.Club Telegram', icon_url='/img/6016/banner/050637280-141139-file_20260919170637261.webp', jump_type=2, jump_detail='https://t.me/www9kclubbot', status=1 WHERE id=25 AND (name='Maan Win Telegram' OR name='9K.Club Telegram')");

    $gameImages = [
        'WinGo_30S'=>'/img/6016/gamelogo/ARLottery/023911577-140947-file_20260919143911571.webp',
        'WinGo_1M'=>'/img/6016/gamelogo/ARLottery/023941348-140950-file_20260919143941339.webp',
        'WinGo_3M'=>'/img/6016/gamelogo/ARLottery/023958969-140952-file_20260919143958962.webp',
        'WinGo_5M'=>'/img/6016/gamelogo/ARLottery/024012094-140954-file_20260919144012085.webp',
        'K3_1M'=>'/img/6016/gamelogo/ARLottery/024317536-140956-file_20260919144317529.webp',
        'K3_3M'=>'/img/6016/gamelogo/ARLottery/024336897-140958-file_20260919144336886.webp',
        'K3_5M'=>'/img/6016/gamelogo/ARLottery/024351171-140960-file_20260919144351165.webp',
        'K3_10M'=>'/img/6016/gamelogo/ARLottery/024407972-140962-file_20260919144407966.webp',
        'D5_1M'=>'/img/6016/gamelogo/ARLottery/024423060-140964-file_20260919144423051.webp',
        'D5_3M'=>'/img/6016/gamelogo/ARLottery/024441112-140966-file_20260919144441106.webp',
        'D5_5M'=>'/img/6016/gamelogo/ARLottery/024458379-140968-file_20260919144458373.webp',
        'D5_10M'=>'/img/6016/gamelogo/ARLottery/024514246-140970-file_20260919144514239.webp',
        'TrxWinGo_1M'=>'/img/6016/gamelogo/ARLottery/024608321-140972-file_20260919144608314.webp',
        'TrxWinGo_3M'=>'/img/6016/gamelogo/ARLottery/024623656-140974-file_20260919144623651.webp',
        'TrxWinGo_5M'=>'/img/6016/gamelogo/ARLottery/024637369-140976-file_20260919144637361.webp',
        'TrxWinGo_10M'=>'/img/6016/gamelogo/ARLottery/024809498-140978-file_20260919144809491.webp',
        'MotoRace_1M'=>'/img/6016/gamelogo/ARLottery/024828314-140980-file_20260919144828306.webp',
        'MotoRace_3M'=>'/img/6016/gamelogo/ARLottery/024828314-140980-file_20260919144828306.webp',
        'MotoRace_5M'=>'/img/6016/gamelogo/ARLottery/024828314-140980-file_20260919144828306.webp',
    ];
    $gameImageStmt = @$conn->prepare("UPDATE games SET img=? WHERE vendor_code='ARLottery' AND game_code=?");
    if ($gameImageStmt) foreach ($gameImages as $code=>$img) {
        $gameImageStmt->bind_param('ss', $img, $code);
        $gameImageStmt->execute();
    }
}
