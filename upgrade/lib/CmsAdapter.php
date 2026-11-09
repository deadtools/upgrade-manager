<?php
class UMECmsAdapter {
    public static function detect($root){
        $root=rtrim(str_replace('\\','/',$root),'/');
        $checks=array(
            array('id'=>'wordpress','name'=>'WordPress','all'=>array('wp-includes/version.php'),'any'=>array('wp-config.php','../wp-config.php')),
            array('id'=>'bitrix','name'=>'1C-Bitrix','all'=>array('bitrix/modules/main'),'any'=>array('bitrix/.settings.php','bitrix/php_interface/dbconn.php')),
            array('id'=>'webasyst','name'=>'Webasyst / Shop-Script','all'=>array('wa-system','wa-config/db.php','wa-apps/shop/lib/config/app.php'),'any'=>array('wa-apps/shop')),
            array('id'=>'opencart','name'=>'OpenCart','all'=>array('system/startup.php'),'any'=>array('config.php')),
            array('id'=>'joomla','name'=>'Joomla','all'=>array('administrator'),'any'=>array('configuration.php')),
            array('id'=>'drupal','name'=>'Drupal','all'=>array('core/lib/Drupal.php'),'any'=>array('sites/default')),
            array('id'=>'magento2','name'=>'Magento 2','all'=>array('bin/magento'),'any'=>array('app/etc/env.php')),
            array('id'=>'prestashop','name'=>'PrestaShop','all'=>array('classes'),'any'=>array('app/config/parameters.php','config/settings.inc.php')),
            array('id'=>'modx','name'=>'MODX Revolution','all'=>array('manager'),'any'=>array('core/config/config.inc.php'))
        );
        foreach($checks as $check){
            $ok=true;foreach($check['all'] as $marker)if(!file_exists($root.'/'.$marker)){$ok=false;break;}
            if($ok&&!empty($check['any'])){$ok=false;foreach($check['any'] as $marker)if(file_exists($root.'/'.$marker)){$ok=true;break;}}
            if($ok){
                $version=self::detectVersion($root,$check['id']);
                $db=self::databaseConfig($root,$check['id'],false);
                return self::describe($check['id'],$check['name'],$version,$db);
            }
        }
        $db=self::databaseConfig($root,'generic',false);
        return self::describe('generic','Неизвестная PHP CMS / сайт','',$db);
    }

