<?php
require __DIR__.'/bootstrap.php';

function umeRemoteJson($data,$status){
    http_response_code((int)$status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if(!UMEUtil::isHttps())umeRemoteJson(array('ok'=>false,'error'=>'Remote admin доступен только по HTTPS.'),403);
$action=isset($_GET['action'])?(string)$_GET['action']:'probe';

try{
    if($action==='probe'){
        $probe=$selfUpdater->remoteAdminProbeInfo();$central=!empty($probe['central_repository']);$clientConfigured=!empty($probe['client_configured']);
        umeRemoteJson(array(
            'ok'=>true,
            'format'=>'ume-remote-admin-probe/1.0',
            'site_id'=>(string)$config['site_id'],
            'domain'=>UMEUtil::domain(),
            'version'=>(string)$config['version'],
            'remote_reset_supported'=>!empty($probe['remote_reset_supported']),
            'remote_set_credentials_supported'=>!empty($probe['remote_set_credentials_supported']),
            'recovery_email_supported'=>!empty($probe['recovery_email_supported']),
            'password_reset_email_supported'=>!empty($probe['password_reset_email_supported']),
            'central_repository'=>$central
        ),200);
    }
    if($action==='send_recovery'){
        if($_SERVER['REQUEST_METHOD']!=='POST')umeRemoteJson(array('ok'=>false,'error'=>'Требуется POST.'),405);
        $provided=isset($_SERVER['HTTP_X_UME_UPDATE_KEY'])?(string)$_SERVER['HTTP_X_UME_UPDATE_KEY']:'';
        $raw=@file_get_contents('php://input');if($raw===false||strlen($raw)>65536)umeRemoteJson(array('ok'=>false,'error'=>'Recovery-запрос слишком велик или не читается.'),400);
        $payload=json_decode($raw,true);if(!is_array($payload))umeRemoteJson(array('ok'=>false,'error'=>'Некорректный JSON Recovery-запроса.'),400);
        $result=$selfUpdater->centralSendRecoveryEmail($payload,$provided);
        umeRemoteJson(array('ok'=>true,'format'=>'ume-recovery-mail-result/1.0','sent'=>!empty($result['sent']),'message'=>'Recovery-письмо отправлено.'),200);
    }
    if($action==='send_password_reset'){
        if($_SERVER['REQUEST_METHOD']!=='POST')umeRemoteJson(array('ok'=>false,'error'=>'Требуется POST.'),405);
        $provided=isset($_SERVER['HTTP_X_UME_UPDATE_KEY'])?(string)$_SERVER['HTTP_X_UME_UPDATE_KEY']:'';
        $raw=@file_get_contents('php://input');if($raw===false||strlen($raw)>65536)umeRemoteJson(array('ok'=>false,'error'=>'Запрос восстановления пароля слишком велик или не читается.'),400);
        $payload=json_decode($raw,true);if(!is_array($payload))umeRemoteJson(array('ok'=>false,'error'=>'Некорректный JSON запроса восстановления пароля.'),400);
        $result=$selfUpdater->centralSendPasswordResetEmail($payload,$provided);
        umeRemoteJson(array('ok'=>true,'format'=>'ume-password-reset-mail-result/1.0','sent'=>!empty($result['sent']),'message'=>'Письмо восстановления пароля отправлено.'),200);
    }
    if($action==='reset_password'){
        if($_SERVER['REQUEST_METHOD']!=='POST')umeRemoteJson(array('ok'=>false,'error'=>'Требуется POST.'),405);
        $raw=@file_get_contents('php://input');if($raw===false||strlen($raw)>131072)umeRemoteJson(array('ok'=>false,'error'=>'Команда слишком велика или не читается.'),400);
        $payload=json_decode($raw,true);if(!is_array($payload)||empty($payload['command'])||!is_array($payload['command'])||empty($payload['signature']))umeRemoteJson(array('ok'=>false,'error'=>'Некорректная команда удалённого сброса.'),400);
        $verified=$selfUpdater->verifyRemotePasswordReset($payload['command'],$payload['signature']);
        $result=$auth->resetPasswordHash($verified['password_hash'],true,'central_remote_reset');
        umeRemoteJson(array('ok'=>true,'format'=>'ume-password-reset-result/1.0','username'=>$result['username'],'force_password_change'=>true,'sessions_revoked'=>true,'message'=>'Временный пароль установлен. Все старые сессии отозваны.'),200);
    }
    if($action==='set_credentials'){
        if($_SERVER['REQUEST_METHOD']!=='POST')umeRemoteJson(array('ok'=>false,'error'=>'Требуется POST.'),405);
        $raw=@file_get_contents('php://input');if($raw===false||strlen($raw)>131072)umeRemoteJson(array('ok'=>false,'error'=>'Команда слишком велика или не читается.'),400);
        $payload=json_decode($raw,true);if(!is_array($payload)||empty($payload['command'])||!is_array($payload['command'])||empty($payload['signature']))umeRemoteJson(array('ok'=>false,'error'=>'Некорректная команда установки логина и пароля.'),400);
        $verified=$selfUpdater->verifyRemoteSetCredentials($payload['command'],$payload['signature']);
        $result=$auth->applyRemoteCredentials($verified['admin_email'],$verified['password_hash'],'central_remote_set_credentials');
        umeRemoteJson(array('ok'=>true,'format'=>'ume-set-credentials-result/1.0','username'=>$result['username'],'force_password_change'=>false,'sessions_revoked'=>true,'message'=>'Логин и пароль установлены центральным сайтом. Все старые сессии отозваны.'),200);
    }
    umeRemoteJson(array('ok'=>false,'error'=>'Неизвестное действие.'),404);
}catch(Exception $e){umeRemoteJson(array('ok'=>false,'error'=>$e->getMessage()),403);}
catch(Throwable $e){umeRemoteJson(array('ok'=>false,'error'=>'Внутренняя ошибка удалённого сброса: '.$e->getMessage()),500);}
