<?php
/**
 * Safe, self-contained AI patch-authoring context included in every site backup.
 * This class never copies config.local.php, runtime storage, HMAC keys, or untracked files.
 * PHP 5.6+ compatible, no dependencies beyond core PHP.
 */
class UMEBackupAgentPack {
    public static function rootContextNames(){
        return array('00_START_HERE_FOR_GPT.md','AGENTS.md','PATCH_AUTHORING_RU.md','PATCH_FORMAT_RU.md','AGENT_WORKFLOW_RU.md','PATCH_UNSIGNED_MANIFEST_TEMPLATE.json','PATCH_SIGNATURE_EXAMPLE.json','PATCH_BUILDER.py','upgrade-source-manifest.json');
    }
    public static function sourceSnapshot($upgradeDir){
        $base=realpath($upgradeDir);
        if(!$base||!is_dir($base))throw new Exception('Не удалось прочитать /upgrade/ для AI-контекста бэкапа.');
        $base=rtrim(str_replace('\\','/',$base),'/');
        $hashFile=$base.'/BUILD_SHA256SUMS.txt';
        $raw=@file_get_contents($hashFile);
        if($raw===false||trim($raw)==='')throw new Exception('В /upgrade/ отсутствует BUILD_SHA256SUMS.txt. Нельзя безопасно экспортировать код Upgrade Manager.');
        $lines=preg_split('/\r\n|\r|\n/',trim($raw));
        $files=array();$checksums=array();$totalBytes=0;
        foreach($lines as $line){
            if(!preg_match('/^([a-f0-9]{64})  (.+)$/',$line,$parts))throw new Exception('Повреждён BUILD_SHA256SUMS.txt.');
            $expected=$parts[1];$rel=$parts[2];$normalized=UMEUtil::normalizeRelativePath($rel);
            if($normalized===false||$normalized!==$rel||isset($files[$rel]))throw new Exception('Недопустимый путь в BUILD_SHA256SUMS.txt: '.$rel);
            // Even a tampered manifest must not be able to export private files.
            $lower=strtolower($rel);$segments=explode('/',$lower);$baseName=strtolower(basename($rel));
            $forbiddenParts=array('.data','.git','.svn','oauth','backups','public-backups','rollbacks','temp','tmp','logs','sessions','uploads','locks','quarantine','api-tasks','api-uploads','api-drafts');
            foreach($segments as $segment)if(in_array($segment,$forbiddenParts,true)||strpos($segment,'.data-')===0)throw new Exception('Закрытый путь в BUILD_SHA256SUMS.txt: '.$rel);
            if(in_array($baseName,array('config.local.php','.env','auth.json','composer.auth.json'),true)||preg_match('/\.(?:pem|key|p12|pfx)$/i',$rel)||strpos($baseName,'.env.')===0)throw new Exception('Нельзя включать секреты в AI-бэкап: '.$rel);
            $abs=$base.'/'.$rel;$real=realpath($abs);
            if(!is_file($abs)||is_link($abs)||$real===false||strpos(str_replace('\\','/',$real),$base.'/')!==0)throw new Exception('Системный файл отсутствует или выходит за /upgrade/: '.$rel);
            $bytes=@file_get_contents($abs);
            if($bytes===false||!hash_equals($expected,hash('sha256',$bytes)))throw new Exception('Файл /upgrade/'.$rel.' изменён после сборки релиза. Обновите BUILD_SHA256SUMS.txt через выпуск релиза; бэкап с неверенным кодом запрещён.');
            $files[$rel]=$bytes;$checksums[]=array('path'=>'upgrade/'.$rel,'size'=>strlen($bytes),'sha256'=>$expected);
            $totalBytes+=strlen($bytes);
            if(count($files)>300||$totalBytes>32*1024*1024)throw new Exception('Исходники Upgrade Manager слишком велики для безопасного включения в backup.');
        }
        foreach(array('index.php','bootstrap.php','release.json','lib/Manager.php','lib/SelfUpdater.php','PATCH_FORMAT_RU.md','PATCH_AUTHORING_RU.md','PATCH_BUILDER.py') as $required)if(!isset($files[$required]))throw new Exception('В /upgrade/ отсутствует обязательный файл: '.$required);
        $files['BUILD_SHA256SUMS.txt']=$raw;
        $checksums[]=array('path'=>'upgrade/BUILD_SHA256SUMS.txt','size'=>strlen($raw),'sha256'=>hash('sha256',$raw));
        $release=json_decode($files['release.json'],true);
        if(!is_array($release)||empty($release['version']))throw new Exception('В исходниках /upgrade/ повреждён release.json.');
        return array('files'=>$files,'checksums'=>$checksums,'version'=>(string)$release['version'],'count'=>count($files),'sha256'=>hash('sha256',$raw));
    }
    public static function addSourceToZip($zip,$snapshot){
        if(!$zip->addEmptyDir('upgrade'))throw new Exception('Не удалось добавить каталог upgrade/ в ZIP.');
        foreach($snapshot['files'] as $rel=>$content){
            if(!$zip->addFromString('upgrade/'.$rel,$content))throw new Exception('Не удалось включить исходник /upgrade/'.$rel.' в backup.');
        }
    }
    public static function rootFiles($backupManifest,$profile,$snapshot,$publicShare){
        $sid=isset($backupManifest['site_id'])?(string)$backupManifest['site_id']:'';
        $project=isset($backupManifest['project'])?(string)$backupManifest['project']:'';
        $domain=isset($backupManifest['domain'])?(string)$backupManifest['domain']:'';
        $siteVersion=isset($backupManifest['site_version'])?(string)$backupManifest['site_version']:'';
        $next=isset($backupManifest['next_patch_version'])?(string)$backupManifest['next_patch_version']:'';
        $kind=$publicShare?'очищенная публичная копия':'приватная резервная копия';
        $guide="# ПЕРВЫМ ДЕЛОМ ПРОЧИТАЙТЕ ЭТО — инструкция GPT по патчам Upgrade Manager\n\n";
        $guide.="Этот ZIP — **".$kind." САЙТА**, не готовый патч. Здесь есть код сайта в `site-files.zip` и безопасные исходники самого Upgrade Manager в каталоге `upgrade/`. Код в `upgrade/` нужен **только как справочник и для проверки формата**, его нельзя менять обычным патчем сайта.\n\n";
        $guide.="## Контекст ИМЕННО ЭТОГО бэкапа (не угадывать!)\n\n";
        $guide.="- `site_id`: `".$sid."`\n- `project`: `".$project."`\n- `domain`: `".$domain."`\n- `base_site_version`: `".$siteVersion."`\n- `version` нового патча: **`".$next."`**\n- формат: **`external-upgrade-patch/1.3`**\n- Upgrade Manager: **`".$snapshot['version']."`**\n\n";
        $guide.="Перед сборкой обязательно сопоставьте эти поля с `manifest.json` и `patch-profile.json` из КОРНЯ этого бэкапа. Версию `".$next."` разрешено использовать только для сайта с текущей версией `".$siteVersion."` — если на сервере уже была установка, запросите новый backup.\n\n";
        $guide.="## ОБЯЗАТЕЛЬНЫЙ порядок действий для GPT / разработчика\n\n";
        $guide.="1. Прочитать этот файл, `PATCH_AUTHORING_RU.md`, `PATCH_FORMAT_RU.md`, `patch-profile.json`, `manifest.json`. При вопросах смотреть действующий код `upgrade/lib/Manager.php` (`buildPatchPayload`, `sealManualPatchIfNeeded`, `validatePatchFile`), `upgrade/lib/ApiTasks.php`.\n";
        $guide.="2. Изменения сайта положить в папку `changes/` с путями от корня сайта; **никогда не включать `upgrade/` в payload**, не передавать конфиги с паролями.\n";
        $guide.="3. Формировать патч `1.3` со строго указанными `site_id`, `project`, `domain`, `base_site_version` и `version`. Для каждого `files[]` и `migrations[]` указать SHA-256 реальных байтов файла в ZIP; `migrations.tables` содержит все затрагиваемые таблицы.\n";
        $guide.="4. **Ручная загрузка через веб-интерфейс Upgrade Manager:** в `manifest.json` итогового патча должны одновременно **отсутствовать** поля `payload_hash` и `signature`. Это НЕ ошибка: именно этот режим официально реализован в 3.7.0+! После авторизованной загрузки сервер проверяет файлы, вычисляет `payload_hash`, HMAC-SHA256 `signature` СВОИМ секретом и повторно проверяет готовый подписанный патч. НЕЛЬЗЯ выдумывать подписи, ставить нули или оставлять одно поле без второго.\n";
        $guide.="5. **MCP/API:** используйте `begin_patch_draft` → `put_patch_file` / `put_patch_migration` → `finalize_patch_draft`; подпись и ZIP формируются на сервере. Неподписанный ручной ZIP **нельзя** отправлять через устаревшую API-чанковую загрузку.\n";
        $guide.="6. Если требуется **полностью подписанный ZIP ещё ДО передачи пользователю**, это возможно только при доступе к серверной авторизации/MCP. В офлайн-бэкапе НЕТ `hmac_key`, и из кода/подписей восстановить его нельзя; честно сообщите об ограничении. Не просите владельца присылать секретный ключ.\n";
        $guide.="7. Проверить ZIP, наличие `manifest.json` в корне, пути `payload/...`, все SHA-256, схему миграций, версии и совместимость. Отдать готовый `.zip`, назвать его **патчем для РУЧНОЙ загрузки с АВТОПОДПИСЬЮ сервером**.\n\n";
        $guide.="## Готовая сборка без ручных ошибок\n\n";
        $guide.="Файл `PATCH_BUILDER.py` лежит прямо в корне этого backup, работает на Python 3 и не требует библиотек. Пример:\n\n";
        $guide.="```bash\npython PATCH_BUILDER.py --backup site-backup.zip --changes changes --name \"Исправление сайта\" --out patch.zip\n```\n\n";
        $guide.="Для пустого тестового патча параметр `--changes` можно опустить. Списки удаления: `--delete path/to/file.php`; SQL: `--migrations migrations.json` (JSON-массив с `id`, `sql_file`, `tables`).\n\n";
        $guide.="**Этот скрипт создаёт корректный НЕПОДПИСАННЫЙ ZIP для ручной загрузки**. Наличие `payload_hash` без настоящей `signature` делает пакет НЕвалидным, поэтому перед сервером эти два поля отсутствуют совместно.\n\n";
        $guide.="## Состав и безопасность\n\n";
        $guide.="- `upgrade/` — ".$snapshot['count']." проверенных системных исходников (с `BUILD_SHA256SUMS.txt`), без `config.local.php`, OAuth-секретов, runtime и файлов хранилища.\n";
        $guide.="- `upgrade-source-manifest.json` — список SHA-256 включённого кода Upgrade Manager.\n";
        $guide.="- `site-files.zip` — файлы сайта; `database.sql` — БД (если в профиле бэкапа она была выбрана/доступна).\n";
        $guide.="- `PATCH_FORMAT_RU.md` — точная формула расчёта `payload_hash` и HMAC-подписи, порядок файлов, SQL и actions.\n";
        $guide.="- `PATCH_UNSIGNED_MANIFEST_TEMPLATE.json` — рабочий шаблон без фиктивной подписи.\n\n";
        $guide.="**Важно:** папка `upgrade/` в корне backup НЕ является частью `site-files.zip` и НЕ восстанавливается вместе с файлами сайта. Это справочные исходники; публичная копия не содержит закрытых данных менеджера.\n";
        $agent="# Инструкции AI-агенту по этому бэкапу\n\nПеред любыми действиями ОБЯЗАТЕЛЬНО прочитать `00_START_HERE_FOR_GPT.md` из корня архива и `PATCH_FORMAT_RU.md`. Не использовать память о других версиях Upgrade Manager. Не придумывать HMAC-подпись; для ручного ZIP использовать серверную автоподпись по инструкции. Код `upgrade/` — справочный, его обновление обычным патчем запрещено.\n";
        $sourceManifest=array('format'=>'ume-ai-source-context/1.0','upgrade_manager_version'=>$snapshot['version'],'safe_source'=>true,'secret_files_included'=>false,'source_file_count'=>$snapshot['count'],'build_sha256s_sha256'=>$snapshot['sha256'],'files'=>$snapshot['checksums']);
        $sourceJson=json_encode($sourceManifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
        if($sourceJson===false)throw new Exception('Не удалось сформировать хэши исходников Upgrade Manager.');
        $result=array('00_START_HERE_FOR_GPT.md'=>$guide,'AGENTS.md'=>$agent,'upgrade-source-manifest.json'=>$sourceJson."\n");
        foreach(array('PATCH_AUTHORING_RU.md','PATCH_FORMAT_RU.md','AGENT_WORKFLOW_RU.md','PATCH_UNSIGNED_MANIFEST_TEMPLATE.json','PATCH_SIGNATURE_EXAMPLE.json','PATCH_BUILDER.py') as $name){
            if(!isset($snapshot['files'][$name]))throw new Exception('В составе Upgrade Manager отсутствует документация: '.$name);
            $result[$name]=$snapshot['files'][$name];
        }
        return $result;
    }
    public static function addRootContext($zip,$files){
        foreach($files as $name=>$content)if(!$zip->addFromString($name,$content))throw new Exception('Не удалось добавить файл AI-контекста в backup: '.$name);
    }
    public static function selfBackupGuide($version,$private){
        $text="# Upgrade Manager ".$version." — исходный код центрального репозитория\n\n";
        if($private)$text.="**ВНИМАНИЕ: ПРИВАТНЫЙ ПОЛНЫЙ SELF-BACKUP. Он может содержать config.local.php, HMAC-ключи, настройки и секреты. НЕ ПЕРЕДАВАЙТЕ ЭТОТ АРХИВ GPT ИЛИ ПОСТОРОННИМ!** Для работы с AI используйте функцию безопасного экспорта исходников.\n\n";
        else $text.="Этот архив безопасного экспорта содержит только проверенные исходники и не содержит config.local.php, ключей и runtime-данных.\n\n";
        $text.="1. Для обновления **самого** Upgrade Manager нужен ПОЛНЫЙ FTP ZIP с корневым каталогом `upgrade/`, новым `upgrade/release.json` и пересчитанным `upgrade/BUILD_SHA256SUMS.txt`. Это НЕ `external-upgrade-patch/1.3`.\n";
        $text.="2. Для патчей **клиентских сайтов**, управляемых Upgrade Manager, читайте `upgrade/PATCH_AUTHORING_RU.md`, `upgrade/PATCH_FORMAT_RU.md`, `upgrade/PATCH_UNSIGNED_MANIFEST_TEMPLATE.json`, `upgrade/lib/Manager.php`.\n";
        $text.="3. Ключ `upgrade/config.local.php` не копируйте в FTP-обновление; секреты никогда не передаются AI. Ручные патчи сайтов 1.3 подписываются самим менеджером при авторизованной загрузке или через MCP.\n";
        return $text;
    }
}
