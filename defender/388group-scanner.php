<?php
set_time_limit(0);
error_reporting(0);
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
ob_implicit_flush(1);

$path = getcwd();
if (isset($_GET['dir'])) {
    $path = $_GET['dir'];
}

if (isset($_GET['kill'])) {
    unlink(__FILE__);
    exit;
}

echo '<link href="https://rasenmedia.my.id/assets/css/bootstrap-dark.css" id="bootstrap-style" rel="stylesheet" type="text/css" />';
echo '<link href="https://rasenmedia.my.id/assets/css/all.min.css" rel="stylesheet" type="text/css">';
echo '<link href="https://rasenmedia.my.id/assets/css/app-dark.css" id="app-style" rel="stylesheet" type="text/css">';
echo '<div class="page-content">';
echo '<div class="container-fluid"><div class="row d-flex justify-content-center">';
echo '<div class="col-12"><div class="card">';
echo '<h5 class="card-header border-bottom text-uppercase"><center>SCANNER BACKDOOR SCANDALAUS</center></h5>';
echo '<div class="card-body">';
echo '<div class="alert bg-primary bg-gradient text-dark d-flex align-items-center" role="alert">';
echo '<i class="bx bx-info-circle fs-2 me-2"></i><center>Breaking boundaries with elegance and precision, this backdoor scanner is a secret weapon that reveals hidden dimensions in digital codes, paving the way for limitless exploration of the cyber world.</center></div>';
echo "<a href='?kill'><font color='green'>[Self Delete]</font></a><br>";
echo '<form action="" method="get"><input class="form-control" type="text" name="dir" value="'.$path.'" style="width: 900px;"><br><input class="btn btn-primary bg-gradient waves-effect waves-light me-1" type="submit" value="Scanner"></form><br>';
echo "CURRENT DIR: <font color='green'>$path</font><br>";

if (isset($_GET['delete'])) {
    unlink($_GET['delete']);
    $status = "<font color='yellow'>FAILED</font>";
    if (!file_exists($_GET['delete'])) {
        $status = "<font color='green'>Success</font>";
    }
    echo "TRY TO DELETE: " . $_GET['delete'] . " $status <br>";
    exit;
}

scanBackdoor($path);

function save($fname, $value) {
    $file = fopen($fname, "a");
    fwrite($file, $value);
    fclose($file);
}

function checkBackdoor($file_location) {
    global $path;
    $pattern = "#exec\(|gzinflate\(|file_put_contents\(|file_get_contents\(|system\(|passthru\(|shell_exec\(|move_uploaded_file\(|eval\(|base64#";
    $contents = file_get_contents($file_location);

    if (strlen($contents) > 0) {
        if (preg_match($pattern, strtolower($contents))) {
            echo "[+] Suspicious file -> <font color='red'>$file_location</font> <a href='?delete=$file_location&dir=$path'><font color='green'>[DELETE]</font></a><br>";
            save("wop.txt", "$file_location\n");
            echo '<textarea class="form-control" name="content" cols="45" rows="15">' . htmlspecialchars($contents) . '</textarea><br><br>';
            
            // Mendapatkan URL file relatif ke DOCUMENT_ROOT
            $document_root = realpath($_SERVER['DOCUMENT_ROOT']);
            $absolute_path = realpath($file_location);
            $relative_path = str_replace($document_root, '', $absolute_path);
            $base_url = "http://" . $_SERVER['HTTP_HOST'];
            $file_url = $base_url . $relative_path;
            
            echo "<a href='" . htmlspecialchars($file_url) . "' target='_blank' style='display:block;'>Click to view file</a>";
        } else {
            echo "[+] Safe file -> <font color='green'>$file_location</font><br>";
        }
    }
}

function scanBackdoor($current_dir) {
    if (is_readable($current_dir)) {
        $dir_location = scandir($current_dir);
        foreach ($dir_location as $file) {
            if ($file === "." || $file === "..") {
                continue;
            }
            $file_location = str_replace("//", "/", $current_dir . '/' . $file);
            $nFile = substr($file, -4, 4);
            if ($nFile == ".php") {
                checkBackdoor($file_location);
            } elseif (is_dir($file_location)) {
                scanBackdoor($file_location);
            }
        }
    }
}
?>
