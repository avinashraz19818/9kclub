<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/_core/bootstrap.php';

function dw_admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // cPanel can point session.save_path at a PHP version directory that no longer
    // exists. Use an account-local private directory and create it on demand.
    $sessionDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'maanwin-admin-' . substr(hash('sha256', __DIR__), 0, 16);
    if ((!is_dir($sessionDir) && !@mkdir($sessionDir, 0700, true)) || !is_writable($sessionDir)) {
        $sessionDir = __DIR__ . '/.sessions';
        if ((!is_dir($sessionDir) && !@mkdir($sessionDir, 0700, true)) || !is_writable($sessionDir)) {
            throw new RuntimeException('Admin session storage is not writable.');
        }
        @file_put_contents($sessionDir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    session_save_path($sessionDir);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('maanwin_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    if (!@session_start() || session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Admin session could not be started.');
    }
}

function dw_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dw_admin_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80);
}

function dw_admin_log_login(mysqli $conn, int $adminId, string $username, bool $success, string $reason = ''): void
{
    $ip = dw_admin_ip();
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ok = $success ? 1 : 0;
    $stmt = @$conn->prepare('INSERT INTO admin_login_logs(admin_user_id,username,ip,user_agent,was_successful,failure_reason,created_at) VALUES(?,?,?,?,?,?,NOW())');
    if ($stmt) {
        $stmt->bind_param('isssis', $adminId, $username, $ip, $ua, $ok, $reason);
        $stmt->execute();
    }
}

function dw_admin_password_matches(string $password, string $storedHash): bool
{
    return $password !== '' && $storedHash !== '' && password_verify($password, $storedHash);
}

function dw_admin_password_is_modern(string $storedHash): bool
{
    $info = password_get_info($storedHash);
    return (($info['algoName'] ?? 'unknown') !== 'unknown');
}

function dw_admin_ensure_root(mysqli $conn): void
{
    // Accounts are managed explicitly; no automatic credential recovery.
}

function dw_admin_login(mysqli $conn, string $username, string $password): array
{
    $username = trim($username);
    if ($username !== 'rdxsmm1234') return [false, 'Invalid admin credentials.'];
    $ip = dw_admin_ip();
    if ($username === '' || $password === '') return [false, 'Username and password are required.'];

    $stmt = @$conn->prepare('SELECT COUNT(*) c FROM admin_login_logs WHERE was_successful=0 AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE) AND (username=? OR ip=?)');
    if ($stmt) {
        $stmt->bind_param('ss', $username, $ip);
        $stmt->execute();
        $attempts = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        if ($attempts >= 10) {
            dw_admin_log_login($conn, 0, $username, false, 'rate_limited');
            return [false, 'Too many failed attempts. Try again after 15 minutes.'];
        }
    }

    $stmt = @$conn->prepare("SELECT * FROM users WHERE username=? AND role='admin' LIMIT 1");
    if (!$stmt) return [false, 'Admin database is unavailable.'];
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    if (!$admin || (int)$admin['status'] !== 1 || !dw_admin_password_matches($password, (string)$admin['password_hash'])) {
        dw_admin_log_login($conn, (int)($admin['id'] ?? 0), $username, false, !$admin ? 'not_found' : 'invalid_credentials');
        return [false, 'Invalid admin credentials.'];
    }

    // Upgrade a successfully verified legacy hash to PHP's current password hash.
    if (!dw_admin_password_is_modern((string)$admin['password_hash'])) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $adminIdForHash = (int)$admin['id'];
        $upgrade = @$conn->prepare('UPDATE users SET password_hash=?,last_password_change_at=NOW() WHERE id=?');
        if ($upgrade) { $upgrade->bind_param('si', $newHash, $adminIdForHash); $upgrade->execute(); }
    }

    session_regenerate_id(true);
    $_SESSION['dw_admin_id'] = (int)$admin['id'];
    $_SESSION['dw_admin_username'] = (string)$admin['username'];
    $_SESSION['dw_admin_login_at'] = time();
    $_SESSION['dw_admin_last_seen'] = time();
    $adminId = (int)$admin['id'];
    $stmt = @$conn->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?');
    if ($stmt) { $stmt->bind_param('i', $adminId); $stmt->execute(); }
    dw_admin_log_login($conn, $adminId, $username, true);
    admin_audit_log('admin_login', 'admin', $adminId, [], $adminId);
    return [true, 'Welcome back.'];
}

function dw_admin_logout(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }
}

function dw_admin_user(mysqli $conn): ?array
{
    $id = (int)($_SESSION['dw_admin_id'] ?? 0);
    if ($id <= 0) return null;
    if ((int)($_SESSION['dw_admin_last_seen'] ?? 0) < time() - 7200) {
        dw_admin_logout();
        return null;
    }
    $stmt = @$conn->prepare("SELECT id,username,nickname,photo,role,status,must_change_password,last_login_at FROM users WHERE id=? AND role='admin' AND status=1 LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    if (!$admin || $admin['username'] !== 'rdxsmm1234') {
        dw_admin_logout();
        return null;
    }
    $_SESSION['dw_admin_last_seen'] = time();
    return $admin;
}

