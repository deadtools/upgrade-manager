<?php
class UMECronScheduler {
    private $config;
    private $manager;
    private $upgradeDir;
    private $storage;
    private $settingsFile;
    private $tag;

    public function __construct($config,$manager,$upgradeDir){
        $this->config=$config;
        $this->manager=$manager;
        $this->upgradeDir=rtrim(str_replace('\\','/',$upgradeDir),'/');
        $this->storage=$manager->storage();
        $this->settingsFile=$this->storage.'/settings.json';
        $siteId=isset($config['site_id'])?(string)$config['site_id']:'site';
        $this->tag='UME_AUTO_BACKUP_'.substr(hash('sha256',$siteId.'|'.$this->upgradeDir),0,16);
    }

    private function frequencies(){
        return array(
            'hourly'=>array('seconds'=>3600,'label'=>'Каждый час'),
            '6h'=>array('seconds'=>21600,'label'=>'Каждые 6 часов'),
            '12h'=>array('seconds'=>43200,'label'=>'Каждые 12 часов'),
            'daily'=>array('seconds'=>86400,'label'=>'Раз в сутки'),
            'weekly'=>array('seconds'=>604800,'label'=>'Раз в неделю')
        );
    }

    private function settings(){
        $s=UMEUtil::jsonRead($this->settingsFile,array());
        if(!isset($s['auto_backup_enabled']))$s['auto_backup_enabled']=false;
        if(empty($s['auto_backup_frequency'])||!isset($this->frequencies()[$s['auto_backup_frequency']]))$s['auto_backup_frequency']='daily';
        if(!isset($s['auto_backup_last_started_at']))$s['auto_backup_last_started_at']='';
        if(!isset($s['auto_backup_last_finished_at']))$s['auto_backup_last_finished_at']='';
        if(!isset($s['auto_backup_last_success_at']))$s['auto_backup_last_success_at']='';
        if(!isset($s['auto_backup_last_status']))$s['auto_backup_last_status']='never';
        if(!isset($s['auto_backup_last_job_id']))$s['auto_backup_last_job_id']='';
        if(!isset($s['auto_backup_last_file']))$s['auto_backup_last_file']='';
        if(!isset($s['auto_backup_last_error']))$s['auto_backup_last_error']='';
        if(!isset($s['auto_backup_cron_error']))$s['auto_backup_cron_error']='';
        if(!isset($s['auto_backup_cron_install_method']))$s['auto_backup_cron_install_method']='';
        if(!isset($s['auto_backup_cron_last_checked_at']))$s['auto_backup_cron_last_checked_at']='';
        return $s;
    }

    private function saveSettingsArray($s){UMEUtil::jsonWriteAtomic($this->settingsFile,$s);}

    private function execAvailable(){
        if(!function_exists('exec'))return false;
        $disabled=array_map('trim',explode(',',(string)ini_get('disable_functions')));
        return !in_array('exec',$disabled,true);
    }

    private function runCommand($command,&$exitCode){
        $exitCode=127;
        if(!$this->execAvailable())return '';
        $lines=array();
        @exec($command.' 2>&1',$lines,$exitCode);
        return trim(implode("\n",$lines));
    }

    private function findBinary($name){
        if(!$this->execAvailable())return '';
        $code=0;$out=$this->runCommand('command -v '.escapeshellarg($name),$code);
        if($code===0&&$out!==''){
            $line=trim(strtok($out,"\n"));
            if($line!==''&&is_executable($line))return $line;
        }
        $candidates=$name==='crontab'?array('/usr/bin/crontab','/bin/crontab','/usr/local/bin/crontab'):array('/usr/bin/php','/usr/local/bin/php','/bin/php');
        foreach($candidates as $candidate)if(is_file($candidate)&&is_executable($candidate))return $candidate;
        return '';
    }

    private function cronCapability(){
        if(!$this->execAvailable())return array('available'=>false,'reason'=>'PHP запрещает exec(), поэтому системный crontab нельзя изменить из веб-интерфейса.','crontab'=>'','php'=>'');
        $crontab=$this->findBinary('crontab');
        if($crontab==='')return array('available'=>false,'reason'=>'На сервере не найден исполняемый файл crontab.','crontab'=>'','php'=>'');
        $php=$this->findBinary('php');
        if($php==='')return array('available'=>false,'reason'=>'Не найден PHP CLI, необходимый для фонового запуска резервной копии.','crontab'=>$crontab,'php'=>'');
        $readable=false;$readResult=$this->readCrontab($crontab,$readable);
        if(!$readable)return array('available'=>false,'reason'=>'crontab найден, но текущий пользователь PHP не может прочитать свой crontab'.($readResult!==''?': '.$readResult:'.'),'crontab'=>$crontab,'php'=>$php);
        return array('available'=>true,'reason'=>'','crontab'=>$crontab,'php'=>$php);
    }

