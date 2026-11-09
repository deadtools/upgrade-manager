<?php
require __DIR__.'/bootstrap.php';

function jsonResponse($data, $status) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requireAuth($auth) {
    if (!$auth->loggedIn()) jsonResponse(array('ok' => false, 'error' => 'Требуется вход.'), 401);
}

function umeEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function umeNavClass($current, $target) {
    return $current === $target ? 'nav-item active' : 'nav-item';
}


$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
$loginError = '';
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: ./');
        exit;
    }
    try {
        $ok = $auth->login(isset($_POST['username']) ? $_POST['username'] : '', isset($_POST['password']) ? $_POST['password'] : '');
        if ($isAjax) {
            if ($ok) jsonResponse(array('ok' => true), 200);
            jsonResponse(array('ok' => false, 'error' => 'Неверный логин или пароль.'), 403);
        }
        if ($ok) {
            header('Location: ./');
            exit;
        }
        $loginError = 'Неверный логин или пароль.';
        $action = '';
    } catch (Exception $e) {
        if ($isAjax) jsonResponse(array('ok' => false, 'error' => $e->getMessage()), 429);
        $loginError = $e->getMessage();
        $action = '';
    }
}

if ($action === 'logout') {
    $auth->logout();
    header('Location: ./');
    exit;
}

// Legacy public-backup URLs from 3.2.1/3.2.2 are redirected to the
// dedicated unauthenticated endpoint. The old token parameter is ignored.
if ($action === 'public_backup') {
    $params = array('id' => isset($_GET['id']) ? (string)$_GET['id'] : '');
    foreach (array('download','format','source','entry','offset','page','q','raw') as $key) {
        if (isset($_GET[$key]) && (string)$_GET[$key] !== '') $params[$key] = (string)$_GET[$key];
    }
    $query = defined('PHP_QUERY_RFC3986') ? http_build_query($params, '', '&', PHP_QUERY_RFC3986) : http_build_query($params);
    header('Location: ./public-backup.php?'.$query, true, 302);
    exit;
}

if ($action !== '') {
    requireAuth($auth);
    // 3.6.3: state-changing actions are accepted only as POST with a CSRF token.
    // GET is limited to read-only views and downloads.
    $readOnlyGetActions = array('dashboard', 'download', 'profile', 'download_network_installer', 'self_update_status');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !in_array($action, $readOnlyGetActions, true)) {
        jsonResponse(array('ok' => false, 'error' => 'Это действие выполняется только POST-запросом из интерфейса Upgrade Manager.'), 405);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$auth->checkCsrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        jsonResponse(array('ok' => false, 'error' => 'CSRF-проверка не пройдена.'), 403);
    }
    if ($auth->requiresEmailBinding() && !in_array($action, array('dashboard','change_password','change_admin_email'), true)) {
        jsonResponse(array('ok' => false, 'error' => 'Сначала привяжите email администратора в разделе «Безопасность».'), 403);
    }
    if ($auth->requiresPasswordChange() && !in_array($action, array('dashboard','change_password','change_admin_email'), true)) {
        jsonResponse(array('ok' => false, 'error' => 'Сначала замените временный пароль в разделе «Безопасность».'), 403);
    }
    try {
        if ($action === 'dashboard') {
            $dashboard=$manager->dashboard();
            $dashboard['api_tasks']=$apiTasks->listTasks(50);
            $dashboard['api']=$oauth->status();
            $dashboard['self_update']=$selfUpdater->status(false);
            $dashboard['cron']=$cronScheduler->status();
            jsonResponse(array('ok' => true, 'data' => $dashboard), 200);
        }
        if ($action === 'start_backup') jsonResponse(array('ok' => true, 'job' => $manager->publicJob($manager->startBackup())), 200);
        if ($action === 'start_backup_restore') jsonResponse(array('ok' => true, 'job' => $manager->publicJob($manager->startBackupRestore(isset($_POST['backup_id']) ? $_POST['backup_id'] : ''))), 200);
        if ($action === 'start_migration_export') jsonResponse(array('ok' => true, 'job' => $migration->publicJob($migration->startExport(isset($_POST['target_domain']) ? $_POST['target_domain'] : ''))), 200);
        if ($action === 'migration_export_link') jsonResponse(array('ok' => true, 'link' => $migration->exportLink(isset($_POST['job_id']) ? (string)$_POST['job_id'] : '')), 200);
        if ($action === 'start_migration_import') jsonResponse(array('ok' => true, 'job' => $migration->publicJob($migration->startImport(isset($_POST['source_url']) ? $_POST['source_url'] : '',isset($_POST['db_host']) ? $_POST['db_host'] : 'localhost',isset($_POST['db_port']) ? $_POST['db_port'] : 3306,isset($_POST['db_name']) ? $_POST['db_name'] : '',isset($_POST['db_user']) ? $_POST['db_user'] : '',isset($_POST['db_password']) ? $_POST['db_password'] : ''))), 200);
        if ($action === 'step') { $jid=isset($_POST['job_id'])?(string)$_POST['job_id']:''; jsonResponse(array('ok' => true, 'job' => strpos($jid,'mig-')===0?$migration->step($jid):$manager->step($jid)), 200); }
        if ($action === 'cancel') { $jid=isset($_POST['job_id'])?(string)$_POST['job_id']:''; jsonResponse(array('ok' => true, 'job' => strpos($jid,'mig-')===0?$migration->cancel($jid):$manager->cancel($jid)), 200); }
        if ($action === 'resume') { $jid=isset($_POST['job_id'])?(string)$_POST['job_id']:''; jsonResponse(array('ok' => true, 'job' => strpos($jid,'mig-')===0?$migration->resume($jid):$manager->resume($jid)), 200); }
        if ($action === 'upload_patch') jsonResponse(array('ok' => true, 'report' => $manager->uploadPatch($_FILES['patch'])), 200);
        if ($action === 'start_install') jsonResponse(array('ok' => true, 'job' => $manager->publicJob($manager->startInstall($_POST['upload_id'], !empty($_POST['force'])))), 200);
        if ($action === 'start_rollback') jsonResponse(array('ok' => true, 'job' => $manager->publicJob($manager->startRollback($_POST['patch_id']))), 200);
        if ($action === 'set_site_version') jsonResponse(array('ok' => true, 'site_version' => $manager->setSiteVersion(isset($_POST['version']) ? $_POST['version'] : '')), 200);
        if ($action === 'self_update_status') jsonResponse(array('ok' => true, 'data' => $selfUpdater->status(true)), 200);
        if ($action === 'check_self_update') jsonResponse(array('ok' => true, 'update' => $selfUpdater->checkRemote()), 200);
        if ($action === 'apply_self_update') jsonResponse(array('ok' => true, 'update' => $selfUpdater->applyRemote()), 200);
        if ($action === 'stage_manual_self_update') jsonResponse(array('ok' => true, 'package' => $selfUpdater->stageManualUpload(isset($_FILES['package']) ? $_FILES['package'] : array())), 200);
        if ($action === 'apply_manual_self_update') jsonResponse(array('ok' => true, 'update' => $selfUpdater->applyManualUpload(isset($_POST['stage_id']) ? $_POST['stage_id'] : '')), 200);
        if ($action === 'save_update_settings') jsonResponse(array('ok' => true, 'data' => $selfUpdater->saveClientSettings($_POST)), 200);
        if ($action === 'save_repository_settings') jsonResponse(array('ok' => true, 'data' => $selfUpdater->saveRepositorySettings($_POST)), 200);
        if ($action === 'save_smtp_settings') jsonResponse(array('ok' => true, 'smtp' => $selfUpdater->saveSmtpSettings($_POST)), 200);
        if ($action === 'test_smtp_connection') jsonResponse(array('ok' => true, 'smtp_test' => $selfUpdater->testSmtpConnection()), 200);
        if ($action === 'send_smtp_test') jsonResponse(array('ok' => true, 'smtp_test' => $selfUpdater->sendSmtpTest(isset($_POST['email']) ? $_POST['email'] : '')), 200);
        if ($action === 'remote_reset_password') jsonResponse(array('ok' => true, 'reset' => $selfUpdater->remoteResetClientPassword(isset($_POST['domain']) ? $_POST['domain'] : '')), 200);
        if ($action === 'remote_set_credentials') {
            $remotePassword = isset($_POST['password']) ? (string)$_POST['password'] : '';
            if ($remotePassword !== (isset($_POST['password_confirm']) ? (string)$_POST['password_confirm'] : '')) jsonResponse(array('ok' => false, 'error' => 'Пароли не совпадают.'), 400);
            jsonResponse(array('ok' => true, 'credentials' => $selfUpdater->remoteSetClientCredentials(isset($_POST['domain']) ? $_POST['domain'] : '', isset($_POST['email']) ? $_POST['email'] : '', $remotePassword)), 200);
        }
        if ($action === 'download_self_source') {
            @set_time_limit(0);$backup=$selfUpdater->createSafeSelfSourceArchive();$file=$backup['path'];$name=$backup['name'];
            register_shutdown_function(function() use($file){if(is_file($file))@unlink($file);});
            if(function_exists('session_write_close'))@session_write_close();
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');
            header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.basename($name).'"');header('Content-Length: '.filesize($file));
            $fh=@fopen($file,'rb');if(!$fh){@unlink($file);throw new Exception('Невозможно открыть архив исходников.');}
            while(!feof($fh)){echo fread($fh,1048576);if(function_exists('flush'))@flush();}fclose($fh);@unlink($file);exit;
        }
        if ($action === 'download_self_backup') {
            if(!isset($_POST['confirm_private_backup'])||(string)$_POST['confirm_private_backup']!=='1')throw new Exception('Для приватного полного бэкапа необходимо явно подтвердить включение секретов.');
            @set_time_limit(0);
            $backup=$selfUpdater->createFullSelfBackup();
            $file=$backup['path'];$name=$backup['name'];
            register_shutdown_function(function() use($file){if(is_file($file))@unlink($file);});
            if(function_exists('session_write_close'))@session_write_close();
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="'.basename($name).'"');
            header('Content-Length: '.filesize($file));
            header('X-UME-Self-Backup-Files: '.(int)$backup['files_count']);
            $fh=@fopen($file,'rb');if(!$fh){@unlink($file);throw new Exception('Не удалось открыть созданный ZIP для скачивания.');}
            while(!feof($fh)){echo fread($fh,1048576);if(function_exists('flush'))@flush();}
            fclose($fh);@unlink($file);exit;
        }
        if ($action === 'download_network_installer') {
            $source=$selfUpdater->networkInstallerSource();
            header('Content-Type: application/x-httpd-php; charset=utf-8');
            // 3.6.7: the installer is always downloaded as upgrade.php (central repository only; enforced in networkInstallerSource()).
            header('Content-Disposition: attachment; filename="upgrade.php"');
            header('Content-Length: '.strlen($source));
            echo $source;exit;
        }
        if ($action === 'save_settings') jsonResponse(array('ok' => true, 'settings' => $manager->saveSettings($_POST)), 200);
        if ($action === 'save_database_connection') jsonResponse(array('ok' => true, 'database_connection' => $manager->saveDatabaseConnectionSettings($_POST)), 200);
        if ($action === 'test_database_connection') jsonResponse(array('ok' => true, 'database_test' => $manager->testDatabaseConnection($_POST)), 200);
        if ($action === 'save_cron_settings') jsonResponse(array('ok' => true, 'cron' => $cronScheduler->save($_POST)), 200);
        if ($action === 'reinstall_cron') jsonResponse(array('ok' => true, 'cron' => $cronScheduler->reinstall()), 200);
        if ($action === 'save_backup_exclusions') jsonResponse(array('ok' => true, 'settings' => $manager->saveBackupExclusions($_POST)), 200);
        if ($action === 'save_api_settings') jsonResponse(array('ok' => true, 'api' => $oauth->saveSettings($_POST)), 200);
        if ($action === 'approve_api_task') jsonResponse(array('ok' => true, 'task' => $apiTasks->approvePatch(isset($_POST['task_id']) ? (string)$_POST['task_id'] : '')), 200);
        if ($action === 'approve_api_rollback') jsonResponse(array('ok' => true, 'task' => $apiTasks->approveRollback(isset($_POST['task_id']) ? (string)$_POST['task_id'] : '',isset($_POST['patch_id']) ? (string)$_POST['patch_id'] : '')), 200);
        if ($action === 'revoke_api_tokens') {
            $oauth->revokeAll();
            jsonResponse(array('ok' => true, 'message' => 'Все подключения ChatGPT отключены.'), 200);
        }
        if ($action === 'change_admin_email') {
            $result=$auth->changeAdminEmail(
                isset($_POST['current_password']) ? $_POST['current_password'] : '',
                isset($_POST['admin_email']) ? $_POST['admin_email'] : ''
            );
            jsonResponse(array('ok' => true, 'auth' => $result, 'csrf' => $auth->csrf()), 200);
        }
        if ($action === 'change_password') {
            $auth->changePassword(
                isset($_POST['current_password']) ? $_POST['current_password'] : '',
                isset($_POST['new_password']) ? $_POST['new_password'] : ''
            );
            jsonResponse(array('ok' => true, 'csrf' => $auth->csrf()), 200);
        }
        if ($action === 'bulk_delete') {
            $ids = isset($_POST['ids']) ? json_decode($_POST['ids'], true) : array();
            jsonResponse(array('ok' => true, 'deleted' => $manager->bulkDelete($_POST['type'], $ids)), 200);
        }
        if ($action === 'download') {
            $file = $manager->download($_GET['type'], $_GET['id']);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($file).'"');
            header('Content-Length: '.filesize($file));
            readfile($file);
            exit;
        }
        if ($action === 'profile') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="upgrade-site-profile.json"');
            echo json_encode($manager->profile(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;
        }
        jsonResponse(array('ok' => false, 'error' => 'Неизвестное действие.'), 404);
    } catch (Exception $e) {
        jsonResponse(array('ok' => false, 'error' => $e->getMessage()), 500);
    } catch (Throwable $e) {
        jsonResponse(array('ok' => false, 'error' => $e->getMessage()), 500);
    }
}

