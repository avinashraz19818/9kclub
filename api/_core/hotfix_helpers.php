<?php
declare(strict_types=1);

function mw_withdraw_state(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['approved', 'pass', 'passed', 'success', 'completed'], true)) return 'Pass';
    if (in_array($status, ['rejected', 'reject', 'failed', 'cancelled', 'canceled'], true)) return 'Reject';
    return 'Wait';
}

function mw_withdraw_history_row(array $row): array
{
    $created = strtotime((string)($row['created_at'] ?? '')) ?: time();
    $updated = strtotime((string)($row['updated_at'] ?? '')) ?: $created;
    $state = mw_withdraw_state((string)($row['status'] ?? 'pending'));
    $amount = abs((float)($row['amount'] ?? 0));
    $note = (string)($row['admin_note'] ?? '');

    return [
        'id' => (int)($row['id'] ?? 0),
        'orderNo' => (string)(($row['order_no'] ?? '') ?: ($row['id'] ?? '')),
        'amount' => $amount,
        'withdrawAmount' => $amount,
        'withdrawType' => (string)($row['method'] ?? ''),
        'method' => (string)($row['method'] ?? ''),
        'withdrawState' => $state,
        'status' => $state,
        'state' => $state,
        'isReserved' => (bool)($row['is_reserved'] ?? false),
        'adminNote' => $note,
        'remarkToUser' => $note,
        'createTime' => $created * 1000,
        'updateTime' => $updated * 1000,
        'lastUpdateTime' => $updated * 1000,
    ];
}

function mw_withdraw_history_filters(array $data): array
{
    $type = trim((string)($data['withdrawType'] ?? $data['method'] ?? ''));
    if ($type !== '' && function_exists('normalize_withdraw_type')) $type = normalize_withdraw_type($type);

    $rawState = strtolower(trim((string)($data['withdrawState'] ?? $data['status'] ?? '')));
    $status = '';
    if (in_array($rawState, ['pass', 'passed', 'approved', 'success', 'completed'], true)) {
        $status = 'approved';
    } elseif (in_array($rawState, ['reject', 'rejected', 'failed', 'cancelled', 'canceled'], true)) {
        $status = 'rejected';
    } elseif (in_array($rawState, ['wait', 'waiting', 'pending', 'processing'], true)) {
        $status = 'pending';
    }

    $toDate = static function ($value, bool $end = false): string {
        if ($value === null || $value === '') return '';
        if (is_numeric($value)) {
            $timestamp = (int)$value;
            if ($timestamp > 20000000000) $timestamp = intdiv($timestamp, 1000);
        } else {
            $timestamp = strtotime((string)$value) ?: 0;
        }
        if ($timestamp <= 0) return '';
        return date('Y-m-d H:i:s', $timestamp);
    };

    return [
        'type' => $type,
        'status' => $status,
        'start' => $toDate($data['startTime'] ?? $data['startDate'] ?? null),
        'end' => $toDate($data['endTime'] ?? $data['endDate'] ?? null, true),
    ];
}

function mw_first_account_value(array $data, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($data[$key]) && !is_array($data[$key]) && trim((string)$data[$key]) !== '') {
            return trim((string)$data[$key]);
        }
    }
    foreach ($data as $value) {
        if (is_array($value)) {
            $found = mw_first_account_value($value, $keys);
            if ($found !== '') return $found;
        }
    }
    return '';
}

