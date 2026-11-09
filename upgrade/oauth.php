<?php
require __DIR__.'/bootstrap.php';

function umeOauthJson($data,$status){http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function umeOauthEscape($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function umeOauthInput(){
    $type=isset($_SERVER['CONTENT_TYPE'])?strtolower($_SERVER['CONTENT_TYPE']):'';
    if(strpos($type,'application/json')!==false){$raw=@file_get_contents('php://input');$data=json_decode($raw,true);return is_array($data)?$data:array();}
    return $_POST;
}
function umeOauthRedirect($uri,$params){$separator=strpos($uri,'?')===false?'?':'&';header('Location: '.$uri.$separator.http_build_query($params,'','&'));exit;}
function umeOauthErrorPage($message){http_response_code(400);header('Content-Type: text/html; charset=utf-8');?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Ошибка подключения · Upgrade Manager</title><link rel="icon" href="./favicon.ico" sizes="any"><link rel="stylesheet" href="./assets/app.css?v=<?php echo (int)@filemtime(__DIR__.'/assets/app.css'); ?>"></head><body class="login-body"><div class="login-shell"><div class="login-card"><div class="login-brand"><span>U</span></div><div class="eyebrow">OAuth 2.1</div><h1>Подключение не выполнено</h1><p><?php echo umeOauthEscape($message); ?></p><a class="button primary full" href="./?view=api-connection">Вернуться в Upgrade Manager</a></div></div></body></html><?php exit;}

$action=isset($_GET['action'])?(string)$_GET['action']:'';
try{
    if($action==='protected-resource'){umeOauthJson($oauth->protectedResourceMetadata(),200);}
    if($action==='metadata'){umeOauthJson($oauth->authorizationServerMetadata(),200);}
    if($action==='register'){
        if($_SERVER['REQUEST_METHOD']!=='POST')umeOauthJson(array('error'=>'invalid_request','error_description'=>'Требуется POST.'),405);
        umeOauthJson($oauth->registerClient(umeOauthInput()),201);
    }
    if($action==='token'){
        if($_SERVER['REQUEST_METHOD']!=='POST')umeOauthJson(array('error'=>'invalid_request','error_description'=>'Требуется POST.'),405);
        try{umeOauthJson($oauth->exchangeToken(umeOauthInput()),200);}catch(Exception $e){umeOauthJson(array('error'=>'invalid_grant','error_description'=>$e->getMessage()),400);}
    }
    if($action==='revoke'){
        if($_SERVER['REQUEST_METHOD']!=='POST')umeOauthJson(array('error'=>'invalid_request'),405);$input=umeOauthInput();$oauth->revoke(isset($input['token'])?$input['token']:'');http_response_code(200);exit;
    }
    if($action!=='authorize')umeOauthJson(array('error'=>'not_found'),404);

    $fields=array('response_type','client_id','redirect_uri','scope','state','code_challenge','code_challenge_method','resource');$params=array();foreach($fields as $field){if(isset($_POST[$field]))$params[$field]=$_POST[$field];else if(isset($_GET[$field]))$params[$field]=$_GET[$field];}
    $validated=$oauth->validateAuthorizationRequest($params);
    if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['decision'])&&$_POST['decision']==='deny')umeOauthRedirect($validated['redirect_uri'],array('error'=>'access_denied','state'=>$validated['state'],'iss'=>$oauth->issuer()));
    $error='';
    if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['decision'])&&$_POST['decision']==='approve'){
        if($auth->loggedIn()){
            if(!$auth->checkCsrf(isset($_POST['csrf'])?$_POST['csrf']:''))$error='Сессия устарела. Обновите страницу и повторите подключение.';
        }else{
            try{if(!$auth->login(isset($_POST['username'])?$_POST['username']:'',isset($_POST['password'])?$_POST['password']:''))$error='Неверный логин или пароль Upgrade Manager.';}catch(Exception $e){$error=$e->getMessage();}
        }
        if($error===''&&$auth->requiresEmailBinding())$error='Сначала откройте Upgrade Manager и привяжите email администратора в разделе «Безопасность».';
        if($error===''&&$auth->requiresPasswordChange())$error='Сначала замените временный пароль в разделе «Безопасность».';
        if($error===''){$code=$oauth->issueAuthorizationCode($validated);umeOauthRedirect($validated['redirect_uri'],array('code'=>$code,'state'=>$validated['state'],'iss'=>$oauth->issuer()));}
    }
    $logged=$auth->loggedIn();$csrf=$logged?$auth->csrf():'';$scopeLabels=array('site:read'=>'Чтение разрешённых файлов и состояния сайта','tasks:read'=>'Просмотр истории API-задач','tasks:write'=>'Создание ТЗ и журнала выполнения','backups:write'=>'Создание резервных копий','patches:write'=>'Загрузка, установка и откат подписанных патчей');
?><!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Подключить ChatGPT · Upgrade Manager</title><link rel="icon" href="./favicon.ico" sizes="any"><link rel="stylesheet" href="./assets/app.css?v=<?php echo (int)@filemtime(__DIR__.'/assets/app.css'); ?>"></head>
<body class="login-body"><div class="login-shell"><form class="login-card oauth-card" method="post" action="./oauth.php?action=authorize">
    <div class="login-brand"><span>U</span></div><div class="eyebrow">Защищённое подключение OAuth 2.1</div><h1>Подключить ChatGPT</h1>
    <p>Приложение <strong><?php echo umeOauthEscape($validated['client']['client_name']); ?></strong> запрашивает доступ к сайту <strong><?php echo umeOauthEscape(UMEUtil::domain()); ?></strong>.</p>
    <?php if($error!==''): ?><div class="notice error persistent"><?php echo umeOauthEscape($error); ?></div><?php endif; ?>
    <ul class="oauth-scopes"><?php foreach($validated['scope'] as $scope): ?><li><strong><?php echo umeOauthEscape($scope); ?></strong><span><?php echo umeOauthEscape(isset($scopeLabels[$scope])?$scopeLabels[$scope]:$scope); ?></span></li><?php endforeach; ?></ul>
    <?php foreach($params as $key=>$value): ?><input type="hidden" name="<?php echo umeOauthEscape($key); ?>" value="<?php echo umeOauthEscape($value); ?>"><?php endforeach; ?>
    <input type="hidden" name="csrf" value="<?php echo umeOauthEscape($csrf); ?>">
    <?php if(!$logged): ?><label for="oauth-username">Email / логин Upgrade Manager</label><input id="oauth-username" type="text" name="username" value="" autocomplete="username" autocapitalize="none" spellcheck="false" required><label for="oauth-password">Пароль Upgrade Manager</label><input id="oauth-password" type="password" name="password" autocomplete="current-password" required><?php else: ?><div class="oauth-session-ok">Вы уже вошли в Upgrade Manager. Подтвердите подключение.</div><?php endif; ?>
    <div class="oauth-actions"><button class="button secondary" type="submit" name="decision" value="deny">Отмена</button><button class="button primary" type="submit" name="decision" value="approve">Подключить ChatGPT</button></div>
    <small class="oauth-note">Секретные ключи сайта не передаются. Доступ можно отключить в разделе «API-подключение».</small>
</form></div></body></html>
<?php
}catch(Exception $e){
    if(in_array($action,array('register','token','revoke','metadata','protected-resource'),true))umeOauthJson(array('error'=>'invalid_request','error_description'=>$e->getMessage()),400);
    umeOauthErrorPage($e->getMessage());
}
