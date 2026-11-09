<?php
if(PHP_SAPI!=='cli'){
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}
$local=__DIR__.'/config.local.php';
if(is_file($local)){
    $pre=require $local;
    if(is_array($pre)&&empty($_SERVER['HTTP_HOST'])&&!empty($pre['allowed_domains'])&&is_array($pre['allowed_domains']))$_SERVER['HTTP_HOST']=(string)$pre['allowed_domains'][0];
}
if(empty($_SERVER['SCRIPT_NAME']))$_SERVER['SCRIPT_NAME']='/upgrade/cron.php';
$_SERVER['HTTPS']='on';
require __DIR__.'/bootstrap.php';
$force=in_array('--force',isset($argv)&&is_array($argv)?$argv:array(),true);
$result=$cronScheduler->runDueBackup($force);
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(!empty($result['ok'])?0:1);
