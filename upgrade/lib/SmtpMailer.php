<?php
class UMESmtpMailer {
    private $settings;
    private $fp;
    private $transcript=array();

    public function __construct($settings){
        $this->settings=is_array($settings)?$settings:array();
        $this->fp=null;
    }

    private function cleanHeader($value){
        $value=trim((string)$value);
        if(strpos($value,"\r")!==false||strpos($value,"\n")!==false)throw new Exception('Недопустимый перенос строки в SMTP-заголовке.');
        return $value;
    }

    private function host(){
        $host=trim(isset($this->settings['host'])?(string)$this->settings['host']:'');
        if($host===''||strlen($host)>255||preg_match('/[\s\r\n\/]/',$host))throw new Exception('Некорректный SMTP-сервер.');
        return $host;
    }

    private function port(){
        $port=isset($this->settings['port'])?(int)$this->settings['port']:587;
        if($port<1||$port>65535)throw new Exception('Некорректный SMTP-порт.');
        return $port;
    }

    private function security(){
        $security=isset($this->settings['security'])?strtolower((string)$this->settings['security']):'tls';
        if(!in_array($security,array('tls','ssl','none'),true))throw new Exception('Неизвестный режим SMTP-шифрования.');
        return $security;
    }

    private function timeout(){
        $timeout=isset($this->settings['timeout'])?(int)$this->settings['timeout']:15;
        return max(5,min(45,$timeout));
    }

    private function logLine($line){
        $line=preg_replace('/[\r\n]+/',' ',trim((string)$line));
        if($line!==''){$this->transcript[]=$line;if(count($this->transcript)>20)array_shift($this->transcript);}
    }

    private function writeAll($data){
        if(!is_resource($this->fp))throw new Exception('SMTP-соединение не установлено.');
        $data=(string)$data;$len=strlen($data);$offset=0;
        while($offset<$len){$written=@fwrite($this->fp,substr($data,$offset));if($written===false||$written===0)throw new Exception('SMTP-соединение оборвалось во время отправки данных.');$offset+=(int)$written;}
    }

    private function readReply(){
        if(!is_resource($this->fp))throw new Exception('SMTP-соединение закрыто.');
        $lines=array();$code=0;
        while(!feof($this->fp)){
            $line=fgets($this->fp,4096);
            if($line===false)break;
            $line=rtrim($line,"\r\n");
            $lines[]=$line;$this->logLine('S: '.$line);
            if(preg_match('/^(\d{3})([ -])/', $line,$m)){
                $code=(int)$m[1];
                if($m[2]===' ')break;
            }
            if(count($lines)>80)break;
        }
        if(!$lines)throw new Exception('SMTP-сервер закрыл соединение без ответа.');
        return array('code'=>$code,'lines'=>$lines,'text'=>implode("\n",$lines));
    }

    private function expect($reply,$allowed,$context){
        if(!in_array((int)$reply['code'],$allowed,true)){
            $last=isset($reply['lines'][count($reply['lines'])-1])?$reply['lines'][count($reply['lines'])-1]:'неизвестная ошибка';
            throw new Exception($context.': '.$last);
        }
        return $reply;
    }

    private function command($command,$allowed,$context,$sensitive){
        if(!is_resource($this->fp))throw new Exception('SMTP-соединение не установлено.');
        $this->logLine('C: '.($sensitive?'[скрыто]':$command));
        $this->writeAll($command."\r\n");
        return $this->expect($this->readReply(),$allowed,$context);
    }

    private function ehlo(){
        $name='localhost';
        if(!empty($_SERVER['SERVER_NAME']))$name=preg_replace('/[^A-Za-z0-9.-]/','',(string)$_SERVER['SERVER_NAME']);
        if($name==='')$name='localhost';
        try{return $this->command('EHLO '.$name,array(250),'SMTP EHLO',false);}catch(Exception $e){return $this->command('HELO '.$name,array(250),'SMTP HELO',false);}
    }

