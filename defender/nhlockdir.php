<?php
// Fungsi untuk membuat bash script yang akan memantau folder
function setupFolderMonitoring($folderName) {
    // Mendapatkan path direktori tempat script diletakkan
    $currentDir = __DIR__;
    
    // Pastikan direktori tujuan memiliki hak akses yang benar
    if (!is_writable($currentDir)) {
        echo "Direktori tidak dapat ditulisi. Pastikan memiliki hak akses tulis.";
        return;
    }

    // Membuat folder jika belum ada
    if (!is_dir($currentDir . '/' . $folderName)) {
        mkdir($currentDir . '/' . $folderName, 0755, true);
    }

    // Path backup folder
    $backupDir = $currentDir . '/.backup/' . $folderName;
    if (!is_dir($currentDir . '/.backup')) {
        mkdir($currentDir . '/.backup', 0755, true);
    }

    // Menyalin file yang ada dalam folder ke backup jika folder ada
    if (is_dir($currentDir . '/' . $folderName)) {
        $files = glob($currentDir . '/' . $folderName . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                copy($file, $backupDir . '/' . basename($file));
            }
        }
    }

    // Membuat bash script untuk memantau folder
    $bashScript = "#!/bin/bash\n"
                 . "folderPath=\"$currentDir/$folderName\"\n"
                 . "backupPath=\"$currentDir/.backup/$folderName\"\n"
                 . "while true; do\n"
                 . "  if [ ! -d \"\$folderPath\" ]; then\n"
                 . "    echo 'Folder $folderName tidak ditemukan. Membuat kembali...' >> /tmp/folder_monitor.log\n"
                 . "    mkdir -p \"\$folderPath\"\n"
                 . "    if [ -d \"\$backupPath\" ]; then\n"
                 . "      echo 'Mengembalikan file dari backup...'\n"
                 . "      cp -r \$backupPath/* \$folderPath/\n"
                 . "    fi\n"
                 . "  fi\n"
                 . "  sleep 1\n"
                 . "done";

    // Simpan bash script di direktori temporer
    $bashFilePath = "/tmp/folder_monitor_$folderName.sh";
    file_put_contents($bashFilePath, $bashScript);

    // Memberi izin eksekusi ke bash script
    chmod($bashFilePath, 0755);

    // Menjalankan bash script di latar belakang menggunakan nohup
    exec("nohup bash $bashFilePath > /dev/null 2>&1 &");

    echo "Bash script telah dibuat untuk memantau folder: $folderName.\n";
}

// Fungsi untuk menghentikan bash script pemantau folder
function removeFolderMonitoring($folderName) {
    // Menghentikan semua proses folder_monitor yang berjalan di latar belakang
    exec("pkill -f folder_monitor_$folderName.sh");
    echo "Pemantauan folder $folderName dihentikan.\n";
}

// Jika pengguna menekan tombol "Start" untuk folder
if (isset($_POST['startFolder'])) {
    $folderName = $_POST['foldername'];

    // Validasi nama folder agar aman dan tidak merusak sistem
    if (preg_match('/^[a-zA-Z0-9_-]+$/', $folderName)) {
        setupFolderMonitoring($folderName);
    } else {
        echo "Nama folder tidak valid. Harap gunakan karakter huruf, angka, underscore, atau dash.";
    }
}

// Jika pengguna menekan tombol "Stop" untuk folder
if (isset($_POST['stopFolder'])) {
    $folderName = $_POST['foldername'];
    removeFolderMonitoring($folderName);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Folder Monitor</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(45deg, #4b79a1, #283e51);
            color: white;
            text-align: center;
            margin-top: 100px;
        }
        .container {
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 8px;
            display: inline-block;
        }
        input[type="text"] {
            padding: 10px;
            border: none;
            border-radius: 4px;
            margin: 10px;
            width: 300px;
        }
        input[type="submit"] {
            padding: 10px 20px;
            border: none;
            background-color: #4CAF50;
            color: white;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        input[type="submit"]:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Simple Folder Monitor</h1>
        <p>Masukkan nama folder yang ingin dipantau:</p>
        <form method="post">
            <input type="text" name="foldername" placeholder="Masukkan nama folder" required>
            <br>
            <input type="submit" name="startFolder" value="Start Monitoring">
            <input type="submit" name="stopFolder" value="Stop Monitoring">
        </form>
    </div>
</body>
</html>
