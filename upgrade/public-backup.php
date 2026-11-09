<?php
require __DIR__.'/bootstrap.php';

function umePbEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function umePbEscapePreview($value) {
    $flags = ENT_QUOTES;
    if (defined('ENT_SUBSTITUTE')) $flags |= ENT_SUBSTITUTE;
    return htmlspecialchars((string)$value, $flags, 'UTF-8');
}

function umePbBaseUrl() {
    $scheme = UMEUtil::isHttps() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)$_SERVER['HTTP_HOST']) : '';
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '/upgrade/public-backup.php';
    return ($host !== '' ? $scheme.'://'.$host : '').$script;
}

function umePbLink($id, $extra) {
    $params = array('id' => (string)$id);
    if (is_array($extra)) foreach ($extra as $key => $value) {
        if ($value === null || $value === '') continue;
        $params[$key] = $value;
    }
    $query = defined('PHP_QUERY_RFC3986') ? http_build_query($params, '', '&', PHP_QUERY_RFC3986) : http_build_query($params);
    return umePbBaseUrl().'?'.$query;
}

function umePbHeaders($contentType) {
    header('Content-Type: '.$contentType);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

function umePbDisposition($mode, $name) {
    $name = basename(str_replace('\\', '/', (string)$name));
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    if ($ascii === '') $ascii = 'backup-file';
    return $mode.'; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name);
}

function umePbRawContentType($path) {
    $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
    $images = array(
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon', 'avif' => 'image/avif'
    );
    if (isset($images[$ext])) return $images[$ext];
    $text = array('php','php3','php4','php5','php7','php8','phtml','inc','module','theme','js','mjs','cjs','jsx','ts','tsx','css','scss','sass','less','html','htm','xhtml','svg','twig','tpl','latte','volt','json','jsonl','ndjson','xml','xsl','xslt','yml','yaml','md','markdown','txt','sql','ini','conf','config','cnf','env','dist','example','sh','bash','zsh','fish','ps1','bat','cmd','py','rb','pl','pm','java','kt','kts','go','rs','c','h','cc','cpp','cxx','hpp','hxx','cs','vb','swift','vue','svelte','graphql','gql','csv','tsv','properties','toml','lock','log','po','pot');
    if (in_array($ext, $text, true)) return 'text/plain; charset=utf-8';
    return 'application/octet-stream';
}

function umePbCleanupTemp($row) {
    if (is_array($row) && !empty($row['file']) && is_file($row['file'])) @unlink($row['file']);
}

$id = isset($_GET['id']) ? (string)$_GET['id'] : '';

try {
    if (!empty($_GET['download'])) {
        $file = $manager->publicBackupDownload($id);
        umePbHeaders('application/zip');
        header('Content-Disposition: '.umePbDisposition('attachment', basename($file)));
        header('Content-Length: '.filesize($file));
        readfile($file);
        exit;
    }

    if (!empty($_GET['raw']) && isset($_GET['entry']) && (string)$_GET['entry'] !== '') {
        $source = isset($_GET['source']) && (string)$_GET['source'] === 'backup' ? 'backup' : 'site';
        $entry = (string)$_GET['entry'];
        $raw = $source === 'backup' ? $manager->publicBackupOuterRawTemp($id, $entry) : $manager->publicBackupSiteRawTemp($id, $entry);
        try {
            $contentType = umePbRawContentType($raw['path']);
            umePbHeaders($contentType);
            $inline = strpos($contentType, 'image/') === 0 || strpos($contentType, 'text/plain') === 0;
            header('Content-Disposition: '.umePbDisposition($inline ? 'inline' : 'attachment', $raw['path']));
            header('Content-Length: '.filesize($raw['file']));
            readfile($raw['file']);
        } finally {
            umePbCleanupTemp($raw);
        }
        exit;
    }

    if (isset($_GET['entry']) && (string)$_GET['entry'] !== '') {
        $source = isset($_GET['source']) && (string)$_GET['source'] === 'backup' ? 'backup' : 'site';
        $entry = (string)$_GET['entry'];
        $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
        $limit = 262144;
        $chunk = $source === 'backup' ? $manager->publicBackupOuterTextChunk($id, $entry, $offset, $limit) : $manager->publicBackupSiteTextChunk($id, $entry, $offset, $limit);
        umePbHeaders('text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        $backUrl = umePbLink($id, array());
        $downloadUrl = umePbLink($id, array('download' => '1'));
        $rawUrl = umePbLink($id, array('source' => $source, 'entry' => $entry, 'raw' => '1'));
        $prevOffset = $chunk['offset'] > 0 ? max(0, $chunk['offset'] - $limit) : null;
        $nextOffset = $chunk['next_offset'];
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.umePbEscape($chunk['path']).' — backup preview</title><style>body{margin:0;background:#f4f6fb;color:#182033;font:15px/1.55 -apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif}.wrap{max-width:1180px;margin:0 auto;padding:28px}.card{background:#fff;border:1px solid #dfe4ee;border-radius:16px;padding:20px;box-shadow:0 8px 30px rgba(31,45,75,.07)}a{color:#3568d4;text-decoration:none}a:hover{text-decoration:underline}.top{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:16px}.meta{color:#667085;margin:8px 0 16px}pre{white-space:pre-wrap;word-break:break-word;background:#111827;color:#e5e7eb;padding:18px;border-radius:12px;overflow:auto;font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}.pager{display:flex;gap:14px;flex-wrap:wrap;margin-top:16px}</style></head><body><div class="wrap"><div class="card"><div class="top"><a href="'.umePbEscape($backUrl).'">← К списку файлов</a><a href="'.umePbEscape($rawUrl).'">Открыть как файл</a><a href="'.umePbEscape($downloadUrl).'">Скачать публичный ZIP</a></div><h1>'.umePbEscape($chunk['path']).'</h1><div class="meta">Источник: '.($chunk['source']==='backup'?'корень backup ZIP':'site-files.zip').' · байты '.(int)$chunk['offset'].'–'.max((int)$chunk['offset'],(int)$chunk['offset']+strlen($chunk['content'])-1).' из '.(int)$chunk['size'].'</div><pre>'.umePbEscapePreview($chunk['content']).'</pre><div class="pager">';
        if ($prevOffset !== null) echo '<a href="'.umePbEscape(umePbLink($id,array('source'=>$source,'entry'=>$entry,'offset'=>$prevOffset))).'">← Предыдущий фрагмент</a>';
        if ($nextOffset !== null) echo '<a href="'.umePbEscape(umePbLink($id,array('source'=>$source,'entry'=>$entry,'offset'=>$nextOffset))).'">Следующий фрагмент →</a>';
        echo '</div></div></div></body></html>';
        exit;
    }

    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $query = isset($_GET['q']) ? (string)$_GET['q'] : '';
    $info = $manager->publicBackupShareInfo($id, $query, $page, 250);
    $downloadUrl = umePbLink($id, array('download' => '1'));

    if (isset($_GET['format']) && (string)$_GET['format'] === 'json') {
        $result = $info;
        $result['public_access'] = 'unauthenticated';
        $result['download_url'] = $downloadUrl;
        foreach ($result['outer_entries'] as &$outerRow) {
            if (!empty($outerRow['previewable'])) $outerRow['preview_url'] = umePbLink($id,array('source'=>'backup','entry'=>$outerRow['path']));
            $outerRow['raw_url'] = umePbLink($id,array('source'=>'backup','entry'=>$outerRow['path'],'raw'=>'1'));
        }
        unset($outerRow);
        foreach ($result['files'] as &$siteRow) {
            if (!empty($siteRow['previewable'])) $siteRow['preview_url'] = umePbLink($id,array('source'=>'site','entry'=>$siteRow['path']));
            $siteRow['raw_url'] = umePbLink($id,array('source'=>'site','entry'=>$siteRow['path'],'raw'=>'1'));
        }
        unset($siteRow);
        $result['page_url'] = umePbLink($id,array('page'=>$info['page'],'q'=>$info['query']));
        if ($info['page'] < $info['pages']) $result['next_page_url'] = umePbLink($id,array('page'=>$info['page']+1,'q'=>$info['query'],'format'=>'json'));
        if ($info['page'] > 1) $result['previous_page_url'] = umePbLink($id,array('page'=>$info['page']-1,'q'=>$info['query'],'format'=>'json'));
        umePbHeaders('application/json; charset=utf-8');
        echo json_encode(array('ok'=>true,'backup'=>$result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    umePbHeaders('text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    $jsonUrl = umePbLink($id,array('page'=>$info['page'],'q'=>$info['query'],'format'=>'json'));
    $manifest = isset($info['manifest']) && is_array($info['manifest']) ? $info['manifest'] : array();
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>'.umePbEscape($info['name']).' — публичный backup</title><style>body{margin:0;background:#f4f6fb;color:#182033;font:15px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,Arial,sans-serif}.wrap{max-width:1180px;margin:0 auto;padding:28px}.card{background:#fff;border:1px solid #dfe4ee;border-radius:16px;padding:20px;box-shadow:0 8px 30px rgba(31,45,75,.07);margin-bottom:18px}.lead{font-size:16px;color:#475467}.actions{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}.button{display:inline-block;padding:10px 14px;border-radius:10px;background:#315fcf;color:#fff;text-decoration:none}.button.secondary{background:#eef2ff;color:#2f55b5}.meta{display:flex;gap:18px;flex-wrap:wrap;color:#667085}.warning{background:#fff8e6;border:1px solid #f5d88b;border-radius:12px;padding:12px 14px;margin-top:14px}.search{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}.search input{min-width:280px;flex:1;padding:10px 12px;border:1px solid #cfd6e4;border-radius:9px}.search button{padding:10px 14px;border:0;border-radius:9px;background:#273c75;color:#fff}table{width:100%;border-collapse:collapse}th,td{text-align:left;border-bottom:1px solid #e7eaf0;padding:10px 8px;vertical-align:top}th{font-size:12px;text-transform:uppercase;color:#667085}.path{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;word-break:break-all}.small{font-size:13px;color:#667085}.pager{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}a{color:#315fcf}.tag{display:inline-block;background:#eef2ff;color:#344eaa;border-radius:999px;padding:2px 9px;font-size:12px}</style></head><body><div class="wrap"><div class="card"><h1>Публичная очищенная копия</h1><p class="lead">Эта страница предназначена в том числе для ChatGPT. Публичная копия создаётся отдельно от приватного recovery-backup: секретные/runtime-файлы исключаются, а БД публикуется только как структура без данных.</p><div class="actions"><a class="button" href="'.umePbEscape($downloadUrl).'">Скачать публичный ZIP</a><a class="button secondary" href="'.umePbEscape($jsonUrl).'">JSON-индекс</a></div><div class="meta"><span><strong>'.umePbEscape($info['name']).'</strong></span><span>'.umePbEscape(UMEUtil::formatBytes($info['size'])).'</span><span>'.umePbEscape(date('Y-m-d H:i:s',$info['mtime'])).'</span>';
    if (!empty($manifest['project'])) echo '<span>Проект: '.umePbEscape($manifest['project']).'</span>';
    if (!empty($manifest['domain'])) echo '<span>Домен: '.umePbEscape($manifest['domain']).'</span>';
    echo '</div><div class="warning"><strong>Доступ полностью публичный.</strong> Вход, cookies, OAuth и токены не используются. Любой, кто знает этот URL, может читать и скачать эту очищенную публичную копию. Приватный recovery-backup через этот endpoint не отдаётся.</div></div>';

    echo '<div class="card"><h2>Служебные файлы backup</h2><table><thead><tr><th>Файл</th><th>Размер</th><th></th></tr></thead><tbody>';
    foreach ($info['outer_entries'] as $row) {
        $actions = array();
        if (!empty($row['previewable'])) $actions[] = '<a href="'.umePbEscape(umePbLink($id,array('source'=>'backup','entry'=>$row['path']))).'">Читать</a>';
        $actions[] = '<a href="'.umePbEscape(umePbLink($id,array('source'=>'backup','entry'=>$row['path'],'raw'=>'1'))).'">Файл</a>';
        echo '<tr><td class="path">'.umePbEscape($row['path']).'</td><td>'.umePbEscape(UMEUtil::formatBytes($row['size'])).'</td><td>'.implode(' · ',$actions).'</td></tr>';
    }
    echo '</tbody></table></div>';

    echo '<div class="card"><h2>Файлы сайта <span class="tag">'.(int)$info['site_files_total'].'</span></h2><p class="small">Текстовые исходники можно читать частями, а любой файл — открыть отдельным публичным HTTP-адресом.</p><form class="search" method="get" action="'.umePbEscape(umePbBaseUrl()).'"><input type="hidden" name="id" value="'.umePbEscape($id).'"><input name="q" value="'.umePbEscape($info['query']).'" placeholder="Фильтр по пути, например upgrade/ или catalog/model"><button type="submit">Найти</button></form>';
    if ($info['query'] !== '') echo '<p class="small">Найдено: '.(int)$info['files_total'].' · фильтр: <strong>'.umePbEscape($info['query']).'</strong></p>';
    echo '<table><thead><tr><th>Путь</th><th>Размер</th><th>SHA-256</th><th></th></tr></thead><tbody>';
    if (!$info['files']) echo '<tr><td colspan="4">Файлы не найдены.</td></tr>';
    foreach ($info['files'] as $row) {
        $actions = array();
        if (!empty($row['previewable'])) $actions[] = '<a href="'.umePbEscape(umePbLink($id,array('source'=>'site','entry'=>$row['path']))).'">Читать</a>';
        $actions[] = '<a href="'.umePbEscape(umePbLink($id,array('source'=>'site','entry'=>$row['path'],'raw'=>'1'))).'">Файл</a>';
        $sha = !empty($row['sha256']) ? substr($row['sha256'],0,16).'…' : '—';
        echo '<tr><td class="path">'.umePbEscape($row['path']).'</td><td>'.umePbEscape(UMEUtil::formatBytes($row['size'])).'</td><td class="path small">'.umePbEscape($sha).'</td><td>'.implode(' · ',$actions).'</td></tr>';
    }
    echo '</tbody></table><div class="pager">';
    if ($info['page'] > 1) echo '<a href="'.umePbEscape(umePbLink($id,array('page'=>$info['page']-1,'q'=>$info['query']))).'">← Предыдущая страница</a>';
    echo '<span class="small">Страница '.(int)$info['page'].' из '.(int)$info['pages'].'</span>';
    if ($info['page'] < $info['pages']) echo '<a href="'.umePbEscape(umePbLink($id,array('page'=>$info['page']+1,'q'=>$info['query']))).'">Следующая страница →</a>';
    echo '</div></div></div></body></html>';
    exit;
} catch (Exception $e) {
    umePbHeaders('application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(array('ok' => false, 'error' => 'Резервная копия не найдена или её содержимое недоступно для просмотра.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
