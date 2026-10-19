<?php
// Lottery engine for demo/virtual-wallet backend.
// Handles WinGo, TrxWinGo, K3, 5D/D5 and MotoRace bet settlement.

function le_game_interval(string $gameCode): int
{
    if (stripos($gameCode, '30S') !== false) return 30;
    if (stripos($gameCode, '3Min') !== false || stripos($gameCode, '_3M') !== false) return 180;
    if (stripos($gameCode, '5Min') !== false || stripos($gameCode, '_5M') !== false) return 300;
    if (stripos($gameCode, '10Min') !== false || stripos($gameCode, '_10M') !== false) return 600;
    return 60;
}

function le_is_k3(string $gameCode): bool { return stripos($gameCode, 'K3') === 0; }
function le_is_d5(string $gameCode): bool { return stripos($gameCode, '5D') === 0 || stripos($gameCode, 'D5') === 0; }
function le_is_moto(string $gameCode): bool { return stripos($gameCode, 'MotoRace') === 0 || stripos($gameCode, 'Moto') === 0; }
function le_is_wingo(string $gameCode): bool { return !le_is_k3($gameCode) && !le_is_d5($gameCode) && !le_is_moto($gameCode); }

/**
 * Real site ke jaisa period number prefix:
 * WinGo = '1000', TrxWinGo = '1000', K3 = '1010', D5/5D = '1020', MotoRace = '1030'
 */
function le_period_prefix(string $gameCode): string
{
    if (le_is_k3($gameCode)) return '1010';
    if (le_is_d5($gameCode)) return '1020';
    if (le_is_moto($gameCode)) return '1030';
    return '1000'; // WinGo, TrxWinGo
}

function le_default_settings(): array
{
    return [
        'win_rate' => 45.0,
        'force_mode' => 'auto', // auto / win / lose
        'force_result' => '',
        'fee_percent' => 0.0,
        'payout_number' => 9.0,
        'payout_color' => 2.0,
        'payout_violet' => 4.5,
        'payout_bigsmall' => 2.0,
        'payout_k3' => 2.0,
        'payout_5d' => 9.5,
        'payout_moto' => 9.5,
        'immediate_settle' => 0,
    ];
}

function le_get_settings(string $gameCode): array
{
    $s = le_default_settings();
    $conn = db();
    if (!$conn) return $s;
    $stmt = @$conn->prepare('SELECT * FROM lottery_game_settings WHERE game_code=? OR game_code="*" ORDER BY game_code="*" ASC LIMIT 1');
    if (!$stmt) return $s;
    $stmt->bind_param('s', $gameCode);
    if (!$stmt->execute()) return $s;
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return $s;
    foreach ($s as $k => $v) {
        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
            $s[$k] = is_numeric($v) ? (float)$row[$k] : $row[$k];
        }
    }
    $s['immediate_settle'] = (int)($row['immediate_settle'] ?? 1);
    return $s;
}

function le_calc_issue_number(string $gameCode, int $timestamp): string
{
    $interval = le_game_interval($gameCode);
    if (le_is_wingo($gameCode)) {
        // Wingo game ko 1 period piche karke chalna hai (current running period se 1 period piche)
        $periodOffset = defined('WINGO_PERIOD_OFFSET') ? (int)WINGO_PERIOD_OFFSET : 1;
        $timestamp -= ($periodOffset * $interval);
    }
    $dt = new DateTime('@' . $timestamp);
    $dt->setTimezone(new DateTimeZone('UTC'));
    $ymd = $dt->format('Ymd');
    $sec = (int)$dt->format('H') * 3600 + (int)$dt->format('i') * 60 + (int)$dt->format('s');
    
    $periodIdx = intdiv($sec, $interval) + 1;
    
    if (stripos($gameCode, '30S') !== false) {
        $sub = '5';
    } elseif (stripos($gameCode, '3Min') !== false || stripos($gameCode, '_3M') !== false) {
        $sub = '2';
    } elseif (stripos($gameCode, '5Min') !== false || stripos($gameCode, '_5M') !== false) {
        $sub = '3';
    } elseif (stripos($gameCode, '10Min') !== false || stripos($gameCode, '_10M') !== false) {
        $sub = '4';
    } else {
        $sub = '1';
    }
    
    $cat = le_period_prefix($gameCode);
    return $ymd . $cat . $sub . str_pad((string)$periodIdx, 4, '0', STR_PAD_LEFT);
}

