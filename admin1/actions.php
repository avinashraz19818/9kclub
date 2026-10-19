<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

function dw_payment_image_upload(string $field, string $existing = ''): string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }
    $file = $_FILES[$field];
    if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('Payment image upload failed.');
    if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > 5 * 1024 * 1024) throw new RuntimeException('Payment image must be between 1 byte and 5 MB.');
    $tmp = (string)($file['tmp_name'] ?? '');
    $info = $tmp !== '' ? @getimagesize($tmp) : false;
    $extensions = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif'];
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    if (!isset($extensions[$mime]) || !is_uploaded_file($tmp)) throw new RuntimeException('Upload a valid PNG, JPG, WEBP or GIF payment image.');
    $directory = dirname(__DIR__) . '/uploads/payments';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('Payment image directory could not be created.');
    $name = 'pay-' . date('YmdHis') . '-' . bin2hex(random_bytes(5)) . '.' . $extensions[$mime];
    if (!@move_uploaded_file($tmp, $directory . '/' . $name)) throw new RuntimeException('Payment image could not be saved.');
    return '/uploads/payments/' . $name;
}

function dw_admin_handle_post(mysqli $conn, array $admin, array $permissions): void
{
    dw_verify_csrf();
    $action = dw_post_string('action');
    $page = dw_allowed_page(dw_post_string('return_page', 'dashboard'));
    $returnQuery = [];
    if ($page === 'wingo') {
        $returnGame = dw_wingo_return_game(dw_post_string('return_game'));
        if ($returnGame !== '') $returnQuery['game'] = $returnGame;
    }
    if ($action === '') throw new RuntimeException('No admin action was selected.');
    if (in_array($action, ['admin_create','admin_permissions_save','admin_reset_password'], true)) throw new RuntimeException('This installation uses one owner admin account.');
    if ((int)($admin['must_change_password'] ?? 0) === 1 && $action !== 'change_own_password') {
        throw new RuntimeException('Change the temporary admin password before using the panel.');
    }

    switch ($action) {
        case 'change_own_password': {
            $current = dw_post_string('current_password');
            $new = dw_post_string('new_password');
            $confirm = dw_post_string('confirm_password');
            if (strlen($new) < 10 || $new !== $confirm) throw new RuntimeException('New password must be at least 10 characters and both entries must match.');
            $id = (int)$admin['id'];
            $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id=? FOR UPDATE');
            $stmt->bind_param('i', $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc();
            if (!$row || !password_verify($current, (string)$row['password_hash'])) throw new RuntimeException('Current password is incorrect.');
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password_hash=?,must_change_password=0,last_password_change_at=NOW() WHERE id=?');
            $stmt->bind_param('si', $hash, $id); $stmt->execute();
            dw_admin_audit($conn, $admin, 'admin_change_own_password', 'admin', $id);
            dw_flash('success', 'Admin password changed successfully.');
            dw_redirect('dashboard');
        }

        case 'user_status': {
            dw_require_permission($permissions, 'users');
            $id=dw_post_int('id'); $status=dw_post_int('status')===1?1:0;
            if($id===(int)$admin['id']) throw new RuntimeException('You cannot disable your own account.');
            $stmt=$conn->prepare("UPDATE users SET status=?,login_session_token='' WHERE id=? AND role='user'");
            $stmt->bind_param('ii',$status,$id); $stmt->execute();
            @$conn->query('UPDATE user_sessions SET is_active=0 WHERE user_id='.(int)$id);
            dw_admin_audit($conn,$admin,'user_status','user',$id,['status'=>$status]);
            dw_flash('success',$status?'User activated.':'User suspended and signed out.');
            break;
        }

        case 'user_adjust_balance': {
            dw_require_permission($permissions, 'finance');
            $id=dw_post_int('id'); $amount=abs(dw_post_float('amount')); $direction=dw_post_string('direction','credit'); $reason=dw_post_string('reason');
            $turnoverMultiplier=max(0,(float)($_POST['turnover_multiplier']??0));
            if($amount<=0 || $reason==='') throw new RuntimeException('Amount and reason are required.');
            $delta=$direction==='debit' ? -$amount : $amount;
            $type=$delta<0?'ManualWithdraw':'ManualRecharge'; $order=dw_random_order('MA');
            $conn->begin_transaction();
            $result=wallet_apply_delta($conn,$id,$delta,$type,$order,$reason,'Admin','Admin',['adminId'=>(int)$admin['id']]);
            if(!$result){$conn->rollback();throw new RuntimeException('Balance adjustment failed or user has insufficient balance.');}
            if($delta>0 && $turnoverMultiplier>0){
                wallet_create_wager_requirement($conn,$id,'manual_adjustment',0,$order,$amount,$amount*$turnoverMultiplier,['adminId'=>(int)$admin['id'],'reason'=>$reason,'multiplier'=>$turnoverMultiplier]);
            }
            $conn->commit();
            dw_admin_audit($conn,$admin,'user_adjust_balance','user',$id,['amount'=>$delta,'orderNo'=>$order,'reason'=>$reason,'turnoverMultiplier'=>$turnoverMultiplier]);
            dw_flash('success','Balance adjusted. Ledger order: '.$order);
            break;
        }

        case 'user_reset_session': {
            dw_require_permission($permissions, 'users');
            $id=dw_post_int('id'); $token=bin2hex(random_bytes(20));
            $stmt=$conn->prepare('UPDATE users SET login_session_token=? WHERE id=?');$stmt->bind_param('si',$token,$id);$stmt->execute();
            @$conn->query('UPDATE user_sessions SET is_active=0 WHERE user_id='.(int)$id);
            dw_admin_audit($conn,$admin,'user_reset_sessions','user',$id);
            dw_flash('success','All user sessions revoked.');
            break;
        }

        case 'user_reset_withdraw_pin': {
            dw_require_permission($permissions, 'users');
            $id=dw_post_int('id'); $stmt=$conn->prepare("UPDATE users SET withdraw_password_hash='' WHERE id=? AND role='user'");$stmt->bind_param('i',$id);$stmt->execute();
            dw_admin_audit($conn,$admin,'user_reset_withdraw_pin','user',$id);
            dw_flash('success','Withdrawal PIN cleared.');
            break;
        }

        case 'user_reset_password': {
            dw_require_permission($permissions, 'users');
            $id=dw_post_int('id');$password=dw_post_string('password');
            if(strlen($password)<8) throw new RuntimeException('Temporary user password must be at least 8 characters.');
            $hash=password_hash($password,PASSWORD_DEFAULT);$token=bin2hex(random_bytes(20));
            $stmt=$conn->prepare("UPDATE users SET password_hash=?,login_session_token=? WHERE id=? AND role='user'");$stmt->bind_param('ssi',$hash,$token,$id);$stmt->execute();
            @$conn->query('UPDATE user_sessions SET is_active=0 WHERE user_id='.(int)$id);
            dw_admin_audit($conn,$admin,'user_reset_password','user',$id);
            dw_flash('success','User password reset and sessions revoked.');
            break;
        }

        case 'user_withdraw_wallet_save': {
            dw_require_permission($permissions, 'users');
            $id=dw_post_int('id');$walletId=dw_post_int('wallet_id');
            $type=dw_post_string('wallet_type','BankCard');
            if(!in_array($type,['BankCard','UPI'],true)) throw new RuntimeException('Only Bank Card and UPI accounts can be managed here.');
            $walletName=trim(dw_post_string('wallet_name',$type));
            $holderName=trim(dw_post_string('holder_name'));
            $account=trim(dw_post_string('account_no'));
            $ifsc=strtoupper(trim(dw_post_string('ifsc_code')));
            $mobile=trim(dw_post_string('mobile_no'));
            $check=$conn->prepare("SELECT id FROM users WHERE id=? AND role='user' LIMIT 1");$check->bind_param('i',$id);$check->execute();
            if(!$check->get_result()->fetch_assoc()) throw new RuntimeException('User UID was not found.');
            if($type==='BankCard' && !preg_match('/^[0-9]{6,25}$/',$account)) throw new RuntimeException('Enter a valid 6-25 digit bank account number.');
            if($type==='BankCard' && $ifsc!=='' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/',$ifsc)) throw new RuntimeException('Enter a valid IFSC code.');
            if($type==='UPI' && !preg_match('/^[A-Za-z0-9._-]{2,}@[A-Za-z0-9.-]{2,}$/',$account)) throw new RuntimeException('Enter a valid UPI ID.');
            if($walletName==='') $walletName=$type;
            $raw=json_encode(['withdrawType'=>$type,'walletType'=>$type,'bankName'=>$walletName,'holderName'=>$holderName,'accountNo'=>$account,'upiId'=>$type==='UPI'?$account:'','ifscCode'=>$ifsc],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if($walletId>0){
                $stmt=$conn->prepare('UPDATE withdraw_wallets SET wallet_type=?,wallet_name=?,account_no=?,ifsc_code=?,mobile_no=?,wallet_data=?,status=1 WHERE id=? AND user_id=?');
                $stmt->bind_param('ssssssii',$type,$walletName,$account,$ifsc,$mobile,$raw,$walletId,$id);$stmt->execute();
                if($stmt->affected_rows<1){$verify=$conn->prepare('SELECT id FROM withdraw_wallets WHERE id=? AND user_id=?');$verify->bind_param('ii',$walletId,$id);$verify->execute();if(!$verify->get_result()->fetch_assoc())throw new RuntimeException('This account does not belong to the selected UID.');}
            }else{
                $stmt=$conn->prepare('INSERT INTO withdraw_wallets(user_id,wallet_type,wallet_name,account_no,ifsc_code,mobile_no,wallet_data,status,created_at) VALUES(?,?,?,?,?,?,?,1,NOW())');
                $stmt->bind_param('issssss',$id,$type,$walletName,$account,$ifsc,$mobile,$raw);$stmt->execute();$walletId=(int)$conn->insert_id;
            }
            dw_admin_audit($conn,$admin,'user_withdraw_wallet_save','withdraw_wallet',$walletId,['userId'=>$id,'walletType'=>$type]);
            dw_flash('success','Bank/UPI details saved for UID '.$id.'.');
            break;
        }

        case 'user_withdraw_wallet_disable': {
            dw_require_permission($permissions, 'users');
            $id=dw_post_int('id');$walletId=dw_post_int('wallet_id');
            $stmt=$conn->prepare('UPDATE withdraw_wallets SET status=0 WHERE id=? AND user_id=?');$stmt->bind_param('ii',$walletId,$id);$stmt->execute();
            if($stmt->affected_rows<1) throw new RuntimeException('Bank/UPI account was not found for this UID.');
            dw_admin_audit($conn,$admin,'user_withdraw_wallet_disable','withdraw_wallet',$walletId,['userId'=>$id]);
            dw_flash('success','Bank/UPI account removed from UID '.$id.'.');
            break;
        }

        case 'user_profile_flags': {
            dw_require_permission($permissions, 'users');
            $id=dw_post_int('id');$vip=max(0,dw_post_int('vip_level'));$agent=isset($_POST['is_agent'])?1:0;
            $stmt=$conn->prepare("UPDATE users SET vip_level=?,is_agent=? WHERE id=? AND role='user'");$stmt->bind_param('iii',$vip,$agent,$id);$stmt->execute();
            dw_admin_audit($conn,$admin,'user_profile_flags','user',$id,['vipLevel'=>$vip,'isAgent'=>$agent]);
            dw_flash('success','User VIP and agent flags updated.');
            break;
        }

        case 'demo_user_create': {
            dw_require_permission($permissions, 'users');
            $username=dw_post_string('username');
            if($username==='')$username='demo'.date('md').random_int(100,999);
            $mobile=dw_post_string('mobile');$nickname=dw_post_string('nickname','Demo User');$password=dw_post_string('password');$opening=max(0,dw_post_float('opening_balance'));
            if(!preg_match('/^[A-Za-z0-9_.-]{4,40}$/',$username)||strlen($password)<8)throw new RuntimeException('Demo username must be valid and password must be at least 8 characters.');
            $tenant=(string)(time().random_int(1000,9999));$invite=strtoupper(substr(bin2hex(random_bytes(6)),0,8));$hash=password_hash($password,PASSWORD_DEFAULT);
            $conn->begin_transaction();
            $stmt=$conn->prepare("INSERT INTO users(tenant_user_id,username,mobile,password_hash,nickname,invite_code,role,status,is_demo,balance,created_at) VALUES(?,?,?,?,?,?,'user',1,1,0,NOW())");
            $stmt->bind_param('ssssss',$tenant,$username,$mobile,$hash,$nickname,$invite);
            if(!$stmt->execute()){$conn->rollback();throw new RuntimeException('Demo user creation failed: '.$stmt->error);}
            $id=(int)$conn->insert_id;
            if($opening>0 && !wallet_apply_delta($conn,$id,$opening,'DemoCredit',dw_random_order('DM'),'Demo user opening balance','Admin','DemoUser',['adminId'=>(int)$admin['id']])){$conn->rollback();throw new RuntimeException('Demo opening balance could not be credited.');}
            $conn->commit();
            dw_admin_audit($conn,$admin,'demo_user_create','user',$id,['username'=>$username,'openingBalance'=>$opening]);
            dw_flash('success','Demo user created: '.$username);
            break;
        }

        case 'recharge_process': {
            dw_require_permission($permissions, 'recharges');
            $id=dw_post_int('id');$decision=dw_post_string('decision');$note=dw_post_string('admin_note');
            if($decision==='approve'){
                $result=wallet_approve_recharge($conn,$id,$note);
                if(empty($result['ok'])) throw new RuntimeException((string)($result['message']??'Recharge approval failed.'));
                $message=(string)$result['message'].'; credited ₹'.dw_money($result['credited']??0).'.';
            } elseif($decision==='reject') {
                $stmt=$conn->prepare("UPDATE recharge_orders SET status='Cancel',admin_note=?,updated_at=NOW() WHERE id=? AND status IN ('Wait','PendingReview')");
                $stmt->bind_param('si',$note,$id);$stmt->execute();
                if($stmt->affected_rows<1) throw new RuntimeException('Only pending recharge orders can be rejected.');
                $message='Recharge rejected.';
            } else throw new RuntimeException('Invalid recharge decision.');
            dw_admin_audit($conn,$admin,'recharge_'.$decision,'recharge',$id,['note'=>$note]);
            dw_flash('success',$message);
            break;
        }

        case 'withdraw_process': {
            dw_require_permission($permissions, 'withdrawals');
            $id=dw_post_int('id');$decision=dw_post_string('decision');$note=dw_post_string('admin_note');
            $result=wallet_process_withdraw($conn,$id,$decision,$note);
            if(empty($result['ok'])) throw new RuntimeException((string)($result['message']??'Withdrawal update failed.'));
            dw_admin_audit($conn,$admin,'withdraw_'.$decision,'withdraw',$id,['note'=>$note,'orderNo'=>$result['orderNo']??'']);
            dw_flash('success',(string)$result['message']);
            break;
        }

        case 'wager_override': {
            dw_require_permission($permissions, 'wagers');
            $id=dw_post_int('id');$decision=dw_post_string('decision');$reason=dw_post_string('reason');
            if($reason==='') throw new RuntimeException('Override reason is required.');
            $conn->begin_transaction();
            $stmt=$conn->prepare('SELECT * FROM wager_requirements WHERE id=? FOR UPDATE');$stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();
            if(!$row || $row['status']!=='active'){$conn->rollback();throw new RuntimeException('Active wager requirement not found.');}
            $uid=(int)$row['user_id'];
            if($decision==='complete'){
                $delta=max(0,(float)$row['required_turnover']-(float)$row['completed_turnover']);
                $stmt=$conn->prepare("UPDATE wager_requirements SET completed_turnover=required_turnover,status='completed',completed_at=NOW(),metadata_json=? WHERE id=?");
                $meta=json_encode(['adminOverride'=>true,'reason'=>$reason,'adminId'=>(int)$admin['id']],JSON_UNESCAPED_SLASHES);$stmt->bind_param('si',$meta,$id);$stmt->execute();
                $stmt=$conn->prepare('UPDATE users SET turnover_completed=turnover_completed+? WHERE id=?');$stmt->bind_param('di',$delta,$uid);$stmt->execute();
            } elseif($decision==='cancel'){
                $stmt=$conn->prepare("UPDATE wager_requirements SET status='cancelled',cancelled_at=NOW(),metadata_json=? WHERE id=?");
                $meta=json_encode(['adminOverride'=>true,'reason'=>$reason,'adminId'=>(int)$admin['id']],JSON_UNESCAPED_SLASHES);$stmt->bind_param('si',$meta,$id);$stmt->execute();
            } else {$conn->rollback();throw new RuntimeException('Invalid wager override.');}
            $conn->commit();
            dw_admin_audit($conn,$admin,'wager_'.$decision,'wager_requirement',$id,['reason'=>$reason,'userId'=>$uid]);
            dw_flash('success','Wager requirement '.$decision.'d.');
            break;
        }

        case 'game_update': {
            dw_require_permission($permissions, 'games');
            $id=dw_post_int('id');$status=isset($_POST['status'])?1:0;$maintenance=isset($_POST['is_maintenance'])?1:0;$rtp=max(0,min(100,(float)($_POST['rtp']??98)));$sort=dw_post_int('sort');
            $stmt=$conn->prepare('UPDATE games SET status=?,is_maintenance=?,rtp=?,sort=? WHERE id=?');$stmt->bind_param('iidii',$status,$maintenance,$rtp,$sort,$id);$stmt->execute();
            dw_admin_audit($conn,$admin,'game_update','game',$id,['status'=>$status,'maintenance'=>$maintenance,'rtp'=>$rtp,'sort'=>$sort]);
            dw_flash('success','Game controls saved.');
            break;
        }

        case 'provider_save': {
            dw_require_permission($permissions, 'games');
            $id=dw_post_int('id');$vendor=strtoupper(dw_post_string('vendor_code'));$name=dw_post_string('display_name');$mode=dw_post_string('integration_mode','disabled');
            $template=dw_post_string('launch_url_template');$host=strtolower(dw_post_string('allowed_host'));$merchant=dw_post_string('merchant_id');$envKey=dw_post_string('secret_env_key');$callbackEnvKey=dw_post_string('callback_secret_env_key');$status=isset($_POST['status'])?1:0;
            if(!preg_match('/^[A-Z0-9_-]{2,80}$/',$vendor)||$name==='')throw new RuntimeException('Provider code and display name are required.');
            if(!in_array($mode,['disabled','demo','official'],true))throw new RuntimeException('Invalid provider mode.');
            if($envKey!==''&&!preg_match('/^[A-Z][A-Z0-9_]{2,188}$/',$envKey))throw new RuntimeException('Secret environment key must use uppercase letters, numbers and underscore.');
            if($callbackEnvKey!==''&&!preg_match('/^[A-Z][A-Z0-9_]{2,188}$/',$callbackEnvKey))throw new RuntimeException('Callback secret environment key must use uppercase letters, numbers and underscore.');
            if($template!==''){$parts=parse_url($template);$templateHost=is_array($parts)?strtolower((string)($parts['host']??'')):'';if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https'||$templateHost==='')throw new RuntimeException('Launch URL must be a valid HTTPS URL.');if($host==='' )$host=$templateHost;if($host!==$templateHost)throw new RuntimeException('Allowed host must match the launch URL host.');}
            if($mode==='official'&&($template===''||$envKey===''))throw new RuntimeException('Official mode requires an HTTPS launch template and secret environment key.');
            $stmt=$conn->prepare('UPDATE third_party_providers SET vendor_code=?,display_name=?,integration_mode=?,launch_url_template=?,merchant_id=?,secret_env_key=?,callback_secret_env_key=?,allowed_host=?,status=?,updated_at=NOW() WHERE id=?');
            $stmt->bind_param('ssssssssii',$vendor,$name,$mode,$template,$merchant,$envKey,$callbackEnvKey,$host,$status,$id);if(!$stmt->execute())throw new RuntimeException('Provider update failed: '.$stmt->error);
            dw_admin_audit($conn,$admin,'provider_save','third_party_provider',$id,['vendorCode'=>$vendor,'mode'=>$mode,'status'=>$status,'allowedHost'=>$host,'secretEnvKey'=>$envKey,'callbackSecretEnvKey'=>$callbackEnvKey]);
            dw_flash('success','Provider adapter saved.');
            break;
        }

        case 'lottery_setting_save': {
            dw_require_permission($permissions, 'wingo');
            $game=dw_post_string('game_code','*');$mode=dw_post_string('force_mode','auto');if(!in_array($mode,['auto','win','lose'],true))$mode='auto';
            $force=dw_post_string('force_result');$win=max(0,min(100,(float)($_POST['win_rate']??45)));$fee=max(0,min(50,(float)($_POST['fee_percent']??0)));
            $pn=max(0,(float)($_POST['payout_number']??9));$pc=max(0,(float)($_POST['payout_color']??2));$pv=max(0,(float)($_POST['payout_violet']??4.5));$pb=max(0,(float)($_POST['payout_bigsmall']??2));$pk=max(0,(float)($_POST['payout_k3']??2));$p5=max(0,(float)($_POST['payout_5d']??9.5));$pm=max(0,(float)($_POST['payout_moto']??9.5));$instant=isset($_POST['immediate_settle'])?1:0;
            $stmt=$conn->prepare("INSERT INTO lottery_game_settings(game_code,win_rate,force_mode,force_result,fee_percent,payout_number,payout_color,payout_violet,payout_bigsmall,payout_k3,payout_5d,payout_moto,immediate_settle,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE win_rate=VALUES(win_rate),force_mode=VALUES(force_mode),force_result=VALUES(force_result),fee_percent=VALUES(fee_percent),payout_number=VALUES(payout_number),payout_color=VALUES(payout_color),payout_violet=VALUES(payout_violet),payout_bigsmall=VALUES(payout_bigsmall),payout_k3=VALUES(payout_k3),payout_5d=VALUES(payout_5d),payout_moto=VALUES(payout_moto),immediate_settle=VALUES(immediate_settle),updated_at=NOW()");
            $stmt->bind_param('sdssddddddddi',$game,$win,$mode,$force,$fee,$pn,$pc,$pv,$pb,$pk,$p5,$pm,$instant);$stmt->execute();
            dw_admin_audit($conn,$admin,'lottery_setting_save','lottery_setting',0,['gameCode'=>$game,'forceMode'=>$mode,'forceResult'=>$force]);
            dw_flash('success','WinGo/lottery controls saved.');
            break;
        }

        case 'lottery_result_save': {
            dw_require_permission($permissions, 'wingo');
            $game=dw_post_string('game_code');$issue=dw_post_string('issue_number');$premium=dw_post_string('premium');
            if($game===''||$issue===''||$premium==='')throw new RuntimeException('Game, issue and result are required.');
            if(!le_save_manual_result($conn,$game,$issue,$premium)) throw new RuntimeException('Manual result could not be saved.');
            $settled=le_settle_pending_bets($game,$issue);
            dw_admin_audit($conn,$admin,'lottery_result_save','lottery_result',0,['gameCode'=>$game,'issue'=>$issue,'premium'=>$premium,'settled'=>$settled]);
            dw_flash('success','Result saved. Settled '.$settled.' eligible pending bets.');
            break;
        }

        case 'lottery_result_unset': {
            dw_require_permission($permissions, 'wingo');
            $game=dw_post_string('game_code');$issue=dw_post_string('issue_number');
            if($game===''||$issue==='')throw new RuntimeException('Game and issue are required.');
            $stmt=$conn->prepare('SELECT COUNT(*) c FROM lottery_bets WHERE game_code=? AND issue_number=? AND state<>2');
            $stmt->bind_param('ss',$game,$issue);$stmt->execute();$settled=(int)($stmt->get_result()->fetch_assoc()['c']??0);
            if($settled>0)throw new RuntimeException('This issue already has settled bets, so its result cannot be removed.');
            $stmt=$conn->prepare('DELETE FROM lottery_results WHERE game_code=? AND issue_number=?');
            $stmt->bind_param('ss',$game,$issue);$stmt->execute();
            dw_admin_audit($conn,$admin,'lottery_result_unset','lottery_result',0,['gameCode'=>$game,'issue'=>$issue]);
            dw_flash('success',$stmt->affected_rows>0?'Manual result removed. Auto result will be used.':'No manual result was set for this issue.');
            break;
        }

        case 'settle_pending_bets': {
            dw_require_permission($permissions, 'wingo');
            $settled=le_settle_pending_bets(dw_post_string('game_code'),dw_post_string('issue_number'));
            dw_admin_audit($conn,$admin,'settle_pending_bets','lottery_bet',0,['count'=>$settled]);
            dw_flash('success','Settled '.$settled.' pending bets whose issues are closed.');
            break;
        }

        case 'gift_save': {
            dw_require_permission($permissions, 'gifts');
            $id=dw_post_int('id');$code=strtoupper(dw_post_string('code'));$title=dw_post_string('title','Gift Code');$amount=dw_post_float('amount');$max=max(1,dw_post_int('max_claim',1));$min=max(0,dw_post_float('min_recharge'));$expiry=dw_post_string('expires_at');$status=isset($_POST['status'])?1:0;$note=dw_post_string('admin_note');
            if($code===''||$amount<=0)throw new RuntimeException('Gift code and positive amount are required.');
            $expiryValue=$expiry!==''?str_replace('T',' ',$expiry).(strlen($expiry)<=16?':00':''):null;
            if($id>0){$stmt=$conn->prepare('UPDATE gift_codes SET code=?,title=?,amount=?,max_claim=?,min_recharge=?,expires_at=?,status=?,admin_note=? WHERE id=?');$stmt->bind_param('ssdidsisi',$code,$title,$amount,$max,$min,$expiryValue,$status,$note,$id);}
            else{$stmt=$conn->prepare('INSERT INTO gift_codes(code,title,amount,max_claim,per_user_limit,min_recharge,expires_at,status,admin_note,created_at) VALUES(?,?,?,?,1,?,?,?,?,NOW())');$stmt->bind_param('ssdidsis',$code,$title,$amount,$max,$min,$expiryValue,$status,$note);}
            if(!$stmt->execute())throw new RuntimeException('Gift code save failed: '.$stmt->error);
            $target=$id>0?$id:(int)$conn->insert_id;dw_admin_audit($conn,$admin,'gift_save','gift_code',$target,['code'=>$code,'amount'=>$amount]);
            dw_flash('success','Gift code saved.');
            break;
        }

        case 'gift_toggle': {
            dw_require_permission($permissions, 'gifts');$id=dw_post_int('id');$status=dw_post_int('status')?1:0;
            $stmt=$conn->prepare('UPDATE gift_codes SET status=? WHERE id=?');$stmt->bind_param('ii',$status,$id);$stmt->execute();
            dw_admin_audit($conn,$admin,'gift_toggle','gift_code',$id,['status'=>$status]);dw_flash('success','Gift status updated.');break;
        }

        case 'payment_save': {
            dw_require_permission($permissions, 'payments');
            $id=dw_post_int('id');if($id<=0)$id=(int)dw_scalar($conn,'SELECT COALESCE(MAX(id),0)+1 FROM payment_methods',1);
            $name=dw_post_string('name');$type=dw_post_string('recharge_type','UPI');$allowedTypes=['UPI','BankCard','USDT','Manual','ARPay'];if(!in_array($type,$allowedTypes,true))$type='Manual';$kind=dw_post_string('method_kind',$type);
            $state=isset($_POST['state'])?1:0;$deposit=isset($_POST['deposit_enabled'])?1:0;$withdraw=isset($_POST['withdraw_enabled'])?1:0;$sort=dw_post_int('sort');
            $rate=max(.0001,dw_post_float('rate',1));$usdtRate=max(.0001,dw_post_float('usdt_rate',$rate));$min=max(0,dw_post_float('min_amount'));$max=max($min,dw_post_float('max_amount'));$gift=max(0,dw_post_float('gift_ratio'));
            $upi=dw_post_string('upi_id');$upiName=dw_post_string('upi_name');$usdtAddress=dw_post_string('usdt_address');$network=dw_post_string('network','TRC20');$qr=dw_post_string('qr_image');$note=dw_post_string('note');
            if($qr===''&&$id>0){$stmt=$conn->prepare('SELECT qr_image FROM payment_methods WHERE id=?');$stmt->bind_param('i',$id);$stmt->execute();$qr=(string)($stmt->get_result()->fetch_assoc()['qr_image']??'');}
            $qr=dw_payment_image_upload('payment_image',$qr);
            if($name===''||$max<=0)throw new RuntimeException('Payment method name and valid limits are required.');
            $stmt=$conn->prepare("INSERT INTO payment_methods(id,name,recharge_type,method_kind,state,deposit_enabled,withdraw_enabled,sort,rate,usdt_rate,min_amount,max_amount,gift_ratio,upi_id,upi_name,usdt_address,network,qr_image,note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),recharge_type=VALUES(recharge_type),method_kind=VALUES(method_kind),state=VALUES(state),deposit_enabled=VALUES(deposit_enabled),withdraw_enabled=VALUES(withdraw_enabled),sort=VALUES(sort),rate=VALUES(rate),usdt_rate=VALUES(usdt_rate),min_amount=VALUES(min_amount),max_amount=VALUES(max_amount),gift_ratio=VALUES(gift_ratio),upi_id=VALUES(upi_id),upi_name=VALUES(upi_name),usdt_address=VALUES(usdt_address),network=VALUES(network),qr_image=VALUES(qr_image),note=VALUES(note),updated_at=NOW()");
            $stmt->bind_param('isssiiiidddddssssss',$id,$name,$type,$kind,$state,$deposit,$withdraw,$sort,$rate,$usdtRate,$min,$max,$gift,$upi,$upiName,$usdtAddress,$network,$qr,$note);
            if(!$stmt->execute())throw new RuntimeException('Payment method save failed: '.$stmt->error);
            dw_admin_audit($conn,$admin,'payment_save','payment_method',$id,['name'=>$name,'type'=>$type,'deposit'=>$deposit,'withdraw'=>$withdraw,'usdtRate'=>$usdtRate]);dw_flash('success','UPI / USDT payment method saved.');break;
        }

        case 'payment_upi_update': {
            dw_require_permission($permissions, 'payments');
            $id=dw_post_int('id');$upi=trim(dw_post_string('upi_id'));$upiName=trim(dw_post_string('upi_name'));
            if($id<=0)throw new RuntimeException('Payment method not found.');
            if(!valid_upi_id($upi))throw new RuntimeException('Enter a valid UPI ID, for example name@ybl.');
            $stmt=$conn->prepare("UPDATE payment_methods SET upi_id=?,upi_name=IF(?='',upi_name,?),updated_at=NOW() WHERE id=?");
            $stmt->bind_param('sssi',$upi,$upiName,$upiName,$id);
            if(!$stmt->execute()||$stmt->affected_rows<0)throw new RuntimeException('UPI ID update failed.');
            dw_admin_audit($conn,$admin,'payment_upi_update','payment_method',$id,['upi_id'=>$upi]);
            dw_flash('success','UPI ID updated. New deposit orders will use it immediately.');
            break;
        }

        case 'payment_toggle': {
            dw_require_permission($permissions, 'payments');$id=dw_post_int('id');$state=dw_post_int('state')?1:0;$stmt=$conn->prepare('UPDATE payment_methods SET state=?,updated_at=NOW() WHERE id=?');$stmt->bind_param('ii',$state,$id);$stmt->execute();dw_admin_audit($conn,$admin,'payment_toggle','payment_method',$id,['state'=>$state]);dw_flash('success','Payment method status updated.');break;
        }

        case 'content_save': {
            dw_require_permission($permissions, 'content');$kind=dw_post_string('kind');
            if($kind==='banner'){$id=dw_post_int('id');$name=dw_post_string('name');$url=dw_post_string('icon_url');$jump=dw_post_string('jump_detail');$sort=dw_post_int('sort');$status=isset($_POST['status'])?1:0;if($name===''||$url==='')throw new RuntimeException('Banner name and image URL are required.');if($id>0){$stmt=$conn->prepare('UPDATE banners SET name=?,icon_url=?,jump_detail=?,sort=?,status=? WHERE id=?');$stmt->bind_param('sssiii',$name,$url,$jump,$sort,$status,$id);}else{$type=2;$target=1;$stmt=$conn->prepare('INSERT INTO banners(name,icon_url,jump_type,jump_detail,display_target,sort,status,created_at) VALUES(?,?,?,?,?,?,?,NOW())');$stmt->bind_param('ssisiii',$name,$url,$type,$jump,$target,$sort,$status);}}
            elseif($kind==='notification'){$id=dw_post_int('id');$title=dw_post_string('title');$content=dw_post_string('content');$type=dw_post_string('type','notice');$jump=dw_post_string('jump_url');$status=isset($_POST['status'])?1:0;if($title==='')throw new RuntimeException('Notification title is required.');if($id>0){$stmt=$conn->prepare('UPDATE notifications SET title=?,content=?,type=?,jump_url=?,status=? WHERE id=?');$stmt->bind_param('ssssii',$title,$content,$type,$jump,$status,$id);}else{$stmt=$conn->prepare('INSERT INTO notifications(title,content,type,jump_url,status,created_at) VALUES(?,?,?,?,?,NOW())');$stmt->bind_param('ssssi',$title,$content,$type,$jump,$status);}}
            else throw new RuntimeException('Invalid content type.');
            if(!$stmt->execute())throw new RuntimeException('Content save failed: '.$stmt->error);
            $targetId=$id>0?$id:(int)$conn->insert_id;dw_admin_audit($conn,$admin,'content_save',$kind,$targetId);dw_flash('success',ucfirst($kind).' saved.');break;
        }

        case 'content_toggle': {
            dw_require_permission($permissions, 'content');$kind=dw_post_string('kind');$id=dw_post_int('id');$status=dw_post_int('status')?1:0;$table=$kind==='banner'?'banners':($kind==='notification'?'notifications':'');if($table==='')throw new RuntimeException('Invalid content type.');$stmt=$conn->prepare("UPDATE {$table} SET status=? WHERE id=?");$stmt->bind_param('ii',$status,$id);$stmt->execute();dw_admin_audit($conn,$admin,'content_toggle',$kind,$id,['status'=>$status]);dw_flash('success','Content status updated.');break;
        }

        case 'vip_save': {
            dw_require_permission($permissions, 'vip');$level=max(0,dw_post_int('level'));$name=dw_post_string('name','VIP '.$level);$dep=max(0,dw_post_float('deposit_required'));$bet=max(0,dw_post_float('bet_required'));$levelReward=max(0,dw_post_float('level_reward'));$week=max(0,dw_post_float('weekly_reward'));$month=max(0,dw_post_float('monthly_reward'));$status=isset($_POST['status'])?1:0;
            $stmt=$conn->prepare("INSERT INTO vip_levels(level,name,deposit_required,bet_required,level_reward,weekly_reward,monthly_reward,status) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),deposit_required=VALUES(deposit_required),bet_required=VALUES(bet_required),level_reward=VALUES(level_reward),weekly_reward=VALUES(weekly_reward),monthly_reward=VALUES(monthly_reward),status=VALUES(status)");
            $stmt->bind_param('isdddddi',$level,$name,$dep,$bet,$levelReward,$week,$month,$status);$stmt->execute();dw_admin_audit($conn,$admin,'vip_save','vip_level',$level);dw_flash('success','VIP level saved.');break;
        }

        case 'vip_toggle': {
            dw_require_permission($permissions, 'vip');$id=dw_post_int('id');$status=dw_post_int('status')?1:0;$stmt=$conn->prepare('UPDATE vip_levels SET status=? WHERE id=?');$stmt->bind_param('ii',$status,$id);$stmt->execute();dw_admin_audit($conn,$admin,'vip_toggle','vip_level',$id,['status'=>$status]);dw_flash('success','VIP level status updated.');break;
        }

        case 'task_save': {
            dw_require_permission($permissions, 'tasks');$id=dw_post_int('id');$code=dw_post_string('code');$title=dw_post_string('title');$type=dw_post_string('task_type','manual');$target=max(0,dw_post_float('target_value'));$reward=max(0,dw_post_float('reward'));$sort=dw_post_int('sort');$status=isset($_POST['status'])?1:0;if($code===''||$title==='')throw new RuntimeException('Task code and title are required.');
            if($id>0){$stmt=$conn->prepare('UPDATE activity_tasks SET code=?,title=?,task_type=?,target_value=?,reward=?,sort=?,status=? WHERE id=?');$stmt->bind_param('sssddiii',$code,$title,$type,$target,$reward,$sort,$status,$id);}else{$stmt=$conn->prepare('INSERT INTO activity_tasks(code,title,task_type,target_value,reward,sort,status) VALUES(?,?,?,?,?,?,?)');$stmt->bind_param('sssddii',$code,$title,$type,$target,$reward,$sort,$status);}
            $stmt->execute();$targetId=$id>0?$id:(int)$conn->insert_id;dw_admin_audit($conn,$admin,'task_save','activity_task',$targetId);dw_flash('success','Activity task saved.');break;
        }

        case 'task_toggle': {
            dw_require_permission($permissions, 'tasks');$id=dw_post_int('id');$status=dw_post_int('status')?1:0;$stmt=$conn->prepare('UPDATE activity_tasks SET status=? WHERE id=?');$stmt->bind_param('ii',$status,$id);$stmt->execute();dw_admin_audit($conn,$admin,'task_toggle','activity_task',$id,['status'=>$status]);dw_flash('success','Task status updated.');break;
        }

        case 'wheel_settings_save': {
            dw_require_permission($permissions, 'wheels');$kind=dw_post_string('kind');
            if($kind==='invited'){$cfg=dw_setting($conn,'invited_wheel_settings',[]);$cfg['enabled']=isset($_POST['enabled']);$cfg['min_withdraw_amount']=max(0,dw_post_float('min_withdraw_amount'));$cfg['turnover_multiplier']=max(0,(float)($_POST['turnover_multiplier']??0));$cfg['free_spin_min']=max(.01,dw_post_float('free_spin_min',.1));$cfg['free_spin_max']=max($cfg['free_spin_min'],dw_post_float('free_spin_max',3));$cfg['max_spin_reward']=max($cfg['free_spin_max'],dw_post_float('max_spin_reward',3));dw_save_setting($conn,'invited_wheel_settings',$cfg);}
            elseif($kind==='recharge'){$cfg=dw_setting($conn,'recharge_wheel_config',[]);$cfg['enabled']=isset($_POST['enabled']);$cfg['requireApprovedRecharge']=isset($_POST['require_approved']);$cfg['needRechargeAmount']=max(0,dw_post_float('need_recharge_amount'));$cfg['rewardUpAmount']=max(0,dw_post_float('reward_up_amount'));$cfg['specialWheelUnlockAmount']=max(0,dw_post_float('special_unlock_amount'));dw_save_setting($conn,'recharge_wheel_config',$cfg);}
            else throw new RuntimeException('Invalid wheel configuration.');
            dw_admin_audit($conn,$admin,'wheel_settings_save','settings',0,['kind'=>$kind]);dw_flash('success','Wheel settings saved.');break;
        }

        case 'support_update': {
            dw_require_permission($permissions, 'support');$id=dw_post_int('id');$status=dw_post_string('status','Answered');$allowed=['Pending','Processing','Answered','Closed','Rejected'];if(!in_array($status,$allowed,true))throw new RuntimeException('Invalid ticket status.');$reply=dw_post_string('admin_reply');$stmt=$conn->prepare('UPDATE work_orders SET status=?,admin_reply=?,updated_at=NOW() WHERE id=?');if(!$stmt)throw new RuntimeException('Unable to prepare the support reply update.');$stmt->bind_param('ssi',$status,$reply,$id);workorder_execute_insert($stmt);if($stmt->affected_rows<1)throw new RuntimeException('Support ticket was not found or nothing changed.');dw_admin_audit($conn,$admin,'support_update','work_order',$id,['status'=>$status]);dw_flash('success','Support ticket updated.');break;
        }

        case 'agent_update': {
            dw_require_permission($permissions, 'agents');$id=dw_post_int('id');$parent=max(0,dw_post_int('agent_parent_id'));$salary=max(0,dw_post_float('agent_salary'));$isAgent=isset($_POST['is_agent'])?1:0;if($parent===$id)throw new RuntimeException('User cannot be their own parent agent.');$parentValue=$parent>0?$parent:null;$stmt=$conn->prepare('UPDATE users SET is_agent=?,agent_parent_id=?,agent_salary=? WHERE id=?');$stmt->bind_param('iidi',$isAgent,$parentValue,$salary,$id);$stmt->execute();dw_admin_audit($conn,$admin,'agent_update','user',$id,['isAgent'=>$isAgent,'parentId'=>$parent,'salary'=>$salary]);dw_flash('success','Agent hierarchy updated.');break;
        }

        case 'agent_salary_run': {
            dw_require_permission($permissions, 'agents');
            $date=dw_post_string('period_date',date('Y-m-d'));$check=DateTime::createFromFormat('Y-m-d',$date);if(!$check||$check->format('Y-m-d')!==$date)throw new RuntimeException('Use a valid salary date.');
            $agents=dw_rows($conn,"SELECT id,agent_salary FROM users WHERE role='user' AND is_agent=1 AND status=1 AND agent_salary>0 ORDER BY id");
            $paid=0;$total=0.0;$conn->begin_transaction();
            try{
                $dateEsc=$conn->real_escape_string($date);
                foreach($agents as $agent){
                    $uid=(int)$agent['id'];$salary=(float)$agent['agent_salary'];
                    $teamDeposit=(float)dw_scalar($conn,"SELECT COALESCE(SUM(r.amount+r.gift_amount),0) FROM recharge_orders r JOIN users u ON u.id=r.user_id WHERE u.agent_parent_id=".$uid." AND r.status='Payed' AND r.created_at>='$dateEsc' AND r.created_at<DATE_ADD('$dateEsc',INTERVAL 1 DAY)");
                    $teamBet=(float)dw_scalar($conn,"SELECT COALESCE(SUM(b.real_amount+b.fee),0) FROM lottery_bets b JOIN users u ON u.id=b.user_id WHERE u.agent_parent_id=".$uid." AND b.created_at>='$dateEsc' AND b.created_at<DATE_ADD('$dateEsc',INTERVAL 1 DAY)");
                    $stmt=$conn->prepare("INSERT IGNORE INTO agent_salary_records(agent_user_id,period_date,team_deposit,team_bet,salary_amount,status,created_at) VALUES(?,?,?,?,?,'paid',NOW())");
                    $stmt->bind_param('isddd',$uid,$date,$teamDeposit,$teamBet,$salary);$stmt->execute();
                    if($stmt->affected_rows<1)continue;
                    $order='SAL'.str_replace('-','',$date).str_pad((string)$uid,8,'0',STR_PAD_LEFT);
                    if(!wallet_apply_delta($conn,$uid,$salary,'DailySalary',$order,'Daily agent salary '.$date,'Salary','Agent',['periodDate'=>$date,'adminId'=>(int)$admin['id']]))throw new RuntimeException('Salary credit failed for agent #'.$uid);
                    $paid++;$total+=$salary;
                }
                $conn->commit();
            }catch(Throwable $e){$conn->rollback();throw $e;}
            dw_admin_audit($conn,$admin,'agent_salary_run','agent_salary',0,['periodDate'=>$date,'paidAgents'=>$paid,'total'=>$total]);
            dw_flash('success','Daily salary completed for '.$paid.' agent(s), total ₹'.dw_money($total).'. Already-paid agents were skipped.');
            break;
        }

        case 'same_ip_scan': {
            dw_require_permission($permissions, 'risk');
            $conn->begin_transaction();
            $conn->query('UPDATE users SET same_ip_flag=0');
            $conn->query("UPDATE users u JOIN (SELECT ip_last FROM users WHERE role='user' AND ip_last<>'' GROUP BY ip_last HAVING COUNT(*)>1) d ON d.ip_last=u.ip_last SET u.same_ip_flag=1 WHERE u.role='user'");
            $flagged=(int)$conn->affected_rows;$conn->commit();
            dw_admin_audit($conn,$admin,'same_ip_scan','risk',0,['flaggedUsers'=>$flagged]);
            dw_flash('success','Same-IP scan completed. '.$flagged.' account(s) flagged.');
            break;
        }

        case 'risk_save': {
            dw_require_permission($permissions, 'risk');$id=dw_post_int('id');
            if($id>0){$status=dw_post_string('status','reviewing');$allowed=['open','reviewing','resolved','dismissed'];if(!in_array($status,$allowed,true))throw new RuntimeException('Invalid risk status.');$stmt=$conn->prepare("UPDATE risk_flags SET status=?,resolved_at=IF(? IN ('resolved','dismissed'),NOW(),NULL),admin_user_id=? WHERE id=?");$aid=(int)$admin['id'];$stmt->bind_param('ssii',$status,$status,$aid,$id);$stmt->execute();}
            else{$uid=dw_post_int('user_id');$type=dw_post_string('flag_type');$severity=dw_post_string('severity','medium');$reason=dw_post_string('reason');if($uid<=0||$type===''||$reason==='')throw new RuntimeException('User, flag type and reason are required.');$aid=(int)$admin['id'];$stmt=$conn->prepare("INSERT INTO risk_flags(user_id,flag_type,severity,status,reason,admin_user_id,created_at) VALUES(?,?,?,'open',?,?,NOW())");$stmt->bind_param('isssi',$uid,$type,$severity,$reason,$aid);$stmt->execute();$id=(int)$conn->insert_id;}
            dw_admin_audit($conn,$admin,'risk_save','risk_flag',$id);dw_flash('success','Risk flag saved.');break;
        }

        case 'site_settings_save': {
            dw_require_permission($permissions, 'settings');$cfg=site_settings();
            $booleanKeys=['home_enabled','popup_enabled','maintenance_enabled','gift_enabled','recharge_enabled','withdraw_enabled','vip_enabled','agent_enabled','invite_enabled','lottery_enabled','wingo_enabled','k3_enabled','d5_enabled','moto_enabled','trx_enabled','support_chat_enabled','wager_lock_enabled'];
            foreach($booleanKeys as $key)$cfg[$key]=isset($_POST[$key]);
            $cfg['maintenance_text']=dw_post_string('maintenance_text');$cfg['min_withdraw_amount']=max(0,dw_post_float('min_withdraw_amount'));$cfg['deposit_turnover_multiplier']=max(0,(float)($_POST['deposit_turnover_multiplier']??1));$cfg['withdraw_need_bet_multiplier']=$cfg['deposit_turnover_multiplier'];$cfg['turnover_required']=$cfg['deposit_turnover_multiplier'];$cfg['gift_code_turnover_multiplier']=max(0,(float)($_POST['gift_code_turnover_multiplier']??1));$cfg['recharge_gift_turnover_multiplier']=max(0,(float)($_POST['recharge_gift_turnover_multiplier']??0));$cfg['withdraw_daily_limit']=max(0,dw_post_int('withdraw_daily_limit',3));$cfg['withdraw_daily_amount_limit']=max(0,dw_post_float('withdraw_daily_amount_limit',50000));$cfg['telegram_url']=dw_post_string('telegram_url');$cfg['service_url']=dw_post_string('service_url');
            save_site_settings($conn,$cfg);dw_admin_audit($conn,$admin,'site_settings_save','settings',0);dw_flash('success','Site, finance and turnover settings saved.');break;
        }

        case 'admin_create': {
            dw_require_permission($permissions, 'admins');$username=dw_post_string('username');$nickname=dw_post_string('nickname',$username);$password=dw_post_string('password');if(!preg_match('/^[A-Za-z0-9_.-]{4,40}$/',$username)||strlen($password)<10)throw new RuntimeException('Use a valid username and a password of at least 10 characters.');$hash=password_hash($password,PASSWORD_DEFAULT);$tenant=(string)(time().random_int(1000,9999));$invite=strtoupper(substr(bin2hex(random_bytes(5)),0,8));$must=1;$stmt=$conn->prepare("INSERT INTO users(tenant_user_id,username,password_hash,nickname,invite_code,role,status,must_change_password,created_at) VALUES(?,?,?,?,?,'admin',1,?,NOW())");$stmt->bind_param('sssssi',$tenant,$username,$hash,$nickname,$invite,$must);if(!$stmt->execute())throw new RuntimeException('Admin creation failed: '.$stmt->error);$id=(int)$conn->insert_id;dw_admin_audit($conn,$admin,'admin_create','admin',$id,['username'=>$username]);dw_flash('success','Admin created. They must change the temporary password at first login.');break;
        }

        case 'admin_permissions_save': {
            dw_require_permission($permissions, 'admins');$id=dw_post_int('id');if($id===(int)$admin['id'])throw new RuntimeException('Use another super admin to change your own permissions.');$stmt=$conn->prepare("SELECT username FROM users WHERE id=? AND role='admin'");$stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();if(!$row)throw new RuntimeException('Admin not found.');$username=(string)$row['username'];if(strtolower($username)==='admin')throw new RuntimeException('The protected super-admin role cannot be reduced.');$selected=$_POST['permissions']??[];if(!is_array($selected))$selected=[];$allowed=['dashboard','users','finance','recharges','withdrawals','wagers','games','wingo','gifts','payments','content','vip','tasks','wheels','support','agents','risk','settings','admins','audit','system'];$selected=array_values(array_intersect($allowed,array_map('strval',$selected)));$json=json_encode($selected);$status=isset($_POST['permission_status'])?1:0;$stmt=$conn->prepare("INSERT INTO admin_permissions(admin_user,permissions,status,created_at,updated_at) VALUES(?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE permissions=VALUES(permissions),status=VALUES(status),updated_at=NOW()");$stmt->bind_param('ssi',$username,$json,$status);$stmt->execute();dw_admin_audit($conn,$admin,'admin_permissions_save','admin',$id,['permissions'=>$selected,'status'=>$status]);dw_flash('success','Admin permissions saved.');break;
        }

        case 'admin_reset_password': {
            dw_require_permission($permissions, 'admins');$id=dw_post_int('id');$password=dw_post_string('password');if($id===(int)$admin['id'])throw new RuntimeException('Use Change Password for your own account.');$target=$conn->prepare("SELECT username FROM users WHERE id=? AND role='admin'");$target->bind_param('i',$id);$target->execute();$targetRow=$target->get_result()->fetch_assoc();if(!$targetRow)throw new RuntimeException('Admin not found.');if(strtolower((string)$targetRow['username'])==='admin')throw new RuntimeException('The protected super-admin password can only be changed by that account.');if(strlen($password)<10)throw new RuntimeException('Temporary password must be at least 10 characters.');$hash=password_hash($password,PASSWORD_DEFAULT);$token=bin2hex(random_bytes(20));$stmt=$conn->prepare("UPDATE users SET password_hash=?,must_change_password=1,login_session_token=? WHERE id=? AND role='admin'");$stmt->bind_param('ssi',$hash,$token,$id);$stmt->execute();dw_admin_audit($conn,$admin,'admin_reset_password','admin',$id);dw_flash('success','Admin password reset; password change required at next login.');break;
        }

        default:
            throw new RuntimeException('Unknown admin action.');
    }

    dw_redirect($page, $returnQuery);
}
