<?php
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require __DIR__.'/bootstrap.php';

function umeResetEscape($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function umeResetSecurityLog($storage,$event,$details=array()){
    $row=array('time'=>UMEUtil::now(),'event'=>(string)$event);
    if(is_array($details))foreach($details as $key=>$value)$row[$key]=$value;
    $json=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($json!==false)UMEUtil::appendLine(rtrim($storage,'/').'/security-events.log',$json);
}

$error='';$message='';$complete=false;$ticket='';$signature='';$ticketValid=false;
if(isset($_REQUEST['ticket']))$ticket=(string)$_REQUEST['ticket'];
if(isset($_REQUEST['signature']))$signature=(string)$_REQUEST['signature'];

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['request_reset'])){
    $started=microtime(true);$email=isset($_POST['admin_email'])?strtolower(trim((string)$_POST['admin_email'])):'';$matched=false;
    try{
        $matched=$auth->recoveryEmailMatches($email);
        if($matched)$selfUpdater->requestPasswordResetEmail($email);
        umeResetSecurityLog($manager->storage(),'password_reset_email_requested',array('email_hash'=>$email!==''?hash('sha256',$email):'','matched'=>$matched));
    }catch(Exception $e){
        umeResetSecurityLog($manager->storage(),'password_reset_email_request_error',array('email_hash'=>$email!==''?hash('sha256',$email):'','message'=>substr($e->getMessage(),0,300)));
    }catch(Throwable $e){
        umeResetSecurityLog($manager->storage(),'password_reset_email_request_error',array('email_hash'=>$email!==''?hash('sha256',$email):'','message'=>'Throwable: '.substr($e->getMessage(),0,260)));
    }
    $elapsed=microtime(true)-$started;if($elapsed<0.35)usleep((int)((0.35-$elapsed)*1000000));
    $message='Если этот email привязан к Upgrade Manager, на него отправлена ссылка для восстановления пароля.';
}

if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['set_password'])){
    try{
        if($ticket===''||$signature==='')throw new Exception('Ссылка восстановления пароля отсутствует или повреждена.');
        $selfUpdater->verifyPasswordResetTicket($ticket,$signature,$auth->adminEmail(),false);$ticketValid=true;
        $new=isset($_POST['new_password'])?(string)$_POST['new_password']:'';
        $confirm=isset($_POST['password_confirm'])?(string)$_POST['password_confirm']:'';
        if($new!==$confirm)throw new Exception('Пароли не совпадают.');
        UMEUtil::assertStrongPassword($new);
        $verified=$selfUpdater->verifyPasswordResetTicket($ticket,$signature,$auth->adminEmail(),true);
        $auth->setPasswordFromEmailReset($new);
        umeResetSecurityLog($manager->storage(),'password_reset_completed',array('ticket_nonce_hash'=>hash('sha256',(string)$verified['nonce']),'sessions_revoked'=>true));
        $complete=true;$ticket='';$signature='';$ticketValid=false;
    }catch(Exception $e){$error=$e->getMessage();}
    catch(Throwable $e){$error='Не удалось изменить пароль. Запросите новую ссылку и повторите попытку.';}
}

if(!$complete&&!$ticketValid&&$ticket!==''&&$signature!==''&&$error===''){
    try{$selfUpdater->verifyPasswordResetTicket($ticket,$signature,$auth->adminEmail(),false);$ticketValid=true;}
    catch(Exception $e){$error=$e->getMessage();}
    catch(Throwable $e){$error='Ссылка восстановления пароля не может быть подтверждена.';}
}
?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Восстановление пароля · Upgrade Manager</title>
    <link rel="icon" href="./favicon.ico" sizes="any">
    <link rel="stylesheet" href="./assets/app.css?v=<?php echo (int)@filemtime(__DIR__.'/assets/app.css'); ?>">
</head>
<body class="login-body">
<div class="login-shell">
<?php if($complete): ?>
    <div class="login-card">
        <div class="login-brand"><span>U</span></div>
        <div class="eyebrow">Пароль изменён</div>
        <h1>Доступ восстановлен</h1>
        <p>Новый пароль сохранён. Все ранее выданные сессии Upgrade Manager отозваны.</p>
        <a class="button primary full" href="./">Войти в систему</a>
    </div>
<?php elseif($ticketValid): ?>
    <form class="login-card" method="post" autocomplete="off">
        <div class="login-brand"><span>U</span></div>
        <div class="eyebrow">Восстановление доступа</div>
        <h1>Новый пароль</h1>
        <p>Задайте новый пароль администратора. После сохранения все старые сессии будут отключены.</p>
        <?php if($error!==''): ?><div class="notice error persistent"><?php echo umeResetEscape($error); ?></div><?php endif; ?>
        <label for="reset-new-password">Новый пароль</label>
        <input id="reset-new-password" type="password" name="new_password" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}" autocomplete="new-password" required autofocus>
        <label for="reset-password-confirm">Повторите пароль</label>
        <input id="reset-password-confirm" type="password" name="password_confirm" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}" autocomplete="new-password" required>
        <input type="hidden" name="ticket" value="<?php echo umeResetEscape($ticket); ?>">
        <input type="hidden" name="signature" value="<?php echo umeResetEscape($signature); ?>">
        <input type="hidden" name="set_password" value="1">
        <small class="password-policy">Минимум 8 символов: латинская строчная буква, заглавная буква и цифра.</small>
        <button class="button primary full" type="submit">Сохранить новый пароль</button>
    </form>
<?php elseif($ticket!==''||$signature!==''): ?>
    <div class="login-card">
        <div class="login-brand"><span>U</span></div>
        <div class="eyebrow">Ссылка недействительна</div>
        <h1>Восстановление пароля</h1>
        <p><?php echo umeResetEscape($error!==''?$error:'Ссылка восстановления пароля недействительна.'); ?></p>
        <a class="button primary full" href="./reset-password.php">Запросить новую ссылку</a>
        <a class="login-secondary-link" href="./">Вернуться ко входу</a>
    </div>
<?php else: ?>
    <form class="login-card" method="post">
        <div class="login-brand"><span>U</span></div>
        <div class="eyebrow">Восстановление доступа</div>
        <h1>Забыли пароль?</h1>
        <p>Введите email администратора. Одноразовая ссылка для смены пароля придёт через настроенный центральный Upgrade Manager.</p>
        <label for="reset-email">Email администратора</label>
        <input id="reset-email" type="email" name="admin_email" autocomplete="username" required autofocus>
        <input type="hidden" name="request_reset" value="1">
        <button class="button primary full" type="submit">Отправить ссылку</button>
        <?php if($message!==''): ?><div class="notice ok persistent"><?php echo umeResetEscape($message); ?></div><?php endif; ?>
        <?php if($error!==''): ?><div class="notice error persistent"><?php echo umeResetEscape($error); ?></div><?php endif; ?>
        <small class="oauth-note">Ссылка действует 10 минут и только один раз.</small>
        <a class="login-secondary-link" href="./">Вернуться ко входу</a>
    </form>
<?php endif; ?>
</div>
</body>
</html>