    private static function describe($id,$name,$version,$db){
        $supported=true;$minSupportedVersion='';$supportReason='';
        if($id==='webasyst'){
            $minSupportedVersion='8.0';
            if($version===''){$supported=false;$supportReason='Не удалось определить версию Shop-Script. Поддерживаются версии 8.0 и выше.';}
            else if(version_compare($version,$minSupportedVersion,'<')){$supported=false;$supportReason='Shop-Script '.$version.' ниже минимально поддерживаемой версии '.$minSupportedVersion.'.';}
        }
        $protected=array('upgrade/config.local.php','.env','.env.local','.env.production','.env.development','.env.testing','auth.json','composer.auth.json','.git');
        $readProtected=array('upgrade/config.local.php','.env','.env.local','.env.production','.env.development','.env.testing','auth.json','composer.auth.json','.git','vendor');
        $excluded=array('node_modules','.git','.svn','upgrade');
        $cache=array();$media=array();$coreDirs=array();$coreFiles=array();
        if($id==='wordpress'){
            $protected=array_merge($protected,array('wp-config.php'));
            $readProtected=array_merge($readProtected,array('wp-config.php','wp-content/uploads'));
            $excluded=array_merge($excluded,array('wp-content/uploads','wp-content/cache','wp-content/upgrade','wp-content/ai1wm-backups','wp-content/updraft'));
            $cache=array('wp-content/cache');$media=array('wp-content/uploads');
            $coreDirs=array('wp-admin','wp-includes');$coreFiles=array('index.php','license.txt','readme.html','xmlrpc.php','wp-activate.php','wp-blog-header.php','wp-comments-post.php','wp-config-sample.php','wp-cron.php','wp-links-opml.php','wp-load.php','wp-login.php','wp-mail.php','wp-settings.php','wp-signup.php','wp-trackback.php');
        }else if($id==='bitrix'){
            $protected=array_merge($protected,array('bitrix/.settings.php','bitrix/php_interface/dbconn.php'));
            $readProtected=array_merge($readProtected,array('bitrix/.settings.php','bitrix/php_interface/dbconn.php','upload','bitrix/backup'));
            $excluded=array_merge($excluded,array('upload','bitrix/cache','bitrix/managed_cache','bitrix/stack_cache','bitrix/tmp','bitrix/backup'));
            $cache=array('bitrix/cache','bitrix/managed_cache','bitrix/stack_cache');$media=array('upload');
            $coreDirs=array('bitrix/admin','bitrix/js','bitrix/modules','bitrix/panel','bitrix/tools','bitrix/wizards','bitrix/components/bitrix');
        }else if($id==='webasyst'){
            $protected=array_merge($protected,array('wa-config'));
            $readProtected=array_merge($readProtected,array('wa-config','wa-data','wa-cache','wa-log'));
            $excluded=array_merge($excluded,array('wa-data','wa-cache','wa-log'));
            $cache=array('wa-cache');$media=array('wa-data/public');$coreDirs=array('wa-system');$coreFiles=array('wa.php');
        }else if($id==='opencart'){
            $protected=array_merge($protected,array('config.php','admin/config.php'));
            $readProtected=array_merge($readProtected,array('config.php','admin/config.php','image','system/storage','storage','download','downloads'));
            $excluded=array_merge($excluded,array('image','system/storage/cache','system/storage/logs','system/storage/session','system/storage/modification','system/storage/upload','system/storage/download','vqmod/vqcache','upload'));
            $cache=array('system/storage/cache','system/storage/modification','vqmod/vqcache');$media=array('image');$coreDirs=array('system');
        }else if($id==='joomla'){
            $protected=array_merge($protected,array('configuration.php'));
            $readProtected=array_merge($readProtected,array('configuration.php','images','tmp','logs'));
            $excluded=array_merge($excluded,array('images','cache','administrator/cache','tmp','logs'));
            $cache=array('cache','administrator/cache');$media=array('images');$coreDirs=array('includes','libraries','administrator/includes');
        }else if($id==='drupal'){
            $protected=array_merge($protected,array('sites/default/settings.php','sites/default/settings.local.php'));
            $readProtected=array_merge($readProtected,array('sites/default/settings.php','sites/default/settings.local.php','sites/default/files','sites/default/private'));
            $excluded=array_merge($excluded,array('sites/default/files','sites/default/private','sites/default/files/php','sites/default/files/cache'));
            $cache=array('sites/default/files/php','sites/default/files/cache');$media=array('sites/default/files');$coreDirs=array('core');
        }else if($id==='magento2'){
            $protected=array_merge($protected,array('app/etc/env.php'));
            $readProtected=array_merge($readProtected,array('app/etc/env.php','pub/media','var/backups','var/log'));
            $excluded=array_merge($excluded,array('pub/media','var/cache','var/page_cache','var/log','var/session','var/tmp','var/backups','generated','vendor'));
            $cache=array('var/cache','var/page_cache','generated');$media=array('pub/media');$coreDirs=array('lib/internal','setup','dev');
        }else if($id==='prestashop'){
            $protected=array_merge($protected,array('app/config/parameters.php','config/settings.inc.php'));
            $readProtected=array_merge($readProtected,array('app/config/parameters.php','config/settings.inc.php','img','download','upload','var/logs'));
            $excluded=array_merge($excluded,array('img','download','upload','var/cache','var/logs','log'));
            $cache=array('var/cache','cache/smarty/cache','cache/smarty/compile');$media=array('img','upload');$coreDirs=array('classes','controllers','src');
        }else if($id==='modx'){
            $protected=array_merge($protected,array('core/config/config.inc.php'));
            $readProtected=array_merge($readProtected,array('core/config/config.inc.php','core/cache','core/logs'));
            $excluded=array_merge($excluded,array('core/cache','core/logs','assets/cache'));
            $cache=array('core/cache','assets/cache');$media=array('assets/images','assets/img');$coreDirs=array('manager','core/model','core/src','core/vendor');
        }else{
            $readProtected=array_merge($readProtected,array('storage','uploads','upload','images','image','logs','log','cache'));
            $excluded=array_merge($excluded,array('cache','logs','log','uploads','upload','images','image','storage'));$media=array('uploads','upload','images','image');
        }
        return array(
            'id'=>$id,'name'=>$name,'version'=>$version,
            'supported'=>$supported,'min_supported_version'=>$minSupportedVersion,'support_reason'=>$supportReason,
            'database_engine'=>isset($db['engine'])?$db['engine']:'unknown',
            'database_available'=>!empty($db['available']) && isset($db['engine']) && $db['engine']==='mysql',
            'database_detected'=>!empty($db['available']),
            'database_reason'=>isset($db['reason'])?$db['reason']:'',
            'db_prefix'=>isset($db['prefix'])?$db['prefix']:'',
            'protected_targets'=>array_values(array_unique($protected)),
            'protected_read_paths'=>array_values(array_unique($readProtected)),
            'excluded_directories'=>array_values(array_unique($excluded)),
            'cache_directories'=>array_values(array_unique($cache)),
            'media_directories'=>array_values(array_unique($media)),
            'cms_core_directories'=>array_values(array_unique($coreDirs)),
            'cms_core_files'=>array_values(array_unique($coreFiles))
        );
    }

