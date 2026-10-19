<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_core/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') api_error('POST required', 405, 405);
$raw = (string)file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) api_error('Invalid JSON payload', 400, 400);

$vendor = strtoupper(trim((string)($data['vendorCode'] ?? ($_SERVER['HTTP_X_PROVIDER_CODE'] ?? ''))));
$signature = strtolower(trim((string)($_SERVER['HTTP_X_SIGNATURE'] ?? '')));
$conn = db();
if (!$conn || $vendor === '' || $signature === '') api_error('Provider authentication required', 401, 401);

$stmt = @$conn->prepare("SELECT * FROM third_party_providers WHERE UPPER(vendor_code)=? AND status=1 AND integration_mode='official' LIMIT 1");
if (!$stmt) api_error('Provider unavailable', 503, 503);
$stmt->bind_param('s',$vendor);$stmt->execute();$provider=$stmt->get_result()->fetch_assoc();
if (!$provider) api_error('Provider unavailable', 404, 404);
$envKey=trim((string)($provider['callback_secret_env_key'] ?: $provider['secret_env_key']));
$secret=$envKey!==''?(string)getenv($envKey):'';
if($secret===''||!hash_equals(hash_hmac('sha256',$raw,$secret),$signature))api_error('Invalid provider signature',401,401);

$action=strtolower(trim((string)($data['action']??'')));
$userId=(int)($data['userId']??0);
if($userId<=0)api_error('Invalid user',400,400);
$stmt=@$conn->prepare("SELECT id,balance,status FROM users WHERE id=? AND role='user' LIMIT 1");
$stmt->bind_param('i',$userId);$stmt->execute();$user=$stmt->get_result()->fetch_assoc();
if(!$user||(int)$user['status']!==1)api_error('User unavailable',404,404);
if($action==='balance')api_success(['userId'=>$userId,'currency'=>(string)$provider['currency'],'balance'=>(float)$user['balance']]);
if(!in_array($action,['bet','win','refund'],true))api_error('Unsupported action',400,400);

$externalTxn=trim((string)($data['transactionId']??$data['externalTxnId']??''));
$round=trim((string)($data['roundId']??''));
$amount=round(abs((float)($data['amount']??0)),2);
if($externalTxn===''||strlen($externalTxn)>190||$amount<=0||$amount>100000000)api_error('Invalid transaction',400,400);
$providerId=(int)$provider['id'];
$stmt=@$conn->prepare('SELECT balance_after,status FROM third_party_wallet_transactions WHERE vendor_code=? AND external_txn_id=? LIMIT 1');
$stmt->bind_param('ss',$vendor,$externalTxn);$stmt->execute();$existing=$stmt->get_result()->fetch_assoc();
if($existing)api_success(['duplicate'=>true,'transactionId'=>$externalTxn,'balance'=>(float)$existing['balance_after'],'status'=>$existing['status']]);

@$conn->begin_transaction();
$delta=$action==='bet' ? -$amount : $amount;
$walletType=$action==='bet'?'GameBet':($action==='win'?'GameEnd':'GameReturn');
$remark=$provider['display_name'].' '.ucfirst($action).' · round '.$round;
$gameCode=(string)($data['gameCode']??'');
$wallet=wallet_apply_delta($conn,$userId,$delta,$walletType,$externalTxn,$remark,$gameCode,$vendor,['externalRoundId'=>$round,'providerTransactionId'=>$externalTxn]);
if(!$wallet){@$conn->rollback();api_error($action==='bet'?'Insufficient balance':'Wallet update failed',409,409);}
$requestHash=hash('sha256',$raw);$payload=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$status='accepted';
$balanceBefore=(float)$wallet['before'];$balanceAfter=(float)$wallet['after'];
$stmt=@$conn->prepare('INSERT INTO third_party_wallet_transactions(provider_id,vendor_code,user_id,external_txn_id,external_round_id,action_type,amount,balance_before,balance_after,request_hash,payload_json,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
if(!$stmt){@$conn->rollback();api_error('Transaction logging failed',500,500);}
$stmt->bind_param('isisssdddsss',$providerId,$vendor,$userId,$externalTxn,$round,$action,$amount,$balanceBefore,$balanceAfter,$requestHash,$payload,$status);
if(!$stmt->execute()){@$conn->rollback();api_error('Duplicate or invalid transaction',409,409);}
if($action==='bet'){wallet_apply_turnover($conn,$userId,$amount,$externalTxn);v34_record_agent_commissions($conn,$userId,$externalTxn,$amount);}
if(!@$conn->commit()){@$conn->rollback();api_error('Transaction commit failed',500,500);}
api_success(['duplicate'=>false,'transactionId'=>$externalTxn,'roundId'=>$round,'currency'=>(string)$provider['currency'],'balance'=>(float)$wallet['after'],'status'=>'accepted']);
