<?php
require_once '../includes/config.php';

require_admin_session($pdo);

// Check permission for student management
if (!is_super_admin($pdo) && !has_permission($pdo, 'students.view')) {
    require_permission($pdo, 'students.view');
}

$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
$error = '';
$success = '';

// Handle individual and bulk student mutations before loading the refreshed list.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $selected_ids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['student_ids'] ?? [])),
            static fn($id) => $id > 0
        )));

        if ($action === 'bulk_delete') {
            if (!is_super_admin($pdo) && !has_permission($pdo, 'students.bulk_delete')) {
                $error = 'You do not have permission to bulk delete students.';
            } elseif (!$selected_ids) {
                $error = 'Select at least one student to delete.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                    $stmt = $pdo->prepare(
                        "SELECT s.id, COUNT(sr.id) AS result_count
                         FROM students s
                         LEFT JOIN student_results sr
                           ON sr.roll_no COLLATE utf8mb4_general_ci = s.roll_no COLLATE utf8mb4_general_ci
                         WHERE s.id IN ($placeholders)
                         GROUP BY s.id"
                    );
                    $stmt->execute($selected_ids);
                    $eligible_ids = [];
                    $blocked_count = 0;
                    while ($student = $stmt->fetch()) {
                        if ((int) $student['result_count'] > 0) {
                            $blocked_count++;
                        } else {
                            $eligible_ids[] = (int) $student['id'];
                        }
                    }

                    if ($eligible_ids) {
                        $delete_placeholders = implode(',', array_fill(0, count($eligible_ids), '?'));
                        $stmt = $pdo->prepare("DELETE FROM students WHERE id IN ($delete_placeholders)");
                        $stmt->execute($eligible_ids);
                        log_activity($pdo, 'Bulk deleted ' . count($eligible_ids) . ' student(s)', $_SESSION['admin_id']);
                    }

                    $pdo->commit();

                    $success = count($eligible_ids) . ' student(s) deleted successfully.';
                    if ($blocked_count > 0) {
                        $error = "$blocked_count student(s) were not deleted because they have existing results.";
                    }
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Bulk student delete database error.');
                    $error = 'Unable to delete the selected students right now. Please try again.';
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            require_permission($pdo, 'students.delete');
            $id = (int) $_POST['id'];
            // Check if student has results
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM student_results sr JOIN students s ON sr.roll_no COLLATE utf8mb4_general_ci = s.roll_no COLLATE utf8mb4_general_ci WHERE s.id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                $error = "Cannot delete student: they have existing results. Delete results first.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM students WHERE id = ?");
                $stmt->execute([$id]);
                log_activity($pdo, "Deleted student ID: $id", $_SESSION['admin_id']);
                header("Location: manage_students.php?msg=deleted");
                exit();
            }
        }
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$allowed_per_page = [10, 20, 50, 100];
$per_page = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowed_per_page) ? (int)$_GET['per_page'] : 50;
$offset = ($page - 1) * $per_page;

$query = "SELECT * FROM students";
$count_query = "SELECT COUNT(*) FROM students";
$params = [];

if ($search) {
    $where = " WHERE roll_no LIKE ? OR student_name LIKE ? OR email LIKE ?";
    $query .= $where;
    $count_query .= $where;
    $term = "%$search%";
    $params = [$term, $term, $term];
}

$stmt_count = $pdo->prepare($count_query);
$stmt_count->execute($params);
$total_students = $stmt_count->fetchColumn();
$total_pages = ceil($total_students / $per_page);

