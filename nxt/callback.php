<?php
// PreferAPI seamless callback — self-contained (own DB connect, no fragile include).
error_reporting(0); ini_set('display_errors',0);
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Kolkata');
$conn = @mysqli_connect("localhost","YOUR_DB_USER","YOUR_DB_PASSWORD","YOUR_DB_NAME");
if (!$conn) { echo json_encode(['code'=>1,'msg'=>'db']); exit; }
$conn->query("CREATE TABLE IF NOT EXISTS game_bet_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY,user_id VARCHAR(255),vendor_code VARCHAR(64),game_code VARCHAR(64),game_name_en VARCHAR(160),bet_amount DECIMAL(15,2),win_amount DECIMAL(15,2),profit_loss DECIMAL(15,2),balance_before DECIMAL(15,2),balance_after DECIMAL(15,2),serial_number VARCHAR(160) UNIQUE,game_round VARCHAR(160),created_at DATETIME) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$raw = file_get_contents('php://input'); $in = json_decode($raw, true);
if (!is_array($in)) { echo json_encode(['code'=>1,'msg'=>'bad json']); exit; }
$user_id = (string)($in['user_id'] ?? $in['playerId'] ?? '');
$bet = floatval($in['bet_amount'] ?? $in['betAmount'] ?? 0);
$win = floatval($in['win_amount'] ?? $in['winAmount'] ?? 0);
$serial = (string)($in['serial_number'] ?? $in['serialNumber'] ?? '');
$round = (string)($in['game_round'] ?? '');
$gc = (string)($in['game_code'] ?? '');
$vc = (string)($in['vendor_code'] ?? '');
$gn = (string)($in['game_name'] ?? 'Game');
if ($user_id === '') { echo json_encode(['code'=>1,'msg'=>'Missing user_id']); exit; }
// current balance
$cur = 0.0;
$bs = $conn->prepare("SELECT motta FROM shonu_kaichila WHERE balakedara=?");
$bs->bind_param("s",$user_id); $bs->execute(); $bs->bind_result($mb); if ($bs->fetch()) $cur = floatval($mb); $bs->close();
// dedupe
if ($serial !== '') {
  $ck = $conn->prepare("SELECT id FROM game_bet_logs WHERE serial_number=?");
  $ck->bind_param("s",$serial); $ck->execute(); $ck->store_result();
  if ($ck->num_rows > 0) { $ck->close(); echo json_encode(['code'=>0,'msg'=>'Duplicate','balance'=>$cur,'balance_inr'=>$cur]); exit; }
  $ck->close();
}
$new = $cur - $bet + $win;
if ($new < 0) { echo json_encode(['code'=>1,'msg'=>'Insufficient balance','balance'=>$cur]); exit; }
$u = $conn->prepare("UPDATE shonu_kaichila SET motta=? WHERE balakedara=?");
$u->bind_param("ds",$new,$user_id); $u->execute(); $u->close();
$pl = $win - $bet; $now = date('Y-m-d H:i:s');
$ins = $conn->prepare("INSERT INTO game_bet_logs (user_id,vendor_code,game_code,game_name_en,bet_amount,win_amount,profit_loss,balance_before,balance_after,serial_number,game_round,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
$ins->bind_param("ssssdddddsss",$user_id,$vc,$gc,$gn,$bet,$win,$pl,$cur,$new,$serial,$round,$now); @$ins->execute(); $ins->close();
echo json_encode(['code'=>0,'msg'=>'Success','balance'=>$new,'balance_inr'=>$new]);
