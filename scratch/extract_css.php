<?php
$theme_file = 'c:/xampp/htdocs/back_result_portal/assets/css/admin_theme.css';
$css = "\n\n/* ── OTP Email Settings Page (security_email.php) Additional Styles ─────── */\n";

$security_file = 'c:/xampp/htdocs/back_result_portal/admin/security_email.php';
$sec_content = file_get_contents($security_file);
if (preg_match('/<style>(.*?)<\/style>/s', $sec_content, $matches)) {
    $css .= $matches[1] . "\n";
    $sec_content = preg_replace('/<style>.*?<\/style>\s*/s', '', $sec_content);
    file_put_contents($security_file, $sec_content);
}

$css .= "\n/* ── Change Password Page (change_password.php) Additional Styles ─────── */\n";
$pwd_file = 'c:/xampp/htdocs/back_result_portal/admin/change_password.php';
$pwd_content = file_get_contents($pwd_file);
if (preg_match('/<style>(.*?)<\/style>/s', $pwd_content, $matches)) {
    $css .= $matches[1] . "\n";
    $pwd_content = preg_replace('/<style>.*?<\/style>\s*/s', '', $pwd_content);
    file_put_contents($pwd_file, $pwd_content);
}

$css .= "\n/* ── Bulk Edit Students Page Additional Styles ─────── */\n";
$bulk_file = 'c:/xampp/htdocs/back_result_portal/admin/bulk_edit_students.php';
$bulk_content = file_get_contents($bulk_file);
if (preg_match('/<style>(.*?)<\/style>/s', $bulk_content, $matches)) {
    $css .= $matches[1] . "\n";
    $bulk_content = preg_replace('/<style>.*?<\/style>\s*/s', '', $bulk_content);
    file_put_contents($bulk_file, $bulk_content);
}

file_put_contents($theme_file, $css, FILE_APPEND);
echo "CSS extracted and appended successfully.";