function le_issue_by_offset(string $gameCode, int $offset = 0): string
{
    if (mw_is_reference_wingo_game($gameCode)) {
        if ($offset === 0) {
            $referenceIssue = mw_fetch_reference_issue($gameCode);
            if ($referenceIssue) return (string)$referenceIssue['issueNumber'];
        } else {
            $referenceRows = mw_fetch_reference_history($gameCode, 1, max(10, min(50, $offset)));
            if (isset($referenceRows[$offset - 1])) return (string)$referenceRows[$offset - 1]['issueNumber'];
        }
    }
    $interval = le_game_interval($gameCode);
    $targetTime = time() - ($offset * $interval);
    return le_calc_issue_number($gameCode, $targetTime);
}

function le_color_from_number(int $n): string
{
    if ($n === 0) return 'red,violet';
    if ($n === 5) return 'green,violet';
    return ($n % 2 === 0) ? 'red' : 'green';
}

function le_result_detail(string $gameCode, string $premium, string $issueNumber = ''): array
{
    if (le_is_moto($gameCode)) {
        $parts = array_values(array_filter(array_map('trim', explode(',', $premium)), 'strlen'));
        $first = (int)($parts[0] ?? 1);
        return [
            'issueNumber' => $issueNumber,
            'premium' => $premium,
            'number' => $premium,
            'firstNumber' => $first,
            'color' => $first % 2 ? 'green' : 'red',
            'bigSmall' => $first > 5 ? 'big' : 'small',
            'sum' => array_sum(array_map('intval', $parts)),
        ];
    }
    if (le_is_k3($gameCode)) {
        $normalised = str_replace(['|','-',' '], ',', trim($premium));
        $parts = array_values(array_filter(array_map('trim', explode(',', $normalised)), 'strlen'));
        if (count($parts) === 1 && preg_match('/^[1-6]{3}$/', (string)$parts[0])) $parts = str_split((string)$parts[0]);
        $valid = count($parts) === 3 && count(array_filter($parts, fn($n)=>(int)$n >= 1 && (int)$n <= 6)) === 3;
        if (!$valid) $parts = [random_int(1,6), random_int(1,6), random_int(1,6)];
        $sum = array_sum(array_map('intval', $parts));
        return [
            'issueNumber' => $issueNumber,
            'premium' => implode(',', $parts),
            'number' => implode('', $parts),
            'dice' => $parts,
            'color' => $sum % 2 ? 'green' : 'red',
            'bigSmall' => $sum >= 11 ? 'big' : 'small',
            'sum' => $sum,
        ];
    }
    if (le_is_d5($gameCode)) {
        $digits = preg_replace('/\D+/', '', $premium);
        if (strlen($digits) < 5) $digits = str_pad($digits, 5, (string)random_int(0,9));
        $digits = substr($digits, 0, 5);
        $arr = array_map('intval', str_split($digits));
        $sum = array_sum($arr);
        return [
            'issueNumber' => $issueNumber,
            'premium' => $digits,
            'number' => $digits,
            'color' => $sum % 2 ? 'green' : 'red',
            'bigSmall' => $sum >= 23 ? 'big' : 'small',
            'sum' => $sum,
        ];
    }
    $n = (int)preg_replace('/\D+/', '', $premium);
    $n = max(0, min(9, $n));
    return [
        'issueNumber' => $issueNumber,
        'premium' => (string)$n,
        'number' => (string)$n,
        'color' => le_color_from_number($n),
        'bigSmall' => $n > 4 ? 'big' : 'small',
        'sum' => $n,
    ];
}

function le_random_premium(string $gameCode): string
{
    if (le_is_moto($gameCode)) { $nums = range(1, 10); shuffle($nums); return implode(',', $nums); }
    if (le_is_k3($gameCode)) return implode(',', [random_int(1,6), random_int(1,6), random_int(1,6)]);
    if (le_is_d5($gameCode)) return implode('', [random_int(0,9), random_int(0,9), random_int(0,9), random_int(0,9), random_int(0,9)]);
    return (string)random_int(0, 9);
}

function le_pick_result_premium(?string $savedPremium, ?string $referencePremium, string $forceResult, string $fallbackPremium): string
{
    if ($savedPremium !== null && trim($savedPremium) !== '') return trim($savedPremium);
    if ($referencePremium !== null && trim($referencePremium) !== '') return trim($referencePremium);
    if (trim($forceResult) !== '') return trim($forceResult);
    return $fallbackPremium;
}