$logged = $auth->loggedIn();
$csrf = $auth->csrf();
$view = isset($_GET['view']) && is_string($_GET['view']) ? preg_replace('/[^a-z\-]/', '', strtolower($_GET['view'])) : 'overview';
$views = array(
    'overview' => array('title' => 'Обновления', 'subtitle' => 'Установка патчей и резервные копии'),
    'processes' => array('title' => 'Процессы', 'subtitle' => 'История операций и состояние фоновых задач'),
    'logs' => array('title' => 'Журналы', 'subtitle' => 'Технические журналы операций Upgrade Manager'),
    'api-tasks' => array('title' => 'API-задачи', 'subtitle' => 'Технические задания, ход выполнения, проверки и результат'),
    'api-connection' => array('title' => 'API-подключение', 'subtitle' => 'Защищённая прямая работа ChatGPT с этим сайтом'),
    'settings' => array('title' => 'Настройки', 'subtitle' => 'Резервное копирование и хранение'),
    'self-update' => array('title' => 'Обновление самого Upgrade Manager', 'subtitle' => 'Проверка и установка версий Upgrade Manager'),
    'security' => array('title' => 'Безопасность', 'subtitle' => 'Email, пароль администратора и Recovery-доступ'),
    'diagnostics' => array('title' => 'Диагностика', 'subtitle' => 'Совместимость сервера и состояние окружения'),
    'patch-format' => array('title' => 'Формат патча', 'subtitle' => 'Требования к подписанным пакетам обновлений')
);
if (!isset($views[$view])) $view = 'overview';
$viewMeta = $views[$view];
$passwordChangeRequired = $logged && method_exists($auth, 'requiresPasswordChange') ? $auth->requiresPasswordChange() : false;
$emailBindingRequired = $logged && method_exists($auth, 'requiresEmailBinding') ? $auth->requiresEmailBinding() : false;
$adminEmail = $logged && method_exists($auth, 'adminEmail') ? $auth->adminEmail() : '';
$legacyLoginMode = !$logged && method_exists($auth, 'requiresEmailBinding') ? $auth->requiresEmailBinding() : false;
if (($passwordChangeRequired || $emailBindingRequired) && $view !== 'security') { header('Location: ./?view=security'); exit; }
$uiSettings = $logged ? $manager->settings() : array();
$uiTheme = isset($uiSettings['ui_theme']) && $uiSettings['ui_theme'] === 'dark' ? 'dark' : 'light';
$selfUpdateUiStatus = $logged ? $selfUpdater->status(false) : array();
$isCentralRepository = !empty($selfUpdateUiStatus['repository']['enabled']);
?><!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo umeEscape($viewMeta['title']); ?> · Upgrade Manager</title>
    <link rel="icon" href="./favicon.ico" sizes="any">
    <link rel="stylesheet" href="./assets/app.css?v=<?php echo (int)@filemtime(__DIR__.'/assets/app.css'); ?>">
