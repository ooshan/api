<?php
$json = json_decode(file_get_contents('php://input'),true);
if(sizeof($json)>1){
	$user=User::getuserviakey($json["public"],$json["private_key"]);
	if($user["user_id"]){
	  if($func=='push'){
		  if(Mysql::insert("logs",["user_id"=>$user["user_id"],"ip"=>$_SERVER['REMOTE_ADDR'],"browser"=>$_SERVER['HTTP_USER_AGENT'],"log_name"=>$json["data_set"],"log"=>$json["data"]])){
			  $resp["ok"]=1;
		  } else { $resp["error"]='Missing parameter'; }
	  }
	  if($func=='get'){
		  Mysql::where("user_id",$user["id"]);
		  if(strlen($json["data_set"])>1){ Mysql::where("log_name",$json["data_set"]); }
		  $resp=Mysql::get("logs","ip,browser,log_name as data_set,log as data,log_time as time");
	  }
	} else { $resp["error"]='Wrong keys'; }
}

header('Content-Type: application/json');
print json_encode($resp);
?>