<?php
require_once __DIR__.'/BackupAgentPack.php';
class UMEManager {
    private $config; private $root; private $storage; private $settingsFile; private $activeFile; private $databaseSecretFile; private $cms; private $settingsCache=null; private $selfProtectedPaths=null; private $upgradeDir;
    public function __construct($config,$upgradeDir){
        $this->config=$config; $this->upgradeDir=$upgradeDir; $this->root=realpath($upgradeDir.'/'.$config['site_root_relative']);
        if(!$this->root) throw new Exception('Не удалось определить корень сайта.');
        $this->cms=class_exists('UMECmsAdapter')?UMECmsAdapter::detect($this->root):(isset($config['cms'])?$config['cms']:array('id'=>'generic','name'=>'Неизвестная CMS','version'=>''));
        if(!empty($config['storage_path']))$this->storage=rtrim(str_replace('\\','/',$config['storage_path']),'/');
        else if(!empty($config['storage_relative']))$this->storage=$this->root.'/'.trim($config['storage_relative'],'/');
        else $this->storage=$this->root.'/upgrade/.data';
        if(class_exists('UMECmsAdapter') && method_exists('UMECmsAdapter','storageUsable') && !UMECmsAdapter::storageUsable($this->storage)){
            $sid=isset($config['site_id'])?(string)$config['site_id']:'ume-runtime';$fallback=UMECmsAdapter::chooseStorage($this->root,$sid);
            if(!empty($fallback['path']))$this->storage=rtrim(str_replace('\\','/',$fallback['path']),'/');
        }
        $this->settingsFile=$this->storage.'/settings.json'; $this->activeFile=$this->storage.'/active-job.json'; $this->databaseSecretFile=$this->storage.'/database-credentials.secret';
        $this->bootstrapStorage();
    }
    public function root(){return $this->root;} public function storage(){return $this->storage;} public function config(){return $this->config;}
    private function bootstrapStorage(){
        $dirs=array('jobs','logs','backups','public-backups','rollbacks','uploads','temp','locks','quarantine','api-tasks','api-uploads','api-drafts','oauth');
        UMEUtil::ensureDir($this->storage);@chmod($this->storage,0700);foreach($dirs as $d){UMEUtil::ensureDir($this->storage.'/'.$d);@chmod($this->storage.'/'.$d,0700);}
        $deny=$this->storage.'/.htaccess';if(!is_file($deny))@file_put_contents($deny,"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        $guard=$this->storage.'/index.php';if(!is_file($guard))@file_put_contents($guard,"<?php http_response_code(404); exit;\n");
        $webConfig=$this->storage.'/web.config';if(!is_file($webConfig))@file_put_contents($webConfig,'<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
        if(!is_file($this->settingsFile)) UMEUtil::jsonWriteAtomic($this->settingsFile,array('file_mode'=>isset($this->config['default_file_mode'])?$this->config['default_file_mode']:'code_cms','database_mode'=>isset($this->config['default_database_mode'])?$this->config['default_database_mode']:'minimal','dump_mode'=>'compact','backup_retention'=>$this->config['limits']['backup_retention'],'log_retention_days'=>$this->config['limits']['log_retention_days'],'backup_exclusions'=>array(),'ui_theme'=>'light','database_connection_mode'=>'auto','database_config_path'=>'','manual_db_host'=>'localhost','manual_db_port'=>3306,'manual_db_name'=>'','manual_db_user'=>'','manual_db_prefix'=>''));
        foreach(array('history.json','applied.json','rollback-index.json') as $f) if(!is_file($this->storage.'/'.$f)) UMEUtil::jsonWriteAtomic($this->storage.'/'.$f,array());
    }
    private function validFileMode($mode){return in_array((string)$mode,array('all','code_cms','necessary','none'),true);}
    private function validDatabaseMode($mode){return in_array((string)$mode,array('full','minimal','schema','none'),true);}
    private function validUiTheme($theme){return in_array((string)$theme,array('light','dark'),true);}
    private function legacyModePair($mode){
        $map=array('code'=>array('file_mode'=>'code_cms','database_mode'=>'none'),'schema'=>array('file_mode'=>'code_cms','database_mode'=>'schema'),'compact'=>array('file_mode'=>'code_cms','database_mode'=>'minimal'),'full'=>array('file_mode'=>'code_cms','database_mode'=>'full'),'complete'=>array('file_mode'=>'all','database_mode'=>'full'));
        return isset($map[$mode])?$map[$mode]:array('file_mode'=>'code_cms','database_mode'=>'minimal');
    }
    private function legacyModeFromPair($fileMode,$databaseMode){
        if($fileMode==='code_cms'&&$databaseMode==='none')return 'code';if($fileMode==='code_cms'&&$databaseMode==='schema')return 'schema';if($fileMode==='code_cms'&&$databaseMode==='minimal')return 'compact';if($fileMode==='code_cms'&&$databaseMode==='full')return 'full';if($fileMode==='all'&&$databaseMode==='full')return 'complete';return 'custom';
    }
    public function settings(){
        if(is_array($this->settingsCache))return $this->settingsCache;
        $s=UMEUtil::jsonRead($this->settingsFile,array());
        if(empty($s['file_mode'])||!$this->validFileMode($s['file_mode'])||empty($s['database_mode'])||!$this->validDatabaseMode($s['database_mode'])){
            $hasLegacy=isset($s['dump_mode'])&&$this->validLegacyBackupMode((string)$s['dump_mode']);$legacy=$hasLegacy?(string)$s['dump_mode']:(isset($this->config['default_dump_mode'])?(string)$this->config['default_dump_mode']:'compact');$pair=$this->legacyModePair($legacy);
            if(empty($s['file_mode'])||!$this->validFileMode($s['file_mode']))$s['file_mode']=$hasLegacy?$pair['file_mode']:(isset($this->config['default_file_mode'])&&$this->validFileMode($this->config['default_file_mode'])?$this->config['default_file_mode']:$pair['file_mode']);
            if(empty($s['database_mode'])||!$this->validDatabaseMode($s['database_mode']))$s['database_mode']=$hasLegacy?$pair['database_mode']:(isset($this->config['default_database_mode'])&&$this->validDatabaseMode($this->config['default_database_mode'])?$this->config['default_database_mode']:$pair['database_mode']);
        }
        $s['dump_mode']=$this->legacyModeFromPair($s['file_mode'],$s['database_mode']);
        if(empty($s['backup_retention']))$s['backup_retention']=isset($this->config['limits']['backup_retention'])?(int)$this->config['limits']['backup_retention']:10;
        if(empty($s['log_retention_days']))$s['log_retention_days']=isset($this->config['limits']['log_retention_days'])?(int)$this->config['limits']['log_retention_days']:30;
        if(!isset($s['backup_exclusions'])||!is_array($s['backup_exclusions']))$s['backup_exclusions']=array();
        if(empty($s['ui_theme'])||!$this->validUiTheme($s['ui_theme']))$s['ui_theme']='light';
        if(empty($s['database_connection_mode'])||!in_array((string)$s['database_connection_mode'],array('auto','file','manual'),true))$s['database_connection_mode']='auto';
        if(!isset($s['database_config_path']))$s['database_config_path']='';if(!isset($s['manual_db_host']))$s['manual_db_host']='localhost';if(!isset($s['manual_db_port']))$s['manual_db_port']=3306;if(!isset($s['manual_db_name']))$s['manual_db_name']='';if(!isset($s['manual_db_user']))$s['manual_db_user']='';if(!isset($s['manual_db_prefix']))$s['manual_db_prefix']='';
        $this->settingsCache=$s;return $s;
    }
    private function dbSecretKey(){
        $base=isset($this->config['hmac_key'])?(string)$this->config['hmac_key']:'';if($base==='')throw new Exception('Локальный HMAC-ключ недоступен для защиты пароля БД.');return hash('sha256',$base.'|ume-manual-database|1',true);
    }
    private function dbEncryptPassword($plain){
        if(!function_exists('openssl_encrypt')||!function_exists('openssl_random_pseudo_bytes'))throw new Exception('Для защищённого хранения пароля БД требуется OpenSSL.');$iv=@openssl_random_pseudo_bytes(16);if(!is_string($iv)||strlen($iv)!==16)throw new Exception('Не удалось создать IV для пароля БД.');$key=$this->dbSecretKey();$cipher=@openssl_encrypt((string)$plain,'AES-256-CBC',$key,OPENSSL_RAW_DATA,$iv);if($cipher===false)throw new Exception('Не удалось зашифровать пароль БД.');$mac=hash_hmac('sha256',$iv.$cipher,$key,true);return array('format'=>'ume-manual-database-secret/1.0','data'=>base64_encode($iv.$mac.$cipher));
    }
    private function dbDecryptPassword(){
        if(!is_file($this->databaseSecretFile))return '';$box=UMEUtil::jsonRead($this->databaseSecretFile,array());if(empty($box['data']))throw new Exception('Хранилище пароля БД повреждено.');$raw=base64_decode((string)$box['data'],true);if($raw===false||strlen($raw)<48)throw new Exception('Хранилище пароля БД повреждено.');$iv=substr($raw,0,16);$mac=substr($raw,16,32);$cipher=substr($raw,48);$key=$this->dbSecretKey();$calc=hash_hmac('sha256',$iv.$cipher,$key,true);if(function_exists('hash_equals')){$ok=hash_equals($calc,$mac);}else{$ok=strlen($calc)===strlen($mac);if($ok){$v=0;for($i=0;$i<strlen($calc);$i++)$v|=ord($calc[$i])^ord($mac[$i]);$ok=$v===0;}}if(!$ok)throw new Exception('Контроль целостности сохранённого пароля БД не пройден.');if(!function_exists('openssl_decrypt'))throw new Exception('Для чтения пароля БД требуется OpenSSL.');$plain=@openssl_decrypt($cipher,'AES-256-CBC',$key,OPENSSL_RAW_DATA,$iv);if($plain===false)throw new Exception('Не удалось расшифровать пароль БД.');return $plain;
    }
    private function dbWritePassword($plain){UMEUtil::jsonWriteAtomic($this->databaseSecretFile,$this->dbEncryptPassword($plain));@chmod($this->databaseSecretFile,0600);}
    private function validateManualDbValues($host,$port,$name,$user,$prefix){
        $host=trim((string)$host);$port=(int)$port;$name=trim((string)$name);$user=trim((string)$user);$prefix=trim((string)$prefix);
        if($host===''||strlen($host)>255||preg_match('/[\\s\\r\\n\\x00\\/]/',$host))throw new Exception('Укажите корректный хост БД.');if($port<1||$port>65535)throw new Exception('Укажите корректный порт БД.');if($name===''||strlen($name)>128||preg_match('/[\\r\\n\\x00]/',$name))throw new Exception('Укажите имя базы данных.');if($user===''||strlen($user)>128||preg_match('/[\\r\\n\\x00]/',$user))throw new Exception('Укажите пользователя БД.');if(strlen($prefix)>64||($prefix!==''&&!preg_match('/^[A-Za-z0-9_]+$/',$prefix)))throw new Exception('Префикс таблиц может содержать только латинские буквы, цифры и _.');return array('host'=>$host,'port'=>$port,'database'=>$name,'user'=>$user,'prefix'=>$prefix);
    }
    private function validateDatabaseConfigPath($path){
        $path=trim((string)$path);if($path===''||strlen($path)>1024||strpos($path,"\0")!==false)throw new Exception('Укажите путь к файлу конфигурации БД.');if(preg_match('#^[a-z][a-z0-9+.-]*://#i',$path))throw new Exception('Разрешён только локальный путь к файлу на сервере.');return $path;
    }
    private function fileDatabaseConfig($path,$throw=false){
        try{if(!class_exists('UMECmsAdapter')||!method_exists('UMECmsAdapter','databaseConfigFromFile'))throw new Exception('Парсер файла конфигурации БД недоступен.');$path=$this->validateDatabaseConfigPath($path);$cfg=UMECmsAdapter::databaseConfigFromFile($this->root,$path,true);$cfg['connection_source']='file';return $cfg;}catch(Exception $e){if($throw)throw $e;return array('available'=>false,'engine'=>'mysql','prefix'=>'','reason'=>$e->getMessage(),'connection_source'=>'file');}
    }
    public function databaseConfig($throw=false){
        $s=$this->settings();if(isset($s['database_connection_mode'])&&$s['database_connection_mode']==='file')return $this->fileDatabaseConfig(isset($s['database_config_path'])?$s['database_config_path']:'',$throw);if(isset($s['database_connection_mode'])&&$s['database_connection_mode']==='manual'){
            try{$v=$this->validateManualDbValues(isset($s['manual_db_host'])?$s['manual_db_host']:'localhost',isset($s['manual_db_port'])?$s['manual_db_port']:3306,isset($s['manual_db_name'])?$s['manual_db_name']:'',isset($s['manual_db_user'])?$s['manual_db_user']:'',isset($s['manual_db_prefix'])?$s['manual_db_prefix']:'');$pass=$this->dbDecryptPassword();return array('available'=>true,'engine'=>'mysql','host'=>$v['host'],'port'=>$v['port'],'socket'=>null,'database'=>$v['database'],'user'=>$v['user'],'password'=>$pass,'prefix'=>$v['prefix'],'reason'=>'','source_files'=>array(),'config_location'=>'manual','connection_source'=>'manual');}
            catch(Exception $e){if($throw)throw $e;return array('available'=>false,'engine'=>'mysql','prefix'=>isset($s['manual_db_prefix'])?(string)$s['manual_db_prefix']:'','reason'=>$e->getMessage(),'connection_source'=>'manual');}
        }
        if(!class_exists('UMECmsAdapter')){if($throw)throw new Exception('CMS adapter is not loaded.');return array('available'=>false,'engine'=>'unknown','prefix'=>'','reason'=>'CMS adapter is not loaded.','connection_source'=>'auto');}
        $cfg=UMECmsAdapter::databaseConfig($this->root,isset($this->cms['id'])?$this->cms['id']:'generic',$throw);$cfg['connection_source']='auto';return $cfg;
    }
    public function databaseConnectionSettings(){
        $s=$this->settings();$auto=class_exists('UMECmsAdapter')?UMECmsAdapter::databaseConfig($this->root,isset($this->cms['id'])?$this->cms['id']:'generic',false):array('available'=>false,'reason'=>'CMS adapter unavailable');$filePath=isset($s['database_config_path'])?(string)$s['database_config_path']:'';$file=$filePath!==''?$this->fileDatabaseConfig($filePath,false):array('available'=>false,'reason'=>'Путь к файлу конфигурации не задан.','connection_source'=>'file');$active=$this->databaseConfig(false);
        $safe=function($cfg){return array('available'=>!empty($cfg['available']),'host'=>isset($cfg['host'])?(string)$cfg['host']:'','port'=>isset($cfg['port'])?(int)$cfg['port']:3306,'database'=>isset($cfg['database'])?(string)$cfg['database']:'','user'=>isset($cfg['user'])?(string)$cfg['user']:'','prefix'=>isset($cfg['prefix'])?(string)$cfg['prefix']:'','reason'=>isset($cfg['reason'])?(string)$cfg['reason']:'','source'=>isset($cfg['connection_source'])?(string)$cfg['connection_source']:'auto');};
        return array('mode'=>isset($s['database_connection_mode'])?(string)$s['database_connection_mode']:'auto','file_path'=>$filePath,'password_configured'=>is_file($this->databaseSecretFile),'manual'=>array('host'=>(string)$s['manual_db_host'],'port'=>(int)$s['manual_db_port'],'database'=>(string)$s['manual_db_name'],'user'=>(string)$s['manual_db_user'],'prefix'=>(string)$s['manual_db_prefix']),'auto'=>$safe($auto),'file'=>$safe($file),'active'=>$safe($active));
    }
    public function saveDatabaseConnectionSettings($input){
        $s=$this->settings();$mode=isset($input['database_connection_mode'])?(string)$input['database_connection_mode']:'auto';if(!in_array($mode,array('auto','file','manual'),true))throw new Exception('Неизвестный режим подключения к БД.');
        if($mode==='file'){$path=$this->validateDatabaseConfigPath(isset($input['database_config_path'])?$input['database_config_path']:(isset($s['database_config_path'])?$s['database_config_path']:''));$this->fileDatabaseConfig($path,true);$s['database_config_path']=$path;}
        if($mode==='manual'){$v=$this->validateManualDbValues(isset($input['manual_db_host'])?$input['manual_db_host']:$s['manual_db_host'],isset($input['manual_db_port'])?$input['manual_db_port']:$s['manual_db_port'],isset($input['manual_db_name'])?$input['manual_db_name']:$s['manual_db_name'],isset($input['manual_db_user'])?$input['manual_db_user']:$s['manual_db_user'],isset($input['manual_db_prefix'])?$input['manual_db_prefix']:$s['manual_db_prefix']);$s['manual_db_host']=$v['host'];$s['manual_db_port']=$v['port'];$s['manual_db_name']=$v['database'];$s['manual_db_user']=$v['user'];$s['manual_db_prefix']=$v['prefix'];if(!empty($input['manual_db_clear_password']))$this->dbWritePassword('');else if(array_key_exists('manual_db_password',$input)&&((string)$input['manual_db_password']!==''||!is_file($this->databaseSecretFile)))$this->dbWritePassword((string)$input['manual_db_password']);}
        $s['database_connection_mode']=$mode;UMEUtil::jsonWriteAtomic($this->settingsFile,$s);$this->settingsCache=$s;return $this->databaseConnectionSettings();
    }
    private function manualConfigFromInput($input){
        $s=$this->settings();$v=$this->validateManualDbValues(isset($input['manual_db_host'])?$input['manual_db_host']:$s['manual_db_host'],isset($input['manual_db_port'])?$input['manual_db_port']:$s['manual_db_port'],isset($input['manual_db_name'])?$input['manual_db_name']:$s['manual_db_name'],isset($input['manual_db_user'])?$input['manual_db_user']:$s['manual_db_user'],isset($input['manual_db_prefix'])?$input['manual_db_prefix']:$s['manual_db_prefix']);$pass=(isset($input['manual_db_password'])&&(string)$input['manual_db_password']!=='')?(string)$input['manual_db_password']:$this->dbDecryptPassword();return array('available'=>true,'engine'=>'mysql','host'=>$v['host'],'port'=>$v['port'],'socket'=>null,'database'=>$v['database'],'user'=>$v['user'],'password'=>$pass,'prefix'=>$v['prefix'],'reason'=>'','connection_source'=>'manual');
    }
    private function fileConfigFromInput($input){$s=$this->settings();$path=isset($input['database_config_path'])?(string)$input['database_config_path']:(isset($s['database_config_path'])?(string)$s['database_config_path']:'');return $this->fileDatabaseConfig($path,true);}
    public function testDatabaseConnection($input=array()){
        if(!extension_loaded('mysqli'))throw new Exception('Расширение mysqli недоступно.');$mode=isset($input['database_connection_mode'])?(string)$input['database_connection_mode']:(isset($this->settings()['database_connection_mode'])?(string)$this->settings()['database_connection_mode']:'auto');if(!in_array($mode,array('auto','file','manual'),true))throw new Exception('Неизвестный режим подключения к БД.');$cfg=$mode==='manual'?$this->manualConfigFromInput($input):($mode==='file'?$this->fileConfigFromInput($input):$this->databaseConfig(true));if(empty($cfg['available']))throw new Exception(isset($cfg['reason'])?$cfg['reason']:'Реквизиты БД недоступны.');
        $db=@mysqli_init();if(!$db)throw new Exception('Не удалось инициализировать mysqli.');if(defined('MYSQLI_OPT_CONNECT_TIMEOUT'))@$db->options(MYSQLI_OPT_CONNECT_TIMEOUT,6);$ok=@$db->real_connect($cfg['host'],$cfg['user'],$cfg['password'],$cfg['database'],isset($cfg['port'])?(int)$cfg['port']:3306,isset($cfg['socket'])?$cfg['socket']:null);if(!$ok){$err=$db->connect_error;@$db->close();throw new Exception('Ошибка подключения к БД: '.$err);}@$db->set_charset('utf8mb4');$server=isset($db->server_info)?(string)$db->server_info:'';@$db->close();return array('connected'=>true,'source'=>$mode,'host'=>$cfg['host'],'port'=>(int)$cfg['port'],'database'=>$cfg['database'],'user'=>$cfg['user'],'server_version'=>$server);
    }
    private function database(){return new UMEDatabase($this->root,$this->databaseConfig(true));}

    private function normalizeBackupExclusions($input){
        if(is_array($input))$rows=$input;else $rows=preg_split('/\r\n|\r|\n/',(string)$input);
        if(!is_array($rows))$rows=array();$out=array();$seen=array();
        foreach($rows as $row){
            $rule=trim((string)$row);if($rule===''||substr($rule,0,1)==='#')continue;if(strpos($rule,"\0")!==false)throw new Exception('В исключениях обнаружен недопустимый символ.');
            $rule=str_replace('\\','/',$rule);$rule=preg_replace('#/+#','/',$rule);if(substr($rule,0,2)==='./')$rule=substr($rule,2);
            if(strlen($rule)>240)throw new Exception('Слишком длинное правило исключения: '.substr($rule,0,80));
            if(strpos($rule,'../')!==false||$rule==='..'||substr($rule,-3)==='/..')throw new Exception('В исключениях нельзя использовать переход ..: '.$rule);
            if(substr($rule,0,1)==='.'&&strpos($rule,'/')===false&&strpos($rule,'*')===false&&strpos($rule,'?')===false)$rule='*'.$rule;
            if(isset($seen[$rule]))continue;$seen[$rule]=1;$out[]=$rule;if(count($out)>250)throw new Exception('Можно сохранить не более 250 правил исключений.');
        }
        return $out;
    }
    public function saveSettings($input){
        $s=$this->settings();
        if(isset($input['file_mode'])||isset($input['database_mode'])){
            $fileMode=isset($input['file_mode'])?(string)$input['file_mode']:$s['file_mode'];$databaseMode=isset($input['database_mode'])?(string)$input['database_mode']:$s['database_mode'];
            if(!$this->validFileMode($fileMode))throw new Exception('Неизвестный режим файлового бэкапа.');if(!$this->validDatabaseMode($databaseMode))throw new Exception('Неизвестный режим бэкапа БД.');$s['file_mode']=$fileMode;$s['database_mode']=$databaseMode;
        }else if(isset($input['dump_mode'])){$pair=$this->legacyModePair((string)$input['dump_mode']);$s['file_mode']=$pair['file_mode'];$s['database_mode']=$pair['database_mode'];}
        if($s['file_mode']==='none'&&$s['database_mode']==='none')throw new Exception('Нельзя одновременно выбрать «Без файлов» и «Без БД».');
        $s['dump_mode']=$this->legacyModeFromPair($s['file_mode'],$s['database_mode']);
        if(isset($input['backup_retention']))$s['backup_retention']=max(1,min(50,(int)$input['backup_retention']));
        if(isset($input['log_retention_days']))$s['log_retention_days']=max(1,min(365,(int)$input['log_retention_days']));
        if(isset($input['backup_exclusions']))$s['backup_exclusions']=$this->normalizeBackupExclusions($input['backup_exclusions']);
        if(isset($input['ui_theme'])){if(!$this->validUiTheme((string)$input['ui_theme']))throw new Exception('Неизвестная тема интерфейса.');$s['ui_theme']=(string)$input['ui_theme'];}
        UMEUtil::jsonWriteAtomic($this->settingsFile,$s);$this->settingsCache=$s;return $s;
    }
    public function saveBackupExclusions($input){
        $s=$this->settings();$s['backup_exclusions']=$this->normalizeBackupExclusions(isset($input['backup_exclusions'])?$input['backup_exclusions']:'');UMEUtil::jsonWriteAtomic($this->settingsFile,$s);$this->settingsCache=$s;return $s;
    }
    private function jobFile($id){return $this->storage.'/jobs/'.$id.'.json';}
    private function cancelFile($id){return $this->storage.'/jobs/'.$id.'.cancel';}
    private function logFile($id){return $this->storage.'/logs/'.$id.'.log';}
    private function log($job,$message,$level){ $line='['.date('Y-m-d H:i:s').'] ['.$level.'] '.$message; UMEUtil::appendLine($this->logFile($job['id']),$line); }
    private function writeJob($job){$job['updated_at']=UMEUtil::now(); UMEUtil::jsonWriteAtomic($this->jobFile($job['id']),$job); if(in_array($job['status'],array('running','stale','cancelling','rolling_back'),true)) UMEUtil::jsonWriteAtomic($this->activeFile,array('id'=>$job['id'])); else {@unlink($this->cancelFile($job['id']));if(is_file($this->activeFile)){ $a=UMEUtil::jsonRead($this->activeFile,array()); if(isset($a['id'])&&$a['id']===$job['id'])@unlink($this->activeFile); }}}
    // 3.6.3: make the "changes started" flag durable before the first destructive write so that a fatal
    // PHP error inside a step can never turn into "cancelled without rollback".
    private function persistChangesStarted(&$job){ if(!empty($job['changes_started']))return; $job['changes_started']=true; $this->writeJob($job); $this->log($job,'Начаты изменения сайта; признак сохранён до первой записи.','INFO'); }
    // 3.6.3: fail fast on short writes (disk full / quota) instead of producing a truncated dump that is later signed.
    private function writeExact($file,$data,$flags,$label){ $data=(string)$data; $written=@file_put_contents($file,$data,$flags); if($written===false||(int)$written!==strlen($data))throw new Exception('Не удалось полностью записать '.$label.'. Проверьте свободное место и квоту.'); return (int)$written; }
    // 3.6.5: ZipArchive::addFile() only queues a file; compression happens in close(). Already-compressed
    // formats and very large files are stored without recompression so one step stays within PHP limits.
    public static function zipEntryShouldStore($path,$bytes){
        $ext=strtolower(pathinfo((string)$path,PATHINFO_EXTENSION));
        $packed=array('jpg','jpeg','png','gif','webp','avif','heic','heif','ico','mp4','m4v','mov','avi','mkv','webm','mp3','m4a','ogg','oga','opus','flac','aac','zip','gz','tgz','bz2','xz','7z','rar','zst','lz4','br','pdf','woff','woff2','jar','apk','docx','xlsx','pptx','odt','ods','odp','epub','mmdb');
        return in_array($ext,$packed,true)||(int)$bytes>67108864;
    }
    private function zipStoreIfPacked($zip,$name,$bytes){
        if(self::zipEntryShouldStore($name,$bytes)&&method_exists($zip,'setCompressionName')&&defined('ZipArchive::CM_STORE'))@$zip->setCompressionName($name,ZipArchive::CM_STORE);
    }
    public function activeJob(){ if(!is_file($this->activeFile)) return null; $a=UMEUtil::jsonRead($this->activeFile,array()); if(empty($a['id']))return null; $job=$this->getJob($a['id']); if($job && $job['status']==='running'){ $updated=strtotime($job['updated_at']); if($updated && time()-$updated>$this->config['limits']['stale_seconds']){ $job['status']='stale'; $job['message']='Процесс не обновлялся более '.(int)$this->config['limits']['stale_seconds'].' секунд.'; $this->writeJob($job); } } return $job; }
    public function getJob($id){ if(!UMEUtil::safeId($id)) return null; $j=UMEUtil::jsonRead($this->jobFile($id),array()); return $j?$j:null; }
    private function assertNoActive(){ $a=$this->activeJob(); if($a && in_array($a['status'],array('running','stale','cancelling','rolling_back'),true)) throw new Exception('Уже выполняется процесс: '.$a['id']); }
    private function newJob($type,$data){
        $this->assertNoActive(); $id=date('Ymd-His').'-'.substr(UMEUtil::randomHex(6),0,12);
        $job=array('id'=>$id,'type'=>$type,'status'=>'running','phase'=>'created','progress'=>0,'message'=>'Процесс создан','created_at'=>UMEUtil::now(),'updated_at'=>UMEUtil::now(),'cancel_requested'=>false,'changes_started'=>false,'data'=>$data);
        UMEUtil::ensureDir($this->storage.'/temp/'.$id); $this->writeJob($job); $this->log($job,'Создан процесс '.$type,'INFO'); return $job;
    }
    public function startBackup(){ $s=$this->settings();return $this->startBackupProfile($s['file_mode'],$s['database_mode'],'manual'); }
    public function startBackupProfile($fileMode,$databaseMode,$trigger){
        $fileMode=(string)$fileMode;$databaseMode=(string)$databaseMode;$trigger=(string)$trigger;
        if(!$this->validFileMode($fileMode))throw new Exception('Неизвестный режим файлового бэкапа.');
        if(!$this->validDatabaseMode($databaseMode))throw new Exception('Неизвестный режим бэкапа БД.');
        if($fileMode==='none'&&$databaseMode==='none')throw new Exception('Нельзя создать пустой бэкап: выберите файлы, БД или оба раздела.');
        return $this->newJob('backup',array('file_mode'=>$fileMode,'database_mode'=>$databaseMode,'trigger'=>$trigger!==''?$trigger:'manual','strict_database'=>$trigger==='cron'&&$databaseMode!=='none','scan_queue'=>array(''),'files_count'=>0,'code_bytes'=>0,'file_index'=>0,'table_index'=>0,'row_offset'=>0,'rows_done'=>0,'rows_total'=>0,'public_sanitized_count'=>0,'public_sanitized_reasons'=>array()));
    }
    public function startBackupRestore($backupId){
        if(!UMEUtil::safeId($backupId))throw new Exception('Некорректный ID резервной копии.');$file=$this->storage.'/backups/'.$backupId;if(!is_file($file))throw new Exception('Резервная копия не найдена.');
        return $this->newJob('backup_restore',array('backup_id'=>$backupId,'backup_file'=>$backupId,'scan_queue'=>array(''),'scan_current'=>null,'scan_count'=>0,'file_index'=>0,'delete_index'=>0,'verify_index'=>0,'db_safety_table_index'=>0,'db_safety_offset'=>0,'db_restore_index'=>0,'rollback_original_index'=>0,'rollback_absent_index'=>0,'rollback_db_index'=>0));
    }
    public function resume($id){ $job=$this->getJob($id); if(!$job)throw new Exception('Процесс не найден.'); if($job['status']==='stale'){ $job['status']='running'; $job['message']='Процесс продолжен пользователем'; $this->log($job,'Процесс продолжен пользователем','INFO'); $this->writeJob($job); } return $this->publicJob($job); }
    public function cancel($id){
        $job=$this->getJob($id);if(!$job)throw new Exception('Процесс не найден.');
        if($job['type']==='rollback'&&$job['phase']!=='created')throw new Exception('Откат уже восстанавливает сайт. На этом критическом этапе прерывание отключено, чтобы не оставить файлы или БД в промежуточном состоянии.');
        if(!in_array($job['status'],array('running','stale','cancelling','rolling_back'),true))return $this->publicJob($job);
        if(!empty($job['cancel_requested']))return $this->publicJob($job);
        @file_put_contents($this->cancelFile($job['id']),UMEUtil::now(),LOCK_EX);$job['cancel_requested']=true;$job['data']['cancelled_by_user']=true;$job['status']='cancelling';
        if($job['type']==='backup_restore'&&!empty($job['changes_started']))$job['message']='Прерываем восстановление. Сначала безопасно возвращаем состояние до запуска.';
        else if($job['type']==='install'&&!empty($job['changes_started']))$job['message']='Прерываем установку. Сначала безопасно откатываем уже внесённые изменения.';
        else $job['message']='Запрошено прерывание процесса';
        $this->log($job,'Пользователь запросил прерывание процесса','WARN');$this->writeJob($job);return $this->publicJob($job);
    }
    public function step($id){
        $lockPath=$this->storage.'/locks/global.lock'; $fh=fopen($lockPath,'c+'); if(!$fh||!flock($fh,LOCK_EX|LOCK_NB)) return $this->publicJob($this->getJob($id));
        try{
            $job=$this->getJob($id); if(!$job)throw new Exception('Процесс не найден.');
            if(is_file($this->cancelFile($job['id']))){$job['cancel_requested']=true;$job['data']['cancelled_by_user']=true;if(in_array($job['status'],array('running','stale'),true)){$job['status']='cancelling';$job['message']='Запрошено прерывание процесса';}}
            if(!empty($job['cancel_requested'])){
                if($job['type']==='backup'||($job['type']==='backup_restore'&&empty($job['changes_started']))||($job['type']==='rollback'&&$job['phase']==='created')){
                    $this->cleanupJobTemp($job);$job['status']='cancelled';$job['phase']='cancelled';$job['progress']=100;$job['message']='Процесс прерван пользователем';$this->log($job,'Процесс прерван пользователем','WARN');$this->writeJob($job);return $this->publicJob($job);
                }
                if($job['type']==='backup_restore'&&!empty($job['changes_started'])&&strpos($job['phase'],'restore_rollback_')!==0){
                    $job['status']='rolling_back';$job['phase']='restore_rollback_files';$job['message']='Прерывание принято. Возвращаем файлы и БД в состояние до запуска восстановления.';$job['data']['rollback_original_index']=0;$job['data']['rollback_absent_index']=0;$job['data']['rollback_db_index']=0;$this->log($job,'Начат безопасный откат после прерывания восстановления','WARN');$this->writeJob($job);return $this->publicJob($job);
                }
            }
            if($job['type']==='backup') $job=$this->stepBackup($job);
            else if($job['type']==='backup_restore') $job=$this->stepBackupRestore($job);
            else if($job['type']==='install') $job=$this->stepInstall($job);
            else if($job['type']==='rollback') $job=$this->stepRollback($job);
            else throw new Exception('Неизвестный тип процесса.');
            $this->writeJob($job); return $this->publicJob($job);
        }catch(Exception $e){return $this->handleStepFailure($job,$e);}
        catch(Throwable $e){return $this->handleStepFailure($job,$e);}
        finally{ if($fh){flock($fh,LOCK_UN);fclose($fh);} }
    }
    private function handleStepFailure($job,$e){
        $message=is_object($e)&&method_exists($e,'getMessage')?$e->getMessage():'Неизвестная ошибка процесса';
        if(isset($job)&&is_array($job)){
            $this->log($job,$message,'ERROR');
            $mayHaveChanged=$job['type']==='install' && (!empty($job['changes_started']) || in_array($job['phase'],array('apply_files','delete_files','migrations','post_actions','verify'),true));
            $restoreChanged=$job['type']==='backup_restore'&&!empty($job['changes_started'])&&strpos($job['phase'],'restore_rollback_')!==0;
            if($mayHaveChanged && $job['phase']!=='rollback_files'){ $job['status']='rolling_back'; $job['phase']='rollback_files'; $job['message']='Ошибка: '.$message.'. Выполняется автоматический откат.'; $job['data']['last_error']=$message; }
            else if($restoreChanged){$job['status']='rolling_back';$job['phase']='restore_rollback_files';$job['message']='Ошибка восстановления: '.$message.'. Возвращаем состояние до запуска.';$job['data']['last_error']=$message;$job['data']['rollback_original_index']=0;$job['data']['rollback_absent_index']=0;$job['data']['rollback_db_index']=0;}
            else { $job['status']='failed'; $job['phase']='failed'; $job['message']=$message; }
            $this->writeJob($job);
            if($job['status']==='rolling_back') return $this->publicJob($job);
        }
        throw $e;
    }
    public function publicJob($job){
        if(!$job)return null;$p=$job;unset($p['data']['files']);
        if(isset($p['data'])&&is_array($p['data']))foreach(array('db_password','share_token','source_db_password') as $secretKey)if(isset($p['data'][$secretKey]))unset($p['data'][$secretKey]);
        $active=in_array($job['status'],array('running','stale'),true);$cancelAllowed=$active&&empty($job['cancel_requested']);
        if($job['type']==='rollback'&&$job['phase']!=='created')$cancelAllowed=false;
        if($job['type']==='backup_restore'&&strpos($job['phase'],'restore_rollback_')===0)$cancelAllowed=false;
        $p['cancel_allowed']=$cancelAllowed;$p['log_tail']=UMEUtil::tail($this->logFile($job['id']),$this->config['limits']['log_tail_lines']);return $p;
    }
    private function backupGlobRegex($pattern){
        $quoted=preg_quote($pattern,'#');$quoted=str_replace(array('\*','\?'),array('.*','.'),$quoted);return '#^'.$quoted.'$#i';
    }
    private function userBackupExcluded($rel,$isDir,$rules=null){
        $rel=trim(str_replace('\\','/',(string)$rel),'/');if($rel==='')return false;$base=basename($rel);if($rules===null){$settings=$this->settings();$rules=isset($settings['backup_exclusions'])&&is_array($settings['backup_exclusions'])?$settings['backup_exclusions']:array();}if(!is_array($rules))$rules=array();
        foreach($rules as $raw){
            $rule=trim(str_replace('\\','/',(string)$raw));if($rule==='')continue;$anchored=substr($rule,0,1)==='/';$dirRule=substr($rule,-1)==='/';$rulePath=trim($rule,'/');if($rulePath==='')continue;
            if($dirRule){
                if($anchored||strpos($rulePath,'/')!==false){if($rel===$rulePath||UMEUtil::startsWith($rel,$rulePath.'/'))return true;}
                else {foreach(explode('/',$rel) as $part)if(strcasecmp($part,$rulePath)===0)return true;}
                continue;
            }
            $hasGlob=strpos($rulePath,'*')!==false||strpos($rulePath,'?')!==false;
            if($hasGlob){
                $target=($anchored||strpos($rulePath,'/')!==false)?$rel:$base;if(preg_match($this->backupGlobRegex($rulePath),$target))return true;continue;
            }
            if($anchored||strpos($rulePath,'/')!==false){if(strcasecmp($rel,$rulePath)===0)return true;}
            else if(strcasecmp($base,$rulePath)===0)return true;
        }
        return false;
    }
    private function excludedDir($rel,$rules=null){
        $rel=trim(str_replace('\\','/',$rel),'/');if($this->userBackupExcluded($rel,true,$rules))return true;foreach($this->config['excluded_directories'] as $e){$e=trim($e,'/');if($rel===$e||UMEUtil::startsWith($rel,$e.'/'))return true;}return false;
    }
    private function protectedReadPath($rel){
        $rel=trim(str_replace('\\','/',$rel),'/');$paths=isset($this->config['protected_read_paths'])&&is_array($this->config['protected_read_paths'])?$this->config['protected_read_paths']:array('upgrade/config.local.php','.env','.git');
        foreach($paths as $p){$p=trim(str_replace('\\','/',$p),'/');if($p!==''&&($rel===$p||UMEUtil::startsWith($rel,$p.'/')))return true;}return false;
    }
    private function includeFile($rel,$rules=null){
        $rel=str_replace('\\','/',$rel);if($this->userBackupExcluded($rel,false,$rules)||$this->protectedReadPath($rel))return false;$base=basename($rel);if(in_array($base,$this->config['code_filenames'],true))return true;
        $abs=$this->root.'/'.$rel;if(is_file($abs)&&isset($this->config['max_code_file_bytes'])&&filesize($abs)>$this->config['max_code_file_bytes'])return false;
        $ext=strtolower(pathinfo($base,PATHINFO_EXTENSION));if(in_array($ext,$this->config['excluded_extensions'],true))return false;return in_array($ext,$this->config['code_extensions'],true);
    }
    private function validLegacyBackupMode($mode){return in_array((string)$mode,array('code','schema','compact','full','complete'),true);}
    private function legacyBackupFileScope($mode){return $mode==='complete'?'code-images':'code';}
    private function pathMatchesTree($rel,$tree){$rel=trim(str_replace('\\','/',(string)$rel),'/');$tree=trim(str_replace('\\','/',(string)$tree),'/');return $tree!==''&&($rel===$tree||UMEUtil::startsWith($rel,$tree.'/')||UMEUtil::startsWith($tree,$rel.'/'));}
    private function isMediaTree($rel){$dirs=isset($this->config['media_directories'])&&is_array($this->config['media_directories'])?$this->config['media_directories']:array();foreach($dirs as $dir)if($this->pathMatchesTree($rel,$dir))return true;return false;}
    private function isCacheTree($rel){$dirs=isset($this->config['cache_directories'])&&is_array($this->config['cache_directories'])?$this->config['cache_directories']:array();$rel=trim(str_replace('\\','/',(string)$rel),'/');foreach($dirs as $dir){$dir=trim(str_replace('\\','/',(string)$dir),'/');if($dir!==''&&($rel===$dir||UMEUtil::startsWith($rel,$dir.'/')))return true;}return false;}
    private function isCmsCorePath($rel){
        $rel=trim(str_replace('\\','/',(string)$rel),'/');$dirs=isset($this->config['cms_core_directories'])&&is_array($this->config['cms_core_directories'])?$this->config['cms_core_directories']:array();foreach($dirs as $dir){$dir=trim(str_replace('\\','/',(string)$dir),'/');if($dir!==''&&($rel===$dir||UMEUtil::startsWith($rel,$dir.'/')))return true;}
        $files=isset($this->config['cms_core_files'])&&is_array($this->config['cms_core_files'])?$this->config['cms_core_files']:array();foreach($files as $file){$file=trim(str_replace('\\','/',(string)$file),'/');if($file!==''&&$rel===$file)return true;}return false;
    }
    private function excludedDirForBackup($rel,$fileMode,$rules=null){
        $rel=trim(str_replace('\\','/',$rel),'/');if($fileMode==='none'||$this->userBackupExcluded($rel,true,$rules))return true;if($this->isCacheTree($rel))return true;if($this->protectedReadPath($rel)&&!$this->isMediaTree($rel))return true;
        if($fileMode==='all'&&$this->isMediaTree($rel)){foreach(array('upgrade','.git','.svn','node_modules') as $hard)if($rel===$hard||UMEUtil::startsWith($rel,$hard.'/'))return true;return false;}
        if($this->excludedDir($rel,$rules))return true;if($fileMode==='necessary'&&$this->isCmsCorePath($rel))return true;return false;
    }
    private function includeFileForBackup($rel,$fileMode,$rules=null){
        $rel=str_replace('\\','/',$rel);if($fileMode==='none'||$this->userBackupExcluded($rel,false,$rules))return false;
        if($fileMode==='all'){if($this->protectedReadPath($rel)&&!$this->isMediaTree($rel))return false;return true;}
        if(!$this->includeFile($rel,$rules))return false;if($fileMode==='necessary'&&$this->isCmsCorePath($rel))return false;return true;
    }
    private function excludedDirForLegacyBackup($rel,$mode,$rules=null){
        $rel=trim(str_replace('\\','/',$rel),'/');if($this->userBackupExcluded($rel,true,$rules))return true;if($mode==='complete'&&$this->isMediaTree($rel)){if($this->isCacheTree($rel))return true;foreach(array('upgrade','.git','.svn','node_modules','vendor') as $hard)if($rel===$hard||UMEUtil::startsWith($rel,$hard.'/'))return true;return false;}return $this->excludedDir($rel,$rules);
    }
    private function includeFileForLegacyBackup($rel,$mode,$rules=null){
        if($this->includeFile($rel,$rules))return true;if($mode!=='complete'||$this->userBackupExcluded($rel,false,$rules))return false;$rel=str_replace('\\','/',$rel);$base=basename($rel);$ext=strtolower(pathinfo($base,PATHINFO_EXTENSION));$images=isset($this->config['image_extensions'])&&is_array($this->config['image_extensions'])?$this->config['image_extensions']:array('jpg','jpeg','png','gif','webp','bmp','ico','avif','svg');if(!in_array($ext,$images,true))return false;if($this->protectedReadPath($rel)&&!$this->isMediaTree($rel))return false;return true;
    }
    private function restoreExcludedDir($rel,$job,$rules){if(!empty($job['data']['legacy_backup_mode']))return $this->excludedDirForLegacyBackup($rel,$job['data']['legacy_backup_mode'],$rules);return $this->excludedDirForBackup($rel,isset($job['data']['file_mode'])?$job['data']['file_mode']:'code_cms',$rules);}
    private function restoreIncludeFile($rel,$job,$rules){if(!empty($job['data']['legacy_backup_mode']))return $this->includeFileForLegacyBackup($rel,$job['data']['legacy_backup_mode'],$rules);return $this->includeFileForBackup($rel,isset($job['data']['file_mode'])?$job['data']['file_mode']:'code_cms',$rules);}
    private function modeOmitsTableData($mode,$table,$prefix){if($mode==='schema')return true;if($mode==='minimal'||$mode==='compact')return $this->compactExcludesData($table,$prefix);return false;}
    private function compactExcludesData($table,$prefix){
        $prefix=(string)$prefix;$name=$prefix!==''&&UMEUtil::startsWith($table,$prefix)?substr($table,strlen($prefix)):$table;
        if(isset($this->config['compact_include_data_tables']) && in_array($name,$this->config['compact_include_data_tables'],true))return false;
        foreach($this->config['compact_exclude_data_patterns'] as $pattern){ $regex='#^'.str_replace(array('%','_'),array('.*','.'),preg_quote($pattern,'#')).'$#i'; if(preg_match($regex,$name))return true; }
        return false;
    }
    private function publicSharePathReason($rel){
        $rel=trim(str_replace('\\','/',(string)$rel),'/');$lower=strtolower($rel);$base=strtolower(basename($rel));
        $exact=array('.env','.env.local','.env.production','.env.development','.env.testing','config.local.php','auth.json','composer.auth.json','api-authorizations.json','api-settings.json','applied.json','rollback-index.json','history.json','id_rsa','id_ed25519','id_ecdsa');
        if(in_array($base,$exact,true)||UMEUtil::startsWith($base,'.env.')||preg_match('/\.(?:pem|key|p12|pfx)$/i',$base))return 'secret-config';
        if(preg_match('/(?:^|[._-])(?:secret|secrets|credentials|tokens?|oauth)(?:[._-]|$).*\.json$/i',$base))return 'secret-config';
        foreach($this->publicShareKnownConfigPaths() as $known){if(strcasecmp($rel,$known)===0)return 'secret-config';}
        if(preg_match('#(^|/)(?:cache|caches|tmp|temp|logs|sessions)(/|$)#i',$lower))return 'runtime';
        if(preg_match('#(^|/)admin/system/(?:export|jobs)(/|$)#i',$lower))return 'runtime';
        if(preg_match('#(^|/)(?:storage/logs|storage/framework/(?:cache|sessions|views)|var/(?:cache|log|session|tmp))(/|$)#i',$lower))return 'runtime';
        if(preg_match('#(^|/)\.data(?:-[^/]+)?(/|$)#i',$lower))return 'runtime';
        if(preg_match('#(^|/)oauth/.*\.(?:json|token|cache)$#i',$lower))return 'runtime';
        if(preg_match('#(^|/)jobs/.*\.(?:json|log|tmp|cache)$#i',$lower))return 'runtime';
        return '';
    }
    // 3.6.3: well-known CMS configuration files and the files in which the static DB detector found
    // credentials are never published, even if their syntax is not recognised by the content scanner.
    private $publicShareKnownConfigCache=null;
    private function publicShareKnownConfigPaths(){
        if(is_array($this->publicShareKnownConfigCache))return $this->publicShareKnownConfigCache;
        $paths=array('wp-config.php','configuration.php','config.php','admin/config.php','bitrix/.settings.php','bitrix/.settings_extra.php','bitrix/php_interface/dbconn.php','app/etc/env.php','app/config/parameters.php','config/settings.inc.php','core/config/config.inc.php','wa-config/db.php','sites/default/settings.php','sites/default/settings.local.php');
        if(class_exists('UMECmsAdapter')){try{$db=UMECmsAdapter::databaseConfig($this->root,isset($this->cms['id'])?$this->cms['id']:'generic',false);if(!empty($db['source_files'])&&is_array($db['source_files']))foreach($db['source_files'] as $f){$f=UMEUtil::normalizeRelativePath($f);if($f!==false)$paths[]=$f;}}catch(Exception $e){}}
        $this->publicShareKnownConfigCache=array_values(array_unique($paths));return $this->publicShareKnownConfigCache;
    }
    private function publicShareSecretContentReason($rel,$abs){
        $ext=strtolower(pathinfo($rel,PATHINFO_EXTENSION));$size=@filesize($abs);if($size===false||$size>2097152)return '';
        $scanExt=array('php','phtml','inc','json','yml','yaml','ini','env','conf','config','txt','xml','sql','pem','key');if(!in_array($ext,$scanExt,true))return '';
        $raw=@file_get_contents($abs);if($raw===false)return '';
        if(strpos($raw,'-----BEGIN PRIVATE KEY-----')!==false||strpos($raw,'-----BEGIN RSA PRIVATE KEY-----')!==false||strpos($raw,'-----BEGIN OPENSSH PRIVATE KEY-----')!==false)return 'private-key';
        $key='(?:PASS(?:WORD)?[0-9]*|PASSWD|SECRET|TOKEN|API[_-]?KEY|PRIVATE[_-]?KEY|CLIENT[_-]?SECRET|HMAC[_-]?KEY|RECOVERY[_-]?KEY|BACKEND[_-]?MASTER[_-]?KEY|DB[_-]?PASS(?:WORD)?)';
        if(preg_match('~(?:define|[A-Za-z_][A-Za-z0-9_]*DefineConfig)\s*\(\s*[\'\"][A-Za-z0-9_]*'.$key.'[A-Za-z0-9_]*[\'\"]\s*,~i',$raw))return 'secret-literal';
        if(preg_match('~[\'\"][^\'\"]*'.$key.'[^\'\"]*[\'\"]\s*(?::|=>)\s*[\'\"][^\'\"]{4,}[\'\"]~i',$raw))return 'secret-literal';
        if(preg_match('~\$[A-Za-z0-9_]*'.$key.'[A-Za-z0-9_]*\s*=\s*[\'\"][^\'\"]{4,}[\'\"]~i',$raw))return 'secret-literal';
        // 3.6.3: array-element, property and constant assignments: $config['db_password'] = '...'; $this->password = '...'; const DB_PASS = '...';
        if(preg_match('~\[\s*[\'\"][^\'\"]*'.$key.'[^\'\"]*[\'\"]\s*\]\s*=\s*[\'\"][^\'\"]{4,}[\'\"]~i',$raw))return 'secret-literal';
        if(preg_match('~->\s*[A-Za-z0-9_]*'.$key.'[A-Za-z0-9_]*\s*=\s*[\'\"][^\'\"]{4,}[\'\"]~i',$raw))return 'secret-literal';
        if(preg_match('~\bconst\s+[A-Za-z0-9_]*'.$key.'[A-Za-z0-9_]*\s*=\s*[\'\"][^\'\"]{4,}[\'\"]~i',$raw))return 'secret-literal';
        if(preg_match('~^(?:[A-Za-z0-9_]*'.$key.'[A-Za-z0-9_]*)\s*=\s*[^\s#;]{4,}~mi',$raw))return 'secret-literal';
        if(preg_match('~(?:mysql|mariadb|pgsql|postgres(?:ql)?):\/\/[^:\s/]+:[^@\s/]+@~i',$raw))return 'credential-uri';
        if($ext==='sql'&&preg_match('/\b(?:INSERT\s+INTO|REPLACE\s+INTO|LOCK\s+TABLES)\b/i',$raw))return 'sql-data-dump';
        return '';
    }
    private function publicShareFileDecision($rel,$abs){
        $reason=$this->publicSharePathReason($rel);if($reason==='')$reason=$this->publicShareSecretContentReason($rel,$abs);return array('safe'=>$reason==='','reason'=>$reason);
    }
    private function publicShareRegisterSkip(&$job,$reason){
        $job['data']['public_sanitized_count']=isset($job['data']['public_sanitized_count'])?(int)$job['data']['public_sanitized_count']+1:1;if(!isset($job['data']['public_sanitized_reasons'])||!is_array($job['data']['public_sanitized_reasons']))$job['data']['public_sanitized_reasons']=array();if(!isset($job['data']['public_sanitized_reasons'][$reason]))$job['data']['public_sanitized_reasons'][$reason]=0;$job['data']['public_sanitized_reasons'][$reason]++;
    }
    private function publicShareSchemaSql($source,$target){
        if(!is_file($source))return false;$inInsert=false;$in=@fopen($source,'rb');$out=@fopen($target,'wb');if(!$in||!$out){if($in)fclose($in);if($out)fclose($out);throw new Exception('Не удалось подготовить публичную схему БД.');}
        while(($line=fgets($in))!==false){$trim=ltrim($line);if(!$inInsert&&preg_match('/^(?:INSERT|REPLACE)\s+INTO\b/i',$trim)){$inInsert=true;if(substr(rtrim($line),-1)===';')$inInsert=false;continue;}if($inInsert){if(substr(rtrim($line),-1)===';')$inInsert=false;continue;}fwrite($out,$line);}fclose($in);fclose($out);return true;
    }
    private function publicShareCmsInfo(){
        return array('id'=>isset($this->cms['id'])?$this->cms['id']:'generic','name'=>isset($this->cms['name'])?$this->cms['name']:'','version'=>isset($this->cms['version'])?$this->cms['version']:'','supported'=>!isset($this->cms['supported'])||!empty($this->cms['supported']),'database_engine'=>isset($this->cms['database_engine'])?$this->cms['database_engine']:'unknown','database_detected'=>!empty($this->cms['database_detected']));
    }
    private function readNdjson($file){
        $out=array(); if(!is_file($file))return $out; $lines=@file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); if(!is_array($lines))return $out;
        foreach($lines as $line){$row=json_decode($line,true);if(is_array($row))$out[]=$row;} return $out;
    }
    private function appendNdjsonBatch($file,$rows){
        if(!$rows)return; $buf=''; foreach($rows as $row){$j=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($j!==false)$buf.=$j."\n";} if($buf!=='')file_put_contents($file,$buf,FILE_APPEND|LOCK_EX);
    }
    private function stepBackup($job){
        $tmp=$this->storage.'/temp/'.$job['id'];$limit=$this->config['limits'];$fileMode=$job['data']['file_mode'];$databaseMode=$job['data']['database_mode'];
        $timeBudget=isset($limit['step_time_budget_seconds'])?(float)$limit['step_time_budget_seconds']:1.6;$scanLimit=isset($limit['scan_entries_per_step'])?(int)$limit['scan_entries_per_step']:1200;$archiveLimit=isset($limit['archive_files_per_step'])?(int)$limit['archive_files_per_step']:500;$dbOpsLimit=isset($limit['db_operations_per_step'])?(int)$limit['db_operations_per_step']:40;
        if($job['phase']==='created'){
            @unlink($tmp.'/files.ndjson');@unlink($tmp.'/files-manifest.ndjson');@unlink($tmp.'/files-manifest-public.ndjson');@unlink($tmp.'/site-files.zip');@unlink($tmp.'/site-files-public.zip');@unlink($tmp.'/database.sql');@unlink($tmp.'/database-public.sql');$job['data']['phase_started_at']=microtime(true);
            if($fileMode==='none'){$job['phase']=$databaseMode==='none'?'finalize':'db_init';$job['progress']=$databaseMode==='none'?92:45;$job['message']='Файлы отключены. Переходим к резервированию БД';$this->log($job,'Файловая часть отключена настройками','INFO');return $job;}
            $job['phase']='scan';$job['message']=$fileMode==='all'?'Сканирование всех файлов':($fileMode==='necessary'?'Сканирование нужных файлов без ядра CMS':'Сканирование кода и ядра CMS');$job['progress']=2;$this->log($job,'Начато быстрое сканирование файлов; режим '.$fileMode,'INFO');return $job;
        }
        if($job['phase']==='scan'){
            $deadline=microtime(true)+$timeBudget;$processed=0;$found=array();
            while($processed<$scanLimit&&microtime(true)<$deadline){
                if(empty($job['data']['scan_current'])){if(empty($job['data']['scan_queue']))break;$rel=array_shift($job['data']['scan_queue']);$abs=$this->root.($rel===''?'':'/'.$rel);$list=@scandir($abs);if(!is_array($list)){$this->log($job,'Пропущен недоступный каталог: '.$rel,'WARN');continue;}$entries=array();foreach($list as $name)if($name!=='.'&&$name!=='..')$entries[]=$name;$job['data']['scan_current']=array('dir'=>$rel,'entries'=>$entries,'index'=>0);}
                $cur=&$job['data']['scan_current'];if($cur['index']>=count($cur['entries'])){unset($job['data']['scan_current']);continue;}$name=$cur['entries'][$cur['index']++];$rel=$cur['dir'];$child=UMEUtil::normalizeRelativePath($rel===''?$name:$rel.'/'.$name);if($child===false){$processed++;continue;}$ca=$this->root.'/'.$child;if(is_link($ca)){$processed++;continue;}
                if(is_dir($ca)){if(!$this->excludedDirForBackup($child,$fileMode))$job['data']['scan_queue'][]=$child;}else if(is_file($ca)&&$this->includeFileForBackup($child,$fileMode)){$size=@filesize($ca);$found[]=array('path'=>$child,'size'=>$size===false?0:(int)$size);$job['data']['files_count']++;$job['data']['code_bytes']+=($size===false?0:(int)$size);}$processed++;
            }
            $this->appendNdjsonBatch($tmp.'/files.ndjson',$found);$count=(int)$job['data']['files_count'];$job['message']='Сканирование: найдено '.$count.' файлов';$job['progress']=8;
            if(empty($job['data']['scan_queue'])&&empty($job['data']['scan_current'])){$job['data']['file_index']=0;$job['phase']='archive';$job['progress']=12;$elapsed=isset($job['data']['phase_started_at'])?round(microtime(true)-$job['data']['phase_started_at'],2):0;$job['data']['phase_started_at']=microtime(true);$this->log($job,'Сканирование завершено: '.$count.' файлов за '.$elapsed.' сек.','INFO');}return $job;
        }
        if($job['phase']==='archive'){
            if(!class_exists('ZipArchive'))throw new Exception('Расширение ZipArchive недоступно.');$lines=@file($tmp.'/files.ndjson',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if(!is_array($lines))$lines=array();$total=count($lines);$start=(int)$job['data']['file_index'];
            $zip=new ZipArchive();$zfile=$tmp.'/site-files.zip';if($zip->open($zfile,ZipArchive::CREATE)!==true)throw new Exception('Не удалось открыть ZIP файлов.');$publicZip=new ZipArchive();$publicZfile=$tmp.'/site-files-public.zip';if($publicZip->open($publicZfile,ZipArchive::CREATE)!==true){$zip->close();throw new Exception('Не удалось открыть публичный ZIP файлов.');}
            $deadline=microtime(true)+$timeBudget;$i=$start;$manifestRows=array();$publicRows=array();$added=0;$queuedBytes=0;$byteLimit=isset($limit['archive_bytes_per_step'])?max(1048576,(int)$limit['archive_bytes_per_step']):25165824;if(function_exists('set_time_limit'))@set_time_limit(120);
            while($i<$total&&$added<$archiveLimit&&microtime(true)<$deadline){$row=json_decode($lines[$i],true);$rowBytes=is_array($row)&&isset($row['size'])?max(0,(int)$row['size']):0;if($added>0&&$queuedBytes+$rowBytes>$byteLimit)break;$i++;if(!is_array($row)||empty($row['path']))continue;$path=$row['path'];$abs=$this->root.'/'.$path;if(!is_file($abs))continue;$sha=hash_file('sha256',$abs);if($sha===false)throw new Exception('Не удалось вычислить SHA-256: '.$path);if(!$zip->addFile($abs,$path))throw new Exception('Не удалось добавить в ZIP: '.$path);$this->zipStoreIfPacked($zip,$path,$rowBytes);$manifestRows[]=array('path'=>$path,'size'=>(int)$row['size'],'sha256'=>$sha);
                $decision=$this->publicShareFileDecision($path,$abs);if(!empty($decision['safe'])){if(!$publicZip->addFile($abs,$path))throw new Exception('Не удалось добавить файл в публичный ZIP: '.$path);$this->zipStoreIfPacked($publicZip,$path,$rowBytes);$publicRows[]=array('path'=>$path,'size'=>(int)$row['size'],'sha256'=>$sha);}else{$this->publicShareRegisterSkip($job,$decision['reason']);}
                $added++;$queuedBytes+=$rowBytes;}
            if(!$zip->close())throw new Exception('Не удалось завершить ZIP файлов. Возможно, закончилось место на диске.');if(!$publicZip->close())throw new Exception('Не удалось завершить публичный ZIP файлов. Возможно, закончилось место на диске.');$this->appendNdjsonBatch($tmp.'/files-manifest.ndjson',$manifestRows);$this->appendNdjsonBatch($tmp.'/files-manifest-public.ndjson',$publicRows);$job['data']['file_index']=$i;$job['progress']=12+($total?(int)floor(33*$i/$total):33);$job['message']='Архивирование файлов: '.$i.' из '.$total;
            if($i>=$total){$job['phase']=$databaseMode==='none'?'finalize':'db_init';$job['progress']=$databaseMode==='none'?92:45;$elapsed=isset($job['data']['phase_started_at'])?round(microtime(true)-$job['data']['phase_started_at'],2):0;$job['data']['phase_started_at']=microtime(true);$this->log($job,'ZIP файлов сформирован за '.$elapsed.' сек.; публичная копия исключила '.(int)$job['data']['public_sanitized_count'].' файлов','INFO');}return $job;
        }
        if($job['phase']==='db_init'){
            try{$db=$this->database();$tables=$db->listTables();$prefix=$db->prefix();}catch(Exception $e){if($fileMode==='none'||!empty($job['data']['strict_database']))throw new Exception('Полный автоматический backup не создан: БД недоступна: '.$e->getMessage());$job['data']['db_skipped_reason']=$e->getMessage();$job['data']['database_included']=false;$job['phase']='finalize';$job['progress']=92;$job['message']='Файловый backup готов; БД пропущена';$this->log($job,'БД пропущена: '.$e->getMessage(),'WARN');return $job;}
            $total=0;foreach($tables as $t){if($this->modeOmitsTableData($databaseMode,$t['name'],$prefix))continue;$total+=max(0,(int)$t['rows']);}$job['data']['tables']=$tables;$job['data']['db_prefix']=$prefix;$job['data']['database_included']=true;$job['data']['rows_total']=$total;$job['data']['table_index']=0;$job['data']['row_offset']=0;$job['data']['rows_done']=0;
            $this->writeExact($tmp.'/database.sql',"-- Upgrade Manager Universal database dump\n-- CMS: ".$this->cms['name']."\n-- Database mode: ".$databaseMode."\n-- Created: ".UMEUtil::now()."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n",0,'SQL-дамп');$job['phase']='db_dump';$job['message']='Быстрый дамп БД: подготовлено '.count($tables).' таблиц';$job['progress']=46;$job['data']['phase_started_at']=microtime(true);$this->log($job,'Начат быстрый дамп БД в режиме '.$databaseMode,'INFO');return $job;
        }
        if($job['phase']==='db_dump'){
            $db=$this->database();$tables=$job['data']['tables'];$deadline=microtime(true)+$timeBudget;$ops=0;$lastTable='';
            while((int)$job['data']['table_index']<count($tables)&&$ops<$dbOpsLimit&&microtime(true)<$deadline){$idx=(int)$job['data']['table_index'];$table=$tables[$idx]['name'];$lastTable=$table;$offset=(int)$job['data']['row_offset'];if($offset===0){$create=$db->createSql($table);$this->writeExact($tmp.'/database.sql','DROP TABLE IF EXISTS '.UMEUtil::sqlIdentifier($table).";\n".$create.";\n\n",FILE_APPEND,'SQL-дамп');$ops++;}$skip=$this->modeOmitsTableData($databaseMode,$table,$db->prefix());if($skip){$this->writeExact($tmp.'/database.sql',"-- Data omitted in ".$databaseMode." mode for ".$table."\n\n",FILE_APPEND,'SQL-дамп');$job['data']['table_index']=$idx+1;$job['data']['row_offset']=0;$this->log($job,'Таблица без данных: '.$table,'INFO');continue;}$rows=$db->rowsChunk($table,$offset,$limit['db_rows_per_step']);$ops++;if($rows){$this->writeExact($tmp.'/database.sql',$db->insertSql($table,$rows)."\n",FILE_APPEND,'SQL-дамп');$count=count($rows);$job['data']['row_offset']=$offset+$count;$job['data']['rows_done']+=$count;}if(count($rows)<$limit['db_rows_per_step']){$job['data']['table_index']=$idx+1;$job['data']['row_offset']=0;$this->log($job,'Таблица выгружена: '.$table,'INFO');}}
            $idx=(int)$job['data']['table_index'];if($idx>=count($tables)){$this->writeExact($tmp.'/database.sql',"\nSET FOREIGN_KEY_CHECKS=1;\n",FILE_APPEND,'SQL-дамп');$job['phase']='finalize';$job['progress']=92;$elapsed=isset($job['data']['phase_started_at'])?round(microtime(true)-$job['data']['phase_started_at'],2):0;$job['data']['phase_started_at']=microtime(true);$this->log($job,'Дамп БД завершён за '.$elapsed.' сек.','INFO');return $job;}$tablePart=count($tables)?$idx/count($tables):1;$rowPart=$job['data']['rows_total']?min(1,$job['data']['rows_done']/$job['data']['rows_total']):1;$job['progress']=46+(int)floor(45*(0.45*$tablePart+0.55*$rowPart));$job['message']='Дамп БД: '.$lastTable.'; таблица '.min($idx+1,count($tables)).' из '.count($tables);return $job;
        }
        if($job['phase']==='finalize'){
            if(!class_exists('ZipArchive'))throw new Exception('Расширение ZipArchive недоступно.');
            foreach(array('site-files.zip','site-files-public.zip') as $zf)if(!is_file($tmp.'/'.$zf)){$empty=new ZipArchive();if($empty->open($tmp.'/'.$zf,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new Exception('Не удалось создать пустой файловый ZIP.');$empty->close();}
            $files=$this->readNdjson($tmp.'/files-manifest.ndjson');$publicFiles=$this->readNdjson($tmp.'/files-manifest-public.ndjson');$dbIncluded=is_file($tmp.'/database.sql');$dbSha=$dbIncluded?hash_file('sha256',$tmp.'/database.sql'):'';$siteSha=hash_file('sha256',$tmp.'/site-files.zip');if($siteSha===false)throw new Exception('Не удалось вычислить SHA-256 файлового архива.');$backupExclusions=$this->settings()['backup_exclusions'];$scopeSha=hash('sha256',UMEUtil::canonicalJson(array('file_mode'=>$fileMode,'database_mode'=>$databaseMode,'backup_exclusions'=>$backupExclusions)));
            $siteVersionNow=$this->siteVersion();$manifest=array('format'=>'external-code-backup/1.5','backup_id'=>$job['id'],'site_id'=>$this->config['site_id'],'project'=>$this->config['project'],'domain'=>UMEUtil::domain(),'created_at'=>UMEUtil::now(),'file_mode'=>$fileMode,'database_mode'=>$databaseMode,'dump_mode'=>$this->legacyModeFromPair($fileMode,$databaseMode),'file_scope'=>$fileMode,'files_count'=>count($files),'database_included'=>$dbIncluded,'database_sha256'=>$dbSha,'site_files_sha256'=>$siteSha,'backup_exclusions'=>$backupExclusions,'scope_sha256'=>$scopeSha,'site_version'=>$siteVersionNow['version'],'next_patch_version'=>$siteVersionNow['next_version']);$manifest['signature']=hash_hmac('sha256',$manifest['backup_id'].'|'.$manifest['site_id'].'|'.$dbSha.'|'.$siteSha.'|'.$scopeSha.'|'.$manifest['site_version'].'|'.$manifest['next_patch_version'],$this->config['hmac_key']);
            // Backup 3.7.1+: include full verified manager source and site-specific AI instructions, without secrets.
            $agentSnapshot=UMEBackupAgentPack::sourceSnapshot($this->upgradeDir);
            $backupProfile=$this->profile();
            $privateAgentFiles=UMEBackupAgentPack::rootFiles($manifest,$backupProfile,$agentSnapshot,false);
            $manifest['upgrade_source_included']=true;
            $manifest['upgrade_source_version']=$agentSnapshot['version'];
            $manifest['upgrade_source_files']=$agentSnapshot['count'];
            UMEUtil::jsonWriteAtomic($tmp.'/manifest.json',$manifest);UMEUtil::jsonWriteAtomic($tmp.'/files-manifest.json',$files);UMEUtil::jsonWriteAtomic($tmp.'/metadata.json',array('site_root'=>$this->root,'php_version'=>PHP_VERSION,'cms'=>$this->cms,'database_included'=>$dbIncluded,'database_skip_reason'=>isset($job['data']['db_skipped_reason'])?$job['data']['db_skipped_reason']:'','file_mode'=>$fileMode,'database_mode'=>$databaseMode,'file_scope'=>$fileMode,'minimal_database_policy'=>'pattern-filtered','excluded_data_patterns'=>$this->config['compact_exclude_data_patterns'],'backup_exclusions'=>$backupExclusions,'engine'=>'universal-backup-v3.7.1','upgrade_source_included'=>true,'upgrade_source_version'=>$agentSnapshot['version'],'upgrade_source_files'=>$agentSnapshot['count'],'patch_context_files'=>array('00_START_HERE_FOR_GPT.md','AGENTS.md','PATCH_AUTHORING_RU.md','PATCH_FORMAT_RU.md','AGENT_WORKFLOW_RU.md','patch-profile.json','PATCH_UNSIGNED_MANIFEST_TEMPLATE.json','PATCH_SIGNATURE_EXAMPLE.json','PATCH_BUILDER.py','upgrade-source-manifest.json')));UMEUtil::jsonWriteAtomic($tmp.'/patch-profile.json',$backupProfile);
            $final=$this->storage.'/backups/backup-'.$job['id'].'-files-'.$fileMode.'-db-'.$databaseMode.'.zip';$finalPart=$final.'.part';@unlink($finalPart);$zip=new ZipArchive();if($zip->open($finalPart,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new Exception('Не удалось создать итоговый ZIP.');foreach(array('manifest.json','files-manifest.json','metadata.json','site-files.zip','database.sql','patch-profile.json') as $f){if(!is_file($tmp.'/'.$f))continue;if(!$zip->addFile($tmp.'/'.$f,$f))throw new Exception('Ошибка добавления файла backup: '.$f);if($f==='site-files.zip'&&method_exists($zip,'setCompressionName')&&defined('ZipArchive::CM_STORE'))@$zip->setCompressionName($f,ZipArchive::CM_STORE);}
            UMEBackupAgentPack::addRootContext($zip,$privateAgentFiles);
            UMEBackupAgentPack::addSourceToZip($zip,$agentSnapshot);
            if(!$zip->close())throw new Exception('Не удалось завершить ZIP backup с исходниками Upgrade Manager.');
            $publicDbIncluded=$dbIncluded&&$this->publicShareSchemaSql($tmp.'/database.sql',$tmp.'/database-public.sql');$publicSiteSha=hash_file('sha256',$tmp.'/site-files-public.zip');if($publicSiteSha===false){@unlink($finalPart);throw new Exception('Не удалось вычислить SHA-256 публичного файлового архива.');}$publicDbSha=$publicDbIncluded?hash_file('sha256',$tmp.'/database-public.sql'):'';$sourceSha=hash_file('sha256',$finalPart);if($sourceSha===false){@unlink($finalPart);throw new Exception('Не удалось вычислить SHA-256 приватной резервной копии.');}
            $sanitizedReasons=isset($job['data']['public_sanitized_reasons'])&&is_array($job['data']['public_sanitized_reasons'])?$job['data']['public_sanitized_reasons']:array();$publicManifest=array('format'=>'external-code-backup-share/1.1','backup_id'=>$job['id'],'site_id'=>$this->config['site_id'],'project'=>$this->config['project'],'domain'=>UMEUtil::domain(),'created_at'=>UMEUtil::now(),'source_backup_sha256'=>$sourceSha,'source_file_mode'=>$fileMode,'source_database_mode'=>$databaseMode,'file_mode'=>$fileMode,'database_mode'=>$publicDbIncluded?'schema':'none','files_count'=>count($publicFiles),'database_included'=>$publicDbIncluded,'database_sha256'=>$publicDbSha,'site_files_sha256'=>$publicSiteSha,'site_version'=>$siteVersionNow['version'],'next_patch_version'=>$siteVersionNow['next_version'],'upgrade_source_included'=>true,'upgrade_source_version'=>$agentSnapshot['version'],'upgrade_source_files'=>$agentSnapshot['count'],'public_sanitized'=>true,'sanitizer_version'=>'1.0','sanitized_files_count'=>(int)$job['data']['public_sanitized_count'],'sanitized_reasons'=>$sanitizedReasons);
            UMEUtil::jsonWriteAtomic($tmp.'/manifest-public.json',$publicManifest);UMEUtil::jsonWriteAtomic($tmp.'/files-manifest-public.json',$publicFiles);UMEUtil::jsonWriteAtomic($tmp.'/metadata-public.json',array('php_version'=>PHP_VERSION,'cms'=>$this->publicShareCmsInfo(),'source_file_mode'=>$fileMode,'source_database_mode'=>$databaseMode,'public_database_mode'=>$publicDbIncluded?'schema':'none','database_included'=>$publicDbIncluded,'public_sanitized'=>true,'sanitizer_version'=>'1.0','sanitized_files_count'=>(int)$job['data']['public_sanitized_count'],'sanitized_reasons'=>$sanitizedReasons,'policy'=>array('secret_bearing_files'=>'excluded','runtime_files'=>'excluded','standalone_sql_data_dumps'=>'excluded','database_data'=>'removed; schema only'),'engine'=>'universal-public-backup-v3.7.1','upgrade_source_included'=>true,'upgrade_source_version'=>$agentSnapshot['version'],'upgrade_source_files'=>$agentSnapshot['count']));
            $publicProfile=$this->profile();$publicProfile['public_share']=array('sanitized'=>true,'database_mode'=>$publicDbIncluded?'schema':'none','secret_files_excluded'=>true,'runtime_files_excluded'=>true);UMEUtil::jsonWriteAtomic($tmp.'/patch-profile-public.json',$publicProfile);
            $publicAgentFiles=UMEBackupAgentPack::rootFiles($publicManifest,$publicProfile,$agentSnapshot,true);
            $publicFinal=$this->storage.'/public-backups/'.basename($final);$publicPart=$publicFinal.'.part';@unlink($publicPart);$publicZip=new ZipArchive();if($publicZip->open($publicPart,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){@unlink($finalPart);throw new Exception('Не удалось создать публичный ZIP.');}
            $publicMap=array('manifest-public.json'=>'manifest.json','files-manifest-public.json'=>'files-manifest.json','metadata-public.json'=>'metadata.json','site-files-public.zip'=>'site-files.zip','database-public.sql'=>'database.sql','patch-profile-public.json'=>'patch-profile.json');foreach($publicMap as $src=>$dst){if(!is_file($tmp.'/'.$src))continue;if(!$publicZip->addFile($tmp.'/'.$src,$dst))throw new Exception('Ошибка добавления файла в публичный backup: '.$dst);if($dst==='site-files.zip'&&method_exists($publicZip,'setCompressionName')&&defined('ZipArchive::CM_STORE'))@$publicZip->setCompressionName($dst,ZipArchive::CM_STORE);}
            UMEBackupAgentPack::addRootContext($publicZip,$publicAgentFiles);
            UMEBackupAgentPack::addSourceToZip($publicZip,$agentSnapshot);
            if(!$publicZip->close())throw new Exception('Не удалось завершить публичный ZIP с исходниками Upgrade Manager.');
            if(!@rename($finalPart,$final)){@unlink($finalPart);@unlink($publicPart);throw new Exception('Не удалось опубликовать приватную резервную копию.');}if(!@rename($publicPart,$publicFinal)){@unlink($publicPart);@unlink($final);throw new Exception('Не удалось опубликовать очищенную публичную копию.');}
            $job['data']['result_file']=basename($final);$job['status']='completed';$job['phase']='completed';$job['progress']=100;$job['message']='Резервная копия создана';$elapsed=isset($job['data']['phase_started_at'])?round(microtime(true)-$job['data']['phase_started_at'],2):0;$this->log($job,'Финальный архив сформирован за '.$elapsed.' сек.','INFO');$this->log($job,'Резервная копия создана: '.basename($final).'; публичная очищенная копия: '.basename($publicFinal).'; исключено файлов: '.(int)$job['data']['public_sanitized_count'],'INFO');$this->cleanupJobTemp($job);$this->pruneBackups();return $job;
        }
        return $job;
    }
    private function validSha256($value){ return is_string($value) && preg_match('/^[a-f0-9]{64}$/i',$value); }
    private function assertPatchManifestBase(&$m,$signed){
        $required=array('format','patch_id','name','version','project','domain','site_id','files','delete','migrations');
        if($signed){$required[]='payload_hash';$required[]='signature';}
        foreach($required as $k)if(!array_key_exists($k,$m))throw new Exception('В manifest.json отсутствует '.$k);
        if(!in_array($m['format'],array('external-upgrade-patch/1.0','external-upgrade-patch/1.1','external-upgrade-patch/1.2','external-upgrade-patch/1.3'),true))throw new Exception('Неподдерживаемый формат патча.');
        if(!UMEUtil::safeId($m['patch_id']))throw new Exception('Некорректный patch_id.');
        if(!is_string($m['project'])||trim($m['project'])===''||!is_string($m['domain'])||trim($m['domain'])===''||(!is_string($m['site_id'])&&!is_int($m['site_id'])))throw new Exception('project, domain и site_id в manifest.json должны быть заполнены.');
        if(!is_array($m['files'])||!is_array($m['delete'])||!is_array($m['migrations']))throw new Exception('files, delete и migrations должны быть массивами.');
        if(!isset($m['actions'])||!is_array($m['actions']))$m['actions']=array();
        if(in_array($m['format'],array('external-upgrade-patch/1.1','external-upgrade-patch/1.2','external-upgrade-patch/1.3'),true)){ $allowed=isset($this->config['allowed_actions'])&&is_array($this->config['allowed_actions'])?$this->config['allowed_actions']:array('clear_cache');foreach($m['actions'] as $key=>$value)if(!in_array($key,$allowed,true))throw new Exception('Неподдерживаемое действие патча: '.$key); }
        if(in_array($m['format'],array('external-upgrade-patch/1.2','external-upgrade-patch/1.3'),true)&&!array_key_exists('base_site_version',$m))throw new Exception('В manifest 1.2/1.3 отсутствует base_site_version.');
        $this->assertPatchVersionContext($m);
        if($signed){
            if(!$this->validSha256($m['payload_hash']))throw new Exception('Некорректный payload_hash.');
            if(!$this->validSha256($m['signature']))throw new Exception('Некорректная signature.');
        }
    }
    private function patchCompatibilityWarnings($m){
        $warnings=array();
        if((string)$m['project']!==(string)$this->config['project'])$warnings[]=array('code'=>'project_mismatch','message'=>'Патч предназначен для другого проекта.');
        if((string)$m['site_id']!==(string)$this->config['site_id'])$warnings[]=array('code'=>'site_mismatch','message'=>'Патч предназначен для другого сайта.');
        $domain=strtolower(trim((string)$m['domain']));$allowed=isset($this->config['allowed_domains'])&&is_array($this->config['allowed_domains'])?$this->config['allowed_domains']:array();$allowed=array_map('strtolower',$allowed);
        if(!in_array($domain,$allowed,true))$warnings[]=array('code'=>'domain_mismatch','message'=>'Домен патча не разрешён.');
        if(isset($m['php_min'])&&trim((string)$m['php_min'])!==''&&!version_compare(PHP_VERSION,(string)$m['php_min'],'>='))$warnings[]=array('code'=>'php_version','message'=>'Требуется PHP '.$m['php_min'].' или новее.');
        if(isset($this->cms['supported'])&&!$this->cms['supported'])$warnings[]=array('code'=>'cms_unsupported','message'=>isset($this->cms['support_reason'])&&$this->cms['support_reason']!==''?$this->cms['support_reason']:'Текущая версия CMS не отмечена как поддерживаемая для установки патчей.');
        return $warnings;
    }
    private function buildPatchPayload($zip,&$m){
        $payload=in_array($m['format'],array('external-upgrade-patch/1.2','external-upgrade-patch/1.3'),true)?'BASE_SITE_VERSION:'.$m['base_site_version']."\n".'TARGET_SITE_VERSION:'.$m['version']."\n":'';$targets=array();$sources=array();$deleted=array();$migrationIds=array();
        foreach($m['files'] as $f){
            if(!is_array($f)||empty($f['source'])||empty($f['target'])||empty($f['sha256'])||!$this->validSha256($f['sha256']))throw new Exception('Некорректная запись files.');
            $src=UMEUtil::normalizeRelativePath($f['source']);$target=UMEUtil::normalizeRelativePath($f['target']);
            if($src===false||$target===false)throw new Exception('Недопустимый путь файла.');
            if($this->forbiddenTarget($target))throw new Exception('Изменение защищённого пути запрещено: '.$target);
            if(isset($targets[$target])||isset($sources[$src]))throw new Exception('Дублирующийся путь в files.');
            $content=$zip->getFromName($src);if($content===false||hash('sha256',$content)!==strtolower($f['sha256']))throw new Exception('Контрольная сумма не совпала: '.$src);
            $targets[$target]=1;$sources[$src]=1;$payload.=$target.':'.$f['sha256']."\n";
        }
        foreach($m['delete'] as $p){
            $p=UMEUtil::normalizeRelativePath($p);if($p===false||$this->forbiddenTarget($p))throw new Exception('Недопустимый delete path.');
            if(isset($deleted[$p])||isset($targets[$p]))throw new Exception('Дублирующийся delete path: '.$p);
            if(is_dir($this->root.'/'.$p))throw new Exception('Удаление каталога целиком запрещено для безопасного rollback: '.$p.'. Перечислите файлы отдельно.');
            $deleted[$p]=1;$payload.='DELETE:'.$p."\n";
        }
        // 3.7.0: 1.0-1.2 never authenticated migrations.tables, which controls SQL rollback.
        // Reject legacy SQL packages rather than accepting a table list that could be tampered with.
        if($m['migrations']&&$m['format']!=='external-upgrade-patch/1.3')throw new Exception('SQL-миграции в форматах 1.0–1.2 небезопасны: migrations.tables не подписан. Пересоберите и подпишите патч в формате 1.3.');
        foreach($m['migrations'] as $mi){
            if(!is_array($mi)||empty($mi['id'])||empty($mi['source'])||empty($mi['sha256'])||!isset($mi['tables'])||!is_array($mi['tables'])||!$mi['tables']||!$this->validSha256($mi['sha256']))throw new Exception('Некорректная миграция.');
            if(!UMEUtil::safeId($mi['id'])||isset($migrationIds[$mi['id']]))throw new Exception('Некорректный или дублирующийся id миграции.');
            $source=UMEUtil::normalizeRelativePath($mi['source']);if($source===false)throw new Exception('Недопустимый путь миграции.');
            $c=$zip->getFromName($source);if($c===false||hash('sha256',$c)!==strtolower($mi['sha256']))throw new Exception('Контрольная сумма миграции не совпала.');
            $tableSeen=array();foreach($mi['tables'] as $table){
                if(!is_string($table)||trim($table)!==$table||!preg_match('/^(?:\{\{DB_PREFIX\}\})?[A-Za-z0-9_$.\-]{1,128}$/',$table)||isset($tableSeen[$table]))throw new Exception('Некорректное или повторяющееся имя в migrations.tables.');
                $tableSeen[$table]=true;
            }
            $migrationIds[$mi['id']]=1;$payload.='SQL:'.$mi['id'].':'.$mi['sha256']."\n";
            $payload.='TABLES:'.$mi['id'].':'.hash('sha256',UMEUtil::canonicalJson($mi['tables']))."\n";
        }
        if(in_array($m['format'],array('external-upgrade-patch/1.1','external-upgrade-patch/1.2','external-upgrade-patch/1.3'),true))$payload.='ACTIONS:'.hash('sha256',UMEUtil::canonicalJson($m['actions']))."\n";
        return $payload;
    }
    private function copyZipEntryToFile($zip,$entry,$dest){
        $stream=$zip->getStream($entry);if(!$stream)throw new Exception('В резервной копии отсутствует '.$entry.'.');UMEUtil::ensureDir(dirname($dest));$out=@fopen($dest,'wb');if(!$out){fclose($stream);throw new Exception('Не удалось подготовить '.$entry.' для восстановления.');}
        while(!feof($stream)){$buf=fread($stream,1048576);if($buf===false){fclose($stream);fclose($out);@unlink($dest);throw new Exception('Ошибка чтения '.$entry.' из резервной копии.');}if($buf!==''&&fwrite($out,$buf)===false){fclose($stream);fclose($out);@unlink($dest);throw new Exception('Ошибка записи временного файла '.$entry.'.');}}
        fclose($stream);fclose($out);
    }
    private function restoreRows($tmp){
        $rows=UMEUtil::jsonRead($tmp.'/files-manifest.json',array());if(!is_array($rows))$rows=array();return $rows;
    }
    private function readPathRows($file){
        $rows=$this->readNdjson($file);$out=array();foreach($rows as $row)if(is_array($row)&&!empty($row['path']))$out[]=$row['path'];return $out;
    }
    private function appendPathRow($file,$path){$this->appendNdjsonBatch($file,array(array('path'=>$path)));}
    private function prepareRestoreDatabasePlan($tmp,$mode){
        $tables=array();$source=$tmp.'/database.sql';UMEUtil::ensureDir($tmp.'/restore-db');if(!is_file($source)){UMEUtil::jsonWriteAtomic($tmp.'/restore-db-tables.json',$tables);return $tables;}
        $sql=@file_get_contents($source);if($sql===false)throw new Exception('Не удалось прочитать database.sql из резервной копии.');
        $parts=preg_split('/(?=^DROP TABLE IF EXISTS `)/m',$sql);if(!is_array($parts))$parts=array();$index=0;
        foreach($parts as $block){
            if(!preg_match('/^DROP TABLE IF EXISTS `((?:``|[^`])+)`;/',$block,$m))continue;$table=str_replace('``','`',$m[1]);
            if(($mode==='compact'||$mode==='minimal')&&(strpos($block,'-- Data omitted in compact mode for ')!==false||strpos($block,'-- Data omitted in minimal mode for ')!==false))continue;
            $file=$tmp.'/restore-db/'.sprintf('%05d',$index).'.sql';$payload="SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n".$block."\nSET FOREIGN_KEY_CHECKS=1;\n";if(@file_put_contents($file,$payload,LOCK_EX)===false)throw new Exception('Не удалось подготовить восстановление таблицы '.$table.'.');
            $tables[]=array('table'=>$table,'file'=>basename($file));$index++;
        }
        UMEUtil::jsonWriteAtomic($tmp.'/restore-db-tables.json',$tables);return $tables;
    }
    private function validateAndStageBackupRestore($job,$tmp){
        if(!class_exists('ZipArchive'))throw new Exception('Расширение ZipArchive недоступно.');$backup=$this->storage.'/backups/'.$job['data']['backup_file'];$zip=new ZipArchive();if($zip->open($backup)!==true)throw new Exception('Резервная копия повреждена или недоступна.');
        $raw=$zip->getFromName('manifest.json');if($raw===false){$zip->close();throw new Exception('В резервной копии отсутствует manifest.json.');}$m=json_decode($raw,true);if(!is_array($m)||empty($m['backup_id'])||empty($m['site_id'])||empty($m['site_files_sha256'])||empty($m['signature'])){$zip->close();throw new Exception('Manifest резервной копии некорректен.');}
        $backupFormat=isset($m['format'])?(string)$m['format']:'';if(!in_array($backupFormat,array('external-code-backup/1.1','external-code-backup/1.2','external-code-backup/1.3','external-code-backup/1.4','external-code-backup/1.5'),true)){$zip->close();throw new Exception('Формат резервной копии не поддерживается.');}
        if((string)$m['site_id']!==(string)$this->config['site_id']){$zip->close();throw new Exception('Эта резервная копия создана для другого сайта.');}if(!$this->validSha256((string)$m['site_files_sha256'])||!$this->validSha256((string)$m['signature'])){$zip->close();throw new Exception('Контрольные суммы manifest резервной копии некорректны.');}
        foreach(array('files-manifest.json','site-files.zip') as $entry)if($zip->locateName($entry)===false){$zip->close();throw new Exception('В резервной копии отсутствует '.$entry.'.');}
        $databaseIncluded=!empty($m['database_included']);$hasDatabase=$zip->locateName('database.sql')!==false;$expectedDb=isset($m['database_sha256'])?(string)$m['database_sha256']:'';if($databaseIncluded&&(!$hasDatabase||!$this->validSha256($expectedDb))){$zip->close();throw new Exception('В manifest заявлена база данных, но database.sql или его SHA-256 отсутствует.');}if(!$databaseIncluded&&($hasDatabase||$expectedDb!=='')){$zip->close();throw new Exception('Состав database.sql не соответствует manifest резервной копии.');}
        $this->copyZipEntryToFile($zip,'files-manifest.json',$tmp.'/files-manifest.json');$this->copyZipEntryToFile($zip,'site-files.zip',$tmp.'/site-files.zip');if($databaseIncluded)$this->copyZipEntryToFile($zip,'database.sql',$tmp.'/database.sql');$zip->close();
        $siteSha=hash_file('sha256',$tmp.'/site-files.zip');if($siteSha===false||strtolower($siteSha)!==strtolower((string)$m['site_files_sha256']))throw new Exception('SHA-256 файлового архива не совпал.');$dbSha=$databaseIncluded?hash_file('sha256',$tmp.'/database.sql'):'';if($databaseIncluded&&($dbSha===false||strtolower((string)$dbSha)!==strtolower($expectedDb)))throw new Exception('SHA-256 дампа базы данных не совпал.');
        $backupExclusions=array();$signaturePayload=$m['backup_id'].'|'.$m['site_id'].'|'.$expectedDb.'|'.$m['site_files_sha256'];$legacyMode='';$fileMode='code_cms';$databaseMode='minimal';
        if($backupFormat==='external-code-backup/1.2'){
            if(!isset($m['backup_exclusions'])||!is_array($m['backup_exclusions'])||count($m['backup_exclusions'])>250)throw new Exception('Правила исключений в manifest резервной копии некорректны.');$backupExclusions=$this->normalizeBackupExclusions($m['backup_exclusions']);if($backupExclusions!==array_values($m['backup_exclusions']))throw new Exception('Правила исключений в manifest резервной копии не нормализованы.');$scopeSha=hash('sha256',UMEUtil::canonicalJson($backupExclusions));if(empty($m['scope_sha256'])||!$this->validSha256((string)$m['scope_sha256'])||!hash_equals(strtolower($scopeSha),strtolower((string)$m['scope_sha256'])))throw new Exception('Контрольная сумма области резервного копирования не совпала.');$signaturePayload.='|'.$scopeSha;
        }else if($backupFormat==='external-code-backup/1.3'){
            if(!isset($m['backup_exclusions'])||!is_array($m['backup_exclusions'])||count($m['backup_exclusions'])>250)throw new Exception('Правила исключений в manifest резервной копии некорректны.');$backupExclusions=$this->normalizeBackupExclusions($m['backup_exclusions']);if($backupExclusions!==array_values($m['backup_exclusions']))throw new Exception('Правила исключений в manifest резервной копии не нормализованы.');$legacyMode=isset($m['backup_mode'])?(string)$m['backup_mode']:(isset($m['dump_mode'])?(string)$m['dump_mode']:'compact');if(!$this->validLegacyBackupMode($legacyMode))throw new Exception('Неизвестный режим резервной копии.');$fileScope=isset($m['file_scope'])?(string)$m['file_scope']:'';if($fileScope!==$this->legacyBackupFileScope($legacyMode))throw new Exception('Область файлов не соответствует режиму резервной копии.');$scopeSha=hash('sha256',UMEUtil::canonicalJson(array('backup_mode'=>$legacyMode,'file_scope'=>$fileScope,'backup_exclusions'=>$backupExclusions)));if(empty($m['scope_sha256'])||!$this->validSha256((string)$m['scope_sha256'])||!hash_equals(strtolower($scopeSha),strtolower((string)$m['scope_sha256'])))throw new Exception('Контрольная сумма области резервного копирования не совпала.');$signaturePayload.='|'.$scopeSha;
        }else if($backupFormat==='external-code-backup/1.4'||$backupFormat==='external-code-backup/1.5'){
            if(!isset($m['backup_exclusions'])||!is_array($m['backup_exclusions'])||count($m['backup_exclusions'])>250)throw new Exception('Правила исключений в manifest резервной копии некорректны.');$backupExclusions=$this->normalizeBackupExclusions($m['backup_exclusions']);if($backupExclusions!==array_values($m['backup_exclusions']))throw new Exception('Правила исключений в manifest резервной копии не нормализованы.');$fileMode=isset($m['file_mode'])?(string)$m['file_mode']:'';$databaseMode=isset($m['database_mode'])?(string)$m['database_mode']:'';if(!$this->validFileMode($fileMode))throw new Exception('Неизвестный режим файлового бэкапа.');if(!$this->validDatabaseMode($databaseMode))throw new Exception('Неизвестный режим бэкапа БД.');if($fileMode==='none'&&$databaseMode==='none')throw new Exception('Пустая резервная копия без файлов и БД не поддерживается.');if(isset($m['file_scope'])&&(string)$m['file_scope']!==$fileMode)throw new Exception('Область файлов не соответствует режиму файлового бэкапа.');if($databaseMode==='none'&&$databaseIncluded)throw new Exception('Manifest содержит БД при режиме «Без БД».');$scopeSha=hash('sha256',UMEUtil::canonicalJson(array('file_mode'=>$fileMode,'database_mode'=>$databaseMode,'backup_exclusions'=>$backupExclusions)));if(empty($m['scope_sha256'])||!$this->validSha256((string)$m['scope_sha256'])||!hash_equals(strtolower($scopeSha),strtolower((string)$m['scope_sha256'])))throw new Exception('Контрольная сумма области резервного копирования не совпала.');$signaturePayload.='|'.$scopeSha;
        }
        if($backupFormat==='external-code-backup/1.5'){$savedVersion=isset($m['site_version'])?$this->normalizeSiteVersion($m['site_version']):false;$savedNext=isset($m['next_patch_version'])?$this->normalizeSiteVersion($m['next_patch_version']):false;if($savedVersion===false||$savedNext===false||$savedNext!==$this->shiftSiteVersion($savedVersion,1))throw new Exception('Версия сайта в manifest резервной копии некорректна.');$signaturePayload.='|'.$savedVersion.'|'.$savedNext;}
        $sig=hash_hmac('sha256',$signaturePayload,$this->config['hmac_key']);if(!hash_equals(strtolower($sig),strtolower((string)$m['signature'])))throw new Exception('Подпись резервной копии недействительна.');
        $rows=$this->restoreRows($tmp);if(isset($m['files_count'])&&(int)$m['files_count']!==count($rows))throw new Exception('Количество файлов не соответствует manifest резервной копии.');if(in_array($backupFormat,array('external-code-backup/1.4','external-code-backup/1.5'),true)&&$fileMode==='none'&&count($rows)!==0)throw new Exception('Manifest режима «Без файлов» не должен содержать файлы.');$seen=array();foreach($rows as $row){if(!is_array($row)||empty($row['path'])||empty($row['sha256']))throw new Exception('files-manifest.json повреждён.');$path=UMEUtil::normalizeRelativePath($row['path']);if($path===false||$this->forbiddenTarget($path))throw new Exception('Недопустимый путь в резервной копии: '.(isset($row['path'])?$row['path']:'?'));if(isset($seen[$path]))throw new Exception('Дублирующийся путь в резервной копии: '.$path);$seen[$path]=1;if(!preg_match('/^[a-f0-9]{64}$/i',(string)$row['sha256']))throw new Exception('Некорректный SHA-256 файла '.$path.'.');}
        $inner=new ZipArchive();if($inner->open($tmp.'/site-files.zip')!==true)throw new Exception('Вложенный архив site-files.zip повреждён.');$zipSeen=array();for($i=0;$i<$inner->numFiles;$i++){$name=$inner->getNameIndex($i);$path=UMEUtil::normalizeRelativePath($name);if($path===false||isset($zipSeen[$path])||!isset($seen[$path])){$inner->close();throw new Exception('Некорректное содержимое site-files.zip.');}$zipSeen[$path]=1;}foreach($seen as $path=>$v)if(!isset($zipSeen[$path])){$inner->close();throw new Exception('В site-files.zip отсутствует '.$path.'.');}$inner->close();
        if(!in_array($backupFormat,array('external-code-backup/1.4','external-code-backup/1.5'),true)){
            if($backupFormat!=='external-code-backup/1.3')$legacyMode=isset($m['dump_mode'])&&$m['dump_mode']==='full'?'full':'compact';$pair=$this->legacyModePair($legacyMode);$fileMode=$pair['file_mode'];$databaseMode=$pair['database_mode'];
        }
        $tables=$this->prepareRestoreDatabasePlan($tmp,$databaseMode);UMEUtil::jsonWriteAtomic($tmp.'/restore-manifest.json',$m);@unlink($tmp.'/delete-paths.ndjson');@unlink($tmp.'/safety-original.ndjson');@unlink($tmp.'/safety-absent.ndjson');@unlink($tmp.'/safety-db-absent.ndjson');UMEUtil::ensureDir($tmp.'/safety/original');UMEUtil::ensureDir($tmp.'/safety-db');
        $job['data']['backup_format']=$backupFormat;$job['data']['file_mode']=$fileMode;$job['data']['database_mode']=$databaseMode;if($backupFormat!=='external-code-backup/1.4')$job['data']['legacy_backup_mode']=$legacyMode;$job['data']['backup_exclusions']=$backupExclusions;$job['data']['restore_files_count']=count($rows);$job['data']['restore_db_tables_count']=count($tables);$job['data']['scan_queue']=array('');$job['data']['scan_current']=null;$job['data']['scan_count']=0;
        if(in_array($backupFormat,array('external-code-backup/1.4','external-code-backup/1.5'),true)&&$fileMode==='none'){$job['phase']='restore_safety_db';$job['progress']=23;$job['message']='Файлы в копии отключены. Готовим безопасное восстановление БД';}else{$job['phase']='restore_scan';$job['progress']=4;$job['message']='Бэкап проверен. Сравниваем текущие файлы со снимком';}
        $this->log($job,'Резервная копия проверена: '.$job['data']['backup_file'].'; файлов '.count($rows).'; таблиц к восстановлению '.count($tables).'; file_mode='.$fileMode.'; database_mode='.$databaseMode,'INFO');return $job;
    }
    private function backupRestoreSafetyFile($job,$tmp,$path){
        $path=$this->assertSafeTargetPath($path,false);$abs=$this->root.'/'.$path;if(is_file($abs)){$dest=$tmp.'/safety/original/'.$path;UMEUtil::ensureDir(dirname($dest));if(!@copy($abs,$dest))throw new Exception('Не удалось сохранить текущий файл перед восстановлением: '.$path);$this->appendPathRow($tmp.'/safety-original.ndjson',$path);}else{$this->appendPathRow($tmp.'/safety-absent.ndjson',$path);}
    }
    private function writeRestoredZipFile($inner,$path,$target,$expectedSha){
        $path=$this->assertSafeTargetPath($path,false);$stream=$inner->getStream($path);if(!$stream)throw new Exception('Не удалось открыть '.$path.' внутри бэкапа.');UMEUtil::ensureDir(dirname($target));$tmpFile=$target.'.ume-restore-'.UMEUtil::randomHex(4);$out=@fopen($tmpFile,'wb');if(!$out){fclose($stream);throw new Exception('Не удалось создать временный файл для '.$path.'.');}$ctx=hash_init('sha256');
        while(!feof($stream)){$buf=fread($stream,1048576);if($buf===false){fclose($stream);fclose($out);@unlink($tmpFile);throw new Exception('Ошибка чтения '.$path.' из бэкапа.');}if($buf!==''){hash_update($ctx,$buf);if(fwrite($out,$buf)===false){fclose($stream);fclose($out);@unlink($tmpFile);throw new Exception('Ошибка записи '.$path.'.');}}}fclose($stream);fclose($out);$actual=hash_final($ctx);if(strtolower($actual)!==strtolower($expectedSha)){@unlink($tmpFile);throw new Exception('SHA-256 не совпал для '.$path.'.');}
        $mode=is_file($target)?(@fileperms($target)&0777):0644;if(!@rename($tmpFile,$target)){@unlink($tmpFile);throw new Exception('Не удалось заменить '.$path.'.');}@chmod($target,$mode);return true;
    }
    private function rollbackBackupRestore($job,$tmp){
        $limit=isset($this->config['limits']['files_per_step'])?(int)$this->config['limits']['files_per_step']:100;
        if($job['phase']==='restore_rollback_files'){
            $original=$this->readPathRows($tmp.'/safety-original.ndjson');$i=(int)$job['data']['rollback_original_index'];$end=min(count($original),$i+$limit);for(;$i<$end;$i++){$path=$this->assertSafeTargetPath($original[$i],false);$src=$tmp.'/safety/original/'.$path;$dst=$this->root.'/'.$path;if(is_file($src)){UMEUtil::ensureDir(dirname($dst));if(!@copy($src,$dst))throw new Exception('Не удалось вернуть файл '.$path.' при аварийном откате.');}}$job['data']['rollback_original_index']=$end;
            if($end<count($original)){$job['progress']=92;$job['message']='Аварийный откат файлов: '.$end.' из '.count($original);return $job;}
            $absent=$this->readPathRows($tmp.'/safety-absent.ndjson');$j=(int)$job['data']['rollback_absent_index'];$end2=min(count($absent),$j+$limit);for(;$j<$end2;$j++){$path=$this->assertSafeTargetPath($absent[$j],true);$dst=$this->root.'/'.$path;if(is_file($dst)||is_link($dst))@unlink($dst);}$job['data']['rollback_absent_index']=$end2;if($end2<count($absent)){$job['progress']=94;$job['message']='Удаляем файлы, которых не было до восстановления: '.$end2.' из '.count($absent);return $job;}
            $job['phase']='restore_rollback_db';$job['data']['rollback_db_index']=0;$job['progress']=95;return $job;
        }
        if($job['phase']==='restore_rollback_db'){
            $tables=UMEUtil::jsonRead($tmp.'/restore-db-tables.json',array());$idx=(int)$job['data']['rollback_db_index'];if($idx<count($tables)){$file=$tmp.'/safety-db/'.sprintf('%05d',$idx).'.sql';if(is_file($file)){$db=$this->database();$db->executeSqlFile($file);}$job['data']['rollback_db_index']=$idx+1;$job['progress']=95+(count($tables)?(int)floor(3*($idx+1)/count($tables)):3);$job['message']='Аварийный откат базы данных: '.($idx+1).' из '.count($tables);return $job;}
            $absent=$this->readPathRows($tmp.'/safety-db-absent.ndjson');if($absent){$db=$this->database();foreach($absent as $table)$db->query('DROP TABLE IF EXISTS '.UMEUtil::sqlIdentifier($table));}$job['phase']='restore_rollback_actions';return $job;
        }
        if($job['phase']==='restore_rollback_actions'){
            $this->runActions($job,array('clear_cache'=>true));
            if(!empty($job['data']['cancelled_by_user'])){$job['status']='cancelled';$job['phase']='cancelled';$job['progress']=100;$job['message']='Восстановление прервано. Состояние сайта до запуска полностью возвращено.';$this->log($job,'Безопасный откат после прерывания восстановления завершён','WARN');}
            else {$last=isset($job['data']['last_error'])?$job['data']['last_error']:'Неизвестная ошибка';$job['status']='failed';$job['phase']='failed';$job['progress']=100;$job['message']='Восстановление не выполнено, состояние до запуска возвращено: '.$last;$this->log($job,'Аварийный откат восстановления завершён','WARN');}
            $this->cleanupJobTemp($job);return $job;
        }
        return $job;
    }
    private function stepBackupRestore($job){
        $tmp=$this->storage.'/temp/'.$job['id'];$limit=$this->config['limits'];$timeBudget=isset($limit['step_time_budget_seconds'])?(float)$limit['step_time_budget_seconds']:1.6;$scanLimit=isset($limit['scan_entries_per_step'])?(int)$limit['scan_entries_per_step']:1200;$fileLimit=isset($limit['files_per_step'])?(int)$limit['files_per_step']:100;
        if(strpos($job['phase'],'restore_rollback_')===0)return $this->rollbackBackupRestore($job,$tmp);
        if($job['phase']==='created')return $this->validateAndStageBackupRestore($job,$tmp);
        if($job['phase']==='restore_scan'){
            $rows=$this->restoreRows($tmp);$set=array();foreach($rows as $row)if(!empty($row['path']))$set[str_replace('\\','/',$row['path'])]=1;$restoreRules=isset($job['data']['backup_exclusions'])&&is_array($job['data']['backup_exclusions'])?$job['data']['backup_exclusions']:array();$deadline=microtime(true)+$timeBudget;$processed=0;$found=array();
            while($processed<$scanLimit&&microtime(true)<$deadline){if(empty($job['data']['scan_current'])){if(empty($job['data']['scan_queue']))break;$rel=array_shift($job['data']['scan_queue']);$abs=$this->root.($rel===''?'':'/'.$rel);$list=@scandir($abs);if(!is_array($list))continue;$entries=array();foreach($list as $name)if($name!=='.'&&$name!=='..')$entries[]=$name;$job['data']['scan_current']=array('dir'=>$rel,'entries'=>$entries,'index'=>0);} $cur=&$job['data']['scan_current'];if($cur['index']>=count($cur['entries'])){unset($job['data']['scan_current']);continue;}$name=$cur['entries'][$cur['index']++];$rel=$cur['dir'];$child=UMEUtil::normalizeRelativePath($rel===''?$name:$rel.'/'.$name);if($child===false){$processed++;continue;}$ca=$this->root.'/'.$child;if(is_link($ca)){$processed++;continue;}if(is_dir($ca)){if(!$this->restoreExcludedDir($child,$job,$restoreRules))$job['data']['scan_queue'][]=$child;}else if(is_file($ca)&&$this->restoreIncludeFile($child,$job,$restoreRules)&&!isset($set[$child])){$found[]=array('path'=>$child);}$processed++;$job['data']['scan_count']++;}
            $this->appendNdjsonBatch($tmp.'/delete-paths.ndjson',$found);$job['progress']=8;$job['message']='Сравнение файлов: проверено '.$job['data']['scan_count'];if(empty($job['data']['scan_queue'])&&empty($job['data']['scan_current'])){$job['phase']='restore_safety_files';$job['data']['file_index']=0;$job['data']['delete_index']=0;$this->log($job,'Сравнение файлов завершено; лишних файлов в области копии: '.count($this->readPathRows($tmp.'/delete-paths.ndjson')),'INFO');}return $job;
        }
        if($job['phase']==='restore_safety_files'){
            $rows=$this->restoreRows($tmp);$delete=$this->readPathRows($tmp.'/delete-paths.ndjson');$i=(int)$job['data']['file_index'];$done=0;while($i<count($rows)&&$done<$fileLimit){$this->backupRestoreSafetyFile($job,$tmp,$rows[$i]['path']);$i++;$done++;}$job['data']['file_index']=$i;if($i<count($rows)){$job['progress']=10+(count($rows)?(int)floor(12*$i/count($rows)):12);$job['message']='Страховочная копия текущих файлов: '.$i.' из '.count($rows);return $job;}
            $j=(int)$job['data']['delete_index'];while($j<count($delete)&&$done<$fileLimit){$this->backupRestoreSafetyFile($job,$tmp,$delete[$j]);$j++;$done++;}$job['data']['delete_index']=$j;$total=count($rows)+count($delete);$made=count($rows)+$j;$job['progress']=10+($total?(int)floor(12*$made/$total):12);$job['message']='Страховочная копия текущих файлов: '.$made.' из '.$total;if($j>=count($delete)){$job['phase']='restore_safety_db';$job['data']['db_safety_table_index']=0;$job['data']['db_safety_offset']=0;$job['progress']=23;}return $job;
        }
        if($job['phase']==='restore_safety_db'){
            $tables=UMEUtil::jsonRead($tmp.'/restore-db-tables.json',array());if(!$tables){$job['changes_started']=true;$job['phase']='restore_apply_files';$job['data']['file_index']=0;$job['progress']=35;$job['message']='Начинаем восстановление файлов';return $job;}
            $db=$this->database();$idx=(int)$job['data']['db_safety_table_index'];if($idx>=count($tables)){$job['changes_started']=true;$job['phase']='restore_apply_files';$job['data']['file_index']=0;$job['progress']=35;$job['message']='Страховочная копия готова. Начинаем восстановление';return $job;}
            $table=$tables[$idx]['table'];$offset=(int)$job['data']['db_safety_offset'];$safety=$tmp.'/safety-db/'.sprintf('%05d',$idx).'.sql';if($offset===0){if(!$db->tableExists($table)){$this->appendPathRow($tmp.'/safety-db-absent.ndjson',$table);$job['data']['db_safety_table_index']=$idx+1;$job['data']['db_safety_offset']=0;$job['message']='Таблица '.$table.' отсутствует сейчас, запомнено для отката';return $job;}$create=$db->createSql($table);$this->writeExact($safety,"SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS ".UMEUtil::sqlIdentifier($table).";\n".$create.";\n\n",0,'SQL-дамп');}
            $rows=$db->rowsChunk($table,$offset,isset($limit['db_rows_per_step'])?(int)$limit['db_rows_per_step']:500);if($rows){$this->writeExact($safety,$db->insertSql($table,$rows)."\n",FILE_APPEND,'SQL-дамп');$offset+=count($rows);$job['data']['db_safety_offset']=$offset;}if(count($rows)<(isset($limit['db_rows_per_step'])?(int)$limit['db_rows_per_step']:500)){$this->writeExact($safety,"SET FOREIGN_KEY_CHECKS=1;\n",FILE_APPEND,'SQL-дамп');$job['data']['db_safety_table_index']=$idx+1;$job['data']['db_safety_offset']=0;}
            $job['progress']=23+(int)floor(12*min(1,($idx+0.5)/max(1,count($tables))));$job['message']='Страховочная копия БД: '.$table.' (таблица '.($idx+1).' из '.count($tables).')';return $job;
        }
        if($job['phase']==='restore_apply_files'){
            $rows=$this->restoreRows($tmp);$i=(int)$job['data']['file_index'];$end=min(count($rows),$i+$fileLimit);$inner=new ZipArchive();if($inner->open($tmp.'/site-files.zip')!==true)throw new Exception('Не удалось открыть site-files.zip.');for(;$i<$end;$i++){$path=UMEUtil::normalizeRelativePath($rows[$i]['path']);$this->writeRestoredZipFile($inner,$path,$this->root.'/'.$path,$rows[$i]['sha256']);}$inner->close();$job['data']['file_index']=$end;$job['progress']=35+(count($rows)?(int)floor(25*$end/count($rows)):25);$job['message']='Восстановление файлов: '.$end.' из '.count($rows);if($end>=count($rows)){$job['phase']='restore_delete_files';$job['data']['delete_index']=0;}return $job;
        }
        if($job['phase']==='restore_delete_files'){
            $delete=$this->readPathRows($tmp.'/delete-paths.ndjson');$i=(int)$job['data']['delete_index'];$end=min(count($delete),$i+$fileLimit);for(;$i<$end;$i++){$path=$this->assertSafeTargetPath($delete[$i],true);$abs=$this->root.'/'.$path;if(is_file($abs)||is_link($abs))if(!@unlink($abs))throw new Exception('Не удалось удалить файл, отсутствовавший в бэкапе: '.$path);}$job['data']['delete_index']=$end;$job['progress']=60+(count($delete)?(int)floor(5*$end/count($delete)):5);$job['message']='Удаляем лишние файлы в области копии: '.$end.' из '.count($delete);if($end>=count($delete)){$job['phase']='restore_apply_db';$job['data']['db_restore_index']=0;}return $job;
        }
        if($job['phase']==='restore_apply_db'){
            $tables=UMEUtil::jsonRead($tmp.'/restore-db-tables.json',array());$idx=(int)$job['data']['db_restore_index'];if($idx<count($tables)){$db=$this->database();$file=$tmp.'/restore-db/'.$tables[$idx]['file'];$db->executeSqlFile($file);$job['data']['db_restore_index']=$idx+1;$job['progress']=65+(count($tables)?(int)floor(20*($idx+1)/count($tables)):20);$job['message']='Восстановление БД: '.$tables[$idx]['table'].' ('.($idx+1).' из '.count($tables).')';return $job;}$job['phase']='restore_actions';$job['progress']=86;return $job;
        }
        if($job['phase']==='restore_actions'){$this->runActions($job,array('clear_cache'=>true));$job['phase']='restore_verify';$job['data']['verify_index']=0;$job['progress']=90;$job['message']='Кэш очищен. Проверяем восстановленные файлы';return $job;}
        if($job['phase']==='restore_verify'){
            $rows=$this->restoreRows($tmp);$i=(int)$job['data']['verify_index'];$end=min(count($rows),$i+$fileLimit);for(;$i<$end;$i++){$path=UMEUtil::normalizeRelativePath($rows[$i]['path']);$abs=$this->root.'/'.$path;if(!is_file($abs)||strtolower(hash_file('sha256',$abs))!==strtolower($rows[$i]['sha256']))throw new Exception('Проверка восстановленного файла не пройдена: '.$path);}$job['data']['verify_index']=$end;$job['progress']=90+(count($rows)?(int)floor(9*$end/count($rows)):9);$job['message']='Проверка файлов: '.$end.' из '.count($rows);if($end>=count($rows)){$delete=$this->readPathRows($tmp.'/delete-paths.ndjson');foreach($delete as $path)if(is_file($this->root.'/'.$path))throw new Exception('После восстановления остался лишний файл из области копии: '.$path);$restoredManifest=UMEUtil::jsonRead($tmp.'/restore-manifest.json',array());$restoredVersion=isset($restoredManifest['site_version'])?$this->normalizeSiteVersion($restoredManifest['site_version']):false;$versionNote='';if($restoredVersion!==false&&(!isset($job['data']['file_mode'])||$job['data']['file_mode']!=='none')){$this->writeSiteVersion($restoredVersion,'backup_restore','','');$versionNote='. Версия сайта: '.$restoredVersion;}$job['status']='completed';$job['phase']='completed';$job['progress']=100;$job['message']='Резервная копия полностью восстановлена в пределах сохранённого состава'.$versionNote;$this->log($job,'Восстановление бэкапа завершено: '.$job['data']['backup_file'].$versionNote,'INFO');$this->cleanupJobTemp($job);}return $job;
        }
        return $job;
    }
    private function sealManualPatchIfNeeded($zipFile){
        $zip=new ZipArchive();if($zip->open($zipFile)!==true)throw new Exception('ZIP повреждён.');
        try{
            $raw=$zip->getFromName('manifest.json');if($raw===false)throw new Exception('В корне нет manifest.json.');
            $m=json_decode($raw,true);if(!is_array($m))throw new Exception('manifest.json некорректен.');
            if(!isset($m['format'])||!in_array($m['format'],array('external-upgrade-patch/1.1','external-upgrade-patch/1.2','external-upgrade-patch/1.3'),true)){ $zip->close(); return false; }
            $hasPayload=array_key_exists('payload_hash',$m)&&trim((string)$m['payload_hash'])!=='';
            $hasSignature=array_key_exists('signature',$m)&&trim((string)$m['signature'])!=='';
            if($hasPayload&&$hasSignature){$zip->close();return false;}
            if($hasPayload!==$hasSignature)throw new Exception('Для ручной автоподписи payload_hash и signature должны либо отсутствовать оба, либо быть заполнены оба.');
            if($m['format']==='external-upgrade-patch/1.2'&&!empty($m['migrations']))$m['format']='external-upgrade-patch/1.3';
            $this->assertPatchManifestBase($m,false);$payload=$this->buildPatchPayload($zip,$m);
            $m['payload_hash']=hash('sha256',$payload);
            $m['signature']=hash_hmac('sha256',$m['patch_id'].'|'.$m['site_id'].'|'.$m['domain'].'|'.$m['payload_hash'],$this->config['hmac_key']);
            $m['signed_by']='upgrade-manager-manual';$m['signed_at']=UMEUtil::now();
            $json=json_encode($m,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);if($json===false)throw new Exception('Не удалось сериализовать подписанный manifest.json.');
            if(!$zip->deleteName('manifest.json'))throw new Exception('Не удалось заменить manifest.json.');
            if(!$zip->addFromString('manifest.json',$json))throw new Exception('Не удалось записать подписанный manifest.json.');
            $zip->close();return true;
        }catch(Exception $e){$zip->close();throw $e;}
    }
    public function uploadPatch($file){
        if(empty($file['tmp_name'])||!is_uploaded_file($file['tmp_name']))throw new Exception('ZIP-файл не получен.'); if($file['size']>$this->config['limits']['max_patch_bytes'])throw new Exception('Патч превышает допустимый размер.');
        if(strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='zip')throw new Exception('Разрешены только ZIP-патчи.'); if(!class_exists('ZipArchive'))throw new Exception('ZipArchive недоступен.');
        $id=date('Ymd-His').'-'.substr(UMEUtil::randomHex(5),0,10); $dest=$this->storage.'/uploads/'.$id.'.zip'; if(!move_uploaded_file($file['tmp_name'],$dest))throw new Exception('Не удалось сохранить патч.');
        try{$sealed=$this->sealManualPatchIfNeeded($dest);$report=$this->validatePatchFile($dest);}catch(Exception $e){@unlink($dest);throw $e;}
        $report['upload_id']=$id;$report['file']=basename($dest);$report['source']='manual';$report['sealed_locally']=$sealed;UMEUtil::jsonWriteAtomic($this->storage.'/uploads/'.$id.'.json',$report);return $report;
    }
    public function registerApiPatch($sourceFile,$originalName){
        if(!is_file($sourceFile))throw new Exception('Загруженный API-патч не найден.');
        $size=@filesize($sourceFile);if($size===false||$size<1)throw new Exception('API-патч пуст.');
        if($size>$this->config['limits']['max_patch_bytes'])throw new Exception('Патч превышает допустимый размер.');
        if(strtolower(pathinfo($originalName,PATHINFO_EXTENSION))!=='zip')throw new Exception('Разрешены только ZIP-патчи.');
        if(!class_exists('ZipArchive'))throw new Exception('ZipArchive недоступен.');
        $id=date('Ymd-His').'-api-'.substr(UMEUtil::randomHex(5),0,10);$dest=$this->storage.'/uploads/'.$id.'.zip';
        if(!@rename($sourceFile,$dest)){if(!@copy($sourceFile,$dest))throw new Exception('Не удалось перенести API-патч в защищённое хранилище.');@unlink($sourceFile);}
        try{$report=$this->validatePatchFile($dest);if(empty($report['valid'])){$message=!empty($report['validation_warnings'][0]['message'])?$report['validation_warnings'][0]['message']:'Патч не прошёл проверку совместимости.';throw new Exception($message);}}catch(Exception $e){@unlink($dest);throw $e;}
        $report['upload_id']=$id;$report['file']=basename($dest);$report['source']='api';$report['sealed_locally']=false;UMEUtil::jsonWriteAtomic($this->storage.'/uploads/'.$id.'.json',$report);return $report;
    }
    public function validatePatchFile($zipFile){
        $zip=new ZipArchive();if($zip->open($zipFile)!==true)throw new Exception('ZIP повреждён.');$count=$zip->numFiles;if($count>$this->config['limits']['max_patch_files'])throw new Exception('Слишком много файлов в патче.');
        $total=0;$seen=array();for($i=0;$i<$count;$i++){$st=$zip->statIndex($i);$name=UMEUtil::normalizeRelativePath($st['name']);if($name===false)throw new Exception('Недопустимый путь в ZIP.');if(isset($seen[$name]))throw new Exception('Дублирующийся путь: '.$name);$seen[$name]=1;$total+=(int)$st['size'];}
        if($total>$this->config['limits']['max_uncompressed_bytes'])throw new Exception('Распакованный патч слишком большой.');$raw=$zip->getFromName('manifest.json');if($raw===false)throw new Exception('В корне нет manifest.json.');$m=json_decode($raw,true);if(!is_array($m))throw new Exception('manifest.json некорректен.');
        $this->assertPatchManifestBase($m,true);$payload=$this->buildPatchPayload($zip,$m);
        if(hash('sha256',$payload)!==strtolower($m['payload_hash']))throw new Exception('Payload hash не совпал.');$sig=hash_hmac('sha256',$m['patch_id'].'|'.$m['site_id'].'|'.$m['domain'].'|'.$m['payload_hash'],$this->config['hmac_key']);if(!hash_equals(strtolower($sig),strtolower($m['signature'])))throw new Exception('Подпись патча недействительна.');
        $applied=UMEUtil::jsonRead($this->storage.'/applied.json',array());if(isset($applied[$m['patch_id']]))throw new Exception('Этот patch_id уже установлен.');$warnings=$this->patchCompatibilityWarnings($m);$zip->close();
        return array('valid'=>empty($warnings),'security_valid'=>true,'override_available'=>!empty($warnings),'validation_warnings'=>$warnings,'validation_error'=>!empty($warnings)?$warnings[0]['message']:'','manifest'=>$m,'files_count'=>count($m['files']),'delete_count'=>count($m['delete']),'migration_count'=>count($m['migrations']),'uncompressed_bytes'=>$total);
    }
    public function startInstall($uploadId,$force=false){
        if(!UMEUtil::safeId($uploadId))throw new Exception('Некорректный upload_id.');
        $report=UMEUtil::jsonRead($this->storage.'/uploads/'.$uploadId.'.json',array());
        $force=(bool)$force;$canOverride=$force&&!empty($report['security_valid'])&&!empty($report['override_available'])&&isset($report['source'])&&$report['source']==='manual';
        if(empty($report['valid'])&&!$canOverride)throw new Exception(!empty($report['validation_error'])?$report['validation_error']:'Патч не проверен.');
        if(isset($this->cms['supported'])&&!$this->cms['supported']&&!$canOverride)throw new Exception(isset($this->cms['support_reason'])&&$this->cms['support_reason']!==''?$this->cms['support_reason']:'Текущая версия CMS не поддерживается для установки патчей.');
        $m=$report['manifest'];$versionContext=$this->assertPatchVersionContext($m);$siteVersionBefore=$versionContext['base_site_version'];$siteVersionAfter=$versionContext['target_site_version'];
        if($canOverride){$report['override_accepted']=true;$report['override_accepted_at']=UMEUtil::now();UMEUtil::jsonWriteAtomic($this->storage.'/uploads/'.$uploadId.'.json',$report);}
        $rollbackManifest=array(
            'format'=>'external-upgrade-rollback/1.0',
            'patch_id'=>$m['patch_id'],
            'patch_name'=>$m['name'],
            'patch_version'=>$m['version'],
            'site_version_before'=>$siteVersionBefore,
            'site_version_after'=>$siteVersionAfter,
            'created_at'=>UMEUtil::now(),
            'original'=>array(),
            'absent'=>array(),
            'tables'=>array(),
            'absent_tables'=>array(),
            'actions_after_restore'=>array('clear_cache'=>(!empty($m['actions']['clear_cache'])||!empty($m['actions']['clear_opencart_cache'])))
        );
        return $this->newJob('install',array('upload_id'=>$uploadId,'manifest'=>$m,'site_version_before'=>$siteVersionBefore,'site_version_after'=>$siteVersionAfter,'file_index'=>0,'delete_index'=>0,'migration_index'=>0,'rollback_file'=>'','rollback_manifest'=>$rollbackManifest,'validation_override'=>$canOverride,'validation_warnings'=>isset($report['validation_warnings'])&&is_array($report['validation_warnings'])?$report['validation_warnings']:array()));
    }
    // 3.6.3: Upgrade Manager itself and its runtime storage are never valid patch/restore targets,
    // regardless of the protected_targets list stored in config.local.php by older installers.
    // Upgrade Manager is updated only through its signed self-update channel.
    public function selfProtectedPaths(){
        if(is_array($this->selfProtectedPaths))return $this->selfProtectedPaths;
        $out=array('upgrade');$root=rtrim(str_replace('\\','/',(string)$this->root),'/');
        foreach(array($this->upgradeDir,$this->storage) as $dir){
            $real=$dir?realpath($dir):false;$real=$real?rtrim(str_replace('\\','/',$real),'/'):rtrim(str_replace('\\','/',(string)$dir),'/');
            if($root!==''&&$real!==''&&UMEUtil::startsWith($real,$root.'/')){$rel=trim(substr($real,strlen($root)),'/');if($rel!==''&&!in_array($rel,$out,true))$out[]=$rel;}
        }
        $this->selfProtectedPaths=$out;return $out;
    }
    public function forbiddenTarget($p){
        $p=trim(str_replace('\\','/',$p),'/');$lower=strtolower($p);
        foreach($this->selfProtectedPaths() as $f){$f=strtolower($f);if($lower===$f||UMEUtil::startsWith($lower,$f.'/'))return true;}$forbidden=isset($this->config['protected_targets'])&&is_array($this->config['protected_targets'])?$this->config['protected_targets']:array('upgrade/config.local.php','.env','.git');
        foreach($forbidden as $f){$f=trim(str_replace('\\','/',$f),'/');if($f!==''&&($p===$f||UMEUtil::startsWith($p,$f.'/')))return true;}return false;
    }
    private function assertSafeTargetPath($p,$allowDirectoryTarget){
        $p=UMEUtil::normalizeRelativePath($p);if($p===false||$this->forbiddenTarget($p))throw new Exception('Изменение защищённого пути запрещено: '.$p);
        $parts=explode('/',$p);$current=$this->root;for($i=0;$i<count($parts)-1;$i++){$current.='/'.$parts[$i];if(is_link($current))throw new Exception('Родительский путь является символьной ссылкой: '.$p);if(file_exists($current)&&!is_dir($current))throw new Exception('Родительский путь не является каталогом: '.$p);if(is_dir($current)){$real=realpath($current);$root=str_replace('\\','/',$this->root);$real=$real?str_replace('\\','/',$real):false;if(!$real||($real!==$root&&!UMEUtil::startsWith($real,$root.'/')))throw new Exception('Путь выходит за пределы сайта: '.$p);}}
        $target=$this->root.'/'.$p;if(is_link($target))throw new Exception('Целевой путь является символьной ссылкой: '.$p);if(!$allowDirectoryTarget&&is_dir($target))throw new Exception('Файл патча конфликтует с существующим каталогом: '.$p);return $p;
    }
    private function rollbackIndex(){ return UMEUtil::jsonRead($this->storage.'/rollback-index.json',array()); }
    private function saveRollbackRecord($patchId,$record){ $idx=$this->rollbackIndex(); $idx[$patchId]=$record; UMEUtil::jsonWriteAtomic($this->storage.'/rollback-index.json',$idx); }
    private function updateRollbackRecord($patchId,$fields){
        $idx=$this->rollbackIndex(); if(!isset($idx[$patchId])||!is_array($idx[$patchId]))$idx[$patchId]=array('patch_id'=>$patchId);
        foreach($fields as $k=>$v)$idx[$patchId][$k]=$v; UMEUtil::jsonWriteAtomic($this->storage.'/rollback-index.json',$idx);
    }
    private function updateHistoryStatus($patchId,$status,$extra){
        $history=UMEUtil::jsonRead($this->storage.'/history.json',array());
        for($i=count($history)-1;$i>=0;$i--){ if(isset($history[$i]['patch_id'])&&$history[$i]['patch_id']===$patchId){$history[$i]['status']=$status;foreach($extra as $k=>$v)$history[$i][$k]=$v;break;} }
        UMEUtil::jsonWriteAtomic($this->storage.'/history.json',$history);
    }
    // 3.6.9: the patch digit is decimal with carry: 1.0.9 -> 1.1.0.
    // manifest.version is the target site version after installation, not an independent AI label.
    private function siteVersionParts($value){
        $value=trim((string)$value);if(!preg_match('/^v?(\d{1,6})\.(\d{1,6})\.(\d{1,6})$/i',$value,$m))return false;
        return array((int)$m[1],(int)$m[2],(int)$m[3]);
    }
    public function normalizeSiteVersion($value){
        $parts=$this->siteVersionParts($value);if($parts===false)return false;
        // Repair legacy 3.6.8 values such as 1.0.10 without changing the meaning of the counter.
        if($parts[2]>=10){$parts[1]+=(int)floor($parts[2]/10);$parts[2]=$parts[2]%10;}if($parts[1]>=10){$parts[0]+=(int)floor($parts[1]/10);$parts[1]=$parts[1]%10;}
        return $parts[0].'.'.$parts[1].'.'.$parts[2];
    }
    public function siteVersion(){
        $row=UMEUtil::jsonRead($this->storage.'/site-version.json',array());
        $version=is_array($row)&&isset($row['version'])?$this->normalizeSiteVersion($row['version']):false;
        if($version===false){
            $count=0;foreach(UMEUtil::jsonRead($this->storage.'/history.json',array()) as $h){if(is_array($h)&&(empty($h['status'])||$h['status']==='installed'))$count++;}
            $version=(1+(int)floor($count/100)).'.'.((int)floor(($count%100)/10)).'.'.($count%10);
            return array('version'=>$version,'next_version'=>$this->shiftSiteVersion($version,1),'source'=>'initial','updated_at'=>'','patch_id'=>'','patch_name'=>'','step'=>'0.0.1');
        }
        return array('version'=>$version,'next_version'=>$this->shiftSiteVersion($version,1),'source'=>isset($row['source'])?(string)$row['source']:'','updated_at'=>isset($row['updated_at'])?(string)$row['updated_at']:'','patch_id'=>isset($row['patch_id'])?(string)$row['patch_id']:'','patch_name'=>isset($row['patch_name'])?(string)$row['patch_name']:'','step'=>'0.0.1');
    }
    public function nextSiteVersion(){ $current=$this->siteVersion();return $current['next_version']; }
    private function shiftSiteVersion($version,$delta){
        $parts=$this->siteVersionParts($this->normalizeSiteVersion($version));if($parts===false)return false;$delta=(int)$delta;
        while($delta>0){$parts[2]++;if($parts[2]>=10){$parts[2]=0;$parts[1]++;if($parts[1]>=10){$parts[1]=0;$parts[0]++;}}$delta--;}
        while($delta<0){if($parts[2]>0)$parts[2]--;else{$parts[2]=9;if($parts[1]>0)$parts[1]--;else if($parts[0]>0){$parts[0]--;$parts[1]=9;}else{$parts=array(0,0,0);break;}}$delta++;}
        return $parts[0].'.'.$parts[1].'.'.$parts[2];
    }
    public function assertPatchVersionContext(&$m){
        $current=$this->siteVersion();$rawTarget=isset($m['version'])?trim((string)$m['version']):'';$target=$this->normalizeSiteVersion($rawTarget);if($target===false)throw new Exception('Версия патча должна быть версией сайта после установки в формате X.Y.Z.');
        if($rawTarget!==$target)throw new Exception('Версия патча должна быть записана канонически: '.$target.'.');
        if(isset($m['base_site_version'])){$rawBase=trim((string)$m['base_site_version']);$base=$this->normalizeSiteVersion($rawBase);if($base===false)throw new Exception('base_site_version должна быть в формате X.Y.Z.');if($rawBase!==$base)throw new Exception('base_site_version должна быть записана канонически: '.$base.'.');if($base!==$current['version'])throw new Exception('Патч создан от версии сайта '.$base.', а текущая версия сайта '.$current['version'].'. Создайте новый бэкап и новый патч.');}
        if($target!==$current['next_version'])throw new Exception('Некорректная версия патча: ожидается '.$current['next_version'].' для сайта '.$current['version'].', получено '.$target.'. Версия патча должна быть ровно на 0.0.1 выше версии бэкапа.');
        return array('base_site_version'=>$current['version'],'target_site_version'=>$target);
    }
    private function writeSiteVersion($version,$source,$patchId,$patchName){
        $row=array('format'=>'ume-site-version/1.0','version'=>$version,'source'=>$source,'updated_at'=>UMEUtil::now(),'patch_id'=>(string)$patchId,'patch_name'=>(string)$patchName);
        UMEUtil::jsonWriteAtomic($this->storage.'/site-version.json',$row);return $this->siteVersion();
    }
    public function setSiteVersion($value){
        $this->assertNoActive();$raw=$this->siteVersionParts($value);if($raw===false)throw new Exception('Версия сайта должна быть в формате X.Y.Z, например 1.0.0.');
        if($raw[1]>9||$raw[2]>9)throw new Exception('Вторая и третья части версии должны быть от 0 до 9. Последовательность: 1.0.9 → 1.1.0, 1.9.9 → 2.0.0.');
        $version=$raw[0].'.'.$raw[1].'.'.$raw[2];return $this->writeSiteVersion($version,'manual','','');
    }
    private function sealRollbackPackage($job){
        $tmp=$this->storage.'/temp/'.$job['id']; $m=$job['data']['manifest'];
        $rm=$job['data']['rollback_manifest']; $rm['source_job_id']=$job['id']; $rm['sealed_at']=UMEUtil::now();
        UMEUtil::jsonWriteAtomic($tmp.'/rollback/manifest.json',$rm);
        $name='rollback-'.$m['patch_id'].'-'.$job['id'].'.zip'; $path=$this->storage.'/rollbacks/'.$name;
        $zip=new ZipArchive(); if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new Exception('Не удалось создать rollback-пакет.');
        $this->addDirectoryToZip($zip,$tmp.'/rollback',''); $zip->close();
        if(!is_file($path)||filesize($path)<1)throw new Exception('Rollback-пакет не был сохранён.');
        $job['data']['rollback_file']=$name;
        $this->saveRollbackRecord($m['patch_id'],array('patch_id'=>$m['patch_id'],'name'=>$m['name'],'version'=>$m['version'],'rollback_file'=>$name,'source_job_id'=>$job['id'],'created_at'=>UMEUtil::now(),'status'=>'prepared'));
        $this->log($job,'Rollback-пакет создан до изменения сайта: '.$name,'INFO');
        return $job;
    }
    private function clearCacheTree($dir){
        if(!is_dir($dir))return 0; $n=0; $items=@scandir($dir); if(!is_array($items))return 0;
        foreach($items as $item){ if($item==='.'||$item==='..'||$item==='.htaccess'||$item==='index.html')continue; $p=$dir.'/'.$item; if(is_dir($p)&&!is_link($p))$n+=$this->clearCacheTree($p); else if(is_file($p)||is_link($p)){if(@unlink($p))$n++;} }
        return $n;
    }
    private function clearCmsCache($job){
        $dirs=isset($this->config['cache_directories'])&&is_array($this->config['cache_directories'])?$this->config['cache_directories']:array();$seen=array();$total=0;$root=str_replace('\\','/',$this->root);
        foreach($dirs as $dir){$candidate=$this->root.'/'.trim(str_replace('\\','/',$dir),'/');$real=is_dir($candidate)?realpath($candidate):false;if(!$real)continue;$real=str_replace('\\','/',$real);if($real!==$root&&!UMEUtil::startsWith($real,$root.'/'))continue;if(isset($seen[$real]))continue;$seen[$real]=1;$total+=$this->clearCacheTree($real);}
        if($job)$this->log($job,'Очищен кэш '.$this->cms['name'].': удалено файлов '.$total,'INFO');return $total;
    }
    private function runActions($job,$actions){
        if(!is_array($actions))return;if(!empty($actions['clear_cache'])||!empty($actions['clear_opencart_cache']))$this->clearCmsCache($job);
    }
    private function findRollbackRecord($patchId){
        if(!UMEUtil::safeId($patchId))return null;
        $applied=UMEUtil::jsonRead($this->storage.'/applied.json',array()); if(!empty($applied[$patchId])&&!empty($applied[$patchId]['rollback_file'])&&is_file($this->storage.'/rollbacks/'.$applied[$patchId]['rollback_file']))return $applied[$patchId];
        $history=UMEUtil::jsonRead($this->storage.'/history.json',array()); for($i=count($history)-1;$i>=0;$i--){$r=$history[$i];if(isset($r['patch_id'])&&$r['patch_id']===$patchId&&!empty($r['rollback_file'])&&is_file($this->storage.'/rollbacks/'.$r['rollback_file']))return $r;}
        $idx=$this->rollbackIndex(); if(!empty($idx[$patchId])&&!empty($idx[$patchId]['rollback_file'])&&is_file($this->storage.'/rollbacks/'.$idx[$patchId]['rollback_file']))return $idx[$patchId];
        $matches=glob($this->storage.'/rollbacks/rollback-'.$patchId.'-*.zip'); if($matches){usort($matches,function($a,$b){return filemtime($b)-filemtime($a);});return array('patch_id'=>$patchId,'rollback_file'=>basename($matches[0]));}
        return null;
    }
    private function stepInstall($job){
        $tmp=$this->storage.'/temp/'.$job['id']; $m=$job['data']['manifest']; $zipFile=$this->storage.'/uploads/'.$job['data']['upload_id'].'.zip';
        if($job['cancel_requested']&&$job['phase']!=='rollback_files'){ if($job['changes_started']){$job['status']='rolling_back';$job['phase']='rollback_files';$job['message']='Прерывание принято. Возвращаем изменения патча.';return $job;} $job['status']='cancelled';$job['phase']='cancelled';$job['progress']=100;$job['message']='Установка прервана';$this->cleanupJobTemp($job);return $job; }
        if($job['phase']==='created'){ $job['phase']='backup_files'; $job['progress']=3; $job['message']='Точечная копия изменяемых файлов'; if(!empty($job['data']['validation_override'])){$messages=array();foreach($job['data']['validation_warnings'] as $warning)if(is_array($warning)&&!empty($warning['message']))$messages[]=$warning['message'];$this->log($job,'Проверка совместимости принудительно продолжена владельцем: '.implode(' | ',$messages),'WARN');}else $this->log($job,'Патч проверен, начато создание rollback-пакета','INFO'); return $job; }
        $targets=array();$deleteTargetSet=array();foreach($m['files'] as $f)$targets[]=$f['target'];foreach($m['delete'] as $p){$targets[]=$p;$normalizedDelete=UMEUtil::normalizeRelativePath($p);if($normalizedDelete!==false)$deleteTargetSet[$normalizedDelete]=true;}$targets=array_values(array_unique($targets));
        if($job['phase']==='backup_files'){
            $i=(int)$job['data']['file_index']; $end=min(count($targets),$i+$this->config['limits']['files_per_step']);
            for(;$i<$end;$i++){ $p=UMEUtil::normalizeRelativePath($targets[$i]);$p=$this->assertSafeTargetPath($p,isset($deleteTargetSet[$p]));$abs=$this->root.'/'.$p; if(is_file($abs)){ $dest=$tmp.'/rollback/original/'.$p; UMEUtil::ensureDir(dirname($dest)); if(!copy($abs,$dest))throw new Exception('Не удалось сохранить исходный файл: '.$p); $job['data']['rollback_manifest']['original'][]=$p; } else $job['data']['rollback_manifest']['absent'][]=$p; }
            $job['data']['file_index']=$end; $job['progress']=3+(count($targets)?(int)floor(14*$end/count($targets)):14); $job['message']='Резервирование файлов: '.$end.' из '.count($targets);
            if($end>=count($targets)){ $job['data']['file_index']=0; $job['phase']=count($m['migrations'])?'backup_database':'seal_rollback'; $job['progress']=17; }
            return $job;
        }
        if($job['phase']==='backup_database'){
            $tables=array(); foreach($m['migrations'] as $mi)foreach($mi['tables'] as $t)$tables[]=$t; $tables=array_values(array_unique($tables));
            if(!isset($job['data']['db_tables'])){$job['data']['db_tables']=$tables;$job['data']['db_table_index']=0;$job['data']['db_row_offset']=0;$this->writeExact($tmp.'/rollback/database.sql',"SET FOREIGN_KEY_CHECKS=0;\n",0,'SQL-дамп');}
            $db=$this->database(); $idx=(int)$job['data']['db_table_index'];
            if($idx>=count($tables)){$this->writeExact($tmp.'/rollback/database.sql',"SET FOREIGN_KEY_CHECKS=1;\n",FILE_APPEND,'SQL-дамп');$job['phase']='seal_rollback';$job['progress']=32;return $job;}
            $table=str_replace('{{DB_PREFIX}}',$db->prefix(),$tables[$idx]); $off=(int)$job['data']['db_row_offset'];
            if($off===0 && !$db->tableExists($table)){
                $this->writeExact($tmp.'/rollback/database.sql','DROP TABLE IF EXISTS '.UMEUtil::sqlIdentifier($table).";\n",FILE_APPEND,'SQL-дамп');$job['data']['rollback_manifest']['absent_tables'][]=$table;$job['data']['db_table_index']=$idx+1;$job['data']['db_row_offset']=0;$job['message']='До патча таблица отсутствовала: '.$table;return $job;
            }
            if($off===0){$create=$db->createSql($table);$this->writeExact($tmp.'/rollback/database.sql','DROP TABLE IF EXISTS '.UMEUtil::sqlIdentifier($table).";\n".$create.";\n",FILE_APPEND,'SQL-дамп');}
            $rows=$db->rowsChunk($table,$off,$this->config['limits']['db_rows_per_step']); if($rows){$this->writeExact($tmp.'/rollback/database.sql',$db->insertSql($table,$rows),FILE_APPEND,'SQL-дамп');$job['data']['db_row_offset']=$off+count($rows);} if(count($rows)<$this->config['limits']['db_rows_per_step']){$job['data']['rollback_manifest']['tables'][]=$table;$job['data']['db_table_index']=$idx+1;$job['data']['db_row_offset']=0;}
            $job['progress']=17+(count($tables)?(int)floor(15*$job['data']['db_table_index']/count($tables)):15);$job['message']='Резервирование таблицы '.$table;return $job;
        }
        if($job['phase']==='seal_rollback'){
            $job=$this->sealRollbackPackage($job); $job['phase']='apply_files'; $job['progress']=35; $job['message']='Rollback-пакет сохранён. Применение файлов.'; return $job;
        }
        if($job['phase']==='apply_files'){
            $zip=new ZipArchive(); if($zip->open($zipFile)!==true)throw new Exception('Не удалось открыть патч.'); $i=(int)$job['data']['file_index']; $end=min(count($m['files']),$i+$this->config['limits']['files_per_step']);
            for(;$i<$end;$i++){ $f=$m['files'][$i]; $target=$this->assertSafeTargetPath($f['target'],false); $content=$zip->getFromName($f['source']); if($content===false||hash('sha256',$content)!==$f['sha256'])throw new Exception('Файл патча повреждён: '.$f['source']); $abs=$this->root.'/'.$target; UMEUtil::ensureDir(dirname($abs)); $temp=$abs.'.ume-tmp-'.UMEUtil::randomHex(4); if(file_put_contents($temp,$content,LOCK_EX)===false)throw new Exception('Не удалось записать '.$target); if(is_file($abs))@chmod($temp,fileperms($abs)&0777); $this->persistChangesStarted($job); if(!@rename($temp,$abs)){@unlink($temp);throw new Exception('Не удалось заменить '.$target);} $this->log($job,'Установлен файл '.$target,'INFO'); }
            $zip->close();$job['data']['file_index']=$end;$job['progress']=35+(count($m['files'])?(int)floor(27*$end/count($m['files'])):27);$job['message']='Установка файлов: '.$end.' из '.count($m['files']);if($end>=count($m['files'])){$job['phase']='delete_files';$job['data']['delete_index']=0;}return $job;
        }
        if($job['phase']==='delete_files'){
            $i=(int)$job['data']['delete_index'];$end=min(count($m['delete']),$i+$this->config['limits']['files_per_step']);for(;$i<$end;$i++){ $p=$this->assertSafeTargetPath($m['delete'][$i],true);$abs=$this->root.'/'.$p;$this->persistChangesStarted($job);if(is_file($abs)||is_link($abs))@unlink($abs);else if(is_dir($abs))UMEUtil::removeTree($abs);$this->log($job,'Удалён '.$p,'INFO');}
            $job['data']['delete_index']=$end;$job['progress']=62+(count($m['delete'])?(int)floor(8*$end/count($m['delete'])):8);$job['message']='Удаление файлов: '.$end.' из '.count($m['delete']);if($end>=count($m['delete']))$job['phase']='migrations';return $job;
        }
        if($job['phase']==='migrations'){
            $i=(int)$job['data']['migration_index']; if($i<count($m['migrations'])){ $zip=new ZipArchive();if($zip->open($zipFile)!==true)throw new Exception('Не удалось открыть патч.');$mi=$m['migrations'][$i];$content=$zip->getFromName($mi['source']);$zip->close();$sqlFile=$tmp.'/migration-'.$i.'.sql';$this->writeExact($sqlFile,$content,LOCK_EX,'SQL-миграцию '.$mi['id']);$this->persistChangesStarted($job);$db=$this->database();$db->executeSqlFile($sqlFile);$job['data']['migration_index']=$i+1;$this->log($job,'Выполнена миграция '.$mi['id'],'INFO'); }
            $job['progress']=70+(count($m['migrations'])?(int)floor(14*$job['data']['migration_index']/count($m['migrations'])):14);$job['message']='SQL-миграции: '.$job['data']['migration_index'].' из '.count($m['migrations']);if($job['data']['migration_index']>=count($m['migrations']))$job['phase']='post_actions';return $job;
        }
        if($job['phase']==='post_actions'){
            if(empty($job['data']['post_actions_done'])){$this->runActions($job,isset($m['actions'])?$m['actions']:array());$job['data']['post_actions_done']=true;}
            $job['phase']='verify';$job['progress']=92;$job['message']='Дополнительные действия выполнены';return $job;
        }
        if($job['phase']==='verify'){
            foreach($m['files'] as $f){$target=UMEUtil::normalizeRelativePath($f['target']);$abs=$this->root.'/'.$target;if(!is_file($abs)||hash_file('sha256',$abs)!==$f['sha256'])throw new Exception('Проверка установленного файла не пройдена: '.$target);}
            $rollbackFile=$job['data']['rollback_file']; if(!$rollbackFile||!is_file($this->storage.'/rollbacks/'.$rollbackFile))throw new Exception('Rollback-пакет отсутствует после установки.');
            $installedAt=UMEUtil::now();
            $siteVersionBefore=isset($job['data']['site_version_before'])?$this->normalizeSiteVersion($job['data']['site_version_before']):false;$siteVersionAfter=isset($job['data']['site_version_after'])?$this->normalizeSiteVersion($job['data']['site_version_after']):false;
            if($siteVersionBefore===false||$siteVersionAfter===false||$siteVersionAfter!==$m['version'])throw new Exception('Потерян контекст версии сайта для установки патча.');
            $applied=UMEUtil::jsonRead($this->storage.'/applied.json',array());$applied[$m['patch_id']]=array('installed_at'=>$installedAt,'job_id'=>$job['id'],'rollback_file'=>$rollbackFile,'name'=>$m['name'],'version'=>$m['version'],'site_version'=>$siteVersionAfter,'site_version_before'=>$siteVersionBefore,'status'=>'installed','validation_override'=>!empty($job['data']['validation_override']),'validation_warnings'=>isset($job['data']['validation_warnings'])?$job['data']['validation_warnings']:array());UMEUtil::jsonWriteAtomic($this->storage.'/applied.json',$applied);
            $this->updateRollbackRecord($m['patch_id'],array('status'=>'installed','installed_at'=>$installedAt));
            $history=UMEUtil::jsonRead($this->storage.'/history.json',array());$history[]=array('patch_id'=>$m['patch_id'],'name'=>$m['name'],'version'=>$m['version'],'site_version'=>$siteVersionAfter,'site_version_before'=>$siteVersionBefore,'installed_at'=>$installedAt,'job_id'=>$job['id'],'rollback_file'=>$rollbackFile,'status'=>'installed','validation_override'=>!empty($job['data']['validation_override']),'validation_warnings'=>isset($job['data']['validation_warnings'])?$job['data']['validation_warnings']:array());UMEUtil::jsonWriteAtomic($this->storage.'/history.json',$history);
            $this->writeSiteVersion($siteVersionAfter,'patch',$m['patch_id'],$m['name']);
            $job['status']='completed';$job['phase']='completed';$job['progress']=100;$job['message']='Патч успешно установлен. Версия сайта: '.$siteVersionAfter;$this->log($job,'Патч установлен: '.$m['patch_id'].'; rollback: '.$rollbackFile.'; версия сайта '.$siteVersionBefore.' → '.$siteVersionAfter,'INFO');$this->cleanupJobTemp($job);return $job;
        }
        if($job['phase']==='rollback_files') return $this->rollbackInstallJob($job);
        return $job;
    }
    private function restoreRollbackDirectory($job,$dir){
        $m=UMEUtil::jsonRead($dir.'/manifest.json',array('original'=>array(),'absent'=>array(),'tables'=>array(),'absent_tables'=>array(),'actions_after_restore'=>array()));
        foreach($m['original'] as $p){$p=$this->assertSafeTargetPath($p,false);$src=$dir.'/original/'.$p;$dst=$this->root.'/'.$p;if(is_file($src)){UMEUtil::ensureDir(dirname($dst));if(!copy($src,$dst))throw new Exception('Не удалось восстановить файл: '.$p);}}
        foreach($m['absent'] as $p){$p=$this->assertSafeTargetPath($p,true);$dst=$this->root.'/'.$p;if(is_file($dst)||is_link($dst))@unlink($dst);else if(is_dir($dst))UMEUtil::removeTree($dst);}
        if(is_file($dir.'/database.sql')){$db=$this->database();$db->executeSqlFile($dir.'/database.sql');}
        $this->runActions($job,isset($m['actions_after_restore'])?$m['actions_after_restore']:array()); return $m;
    }
    private function rollbackInstallJob($job){
        $tmp=$this->storage.'/temp/'.$job['id'];
        if(!is_file($tmp.'/rollback/manifest.json')&&!empty($job['data']['rollback_file'])){ $zip=new ZipArchive();if($zip->open($this->storage.'/rollbacks/'.$job['data']['rollback_file'])!==true)throw new Exception('Rollback-пакет для автоматического отката не найден.');$zip->extractTo($tmp.'/rollback');$zip->close(); }
        $this->restoreRollbackDirectory($job,$tmp.'/rollback');
        $patchId=$job['data']['manifest']['patch_id'];$this->updateRollbackRecord($patchId,array('status'=>'auto_rolled_back','rolled_back_at'=>UMEUtil::now()));
        if(!empty($job['data']['last_error'])){$job['status']='failed';$job['phase']='failed';$job['progress']=100;$job['message']='Ошибка установки, изменения полностью откачены: '.$job['data']['last_error'];$this->log($job,'Автоматический откат после ошибки завершён','WARN');}
        else {$job['status']='cancelled';$job['phase']='cancelled';$job['progress']=100;$job['message']='Установка отменена, изменения откачены';$this->log($job,'Изменения откачены после отмены','WARN');}
        $this->cleanupJobTemp($job);return $job;
    }
    public function startRollback($patchId){
        if(!UMEUtil::safeId($patchId))throw new Exception('Некорректный patch_id.'); $r=$this->findRollbackRecord($patchId); if(!$r)throw new Exception('Rollback-пакет для этого патча не найден.');
        return $this->newJob('rollback',array('patch_id'=>$patchId,'rollback_file'=>$r['rollback_file'],'phase_index'=>0));
    }
    private function stepRollback($job){
        $tmp=$this->storage.'/temp/'.$job['id'];
        if($job['phase']==='created'){ $zip=new ZipArchive();if($zip->open($this->storage.'/rollbacks/'.$job['data']['rollback_file'])!==true)throw new Exception('Rollback архив не найден: '.$job['data']['rollback_file']);$zip->extractTo($tmp.'/rollback');$zip->close();$job['phase']='restore';$job['progress']=20;$job['message']='Rollback-пакет распакован';return $job;}
        if($job['phase']==='restore'){
            $this->restoreRollbackDirectory($job,$tmp.'/rollback');
            $patchId=$job['data']['patch_id'];$applied=UMEUtil::jsonRead($this->storage.'/applied.json',array());$wasApplied=isset($applied[$patchId]);$appliedRecord=$wasApplied&&is_array($applied[$patchId])?$applied[$patchId]:array();$patchName=$wasApplied&&isset($appliedRecord['name'])?(string)$appliedRecord['name']:'';unset($applied[$patchId]);UMEUtil::jsonWriteAtomic($this->storage.'/applied.json',$applied);
            $rolledAt=UMEUtil::now();$extra=array('rolled_back_at'=>$rolledAt);$versionNote='';
            if($wasApplied){$current=$this->siteVersion();$savedBefore=isset($appliedRecord['site_version_before'])?$this->normalizeSiteVersion($appliedRecord['site_version_before']):false;$lowered=$savedBefore!==false?$savedBefore:$this->shiftSiteVersion($current['version'],-1);$this->writeSiteVersion($lowered,'rollback',$patchId,$patchName);$extra['site_version_after_rollback']=$lowered;$versionNote='; версия сайта '.$current['version'].' → '.$lowered;}
            $this->updateRollbackRecord($patchId,array('status'=>'rolled_back','rolled_back_at'=>$rolledAt));$this->updateHistoryStatus($patchId,'rolled_back',$extra);
            $job['status']='completed';$job['phase']='completed';$job['progress']=100;$job['message']='Откат выполнен, файлы и БД восстановлены'.($versionNote!==''?'. Версия сайта: '.$extra['site_version_after_rollback']:'');$this->log($job,'Откат выполнен для '.$patchId.$versionNote,'INFO');$this->cleanupJobTemp($job);return $job;
        }
        return $job;
    }
    private function addDirectoryToZip($zip,$dir,$prefix){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));foreach($it as $f){if($f->isFile()){$rel=str_replace('\\','/',substr($f->getPathname(),strlen($dir)+1));$zip->addFile($f->getPathname(),$prefix.$rel);}}}
    private function cleanupJobTemp($job){ UMEUtil::removeTree($this->storage.'/temp/'.$job['id']); }
    private function pruneBackups(){ $s=$this->settings();$files=glob($this->storage.'/backups/*.zip');usort($files,function($a,$b){return filemtime($b)-filemtime($a);});for($i=(int)$s['backup_retention'];$i<count($files);$i++){@unlink($this->storage.'/public-backups/'.basename($files[$i]));@unlink($files[$i]);} }
    public function dashboard(){
        $backups=array();foreach(glob($this->storage.'/backups/*.zip') as $f){$row=array('id'=>basename($f),'name'=>basename($f),'size'=>filesize($f),'mtime'=>filemtime($f),'public_available'=>is_file($this->storage.'/public-backups/'.basename($f)));if(class_exists('ZipArchive')){$z=new ZipArchive();if($z->open($f)===true){$raw=$z->getFromName('manifest.json');$z->close();$m=$raw!==false?json_decode($raw,true):null;if(is_array($m)){if(isset($m['file_mode']))$row['file_mode']=(string)$m['file_mode'];if(isset($m['database_mode']))$row['database_mode']=(string)$m['database_mode'];if(array_key_exists('database_included',$m))$row['database_included']=!empty($m['database_included']);if(isset($m['format']))$row['format']=(string)$m['format'];if(isset($m['site_version']))$row['site_version']=$this->normalizeSiteVersion($m['site_version']);if(isset($m['next_patch_version']))$row['next_patch_version']=$this->normalizeSiteVersion($m['next_patch_version']);}}}$backups[]=$row;}usort($backups,function($a,$b){return $b['mtime']-$a['mtime'];});
        $logs=array();foreach(glob($this->storage.'/logs/*.log') as $f)$logs[]=array('id'=>basename($f),'name'=>basename($f),'size'=>filesize($f),'mtime'=>filemtime($f));usort($logs,function($a,$b){return $b['mtime']-$a['mtime'];});
        $jobs=array();foreach(glob($this->storage.'/jobs/*.json') as $f){$j=UMEUtil::jsonRead($f,array());if(!$j)continue;$row=array('id'=>$j['id'],'type'=>$j['type'],'status'=>$j['status'],'phase'=>$j['phase'],'message'=>$j['message'],'created_at'=>$j['created_at'],'updated_at'=>$j['updated_at']);if($j['type']==='migration_export'&&$j['status']==='completed'&&!empty($j['data']['migration_id'])&&!empty($j['data']['expires_at'])){$expires=@strtotime((string)$j['data']['expires_at']);$row['migration_expires_at']=$j['data']['expires_at'];$row['migration_link_available']=$expires!==false&&$expires>time()&&is_dir($this->storage.'/migrations/'.$j['data']['migration_id'])&&is_file($this->storage.'/migration-secrets/'.$j['id'].'.secret');}$jobs[]=$row;}usort($jobs,function($a,$b){return strcmp($b['created_at'],$a['created_at']);});
        $history=UMEUtil::jsonRead($this->storage.'/history.json',array());foreach($history as &$h){if(empty($h['status']))$h['status']='installed';$h['rollback_available']=!empty($h['rollback_file'])&&is_file($this->storage.'/rollbacks/'.$h['rollback_file']);}unset($h);
        $db=$this->databaseConfig(false);$dbReady=!empty($db['available'])&&isset($db['engine'])&&$db['engine']==='mysql'&&extension_loaded('mysqli');
        return array('active_job'=>$this->publicJob($this->activeJob()),'site_version'=>$this->siteVersion(),'backups'=>$backups,'history'=>array_reverse($history),'jobs'=>$jobs,'logs'=>$logs,'settings'=>$this->settings(),'database_connection'=>$this->databaseConnectionSettings(),'site'=>array('site_id'=>$this->config['site_id'],'project'=>$this->config['project'],'domain'=>UMEUtil::domain(),'php'=>PHP_VERSION,'root'=>$this->root,'cms'=>$this->cms,'diagnostics'=>array('cms_detected'=>$this->cms['id']!=='generic','cms_supported'=>!isset($this->cms['supported'])||!empty($this->cms['supported']),'database_available'=>$dbReady,'database_engine'=>isset($db['engine'])?$db['engine']:'unknown','mysqli'=>extension_loaded('mysqli'),'zip'=>class_exists('ZipArchive'),'openssl'=>extension_loaded('openssl'),'json'=>extension_loaded('json'),'session'=>extension_loaded('session'),'storage_writable'=>is_writable($this->storage),'config_found'=>is_file($this->root.'/upgrade/config.local.php'))));
    }
    public function publicBackupDownload($id){
        $id=(string)$id;if(!UMEUtil::safeId($id)||!preg_match('/\.zip$/i',$id))throw new Exception('Некорректный ID резервной копии.');$file=$this->storage.'/public-backups/'.$id;if(!is_file($file)){if(is_file($this->storage.'/backups/'.$id))throw new Exception('Для этой резервной копии нет очищенной публичной версии. Создайте backup заново в Upgrade Manager 3.2.4+.');throw new Exception('Резервная копия не найдена.');}return $file;
    }
    private function publicBackupTextPath($path){
        $path=str_replace('\\','/',(string)$path);$base=strtolower(basename($path));$exact=array('.htaccess','.gitignore','.gitattributes','.editorconfig','.env','dockerfile','makefile','procfile','license','readme');if(in_array($base,$exact,true))return true;if(UMEUtil::startsWith($base,'.env.'))return true;$ext=strtolower(pathinfo($base,PATHINFO_EXTENSION));return in_array($ext,array('php','php3','php4','php5','php7','php8','phtml','inc','module','theme','js','mjs','cjs','jsx','ts','tsx','css','scss','sass','less','html','htm','xhtml','twig','tpl','latte','volt','json','jsonl','ndjson','xml','xsl','xslt','yml','yaml','md','markdown','txt','sql','ini','conf','config','cnf','env','dist','example','sh','bash','zsh','fish','ps1','bat','cmd','py','rb','pl','pm','java','kt','kts','go','rs','c','h','cc','cpp','cxx','hpp','hxx','cs','vb','swift','vue','svelte','graphql','gql','csv','tsv','properties','toml','lock','log','po','pot'),true);
    }
    private function publicBackupOuterPreviewAllowed($entry){
        return in_array((string)$entry,array('manifest.json','files-manifest.json','metadata.json','database.sql','00_START_HERE_FOR_GPT.md','AGENTS.md','PATCH_AUTHORING_RU.md','PATCH_FORMAT_RU.md','AGENT_WORKFLOW_RU.md','patch-profile.json','PATCH_UNSIGNED_MANIFEST_TEMPLATE.json','PATCH_SIGNATURE_EXAMPLE.json','PATCH_BUILDER.py','upgrade-source-manifest.json'),true);
    }
    private function publicBackupZipJson($zip,$entry,$default){
        $raw=$zip->getFromName($entry);if($raw===false)return $default;$data=json_decode($raw,true);return is_array($data)?$data:$default;
    }
    public function publicBackupShareInfo($id,$query,$page,$perPage){
        $file=$this->publicBackupDownload($id);if(!class_exists('ZipArchive'))throw new Exception('Для просмотра резервной копии требуется ZipArchive.');$zip=new ZipArchive();if($zip->open($file)!==true)throw new Exception('Не удалось открыть резервную копию.');
        $manifest=$this->publicBackupZipJson($zip,'manifest.json',array());$metadata=$this->publicBackupZipJson($zip,'metadata.json',array());$rows=$this->publicBackupZipJson($zip,'files-manifest.json',array());$outer=array();foreach(array('manifest.json','metadata.json','files-manifest.json','database.sql','00_START_HERE_FOR_GPT.md','AGENTS.md','PATCH_AUTHORING_RU.md','PATCH_FORMAT_RU.md','AGENT_WORKFLOW_RU.md','patch-profile.json','PATCH_UNSIGNED_MANIFEST_TEMPLATE.json','PATCH_SIGNATURE_EXAMPLE.json','PATCH_BUILDER.py','upgrade-source-manifest.json') as $name){$stat=$zip->statName($name);if(is_array($stat))$outer[]=array('path'=>$name,'size'=>isset($stat['size'])?(int)$stat['size']:0,'previewable'=>$this->publicBackupOuterPreviewAllowed($name));}$siteStat=$zip->statName('site-files.zip');$zip->close();
        if(!is_array($rows))$rows=array();$query=trim((string)$query);if(strlen($query)>160)$query=substr($query,0,160);$filtered=array();foreach($rows as $row){if(!is_array($row)||empty($row['path']))continue;$path=UMEUtil::normalizeRelativePath($row['path']);if($path===false)continue;if($query!==''&&stripos($path,$query)===false)continue;$filtered[]=array('path'=>$path,'size'=>isset($row['size'])?(int)$row['size']:0,'sha256'=>isset($row['sha256'])?(string)$row['sha256']:'','previewable'=>$this->publicBackupTextPath($path));}
        usort($filtered,function($a,$b){return strcmp($a['path'],$b['path']);});$perPage=max(20,min(500,(int)$perPage));$total=count($filtered);$pages=max(1,(int)ceil($total/$perPage));$page=max(1,min($pages,(int)$page));$slice=array_slice($filtered,($page-1)*$perPage,$perPage);
        return array('id'=>$id,'name'=>basename($file),'size'=>filesize($file),'mtime'=>filemtime($file),'manifest'=>$manifest,'metadata'=>$metadata,'outer_entries'=>$outer,'site_archive_present'=>is_array($siteStat),'site_files_total'=>count($rows),'query'=>$query,'files_total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$perPage,'files'=>$slice);
    }
    private function publicBackupReadZipStreamChunk($zip,$entry,$offset,$limit){
        $stat=$zip->statName($entry);if(!is_array($stat))throw new Exception('Файл не найден в резервной копии.');$size=isset($stat['size'])?(int)$stat['size']:0;$offset=max(0,min($size,(int)$offset));$limit=max(4096,min(524288,(int)$limit));$stream=$zip->getStream($entry);if(!$stream)throw new Exception('Не удалось открыть файл внутри резервной копии.');$remaining=$offset;if($remaining>0){$seek=@fseek($stream,$remaining,SEEK_SET);if($seek!==0){while($remaining>0&&!feof($stream)){$skip=fread($stream,min(65536,$remaining));if($skip===false||$skip==='')break;$remaining-=strlen($skip);}}}$content='';while(strlen($content)<$limit&&!feof($stream)){$part=fread($stream,min(65536,$limit-strlen($content)));if($part===false||$part==='')break;$content.=$part;}fclose($stream);$next=$offset+strlen($content);return array('content'=>$content,'size'=>$size,'offset'=>$offset,'next_offset'=>$next<$size?$next:null);
    }
    public function publicBackupOuterTextChunk($id,$entry,$offset,$limit){
        $file=$this->publicBackupDownload($id);$entry=(string)$entry;if(!$this->publicBackupOuterPreviewAllowed($entry))throw new Exception('Этот файл нельзя открыть через публичный просмотр.');if(!class_exists('ZipArchive'))throw new Exception('Для просмотра резервной копии требуется ZipArchive.');$zip=new ZipArchive();if($zip->open($file)!==true)throw new Exception('Не удалось открыть резервную копию.');$chunk=$this->publicBackupReadZipStreamChunk($zip,$entry,$offset,$limit);$zip->close();$chunk['path']=$entry;$chunk['source']='backup';return $chunk;
    }
    private function publicBackupReadAt($fh,$offset,$length){
        if($length<0||@fseek($fh,$offset,SEEK_SET)!==0)throw new Exception('Не удалось прочитать структуру ZIP.');$data='';while(strlen($data)<$length&&!feof($fh)){$part=fread($fh,min(1048576,$length-strlen($data)));if($part===false||$part==='')break;$data.=$part;}if(strlen($data)!==$length)throw new Exception('ZIP-файл обрезан или повреждён.');return $data;
    }
    private function publicBackupU16($data,$offset){$v=unpack('vvalue',substr($data,$offset,2));return (int)$v['value'];}
    private function publicBackupU32($data,$offset){$v=unpack('Vvalue',substr($data,$offset,4));return (int)$v['value'];}
    private function publicBackupZipDirectory($file,$base,$size){
        $fh=@fopen($file,'rb');if(!$fh)throw new Exception('Не удалось открыть ZIP.');try{$tailLength=min((int)$size,66000);$tail=$this->publicBackupReadAt($fh,$base+$size-$tailLength,$tailLength);$eocd=strrpos($tail,"PK\x05\x06");if($eocd===false)throw new Exception('Не найден каталог ZIP.');if(strlen($tail)-$eocd<22)throw new Exception('Каталог ZIP повреждён.');$entries=$this->publicBackupU16($tail,$eocd+10);$cdSize=$this->publicBackupU32($tail,$eocd+12);$cdOffset=$this->publicBackupU32($tail,$eocd+16);if($entries===65535||$cdSize===4294967295||$cdOffset===4294967295)throw new Exception('ZIP64 требует резервного способа просмотра.');if($cdOffset<0||$cdSize<0||$cdOffset+$cdSize>$size)throw new Exception('Каталог ZIP выходит за границы файла.');$cd=$this->publicBackupReadAt($fh,$base+$cdOffset,$cdSize);$pos=0;$out=array();$count=0;while($pos+46<=strlen($cd)&&substr($cd,$pos,4)==="PK\x01\x02"){$flags=$this->publicBackupU16($cd,$pos+8);$method=$this->publicBackupU16($cd,$pos+10);$crc=$this->publicBackupU32($cd,$pos+16);$comp=$this->publicBackupU32($cd,$pos+20);$uncomp=$this->publicBackupU32($cd,$pos+24);$nameLen=$this->publicBackupU16($cd,$pos+28);$extraLen=$this->publicBackupU16($cd,$pos+30);$commentLen=$this->publicBackupU16($cd,$pos+32);$local=$this->publicBackupU32($cd,$pos+42);$end=$pos+46+$nameLen+$extraLen+$commentLen;if($end>strlen($cd))throw new Exception('Каталог ZIP повреждён.');$name=substr($cd,$pos+46,$nameLen);$out[$name]=array('name'=>$name,'flags'=>$flags,'method'=>$method,'crc'=>$crc,'compressed_size'=>$comp,'size'=>$uncomp,'local_offset'=>$local);$pos=$end;$count++;if($count>$entries+10)break;}return $out;}finally{fclose($fh);}
    }
    private function publicBackupZipEntryDataOffset($file,$base,$entry){
        $fh=@fopen($file,'rb');if(!$fh)throw new Exception('Не удалось открыть ZIP.');try{$head=$this->publicBackupReadAt($fh,$base+(int)$entry['local_offset'],30);if(substr($head,0,4)!=="PK\x03\x04")throw new Exception('Локальный заголовок ZIP повреждён.');$nameLen=$this->publicBackupU16($head,26);$extraLen=$this->publicBackupU16($head,28);return $base+(int)$entry['local_offset']+30+$nameLen+$extraLen;}finally{fclose($fh);}
    }
    private function publicBackupExtractOuterEntryTemp($outerFile,$entry){
        if(!class_exists('ZipArchive'))throw new Exception('Для резервного просмотра ZIP64 требуется ZipArchive.');$zip=new ZipArchive();if($zip->open($outerFile)!==true)throw new Exception('Не удалось открыть резервную копию.');$stream=$zip->getStream($entry);if(!$stream){$zip->close();throw new Exception('В резервной копии отсутствует '.$entry.'.');}$tmp=@tempnam($this->storage.'/temp','ume-public-');if($tmp===false){fclose($stream);$zip->close();throw new Exception('Не удалось создать временный файл просмотра.');}$out=@fopen($tmp,'wb');if(!$out){@unlink($tmp);fclose($stream);$zip->close();throw new Exception('Не удалось создать временный файл просмотра.');}while(!feof($stream)){$buf=fread($stream,1048576);if($buf===false){fclose($stream);fclose($out);$zip->close();@unlink($tmp);throw new Exception('Ошибка чтения вложенного ZIP.');}if($buf!==''&&fwrite($out,$buf)===false){fclose($stream);fclose($out);$zip->close();@unlink($tmp);throw new Exception('Ошибка записи временного ZIP.');}}fclose($stream);fclose($out);$zip->close();return $tmp;
    }
    private function publicBackupStreamToTemp($stream,$prefix){
        $tmp=@tempnam($this->storage.'/temp',$prefix);if($tmp===false)throw new Exception('Не удалось создать временный файл.');$out=@fopen($tmp,'wb');if(!$out){@unlink($tmp);throw new Exception('Не удалось создать временный файл.');}try{while(!feof($stream)){$buf=fread($stream,1048576);if($buf===false)throw new Exception('Ошибка чтения файла из резервной копии.');if($buf!==''&&fwrite($out,$buf)===false)throw new Exception('Ошибка записи временного файла.');}}catch(Exception $e){fclose($out);@unlink($tmp);throw $e;}fclose($out);return $tmp;
    }
    public function publicBackupOuterRawTemp($id,$entry){
        $file=$this->publicBackupDownload($id);$entry=(string)$entry;if(!$this->publicBackupOuterPreviewAllowed($entry))throw new Exception('Этот служебный файл недоступен.');if(!class_exists('ZipArchive'))throw new Exception('Для чтения резервной копии требуется ZipArchive.');$zip=new ZipArchive();if($zip->open($file)!==true)throw new Exception('Не удалось открыть резервную копию.');$stat=$zip->statName($entry);if(!is_array($stat)){$zip->close();throw new Exception('Файл не найден в резервной копии.');}$stream=$zip->getStream($entry);if(!$stream){$zip->close();throw new Exception('Не удалось открыть файл внутри резервной копии.');}try{$tmp=$this->publicBackupStreamToTemp($stream,'ume-outer-');}catch(Exception $e){fclose($stream);$zip->close();throw $e;}fclose($stream);$zip->close();return array('file'=>$tmp,'path'=>$entry,'size'=>filesize($tmp));
    }
    public function publicBackupSiteRawTemp($id,$path){
        $outerFile=$this->publicBackupDownload($id);$path=UMEUtil::normalizeRelativePath($path);if($path===false||$path==='')throw new Exception('Некорректный путь файла.');$nested=$this->publicBackupExtractOuterEntryTemp($outerFile,'site-files.zip');try{$zip=new ZipArchive();if($zip->open($nested)!==true)throw new Exception('Не удалось открыть файловый ZIP резервной копии.');$stat=$zip->statName($path);if(!is_array($stat)){$zip->close();throw new Exception('Файл не найден в файловом архиве.');}$stream=$zip->getStream($path);if(!$stream){$zip->close();throw new Exception('Не удалось открыть файл внутри файлового архива.');}try{$tmp=$this->publicBackupStreamToTemp($stream,'ume-site-');}catch(Exception $e){fclose($stream);$zip->close();throw $e;}fclose($stream);$zip->close();return array('file'=>$tmp,'path'=>$path,'size'=>filesize($tmp));}finally{@unlink($nested);}
    }
    private function publicBackupSiteSegment($outerFile){
        $size=filesize($outerFile);try{$outer=$this->publicBackupZipDirectory($outerFile,0,$size);if(!isset($outer['site-files.zip']))throw new Exception('В резервной копии отсутствует site-files.zip.');$entry=$outer['site-files.zip'];if((int)$entry['method']===0){$base=$this->publicBackupZipEntryDataOffset($outerFile,0,$entry);return array('file'=>$outerFile,'base'=>$base,'size'=>(int)$entry['size'],'temp'=>false);}}catch(Exception $e){}$tmp=$this->publicBackupExtractOuterEntryTemp($outerFile,'site-files.zip');return array('file'=>$tmp,'base'=>0,'size'=>filesize($tmp),'temp'=>true);
    }
    private function publicBackupZipArchiveNestedChunk($nestedFile,$path,$offset,$limit){
        $zip=new ZipArchive();if($zip->open($nestedFile)!==true)throw new Exception('Не удалось открыть файловый ZIP резервной копии.');try{return $this->publicBackupReadZipStreamChunk($zip,$path,$offset,$limit);}finally{$zip->close();}
    }
    public function publicBackupSiteTextChunk($id,$path,$offset,$limit){
        $outerFile=$this->publicBackupDownload($id);$path=UMEUtil::normalizeRelativePath($path);if($path===false||!$this->publicBackupTextPath($path))throw new Exception('Этот файл нельзя открыть как текст через публичный просмотр.');$segment=$this->publicBackupSiteSegment($outerFile);try{try{$dir=$this->publicBackupZipDirectory($segment['file'],$segment['base'],$segment['size']);if(!isset($dir[$path]))throw new Exception('Файл не найден в файловом архиве.');$entry=$dir[$path];$size=(int)$entry['size'];$offset=max(0,min($size,(int)$offset));$limit=max(4096,min(524288,(int)$limit));if((int)$entry['method']===0){$dataOffset=$this->publicBackupZipEntryDataOffset($segment['file'],$segment['base'],$entry);$fh=@fopen($segment['file'],'rb');if(!$fh)throw new Exception('Не удалось открыть файловый ZIP.');try{$length=min($limit,max(0,$size-$offset));$content=$length>0?$this->publicBackupReadAt($fh,$dataOffset+$offset,$length):'';}finally{fclose($fh);}$next=$offset+strlen($content);$chunk=array('content'=>$content,'size'=>$size,'offset'=>$offset,'next_offset'=>$next<$size?$next:null);}else if((int)$entry['method']===8&&$size<=8388608){$dataOffset=$this->publicBackupZipEntryDataOffset($segment['file'],$segment['base'],$entry);$fh=@fopen($segment['file'],'rb');if(!$fh)throw new Exception('Не удалось открыть файловый ZIP.');try{$compressed=$this->publicBackupReadAt($fh,$dataOffset,(int)$entry['compressed_size']);}finally{fclose($fh);}$plain=@gzinflate($compressed);if($plain===false)throw new Exception('Не удалось распаковать файл для просмотра.');$content=substr($plain,$offset,$limit);$next=$offset+strlen($content);$chunk=array('content'=>$content,'size'=>strlen($plain),'offset'=>$offset,'next_offset'=>$next<strlen($plain)?$next:null);}else{if(!$segment['temp']){$tmp=$this->publicBackupExtractOuterEntryTemp($outerFile,'site-files.zip');try{$chunk=$this->publicBackupZipArchiveNestedChunk($tmp,$path,$offset,$limit);}finally{@unlink($tmp);}}else{$chunk=$this->publicBackupZipArchiveNestedChunk($segment['file'],$path,$offset,$limit);}}}catch(Exception $e){if(!$segment['temp']){$tmp=$this->publicBackupExtractOuterEntryTemp($outerFile,'site-files.zip');try{$chunk=$this->publicBackupZipArchiveNestedChunk($tmp,$path,$offset,$limit);}finally{@unlink($tmp);}}else throw $e;}$chunk['path']=$path;$chunk['source']='site';return $chunk;}finally{if(!empty($segment['temp']))@unlink($segment['file']);}
    }
    public function download($type,$id){
        if(!UMEUtil::safeId($id))throw new Exception('Некорректный ID.');$map=array('backup'=>$this->storage.'/backups/'.$id,'log'=>$this->storage.'/logs/'.$id,'rollback'=>$this->storage.'/rollbacks/'.$id);if(!isset($map[$type])||!is_file($map[$type]))throw new Exception('Файл не найден.');return $map[$type];
    }
    public function bulkDelete($type,$ids){
        if(!is_array($ids))throw new Exception('Не выбраны строки.');$deleted=0;
        if($type==='history'){ $history=UMEUtil::jsonRead($this->storage.'/history.json',array()); $keep=array(); foreach($history as $row){ if(in_array($row['patch_id'],$ids,true))$deleted++; else $keep[]=$row; } UMEUtil::jsonWriteAtomic($this->storage.'/history.json',$keep); return $deleted; }
        $active=$this->activeJob(); foreach($ids as $id){if(!UMEUtil::safeId($id))continue;if($type==='backup'){$f=$this->storage.'/backups/'.$id;@unlink($this->storage.'/public-backups/'.$id);}else if($type==='log')$f=$this->storage.'/logs/'.$id;else if($type==='job'){if($active&&$active['id']===$id)continue;$f=$this->storage.'/jobs/'.$id.'.json';$deleteJob=UMEUtil::jsonRead($f,array());if($deleteJob&&isset($deleteJob['type'])&&$deleteJob['type']==='migration_export'&&!empty($deleteJob['data']['migration_id'])&&UMEUtil::safeId($deleteJob['data']['migration_id'])&&strpos($deleteJob['data']['migration_id'],'migration-')===0)UMEUtil::removeTree($this->storage.'/migrations/'.$deleteJob['data']['migration_id']);@unlink($this->cancelFile($id));@unlink($this->storage.'/migration-secrets/'.$id.'.secret');}else continue;if(is_file($f)&&@unlink($f))$deleted++;}return $deleted;
    }
    public function availableRollbacks(){
        $out=array(); $idx=$this->rollbackIndex(); foreach($idx as $patchId=>$r){if(empty($r['rollback_file'])||!is_file($this->storage.'/rollbacks/'.$r['rollback_file']))continue;if(isset($r['status'])&&$r['status']==='rolled_back')continue;$out[$patchId]=$r;}
        $history=UMEUtil::jsonRead($this->storage.'/history.json',array());foreach($history as $r){if(empty($r['patch_id'])||empty($r['rollback_file'])||!is_file($this->storage.'/rollbacks/'.$r['rollback_file']))continue;if(isset($r['status'])&&$r['status']==='rolled_back')continue;if(!isset($out[$r['patch_id']]))$out[$r['patch_id']]=$r;}
        return $out;
    }
    public function profile(){
        $db=$this->databaseConfig(false);
        $dbBackup=!empty($db['available'])&&isset($db['engine'])&&$db['engine']==='mysql'&&extension_loaded('mysqli');
        $siteVersion=$this->siteVersion();
        return array(
            'format'=>'external-upgrade-site-profile/3.0',
            'site_id'=>$this->config['site_id'],
            'project'=>$this->config['project'],
            'domains'=>$this->config['allowed_domains'],
            'current_domain'=>UMEUtil::domain(),
            'upgrade_manager_version'=>$this->config['version'],
            'site_version'=>$siteVersion['version'],
            'next_patch_version'=>$siteVersion['next_version'],
            'site_version_policy'=>'manifest.version MUST equal next_patch_version; 1.2/1.3 MUST set base_site_version; SQL migrations require format 1.3',
            'php_target'=>'5.6+',
            'cms'=>array('id'=>$this->cms['id'],'name'=>$this->cms['name'],'version'=>$this->cms['version'],'supported'=>!isset($this->cms['supported'])||!empty($this->cms['supported']),'min_supported_version'=>isset($this->cms['min_supported_version'])?$this->cms['min_supported_version']:'','support_reason'=>isset($this->cms['support_reason'])?$this->cms['support_reason']:''),
            'database'=>array('engine'=>isset($db['engine'])?$db['engine']:'unknown','detected'=>!empty($db['available']),'backup_supported'=>$dbBackup,'migrations_supported'=>$dbBackup,'table_prefix'=>isset($db['prefix'])?$db['prefix']:'','connection_source'=>isset($db['connection_source'])?$db['connection_source']:'auto','reason'=>$dbBackup?'':(isset($db['reason'])?$db['reason']:'MySQL/MariaDB + mysqli required')),
            'patch_formats'=>array('external-upgrade-patch/1.0','external-upgrade-patch/1.1','external-upgrade-patch/1.2','external-upgrade-patch/1.3'),
            'preferred_patch_format'=>'external-upgrade-patch/1.3',
            'manual_upload'=>array('accepts_signed'=>true,'accepts_unsigned_1_1'=>true,'accepts_unsigned_1_2'=>true,'accepts_unsigned_1_3'=>true,'unsigned_behavior'=>'verify_then_sign_locally_then_verify_again','requires_hmac_key_from_developer'=>false),
            'manifest'=>array('unsigned_manual_required_fields'=>array('format','patch_id','name','base_site_version','version','project','domain','site_id','files','delete','migrations','actions'),'signed_required_fields'=>array('format','patch_id','name','base_site_version','version','project','domain','site_id','files','delete','migrations','actions','payload_hash','signature')),
            'payload_hash'=>array('algorithm'=>'SHA-256','file_line'=>'<target>:<sha256>\n','delete_line'=>'DELETE:<path>\n','migration_line'=>'SQL:<migration_id>:<sha256>\n','migration_tables_line'=>'TABLES:<migration_id>:<sha256(canonical-json(tables))>\n (1.3)','actions_line'=>'ACTIONS:<sha256(canonical-json(actions))>\n','ordering'=>'manifest array order; ACTIONS last','canonical_json'=>'recursive object-key sort; array order preserved; unescaped Unicode and slashes'),
            'signature'=>array('algorithm'=>'HMAC-SHA256','message'=>'patch_id|site_id|domain|payload_hash','secret_file'=>'upgrade/config.local.php','secret_in_backup'=>false,'secret_in_profile'=>false,'manual_note'=>'For authenticated manual uploads, omit payload_hash and signature together and let Upgrade Manager seal the patch locally.'),
            'allowed_actions'=>isset($this->config['allowed_actions'])?$this->config['allowed_actions']:array('clear_cache'),
            'forbidden_targets'=>array_values(array_unique(array_merge($this->selfProtectedPaths(),isset($this->config['protected_targets'])?$this->config['protected_targets']:array('upgrade/config.local.php','.env','.git')))),
            'delete_directories_allowed'=>false,
            'storage_mode'=>isset($this->config['storage_mode'])?$this->config['storage_mode']:'legacy',
            'backup_docs'=>array('00_START_HERE_FOR_GPT.md','AGENTS.md','PATCH_AUTHORING_RU.md','PATCH_FORMAT_RU.md','AGENT_WORKFLOW_RU.md','PATCH_UNSIGNED_MANIFEST_TEMPLATE.json','PATCH_SIGNATURE_EXAMPLE.json','PATCH_BUILDER.py','upgrade-source-manifest.json'),'backup_upgrade_source'=>array('included_in_private_and_public_backup'=>true,'location'=>'upgrade/','source'=>'BUILD_SHA256SUMS.txt verified allowlist','contains_hmac_keys'=>false,'recovery_restore_separate'=>true),
            'backup_policy'=>array('default_file_mode'=>isset($this->config['default_file_mode'])?$this->config['default_file_mode']:'code_cms','available_file_modes'=>array('all','code_cms','necessary','none'),'default_database_mode'=>isset($this->config['default_database_mode'])?$this->config['default_database_mode']:'minimal','available_database_modes'=>array('full','minimal','schema','none'),'minimal_database_policy'=>'pattern-filtered','all_files_mode_includes_media'=>true,'necessary_mode_excludes_detected_cms_core'=>true,'user_exclusions'=>$this->settings()['backup_exclusions']),
            'mcp_url'=>(UMEUtil::isHttps()?'https':'http').'://'.UMEUtil::domain().rtrim(dirname(isset($_SERVER['SCRIPT_NAME'])?$_SERVER['SCRIPT_NAME']:'/upgrade/index.php'),'/').'/mcp.php',
            'contains_secrets'=>false,
            'contains_secrets_scope'=>'patch-profile-only'
        );
    }
}
