<?php
require_once __DIR__.'/BackupAgentPack.php';
class UMESelfUpdater {
    private $config;
    private $manager;
    private $upgradeDir;
    private $storage;
    private $settingsFile;
    private $repoDir;
    private $remoteAdminPrivateKey;
    private $remoteAdminPublicKey;
    private $remoteAdminClientKey;
    private $remoteAdminClientMeta;
    private $remoteAdminNonceFile;
    private $recoveryTicketNonceFile;
    private $passwordResetTicketNonceFile;
    private $recoveryMailRateFile;
    private $maxPackageBytes=33554432;

    public function __construct($config,$manager,$upgradeDir){
        $this->config=$config;
        $this->manager=$manager;
        $this->upgradeDir=rtrim(str_replace('\\','/',$upgradeDir),'/');
        $this->storage=$manager->storage();
        $this->settingsFile=$this->storage.'/settings.json';
        $this->repoDir=$this->storage.'/self-updates';
        $this->remoteAdminPrivateKey=$this->repoDir.'/remote-admin-private.pem';
        $this->remoteAdminPublicKey=$this->repoDir.'/remote-admin-public.pem';
        $this->remoteAdminClientKey=$this->storage.'/remote-admin-central-public.pem';
        $this->remoteAdminClientMeta=$this->storage.'/remote-admin-central.json';
        $this->remoteAdminNonceFile=$this->storage.'/remote-admin-nonces.json';
        $this->recoveryTicketNonceFile=$this->storage.'/recovery-ticket-nonces.json';
        $this->passwordResetTicketNonceFile=$this->storage.'/password-reset-ticket-nonces.json';
        $this->recoveryMailRateFile=$this->repoDir.'/recovery-mail-rate.json';
        UMEUtil::ensureDir($this->repoDir);
        UMEUtil::ensureDir($this->repoDir.'/releases');
        UMEUtil::ensureDir($this->repoDir.'/rollbacks');
        UMEUtil::ensureDir($this->repoDir.'/temp');
    }

    private function eq($a,$b){
        if(function_exists('hash_equals'))return hash_equals((string)$a,(string)$b);
        $a=(string)$a;$b=(string)$b;if(strlen($a)!==strlen($b))return false;$r=0;for($i=0;$i<strlen($a);$i++)$r|=ord($a[$i])^ord($b[$i]);return $r===0;
    }

    private function remoteAdminOpenSslAvailable(){
        return function_exists('openssl_pkey_new')&&function_exists('openssl_pkey_export')&&function_exists('openssl_pkey_get_details')&&function_exists('openssl_sign')&&function_exists('openssl_verify');
    }

    private function atomicTextWrite($file,$data,$mode){
        $tmp=$file.'.tmp.'.UMEUtil::randomHex(4);$written=@file_put_contents($tmp,(string)$data,LOCK_EX);
        if($written===false||(int)$written!==strlen((string)$data)){@unlink($tmp);throw new Exception('Не удалось полностью записать защищённый файл: '.basename($file));}
        @chmod($tmp,(int)$mode);if(!@rename($tmp,$file)){@unlink($tmp);throw new Exception('Не удалось атомарно заменить защищённый файл: '.basename($file));}@chmod($file,(int)$mode);
    }

    private function smtpCryptoKey(){
        $base=isset($this->config['hmac_key'])?(string)$this->config['hmac_key']:'';
        if($base==='')throw new Exception('Локальный HMAC-ключ недоступен для защиты SMTP-пароля.');
        return hash('sha256',$base.'|ume-smtp-settings|1',true);
    }

    private function smtpEncryptPassword($plain){
        $plain=(string)$plain;if($plain==='')return '';
        if(!function_exists('openssl_encrypt')||!function_exists('openssl_random_pseudo_bytes'))throw new Exception('Для защищённого хранения SMTP-пароля требуется OpenSSL.');
        $iv=@openssl_random_pseudo_bytes(16);if(!is_string($iv)||strlen($iv)!==16)throw new Exception('Не удалось создать IV для SMTP-пароля.');
        $key=$this->smtpCryptoKey();$cipher=@openssl_encrypt($plain,'AES-256-CBC',$key,OPENSSL_RAW_DATA,$iv);if($cipher===false)throw new Exception('Не удалось зашифровать SMTP-пароль.');
        $mac=hash_hmac('sha256',$iv.$cipher,$key,true);return 'v1.'.base64_encode($iv.$mac.$cipher);
    }

    private function smtpDecryptPassword($encoded){
        $encoded=(string)$encoded;if($encoded==='')return '';
        if(strpos($encoded,'v1.')!==0)throw new Exception('SMTP-пароль сохранён в неизвестном формате.');
        $raw=base64_decode(substr($encoded,3),true);if($raw===false||strlen($raw)<49)throw new Exception('Зашифрованный SMTP-пароль повреждён.');
        $iv=substr($raw,0,16);$mac=substr($raw,16,32);$cipher=substr($raw,48);$key=$this->smtpCryptoKey();$expected=hash_hmac('sha256',$iv.$cipher,$key,true);if(!$this->eq($mac,$expected))throw new Exception('Проверка целостности SMTP-пароля не пройдена.');
        if(!function_exists('openssl_decrypt'))throw new Exception('Для чтения SMTP-пароля требуется OpenSSL.');$plain=@openssl_decrypt($cipher,'AES-256-CBC',$key,OPENSSL_RAW_DATA,$iv);if($plain===false)throw new Exception('Не удалось расшифровать SMTP-пароль.');return $plain;
    }

    private function smtpPublicSettings($s){
        $security=isset($s['smtp_security'])?(string)$s['smtp_security']:'tls';
        return array(
            'central_only'=>true,
            'enabled'=>!empty($s['smtp_enabled']),
            'configured'=>!empty($s['smtp_host'])&&!empty($s['smtp_from_email']),
            'host'=>isset($s['smtp_host'])?(string)$s['smtp_host']:'',
            'port'=>isset($s['smtp_port'])?(int)$s['smtp_port']:587,
            'security'=>$security,
            'username'=>isset($s['smtp_username'])?(string)$s['smtp_username']:'',
            'password_configured'=>!empty($s['smtp_password_enc']),
            'from_email'=>isset($s['smtp_from_email'])?(string)$s['smtp_from_email']:$this->recoveryMailFromAddress(),
            'from_name'=>isset($s['smtp_from_name'])?(string)$s['smtp_from_name']:'Upgrade Manager Security',
            'legacy_mail_fallback'=>empty($s['smtp_enabled'])
        );
    }

    private function smtpMailerSettings($s){
        if(empty($s['smtp_enabled']))throw new Exception('SMTP отключён в настройках центрального сервера.');
        return array(
            'host'=>isset($s['smtp_host'])?(string)$s['smtp_host']:'',
            'port'=>isset($s['smtp_port'])?(int)$s['smtp_port']:587,
            'security'=>isset($s['smtp_security'])?(string)$s['smtp_security']:'tls',
            'username'=>isset($s['smtp_username'])?(string)$s['smtp_username']:'',
            'password'=>$this->smtpDecryptPassword(isset($s['smtp_password_enc'])?(string)$s['smtp_password_enc']:''),
            'from_email'=>isset($s['smtp_from_email'])?(string)$s['smtp_from_email']:$this->recoveryMailFromAddress(),
            'from_name'=>isset($s['smtp_from_name'])?(string)$s['smtp_from_name']:'Upgrade Manager Security',
            'timeout'=>15
        );
    }

