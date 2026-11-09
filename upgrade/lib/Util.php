<?php
class UMEUtil {
    public static function now() { return gmdate('c'); }
    public static function randomHex($bytes) {
        if (function_exists('random_bytes')) return bin2hex(random_bytes($bytes));
        if (function_exists('openssl_random_pseudo_bytes')) return bin2hex(openssl_random_pseudo_bytes($bytes));
        $out=''; for ($i=0;$i<$bytes*2;$i++) $out .= dechex(mt_rand(0,15)); return $out;
    }
    public static function jsonRead($file, $default) {
        if (!is_file($file)) return $default;
        $raw=@file_get_contents($file); if ($raw===false || $raw==='') return $default;
        $data=json_decode($raw,true); return is_array($data)?$data:$default;
    }
    public static function jsonWriteAtomic($file, $data) {
        $dir=dirname($file); if (!is_dir($dir) && !@mkdir($dir,0775,true)) throw new Exception('Не удалось создать каталог: '.$dir);
        $tmp=$file.'.tmp.'.self::randomHex(4);
        $json=json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        if ($json===false || @file_put_contents($tmp,$json,LOCK_EX)===false) throw new Exception('Не удалось записать JSON: '.$file);
        if (!@rename($tmp,$file)) { @unlink($tmp); throw new Exception('Не удалось атомарно заменить JSON: '.$file); }
    }
    public static function appendLine($file, $line) {
        $dir=dirname($file); if (!is_dir($dir)) @mkdir($dir,0775,true);
        @file_put_contents($file,$line."\n",FILE_APPEND|LOCK_EX);
    }
    public static function normalizeRelativePath($path) {
        $path=str_replace('\\','/',$path); $path=preg_replace('#/+#','/',$path); $path=ltrim($path,'/');
        if ($path==='' || strpos($path,"\0")!==false || preg_match('#^[A-Za-z]:/#',$path)) return false;
        $parts=explode('/',$path); $clean=array();
        foreach($parts as $part){ if($part===''||$part==='.') continue; if($part==='..') return false; $clean[]=$part; }
        return implode('/',$clean);
    }
    public static function startsWith($value,$prefix){ return $prefix==='' || substr($value,0,strlen($prefix))===$prefix; }
    public static function endsWith($value,$suffix){ return $suffix==='' || substr($value,-strlen($suffix))===$suffix; }
    public static function isHttps(){ return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO']==='https'); }
    public static function domain(){ $h=isset($_SERVER['HTTP_HOST'])?strtolower($_SERVER['HTTP_HOST']):''; return preg_replace('/:\\d+$/','',$h); }
    public static function tail($file,$maxLines){
        if(!is_file($file)) return array(); $lines=@file($file,FILE_IGNORE_NEW_LINES); if(!is_array($lines)) return array();
        return array_slice($lines,max(0,count($lines)-$maxLines));
    }
    public static function removeTree($path){
        if(!file_exists($path) && !is_link($path)) return;
        if(is_file($path)||is_link($path)){ @unlink($path); return; }
        $items=@scandir($path); if(is_array($items)) foreach($items as $i){ if($i==='.'||$i==='..') continue; self::removeTree($path.DIRECTORY_SEPARATOR.$i); }
        @rmdir($path);
    }
    public static function ensureDir($path){ if(!is_dir($path) && !@mkdir($path,0775,true)) throw new Exception('Не удалось создать каталог: '.$path); }
    public static function formatBytes($bytes){
        $units=array('Б','КБ','МБ','ГБ','ТБ'); $i=0; $n=(float)$bytes; while($n>=1024 && $i<count($units)-1){$n/=1024;$i++;}
        return number_format($n,$i?2:0,',',' ').' '.$units[$i];
    }
    public static function safeId($id){ return preg_match('/^[a-zA-Z0-9._-]{1,160}$/',$id)?$id:false; }
    public static function sqlIdentifier($name){ return '`'.str_replace('`','``',$name).'`'; }
    public static function canonicalize($value) {
        if (!is_array($value)) return $value;
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $out=array(); foreach($value as $v) $out[]=self::canonicalize($v); return $out;
        }
        ksort($value, SORT_STRING);
        foreach($value as $k=>$v) $value[$k]=self::canonicalize($v);
        return $value;
    }
    public static function canonicalJson($value) {
        return json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    public static function passwordPolicyError($password) {
        $password=(string)$password;
        if(strlen($password)<8) return 'Пароль должен содержать не менее 8 символов.';
        if(!preg_match('/[a-z]/',$password)) return 'Пароль должен содержать хотя бы одну строчную латинскую букву.';
        if(!preg_match('/[A-Z]/',$password)) return 'Пароль должен содержать хотя бы одну заглавную латинскую букву.';
        if(!preg_match('/[0-9]/',$password)) return 'Пароль должен содержать хотя бы одну цифру.';
        return '';
    }
    public static function assertStrongPassword($password) {
        $error=self::passwordPolicyError($password);
        if($error!=='') throw new Exception($error);
        return true;
    }
    public static function versionCompareMin($current,$min){ return version_compare($current,$min,'>='); }
}
