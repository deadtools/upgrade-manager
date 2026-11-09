<?php
class UMEOAuth {
    private $config;
    private $manager;
    private $storage;
    private $dir;
    private $discoveryState=null;

    public function __construct($config,$manager){
        $this->config=$config;
        $this->manager=$manager;
        $this->storage=$manager->storage();
        $this->dir=$this->storage.'/oauth';
        UMEUtil::ensureDir($this->dir);
        foreach(array('clients.json','codes.json','tokens.json') as $file){
            $path=$this->dir.'/'.$file;
            if(!is_file($path))UMEUtil::jsonWriteAtomic($path,$file==='tokens.json'?array('access'=>array(),'refresh'=>array()):array());
        }
        if(!is_file($this->settingsFile()))UMEUtil::jsonWriteAtomic($this->settingsFile(),array(
            'enabled'=>true,
            'require_backup_before_install'=>true,
            'auto_rollback'=>true,
            'patch_upload_mode'=>'confirm',
            'patch_apply_mode'=>'confirm',
            'token_ttl_seconds'=>3600,
            'refresh_ttl_seconds'=>15552000,
            'upload_chunk_bytes'=>524288
        ));
        // RFC 9728 / RFC 8414 metadata for an issuer/resource that live below /upgrade
        // are discovered from the site-root /.well-known tree. Upgrade Manager creates
        // tiny forwarding shims there when the document root is writable.
        $this->discoveryState=$this->ensureDiscoveryShims();
    }