    private function connect(){
        $host=$this->host();$port=$this->port();$security=$this->security();$timeout=$this->timeout();
        $ctx=stream_context_create(array('ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'peer_name'=>$host,'SNI_enabled'=>true)));
        $remote=($security==='ssl'?'ssl://':'tcp://').$host.':'.$port;
        $errno=0;$errstr='';
        $this->fp=@stream_socket_client($remote,$errno,$errstr,$timeout,STREAM_CLIENT_CONNECT,$ctx);
        if(!$this->fp)throw new Exception('Не удалось подключиться к SMTP '.$host.':'.$port.($errstr!==''?' — '.$errstr:''));
        stream_set_timeout($this->fp,$timeout);
        $this->expect($this->readReply(),array(220),'SMTP приветствие');
        $ehlo=$this->ehlo();
        if($security==='tls'){
            $supports=false;foreach($ehlo['lines'] as $line)if(stripos($line,'STARTTLS')!==false){$supports=true;break;}
            if(!$supports)throw new Exception('SMTP-сервер не объявил поддержку STARTTLS.');
            $this->command('STARTTLS',array(220),'SMTP STARTTLS',false);
            $method=defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')?STREAM_CRYPTO_METHOD_TLS_CLIENT:STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
            if(!@stream_socket_enable_crypto($this->fp,true,$method))throw new Exception('Не удалось включить TLS для SMTP-соединения.');
            $ehlo=$this->ehlo();
        }
        $username=trim(isset($this->settings['username'])?(string)$this->settings['username']:'');
        $password=isset($this->settings['password'])?(string)$this->settings['password']:'';
        if($username!==''||$password!==''){
            if($username===''||$password==='')throw new Exception('Для SMTP AUTH нужны и логин, и пароль.');
            $caps=strtoupper(implode(' ',isset($ehlo['lines'])?$ehlo['lines']:array()));
            if(strpos($caps,'AUTH')!==false&&strpos($caps,'LOGIN')!==false){
                $this->command('AUTH LOGIN',array(334),'SMTP AUTH LOGIN',false);
                $this->command(base64_encode($username),array(334),'SMTP AUTH username',true);
                $this->command(base64_encode($password),array(235),'SMTP AUTH password',true);
            }elseif(strpos($caps,'AUTH')!==false&&strpos($caps,'PLAIN')!==false){
                $this->command('AUTH PLAIN '.base64_encode("\0".$username."\0".$password),array(235),'SMTP AUTH PLAIN',true);
            }else throw new Exception('SMTP-сервер не объявил поддерживаемый AUTH LOGIN/PLAIN.');
        }
        return array('host'=>$host,'port'=>$port,'security'=>$security,'authenticated'=>$username!=='');
    }

    private function close(){
        if(is_resource($this->fp)){
            try{$this->command('QUIT',array(221,250),'SMTP QUIT',false);}catch(Exception $e){}
            @fclose($this->fp);
        }
        $this->fp=null;
    }

    public function testConnection(){
        try{$info=$this->connect();$info['ok']=true;$info['transcript']=$this->transcript;return $info;}finally{$this->close();}
    }

    private function encodeSubject($subject){
        $subject=$this->cleanHeader($subject);
        return '=?UTF-8?B?'.base64_encode($subject).'?=';
    }

    private function addressHeader($email,$name){
        $email=$this->cleanHeader($email);if(filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Некорректный email отправителя.');
        $name=$this->cleanHeader($name);if($name==='')return '<'.$email.'>';
        return '=?UTF-8?B?'.base64_encode($name).'?= <'.$email.'>';
    }

    private function normalizeBody($body){
        $body=str_replace(array("\r\n","\r"),"\n",(string)$body);
        $body=str_replace("\n","\r\n",$body);
        $lines=explode("\r\n",$body);
        foreach($lines as &$line)if(isset($line[0])&&$line[0]==='.')$line='.'.$line;
        unset($line);
        return implode("\r\n",$lines);
    }

    public function send($to,$subject,$body){
        $to=strtolower(trim((string)$to));if($to===''||strlen($to)>254||filter_var($to,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Некорректный email получателя.');
        $from=strtolower(trim(isset($this->settings['from_email'])?(string)$this->settings['from_email']:''));if($from===''||filter_var($from,FILTER_VALIDATE_EMAIL)===false)throw new Exception('Некорректный email отправителя SMTP.');
        $fromName=isset($this->settings['from_name'])?(string)$this->settings['from_name']:'Upgrade Manager';
        try{
            $info=$this->connect();
            $this->command('MAIL FROM:<'.$from.'>',array(250),'SMTP MAIL FROM',false);
            $this->command('RCPT TO:<'.$to.'>',array(250,251),'SMTP RCPT TO',false);
            $this->command('DATA',array(354),'SMTP DATA',false);
            $headers=array(
                'Date: '.date('r'),
                'From: '.$this->addressHeader($from,$fromName),
                'To: <'.$to.'>',
                'Subject: '.$this->encodeSubject($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                'Message-ID: <'.substr(hash('sha256',uniqid('',true).$to),0,32).'@'.preg_replace('/[^A-Za-z0-9.-]/','',$this->host()).'>'
            );
            $data=implode("\r\n",$headers)."\r\n\r\n".$this->normalizeBody($body)."\r\n.";
            $this->logLine('C: [DATA '.strlen($data).' bytes]');
            $this->writeAll($data."\r\n");
            $this->expect($this->readReply(),array(250),'SMTP отправка письма');
            return array('ok'=>true,'host'=>$info['host'],'port'=>$info['port'],'security'=>$info['security'],'authenticated'=>$info['authenticated']);
        }finally{$this->close();}
    }
}
