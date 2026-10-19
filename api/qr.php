<?php
// Local QR Code Generator Endpoint
// Generates PNG QR codes dynamically for UPI links, addresses, etc.

$text = $_GET['text'] ?? $_GET['data'] ?? $_GET['url'] ?? '';
$text = trim($text);

if ($text === '') {
    $text = 'upi://pay?pa=paytm@upi&pn=Pay';
}

require_once __DIR__ . '/_core/qrcode_lib.php';

try {
    $qr = new QRcode($text, 'M');
    $arr = $qr->getBarcodeArray();
    $rows = $arr['bcode'];
    $matrixSize = count($rows);
    
    $scale = max(4, min(16, (int)($_GET['size'] ?? 8)));
    $margin = max(1, min(6, (int)($_GET['margin'] ?? 2)));
    
    $imgSize = ($matrixSize + ($margin * 2)) * $scale;
    $img = imagecreatetruecolor($imgSize, $imgSize);
    
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 10, 10, 10);
    
    imagefilledrectangle($img, 0, 0, $imgSize, $imgSize, $white);
    
    for ($y = 0; $y < $matrixSize; $y++) {
        for ($x = 0; $x < $matrixSize; $x++) {
            if ($rows[$y][$x] == 1) {
                $x1 = ($x + $margin) * $scale;
                $y1 = ($y + $margin) * $scale;
                $x2 = $x1 + $scale - 1;
                $y2 = $y1 + $scale - 1;
                imagefilledrectangle($img, $x1, $y1, $x2, $y2, $black);
            }
        }
    }
    
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('Access-Control-Allow-Origin: *');
    
    imagepng($img);
    imagedestroy($img);
    exit;
} catch (Throwable $e) {
    header('Content-Type: image/png');
    $img = imagecreatetruecolor(200, 200);
    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 0, 0, 0);
    imagefilledrectangle($img, 0, 0, 200, 200, $white);
    imagestring($img, 3, 20, 90, 'QR Generation Error', $black);
    imagepng($img);
    imagedestroy($img);
    exit;
}