    public static function configDefaults($root){
        $cms=self::detect($root);
        $patterns=array('order','order_%','return','return_%','customer','customer_%','address','address_%','affiliate','affiliate_%','session','sessions','cart','user','users','usermeta','comment','comments','commentmeta','fias');
        if($cms['id']==='bitrix')$patterns=array_merge($patterns,array('b_user','b_user_%','b_sale_order%','b_sale_basket%','b_sale_fuser%'));
        if($cms['id']==='wordpress')$patterns=array_merge($patterns,array('woocommerce_sessions','wc_orders%','actionscheduler_logs'));
        if($cms['id']==='webasyst')$patterns=array_merge($patterns,array('shop_order%','shop_cart%','shop_customer%','wa_contact%','wa_session%','blog_comment%','contacts_history','wa_app_settings','wa_log%','wa_login_log','wa_verification_channel%','shop_checkout_flow','shop_importexport','shop_notification%','shop_product_stocks_log','shop_sales','wa_widget%'));
        return array(
            'cms'=>$cms,
            'default_dump_mode'=>'compact',
            'default_file_mode'=>'code_cms',
            'default_database_mode'=>'minimal',
            'compact_exclude_data_patterns'=>array_values(array_unique($patterns)),
            'compact_include_data_tables'=>array(),
            'compact_schema_only_database'=>false,
            'code_extensions'=>array('php','phtml','inc','module','install','theme','tpl','twig','latte','js','jsx','ts','tsx','css','scss','less','html','htm','xml','json','yml','yaml','ini','sql','md','txt','svg','vue','svelte','woff','woff2','ttf','eot','map'),
            'max_code_file_bytes'=>20971520,
            'code_filenames'=>array('.htaccess','.user.ini','web.config','robots.txt','composer.json','composer.lock','package.json','package-lock.json','yarn.lock'),
            'excluded_directories'=>$cms['excluded_directories'],
            'excluded_extensions'=>array('jpg','jpeg','png','gif','webp','bmp','ico','avif','heic','heif','tif','tiff','mp4','mov','avi','mkv','mp3','wav','ogg','zip','rar','7z','tar','gz','bz2','xz','pdf','psd','ai','eps','dat','mmdb','bin'),
            'image_extensions'=>array('jpg','jpeg','png','gif','webp','bmp','ico','avif','svg','heic','heif','tif','tiff'),
            'media_directories'=>isset($cms['media_directories'])?$cms['media_directories']:array(),
            'cms_core_directories'=>isset($cms['cms_core_directories'])?$cms['cms_core_directories']:array(),
            'cms_core_files'=>isset($cms['cms_core_files'])?$cms['cms_core_files']:array(),
            'protected_targets'=>$cms['protected_targets'],
            'protected_read_paths'=>$cms['protected_read_paths'],
            'cache_directories'=>$cms['cache_directories'],
            'allowed_actions'=>array('clear_cache','clear_opencart_cache'),
            'update_server_url'=>'',
            'update_repository_key'=>'',
            'limits'=>array('max_patch_bytes'=>104857600,'max_patch_files'=>5000,'max_uncompressed_bytes'=>524288000,'files_per_step'=>35,'directories_per_step'=>20,'db_rows_per_step'=>500,'log_tail_lines'=>80,'stale_seconds'=>600,'backup_retention'=>10,'log_retention_days'=>30)
        );
    }


    public static function chooseStorage($root,$siteId){
        $root=rtrim(str_replace('\\','/',$root),'/');$token=substr(hash('sha256',$siteId.'|'.$root),0,16);$parent=dirname($root);
        $outside=$parent.'/.upgrade-manager-'.$token;
        if($parent!==$root && is_dir($parent) && self::storageProbe($outside))return array('path'=>$outside,'mode'=>'outside_web_root','web_exposed_risk'=>false);
        return array('path'=>$root.'/upgrade/.data-'.$token,'mode'=>'inside_upgrade_randomized','web_exposed_risk'=>true);
    }
    public static function storageUsable($path){ return self::storageProbe($path); }
    private static function storageProbe($path){
        $path=rtrim(str_replace('\\','/',(string)$path),'/');if($path==='')return false;$created=false;
        if(!is_dir($path)){if(!@mkdir($path,0700,true)&&!is_dir($path))return false;$created=true;}
        if(!is_writable($path)){if($created)@rmdir($path);return false;}
        $probe=$path.'/.ume-write-probe-'.substr(hash('sha256',uniqid('',true).mt_rand()),0,12);$ok=@file_put_contents($probe,'ok',LOCK_EX)!==false;
        if($ok)@unlink($probe);if($created)@rmdir($path);return $ok;
    }

    public static function databaseConfig($root,$cmsId,$throw){
        try{
            if($cmsId==='wordpress')$db=self::dbWordPress($root);
            else if($cmsId==='bitrix')$db=self::dbBitrix($root);
            else if($cmsId==='webasyst'){
                $version=self::detectVersion($root,'webasyst');
                if($version==='')$db=self::dbUnavailable('Не удалось определить версию Shop-Script. Поддерживаются версии 8.0 и выше.','mysql','');
                else if(version_compare($version,'8.0','<'))$db=self::dbUnavailable('Shop-Script '.$version.' ниже минимально поддерживаемой версии 8.0.','mysql','');
                else $db=self::dbWebasyst($root);
            }
            else if($cmsId==='opencart')$db=self::dbOpenCart($root);
            else if($cmsId==='joomla')$db=self::dbJoomla($root);
            else if($cmsId==='magento2')$db=self::dbMagento($root);
            else if($cmsId==='prestashop')$db=self::dbPrestaShop($root);
            else if($cmsId==='modx')$db=self::dbModx($root);
            else if($cmsId==='generic')$db=self::dbGeneric($root);
            else $db=array('available'=>false,'engine'=>'unknown','prefix'=>'','reason'=>'Database adapter is not implemented for this CMS.');
            if(empty($db['available']) && $throw)throw new Exception(isset($db['reason'])?$db['reason']:'Параметры БД не определены.');
            return $db;
        }catch(Exception $e){if($throw)throw $e;return array('available'=>false,'engine'=>'unknown','prefix'=>'','reason'=>$e->getMessage());}
    }

