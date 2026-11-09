<?php
class UMEDatabase {
    private $conn; private $prefix; private $engine;
    public function __construct($siteRoot,$cfg=null){
        if(!class_exists('UMECmsAdapter'))throw new Exception('CMS adapter is not loaded.');
        if(!is_array($cfg)){ $cms=UMECmsAdapter::detect($siteRoot);$cfg=UMECmsAdapter::databaseConfig($siteRoot,$cms['id'],true); }
        $this->engine=isset($cfg['engine'])?$cfg['engine']:'unknown';
        if($this->engine!=='mysql')throw new Exception('База данных '.$this->engine.' обнаружена, но эта версия Upgrade Manager выполняет SQL backup/migrations только для MySQL/MariaDB. Файловые операции доступны.');
        if(!extension_loaded('mysqli'))throw new Exception('Расширение mysqli недоступно. Файловые операции доступны, резервирование БД отключено.');
        $port=isset($cfg['port'])?(int)$cfg['port']:3306;$socket=isset($cfg['socket'])?$cfg['socket']:null;
        $this->conn=@new mysqli($cfg['host'],$cfg['user'],$cfg['password'],$cfg['database'],$port,$socket);
        if($this->conn->connect_errno)throw new Exception('Ошибка подключения к БД: '.$this->conn->connect_error);
        if(method_exists($this->conn,'set_charset')){@$this->conn->set_charset('utf8mb4');if($this->conn->errno)@$this->conn->set_charset('utf8');}
        $this->prefix=isset($cfg['prefix'])?(string)$cfg['prefix']:'';
    }
    public function prefix(){return $this->prefix;}
    public function engine(){return $this->engine;}
    public function connection(){return $this->conn;}
    public function query($sql){$r=$this->conn->query($sql);if($r===false)throw new Exception('Ошибка MySQL: '.$this->conn->error);return $r;}
    public function escape($v){return $this->conn->real_escape_string($v);}
    public function listTables(){
        $out=array();$r=$this->query('SHOW TABLE STATUS');
        while($row=$r->fetch_assoc())$out[]=array('name'=>$row['Name'],'rows'=>(int)$row['Rows'],'engine'=>$row['Engine']);return $out;
    }
    public function tableExists($table){$safe=$this->escape($table);$r=$this->query("SHOW TABLES LIKE '".$safe."'");return $r->num_rows>0;}
    public function createSql($table){$r=$this->query('SHOW CREATE TABLE '.UMEUtil::sqlIdentifier($table));$row=$r->fetch_assoc();return $row['Create Table'];}
    public function rowsChunk($table,$offset,$limit){$r=$this->query('SELECT * FROM '.UMEUtil::sqlIdentifier($table).' LIMIT '.(int)$offset.','.(int)$limit);$rows=array();while($row=$r->fetch_assoc())$rows[]=$row;return $rows;}
    public function rowCount($table){$r=$this->query('SELECT COUNT(*) c FROM '.UMEUtil::sqlIdentifier($table));$row=$r->fetch_assoc();return (int)$row['c'];}
    public function insertSql($table,$rows){
        if(!$rows)return '';$cols=array_keys($rows[0]);$quotedCols=array();foreach($cols as $c)$quotedCols[]=UMEUtil::sqlIdentifier($c);
        $vals=array();foreach($rows as $row){$one=array();foreach($cols as $c){$v=$row[$c];$one[]=$v===null?'NULL':"'".$this->escape($v)."'";}$vals[]='('.implode(',',$one).')';}
        return 'INSERT INTO '.UMEUtil::sqlIdentifier($table).' ('.implode(',',$quotedCols).') VALUES'."\n".implode(",\n",$vals).";\n";
    }
    public function executeSqlFile($file){
        $sql=@file_get_contents($file);if($sql===false)throw new Exception('Не удалось прочитать SQL: '.$file);$sql=str_replace('{{DB_PREFIX}}',$this->prefix,$sql);
        if(!$this->conn->multi_query($sql))throw new Exception('Ошибка SQL-миграции: '.$this->conn->error);
        do{if($res=$this->conn->store_result())$res->free();}while($this->conn->more_results()&&$this->conn->next_result());if($this->conn->errno)throw new Exception('Ошибка SQL-миграции: '.$this->conn->error);
    }
}