</head>
<body class="<?php echo $logged ? 'app-body'.($uiTheme === 'dark' ? ' ume-theme-dark' : '') : 'login-body'; ?>">
<?php if (!$logged): ?>
    <div class="login-shell">
        <form class="login-card" id="login-form" method="post" action="./?action=login">
            <div class="login-brand"><span>U</span></div>
            <div class="eyebrow">Защищённый доступ</div>
            <h1>Upgrade Manager</h1>
            <p>Универсальный менеджер резервных копий, патчей и безопасного отката.</p>
            <label for="login-username"><?php echo $legacyLoginMode ? 'Email / прежний логин' : 'Email администратора'; ?></label>
            <input id="login-username" type="text" name="username" value="" autocomplete="username" autocapitalize="none" spellcheck="false" autofocus required>
            <?php if ($legacyLoginMode): ?><small>После обновления со старой версии сначала войдите прежним логином (обычно <code>admin</code>), затем привяжите email в «Безопасности».</small><?php endif; ?>
            <label for="login-password">Пароль администратора</label>
            <input id="login-password" type="password" name="password" autocomplete="current-password" required>
            <button class="button primary full" type="submit">Войти в систему</button>
            <a class="login-secondary-link" href="./reset-password.php">Забыли пароль?</a>
            <div class="error" id="login-error" role="alert"><?php echo umeEscape($loginError); ?></div>
            <noscript><small>JavaScript отключён — вход работает обычной отправкой формы.</small></noscript>
        </form>
    </div>
