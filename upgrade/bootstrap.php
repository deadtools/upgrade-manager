<?php
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
error_reporting(E_ALL); ini_set('display_errors','0');
require_once __DIR__.'/lib/Util.php';
$configFile=__DIR__.'/config.local.php';
if(!is_file($configFile)){
    if(PHP_SAPI!=='cli' && basename(isset($_SERVER['SCRIPT_NAME'])?$_SERVER['SCRIPT_NAME']:'')!=='setup.php'){
        header('Location: ./setup.php');exit;
    }
    throw new Exception('Upgrade Manager не настроен. Откройте /upgrade/setup.php.');
}
$config=require $configFile;
// Runtime release metadata and performance profile live outside site secrets.
$releaseMeta=UMEUtil::jsonRead(__DIR__.'/release.json',array());
$config['version']=!empty($releaseMeta['version'])?(string)$releaseMeta['version']:'3.7.4';
$config['limits']['files_per_step']=600;
$config['limits']['directories_per_step']=120;
$config['limits']['scan_entries_per_step']=1600;
$config['limits']['archive_files_per_step']=600;
$config['limits']['archive_bytes_per_step']=25165824;
$config['limits']['db_rows_per_step']=2000;
$config['limits']['db_operations_per_step']=60;
$config['limits']['step_time_budget_seconds']=1.8;
require_once __DIR__.'/lib/CmsAdapter.php';
// Re-apply security-sensitive adapter defaults at runtime as well. This makes
// privacy fixes effective after both single-file upgrades and central self-updates,
// without exposing or rewriting local secrets in config.local.php.
$runtimeRoot=realpath(__DIR__.'/..');
if($runtimeRoot){
    $runtimeDefaults=UMECmsAdapter::configDefaults($runtimeRoot);
    if(array_key_exists('compact_schema_only_database',$runtimeDefaults)){
        $config['compact_schema_only_database']=(bool)$runtimeDefaults['compact_schema_only_database'];
    }
    $config['media_directories']=isset($runtimeDefaults['media_directories'])&&is_array($runtimeDefaults['media_directories'])?$runtimeDefaults['media_directories']:array();
    $config['cms_core_directories']=isset($runtimeDefaults['cms_core_directories'])&&is_array($runtimeDefaults['cms_core_directories'])?$runtimeDefaults['cms_core_directories']:array();
    $config['cms_core_files']=isset($runtimeDefaults['cms_core_files'])&&is_array($runtimeDefaults['cms_core_files'])?$runtimeDefaults['cms_core_files']:array();
    $config['default_file_mode']=isset($runtimeDefaults['default_file_mode'])?(string)$runtimeDefaults['default_file_mode']:'code_cms';
    $config['default_database_mode']=isset($runtimeDefaults['default_database_mode'])?(string)$runtimeDefaults['default_database_mode']:'minimal';
    $config['image_extensions']=isset($runtimeDefaults['image_extensions'])&&is_array($runtimeDefaults['image_extensions'])?$runtimeDefaults['image_extensions']:array('jpg','jpeg','png','gif','webp','bmp','ico','avif','svg','heic','heif','tif','tiff');
    $defaultPatterns=isset($runtimeDefaults['compact_exclude_data_patterns'])&&is_array($runtimeDefaults['compact_exclude_data_patterns'])?$runtimeDefaults['compact_exclude_data_patterns']:array();
    $localPatterns=isset($config['compact_exclude_data_patterns'])&&is_array($config['compact_exclude_data_patterns'])?$config['compact_exclude_data_patterns']:array();
    $config['compact_exclude_data_patterns']=array_values(array_unique(array_merge($defaultPatterns,$localPatterns)));
}
require_once __DIR__.'/lib/Auth.php';
require_once __DIR__.'/lib/Database.php';
require_once __DIR__.'/lib/DatabaseDump.php';
require_once __DIR__.'/lib/Manager.php';
require_once __DIR__.'/lib/OAuth.php';
require_once __DIR__.'/lib/ApiTasks.php';
require_once __DIR__.'/lib/SmtpMailer.php';
require_once __DIR__.'/lib/SelfUpdater.php';
require_once __DIR__.'/lib/Cron.php';
require_once __DIR__.'/lib/Migration.php';
$manager=new UMEManager($config,__DIR__);
$auth=new UMEAuth($config,$manager->storage()); $auth->startSession();
$oauth=new UMEOAuth($config,$manager);
$apiTasks=new UMEApiTasks($config,$manager,$oauth);
$selfUpdater=new UMESelfUpdater($config,$manager,__DIR__);
$cronScheduler=new UMECronScheduler($config,$manager,__DIR__);
$migration=new UMEProjectMigration($config,$manager,__DIR__);
