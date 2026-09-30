<?php
require_once '../includes/config.php';
require_admin_session($pdo);

$is_super_admin = is_super_admin($pdo);
if (!$is_super_admin && !has_permission($pdo, 'disclaimer_records.view')) {
    require_permission($pdo, 'disclaimer_records.view');
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_records'])) {
    if (!$is_super_admin) {
        die("Only Super Admin can delete records.");
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF Token.");
    } else {
        try {
            $pdo->query("DELETE FROM disclaimer_verification_records");
            log_activity($pdo, "Cleared all disclaimer records", $_SESSION['admin_id'] ?? null);
            $success = "All disclaimer records have been cleared successfully.";
        } catch (PDOException $e) {
            die("Failed to clear records: " . $e->getMessage());
        }
    }
}

$search = trim((string) ($_GET['search'] ?? ''));
$status = $_GET['status'] ?? '';
$date = $_GET['date'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 25;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(d.roll_no LIKE ? OR d.student_name LIKE ? OR d.exam_name LIKE ? OR d.ip_address LIKE ?)';
    $term = "%{$search}%";
    array_push($params, $term, $term, $term, $term);
}

if (in_array($status, ['accepted', 'pending'], true)) {
    $where[] = 'd.disclaimer_accepted = ?';
    $params[] = ($status === 'accepted') ? 1 : 0;
}

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
    $where[] = 'DATE(d.accepted_at) = ?';
    $params[] = $date;
}

$clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Get stats
$stats = [
    'total' => (int) $pdo->query("SELECT COUNT(*) FROM disclaimer_verification_records")->fetchColumn(),
    'accepted' => (int) $pdo->query("SELECT COUNT(*) FROM disclaimer_verification_records WHERE disclaimer_accepted = 1")->fetchColumn(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM disclaimer_verification_records WHERE disclaimer_accepted = 0")->fetchColumn(),
    'otp_verified' => (int) $pdo->query("SELECT COUNT(*) FROM disclaimer_verification_records WHERE otp_verified = 1")->fetchColumn(),
    'today' => (int) $pdo->query("SELECT COUNT(*) FROM disclaimer_verification_records WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
];

// Get total count for pagination
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM disclaimer_verification_records d{$clause}");
$total_stmt->execute($params);
$total_records = (int) $total_stmt->fetchColumn();

// Get paginated records
$list_stmt = $pdo->prepare("SELECT d.*, s.student_name AS student_name_db 
                            FROM disclaimer_verification_records d 
                            LEFT JOIN students s ON s.id = d.student_id 
                            {$clause} 
                            ORDER BY d.created_at DESC, d.id DESC 
                            LIMIT ? OFFSET ?");
foreach ($params as $key => $value) {
    $list_stmt->bindValue($key + 1, $value);
}
$list_stmt->bindValue(count($params) + 1, $per_page, PDO::PARAM_INT);
$list_stmt->bindValue(count($params) + 2, ($page - 1) * $per_page, PDO::PARAM_INT);
$list_stmt->execute();
$records = $list_stmt->fetchAll();

// Export Excel/CSV
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    if (!$is_super_admin && !has_permission($pdo, 'disclaimer_records.export')) {
        require_permission($pdo, 'disclaimer_records.export');
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="disclaimer-verification-records-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['ID', 'Student ID', 'Roll Number', 'Student Name', 'Exam', 'Disclaimer Version', 'OTP Verified', 'Disclaimer Accepted', 'Accepted At', 'IP Address', 'User Agent', 'Session Hash', 'Created At']);
    foreach ($records as $row) {
        fputcsv($out, [
            $row['id'],
            $row['student_id'] ?? '',
            $row['roll_no'] ?? '',
            $row['student_name'] ?: $row['student_name_db'] ?: 'Unknown',
            $row['exam_name'] ?: 'N/A',
            $row['disclaimer_version'] ?? 'v1',
            ($row['otp_verified'] ?? 0) ? 'Yes' : 'No',
            ($row['disclaimer_accepted'] ?? 0) ? 'Yes' : 'No',
            $row['accepted_at'] ?? '',
            $row['ip_address'] ?? '',
            $row['user_agent'] ?? '',
            $row['session_id_hash'] ?? '',
            $row['created_at'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

include '../includes/header.php';
?>


<div class="admin-page-shell">
    <div class="container-fluid">
        <div class="row">
            <!-- SIDEBAR -->
            <?php include '../includes/admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 admin-page-content">
                <!-- ===== HEADER ===== -->
                <div class="admin-page-header">
            <div>
                <h1>
                    <i class="fas fa-file-contract"></i>
                    Disclaimer Records
                </h1>
                <div class="subtitle">
                    <i class="fas fa-history me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                    View and export student disclaimer acceptance logs
                </div>
            </div>
            <div class="header-actions">
                <a href="dashboard.php" class="btn-back-dashboard">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
                <a href="manage_admins.php" class="btn-back-admin">
                    <i class="fas fa-users"></i>
                    Admin Management
                </a>
                <?php if ($is_super_admin || has_permission($pdo, 'disclaimer_records.export')): ?>
                    <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>" class="btn-export">
                        <i class="fas fa-file-excel"></i>
                        Export Excel
                    </a>
                <?php endif; ?>
                <?php if ($is_super_admin): ?>
                <form method="POST" action="disclaimer_records.php" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete ALL disclaimer records? This action cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="clear_records" value="1">
                    <button type="submit" style="background: #e52d2d; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; font-weight: 500; display: inline-flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <i class="fas fa-trash-alt"></i>
                        Clear Records
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert-mgmt alert-success mb-4" style="background:#e8f5e9; color:#2e7d32; padding:1rem; border-radius:8px; display:flex; align-items:center;">
                <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-mgmt alert-danger mb-4" style="background:#ffebee; color:#c62828; padding:1rem; border-radius:8px; display:flex; align-items:center;">
                <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- ===== STATS ===== -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="label">Total Records</div>
                <div class="value"><?= number_format($stats['total']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Accepted</div>
                <div class="value text-success"><?= number_format($stats['accepted']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Pending</div>
                <div class="value text-warning"><?= number_format($stats['pending']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">OTP Verified</div>
                <div class="value text-primary"><?= number_format($stats['otp_verified']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Today</div>
                <div class="value"><?= number_format($stats['today']) ?></div>
            </div>
        </div>

        <!-- ===== FILTERS ===== -->
        <div class="filter-card">
            <form method="GET" class="filter-row">
                <div>
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Roll No, Name, Exam, IP..." 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div>
                    <label class="form-label">Date</label>
                    <input type="date" name="date" class="form-control" 
                           value="<?= htmlspecialchars($date) ?>">
                </div>
                <div>
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="accepted" <?= $status === 'accepted' ? 'selected' : '' ?>>Accepted</option>
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                </div>
                <div style="display:flex;gap:0.5rem;">
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-search me-1"></i> Filter
                    </button>
                    <?php if ($search || $status || $date): ?>
                        <a href="disclaimer_records.php" class="btn-clear">
                            <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- ===== TABLE ===== -->
        <div class="table-card">
            <div class="card-header">
                <h5>
                    <i class="fas fa-list-ul text-muted"></i>
                    Disclaimer Verification Records
                    <span class="badge-count"><?= number_format($total_records) ?> records</span>
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="min-width:140px;">Date &amp; Time</th>
                            <th>Student</th>
                            <th>Roll No</th>
                            <th>Exam</th>
                            <th>OTP</th>
                            <th>Disclaimer</th>
                            <th>IP Address</th>
                            <th>User Agent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x d-block mb-2" style="color:#d0d7e2;"></i>
                                    No disclaimer records found matching your criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($records as $record): ?>
                                <tr>
                                    <td data-label="Date &amp; Time">
                                        <div style="font-weight:500;font-size:0.8rem;">
                                            <?= date('d M Y', strtotime($record['created_at'] ?? $record['accepted_at'] ?? 'now')) ?>
                                        </div>
                                        <div class="text-muted-small">
                                            <?= date('H:i:s', strtotime($record['created_at'] ?? $record['accepted_at'] ?? 'now')) ?>
                                        </div>
                                    </td>
                                    <td data-label="Student">
                                        <strong>
                                            <?= htmlspecialchars($record['student_name'] ?: ($record['student_name_db'] ?? 'Unknown')) ?>
                                        </strong>
                                    </td>
                                    <td data-label="Roll No">
                                        <span style="font-family:monospace;font-size:0.8rem;">
                                            <?= htmlspecialchars($record['roll_no'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td data-label="Exam">
                                        <span style="font-size:0.8rem;">
                                            <?= htmlspecialchars($record['exam_name'] ?: 'N/A') ?>
                                        </span>
                                    </td>
                                    <td data-label="OTP">
                                        <span class="status-badge <?= !empty($record['otp_verified']) ? 'verified' : 'not_verified' ?>">
                                            <?= !empty($record['otp_verified']) ? 'Verified' : 'Not Verified' ?>
                                        </span>
                                    </td>
                                    <td data-label="Disclaimer">
                                        <span class="status-badge <?= !empty($record['disclaimer_accepted']) ? 'accepted' : 'pending' ?>">
                                            <?= !empty($record['disclaimer_accepted']) ? 'Accepted' : 'Pending' ?>
                                        </span>
                                    </td>
                                    <td data-label="IP Address">
                                        <span class="ip-address"><?= htmlspecialchars($record['ip_address'] ?? '—') ?></span>
                                    </td>
                                    <td data-label="User Agent">
                                        <?php if ($record['user_agent']): ?>
                                            <span class="user-agent-text" title="<?= htmlspecialchars($record['user_agent']) ?>">
                                                <?= htmlspecialchars($record['user_agent']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted-small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ===== PAGINATION ===== -->
            <?php if ($total_records > $per_page): ?>
                <div class="pagination-wrap">
                    <div class="pagination-info">
                        Showing <?= ($page - 1) * $per_page + 1 ?> to 
                        <?= min($page * $per_page, $total_records) ?> of 
                        <?= number_format($total_records) ?> records
                    </div>
                    <nav>
                        <ul class="pagination">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1, 'export' => null]))) ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            <?php 
                            $total_pages = max(1, ceil($total_records / $per_page));
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if ($start_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => 1, 'export' => null]))) ?>">1</a>
                                </li>
                                <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i, 'export' => null]))) ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">…</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $total_pages, 'export' => null]))) ?>">
                                        <?= $total_pages ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1, 'export' => null]))) ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
            </main>
        </div>
    </div><!-- /container-fluid -->
</div>

<?php include '../includes/footer.php'; ?>