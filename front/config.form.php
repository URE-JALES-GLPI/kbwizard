<?php
// Wrapper para compatibilidade GLPI marketplace - robusto plugins/ e marketplace/
$inc = null;
$candidates = [
    __DIR__ . "/../../../inc/includes.php",
    __DIR__ . "/../../../../inc/includes.php",
    dirname(__DIR__, 3) . "/inc/includes.php",
];
foreach ($candidates as $c) { if (file_exists($c)) { $inc = $c; break; } }
if ($inc) include($inc);
include (__DIR__ . "/config.php");
