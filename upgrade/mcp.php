<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/lib/McpServer.php';
$server=new UMEMcpServer($config,$manager,$oauth,$apiTasks);
$server->handle();