    private function settingsFile(){return $this->storage.'/api-settings.json';}
    private function file($name){return $this->dir.'/'.$name.'.json';}
    private function lockFile(){return $this->dir.'/oauth.lock';}
    private function hashEquals($a,$b){return function_exists('hash_equals')?hash_equals((string)$a,(string)$b):(string)$a===(string)$b;}
    private function base64Url($raw){return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');}
    private function origin(){return (UMEUtil::isHttps()?'https':'http').'://'.UMEUtil::domain();}
    private function normalizeFs($path){return rtrim(str_replace('\\','/',(string)$path),'/');}
    private function pluginDir(){
        $dir=realpath(dirname(__DIR__));
        return $dir?$this->normalizeFs($dir):$this->normalizeFs(dirname(__DIR__));
    }
    private function pluginRelative(){
        $root=$this->normalizeFs($this->manager->root());$dir=$this->pluginDir();
        if($root!==''&&($dir===$root||UMEUtil::startsWith($dir,$root.'/'))){
            $rel=ltrim(substr($dir,strlen($root)),'/');
            return trim($rel,'/');
        }
        return 'upgrade';
    }
    public function baseUrl(){
        $rel=$this->pluginRelative();
        return $this->origin().($rel!==''?'/'.$rel:'');
    }
    // Use a path-bearing issuer that ends in oauth.php. RFC 8414 therefore maps it
    // to /.well-known/oauth-authorization-server/<plugin>/oauth.php without relying
    // on DirectoryIndex or non-standard discovery under /upgrade/.well-known.
    public function issuer(){return $this->baseUrl().'/oauth.php';}
    public function resource(){return $this->baseUrl().'/mcp.php';}
    private function standardProtectedResourceUrl(){
        $path=ltrim((string)parse_url($this->resource(),PHP_URL_PATH),'/');
        return $this->origin().'/.well-known/oauth-protected-resource/'.$path;
    }
    public function authorizationMetadataUrl(){
        $path=ltrim((string)parse_url($this->issuer(),PHP_URL_PATH),'/');
        return $this->origin().'/.well-known/oauth-authorization-server/'.$path;
    }
    public function legacyMetadataUrl(){return $this->baseUrl().'/.well-known/oauth-protected-resource';}
    public function directMetadataUrl(){return $this->baseUrl().'/oauth.php?action=protected-resource';}
    public function metadataUrl(){
        $diag=$this->discoveryState!==null?$this->discoveryState:$this->diagnostics();
        return !empty($diag['protected_resource_shim'])?$this->standardProtectedResourceUrl():$this->directMetadataUrl();
    }
    private function toolScopes(){return array('site:read','tasks:read','tasks:write','backups:write','patches:write');}
    private function supportedScopes(){return array_merge($this->toolScopes(),array('offline_access'));}
    public function settings(){
        $defaults=array(
            'enabled'=>true,
            'require_backup_before_install'=>true,
            'auto_rollback'=>true,
            'patch_upload_mode'=>'confirm',
            'patch_apply_mode'=>'confirm',
            'token_ttl_seconds'=>3600,
            'refresh_ttl_seconds'=>15552000,
            'upload_chunk_bytes'=>524288
        );
        $s=array_merge($defaults,UMEUtil::jsonRead($this->settingsFile(),array()));
        if(!in_array($s['patch_upload_mode'],array('confirm','auto'),true))$s['patch_upload_mode']='confirm';
        if(!in_array($s['patch_apply_mode'],array('confirm','auto'),true))$s['patch_apply_mode']='confirm';
        $s['auto_rollback']=true;
        return $s;
    }
    public function saveSettings($input){
        $current=$this->settings();
        $current['enabled']=!empty($input['enabled']);
        $current['require_backup_before_install']=!empty($input['require_backup_before_install']);
        $current['auto_rollback']=true;
        $upload=isset($input['patch_upload_mode'])?(string)$input['patch_upload_mode']:$current['patch_upload_mode'];
        $apply=isset($input['patch_apply_mode'])?(string)$input['patch_apply_mode']:$current['patch_apply_mode'];
        $current['patch_upload_mode']=in_array($upload,array('confirm','auto'),true)?$upload:'confirm';
        $current['patch_apply_mode']=in_array($apply,array('confirm','auto'),true)?$apply:'confirm';
        $current['token_ttl_seconds']=max(900,min(86400,(int)(isset($input['token_ttl_seconds'])?$input['token_ttl_seconds']:$current['token_ttl_seconds'])));
        $current['refresh_ttl_seconds']=max(86400,min(31536000,(int)(isset($input['refresh_ttl_seconds'])?$input['refresh_ttl_seconds']:$current['refresh_ttl_seconds'])));
        $current['upload_chunk_bytes']=max(131072,min(1048576,(int)(isset($input['upload_chunk_bytes'])?$input['upload_chunk_bytes']:$current['upload_chunk_bytes'])));
        UMEUtil::jsonWriteAtomic($this->settingsFile(),$current);
        return $current;
    }
    public function enabled(){ $s=$this->settings();return !empty($s['enabled']); }
    public function assertEnabled(){if(!$this->enabled())throw new Exception('Прямой API-доступ отключён в Upgrade Manager.');}

    private function withLock($callback){
        $fh=@fopen($this->lockFile(),'c+');if(!$fh||!@flock($fh,LOCK_EX))throw new Exception('Не удалось заблокировать OAuth-хранилище.');
        try{return call_user_func($callback);}finally{@flock($fh,LOCK_UN);@fclose($fh);}
    }
    private function allowedRedirect($uri){
        $parts=@parse_url($uri);if(!is_array($parts)||empty($parts['scheme'])||empty($parts['host']))return false;
        $scheme=strtolower($parts['scheme']);$host=strtolower($parts['host']);
        if($scheme==='http'&&in_array($host,array('localhost','127.0.0.1','::1'),true))return true;
        if($scheme!=='https')return false;
        foreach(array('chatgpt.com','openai.com') as $suffix)if($host===$suffix||substr($host,-strlen('.'.$suffix))==='.'. $suffix)return true;
        return false;
    }
    private function normalizeScopes($scope){
        $allowed=$this->supportedScopes();
        if(is_array($scope))$requested=$scope;else $requested=preg_split('/\s+/',trim((string)$scope),-1,PREG_SPLIT_NO_EMPTY);
        if(!$requested)$requested=$this->toolScopes();
        $out=array();foreach($requested as $item)if(in_array($item,$allowed,true)&&!in_array($item,$out,true))$out[]=$item;
        if(!$out)throw new Exception('Не запрошено ни одного поддерживаемого OAuth scope.');return $out;
    }

    private function shimContent($action){
        $oauthFile=$this->pluginDir().'/oauth.php';
        return "<?php\n// Upgrade Manager OAuth discovery shim. Managed automatically; do not edit.\n".
            "\$_GET['action']='".$action."';\nrequire ".var_export($oauthFile,true).";\n";
    }
    private function writeManagedShim($path,$content){
        $dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0775,true))return false;
        if(is_file($path)){
            $existing=@file_get_contents($path);
            if($existing!==false&&strpos($existing,'Upgrade Manager OAuth discovery shim')===false)return false;
            if($existing===$content)return true;
        }
        return @file_put_contents($path,$content,LOCK_EX)!==false;
    }
    private function discoveryPaths(){
        $root=$this->normalizeFs($this->manager->root());
        $resourcePath=ltrim((string)parse_url($this->resource(),PHP_URL_PATH),'/');
        $issuerPath=ltrim((string)parse_url($this->issuer(),PHP_URL_PATH),'/');
        return array(
            'protected_resource_file'=>$root.'/.well-known/oauth-protected-resource/'.$resourcePath,
            'authorization_server_file'=>$root.'/.well-known/oauth-authorization-server/'.$issuerPath
        );
    }
    private function ensureDiscoveryShims(){
        $paths=$this->discoveryPaths();
        $pr=$this->writeManagedShim($paths['protected_resource_file'],$this->shimContent('protected-resource'));
        $as=$this->writeManagedShim($paths['authorization_server_file'],$this->shimContent('metadata'));
        return array(
            'protected_resource_shim'=>$pr&&is_file($paths['protected_resource_file']),
            'authorization_server_shim'=>$as&&is_file($paths['authorization_server_file']),
            'protected_resource_url'=>$this->standardProtectedResourceUrl(),
            'authorization_server_url'=>$this->authorizationMetadataUrl(),
            'protected_resource_file'=>$paths['protected_resource_file'],
            'authorization_server_file'=>$paths['authorization_server_file']
        );
    }
    public function diagnostics(){
        $paths=$this->discoveryPaths();
        $managed=function($path){$raw=is_file($path)?@file_get_contents($path):false;return $raw!==false&&strpos($raw,'Upgrade Manager OAuth discovery shim')!==false;};
        return array(
            'protected_resource_shim'=>$managed($paths['protected_resource_file']),
            'authorization_server_shim'=>$managed($paths['authorization_server_file']),
            'protected_resource_url'=>$this->standardProtectedResourceUrl(),
            'authorization_server_url'=>$this->authorizationMetadataUrl(),
            'direct_protected_resource_url'=>$this->directMetadataUrl(),
            'legacy_protected_resource_url'=>$this->legacyMetadataUrl(),
            'cimd_supported'=>true,
            'dcr_supported'=>true,
            'pkce_s256'=>true,
            'issuer_identification'=>true,
            'mcp_modern_2026_07_28'=>true,
            'ready'=>$managed($paths['protected_resource_file'])&&$managed($paths['authorization_server_file'])&&UMEUtil::isHttps()
        );
    }

    public function protectedResourceMetadata(){return array(
        'resource'=>$this->resource(),
        'authorization_servers'=>array($this->issuer()),
        'scopes_supported'=>$this->toolScopes(),
        'bearer_methods_supported'=>array('header'),
        'resource_documentation'=>$this->baseUrl().'/README_API_RU.md'
    );}
    public function authorizationServerMetadata(){return array(
        'issuer'=>$this->issuer(),
        'authorization_endpoint'=>$this->baseUrl().'/oauth.php?action=authorize',
        'token_endpoint'=>$this->baseUrl().'/oauth.php?action=token',
        'registration_endpoint'=>$this->baseUrl().'/oauth.php?action=register',
        'revocation_endpoint'=>$this->baseUrl().'/oauth.php?action=revoke',
        'response_types_supported'=>array('code'),
        'response_modes_supported'=>array('query'),
        'grant_types_supported'=>array('authorization_code','refresh_token'),
        'token_endpoint_auth_methods_supported'=>array('none'),
        'revocation_endpoint_auth_methods_supported'=>array('none'),
        'code_challenge_methods_supported'=>array('S256'),
        'scopes_supported'=>$this->supportedScopes(),
        'client_id_metadata_document_supported'=>true,
        'authorization_response_iss_parameter_supported'=>true,
        'service_documentation'=>$this->baseUrl().'/README_API_RU.md'
    );}

    public function registerClient($input){
        $this->assertEnabled();
        $redirects=isset($input['redirect_uris'])&&is_array($input['redirect_uris'])?$input['redirect_uris']:array();
        if(!$redirects||count($redirects)>10)throw new Exception('OAuth-клиент должен передать redirect_uris.');
        $clean=array();foreach($redirects as $uri){if(!is_string($uri)||!$this->allowedRedirect($uri))throw new Exception('Недопустимый OAuth redirect_uri.');if(!in_array($uri,$clean,true))$clean[]=$uri;}
        $authMethod=isset($input['token_endpoint_auth_method'])?(string)$input['token_endpoint_auth_method']:'none';if($authMethod!==''&&$authMethod!=='none')throw new Exception('Поддерживается только публичный OAuth-клиент token_endpoint_auth_method=none.');
        $clientId='ume_'.UMEUtil::randomHex(24);$name=isset($input['client_name'])?trim((string)$input['client_name']):'ChatGPT';if($name==='')$name='ChatGPT';$name=substr($name,0,120);
        $row=array('client_id'=>$clientId,'client_name'=>$name,'redirect_uris'=>$clean,'created_at'=>UMEUtil::now(),'issued_at'=>time(),'token_endpoint_auth_method'=>'none','registration'=>'dcr');
        $self=$this;$this->withLock(function()use($self,$clientId,$row){$rows=UMEUtil::jsonRead($self->file('clients'),array());$rows[$clientId]=$row;UMEUtil::jsonWriteAtomic($self->file('clients'),$rows);});
        return array('client_id'=>$clientId,'client_id_issued_at'=>$row['issued_at'],'client_name'=>$name,'redirect_uris'=>$clean,'token_endpoint_auth_method'=>'none','grant_types'=>array('authorization_code','refresh_token'),'response_types'=>array('code'));
    }
    private function isCimdClientId($clientId){
        $parts=@parse_url($clientId);if(!is_array($parts)||strtolower(isset($parts['scheme'])?$parts['scheme']:'')!=='https'||empty($parts['host']))return false;
        $host=strtolower($parts['host']);if(!in_array($host,array('chatgpt.com','www.chatgpt.com','openai.com','www.openai.com'),true))return false;
        $path=isset($parts['path'])?$parts['path']:'';
        return (bool)preg_match('#^/oauth/(?:client\.json|[A-Za-z0-9._-]{1,160}/client\.json)$#',$path);
    }
    private function fetchJsonHttps($url){
        $raw=false;
        if(function_exists('curl_init')){
            $ch=curl_init($url);curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_FOLLOWLOCATION,false);curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,4);curl_setopt($ch,CURLOPT_TIMEOUT,7);curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,true);curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,2);curl_setopt($ch,CURLOPT_USERAGENT,'UpgradeManager-OAuth/3.7.4');$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($code<200||$code>=300)$raw=false;
        }else if(ini_get('allow_url_fopen')){
            $ctx=stream_context_create(array('http'=>array('timeout'=>7,'ignore_errors'=>true,'header'=>"User-Agent: UpgradeManager-OAuth/3.7.4\r\nAccept: application/json\r\n"),'ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true)));
            $raw=@file_get_contents($url,false,$ctx);
        }
        if($raw===false||strlen($raw)>262144)return null;$data=json_decode($raw,true);return is_array($data)?$data:null;
    }
    private function fallbackCimdClient($clientId){
        $parts=parse_url($clientId);$path=isset($parts['path'])?$parts['path']:'';$redirects=array();
        if($path==='/oauth/client.json')$redirects[]= 'https://chatgpt.com/connector_platform_oauth_redirect';
        else if(preg_match('#^/oauth/([A-Za-z0-9._-]{1,160})/client\.json$#',$path,$m))$redirects[]='https://chatgpt.com/connector/oauth/'.$m[1];
        if(!$redirects)return null;
        return array('client_id'=>$clientId,'client_name'=>'ChatGPT','redirect_uris'=>$redirects,'token_endpoint_auth_method'=>'none','registration'=>'cimd-fallback');
    }
    private function cimdClient($clientId){
        if(!$this->isCimdClientId($clientId))return null;
        $meta=$this->fetchJsonHttps($clientId);$redirects=array();$name='ChatGPT';
        if(is_array($meta)){
            if(isset($meta['client_name'])&&is_string($meta['client_name'])&&trim($meta['client_name'])!=='')$name=substr(trim($meta['client_name']),0,120);
            if(isset($meta['redirect_uris'])&&is_array($meta['redirect_uris']))foreach($meta['redirect_uris'] as $uri)if(is_string($uri)&&$this->allowedRedirect($uri)&&!in_array($uri,$redirects,true))$redirects[]=$uri;
            $methods=array();if(isset($meta['token_endpoint_auth_methods_supported'])&&is_array($meta['token_endpoint_auth_methods_supported']))$methods=$meta['token_endpoint_auth_methods_supported'];else if(isset($meta['token_endpoint_auth_method']))$methods=array($meta['token_endpoint_auth_method']);
            if($methods&&!in_array('none',$methods,true))return null;
        }
        if(!$redirects)return $this->fallbackCimdClient($clientId);
        return array('client_id'=>$clientId,'client_name'=>$name,'redirect_uris'=>$redirects,'token_endpoint_auth_method'=>'none','registration'=>'cimd');
    }
    public function client($clientId){
        $clientId=(string)$clientId;$clients=UMEUtil::jsonRead($this->file('clients'),array());if(isset($clients[$clientId]))return $clients[$clientId];
        return $this->cimdClient($clientId);
    }
    public function validateAuthorizationRequest($p){
        $this->assertEnabled();
        if(!isset($p['response_type'])||$p['response_type']!=='code')throw new Exception('Поддерживается только response_type=code.');
        $clientId=isset($p['client_id'])?(string)$p['client_id']:'';$client=$this->client($clientId);if(!$client)throw new Exception('OAuth-клиент не зарегистрирован и не является поддерживаемым CIMD-клиентом.');
        $redirect=isset($p['redirect_uri'])?(string)$p['redirect_uri']:'';if(!in_array($redirect,$client['redirect_uris'],true))throw new Exception('redirect_uri не зарегистрирован для этого OAuth-клиента.');
        $challenge=isset($p['code_challenge'])?(string)$p['code_challenge']:'';$method=isset($p['code_challenge_method'])?(string)$p['code_challenge_method']:'';
        if($method!=='S256'||!preg_match('/^[A-Za-z0-9_-]{43,128}$/',$challenge))throw new Exception('Требуется PKCE S256.');
        $resource=isset($p['resource'])?(string)$p['resource']:$this->resource();if($resource!==$this->resource())throw new Exception('OAuth resource не совпадает с MCP-адресом.');
        return array('client'=>$client,'client_id'=>$clientId,'redirect_uri'=>$redirect,'code_challenge'=>$challenge,'scope'=>$this->normalizeScopes(isset($p['scope'])?$p['scope']:''),'state'=>isset($p['state'])?(string)$p['state']:'','resource'=>$resource);
    }
    public function issueAuthorizationCode($validated){
        $code='umec_'.UMEUtil::randomHex(36);$hash=hash('sha256',$code);$row=array('client_id'=>$validated['client_id'],'redirect_uri'=>$validated['redirect_uri'],'code_challenge'=>$validated['code_challenge'],'scope'=>$validated['scope'],'resource'=>$validated['resource'],'expires_at'=>time()+300,'created_at'=>UMEUtil::now());
        $self=$this;$this->withLock(function()use($self,$hash,$row){$rows=UMEUtil::jsonRead($self->file('codes'),array());foreach($rows as $key=>$item)if(empty($item['expires_at'])||(int)$item['expires_at']<time())unset($rows[$key]);$rows[$hash]=$row;UMEUtil::jsonWriteAtomic($self->file('codes'),$rows);});return $code;
    }
    private function issueTokens($clientId,$scopes,$resource,$existingRefreshHash){
        $settings=$this->settings();$access='umea_'.UMEUtil::randomHex(40);$refresh='umer_'.UMEUtil::randomHex(48);$accessHash=hash('sha256',$access);$refreshHash=hash('sha256',$refresh);$now=time();
        $accessRow=array('client_id'=>$clientId,'scope'=>$scopes,'resource'=>$resource,'created_at'=>UMEUtil::now(),'expires_at'=>$now+(int)$settings['token_ttl_seconds']);
        $refreshRow=array('client_id'=>$clientId,'scope'=>$scopes,'resource'=>$resource,'created_at'=>UMEUtil::now(),'expires_at'=>$now+(int)$settings['refresh_ttl_seconds']);
        $self=$this;$this->withLock(function()use($self,$accessHash,$accessRow,$refreshHash,$refreshRow,$existingRefreshHash){$rows=UMEUtil::jsonRead($self->file('tokens'),array('access'=>array(),'refresh'=>array()));if(!isset($rows['access']))$rows['access']=array();if(!isset($rows['refresh']))$rows['refresh']=array();foreach($rows['access'] as $key=>$row)if(empty($row['expires_at'])||(int)$row['expires_at']<time())unset($rows['access'][$key]);foreach($rows['refresh'] as $key=>$row)if(empty($row['expires_at'])||(int)$row['expires_at']<time())unset($rows['refresh'][$key]);if($existingRefreshHash)unset($rows['refresh'][$existingRefreshHash]);$rows['access'][$accessHash]=$accessRow;$rows['refresh'][$refreshHash]=$refreshRow;UMEUtil::jsonWriteAtomic($self->file('tokens'),$rows);});
        return array('access_token'=>$access,'token_type'=>'Bearer','expires_in'=>(int)$settings['token_ttl_seconds'],'refresh_token'=>$refresh,'scope'=>implode(' ',$scopes));
    }
    public function exchangeToken($input){
        $this->assertEnabled();$grant=isset($input['grant_type'])?(string)$input['grant_type']:'';$clientId=isset($input['client_id'])?(string)$input['client_id']:'';
        if(!$this->client($clientId))throw new Exception('OAuth-клиент не зарегистрирован и не распознан через CIMD.');
        if($grant==='authorization_code'){
            $code=isset($input['code'])?(string)$input['code']:'';$hash=hash('sha256',$code);$self=$this;$row=$this->withLock(function()use($self,$hash){$rows=UMEUtil::jsonRead($self->file('codes'),array());$row=isset($rows[$hash])?$rows[$hash]:null;unset($rows[$hash]);UMEUtil::jsonWriteAtomic($self->file('codes'),$rows);return $row;});
            if(!$row||empty($row['expires_at'])||(int)$row['expires_at']<time())throw new Exception('OAuth code недействителен или просрочен.');
            if($row['client_id']!==$clientId)throw new Exception('OAuth code выдан другому клиенту.');
            if(!isset($input['redirect_uri'])||$row['redirect_uri']!==(string)$input['redirect_uri'])throw new Exception('redirect_uri не совпадает.');
            $resource=isset($input['resource'])?(string)$input['resource']:$row['resource'];if($resource!==$row['resource']||$resource!==$this->resource())throw new Exception('OAuth resource не совпадает.');
            $verifier=isset($input['code_verifier'])?(string)$input['code_verifier']:'';if(!preg_match('/^[A-Za-z0-9._~-]{43,128}$/',$verifier))throw new Exception('Некорректный PKCE code_verifier.');$derived=$this->base64Url(hash('sha256',$verifier,true));if(!$this->hashEquals($row['code_challenge'],$derived))throw new Exception('PKCE-проверка не пройдена.');
            return $this->issueTokens($clientId,$row['scope'],$resource,null);
        }
        if($grant==='refresh_token'){
            $refresh=isset($input['refresh_token'])?(string)$input['refresh_token']:'';$hash=hash('sha256',$refresh);$rows=UMEUtil::jsonRead($this->file('tokens'),array('refresh'=>array()));$row=isset($rows['refresh'][$hash])?$rows['refresh'][$hash]:null;
            if(!$row||empty($row['expires_at'])||(int)$row['expires_at']<time()||$row['client_id']!==$clientId)throw new Exception('Refresh token недействителен.');
            $resource=isset($input['resource'])?(string)$input['resource']:(isset($row['resource'])?$row['resource']:$this->resource());if($resource!==$this->resource()||(!empty($row['resource'])&&$resource!==$row['resource']))throw new Exception('OAuth resource не совпадает.');
            return $this->issueTokens($clientId,$row['scope'],$resource,$hash);
        }
        throw new Exception('Неподдерживаемый grant_type.');
    }
    private function bearerToken(){
        $header='';if(isset($_SERVER['HTTP_AUTHORIZATION']))$header=$_SERVER['HTTP_AUTHORIZATION'];else if(function_exists('getallheaders')){$headers=getallheaders();foreach($headers as $name=>$value)if(strtolower($name)==='authorization'){$header=$value;break;}}
        return preg_match('/^Bearer\s+(.+)$/i',trim($header),$m)?trim($m[1]):'';
    }
    public function authenticate($requiredScopes){
        $this->assertEnabled();$token=$this->bearerToken();if($token==='')return null;$hash=hash('sha256',$token);$rows=UMEUtil::jsonRead($this->file('tokens'),array('access'=>array()));$row=isset($rows['access'][$hash])?$rows['access'][$hash]:null;
        if(!$row||empty($row['expires_at'])||(int)$row['expires_at']<time())return null;if(!empty($row['resource'])&&$row['resource']!==$this->resource())return null;$scopes=isset($row['scope'])&&is_array($row['scope'])?$row['scope']:array();foreach($requiredScopes as $scope)if(!in_array($scope,$scopes,true))return null;return $row;
    }
    public function revoke($token){
        $hash=hash('sha256',(string)$token);$self=$this;$this->withLock(function()use($self,$hash){$rows=UMEUtil::jsonRead($self->file('tokens'),array('access'=>array(),'refresh'=>array()));unset($rows['access'][$hash],$rows['refresh'][$hash]);UMEUtil::jsonWriteAtomic($self->file('tokens'),$rows);});
    }
    public function revokeAll(){UMEUtil::jsonWriteAtomic($this->file('tokens'),array('access'=>array(),'refresh'=>array()));return true;}
    public function status(){
        $clients=UMEUtil::jsonRead($this->file('clients'),array());$tokens=UMEUtil::jsonRead($this->file('tokens'),array('access'=>array(),'refresh'=>array()));$active=0;$connected=array();foreach(isset($tokens['access'])?$tokens['access']:array() as $row)if(!empty($row['expires_at'])&&(int)$row['expires_at']>=time()){$active++;if(!empty($row['client_id']))$connected[(string)$row['client_id']]=true;}
        return array('enabled'=>$this->enabled(),'https'=>UMEUtil::isHttps(),'mcp_url'=>$this->resource(),'oauth_issuer'=>$this->issuer(),'protected_resource_metadata'=>$this->metadataUrl(),'authorization_server_metadata'=>$this->authorizationMetadataUrl(),'registered_clients'=>count($clients),'connected_clients'=>count($connected),'active_access_tokens'=>$active,'settings'=>$this->settings(),'discovery'=>$this->diagnostics());
    }
}
