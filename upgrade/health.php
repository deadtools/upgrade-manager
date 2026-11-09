<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
$releaseVersion='3.7.4';$releaseFile=__DIR__.'/release.json';if(is_file($releaseFile)){$release=json_decode(@file_get_contents($releaseFile),true);if(is_array($release)&&!empty($release['version']))$releaseVersion=(string)$release['version'];}
$result = array(
    'ok' => true,
    'upgrade_manager' => $releaseVersion,
    'php' => PHP_VERSION,
    'config_present' => is_file(__DIR__ . '/config.local.php'),
    'bootstrap_present' => is_file(__DIR__ . '/bootstrap.php'),
    'index_present' => is_file(__DIR__ . '/index.php'),
    'directory_readable' => is_readable(__DIR__),
    'directory_writable' => is_writable(__DIR__)
);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