    public static function databaseConfigFromFile($root,$path,$throw=false){
        try{
            $root=rtrim(str_replace('\\','/',(string)$root),'/');$path=trim(str_replace('\\','/',(string)$path));
            if($path===''||strlen($path)>1024||strpos($path,"\0")!==false)throw new Exception('Укажите корректный путь к файлу конфигурации БД.');
            if(preg_match('#^[a-z][a-z0-9+.-]*://#i',$path))throw new Exception('Разрешён только локальный путь к файлу на сервере.');
            $absolute=(substr($path,0,1)==='/'||preg_match('#^[A-Za-z]:/#',$path));$candidate=$absolute?$path:$root.'/'.$path;$real=realpath($candidate);
            if($real===false||!is_file($real))throw new Exception('Файл конфигурации БД не найден: '.$path);
            if(!is_readable($real))throw new Exception('Нет прав на чтение файла конфигурации БД: '.$path);
            $size=@filesize($real);if($size!==false&&$size>1048576)throw new Exception('Файл конфигурации БД слишком большой (максимум 1 МБ).');
            $raw=@file_get_contents($real);if($raw===false)throw new Exception('Не удалось прочитать файл конфигурации БД.');
            $parsed=self::genericDbParseFile($raw,basename($real));$missing=array();foreach(array('host','database','user','password') as $key)if(!array_key_exists($key,$parsed)||$parsed[$key]===null)$missing[]=$key;
            if($missing)throw new Exception('В указанном файле не удалось определить: '.implode(', ',$missing).'.');
            $hp=self::splitHost((string)$parsed['host']);$port=isset($parsed['port'])&&$parsed['port']!==null?(int)$parsed['port']:$hp['port'];$prefix=isset($parsed['prefix'])&&$parsed['prefix']!==null?(string)$parsed['prefix']:'';
            $db=self::dbReady('mysql',$hp['host'],(string)$parsed['user'],(string)$parsed['password'],(string)$parsed['database'],$port,$hp['socket'],$prefix);$db['source_files']=array($path);$db['config_location']='custom_file';$db['config_path']=$path;return $db;
        }catch(Exception $e){if($throw)throw $e;return array('available'=>false,'engine'=>'mysql','prefix'=>'','reason'=>$e->getMessage(),'source_files'=>array());}
    }


