<?php
class User{
  public static function login($logout=0){
	if($logout==0){
    Mysql::where("email",$_POST["email"]);
	$user=Mysql::getone("users","user_id,name,pass");
	if(md5($_POST["pass"])==$user["pass"]){
		$_SESSION["user_id"]=$user["id"];
		$_SESSION["user_name"]=$user["name"];
		header('Location: /gui');
		exit;
	} else {
	  print '<script>alert("Wrong Password")</script>';	
	}
	} else { unset($_SESSION["user_id"]); unset($_SESSION["user_name"]); }
  }
  
  public static function register(){
	Mysql::where("email",$_POST["email"]);
	$user=Mysql::getone("users","id,name,pass");
	if(!$user["id"]){
		$_SESSION["user_id"]=Mysql::insert("users",["name"=>$_POST["name"],"email"=>$_POST["email"],"pass"=>md5($_POST["pass"]),"pubkey"=>substr(md5(mt_rand()),0,20),"prvkey"=>substr(md5(mt_rand()),0,20)]);
		$_SESSION["user_name"]=$_POST["name"];
		header('Location: /gui');
		exit;
	} else {
	  print '<script>alert("There is a registry with this email")</script>';	
	}
  }
  
  public static function getuserviakey($public,$private=''){
	  Mysql::where("pubkey",$public);
	  if(strlen($private)>10){ Mysql::where("prvkey",$private); }
	  return Mysql::getone("users","user_id");
  }

}
?>