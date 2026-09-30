<?php
require_once '../includes/config.php';

require_admin_session($pdo);

// Check permission for exam management
if (!is_super_admin($pdo) && !has_permission($pdo, 'exams.view')) {
    require_permission($pdo, 'exams.view');
}

$success = '';
$error = '';

// Download Results CSV – per-subject semester
if (isset($_GET['action']) && $_GET['action'] == 'download' && isset($_GET['id']) && verify_csrf_token($_GET['csrf_token'])) {
    require_permission($pdo, 'exams.edit');
    
    $exam_id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch();
    if (!$exam) die("Exam not found.");

    $stmt = $pdo->prepare("
        SELECT sr.roll_no, s.student_name, s.email, sr.semester, sr.subject_name, sr.grade
        FROM student_results sr
        LEFT JOIN students s ON sr.roll_no COLLATE utf8mb4_general_ci = s.roll_no
        WHERE sr.exam_id = ?
        ORDER BY sr.roll_no, sr.subject_name
    ");
    $stmt->execute([$exam_id]);
    $results = $stmt->fetchAll();
    if (empty($results)) die("No results found for this exam.");

    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="results_exam_' . $exam_id . '_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Roll No', 'Student Name', 'Student Email', 'Semester', 'Subject Name', 'Grade']);
    foreach ($results as $row) {
        $csv_value = static function ($value) {
            $value = (string) $value;
            return preg_match('/^[=+\-@]/', ltrim($value)) ? "'" . $value : $value;
        };
        fputcsv($output, [
            $csv_value($row['roll_no']),
            $csv_value($row['student_name'] ?? ''),
            $csv_value($row['email'] ?? ''),
            $csv_value($row['semester'] ?? $exam['semester']),
            $csv_value($row['subject_name']),
            $csv_value($row['grade'])
        ]);
    }
    fclose($output);
    exit();
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id']) && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    require_permission($pdo, 'exams.delete');
    $id = (int)$_POST['id'];
    $stmt = $pdo->prepare("DELETE FROM exams WHERE id = ?");
    if ($stmt->execute([$id])) {
        log_activity($pdo, "Deleted exam ID: $id", $_SESSION['admin_id']);
        $success = "Exam and all associated results deleted successfully.";
    } else {
        $error = "Error deleting exam.";
    }
}

// Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    require_permission($pdo, 'exams.edit');
    
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $raw_status = $_POST['status'] ?? null;
    $csrf_token = $_POST['csrf_token'] ?? '';

    if ($id <= 0) {
        $error = "Invalid exam ID.";
    } elseif (!verify_csrf_token($csrf_token)) {
        $error = "Invalid security token. Please refresh and try again.";
    } else {
        try {
            $status = null;
            if (is_scalar($raw_status)) {
                $normalized_status = strtolower(trim((string) $raw_status));
                if ($normalized_status === '1' || $normalized_status === 'active') {
                    $status = 1;
                } elseif ($normalized_status === '0' || $normalized_status === 'inactive') {
                    $status = 0;
                }
            }

            $current_stmt = $pdo->prepare("SELECT status FROM exams WHERE id = ?");
            $current_stmt->execute([$id]);
            $current_status = $current_stmt->fetchColumn();

            if ($current_status === false) {
                $error = "Exam not found.";
            } elseif ($status === null) {
                $status = ((int) $current_status === 1) ? 0 : 1;
            }

            if ($error === '') {
                $stmt = $pdo->prepare("UPDATE exams SET status = ? WHERE id = ?");
                if ($stmt->execute([$status, $id])) {
                    log_activity($pdo, "Updated status for exam ID: $id to " . ($status ? 'Active' : 'Inactive'), $_SESSION['admin_id']);
                    $success = "Exam status updated successfully.";
                } else {
                    $error = "Error updating status.";
                }
            }
        } catch (PDOException $e) {
            error_log("Status update PDO error: " . $e->getMessage());
            $error = "Database error occurred. Please try again.";
        }
    }
}