    public function saveSmtpSettings($input){
        $s=$this->settings();if(!$this->centralRepositoryEnabled($s))throw new Exception('SMTP настраивается только на главном Upgrade Manager с включённым центральным репозиторием.');
        $enabled=!empty($input['smtp_enabled']);$host=trim(isset($input['smtp_host'])?(string)$input['smtp_host']:'');$port=isset($input['smtp_port'])?(int)$input['smtp_port']:587;$security=isset($input['smtp_security'])?strtolower(trim((string)$input['smtp_security'])):'tls';$username=trim(isset($input['smtp_username'])?(string)$input['smtp_username']:'');$password=isset($input['smtp_password'])?(string)$input['smtp_password']:'';$fromEmail=strtolower(trim(isset($input['smtp_from_email'])?(string)$input['smtp_from_email']:''));$fromName=trim(isset($input['smtp_from_name'])?(string)$input['smtp_from_name']:'Upgrade Manager Security');
        if($host!==''&&(strlen($host)>255||preg_match('/[\s\r\n\/]/',$host)))throw new Exception('Некорректный SMTP-сервер.');if($port<1||$port>65535)throw new Exception('Некорректный SMTP-порт.');if(!in_array($security,array('tls','ssl','none'),true))throw new Exception('Неизвестный режим SMTP-шифрования.');if(strlen($username)>254||strpos($username,"\r")!==false||strpos($username,"\n")!==false)throw new Exception('Некорректный SMTP-логин.');if($fromEmail!==''&&filter_var($fromEmail,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Некорректный email отправителя.');if(strlen($fromName)>120||strpos($fromName,"\r")!==false||strpos($fromName,"\n")!==false)throw new Exception('Некорректное имя отправителя.');
        if($password!=='')$s['smtp_password_enc']=$this->smtpEncryptPassword($password);elseif($username==='')$s['smtp_password_enc']='';elseif(!empty($input['smtp_clear_password']))$s['smtp_password_enc']='';elseif(!isset($s['smtp_password_enc']))$s['smtp_password_enc']='';
        $s['smtp_enabled']=$enabled;$s['smtp_host']=$host;$s['smtp_port']=$port;$s['smtp_security']=$security;$s['smtp_username']=$username;$s['smtp_from_email']=$fromEmail;$s['smtp_from_name']=$fromName;
        if($enabled){if($host==='')throw new Exception('Укажите SMTP-сервер.');if($fromEmail===''||filter_var($fromEmail,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Укажите корректный email отправителя.');$hasPassword=!empty($s['smtp_password_enc']);if(($username!==''&&!$hasPassword)||($username===''&&$hasPassword))throw new Exception('Для SMTP AUTH нужны и логин, и пароль.');}
        $this->saveAllSettings($s);return $this->smtpPublicSettings($s);
    }

    public function testSmtpConnection(){
        $s=$this->settings();if(!$this->centralRepositoryEnabled($s))throw new Exception('Проверка SMTP доступна только на главном Upgrade Manager.');$mailer=new UMESmtpMailer($this->smtpMailerSettings($s));$result=$mailer->testConnection();$result['message']='SMTP-подключение и авторизация успешно проверены.';return $result;
    }

    public function sendSmtpTest($email){
        $s=$this->settings();if(!$this->centralRepositoryEnabled($s))throw new Exception('Тестовое SMTP-письмо доступно только на главном Upgrade Manager.');$email=strtolower(trim((string)$email));if($email===''||filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Укажите корректный email для тестового письма.');$mailer=new UMESmtpMailer($this->smtpMailerSettings($s));$result=$mailer->send($email,'Тест SMTP Upgrade Manager',"Это тестовое письмо Upgrade Manager.\n\nSMTP настроен правильно, если вы получили это сообщение.\nСайт: ".UMEUtil::domain()."\nВремя: ".UMEUtil::now()."\n");$result['message']='Тестовое письмо принято SMTP-сервером для доставки на '.$email.'.';return $result;
    }

    private function sendCentralSecurityMail($to,$subject,$body,$legacyName){
        $s=$this->settings();if(!empty($s['smtp_enabled'])){$mailer=new UMESmtpMailer($this->smtpMailerSettings($s));return $mailer->send($to,$subject,$body);}
        $from=$this->recoveryMailFromAddress();$encoded='=?UTF-8?B?'.base64_encode((string)$subject).'?=';$headers='From: '.str_replace(array("\r","\n"),'',(string)$legacyName).' <'.$from.">\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";if(!function_exists('mail')||!@mail($to,$encoded,$body,$headers))throw new Exception('Центральный сервер не смог отправить письмо. Настройте SMTP в разделе «Настройки» на главном Upgrade Manager.');return array('ok'=>true,'legacy_mail'=>true);
    }

    private function ensureRemoteAdminKeyPair(){
        if(!$this->remoteAdminOpenSslAvailable())throw new Exception('Для удалённого сброса пароля требуется расширение OpenSSL.');
        $private=is_file($this->remoteAdminPrivateKey)?@file_get_contents($this->remoteAdminPrivateKey):false;
        $public=is_file($this->remoteAdminPublicKey)?@file_get_contents($this->remoteAdminPublicKey):false;
        if(is_string($private)&&$private!==''&&is_string($public)&&$public!==''){
            $priv=@openssl_pkey_get_private($private);$pub=@openssl_pkey_get_public($public);
            if($priv!==false&&$pub!==false){if(is_resource($priv))@openssl_free_key($priv);if(is_resource($pub))@openssl_free_key($pub);return array('private'=>$private,'public'=>$public,'fingerprint'=>hash('sha256',$public));}
        }
        $opts=array('private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA);$key=@openssl_pkey_new($opts);
        if($key===false)throw new Exception('Не удалось создать RSA-ключ центрального удалённого управления.');
        $privatePem='';if(!@openssl_pkey_export($key,$privatePem)||$privatePem==='')throw new Exception('Не удалось экспортировать приватный RSA-ключ центрального управления.');
        $details=@openssl_pkey_get_details($key);if(!is_array($details)||empty($details['key']))throw new Exception('Не удалось получить публичный RSA-ключ центрального управления.');
        $publicPem=(string)$details['key'];$this->atomicTextWrite($this->remoteAdminPrivateKey,$privatePem,0600);$this->atomicTextWrite($this->remoteAdminPublicKey,$publicPem,0600);
        if(is_resource($key))@openssl_free_key($key);return array('private'=>$privatePem,'public'=>$publicPem,'fingerprint'=>hash('sha256',$publicPem));
    }

    private function repositoryRemoteAdminBundle($s){
        if(empty($s['repository_enabled'])||empty($s['repository_key'])||!$this->remoteAdminOpenSslAvailable())return null;
        try{$keys=$this->ensureRemoteAdminKeyPair();return array('format'=>'ume-remote-admin-key/1.0','algorithm'=>'RSA-SHA256','public_key'=>$keys['public'],'fingerprint'=>$keys['fingerprint']);}
        catch(Exception $e){return null;}
    }

    private function clearClientRemoteAdminPin(){@unlink($this->remoteAdminClientKey);@unlink($this->remoteAdminClientMeta);}

    private function pinRemoteAdminFromFeed($feed,$repositoryKey,$sourceUrl){
        if(!is_array($feed)||empty($feed['remote_admin'])||!is_array($feed['remote_admin'])||empty($feed['remote_admin_signature']))return false;
        $remote=$feed['remote_admin'];if(empty($remote['format'])||$remote['format']!=='ume-remote-admin-key/1.0'||empty($remote['algorithm'])||$remote['algorithm']!=='RSA-SHA256'||empty($remote['public_key'])||empty($remote['fingerprint']))throw new Exception('Центральный сервер вернул некорректный ключ удалённого управления.');
        $expected=hash_hmac('sha256',UMEUtil::canonicalJson($remote),(string)$repositoryKey);if(!$this->eq($expected,(string)$feed['remote_admin_signature']))throw new Exception('Подпись публичного ключа удалённого управления не совпала.');
        $public=(string)$remote['public_key'];$fingerprint=strtolower((string)$remote['fingerprint']);if(!preg_match('/^[a-f0-9]{64}$/',$fingerprint)||hash('sha256',$public)!==$fingerprint)throw new Exception('Fingerprint публичного ключа удалённого управления повреждён.');
        if(!function_exists('openssl_pkey_get_public')||@openssl_pkey_get_public($public)===false)throw new Exception('Публичный RSA-ключ удалённого управления не читается OpenSSL.');
        $existing=UMEUtil::jsonRead($this->remoteAdminClientMeta,array());if(!empty($existing['fingerprint'])&&!$this->eq(strtolower((string)$existing['fingerprint']),$fingerprint))throw new Exception('Публичный ключ центрального удалённого управления изменился. Автоматическая замена заблокирована; переподключите источник обновлений вручную.');
        $this->atomicTextWrite($this->remoteAdminClientKey,$public,0600);UMEUtil::jsonWriteAtomic($this->remoteAdminClientMeta,array('format'=>'ume-remote-admin-pin/1.0','fingerprint'=>$fingerprint,'source_url'=>(string)$sourceUrl,'pinned_at'=>UMEUtil::now()));@chmod($this->remoteAdminClientMeta,0600);return true;
    }

    public function ensureClientRemoteAdminPin(){
        $s=$this->settings();if($this->centralRepositoryEnabled($s))throw new Exception('Удалённый сброс предназначен для клиентских сайтов, а не для центрального репозитория.');
        if(is_file($this->remoteAdminClientKey)){
            $public=@file_get_contents($this->remoteAdminClientKey);$meta=UMEUtil::jsonRead($this->remoteAdminClientMeta,array());if(is_string($public)&&$public!==''&&!empty($meta['fingerprint'])&&hash('sha256',$public)===strtolower((string)$meta['fingerprint']))return array('public_key'=>$public,'fingerprint'=>strtolower((string)$meta['fingerprint']));
            $this->clearClientRemoteAdminPin();
        }
        if(empty($s['update_server_url'])||empty($s['update_repository_key']))throw new Exception('На клиентском сайте не настроен центральный update-feed, поэтому доверенный ключ удалённого сброса получить нельзя.');
        $raw=$this->httpGet($s['update_server_url'],$s['update_repository_key'],1048576);$feed=json_decode($raw,true);$this->verifyFeed($feed,$s['update_repository_key']);$this->pinRemoteAdminFromFeed($feed,$s['update_repository_key'],$s['update_server_url']);
        $public=@file_get_contents($this->remoteAdminClientKey);$meta=UMEUtil::jsonRead($this->remoteAdminClientMeta,array());if(!is_string($public)||$public===''||empty($meta['fingerprint']))throw new Exception('Центральный сервер не опубликовал ключ удалённого управления. Обновите центральный Upgrade Manager до 3.5.8.');return array('public_key'=>$public,'fingerprint'=>(string)$meta['fingerprint']);
    }

    private function consumeRemoteResetNonce($nonce,$expires){
        $nonce=strtolower((string)$nonce);if(!preg_match('/^[a-f0-9]{48,128}$/',$nonce))throw new Exception('Некорректный nonce удалённой команды.');$lock=@fopen($this->storage.'/remote-admin-nonces.lock','c+');if(!$lock||!@flock($lock,LOCK_EX)){if($lock)@fclose($lock);throw new Exception('Не удалось заблокировать журнал одноразовых команд.');}
        try{$now=time();$data=UMEUtil::jsonRead($this->remoteAdminNonceFile,array());if(!is_array($data))$data=array();foreach($data as $key=>$until)if((int)$until<$now)unset($data[$key]);if(isset($data[$nonce]))throw new Exception('Эта команда удалённого управления уже была использована.');$data[$nonce]=(int)$expires;if(count($data)>256){asort($data,SORT_NUMERIC);while(count($data)>256)array_shift($data);}UMEUtil::jsonWriteAtomic($this->remoteAdminNonceFile,$data);@chmod($this->remoteAdminNonceFile,0600);}
        finally{@flock($lock,LOCK_UN);@fclose($lock);}
    }

    public function verifyRemotePasswordReset($command,$signature){
        return $this->verifySignedRemoteCommand($command,$signature,'ume-password-reset/1.0',array('target_site_id','target_domain','issued_at','expires_at','nonce','password_hash'),true);
    }

    // 3.6.4: the central site can set a permanent admin email (login) and password on a client.
    // The password itself never leaves the central site: only its bcrypt hash is signed and sent.
    public function verifyRemoteSetCredentials($command,$signature){
        $verified=$this->verifySignedRemoteCommand($command,$signature,'ume-set-credentials/1.0',array('target_site_id','target_domain','issued_at','expires_at','nonce','password_hash','admin_email'),false);
        $email=strtolower(trim((string)$command['admin_email']));if($email===''||strlen($email)>254||filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Команда содержит некорректный email администратора.');
        $verified['admin_email']=$email;return $verified;
    }

    private function verifySignedRemoteCommand($command,$signature,$format,$requiredKeys,$forceChange){
        if(!$this->remoteAdminOpenSslAvailable())throw new Exception('Для проверки удалённой команды требуется OpenSSL.');$pin=$this->ensureClientRemoteAdminPin();
        if(!is_array($command)||empty($command['format'])||$command['format']!==$format)throw new Exception('Некорректный формат команды удалённого управления.');foreach($requiredKeys as $key)if(!isset($command[$key]))throw new Exception('В команде удалённого управления отсутствует '.$key.'.');
        $now=time();$issued=(int)$command['issued_at'];$expires=(int)$command['expires_at'];if($issued>$now+60||$issued<$now-600||$expires<$now||$expires-$issued>300)throw new Exception('Команда удалённого управления истекла или имеет недопустимый срок действия.');
        if((string)$command['target_site_id']!==(string)$this->config['site_id'])throw new Exception('Команда удалённого управления предназначена для другого site_id.');$domain=strtolower(trim((string)$command['target_domain'],'. '));$current=strtolower(trim(UMEUtil::domain(),'. '));if($domain===''||$domain!==$current)throw new Exception('Команда удалённого управления предназначена для другого домена.');
        $sig=base64_decode((string)$signature,true);if($sig===false||$sig==='')throw new Exception('Подпись команды удалённого управления повреждена.');$pub=@openssl_pkey_get_public($pin['public_key']);if($pub===false)throw new Exception('Закреплённый публичный ключ центрального сайта повреждён.');$ok=@openssl_verify(UMEUtil::canonicalJson($command),$sig,$pub,OPENSSL_ALGO_SHA256);if(is_resource($pub))@openssl_free_key($pub);if($ok!==1)throw new Exception('Подпись центрального сервера для удалённой команды недействительна.');
        $hash=(string)$command['password_hash'];if(!preg_match('/^\$2[aby]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/',$hash))throw new Exception('Команда содержит некорректный bcrypt-хэш.');$this->consumeRemoteResetNonce((string)$command['nonce'],$expires);return array('password_hash'=>$hash,'force_password_change'=>(bool)$forceChange,'fingerprint'=>$pin['fingerprint']);
    }

    private function ipInCidrRemote($ip,$cidr){$parts=explode('/',$cidr,2);if(count($parts)!==2)return false;$a=@inet_pton($ip);$n=@inet_pton($parts[0]);if($a===false||$n===false||strlen($a)!==strlen($n))return false;$bits=(int)$parts[1];$max=strlen($a)*8;if($bits<0||$bits>$max)return false;$bytes=(int)floor($bits/8);$rem=$bits%8;if($bytes>0&&substr($a,0,$bytes)!==substr($n,0,$bytes))return false;if($rem===0)return true;$mask=(0xFF << (8-$rem)) & 0xFF;return (ord($a[$bytes])&$mask)===(ord($n[$bytes])&$mask);}
    private function publicRemoteIp($ip){$ip=trim((string)$ip,'[] ');if(filter_var($ip,FILTER_VALIDATE_IP)===false)return false;$packed=@inet_pton($ip);if($packed===false)return false;if(strlen($packed)===16&&substr($packed,0,10)===str_repeat("\0",10)&&substr($packed,10,2)==="\xff\xff"){$v4=@inet_ntop(substr($packed,12,4));return $v4!==false&&$this->publicRemoteIp($v4);}$flags=0;if(defined('FILTER_FLAG_NO_PRIV_RANGE'))$flags|=FILTER_FLAG_NO_PRIV_RANGE;if(defined('FILTER_FLAG_NO_RES_RANGE'))$flags|=FILTER_FLAG_NO_RES_RANGE;if($flags&&filter_var($ip,FILTER_VALIDATE_IP,$flags)===false)return false;$blocked=array('0.0.0.0/8','10.0.0.0/8','100.64.0.0/10','127.0.0.0/8','169.254.0.0/16','172.16.0.0/12','192.0.0.0/24','192.0.2.0/24','192.88.99.0/24','192.168.0.0/16','198.18.0.0/15','198.51.100.0/24','203.0.113.0/24','224.0.0.0/4','240.0.0.0/4','::/128','::1/128','64:ff9b:1::/48','100::/64','2001:db8::/32','2001:10::/28','fc00::/7','fe80::/10','ff00::/8');foreach($blocked as $cidr)if($this->ipInCidrRemote($ip,$cidr))return false;return true;}
    private function normalizeRemoteDomain($domain){$domain=strtolower(trim((string)$domain));$domain=preg_replace('#^https?://#i','',$domain);$domain=preg_replace('#/.*$#','',$domain);$domain=preg_replace('/:\d+$/','',$domain);$domain=trim($domain,'. ');if($domain===''||strlen($domain)>253||filter_var($domain,FILTER_VALIDATE_IP)!==false||!preg_match('/^[a-z0-9.-]+$/',$domain)||strpos($domain,'.')===false)throw new Exception('Укажите публичный домен клиентского сайта без пути, например client.example.com.');$labels=explode('.',$domain);foreach($labels as $label)if($label===''||strlen($label)>63||$label[0]==='-'||substr($label,-1)==='-')throw new Exception('Некорректный домен клиентского сайта.');$bad=array('localhost','local','internal','lan','home','test','invalid');foreach($bad as $suffix)if($domain===$suffix||substr($domain,-strlen('.'.$suffix))==='.'. $suffix)throw new Exception('Локальные домены нельзя использовать для удалённого сброса.');return $domain;}
    private function resolvePublicRemoteHost($host){$ips=array();if(function_exists('dns_get_record')){$a=defined('DNS_A')?@dns_get_record($host,DNS_A):array();if(is_array($a))foreach($a as $row)if(!empty($row['ip']))$ips[]=$row['ip'];$aaaa=defined('DNS_AAAA')?@dns_get_record($host,DNS_AAAA):array();if(is_array($aaaa))foreach($aaaa as $row)if(!empty($row['ipv6']))$ips[]=$row['ipv6'];}if(function_exists('gethostbynamel')){$v4=@gethostbynamel($host);if(is_array($v4))foreach($v4 as $ip)$ips[]=$ip;}$clean=array();foreach($ips as $ip){$packed=@inet_pton(trim((string)$ip,'[] '));if($packed===false)continue;$canonical=@inet_ntop($packed);if($canonical!==false&&!in_array($canonical,$clean,true))$clean[]=$canonical;}if(!$clean)throw new Exception('Не удалось определить публичный IP клиентского сайта.');foreach($clean as $ip)if(!$this->publicRemoteIp($ip))throw new Exception('Домен клиентского сайта резолвится в приватный/локальный/зарезервированный IP. Запрос заблокирован.');usort($clean,function($a,$b){$av4=strpos($a,':')===false;$bv4=strpos($b,':')===false;if($av4===$bv4)return strcmp($a,$b);return $av4?-1:1;});return $clean;}
    private function sameRemoteIp($a,$b){$pa=@inet_pton(trim((string)$a,'[] '));$pb=@inet_pton(trim((string)$b,'[] '));return $pa!==false&&$pb!==false&&$pa===$pb;}
    private function streamReadExactRemote($fp,$length){$out='';while(strlen($out)<$length&&!feof($fp)){$chunk=fread($fp,min(65536,$length-strlen($out)));if($chunk===false||$chunk===''){$meta=stream_get_meta_data($fp);if(!empty($meta['timed_out']))throw new Exception('Таймаут при чтении ответа клиентского сайта.');if(feof($fp))break;continue;}$out.=$chunk;}if(strlen($out)!==$length)throw new Exception('Клиентский сайт преждевременно закрыл HTTPS-соединение.');return $out;}
    private function streamReadChunkedRemote($fp,$maxBytes){$out='';while(true){$line=fgets($fp,8192);if($line===false)throw new Exception('Некорректный chunked-ответ клиентского сайта.');$line=trim($line);$semi=strpos($line,';');if($semi!==false)$line=substr($line,0,$semi);if($line===''||!preg_match('/^[0-9a-fA-F]+$/',$line))throw new Exception('Некорректный размер HTTP chunk клиента.');$size=hexdec($line);if($size===0){while(($trailer=fgets($fp,8192))!==false){if(rtrim($trailer,"\r\n")==='')break;}break;}if(strlen($out)+$size>$maxBytes)throw new Exception('Клиентский Upgrade Manager вернул слишком большой ответ.');$out.=$this->streamReadExactRemote($fp,$size);if($this->streamReadExactRemote($fp,2)!=="\r\n")throw new Exception('Некорректный chunked-ответ клиентского сайта.');}return $out;}
    private function streamPinnedRemoteRequest($domain,$ips,$path,$payload){if(!function_exists('stream_socket_client'))throw new Exception('Для безопасного удалённого сброса требуется cURL или stream_socket_client с OpenSSL.');$method=$payload===null?'GET':'POST';$body=$payload===null?'':json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($payload!==null&&$body===false)throw new Exception('Не удалось сериализовать команду удалённого управления.');$last='';foreach($ips as $ip){$ctx=stream_context_create(array('ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$domain,'SNI_enabled'=>true,'SNI_server_name'=>$domain,'allow_self_signed'=>false)));$target='tls://'.(strpos($ip,':')!==false?'['.$ip.']':$ip).':443';$errno=0;$errstr='';$fp=@stream_socket_client($target,$errno,$errstr,8,STREAM_CLIENT_CONNECT,$ctx);if(!$fp){$last=$errstr;continue;}stream_set_timeout($fp,20);$request=$method.' '.$path." HTTP/1.1\r\nHost: ".$domain."\r\nAccept: application/json\r\nUser-Agent: UpgradeManager-RemoteAdmin/".$this->currentVersion()."\r\nConnection: close\r\n";if($method==='POST')$request.="Content-Type: application/json\r\nContent-Length: ".strlen($body)."\r\n";$request.="\r\n".($method==='POST'?$body:'');$sent=0;while($sent<strlen($request)){$n=fwrite($fp,substr($request,$sent));if($n===false||$n===0){fclose($fp);throw new Exception('Не удалось отправить HTTPS-запрос клиентскому сайту.');}$sent+=$n;}$status=fgets($fp,8192);if($status===false||!preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#',$status,$m)){fclose($fp);throw new Exception('Клиентский сайт вернул некорректный HTTP-ответ.');}$code=(int)$m[1];$headers=array();$headerBytes=strlen($status);while(($line=fgets($fp,8192))!==false){$headerBytes+=strlen($line);if($headerBytes>65536){fclose($fp);throw new Exception('HTTP-заголовки клиентского сайта слишком велики.');}$trim=rtrim($line,"\r\n");if($trim==='')break;$pos=strpos($trim,':');if($pos!==false){$name=strtolower(trim(substr($trim,0,$pos)));$value=trim(substr($trim,$pos+1));$headers[$name]=isset($headers[$name])?$headers[$name].','.$value:$value;}}if($code>=300&&$code<400){fclose($fp);throw new Exception('Редиректы remote-admin endpoint запрещены.');}$max=1048576;if(isset($headers['transfer-encoding'])&&stripos($headers['transfer-encoding'],'chunked')!==false)$raw=$this->streamReadChunkedRemote($fp,$max);elseif(isset($headers['content-length'])){$length=(int)$headers['content-length'];if($length<0||$length>$max){fclose($fp);throw new Exception('Клиентский Upgrade Manager вернул слишком большой ответ.');}$raw=$this->streamReadExactRemote($fp,$length);}else{$raw='';while(!feof($fp)){$chunk=fread($fp,65536);if($chunk===false){fclose($fp);throw new Exception('Ошибка чтения ответа клиентского сайта.');}if($chunk===''){$meta=stream_get_meta_data($fp);if(!empty($meta['timed_out'])){fclose($fp);throw new Exception('Таймаут при чтении ответа клиентского сайта.');}continue;}$raw.=$chunk;if(strlen($raw)>$max){fclose($fp);throw new Exception('Клиентский Upgrade Manager вернул слишком большой ответ.');}}}fclose($fp);$json=json_decode($raw,true);if(!is_array($json))throw new Exception('Клиентский Upgrade Manager вернул некорректный JSON (HTTP '.$code.').');if($code<200||$code>=300||empty($json['ok']))throw new Exception(isset($json['error'])?(string)$json['error']:'Клиентский Upgrade Manager вернул HTTP '.$code.'.');return $json;}throw new Exception('Не удалось подключиться к клиентскому сайту'.($last!==''?': '.$last:'.'));}

    private function remoteClientRequest($domain,$action,$payload){
        $domain=$this->normalizeRemoteDomain($domain);$ips=$this->resolvePublicRemoteHost($domain);$url='https://'.$domain.'/upgrade/remote-admin.php?action='.rawurlencode($action);$body=$payload===null?'':json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($payload!==null&&$body===false)throw new Exception('Не удалось сериализовать команду удалённого управления.');
        if(!function_exists('curl_init')||!defined('CURLOPT_RESOLVE')||!defined('CURLINFO_PRIMARY_IP'))return $this->streamPinnedRemoteRequest($domain,$ips,'/upgrade/remote-admin.php?action='.rawurlencode($action),$payload);$last='';foreach($ips as $ip){$ch=curl_init($url);if(!$ch)continue;curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_FOLLOWLOCATION,false);curl_setopt($ch,CURLOPT_MAXREDIRS,0);curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,8);curl_setopt($ch,CURLOPT_TIMEOUT,20);curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,true);curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);$headers=array('Accept: application/json','User-Agent: UpgradeManager-RemoteAdmin/'.$this->currentVersion());if($payload!==null){$headers[]='Content-Type: application/json';curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,$body);}curl_setopt($ch,CURLOPT_HTTPHEADER,$headers);if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))curl_setopt($ch,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);if(defined('CURLOPT_PROXY'))curl_setopt($ch,CURLOPT_PROXY,'');if(defined('CURLOPT_NOPROXY'))curl_setopt($ch,CURLOPT_NOPROXY,'*');$resolvedIp=strpos($ip,':')!==false?'['.$ip.']':$ip;curl_setopt($ch,CURLOPT_RESOLVE,array($domain.':443:'.$resolvedIp));$raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$primary=(string)curl_getinfo($ch,CURLINFO_PRIMARY_IP);curl_close($ch);if($raw===false){$last=$err;continue;}if(!$this->sameRemoteIp($primary,$ip)||!$this->publicRemoteIp($primary))throw new Exception('SSRF-защита остановила соединение: фактический IP клиента не совпал с проверенным DNS-адресом.');if($code>=300&&$code<400)throw new Exception('Редиректы remote-admin endpoint запрещены.');if(strlen($raw)>1048576)throw new Exception('Клиентский Upgrade Manager вернул слишком большой ответ.');$json=json_decode($raw,true);if(!is_array($json))throw new Exception('Клиентский Upgrade Manager вернул некорректный JSON (HTTP '.$code.').');if($code<200||$code>=300||empty($json['ok']))throw new Exception(isset($json['error'])?(string)$json['error']:'Клиентский Upgrade Manager вернул HTTP '.$code.'.');return $json;}throw new Exception('Не удалось подключиться к клиентскому сайту'.($last!==''?': '.$last:'.'));
    }

    public function remoteResetClientPassword($domain){
        $s=$this->settings();if(!$this->centralRepositoryEnabled($s))throw new Exception('Удалённый сброс доступен только на главном сайте с включённым центральным репозиторием.');$keys=$this->ensureRemoteAdminKeyPair();$domain=$this->normalizeRemoteDomain($domain);$probe=$this->remoteClientRequest($domain,'probe',null);if(empty($probe['site_id'])||empty($probe['domain']))throw new Exception('Клиентский Upgrade Manager не вернул site_id/domain.');if(strtolower(trim((string)$probe['domain'],'. '))!==$domain)throw new Exception('Клиент сообщил другой домен. Сброс остановлен.');if(empty($probe['remote_reset_supported']))throw new Exception('На клиентском сайте удалённый сброс ещё не готов. Требуется Upgrade Manager 3.5.8+ с настроенным центральным update-feed и OpenSSL.');
        $temporary=substr(UMEUtil::randomHex(16),0,24);$hash=password_hash($temporary,PASSWORD_BCRYPT);if(!is_string($hash)||$hash==='')throw new Exception('Не удалось создать временный пароль.');$now=time();$command=array('format'=>'ume-password-reset/1.0','target_site_id'=>(string)$probe['site_id'],'target_domain'=>$domain,'issued_at'=>$now,'expires_at'=>$now+180,'nonce'=>UMEUtil::randomHex(32),'password_hash'=>$hash,'force_password_change'=>true);$signature='';$priv=@openssl_pkey_get_private($keys['private']);if($priv===false||!@openssl_sign(UMEUtil::canonicalJson($command),$signature,$priv,OPENSSL_ALGO_SHA256))throw new Exception('Не удалось подписать одноразовую команду сброса.');if(is_resource($priv))@openssl_free_key($priv);$result=$this->remoteClientRequest($domain,'reset_password',array('command'=>$command,'signature'=>base64_encode($signature)));return array('domain'=>$domain,'site_id'=>(string)$probe['site_id'],'version'=>isset($probe['version'])?(string)$probe['version']:'','username'=>isset($result['username'])?(string)$result['username']:'admin','temporary_password'=>$temporary,'force_password_change'=>true,'signing_fingerprint'=>$keys['fingerprint'],'message'=>'Пароль сброшен. Все старые сессии отключены; после входа временный пароль нужно заменить.');
    }

    public function remoteSetClientCredentials($domain,$email,$password){
        $s=$this->settings();if(!$this->centralRepositoryEnabled($s))throw new Exception('Установка логина и пароля доступна только на главном сайте с включённым центральным репозиторием.');
        $email=strtolower(trim((string)$email));if($email===''||strlen($email)>254||filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Укажите корректный email администратора — он станет логином на клиентском сайте.');
        UMEUtil::assertStrongPassword($password);
        $keys=$this->ensureRemoteAdminKeyPair();$domain=$this->normalizeRemoteDomain($domain);$probe=$this->remoteClientRequest($domain,'probe',null);
        if(empty($probe['site_id'])||empty($probe['domain']))throw new Exception('Клиентский Upgrade Manager не вернул site_id/domain.');if(strtolower(trim((string)$probe['domain'],'. '))!==$domain)throw new Exception('Клиент сообщил другой домен. Операция остановлена.');
        if(empty($probe['remote_set_credentials_supported'])){$v=isset($probe['version'])?(string)$probe['version']:'неизвестна';throw new Exception('Клиент (версия '.$v.') ещё не поддерживает установку логина и пароля. Обновите его до 3.6.4+ («Обновление самого Upgrade Manager» → «Проверить обновления») или используйте режим временного пароля.');}
        $hash=password_hash((string)$password,PASSWORD_BCRYPT);if(!is_string($hash)||$hash==='')throw new Exception('Не удалось создать bcrypt-хэш пароля.');$now=time();
        $command=array('format'=>'ume-set-credentials/1.0','target_site_id'=>(string)$probe['site_id'],'target_domain'=>$domain,'issued_at'=>$now,'expires_at'=>$now+180,'nonce'=>UMEUtil::randomHex(32),'admin_email'=>$email,'password_hash'=>$hash,'force_password_change'=>false);
        $signature='';$priv=@openssl_pkey_get_private($keys['private']);if($priv===false||!@openssl_sign(UMEUtil::canonicalJson($command),$signature,$priv,OPENSSL_ALGO_SHA256))throw new Exception('Не удалось подписать одноразовую команду.');if(is_resource($priv))@openssl_free_key($priv);
        $result=$this->remoteClientRequest($domain,'set_credentials',array('command'=>$command,'signature'=>base64_encode($signature)));
        return array('domain'=>$domain,'site_id'=>(string)$probe['site_id'],'version'=>isset($probe['version'])?(string)$probe['version']:'','username'=>isset($result['username'])?(string)$result['username']:$email,'force_password_change'=>false,'signing_fingerprint'=>$keys['fingerprint'],'message'=>'Логин и пароль установлены. Все старые сессии клиента отключены.');
    }

    private function recoveryB64Encode($value){return rtrim(strtr(base64_encode((string)$value),'+/','-_'),'=');}
    private function recoveryB64Decode($value){$value=(string)$value;if($value===''||!preg_match('/^[A-Za-z0-9_-]+$/',$value))return false;$pad=strlen($value)%4;if($pad)$value.=str_repeat('=',4-$pad);return base64_decode(strtr($value,'-_','+/'),true);}

    private function recoveryCentralUrl($feedUrl){
        $parts=@parse_url((string)$feedUrl);if(!is_array($parts)||empty($parts['scheme'])||strtolower((string)$parts['scheme'])!=='https'||empty($parts['host']))throw new Exception('Центральный Recovery требует HTTPS update-feed.');
        $path=isset($parts['path'])?(string)$parts['path']:'/upgrade/update-feed.php';$dir=rtrim(str_replace('\\','/',dirname($path)),'/');if($dir==='.'||$dir==='')$dir='';$port=!empty($parts['port'])?':'.(int)$parts['port']:'';return 'https://'.$parts['host'].$port.$dir.'/remote-admin.php?action=send_recovery';
    }

    private function passwordResetCentralUrl($feedUrl){
        $url=$this->recoveryCentralUrl($feedUrl);
        return preg_replace('/action=send_recovery$/','action=send_password_reset',$url);
    }

    private function httpPostCentralRecovery($url,$key,$payload){
        if(!preg_match('#^https://#i',(string)$url))throw new Exception('Recovery-сервер должен использовать HTTPS.');$body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($body===false||strlen($body)>65536)throw new Exception('Не удалось подготовить Recovery-запрос.');$status=0;$raw=false;
        if(function_exists('curl_init')){$ch=curl_init($url);if(!$ch)throw new Exception('Не удалось инициализировать Recovery-запрос.');curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_FOLLOWLOCATION,false);curl_setopt($ch,CURLOPT_MAXREDIRS,0);curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);curl_setopt($ch,CURLOPT_TIMEOUT,25);curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,true);curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,$body);curl_setopt($ch,CURLOPT_HTTPHEADER,array('Accept: application/json','Content-Type: application/json','X-UME-Update-Key: '.$key,'User-Agent: UpgradeManager-Recovery/'.$this->currentVersion()));if(defined('CURLOPT_PROXY'))curl_setopt($ch,CURLOPT_PROXY,'');if(defined('CURLOPT_NOPROXY'))curl_setopt($ch,CURLOPT_NOPROXY,'*');$raw=curl_exec($ch);if($raw===false){$e=curl_error($ch);curl_close($ch);throw new Exception('Не удалось связаться с центральным Recovery-сервером: '.$e);}$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);}else{if(!ini_get('allow_url_fopen'))throw new Exception('Для Recovery требуется cURL или allow_url_fopen.');$ctx=stream_context_create(array('http'=>array('method'=>'POST','timeout'=>25,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'header'=>"Accept: application/json\r\nContent-Type: application/json\r\nX-UME-Update-Key: ".$key."\r\nUser-Agent: UpgradeManager-Recovery/".$this->currentVersion()."\r\nContent-Length: ".strlen($body)."\r\n",'content'=>$body),'ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false)));$raw=@file_get_contents($url,false,$ctx);if($raw===false)throw new Exception('Не удалось связаться с центральным Recovery-сервером.');if(isset($http_response_header)&&is_array($http_response_header))foreach($http_response_header as $h)if(preg_match('#^HTTP/\S+\s+(\d+)#i',$h,$m))$status=(int)$m[1];}
        if(strlen((string)$raw)>1048576)throw new Exception('Центральный Recovery-сервер вернул слишком большой ответ.');$json=json_decode((string)$raw,true);if(!is_array($json))throw new Exception('Центральный Recovery-сервер вернул некорректный ответ.');if($status<200||$status>=300||empty($json['ok']))throw new Exception(isset($json['error'])?(string)$json['error']:'Центральный Recovery-сервер вернул HTTP '.$status.'.');return $json;
    }

    public function requestRecoveryEmail($email){
        $email=strtolower(trim((string)$email));if($email===''||filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Некорректный email.');$s=$this->settings();$domain=$this->normalizeRemoteDomain(UMEUtil::domain());$payload=array('format'=>'ume-recovery-request/1.0','target_site_id'=>(string)$this->config['site_id'],'target_domain'=>$domain,'admin_email'=>$email,'requested_at'=>time(),'request_nonce'=>UMEUtil::randomHex(24));
        if($this->centralRepositoryEnabled($s)){$this->centralSendRecoveryEmail($payload,isset($s['repository_key'])?(string)$s['repository_key']:'');return array('queued'=>true);}
        if(empty($s['update_server_url'])||empty($s['update_repository_key']))throw new Exception('Центральный update-feed не настроен.');$this->ensureClientRemoteAdminPin();$url=$this->recoveryCentralUrl($s['update_server_url']);$this->httpPostCentralRecovery($url,$s['update_repository_key'],$payload);return array('queued'=>true);
    }

    public function requestPasswordResetEmail($email){
        $email=strtolower(trim((string)$email));if($email===''||filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Некорректный email.');$s=$this->settings();$domain=$this->normalizeRemoteDomain(UMEUtil::domain());$payload=array('format'=>'ume-password-reset-request/1.0','target_site_id'=>(string)$this->config['site_id'],'target_domain'=>$domain,'admin_email'=>$email,'requested_at'=>time(),'request_nonce'=>UMEUtil::randomHex(24));
        if($this->centralRepositoryEnabled($s)){$this->centralSendPasswordResetEmail($payload,isset($s['repository_key'])?(string)$s['repository_key']:'');return array('queued'=>true);}
        if(empty($s['update_server_url'])||empty($s['update_repository_key']))throw new Exception('Центральный update-feed не настроен.');$this->ensureClientRemoteAdminPin();$url=$this->passwordResetCentralUrl($s['update_server_url']);$this->httpPostCentralRecovery($url,$s['update_repository_key'],$payload);return array('queued'=>true);
    }

    private function recoveryMailRateLimit($siteId,$email){
        $lock=@fopen($this->repoDir.'/recovery-mail-rate.lock','c+');if(!$lock||!@flock($lock,LOCK_EX)){if($lock)@fclose($lock);throw new Exception('Не удалось заблокировать лимит писем безопасности.');}
        try{$now=time();$data=UMEUtil::jsonRead($this->recoveryMailRateFile,array());if(!is_array($data))$data=array();$keys=array('site:'.hash('sha256',(string)$siteId),'mail:'.hash('sha256',strtolower((string)$email)));foreach($data as $k=>$rows){if(!is_array($rows)){unset($data[$k]);continue;}$rows=array_values(array_filter(array_map('intval',$rows),function($t)use($now){return $t>$now-3600;}));if($rows)$data[$k]=$rows;else unset($data[$k]);}foreach($keys as $key){$rows=isset($data[$key])?$data[$key]:array();if($rows&&end($rows)>$now-60)throw new Exception('Письмо уже отправлялось недавно. Повторите через минуту.');if(count($rows)>=5)throw new Exception('Слишком много запросов писем безопасности. Повторите позже.');$rows[]=$now;$data[$key]=$rows;}UMEUtil::jsonWriteAtomic($this->recoveryMailRateFile,$data);@chmod($this->recoveryMailRateFile,0600);}finally{@flock($lock,LOCK_UN);@fclose($lock);}
    }

    private function recoveryMailFromAddress(){
        $host=strtolower(trim(UMEUtil::domain(),'. '));if(substr($host,0,4)==='www.')$host=substr($host,4);if($host===''||filter_var('noreply@'.$host,FILTER_VALIDATE_EMAIL)===false)$host='example.invalid';return 'noreply@'.$host;
    }

    public function centralSendRecoveryEmail($payload,$providedKey){
        $s=$this->settings();if(!$this->centralRepositoryEnabled($s)||empty($s['repository_key']))throw new Exception('Этот сайт не является центральным Recovery-сервером.');if(!$this->eq((string)$s['repository_key'],(string)$providedKey))throw new Exception('Неверный ключ центрального Recovery-сервера.');if(!is_array($payload)||empty($payload['format'])||$payload['format']!=='ume-recovery-request/1.0')throw new Exception('Некорректный Recovery-запрос.');foreach(array('target_site_id','target_domain','admin_email','requested_at','request_nonce') as $key)if(!isset($payload[$key]))throw new Exception('В Recovery-запросе отсутствует '.$key.'.');
        $siteId=trim((string)$payload['target_site_id']);if(!preg_match('/^ume-[a-f0-9]{20,64}$/',$siteId))throw new Exception('Некорректный site_id Recovery-запроса.');$domain=$this->normalizeRemoteDomain($payload['target_domain']);$email=strtolower(trim((string)$payload['admin_email']));if($email===''||strlen($email)>254||filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Некорректный email Recovery-запроса.');$requested=(int)$payload['requested_at'];if(abs(time()-$requested)>300)throw new Exception('Recovery-запрос устарел.');if(!preg_match('/^[a-f0-9]{32,128}$/',(string)$payload['request_nonce']))throw new Exception('Некорректный nonce Recovery-запроса.');
        $isSelf=$siteId===(string)$this->config['site_id']&&strtolower(trim(UMEUtil::domain(),'. '))===$domain;if(!$isSelf){$probe=$this->remoteClientRequest($domain,'probe',null);if(empty($probe['site_id'])||(string)$probe['site_id']!==$siteId||empty($probe['domain'])||strtolower(trim((string)$probe['domain'],'. '))!==$domain)throw new Exception('Клиентский сайт не подтвердил site_id/domain.');if(empty($probe['recovery_email_supported']))throw new Exception('Клиентский сайт ещё не поддерживает email Recovery.');}
        $this->recoveryMailRateLimit($siteId,$email);$keys=$this->ensureRemoteAdminKeyPair();$now=time();$ticket=array('format'=>'ume-recovery-ticket/1.0','target_site_id'=>$siteId,'target_domain'=>$domain,'admin_email_hash'=>hash('sha256',$email),'issued_at'=>$now,'expires_at'=>$now+600,'nonce'=>UMEUtil::randomHex(32));$sig='';$priv=@openssl_pkey_get_private($keys['private']);if($priv===false||!@openssl_sign(UMEUtil::canonicalJson($ticket),$sig,$priv,OPENSSL_ALGO_SHA256))throw new Exception('Не удалось подписать Recovery-ссылку.');if(is_resource($priv))@openssl_free_key($priv);$encoded=$this->recoveryB64Encode(json_encode($ticket,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$link='https://'.$domain.'/upgrade/recovery.php?ticket='.rawurlencode($encoded).'&signature='.rawurlencode(base64_encode($sig));
        $subject='Recovery-доступ к Upgrade Manager — '.$domain;$body="Запрошен аварийный вход в Upgrade Manager.\n\nСайт: ".$domain."\nСсылка действует 10 минут и только один раз:\n".$link."\n\nЕсли вы не запрашивали Recovery, просто проигнорируйте это письмо.\n";$this->sendCentralSecurityMail($email,$subject,$body,'Upgrade Manager Recovery');
        return array('sent'=>true,'domain'=>$domain,'site_id'=>$siteId,'expires_at'=>$ticket['expires_at']);
    }

    public function centralSendPasswordResetEmail($payload,$providedKey){
        $s=$this->settings();if(!$this->centralRepositoryEnabled($s)||empty($s['repository_key']))throw new Exception('Этот сайт не является центральным сервером восстановления пароля.');if(!$this->eq((string)$s['repository_key'],(string)$providedKey))throw new Exception('Неверный ключ центрального сервера восстановления пароля.');if(!is_array($payload)||empty($payload['format'])||$payload['format']!=='ume-password-reset-request/1.0')throw new Exception('Некорректный запрос восстановления пароля.');foreach(array('target_site_id','target_domain','admin_email','requested_at','request_nonce') as $key)if(!isset($payload[$key]))throw new Exception('В запросе восстановления пароля отсутствует '.$key.'.');
        $siteId=trim((string)$payload['target_site_id']);if(!preg_match('/^ume-[a-f0-9]{20,64}$/',$siteId))throw new Exception('Некорректный site_id запроса восстановления пароля.');$domain=$this->normalizeRemoteDomain($payload['target_domain']);$email=strtolower(trim((string)$payload['admin_email']));if($email===''||strlen($email)>254||filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Некорректный email запроса восстановления пароля.');$requested=(int)$payload['requested_at'];if(abs(time()-$requested)>300)throw new Exception('Запрос восстановления пароля устарел.');if(!preg_match('/^[a-f0-9]{32,128}$/',(string)$payload['request_nonce']))throw new Exception('Некорректный nonce запроса восстановления пароля.');
        $isSelf=$siteId===(string)$this->config['site_id']&&strtolower(trim(UMEUtil::domain(),'. '))===$domain;if(!$isSelf){$probe=$this->remoteClientRequest($domain,'probe',null);if(empty($probe['site_id'])||(string)$probe['site_id']!==$siteId||empty($probe['domain'])||strtolower(trim((string)$probe['domain'],'. '))!==$domain)throw new Exception('Клиентский сайт не подтвердил site_id/domain.');if(empty($probe['password_reset_email_supported']))throw new Exception('Клиентский сайт ещё не поддерживает восстановление пароля по email.');}
        $this->recoveryMailRateLimit($siteId,$email);$keys=$this->ensureRemoteAdminKeyPair();$now=time();$ticket=array('format'=>'ume-password-reset-ticket/1.0','target_site_id'=>$siteId,'target_domain'=>$domain,'admin_email_hash'=>hash('sha256',$email),'issued_at'=>$now,'expires_at'=>$now+600,'nonce'=>UMEUtil::randomHex(32));$sig='';$priv=@openssl_pkey_get_private($keys['private']);if($priv===false||!@openssl_sign(UMEUtil::canonicalJson($ticket),$sig,$priv,OPENSSL_ALGO_SHA256))throw new Exception('Не удалось подписать ссылку восстановления пароля.');if(is_resource($priv))@openssl_free_key($priv);$encoded=$this->recoveryB64Encode(json_encode($ticket,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$link='https://'.$domain.'/upgrade/reset-password.php?ticket='.rawurlencode($encoded).'&signature='.rawurlencode(base64_encode($sig));
        $subject='Восстановление пароля Upgrade Manager — '.$domain;$body="Запрошено восстановление пароля Upgrade Manager.\n\nСайт: ".$domain."\nСсылка действует 10 минут и только один раз:\n".$link."\n\nНа странице по ссылке задайте новый пароль дважды.\nЕсли вы не запрашивали восстановление пароля, просто проигнорируйте это письмо.\n";$this->sendCentralSecurityMail($email,$subject,$body,'Upgrade Manager Security');
        return array('sent'=>true,'domain'=>$domain,'site_id'=>$siteId,'expires_at'=>$ticket['expires_at']);
    }

    private function consumeRecoveryTicketNonce($nonce,$expires){
        $nonce=strtolower((string)$nonce);if(!preg_match('/^[a-f0-9]{48,128}$/',$nonce))throw new Exception('Некорректный nonce Recovery-ссылки.');$lock=@fopen($this->storage.'/recovery-ticket-nonces.lock','c+');if(!$lock||!@flock($lock,LOCK_EX)){if($lock)@fclose($lock);throw new Exception('Не удалось заблокировать Recovery nonce.');}
        try{$now=time();$data=UMEUtil::jsonRead($this->recoveryTicketNonceFile,array());if(!is_array($data))$data=array();foreach($data as $key=>$until)if((int)$until<$now)unset($data[$key]);if(isset($data[$nonce]))throw new Exception('Эта Recovery-ссылка уже была использована.');$data[$nonce]=(int)$expires;if(count($data)>256){asort($data,SORT_NUMERIC);while(count($data)>256)array_shift($data);}UMEUtil::jsonWriteAtomic($this->recoveryTicketNonceFile,$data);@chmod($this->recoveryTicketNonceFile,0600);}finally{@flock($lock,LOCK_UN);@fclose($lock);}
    }

    public function verifyRecoveryTicket($encoded,$signature,$expectedEmail){
        if(!$this->remoteAdminOpenSslAvailable())throw new Exception('Для Recovery по email требуется OpenSSL.');$raw=$this->recoveryB64Decode($encoded);if($raw===false||strlen($raw)>16384)throw new Exception('Recovery-ссылка повреждена.');$ticket=json_decode($raw,true);if(!is_array($ticket)||empty($ticket['format'])||$ticket['format']!=='ume-recovery-ticket/1.0')throw new Exception('Некорректный Recovery ticket.');foreach(array('target_site_id','target_domain','admin_email_hash','issued_at','expires_at','nonce') as $key)if(!isset($ticket[$key]))throw new Exception('Recovery ticket неполный.');$s=$this->settings();if($this->centralRepositoryEnabled($s)){$keys=$this->ensureRemoteAdminKeyPair();$public=$keys['public'];}else{$pin=$this->ensureClientRemoteAdminPin();$public=$pin['public_key'];}$sig=base64_decode((string)$signature,true);if($sig===false)throw new Exception('Подпись Recovery-ссылки повреждена.');$pub=@openssl_pkey_get_public($public);if($pub===false||@openssl_verify(UMEUtil::canonicalJson($ticket),$sig,$pub,OPENSSL_ALGO_SHA256)!==1)throw new Exception('Подпись Recovery-ссылки не подтверждена.');if(is_resource($pub))@openssl_free_key($pub);
        $now=time();$issued=(int)$ticket['issued_at'];$expires=(int)$ticket['expires_at'];if($issued>$now+60||$issued<$now-900||$expires<$now||$expires-$issued>600)throw new Exception('Recovery-ссылка истекла или имеет некорректный срок действия.');if((string)$ticket['target_site_id']!==(string)$this->config['site_id'])throw new Exception('Recovery-ссылка выпущена для другого сайта.');$domain=strtolower(trim(UMEUtil::domain(),'. '));if(strtolower(trim((string)$ticket['target_domain'],'. '))!==$domain)throw new Exception('Recovery-ссылка выпущена для другого домена.');$email=strtolower(trim((string)$expectedEmail));if($email===''||filter_var($email,FILTER_VALIDATE_EMAIL)===false||!$this->eq((string)$ticket['admin_email_hash'],hash('sha256',$email)))throw new Exception('Recovery-ссылка выпущена для другого администратора.');$this->consumeRecoveryTicketNonce((string)$ticket['nonce'],$expires);return $ticket;
    }

    private function consumePasswordResetTicketNonce($nonce,$expires){
        $nonce=strtolower((string)$nonce);if(!preg_match('/^[a-f0-9]{48,128}$/',$nonce))throw new Exception('Некорректный nonce ссылки восстановления пароля.');$lock=@fopen($this->storage.'/password-reset-ticket-nonces.lock','c+');if(!$lock||!@flock($lock,LOCK_EX)){if($lock)@fclose($lock);throw new Exception('Не удалось заблокировать nonce восстановления пароля.');}
        try{$now=time();$data=UMEUtil::jsonRead($this->passwordResetTicketNonceFile,array());if(!is_array($data))$data=array();foreach($data as $key=>$until)if((int)$until<$now)unset($data[$key]);if(isset($data[$nonce]))throw new Exception('Эта ссылка восстановления пароля уже была использована.');$data[$nonce]=(int)$expires;if(count($data)>256){asort($data,SORT_NUMERIC);while(count($data)>256)array_shift($data);}UMEUtil::jsonWriteAtomic($this->passwordResetTicketNonceFile,$data);@chmod($this->passwordResetTicketNonceFile,0600);}finally{@flock($lock,LOCK_UN);@fclose($lock);}
    }

    public function verifyPasswordResetTicket($encoded,$signature,$expectedEmail,$consume){
        if(!$this->remoteAdminOpenSslAvailable())throw new Exception('Для восстановления пароля по email требуется OpenSSL.');$raw=$this->recoveryB64Decode($encoded);if($raw===false||strlen($raw)>16384)throw new Exception('Ссылка восстановления пароля повреждена.');$ticket=json_decode($raw,true);if(!is_array($ticket)||empty($ticket['format'])||$ticket['format']!=='ume-password-reset-ticket/1.0')throw new Exception('Некорректный ticket восстановления пароля.');foreach(array('target_site_id','target_domain','admin_email_hash','issued_at','expires_at','nonce') as $key)if(!isset($ticket[$key]))throw new Exception('Ticket восстановления пароля неполный.');$s=$this->settings();if($this->centralRepositoryEnabled($s)){$keys=$this->ensureRemoteAdminKeyPair();$public=$keys['public'];}else{$pin=$this->ensureClientRemoteAdminPin();$public=$pin['public_key'];}$sig=base64_decode((string)$signature,true);if($sig===false)throw new Exception('Подпись ссылки восстановления пароля повреждена.');$pub=@openssl_pkey_get_public($public);if($pub===false||@openssl_verify(UMEUtil::canonicalJson($ticket),$sig,$pub,OPENSSL_ALGO_SHA256)!==1)throw new Exception('Подпись ссылки восстановления пароля не подтверждена.');if(is_resource($pub))@openssl_free_key($pub);
        $now=time();$issued=(int)$ticket['issued_at'];$expires=(int)$ticket['expires_at'];if($issued>$now+60||$issued<$now-900||$expires<$now||$expires-$issued>600)throw new Exception('Ссылка восстановления пароля истекла или имеет некорректный срок действия.');if((string)$ticket['target_site_id']!==(string)$this->config['site_id'])throw new Exception('Ссылка восстановления пароля выпущена для другого сайта.');$domain=strtolower(trim(UMEUtil::domain(),'. '));if(strtolower(trim((string)$ticket['target_domain'],'. '))!==$domain)throw new Exception('Ссылка восстановления пароля выпущена для другого домена.');$email=strtolower(trim((string)$expectedEmail));if($email===''||filter_var($email,FILTER_VALIDATE_EMAIL)===false||!$this->eq((string)$ticket['admin_email_hash'],hash('sha256',$email)))throw new Exception('Ссылка восстановления пароля выпущена для другого администратора.');if($consume)$this->consumePasswordResetTicketNonce((string)$ticket['nonce'],$expires);return $ticket;
    }

    private function settings(){
        $s=UMEUtil::jsonRead($this->settingsFile,array());
        $defaultUrl='';
        $defaultKey='';
        if(!array_key_exists('update_server_url',$s)){$configured=isset($this->config['update_server_url'])?trim((string)$this->config['update_server_url']):'';$s['update_server_url']=$configured!==''?$configured:$defaultUrl;}
        if(!array_key_exists('update_repository_key',$s)){$configured=isset($this->config['update_repository_key'])?trim((string)$this->config['update_repository_key']):'';$s['update_repository_key']=$configured!==''?$configured:$defaultKey;}
        if(!isset($s['repository_enabled']))$s['repository_enabled']=false;
        if(!isset($s['repository_key']))$s['repository_key']='';
        if(!isset($s['smtp_enabled']))$s['smtp_enabled']=false;
        if(!isset($s['smtp_host']))$s['smtp_host']='';
        if(!isset($s['smtp_port']))$s['smtp_port']=587;
        if(!isset($s['smtp_security']))$s['smtp_security']='tls';
        if(!isset($s['smtp_username']))$s['smtp_username']='';
        if(!isset($s['smtp_password_enc']))$s['smtp_password_enc']='';
        if(!isset($s['smtp_from_email'])||trim((string)$s['smtp_from_email'])==='')$s['smtp_from_email']=$this->recoveryMailFromAddress();
        if(!isset($s['smtp_from_name'])||trim((string)$s['smtp_from_name'])==='')$s['smtp_from_name']='Upgrade Manager Security';
        return $s;
    }

    private function saveAllSettings($s){UMEUtil::jsonWriteAtomic($this->settingsFile,$s);}

    private function centralRepositoryEnabled($settings=null){
        $s=is_array($settings)?$settings:$this->settings();
        return !empty($s['repository_enabled']);
    }

    private function requireCentralRepositoryForManualUpdate(){
        $s=$this->settings();
        if(!$this->centralRepositoryEnabled($s))throw new Exception('Ручное самообновление FTP ZIP разрешено только на основном сайте, который включён как центральный репозиторий Upgrade Manager. На клиентских сайтах используйте обновление с центрального репозитория.');
        return $s;
    }

    private function normalizeFeedUrl($url){
        $url=trim((string)$url);
        if($url==='')return '';
        if(!preg_match('#^https://#i',$url))throw new Exception('Адрес сервера обновлений должен использовать HTTPS.');
        if(filter_var($url,FILTER_VALIDATE_URL)===false)throw new Exception('Некорректный адрес сервера обновлений.');
        return $url;
    }

    public function saveClientSettings($input){
        $s=$this->settings();$oldUrl=isset($s['update_server_url'])?(string)$s['update_server_url']:'';$oldKey=isset($s['update_repository_key'])?(string)$s['update_repository_key']:'';
        $url=isset($input['update_server_url'])?$this->normalizeFeedUrl($input['update_server_url']):'';
        $key=isset($input['update_repository_key'])?trim((string)$input['update_repository_key']):'';
        if($url!=='' && $key==='' && !empty($s['update_repository_key']))$key=$s['update_repository_key'];
        if($url==='')$key='';
        if(($url==='' && $key!=='')||($url!=='' && $key===''))throw new Exception('Для подключения нужны и URL, и ключ сервера обновлений.');
        if($key!=='' && !preg_match('/^[a-f0-9]{32,128}$/i',$key))throw new Exception('Ключ сервера обновлений имеет некорректный формат.');
        if($url!==$oldUrl||$key!==$oldKey)$this->clearClientRemoteAdminPin();
        $s['update_server_url']=$url;$s['update_repository_key']=$key;$this->saveAllSettings($s);
        return $this->status(false);
    }

    public function saveRepositorySettings($input){
        $s=$this->settings();$enabled=!empty($input['repository_enabled']);
        if($enabled && !UMEUtil::isHttps())throw new Exception('Центральный сервер обновлений можно включить только через HTTPS.');
        if($enabled && empty($s['repository_key']))$s['repository_key']=UMEUtil::randomHex(32);
        $s['repository_enabled']=$enabled;$this->saveAllSettings($s);if($enabled&&$this->remoteAdminOpenSslAvailable())$this->ensureRemoteAdminKeyPair();return $this->status(true);
    }

    private function currentVersion(){
        $release=UMEUtil::jsonRead($this->upgradeDir.'/release.json',array());
        if(!empty($release['version']))return (string)$release['version'];
        return isset($this->config['version'])?(string)$this->config['version']:'0.0.0';
    }

    private function feedUrl(){
        $scheme=UMEUtil::isHttps()?'https':'http';$domain=UMEUtil::domain();
        $script=isset($_SERVER['SCRIPT_NAME'])?str_replace('\\','/',$_SERVER['SCRIPT_NAME']):'/upgrade/index.php';
        $base=rtrim(dirname($script),'/');if($base==='.'||$base==='\\')$base='';
        return $scheme.'://'.$domain.$base.'/update-feed.php';
    }

    private function latestRepositoryRelease(){return UMEUtil::jsonRead($this->repoDir.'/latest.json',array());}

    private function installedReleaseMeta(){
        $release=UMEUtil::jsonRead($this->upgradeDir.'/release.json',array());
        if(!is_array($release)||empty($release['version']))throw new Exception('release.json текущей версии Upgrade Manager повреждён.');
        if(isset($release['product'])&&$release['product']!=='upgrade-manager')throw new Exception('release.json относится к другому продукту.');
        return $release;
    }

    private function repositoryReleaseHealthy($latest){
        if(!is_array($latest)||empty($latest['version'])||empty($latest['package_file'])||empty($latest['package_sha256']))return false;
        $packageFile=basename((string)$latest['package_file']);if($packageFile!==$latest['package_file'])return false;
        $file=$this->repoDir.'/releases/'.$packageFile;if(!is_file($file))return false;
        $sha=@hash_file('sha256',$file);return is_string($sha)&&$sha!==''&&strtolower($sha)===strtolower((string)$latest['package_sha256']);
    }

    private function publishInstalledRelease(){
        $s=$this->settings();if(empty($s['repository_enabled'])||empty($s['repository_key']))throw new Exception('Центральный сервер обновлений отключён.');
        $lock=@fopen($this->repoDir.'/publish.lock','c+');if(!$lock)throw new Exception('Не удалось открыть блокировку публикации релиза.');
        if(!@flock($lock,LOCK_EX)){@fclose($lock);throw new Exception('Не удалось получить блокировку публикации релиза.');}
        try{
            $release=$this->installedReleaseMeta();$version=trim((string)$release['version']);if(!preg_match('/^[0-9][0-9A-Za-z._-]{0,31}$/',$version))throw new Exception('Некорректная версия установленного релиза.');
            $previous=$this->latestRepositoryRelease();
            if(!empty($previous['version'])&&version_compare((string)$previous['version'],$version,'>'))throw new Exception('В центральном репозитории уже опубликована более новая версия '.$previous['version'].'.');
            if(!empty($previous['version'])&&(string)$previous['version']===$version&&$this->repositoryReleaseHealthy($previous))return array('published'=>true,'created'=>false,'release'=>$previous,'message'=>'Версия '.$version.' уже опубликована.');
            $notes=isset($release['notes'])?trim((string)$release['notes']):'';if(strlen($notes)>4000)$notes=substr($notes,0,4000);
            $files=$this->publishableFiles();$manifestFiles=array();$payload=array();
            foreach($files as $rel){$data=@file_get_contents($this->upgradeDir.'/'.$rel);if($data===false)throw new Exception('Не удалось прочитать файл релиза: '.$rel);$manifestFiles[]=array('path'=>$rel,'sha256'=>hash('sha256',$data),'size'=>strlen($data));$payload[$rel]=base64_encode($data);}
            $manifest=array('format'=>'ume-self-update/1.0','product'=>'upgrade-manager','version'=>$version,'min_php'=>'5.6.0','built_at'=>UMEUtil::now(),'files'=>$manifestFiles);
            $bundle=array('format'=>'ume-self-update-bundle/1.0','product'=>'upgrade-manager','manifest'=>$manifest,'payload'=>$payload);$json=json_encode($bundle,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if($json===false)throw new Exception('Не удалось сериализовать системный релиз.');if(strlen($json)>$this->maxPackageBytes)throw new Exception('Системный релиз превышает допустимые 32 МБ.');
            $safeVersion=preg_replace('/[^a-zA-Z0-9._-]+/','-',$version);$final=$this->repoDir.'/releases/upgrade-manager-'.$safeVersion.'.ume';$tmp=$final.'.tmp.'.UMEUtil::randomHex(4);$backup='';
            if(@file_put_contents($tmp,$json,LOCK_EX)===false){@unlink($tmp);throw new Exception('Не удалось сохранить временный системный релиз.');}
            if(is_file($final)){$backup=$final.'.old.'.UMEUtil::randomHex(3);if(!@rename($final,$backup)){@unlink($tmp);throw new Exception('Не удалось подготовить замену ранее опубликованного релиза.');}}
            if(!@rename($tmp,$final)){@unlink($tmp);if($backup!==''&&is_file($backup))@rename($backup,$final);throw new Exception('Не удалось опубликовать системный релиз.');}
            if($backup!==''&&is_file($backup))@unlink($backup);
            $latest=array('version'=>$version,'published_at'=>UMEUtil::now(),'notes'=>$notes,'min_php'=>'5.6.0','package_file'=>basename($final),'package_sha256'=>hash_file('sha256',$final),'package_bytes'=>filesize($final),'file_count'=>count($files),'automatic'=>true);
            $index=UMEUtil::jsonRead($this->repoDir.'/releases.json',array());$index[$version]=$latest;UMEUtil::jsonWriteAtomic($this->repoDir.'/releases.json',$index);UMEUtil::jsonWriteAtomic($this->repoDir.'/latest.json',$latest);$this->writeState($version,$files);
            return array('published'=>true,'created'=>true,'release'=>$latest,'message'=>'Версия '.$version.' автоматически опубликована для подключённых сайтов.');
        }finally{@flock($lock,LOCK_UN);@fclose($lock);}
    }

    private function automaticPublicationStatus(){
        $s=$this->settings();if(empty($s['repository_enabled']))return array('enabled'=>false,'state'=>'disabled','message'=>'Автопубликация действует только на центральном репозитории.');
        try{$result=$this->publishInstalledRelease();return array('enabled'=>true,'state'=>'published','created'=>!empty($result['created']),'message'=>$result['message'],'release'=>$result['release']);}
        catch(Exception $e){return array('enabled'=>true,'state'=>'error','created'=>false,'message'=>'Автопубликация не выполнена: '.$e->getMessage(),'error'=>$e->getMessage(),'release'=>$this->latestRepositoryRelease());}
    }

    public function status($includeSecret){
        $s=$this->settings();$automatic=array('enabled'=>false,'state'=>'disabled','message'=>'Автопубликация отключена.');
        if($this->centralRepositoryEnabled($s))$automatic=$this->automaticPublicationStatus();
        $latest=$this->latestRepositoryRelease();$remote=array('available'=>false,'mode'=>$this->centralRepositoryEnabled($s)?'central':'client','fingerprint'=>'','pinned'=>false,'reason'=>'');
        if(!$this->remoteAdminOpenSslAvailable())$remote['reason']='OpenSSL недоступен.';
        elseif($this->centralRepositoryEnabled($s)){
            try{$keys=$this->ensureRemoteAdminKeyPair();$remote['available']=(function_exists('curl_init')&&defined('CURLOPT_RESOLVE')&&defined('CURLINFO_PRIMARY_IP'))||function_exists('stream_socket_client');$remote['fingerprint']=$keys['fingerprint'];if(!$remote['available'])$remote['reason']='Для безопасного запроса к клиенту требуется cURL с CURLOPT_RESOLVE или stream_socket_client с OpenSSL.';}
            catch(Exception $e){$remote['reason']=$e->getMessage();}
        }else{
            $meta=UMEUtil::jsonRead($this->remoteAdminClientMeta,array());$remote['pinned']=is_file($this->remoteAdminClientKey)&&!empty($meta['fingerprint']);$remote['fingerprint']=$remote['pinned']?(string)$meta['fingerprint']:'';$remote['available']=!empty($s['update_server_url'])&&!empty($s['update_repository_key']);if(!$remote['available'])$remote['reason']='Центральный update-feed не настроен.';
        }
        return array(
            'current_version'=>$this->currentVersion(),
            'client'=>array('configured'=>!empty($s['update_server_url'])&&!empty($s['update_repository_key']),'server_url'=>isset($s['update_server_url'])?$s['update_server_url']:'','key_configured'=>!empty($s['update_repository_key']),'remote_admin_pinned'=>$remote['pinned'],'remote_admin_fingerprint'=>$remote['fingerprint']),
            'repository'=>array('enabled'=>!empty($s['repository_enabled']),'feed_url'=>$this->feedUrl(),'key'=>($includeSecret&&!empty($s['repository_key']))?$s['repository_key']:'','key_configured'=>!empty($s['repository_key']),'latest'=>$latest,'automatic_publication'=>$automatic,'remote_password_reset'=>$remote),
            'smtp'=>$this->smtpPublicSettings($s),
            'manual_self_update_allowed'=>$this->centralRepositoryEnabled($s),
            'remote_self_update_allowed'=>!$this->centralRepositoryEnabled($s),
            'zip_available'=>class_exists('ZipArchive'),
            'curl_available'=>function_exists('curl_init'),
            'allow_url_fopen'=>(bool)ini_get('allow_url_fopen')
        );
    }

    public function remoteAdminProbeInfo(){
        $s=$this->settings();$central=$this->centralRepositoryEnabled($s);$configured=!empty($s['update_server_url'])&&!empty($s['update_repository_key']);
        return array('central_repository'=>$central,'client_configured'=>$configured,'remote_reset_supported'=>!$central&&$configured&&$this->remoteAdminOpenSslAvailable(),'remote_set_credentials_supported'=>!$central&&$configured&&$this->remoteAdminOpenSslAvailable(),'recovery_email_supported'=>$configured&&$this->remoteAdminOpenSslAvailable(),'password_reset_email_supported'=>$configured&&$this->remoteAdminOpenSslAvailable());
    }

    private function httpGet($url,$key,$maxBytes){
        if(!preg_match('#^https://#i',$url))throw new Exception('Сервер обновлений должен использовать HTTPS.');
        $body='';$status=0;$contentLength=0;
        if(function_exists('curl_init')){
            $ch=curl_init($url);if(!$ch)throw new Exception('Не удалось инициализировать cURL.');
            curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_FOLLOWLOCATION,false);curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,10);curl_setopt($ch,CURLOPT_TIMEOUT,45);curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,true);curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);curl_setopt($ch,CURLOPT_HTTPHEADER,array('Accept: application/json, application/zip;q=0.9','X-UME-Update-Key: '.$key,'User-Agent: UpgradeManager/'.$this->currentVersion()));
            $body=curl_exec($ch);if($body===false){$e=curl_error($ch);curl_close($ch);throw new Exception('Не удалось связаться с сервером обновлений: '.$e);} $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$contentLength=(int)curl_getinfo($ch,CURLINFO_CONTENT_LENGTH_DOWNLOAD);curl_close($ch);
        }else{
            if(!ini_get('allow_url_fopen'))throw new Exception('На сервере нет cURL и отключён allow_url_fopen. Проверка обновлений невозможна.');
            $ctx=stream_context_create(array('http'=>array('method'=>'GET','timeout'=>45,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'header'=>"Accept: application/json, application/zip;q=0.9\r\nX-UME-Update-Key: ".$key."\r\nUser-Agent: UpgradeManager/".$this->currentVersion()."\r\n"),'ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false)));
            $body=@file_get_contents($url,false,$ctx);if($body===false)throw new Exception('Не удалось связаться с сервером обновлений.');
            if(isset($http_response_header)&&is_array($http_response_header))foreach($http_response_header as $h){if(preg_match('#^HTTP/\S+\s+(\d+)#i',$h,$m))$status=(int)$m[1];if(preg_match('/^Content-Length:\s*(\d+)/i',$h,$m))$contentLength=(int)$m[1];}
        }
        if($status<200||$status>=300)throw new Exception('Сервер обновлений вернул HTTP '.$status.'.');
        if($contentLength>$maxBytes||strlen($body)>$maxBytes)throw new Exception('Ответ сервера обновлений превышает допустимый размер.');
        return $body;
    }

    private function verifyFeed($feed,$key){
        if(!is_array($feed)||!isset($feed['format'])||$feed['format']!=='ume-update-feed/1.0'||!isset($feed['product'])||$feed['product']!=='upgrade-manager'||empty($feed['latest'])||!is_array($feed['latest'])||empty($feed['signature']))throw new Exception('Сервер обновлений вернул некорректный manifest.');
        $signed=array('format'=>$feed['format'],'product'=>$feed['product'],'latest'=>$feed['latest']);$expected=hash_hmac('sha256',UMEUtil::canonicalJson($signed),$key);
        if(!$this->eq($expected,$feed['signature']))throw new Exception('Подпись manifest сервера обновлений не совпала.');
        $l=$feed['latest'];foreach(array('version','published_at','min_php','package_url','package_sha256','package_bytes') as $k)if(!isset($l[$k]))throw new Exception('В manifest обновления отсутствует '.$k.'.');
        if(!preg_match('/^[a-f0-9]{64}$/i',$l['package_sha256']))throw new Exception('Некорректный SHA-256 пакета.');
        if(!preg_match('#^https://#i',$l['package_url']))throw new Exception('Пакет обновления должен скачиваться по HTTPS.');
        if(!UMEUtil::versionCompareMin(PHP_VERSION,$l['min_php']))throw new Exception('Для версии '.$l['version'].' требуется PHP '.$l['min_php'].' или новее.');
        return $l;
    }

    public function checkRemote(){
        $s=$this->settings();if($this->centralRepositoryEnabled($s))throw new Exception('Этот сайт является центральным репозиторием. На нём сетевое самообновление отключено: загрузите FTP ZIP вручную, затем опубликуйте релиз для клиентских сайтов.');if(empty($s['update_server_url'])||empty($s['update_repository_key']))throw new Exception('Сервер обновлений не настроен.');
        $raw=$this->httpGet($s['update_server_url'],$s['update_repository_key'],1048576);$feed=json_decode($raw,true);$latest=$this->verifyFeed($feed,$s['update_repository_key']);if(!empty($feed['remote_admin']))$this->pinRemoteAdminFromFeed($feed,$s['update_repository_key'],$s['update_server_url']);$current=$this->currentVersion();
        return array('current_version'=>$current,'latest_version'=>$latest['version'],'update_available'=>version_compare($latest['version'],$current,'>'),'published_at'=>$latest['published_at'],'notes'=>isset($latest['notes'])?$latest['notes']:'','package_bytes'=>(int)$latest['package_bytes'],'min_php'=>$latest['min_php']);
    }

    private function forbiddenCorePath($rel){
        $rel=str_replace('\\','/',(string)$rel);if(in_array($rel,array('config.local.php','update-source.local.php','setup-token.local.php','setup-key.local.php'),true))return true;
        if(UMEUtil::startsWith($rel,'.data')||UMEUtil::startsWith($rel,'.ume-'))return true;
        if(preg_match('#(^|/)config\.local\.php($|/)#i',$rel))return true;
        return false;
    }

    private function decodeBundle($raw,$expectedVersion){
        $bundle=json_decode($raw,true);if(!is_array($bundle)||!isset($bundle['format'])||$bundle['format']!=='ume-self-update-bundle/1.0'||!isset($bundle['product'])||$bundle['product']!=='upgrade-manager'||empty($bundle['manifest'])||!is_array($bundle['manifest'])||empty($bundle['payload'])||!is_array($bundle['payload']))throw new Exception('Пакет обновления имеет некорректный формат.');
        $m=$bundle['manifest'];if(empty($m['version'])||$m['version']!==$expectedVersion||empty($m['files'])||!is_array($m['files']))throw new Exception('Некорректный manifest системного обновления.');if(!empty($m['min_php'])&&!UMEUtil::versionCompareMin(PHP_VERSION,$m['min_php']))throw new Exception('Пакет требует PHP '.$m['min_php'].' или новее.');
        $files=array();$seen=array();foreach($m['files'] as $row){if(!is_array($row)||empty($row['path'])||empty($row['sha256'])||!isset($row['size']))throw new Exception('Некорректная запись файла в системном обновлении.');$rel=UMEUtil::normalizeRelativePath($row['path']);if($rel===false||$this->forbiddenCorePath($rel))throw new Exception('Недопустимый файл в релизе: '.(isset($row['path'])?$row['path']:'?'));if(isset($seen[$rel]))throw new Exception('Дублирующийся файл в релизе: '.$rel);$seen[$rel]=1;if(!array_key_exists($rel,$bundle['payload'])||!is_string($bundle['payload'][$rel]))throw new Exception('Файл отсутствует в bundle: '.$rel);$data=base64_decode($bundle['payload'][$rel],true);if($data===false)throw new Exception('Повреждён Base64 payload: '.$rel);if(strlen($data)!==(int)$row['size']||hash('sha256',$data)!==strtolower($row['sha256']))throw new Exception('SHA-256/размер не совпал: '.$rel);$files[$rel]=$data;}
        foreach(array('bootstrap.php','index.php','lib/Util.php','lib/Manager.php','lib/SelfUpdater.php','release.json') as $required)if(!isset($seen[$required]))throw new Exception('В релизе отсутствует обязательный файл: '.$required);
        return array('manifest'=>$m,'files'=>$files,'paths'=>array_keys($seen));
    }

    private function state(){return UMEUtil::jsonRead($this->repoDir.'/installed-state.json',array('version'=>$this->currentVersion(),'managed_files'=>array()));}
    private function writeState($version,$files){UMEUtil::jsonWriteAtomic($this->repoDir.'/installed-state.json',array('version'=>$version,'managed_files'=>array_values($files),'updated_at'=>UMEUtil::now()));}

    private function backupForUpdate($version,$newFiles,$deleteFiles){
        $id=date('Ymd-His').'-'.preg_replace('/[^a-zA-Z0-9._-]+/','-',$version).'-'.substr(UMEUtil::randomHex(4),0,8);$dir=$this->repoDir.'/rollbacks/'.$id;UMEUtil::ensureDir($dir.'/original');$manifest=array('version_before'=>$this->currentVersion(),'created_at'=>UMEUtil::now(),'original'=>array(),'absent'=>array());$targets=array_values(array_unique(array_merge($newFiles,$deleteFiles)));
        foreach($targets as $rel){if($this->forbiddenCorePath($rel))continue;$abs=$this->upgradeDir.'/'.$rel;if(is_file($abs)){$dest=$dir.'/original/'.$rel;UMEUtil::ensureDir(dirname($dest));if(!@copy($abs,$dest))throw new Exception('Не удалось сохранить файл перед обновлением: '.$rel);$manifest['original'][]=$rel;}else $manifest['absent'][]=$rel;}
        UMEUtil::jsonWriteAtomic($dir.'/manifest.json',$manifest);return $dir;
    }

    private function rollbackDirectory($dir){
        $m=UMEUtil::jsonRead($dir.'/manifest.json',array());if(!$m)throw new Exception('Rollback manifest обновления повреждён.');
        if(isset($m['absent'])&&is_array($m['absent']))foreach($m['absent'] as $rel){$rel=UMEUtil::normalizeRelativePath($rel);if($rel!==false&&!$this->forbiddenCorePath($rel)&&is_file($this->upgradeDir.'/'.$rel))@unlink($this->upgradeDir.'/'.$rel);}
        if(isset($m['original'])&&is_array($m['original']))foreach($m['original'] as $rel){$rel=UMEUtil::normalizeRelativePath($rel);if($rel===false||$this->forbiddenCorePath($rel))continue;$src=$dir.'/original/'.$rel;$dst=$this->upgradeDir.'/'.$rel;if(is_file($src)){UMEUtil::ensureDir(dirname($dst));$tmp=$dst.'.ume-rollback-'.UMEUtil::randomHex(3);if(!@copy($src,$tmp)||!@rename($tmp,$dst)){@unlink($tmp);throw new Exception('Не удалось восстановить '.$rel);}}}
    }

    private function pruneUpdateRollbacks(){
        $dirs=glob($this->repoDir.'/rollbacks/*',GLOB_ONLYDIR);if(!is_array($dirs))return;usort($dirs,function($a,$b){return filemtime($b)-filemtime($a);});for($i=3;$i<count($dirs);$i++)UMEUtil::removeTree($dirs[$i]);
    }

    public function applyRemote(){
        $s=$this->settings();if($this->centralRepositoryEnabled($s))throw new Exception('Этот сайт является центральным репозиторием. Сетевое самообновление на нём запрещено; обновляйте основной Upgrade Manager только проверенным FTP ZIP через ручную зону.');if(empty($s['update_server_url'])||empty($s['update_repository_key']))throw new Exception('Сервер обновлений не настроен.');
        $feedRaw=$this->httpGet($s['update_server_url'],$s['update_repository_key'],1048576);$feed=json_decode($feedRaw,true);$latest=$this->verifyFeed($feed,$s['update_repository_key']);if(!empty($feed['remote_admin']))$this->pinRemoteAdminFromFeed($feed,$s['update_repository_key'],$s['update_server_url']);$current=$this->currentVersion();if(!version_compare($latest['version'],$current,'>'))return array('updated'=>false,'current_version'=>$current,'latest_version'=>$latest['version'],'message'=>'Установлена актуальная версия.');
        if((int)$latest['package_bytes']>$this->maxPackageBytes)throw new Exception('Пакет обновления слишком большой.');
        $lock=fopen($this->repoDir.'/update.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new Exception('Другое обновление Upgrade Manager уже выполняется.');$rollbackDir='';
        try{
            $body=$this->httpGet($latest['package_url'],$s['update_repository_key'],$this->maxPackageBytes);if(hash('sha256',$body)!==strtolower($latest['package_sha256']))throw new Exception('SHA-256 скачанного пакета не совпал.');$decoded=$this->decodeBundle($body,$latest['version']);unset($body);$newFiles=$decoded['paths'];$state=$this->state();$oldFiles=isset($state['managed_files'])&&is_array($state['managed_files'])?$state['managed_files']:array();$deleteFiles=array_values(array_diff($oldFiles,$newFiles));$rollbackDir=$this->backupForUpdate($latest['version'],$newFiles,$deleteFiles);
            foreach($decoded['files'] as $rel=>$data){$target=$this->upgradeDir.'/'.$rel;UMEUtil::ensureDir(dirname($target));$tmp=$target.'.ume-new-'.UMEUtil::randomHex(3);if(@file_put_contents($tmp,$data,LOCK_EX)===false){@unlink($tmp);throw new Exception('Не удалось записать новый файл: '.$rel);}if(is_file($target))@chmod($tmp,fileperms($target)&0777);else @chmod($tmp,0644);if(!@rename($tmp,$target)){@unlink($tmp);throw new Exception('Не удалось заменить файл: '.$rel);}}
            foreach($deleteFiles as $rel){$rel=UMEUtil::normalizeRelativePath($rel);if($rel!==false&&!$this->forbiddenCorePath($rel)&&is_file($this->upgradeDir.'/'.$rel)&&!@unlink($this->upgradeDir.'/'.$rel))throw new Exception('Не удалось удалить устаревший файл: '.$rel);}
            $installed=UMEUtil::jsonRead($this->upgradeDir.'/release.json',array());if(empty($installed['version'])||$installed['version']!==$latest['version'])throw new Exception('После копирования файлов версия релиза не совпала.');$this->writeState($latest['version'],$newFiles);$this->pruneUpdateRollbacks();flock($lock,LOCK_UN);fclose($lock);
            return array('updated'=>true,'from_version'=>$current,'current_version'=>$latest['version'],'message'=>'Upgrade Manager обновлён до '.$latest['version'].'.','reload_required'=>true);
        }catch(Exception $e){
            if($rollbackDir!=='')try{$this->rollbackDirectory($rollbackDir);}catch(Exception $rollbackError){$e=new Exception($e->getMessage().' Автоматический откат также завершился ошибкой: '.$rollbackError->getMessage());}
            if(isset($lock)&&is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}throw $e;
        }
    }

    private function cleanupManualStages(){
        $files=glob($this->repoDir.'/temp/manual-*');if(!is_array($files))return;$cut=time()-21600;
        foreach($files as $file){if(is_file($file)&&@filemtime($file)<$cut)@unlink($file);}
    }

    private function manualStagePath($stageId,$suffix){
        $stageId=(string)$stageId;if(!preg_match('/^[A-Za-z0-9._-]{8,96}$/',$stageId))throw new Exception('Некорректный идентификатор загруженного обновления.');
        return $this->repoDir.'/temp/manual-'.$stageId.$suffix;
    }

    private function manualPublicMeta($meta){
        return array(
            'stage_id'=>isset($meta['stage_id'])?(string)$meta['stage_id']:'',
            'current_version'=>isset($meta['current_version'])?(string)$meta['current_version']:$this->currentVersion(),
            'version'=>isset($meta['version'])?(string)$meta['version']:'',
            'published_at'=>isset($meta['published_at'])?(string)$meta['published_at']:'',
            'notes'=>isset($meta['notes'])?(string)$meta['notes']:'',
            'package_bytes'=>isset($meta['package_bytes'])?(int)$meta['package_bytes']:0,
            'file_count'=>isset($meta['file_count'])?(int)$meta['file_count']:0,
            'created_at'=>isset($meta['created_at'])?(string)$meta['created_at']:'',
            'ready'=>!empty($meta['ready'])
        );
    }

    private function zipEntryIsSymlink($zip,$index){
        if(!method_exists($zip,'getExternalAttributesIndex'))return false;$opsys=0;$attr=0;
        if(!@$zip->getExternalAttributesIndex($index,$opsys,$attr))return false;
        $mode=($attr>>16)&0170000;return $mode===0120000;
    }

    private function parseBuildHashText($text){
        $map=array();$lines=preg_split('/\\r\\n|\\r|\\n/',(string)$text);
        foreach($lines as $line){$line=trim($line);if($line==='')continue;if(!preg_match('/^([a-f0-9]{64})\\s{2}(.+)$/i',$line,$m))throw new Exception('BUILD_SHA256SUMS.txt имеет некорректный формат.');$rel=UMEUtil::normalizeRelativePath($m[2]);if($rel===false||$rel===''||$this->forbiddenCorePath($rel))throw new Exception('Недопустимый путь в BUILD_SHA256SUMS.txt: '.$m[2]);if(isset($map[$rel]))throw new Exception('Дублирующийся путь в BUILD_SHA256SUMS.txt: '.$rel);$map[$rel]=strtolower($m[1]);}
        if(!$map)throw new Exception('BUILD_SHA256SUMS.txt пуст.');return $map;
    }

    private function inspectManualZip($zipFile){
        if(!class_exists('ZipArchive'))throw new Exception('Для ручного самообновления требуется PHP-расширение ZipArchive.');
        if(!is_file($zipFile))throw new Exception('ZIP обновления не найден.');$zip=new ZipArchive();$opened=$zip->open($zipFile);if($opened!==true)throw new Exception('Не удалось открыть ZIP обновления Upgrade Manager.');
        try{
            $rows=array();$byPath=array();$total=0;$count=0;$maxFiles=2500;$maxUnpacked=$this->maxPackageBytes*4;
            for($i=0;$i<$zip->numFiles;$i++){
                $name=$zip->getNameIndex($i);if(!is_string($name)||$name==='')throw new Exception('ZIP содержит запись с некорректным именем.');
                if(substr($name,-1)==='/')continue;if($this->zipEntryIsSymlink($zip,$i))throw new Exception('Символические ссылки запрещены в пакете самообновления: '.$name);
                $normalized=UMEUtil::normalizeRelativePath($name);if($normalized===false||strpos($normalized,'upgrade/')!==0)throw new Exception('FTP ZIP должен содержать только каталог upgrade/. Недопустимая запись: '.$name);
                $rel=UMEUtil::normalizeRelativePath(substr($normalized,8));if($rel===false||$rel===''||$this->forbiddenCorePath($rel))throw new Exception('Недопустимый файл в пакете самообновления: '.$name);
                if(isset($byPath[$rel]))throw new Exception('Дублирующийся файл в ZIP: '.$rel);
                $stat=$zip->statIndex($i);if(!is_array($stat)||!isset($stat['size']))throw new Exception('Не удалось прочитать метаданные ZIP: '.$rel);$size=(int)$stat['size'];if($size<0||$size>$maxUnpacked)throw new Exception('Недопустимый размер файла в ZIP: '.$rel);$total+=$size;$count++;if($count>$maxFiles)throw new Exception('В пакете слишком много файлов.');if($total>$maxUnpacked)throw new Exception('Распакованный пакет превышает допустимый размер.');
                $row=array('path'=>$rel,'zip_name'=>$name,'size'=>$size);$rows[]=$row;$byPath[$rel]=$row;
            }
            foreach(array('bootstrap.php','index.php','health.php','lib/Util.php','lib/Manager.php','lib/SelfUpdater.php','release.json','BUILD_SHA256SUMS.txt') as $required)if(!isset($byPath[$required]))throw new Exception('В FTP ZIP отсутствует обязательный файл: upgrade/'.$required);
            $releaseRaw=$zip->getFromName($byPath['release.json']['zip_name']);$release=json_decode($releaseRaw,true);if(!is_array($release)||!isset($release['format'])||$release['format']!=='ume-release/1.0'||empty($release['version']))throw new Exception('release.json не является релизом Upgrade Manager.');if(isset($release['product'])&&$release['product']!=='upgrade-manager')throw new Exception('ZIP предназначен для другого продукта.');
            $version=(string)$release['version'];$current=$this->currentVersion();if(!version_compare($version,$current,'>'))throw new Exception('Версия в ZIP ('.$version.') должна быть выше установленной '.$current.'.');
            $hashText=$zip->getFromName($byPath['BUILD_SHA256SUMS.txt']['zip_name']);if($hashText===false)throw new Exception('Не удалось прочитать BUILD_SHA256SUMS.txt.');$hashes=$this->parseBuildHashText($hashText);
            foreach($rows as $idx=>$row){$rel=$row['path'];if($rel==='BUILD_SHA256SUMS.txt')continue;if(!isset($hashes[$rel]))throw new Exception('Файл отсутствует в BUILD_SHA256SUMS.txt: '.$rel);$data=$zip->getFromName($row['zip_name']);if($data===false)throw new Exception('Не удалось прочитать файл из ZIP: '.$rel);if(strlen($data)!==$row['size'])throw new Exception('Размер файла не совпал: '.$rel);$sha=hash('sha256',$data);if($sha!==$hashes[$rel])throw new Exception('SHA-256 файла не совпал: '.$rel);$rows[$idx]['sha256']=$sha;unset($data);}
            foreach($hashes as $rel=>$sha)if(!isset($byPath[$rel]))throw new Exception('BUILD_SHA256SUMS.txt ссылается на отсутствующий файл: '.$rel);
            return array('version'=>$version,'published_at'=>isset($release['published_at'])?(string)$release['published_at']:'','notes'=>isset($release['notes'])?(string)$release['notes']:'','files'=>$rows,'paths'=>array_keys($byPath),'file_count'=>count($rows),'unpacked_bytes'=>$total);
        }finally{$zip->close();}
    }

    public function stageManualUpload($file){
        $this->requireCentralRepositoryForManualUpdate();$this->cleanupManualStages();if(!is_array($file)||!isset($file['error'])||(int)$file['error']!==UPLOAD_ERR_OK)throw new Exception('ZIP обновления не был загружен.');$name=isset($file['name'])?(string)$file['name']:'';if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='zip')throw new Exception('Для самообновления принимается только FTP ZIP Upgrade Manager.');$tmp=isset($file['tmp_name'])?(string)$file['tmp_name']:'';if($tmp===''||!is_file($tmp))throw new Exception('Временный файл загрузки не найден.');$size=@filesize($tmp);if($size===false||$size<=0)throw new Exception('Загруженный ZIP пуст.');if($size>$this->maxPackageBytes)throw new Exception('ZIP обновления превышает допустимые 32 МБ.');
        $stageId=date('YmdHis').'-'.substr(UMEUtil::randomHex(8),0,16);$dest=$this->manualStagePath($stageId,'.zip');$moved=false;if(function_exists('is_uploaded_file')&&@is_uploaded_file($tmp))$moved=@move_uploaded_file($tmp,$dest);if(!$moved)$moved=@copy($tmp,$dest);if(!$moved)throw new Exception('Не удалось сохранить загруженный ZIP во временное хранилище.');@chmod($dest,0600);
        try{$inspection=$this->inspectManualZip($dest);$meta=array('format'=>'ume-manual-self-update-stage/1.0','stage_id'=>$stageId,'current_version'=>$this->currentVersion(),'version'=>$inspection['version'],'published_at'=>$inspection['published_at'],'notes'=>$inspection['notes'],'package_bytes'=>(int)filesize($dest),'package_sha256'=>hash_file('sha256',$dest),'file_count'=>$inspection['file_count'],'created_at'=>UMEUtil::now(),'created_ts'=>time(),'ready'=>true);UMEUtil::jsonWriteAtomic($this->manualStagePath($stageId,'.json'),$meta);return $this->manualPublicMeta($meta);}catch(Exception $e){@unlink($dest);@unlink($this->manualStagePath($stageId,'.json'));throw $e;}
    }

    private function managedFilesBeforeUpdate(){
        $seen=array();$state=$this->state();$items=isset($state['managed_files'])&&is_array($state['managed_files'])?$state['managed_files']:array();foreach($items as $rel){$rel=UMEUtil::normalizeRelativePath($rel);if($rel!==false&&$rel!==''&&!$this->forbiddenCorePath($rel))$seen[$rel]=1;}
        $hashFile=$this->upgradeDir.'/BUILD_SHA256SUMS.txt';if(is_file($hashFile)){try{$map=$this->parseBuildHashText(@file_get_contents($hashFile));foreach($map as $rel=>$sha)$seen[$rel]=1;$seen['BUILD_SHA256SUMS.txt']=1;}catch(Exception $ignore){}}
        $seen['release.json']=1;return array_keys($seen);
    }

    private function verifyInstalledManualFiles($inspection){
        foreach($inspection['files'] as $row){$rel=$row['path'];$target=$this->upgradeDir.'/'.$rel;if(!is_file($target))throw new Exception('После обновления отсутствует файл: '.$rel);if($rel==='BUILD_SHA256SUMS.txt')continue;if(empty($row['sha256'])||hash_file('sha256',$target)!==$row['sha256'])throw new Exception('После обновления SHA-256 не совпал: '.$rel);}
        $release=UMEUtil::jsonRead($this->upgradeDir.'/release.json',array());if(empty($release['version'])||$release['version']!==$inspection['version'])throw new Exception('После обновления версия release.json не совпала с ожидаемой.');
        $hashText=@file_get_contents($this->upgradeDir.'/BUILD_SHA256SUMS.txt');$hashes=$this->parseBuildHashText($hashText);foreach($hashes as $rel=>$sha){$target=$this->upgradeDir.'/'.$rel;if(!is_file($target)||hash_file('sha256',$target)!==$sha)throw new Exception('Контрольная проверка установленного файла не пройдена: '.$rel);}
    }

    public function applyManualUpload($stageId){
        $this->requireCentralRepositoryForManualUpdate();$this->cleanupManualStages();$metaFile=$this->manualStagePath($stageId,'.json');$zipFile=$this->manualStagePath($stageId,'.zip');$meta=UMEUtil::jsonRead($metaFile,array());if(!$meta||empty($meta['ready'])||empty($meta['version'])||empty($meta['package_sha256']))throw new Exception('Подготовленное обновление не найдено или устарело.');if(!is_file($zipFile)||hash_file('sha256',$zipFile)!==strtolower($meta['package_sha256']))throw new Exception('Загруженный ZIP был изменён или повреждён.');if(isset($meta['created_ts'])&&time()-(int)$meta['created_ts']>21600)throw new Exception('Подготовленное обновление устарело. Загрузите ZIP заново.');$current=$this->currentVersion();if(!empty($meta['current_version'])&&$meta['current_version']!==$current)throw new Exception('После загрузки ZIP установленная версия изменилась. Загрузите пакет заново.');
        $inspection=$this->inspectManualZip($zipFile);if($inspection['version']!==$meta['version'])throw new Exception('Версия подготовленного ZIP изменилась.');$lock=fopen($this->repoDir.'/update.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new Exception('Другое обновление Upgrade Manager уже выполняется.');$rollbackDir='';$zip=null;
        try{
            $newFiles=$inspection['paths'];$oldFiles=$this->managedFilesBeforeUpdate();$deleteFiles=array_values(array_diff($oldFiles,$newFiles));$rollbackDir=$this->backupForUpdate($inspection['version'],$newFiles,$deleteFiles);$zip=new ZipArchive();if($zip->open($zipFile)!==true)throw new Exception('Не удалось повторно открыть подготовленный ZIP.');
            foreach($inspection['files'] as $row){$rel=$row['path'];$data=$zip->getFromName($row['zip_name']);if($data===false)throw new Exception('Не удалось прочитать файл из ZIP: '.$rel);if($rel!=='BUILD_SHA256SUMS.txt'&&(empty($row['sha256'])||hash('sha256',$data)!==$row['sha256']))throw new Exception('Повторная проверка файла не пройдена: '.$rel);$target=$this->upgradeDir.'/'.$rel;UMEUtil::ensureDir(dirname($target));$tmp=$target.'.ume-new-'.UMEUtil::randomHex(3);if(@file_put_contents($tmp,$data,LOCK_EX)===false){@unlink($tmp);throw new Exception('Не удалось записать новый файл: '.$rel);}if(is_file($target))@chmod($tmp,fileperms($target)&0777);else @chmod($tmp,0644);if(!@rename($tmp,$target)){@unlink($tmp);throw new Exception('Не удалось заменить файл: '.$rel);}unset($data);}
            $zip->close();$zip=null;foreach($deleteFiles as $rel){$rel=UMEUtil::normalizeRelativePath($rel);if($rel!==false&&$rel!==''&&!$this->forbiddenCorePath($rel)&&is_file($this->upgradeDir.'/'.$rel)&&!@unlink($this->upgradeDir.'/'.$rel))throw new Exception('Не удалось удалить устаревший файл: '.$rel);}
            $this->verifyInstalledManualFiles($inspection);$this->writeState($inspection['version'],$newFiles);UMEUtil::jsonWriteAtomic($this->repoDir.'/last-manual-update.json',array('from_version'=>$current,'to_version'=>$inspection['version'],'installed_at'=>UMEUtil::now(),'package_sha256'=>$meta['package_sha256'],'rollback_dir'=>basename($rollbackDir)));$this->pruneUpdateRollbacks();@unlink($zipFile);@unlink($metaFile);flock($lock,LOCK_UN);fclose($lock);
            $publication=$this->automaticPublicationStatus();$message='Upgrade Manager обновлён до '.$inspection['version'].' из FTP ZIP. Локальные config.local.php, .data и настройки сохранены.';
            if(isset($publication['state'])&&$publication['state']==='published')$message.=' '.$publication['message'];
            elseif(isset($publication['state'])&&$publication['state']==='error')$message.=' '.$publication['message'].' Повтор будет выполнен автоматически при следующем открытии страницы самообновления.';
            return array('updated'=>true,'from_version'=>$current,'current_version'=>$inspection['version'],'message'=>$message,'automatic_publication'=>$publication,'reload_required'=>true);
        }catch(Exception $e){if($zip instanceof ZipArchive)@$zip->close();if($rollbackDir!=='')try{$this->rollbackDirectory($rollbackDir);}catch(Exception $rollbackError){$e=new Exception($e->getMessage().' Автоматический откат также завершился ошибкой: '.$rollbackError->getMessage());}if(isset($lock)&&is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}throw $e;}
    }

    private function publishableFiles(){
        $out=array();$stack=array(array($this->upgradeDir,''));
        while($stack){$node=array_pop($stack);$dir=$node[0];$base=$node[1];$items=@scandir($dir);if(!is_array($items))throw new Exception('Не удалось прочитать каталог релиза: '.$dir);foreach($items as $name){if($name==='.'||$name==='..')continue;$rel=$base===''?$name:$base.'/'.$name;$abs=$dir.'/'.$name;if(is_dir($abs)&&!is_link($abs)){if($this->forbiddenCorePath($rel)||$name==='.git'||UMEUtil::startsWith($name,'.data')||UMEUtil::startsWith($name,'.ume-'))continue;$stack[]=array($abs,$rel);continue;}if(!is_file($abs)||is_link($abs))continue;if($this->forbiddenCorePath($rel))continue;$out[]=$rel;}}
        sort($out,SORT_STRING);return $out;
    }

    private function regenerateBuildHashes($files){
        $rows=array();foreach($files as $rel){if($rel==='BUILD_SHA256SUMS.txt')continue;$rows[]=hash_file('sha256',$this->upgradeDir.'/'.$rel).'  '.$rel;}@file_put_contents($this->upgradeDir.'/BUILD_SHA256SUMS.txt',implode("\n",$rows)."\n",LOCK_EX);
    }

    public function buildRelease($version,$notes){
        $s=$this->settings();if(empty($s['repository_enabled'])||empty($s['repository_key']))throw new Exception('Сначала включите режим центрального сервера обновлений.');
        $version=trim((string)$version);if(!preg_match('/^[0-9][0-9A-Za-z._-]{0,31}$/',$version))throw new Exception('Некорректная версия релиза.');
        $previous=$this->latestRepositoryRelease();if(!empty($previous['version'])&&!version_compare($version,$previous['version'],'>'))throw new Exception('Новая версия должна быть выше уже опубликованной '.$previous['version'].'.');
        $notes=trim((string)$notes);if(strlen($notes)>4000)$notes=substr($notes,0,4000);
        $releaseFile=$this->upgradeDir.'/release.json';$hashFile=$this->upgradeDir.'/BUILD_SHA256SUMS.txt';$oldRelease=is_file($releaseFile)?file_get_contents($releaseFile):false;$oldHashes=is_file($hashFile)?file_get_contents($hashFile):false;$final='';
        try{
            $releaseMeta=array('format'=>'ume-release/1.0','product'=>'upgrade-manager','version'=>$version,'channel'=>'stable','published_at'=>UMEUtil::now(),'notes'=>$notes);UMEUtil::jsonWriteAtomic($releaseFile,$releaseMeta);
            $files=$this->publishableFiles();$this->regenerateBuildHashes($files);$files=$this->publishableFiles();$manifestFiles=array();$payload=array();foreach($files as $rel){$data=@file_get_contents($this->upgradeDir.'/'.$rel);if($data===false)throw new Exception('Не удалось прочитать файл релиза: '.$rel);$manifestFiles[]=array('path'=>$rel,'sha256'=>hash('sha256',$data),'size'=>strlen($data));$payload[$rel]=base64_encode($data);}
            $manifest=array('format'=>'ume-self-update/1.0','product'=>'upgrade-manager','version'=>$version,'min_php'=>'5.6.0','built_at'=>UMEUtil::now(),'files'=>$manifestFiles);$bundle=array('format'=>'ume-self-update-bundle/1.0','product'=>'upgrade-manager','manifest'=>$manifest,'payload'=>$payload);$json=json_encode($bundle,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new Exception('Не удалось сериализовать системный релиз.');if(strlen($json)>$this->maxPackageBytes)throw new Exception('Системный релиз превышает допустимые 32 МБ.');
            $safeVersion=preg_replace('/[^a-zA-Z0-9._-]+/','-',$version);$final=$this->repoDir.'/releases/upgrade-manager-'.$safeVersion.'.ume';if(is_file($final))throw new Exception('Пакет этой версии уже существует.');$tmp=$final.'.tmp.'.UMEUtil::randomHex(4);if(@file_put_contents($tmp,$json,LOCK_EX)===false||!@rename($tmp,$final)){@unlink($tmp);throw new Exception('Не удалось сохранить системный релиз.');}
            $latest=array('version'=>$version,'published_at'=>$releaseMeta['published_at'],'notes'=>$notes,'min_php'=>'5.6.0','package_file'=>basename($final),'package_sha256'=>hash_file('sha256',$final),'package_bytes'=>filesize($final),'file_count'=>count($files));$index=UMEUtil::jsonRead($this->repoDir.'/releases.json',array());$index[$version]=$latest;UMEUtil::jsonWriteAtomic($this->repoDir.'/releases.json',$index);UMEUtil::jsonWriteAtomic($this->repoDir.'/latest.json',$latest);$this->writeState($version,$files);return $latest;
        }catch(Exception $e){if($final!==''&&is_file($final))@unlink($final);if($oldRelease!==false)@file_put_contents($releaseFile,$oldRelease,LOCK_EX);else @unlink($releaseFile);if($oldHashes!==false)@file_put_contents($hashFile,$oldHashes,LOCK_EX);else @unlink($hashFile);throw $e;}
    }

    public function repositoryFeed($providedKey){
        $s=$this->settings();if(empty($s['repository_enabled'])||empty($s['repository_key']))throw new Exception('Центральный сервер обновлений отключён.');if(!$this->eq($s['repository_key'],(string)$providedKey))throw new Exception('Неверный ключ сервера обновлений.');
        // Клиентский запрос к feed сам является триггером автопубликации. Поэтому даже после аварийного FTP-обновления
        // центрального сайта не требуется заходить в админку и нажимать отдельную кнопку публикации.
        $this->publishInstalledRelease();
        $latest=$this->latestRepositoryRelease();if(empty($latest['version'])||empty($latest['package_file']))throw new Exception('На сервере ещё нет опубликованного релиза.');$package=$this->repoDir.'/releases/'.$latest['package_file'];if(!is_file($package))throw new Exception('Файл опубликованного релиза не найден.');$base=$this->feedUrl();$publicLatest=array('version'=>$latest['version'],'published_at'=>$latest['published_at'],'notes'=>isset($latest['notes'])?$latest['notes']:'','min_php'=>$latest['min_php'],'package_url'=>$base.'?action=package&version='.rawurlencode($latest['version']),'package_sha256'=>$latest['package_sha256'],'package_bytes'=>(int)$latest['package_bytes']);$feed=array('format'=>'ume-update-feed/1.0','product'=>'upgrade-manager','latest'=>$publicLatest);$feed['signature']=hash_hmac('sha256',UMEUtil::canonicalJson($feed),$s['repository_key']);$remote=$this->repositoryRemoteAdminBundle($s);if($remote){$feed['remote_admin']=$remote;$feed['remote_admin_signature']=hash_hmac('sha256',UMEUtil::canonicalJson($remote),$s['repository_key']);}return $feed;
    }

    public function repositoryPackage($providedKey,$version){
        $s=$this->settings();if(empty($s['repository_enabled'])||empty($s['repository_key']))throw new Exception('Центральный сервер обновлений отключён.');if(!$this->eq($s['repository_key'],(string)$providedKey))throw new Exception('Неверный ключ сервера обновлений.');
        $version=(string)$version;$index=UMEUtil::jsonRead($this->repoDir.'/releases.json',array());$meta=isset($index[$version])&&is_array($index[$version])?$index[$version]:array();if(!$meta){$latest=$this->latestRepositoryRelease();if(!empty($latest['version'])&&$latest['version']===$version)$meta=$latest;}if(empty($meta['package_file'])||empty($meta['package_sha256']))throw new Exception('Запрошенная версия не опубликована.');$packageFile=basename((string)$meta['package_file']);if($packageFile!==$meta['package_file'])throw new Exception('Некорректное имя пакета релиза.');$file=$this->repoDir.'/releases/'.$packageFile;if(!is_file($file)||hash_file('sha256',$file)!==$meta['package_sha256'])throw new Exception('Пакет релиза повреждён.');return $file;
    }

    private function selfBackupExcludedRelPaths(){
        $out=array();
        $upgradeReal=realpath($this->upgradeDir);$storageReal=realpath($this->storage);
        if(!$upgradeReal||!$storageReal)return $out;
        $upgradeReal=rtrim(str_replace('\\','/',$upgradeReal),'/');$storageReal=rtrim(str_replace('\\','/',$storageReal),'/');
        $prefix=$upgradeReal.'/';
        if($storageReal!==$upgradeReal && strpos($storageReal,$prefix)!==0)return $out;
        $storageRel=$storageReal===$upgradeReal?'':ltrim(substr($storageReal,strlen($upgradeReal)),'/');
        foreach(array('backups','public-backups','rollbacks','migrations') as $name){$rel=trim(($storageRel!==''?$storageRel.'/':'').$name,'/');if($rel!=='')$out[]=$rel;}
        return array_values(array_unique($out));
    }

    private function selfBackupRelExcluded($rel,$excluded){
        $rel=trim(str_replace('\\','/',(string)$rel),'/');
        foreach($excluded as $base){$base=trim(str_replace('\\','/',(string)$base),'/');if($base!==''&&($rel===$base||strpos($rel,$base.'/')===0))return true;}
        return false;
    }

    private function collectSelfBackupTree($absDir,$relDir,$excluded,&$files,&$dirs,&$totalBytes){
        $dh=@opendir($absDir);if(!$dh)throw new Exception('Не удалось прочитать каталог собственного бэкапа: '.($relDir!==''?$relDir:'/upgrade/'));
        $names=array();while(($name=readdir($dh))!==false){if($name==='.'||$name==='..')continue;$names[]=$name;}closedir($dh);sort($names,SORT_STRING);
        foreach($names as $name){
            $rel=$relDir===''?$name:$relDir.'/'.$name;if($this->selfBackupRelExcluded($rel,$excluded))continue;$abs=$absDir.'/'.$name;
            if(is_link($abs))throw new Exception('Полный бэкап /upgrade/ остановлен: обнаружена символическая ссылка '.$rel.'. Ссылки не архивируются, чтобы не выйти за пределы /upgrade/.');
            if(is_dir($abs)){$dirs[]=$rel;$this->collectSelfBackupTree($abs,$rel,$excluded,$files,$dirs,$totalBytes);continue;}
            if(!is_file($abs))continue;$size=@filesize($abs);if($size===false)throw new Exception('Не удалось определить размер файла: '.$rel);$files[]=$rel;$totalBytes+=(int)$size;
        }
    }

    public function createFullSelfBackup(){
        $s=$this->settings();if(!$this->centralRepositoryEnabled($s))throw new Exception('Полный бэкап самого Upgrade Manager доступен только на основном сайте — центральном репозитории.');
        if(!class_exists('ZipArchive'))throw new Exception('Для полного бэкапа Upgrade Manager требуется PHP-расширение ZipArchive.');
        $upgradeReal=realpath($this->upgradeDir);if(!$upgradeReal||!is_dir($upgradeReal))throw new Exception('Не удалось определить каталог /upgrade/.');
        $upgradeReal=rtrim(str_replace('\\','/',$upgradeReal),'/');$excluded=$this->selfBackupExcludedRelPaths();$files=array();$dirs=array();$totalBytes=0;
        $this->collectSelfBackupTree($upgradeReal,'',$excluded,$files,$dirs,$totalBytes);
        if(!in_array('index.php',$files,true)||!in_array('bootstrap.php',$files,true))throw new Exception('Каталог /upgrade/ неполный: отсутствуют обязательные системные файлы.');
        UMEUtil::ensureDir($this->storage.'/temp');$safeVersion=preg_replace('/[^A-Za-z0-9._-]+/','-',$this->currentVersion());$stamp=date('Ymd-His');$name='upgrade-manager-self-backup-'.$safeVersion.'-'.$stamp.'.zip';$tmp=$this->storage.'/temp/'.$name.'.part-'.substr(UMEUtil::randomHex(4),0,8);@unlink($tmp);
        $zip=new ZipArchive();$opened=$zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE);if($opened!==true)throw new Exception('Не удалось создать ZIP полного бэкапа Upgrade Manager.');
        try{
            if(!$zip->addEmptyDir('upgrade'))throw new Exception('Не удалось создать корневой каталог upgrade/ в ZIP.');
            foreach($dirs as $rel)if(!$zip->addEmptyDir('upgrade/'.$rel))throw new Exception('Не удалось добавить каталог в ZIP: '.$rel);
            foreach($files as $rel){$abs=$upgradeReal.'/'.$rel;if(!$zip->addFile($abs,'upgrade/'.$rel))throw new Exception('Не удалось добавить файл в ZIP: '.$rel);}
            if(!$zip->addFromString('00_START_HERE_FOR_GPT.md',UMEBackupAgentPack::selfBackupGuide($this->currentVersion(),true)))throw new Exception('Не удалось добавить инструкцию в self-backup.');
            if(!$zip->addFromString('AGENTS.md','Read 00_START_HERE_FOR_GPT.md before preparing an update. Private full self-backup contains secrets: NEVER share with AI or third parties.'))throw new Exception('Не удалось добавить AGENTS.md.');
            if(method_exists($zip,'setArchiveComment'))@$zip->setArchiveComment('Upgrade Manager full self backup '.$this->currentVersion().' | no site database | site backup archives excluded');
            if(!$zip->close())throw new Exception('Не удалось завершить ZIP полного бэкапа Upgrade Manager.');
        }catch(Exception $e){@$zip->close();@unlink($tmp);throw $e;}
        if(!is_file($tmp)||filesize($tmp)<=0){@unlink($tmp);throw new Exception('ZIP полного бэкапа Upgrade Manager не создан.');}
        @chmod($tmp,0600);
        $verify=new ZipArchive();$check=$verify->open($tmp);if($check!==true){@unlink($tmp);throw new Exception('Созданный ZIP не прошёл повторное открытие.');}
        $ok=$verify->locateName('upgrade/index.php')!==false&&$verify->locateName('upgrade/bootstrap.php')!==false&&$verify->locateName('upgrade/release.json')!==false;$verify->close();if(!$ok){@unlink($tmp);throw new Exception('Созданный ZIP не содержит обязательные файлы Upgrade Manager.');}
        return array('path'=>$tmp,'name'=>$name,'size'=>(int)filesize($tmp),'files_count'=>count($files),'source_bytes'=>$totalBytes,'excluded'=>$excluded,'version'=>$this->currentVersion());
    }

    // A source-code export must NEVER include config.local.php, OAuth tokens, runtime storage,
    // or any untracked files. Build from the verified managed-file hash allowlist only.
    public function createSafeSelfSourceArchive(){
        $s=$this->settings();if(!$this->centralRepositoryEnabled($s))throw new Exception('Экспорт исходников доступен только центральному репозиторию.');
        if(!class_exists('ZipArchive'))throw new Exception('Для экспорта исходников требуется ZipArchive.');
        $base=realpath($this->upgradeDir);if(!$base||!is_dir($base))throw new Exception('Каталог Upgrade Manager не найден.');
        $base=rtrim(str_replace('\\','/',$base),'/');
        $hashes=$this->parseBuildHashText(@file_get_contents($base.'/BUILD_SHA256SUMS.txt'));
        $files=array();foreach($hashes as $rel=>$expected){
            if($this->forbiddenCorePath($rel)||$this->selfBackupRelExcluded($rel,$this->selfBackupExcludedRelPaths()))throw new Exception('В списке системных файлов обнаружен закрытый путь.');
            $abs=$base.'/'.$rel;$real=realpath($abs);
            if(is_link($abs)||!is_file($abs)||$real===false||strpos(str_replace('\\','/',$real),$base.'/')!==0)throw new Exception('Системный файл отсутствует или небезопасен: '.$rel);
            if(hash_file('sha256',$abs)!==$expected)throw new Exception('Файл изменён после сборки контрольных сумм: '.$rel.'. Сначала опубликуйте системный релиз.');
            $files[$rel]=$abs;
        }
        foreach(array('bootstrap.php','index.php','release.json','lib/SelfUpdater.php','lib/Manager.php') as $required)if(!isset($files[$required]))throw new Exception('В исходном релизе отсутствует '.$required);
        $files['BUILD_SHA256SUMS.txt']=$base.'/BUILD_SHA256SUMS.txt';
        UMEUtil::ensureDir($this->storage.'/temp');$name='upgrade-manager-source-'.$this->currentVersion().'-'.date('Ymd-His').'.zip';
        $tmp=$this->storage.'/temp/'.$name.'.part-'.substr(UMEUtil::randomHex(4),0,8);$zip=new ZipArchive();
        if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new Exception('Не удалось создать безопасный архив исходников.');
        try{if(!$zip->addEmptyDir('upgrade'))throw new Exception('Ошибка ZIP.');foreach($files as $rel=>$abs)if(!$zip->addFile($abs,'upgrade/'.$rel))throw new Exception('Ошибка добавления файла: '.$rel);
            if(!$zip->addFromString('00_START_HERE_FOR_GPT.md',UMEBackupAgentPack::selfBackupGuide($this->currentVersion(),false)))throw new Exception('Ошибка записи инструкции.');
            if(!$zip->addFromString('AGENTS.md','Read 00_START_HERE_FOR_GPT.md for the central repository release format. Never use a site patch to update /upgrade/.'))throw new Exception('Ошибка записи AGENTS.md.');
            if(!$zip->close())throw new Exception('Ошибка записи ZIP.');}
        catch(Exception $e){@$zip->close();@unlink($tmp);throw $e;}
        @chmod($tmp,0600);
        return array('path'=>$tmp,'name'=>$name,'size'=>(int)filesize($tmp),'files_count'=>count($files),'version'=>$this->currentVersion(),'contains_secrets'=>false);
    }

    public function networkInstallerSource(){
        $s=$this->settings();if(empty($s['repository_enabled'])||empty($s['repository_key']))throw new Exception('Центральный сервер обновлений отключён.');$latest=$this->latestRepositoryRelease();if(empty($latest['version']))throw new Exception('Сначала опубликуйте хотя бы один релиз.');$feed=$this->feedUrl();$key=$s['repository_key'];
        $tpl=<<<'PHP'
<?php
// Upgrade Manager network installer (generated by Upgrade Manager __VERSION__ central repository).
// upgrade.php: upload to the site root, open https://<site>/upgrade.php in a browser and press "Установить".
// It downloads the current release over HTTPS, verifies the feed signature and SHA-256 of every file,
// installs /upgrade/ atomically and hands a one-time setup permit (30 min) to the browser that installed it.
error_reporting(E_ALL);ini_set('display_errors','0');
header('X-Frame-Options: DENY');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('X-Robots-Tag: noindex, nofollow');
$feedUrl=__FEED__;$repoKey=__KEY__;$ni_tmp='';
function ni_h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function ni_page($title,$body,$code){http_response_code($code);header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.ni_h($title).'</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f4f6fb;color:#182033;font:15px/1.55 -apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif}.card{max-width:520px;margin:24px;padding:28px;background:#fff;border:1px solid #dfe4ee;border-radius:16px;box-shadow:0 8px 30px rgba(31,45,75,.08)}h1{font-size:22px;margin:0 0 10px}p{margin:0 0 14px;color:#475467}.err{color:#b42318}button{width:100%;padding:12px 16px;border:0;border-radius:10px;background:#315fcf;color:#fff;font-size:15px;font-weight:600;cursor:pointer}small{display:block;margin-top:14px;color:#667085}</style></head><body><div class="card">'.$body.'</div></body></html>';exit;}
function ni_rm($p){if(is_link($p)||is_file($p)){@unlink($p);return;}if(!is_dir($p))return;$items=@scandir($p);if(is_array($items))foreach($items as $i){if($i==='.'||$i==='..')continue;ni_rm($p.'/'.$i);}@rmdir($p);}
function ni_fail($m){global $ni_tmp;if($ni_tmp!==''&&is_dir($ni_tmp))ni_rm($ni_tmp);ni_page('Upgrade Manager — ошибка установки','<h1>Установка не выполнена</h1><p class="err">'.ni_h($m).'</p><small>Сайт не изменён: временные файлы установщика удалены.</small>',500);}
function ni_eq($a,$b){$a=(string)$a;$b=(string)$b;if(function_exists('hash_equals'))return hash_equals($a,$b);if(strlen($a)!==strlen($b))return false;$r=0;for($i=0;$i<strlen($a);$i++)$r|=ord($a[$i])^ord($b[$i]);return $r===0;}
function ni_canon($v){if(!is_array($v))return $v;$keys=array_keys($v);$list=$keys===range(0,count($v)-1);if($list){$o=array();foreach($v as $x)$o[]=ni_canon($x);return $o;}ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=ni_canon($x);return $v;}
function ni_path($p){$p=str_replace('\\','/',(string)$p);$p=ltrim($p,'/');if($p===''||strpos($p,"\0")!==false||preg_match('#^[A-Za-z]:/#',$p))return false;$out=array();foreach(explode('/',$p) as $x){if($x===''||$x==='.')continue;if($x==='..')return false;$out[]=$x;}return implode('/',$out);}
function ni_random_hex($bytes){if(function_exists('random_bytes'))return bin2hex(random_bytes($bytes));if(function_exists('openssl_random_pseudo_bytes')){$strong=false;$b=openssl_random_pseudo_bytes($bytes,$strong);if($b!==false&&$strong)return bin2hex($b);}ni_fail('На сервере нет криптостойкого генератора случайных чисел (random_bytes/OpenSSL).');}
function ni_https(){return (!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')||(isset($_SERVER['HTTP_X_FORWARDED_PROTO'])&&strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO'])==='https');}
function ni_get($url,$key,$max){if(!preg_match('#^https://#i',$url))ni_fail('Сервер обновлений должен использовать HTTPS.');if(function_exists('curl_init')){$c=curl_init($url);curl_setopt($c,CURLOPT_RETURNTRANSFER,true);curl_setopt($c,CURLOPT_FOLLOWLOCATION,false);curl_setopt($c,CURLOPT_CONNECTTIMEOUT,10);curl_setopt($c,CURLOPT_TIMEOUT,60);curl_setopt($c,CURLOPT_SSL_VERIFYPEER,true);curl_setopt($c,CURLOPT_SSL_VERIFYHOST,2);if(defined('CURLOPT_PROTOCOLS')&&defined('CURLPROTO_HTTPS'))curl_setopt($c,CURLOPT_PROTOCOLS,CURLPROTO_HTTPS);curl_setopt($c,CURLOPT_HTTPHEADER,array('X-UME-Update-Key: '.$key,'User-Agent: UME-Network-Installer'));$b=curl_exec($c);if($b===false){$e=curl_error($c);curl_close($c);ni_fail('Не удалось связаться с сервером обновлений: '.$e);}$s=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);}else{if(!ini_get('allow_url_fopen'))ni_fail('Нет cURL и отключён allow_url_fopen.');$ctx=stream_context_create(array('http'=>array('timeout'=>60,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,'header'=>"X-UME-Update-Key: ".$key."\r\nUser-Agent: UME-Network-Installer\r\n"),'ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false)));$b=@file_get_contents($url,false,$ctx);$s=0;if(isset($http_response_header))foreach($http_response_header as $h)if(preg_match('#^HTTP/\S+\s+(\d+)#',$h,$m))$s=(int)$m[1];if($b===false)ni_fail('Не удалось скачать данные с сервера обновлений.');}if($s<200||$s>=300)ni_fail('Сервер обновлений вернул HTTP '.$s.'.');if(strlen($b)>$max)ni_fail('Ответ сервера обновлений слишком большой.');return $b;}
if(version_compare(PHP_VERSION,'5.6.0','<'))ni_fail('Требуется PHP 5.6 или новее.');
$root=realpath(__DIR__);if(!$root)ni_fail('Не удалось определить корень сайта.');$target=$root.'/upgrade';
if(is_dir($target)&&is_file($target.'/config.local.php')){$gone=@unlink(__FILE__);ni_page('Upgrade Manager уже установлен','<h1>Upgrade Manager уже установлен</h1><p>На этом сайте уже работает Upgrade Manager. Обновляйте его в разделе /upgrade/ → «Обновление самого Upgrade Manager».</p><p>'.($gone?'Файл установщика удалён автоматически.':'<b>Удалите этот файл установщика вручную.</b>').'</p>',200);}
if(file_exists($target))ni_fail('Каталог /upgrade уже существует. Удалите или переименуйте его перед новой установкой.');
if(!is_writable($root))ni_fail('Корень сайта недоступен PHP для записи.');
// Installation starts only after an explicit POST from the confirmation page: search bots and link
// previews that merely open (GET) this URL no longer install anything.
if(!isset($_SERVER['REQUEST_METHOD'])||$_SERVER['REQUEST_METHOD']!=='POST'||!isset($_POST['ume_install'])){
    ni_page('Установка Upgrade Manager','<h1>Установка Upgrade Manager</h1><p>Будет установлен актуальный Upgrade Manager с центрального сервера обновлений в каталог <b>/upgrade/</b> этого сайта. Файлы сайта не изменяются.</p><p>После установки этот браузер получит одноразовый допуск к настройке на 30 минут — завершите её сразу.</p><form method="post"><input type="hidden" name="ume_install" value="1"><button type="submit">Установить</button></form><small>Файл установщика удалится автоматически после успешной установки.</small>',200);
}
@set_time_limit(120);
$raw=ni_get($feedUrl,$repoKey,1048576);$feed=json_decode($raw,true);
if(!is_array($feed)||!isset($feed['format'])||$feed['format']!=='ume-update-feed/1.0'||!isset($feed['product'])||$feed['product']!=='upgrade-manager'||empty($feed['latest'])||!is_array($feed['latest'])||empty($feed['signature']))ni_fail('Сервер обновлений вернул некорректный feed.');
$signed=array('format'=>$feed['format'],'product'=>$feed['product'],'latest'=>$feed['latest']);$sig=hash_hmac('sha256',json_encode(ni_canon($signed),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$repoKey);if(!ni_eq($sig,$feed['signature']))ni_fail('Подпись feed не совпала.');
$l=$feed['latest'];foreach(array('version','min_php','package_url','package_sha256') as $k)if(empty($l[$k]))ni_fail('В feed отсутствует '.$k.'.');
if(!preg_match('/^[a-f0-9]{64}$/i',(string)$l['package_sha256']))ni_fail('Некорректный SHA-256 пакета в feed.');
if(!version_compare(PHP_VERSION,(string)$l['min_php'],'>='))ni_fail('Для Upgrade Manager '.$l['version'].' требуется PHP '.$l['min_php'].' или новее.');
$pkg=ni_get((string)$l['package_url'],$repoKey,33554432);if(!ni_eq(hash('sha256',$pkg),strtolower((string)$l['package_sha256'])))ni_fail('SHA-256 пакета не совпал с feed.');
$bundle=json_decode($pkg,true);unset($pkg);
if(!is_array($bundle)||!isset($bundle['format'])||$bundle['format']!=='ume-self-update-bundle/1.0'||!isset($bundle['product'])||$bundle['product']!=='upgrade-manager'||empty($bundle['manifest']['files'])||!is_array($bundle['manifest']['files'])||empty($bundle['payload'])||!is_array($bundle['payload']))ni_fail('Пакет Upgrade Manager повреждён.');
if(!isset($bundle['manifest']['version'])||(string)$bundle['manifest']['version']!==(string)$l['version'])ni_fail('Версия пакета не совпадает с версией в feed.');
$seen=array();foreach($bundle['manifest']['files'] as $r){$p=ni_path(isset($r['path'])?$r['path']:'');if($p===false||isset($seen[$p]))ni_fail('Недопустимый или повторяющийся путь в пакете.');$seen[$p]=true;}
foreach(array('bootstrap.php','index.php','setup.php','release.json','lib/Util.php','lib/Manager.php','lib/SelfUpdater.php') as $req)if(!isset($seen[$req]))ni_fail('В пакете отсутствует обязательный файл: '.$req);
$ni_tmp=$root.'/.ume-net-'.ni_random_hex(8);if(!@mkdir($ni_tmp.'/upgrade',0755,true)){$ni_tmp='';ni_fail('Не удалось создать временный каталог установки.');}
foreach($bundle['manifest']['files'] as $r){$p=ni_path($r['path']);if($p==='config.local.php'||$p==='update-source.local.php'||$p==='setup-token.local.php'||$p==='setup-key.local.php'||strpos($p,'.data')===0||strpos($p,'.ume-')===0)ni_fail('Недопустимый путь в пакете: '.$p);if(!isset($bundle['payload'][$p])||!is_string($bundle['payload'][$p]))ni_fail('Файл отсутствует в пакете: '.$p);$data=base64_decode($bundle['payload'][$p],true);if($data===false||!isset($r['size'],$r['sha256'])||strlen($data)!==(int)$r['size']||!ni_eq(hash('sha256',$data),strtolower((string)$r['sha256'])))ni_fail('Проверка файла не пройдена: '.$p);$dst=$ni_tmp.'/upgrade/'.$p;$d=dirname($dst);if(!is_dir($d)&&!@mkdir($d,0755,true))ni_fail('Не удалось создать каталог: '.$p);$w=@file_put_contents($dst,$data,LOCK_EX);if($w===false||$w!==strlen($data))ni_fail('Не удалось полностью записать файл: '.$p);}
unset($bundle);
if(@file_put_contents($ni_tmp.'/upgrade/update-source.local.php',"<?php\nreturn ".var_export(array('url'=>$feedUrl,'key'=>$repoKey),true).";\n",LOCK_EX)===false)ni_fail('Не удалось сохранить источник обновлений.');
// One-time setup permit: only the hash is stored on the server, the token itself goes to this browser only.
$token=ni_random_hex(32);
if(@file_put_contents($ni_tmp.'/upgrade/setup-token.local.php',"<?php\nreturn ".var_export(array('token_sha256'=>hash('sha256',$token),'expires_at'=>time()+1800,'created_at'=>gmdate('c')),true).";\n",LOCK_EX)===false)ni_fail('Не удалось сохранить допуск к настройке.');
if(!@rename($ni_tmp.'/upgrade',$target))ni_fail('Не удалось установить каталог /upgrade.');
@rmdir($ni_tmp);$ni_tmp='';
$base=rtrim(str_replace('\\','/',dirname(isset($_SERVER['SCRIPT_NAME'])?$_SERVER['SCRIPT_NAME']:'/')),'/');if($base==='.')$base='';
header('Set-Cookie: UME_SETUP='.$token.'; Max-Age=1800; Path='.$base.'/upgrade/; HttpOnly; SameSite=Lax'.(ni_https()?'; Secure':''),false);
@unlink(__FILE__);
header('Location: '.$base.'/upgrade/setup.php',true,303);exit;
PHP;
        return str_replace(array('__FEED__','__KEY__','__VERSION__'),array(var_export($feed,true),var_export($key,true),preg_replace('/[^0-9A-Za-z._-]/','',$this->currentVersion())),$tpl);
    }

}