function mw_withdraw_account_html(array $row): string
{
    $data = $row;
    foreach (['wallet_snapshot', 'wallet_info'] as $field) {
        $raw = trim((string)($row[$field] ?? ''));
        if ($raw === '') continue;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $data = array_replace_recursive($data, $decoded);
    }

    $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $name = mw_first_account_value($data, ['bankName','bank_name','bank','walletName','wallet_name','wallet']);
    $holder = mw_first_account_value($data, ['holderName','holder_name','accountHolder','account_holder','payeeName','payee_name','realName','name']);
    $account = mw_first_account_value($data, ['accountNo','account_no','accountNumber','account_number','upiId','upi_id','usdtAddress','usdt_address','walletAddress','wallet_address']);
    $ifsc = mw_first_account_value($data, ['ifscCode','ifsc_code','ifsc','IFSC']);
    $bankCode = mw_first_account_value($data, ['bankCode','bank_code']);
    $mobile = mw_first_account_value($data, ['mobileNo','mobile_no','mobile','phone']);
    $network = mw_first_account_value($data, ['network','networkType','network_type','chain','protocol']);
    $branch = mw_first_account_value($data, ['branch','bankBranch','bank_branch']);

    $parts = [];
    if ($name !== '') $parts[] = $esc($name);
    if ($holder !== '' && $holder !== $name) $parts[] = $esc($holder);
    $html = implode(' · ', $parts);
    if ($account !== '') $html .= ($html !== '' ? ' ' : '') . '<span class="mono">' . $esc($account) . '</span>';
    if ($ifsc !== '') $html .= '<div class="small mono">IFSC: ' . $esc($ifsc) . '</div>';
    if ($bankCode !== '') $html .= '<div class="small">Bank code: ' . $esc($bankCode) . '</div>';
    if ($branch !== '') $html .= '<div class="small">Branch: ' . $esc($branch) . '</div>';
    if ($mobile !== '') $html .= '<div class="small mono">Mobile: ' . $esc($mobile) . '</div>';
    if ($network !== '') $html .= '<div class="small">Network: ' . $esc($network) . '</div>';

    if ($html !== '') return $html;
    $method = trim((string)($row['method'] ?? ''));
    return $method !== ''
        ? '<span class="muted">' . $esc($method) . ' — no account details</span>'
        : '<span class="muted">No account details</span>';
}

function mw_reference_history_rows(array $response, string $gameCode): array
{
    $data = $response['data'] ?? $response;
    if (is_array($data) && isset($data['data']) && is_array($data['data'])) $data = $data['data'];
    $rows = is_array($data) ? ($data['list'] ?? $data['records'] ?? []) : [];
    if (!is_array($rows)) return [];

    $valid = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $issue = trim((string)($row['issueNumber'] ?? $row['issue'] ?? $row['period'] ?? ''));
        $rawNumber = $row['number'] ?? $row['premium'] ?? $row['result'] ?? null;
        if (!preg_match('/^[0-9]{12,24}$/', $issue) || !is_numeric($rawNumber)) continue;
        $number = (int)$rawNumber;
        if ($number < 0 || $number > 9) continue;
        $valid[] = [
            'issueNumber' => $issue,
            'premium' => (string)$number,
            'number' => (string)$number,
            'color' => $number === 0 ? 'red,violet' : ($number === 5 ? 'green,violet' : ($number % 2 === 0 ? 'red' : 'green')),
            'bigSmall' => $number > 4 ? 'big' : 'small',
            'sum' => $number,
            'createTime' => (int)($row['createTime'] ?? $row['openTime'] ?? 0),
            'gameCode' => $gameCode,
        ];
    }
    return $valid;
}

function mw_reference_base_url(): string
{
    $configured = trim((string)getenv('LOTTERY_REFERENCE_BASE'));
    return rtrim($configured !== '' ? $configured : 'https://maanwin1.com', '/');
}

function mw_reference_draw_base_url(): string
{
    $configured = trim((string)getenv('LOTTERY_REFERENCE_DRAW_BASE'));
    return rtrim($configured !== '' ? $configured : 'https://draw.ar-lottery01.com', '/');
}

function mw_reference_history_url(string $gameCode): string
{
    $parts = explode('_', $gameCode, 2);
    $lotteryCode = $parts[0] !== '' ? $parts[0] : 'WinGo';
    return mw_reference_draw_base_url() . '/' . rawurlencode($lotteryCode) . '/' . rawurlencode($gameCode) . '/GetHistoryIssuePage.json';
}