// Fetch all exams with records count
$stmt = $pdo->query("
    SELECT e.*, COUNT(sr.id) AS uploaded_records
    FROM exams e
    LEFT JOIN student_results sr ON sr.exam_id = e.id
    GROUP BY e.id
    ORDER BY e.created_at DESC
");
$exams = $stmt->fetchAll();

// Generate CSRF token for forms
$csrf_token = generate_csrf_token();

include '../includes/header.php';
?>

<div class="admin-page-shell">
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 admin-page-content">
                
                <div class="admin-page-header mb-4">
                    <div>
                        <h1>
                            <i class="fas fa-list"></i>
                            Manage Exams
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-file-alt me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                            View, update, and manage examination records and settings
                        </div>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert-mgmt alert-success">
                        <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert-mgmt alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <div class="table-card" id="exams-table">
                    <div class="card-header border-bottom py-3">
                        <div class="w-100 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5>
                                <i class="fas fa-file-invoice text-muted"></i>
                                Exam Records
                                <span class="badge-count"><?php echo count($exams); ?></span>
                            </h5>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Exam Name</th>
                                    <th>Batch / Session</th>
                                    <th>Semester</th>
                                    <th>Published</th>
                                    <th>Status</th>
                                    <th>Records</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($exams) > 0): ?>
                                    <?php foreach ($exams as $row): ?>
                                        <tr>
                                            <td data-label="Exam Name">
                                                <strong><?php echo sanitize_input($row['exam_name']); ?></strong>
                                                <div class="text-muted small mt-1">ID: #EX<?php echo $row['id']; ?></div>
                                                <div class="action-group mt-2">
                                                    <?php if ($is_super_admin || has_permission($pdo, 'exams.edit')): ?>
                                                        <a href="edit_exam.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Exam">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($is_super_admin || has_permission($pdo, 'results.view')): ?>
                                                        <a href="exam_results.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-info" title="View Results">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($is_super_admin || has_permission($pdo, 'exams.view')): ?>
                                                        <a href="manage_exams.php?action=download&id=<?php echo $row['id']; ?>&csrf_token=<?php echo $csrf_token; ?>" class="btn btn-sm btn-outline-success" title="Download CSV">
                                                            <i class="fas fa-download"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($is_super_admin || has_permission($pdo, 'exams.delete')): ?>
                                                        <form method="POST" class="d-inline status-form" onsubmit="return confirm('WARNING: Are you sure you want to delete this exam? ALL associated student results will also be deleted. This cannot be undone.');">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Exam">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td data-label="Batch / Session">
                                                <div class="fw-medium text-dark"><?php echo sanitize_input($row['batch']); ?></div>
                                                <div class="text-muted small"><?php echo sanitize_input($row['session']); ?></div>
                                            </td>
                                            <td data-label="Semester"><?php echo sanitize_input($row['semester']); ?></td>
                                            <td data-label="Published">
                                                <?php echo date('M d, Y', strtotime($row['created_at'])); ?>
                                            </td>
                                            <td data-label="Status">
                                                <?php if ($is_super_admin || has_permission($pdo, 'exams.edit')): ?>
                                                    <form method="POST" class="status-form" onchange="this.submit();">
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <select name="status" class="form-select form-select-sm status-badge <?php echo $row['status'] ? 'active' : 'locked'; ?>" style="width: 100px; padding: 2px 8px; border: none; cursor: pointer; text-transform: uppercase;">
                                                            <option value="1" <?php echo $row['status'] ? 'selected' : ''; ?>>Active</option>
                                                            <option value="0" <?php echo !$row['status'] ? 'selected' : ''; ?>>Inactive</option>
                                                        </select>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="status-badge <?php echo $row['status'] ? 'active' : 'locked'; ?>">
                                                        <?php echo $row['status'] ? 'Active' : 'Inactive'; ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td data-label="Records">
                                                <span class="badge-count"><?php echo number_format((int) $row['uploaded_records']); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">No exams found.</td>
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