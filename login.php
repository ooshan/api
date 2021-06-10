<?php
if($_POST["login"]=='Login'){ User::login(); }
if($func=='logout'){ User::login(1); }
?>
<div style="margin:30px auto;width:300px"><form method="post" action="/login"><input type="text" name="email" placeholder="Email" required /><input type="password" name="pass" placeholder="Password" required /><br /><input type="submit" name="login" value="Login"><input style="float:right" type="button" onClick="document.location.href='register'" value="Register"></form></div>