function mw_reference_request(string $url, ?array $payload = null): ?array
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        $headers = ['Accept: application/json', 'User-Agent: MaanWin-PublicDrawSync/1.0', 'Referer: ' . mw_reference_base_url() . '/'];
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $payload === null ? $headers : array_merge($headers, ['Content-Type: application/json']),
        ]);
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) return $decoded;
        }
        return null;
    }
    $headers = "Accept: application/json\r\nUser-Agent: MaanWin-PublicDrawSync/1.0\r\nReferer: " . mw_reference_base_url() . "/\r\n";
    $options = ['http' => ['method' => $payload === null ? 'GET' : 'POST', 'timeout' => 3, 'ignore_errors' => true, 'header' => $headers]];
    if ($payload !== null) {
        $options['http']['header'] .= "Content-Type: application/json\r\n";
        $options['http']['content'] = json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
    $body = @file_get_contents($url, false, stream_context_create($options));
    if (!is_string($body) || $body === '') return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function mw_reference_cache_read(string $key, int $successTtl, int $failureTtl, bool &$hit)
{
    $hit = false;
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'maanwin-ref-' . hash('sha256', $key) . '.json';
    if (!is_file($path)) return null;
    $decoded = json_decode((string)@file_get_contents($path), true);
    if (!is_array($decoded) || !array_key_exists('ok', $decoded)) return null;
    $ttl = $decoded['ok'] ? $successTtl : $failureTtl;
    if ((time() - (int)($decoded['time'] ?? 0)) > $ttl) return null;
    $hit = true;
    return $decoded['data'] ?? null;
}

function mw_reference_cache_write(string $key, $data, bool $ok): void
{
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'maanwin-ref-' . hash('sha256', $key) . '.json';
    @file_put_contents($path, json_encode(['ok' => $ok, 'time' => time(), 'data' => $data], JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function mw_reference_signed_payload(array $payload): array
{
    $signable = $payload;
    unset($signable['signature'], $signable['track'], $signable['timestamp']);
    ksort($signable);
    $payload['signature'] = strtoupper(md5((string)json_encode($signable, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    $payload['timestamp'] = time();
    return $payload;
}

function mw_is_reference_wingo_game(string $gameCode): bool
{
    // External draw mirroring is optional.  A fresh installation must never
    // wait on a third-party host before it can render issues or game history.
    // Set LOTTERY_REFERENCE_ENABLED=1 only when a working reference feed has
    // deliberately been configured by the server administrator.
    $enabled = strtolower(trim((string)getenv('LOTTERY_REFERENCE_ENABLED')));
    if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) return false;
    return in_array($gameCode, ['WinGo_30S', 'WinGo_1M', 'WinGo_3M', 'WinGo_5M'], true);
}

function mw_fetch_reference_history(string $gameCode, int $pageNo = 1, int $pageSize = 10): array
{
    if (!mw_is_reference_wingo_game($gameCode)) return [];
    static $memo = [];
    $cacheKey = 'v2:' . $gameCode . ':' . $pageNo . ':' . $pageSize;
    if (array_key_exists($cacheKey, $memo)) return $memo[$cacheKey];
    $cacheHit = false;
    $cached = mw_reference_cache_read('history:' . $cacheKey, 5, 10, $cacheHit);
    if ($cacheHit) return $memo[$cacheKey] = (is_array($cached) ? $cached : []);
    $response = mw_reference_request(mw_reference_history_url($gameCode));
    $rows = $response ? mw_reference_history_rows($response, $gameCode) : [];
    if (le_is_wingo($gameCode) && count($rows) > 1) {
        $rows = array_values(array_slice($rows, 1));
    }
    mw_reference_cache_write('history:' . $cacheKey, $rows, $rows !== []);
    return $memo[$cacheKey] = $rows;
}

function mw_fetch_reference_issue(string $gameCode): ?array
{
    if (!mw_is_reference_wingo_game($gameCode)) return null;
    $cacheKey = 'issue:' . $gameCode;
    $cacheHit = false;
    $cached = mw_reference_cache_read($cacheKey, 2, 10, $cacheHit);
    if ($cacheHit) return is_array($cached) ? $cached : null;
    $response = mw_reference_request(mw_reference_base_url() . '/webapi/kv/issue/' . rawurlencode($gameCode));
    $data = $response['data'] ?? null;
    if (!is_array($data) || !preg_match('/^[0-9]{12,24}$/', (string)($data['issueNumber'] ?? ''))) {
        mw_reference_cache_write($cacheKey, null, false);
        return null;
    }
    if (le_is_wingo($gameCode) && function_exists('le_calc_issue_number')) {
        $refTime = !empty($data['startTime']) ? (int)intdiv((int)$data['startTime'], 1000) : time();
        $data['issueNumber'] = le_calc_issue_number($gameCode, $refTime);
    }
    mw_reference_cache_write($cacheKey, $data, true);
    return $data;
}