    private function readCrontab($binary,&$readable){
        $readable=false;$code=0;$out=$this->runCommand(escapeshellarg($binary).' -l',$code);
        if($code===0){$readable=true;return $out;}
        $lower=strtolower($out);
        if($out===''||strpos($lower,'no crontab')!==false||strpos($lower,'no crontab for')!==false){$readable=true;return '';}
        return $out;
    }

    private function cronLine($phpBinary){
        $runner=$this->upgradeDir.'/cron.php';
        return '*/5 * * * * '.escapeshellarg($phpBinary).' '.escapeshellarg($runner).' --auto-backup >/dev/null 2>&1 # '.$this->tag;
    }

    private function inspectInstalled($cap){
        if(empty($cap['available']))return array('installed'=>false,'readable'=>false,'line'=>'');
        $readable=false;$content=$this->readCrontab($cap['crontab'],$readable);
        if(!$readable)return array('installed'=>false,'readable'=>false,'line'=>'');
        foreach(preg_split('/\r\n|\r|\n/',$content) as $line){if(strpos($line,'# '.$this->tag)!==false)return array('installed'=>true,'readable'=>true,'line'=>trim($line));}
        return array('installed'=>false,'readable'=>true,'line'=>'');
    }

    private function installCrontabPayload($binary,$payload){
        if(strpos($payload,"\0")!==false)throw new Exception('В содержимом crontab обнаружен недопустимый NUL-символ.');
        $errors=array();

        // Primary path: feed the complete crontab through STDIN. This avoids a class of
        // shared-hosting/container issues where the crontab helper cannot open a file
        // located in the website runtime storage even though PHP itself can write it.
        if(strlen($payload)<=65536){
            $code=0;
            $command='printf %s '.escapeshellarg($payload).' | '.escapeshellarg($binary).' -';
            $out=$this->runCommand($command,$code);
            if($code===0)return array('method'=>'stdin','output'=>$out);
            $errors[]='stdin: '.($out!==''?$out:'код '.$code);
        }else $errors[]='stdin: пропущен из-за большого размера crontab';

        // Compatibility fallback for crontab implementations that do not accept "-".
        // Use the operating-system temp directory rather than Upgrade Manager storage;
        // some hosting control panels execute the crontab helper in a namespace where
        // site-private runtime paths are not visible.
        $tmpDir=rtrim(str_replace('\\','/',(string)sys_get_temp_dir()),'/');
        $tmp='';
        if($tmpDir!==''&&is_dir($tmpDir)&&is_writable($tmpDir))$tmp=@tempnam($tmpDir,'ume-cron-');
        if($tmp!==false&&$tmp!==''){
            try{
                if(@file_put_contents($tmp,$payload,LOCK_EX)===false)throw new Exception('не удалось записать временный файл');
                @chmod($tmp,0600);
                clearstatcache(true,$tmp);
                $real=@realpath($tmp);
                if($real===false||!is_file($real)||!is_readable($real))throw new Exception('временный файл недоступен для чтения');
                $code=0;$out=$this->runCommand(escapeshellarg($binary).' '.escapeshellarg($real),$code);
                if($code===0)return array('method'=>'system_temp_file','output'=>$out);
                $errors[]='temp-file: '.($out!==''?$out:'код '.$code);
            }catch(Exception $e){$errors[]='temp-file: '.$e->getMessage();}
            finally{@unlink($tmp);}
        }else $errors[]='temp-file: системный временный каталог недоступен';

        throw new Exception('crontab отказался сохранить задачу. '.implode('; ',$errors));
    }

    private function replaceCronLine($enabled,$cap){
        if(empty($cap['available']))throw new Exception($cap['reason']);
        $readable=false;$current=$this->readCrontab($cap['crontab'],$readable);
        if(!$readable)throw new Exception('Не удалось прочитать текущий crontab: '.$current);
        $rows=array();
        foreach(preg_split('/\r\n|\r|\n/',$current) as $line){
            if(trim($line)==='')continue;
            if(strpos($line,'# '.$this->tag)!==false)continue;
            $rows[]=$line;
        }
        if($enabled)$rows[]=$this->cronLine($cap['php']);
        $payload=implode("\n",$rows).(count($rows)?"\n":'');
        $install=$this->installCrontabPayload($cap['crontab'],$payload);
        $check=$this->inspectInstalled($cap);
        if($enabled&&!$check['installed'])throw new Exception('Cron-команда была отправлена, но после повторного чтения задача не найдена в crontab.');
        if(!$enabled&&$check['installed'])throw new Exception('Не удалось удалить задачу Upgrade Manager из crontab.');
        $check['install_method']=isset($install['method'])?$install['method']:'';
        return $check;
    }