function dw_admin_permissions(mysqli $conn, array $admin): array
{
    if ((int)$admin['id'] === 1 || strtolower((string)$admin['username']) === 'admin') return ['*'];
    $username = (string)$admin['username'];
    $stmt = @$conn->prepare('SELECT permissions,status FROM admin_permissions WHERE admin_user=? LIMIT 1');
    if (!$stmt) return ['dashboard'];
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row || (int)$row['status'] !== 1) return ['dashboard'];
    $decoded = json_decode((string)$row['permissions'], true);
    if (!is_array($decoded)) return ['dashboard'];
    $isList = $decoded === [] || array_keys($decoded) === range(0, count($decoded) - 1);
    if ($isList) return array_values(array_map('strval', $decoded));
    return array_keys(array_filter($decoded));
}

function dw_can(array $permissions, string $permission): bool
{
    return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
}

function dw_require_permission(array $permissions, string $permission): void
{
    if (!dw_can($permissions, $permission)) {
        http_response_code(403);
        throw new RuntimeException('You do not have permission for this action.');
    }
}

function dw_csrf_token(): string
{
    if (empty($_SESSION['dw_admin_csrf'])) $_SESSION['dw_admin_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['dw_admin_csrf'];
}

function dw_verify_csrf(): void
{
    $token = (string)($_POST['_csrf'] ?? '');
    if ($token === '' || !hash_equals(dw_csrf_token(), $token)) {
        throw new RuntimeException('Security token expired. Refresh the page and try again.');
    }
}

function dw_flash(string $type, string $message): void
{
    $_SESSION['dw_admin_flash'] = ['type'=>$type, 'message'=>$message];
}

function dw_take_flash(): ?array
{
    $flash = $_SESSION['dw_admin_flash'] ?? null;
    unset($_SESSION['dw_admin_flash']);
    return is_array($flash) ? $flash : null;
}

function dw_redirect(string $page, array $query = []): void
{
    $query = array_merge(['page'=>$page], $query);
    header('Location: index.php?' . http_build_query($query));
    exit;
}

function dw_wingo_return_game(string $game): string
{
    $allowed = [
        'WinGo_30S','WinGo_1M','WinGo_3M','WinGo_5M',
        'K3_1M','K3_3M','K3_5M','K3_10M',
        'D5_1M','D5_3M','D5_5M','D5_10M',
        'TrxWinGo_1M','TrxWinGo_3M','TrxWinGo_5M','TrxWinGo_10M',
    ];
    return in_array($game, $allowed, true) ? $game : '';
}

function dw_scalar(mysqli $conn, string $sql, $default = 0)
{
    $rs = @$conn->query($sql);
    if (!$rs) return $default;
    $row = $rs->fetch_row();
    return $row ? $row[0] : $default;
}

function dw_rows(mysqli $conn, string $sql): array
{
    $rows = [];
    $rs = @$conn->query($sql);
    if ($rs) while ($row = $rs->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function dw_setting(mysqli $conn, string $key, array $default = []): array
{
    return v12_get_setting_json($conn, $key, $default);
}

function dw_save_setting(mysqli $conn, string $key, array $value): void
{
    v12_save_setting_json($conn, $key, $value);
}

function dw_money($value): string
{
    return number_format((float)$value, 2, '.', ',');
}

function dw_mask_account(string $value): string
{
    $value = trim($value);
    $len = strlen($value);
    if ($len <= 5) return str_repeat('*', max(0, $len - 2)) . substr($value, -2);
    return substr($value, 0, 2) . str_repeat('*', max(3, $len - 6)) . substr($value, -4);
}

function dw_admin_audit(mysqli $conn, array $admin, string $action, string $targetType, int $targetId, array $data = []): void
{
    admin_audit_log($action, $targetType, $targetId, $data, (int)$admin['id']);
}

function dw_random_order(string $prefix): string
{
    return $prefix . date('ymdHis') . random_int(1000, 9999);
}

function dw_post_string(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function dw_post_int(string $key, int $default = 0): int
{
    return (int)($_POST[$key] ?? $default);
}

function dw_post_float(string $key, float $default = 0): float
{
    return round((float)($_POST[$key] ?? $default), 2);
}

function dw_allowed_page(string $page): string
{
    $allowed = ['dashboard','users','recharges','withdrawals','ledger','wagers','games','providers','wingo','gifts','payments','content','vip','tasks','wheels','support','agents','risk','settings','admins','audit','system'];
    return in_array($page, $allowed, true) ? $page : 'dashboard';
}

function dw_csv_download(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}
