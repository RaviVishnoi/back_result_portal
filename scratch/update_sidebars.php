<?php
$files = glob('c:/xampp/htdocs/back_result_portal/admin/*.php');
foreach ($files as $file) {
    $content = file_get_contents($file);
    if (strpos($content, 'class="admin-page-shell"') !== false && strpos($content, 'admin_sidebar.php') === false) {
        echo basename($file) . "\n";
    }
}