<?php else: ?>
    <div class="admin-shell">
        <aside class="sidebar" id="sidebar" aria-label="Основная навигация">
            <div class="sidebar-brand">
                <div class="brand-mark">U</div>
                <div><strong>Upgrade Manager</strong><span>Professional</span></div>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-group">
                    <div class="nav-caption">Управление</div>
                    <a class="<?php echo umeNavClass($view, 'overview'); ?>" href="./?view=overview">
                        <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14"/></svg></span>
                        <span>Обновления</span>
                    </a>
                    <a class="<?php echo umeNavClass($view, 'processes'); ?>" href="./?view=processes">
                        <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v4l3 2M4.9 4.9l2 2m10.2-2-2 2M12 3v2M5 12H3m18 0h-2M6.3 17.7A8 8 0 1 1 17.7 17.7"/></svg></span>
                        <span>Процессы</span>
                    </a>
                    <a class="<?php echo umeNavClass($view, 'logs'); ?>" href="./?view=logs">
                        <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l3 3v15H6zM9 10h6m-6 4h6m-6 4h4"/></svg></span>
                        <span>Журналы</span>
                    </a>
                </div>

                <div class="nav-group">
                    <div class="nav-caption">Прямая работа</div>
                    <a class="<?php echo umeNavClass($view, 'api-tasks'); ?>" href="./?view=api-tasks">
                        <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h12v16H6zM9 8h6m-6 4h6m-6 4h4"/></svg></span>
                        <span>API-задачи</span>
                    </a>
                    <a class="<?php echo umeNavClass($view, 'api-connection'); ?>" href="./?view=api-connection">
                        <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 15.5 6 18a3 3 0 0 1-4-4l4-4a3 3 0 0 1 4 0m5.5-1.5L18 6a3 3 0 0 1 4 4l-4 4a3 3 0 0 1-4 0M8 12h8"/></svg></span>
                        <span>API-подключение</span>
                    </a>
                </div>

                <div class="nav-group">
                    <div class="nav-caption">Конфигурация</div>
                    <a class="<?php echo umeNavClass($view, 'settings'); ?>" href="./?view=settings">
                        <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM19 12l2-1-2-4-2 .5-1.5-1.5.5-2H11l-.5 2L9 7.5 7 7 5 11l2 1c0 .7.2 1.4.5 2L6 15.5 9 19l1.5-1.5c.5.2 1 .4 1.5.4l1 2.1h4l.5-2c.6-.3 1.1-.6 1.5-1l2 .5 2-3.5-2-1c0-.7-.2-1.4-.5-2Z"/></svg></span>
                        <span>Настройки</span>
                    </a>
                    <a class="<?php echo umeNavClass($view, 'security'); ?>" href="./?view=security">
                        <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.6 2.8 8.2 7 10 4.2-1.8 7-5.4 7-10V6l-7-3Zm-2 9 1.5 1.5L15 10"/></svg></span>
                        <span>Безопасность</span>
                        <?php if ($passwordChangeRequired): ?><span class="nav-dot" title="Требуется смена пароля"></span><?php endif; ?>
                    </a>
                    <a class="<?php echo umeNavClass($view, 'diagnostics'); ?>" href="./?view=diagnostics">
                        <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5m0 14h16M8 15l3-4 3 2 5-7"/></svg></span>
                        <span>Диагностика</span>
                    </a>
                    <a class="<?php echo umeNavClass($view, 'patch-format'); ?>" href="./?view=patch-format">
                        <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4.5-8 4.5-8-4.5L12 3Zm-8 9 8 4.5 8-4.5M4 16.5l8 4.5 8-4.5"/></svg></span>
                        <span>Формат патча</span>
                    </a>
                    <label class="sidebar-theme-control" for="ui-theme-toggle" title="Переключить тему рабочей зоны">
                        <span class="nav-icon sidebar-theme-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v2m0 14v2M5.6 5.6 7 7m10 10 1.4 1.4M3 12h2m14 0h2M5.6 18.4 7 17m10-10 1.4-1.4M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/></svg></span>
                        <span class="sidebar-theme-label" id="ui-theme-label">Тема: <?php echo $uiTheme === 'dark' ? 'Ночь' : 'День'; ?></span>
                        <input class="sidebar-theme-input" id="ui-theme-toggle" type="checkbox"<?php echo $uiTheme === 'dark' ? ' checked' : ''; ?> aria-label="Переключить дневную и ночную тему">
                        <span class="sidebar-theme-switch" aria-hidden="true"></span>
                    </label>
                </div>
            </nav>

            <div class="sidebar-footer">
                <a class="server-state server-state-link<?php echo $view === 'self-update' ? ' active' : ''; ?>" href="./?view=self-update" title="Обновление самого Upgrade Manager"><span class="state-dot"></span><div><strong>Обновление самого Upgrade Manager</strong><small>Версия <?php echo umeEscape($config['version']); ?></small></div><span class="server-state-arrow" aria-hidden="true">→</span></a>
                <a class="footer-link" href="./recovery.php">Открыть Recovery</a>
                <a class="footer-link logout-link" href="./?action=logout">Выйти</a>
            </div>
        </aside>
        <button class="sidebar-backdrop" id="sidebar-backdrop" type="button" aria-label="Закрыть меню"></button>

        <div class="workspace<?php echo $uiTheme === 'dark' ? ' theme-dark' : ''; ?>">
            <main class="content">
                <div class="page-heading">
                    <button class="menu-toggle" id="menu-toggle" type="button" aria-label="Открыть меню" aria-controls="sidebar" aria-expanded="false">
                        <span></span><span></span><span></span>
                    </button>
                    <div><h2><?php echo umeEscape($viewMeta['title']); ?></h2><p><?php echo umeEscape($viewMeta['subtitle']); ?></p></div>
                    <?php if ($view === 'overview'): ?>
                        <div class="site-version" id="site-version" aria-live="polite">
                            <div class="site-version-view" id="site-version-view">
                                <span class="site-version-label">Версия сайта</span>
                                <div class="site-version-row"><strong class="site-version-value" id="site-version-value">—</strong><button class="site-version-edit" id="site-version-edit" type="button" title="Задать версию вручную" aria-label="Задать версию сайта вручную"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/></svg></button></div>
                                <small class="site-version-meta" id="site-version-meta">Следующий патч = текущая версия + 0.0.1</small>
                            </div>
                            <form class="site-version-form hidden" id="site-version-form" autocomplete="off">
                                <label class="site-version-label" for="site-version-input">Версия сайта</label>
                                <div class="site-version-row"><input id="site-version-input" name="version" inputmode="decimal" pattern="v?[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}" placeholder="1.0.0" maxlength="21" required><button class="button primary small" type="submit">Сохранить</button><button class="button secondary small" id="site-version-cancel" type="button">Отмена</button></div>
                                <small class="site-version-meta">Формат X.Y.Z. Y и Z — 0–9: 1.0.9 → 1.1.0, 1.9.9 → 2.0.0.</small>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
                <div id="notice" aria-live="polite"></div>
                <?php if ($passwordChangeRequired && $view !== 'security'): ?>
                    <div class="notice warning persistent">Для временного пароля требуется замена. <a href="./?view=security">Открыть раздел безопасности</a>.</div>
                <?php endif; ?>

                <?php if ($view === 'overview'): ?>
                    <section class="card upload-card">
                        <div class="card-heading">
                            <div class="card-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 20h14"/></svg></div>
                            <div><h3>Установить ZIP-патч</h3><p>Patch 1.2 можно загрузить уже подписанным или без payload_hash/signature — менеджер проверит base_site_version/version и безопасно подпишет пакет локально.</p></div>
                        </div>
                        <div class="dropzone" id="dropzone">
                            <input type="file" id="patch-file" accept=".zip,application/zip" aria-label="Выбрать ZIP-патч">
                            <div class="drop-illustration"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M13 38h22a8 8 0 0 0 1-15.9A13 13 0 0 0 11.3 18 10 10 0 0 0 13 38Z"/><path d="M24 32V18m0 0-5 5m5-5 5 5"/></svg></div>
                            <strong>Перетащите ZIP-патч в эту область</strong>
                            <span>или нажмите, чтобы выбрать файл на компьютере</span>
                            <small id="patch-name">Поддерживается безопасный формат ZIP</small>
                        </div>
                        <button class="button primary full" id="validate-btn" type="button" disabled>Проверить выбранный патч</button>
                        <div id="patch-report"></div>
                    </section>

                    <section class="card">
                        <div class="table-title">
                            <div><div class="title-row"><h3>Резервные копии</h3><span class="mode-badge" id="backup-mode-badge">Файлы: код + CMS · БД: минимум</span></div><p>Файлы и база данных настраиваются независимо. Любую копию можно восстановить кнопкой «Откатить».</p></div>
                            <div class="table-actions"><button class="button ghost danger-text small bulk-delete" data-type="backup" type="button">Удалить выбранные</button><button class="button primary" id="backup-btn" type="button">Создать бэкап</button></div>
                        </div>
                        <div class="table-wrap"><table><thead><tr><th class="check-cell"><input type="checkbox" class="select-all" data-table="backups" aria-label="Выбрать все бэкапы"></th><th>Файл</th><th>Состав</th><th>Размер</th><th>Создан</th><th class="actions-cell">Действия</th></tr></thead><tbody id="backups-body"><tr><td colspan="6" class="table-loading">Загрузка списка…</td></tr></tbody></table></div>
                    </section>

                    <section class="card">
                        <div class="table-title">
                            <div><h3>Загруженные патчи</h3><p>История установок и доступные пакеты автоматического отката.</p></div>
                            <button class="button ghost danger-text small bulk-delete" data-type="history" type="button">Удалить выбранные</button>
                        </div>
                        <div class="table-wrap"><table><thead><tr><th class="check-cell"><input type="checkbox" class="select-all" data-table="history" aria-label="Выбрать все патчи"></th><th>Патч</th><th>Версия</th><th>Статус</th><th>Установлен</th><th class="actions-cell">Действия</th></tr></thead><tbody id="history-body"><tr><td colspan="6" class="table-loading">Загрузка списка…</td></tr></tbody></table></div>
                    </section>

                <?php elseif ($view === 'processes'): ?>
                    <section class="card">
                        <div class="table-title"><div><h3>История процессов</h3><p>Резервные копии, установки патчей и операции отката.</p></div><button class="button ghost danger-text small bulk-delete" data-type="job" type="button">Удалить выбранные</button></div>
                        <div class="table-wrap"><table><thead><tr><th class="check-cell"><input type="checkbox" class="select-all" data-table="jobs" aria-label="Выбрать все процессы"></th><th>Процесс</th><th>Статус</th><th>Этап</th><th>Создан</th><th>Действия</th></tr></thead><tbody id="jobs-body"><tr><td colspan="6" class="table-loading">Загрузка списка…</td></tr></tbody></table></div>
                    </section>

                <?php elseif ($view === 'logs'): ?>
                    <section class="card">
                        <div class="table-title"><div><h3>Технические журналы</h3><p>Скачивайте журнал для диагностики конкретной операции.</p></div><button class="button ghost danger-text small bulk-delete" data-type="log" type="button">Удалить выбранные</button></div>
                        <div class="table-wrap"><table><thead><tr><th class="check-cell"><input type="checkbox" class="select-all" data-table="logs" aria-label="Выбрать все журналы"></th><th>Файл</th><th>Размер</th><th>Изменён</th><th class="actions-cell">Действия</th></tr></thead><tbody id="logs-body"><tr><td colspan="5" class="table-loading">Загрузка списка…</td></tr></tbody></table></div>
                    </section>

                <?php elseif ($view === 'api-tasks'): ?>
                    <section class="card api-intro-card">
                        <div class="card-heading"><div class="card-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h12v16H6zM9 8h6m-6 4h6m-6 4h4"/></svg></div><div><h3>История прямых заданий</h3><p>Здесь сохраняются исходное ТЗ, фактические действия, процессы бэкапа и установки, проверки и окончательный результат.</p></div></div>
                        <div class="task-filter-row"><span class="mode-badge" id="api-task-count">Задач: 0</span><a class="button secondary small" href="./?view=api-connection">Настроить подключение</a></div>
                    </section>
                    <div class="api-task-list" id="api-task-list"><section class="card"><div class="table-loading">Загрузка API-задач…</div></section></div>

                <?php elseif ($view === 'api-connection'): ?>
                    <div class="api-page-grid">
                        <div class="api-main-column">
                            <section class="card form-card">
                                <div class="card-heading"><div class="card-icon green"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 15.5 6 18a3 3 0 0 1-4-4l4-4a3 3 0 0 1 4 0m5.5-1.5L18 6a3 3 0 0 1 4 4l-4 4a3 3 0 0 1-4 0M8 12h8"/></svg></div><div><h3>Подключение ChatGPT Work</h3><p>Укажите этот адрес при создании собственного MCP-приложения. Секретный ключ вводить или отправлять в чат не нужно.</p></div></div>
                                <div class="connection-status" id="api-connection-status"><span class="state-dot"></span><div><strong>Проверяем API…</strong><small>Состояние соединения загружается</small></div></div>
                                <div class="field"><label for="mcp-url">MCP server URL</label><div class="copy-field"><input id="mcp-url" type="text" readonly value=""><button class="button secondary" id="copy-mcp-url" type="button">Копировать</button></div><small>Подключение использует OAuth 2.1 с PKCE. Доступ подтверждается паролем Upgrade Manager на этом сайте.</small></div>
                                <div class="connection-metrics" id="api-connection-metrics"></div><div class="connection-diagnostics" id="api-connection-diagnostics"></div>
                            </section>

                            <section class="card form-card">
                                <div class="card-heading"><div class="card-icon violet"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM19 12l2-1-2-4-2 .5-1.5-1.5.5-2H11l-.5 2L9 7.5 7 7 5 11l2 1c0 .7.2 1.4.5 2L6 15.5 9 19l1.5-1.5c.5.2 1 .4 1.5.4l1 2.1h4l.5-2c.6-.3 1.1-.6 1.5-1l2 .5 2-3.5-2-1c0-.7-.2-1.4-.5-2Z"/></svg></div><div><h3>Правила API-обновлений</h3><p>Ручная загрузка патчей и создание бэкапов продолжают работать независимо.</p></div></div>
                                <form id="api-settings-form">
                                    <label class="switch-row"><input type="checkbox" name="enabled" value="1"><span><strong>Разрешить прямое API-подключение</strong><small>При отключении все MCP-вызовы блокируются.</small></span></label>
                                    <div class="form-row api-policy-row">
                                        <div class="field"><label for="patch-upload-mode">Передача патча из ChatGPT</label><select id="patch-upload-mode" name="patch_upload_mode"><option value="confirm">После подтверждения</option><option value="auto">Автоматически</option></select><small>Определяет, должен ли ChatGPT сначала спросить разрешение перед передачей подготовленных файлов в Upgrade Manager.</small></div>
                                        <div class="field"><label for="patch-apply-mode">Применение патча</label><select id="patch-apply-mode" name="patch_apply_mode"><option value="confirm">После подтверждения</option><option value="auto">Автоматически после загрузки</option></select><small>В автоматическом режиме после серверной проверки запускаются бэкап и установка. ChatGPT может дополнительно показать собственное подтверждение действия.</small></div>
                                    </div>
                                    <label class="switch-row"><input type="checkbox" name="require_backup_before_install" value="1"><span><strong>Требовать бэкап перед установкой</strong><small>Перед прямой установкой Upgrade Manager сначала завершит резервную копию этой API-задачи.</small></span></label>
                                    <label class="switch-row locked"><input type="checkbox" name="auto_rollback" value="1" checked disabled><span><strong>Автоматический откат при ошибке</strong><small>Обязательная защита прямых изменений; отключить её нельзя.</small></span></label>
                                    <div class="api-policy-note">Новый MCP-поток не требует ZIP на вашем компьютере: ChatGPT передаёт изменённые файлы, Upgrade Manager сам собирает, подписывает и проверяет штатный ZIP-патч на сервере.</div>
                                    <div class="form-actions"><button class="button primary" type="submit">Сохранить API-настройки</button></div>
                                </form>
                            </section>
                        </div>

                        <div class="api-side-column">
                            <aside class="info-panel connection-guide"><div class="info-icon">1</div><h3>Как подключить</h3><ol><li>В ChatGPT откройте «Плагины» и создайте собственный плагин из удалённого MCP-сервера.</li><li>Вставьте MCP server URL слева, выберите OAuth и завершите вход в Upgrade Manager.</li><li>После установки новой версии Upgrade Manager нажмите в настройках созданного плагина <strong>Refresh / Обновить действия</strong>, чтобы ChatGPT заново выполнил <code>tools/list</code>. Если Refresh недоступен — пересоздайте этот персональный плагин один раз.</li><li>Откройте <strong>новый ChatGPT Work-чат</strong>, в поле сообщения введите <strong>@</strong> и выберите «Upgrade Example Site».</li><li>Для проверки попросите: «Создай резервную копию этого сайта» или «Покажи последние резервные копии».</li></ol><p>Сервер публикует высокоуровневые действия <code>create_backup</code>, <code>list_backups</code>, <code>get_backup_download_url</code> и полный поток прямой передачи/установки патча. Выбор плагина через @ действует на сообщение, в котором нужны MCP-действия.</p></aside>
                            <aside class="info-panel security-panel"><div class="info-icon shield">✓</div><h3>Управление доступом</h3><p>Токены хранятся на сервере только в виде SHA-256. Конфиги, пароли и HMAC-ключ недоступны через MCP.</p><button class="button danger full" id="revoke-api-tokens" type="button">Отключить все подключения</button></aside>
                        </div>
                    </div>

                <?php elseif ($view === 'settings'): ?>
                    <div class="settings-grid">
                        <div class="settings-main-stack">
                        <section class="card form-card">
                            <div class="card-heading"><div class="card-icon violet"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM19 12l2-1-2-4-2 .5-1.5-1.5.5-2H11l-.5 2L9 7.5 7 7 5 11l2 1c0 .7.2 1.4.5 2L6 15.5 9 19l1.5-1.5c.5.2 1 .4 1.5.4l1 2.1h4l.5-2c.6-.3 1.1-.6 1.5-1l2 .5 2-3.5-2-1c0-.7-.2-1.4-.5-2Z"/></svg></div><div><h3>Резервное копирование</h3><p>Настройте состав и срок хранения создаваемых архивов.</p></div></div>
                            <form id="settings-form">
                                <div class="form-row backup-mode-row">
                                    <div class="field"><label for="file-mode">Настройки файлов</label><select id="file-mode" name="file_mode"><option value="all">1. Все файлы</option><option value="code_cms">2. Только код + Ядро CMS</option><option value="necessary">3. Только нужные без CMS</option><option value="none">4. Без файлов</option></select><small>Режим «Все файлы» включает медиа и другие обычные файлы. Кэш, служебные backup-каталоги, Upgrade Manager и защищённые конфиги по-прежнему не архивируются.</small></div>
                                    <div class="field"><label for="database-mode">Настройки БД</label><select id="database-mode" name="database_mode"><option value="full">1. Полный дамп</option><option value="minimal">2. Минимум данных</option><option value="schema">3. Только структура без данных</option><option value="none">4. Без БД</option></select><small>«Минимум данных» сохраняет структуру и системные данные, но не выгружает данные чувствительных таблиц. Эти таблицы при восстановлении не очищаются.</small></div>
                                </div>
                                <div class="backup-combination-note">Файловый режим и режим БД комбинируются независимо. Нельзя создать только комбинацию «Без файлов» + «Без БД».</div>
                                <div class="form-row"><div class="field"><label for="backup-retention">Количество хранимых копий</label><input id="backup-retention" type="number" name="backup_retention" min="1" max="50"></div><div class="field"><label for="log-retention">Хранение журналов, дней</label><input id="log-retention" type="number" name="log_retention_days" min="1" max="365"></div></div>
                                <div class="form-actions"><button class="button primary" type="submit">Сохранить настройки</button></div>
                            </form>
                        </section>
                        <section class="card form-card" id="database-connection-card">
                            <div class="card-heading"><div class="card-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="5" rx="7" ry="3"/><path d="M5 5v7c0 1.7 3.1 3 7 3s7-1.3 7-3V5M5 12v7c0 1.7 3.1 3 7 3s7-1.3 7-3v-7"/></svg></div><div><h3>Подключение к базе данных</h3><p>Выберите источник реквизитов БД: автоопределение CMS, конкретный файл конфигурации или ручной ввод.</p></div></div>
                            <form id="database-connection-form">
                                <div class="db-source-options" role="radiogroup" aria-label="Источник подключения к базе данных">
                                    <label class="db-source-option"><input type="radio" name="database_connection_mode" value="auto" checked><span><strong>Автоопределение</strong><small>Upgrade Manager сам ищет реквизиты в конфигурации CMS.</small></span></label>
                                    <label class="db-source-option"><input type="radio" name="database_connection_mode" value="file"><span><strong>Файл конфигурации</strong><small>Укажите путь к конкретному PHP/.env-файлу с реквизитами БД.</small></span></label>
                                    <label class="db-source-option"><input type="radio" name="database_connection_mode" value="manual"><span><strong>Реквизиты вручную</strong><small>Введите host, порт, базу, пользователя и пароль вручную.</small></span></label>
                                </div>
                                <div id="file-db-fields" class="db-mode-panel hidden">
                                    <div class="field"><label for="database-config-path">Путь к файлу конфигурации БД</label><input id="database-config-path" name="database_config_path" type="text" autocomplete="off" placeholder="Например: .env, config/database.php, ../wp-config.php"><small>Можно указать путь относительно корня сайта или абсолютный путь на сервере. Файл только читается — его содержимое не возвращается в браузер.</small></div>
                                </div>
                                <div id="manual-db-fields" class="db-mode-panel hidden">
                                    <div class="form-row"><div class="field"><label for="manual-db-host">Хост БД</label><input id="manual-db-host" name="manual_db_host" type="text" value="localhost" autocomplete="off"></div><div class="field small-field"><label for="manual-db-port">Порт</label><input id="manual-db-port" name="manual_db_port" type="number" min="1" max="65535" value="3306"></div></div>
                                    <div class="form-row"><div class="field"><label for="manual-db-name">Имя базы данных</label><input id="manual-db-name" name="manual_db_name" type="text" autocomplete="off"></div><div class="field"><label for="manual-db-user">Пользователь</label><input id="manual-db-user" name="manual_db_user" type="text" autocomplete="username"></div></div>
                                    <div class="form-row"><div class="field"><label for="manual-db-password">Пароль</label><input id="manual-db-password" name="manual_db_password" type="password" autocomplete="new-password"><small id="manual-db-password-note">Пароль сохраняется отдельно в зашифрованном runtime-хранилище и не возвращается в браузер.</small><label class="inline-check"><input type="checkbox" name="manual_db_clear_password" value="1"><span>Очистить сохранённый пароль (использовать пустой)</span></label></div><div class="field"><label for="manual-db-prefix">Префикс таблиц</label><input id="manual-db-prefix" name="manual_db_prefix" type="text" placeholder="wp_" autocomplete="off"><small>Необязательно. Нужен для SQL-патчей с {{DB_PREFIX}} и некоторых CMS.</small></div></div>
                                </div>
                                <div class="cron-status-box" id="database-connection-status"><span class="status-badge neutral">Проверяем источник подключения…</span></div>
                                <div class="form-actions"><button class="button primary" type="submit">Сохранить подключение</button><button class="button secondary" id="test-database-connection" type="button">Проверить подключение</button></div>
                            </form>
                        </section>
                        <section class="card form-card auto-backup-card" id="auto-backup-card">
                            <div class="card-heading"><div class="card-icon cyan"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6v6l4 2M4.9 4.9A10 10 0 1 0 12 2v3M2 2v5h5"/></svg></div><div><h3>Автоматическое резервное копирование</h3><p>Upgrade Manager сам создаёт системную cron-задачу и по расписанию запускает полный бэкап сайта.</p></div></div>
                            <form id="cron-settings-form">
                                <label class="switch-row auto-backup-switch"><input type="checkbox" name="auto_backup_enabled" value="1"><span><strong>Включить автоматические полные бэкапы</strong><small>Каждый автоматический запуск использует «Все файлы» + «Полный дамп БД». Исключения из настроек продолжают действовать.</small></span></label>
                                <div class="field"><label for="auto-backup-frequency">Как часто создавать полную копию</label><select id="auto-backup-frequency" name="auto_backup_frequency"><option value="hourly">Каждый час</option><option value="6h">Каждые 6 часов</option><option value="12h">Каждые 12 часов</option><option value="daily">Раз в сутки</option><option value="weekly">Раз в неделю</option></select><small>Системная cron-задача проверяет расписание каждые 5 минут. Тяжёлый backup не запускается параллельно с другим процессом Upgrade Manager.</small></div>
                                <div class="cron-status-box" id="cron-status-box"><span class="status-badge neutral">Проверяем системный cron…</span></div>
                                <div class="form-actions cron-actions"><button class="button primary" type="submit">Сохранить и настроить cron</button><button class="button secondary hidden" id="reinstall-cron" type="button">Переустановить cron</button></div>
                            </form>
                        </section>
                        <section class="card form-card<?php echo $isCentralRepository ? '' : ' hidden'; ?>" id="smtp-settings-card">
                            <div class="card-heading"><div class="card-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM4 7l8 6 8-6"/></svg></div><div><h3>SMTP для писем безопасности</h3><p>Recovery и восстановление пароля отправляются с центрального Upgrade Manager через настроенный здесь SMTP-сервер.</p></div></div>
                            <form id="smtp-settings-form">
                                <label class="switch-row"><input type="checkbox" name="smtp_enabled" value="1"><span><strong>Использовать SMTP</strong><small>Если выключено, остаётся старый PHP mail() как резервный режим.</small></span></label>
                                <div class="form-row"><div class="field"><label for="smtp-host">SMTP-сервер</label><input id="smtp-host" name="smtp_host" type="text" placeholder="smtp.example.com" autocomplete="off"></div><div class="field small-field"><label for="smtp-port">Порт</label><input id="smtp-port" name="smtp_port" type="number" min="1" max="65535" value="587"></div></div>
                                <div class="field"><label for="smtp-security">Шифрование</label><select id="smtp-security" name="smtp_security"><option value="tls">STARTTLS (обычно 587)</option><option value="ssl">SSL/TLS (обычно 465)</option><option value="none">Без шифрования</option></select></div>
                                <div class="form-row"><div class="field"><label for="smtp-username">SMTP-логин</label><input id="smtp-username" name="smtp_username" type="text" autocomplete="username"></div><div class="field"><label for="smtp-password">SMTP-пароль</label><input id="smtp-password" name="smtp_password" type="password" autocomplete="new-password"><small id="smtp-password-note">Оставьте пустым, если авторизация не требуется.</small></div></div>
                                <div class="form-row"><div class="field"><label for="smtp-from-email">Email отправителя</label><input id="smtp-from-email" name="smtp_from_email" type="email" placeholder="noreply@example.com"></div><div class="field"><label for="smtp-from-name">Имя отправителя</label><input id="smtp-from-name" name="smtp_from_name" type="text" placeholder="Upgrade Manager Security"></div></div>
                                <div class="field"><label for="smtp-test-email">Куда отправить тестовое письмо</label><input id="smtp-test-email" name="smtp_test_email" type="email" value="<?php echo umeEscape($adminEmail); ?>"></div>
                                <div class="cron-status-box smtp-status-box" id="smtp-status-box"><span class="status-badge neutral">SMTP ещё не проверен</span></div>
                                <div class="form-actions smtp-actions"><button class="button primary" type="submit">Сохранить SMTP</button><button class="button secondary" id="smtp-test-connection" type="button">Проверить подключение</button><button class="button secondary" id="smtp-send-test" type="button">Отправить тестовое письмо</button></div>
                            </form>
                        </section>
                        </div>
                        <div class="settings-side-stack">
                            <aside class="info-panel"><div class="info-icon">i</div><h3>Файлы и БД отдельно</h3><p>Доступно 4 файловых профиля × 4 режима БД. <strong>Только нужные без CMS</strong> исключает распознанное ядро CMS и оставляет проектный код. Для неизвестной CMS этот режим работает консервативно и не удаляет предполагаемое ядро.</p><a href="./?view=patch-format">Подробнее о форматах</a></aside>
                            <section class="card exclusions-card">
                                <div class="exclusions-heading"><div><h3>Исключения</h3><p>Эти правила применяются ко всем файловым режимам, кроме «Без файлов».</p></div><button class="exclusion-help" type="button" title="По одному правилу в строке: /папка/, /путь/файл.ext, имя.ext, *.jpg, *.pdf. Правила с # в начале считаются комментариями.">?</button></div>
                                <form id="backup-exclusions-form">
                                    <div class="exclusion-editor"><pre id="backup-exclusion-lines" aria-hidden="true">1</pre><textarea id="backup-exclusions" name="backup_exclusions" rows="10" spellcheck="false" placeholder="*.jpg
