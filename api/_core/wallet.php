<?php
/**
 * Maan Win wallet ledger and wager requirement service.
 *
 * All balance-changing code should use these helpers so that the user-facing
 * Balance Record always contains a recognised type, the resulting balance,
 * an order number and a readable description.
 */

function wallet_ensure_column(mysqli $conn, string $table, string $column, string $sql): void
{
    $tableEsc = str_replace('`', '', $table);
    $columnEsc = $conn->real_escape_string($column);
    $res = @$conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    if ($res instanceof mysqli_result && $res->num_rows > 0) return;
    @$conn->query($sql);
}

function wallet_ensure_index(mysqli $conn, string $table, string $index, string $sql): void
{
    $tableEsc = str_replace(chr(96), '', $table);
    $indexEsc = $conn->real_escape_string($index);
    $res = @$conn->query("SHOW INDEX FROM {$tableEsc} WHERE Key_name='{$indexEsc}'");
    if ($res instanceof mysqli_result && $res->num_rows > 0) return;
    @$conn->query($sql);
}

function ensure_v32_wallet_tables(mysqli $conn): void
{
    @$conn->query("CREATE TABLE IF NOT EXISTS wager_requirements (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      source_type VARCHAR(40) NOT NULL,
      source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      source_order_no VARCHAR(80) NOT NULL,
      locked_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      required_turnover DECIMAL(18,2) NOT NULL DEFAULT 0,
      completed_turnover DECIMAL(18,2) NOT NULL DEFAULT 0,
      status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
      metadata_json LONGTEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME DEFAULT NULL,
      cancelled_at DATETIME DEFAULT NULL,
      PRIMARY KEY(id),
      UNIQUE KEY uq_wager_source(user_id,source_type,source_order_no),
      KEY idx_wager_user_status(user_id,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @$conn->query("CREATE TABLE IF NOT EXISTS wager_requirement_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      requirement_id BIGINT UNSIGNED NOT NULL,
      bet_order_no VARCHAR(80) NOT NULL,
      turnover_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      UNIQUE KEY uq_wager_event(requirement_id,bet_order_no),
      KEY idx_wager_event_user(user_id,created_at),
      KEY idx_wager_event_bet(user_id,bet_order_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $eventIndex = @$conn->query("SHOW INDEX FROM wager_requirement_events WHERE Key_name='idx_wager_event_bet'");
    if (!($eventIndex instanceof mysqli_result) || $eventIndex->num_rows === 0) {
        @$conn->query('ALTER TABLE wager_requirement_events ADD INDEX idx_wager_event_bet(user_id,bet_order_no)');
    }

    @$conn->query("CREATE TABLE IF NOT EXISTS admin_login_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      admin_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      username VARCHAR(120) NOT NULL,
      ip VARCHAR(80) DEFAULT '',
      user_agent VARCHAR(255) DEFAULT '',
      was_successful TINYINT NOT NULL DEFAULT 0,
      failure_reason VARCHAR(190) DEFAULT '',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY(id),
      KEY idx_admin_login_user_time(username,created_at),
      KEY idx_admin_login_ip_time(ip,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    @$conn->query("CREATE TABLE IF NOT EXISTS risk_flags (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      flag_type VARCHAR(80) NOT NULL,
      severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
      status ENUM('open','reviewing','resolved','dismissed') NOT NULL DEFAULT 'open',
      reason VARCHAR(255) DEFAULT '',
      evidence_json LONGTEXT,
      admin_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      resolved_at DATETIME DEFAULT NULL,
      PRIMARY KEY(id),
      KEY idx_risk_user_status(user_id,status),
      KEY idx_risk_severity_status(severity,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    wallet_ensure_column($conn, 'financial_records', 'balance_before', "ALTER TABLE financial_records ADD COLUMN balance_before DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER amount");
    wallet_ensure_column($conn, 'financial_records', 'description', "ALTER TABLE financial_records ADD COLUMN description VARCHAR(255) DEFAULT '' AFTER remark");
    wallet_ensure_column($conn, 'financial_records', 'metadata_json', "ALTER TABLE financial_records ADD COLUMN metadata_json LONGTEXT AFTER description");
    wallet_ensure_column($conn, 'financial_records', 'status', "ALTER TABLE financial_records ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'Success' AFTER type");

    wallet_ensure_column($conn, 'withdraw_requests', 'order_no', "ALTER TABLE withdraw_requests ADD COLUMN order_no VARCHAR(80) DEFAULT '' AFTER user_id");
    wallet_ensure_column($conn, 'withdraw_requests', 'wallet_id', "ALTER TABLE withdraw_requests ADD COLUMN wallet_id BIGINT UNSIGNED DEFAULT NULL");
    wallet_ensure_column($conn, 'withdraw_requests', 'wallet_snapshot', "ALTER TABLE withdraw_requests ADD COLUMN wallet_snapshot TEXT");
    wallet_ensure_column($conn, 'withdraw_requests', 'is_reserved', "ALTER TABLE withdraw_requests ADD COLUMN is_reserved TINYINT NOT NULL DEFAULT 0");
    wallet_ensure_column($conn, 'withdraw_requests', 'balance_before', "ALTER TABLE withdraw_requests ADD COLUMN balance_before DECIMAL(18,2) NOT NULL DEFAULT 0");
    wallet_ensure_column($conn, 'withdraw_requests', 'balance_after', "ALTER TABLE withdraw_requests ADD COLUMN balance_after DECIMAL(18,2) NOT NULL DEFAULT 0");
    wallet_ensure_column($conn, 'withdraw_requests', 'processed_at', "ALTER TABLE withdraw_requests ADD COLUMN processed_at DATETIME DEFAULT NULL");

    wallet_ensure_column($conn, 'gift_code_claims', 'claim_no', "ALTER TABLE gift_code_claims ADD COLUMN claim_no VARCHAR(80) DEFAULT '' AFTER user_id");
    wallet_ensure_column($conn, 'gift_code_claims', 'balance_before', "ALTER TABLE gift_code_claims ADD COLUMN balance_before DECIMAL(18,2) NOT NULL DEFAULT 0");
    wallet_ensure_column($conn, 'gift_code_claims', 'balance_after', "ALTER TABLE gift_code_claims ADD COLUMN balance_after DECIMAL(18,2) NOT NULL DEFAULT 0");
    wallet_ensure_column($conn, 'gift_code_claims', 'ip', "ALTER TABLE gift_code_claims ADD COLUMN ip VARCHAR(80) DEFAULT ''");
    wallet_ensure_column($conn, 'gift_code_claims', 'device_id', "ALTER TABLE gift_code_claims ADD COLUMN device_id VARCHAR(120) DEFAULT ''");

    wallet_ensure_column($conn, 'users', 'turnover_required', "ALTER TABLE users ADD COLUMN turnover_required DECIMAL(18,2) NOT NULL DEFAULT 0");
    wallet_ensure_column($conn, 'users', 'turnover_completed', "ALTER TABLE users ADD COLUMN turnover_completed DECIMAL(18,2) NOT NULL DEFAULT 0");
    wallet_ensure_column($conn, 'users', 'total_deposit', "ALTER TABLE users ADD COLUMN total_deposit DECIMAL(18,2) NOT NULL DEFAULT 0");
    wallet_ensure_column($conn, 'users', 'total_withdraw', "ALTER TABLE users ADD COLUMN total_withdraw DECIMAL(18,2) NOT NULL DEFAULT 0");
    wallet_ensure_column($conn, 'users', 'total_bet', "ALTER TABLE users ADD COLUMN total_bet DECIMAL(18,2) NOT NULL DEFAULT 0");
    wallet_ensure_column($conn, 'users', 'must_change_password', "ALTER TABLE users ADD COLUMN must_change_password TINYINT NOT NULL DEFAULT 0");
    wallet_ensure_column($conn, 'users', 'last_password_change_at', "ALTER TABLE users ADD COLUMN last_password_change_at DATETIME DEFAULT NULL");
    @$conn->query("UPDATE users SET must_change_password=1 WHERE id=1 AND username='admin' AND role='admin' AND last_password_change_at IS NULL");

    wallet_ensure_index($conn, 'financial_records', 'idx_fin_user_created', 'ALTER TABLE financial_records ADD INDEX idx_fin_user_created(user_id,created_at)');
    wallet_ensure_index($conn, 'financial_records', 'idx_fin_order', 'ALTER TABLE financial_records ADD INDEX idx_fin_order(order_no)');
    wallet_ensure_index($conn, 'financial_records', 'idx_fin_type_created', 'ALTER TABLE financial_records ADD INDEX idx_fin_type_created(type,created_at)');
    wallet_ensure_index($conn, 'withdraw_requests', 'idx_withdraw_status_created', 'ALTER TABLE withdraw_requests ADD INDEX idx_withdraw_status_created(status,created_at)');
    wallet_ensure_index($conn, 'withdraw_requests', 'idx_withdraw_order', 'ALTER TABLE withdraw_requests ADD INDEX idx_withdraw_order(order_no)');
    wallet_ensure_index($conn, 'gift_code_claims', 'idx_gift_claim_user_time', 'ALTER TABLE gift_code_claims ADD INDEX idx_gift_claim_user_time(user_id,created_at)');

    if (function_exists('v12_get_setting_json') && function_exists('v12_save_setting_json')) {
        $settings = v12_get_setting_json($conn, 'site_settings', []);
        $legacyMultiplier = (float)($settings['withdraw_need_bet_multiplier'] ?? $settings['turnover_required'] ?? 0);
        $defaults = [
            'wager_lock_enabled' => true,
            'deposit_turnover_multiplier' => $legacyMultiplier > 0 ? $legacyMultiplier : 1.0,
            'recharge_gift_turnover_multiplier' => 0.0,
            'gift_code_turnover_multiplier' => 1.0,
            'min_withdraw_amount' => 110.0,
            'withdraw_daily_limit' => 3,
            'withdraw_daily_amount_limit' => 50000.0,
        ];
        $settings = array_merge($defaults, $settings);
        // V32 behaviour is 1x deposit turnover unless the owner already chose a value.
        if (!isset($settings['deposit_turnover_multiplier']) || (float)$settings['deposit_turnover_multiplier'] < 0) {
            $settings['deposit_turnover_multiplier'] = 1.0;
        }
        $settings['withdraw_need_bet_multiplier'] = (float)$settings['deposit_turnover_multiplier'];
        $settings['turnover_required'] = (float)$settings['deposit_turnover_multiplier'];
        v12_save_setting_json($conn, 'site_settings', $settings);
    }
}

function wallet_balance(mysqli $conn, int $userId, bool $forUpdate = false): ?float
{
    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = @$conn->prepare('SELECT balance FROM users WHERE id=? LIMIT 1' . $suffix);
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) return null;
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (float)$row['balance'] : null;
}

function wallet_type_label(string $type): string
{
    $labels = [
        'Recharge' => 'Deposit credited',
        'ManualRecharge' => 'Manual deposit adjustment',
        'RechargeGift' => 'Deposit bonus',
        'BonusRecharge' => 'Deposit bonus',
        'Withdraw' => 'Withdrawal submitted',
        'WithdrawBack' => 'Withdrawal returned',
        'WithdrawReject' => 'Withdrawal rejected and returned',
        'ManualWithdraw' => 'Manual withdrawal adjustment',
        'ActivityReward' => 'Activity reward',
        'InvitedWheel' => 'Invite wheel reward',
        'GiftCode' => 'Gift code reward',
        'VIPReward' => 'VIP reward',
        'DailyCheckInReward' => 'Daily check-in reward',
        'DayWeekTaskReward' => 'Task reward',
        'RechargeWheelSpin' => 'Recharge wheel reward',
        'GameBet' => 'Game bet',
        'GameEnd' => 'Game settlement',
        'GameReturn' => 'Game amount returned',
        'SendCommission' => 'Commission reward',
    ];
    return $labels[$type] ?? preg_replace('/(?<!^)([A-Z])/', ' $1', $type);
}

function wallet_record(
    mysqli $conn,
    int $userId,
    string $recordNo,
    string $orderNo,
    string $type,
    float $amount,
    float $balanceBefore,
    float $balanceAfter,
    string $remark = '',
    string $subType = '',
    string $vendorCode = 'Platform',
    array $metadata = []
): bool {
    $recordNo = trim($recordNo) ?: ('FR' . date('ymdHis') . random_int(1000, 9999));
    $orderNo = trim($orderNo) ?: $recordNo;
    $description = wallet_type_label($type);
    if ($remark === '') $remark = $description;
    $status = 'Success';
    $meta = $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    $stmt = @$conn->prepare('INSERT INTO financial_records(user_id,record_no,order_no,vendor_code,type,status,sub_type,amount,balance_before,back_amount,remark,description,metadata_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
    if (!$stmt) return false;
    $stmt->bind_param('issssssdddsss', $userId, $recordNo, $orderNo, $vendorCode, $type, $status, $subType, $amount, $balanceBefore, $balanceAfter, $remark, $description, $meta);
    return (bool)$stmt->execute();
}

function wallet_apply_delta(
    mysqli $conn,
    int $userId,
    float $delta,
    string $type,
    string $orderNo,
    string $remark = '',
    string $subType = '',
    string $vendorCode = 'Platform',
    array $metadata = [],
    bool $allowNegative = false
): ?array {
    $before = wallet_balance($conn, $userId, true);
    if ($before === null) return null;
    $after = round($before + $delta, 2);
    if (!$allowNegative && $after < 0) return null;
    $stmt = @$conn->prepare('UPDATE users SET balance=? WHERE id=?');
    if (!$stmt) return null;
    $stmt->bind_param('di', $after, $userId);
    if (!$stmt->execute()) return null;
    $recordNo = $orderNo . ($delta < 0 ? 'D' : 'C') . substr(hash('sha256', $type), 0, 4);
    if (!wallet_record($conn, $userId, $recordNo, $orderNo, $type, $delta, $before, $after, $remark, $subType, $vendorCode, $metadata)) return null;
    return ['before'=>$before, 'after'=>$after, 'amount'=>$delta, 'recordNo'=>$recordNo];
}

function wallet_credit_user(
    int $userId,
    float $amount,
    string $type,
    string $orderNo,
    string $remark = '',
    string $subType = '',
    string $vendorCode = 'Platform',
    array $metadata = []
): ?array {
    if ($amount <= 0) return null;
    $conn = db();
    if (!$conn) return null;
    @$conn->begin_transaction();
    $result = wallet_apply_delta($conn, $userId, round($amount, 2), $type, $orderNo, $remark, $subType, $vendorCode, $metadata);
    if (!$result) { @$conn->rollback(); return null; }
    @$conn->commit();
    return $result;
}

function wallet_create_wager_requirement(
    mysqli $conn,
    int $userId,
    string $sourceType,
    int $sourceId,
    string $sourceOrderNo,
    float $lockedAmount,
    float $requiredTurnover,
    array $metadata = []
): int {
    $lockedAmount = round(max(0, $lockedAmount), 2);
    $requiredTurnover = round(max(0, $requiredTurnover), 2);
    if ($lockedAmount <= 0 || $requiredTurnover <= 0) return 0;
    $meta = $metadata ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    $stmt = @$conn->prepare("INSERT IGNORE INTO wager_requirements(user_id,source_type,source_id,source_order_no,locked_amount,required_turnover,completed_turnover,status,metadata_json,created_at) VALUES(?,?,?,?,?,?,0,'active',?,NOW())");
    if (!$stmt) return 0;
    $stmt->bind_param('isisdds', $userId, $sourceType, $sourceId, $sourceOrderNo, $lockedAmount, $requiredTurnover, $meta);
    if (!$stmt->execute() || $stmt->affected_rows <= 0) return 0;
    $id = (int)$conn->insert_id;
    $up = @$conn->prepare('UPDATE users SET turnover_required=turnover_required+? WHERE id=?');
    if ($up) { $up->bind_param('di', $requiredTurnover, $userId); $up->execute(); }
    return $id;
}

function wallet_apply_turnover(mysqli $conn, int $userId, float $betAmount, string $betOrderNo): float
{
    $remaining = round(max(0, $betAmount), 2);
    if ($remaining <= 0) return 0.0;
    $prior = @$conn->prepare('SELECT COALESCE(SUM(turnover_amount),0) applied FROM wager_requirement_events WHERE user_id=? AND bet_order_no=?');
    if ($prior) {
        $prior->bind_param('is', $userId, $betOrderNo);
        $prior->execute();
        $alreadyApplied = round((float)($prior->get_result()->fetch_assoc()['applied'] ?? 0), 2);
        $remaining = round(max(0, $remaining - $alreadyApplied), 2);
        if ($remaining <= 0) return 0.0;
    }
    $stmt = @$conn->prepare("SELECT * FROM wager_requirements WHERE user_id=? AND status='active' AND completed_turnover<required_turnover ORDER BY id ASC FOR UPDATE");
    if (!$stmt) return 0.0;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rs = $stmt->get_result();
    $applied = 0.0;
    while ($remaining > 0 && ($row = $rs->fetch_assoc())) {
        $need = round(max(0, (float)$row['required_turnover'] - (float)$row['completed_turnover']), 2);
        if ($need <= 0) continue;
        $use = min($need, $remaining);
        $requirementId = (int)$row['id'];
        $event = @$conn->prepare('INSERT IGNORE INTO wager_requirement_events(user_id,requirement_id,bet_order_no,turnover_amount,created_at) VALUES(?,?,?,?,NOW())');
        if (!$event) continue;
        $event->bind_param('iisd', $userId, $requirementId, $betOrderNo, $use);
        $event->execute();
        if ($event->affected_rows <= 0) continue;
        $newCompleted = round((float)$row['completed_turnover'] + $use, 2);
        $newStatus = $newCompleted + 0.00001 >= (float)$row['required_turnover'] ? 'completed' : 'active';
        $up = @$conn->prepare("UPDATE wager_requirements SET completed_turnover=?,status=?,completed_at=IF(?='completed',NOW(),completed_at) WHERE id=?");
        if ($up) { $up->bind_param('dssi', $newCompleted, $newStatus, $newStatus, $requirementId); $up->execute(); }
        $remaining = round($remaining - $use, 2);
        $applied = round($applied + $use, 2);
    }
    if ($applied > 0) {
        $up = @$conn->prepare('UPDATE users SET turnover_completed=turnover_completed+? WHERE id=?');
        if ($up) { $up->bind_param('di', $applied, $userId); $up->execute(); }
    }
    return $applied;
}

function wallet_wager_summary(mysqli $conn, int $userId, ?float $balance = null): array
{
    if ($balance === null) $balance = wallet_balance($conn, $userId, false) ?? 0.0;
    $stmt = @$conn->prepare("SELECT COALESCE(SUM(locked_amount),0) locked_amount,COALESCE(SUM(GREATEST(required_turnover-completed_turnover,0)),0) need_turnover,COALESCE(SUM(required_turnover),0) required_turnover,COALESCE(SUM(completed_turnover),0) completed_turnover,COUNT(*) active_count FROM wager_requirements WHERE user_id=? AND status='active'");
    $row = null;
    if ($stmt) { $stmt->bind_param('i', $userId); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); }
    $locked = round((float)($row['locked_amount'] ?? 0), 2);
    $need = round((float)($row['need_turnover'] ?? 0), 2);
    $withdrawable = round(max(0, (float)$balance - $locked), 2);
    return [
        'balance' => round((float)$balance, 2),
        'withdrawableBalance' => $withdrawable,
        'lockedBalance' => min(round((float)$balance, 2), $locked),
        'lockedRequirementAmount' => $locked,
        'needBetAmount' => $need,
        'requiredTurnover' => round((float)($row['required_turnover'] ?? 0), 2),
        'completedTurnover' => round((float)($row['completed_turnover'] ?? 0), 2),
        'activeRequirementCount' => (int)($row['active_count'] ?? 0),
        'isTurnoverComplete' => $need <= 0.00001,
    ];
}

function wallet_approve_recharge(mysqli $conn, int $rechargeId, string $adminNote = ''): array
{
    @$conn->begin_transaction();
    $stmt = @$conn->prepare('SELECT * FROM recharge_orders WHERE id=? FOR UPDATE');
    if (!$stmt) { @$conn->rollback(); return ['ok'=>false,'message'=>'Recharge order unavailable']; }
    $stmt->bind_param('i', $rechargeId); $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) { @$conn->rollback(); return ['ok'=>false,'message'=>'Recharge order not found']; }
    if ((string)$order['status'] === 'Payed') { @$conn->commit(); return ['ok'=>true,'message'=>'Recharge already approved','already'=>true]; }
    if (!in_array((string)$order['status'], ['Wait','PendingReview'], true)) {
        @$conn->rollback(); return ['ok'=>false,'message'=>'Only pending recharge orders can be approved'];
    }

    $userId = (int)$order['user_id'];
    $principal = round((float)$order['amount'], 2);
    $gift = round((float)($order['gift_amount'] ?? 0), 2);
    $orderNo = (string)$order['order_no'];
    $before = wallet_balance($conn, $userId, true);
    if ($before === null) { @$conn->rollback(); return ['ok'=>false,'message'=>'User not found']; }

    $up = @$conn->prepare('UPDATE recharge_orders SET status="Payed",admin_note=?,updated_at=NOW() WHERE id=?');
    if (!$up) { @$conn->rollback(); return ['ok'=>false,'message'=>'Recharge update failed']; }
    $up->bind_param('si', $adminNote, $rechargeId);
    if (!$up->execute()) { @$conn->rollback(); return ['ok'=>false,'message'=>'Recharge update failed']; }

    $current = $before;
    if ($principal > 0) {
        $afterPrincipal = round($current + $principal, 2);
        $up = @$conn->prepare('UPDATE users SET balance=?,total_deposit=total_deposit+? WHERE id=?');
        if (!$up) { @$conn->rollback(); return ['ok'=>false,'message'=>'Balance update failed']; }
        $up->bind_param('ddi', $afterPrincipal, $principal, $userId);
        if (!$up->execute()) { @$conn->rollback(); return ['ok'=>false,'message'=>'Balance update failed']; }
        if (!wallet_record($conn, $userId, $orderNo.'RC', $orderNo, 'Recharge', $principal, $current, $afterPrincipal, 'Deposit approved: '.$orderNo, (string)($order['recharge_type'] ?? ''), 'Recharge', ['rechargeId'=>$rechargeId])) {
            @$conn->rollback(); return ['ok'=>false,'message'=>'Deposit ledger write failed'];
        }
        $current = $afterPrincipal;
    }
    if ($gift > 0) {
        $afterGift = round($current + $gift, 2);
        $up = @$conn->prepare('UPDATE users SET balance=? WHERE id=?');
        if (!$up) { @$conn->rollback(); return ['ok'=>false,'message'=>'Gift balance update failed']; }
        $up->bind_param('di', $afterGift, $userId);
        if (!$up->execute()) { @$conn->rollback(); return ['ok'=>false,'message'=>'Gift balance update failed']; }
        if (!wallet_record($conn, $userId, $orderNo.'RG', $orderNo, 'RechargeGift', $gift, $current, $afterGift, 'Deposit bonus for '.$orderNo, '', 'Recharge', ['rechargeId'=>$rechargeId])) {
            @$conn->rollback(); return ['ok'=>false,'message'=>'Deposit gift ledger write failed'];
        }
        $current = $afterGift;
    }

    $settings = function_exists('site_settings') ? site_settings() : [];
    if (!empty($settings['wager_lock_enabled']) && $principal > 0) {
        $multiplier = max(0, (float)($settings['deposit_turnover_multiplier'] ?? 1));
        wallet_create_wager_requirement($conn, $userId, 'recharge', $rechargeId, $orderNo, $principal, $principal * $multiplier, ['multiplier'=>$multiplier]);
    }
    if (!empty($settings['wager_lock_enabled']) && $gift > 0) {
        $giftMultiplier = max(0, (float)($settings['recharge_gift_turnover_multiplier'] ?? 0));
        wallet_create_wager_requirement($conn, $userId, 'recharge_gift', $rechargeId, $orderNo.'-GIFT', $gift, $gift * $giftMultiplier, ['multiplier'=>$giftMultiplier]);
    }

    if (function_exists('invited_wheel_add_spin_for_inviter')) invited_wheel_add_spin_for_inviter($conn, $userId, $principal);
    @$conn->commit();
    return ['ok'=>true,'message'=>'Recharge approved','balance'=>$current,'credited'=>round($principal+$gift,2),'userId'=>$userId,'orderNo'=>$orderNo];
}

function wallet_process_withdraw(mysqli $conn, int $withdrawId, string $newStatus, string $adminNote = ''): array
{
    $newStatus = strtolower($newStatus);
    if (!in_array($newStatus, ['approved','rejected'], true)) return ['ok'=>false,'message'=>'Invalid withdraw status'];
    @$conn->begin_transaction();
    $stmt = @$conn->prepare('SELECT * FROM withdraw_requests WHERE id=? FOR UPDATE');
    if (!$stmt) { @$conn->rollback(); return ['ok'=>false,'message'=>'Withdraw request unavailable']; }
    $stmt->bind_param('i', $withdrawId); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) { @$conn->rollback(); return ['ok'=>false,'message'=>'Withdraw request not found']; }
    if ((string)$row['status'] !== 'pending') { @$conn->commit(); return ['ok'=>false,'message'=>'Withdraw already processed']; }

    $userId = (int)$row['user_id'];
    $amount = round((float)$row['amount'], 2);
    $orderNo = trim((string)($row['order_no'] ?? '')) ?: ('WD'.date('ymdHis').$withdrawId);
    $reserved = (int)($row['is_reserved'] ?? 0) === 1;

    if ($newStatus === 'approved') {
        if (!$reserved) {
            $before = wallet_balance($conn, $userId, true);
            if ($before === null) { @$conn->rollback(); return ['ok'=>false,'message'=>'User not found']; }
            $summary = wallet_wager_summary($conn, $userId, $before);
            if ($amount > (float)$summary['withdrawableBalance'] + 0.00001) { @$conn->rollback(); return ['ok'=>false,'message'=>'Withdrawable balance or turnover is insufficient']; }
            $result = wallet_apply_delta($conn, $userId, -$amount, 'Withdraw', $orderNo, 'Withdrawal reserved', (string)($row['method'] ?? ''), 'Withdraw', ['withdrawId'=>$withdrawId]);
            if (!$result) { @$conn->rollback(); return ['ok'=>false,'message'=>'Balance reserve failed']; }
            $reserved = true;
        }
        $up = @$conn->prepare('UPDATE withdraw_requests SET status="approved",is_reserved=1,admin_note=?,processed_at=NOW(),updated_at=NOW() WHERE id=?');
        if ($up) { $up->bind_param('si', $adminNote, $withdrawId); $up->execute(); }
        $up = @$conn->prepare('UPDATE users SET total_withdraw=total_withdraw+? WHERE id=?');
        if ($up) { $up->bind_param('di', $amount, $userId); $up->execute(); }
    } else {
        if ($reserved) {
            $result = wallet_apply_delta($conn, $userId, $amount, 'WithdrawReject', $orderNo, 'Withdrawal rejected: '.$adminNote, (string)($row['method'] ?? ''), 'Withdraw', ['withdrawId'=>$withdrawId]);
            if (!$result) { @$conn->rollback(); return ['ok'=>false,'message'=>'Withdraw return failed']; }
        }
        $up = @$conn->prepare('UPDATE withdraw_requests SET status="rejected",is_reserved=0,admin_note=?,processed_at=NOW(),updated_at=NOW() WHERE id=?');
        if ($up) { $up->bind_param('si', $adminNote, $withdrawId); $up->execute(); }
    }
    @$conn->commit();
    return ['ok'=>true,'message'=>$newStatus === 'approved' ? 'Withdraw approved' : 'Withdraw rejected and balance returned','orderNo'=>$orderNo,'userId'=>$userId,'amount'=>$amount];
}
