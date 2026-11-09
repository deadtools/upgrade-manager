<?php
class UMEApiTasks {
    private $config;
    private $manager;
    private $oauth;
    private $storage;
    private $tasksDir;
    private $uploadsDir;
    private $draftsDir;
    private $authorizationsFile;

    public function __construct($config,$manager,$oauth){
        $this->config=$config;$this->manager=$manager;$this->oauth=$oauth;$this->storage=$manager->storage();
        $this->tasksDir=$this->storage.'/api-tasks';$this->uploadsDir=$this->storage.'/api-uploads';$this->draftsDir=$this->storage.'/api-drafts';$this->authorizationsFile=$this->storage.'/api-authorizations.json';
        UMEUtil::ensureDir($this->tasksDir);UMEUtil::ensureDir($this->uploadsDir);UMEUtil::ensureDir($this->draftsDir);if(!is_file($this->authorizationsFile))UMEUtil::jsonWriteAtomic($this->authorizationsFile,array());
    }
    private function limitText($value,$max){$value=trim((string)$value);return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max);}
    private function taskFile($id){if(!UMEUtil::safeId($id))throw new Exception('Некорректный task_id.');return $this->tasksDir.'/'.$id.'.json';}
    private function saveTask($task){$task['updated_at']=UMEUtil::now();UMEUtil::jsonWriteAtomic($this->taskFile($task['id']),$task);return $task;}
    private function addEventToTask($task,$message,$level,$data){
        if(!isset($task['events'])||!is_array($task['events']))$task['events']=array();
        $task['events'][]=array('at'=>UMEUtil::now(),'level'=>$level,'message'=>$this->limitText($message,1000),'data'=>is_array($data)?$data:array());
        if(count($task['events'])>400)$task['events']=array_slice($task['events'],-400);return $task;
    }
    public function getTask($id){$task=UMEUtil::jsonRead($this->taskFile($id),array());if(!$task)throw new Exception('API-задача не найдена.');return $task;}
    public function listTasks($limit){
        $limit=max(1,min(100,(int)$limit));$rows=array();foreach(glob($this->tasksDir.'/*.json') as $file){$task=UMEUtil::jsonRead($file,array());if($task){$changed=false;foreach(isset($task['jobs'])?$task['jobs']:array() as $known){if(empty($known['id']))continue;$actual=$this->manager->getJob($known['id']);if($actual&&(!isset($known['status'])||$known['status']!==$actual['status']||!isset($known['phase'])||$known['phase']!==$actual['phase'])){$task=$this->syncJob($task,$this->manager->publicJob($actual));$changed=true;}}if($changed)$task=$this->saveTask($task);$rows[]=$task;}}
        usort($rows,function($a,$b){return strcmp(isset($b['created_at'])?$b['created_at']:'',isset($a['created_at'])?$a['created_at']:'');});return array_slice($rows,0,$limit);
    }
    public function createTask($title,$specification,$criteria){
        $title=$this->limitText($title,180);$specification=$this->limitText($specification,12000);if($title===''||$specification==='')throw new Exception('Укажите название и полное ТЗ API-задачи.');
        $cleanCriteria=array();if(is_array($criteria))foreach($criteria as $item){$item=$this->limitText($item,800);if($item!==''&&count($cleanCriteria)<50)$cleanCriteria[]=$item;}
        $id='api-'.date('Ymd-His').'-'.substr(UMEUtil::randomHex(5),0,10);$task=array('id'=>$id,'source'=>'mcp','title'=>$title,'specification'=>$specification,'acceptance_criteria'=>$cleanCriteria,'status'=>'planned','created_at'=>UMEUtil::now(),'updated_at'=>UMEUtil::now(),'jobs'=>array(),'patch'=>null,'result'=>null,'events'=>array());
        $task=$this->addEventToTask($task,'Получено и зарегистрировано техническое задание.','INFO',array('criteria_count'=>count($cleanCriteria)));return $this->saveTask($task);
    }
    public function appendLog($taskId,$message,$level,$data){
        $task=$this->getTask($taskId);$allowed=array('INFO','WARN','ERROR','CHECK');$level=strtoupper((string)$level);if(!in_array($level,$allowed,true))$level='INFO';$message=$this->limitText($message,1000);if($message==='')throw new Exception('Сообщение журнала пусто.');$task=$this->addEventToTask($task,$message,$level,$data);if($task['status']==='planned')$task['status']='in_progress';return $this->saveTask($task);
    }
    private function attachJob($task,$job,$purpose){
        if(!isset($task['jobs'])||!is_array($task['jobs']))$task['jobs']=array();$task['jobs'][]=array('id'=>$job['id'],'type'=>$job['type'],'purpose'=>$purpose,'status'=>$job['status'],'created_at'=>$job['created_at'],'updated_at'=>$job['updated_at']);$task['status']='in_progress';return $task;
    }
    private function syncJob($task,$job){
        if(!isset($task['jobs'])||!is_array($task['jobs']))$task['jobs']=array();$found=false;$previousStatus=null;
        foreach($task['jobs'] as &$row)if(isset($row['id'])&&$row['id']===$job['id']){$previousStatus=isset($row['status'])?$row['status']:null;$row['status']=$job['status'];$row['phase']=$job['phase'];$row['message']=$job['message'];$row['updated_at']=$job['updated_at'];$found=true;break;}unset($row);
        if(!$found)$task=$this->attachJob($task,$job,$job['type']);
        if($job['status']==='failed'){$task['status']='failed';if($previousStatus!=='failed')$task=$this->addEventToTask($task,'Процесс завершился ошибкой: '.$job['message'],'ERROR',array('job_id'=>$job['id'],'job_type'=>$job['type']));}
        else if($job['status']==='cancelled'){$task['status']='cancelled';if($previousStatus!=='cancelled')$task=$this->addEventToTask($task,'Процесс отменён: '.$job['message'],'WARN',array('job_id'=>$job['id'],'job_type'=>$job['type']));}
        else if($job['status']==='completed'){
            if($job['type']==='install')$task['status']='deployed';else if($job['type']==='rollback')$task['status']='rolled_back';
            if($previousStatus!=='completed')$task=$this->addEventToTask($task,$job['message'],'CHECK',array('job_id'=>$job['id'],'job_type'=>$job['type']));
        }
        return $task;
    }
    public function startBackup($taskId){$task=$this->getTask($taskId);$job=$this->manager->publicJob($this->manager->startBackup());$task=$this->attachJob($task,$job,'Резервная копия перед изменениями');$task=$this->addEventToTask($task,'Запущено создание резервной копии перед изменениями.','INFO',array('job_id'=>$job['id']));$this->saveTask($task);return $job;}
    public function createStandaloneBackup(){
        $task=$this->createTask('Резервная копия по запросу ChatGPT','Создать резервную копию сайта штатным механизмом Upgrade Manager с текущими сохранёнными настройками файлов и базы данных.',array('Резервная копия должна завершиться со статусом completed.'));
        $job=$this->startBackup($task['id']);
        return array('task_id'=>$task['id'],'job'=>$job,'next_action'=>'Вызывайте advance_job с task_id и job.id до терминального статуса.');
    }
    public function listBackups($limit){
        $limit=max(1,min(100,(int)$limit));$dash=$this->manager->dashboard();$rows=isset($dash['backups'])&&is_array($dash['backups'])?$dash['backups']:array();$out=array();
        foreach(array_slice($rows,0,$limit) as $row){
            $item=array('id'=>isset($row['id'])?(string)$row['id']:'','size'=>isset($row['size'])?(int)$row['size']:0,'created_at'=>isset($row['mtime'])?gmdate('c',(int)$row['mtime']):null,'public_available'=>!empty($row['public_available']));
            foreach(array('file_mode','database_mode','database_included','format','site_version','next_patch_version') as $key)if(array_key_exists($key,$row))$item[$key]=$row[$key];
            if($item['public_available'])$item['public_url']=$this->oauth->baseUrl().'/public-backup.php?id='.rawurlencode($item['id']);
            $out[]=$item;
        }
        return $out;
    }
    public function backupShareUrls($backupId){
        $backupId=trim((string)$backupId);$this->manager->publicBackupDownload($backupId);$base=$this->oauth->baseUrl().'/public-backup.php?id='.rawurlencode($backupId);
        return array('backup_id'=>$backupId,'public_url'=>$base,'download_url'=>$base.'&download=1','note'=>'Публичная копия очищена от секретов/runtime-файлов; БД публикуется только как структура без данных.');
    }
    public function advanceJob($taskId,$jobId){
        $task=$this->getTask($taskId);$known=false;foreach(isset($task['jobs'])?$task['jobs']:array() as $row)if(isset($row['id'])&&$row['id']===$jobId){$known=true;break;}if(!$known)throw new Exception('Процесс не относится к указанной API-задаче.');
        $current=$this->manager->getJob($jobId);if(!$current)throw new Exception('Процесс не найден.');if(isset($current['status'])&&in_array($current['status'],array('completed','failed','cancelled'),true))$job=$this->manager->publicJob($current);else{if(isset($current['status'])&&$current['status']==='stale')$this->manager->resume($jobId);$job=$this->manager->step($jobId);}$task=$this->syncJob($task,$job);$this->saveTask($task);return $job;
    }
    public function cancelJob($taskId,$jobId){
        $task=$this->getTask($taskId);$known=false;foreach(isset($task['jobs'])?$task['jobs']:array() as $row)if(isset($row['id'])&&$row['id']===$jobId){$known=true;break;}if(!$known)throw new Exception('Процесс не относится к указанной API-задаче.');$job=$this->manager->cancel($jobId);$task=$this->syncJob($task,$job);$task=$this->addEventToTask($task,'Запрошена отмена процесса. Если изменения начались, будет выполнен откат.','WARN',array('job_id'=>$jobId));$this->saveTask($task);return $job;
    }
    private function completedBackupExists($task){foreach(isset($task['jobs'])?$task['jobs']:array() as $row)if(isset($row['type'],$row['status'])&&$row['type']==='backup'&&$row['status']==='completed')return true;return false;}
    private function forbiddenRead($path){
        $path=trim(str_replace('\\','/',$path),'/');$forbidden=isset($this->config['protected_read_paths'])&&is_array($this->config['protected_read_paths'])?$this->config['protected_read_paths']:array('upgrade/config.local.php','.env','.git','vendor');
        $root=str_replace('\\','/',$this->manager->root());$storage=str_replace('\\','/',$this->manager->storage());if($storage!==$root&&UMEUtil::startsWith($storage,$root.'/'))$forbidden[]=substr($storage,strlen($root)+1);
        foreach($forbidden as $prefix){$prefix=trim(str_replace('\\','/',$prefix),'/');if($prefix!==''&&($path===$prefix||UMEUtil::startsWith($path,$prefix.'/')))return true;}$base=strtolower(basename($path));return in_array($base,array('.env','id_rsa','id_ed25519','credentials.json','secrets.json','auth.json'),true);
    }
    private function normalizePath($path,$allowRoot){$path=trim(str_replace('\\','/',trim((string)$path)),'/');if($allowRoot&&$path==='')return '';$path=UMEUtil::normalizeRelativePath($path);if($path===false||$this->forbiddenRead($path))throw new Exception('Доступ к пути запрещён: '.(string)$path);return $path;}
    private function codeFileAllowed($rel){
        if($this->forbiddenRead($rel))return false;$base=basename($rel);if(isset($this->config['code_filenames'])&&in_array($base,$this->config['code_filenames'],true))return true;$ext=strtolower(pathinfo($base,PATHINFO_EXTENSION));return isset($this->config['code_extensions'])&&in_array($ext,$this->config['code_extensions'],true);
    }
    public function listFiles($path,$recursive,$limit){
        $rel=$this->normalizePath($path,true);$base=$this->manager->root().($rel===''?'':'/'.$rel);if(!is_dir($base))throw new Exception('Каталог не найден.');$limit=max(1,min(1000,(int)$limit));$out=array();$root=$this->manager->root();
        if($recursive){$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);foreach($iterator as $file){if(count($out)>=$limit)break;if(!$file->isFile()||$file->isLink())continue;$item=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));if(!$this->codeFileAllowed($item))continue;$out[]=array('path'=>$item,'size'=>$file->getSize(),'mtime'=>$file->getMTime());}}
        else{$items=@scandir($base);if(!is_array($items))throw new Exception('Не удалось прочитать каталог.');foreach($items as $name){if($name==='.'||$name==='..'||count($out)>=$limit)continue;$abs=$base.'/'.$name;$item=$rel===''?$name:$rel.'/'.$name;if($this->forbiddenRead($item)||is_link($abs))continue;if(is_dir($abs))$out[]=array('path'=>$item,'type'=>'directory');else if(is_file($abs)&&$this->codeFileAllowed($item))$out[]=array('path'=>$item,'type'=>'file','size'=>filesize($abs),'mtime'=>filemtime($abs));}}
        return array('path'=>$rel,'items'=>$out,'limit'=>$limit,'truncated'=>count($out)>=$limit);
    }
    public function readFiles($paths){
        if(!is_array($paths)||!$paths||count($paths)>20)throw new Exception('Передайте от 1 до 20 путей.');$out=array();$total=0;
        foreach($paths as $value){$rel=$this->normalizePath($value,false);if(!$this->codeFileAllowed($rel))throw new Exception('Тип файла не разрешён для чтения: '.$rel);$abs=$this->manager->root().'/'.$rel;if(!is_file($abs)||is_link($abs))throw new Exception('Файл не найден: '.$rel);$size=filesize($abs);if($size>1572864)throw new Exception('Файл слишком большой для API-чтения: '.$rel);$total+=$size;if($total>4194304)throw new Exception('Суммарный объём чтения превышает 4 МБ.');$content=@file_get_contents($abs);if($content===false||strpos($content,"\0")!==false)throw new Exception('Файл не является безопасным текстовым файлом: '.$rel);$out[]=array('path'=>$rel,'size'=>$size,'sha256'=>hash('sha256',$content),'content'=>$content);}
        return array('files'=>$out,'total_bytes'=>$total);
    }
    public function searchCode($query,$path,$caseSensitive,$maxResults){
        $query=$this->limitText($query,200);if($query==='')throw new Exception('Строка поиска пуста.');$rel=$this->normalizePath($path,true);$base=$this->manager->root().($rel===''?'':'/'.$rel);if(!is_dir($base))throw new Exception('Каталог поиска не найден.');$maxResults=max(1,min(100,(int)$maxResults));$results=array();$scanned=0;$root=$this->manager->root();
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);foreach($iterator as $file){if(count($results)>=$maxResults||$scanned>=5000)break;if(!$file->isFile()||$file->isLink())continue;$item=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));if(!$this->codeFileAllowed($item)||$file->getSize()>1048576)continue;$scanned++;$lines=@file($file->getPathname(),FILE_IGNORE_NEW_LINES);if(!is_array($lines))continue;foreach($lines as $number=>$line){$found=$caseSensitive?strpos($line,$query):stripos($line,$query);if($found!==false){$results[]=array('path'=>$item,'line'=>$number+1,'text'=>$this->limitText($line,500));if(count($results)>=$maxResults)break;}}}
        return array('query'=>$query,'results'=>$results,'files_scanned'=>$scanned,'truncated'=>count($results)>=$maxResults||$scanned>=5000);
    }
    public function siteStatus(){
        $dash=$this->manager->dashboard();$api=$this->oauth->status();return array('site_id'=>$this->config['site_id'],'project'=>$this->config['project'],'domain'=>UMEUtil::domain(),'upgrade_manager_version'=>$this->config['version'],'site_version'=>isset($dash['site_version']['version'])?$dash['site_version']['version']:'','next_patch_version'=>isset($dash['site_version']['next_version'])?$dash['site_version']['next_version']:'','php_version'=>PHP_VERSION,'api'=>$api,'active_job'=>$dash['active_job'],'patch_count'=>count($dash['history']),'backup_count'=>count($dash['backups']));
    }
    private function validHash($value){return is_string($value)&&preg_match('/^[a-f0-9]{64}$/',$value);}
    public function authorizePatch($taskId,$manifest){
        $task=$this->getTask($taskId);if(!is_array($manifest))throw new Exception('Манифест патча не передан.');
        foreach(array('format','patch_id','name','version','project','domain','site_id','files','delete','migrations') as $key)if(!array_key_exists($key,$manifest))throw new Exception('В манифесте отсутствует '.$key.'.');
        if(!in_array($manifest['format'],array('external-upgrade-patch/1.1','external-upgrade-patch/1.2','external-upgrade-patch/1.3'),true))throw new Exception('API подписывает только external-upgrade-patch/1.1, 1.2 или 1.3.');if(!UMEUtil::safeId($manifest['patch_id']))throw new Exception('Некорректный patch_id.');if(in_array($manifest['format'],array('external-upgrade-patch/1.2','external-upgrade-patch/1.3'),true)&&!array_key_exists('base_site_version',$manifest))throw new Exception('В manifest 1.2/1.3 отсутствует base_site_version.');
        if($manifest['project']!==$this->config['project']||$manifest['site_id']!==$this->config['site_id'])throw new Exception('Профиль патча не совпадает с сайтом.');if(!in_array(strtolower($manifest['domain']),$this->config['allowed_domains'],true))throw new Exception('Домен патча не разрешён.');$this->manager->assertPatchVersionContext($manifest);
        if(!is_array($manifest['files'])||!is_array($manifest['delete'])||!is_array($manifest['migrations']))throw new Exception('files, delete и migrations должны быть массивами.');if(count($manifest['files'])>$this->config['limits']['max_patch_files'])throw new Exception('Слишком много файлов.');
        $payload=in_array($manifest['format'],array('external-upgrade-patch/1.2','external-upgrade-patch/1.3'),true)?'BASE_SITE_VERSION:'.$manifest['base_site_version']."\n".'TARGET_SITE_VERSION:'.$manifest['version']."\n":'';$targets=array();$sources=array();foreach($manifest['files'] as $file){if(!is_array($file)||empty($file['source'])||empty($file['target'])||!isset($file['sha256']))throw new Exception('Некорректная запись files.');$source=UMEUtil::normalizeRelativePath($file['source']);$target=UMEUtil::normalizeRelativePath($file['target']);if($source===false||$target===false||!$this->validHash($file['sha256']))throw new Exception('Некорректный путь или SHA-256 файла.');if($this->manager->forbiddenTarget($target))throw new Exception('Изменение защищённого пути запрещено: '.$target);if(isset($targets[$target])||isset($sources[$source]))throw new Exception('Дублирующийся путь в files.');$targets[$target]=1;$sources[$source]=1;$payload.=$target.':'.$file['sha256']."\n";}
        foreach($manifest['delete'] as $path){$path=UMEUtil::normalizeRelativePath($path);if($path===false||$this->manager->forbiddenTarget($path))throw new Exception('Недопустимый delete path.');$payload.='DELETE:'.$path."\n";}
        if($manifest['migrations']&&$manifest['format']!=='external-upgrade-patch/1.3')throw new Exception('SQL-миграции требуют формат патча 1.3: в старых форматах rollback-таблицы не были защищены подписью.');
        $migrationIds=array();foreach($manifest['migrations'] as $migration){
            if(!is_array($migration)||empty($migration['id'])||empty($migration['source'])||!isset($migration['sha256'])||!isset($migration['tables'])||!is_array($migration['tables'])||!$migration['tables']||!$this->validHash($migration['sha256'])||!UMEUtil::safeId($migration['id'])||isset($migrationIds[$migration['id']]))throw new Exception('Некорректная миграция.');
            $source=UMEUtil::normalizeRelativePath($migration['source']);if($source===false)throw new Exception('Недопустимый путь SQL-миграции.');
            $tableSeen=array();foreach($migration['tables'] as $table){if(!is_string($table)||trim($table)!==$table||!preg_match('/^(?:\{\{DB_PREFIX\}\})?[A-Za-z0-9_$.\-]{1,128}$/',$table)||isset($tableSeen[$table]))throw new Exception('Некорректное имя таблицы SQL-миграции.');$tableSeen[$table]=true;}
            $migrationIds[$migration['id']]=true;$payload.='SQL:'.$migration['id'].':'.$migration['sha256']."\n";
            $payload.='TABLES:'.$migration['id'].':'.hash('sha256',UMEUtil::canonicalJson($migration['tables']))."\n";
        }
        $actions=isset($manifest['actions'])&&is_array($manifest['actions'])?$manifest['actions']:array();$allowed=isset($this->config['allowed_actions'])&&is_array($this->config['allowed_actions'])?$this->config['allowed_actions']:array('clear_cache');foreach($actions as $key=>$value)if(!in_array($key,$allowed,true))throw new Exception('Неподдерживаемое действие патча: '.$key);$manifest['actions']=$actions;$payload.='ACTIONS:'.hash('sha256',UMEUtil::canonicalJson($actions))."\n";
        $manifest['payload_hash']=hash('sha256',$payload);$manifest['signature']=hash_hmac('sha256',$manifest['patch_id'].'|'.$manifest['site_id'].'|'.$manifest['domain'].'|'.$manifest['payload_hash'],$this->config['hmac_key']);$manifest['authorized_task_id']=$taskId;$manifest['authorized_at']=UMEUtil::now();
        $rows=UMEUtil::jsonRead($this->authorizationsFile,array());$rows[$manifest['patch_id']]=array('task_id'=>$taskId,'payload_hash'=>$manifest['payload_hash'],'signature'=>$manifest['signature'],'expires_at'=>time()+3600,'created_at'=>UMEUtil::now());UMEUtil::jsonWriteAtomic($this->authorizationsFile,$rows);
        $task['patch']=array('patch_id'=>$manifest['patch_id'],'name'=>$this->limitText($manifest['name'],180),'version'=>$this->limitText($manifest['version'],80),'status'=>'authorized','payload_hash'=>$manifest['payload_hash']);$task=$this->addEventToTask($task,'Манифест патча проверен и получил одноразовую подпись.','CHECK',array('patch_id'=>$manifest['patch_id'],'files'=>count($manifest['files']),'deletions'=>count($manifest['delete']),'migrations'=>count($manifest['migrations'])));$this->saveTask($task);return $manifest;
    }
    private function uploadMetaFile($id){if(!UMEUtil::safeId($id))throw new Exception('Некорректный upload_id.');return $this->uploadsDir.'/'.$id.'.json';}
    public function beginUpload($taskId,$filename,$size,$sha256,$chunksTotal){
        $this->getTask($taskId);$filename=basename((string)$filename);$size=(int)$size;$chunksTotal=(int)$chunksTotal;if(strtolower(pathinfo($filename,PATHINFO_EXTENSION))!=='zip'||$size<1||$size>$this->config['limits']['max_patch_bytes']||!$this->validHash($sha256)||$chunksTotal<1||$chunksTotal>2000)throw new Exception('Некорректные параметры загрузки ZIP.');
        $id='upload-'.date('Ymd-His').'-'.substr(UMEUtil::randomHex(5),0,10);$part=$this->uploadsDir.'/'.$id.'.part';if(@file_put_contents($part,'',LOCK_EX)===false)throw new Exception('Не удалось создать временный файл загрузки.');$meta=array('id'=>$id,'task_id'=>$taskId,'filename'=>$filename,'expected_size'=>$size,'expected_sha256'=>$sha256,'chunks_total'=>$chunksTotal,'next_index'=>0,'received_bytes'=>0,'created_at'=>UMEUtil::now(),'part_file'=>$part);UMEUtil::jsonWriteAtomic($this->uploadMetaFile($id),$meta);$this->appendLog($taskId,'Начата защищённая загрузка ZIP-патча.','INFO',array('upload_id'=>$id,'bytes'=>$size,'chunks'=>$chunksTotal));return array('upload_id'=>$id,'next_index'=>0,'chunk_bytes'=>$this->oauth->settings()['upload_chunk_bytes']);
    }
    public function uploadChunk($taskId,$uploadId,$index,$base64,$chunkSha256){
        $meta=UMEUtil::jsonRead($this->uploadMetaFile($uploadId),array());if(!$meta||$meta['task_id']!==$taskId)throw new Exception('Загрузка не найдена для этой задачи.');$index=(int)$index;if($index!==(int)$meta['next_index'])throw new Exception('Ожидался chunk_index '.(int)$meta['next_index'].'.');$raw=base64_decode((string)$base64,true);if($raw===false)throw new Exception('Чанк не является корректным base64.');$max=(int)$this->oauth->settings()['upload_chunk_bytes'];if(strlen($raw)<1||strlen($raw)>$max)throw new Exception('Размер чанка выходит за допустимый предел.');if($chunkSha256!==''&&(!$this->validHash($chunkSha256)||!hash_equals($chunkSha256,hash('sha256',$raw))))throw new Exception('SHA-256 чанка не совпал.');if((int)$meta['received_bytes']+strlen($raw)>(int)$meta['expected_size'])throw new Exception('Получено больше данных, чем заявлено.');if(@file_put_contents($meta['part_file'],$raw,FILE_APPEND|LOCK_EX)===false)throw new Exception('Не удалось записать чанк.');$meta['next_index']=$index+1;$meta['received_bytes']=(int)$meta['received_bytes']+strlen($raw);UMEUtil::jsonWriteAtomic($this->uploadMetaFile($uploadId),$meta);return array('upload_id'=>$uploadId,'next_index'=>$meta['next_index'],'received_bytes'=>$meta['received_bytes'],'complete'=>$meta['next_index']===(int)$meta['chunks_total']);
    }
    public function finishUpload($taskId,$uploadId){
        $meta=UMEUtil::jsonRead($this->uploadMetaFile($uploadId),array());if(!$meta||$meta['task_id']!==$taskId)throw new Exception('Загрузка не найдена для этой задачи.');if((int)$meta['next_index']!==(int)$meta['chunks_total']||(int)$meta['received_bytes']!==(int)$meta['expected_size'])throw new Exception('Получены не все чанки патча.');if(!is_file($meta['part_file'])||!hash_equals($meta['expected_sha256'],hash_file('sha256',$meta['part_file'])))throw new Exception('SHA-256 итогового ZIP не совпал.');
        $zip=new ZipArchive();if($zip->open($meta['part_file'])!==true)throw new Exception('ZIP-патч повреждён.');$raw=$zip->getFromName('manifest.json');$zip->close();$manifest=json_decode($raw,true);if(!is_array($manifest)||empty($manifest['patch_id']))throw new Exception('В ZIP отсутствует корректный manifest.json.');$authorizations=UMEUtil::jsonRead($this->authorizationsFile,array());$authorization=isset($authorizations[$manifest['patch_id']])?$authorizations[$manifest['patch_id']]:null;if(!$authorization||$authorization['task_id']!==$taskId||(int)$authorization['expires_at']<time()||$authorization['payload_hash']!==$manifest['payload_hash']||$authorization['signature']!==$manifest['signature'])throw new Exception('Для этого ZIP нет действующей одноразовой авторизации.');
        $report=$this->manager->registerApiPatch($meta['part_file'],$meta['filename']);@unlink($this->uploadMetaFile($uploadId));unset($authorizations[$manifest['patch_id']]);UMEUtil::jsonWriteAtomic($this->authorizationsFile,$authorizations);$task=$this->getTask($taskId);$task['patch']['status']='validated';$task['patch']['upload_id']=$report['upload_id'];$task=$this->addEventToTask($task,'ZIP-патч полностью загружен и прошёл серверную проверку.','CHECK',array('patch_id'=>$manifest['patch_id'],'upload_id'=>$report['upload_id'],'bytes'=>$report['uncompressed_bytes']));$this->saveTask($task);return $report;
    }
    private function draftMetaFile($draftId){
        if(!UMEUtil::safeId($draftId))throw new Exception('Некорректный draft_id.');
        return $this->draftsDir.'/'.$draftId.'/draft.json';
    }
    private function readDraft($taskId,$draftId,$requireDraft=true){
        $meta=UMEUtil::jsonRead($this->draftMetaFile($draftId),array());
        if(!$meta||!isset($meta['task_id'])||$meta['task_id']!==$taskId)throw new Exception('Черновик патча не найден для этой API-задачи.');
        if($requireDraft&&isset($meta['status'])&&$meta['status']!=='draft')throw new Exception('Черновик патча уже финализирован.');
        return $meta;
    }
    private function saveDraft($draft){
        $draft['updated_at']=UMEUtil::now();
        UMEUtil::jsonWriteAtomic($this->draftMetaFile($draft['id']),$draft);
        return $draft;
    }
    private function draftPublic($draft){
        return array(
            'draft_id'=>$draft['id'],
            'patch_id'=>$draft['patch_id'],
            'status'=>$draft['status'],
            'name'=>$draft['name'],
            'version'=>$draft['version'],
            'files_count'=>count(isset($draft['files'])?$draft['files']:array()),
            'migrations_count'=>count(isset($draft['migrations'])?$draft['migrations']:array()),
            'delete_count'=>count(isset($draft['delete'])?$draft['delete']:array()),
            'total_bytes'=>(int)(isset($draft['total_bytes'])?$draft['total_bytes']:0),
            'updated_at'=>isset($draft['updated_at'])?$draft['updated_at']:null
        );
    }
    private function validateDraftTarget($target){
        $target=UMEUtil::normalizeRelativePath(trim(str_replace('\\','/',(string)$target),'/'));
        if($target===false||$target===''||$this->manager->forbiddenTarget($target))throw new Exception('Изменение защищённого или некорректного пути запрещено: '.(string)$target);
        return $target;
    }
    public function beginPatchDraft($taskId,$name,$version,$delete,$actions,$phpMin){
        $task=$this->getTask($taskId);$name=$this->limitText($name,180);$version=$this->limitText($version,80);$phpMin=trim((string)$phpMin);$site=$this->manager->siteVersion();$expectedVersion=$site['next_version'];
        if($name==='')throw new Exception('Для патча нужно название.');if($version!==''&&trim((string)$version)!==$expectedVersion)throw new Exception('Версия прямого патча фиксирована сервером: '.$expectedVersion.' (текущая версия сайта '.$site['version'].').');$version=$expectedVersion;
        if($phpMin!==''&&!preg_match('/^[0-9]+(?:\.[0-9]+){0,2}$/',$phpMin))throw new Exception('php_min должен быть версией вида 8.2 или 8.2.1.');
        $cleanDelete=array();$seen=array();foreach(is_array($delete)?$delete:array() as $path){$path=$this->validateDraftTarget($path);if(isset($seen[$path]))continue;if(is_dir($this->manager->root().'/'.$path))throw new Exception('Удаление каталога целиком запрещено: '.$path.'. Перечислите файлы отдельно.');$seen[$path]=1;$cleanDelete[]=$path;if(count($cleanDelete)>200)throw new Exception('Можно удалить не более 200 файлов одним прямым патчем.');}
        if(!is_array($actions))$actions=array();$allowed=isset($this->config['allowed_actions'])&&is_array($this->config['allowed_actions'])?$this->config['allowed_actions']:array('clear_cache');foreach($actions as $key=>$value)if(!in_array($key,$allowed,true))throw new Exception('Неподдерживаемое действие патча: '.$key);
        $draftId='draft-'.date('Ymd-His').'-'.substr(UMEUtil::randomHex(6),0,12);$patchId='mcp-'.date('Ymd-His').'-'.substr(UMEUtil::randomHex(6),0,12);$dir=$this->draftsDir.'/'.$draftId;UMEUtil::ensureDir($dir.'/files');UMEUtil::ensureDir($dir.'/migrations');
        $draft=array('id'=>$draftId,'task_id'=>$taskId,'patch_id'=>$patchId,'name'=>$name,'base_site_version'=>$site['version'],'version'=>$version,'php_min'=>$phpMin,'delete'=>$cleanDelete,'actions'=>$actions,'files'=>array(),'migrations'=>array(),'total_bytes'=>0,'status'=>'draft','created_at'=>UMEUtil::now(),'updated_at'=>UMEUtil::now());$this->saveDraft($draft);
        $task['draft']=$this->draftPublic($draft);$task['status']='in_progress';$task=$this->addEventToTask($task,'Создан серверный черновик патча. Файлы будут переданы напрямую через MCP без промежуточного скачивания ZIP.','INFO',array('draft_id'=>$draftId,'patch_id'=>$patchId));$this->saveTask($task);
        return array('draft'=>$this->draftPublic($draft),'policy'=>$this->oauth->settings());
    }
    public function putPatchFile($taskId,$draftId,$target,$content,$encoding){
        $this->getTask($taskId);$draft=$this->readDraft($taskId,$draftId,true);$target=$this->validateDraftTarget($target);$encoding=(string)$encoding;
        if($encoding==='base64'){$raw=base64_decode((string)$content,true);if($raw===false)throw new Exception('content не является корректным base64.');}
        else if($encoding==='utf8'){$raw=(string)$content;}else throw new Exception('encoding должен быть utf8 или base64.');
        $perFile=min($encoding==='base64'?2500000:3145728,(int)$this->config['limits']['max_uncompressed_bytes']);if(strlen($raw)>$perFile)throw new Exception('Один файл прямого патча не может превышать '.UMEUtil::formatBytes($perFile).'. Разбейте крупный артефакт или используйте совместимый ZIP-поток.');
        $key=hash('sha256',$target);$local=$this->draftsDir.'/'.$draftId.'/files/'.$key.'.bin';$existingSize=0;foreach($draft['files'] as $row)if($row['target']===$target){$existingSize=(int)$row['size'];break;}$prospective=(int)$draft['total_bytes']-$existingSize+strlen($raw);if($prospective>(int)$this->config['limits']['max_uncompressed_bytes'])throw new Exception('Суммарный размер прямого патча превышает допустимый лимит.');if(@file_put_contents($local,$raw,LOCK_EX)===false)throw new Exception('Не удалось сохранить файл серверного черновика.');
        $found=false;foreach($draft['files'] as &$row){if($row['target']===$target){$row=array('source'=>'payload/files/'.$key.'.bin','target'=>$target,'sha256'=>hash('sha256',$raw),'size'=>strlen($raw));$found=true;break;}}unset($row);if(!$found)$draft['files'][]=array('source'=>'payload/files/'.$key.'.bin','target'=>$target,'sha256'=>hash('sha256',$raw),'size'=>strlen($raw));
        $draft['total_bytes']=$prospective;if(count($draft['files'])>(int)$this->config['limits']['max_patch_files'])throw new Exception('Слишком много файлов в патче.');$draft=$this->saveDraft($draft);
        $task=$this->getTask($taskId);$task['draft']=$this->draftPublic($draft);$this->saveTask($task);return array('draft'=>$this->draftPublic($draft),'file'=>array('target'=>$target,'size'=>strlen($raw),'sha256'=>hash('sha256',$raw)));
    }
    public function putPatchMigration($taskId,$draftId,$migrationId,$tables,$sql){
        $this->getTask($taskId);$draft=$this->readDraft($taskId,$draftId,true);$migrationId=trim((string)$migrationId);if(!UMEUtil::safeId($migrationId))throw new Exception('Некорректный migration_id.');
        $cleanTables=array();foreach(is_array($tables)?$tables:array() as $table){$table=trim((string)$table);if($table===''||!preg_match('/^[A-Za-z0-9_$.-]{1,128}$/',$table))throw new Exception('Некорректное имя таблицы миграции.');if(!in_array($table,$cleanTables,true))$cleanTables[]=$table;}if(!$cleanTables)throw new Exception('Укажите затрагиваемые таблицы миграции.');
        $sql=(string)$sql;if(trim($sql)==='')throw new Exception('SQL миграции пуст.');if(strlen($sql)>3145728)throw new Exception('SQL миграция слишком большая для прямой передачи.');$existingSize=0;foreach($draft['migrations'] as $row)if($row['id']===$migrationId){$existingSize=(int)(isset($row['size'])?$row['size']:0);break;}$prospective=(int)$draft['total_bytes']-$existingSize+strlen($sql);if($prospective>(int)$this->config['limits']['max_uncompressed_bytes'])throw new Exception('Суммарный размер прямого патча превышает допустимый лимит.');
        $local=$this->draftsDir.'/'.$draftId.'/migrations/'.$migrationId.'.sql';if(@file_put_contents($local,$sql,LOCK_EX)===false)throw new Exception('Не удалось сохранить SQL миграцию.');$source='migrations/'.$migrationId.'.sql';$found=false;foreach($draft['migrations'] as &$row){if($row['id']===$migrationId){$row=array('id'=>$migrationId,'source'=>$source,'sha256'=>hash('sha256',$sql),'tables'=>$cleanTables,'size'=>strlen($sql));$found=true;break;}}unset($row);if(!$found)$draft['migrations'][]=array('id'=>$migrationId,'source'=>$source,'sha256'=>hash('sha256',$sql),'tables'=>$cleanTables,'size'=>strlen($sql));$draft['total_bytes']=$prospective;$draft=$this->saveDraft($draft);
        $task=$this->getTask($taskId);$task['draft']=$this->draftPublic($draft);$this->saveTask($task);return array('draft'=>$this->draftPublic($draft),'migration'=>array('id'=>$migrationId,'tables'=>$cleanTables,'sha256'=>hash('sha256',$sql)));
    }
    private function removeAuthorization($patchId){$rows=UMEUtil::jsonRead($this->authorizationsFile,array());if(isset($rows[$patchId])){unset($rows[$patchId]);UMEUtil::jsonWriteAtomic($this->authorizationsFile,$rows);}}
    public function finalizePatchDraft($taskId,$draftId){
        $task=$this->getTask($taskId);$draft=$this->readDraft($taskId,$draftId,true);if(!$draft['files']&&!$draft['delete']&&!$draft['migrations']&&!$draft['actions'])throw new Exception('Черновик патча не содержит изменений.');if(!class_exists('ZipArchive'))throw new Exception('ZipArchive недоступен: прямой MCP-патч нельзя собрать на сервере.');
        $manifest=array('format'=>'external-upgrade-patch/1.3','patch_id'=>$draft['patch_id'],'name'=>$draft['name'],'base_site_version'=>$draft['base_site_version'],'version'=>$draft['version'],'project'=>$this->config['project'],'domain'=>strtolower(UMEUtil::domain()),'site_id'=>$this->config['site_id'],'files'=>array(),'delete'=>$draft['delete'],'migrations'=>array(),'actions'=>$draft['actions']);if($draft['php_min']!=='')$manifest['php_min']=$draft['php_min'];
        foreach($draft['files'] as $row)$manifest['files'][]=array('source'=>$row['source'],'target'=>$row['target'],'sha256'=>$row['sha256']);foreach($draft['migrations'] as $row)$manifest['migrations'][]=array('id'=>$row['id'],'source'=>$row['source'],'sha256'=>$row['sha256'],'tables'=>$row['tables']);
        $manifest=$this->authorizePatch($taskId,$manifest);$build=$this->uploadsDir.'/'.$draftId.'.built.zip';@unlink($build);$zip=new ZipArchive();if($zip->open($build,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){$this->removeAuthorization($draft['patch_id']);throw new Exception('Не удалось создать серверный ZIP-патч.');}
        try{$zip->addFromString('manifest.json',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));foreach($draft['files'] as $row){$local=$this->draftsDir.'/'.$draftId.'/files/'.hash('sha256',$row['target']).'.bin';if(!is_file($local)||hash_file('sha256',$local)!==$row['sha256'])throw new Exception('Файл черновика повреждён: '.$row['target']);$zip->addFile($local,$row['source']);}foreach($draft['migrations'] as $row){$local=$this->draftsDir.'/'.$draftId.'/migrations/'.$row['id'].'.sql';if(!is_file($local)||hash_file('sha256',$local)!==$row['sha256'])throw new Exception('SQL черновика повреждён: '.$row['id']);$zip->addFile($local,$row['source']);}}catch(Exception $e){$zip->close();@unlink($build);$this->removeAuthorization($draft['patch_id']);throw $e;}$zip->close();
        try{$report=$this->manager->registerApiPatch($build,$draft['name'].'.zip');}catch(Exception $e){@unlink($build);$this->removeAuthorization($draft['patch_id']);throw $e;}$this->removeAuthorization($draft['patch_id']);
        $task=$this->getTask($taskId);if(!isset($task['patch'])||!is_array($task['patch']))$task['patch']=array();$task['patch']['status']='validated';$task['patch']['upload_id']=$report['upload_id'];$task['patch']['source']='direct_mcp';$task['patch']['draft_id']=$draftId;$task['draft']=array_merge($this->draftPublic($draft),array('status'=>'finalized'));$task=$this->addEventToTask($task,'Прямой MCP-патч собран на сервере, подписан и прошёл проверку.','CHECK',array('patch_id'=>$draft['patch_id'],'upload_id'=>$report['upload_id'],'files'=>count($draft['files']),'migrations'=>count($draft['migrations'])));$this->saveTask($task);UMEUtil::removeTree($this->draftsDir.'/'.$draftId);
        $settings=$this->oauth->settings();if($settings['patch_apply_mode']==='auto')$state=$this->startDeployment($taskId);else $state=$this->deploymentState($this->getTask($taskId),'ready_for_approval');return array('report'=>$report,'deployment_state'=>$state,'policy'=>$settings);
    }
    private function refreshTaskJobs($task){
        $changed=false;if(isset($task['jobs'])&&is_array($task['jobs']))foreach($task['jobs'] as $known){if(empty($known['id']))continue;$actual=$this->manager->getJob($known['id']);if($actual){$before=isset($known['status'])?$known['status']:null;$task=$this->syncJob($task,$this->manager->publicJob($actual));if($before!==$actual['status'])$changed=true;}}if($changed)$task=$this->saveTask($task);return $task;
    }
    private function latestJobOfTypes($task,$types){$jobs=isset($task['jobs'])&&is_array($task['jobs'])?$task['jobs']:array();for($i=count($jobs)-1;$i>=0;$i--)if(isset($jobs[$i]['type'])&&in_array($jobs[$i]['type'],$types,true))return $jobs[$i];return null;}
    private function deploymentState($task,$forcedStatus=null){
        $task=$this->refreshTaskJobs($task);$job=$this->latestJobOfTypes($task,array('backup','install'));$status=$forcedStatus;if($status===null){if($job&&in_array(isset($job['status'])?$job['status']:'',array('failed','cancelled'),true))$status=$job['status'];else if($job&&$job['type']==='install'&&isset($job['status'])&&$job['status']==='completed')$status='completed';else if($job&&in_array(isset($job['status'])?$job['status']:'',array('running','stale','cancelling','rolling_back'),true))$status='running';else if(!empty($task['patch']['upload_id']))$status='ready';else $status='draft';}
        return array('status'=>$status,'phase'=>$job?(isset($job['type'])?$job['type']:null):null,'job'=>$job,'patch'=>isset($task['patch'])?$task['patch']:null,'backup_completed'=>$this->completedBackupExists($task),'owner_approval_required'=>$this->applyApprovalRequired(),'owner_approved'=>$this->applyApproved($task),'settings'=>$this->oauth->settings());
    }
    // 3.6.3: with patch_apply_mode=confirm the owner must approve the exact validated upload in the
    // Upgrade Manager panel. Previously "confirm" was only an instruction for the AI agent.
    private function applyApprovalRequired(){$s=$this->oauth->settings();return isset($s['patch_apply_mode'])&&$s['patch_apply_mode']!=='auto';}
    private function applyApproved($task){return !$this->applyApprovalRequired()||(!empty($task['patch']['upload_id'])&&!empty($task['patch']['approved_upload_id'])&&$task['patch']['approved_upload_id']===$task['patch']['upload_id']);}
    public function approvePatch($taskId){
        $task=$this->refreshTaskJobs($this->getTask($taskId));if(empty($task['patch']['upload_id']))throw new Exception('В этой API-задаче нет проверенного патча для подтверждения.');
        $status=isset($task['patch']['status'])?(string)$task['patch']['status']:'';if(!in_array($status,array('validated','installing'),true))throw new Exception('Патч уже не ожидает подтверждения (статус: '.$status.').');
        if(!is_file($this->storage.'/uploads/'.$task['patch']['upload_id'].'.zip'))throw new Exception('Файл проверенного патча не найден в хранилище.');
        $task['patch']['approved_upload_id']=$task['patch']['upload_id'];$task['patch']['approved_at']=UMEUtil::now();
        $task=$this->addEventToTask($task,'Владелец разрешил установку патча в панели Upgrade Manager.','CHECK',array('upload_id'=>$task['patch']['upload_id']));
        return $this->saveTask($task);
    }
    public function startDeployment($taskId){
        $task=$this->refreshTaskJobs($this->getTask($taskId));if(empty($task['patch']['upload_id']))throw new Exception('Для API-задачи ещё нет проверенного патча. Сначала финализируйте серверный черновик.');$current=$this->latestJobOfTypes($task,array('backup','install'));if($current&&in_array(isset($current['status'])?$current['status']:'',array('running','stale','cancelling','rolling_back'),true))return $this->deploymentState($task);
        if($current&&$current['type']==='install'&&isset($current['status'])&&$current['status']==='completed')return $this->deploymentState($task);
        if(!$this->applyApproved($task)){$state=$this->deploymentState($task,'awaiting_owner_approval');$state['message']='Применение патча требует подтверждения владельца: Upgrade Manager → «API-задачи» → «Разрешить установку». После подтверждения повторите start_deployment.';return $state;}
        $settings=$this->oauth->settings();if(!empty($settings['require_backup_before_install'])&&!$this->completedBackupExists($task)){$this->startBackup($taskId);$task=$this->getTask($taskId);$task['deployment']=array('status'=>'running','phase'=>'backup','started_at'=>UMEUtil::now());$this->saveTask($task);return $this->deploymentState($task);}
        $this->startInstall($taskId,$task['patch']['upload_id']);$task=$this->getTask($taskId);$task['deployment']=array('status'=>'running','phase'=>'install','started_at'=>isset($task['deployment']['started_at'])?$task['deployment']['started_at']:UMEUtil::now());$this->saveTask($task);return $this->deploymentState($task);
    }
    public function advanceDeployment($taskId){
        $task=$this->refreshTaskJobs($this->getTask($taskId));if(empty($task['patch']['upload_id']))throw new Exception('Проверенный патч для deployment отсутствует.');$job=$this->latestJobOfTypes($task,array('backup','install'));
        if(!$job)return $this->startDeployment($taskId);
        if(in_array(isset($job['status'])?$job['status']:'',array('running','stale','cancelling','rolling_back'),true)){$this->advanceJob($taskId,$job['id']);$task=$this->refreshTaskJobs($this->getTask($taskId));$job=$this->latestJobOfTypes($task,array('backup','install'));}
        if($job&&in_array(isset($job['status'])?$job['status']:'',array('failed','cancelled'),true)){if(isset($task['deployment'])){$task['deployment']['status']=$job['status'];$task['deployment']['phase']=$job['type'];$this->saveTask($task);}return $this->deploymentState($task);}
        if($job&&$job['type']==='backup'&&isset($job['status'])&&$job['status']==='completed'){$this->startInstall($taskId,$task['patch']['upload_id']);$task=$this->getTask($taskId);$task['deployment']=array('status'=>'running','phase'=>'install','started_at'=>isset($task['deployment']['started_at'])?$task['deployment']['started_at']:UMEUtil::now());$this->saveTask($task);return $this->deploymentState($task);}
        if($job&&$job['type']==='install'&&isset($job['status'])&&$job['status']==='completed'){$task['deployment']=array('status'=>'completed','phase'=>'install','completed_at'=>UMEUtil::now());$this->saveTask($task);return $this->deploymentState($task,'completed');}
        return $this->deploymentState($task);
    }
    public function cancelDeployment($taskId){
        $task=$this->refreshTaskJobs($this->getTask($taskId));$job=$this->latestJobOfTypes($task,array('backup','install'));if(!$job||!in_array(isset($job['status'])?$job['status']:'',array('running','stale'),true))return $this->deploymentState($task,'no_active_process');$this->cancelJob($taskId,$job['id']);return $this->deploymentState($this->getTask($taskId));
    }

    public function startInstall($taskId,$uploadId){
        $task=$this->getTask($taskId);$settings=$this->oauth->settings();if(!$this->applyApproved($task))throw new Exception('Применение патча требует подтверждения владельца в панели Upgrade Manager → «API-задачи».');if(!empty($settings['require_backup_before_install'])&&!$this->completedBackupExists($task))throw new Exception('Перед API-установкой требуется завершённый бэкап этой задачи.');if(empty($task['patch']['upload_id'])||$task['patch']['upload_id']!==$uploadId)throw new Exception('Проверенный патч не относится к этой API-задаче.');$job=$this->manager->publicJob($this->manager->startInstall($uploadId));$task=$this->attachJob($task,$job,'Установка API-патча');$task['patch']['status']='installing';$task=$this->addEventToTask($task,'Запущена установка проверенного патча.','INFO',array('job_id'=>$job['id'],'upload_id'=>$uploadId));$this->saveTask($task);return $job;
    }
    public function approveRollback($taskId,$patchId){
        $task=$this->getTask($taskId);$request=isset($task['rollback_request'])&&is_array($task['rollback_request'])?$task['rollback_request']:array();
        if(!UMEUtil::safeId($patchId)||empty($request['patch_id'])||$request['patch_id']!==$patchId||!isset($request['status'])||$request['status']!=='pending')throw new Exception('Откат этого патча не ожидает подтверждения.');
        if(empty($request['requested_ts'])||time()-(int)$request['requested_ts']>900)throw new Exception('Запрос отката устарел. Попросите агента повторить запрос.');
        $task['rollback_request']['status']='approved';$task['rollback_request']['approved_ts']=time();$task['rollback_request']['approved_at']=UMEUtil::now();
        $task=$this->addEventToTask($task,'Владелец разрешил откат патча '.$patchId.'.','CHECK',array('patch_id'=>$patchId));return $this->saveTask($task);
    }
    public function startRollback($taskId,$patchId){
        $task=$this->getTask($taskId);if(!UMEUtil::safeId($patchId))throw new Exception('Некорректный patch_id.');
        $request=isset($task['rollback_request'])&&is_array($task['rollback_request'])?$task['rollback_request']:array();
        if(!empty($request['patch_id'])&&$request['patch_id']===$patchId&&isset($request['status'])&&$request['status']==='started'&&!empty($request['job_id'])){
            $existing=$this->manager->getJob($request['job_id']);if($existing)return $this->manager->publicJob($existing);
            throw new Exception('Откат уже запускался; повторный старт заблокирован.');
        }
        if($this->applyApprovalRequired()){
            $approved=!empty($request['patch_id'])&&$request['patch_id']===$patchId&&isset($request['status'])&&$request['status']==='approved'&&!empty($request['approved_ts'])&&time()-(int)$request['approved_ts']<=900;
            if(!$approved){
                $task['rollback_request']=array('patch_id'=>$patchId,'status'=>'pending','requested_ts'=>time(),'requested_at'=>UMEUtil::now());
                $task=$this->addEventToTask($task,'Откат '.$patchId.' ожидает разрешения владельца в панели API-задач.','WARN',array('patch_id'=>$patchId));$this->saveTask($task);
                return array('status'=>'awaiting_owner_approval','patch_id'=>$patchId,'message'=>'Подтвердите откат в Upgrade Manager → API-задачи → Разрешить откат. После подтверждения повторите start_patch_rollback.');
            }
        }
        // One approval is valid only for this task+patch and cannot be reused for another rollback.
        $job=$this->manager->publicJob($this->manager->startRollback($patchId));
        $task=$this->attachJob($task,$job,'Откат API-патча');$task['rollback_request']=array('patch_id'=>$patchId,'status'=>'started','job_id'=>$job['id'],'started_at'=>UMEUtil::now());
        $task=$this->addEventToTask($task,'Запущен откат патча.','WARN',array('job_id'=>$job['id'],'patch_id'=>$patchId));$this->saveTask($task);return $job;
    }
    public function finishTask($taskId,$status,$summary,$requirements,$checks){
        $task=$this->getTask($taskId);$allowed=array('completed','failed','no_changes');if(!in_array($status,$allowed,true))throw new Exception('Недопустимый итоговый статус задачи.');$summary=$this->limitText($summary,6000);if($summary==='')throw new Exception('Итоговое резюме задачи пусто.');
        $cleanRequirements=array();foreach(is_array($requirements)?$requirements:array() as $item){$item=$this->limitText($item,1000);if($item!==''&&count($cleanRequirements)<100)$cleanRequirements[]=$item;}$cleanChecks=array();foreach(is_array($checks)?$checks:array() as $item){$item=$this->limitText($item,1000);if($item!==''&&count($cleanChecks)<100)$cleanChecks[]=$item;}
        if($status==='completed'){$installed=false;foreach(isset($task['jobs'])?$task['jobs']:array() as $row)if(isset($row['type'],$row['status'])&&$row['type']==='install'&&$row['status']==='completed'){$installed=true;break;}if(!$installed)throw new Exception('Нельзя отметить задачу выполненной до завершения установки.');if(!$cleanChecks)throw new Exception('Для выполненной задачи укажите проведённые проверки.');}
        $task['status']=$status;$task['result']=array('status'=>$status,'summary'=>$summary,'completed_requirements'=>$cleanRequirements,'validation_checks'=>$cleanChecks,'completed_at'=>UMEUtil::now());$task=$this->addEventToTask($task,$status==='completed'?'Техническое задание выполнено и зафиксировано.':($status==='no_changes'?'Изменения не потребовались.':'Техническое задание завершено с ошибкой.'),$status==='failed'?'ERROR':'CHECK',array('checks_count'=>count($cleanChecks)));return $this->saveTask($task);
    }
}
