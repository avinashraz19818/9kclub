<?php
require_once __DIR__ . '/api/_core/config.php';
require_once __DIR__ . '/api/_core/bootstrap.php';

$file = __DIR__ . '/index.html';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
readfile($file);
?>