    private function timestamp($value){$ts=$value!==''?strtotime($value):false;return $ts===false?0:(int)$ts;}

    private function dueInfo($s){
        $map=$this->frequencies();$frequency=isset($map[$s['auto_backup_frequency']])?$s['auto_backup_frequency']:'daily';$seconds=$map[$frequency]['seconds'];
        $last=$this->timestamp($s['auto_backup_last_started_at']);
        $next=$last>0?$last+$seconds:time();
        return array('frequency'=>$frequency,'seconds'=>$seconds,'due'=>time()>=$next,'next'=>$next);
    }

    public function save($input){
        $s=$this->settings();
        $enabled=!empty($input['auto_backup_enabled']);
        $frequency=isset($input['auto_backup_frequency'])?(string)$input['auto_backup_frequency']:$s['auto_backup_frequency'];
        $map=$this->frequencies();if(!isset($map[$frequency]))throw new Exception('Неизвестная частота автоматического резервного копирования.');
        $s['auto_backup_enabled']=$enabled;$s['auto_backup_frequency']=$frequency;$s['auto_backup_cron_error']='';$s['auto_backup_cron_last_checked_at']=UMEUtil::now();$this->saveSettingsArray($s);
        $cap=$this->cronCapability();
        if($enabled){
            try{$result=$this->replaceCronLine(true,$cap);$s=$this->settings();$s['auto_backup_cron_install_method']=isset($result['install_method'])?$result['install_method']:'';$s['auto_backup_cron_error']='';$s['auto_backup_cron_last_checked_at']=UMEUtil::now();$this->saveSettingsArray($s);}catch(Exception $e){$s=$this->settings();$s['auto_backup_cron_error']=$e->getMessage();$s['auto_backup_cron_last_checked_at']=UMEUtil::now();$this->saveSettingsArray($s);return $this->status();}
        }else if(!empty($cap['available'])){
            try{$result=$this->replaceCronLine(false,$cap);$s=$this->settings();$s['auto_backup_cron_install_method']=isset($result['install_method'])?$result['install_method']:'';$s['auto_backup_cron_error']='';$s['auto_backup_cron_last_checked_at']=UMEUtil::now();$this->saveSettingsArray($s);}catch(Exception $e){$s=$this->settings();$s['auto_backup_cron_error']=$e->getMessage();$s['auto_backup_cron_last_checked_at']=UMEUtil::now();$this->saveSettingsArray($s);return $this->status();}
        }
        return $this->status();
    }

    public function reinstall(){
        $s=$this->settings();$cap=$this->cronCapability();$result=$this->replaceCronLine(!empty($s['auto_backup_enabled']),$cap);$s=$this->settings();$s['auto_backup_cron_error']='';$s['auto_backup_cron_install_method']=isset($result['install_method'])?$result['install_method']:'';$s['auto_backup_cron_last_checked_at']=UMEUtil::now();$this->saveSettingsArray($s);return $this->status();
    }

    public function status(){
        $s=$this->settings();$map=$this->frequencies();$cap=$this->cronCapability();$installed=$this->inspectInstalled($cap);$due=$this->dueInfo($s);
        $active=!empty($s['auto_backup_enabled'])&&!empty($cap['available'])&&!empty($installed['installed']);
        $reason='';
        if(!empty($s['auto_backup_enabled'])&&!$active)$reason=!empty($s['auto_backup_cron_error'])?$s['auto_backup_cron_error']:$cap['reason'];
        return array(
            'enabled'=>!empty($s['auto_backup_enabled']),
            'frequency'=>$due['frequency'],
            'frequency_label'=>$map[$due['frequency']]['label'],
            'active'=>$active,
            'system_cron_available'=>!empty($cap['available']),
            'system_cron_installed'=>!empty($installed['installed']),
            'system_cron_reason'=>$cap['reason'],
            'cron_error'=>$reason,
            'cron_line'=>!empty($installed['installed'])?$installed['line']:'',
            'cron_install_method'=>isset($s['auto_backup_cron_install_method'])?$s['auto_backup_cron_install_method']:'',
            'cron_last_checked_at'=>isset($s['auto_backup_cron_last_checked_at'])?$s['auto_backup_cron_last_checked_at']:'',
            'crontab_binary'=>isset($cap['crontab'])?$cap['crontab']:'',
            'php_cli_binary'=>isset($cap['php'])?$cap['php']:'',
            'runner'=>$this->upgradeDir.'/cron.php',
            'next_run_at'=>($active?gmdate('c',$due['next']):''),
            'last_started_at'=>$s['auto_backup_last_started_at'],
            'last_finished_at'=>$s['auto_backup_last_finished_at'],
            'last_success_at'=>$s['auto_backup_last_success_at'],
            'last_status'=>$s['auto_backup_last_status'],
            'last_job_id'=>$s['auto_backup_last_job_id'],
            'last_file'=>$s['auto_backup_last_file'],
            'last_error'=>$s['auto_backup_last_error'],
            'frequencies'=>$map
        );
    }

