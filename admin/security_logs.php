<?php
require_once '../includes/config.php';
require_admin_session($pdo);
require_super_admin($pdo);

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_logs'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF Token.");
    } else {
        try {
            $pdo->query("DELETE FROM admin_security_logs");
            log_activity($pdo, "Cleared all security logs", $_SESSION['admin_id'] ?? null);
            $success = "All security logs have been cleared successfully.";
        } catch (PDOException $e) {
            die("Failed to clear logs: " . $e->getMessage());
        }
    }
}
$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$otp_status = $_GET['otp_status'] ?? '';
$date = $_GET['date'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 25;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(username LIKE ? OR action LIKE ? OR ip_address LIKE ? OR details LIKE ?)';
    $term = "%$search%";
    array_push($params, $term, $term, $term, $term);
}

if (in_array($status, ['success', 'failed', 'info'], true)) {
    $where[] = 'status = ?';
    $params[] = $status;
}

if ($otp_status !== '') {
    $where[] = 'otp_status = ?';
    $params[] = $otp_status;
}

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where[] = 'DATE(created_at) = ?';
    $params[] = $date;
}

$clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Get total count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_security_logs$clause");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

// Get paginated results
$stmt = $pdo->prepare("SELECT * FROM admin_security_logs$clause ORDER BY created_at DESC LIMIT ? OFFSET ?");
foreach ($params as $key => $value) {
    $stmt->bindValue($key + 1, $value);
}
$stmt->bindValue(count($params) + 1, $per_page, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, ($page - 1) * $per_page, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Get summary stats
$stats = [
    'total' => (int) $pdo->query("SELECT COUNT(*) FROM admin_security_logs")->fetchColumn(),
    'success' => (int) $pdo->query("SELECT COUNT(*) FROM admin_security_logs WHERE status = 'success'")->fetchColumn(),
    'failed' => (int) $pdo->query("SELECT COUNT(*) FROM admin_security_logs WHERE status = 'failed'")->fetchColumn(),
    'otp_failed' => (int) $pdo->query("SELECT COUNT(*) FROM admin_security_logs WHERE otp_status = 'failed'")->fetchColumn(),
    'today' => (int) $pdo->query("SELECT COUNT(*) FROM admin_security_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
];

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="admin-security-logs-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Date', 'Username', 'Action', 'Status', 'OTP Status', 'IP Address', 'Device', 'User Agent', 'Details']);
    foreach ($logs as $log) {
        fputcsv($out, [
            $log['created_at'],
            $log['username'] ?: 'Unknown',
            $log['action'],
            $log['status'],
            $log['otp_status'] ?: '-',
            $log['ip_address'],
            $log['device_info'] ?: '-',
            $log['user_agent'] ?: '-',
            $log['details'] ?: ''
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
                    <i class="fas fa-shield-alt"></i>
                    Security Logs
                </h1>
                <div class="subtitle">
                    <i class="fas fa-history me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                    Monitor admin login activity, OTP verifications, and security events
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
                <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="btn-export">
                    <i class="fas fa-file-csv"></i>
                    Export CSV
                </a>
                <form method="POST" action="security_logs.php" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete ALL security logs? This action cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <input type="hidden" name="clear_logs" value="1">
                    <button type="submit" style="background: #e52d2d; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; font-weight: 500; display: inline-flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                        <i class="fas fa-trash-alt"></i>
                        Clear Logs
                    </button>
                </form>
            </div>
        </div>

        <!-- ===== STATS ===== -->
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

        <div class="stats-grid">
            <div class="stat-card">
                <div class="label">Total Events</div>
                <div class="value"><?= number_format($stats['total']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Successful</div>
                <div class="value text-success"><?= number_format($stats['success']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Failed</div>
                <div class="value text-danger"><?= number_format($stats['failed']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">OTP Failures</div>
                <div class="value text-warning"><?= number_format($stats['otp_failed']) ?></div>
            </div>
            <div class="stat-card">
                <div class="label">Today's Events</div>
                <div class="value text-primary"><?= number_format($stats['today']) ?></div>
            </div>
        </div>

        <!-- ===== FILTERS ===== -->
        <div class="filter-card">
            <form method="GET" class="filter-row">
                <div>
                    <label class="form-label">Search</label>
                    <input type="text" name="search" class="form-control" 
                           placeholder="Username, action, IP..." 
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
                        <option value="success" <?= $status === 'success' ? 'selected' : '' ?>>Success</option>
                        <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="info" <?= $status === 'info' ? 'selected' : '' ?>>Info</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">OTP Status</label>
                    <select name="otp_status" class="form-select">
                        <option value="">All OTP</option>
                        <option value="verified" <?= $otp_status === 'verified' ? 'selected' : '' ?>>Verified</option>
                        <option value="failed" <?= $otp_status === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="expired" <?= $otp_status === 'expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="pending" <?= $otp_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="not_required" <?= $otp_status === 'not_required' ? 'selected' : '' ?>>Not Required</option>
                    </select>
                </div>
                <div style="display:flex;gap:0.5rem;">
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-search me-1"></i> Filter
                    </button>
                    <?php if ($search || $status || $otp_status || $date): ?>
                        <a href="security_logs.php" class="btn-clear">
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
                    Security Events
                    <span class="badge-count"><?= number_format($total) ?> records</span>
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="min-width:160px;">Date &amp; Time</th>
                            <th>Admin</th>
                            <th>Action</th>
                            <th>Status</th>
                            <th>OTP</th>
                            <th>IP Address</th>
                            <th>Device</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x d-block mb-2" style="color:#d0d7e2;"></i>
                                    No security events found matching your criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td data-label="Date &amp; Time">
                                        <div style="font-weight:500;font-size:0.8rem;">
                                            <?= date('d M Y', strtotime($log['created_at'])) ?>
                                        </div>
                                        <div class="text-muted-small">
                                            <?= date('H:i:s', strtotime($log['created_at'])) ?>
                                        </div>
                                    </td>
                                    <td data-label="Admin">
                                        <strong><?= htmlspecialchars($log['username'] ?: 'Unknown') ?></strong>
                                    </td>
                                    <td data-label="Action">
                                        <span style="font-size:0.82rem;"><?= htmlspecialchars($log['action']) ?></span>
                                    </td>
                                    <td data-label="Status">
                                        <span class="status-badge <?= $log['status'] ?>">
                                            <?= ucfirst($log['status']) ?>
                                        </span>
                                    </td>
                                    <td data-label="OTP">
                                        <?php if ($log['otp_status']): ?>
                                            <span class="otp-badge <?= $log['otp_status'] ?>">
                                                <?= ucfirst(str_replace('_', ' ', $log['otp_status'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted-small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="IP Address">
                                        <span class="ip-address"><?= htmlspecialchars($log['ip_address']) ?></span>
                                    </td>
                                    <td data-label="Device">
                                        <span style="font-size:0.75rem;color:#6b7a8f;">
                                            <?= htmlspecialchars($log['device_info'] ?: '-') ?>
                                        </span>
                                    </td>
                                    <td data-label="Details">
                                        <?php if ($log['details']): ?>
                                            <span class="details-text" title="<?= htmlspecialchars($log['details']) ?>">
                                                <?= htmlspecialchars($log['details']) ?>
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
            <?php if ($total > $per_page): ?>
                <div class="pagination-wrap">
                    <div class="pagination-info">
                        Showing <?= ($page - 1) * $per_page + 1 ?> to 
                        <?= min($page * $per_page, $total) ?> of 
                        <?= number_format($total) ?> records
                    </div>
                    <nav>
                        <ul class="pagination">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1, 'export' => null]))) ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            <?php 
                            $total_pages = max(1, ceil($total / $per_page));
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