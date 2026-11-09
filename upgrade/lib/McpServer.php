<?php
class UMEMcpServer {
    private $config;
    private $manager;
    private $oauth;
    private $tasks;
    private $modern=false;

    public function __construct($config,$manager,$oauth,$tasks){$this->config=$config;$this->manager=$manager;$this->oauth=$oauth;$this->tasks=$tasks;}
    private function serverInfo(){return array('name'=>'universal-upgrade-manager','title'=>'Universal Upgrade Manager','version'=>$this->config['version']);}
    private function json($data,$status){http_response_code($status);header('Content-Type: application/json; charset=utf-8');if($this->modern)header('MCP-Protocol-Version: 2026-07-28');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
    private function rpcError($id,$code,$message,$data){$error=array('code'=>$code,'message'=>$message);if($data!==null)$error['data']=$data;$this->json(array('jsonrpc'=>'2.0','id'=>$id,'error'=>$error),200);}
    private function authHeader($error='invalid_token',$description='Требуется OAuth-авторизация Upgrade Manager.'){
        return 'Bearer resource_metadata="'.$this->oauth->metadataUrl().'", error="'.$error.'", error_description="'.str_replace('"','\\"',$description).'"';
    }
    private function unauthorized(){http_response_code(401);header('WWW-Authenticate: '.$this->authHeader());header('Content-Type: application/json; charset=utf-8');echo json_encode(array('error'=>'unauthorized','error_description'=>'Подключите MCP-сервер через OAuth Upgrade Manager.','resource_metadata'=>$this->oauth->metadataUrl()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
    private function arg($args,$name,$default){return isset($args[$name])?$args[$name]:$default;}
    private function stampModernResult($result,$cacheable=false){
        if(!$this->modern)return $result;
        if(!isset($result['resultType']))$result['resultType']='complete';
        if($cacheable){if(!isset($result['ttlMs']))$result['ttlMs']=0;if(!isset($result['cacheScope']))$result['cacheScope']='private';}
        if(!isset($result['_meta'])||!is_array($result['_meta']))$result['_meta']=array();
        $result['_meta']['io.modelcontextprotocol/serverInfo']=$this->serverInfo();
        return $result;
    }
    private function toolResult($data,$message){return $this->stampModernResult(array('content'=>array(array('type'=>'text','text'=>$message)),'structuredContent'=>$data,'isError'=>false),false);}
    private function toolError($message,$auth=false,$error='invalid_token'){
        $out=array('content'=>array(array('type'=>'text','text'=>$message)),'isError'=>true);
        if($auth)$out['_meta']=array('mcp/www_authenticate'=>array($this->authHeader($error,$message)));
        return $this->stampModernResult($out,false);
    }
    private function objectSchema($properties,$required){if(!is_array($properties))$properties=array();$schema=array('type'=>'object','properties'=>$properties? $properties : new stdClass(),'additionalProperties'=>false);if($required)$schema['required']=array_values($required);return $schema;}
    private function stringProp($description){return array('type'=>'string','description'=>$description);}
    private function oauthScheme($scopes){return array('type'=>'oauth2','scopes'=>$scopes);}
    private function tool($name,$title,$description,$schema,$readOnly,$destructive,$openWorld,$scopes,$extraMeta=array(),$outputSchema=null){
        $schemes=array($this->oauthScheme($scopes));
        $tool=array('name'=>$name,'title'=>$title,'description'=>$description,'inputSchema'=>$schema,'annotations'=>array('readOnlyHint'=>$readOnly,'destructiveHint'=>$destructive,'openWorldHint'=>$openWorld),'securitySchemes'=>$schemes,'_meta'=>array('securitySchemes'=>$schemes));
        if($outputSchema!==null)$tool['outputSchema']=$outputSchema;
        foreach($extraMeta as $k=>$v)$tool['_meta'][$k]=$v;
        return $tool;
    }
    private function deploymentInstructions(){
        $s=$this->oauth->settings();
        $upload=!empty($s['patch_upload_mode'])&&$s['patch_upload_mode']==='auto'
            ? 'После подготовки изменений сразу передавайте их на сервер через begin_patch_draft/put_patch_file: отдельного ожидания подтверждения передачи не требуется.'
            : 'Перед begin_patch_draft дождитесь явного согласия пользователя отправить подготовленный патч на сервер.';
        $apply=!empty($s['patch_apply_mode'])&&$s['patch_apply_mode']==='auto'
            ? 'После finalize_patch_draft установка запускается автоматически; вызывайте advance_deployment до completed/failed/cancelled.'
            : 'После finalize_patch_draft остановитесь: применение подтверждает владелец сайта кнопкой «Разрешить установку» в Upgrade Manager → «API-задачи» (сервер не начнёт установку без этого подтверждения). Попросите пользователя нажать кнопку, затем вызовите start_deployment и advance_deployment до терминального статуса. Статус awaiting_owner_approval означает, что подтверждения ещё нет.';
        return $upload.' '.$apply;
    }
    public function tools(){
        $empty=$this->objectSchema(array(),array());$taskId=$this->stringProp('ID API-задачи из create_task.');$jobId=$this->stringProp('ID процесса Upgrade Manager.');$draftId=$this->stringProp('ID серверного черновика патча из begin_patch_draft.');$autoApply=$this->oauth->settings()['patch_apply_mode']==='auto';
        $profileOutput=$this->objectSchema(array('id'=>array('type'=>'string'),'name'=>array('type'=>'string'),'nickname'=>array('type'=>'string')),array('id'));
        return array(
            $this->tool('get_connection_profile','Профиль подключения','Возвращает безопасный профиль подключённого сайта, чтобы пользователь отличал несколько подключений Upgrade Manager.',$empty,true,false,false,array('site:read'),array('openai/profile'=>true),$profileOutput),
            $this->tool('get_site_status','Состояние сайта','Начинайте с этого инструмента: возвращает профиль сайта, версию Upgrade Manager, политики прямой передачи/применения и активный процесс.',$empty,true,false,false,array('site:read')),
            $this->tool('get_patch_profile','Профиль патча','Возвращает безопасный профиль формата патча без HMAC-ключа и других секретов.',$empty,true,false,false,array('site:read')),
            $this->tool('list_api_tasks','Список API-задач','Показывает последние задания, их статусы, ТЗ и результаты.',$this->objectSchema(array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100,'default'=>30)),array()),true,false,false,array('tasks:read')),
            $this->tool('get_api_task','Карточка API-задачи','Возвращает полное ТЗ, журнал, процессы, серверный черновик патча и итог конкретной задачи.',$this->objectSchema(array('task_id'=>$taskId),array('task_id')),true,false,false,array('tasks:read')),
            $this->tool('create_task','Создать API-задачу','Перед любыми изменениями зарегистрируйте точное ТЗ и критерии приёмки. Карточка будет видна владельцу сайта.',$this->objectSchema(array('title'=>$this->stringProp('Короткое название задания.'),'specification'=>$this->stringProp('Полное техническое задание без сокращений.'),'acceptance_criteria'=>array('type'=>'array','items'=>array('type'=>'string'),'maxItems'=>50)),array('title','specification')),false,false,false,array('tasks:write')),
            $this->tool('append_task_log','Добавить запись в лог ТЗ','Фиксирует понятный владельцу этап работы, решение, предупреждение или проверку. Не записывайте секреты.',$this->objectSchema(array('task_id'=>$taskId,'message'=>$this->stringProp('Что было сделано или проверено.'),'level'=>array('type'=>'string','enum'=>array('INFO','WARN','ERROR','CHECK'),'default'=>'INFO'),'data'=>array('type'=>'object','additionalProperties'=>true)),array('task_id','message')),false,false,false,array('tasks:write')),
            $this->tool('list_files','Список файлов сайта','Показывает разрешённые кодовые файлы. Конфиги с паролями, хранилище, медиа и секреты закрыты.',$this->objectSchema(array('path'=>$this->stringProp('Каталог относительно корня сайта; пустая строка означает корень.'),'recursive'=>array('type'=>'boolean','default'=>false),'limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>1000,'default'=>300)),array()),true,false,false,array('site:read')),
            $this->tool('read_files','Прочитать файлы сайта','Читает до 20 разрешённых текстовых файлов и возвращает SHA-256. Используйте только релевантные ТЗ файлы.',$this->objectSchema(array('paths'=>array('type'=>'array','items'=>array('type'=>'string'),'minItems'=>1,'maxItems'=>20)),array('paths')),true,false,false,array('site:read')),
            $this->tool('search_code','Поиск по коду','Ищет буквальную строку в разрешённых кодовых файлах, не раскрывая конфиги и секреты.',$this->objectSchema(array('query'=>$this->stringProp('Буквальная строка поиска.'),'path'=>$this->stringProp('Каталог поиска относительно корня.'),'case_sensitive'=>array('type'=>'boolean','default'=>false),'max_results'=>array('type'=>'integer','minimum'=>1,'maximum'=>100,'default'=>50)),array('query')),true,false,false,array('site:read')),

            $this->tool('create_backup','Создать резервную копию','Создаёт новую резервную копию штатным механизмом Upgrade Manager с текущими настройками файлов и БД. Отдельный task_id не нужен: сервер сам создаст служебную API-задачу и вернёт task_id и job. После запуска вызывайте advance_job до completed, failed или cancelled.',$empty,false,false,false,array('backups:write')),
            $this->tool('list_backups','Список резервных копий','Возвращает последние резервные копии Upgrade Manager, режимы файлов/БД, размер, время создания и наличие очищенной публичной копии.',$this->objectSchema(array('limit'=>array('type'=>'integer','minimum'=>1,'maximum'=>100,'default'=>20)),array()),true,false,false,array('site:read')),
            $this->tool('get_backup_download_url','Ссылка на резервную копию','Возвращает публичную очищенную ссылку на выбранный backup для просмотра ChatGPT и отдельную прямую ссылку скачивания ZIP. Приватный recovery-backup и секреты не публикуются.',$this->objectSchema(array('backup_id'=>$this->stringProp('ID резервной копии из list_backups, например backup-...zip.')),array('backup_id')),true,false,false,array('site:read')),

            $this->tool('begin_patch_draft','Начать прямой патч','Предпочтительный путь для новых изменений: создаёт серверный черновик патча. Версию патча сервер назначает сам: ровно next_patch_version из get_site_status. ZIP на компьютере пользователя не нужен.',$this->objectSchema(array('task_id'=>$taskId,'name'=>$this->stringProp('Понятное название патча.'),'version'=>$this->stringProp('Необязательно. Если передано, должно точно совпасть с next_patch_version из get_site_status.'),'delete'=>array('type'=>'array','items'=>array('type'=>'string'),'maxItems'=>200),'actions'=>array('type'=>'object','additionalProperties'=>true),'php_min'=>$this->stringProp('Необязательная минимальная версия PHP.')),array('task_id','name')),false,false,false,array('patches:write')),
            $this->tool('put_patch_file','Передать файл патча','Передаёт один изменённый/новый файл прямо в защищённый серверный черновик. Upgrade Manager сам вычислит SHA-256 и соберёт ZIP. encoding=utf8 для текста, base64 для бинарных данных.',$this->objectSchema(array('task_id'=>$taskId,'draft_id'=>$draftId,'target'=>$this->stringProp('Путь назначения относительно корня сайта.'),'encoding'=>array('type'=>'string','enum'=>array('utf8','base64'),'default'=>'utf8'),'content'=>$this->stringProp('Содержимое файла в выбранной кодировке.')),array('task_id','draft_id','target','content')),false,false,false,array('patches:write')),
            $this->tool('put_patch_migration','Передать SQL-миграцию','Добавляет SQL-миграцию в серверный черновик патча. Используйте только для таблиц, которые реально меняет патч; Upgrade Manager сделает rollback этих таблиц перед применением.',$this->objectSchema(array('task_id'=>$taskId,'draft_id'=>$draftId,'migration_id'=>$this->stringProp('Уникальный безопасный ID миграции.'),'tables'=>array('type'=>'array','items'=>array('type'=>'string'),'minItems'=>1,'maxItems'=>100),'sql'=>$this->stringProp('SQL миграции UTF-8.')),array('task_id','draft_id','migration_id','tables','sql')),false,false,false,array('patches:write')),
            $this->tool('finalize_patch_draft','Завершить прямой патч','Собирает ZIP на сервере, подписывает локальным HMAC, проверяет контрольные суммы и совместимость. При политике авто-применения сразу запускает безопасный deployment; иначе возвращает ready_for_approval.',$this->objectSchema(array('task_id'=>$taskId,'draft_id'=>$draftId),array('task_id','draft_id')),false,$autoApply,false,array('patches:write')),
            $this->tool('start_deployment','Применить готовый патч','Запускает единый безопасный deployment готового API-патча: при необходимости сначала создаёт бэкап, затем устанавливает патч. Это изменение сайта; используйте после подтверждения пользователя, если политика не автоматическая.',$this->objectSchema(array('task_id'=>$taskId),array('task_id')),false,true,false,array('patches:write','backups:write')),
            $this->tool('advance_deployment','Продолжить deployment','Выполняет один безопасный шаг текущего deployment. Автоматически переходит от бэкапа к установке и возвращает терминальный результат. Повторяйте до completed, failed или cancelled.',$this->objectSchema(array('task_id'=>$taskId),array('task_id')),false,true,false,array('patches:write','backups:write')),
            $this->tool('cancel_deployment','Прервать deployment','Запрашивает безопасную отмену активного бэкапа/установки. Если изменения уже начались, Upgrade Manager выполняет автоматический откат.',$this->objectSchema(array('task_id'=>$taskId),array('task_id')),false,true,false,array('patches:write','backups:write')),

            $this->tool('start_backup','Создать бэкап для ТЗ','Совместимый низкоуровневый инструмент: запускает резервную копию согласно сохранённым настройкам. Для нового прямого deployment обычно используйте start_deployment.',$this->objectSchema(array('task_id'=>$taskId),array('task_id')),false,false,false,array('backups:write')),
            $this->tool('advance_job','Продолжить отдельный процесс','Низкоуровневый совместимый инструмент. Выполняет один шаг конкретного процесса. Для нового прямого deployment используйте advance_deployment.',$this->objectSchema(array('task_id'=>$taskId,'job_id'=>$jobId),array('task_id','job_id')),false,true,false,array('tasks:write','backups:write','patches:write')),
            $this->tool('cancel_job','Отменить отдельный процесс','Низкоуровневый совместимый инструмент безопасной отмены.',$this->objectSchema(array('task_id'=>$taskId,'job_id'=>$jobId),array('task_id','job_id')),false,true,false,array('tasks:write','backups:write','patches:write')),
            $this->tool('authorize_patch','Авторизовать внешний ZIP-манифест','Совместимость со старым ZIP-потоком. Для новых патчей предпочтительны begin_patch_draft/put_patch_file/finalize_patch_draft.',$this->objectSchema(array('task_id'=>$taskId,'manifest'=>array('type'=>'object','additionalProperties'=>true)),array('task_id','manifest')),false,false,false,array('patches:write')),
            $this->tool('begin_patch_upload','Начать загрузку внешнего ZIP','Совместимость со старым ZIP-потоком после authorize_patch.',$this->objectSchema(array('task_id'=>$taskId,'filename'=>$this->stringProp('Имя ZIP-файла.'),'size'=>array('type'=>'integer','minimum'=>1),'sha256'=>$this->stringProp('SHA-256 всего ZIP.'),'chunks_total'=>array('type'=>'integer','minimum'=>1,'maximum'=>2000)),array('task_id','filename','size','sha256','chunks_total')),false,false,false,array('patches:write')),
            $this->tool('upload_patch_chunk','Загрузить чанк внешнего ZIP','Совместимость со старым ZIP-потоком; передаёт следующий base64-чанк строго по порядку.',$this->objectSchema(array('task_id'=>$taskId,'upload_id'=>$this->stringProp('ID защищённой загрузки.'),'chunk_index'=>array('type'=>'integer','minimum'=>0),'base64'=>$this->stringProp('Base64-содержимое чанка.'),'chunk_sha256'=>$this->stringProp('SHA-256 декодированного чанка.')),array('task_id','upload_id','chunk_index','base64')),false,false,false,array('patches:write')),
            $this->tool('finish_patch_upload','Завершить внешний ZIP','Совместимость со старым ZIP-потоком; проверяет размер, SHA-256, подпись и содержимое.',$this->objectSchema(array('task_id'=>$taskId,'upload_id'=>$this->stringProp('ID защищённой загрузки.')),array('task_id','upload_id')),false,false,false,array('patches:write')),
            $this->tool('start_patch_install','Установить внешний ZIP','Низкоуровневая совместимость. Для нового потока используйте start_deployment.',$this->objectSchema(array('task_id'=>$taskId,'upload_id'=>$this->stringProp('Проверенный upload_id.')),array('task_id','upload_id')),false,true,false,array('patches:write','backups:write')),
            $this->tool('start_patch_rollback','Откатить патч','Восстановление из rollback-пакета. При ручной политике сначала возвращает awaiting_owner_approval: владелец подтверждает конкретный откат в API-задачах, затем повторите вызов.',$this->objectSchema(array('task_id'=>$taskId,'patch_id'=>$this->stringProp('ID установленного патча.')),array('task_id','patch_id')),false,true,false,array('patches:write')),
            $this->tool('finish_task','Зафиксировать результат ТЗ','После терминального процесса запишите понятное резюме, выполненные требования и фактические проверки. Нельзя отметить completed до успешной установки.',$this->objectSchema(array('task_id'=>$taskId,'status'=>array('type'=>'string','enum'=>array('completed','failed','no_changes')),'summary'=>$this->stringProp('Итог: что изменено и каков результат.'),'completed_requirements'=>array('type'=>'array','items'=>array('type'=>'string'),'maxItems'=>100),'validation_checks'=>array('type'=>'array','items'=>array('type'=>'string'),'maxItems'=>100)),array('task_id','status','summary')),false,false,false,array('tasks:write'))
        );
    }
    private function toolScopes($name){
        if(in_array($name,array('get_connection_profile','get_site_status','get_patch_profile','list_files','read_files','search_code','list_backups','get_backup_download_url'),true))return array('site:read');
        if(in_array($name,array('list_api_tasks','get_api_task'),true))return array('tasks:read');
        if(in_array($name,array('create_task','append_task_log','finish_task'),true))return array('tasks:write');
        if(in_array($name,array('create_backup','start_backup'),true))return array('backups:write');
        if(in_array($name,array('begin_patch_draft','put_patch_file','put_patch_migration','finalize_patch_draft','authorize_patch','begin_patch_upload','upload_patch_chunk','finish_patch_upload','start_patch_rollback'),true))return array('patches:write');
        if(in_array($name,array('start_deployment','advance_deployment','cancel_deployment','start_patch_install','advance_job','cancel_job'),true))return array('patches:write','backups:write');
        return array('site:read');
    }
    private function checkToolAuth($name){
        try{$row=$this->oauth->authenticate($this->toolScopes($name));}catch(Exception $e){return $this->toolError($e->getMessage(),true,'access_denied');}
        if(!$row)return $this->toolError('Требуется подключить Upgrade Manager через OAuth и разрешить необходимые области доступа.',true,'insufficient_scope');
        return null;
    }
    private function callTool($name,$args){
        if($name==='get_connection_profile'){$domain=UMEUtil::domain();return $this->toolResult(array('id'=>$domain,'name'=>'Upgrade Manager · '.$domain,'nickname'=>$domain.' · Upgrade Manager'),json_encode(array('id'=>$domain,'name'=>'Upgrade Manager · '.$domain,'nickname'=>$domain.' · Upgrade Manager'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
        if($name==='get_site_status'){ $data=$this->tasks->siteStatus();$data['deployment_policy']=$this->oauth->settings();return $this->toolResult($data,'Состояние сайта и политика deployment получены.'); }
        if($name==='get_patch_profile')return $this->toolResult($this->manager->profile(),'Безопасный профиль патча получен; секреты не раскрыты.');
        if($name==='list_api_tasks')return $this->toolResult(array('tasks'=>$this->tasks->listTasks($this->arg($args,'limit',30))),'Список API-задач получен.');
        if($name==='get_api_task')return $this->toolResult($this->tasks->getTask($this->arg($args,'task_id','')),'Карточка API-задачи получена.');
        if($name==='create_task')return $this->toolResult($this->tasks->createTask($this->arg($args,'title',''),$this->arg($args,'specification',''),$this->arg($args,'acceptance_criteria',array())),'Техническое задание зарегистрировано в Upgrade Manager.');
        if($name==='append_task_log')return $this->toolResult($this->tasks->appendLog($this->arg($args,'task_id',''),$this->arg($args,'message',''),$this->arg($args,'level','INFO'),$this->arg($args,'data',array())),'Запись добавлена в журнал ТЗ.');
        if($name==='list_files')return $this->toolResult($this->tasks->listFiles($this->arg($args,'path',''),!empty($args['recursive']),$this->arg($args,'limit',300)),'Список разрешённых файлов получен.');
        if($name==='read_files')return $this->toolResult($this->tasks->readFiles($this->arg($args,'paths',array())),'Файлы прочитаны с контрольными суммами.');
        if($name==='search_code')return $this->toolResult($this->tasks->searchCode($this->arg($args,'query',''),$this->arg($args,'path',''),!empty($args['case_sensitive']),$this->arg($args,'max_results',50)),'Поиск по коду завершён.');
        if($name==='create_backup')return $this->toolResult($this->tasks->createStandaloneBackup(),'Резервная копия запущена штатным механизмом Upgrade Manager. Продолжайте advance_job по возвращённым task_id и job.id.');
        if($name==='list_backups')return $this->toolResult(array('backups'=>$this->tasks->listBackups($this->arg($args,'limit',20))),'Список резервных копий получен.');
        if($name==='get_backup_download_url')return $this->toolResult($this->tasks->backupShareUrls($this->arg($args,'backup_id','')),'Ссылки на очищенную публичную копию получены.');

        if($name==='begin_patch_draft')return $this->toolResult($this->tasks->beginPatchDraft($this->arg($args,'task_id',''),$this->arg($args,'name',''),$this->arg($args,'version',''),$this->arg($args,'delete',array()),$this->arg($args,'actions',array()),$this->arg($args,'php_min','')),'Серверный черновик патча создан. Передавайте изменённые файлы прямо в него.');
        if($name==='put_patch_file')return $this->toolResult($this->tasks->putPatchFile($this->arg($args,'task_id',''),$this->arg($args,'draft_id',''),$this->arg($args,'target',''),$this->arg($args,'content',''),$this->arg($args,'encoding','utf8')),'Файл принят в серверный черновик патча.');
        if($name==='put_patch_migration')return $this->toolResult($this->tasks->putPatchMigration($this->arg($args,'task_id',''),$this->arg($args,'draft_id',''),$this->arg($args,'migration_id',''),$this->arg($args,'tables',array()),$this->arg($args,'sql','')),'SQL-миграция принята в серверный черновик патча.');
        if($name==='finalize_patch_draft')return $this->toolResult($this->tasks->finalizePatchDraft($this->arg($args,'task_id',''),$this->arg($args,'draft_id','')),'Патч собран и проверен на сервере. Следуйте возвращённому deployment_state.');
        if($name==='start_deployment')return $this->toolResult($this->tasks->startDeployment($this->arg($args,'task_id','')),'Безопасный deployment запущен. Вызывайте advance_deployment до терминального статуса.');
        if($name==='advance_deployment')return $this->toolResult($this->tasks->advanceDeployment($this->arg($args,'task_id','')),'Выполнен следующий шаг deployment.');
        if($name==='cancel_deployment')return $this->toolResult($this->tasks->cancelDeployment($this->arg($args,'task_id','')),'Запрошено безопасное прерывание deployment.');

        if($name==='start_backup')return $this->toolResult(array('job'=>$this->tasks->startBackup($this->arg($args,'task_id',''))),'Создание резервной копии запущено; продолжайте advance_job до терминального статуса.');
        if($name==='advance_job')return $this->toolResult(array('job'=>$this->tasks->advanceJob($this->arg($args,'task_id',''),$this->arg($args,'job_id',''))),'Шаг процесса выполнен.');
        if($name==='cancel_job')return $this->toolResult(array('job'=>$this->tasks->cancelJob($this->arg($args,'task_id',''),$this->arg($args,'job_id',''))),'Запрошена безопасная отмена процесса.');
        if($name==='authorize_patch')return $this->toolResult(array('manifest'=>$this->tasks->authorizePatch($this->arg($args,'task_id',''),$this->arg($args,'manifest',array()))),'Манифест проверен и получил одноразовую подпись.');
        if($name==='begin_patch_upload')return $this->toolResult($this->tasks->beginUpload($this->arg($args,'task_id',''),$this->arg($args,'filename',''),$this->arg($args,'size',0),$this->arg($args,'sha256',''),$this->arg($args,'chunks_total',0)),'Защищённая чанковая загрузка создана.');
        if($name==='upload_patch_chunk')return $this->toolResult($this->tasks->uploadChunk($this->arg($args,'task_id',''),$this->arg($args,'upload_id',''),$this->arg($args,'chunk_index',-1),$this->arg($args,'base64',''),$this->arg($args,'chunk_sha256','')),'Чанк патча принят.');
        if($name==='finish_patch_upload')return $this->toolResult(array('report'=>$this->tasks->finishUpload($this->arg($args,'task_id',''),$this->arg($args,'upload_id',''))),'ZIP полностью загружен и прошёл все серверные проверки.');
        if($name==='start_patch_install')return $this->toolResult(array('job'=>$this->tasks->startInstall($this->arg($args,'task_id',''),$this->arg($args,'upload_id',''))),'Установка запущена; продолжайте advance_job до терминального статуса.');
        if($name==='start_patch_rollback'){$rollback=$this->tasks->startRollback($this->arg($args,'task_id',''),$this->arg($args,'patch_id',''));$pending=isset($rollback['status'])&&$rollback['status']==='awaiting_owner_approval';return $this->toolResult($pending?array('approval'=>$rollback):array('job'=>$rollback),$pending?'Откат не запущен: требуется разрешение владельца в Upgrade Manager → API-задачи.':'Откат запущен; продолжайте advance_job до терминального статуса.');}
        if($name==='finish_task')return $this->toolResult($this->tasks->finishTask($this->arg($args,'task_id',''),$this->arg($args,'status',''),$this->arg($args,'summary',''),$this->arg($args,'completed_requirements',array()),$this->arg($args,'validation_checks',array())),'Итог технического задания сохранён.');
        throw new Exception('Неизвестный MCP-инструмент: '.$name);
    }
    private function detectModern($request){
        $header=isset($_SERVER['HTTP_MCP_PROTOCOL_VERSION'])?(string)$_SERVER['HTTP_MCP_PROTOCOL_VERSION']:'';
        if($header==='2026-07-28')return true;
        if(isset($request['method'])&&$request['method']==='server/discover')return true;
        $params=isset($request['params'])&&is_array($request['params'])?$request['params']:array();$meta=isset($params['_meta'])&&is_array($params['_meta'])?$params['_meta']:array();
        return isset($meta['io.modelcontextprotocol/protocolVersion'])&&$meta['io.modelcontextprotocol/protocolVersion']==='2026-07-28';
    }
    private function discoverResult(){
        return $this->stampModernResult(array('supportedVersions'=>array('2026-07-28','2025-11-25','2025-06-18','2025-03-26'),'capabilities'=>array('tools'=>array('listChanged'=>false)),'instructions'=>$this->deploymentInstructions()),true);
    }
    public function handle(){
        if($_SERVER['REQUEST_METHOD']==='GET'){
            if(isset($_GET['health']))$this->json(array('name'=>'Universal Upgrade Manager MCP','version'=>$this->config['version'],'status'=>$this->oauth->enabled()?'ready':'disabled','oauth'=>$this->oauth->metadataUrl(),'authorization_server'=>$this->oauth->authorizationMetadataUrl(),'protocols'=>array('2026-07-28','2025-11-25','2025-06-18','2025-03-26')),200);
            // A GET is not an MCP request, but returning the OAuth challenge here is useful
            // for connector setup screens that probe the endpoint before sending JSON-RPC.
            $this->unauthorized();
        }
        if($_SERVER['REQUEST_METHOD']==='HEAD'){$this->unauthorized();}
        if($_SERVER['REQUEST_METHOD']==='OPTIONS'){header('Allow: POST, GET, HEAD, OPTIONS');header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Method, Mcp-Name');header('Access-Control-Expose-Headers: WWW-Authenticate, MCP-Protocol-Version');header('Access-Control-Allow-Methods: POST, GET, HEAD, OPTIONS');http_response_code(204);exit;}
        if($_SERVER['REQUEST_METHOD']!=='POST')$this->json(array('error'=>'method_not_allowed'),405);
        $raw=@file_get_contents('php://input');if($raw===false||strlen($raw)>4194304)$this->rpcError(null,-32700,'Некорректное тело запроса.',null);$request=json_decode($raw,true);if(!is_array($request))$this->rpcError(null,-32700,'JSON-RPC запрос не разобран.',null);
        $this->modern=$this->detectModern($request);$id=array_key_exists('id',$request)?$request['id']:null;$method=isset($request['method'])?(string)$request['method']:'';$params=isset($request['params'])&&is_array($request['params'])?$request['params']:array();

        if($method==='server/discover')$this->json(array('jsonrpc'=>'2.0','id'=>$id,'result'=>$this->discoverResult()),200);
        if($method==='notifications/initialized'){http_response_code(202);exit;}
        if($method==='ping')$this->json(array('jsonrpc'=>'2.0','id'=>$id,'result'=>$this->stampModernResult(array(),false)),200);
        if($method==='initialize'){
            $requested=isset($params['protocolVersion'])?(string)$params['protocolVersion']:'2025-03-26';$supported=array('2025-11-25','2025-06-18','2025-03-26');$selected=in_array($requested,$supported,true)?$requested:'2025-11-25';
            $result=array('protocolVersion'=>$selected,'capabilities'=>array('tools'=>array('listChanged'=>false)),'serverInfo'=>$this->serverInfo(),'instructions'=>$this->deploymentInstructions());$this->json(array('jsonrpc'=>'2.0','id'=>$id,'result'=>$result),200);
        }
        if($method==='tools/list')$this->json(array('jsonrpc'=>'2.0','id'=>$id,'result'=>$this->stampModernResult(array('tools'=>$this->tools()),true)),200);
        if($method==='tools/call'){
            $name=isset($params['name'])?(string)$params['name']:'';$args=isset($params['arguments'])&&is_array($params['arguments'])?$params['arguments']:array();
            $authError=$this->checkToolAuth($name);if($authError!==null)$this->json(array('jsonrpc'=>'2.0','id'=>$id,'result'=>$authError),200);
            try{$result=$this->callTool($name,$args);}catch(Exception $e){$result=$this->toolError($e->getMessage());}
            $this->json(array('jsonrpc'=>'2.0','id'=>$id,'result'=>$result),200);
        }
        $this->rpcError($id,-32601,'Метод не найден.',array('method'=>$method));
    }
}