    private function updateRunState($fields){
        $s=$this->settings();foreach($fields as $k=>$v)$s[$k]=$v;$this->saveSettingsArray($s);
    }

    public function runDueBackup($force){
        $lockPath=$this->storage.'/locks/cron-auto-backup.lock';$fh=@fopen($lockPath,'c+');if(!$fh||!@flock($fh,LOCK_EX|LOCK_NB)){if($fh)@fclose($fh);return array('ok'=>true,'status'=>'locked','message'=>'Другой cron-процесс уже выполняется.');}
        try{
            $s=$this->settings();if(empty($s['auto_backup_enabled'])&&!$force)return array('ok'=>true,'status'=>'disabled','message'=>'Автоматические резервные копии отключены.');
            $due=$this->dueInfo($s);if(!$force&&!$due['due'])return array('ok'=>true,'status'=>'not_due','message'=>'Время следующей резервной копии ещё не наступило.','next_run_at'=>gmdate('c',$due['next']));
            $active=$this->manager->activeJob();if($active&&in_array($active['status'],array('running','stale','cancelling','rolling_back'),true))return array('ok'=>true,'status'=>'busy','message'=>'Резервная копия отложена: уже выполняется процесс '.$active['id'].'.');
            $started=UMEUtil::now();$this->updateRunState(array('auto_backup_last_started_at'=>$started,'auto_backup_last_finished_at'=>'','auto_backup_last_status'=>'running','auto_backup_last_job_id'=>'','auto_backup_last_file'=>'','auto_backup_last_error'=>''));
            $job=$this->manager->startBackupProfile('all','full','cron');$jobId=$job['id'];$this->updateRunState(array('auto_backup_last_job_id'=>$jobId));
            $deadline=time()+14400;$last=null;$iterations=0;
            while(time()<$deadline&&$iterations<100000){
                $last=$this->manager->step($jobId);$iterations++;
                if(!$last)throw new Exception('Процесс резервного копирования исчез.');
                if(in_array($last['status'],array('completed','failed','cancelled'),true))break;
                usleep(20000);
            }
            if(!$last||$last['status']!=='completed'){
                $message=$last&&isset($last['message'])?$last['message']:'Превышено время выполнения автоматического backup.';
                $this->updateRunState(array('auto_backup_last_finished_at'=>UMEUtil::now(),'auto_backup_last_status'=>$last&&isset($last['status'])?$last['status']:'failed','auto_backup_last_error'=>$message));
                return array('ok'=>false,'status'=>'failed','job_id'=>$jobId,'message'=>$message);
            }
            $stored=$this->manager->getJob($jobId);$file=$stored&&isset($stored['data']['result_file'])?(string)$stored['data']['result_file']:'';
            $finished=UMEUtil::now();$this->updateRunState(array('auto_backup_last_finished_at'=>$finished,'auto_backup_last_success_at'=>$finished,'auto_backup_last_status'=>'completed','auto_backup_last_file'=>$file,'auto_backup_last_error'=>''));
            return array('ok'=>true,'status'=>'completed','job_id'=>$jobId,'file'=>$file,'message'=>'Полная автоматическая резервная копия создана.');
        }catch(Exception $e){
            $this->updateRunState(array('auto_backup_last_finished_at'=>UMEUtil::now(),'auto_backup_last_status'=>'failed','auto_backup_last_error'=>$e->getMessage()));
            return array('ok'=>false,'status'=>'failed','message'=>$e->getMessage());
        }finally{@flock($fh,LOCK_UN);@fclose($fh);}
    }
}