    private static function dbGeneric($root){
        $root=rtrim(str_replace('\\','/',$root),'/');$candidates=self::genericDbCandidateFiles($root);
        $values=array('host'=>null,'database'=>null,'user'=>null,'password'=>null,'port'=>null,'prefix'=>'');$sources=array();
        foreach($candidates as $file){
            $raw=@file_get_contents($file);if($raw===false||strlen($raw)>1048576)continue;$parsed=self::genericDbParseFile($raw,basename($file));$used=false;
            foreach(array('host','database','user','password','port','prefix') as $key){if(($values[$key]===null||($key==='prefix'&&$values[$key]===''))&&array_key_exists($key,$parsed)&&$parsed[$key]!==null){$values[$key]=$parsed[$key];$used=true;}}
            if($used){$sources[]=str_replace('\\','/',substr($file,strlen($root)+1));}
            if($values['host']!==null&&$values['database']!==null&&$values['user']!==null&&$values['password']!==null)break;
        }
        $missing=array();foreach(array('host','database','user','password') as $key)if($values[$key]===null)$missing[]=$key;
        if($missing)return array('available'=>false,'engine'=>'mysql','prefix'=>(string)$values['prefix'],'reason'=>'Универсальный PHP/MySQL detector не нашёл статические параметры: '.implode(', ',$missing).'.','source_files'=>array_values(array_unique($sources)));
        $hp=self::splitHost((string)$values['host']);$port=$values['port']!==null?(int)$values['port']:$hp['port'];$db=self::dbReady('mysql',$hp['host'],$values['user'],$values['password'],$values['database'],$port,$hp['socket'],(string)$values['prefix']);$db['source_files']=array_values(array_unique($sources));return $db;
    }
    private static function genericDbCandidateFiles($root){
        $fixed=array('.env.local','.env','config.local.php','assets/core/config.local.php','app/config.local.php','config/database.php','config/db.php','assets/core/config.php','config.php','database.php','db.php','settings.php','includes/config.php','inc/config.php','app/config.php');$out=array();$seen=array();
        foreach($fixed as $rel){$file=$root.'/'.$rel;if(is_file($file)){$real=realpath($file);if($real&&!isset($seen[$real])){$seen[$real]=1;$out[]=$real;}}}
        $queue=array(array($root,0));$visited=0;while($queue&&$visited<240){$item=array_shift($queue);$dir=$item[0];$depth=$item[1];$list=@scandir($dir);if(!is_array($list))continue;foreach($list as $name){if($name==='.'||$name==='..')continue;$path=$dir.'/'.$name;if(is_link($path))continue;if(is_dir($path)){if($depth<3&&preg_match('/^(?:assets|core|app|config|includes?|inc|src|private|system)$/i',$name))$queue[]=array($path,$depth+1);continue;}if(!is_file($path))continue;$visited++;if(!(preg_match('/^(?:config(?:\.local)?|database|db|settings|connection)\.php$/i',$name)||preg_match('/^\.env(?:\.[a-z0-9_-]+)?$/i',$name)))continue;$real=realpath($path);if($real&&!isset($seen[$real])){$seen[$real]=1;$out[]=$real;}}}
        return $out;
    }
    private static function genericDbParseFile($raw,$basename){
        if(preg_match('/^\.env/i',(string)$basename))return self::genericDbParseEnv($raw);
        $map=array('host'=>array('DB_HOST','DATABASE_HOST','MYSQL_HOST','DB_SERVER','DB_HOSTNAME'),'database'=>array('DB_NAME','DATABASE_NAME','MYSQL_DATABASE','DB_DATABASE','DBNAME'),'user'=>array('DB_USER','DATABASE_USER','MYSQL_USER','DB_USERNAME'),'password'=>array('DB_PASS','DB_PASSWORD','DATABASE_PASSWORD','MYSQL_PASSWORD','DB_PASSWD'),'port'=>array('DB_PORT','DATABASE_PORT','MYSQL_PORT'),'prefix'=>array('DB_PREFIX','TABLE_PREFIX'));
        $out=array();foreach($map as $key=>$names)$out[$key]=self::genericDbNamedValue($raw,$names,$key==='port');
        $literal=self::genericDbLiteralConnection($raw);foreach(array('host','database','user','password','port') as $key)if(($out[$key]===null||$out[$key]==='')&&array_key_exists($key,$literal)&&$literal[$key]!==null)$out[$key]=$literal[$key];return $out;
    }
    private static function genericDbLiteralConnection($raw){
        $out=array('host'=>null,'database'=>null,'user'=>null,'password'=>null,'port'=>null);
        if(preg_match("~new\\s+\\\\?PDO\\s*\\(\\s*(['\"])(mysql:[^'\"]+)\\1\\s*,\\s*(['\"])((?:\\\\.|(?!\\3).)*)\\3\\s*,\\s*(['\"])((?:\\\\.|(?!\\5).)*)\\5~is",$raw,$m)){
            $dsn=stripcslashes($m[2]);$out['user']=stripcslashes($m[4]);$out['password']=stripcslashes($m[6]);
            if(preg_match('/(?:^|[:;])host=([^;]+)/i',$dsn,$x))$out['host']=trim($x[1]);if(preg_match('/(?:^|;)dbname=([^;]+)/i',$dsn,$x))$out['database']=trim($x[1]);if(preg_match('/(?:^|;)port=(\d+)/i',$dsn,$x))$out['port']=(int)$x[1];
        }
        if(($out['host']===null||$out['database']===null||$out['user']===null||$out['password']===null)&&preg_match("~new\\s+\\\\?mysqli\\s*\\(\\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1\\s*,\\s*(['\"])((?:\\\\.|(?!\\3).)*)\\3\\s*,\\s*(['\"])((?:\\\\.|(?!\\5).)*)\\5\\s*,\\s*(['\"])((?:\\\\.|(?!\\7).)*)\\7(?:\\s*,\\s*(\\d+))?~is",$raw,$m)){
            $out['host']=stripcslashes($m[2]);$out['user']=stripcslashes($m[4]);$out['password']=stripcslashes($m[6]);$out['database']=stripcslashes($m[8]);if(isset($m[9])&&$m[9]!=='')$out['port']=(int)$m[9];
        }
        return $out;
    }
    private static function genericDbParseEnv($raw){
        $aliases=array('host'=>array('DB_HOST','DATABASE_HOST','MYSQL_HOST'),'database'=>array('DB_NAME','DATABASE_NAME','MYSQL_DATABASE','DB_DATABASE'),'user'=>array('DB_USER','DATABASE_USER','MYSQL_USER','DB_USERNAME'),'password'=>array('DB_PASS','DB_PASSWORD','DATABASE_PASSWORD','MYSQL_PASSWORD'),'port'=>array('DB_PORT','DATABASE_PORT','MYSQL_PORT'),'prefix'=>array('DB_PREFIX','TABLE_PREFIX'));
        $out=array('host'=>null,'database'=>null,'user'=>null,'password'=>null,'port'=>null,'prefix'=>'');$lines=preg_split('/\r\n|\r|\n/',(string)$raw);
        foreach($aliases as $key=>$names){foreach($lines as $line){$line=trim($line);if($line===''||substr($line,0,1)==='#')continue;foreach($names as $name){if(preg_match('/^(?:export\s+)?'.preg_quote($name,'/').'\s*=\s*(.*)$/i',$line,$m)){$v=trim($m[1]);if(strlen($v)>=2&&(($v[0]==='"'&&substr($v,-1)==='"')||($v[0]==="'"&&substr($v,-1)==="'")))$v=substr($v,1,-1);$out[$key]=$key==='port'?(int)$v:$v;break 2;}}}}
        return $out;
    }
    private static function genericDbNamedValue($raw,$names,$numeric){
        foreach($names as $name){$q=preg_quote($name,'~');
            if(!$numeric){$direct=self::phpDefineString($raw,$name);if($direct!==null)return $direct;}
            if($numeric&&preg_match("~(?:define|[A-Za-z_][A-Za-z0-9_]*DefineConfig)\\s*\\(\\s*(['\"])".$q."\\1\\s*,\\s*(\\d+)~i",$raw,$m))return (int)$m[2];
            if(!$numeric&&preg_match("~\\bconst\\s+".$q."\\s*=\\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1\\s*;~s",$raw,$m))return stripcslashes($m[2]);
            if(preg_match("~\\b[A-Za-z_][A-Za-z0-9_]*DefineConfig\\s*\\(\\s*(['\"])".$q."\\1\\s*,\\s*(.*?)\\)\\s*;~is",$raw,$m)){
                $expr=$m[2];if(preg_match("~(?:getenv|[A-Za-z_][A-Za-z0-9_]*ConfigEnv)\\s*\\(\\s*(['\"])([A-Za-z_][A-Za-z0-9_]*)\\1~i",$expr,$em)){$env=getenv($em[2]);if($env!==false&&$env!=='')return $numeric?(int)$env:$env;}
                if($numeric&&preg_match_all('/\b(\d+)\b/',$expr,$nm)&&!empty($nm[1]))return (int)end($nm[1]);
                if(!$numeric&&preg_match_all("~(['\"])((?:\\\\.|(?!\\1).)*)\\1~s",$expr,$sm)&&!empty($sm[2]))return stripcslashes(end($sm[2]));
            }
            $aliases=array(strtolower($name),strtolower(preg_replace('/^(?:DB_|DATABASE_|MYSQL_)/','',$name)));if(preg_match('/(?:NAME|DATABASE|DBNAME)$/i',$name))$aliases=array_merge($aliases,array('database','dbname','db'));if(preg_match('/(?:USER|USERNAME)$/i',$name))$aliases=array_merge($aliases,array('user','username'));if(preg_match('/(?:PASS|PASSWORD|PASSWD)$/i',$name))$aliases=array_merge($aliases,array('pass','password','passwd'));if(preg_match('/(?:HOST|HOSTNAME|SERVER)$/i',$name))$aliases=array_merge($aliases,array('host','hostname','server'));if(preg_match('/PORT$/i',$name))$aliases[]='port';$aliases=array_values(array_unique($aliases));foreach($aliases as $alias){$aq=preg_quote($alias,'~');if($numeric){if(preg_match("~['\"]".$aq."['\"]\\s*=>\\s*(\\d+)~i",$raw,$m))return (int)$m[1];}else if(preg_match("~['\"]".$aq."['\"]\\s*=>\\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1~is",$raw,$m))return stripcslashes($m[2]);}
            $variableNames=array(strtolower($name),strtolower(str_replace('_','',$name)));foreach($variableNames as $vn){$vq=preg_quote($vn,'~');if($numeric){if(preg_match("~\\$".$vq."\\s*=\\s*(\\d+)\\s*;~i",$raw,$m))return (int)$m[1];}else if(preg_match("~\\$".$vq."\\s*=\\s*(['\"])((?:\\\\.|(?!\\1).)*)\\1\\s*;~is",$raw,$m))return stripcslashes($m[2]);}
        }
        return null;
    }