*.png
*.pdf
/storage/"></textarea></div>
                                    <small class="exclusion-note">Папка с завершающим / исключается целиком. Маски * и ? поддерживаются. Встроенные системные исключения продолжают действовать отдельно.</small>
                                    <div class="form-actions"><button class="button primary" type="submit">Сохранить исключения</button></div>
                                </form>
                            </section>
                            <section class="card migration-card" id="migration-card">
                                <div class="card-heading"><div class="card-icon green"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h10M14 4l3 3-3 3M17 17H7m3 3-3-3 3-3"/></svg></div><div><h3>Перенос проекта</h3><p>Перенесите весь сайт и полную БД напрямую между двумя Upgrade Manager без FTP и phpMyAdmin.</p></div></div>
                                <div class="migration-actions"><button class="button primary" id="open-migration-export" type="button">Перенести проект на другой сайт</button><button class="button secondary" id="open-migration-import" type="button">Импортировать проект сюда</button></div>
                                <div class="migration-share-status hidden" id="migration-share-status" aria-live="polite"></div>
                                <small class="migration-note">/upgrade/ и служебное хранилище Upgrade Manager не переносятся. Реквизиты новой БД вводятся только на новом сайте и не отправляются старому серверу.</small>
                            </section>
                        </div>
                    </div>



                <?php elseif ($view === 'self-update'): ?>
                    <section class="card self-update-card" id="self-update-card">
                        <div class="table-title">
                            <div>
                                <div class="title-row"><h3>Обновление самого Upgrade Manager</h3><span class="mode-badge" id="self-update-version">Версия <?php echo umeEscape($config['version']); ?></span></div>
                                <p id="self-update-description"><?php echo $isCentralRepository ? 'Основной сайт обновляется проверенным FTP ZIP. После успешной установки новая версия автоматически публикуется для подключённых сайтов.' : 'Клиентский сайт получает и устанавливает Upgrade Manager только с настроенного центрального репозитория.'; ?></p>
                            </div>
                            <div class="table-actions"><a class="button primary<?php echo $isCentralRepository ? '' : ' hidden'; ?>" id="download-installer" href="./?action=download_network_installer" download="upgrade.php" title="Файл upgrade.php устанавливает актуальную версию Upgrade Manager на любой сайт: загрузите его в корень сайта, откройте в браузере и нажмите «Установить».">Скачать инсталлятор</a><button class="button secondary<?php echo $isCentralRepository ? ' hidden' : ''; ?>" id="check-self-update" type="button">Проверить обновления</button><button class="button primary hidden" id="apply-self-update" type="button">Обновить</button></div>
                        </div>
                        <div class="update-result" id="self-update-result"><?php if ($isCentralRepository): ?><span class="status-badge success">Центральный репозиторий</span><span class="update-inline-note">Ручное самообновление разрешено только здесь</span><?php else: ?><span class="status-badge neutral">Источник обновлений проверяется…</span><?php endif; ?></div>
                    </section>

                    <div class="settings-grid update-settings-grid">
                        <div class="update-left-stack">
                            <section class="card form-card<?php echo $isCentralRepository ? ' hidden' : ''; ?>" id="update-source-card">
                                <div class="card-heading"><div class="card-icon cyan"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12a8 8 0 0 1 14-5m2-3v5h-5M20 12a8 8 0 0 1-14 5m-2 3v-5h5"/></svg></div><div><h3>Источник обновлений Upgrade Manager</h3><p>Клиентский сайт получает новые версии только с указанного центрального сервера по HTTPS.</p></div></div>
                                <form id="update-source-form">
                                    <div class="field"><label for="update-server-url">URL update-feed</label><input id="update-server-url" name="update_server_url" type="url" placeholder="https://central.example.com/upgrade/update-feed.php" autocomplete="off"><small>Например: https://central.example.com/upgrade/update-feed.php</small></div>
                                    <div class="field"><label for="update-repository-key">Ключ репозитория</label><input id="update-repository-key" name="update_repository_key" type="password" autocomplete="new-password" placeholder="Вставьте ключ центрального сайта"></div>
                                    <div class="form-actions"><button class="button primary" type="submit">Сохранить источник</button></div>
                                </form>
                            </section>
                            <section class="card manual-self-update-card<?php echo $isCentralRepository ? '' : ' hidden'; ?>" id="manual-self-update-card">
                                <div class="card-heading"><div class="card-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5h14v-5"/></svg></div><div><h3>Установить обновление вручную</h3><p>Перетащите сюда полный FTP ZIP Upgrade Manager. Локальная конфигурация, OAuth/MCP-токены и каталог .data сохраняются.</p></div></div>
                                <div class="dropzone self-update-dropzone" id="self-update-dropzone" tabindex="0" role="button" aria-label="Выбрать FTP ZIP Upgrade Manager">
                                    <input id="self-update-file" type="file" accept=".zip,application/zip">
                                    <div class="drop-illustration"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5h14v-5"/></svg></div>
                                    <strong>Перетащите FTP ZIP Upgrade Manager сюда</strong>
                                    <span>или нажмите, чтобы выбрать файл</span>
                                    <small id="self-update-package-name">Пакет будет загружен, полностью проверен и подготовлен к установке</small>
                                </div>
                                <div class="manual-self-update-report" id="manual-self-update-report"></div>
                            </section>
                            <section class="card form-card<?php echo $isCentralRepository ? '' : ' hidden'; ?>" id="self-backup-card">
                                <div class="card-heading"><div class="card-icon green"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v12H4zM7 4h10v3M8 11h8M8 15h5"/></svg></div><div><h3>Полный бэкап Upgrade Manager</h3><p>Скачать отдельную резервную копию самого /upgrade/ без базы данных сайта и без архивов резервных копий основного сайта.</p></div></div>
                                <div class="inline-alert self-backup-note"><strong>Для передачи разработчику:</strong> безопасный ZIP исходного кода содержит только файлы системного релиза, проверенные по SHA-256. Конфиги, ключи, OAuth-токены и внутреннее хранилище не включаются.</div>
                                <form method="post" action="./?action=download_self_source" class="form-actions self-backup-actions">
                                    <input type="hidden" name="csrf" value="<?php echo umeEscape($csrf); ?>">
                                    <button class="button primary" type="submit">Скачать исходный код без секретов</button>
                                </form>
                                <div class="inline-alert error"><strong>Приватный полный бэкап — только для восстановления.</strong> В нём <code>config.local.php</code>, ключи и служебные данные. Не загружайте этот архив в чаты, публичные хранилища или почту.</div>
                                <form method="post" action="./?action=download_self_backup" class="form-actions self-backup-actions">
                                    <input type="hidden" name="csrf" value="<?php echo umeEscape($csrf); ?>">
                                    <label><input type="checkbox" name="confirm_private_backup" value="1" required> Понимаю, что ZIP содержит закрытые ключи, и сохраню его только в защищённом месте.</label>
                                    <button class="button secondary" type="submit">Скачать приватный полный бэкап</button>
                                </form>
                            </section>
                            <section class="card form-card<?php echo $isCentralRepository ? ' hidden' : ''; ?>" id="manual-self-update-locked">
                                <div class="card-heading"><div class="card-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10V8a6 6 0 0 1 12 0v2M5 10h14v10H5zM12 14v3"/></svg></div><div><h3>Ручное самообновление закрыто</h3><p>FTP ZIP можно устанавливать только на основном сайте, который включён как центральный репозиторий. Этот клиент обновляется только подписанными релизами центрального сайта.</p></div></div>
                            </section>
                        </div>
                        <section class="card form-card">
                            <div class="card-heading"><div class="card-icon violet"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v10H4zM8 11h8M8 15h5M7 4v3m10-3v3"/></svg></div><div><h3>Центральный сервер обновлений</h3><p>Включите только на главном сайте. Каждая успешно установленная новая версия Upgrade Manager автоматически становится доступна подключённым проектам.</p></div></div>
                            <form id="repository-form"><label class="toggle-row"><input id="repository-enabled" type="checkbox" name="repository_enabled"><span><strong>Этот сайт — центральный репозиторий</strong><small>Релизы хранятся в защищённом storage и выдаются только по ключу.</small></span></label><div class="form-actions"><button class="button secondary" type="submit">Сохранить режим</button></div></form>
                            <div id="repository-panel" class="repository-panel hidden">
                                <div class="field"><label for="repository-feed-url">Feed URL</label><div class="copy-row"><input id="repository-feed-url" readonly><button class="button secondary small" id="copy-feed-url" type="button">Копировать</button></div></div>
                                <div class="field"><label for="repository-key">Ключ репозитория</label><div class="copy-row"><input id="repository-key" readonly><button class="button secondary small" id="copy-repository-key" type="button">Копировать</button></div></div>
                                <div class="repository-separator"></div>
                                <div class="inline-alert" id="repository-auto-publication"><strong>Автопубликация включена.</strong> После успешного самообновления центрального Upgrade Manager релиз собирается и публикуется автоматически.</div>
                                <div class="update-result" id="repository-latest"></div>
                            </div>
                        </section>
                    </div>

                <?php elseif ($view === 'security'): ?>
                    <div class="settings-grid">
                        <div class="security-left-stack">
                            <section class="card form-card">
                                <div class="card-heading"><div class="card-icon blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4zM4 7l8 6 8-6"/></svg></div><div><h3>Email администратора</h3><p>Email используется как логин и как единственный адрес для Recovery-ссылок.</p></div></div>
                                <?php if ($emailBindingRequired): ?><div class="inline-alert warning"><strong>Требуется привязка email.</strong> Это старая установка с прежним логином. До привязки email остальные рабочие действия заблокированы.</div><?php else: ?><div class="inline-alert"><strong>Текущий email:</strong> <?php echo umeEscape($adminEmail); ?></div><?php endif; ?>
                                <form id="admin-email-form">
                                    <div class="field"><label for="admin-email">Новый email администратора</label><input id="admin-email" type="email" name="admin_email" value="<?php echo umeEscape($adminEmail); ?>" autocomplete="username" required></div>
                                    <div class="field"><label for="admin-email-password">Текущий пароль</label><input id="admin-email-password" type="password" name="current_password" autocomplete="current-password" required><small>После смены email старые сессии будут отозваны, а новый адрес станет логином.</small></div>
                                    <div class="form-actions"><button class="button primary" type="submit"><?php echo $emailBindingRequired ? 'Привязать email' : 'Изменить email'; ?></button></div>
                                </form>
                            </section>
                            <section class="card form-card">
                                <div class="card-heading"><div class="card-icon green"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.6 2.8 8.2 7 10 4.2-1.8 7-5.4 7-10V6l-7-3Zm-3 9 2 2 4-4"/></svg></div><div><h3>Пароль администратора</h3><p>Пароль используется вместе с email для обычного входа.</p></div></div>
                                <?php if ($passwordChangeRequired): ?><div class="inline-alert">Сейчас используется временный пароль. Замените его перед рабочей эксплуатацией.</div><?php endif; ?>
                                <form id="password-form">
                                    <div class="field"><label for="current-password">Текущий пароль</label><input id="current-password" type="password" name="current_password" autocomplete="current-password" required></div>
                                    <div class="field"><label for="new-password">Новый пароль</label><input id="new-password" type="password" name="new_password" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}" autocomplete="new-password" required><small>Минимум 8 символов: латинская строчная буква, заглавная буква и цифра.</small></div>
                                    <div class="form-actions"><button class="button primary" type="submit">Изменить пароль</button></div>
                                </form>
                            </section>
                            <section class="card form-card<?php echo $isCentralRepository ? '' : ' hidden'; ?>" id="remote-password-reset-card">
                                <div class="card-heading"><div class="card-icon amber"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a6 6 0 0 0-6 6v3H4v9h16v-9h-2V9a6 6 0 0 0-6-6Zm-3 9V9a3 3 0 0 1 6 0v3"/></svg></div><div><h3>Доступ к клиентскому сайту</h3><p>Главный Upgrade Manager отправит одноразовую подписанную команду: сбросит пароль на временный или установит постоянные email (логин) и пароль.</p></div></div>
                                <form id="remote-password-reset-form">
                                    <div class="field"><label for="remote-password-reset-domain">Домен клиентского сайта</label><input id="remote-password-reset-domain" name="domain" type="text" placeholder="client.example.com" autocomplete="off" required><small>Укажите только домен. Клиент должен быть подключён к этому центральному update-feed: временный пароль — Upgrade Manager 3.5.8+, установка email и пароля — 3.6.4+.</small></div>
                                    <div class="field"><label>Действие</label><div class="remote-access-modes"><label class="remote-access-mode"><input type="radio" name="remote_mode" value="reset" checked><span><strong>Временный пароль</strong><small>Клиент обязан сменить его при первом входе</small></span></label><label class="remote-access-mode"><input type="radio" name="remote_mode" value="set"><span><strong>Установить email и пароль</strong><small>Постоянные: как зададите здесь, так и будет</small></span></label></div></div>
                                    <div class="remote-set-fields hidden" id="remote-set-fields">
                                        <div class="field"><label for="remote-set-email">Email администратора (логин на клиентском сайте)</label><input id="remote-set-email" name="email" type="email" autocomplete="off" placeholder="admin@example.com"></div>
                                        <div class="field"><label for="remote-set-password">Пароль</label><input id="remote-set-password" name="password" type="password" autocomplete="new-password"><small>Минимум 8 символов: латинская строчная буква, заглавная буква и цифра.</small></div>
                                        <div class="field"><label for="remote-set-password-confirm">Повторите пароль</label><input id="remote-set-password-confirm" name="password_confirm" type="password" autocomplete="new-password"></div>
                                    </div>
                                    <div class="inline-alert" id="remote-password-reset-security"><strong>Безопасность:</strong> команда подписывается приватным RSA-ключом главного сайта. Приватный ключ не передаётся клиентам; все старые сессии клиента будут отозваны.</div>
                                    <div class="form-actions"><button class="button danger" type="submit" id="remote-access-submit">Сбросить пароль</button></div>
                                </form>
                                <div class="manual-self-update-report" id="remote-password-reset-result"></div>
                            </section>
                        </div>
                        <aside class="info-panel security-panel"><div class="info-icon shield">✓</div><h3>Защита включена</h3><ul class="feature-list"><li>Подписанная cookie авторизации</li><li>CSRF-проверка всех изменений</li><li>Ограничение попыток входа</li><li>Восстановление пароля и Recovery через центральный сервер</li><li>Проверка подписи ZIP-патча</li><li>Автоматический rollback-пакет</li></ul><a href="./recovery.php">Перейти в Recovery</a></aside>
                    </div>

                <?php elseif ($view === 'diagnostics'): ?>
                    <section class="card">
                        <div class="card-heading"><div class="card-icon cyan"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5m0 14h16M8 15l3-4 3 2 5-7"/></svg></div><div><h3>Состояние сервера</h3><p>Проверка обязательных расширений PHP и доступности хранилища.</p></div></div>
                        <div class="diagnostic-grid" id="diagnostic-grid"><div class="diagnostic-placeholder">Выполняется проверка окружения…</div></div>
                        <div class="site-details" id="site-info"></div>
                    </section>

                <?php elseif ($view === 'patch-format'): ?>
                    <div class="docs-grid">
                        <section class="card docs-card">
                            <div class="card-heading"><div class="card-icon amber"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4.5-8 4.5-8-4.5L12 3Zm-8 9 8 4.5 8-4.5M4 16.5l8 4.5 8-4.5"/></svg></div><div><h3>External Upgrade Patch 1.2</h3><p>Версия патча жёстко связана с версией сайта: base_site_version берётся из backup, version = next_patch_version.</p></div></div>
                            <div class="steps"><div class="step"><span>1</span><div><strong>manifest.json</strong><p>Описывает проект, домен, версию, файлы, удаления, миграции и действия.</p></div></div><div class="step"><span>2</span><div><strong>SHA-256 содержимого</strong><p>Каждый payload/SQL-файл проверяется до подписи и установки.</p></div></div><div class="step"><span>3</span><div><strong>Локальная подпись</strong><p>В ручном режиме 3.0+ можно не передавать HMAC: сервер сам добавит payload_hash и signature после проверки.</p></div></div><div class="step"><span>4</span><div><strong>Rollback до изменений</strong><p>Менеджер сохраняет исходные файлы и затронутые таблицы базы.</p></div></div></div>
                        </section>
                        <aside class="docs-actions"><h3>Материалы</h3><a class="resource-link" href="./PATCH_AUTHORING_RU.md" target="_blank" rel="noopener"><span>С чего начать по бэкапу</span><small>PATCH_AUTHORING_RU.md</small></a><a class="resource-link" href="./PATCH_FORMAT_RU.md" target="_blank" rel="noopener"><span>Документация формата</span><small>PATCH_FORMAT_RU.md</small></a><a class="resource-link" href="./?action=profile"><span>Безопасный профиль сайта</span><small>Без HMAC-ключа и других секретов</small></a><a class="resource-link" href="./README_INSTALL_RU.md" target="_blank" rel="noopener"><span>Инструкция по установке</span><small>README_INSTALL_RU.md</small></a><a class="resource-link" href="./README_API_RU.md" target="_blank" rel="noopener"><span>Инструкция API/MCP</span><small>README_API_RU.md</small></a><a class="resource-link" href="./CHANGELOG_RU.md" target="_blank" rel="noopener"><span>История изменений</span><small>CHANGELOG_RU.md</small></a></aside>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <div class="wizard-overlay hidden" id="migration-export-modal" aria-hidden="true">
        <div class="wizard-backdrop" data-close-wizard="migration-export-modal"></div>
        <section class="wizard-dialog" role="dialog" aria-modal="true" aria-labelledby="migration-export-title">
            <div class="wizard-head"><div><span class="eyebrow">Миграция проекта</span><h3 id="migration-export-title">Подготовить перенос на другой сайт</h3><p>Upgrade Manager соберёт все файлы проекта без /upgrade/, сделает полный дамп БД и выдаст защищённую ссылку на 24 часа.</p></div><button class="wizard-close" type="button" data-close-wizard="migration-export-modal" aria-label="Закрыть">×</button></div>
            <form id="migration-export-form">
                <div class="field"><label for="migration-target-domain">Домен нового проекта</label><input id="migration-target-domain" name="target_domain" type="text" placeholder="newsite.ru" autocomplete="off" required><small>Без https:// и без пути. Импорт будет разрешён только на этом домене.</small></div>
                <div class="inline-alert"><strong>Что будет перенесено:</strong> все файлы сайта и полный дамп обнаруженной MySQL/MariaDB. Папка /upgrade/ и runtime Upgrade Manager не попадут в пакет.</div>
                <div class="wizard-actions"><button class="button secondary" type="button" data-close-wizard="migration-export-modal">Отмена</button><button class="button primary" type="submit">Создать ссылку для переноса</button></div>
            </form>
        </section>
    </div>

    <div class="wizard-overlay hidden" id="migration-import-modal" aria-hidden="true">
        <div class="wizard-backdrop" data-close-wizard="migration-import-modal"></div>
        <section class="wizard-dialog wide" role="dialog" aria-modal="true" aria-labelledby="migration-import-title">
            <div class="wizard-head"><div><span class="eyebrow">Миграция проекта</span><h3 id="migration-import-title">Импортировать проект на этот сайт</h3><p>Вставьте ссылку со старого Upgrade Manager. Файлы и БД будут скачаны сервер-сервер, проверены и установлены с автоматическим rollback.</p></div><button class="wizard-close" type="button" data-close-wizard="migration-import-modal" aria-label="Закрыть">×</button></div>
            <form id="migration-import-form">
                <div class="field"><label for="migration-source-url">Ссылка миграции со старого сайта</label><input id="migration-source-url" name="source_url" type="url" placeholder="https://old.example.com/upgrade/migration.php?id=...&token=..." autocomplete="off" required></div>
                <div class="form-row migration-db-grid">
                    <div class="field"><label for="migration-db-host">Хост БД</label><input id="migration-db-host" name="db_host" value="localhost" autocomplete="off"></div>
                    <div class="field small-field"><label for="migration-db-port">Порт</label><input id="migration-db-port" name="db_port" type="number" min="1" max="65535" value="3306"></div>
                </div>
                <div class="form-row migration-db-grid">
                    <div class="field"><label for="migration-db-name">Имя БД на новом проекте</label><input id="migration-db-name" name="db_name" autocomplete="off"></div>
                    <div class="field"><label for="migration-db-user">Имя пользователя БД</label><input id="migration-db-user" name="db_user" autocomplete="off"></div>
                </div>
                <div class="field"><label for="migration-db-password">Пароль от БД</label><input id="migration-db-password" name="db_password" type="password" autocomplete="new-password"><small>Реквизиты сохраняются только во временной защищённой задаче нового Upgrade Manager и никогда не передаются старому сайту.</small></div>
                <div class="inline-alert warning"><strong>Перед изменениями</strong> Upgrade Manager сначала скачает и проверит источник, перепишет DB-конфиг во временной копии, а затем сделает страховочную копию текущих файлов и БД нового сайта.</div>
                <div class="wizard-actions"><button class="button secondary" type="button" data-close-wizard="migration-import-modal">Отмена</button><button class="button primary" type="submit">Проверить и начать перенос</button></div>
            </form>
        </section>
    </div>

    <div class="process-overlay hidden" id="process" aria-hidden="true">
        <div class="process-backdrop" aria-hidden="true"></div>
        <section class="process process-dialog" role="dialog" aria-modal="true" aria-labelledby="process-title" aria-describedby="process-message" aria-live="polite">
            <div class="process-head">
                <div class="process-title-wrap"><span class="process-spinner" aria-hidden="true"></span><div><strong id="process-title">Процесс</strong><p id="process-message"></p></div></div>
                <span class="process-status" id="process-status">Выполняется</span>
            </div>
            <div class="progress"><div id="progress-bar"></div></div>
            <div class="progress-meta"><span id="progress-percent">0%</span><span id="process-time"></span></div>
            <p class="process-lock-note" id="process-lock-note">Пока процесс не завершён, остальной интерфейс заблокирован.</p>
            <details class="process-details"><summary>Технический журнал процесса</summary><pre id="process-log"></pre></details>
            <div class="process-actions"><button class="button secondary hidden" id="resume-btn" type="button">Продолжить</button><button class="button danger" id="cancel-btn" type="button">Прервать</button></div>
        </section>
    </div>
    <script>window.UME={csrf:<?php echo json_encode($csrf); ?>,view:<?php echo json_encode($view); ?>};</script>
    <script src="./assets/app.js?v=<?php echo (int)@filemtime(__DIR__.'/assets/app.js'); ?>"></script>
<?php endif; ?>
</body>
</html>