function le_latest_saved_result(array $rows): ?array
{
    if (!$rows) return null;
    usort($rows, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
    return $rows[0] ?? null;
}

function le_saved_result(string $gameCode, string $issueNumber): ?array
{
    $conn = db();
    if (!$conn) return null;
    le_ensure_lottery_results_table($conn);
    $stmt = @$conn->prepare('SELECT id,premium FROM lottery_results WHERE game_code=? AND issue_number=? ORDER BY id DESC LIMIT 1');
    if (!$stmt) return null;
    $stmt->bind_param('ss', $gameCode, $issueNumber);
    if (!$stmt->execute()) return null;
    $row = le_latest_saved_result($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    if (!$row) return null;
    return le_result_detail($gameCode, (string)$row['premium'], $issueNumber);
}

function le_save_manual_result($conn, string $gameCode, string $issueNumber, string $premium): bool
{
    le_ensure_lottery_results_table($conn);
    $detail = le_result_detail($gameCode, $premium, $issueNumber);
    $number = (string)$detail['number'];
    $color = (string)$detail['color'];
    $bigSmall = (string)$detail['bigSmall'];
    $sum = (int)$detail['sum'];

    // Update every legacy duplicate for this exact issue. This makes the admin
    // selection authoritative even before the schema self-heal removes duplicates.
    $update = @$conn->prepare('UPDATE lottery_results SET premium=?,number=?,color=?,big_small=?,sum_value=?,open_time=NOW() WHERE game_code=? AND issue_number=?');
    if (!$update) return false;
    $update->bind_param('ssssiss', $premium, $number, $color, $bigSmall, $sum, $gameCode, $issueNumber);
    if (!$update->execute()) return false;

    $exists = @$conn->prepare('SELECT id FROM lottery_results WHERE game_code=? AND issue_number=? LIMIT 1');
    if (!$exists) return false;
    $exists->bind_param('ss', $gameCode, $issueNumber);
    if (!$exists->execute()) return false;
    if ($exists->get_result()->fetch_assoc()) return true;

    $insert = @$conn->prepare('INSERT INTO lottery_results(game_code,issue_number,premium,number,color,big_small,sum_value,open_time,created_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW())');
    if (!$insert) return false;
    $insert->bind_param('ssssssi', $gameCode, $issueNumber, $premium, $number, $color, $bigSmall, $sum);
    return (bool)$insert->execute();
}

function le_ensure_lottery_results_table($conn) {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;
    $conn->query("CREATE TABLE IF NOT EXISTS lottery_results (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      game_code VARCHAR(50) NOT NULL,
      issue_number VARCHAR(50) NOT NULL,
      premium VARCHAR(20) NOT NULL,
      number VARCHAR(20) NOT NULL,
      color VARCHAR(20) NOT NULL,
      big_small VARCHAR(20) NOT NULL,
      sum_value INT NOT NULL,
      open_time DATETIME NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_game_issue (game_code, issue_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function le_store_reference_result(string $gameCode, array $row): array
{
    $issueNumber = (string)($row['issueNumber'] ?? '');
    // A result already saved for this exact game + issue is authoritative.
    // Check before any reference insert so the admin override stays permanent
    // even on older hosting schemas where the unique index migration failed.
    $saved = le_saved_result($gameCode, $issueNumber);
    if ($saved) {
        $saved['_persisted'] = false;
        return $saved;
    }
    $premium = (string)($row['premium'] ?? $row['number'] ?? '0');
    $detail = le_result_detail($gameCode, $premium, $issueNumber);
    $detail['_persisted'] = false;
    $conn = db();
    if (!$conn) return $detail;
    le_ensure_lottery_results_table($conn);
    $premium = (string)$detail['premium'];
    $number = (string)$detail['number'];
    $color = (string)$detail['color'];
    $bigSmall = (string)$detail['bigSmall'];
    $sum = (int)$detail['sum'];
    $stmt = @$conn->prepare('INSERT IGNORE INTO lottery_results(game_code, issue_number, premium, number, color, big_small, sum_value, open_time, created_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW())');
    if ($stmt) {
        $stmt->bind_param('ssssssi', $gameCode, $issueNumber, $premium, $number, $color, $bigSmall, $sum);
        $detail['_persisted'] = (bool)$stmt->execute() && $stmt->affected_rows > 0;
    }
    $saved = le_saved_result($gameCode, $issueNumber);
    if ($saved) {
        $saved['_persisted'] = $detail['_persisted'];
        return $saved;
    }
    return $detail;
}

function le_result_for_issue(string $gameCode, string $issueNumber, bool $save = true): array
{
    $conn = db();
    if ($conn) le_ensure_lottery_results_table($conn);
    $saved = le_saved_result($gameCode, $issueNumber);
    if ($saved) return $saved;
    $referencePremium = null;
    if (mw_is_reference_wingo_game($gameCode)) {
        foreach (mw_fetch_reference_history($gameCode, 1, 50) as $referenceRow) {
            if ((string)$referenceRow['issueNumber'] === $issueNumber) {
                $referencePremium = (string)$referenceRow['premium'];
                break;
            }
        }
    }
    if ($referencePremium !== null) {
        if ($save) return le_store_reference_result($gameCode, ['issueNumber' => $issueNumber, 'premium' => $referencePremium]);
        return le_result_detail($gameCode, $referencePremium, $issueNumber);
    }
    $settings = le_get_settings($gameCode);
    $forceResult = trim((string)($settings['force_result'] ?? ''));
    $premium = le_pick_result_premium(null, null, $forceResult, le_random_premium($gameCode));
    if ($forceResult === '') {
        if ($conn && $issueNumber !== '') {
            $gEsc = $conn->real_escape_string($gameCode);
            $iEsc = $conn->real_escape_string($issueNumber);
            $q = $conn->query("SELECT bet_content FROM lottery_bets WHERE game_code='$gEsc' AND issue_number='$iEsc' AND state=2 ORDER BY id ASC LIMIT 1");
            $bet = $q ? $q->fetch_assoc() : null;
            if ($bet) {
                $choice = le_extract_choice($gameCode, (string)$bet['bet_content']);
                $force = (string)($settings['force_mode'] ?? 'auto');
                if ($force === 'win') {
                    $premium = le_premium_for_choice($gameCode, $choice, true);
                } elseif ($force === 'lose') {
                    $premium = le_premium_for_choice($gameCode, $choice, false);
                } else {
                    $shouldWin = (random_int(1,10000) <= (int)round(((float)$settings['win_rate'])*100));
                    $premium = le_premium_for_choice($gameCode, $choice, $shouldWin);
                }
            }
        }
    }
    $detail = le_result_detail($gameCode, $premium, $issueNumber);
    if ($conn && $save) {
        $premium = (string)$detail['premium'];
        $number = (string)$detail['number'];
        $color = (string)$detail['color'];
        $bigSmall = (string)$detail['bigSmall'];
        $sum = (int)$detail['sum'];
        $stmt = @$conn->prepare('INSERT IGNORE INTO lottery_results(game_code, issue_number, premium, number, color, big_small, sum_value, open_time, created_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW())');
        if (!$stmt) error_log('lottery_results write unavailable: ' . $conn->error);
        if ($stmt) { 
            $stmt->bind_param('ssssssi', $gameCode, $issueNumber, $premium, $number, $color, $bigSmall, $sum); 
            $stmt->execute();
        }
        $stmt2 = @$conn->prepare('SELECT * FROM lottery_results WHERE game_code=? AND issue_number=? ORDER BY id DESC LIMIT 1');
        if ($stmt2) {
            $stmt2->bind_param('ss', $gameCode, $issueNumber);
            $stmt2->execute();
            $row2 = $stmt2->get_result()->fetch_assoc();
            if ($row2) {
                return le_result_detail($gameCode, (string)$row2['premium'], $issueNumber);
            }
        }
    }
    return $detail;
}

function le_bet_content_from_request(array $d): string
{
    @file_put_contents(__DIR__ . '/bet_debug.log', date('Y-m-d H:i:s') . ' PAYLOAD: ' . json_encode($d) . "\n", FILE_APPEND);
    if (isset($d['betContent'])) return (string)$d['betContent'];
    
    // Find type and value dynamically from all possible keys
    $type = '';
    $val = '';
    
    $typeKeys = ['selectType', 'playType', 'betType', 'type', 'gameType'];
    foreach ($typeKeys as $k) {
        if (isset($d[$k]) && is_string($d[$k]) && $d[$k] !== '') {
            $type = strtolower(trim($d[$k]));
            break;
        }
    }
    
    $valKeys = ['playBet', 'bettingContent', 'pick', 'content', 'value', 'select', 'choice', 'option', 'number', 'color', 'bigSmall'];
    foreach ($valKeys as $k) {
        if (isset($d[$k]) && is_string($d[$k]) && $d[$k] !== '') {
            $val = trim($d[$k]);
            break;
        }
    }
    
    if ($type !== '' && $val !== '') {
        if ($type === 'bigsmalleven' || $type === 'bsoe' || $type === 'size') {
            // "1" is Small and "0" is Big based on the user's inverted win reports
            if ($val === '1' || $val === 's' || $val === 'small') return 'small';
            if ($val === '0' || $val === 'b' || $val === 'big') return 'big';
            if ($val === '2') return 'even';
            if ($val === '3') return 'odd';
            return 'bigsmall_' . $val;
        }
        if ($type === 'color' || $type === 'colour') {
            if ($val === '10' || $val === 'g' || $val === 'green' || $val === '1') return 'green';
            if ($val === '20' || $val === 'v' || $val === 'violet' || $val === '2') return 'violet';
            if ($val === '30' || $val === 'r' || $val === 'red' || $val === '3') return 'red';
            return 'color_' . $val;
        }
        if ($type === 'num' || $type === 'number') {
            return (string)$val;
        }
        return $type . '_' . $val;
    }

    // Fallback if we couldn't find type/val clearly
    $keys = ['bettingContent','playType','betType','pick','content','selectType','number','color','bigSmall','betInfo','betDetail','name','title','value','betName','bet_name','select','choice','option'];
    foreach ($keys as $k) {
        if (isset($d[$k]) && $d[$k] !== '' && !is_array($d[$k])) {
            $val = (string)$d[$k];
            $lower = strtolower(trim($val));
            if (!in_array($lower, ['bigsmalleven', 'bsoe', 'num', 'number', 'color', 'sum', 'playbet'], true)) {
                return $val;
            }
        }
    }
    return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function le_total_amount_from_request(array $d): array
{
    $amount = (float)first_value($d, ['amount','betAmount','bettingAmount','singleAmount','money','baseAmount','selectAmount','contractMoney'], 0);
    $multiple = (int)first_value($d, ['betMultiple','multiple','quantity','bettingQuantity','betCount','count','contractCount'], 1);
    if ($amount <= 0 && isset($d['totalAmount'])) { $amount = (float)$d['totalAmount']; $multiple = 1; }
    if ($multiple <= 0) $multiple = 1;
    return [$amount, $multiple, round($amount * $multiple, 2)];
}

function le_extract_choice(string $gameCode, string $content): array
{
    $c = trim(strtolower($content));
    
    $result = ['kind'=>'number','value'=>'0'];
    
    // Direct matches for common combined strings like 'BigSmall_Small'
    if ($c === 'bigsmall_small' || $c === 'small') {
        $result = ['kind'=>'bigsmall','value'=>'small'];
    } elseif ($c === 'bigsmall_big' || $c === 'big') {
        $result = ['kind'=>'bigsmall','value'=>'big'];
    } elseif (strpos($c, 'color_red') !== false || $c === 'red') {
        $result = ['kind'=>'color','value'=>'red'];
    } elseif (strpos($c, 'color_green') !== false || $c === 'green') {
        $result = ['kind'=>'color','value'=>'green'];
    } elseif (strpos($c, 'color_violet') !== false || strpos($c, 'color_purple') !== false || $c === 'violet' || $c === 'purple') {
        $result = ['kind'=>'color','value'=>'violet'];
    } else {
        // Strip frontend meta-strings from the payload so 'bigsmalleven' doesn't falsely trigger 'small'
        $c = str_replace(['bigsmalleven', 'bsoe', 'playbet', 'selecttype', '"', "'", 'bigsmall_'], ' ', $c);
        
        if ($c === 'b' || $c === 'bada' || $c === '40') $result = ['kind'=>'bigsmall','value'=>'big'];
        elseif ($c === 's' || $c === 'chota' || $c === '50') $result = ['kind'=>'bigsmall','value'=>'small'];
        elseif ($c === 'g' || $c === '10') $result = ['kind'=>'color','value'=>'green'];
        elseif ($c === 'v' || $c === '20') $result = ['kind'=>'color','value'=>'violet'];
        elseif ($c === 'r' || $c === '30') $result = ['kind'=>'color','value'=>'red'];
        elseif (strpos($c, 'violet') !== false || strpos($c, 'purple') !== false) $result = ['kind'=>'color','value'=>'violet'];
        elseif (strpos($c, 'green') !== false) $result = ['kind'=>'color','value'=>'green'];
        elseif (strpos($c, 'red') !== false) $result = ['kind'=>'color','value'=>'red'];
        else {
            $hasSmall = (strpos($c, 'small') !== false || strpos($c, 'chota') !== false);
            $hasBig = (strpos($c, 'big') !== false || strpos($c, 'bada') !== false);
            if ($hasSmall && !$hasBig) $result = ['kind'=>'bigsmall','value'=>'small'];
            elseif ($hasBig && !$hasSmall) $result = ['kind'=>'bigsmall','value'=>'big'];
            else {
                $clean = preg_replace('/(wingo|trxwingo|k3|5d|d5|motorace|motogame|1min|3min|5min|30s|issue|gamecode|amount|multiple|betcontent|playtype|playbet|bigsmall|size)/i', ' ', $content);
                if (le_is_moto($gameCode) && preg_match('/\b(10|[1-9])\b/', $clean, $m)) $result = ['kind'=>'number','value'=>(string)(int)$m[1]];
                elseif (le_is_k3($gameCode) && preg_match('/\b([3-9]|1[0-8])\b/', $clean, $m)) $result = ['kind'=>'sum','value'=>(string)(int)$m[1]];
                elseif (preg_match('/\b([0-9])\b/', $clean, $m)) $result = ['kind'=>'number','value'=>(string)(int)$m[1]];
                elseif (preg_match('/(?:num|number|digit|select)[^0-9]*([0-9])/', $c, $m)) $result = ['kind'=>'number','value'=>(string)(int)$m[1]];
            }
        }
    }
    
    @file_put_contents(__DIR__ . '/bet_debug.log', date('Y-m-d H:i:s') . ' EXTRACT_CHOICE IN: ' . $content . ' OUT: ' . json_encode($result) . "\n", FILE_APPEND);
    return $result;
}

function le_result_matches(array $choice, array $result, string $gameCode): bool
{
    $kind = $choice['kind'] ?? 'number';
    $value = trim(strtolower((string)($choice['value'] ?? '')));
    $bigSmall = trim(strtolower((string)($result['bigSmall'] ?? '')));
    $colorStr = trim(strtolower((string)($result['color'] ?? '')));
    
    if ($kind === 'color') return in_array($value, array_map('trim', explode(',', $colorStr)), true);
    if ($kind === 'bigsmall') return $bigSmall === $value;
    if ($kind === 'sum') return (string)((int)$result['sum']) === (string)((int)$value);
    if (le_is_moto($gameCode)) return (string)((int)($result['firstNumber'] ?? 0)) === (string)((int)$value);
    if (le_is_k3($gameCode)) return strpos((string)$result['number'], (string)((int)$value)) !== false;
    if (le_is_d5($gameCode)) return strpos((string)$result['number'], (string)((int)$value)) !== false;
    
    return (string)((int)$result['number']) === (string)((int)$value);
}

function le_premium_for_choice(string $gameCode, array $choice, bool $shouldWin): string
{
    for ($i=0; $i<60; $i++) {
        if ($shouldWin) {
            if (le_is_wingo($gameCode)) {
                if ($choice['kind'] === 'number') $premium = (string)((int)$choice['value']);
                elseif ($choice['kind'] === 'color') {
                    $map = ['red'=>[2,4,6,8], 'green'=>[1,3,7,9], 'violet'=>[0,5]];
                    $arr = $map[strtolower($choice['value'])] ?? [0];
                    $premium = (string)$arr[array_rand($arr)];
                } else {
                    $premium = strtolower($choice['value']) === 'big' ? (string)random_int(5,9) : (string)random_int(0,4);
                }
            } elseif (le_is_k3($gameCode)) {
                if (($choice['kind'] ?? '') === 'sum') {
                    $target = max(3, min(18, (int)$choice['value']));
                    $a = random_int(1,6); $b = random_int(1,6); $c = max(1, min(6, $target-$a-$b));
                    while ($a+$b+$c !== $target) { $a=random_int(1,6); $b=random_int(1,6); $c=random_int(1,6); }
                    $premium = "$a,$b,$c";
                } else $premium = le_random_premium($gameCode);
            } elseif (le_is_d5($gameCode)) {
                $digit = preg_match('/^[0-9]$/', (string)$choice['value']) ? (string)$choice['value'] : (string)random_int(0,9);
                $arr = [random_int(0,9),random_int(0,9),random_int(0,9),random_int(0,9),random_int(0,9)];
                $arr[random_int(0,4)] = (int)$digit;
                $premium = implode('', $arr);
            } elseif (le_is_moto($gameCode)) {
                $first = max(1, min(10, (int)$choice['value']));
                $nums = range(1,10); shuffle($nums); $nums = array_values(array_diff($nums, [$first])); array_unshift($nums, $first);
                $premium = implode(',', $nums);
            } else $premium = le_random_premium($gameCode);
        } else {
            $premium = le_random_premium($gameCode);
        }
        $detail = le_result_detail($gameCode, $premium);
        if (le_result_matches($choice, $detail, $gameCode) === $shouldWin) return $premium;
    }
    return le_random_premium($gameCode);
}

function le_payout_rate(string $gameCode, array $choice, array $settings): float
{
    if (le_is_k3($gameCode)) return (float)$settings['payout_k3'];
    if (le_is_d5($gameCode)) return (float)$settings['payout_5d'];
    if (le_is_moto($gameCode)) return (float)$settings['payout_moto'];
    // Hardcode rates to prevent 10x payouts from DB misconfigurations
    if (($choice['kind'] ?? '') === 'color') return strtolower((string)$choice['value']) === 'violet' ? 4.5 : 2.0;
    if (($choice['kind'] ?? '') === 'bigsmall') return 2.0;
    return (float)($settings['payout_number'] ?? 9.0);
}

function le_normalize_bet_content(string $gameCode, string $content): string
{
    $content = trim($content);
    if ($content === '') return $content;

    if (strpos($content, ',') !== false) {
        $parts = explode(',', $content);
        $normParts = array_map(fn($p) => le_normalize_bet_content($gameCode, $p), $parts);
        return implode(',', $normParts);
    }

    if (strpos($content, '_') !== false) {
        $parts = explode('_', $content, 2);
        $t = strtolower(trim($parts[0]));
        $v = trim($parts[1]);
        if ($t === 'color') return 'Color_' . ucfirst(strtolower($v));
        if ($t === 'bigsmall' || $t === 'size') return 'BigSmall_' . ucfirst(strtolower($v));
        if ($t === 'num' || $t === 'number') return 'Num_' . $v;
        return ucfirst($parts[0]) . '_' . $v;
    }

    $c = strtolower($content);
    if ($c === 'small' || $c === 'chota' || $c === 's' || $c === '50') return 'BigSmall_Small';
    if ($c === 'big' || $c === 'bada' || $c === 'b' || $c === '40') return 'BigSmall_Big';
    if ($c === 'green' || $c === 'g' || $c === '10') return 'Color_Green';
    if ($c === 'red' || $c === 'r' || $c === '30') return 'Color_Red';
    if ($c === 'violet' || $c === 'purple' || $c === 'v' || $c === '20') return 'Color_Violet';
    if (preg_match('/^[0-9]$/', $content)) return 'Num_' . $content;

    if (le_is_k3($gameCode)) {
        if (is_numeric($content)) {
            $n = (int)$content;
            if ($n >= 3 && $n <= 18) return 'SumNum_' . $n;
        }
    }

    return $content;
}

function le_response_row(array $r): array
{
    $gameCode = (string)($r['game_code'] ?? '');
    $rawContent = (string)($r['bet_content'] ?? '');
    $normContent = le_normalize_bet_content($gameCode, $rawContent);

    $premium = trim((string)($r['premium'] ?? ''));
    if ($premium === '') {
        $result = ['number'=>'','color'=>'','bigSmall'=>'','sum'=>0,'premium'=>''];
    } else {
        $result = le_result_detail($gameCode, $premium, (string)($r['issue_number'] ?? ''));
    }

    $firstPart = explode(',', $normContent)[0] ?? $normContent;
    $playType = str_contains($firstPart, '_') ? explode('_', $firstPart)[0] : '';

    return [
        'orderNo' => (string)$r['order_no'],
        'issueNumber' => (string)$r['issue_number'],
        'gameCode' => $gameCode,
        'betContent' => $normContent,
        'amount' => (float)$r['amount'],
        'betMultiple' => (int)$r['bet_multiple'],
        'realAmount' => (float)$r['real_amount'],
        'fee' => (float)$r['fee'],
        'premium' => $premium,
        'number' => (string)$result['number'],
        'color' => (string)$result['color'],
        'bigSmall' => (string)$result['bigSmall'],
        'sum' => (int)$result['sum'],
        'playType' => $playType,
        'state' => (int)$r['state'],
        'isWin' => (int)$r['state'] === 1,
        'isPending' => (int)$r['state'] === 2,
        'winLoseAmount' => (float)$r['win_lose_amount'],
        'betTime' => strtotime((string)$r['created_at']) * 1000,
        'createTime' => strtotime((string)$r['created_at']) * 1000,
    ];
}

function le_issue_is_closed(string $gameCode, string $issueNumber): bool
{
    $issueNumber = trim($issueNumber);
    if ($issueNumber === '') return false;
    $current = function_exists('lottery_issue') ? lottery_issue($gameCode)['issueNumber'] : le_issue_by_offset($gameCode, 0);
    return strcmp($issueNumber, (string)$current) < 0;
}

function le_settle_pending_bets(string $gameCode = '', string $issueNumber = '', int $userId = 0, string $orderNo = ''): int
{
    $conn = db();
    if (!$conn) return 0;
    $where = ['state=2'];
    if ($gameCode !== '') $where[] = "game_code='" . $conn->real_escape_string($gameCode) . "'";
    if ($issueNumber !== '') $where[] = "issue_number='" . $conn->real_escape_string($issueNumber) . "'";
    if ($userId > 0) $where[] = 'user_id=' . (int)$userId;
    if ($orderNo !== '') $where[] = "order_no='" . $conn->real_escape_string($orderNo) . "'";
    $sql = 'SELECT * FROM lottery_bets WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT 500';
    $rs = $conn->query($sql);
    if (!$rs) return 0;
    $settled = 0;
    while ($r = $rs->fetch_assoc()) {
        $g = (string)$r['game_code'];
        $issue = (string)$r['issue_number'];
        if (!le_issue_is_closed($g, $issue)) continue;

        $result = le_result_for_issue($g, $issue, true);
        $choice = le_extract_choice($g, (string)$r['bet_content']);
        $settings = le_get_settings($g);
        $isWin = le_result_matches($choice, $result, $g);
        $rate = le_payout_rate($g, $choice, $settings);
        $stake = (float)$r['real_amount'];
        $fee = (float)$r['fee'];
        $debit = round($stake + $fee, 2);
        // Apply 2% tax to the gross winning payout: ₹100 at 2x credits ₹196.
        $grossPayout = $isWin ? round($stake * $rate, 2) : 0.0;
        $payout = $isWin ? round($grossPayout * 0.98, 2) : 0.0;
        $net = round($payout - $debit, 2);
        $state = $isWin ? 1 : 0;
        $premium = (string)$result['premium'];
        $betId = (int)$r['id'];
        $uid = (int)$r['user_id'];
        $order = (string)$r['order_no'];
        $vendor = 'ARLottery';
        $remark = $g . ' ' . (string)$r['bet_content'] . ' result ' . $premium;

        @$conn->begin_transaction();
        $check = $conn->query('SELECT state FROM lottery_bets WHERE id=' . $betId . ' FOR UPDATE');
        $fresh = $check ? $check->fetch_assoc() : null;
        if (!$fresh || (int)$fresh['state'] !== 2) { @$conn->rollback(); continue; }

        $stmt = $conn->prepare('UPDATE lottery_bets SET premium=?, state=?, win_lose_amount=? WHERE id=?');
        if (!$stmt) { @$conn->rollback(); continue; }
        $stmt->bind_param('sidi', $premium, $state, $net, $betId);
        if (!$stmt->execute()) { @$conn->rollback(); continue; }

        $settlement = wallet_apply_delta($conn, $uid, $payout, 'GameEnd', $order, $remark, $isWin ? 'Win' : 'Loss', $vendor, ['gameCode'=>$g,'issueNumber'=>$issue,'premium'=>$premium,'isWin'=>$isWin,'net'=>$net]);
        if (!$settlement) { @$conn->rollback(); continue; }
        @$conn->commit();
        $settled++;
    }
    return $settled;
}