$query .= " ORDER BY roll_no ASC LIMIT " . (int)$per_page . " OFFSET " . (int)$offset;
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

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
                            <i class="fas fa-users"></i>
                            Manage Students
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-graduation-cap me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                            View, add, and manage student records and details
                        </div>
                    </div>
                    <div class="header-actions">
                        <?php if ($is_super_admin || has_permission($pdo, 'students.create')): ?>
                            <a href="add_student.php" class="btn-create-admin">
                                <i class="fas fa-plus"></i> Add Student
                            </a>
                        <?php endif; ?>
                        <?php if ($is_super_admin || has_permission($pdo, 'students.bulk_edit')): ?>
                            <a href="bulk_edit_students.php" class="btn-back-dashboard">
                                <i class="fas fa-file-import"></i> Bulk Edit
                            </a>
                        <?php endif; ?>
                        <?php if ($is_super_admin || has_permission($pdo, 'students.bulk_upload')): ?>
                            <a href="bulk_upload_students.php" class="btn-back-dashboard">
                                <i class="fas fa-file-csv"></i> Bulk Upload
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (isset($_GET['msg']) && $_GET['msg'] == 'deleted'): ?>
                    <div class="alert-mgmt alert-success">
                        <i class="fas fa-check-circle me-2"></i> Student deleted successfully.
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert-mgmt alert-success">
                        <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert-mgmt alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="table-card" id="students-table">
                    <div class="card-header border-bottom py-3">
                        <div class="w-100 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5>
                                <i class="fas fa-list text-muted"></i>
                                Student Directory
                                <span class="badge-count"><?php echo $total_students; ?></span>
                            </h5>
                            
                            <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="text-muted small text-nowrap">Show:</span>
                                    <select name="per_page" class="form-select form-select-sm" style="width: 80px;" onchange="this.form.submit()">
                                        <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10</option>
                                        <option value="20" <?php echo $per_page == 20 ? 'selected' : ''; ?>>20</option>
                                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                                    </select>
                                </div>
                                <div class="input-group input-group-sm" style="width: 250px;">
                                    <input type="text" name="search" class="form-control" placeholder="Search by Roll No or Name..." value="<?php echo htmlspecialchars($search); ?>">
                                    <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search"></i></button>
                                </div>
                                <?php if ($search): ?><a href="manage_students.php" class="btn btn-sm btn-outline-danger">Clear</a><?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <?php if ($is_super_admin || has_permission($pdo, 'students.bulk_delete')): ?>
                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <form method="POST" id="bulkStudentForm" class="d-flex align-items-center gap-3">
                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                <input type="hidden" name="action" id="bulkAction" value="">
                                
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="selectAllStudents" form="bulkStudentForm">
                                    <label class="form-check-label fw-bold" for="selectAllStudents">Select All</label>
                                </div>
                                
                                <button type="button" id="bulkDeleteButton" class="btn btn-sm btn-outline-danger" disabled>
                                    <i class="fas fa-trash me-1"></i> Bulk Delete
                                </button>
                                
                                <span class="small text-muted" id="selectionStatus">No students selected.</span>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <?php if ($is_super_admin || has_permission($pdo, 'students.bulk_delete')): ?>
                                        <th scope="col" aria-label="Select student" style="width: 40px;"></th>
                                    <?php endif; ?>
                                    <th>Roll No</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Created</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($students) > 0): ?>
                                    <?php foreach ($students as $s): ?>
                                        <tr>
                                            <?php if ($is_super_admin || has_permission($pdo, 'students.bulk_delete')): ?>
                                                <td>
                                                    <input class="form-check-input student-selector" type="checkbox" name="student_ids[]" value="<?php echo (int) $s['id']; ?>" form="bulkStudentForm" aria-label="Select <?php echo htmlspecialchars($s['student_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                </td>
                                            <?php endif; ?>
                                            <td data-label="Roll No"><strong><?php echo sanitize_input($s['roll_no']); ?></strong></td>
                                            <td data-label="Name"><?php echo sanitize_input($s['student_name']); ?></td>
                                            <td data-label="Email"><?php echo sanitize_input($s['email']); ?></td>
                                            <td data-label="Created"><?php echo date('d M Y', strtotime($s['created_at'])); ?></td>
                                            <td data-label="Actions" class="text-end">
                                                <div class="action-group justify-content-end">
                                                    <?php if ($is_super_admin || has_permission($pdo, 'students.edit')): ?>
                                                        <a href="edit_student.php?id=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-primary" title="Edit Student">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($is_super_admin || has_permission($pdo, 'students.delete')): ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this student? (No results linked)')">
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                                                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Student">
                                                                <i class="fas fa-trash"></i> Delete
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No students found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="card-footer bg-white border-top py-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div class="text-muted small">
                                Showing <?php echo count($students); ?> of <?php echo $total_students; ?> students
                            </div>
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&per_page=<?php echo $per_page; ?>&search=<?php echo urlencode($search); ?>"><i class="fas fa-chevron-left"></i></a>
                                </li>
                                <?php 
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                for ($i = $start_page; $i <= $end_page; $i++): 
                                ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&per_page=<?php echo $per_page; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&per_page=<?php echo $per_page; ?>&search=<?php echo urlencode($search); ?>"><i class="fas fa-chevron-right"></i></a>
                                </li>
                            </ul>
                        </div>
                    <?php endif; ?>

                </div><!-- /table-card -->
            </main>
        </div>
    </div>
</div>

<script>
    (function () {
        const form = document.getElementById('bulkStudentForm');
        const actionInput = document.getElementById('bulkAction');
        const selectAll = document.getElementById('selectAllStudents');
        const selectors = Array.from(document.querySelectorAll('.student-selector'));
        const deleteButton = document.getElementById('bulkDeleteButton');
        const status = document.getElementById('selectionStatus');

        function selectedStudents() {
            return selectors.filter((selector) => selector.checked);
        }

        function updateSelection() {
            const count = selectedStudents().length;
            if (deleteButton) deleteButton.disabled = count === 0;
            if (selectAll) {
                selectAll.checked = selectors.length > 0 && count === selectors.length;
                selectAll.indeterminate = count > 0 && count < selectors.length;
            }
            if (status) status.textContent = count === 0 ? 'No students selected.' : count + ' student(s) selected.';
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                selectors.forEach((selector) => { selector.checked = selectAll.checked; });
                updateSelection();
            });
        }
        selectors.forEach((selector) => selector.addEventListener('change', updateSelection));

        if (deleteButton) {
            deleteButton.addEventListener('click', function () {
                actionInput.value = 'bulk_delete';
                if (window.confirm('Delete ' + selectedStudents().length + ' selected student(s)? Students with existing results will be skipped.')) {
                    form.submit();
                }
            });
        }

        updateSelection();
    }());
</script>

<?php include '../includes/footer.php'; ?>