<?php
// Ensure this file is included from within an admin page that has already required config.php and security.php
if (!isset($pdo) || !function_exists('is_super_admin')) {
    exit('Direct access not permitted.');
}

$current_page = basename($_SERVER['PHP_SELF']);
$is_super_admin = is_super_admin($pdo);

// Grouping logic for active states
$students_pages = ['manage_students.php', 'add_student.php', 'edit_student.php', 'bulk_edit_students.php', 'bulk_upload_students.php'];
$exams_pages = ['manage_exams.php', 'edit_exam.php', 'exam_results.php', 'edit_student_result.php'];
$upload_pages = ['upload_result.php'];
?>
<nav class="col-md-3 col-lg-2 d-md-block sidebar py-4 no-print">
    <div class="position-sticky">
        <div class="px-3 mb-4">
            <h6 class="text-muted text-uppercase small fw-bold">Main Menu</h6>
        </div>
        <ul class="nav flex-column px-2">
            <li class="nav-item">
                <a class="nav-link nav-link-admin <?= $current_page == 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
                    <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                </a>
            </li>

            <?php if ($is_super_admin || has_permission($pdo, 'students.view')): ?>
            <li class="nav-item">
                <a class="nav-link nav-link-admin <?= in_array($current_page, $students_pages) ? 'active' : '' ?>" href="manage_students.php">
                    <i class="fas fa-users me-2"></i> Manage Students
                </a>
            </li>
            <?php endif; ?>

            <?php if ($is_super_admin || has_permission($pdo, 'exams.view')): ?>
            <li class="nav-item">
                <a class="nav-link nav-link-admin <?= in_array($current_page, $exams_pages) ? 'active' : '' ?>" href="manage_exams.php">
                    <i class="fas fa-list me-2"></i> Manage Exams
                </a>
            </li>
            <?php endif; ?>

            <?php if ($is_super_admin || has_permission($pdo, 'results.upload')): ?>
            <li class="nav-item">
                <a class="nav-link nav-link-admin <?= in_array($current_page, $upload_pages) ? 'active' : '' ?>" href="upload_result.php">
                    <i class="fas fa-upload me-2"></i> Upload Result
                </a>
            </li>
            <?php endif; ?>

            <?php if ($is_super_admin || has_permission($pdo, 'admins.view') || has_permission($pdo, 'security.view') || has_permission($pdo, 'disclaimer_records.view')): ?>
            <div class="px-3 mt-4 mb-2">
                <h6 class="text-muted text-uppercase small fw-bold">Administration</h6>
            </div>
            <?php endif; ?>

            <?php if ($is_super_admin || has_permission($pdo, 'admins.view')): ?>
            <li class="nav-item">
                <a class="nav-link nav-link-admin <?= $current_page == 'manage_admins.php' ? 'active' : '' ?>" href="manage_admins.php">
                    <i class="fas fa-user-shield me-2"></i> Admin Management
                </a>
            </li>
            <?php endif; ?>

            <?php if ($is_super_admin || has_permission($pdo, 'admins.permissions')): ?>
            <li class="nav-item">
                <a class="nav-link nav-link-admin <?= $current_page == 'manage_permissions.php' ? 'active' : '' ?>" href="manage_permissions.php">
                    <i class="fas fa-unlock-alt me-2"></i> Manage Permissions
                </a>
            </li>
            <?php endif; ?>

            <?php if ($is_super_admin || has_permission($pdo, 'security.view')): ?>
            <li class="nav-item">
                <a class="nav-link nav-link-admin <?= $current_page == 'security_logs.php' ? 'active' : '' ?>" href="security_logs.php">
                    <i class="fas fa-shield-alt me-2"></i> Security Logs
                </a>
            </li>
            <?php endif; ?>

            <?php if ($is_super_admin || has_permission($pdo, 'security.settings')): ?>
            <li class="nav-item">
                <a class="nav-link nav-link-admin <?= $current_page == 'security_email.php' ? 'active' : '' ?>" href="security_email.php">
                    <i class="fas fa-envelope-open-text me-2"></i> Email Settings
                </a>
            </li>
            <?php endif; ?>
            
            <?php if ($is_super_admin || has_permission($pdo, 'disclaimer_records.view')): ?>
            <li class="nav-item">
                <a class="nav-link nav-link-admin <?= $current_page == 'disclaimer_records.php' ? 'active' : '' ?>" href="disclaimer_records.php">
                    <i class="fas fa-file-contract me-2"></i> Disclaimer Records
                </a>
            </li>
            <?php endif; ?>

            <li class="nav-item mt-4">
                <a class="nav-link nav-link-admin text-danger" href="logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i> Logout
                </a>
            </li>
        </ul>
    </div>
</nav>
