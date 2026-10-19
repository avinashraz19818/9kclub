<?php

function usdt_read_settings_json(?mysqli $conn): array
{
    if (!$conn) {
        return [];
    }
    $key = 'usdt_deposit_settings';
    $stmt = @$conn->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $decoded = json_decode((string)($row['setting_value'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function usdt_write_settings_json(mysqli $conn, array $data): void
{
    $key = 'usdt_deposit_settings';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $conn->prepare('INSERT INTO settings(setting_key, setting_value) VALUES(?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    if (!$stmt) {
        throw new Exception('Could not save USDT settings');
    }
    $stmt->bind_param('ss', $key, $json);
    if (!$stmt->execute()) {
        throw new Exception('Could not save USDT settings');
    }
}

function usdt_deposit_defaults(): array
{
    return [
        'rate' => 97.0,
        'min_amount' => 10.0,
        'max_amount' => 10000.0,
        'trc20_enabled' => true,
        'bep20_enabled' => true,
        'trc20_address' => '',
        'bep20_address' => '',
        'default_network' => 'TRC20',
    ];
}

function usdt_is_placeholder_address(string $address): bool
{
    $address = trim($address);
    if ($address === '') {
        return true;
    }
    if (function_exists('gateway_upi_is_placeholder') && gateway_upi_is_placeholder($address)) {
        return true;
    }
    return stripos($address, 'Set USDT wallet') !== false;
}

function usdt_payment_method_row(?mysqli $conn): array
{
    if (!$conn) {
        return [];
    }
    $stmt = @$conn->prepare("SELECT rate, upi_id, min_amount, max_amount FROM payment_methods WHERE recharge_type='USDT' AND state=1 ORDER BY id DESC LIMIT 1");
    if (!$stmt) {
        return [];
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return is_array($row) ? $row : [];
}

function usdt_address_for_network(array $settings, string $network): string
{
    return strtoupper(trim($network)) === 'BEP20'
        ? trim((string)($settings['bep20_address'] ?? ''))
        : trim((string)($settings['trc20_address'] ?? ''));
}

function usdt_resolve_wallet_address(?mysqli $conn, array $settings, string $network): string
{
    $network = strtoupper(trim($network)) === 'BEP20' ? 'BEP20' : 'TRC20';
    $address = usdt_address_for_network($settings, $network);
    if (!usdt_is_placeholder_address($address)) {
        return $address;
    }

    $pm = usdt_payment_method_row($conn);
    $fallback = trim((string)($pm['upi_id'] ?? ''));
    if ($network === 'TRC20' && !usdt_is_placeholder_address($fallback)) {
        return $fallback;
    }

    return '';
}

function usdt_deposit_settings(?mysqli $conn = null): array
{
    if (!$conn && function_exists('db')) {
        $conn = db();
    }

    $settings = usdt_deposit_defaults();
    $saved = usdt_read_settings_json($conn);
    if ($saved) {
        $settings = array_merge($settings, $saved);
    }

    if ($conn) {
        $row = usdt_payment_method_row($conn);
        if ($row) {
            if ((float)($row['rate'] ?? 0) > 0 && empty($saved['rate'])) {
                $settings['rate'] = (float)$row['rate'];
            }
            $addr = trim((string)($row['upi_id'] ?? ''));
            if (!usdt_is_placeholder_address($addr) && trim((string)($settings['trc20_address'] ?? '')) === '') {
                $settings['trc20_address'] = $addr;
            }
        }
    }

    $settings['rate'] = max(0.01, (float)($settings['rate'] ?? 97));
    $settings['min_amount'] = max(1, (float)($settings['min_amount'] ?? 10));
    $settings['max_amount'] = max($settings['min_amount'], (float)($settings['max_amount'] ?? 10000));
    $settings['trc20_enabled'] = !empty($settings['trc20_enabled']);
    $settings['bep20_enabled'] = !empty($settings['bep20_enabled']);
    $settings['default_network'] = strtoupper((string)($settings['default_network'] ?? 'TRC20')) === 'BEP20' ? 'BEP20' : 'TRC20';

    return $settings;
}

function usdt_deposit_save(mysqli $conn, array $data): void
{
    $current = usdt_deposit_settings($conn);
    $settings = array_merge($current, [
        'rate' => max(0.01, (float)($data['rate'] ?? $current['rate'])),
        'trc20_enabled' => !empty($data['trc20_enabled']),
        'bep20_enabled' => !empty($data['bep20_enabled']),
        'trc20_address' => trim((string)($data['trc20_address'] ?? '')),
        'bep20_address' => trim((string)($data['bep20_address'] ?? '')),
        'default_network' => strtoupper((string)($data['default_network'] ?? 'TRC20')) === 'BEP20' ? 'BEP20' : 'TRC20',
        'min_amount' => 10.0,
        'max_amount' => 10000.0,
    ]);

    if ($settings['trc20_enabled'] && $settings['trc20_address'] === '') {
        $settings['trc20_enabled'] = false;
    }
    if ($settings['bep20_enabled'] && $settings['bep20_address'] === '') {
        $settings['bep20_enabled'] = false;
    }
    if (!$settings['trc20_enabled'] && !$settings['bep20_enabled']) {
        throw new Exception('Enter at least one wallet address (TRC20 or BEP20)');
    }
    if ($settings['default_network'] === 'TRC20' && !$settings['trc20_enabled']) {
        $settings['default_network'] = 'BEP20';
    }
    if ($settings['default_network'] === 'BEP20' && !$settings['bep20_enabled']) {
        $settings['default_network'] = 'TRC20';
    }

    usdt_write_settings_json($conn, $settings);

    $primaryAddr = usdt_address_for_network($settings, $settings['default_network']);
    if ($primaryAddr === '') {
        $primaryAddr = $settings['trc20_address'] ?: $settings['bep20_address'];
    }
    if ($primaryAddr === '') {
        $existing = usdt_payment_method_row($conn);
        $primaryAddr = trim((string)($existing['upi_id'] ?? ''));
    }

    $stmt = $conn->prepare("UPDATE payment_methods SET rate=?, min_amount=?, max_amount=?, upi_id=IF(?='', upi_id, ?), upi_name=?, state=1 WHERE recharge_type='USDT'");
    if ($stmt) {
        $name = 'USDT TRC20/BEP20';
        $stmt->bind_param('dddsss', $settings['rate'], $settings['min_amount'], $settings['max_amount'], $primaryAddr, $primaryAddr, $name);
        $stmt->execute();
    }
}

function usdt_enabled_networks(array $settings): array
{
    $list = [];
    if (!empty($settings['trc20_enabled']) && trim((string)($settings['trc20_address'] ?? '')) !== '') {
        $list[] = 'TRC20';
    }
    if (!empty($settings['bep20_enabled']) && trim((string)($settings['bep20_address'] ?? '')) !== '') {
        $list[] = 'BEP20';
    }
    return $list;
}

function usdt_normalize_network(string $network, array $settings): string
{
    $network = strtoupper(trim($network));
    $enabled = usdt_enabled_networks($settings);
    if (!$enabled) {
        return 'TRC20';
    }
    if (in_array($network, $enabled, true)) {
        return $network;
    }
    $default = strtoupper((string)($settings['default_network'] ?? 'TRC20'));
    if (in_array($default, $enabled, true)) {
        return $default;
    }
    return $enabled[0];
}

function usdt_qr_for_address(string $address): string
{
    $address = trim($address);
    if ($address === '') {
        return '';
    }
    return 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=10&data=' . rawurlencode($address);
}

function usdt_pay_method(?mysqli $conn, float $amount, string $orderNo = '', string $network = ''): array
{
    $settings = usdt_deposit_settings($conn);
    $network = usdt_normalize_network($network, $settings);
    $address = usdt_resolve_wallet_address($conn, $settings, $network);

    return [
        'upi_id' => $address,
        'upi_name' => 'USDT ' . $network,
        'upi_link' => '',
        'qr_image' => usdt_qr_for_address($address),
        'rate' => (float)$settings['rate'],
        'network' => $network,
        'networks' => usdt_enabled_networks($settings),
        'settings' => $settings,
    ];
}

function usdt_apply_category_settings(array $cat): array
{
    if (strtoupper((string)($cat['rechargeType'] ?? '')) !== 'USDT' && (int)($cat['id'] ?? 0) !== 400087) {
        return $cat;
    }

    $settings = usdt_deposit_settings(function_exists('db') ? db() : null);
    $cat['rate'] = (float)$settings['rate'];
    $cat['minAmount'] = (float)$settings['min_amount'];
    $cat['maxAmount'] = (float)$settings['max_amount'];
    $networks = usdt_enabled_networks($settings);
    $cat['usdtNetworks'] = array_map(static function (string $code) use ($settings): array {
        return [
            'code' => $code,
            'name' => 'USDT-' . $code,
            'address' => usdt_address_for_network($settings, $code),
        ];
    }, $networks);
    $cat['defaultUsdtNetwork'] = usdt_normalize_network('', $settings);
    return $cat;
}