    private static function dbWordPress($root){
        $inRoot=is_file($root.'/wp-config.php');$file=$inRoot?$root.'/wp-config.php':(is_file(dirname($root).'/wp-config.php')?dirname($root).'/wp-config.php':'');if($file===''||!is_file($file))return self::dbUnavailable('wp-config.php не найден.');$raw=@file_get_contents($file);if($raw===false)return self::dbUnavailable('Не удалось прочитать wp-config.php.');
        $name=self::phpDefineString($raw,'DB_NAME');$user=self::phpDefineString($raw,'DB_USER');$pass=self::phpDefineString($raw,'DB_PASSWORD');$host=self::phpDefineString($raw,'DB_HOST');$prefix=self::phpVariableString($raw,'table_prefix');
        if($name===null||$user===null||$pass===null||$host===null)return self::dbUnavailable('WordPress использует динамическую конфигурацию БД, которую безопасный парсер не смог разобрать.','mysql',$prefix===null?'':$prefix);
        $hp=self::splitHost($host);$db=self::dbReady('mysql',$hp['host'],$user,$pass,$name,$hp['port'],$hp['socket'],$prefix===null?'':$prefix);$db['config_location']=$inRoot?'root':'parent';$db['source_files']=array($inRoot?'wp-config.php':'../wp-config.php');return $db;
    }
    private static function dbOpenCart($root){
        $file=$root.'/config.php';if(!is_file($file))return self::dbUnavailable('config.php OpenCart не найден.');$raw=@file_get_contents($file);if($raw===false)return self::dbUnavailable('Не удалось прочитать config.php.');
        $host=self::phpDefineString($raw,'DB_HOSTNAME');$user=self::phpDefineString($raw,'DB_USERNAME');$pass=self::phpDefineString($raw,'DB_PASSWORD');$name=self::phpDefineString($raw,'DB_DATABASE');$prefix=self::phpDefineString($raw,'DB_PREFIX');$port=self::phpDefineNumber($raw,'DB_PORT');
        if($host===null||$user===null||$pass===null||$name===null)return self::dbUnavailable('Параметры БД OpenCart не удалось разобрать.','mysql',$prefix===null?'':$prefix);
        return self::dbReady('mysql',$host,$user,$pass,$name,$port===null?3306:$port,null,$prefix===null?'':$prefix);
    }
    private static function dbBitrix($root){
        $settings=$root.'/bitrix/.settings.php';if(is_file($settings)){
            $data=self::includeArrayFile($settings);$d=isset($data['connections']['value']['default'])?$data['connections']['value']['default']:null;
            if(is_array($d)){
                $class=isset($d['className'])?(string)$d['className']:'';$engine=stripos($class,'pgsql')!==false?'pgsql':'mysql';$hp=self::splitHost(isset($d['host'])?$d['host']:'localhost');
                if(isset($d['database'],$d['login'],$d['password']))return self::dbReady($engine,$hp['host'],$d['login'],$d['password'],$d['database'],$hp['port'],$hp['socket'],'');
            }
        }
        $legacy=$root.'/bitrix/php_interface/dbconn.php';if(is_file($legacy)){
            $raw=@file_get_contents($legacy);if($raw!==false){$host=self::phpVariableString($raw,'DBHost');$user=self::phpVariableString($raw,'DBLogin');$pass=self::phpVariableString($raw,'DBPassword');$name=self::phpVariableString($raw,'DBName');$type=self::phpVariableString($raw,'DBType');if($host!==null&&$user!==null&&$pass!==null&&$name!==null){$hp=self::splitHost($host);return self::dbReady($type!==null&&stripos($type,'pgsql')!==false?'pgsql':'mysql',$hp['host'],$user,$pass,$name,$hp['port'],$hp['socket'],'');}}
        }
        return self::dbUnavailable('Параметры БД 1C-Bitrix не удалось определить.');
    }
    private static function dbWebasyst($root){
        $file=$root.'/wa-config/db.php';if(!is_file($file))return self::dbUnavailable('wa-config/db.php Webasyst не найден.');
        $data=self::includeArrayFile($file);$d=isset($data['default'])?$data['default']:null;
        if(!is_array($d)||!isset($d['host'],$d['user'],$d['database']))return self::dbUnavailable('Параметры БД Webasyst в wa-config/db.php не удалось определить.');
        $type=isset($d['type'])?strtolower((string)$d['type']):'mysql';
        if($type===''||strpos($type,'mysql')!==false)$engine='mysql';else if(strpos($type,'pgsql')!==false||strpos($type,'postgres')!==false)$engine='pgsql';else $engine=$type;
        $hp=self::splitHost($d['host']);$port=isset($d['port'])&&$d['port']!==''?(int)$d['port']:$hp['port'];$socket=isset($d['socket'])&&$d['socket']!==''?(string)$d['socket']:$hp['socket'];
        return self::dbReady($engine,$hp['host'],$d['user'],isset($d['password'])?$d['password']:'',$d['database'],$port,$socket,'');
    }
    private static function dbJoomla($root){
        $file=$root.'/configuration.php';if(!is_file($file))return self::dbUnavailable('configuration.php Joomla не найден.');$raw=@file_get_contents($file);if($raw===false)return self::dbUnavailable('Не удалось прочитать configuration.php.');
        $host=self::phpPropertyString($raw,'host');$user=self::phpPropertyString($raw,'user');$pass=self::phpPropertyString($raw,'password');$name=self::phpPropertyString($raw,'db');$prefix=self::phpPropertyString($raw,'dbprefix');$type=self::phpPropertyString($raw,'dbtype');
        if($host===null||$user===null||$pass===null||$name===null)return self::dbUnavailable('Параметры БД Joomla не удалось разобрать.');$hp=self::splitHost($host);$engine=$type!==null&&stripos($type,'pgsql')!==false?'pgsql':'mysql';return self::dbReady($engine,$hp['host'],$user,$pass,$name,$hp['port'],$hp['socket'],$prefix===null?'':$prefix);
    }
    private static function dbMagento($root){
        $file=$root.'/app/etc/env.php';if(!is_file($file))return self::dbUnavailable('app/etc/env.php Magento не найден.');$data=self::includeArrayFile($file);$d=isset($data['db']['connection']['default'])?$data['db']['connection']['default']:null;if(!is_array($d)||!isset($d['host'],$d['username'],$d['dbname']))return self::dbUnavailable('Параметры БД Magento не найдены.');$hp=self::splitHost($d['host']);$prefix=isset($data['db']['table_prefix'])?$data['db']['table_prefix']:'';return self::dbReady('mysql',$hp['host'],$d['username'],isset($d['password'])?$d['password']:'',$d['dbname'],$hp['port'],$hp['socket'],$prefix);
    }
    private static function dbPrestaShop($root){
        $modern=$root.'/app/config/parameters.php';if(is_file($modern)){$data=self::includeArrayFile($modern);$p=isset($data['parameters'])?$data['parameters']:null;if(is_array($p)&&isset($p['database_host'],$p['database_name'],$p['database_user'])){$hp=self::splitHost($p['database_host']);$port=isset($p['database_port'])&&$p['database_port']?(int)$p['database_port']:$hp['port'];return self::dbReady('mysql',$hp['host'],$p['database_user'],isset($p['database_password'])?$p['database_password']:'',$p['database_name'],$port,$hp['socket'],isset($p['database_prefix'])?$p['database_prefix']:'');}}
        $legacy=$root.'/config/settings.inc.php';if(is_file($legacy)){$raw=@file_get_contents($legacy);$host=self::phpDefineString($raw,'_DB_SERVER_');$user=self::phpDefineString($raw,'_DB_USER_');$pass=self::phpDefineString($raw,'_DB_PASSWD_');$name=self::phpDefineString($raw,'_DB_NAME_');$prefix=self::phpDefineString($raw,'_DB_PREFIX_');if($host!==null&&$user!==null&&$pass!==null&&$name!==null){$hp=self::splitHost($host);return self::dbReady('mysql',$hp['host'],$user,$pass,$name,$hp['port'],$hp['socket'],$prefix===null?'':$prefix);}}
        return self::dbUnavailable('Параметры БД PrestaShop не удалось определить.');
    }
    private static function dbModx($root){
        $file=$root.'/core/config/config.inc.php';if(!is_file($file))return self::dbUnavailable('Конфигурация MODX не найдена.');$raw=@file_get_contents($file);$server=self::phpVariableString($raw,'database_server');$user=self::phpVariableString($raw,'database_user');$pass=self::phpVariableString($raw,'database_password');$name=self::phpVariableString($raw,'dbase');$prefix=self::phpVariableString($raw,'table_prefix');if($server===null||$user===null||$pass===null||$name===null)return self::dbUnavailable('Параметры БД MODX не удалось разобрать.');$server=preg_replace('#^mysql:#','',$server);$server=trim($server,'/');$hp=self::splitHost($server);return self::dbReady('mysql',$hp['host'],$user,$pass,trim($name,'`'),$hp['port'],$hp['socket'],$prefix===null?'':$prefix);
    }

