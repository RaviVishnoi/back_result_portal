<?php
$theme_file = 'c:/xampp/htdocs/back_result_portal/assets/css/admin_theme.css';
$css = "\n\n/* ── Admin Login Page (admin_login.php) Additional Styles ─────── */\n";

$file = 'c:/xampp/htdocs/back_result_portal/admin_login.php';
$content = file_get_contents($file);
if (preg_match('/<style>(.*?)<\/style>/s', $content, $matches)) {
    $css .= $matches[1] . "\n";
    $content = preg_replace('/<style>.*?<\/style>\s*/s', '', $content);
    file_put_contents($file, $content);
}

file_put_contents($theme_file, $css, FILE_APPEND);
echo "Admin Login CSS extracted and appended successfully.";
