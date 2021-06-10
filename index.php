<?php
session_start();
ini_set('memory_limit', '-1');

function autoloader($classname) {
$classname=strtolower($classname);
foreach (glob("class/*.php") as $libs){ if(strpos($libs,$classname)!==false){ $filename=$libs; } }
@require_once $filename;
}
spl_autoload_register('autoloader');


$url=explode('/',addslashes($_SERVER['REQUEST_URI']));
$page=$url[1];
$func=$url[2];
if(!$page){ $page='login'; }
@include $page.'.php';
?>