    private static function dbReady($engine,$host,$user,$pass,$name,$port,$socket,$prefix){return array('available'=>true,'engine'=>$engine,'host'=>(string)$host,'user'=>(string)$user,'password'=>(string)$pass,'database'=>(string)$name,'port'=>$port?$port:3306,'socket'=>$socket,'prefix'=>(string)$prefix,'reason'=>'');}
    private static function dbUnavailable($reason,$engine='unknown',$prefix=''){return array('available'=>false,'engine'=>$engine,'prefix'=>$prefix,'reason'=>$reason);}
    private static function includeArrayFile($file){$loader=function($f){ob_start();$value=include $f;ob_end_clean();return is_array($value)?$value:array();};return $loader($file);}
    private static function splitHost($host){$host=trim((string)$host);$port=3306;$socket=null;if(preg_match('/^(.+):(\d+)$/',$host,$m)){$host=$m[1];$port=(int)$m[2];}else if(preg_match('#^([^:]+):(/.+)$#',$host,$m)){$host=$m[1];$socket=$m[2];}return array('host'=>$host===''?'localhost':$host,'port'=>$port,'socket'=>$socket);}
    private static function phpDefineString($raw,$name){$q=preg_quote($name,'/');if(preg_match('/define\s*\(\s*([\'\"])'.$q.'\\1\s*,\s*([\'\"])((?:\\\\.|(?!\\2).)*)\\2\s*\)\s*;/s',$raw,$m))return stripcslashes($m[3]);return null;}
    private static function phpDefineNumber($raw,$name){$q=preg_quote($name,'/');if(preg_match('/define\s*\(\s*([\'\"])'.$q.'\\1\s*,\s*(\d+)\s*\)\s*;/',$raw,$m))return (int)$m[2];return null;}
    private static function phpVariableString($raw,$name){$q=preg_quote($name,'/');if(preg_match('/\$'.$q.'\s*=\s*([\'\"])((?:\\\\.|(?!\\1).)*)\\1\s*;/s',$raw,$m))return stripcslashes($m[2]);return null;}
    private static function phpPropertyString($raw,$name){$q=preg_quote($name,'/');if(preg_match('/(?:public|protected|var)\s+\$'.$q.'\s*=\s*([\'\"])((?:\\\\.|(?!\\1).)*)\\1\s*;/s',$raw,$m))return stripcslashes($m[2]);return null;}

