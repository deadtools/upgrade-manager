<?php
require __DIR__.'/bootstrap.php';
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function umeMigrationJson($data,$status){
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}

$id=isset($_GET['id'])?(string)$_GET['id']:'';$token=isset($_GET['token'])?(string)$_GET['token']:'';
if($id===''||$token==='')umeMigrationJson(array('ok'=>false,'error'=>'Ссылка миграции неполная.'),404);
try{
    if($_SERVER['REQUEST_METHOD']==='POST'&&!empty($_GET['complete'])){$migration->completeShare($id,$token);umeMigrationJson(array('ok'=>true,'message'=>'Миграционный пакет удалён со старого сайта.'),200);}
    if(!empty($_GET['download'])){
        $kind=isset($_GET['file'])&&$_GET['file']==='db'?'db':'site';$file=$migration->shareFile($id,$token,$kind);$size=(int)filesize($file);$offset=isset($_GET['offset'])?max(0,(int)$_GET['offset']):0;$length=isset($_GET['length'])?max(1,min(4194304,(int)$_GET['length'])):2097152;if($offset>$size)$offset=$size;$length=min($length,max(0,$size-$offset));
        $fh=@fopen($file,'rb');if(!$fh)throw new Exception('Не удалось открыть часть миграции.');if($offset>0&&@fseek($fh,$offset,SEEK_SET)!==0){fclose($fh);throw new Exception('Не удалось перейти к нужному блоку миграции.');}
        header('Content-Type: application/octet-stream');header('Content-Length: '.$length);header('X-UME-Offset: '.$offset);header('X-UME-Total: '.$size);header('Accept-Ranges: bytes');$remaining=$length;while($remaining>0&&!feof($fh)){$buf=fread($fh,min(1048576,$remaining));if($buf===false||$buf==='')break;echo $buf;$remaining-=strlen($buf);if(function_exists('flush'))@flush();}fclose($fh);exit;
    }
    $info=$migration->shareInfo($id,$token);umeMigrationJson(array('ok'=>true,'migration'=>$info),200);
}catch(Exception $e){umeMigrationJson(array('ok'=>false,'error'=>$e->getMessage()),404);}
