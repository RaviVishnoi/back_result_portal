<?php
$css_file = 'c:/xampp/htdocs/back_result_portal/assets/css/admin_theme.css';
$css_content = file_get_contents($css_file);

// Targets and their markers in CSS
$targets = [
    'security_email' => [
        'file' => 'c:/xampp/htdocs/back_result_portal/admin/security_email.php',
        'marker' => '/* ── OTP Email Settings Page (security_email.php) Additional Styles ─────── */',
        'insert_before' => '<div class="otp-email-shell">'
    ],
    'change_password' => [
        'file' => 'c:/xampp/htdocs/back_result_portal/admin/change_password.php',
        'marker' => '/* ── Change Password Page (change_password.php) Additional Styles ─────── */',
        'insert_before' => '<div class="admin-page-shell">'
    ],
    'bulk_edit_students' => [
        'file' => 'c:/xampp/htdocs/back_result_portal/admin/bulk_edit_students.php',
        'marker' => '/* ── Bulk Edit Students Page Additional Styles ─────── */',
        'insert_before' => '<div class="admin-page-shell">'
    ],
    'admin_login' => [
        'file' => 'c:/xampp/htdocs/back_result_portal/admin_login.php',
        'marker' => '/* ── Admin Login Page (admin_login.php) Additional Styles ─────── */',
        'insert_before' => '<main class="auth-page admin-otp-page py-5">'
    ]
];

$remaining_css = $css_content;

foreach ($targets as $key => $target) {
    $marker = $target['marker'];
    $marker_pos = strpos($remaining_css, $marker);
    
    if ($marker_pos !== false) {
        // Find the next marker or end of string
        $next_marker_pos = strlen($remaining_css);
        foreach ($targets as $k => $t) {
            if ($k !== $key) {
                $pos = strpos($remaining_css, $t['marker'], $marker_pos + strlen($marker));
                if ($pos !== false && $pos < $next_marker_pos) {
                    $next_marker_pos = $pos;
                }
            }
        }
        
        $block_length = $next_marker_pos - $marker_pos;
        $extracted_css = substr($remaining_css, $marker_pos, $block_length);
        
        // Remove this block from remaining_css
        $remaining_css = substr_replace($remaining_css, '', $marker_pos, $block_length);
        
        // Clean up the extracted CSS (remove the comment marker)
        $clean_css = trim(str_replace($marker, '', $extracted_css));
        
        if (!empty($clean_css)) {
            // Read target PHP file
            $php_content = file_get_contents($target['file']);
            
            // Check if <style> already exists to avoid duplicates
            if (strpos($php_content, '<style>') === false) {
                $style_block = "<style>\n" . $clean_css . "\n</style>\n\n";
                $php_content = str_replace($target['insert_before'], $style_block . $target['insert_before'], $php_content);
                file_put_contents($target['file'], $php_content);
                echo "Restored CSS to {$target['file']}\n";
            } else {
                echo "CSS already seems to exist in {$target['file']}\n";
            }
        }
    }
}

file_put_contents($css_file, trim($remaining_css) . "\n");
echo "Updated admin_theme.css\n";