    private static function detectVersion($root,$id){
        $files=array();$patterns=array();
        if($id==='wordpress'){$files=array($root.'/wp-includes/version.php');$patterns=array('/\$wp_version\s*=\s*[\'\"]([^\'\"]+)/');}
        else if($id==='bitrix'){$files=array($root.'/bitrix/modules/main/classes/general/version.php');$patterns=array('/SM_VERSION\s*[\'\"]?\s*,\s*[\'\"]([^\'\"]+)/');}
        else if($id==='webasyst'){$files=array($root.'/wa-apps/shop/lib/config/app.php');$patterns=array('/[\'\"]version[\'\"]\s*=>\s*[\'\"]([^\'\"]+)/i');}
        else if($id==='opencart'){$files=array($root.'/index.php',$root.'/admin/index.php');$patterns=array('/define\s*\(\s*[\'\"]VERSION[\'\"]\s*,\s*[\'\"]([^\'\"]+)/');}
        else if($id==='drupal'){$files=array($root.'/core/lib/Drupal.php');$patterns=array('/const\s+VERSION\s*=\s*[\'\"]([^\'\"]+)/');}
        foreach($files as $file)if(is_file($file)){$raw=@file_get_contents($file);if($raw!==false)foreach($patterns as $pattern)if(preg_match($pattern,$raw,$m))return trim($m[1]);}
        return '';
    }
}
