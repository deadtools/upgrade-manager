<?php
header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__.'/lib/Util.php';require_once __DIR__.'/lib/CmsAdapter.php';
$configFile=__DIR__.'/config.local.php';if(is_file($configFile)){header('Location: ./');exit;}
// 3.6.6: an unconfigured installation can be claimed only by (a) the browser that ran the network installer
// (one-time UME_SETUP cookie, 30 minutes) or (b) someone who can read upgrade/setup-key.local.php through
// FTP / the hosting file manager. A random visitor can no longer become the administrator.
$setupTokenFile=__DIR__.'/setup-token.local.php';$setupKeyFile=__DIR__.'/setup-key.local.php';
function umeSetupCookiePath(){$p=rtrim(str_replace('\\','/',dirname(isset($_SERVER['SCRIPT_NAME'])?$_SERVER['SCRIPT_NAME']:'/upgrade/setup.php')),'/');return ($p===''||$p==='.')?'/':$p.'/';}
function umeSetupTokenValid($file){
    if(!is_file($file))return false;$t=@include $file;if(!is_array($t)||empty($t['token_sha256'])||empty($t['expires_at'])||(int)$t['expires_at']<time())return false;
    $c=isset($_COOKIE['UME_SETUP'])&&is_string($_COOKIE['UME_SETUP'])?$_COOKIE['UME_SETUP']:'';if(!preg_match('/^[a-f0-9]{64}$/',$c))return false;
    return function_exists('hash_equals')?hash_equals((string)$t['token_sha256'],hash('sha256',$c)):(string)$t['token_sha256']===hash('sha256',$c);
}
function umeSetupKey($file){
    if(is_file($file)){$k=@include $file;if(is_string($k)&&preg_match('/^[A-F0-9]{4}(?:-[A-F0-9]{4}){3}$/',$k))return $k;}
    $key=implode('-',str_split(strtoupper(UMEUtil::randomHex(8)),4));
    $php="<?php\n// Ключ установки Upgrade Manager. Введите его на странице /upgrade/setup.php.\n// Файл удаляется автоматически после завершения настройки.\nreturn ".var_export($key,true).";\n";
    $tmp=$file.'.tmp.'.UMEUtil::randomHex(4);if(@file_put_contents($tmp,$php,LOCK_EX)===false||!@rename($tmp,$file)){@unlink($tmp);return '';}@chmod($file,0600);return $key;
}
function umeSetupKeyMatches($given,$key){$given=strtoupper(preg_replace('/[^A-Fa-f0-9]/','',(string)$given));$expected=str_replace('-','',(string)$key);if($expected===''||strlen($given)!==strlen($expected))return false;return function_exists('hash_equals')?hash_equals($expected,$given):$expected===$given;}
$setupTokenOk=umeSetupTokenValid($setupTokenFile);$setupKey=$setupTokenOk?'':umeSetupKey($setupKeyFile);
$defaultUpdateServerUrl='';
$defaultUpdateRepositoryKey='';
$updateSourceFile=__DIR__.'/update-source.local.php';$updateSource=array('url'=>$defaultUpdateServerUrl,'key'=>$defaultUpdateRepositoryKey);if(is_file($updateSourceFile)){$tmpSource=require $updateSourceFile;if(is_array($tmpSource)){if(!empty($tmpSource['url']))$updateSource['url']=(string)$tmpSource['url'];if(!empty($tmpSource['key']))$updateSource['key']=(string)$tmpSource['key'];}}
$siteRoot=realpath(__DIR__.'/..');if(!$siteRoot)die('Не удалось определить корень сайта.');
$cms=UMECmsAdapter::detect($siteRoot);$domain=UMEUtil::domain();$defaultProject=preg_replace('/[^a-z0-9.-]+/','-',strtolower($domain));if($defaultProject==='')$defaultProject='website';$error='';$complete=false;$storageMode='';$createdEmail='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!$setupTokenOk){if($setupKey==='')throw new Exception('Не удалось создать ключ установки: каталог upgrade недоступен для записи.');if(!umeSetupKeyMatches(isset($_POST['setup_key'])?$_POST['setup_key']:'',$setupKey)){usleep(400000);throw new Exception('Неверный ключ установки. Откройте файл upgrade/setup-key.local.php через FTP или файловый менеджер хостинга и скопируйте ключ из него.');}}
        if(isset($cms['supported'])&&!$cms['supported'])throw new Exception(isset($cms['support_reason'])&&$cms['support_reason']!==''?$cms['support_reason']:'Текущая версия CMS не поддерживается.');
        $project=isset($_POST['project'])?trim(strtolower($_POST['project'])):'';
        if(!preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/',$project))throw new Exception('Идентификатор проекта: 3-100 латинских символов, цифр, точек, дефисов или подчёркиваний.');
        $adminEmail=isset($_POST['admin_email'])?strtolower(trim((string)$_POST['admin_email'])):'';
        if($adminEmail===''||strlen($adminEmail)>254||filter_var($adminEmail,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Укажите корректный email администратора.');
        $password=isset($_POST['password'])?(string)$_POST['password']:'';$passwordConfirm=isset($_POST['password_confirm'])?(string)$_POST['password_confirm']:'';
        UMEUtil::assertStrongPassword($password);
        if($password!==$passwordConfirm)throw new Exception('Пароли не совпадают.');
        $passwordHash=password_hash($password,PASSWORD_BCRYPT);if(!is_string($passwordHash)||$passwordHash==='')throw new Exception('Не удалось создать защищённый хэш пароля.');
        if($domain==='')throw new Exception('Не удалось определить домен сайта.');if(!is_writable(__DIR__))throw new Exception('Каталог upgrade недоступен для записи.');
        $allowed=array($domain);if(substr($domain,0,4)==='www.')$allowed[]=substr($domain,4);else $allowed[]='www.'.$domain;$allowed=array_values(array_unique($allowed));$siteId='ume-'.substr(hash('sha256',$domain.'|'.UMEUtil::randomHex(32)),0,28);
        $storage=UMECmsAdapter::chooseStorage($siteRoot,$siteId);$storageMode=$storage['mode'];$defaults=UMECmsAdapter::configDefaults($siteRoot);
        $config=array_merge($defaults,array(
            'version'=>'3.7.4','site_id'=>$siteId,'project'=>$project,'allowed_domains'=>$allowed,'hmac_key'=>UMEUtil::randomHex(32),
            'login_username'=>$adminEmail,'admin_email'=>$adminEmail,'password_hash'=>$passwordHash,'force_password_change'=>false,'storage_path'=>$storage['path'],'storage_mode'=>$storage['mode'],'storage_web_exposed_risk'=>$storage['web_exposed_risk'],'site_root_relative'=>'..','update_server_url'=>isset($updateSource['url'])?(string)$updateSource['url']:$defaultUpdateServerUrl,'update_repository_key'=>isset($updateSource['key'])?(string)$updateSource['key']:$defaultUpdateRepositoryKey
        ));
        $php="<?php\n// Upgrade Manager - generated locally. Never publish or share this file.\nreturn ".var_export($config,true).";\n";$temp=$configFile.'.tmp.'.UMEUtil::randomHex(4);if(@file_put_contents($temp,$php,LOCK_EX)===false||!@rename($temp,$configFile)){@unlink($temp);throw new Exception('Не удалось создать config.local.php. Проверьте права каталога.');}$createdEmail=$adminEmail;$complete=true;if(is_file($updateSourceFile))@unlink($updateSourceFile);@unlink($setupTokenFile);@unlink($setupKeyFile);header('Set-Cookie: UME_SETUP=deleted; Max-Age=0; Path='.umeSetupCookiePath().'; HttpOnly; SameSite=Lax'.(UMEUtil::isHttps()?'; Secure':''),false);
    }catch(Exception $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Установка Upgrade Manager</title><link rel="icon" href="./favicon.ico" sizes="any"><link rel="stylesheet" href="./assets/app.css?v=<?php echo (int)@filemtime(__DIR__.'/assets/app.css'); ?>"></head><body class="login-body"><div class="login-shell">
<?php if($complete): ?><div class="login-card setup-card"><div class="login-brand"><span>U</span></div><div class="eyebrow">Установка завершена</div><h1>Upgrade Manager готов</h1><p>Определена CMS: <strong><?php echo htmlspecialchars($cms['name'],ENT_QUOTES,'UTF-8'); ?></strong><?php echo $cms['version']!==''?' '.htmlspecialchars($cms['version'],ENT_QUOTES,'UTF-8'):''; ?>. Администратор: <strong><?php echo htmlspecialchars($createdEmail,ENT_QUOTES,'UTF-8'); ?></strong>.</p><div class="notice ok persistent"><strong>Ничего дополнительно сохранять не нужно.</strong><br>Если забудете пароль, используйте ссылку «Забыли пароль?» на форме входа. После настройки центрального сервера аварийный Recovery выполняется одноразовой ссылкой на email.</div><?php if($storageMode==='inside_upgrade_randomized'): ?><div class="notice warning persistent">Хостинг не позволил создать хранилище выше web-root. Использован случайный защищённый каталог внутри /upgrade. Для Nginx рекомендуется дополнительно запретить прямой доступ к скрытым каталогам.</div><?php endif; ?><a class="button primary full" href="./">Открыть Upgrade Manager</a></div>
<?php else: ?><form class="login-card setup-card" method="post"><div class="login-brand"><span>U</span></div><div class="eyebrow">Универсальная установка</div><h1>Настройка сайта</h1><p>CMS определена автоматически: <strong><?php echo htmlspecialchars($cms['name'],ENT_QUOTES,'UTF-8'); ?></strong><?php echo $cms['version']!==''?' '.htmlspecialchars($cms['version'],ENT_QUOTES,'UTF-8'):''; ?>.</p><?php if(isset($cms['supported'])&&!$cms['supported']): ?><div class="notice error persistent"><?php echo htmlspecialchars($cms['support_reason'],ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?><?php if($cms['database_available'] && extension_loaded('mysqli')): ?><div class="notice ok persistent">База MySQL/MariaDB распознана: файловые бэкапы, БД и SQL-миграции доступны.</div><?php else: ?><div class="notice warning persistent">Файловый режим доступен. Бэкап/миграции БД будут отключены, пока адаптер не сможет безопасно определить поддерживаемую MySQL/MariaDB конфигурацию.</div><?php endif; ?><?php if($error!==''): ?><div class="notice error persistent"><?php echo htmlspecialchars($error,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
<?php if($setupTokenOk): ?><div class="notice ok persistent"><strong>Установка подтверждена этим браузером.</strong> Сетевой установщик выдал одноразовый допуск к настройке на 30 минут.</div><?php else: ?><div class="notice warning persistent"><strong>Нужен ключ установки.</strong> Он создан в файле <code>upgrade/setup-key.local.php</code> на сервере. Откройте файл через FTP или файловый менеджер хостинга и скопируйте ключ — так Upgrade Manager убеждается, что настройку завершает владелец сайта.</div>
<label for="setup-key" class="setup-label">Ключ установки</label><input id="setup-key" name="setup_key" value="" placeholder="XXXX-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false" required><?php endif; ?>
<label for="setup-domain">Домен</label><input id="setup-domain" value="<?php echo htmlspecialchars($domain,ENT_QUOTES,'UTF-8'); ?>" disabled>
<label for="setup-project" class="setup-label">Идентификатор проекта</label><input id="setup-project" name="project" value="<?php echo htmlspecialchars(isset($_POST['project'])?$_POST['project']:$defaultProject,ENT_QUOTES,'UTF-8'); ?>" pattern="[a-z0-9][a-z0-9._-]{2,99}" required>
<label for="setup-admin-email" class="setup-label">Email администратора</label><input id="setup-admin-email" name="admin_email" type="email" value="<?php echo htmlspecialchars(isset($_POST['admin_email'])?$_POST['admin_email']:'',ENT_QUOTES,'UTF-8'); ?>" autocomplete="username" required>
<label for="setup-password" class="setup-label">Пароль администратора</label><input id="setup-password" name="password" type="password" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}" autocomplete="new-password" required>
<label for="setup-password-confirm" class="setup-label">Повторите пароль</label><input id="setup-password-confirm" name="password_confirm" type="password" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}" autocomplete="new-password" required>
<small class="password-policy">Минимум 8 символов: латинская строчная буква, заглавная буква и цифра.</small>
<div class="notice ok persistent"><strong>Email станет логином Upgrade Manager.</strong> После настройки центрального сервера Recovery выполняется одноразовой ссылкой на этот адрес.</div>
<button class="button primary full" type="submit">Создать защищённый профиль</button><small class="oauth-note">В конфигурации хранится только bcrypt-хэш пароля. CMS-конфиги и пароли БД не выдаются через MCP.</small></form><?php endif; ?>
</div></body></html>
