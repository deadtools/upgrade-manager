<?php
/**
 * Streaming full MySQL/MariaDB dump used by direct project migration.
 *
 * Goals:
 * - one connection for the whole data snapshot;
 * - no OFFSET pagination while the source is changing;
 * - views are preserved without treating them as tables;
 * - generated columns are excluded from INSERT lists;
 * - routines, triggers and events are exported after table data;
 * - DEFINER clauses are removed so the dump can be restored by another DB user;
 * - INSERT statements are kept reasonably small and the largest statement is reported.
 *
 * PHP 5.6 compatible by design.
 */
class UMEDatabaseDump {
    private $db;
    private $insertTargetBytes;
    private $maxStatementBytes=0;

    public function __construct($db,$insertTargetBytes){
        $this->db=$db;
        $this->insertTargetBytes=max(65536,(int)$insertTargetBytes);
    }

    public function dumpToFile($file,$label,$completeMarker){
        @set_time_limit(0);
        $inventory=$this->inventory(true);
        $fh=@fopen($file,'wb');
        if(!$fh)throw new Exception('Не удалось создать SQL-дамп: '.$file);
        $locked=false;$transaction=false;$success=false;
        try{
            $this->write($fh,"-- Upgrade Manager full database dump v2\n");
            $this->write($fh,'-- '.trim((string)$label)."\n");
            $this->write($fh,'-- Created: '.UMEUtil::now()."\n");
            $this->write($fh,"SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

            // Base-table structures are emitted before the consistent data read.
            foreach($inventory['tables'] as $table){
                $this->writeStatement($fh,'DROP TABLE IF EXISTS '.UMEUtil::sqlIdentifier($table['name']));
                $this->writeStatement($fh,$table['create_sql']);
                $this->write($fh,"\n");
            }

            // Temporary view tables let dependent views be created in arbitrary order later.
            foreach($inventory['views'] as $view){
                $this->writeStatement($fh,'DROP VIEW IF EXISTS '.UMEUtil::sqlIdentifier($view['name']));
                $this->writeStatement($fh,'DROP TABLE IF EXISTS '.UMEUtil::sqlIdentifier($view['name']));
                $this->writeStatement($fh,$view['placeholder_sql']);
                $this->write($fh,"\n");
            }

            $mode=$this->beginConsistentRead($inventory['tables']);
            $locked=$mode==='locked';$transaction=$mode==='transaction';
            foreach($inventory['tables'] as $table)$this->dumpTableData($fh,$table['name'],$table['insert_columns']);
            if($transaction){if(!$this->db->commit())throw new Exception('Не удалось завершить consistent snapshot: '.$this->db->error);$transaction=false;}
            if($locked){if(!$this->db->query('UNLOCK TABLES'))throw new Exception('Не удалось снять read-lock после дампа: '.$this->db->error);$locked=false;}

            // Stored functions/procedures are created before views/triggers/events because those
            // objects can call routines.
            foreach($inventory['routines'] as $routine){
                $kind=$routine['type']==='FUNCTION'?'FUNCTION':'PROCEDURE';
                $this->writeStatement($fh,'DROP '.$kind.' IF EXISTS '.UMEUtil::sqlIdentifier($routine['name']));
                $this->writeProgrammableObject($fh,$this->stripDefiner($routine['create_sql']),$routine['sql_mode']);
                $this->write($fh,"\n");
            }

            foreach($inventory['views'] as $view){
                $this->writeStatement($fh,'DROP TABLE IF EXISTS '.UMEUtil::sqlIdentifier($view['name']));
                $this->writeStatement($fh,'DROP VIEW IF EXISTS '.UMEUtil::sqlIdentifier($view['name']));
                $this->writeStatement($fh,$this->stripDefiner($view['create_sql']));
                $this->write($fh,"\n");
            }

            foreach($inventory['triggers'] as $trigger){
                $this->writeStatement($fh,'DROP TRIGGER IF EXISTS '.UMEUtil::sqlIdentifier($trigger['name']));
                $this->writeProgrammableObject($fh,$this->stripDefiner($trigger['create_sql']),$trigger['sql_mode']);
                $this->write($fh,"\n");
            }

            foreach($inventory['events'] as $event){
                $this->writeStatement($fh,'DROP EVENT IF EXISTS '.UMEUtil::sqlIdentifier($event['name']));
                $this->writeProgrammableObject($fh,$this->stripDefiner($event['create_sql']),$event['sql_mode']);
                $this->write($fh,"\n");
            }

            $this->write($fh,"SET FOREIGN_KEY_CHECKS=1;\n".$completeMarker."\n");
            if(!@fflush($fh))throw new Exception('Не удалось завершить запись SQL-дампа.');
            $success=true;
        } finally {
            if($transaction)@$this->db->rollback();
            if($locked)@$this->db->query('UNLOCK TABLES');
            if(is_resource($fh))fclose($fh);
            if(!$success)@unlink($file);
        }
        clearstatcache(true,$file);
        $bytes=@filesize($file);$sha=@hash_file('sha256',$file);
        if($bytes===false||$bytes<=0||!is_string($sha)||strlen($sha)!==64)throw new Exception('Не удалось проверить готовый SQL-дамп.');
        return array(
            'bytes'=>(int)$bytes,
            'sha256'=>strtolower($sha),
            'max_statement_bytes'=>(int)$this->maxStatementBytes,
            'consistency_mode'=>$inventory['has_non_transactional']?'locked':'transaction',
            'counts'=>$inventory['counts'],
            'fingerprint'=>$inventory['fingerprint'],
            'non_transactional_tables'=>$inventory['non_transactional_tables']
        );
    }

    public function inventory($includeDefinitions){
        $relations=$this->relations();$tables=array();$views=array();$nonTransactional=array();
        foreach($relations as $r){
            if($r['type']==='VIEW')$views[]=$r;
            else {$tables[]=$r;if(!$this->isTransactionalEngine($r['engine']))$nonTransactional[]=$r['name'];}
        }
        $triggers=$this->namesFromQuery('SHOW TRIGGERS','Trigger','TRIGGER');
        $routines=array_merge($this->routineNames('PROCEDURE'),$this->routineNames('FUNCTION'));
        usort($routines,array($this,'sortObjectRows'));
        $events=$this->eventNames();
        if($includeDefinitions){
            foreach($tables as $i=>$row){$m=$this->showCreateMeta('TABLE',$row['name']);$tables[$i]['create_sql']=$m['sql'];$tables[$i]['insert_columns']=$this->insertableColumns($row['name']);}
            foreach($views as $i=>$row){$m=$this->showCreateMeta('VIEW',$row['name']);$views[$i]['create_sql']=$m['sql'];$views[$i]['placeholder_sql']=$this->viewPlaceholderSql($row['name']);}
            foreach($routines as $i=>$row){$m=$this->showCreateMeta($row['type'],$row['name']);$routines[$i]['create_sql']=$m['sql'];$routines[$i]['sql_mode']=$m['sql_mode'];}
            foreach($triggers as $i=>$row){$m=$this->showCreateMeta('TRIGGER',$row['name']);$triggers[$i]['create_sql']=$m['sql'];$triggers[$i]['sql_mode']=$m['sql_mode'];}
            foreach($events as $i=>$row){$m=$this->showCreateMeta('EVENT',$row['name']);$events[$i]['create_sql']=$m['sql'];$events[$i]['sql_mode']=$m['sql_mode'];}
        }
        $names=array();
        foreach($tables as $r)$names[]='TABLE:'.$r['name'];
        foreach($views as $r)$names[]='VIEW:'.$r['name'];
        foreach($triggers as $r)$names[]='TRIGGER:'.$r['name'];
        foreach($routines as $r)$names[]=$r['type'].':'.$r['name'];
        foreach($events as $r)$names[]='EVENT:'.$r['name'];
        sort($names,SORT_STRING);
        return array(
            'tables'=>$tables,'views'=>$views,'triggers'=>$triggers,'routines'=>$routines,'events'=>$events,
            'has_non_transactional'=>!empty($nonTransactional),'non_transactional_tables'=>$nonTransactional,
            'counts'=>array('tables'=>count($tables),'views'=>count($views),'triggers'=>count($triggers),'procedures'=>$this->countType($routines,'PROCEDURE'),'functions'=>$this->countType($routines,'FUNCTION'),'events'=>count($events)),
            'fingerprint'=>hash('sha256',implode("\n",$names))
        );
    }

    public function maxAllowedPacket(){
        $r=$this->query('SELECT @@SESSION.max_allowed_packet AS p');$row=$r->fetch_assoc();return isset($row['p'])?(int)$row['p']:0;
    }

    private function databaseName(){
        $r=$this->query('SELECT DATABASE() AS db');$row=$r->fetch_assoc();$name=isset($row['db'])?(string)$row['db']:'';if($name==='')throw new Exception('Не удалось определить имя текущей БД.');return $name;
    }

    private function relations(){
        $out=array();$r=$this->query('SHOW FULL TABLES');
        while($row=$r->fetch_assoc()){
            $v=array_values($row);if(!isset($v[0]))continue;$name=(string)$v[0];$type=isset($v[1])?strtoupper((string)$v[1]):'BASE TABLE';$engine='';
            if($type!=='VIEW'){$sr=$this->query('SHOW TABLE STATUS WHERE Name='.$this->quote($name));$st=$sr->fetch_assoc();$engine=is_array($st)&&isset($st['Engine'])?(string)$st['Engine']:'';}
            $out[]=array('name'=>$name,'type'=>$type==='VIEW'?'VIEW':'TABLE','engine'=>$engine);
        }
        usort($out,array($this,'sortObjectRows'));return $out;
    }

    private function namesFromQuery($sql,$field,$type){
        $out=array();$r=$this->db->query($sql);if($r===false){
            // SHOW TRIGGERS can be unavailable on very old/limited servers. A full dump must not
            // silently pretend there are no triggers if the server explicitly rejected the query.
            throw new Exception('Не удалось получить '.$type.' из БД: '.$this->db->error);
        }
        while($row=$r->fetch_assoc())if(isset($row[$field]))$out[]=array('name'=>(string)$row[$field],'type'=>$type);
        usort($out,array($this,'sortObjectRows'));return $out;
    }

    private function routineNames($type){
        $dbName=$this->databaseName();$out=array();$sql='SHOW '.$type.' STATUS WHERE Db='.$this->quote($dbName);$r=$this->db->query($sql);if($r===false)throw new Exception('Не удалось получить '.$type.' из БД: '.$this->db->error);
        while($row=$r->fetch_assoc())if(isset($row['Name']))$out[]=array('name'=>(string)$row['Name'],'type'=>$type);
        return $out;
    }

    private function eventNames(){
        $dbName=$this->databaseName();$out=array();$r=$this->db->query('SHOW EVENTS FROM '.UMEUtil::sqlIdentifier($dbName));
        if($r===false){
            // MariaDB/MySQL can return an error when EVENT metadata is forbidden. Failing is safer
            // than producing a dump that claims to be full while silently dropping events.
            throw new Exception('Не удалось получить EVENTS из БД: '.$this->db->error);
        }
        while($row=$r->fetch_assoc()){
            if(isset($row['Name']))$name=$row['Name'];else { $v=array_values($row);$name=isset($v[1])?$v[1]:''; }
            if($name!=='')$out[]=array('name'=>(string)$name,'type'=>'EVENT');
        }
        usort($out,array($this,'sortObjectRows'));return $out;
    }

    public function sortObjectRows($a,$b){$an=isset($a['name'])?$a['name']:'';$bn=isset($b['name'])?$b['name']:'';return strcmp($an,$bn);}
    private function countType($rows,$type){$n=0;foreach($rows as $r)if(isset($r['type'])&&$r['type']===$type)$n++;return $n;}
    private function isTransactionalEngine($engine){$e=strtoupper((string)$engine);return in_array($e,array('INNODB','XTRADB','NDB','NDBCLUSTER'),true);}

    private function beginConsistentRead($tables){
        $non=array();foreach($tables as $t)if(!$this->isTransactionalEngine($t['engine']))$non[]=$t['name'];
        if(!$non){
            $this->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->query('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            return 'transaction';
        }
        if(!$tables)return 'none';
        $locks=array();foreach($tables as $t)$locks[]=UMEUtil::sqlIdentifier($t['name']).' READ';
        $r=$this->db->query('LOCK TABLES '.implode(', ',$locks));
        if($r===false)throw new Exception('В БД есть нетранзакционные таблицы ('.implode(', ',$non).'), но MySQL не разрешил LOCK TABLES для согласованного дампа: '.$this->db->error);
        return 'locked';
    }

    private function showCreateMeta($type,$name){
        $type=strtoupper($type);$r=$this->query('SHOW CREATE '.$type.' '.UMEUtil::sqlIdentifier($name));$row=$r->fetch_assoc();if(!is_array($row))throw new Exception('Пустой SHOW CREATE '.$type.' для '.$name.'.');
        $preferred=array('Create Table','Create View','Create Procedure','Create Function','Create Event','Create Trigger','SQL Original Statement');$sql='';
        foreach($preferred as $k)if(isset($row[$k])&&trim((string)$row[$k])!==''){$sql=rtrim(trim((string)$row[$k]),';');break;}
        if($sql==='')foreach($row as $k=>$v)if(is_string($v)&&stripos((string)$k,'create')!==false&&trim($v)!==''){$sql=rtrim(trim($v),';');break;}
        if($sql==='')foreach($row as $k=>$v)if(is_string($v)&&stripos((string)$k,'statement')!==false&&trim($v)!==''){$sql=rtrim(trim($v),';');break;}
        if($sql==='')throw new Exception('SHOW CREATE '.$type.' не вернул SQL для '.$name.'.');
        return array('sql'=>$sql,'sql_mode'=>isset($row['sql_mode'])?(string)$row['sql_mode']:'');
    }
    private function showCreate($type,$name){$m=$this->showCreateMeta($type,$name);return $m['sql'];}

    private function viewPlaceholderSql($view){
        $r=$this->query('SHOW FULL COLUMNS FROM '.UMEUtil::sqlIdentifier($view));$cols=array();
        while($c=$r->fetch_assoc()){
            if(!isset($c['Field']))continue;$type=isset($c['Type'])&&trim((string)$c['Type'])!==''?(string)$c['Type']:'text';
            $cols[]=UMEUtil::sqlIdentifier($c['Field']).' '.$type.' NULL';
        }
        if(!$cols)$cols[]=UMEUtil::sqlIdentifier('__ume_placeholder').' tinyint NULL';
        return 'CREATE TABLE '.UMEUtil::sqlIdentifier($view).' ('.implode(', ',$cols).')';
    }

    private function insertableColumns($table){
        $r=$this->query('SHOW FULL COLUMNS FROM '.UMEUtil::sqlIdentifier($table));$cols=array();
        while($c=$r->fetch_assoc()){
            if(!isset($c['Field']))continue;$extra=isset($c['Extra'])?strtoupper((string)$c['Extra']):'';
            if(strpos($extra,'VIRTUAL GENERATED')!==false||strpos($extra,'STORED GENERATED')!==false)continue;
            $cols[]=(string)$c['Field'];
        }
        return $cols;
    }

    private function dumpTableData($fh,$table,$cols){
        if(!$cols)return;
        $quoted=array();foreach($cols as $c)$quoted[]=UMEUtil::sqlIdentifier($c);
        $sql='SELECT '.implode(',',$quoted).' FROM '.UMEUtil::sqlIdentifier($table);
        $result=$this->db->query($sql,defined('MYSQLI_USE_RESULT')?MYSQLI_USE_RESULT:1);
        if($result===false)throw new Exception('Не удалось прочитать данные '.$table.': '.$this->db->error);
        $prefix='INSERT INTO '.UMEUtil::sqlIdentifier($table).' ('.implode(',',$quoted).') VALUES'."\n";
        $rows=array();$bytes=strlen($prefix)+2;
        while($row=$result->fetch_assoc()){
            $parts=array();foreach($cols as $c)$parts[]=$this->sqlValue(array_key_exists($c,$row)?$row[$c]:null);
            $encoded='('.implode(',',$parts).')';$candidateBytes=strlen($encoded)+($rows?2:0);
            if($rows&&$bytes+$candidateBytes>$this->insertTargetBytes){$this->flushInsert($fh,$prefix,$rows);$rows=array();$bytes=strlen($prefix)+2;}
            $rows[]=$encoded;$bytes+=$candidateBytes;
        }
        $result->free();if($rows)$this->flushInsert($fh,$prefix,$rows);$this->write($fh,"\n");
    }

    private function flushInsert($fh,$prefix,$rows){$sql=$prefix.implode(",\n",$rows);$this->writeStatement($fh,$sql);}
    private function sqlValue($v){if($v===null)return 'NULL';return "'".$this->db->real_escape_string((string)$v)."'";}

    private function stripDefiner($sql){
        $sql=preg_replace('~\s+DEFINER\s*=\s*(?:`(?:``|[^`])*`|[^\s@]+)\s*@\s*(?:`(?:``|[^`])*`|[^\s]+)~i',' ',$sql);
        return trim($sql);
    }

    private function writeStatement($fh,$sql){
        $sql=rtrim(trim((string)$sql),';');if($sql==='')return;$payload=$sql.";\n";$this->maxStatementBytes=max($this->maxStatementBytes,strlen($payload));$this->write($fh,$payload);
    }

    private function writeProgrammableObject($fh,$sql,$sqlMode){
        if((string)$sqlMode!=='')$this->writeStatement($fh,'SET SESSION SQL_MODE='.$this->quote($sqlMode));
        $this->writeDelimitedStatement($fh,$sql);
        if((string)$sqlMode!=='')$this->writeStatement($fh,"SET SESSION SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
    }

    private function writeDelimitedStatement($fh,$sql){
        $sql=rtrim(trim((string)$sql),';');if($sql==='')return;$delimiter='$$UME_352$$';$i=0;while(strpos($sql,$delimiter)!==false){$i++;$delimiter='$$UME_352_'.$i.'$$';}
        $payload="DELIMITER ".$delimiter."\n".$sql.$delimiter."\nDELIMITER ;\n";$this->maxStatementBytes=max($this->maxStatementBytes,strlen($sql)+strlen($delimiter));$this->write($fh,$payload);
    }

    private function write($fh,$data){
        $len=strlen($data);$off=0;while($off<$len){$n=@fwrite($fh,substr($data,$off));if($n===false||$n===0)throw new Exception('Не удалось полностью записать SQL-дамп: возможно, закончилось место на диске.');$off+=$n;}
    }
    private function quote($v){return "'".$this->db->real_escape_string((string)$v)."'";}
    private function query($sql){$r=$this->db->query($sql);if($r===false)throw new Exception('Ошибка MySQL при создании дампа: '.$this->db->error);return $r;}
}
