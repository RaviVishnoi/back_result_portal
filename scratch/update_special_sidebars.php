<?php
$files = [
    'c:/xampp/htdocs/back_result_portal/admin/disclaimer_records.php',
    'c:/xampp/htdocs/back_result_portal/admin/manage_permissions.php',
    'c:/xampp/htdocs/back_result_portal/admin/security_logs.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    
    // Check if it already has the sidebar
    if (strpos($content, 'admin_sidebar.php') !== false) {
        continue;
    }
    
    // Replace opening
    $search_open = '<div class="admin-page-shell">
    <div class="container-fluid">';
    
    $replace_open = '<div class="admin-page-shell">
    <div class="container-fluid">
        <div class="row">
            <?php include \'../includes/admin_sidebar.php\'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 admin-page-content">';
            
    $content = str_replace($search_open, $replace_open, $content);
    
    // Replace closing
    $search_close = '</div><!-- /container-fluid -->
</div>';

    $replace_close = '            </main>
        </div>
    </div><!-- /container-fluid -->
</div>';

    $content = str_replace($search_close, $replace_close, $content);
    
    file_put_contents($file, $content);
    echo "Updated " . basename($file) . "\n";
}
