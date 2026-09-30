<?php
require_once '../includes/config.php';

require_admin_session($pdo);

$current_admin_role = admin_current_role($pdo);
$is_super_admin = is_super_admin($pdo);

// Statistics (Student count now from students table)
$total_exams    = $pdo->query("SELECT COUNT(*) FROM exams")->fetchColumn();
$total_students = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$total_results  = $pdo->query("SELECT COUNT(*) FROM student_results")->fetchColumn();

// Recent Exams with live uploaded-result counts.
$recent_exams = $pdo->query("
    SELECT e.*, COUNT(sr.id) AS uploaded_records
    FROM exams e
    LEFT JOIN student_results sr ON sr.exam_id = e.id
    GROUP BY e.id
    ORDER BY e.created_at DESC
    LIMIT 5
")->fetchAll();

include '../includes/header.php';
?>

<div class="admin-page-shell">
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <?php include '../includes/admin_sidebar.php'; ?>

            <!-- Main Content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 admin-page-content">
                <div class="admin-page-header">
                    <div>
                        <h1>
                            <i class="fas fa-tachometer-alt"></i>
                            Dashboard
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-user-circle me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                            Welcome back, <strong><?php echo sanitize_input($_SESSION['username']); ?></strong>
                            <span class="role-badge <?= in_array($current_admin_role, ['super_admin', 'superadmin']) ? 'super' : 'admin' ?> ms-2">
                                <?= htmlspecialchars($current_admin_role) ?>
                            </span>
                        </div>
                    </div>
                    <div class="header-actions">
                        <?php if (!$is_super_admin): ?>
                            <a href="manage_students.php" class="btn-create-admin bg-success text-white">
                                <i class="fas fa-users me-2"></i> Manage Students
                            </a>
                            <a href="upload_result.php" class="btn-create-admin">
                                <i class="fas fa-plus me-2"></i> Create New Result
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (isset($_GET['timeout'])): ?>
                    <div class="alert-mgmt alert-warning fade show" role="alert">
                        <i class="fas fa-clock me-2"></i> Session timed out. You have been logged out. Please login again.
                    </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <a href="manage_exams.php" class="stat-card">
                        <div class="label">Total Exams</div>
                        <div class="value"><?php echo $total_exams; ?></div>
                    </a>
                    <a href="manage_students.php" class="stat-card">
                        <div class="label">Total Students</div>
                        <div class="value"><?php echo $total_students; ?></div>
                    </a>
                    <a href="exam_results.php" class="stat-card">
                        <div class="label">Records Tracked</div>
                        <div class="value"><?php echo $total_results; ?></div>
                    </a>
                </div>

                <!-- Recent Activity Table -->
                <div class="table-card" id="recent-activity-table">
                    <div class="card-header">
                        <h5>
                            <i class="fas fa-history text-muted"></i>
                            Recent Exam Activity
                        </h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Exam Name</th>
                                    <th>Batch</th>
                                    <th>Status</th>
                                    <th>Records</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($recent_exams) > 0): ?>
                                    <?php foreach ($recent_exams as $row): ?>
                                        <tr>
                                            <td data-label="Exam Name"><strong><?php echo sanitize_input($row['exam_name']); ?></strong></td>
                                            <td data-label="Batch"><?php echo sanitize_input($row['batch']); ?></td>
                                            <td data-label="Status">
                                                <span class="status-badge <?php echo $row['status'] ? 'active' : 'locked'; ?>">
                                                    <?php echo $row['status'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td data-label="Records">
                                                <span class="badge-count"><?php echo number_format((int) $row['uploaded_records']); ?></span>
                                            </td>
                                            <td data-label="Actions">
                                                <div class="action-group">
                                                    <a href="upload_result.php?exam_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-success" title="Upload Results">
                                                        <i class="fas fa-upload"></i>
                                                    </a>
                                                    <a href="exam_results.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-info" title="View Results">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">No exam results found yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>