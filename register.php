<?php
if($_POST["register"]=='Register'){ User::register(); }
?>
<div style="margin:30px auto;text-align:center;width:300px"><form method="post" action="/register"><input type="text" name="name" placeholder="Name" required /><input type="text" name="email" placeholder="Email" required /><input type="password" name="pass" placeholder="Password" required /><br /><input type="submit" name="register" value="Register"></form></div>