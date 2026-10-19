<?php
require_once __DIR__ . '/_core/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$action = strtolower(trim((string)($_GET['action'] ?? '')));
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true) ?: [];
$token = $_GET['token'] ?? ($body['token'] ?? ($_SESSION['ar_p_t'] ?? ''));

// Get current user if logged in
$u = current_user();
$uid = $u ? (int)$u['id'] : 0;
$conn = db();

// Helper to get active UPI ID
$upiId = 'trushemee@ybl';
$merchantName = '9K.Club';
if ($conn) {
    $res = @$conn->query("SELECT upi_id, upi_name FROM payment_methods WHERE state=1 AND upi_id != '' ORDER BY sort ASC LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        if (!empty($row['upi_id'])) $upiId = $row['upi_id'];
        if (!empty($row['upi_name'])) $merchantName = $row['upi_name'];
    }
}

// Find latest order for this user / token
$order = null;
if ($conn && $uid > 0) {
    $stmt = @$conn->prepare("SELECT * FROM recharge_orders WHERE user_id=? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
    }
}
if (!$order && $conn && !empty($token)) {
    $stmt = @$conn->prepare("SELECT * FROM recharge_orders WHERE order_no=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();
    }
}

switch ($action) {
    case 'fetchthirdpartyrechargepageinfoencryption':
        $amount = $order ? (float)$order['amount'] : 500.00;
        $orderNo = $order ? (string)$order['order_no'] : ('RC' . date('YmdHis') . random_int(100, 999));
        $createdTime = $order ? (string)$order['created_at'] : date('Y-m-d H:i:s');
        $statusStr = '1';
        if ($order) {
            if ($order['status'] === 'Payed') $statusStr = '2';
            elseif ($order['status'] === 'Cancel') $statusStr = '3';
        }

        $payload = [
            'amount' => $amount,
            'randomAmount' => 0,
            'paymentExpireTime' => 900,
            'merchantName' => $merchantName,
            'isPhonepeWakeUp' => 1,
            'tid' => $orderNo,
            'merchantOrder' => $orderNo,
            'platformOrder' => $orderNo,
            'buyOrderNo' => '',
            'sellOrderNo' => '',
            'chOrderNo' => '',
            'createTime' => $createdTime,
            'memberId' => (string)($uid ?: '1001'),
            'externalMemberId' => (string)($uid ?: '1001'),
            'returnUrl' => '/wallet/recharge',
            'orderStatus' => $statusStr,
            'upiId' => $upiId,
            'utr' => $order ? (string)($order['utr'] ?? '') : '',
            'hideUpiCopyButton' => 0,
            'randomCode' => ''
        ];

        $key = 'c4d9e1b54a0c4d3681b98d3f54c0a8e7';
        $encrypted = openssl_encrypt(json_encode($payload, JSON_UNESCAPED_SLASHES), 'aes-256-ecb', $key, 0);

        echo json_encode([
            'code' => '1',
            'data' => $encrypted,
            'msg' => 'Success'
        ]);
        exit;

    case 'status':
        $statusStr = '1';
        $utr = '';
        if ($order) {
            if ($order['status'] === 'Payed') $statusStr = '2';
            elseif ($order['status'] === 'Cancel') $statusStr = '3';
            $utr = (string)($order['utr'] ?? '');
        }
        echo json_encode([
            'code' => '1',
            'data' => [
                'orderStatus' => $statusStr,
                'utr' => $utr,
                'syncNotifyAddress' => ''
            ],
            'msg' => 'Success'
        ]);
        exit;

    case 'subutr':
    case 'submitutr':
    case 'submit-utr':
        $utr = trim((string)($body['utr'] ?? ($_POST['utr'] ?? '')));
        if ($order && $conn && $utr !== '') {
            $oid = (int)$order['id'];
            $stmt = @$conn->prepare("UPDATE recharge_orders SET utr=?, status='PendingReview', utr_submit_at=NOW(), updated_at=NOW() WHERE id=?");
            if ($stmt) {
                $stmt->bind_param('si', $utr, $oid);
                $stmt->execute();
            }
        }
        echo json_encode([
            'code' => '1',
            'msg' => 'Submitted'
        ]);
        exit;

    case 'nopay':
    case 'cancelorder':
        if ($order && $conn) {
            $oid = (int)$order['id'];
            @$conn->query("UPDATE recharge_orders SET status='Cancel', updated_at=NOW() WHERE id=$oid AND status='Wait'");
        }
        echo json_encode([
            'code' => '1',
            'msg' => 'Canceled'
        ]);
        exit;

    case 'cancellationreasonlist':
        echo json_encode([
            'code' => '1',
            'data' => [
                ['reason' => 'I changed my mind'],
                ['reason' => 'Amount was incorrect'],
                ['reason' => 'Payment failed via UPI'],
                ['reason' => 'Network timeout'],
                ['reason' => 'Other reason']
            ],
            'msg' => 'Success'
        ]);
        exit;

    default:
        $map = [
            'getorderinfo' => 'Recharge/GetLocalRechargeOrderDetail',
            'getorder' => 'Recharge/GetLocalRechargeOrderDetail',
            'orderinfo' => 'Recharge/GetLocalRechargeOrderDetail',
            'getpayorder' => 'Recharge/GetLocalRechargeOrderDetail',
        ];
        if (isset($map[$action])) {
            $GLOBALS['API_PATH'] = $map[$action];
            require __DIR__ . '/_router.php';
            exit;
        }
        echo json_encode(['code' => '1', 'data' => null, 'msg' => 'OK']);
        exit;
}
