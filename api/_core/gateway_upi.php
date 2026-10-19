<?php

function gateway_upi_setting_key(string $gateway): string
{
    return $gateway === 'wepay1' ? 'gateway_upi_wepay1' : 'gateway_upi_wepay';
}

function gateway_upi_is_placeholder(string $upi): bool
{
    return in_array(strtolower(trim($upi)), ['demo@upi', 'demo2@upi'], true);
}

function gateway_upi_get(?mysqli $conn, string $gateway): string
{
    if (!$conn) {
        return '';
    }

    $key = gateway_upi_setting_key($gateway);
    $stmt = @$conn->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $upi = trim((string)($row['setting_value'] ?? ''));

    return gateway_upi_is_placeholder($upi) ? '' : $upi;
}

function gateway_upi_save(?mysqli $conn, string $gateway, string $upi): void
{
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $upi = trim($upi);
    if ($upi === '') {
        throw new Exception('Enter UPI ID');
    }

    $key = gateway_upi_setting_key($gateway);
    $stmt = $conn->prepare('INSERT INTO settings(setting_key, setting_value) VALUES(?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    if (!$stmt) {
        throw new Exception('Could not save UPI');
    }
    $stmt->bind_param('ss', $key, $upi);
    if (!$stmt->execute()) {
        throw new Exception('Could not save UPI');
    }
}

function gateway_upi_delete(?mysqli $conn, string $gateway): void
{
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    $key = gateway_upi_setting_key($gateway);
    $stmt = $conn->prepare('DELETE FROM settings WHERE setting_key=? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $key);
        $stmt->execute();
    }
}

function gateway_upi_method_ids(string $gateway): array
{
    return $gateway === 'wepay1' ? [400084, 400085] : [400088, 400086, 400095];
}

function gateway_upi_primary_id(string $gateway): int
{
    return $gateway === 'wepay1' ? 400084 : 400088;
}

function gateway_upi_sync_payment_methods(?mysqli $conn, string $gateway, string $upi, bool $active): void
{
    if (!$conn) {
        return;
    }

    $ids = gateway_upi_method_ids($gateway);
    if ($active) {
        $stmt = $conn->prepare('UPDATE payment_methods SET upi_id=?, qr_image="", state=1 WHERE id=?');
        if (!$stmt) {
            return;
        }
        foreach ($ids as $id) {
            $stmt->bind_param('si', $upi, $id);
            $stmt->execute();
        }
        return;
    }

    $stmt = $conn->prepare('UPDATE payment_methods SET upi_id="", qr_image="", state=0 WHERE id=?');
    if (!$stmt) {
        return;
    }
    foreach ($ids as $id) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
    }
}

function gateway_upi_build_method(string $upi, float $amount, string $orderNo = '', string $upiName = '13L GAME'): array
{
    $upi = trim($upi);
    if ($upi === '' || gateway_upi_is_placeholder($upi)) {
        return [
            'upi_id' => '',
            'upi_name' => $upiName,
            'upi_link' => '',
            'qr_image' => '',
        ];
    }

    $orderRef = $orderNo !== '' ? $orderNo : ('PAY' . date('ymdHis'));
    $upiLink = 'upi://pay?pa=' . rawurlencode($upi)
        . '&pn=' . rawurlencode($upiName)
        . '&am=' . rawurlencode(number_format($amount, 2, '.', ''))
        . '&cu=INR'
        . '&tn=' . rawurlencode($orderRef);

    return [
        'upi_id' => $upi,
        'upi_name' => $upiName,
        'upi_link' => $upiLink,
        'qr_image' => 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=10&data=' . rawurlencode($upiLink),
    ];
}

function gateway_upi_method_for_gateway(?mysqli $conn, string $gateway, float $amount, string $orderNo = ''): array
{
    $upi = gateway_upi_get($conn, $gateway);
    if ($upi !== '') {
        return gateway_upi_build_method($upi, $amount, $orderNo);
    }

    if (!$conn) {
        return gateway_upi_build_method('', $amount, $orderNo);
    }

    $id = gateway_upi_primary_id($gateway);
    $stmt = @$conn->prepare('SELECT upi_id, upi_name FROM payment_methods WHERE id=? AND state=1 LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $fallback = trim((string)($row['upi_id'] ?? ''));
        if ($fallback !== '' && !gateway_upi_is_placeholder($fallback)) {
            $name = trim((string)($row['upi_name'] ?? '')) ?: '13L GAME';
            @gateway_upi_save($conn, $gateway, $fallback);
            return gateway_upi_build_method($fallback, $amount, $orderNo, $name);
        }
    }

    return gateway_upi_build_method('', $amount, $orderNo);
}
