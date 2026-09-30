<?php
require_once '../includes/config.php';
require_admin_session($pdo);
require_permission($pdo, 'students.bulk_edit');

$error = '';
$success = '';
$preview = null;
$preview_token = '';
$search_term = '';
$filter = 'all';
$page = 1;
$per_page = 25;
$allowed_per_page = [25, 50, 100];
$allowed_filters = ['all', 'valid', 'invalid', 'unmatched', 'errors'];

// Helper to normalize CSV header
function bulk_edit_normalize_header($header)
{
    return strtolower(trim(ltrim((string) $header, "\xEF\xBB\xBF")));
}

// Download template
if (isset($_GET['download_template']) && $_GET['download_template'] === '1') {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_bulk_edit_template.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Roll No', 'Student Name', 'Email']);
    fputcsv($output, ['22mcan028', 'Updated Student Name', 'student@example.com']);
    fclose($output);
    exit();
}

if (isset($_GET['download_errors']) && $_GET['download_errors'] === '1') {
    $stored_preview = $_SESSION['bulk_edit_preview'] ?? null;
    if (!$stored_preview) {
        http_response_code(404);
        exit('No validation report is available.');
    }
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_bulk_edit_errors.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Source Row', 'Roll No', 'Student Name', 'Email', 'Issue']);
    foreach ($stored_preview['rows'] as $row) {
        if ($row['status'] !== 'valid') {
            fputcsv($output, [$row['row'], $row['roll_no'], $row['student_name'], $row['email'], $row['error']]);
        }
    }
    fclose($output);
    exit();
}

if (isset($_GET['download_result']) && $_GET['download_result'] === '1') {
    $result_report = $_SESSION['bulk_edit_result_report'] ?? null;
    if (!$result_report) {
        http_response_code(404);
        exit('No update report is available.');
    }
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_bulk_edit_update_report.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Source Row', 'Roll No', 'Student Name', 'Email', 'Status', 'Issue']);
    foreach ($result_report as $row) {
        fputcsv($output, [$row['row'], $row['roll_no'], $row['student_name'], $row['email'], $row['status'], $row['error'] ?? '']);
    }
    fclose($output);
    exit();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $error = 'Invalid security token. Please refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'cancel_preview') {
        unset($_SESSION['bulk_edit_preview']);
        unset($_SESSION['bulk_edit_summary']);
        header('Location: bulk_edit_students.php');
        exit();
    }

    if ($action === 'confirm_update') {
        $stored_preview = $_SESSION['bulk_edit_preview'] ?? null;
        $submitted_token = (string) ($_POST['preview_token'] ?? '');
        if (!$stored_preview || !hash_equals((string) ($stored_preview['token'] ?? ''), $submitted_token)) {
            $error = 'This preview has expired. Upload the file again to continue.';
        } else {
            $valid_records = array_filter($stored_preview['rows'], static fn($row) => $row['status'] === 'valid');
            if (empty($valid_records)) {
                $error = 'There are no valid matched students to update.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $records = array_values($valid_records);
                    $updated = 0;
                    $failed = 0;

                    foreach (array_chunk($records, 250) as $chunk) {
                        $value_selects = [];
                        $params = [];

                        foreach ($chunk as $record) {
                            $value_selects[] = 'SELECT ? AS roll_no, ? AS student_name, ? AS email';
                            $params[] = $record['roll_no'];
                            $params[] = $record['student_name'];
                            $params[] = $record['email'];
                        }

                        $sql = 'UPDATE students AS s
                                INNER JOIN (' . implode(' UNION ALL ', $value_selects) . ') AS updates
                                    ON updates.roll_no = s.roll_no
                                SET s.student_name = updates.student_name,
                                    s.email = updates.email';

                        $stmt = $pdo->prepare($sql);
                        if (!$stmt->execute($params)) {
                            $errorInfo = $stmt->errorInfo();
                            throw new PDOException("SQL execution failed: " . implode(' ', $errorInfo));
                        }
                        // Every valid, matched row was included in the atomic update.
                        $updated += count($chunk);
                    }

                    log_activity($pdo, "Bulk edited $updated student(s) from CSV", $_SESSION['admin_id']);
                    $pdo->commit();

                    unset($_SESSION['bulk_edit_preview']);
                    unset($_SESSION['bulk_edit_summary']);

                    $_SESSION['bulk_edit_result'] = [
                        'total' => count($stored_preview['rows']),
                        'updated' => $updated,
                        'failed' => $failed,
                        'not_found' => count(array_filter($stored_preview['rows'], static fn($row) => $row['status'] === 'unmatched')),
                        'invalid' => count(array_filter($stored_preview['rows'], static fn($row) => $row['status'] === 'invalid')),
                    ];
                    $_SESSION['bulk_edit_result_report'] = array_map(static function ($row) {
                        if ($row['status'] === 'valid') {
                            $row['status'] = 'updated';
                        }
                        return $row;
                    }, $stored_preview['rows']);
                    header('Location: bulk_edit_students.php?result=1');
                    exit();

                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Bulk edit PDO error: ' . $e->getMessage());
                    error_log('SQL State: ' . $e->getCode());
                    if (isset($sql)) {
                        error_log('Failed SQL: ' . $sql);
                    }

                    // Show detailed error in development, generic in production
                    if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
                        $error = 'Database error: ' . htmlspecialchars($e->getMessage());
                        if (isset($sql)) {
                            $error .= '<br><br><strong>SQL:</strong> <pre>' . htmlspecialchars($sql) . '</pre>';
                        }
                    } else {
                        $error = 'A database error occurred. No records were changed. Please check the server logs for details.';
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Bulk edit general error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                    if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
                        $error = 'Error: ' . htmlspecialchars($e->getMessage());
                    } else {
                        $error = 'An unexpected error occurred. No records were changed.';
                    }
                }
            }
        }
    } elseif ($action === 'validate_upload') {
        // ------------------------------------------------------------
        // File upload and validation (unchanged – works correctly)
        // ------------------------------------------------------------
        if (!isset($_FILES['student_file']) || $_FILES['student_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Choose a CSV file to upload.';
        } else {
            $file = $_FILES['student_file'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($extension !== 'csv') {
                $error = 'Only CSV files are supported. Use the download template for the required format.';
            } elseif ($file['size'] > 5000000) {
                $error = 'File size exceeds 5MB.';
            } elseif (($handle = fopen($file['tmp_name'], 'r')) === false) {
                $error = 'The uploaded file could not be opened.';
            } else {
                $headers = fgetcsv($handle, 1000, ',');
                $expected_headers = ['roll no', 'student name', 'email'];
                if ($headers === false || count($headers) < 3) {
                    $error = 'The file must contain Roll No, Student Name, and Email columns.';
                } else {
                    foreach ($expected_headers as $index => $expected) {
                        if (bulk_edit_normalize_header($headers[$index]) !== $expected) {
                            $error = 'Column ' . ($index + 1) . ' must be "' . ucwords($expected) . '".';
                            break;
                        }
                    }
                }

                $rows = [];
                $seen_rolls = [];
                $row_number = 1;
                $all_rows = [];

                if (!$error) {
                    while (($data = fgetcsv($handle, 2000, ',')) !== false) {
                        $row_number++;
                        if (count($data) < 3 || count(array_filter($data, static fn($value) => trim((string) $value) !== '')) === 0) {
                            continue;
                        }
                        $roll_no = trim((string) $data[0]);
                        $student_name = trim((string) $data[1]);
                        $email = trim((string) $data[2]);
                        $row_error = '';
                        $status = 'valid';
                        if ($roll_no === '' || strlen($roll_no) > 50) {
                            $row_error = 'Missing or invalid Roll No.';
                            $status = 'invalid';
                        } elseif (isset($seen_rolls[strtolower($roll_no)])) {
                            $row_error = 'Duplicate Roll No. in file.';
                            $status = 'invalid';
                        } elseif ($student_name === '' || strlen($student_name) > 255) {
                            $row_error = 'Missing or invalid Student Name.';
                            $status = 'invalid';
                        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                            $row_error = 'Invalid or missing Email.';
                            $status = 'invalid';
                        } else {
                            $seen_rolls[strtolower($roll_no)] = true;
                            // We'll check later if roll exists in DB
                        }
                        $all_rows[] = [
                            'row' => $row_number,
                            'roll_no' => $roll_no,
                            'student_name' => $student_name,
                            'email' => $email,
                            'status' => $status,
                            'error' => $row_error,
                        ];
                    }
                }
                fclose($handle);

                if (!$error && empty($all_rows)) {
                    $error = 'No data rows were found in the uploaded file.';
                } elseif (!$error) {
                    // Now check existence in DB for valid rows
                    $valid_row_indexes = array_keys(array_filter($all_rows, static fn($row) => $row['status'] === 'valid'));
                    if ($valid_row_indexes) {
                        $rolls_to_check = array_map(static fn($index) => $all_rows[$index]['roll_no'], $valid_row_indexes);
                        $matched = [];
                        foreach (array_chunk($rolls_to_check, 500) as $chunk) {
                            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                            $stmt = $pdo->prepare("SELECT roll_no FROM students WHERE roll_no IN ($placeholders)");
                            $stmt->execute($chunk);
                            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $roll_no) {
                                $matched[strtolower($roll_no)] = true;
                            }
                        }
                        foreach ($valid_row_indexes as $index) {
                            if (!isset($matched[strtolower($all_rows[$index]['roll_no'])])) {
                                $all_rows[$index]['status'] = 'unmatched';
                                $all_rows[$index]['error'] = 'Student not found in database.';
                            }
                        }
                    }

                    $token = bin2hex(random_bytes(32));
                    $_SESSION['bulk_edit_preview'] = [
                        'token' => $token,
                        'rows' => $all_rows,
                    ];
                    $_SESSION['bulk_edit_summary'] = [
                        'total' => count($all_rows),
                        'valid' => count(array_filter($all_rows, static fn($row) => $row['status'] === 'valid')),
                        'invalid' => count(array_filter($all_rows, static fn($row) => $row['status'] === 'invalid')),
                        'unmatched' => count(array_filter($all_rows, static fn($row) => $row['status'] === 'unmatched')),
                    ];
                    header('Location: bulk_edit_students.php');
                    exit();
                }
            }
        }
    }
}

// Check for result display
if (isset($_GET['result']) && $_GET['result'] == 1 && isset($_SESSION['bulk_edit_result'])) {
    $result = $_SESSION['bulk_edit_result'];
    unset($_SESSION['bulk_edit_result']);
    $success = "Update completed successfully!<br>
                Total records: {$result['total']}<br>
                Updated successfully: {$result['updated']}<br>
                Failed: {$result['failed']}<br>
                Not found: {$result['not_found']}<br>
                Invalid: {$result['invalid']}";
}

// Pagination and filters
$preview_data = $_SESSION['bulk_edit_preview'] ?? null;
$summary = $_SESSION['bulk_edit_summary'] ?? null;

if ($preview_data && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    // No action needed
}

// Get filter and search from GET
$filter = isset($_GET['filter']) && in_array($_GET['filter'], $allowed_filters, true) ? $_GET['filter'] : 'all';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$per_page = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $allowed_per_page) ? (int)$_GET['per_page'] : 25;

// Prepare data for pagination
$all_rows = $preview_data['rows'] ?? [];
$filtered_rows = $all_rows;

// Apply filter
if ($filter !== 'all') {
    $filtered_rows = array_filter($filtered_rows, static function ($row) use ($filter) {
        return $filter === 'errors' ? $row['status'] !== 'valid' : $row['status'] === $filter;
    });
}

// Apply search
if ($search_term !== '') {
    $search_lower = strtolower($search_term);
    $filtered_rows = array_filter($filtered_rows, static function ($row) use ($search_lower) {
        return strpos(strtolower($row['roll_no']), $search_lower) !== false ||
               strpos(strtolower($row['student_name']), $search_lower) !== false ||
               strpos(strtolower($row['email']), $search_lower) !== false;
    });
}

$total_filtered = count($filtered_rows);
$total_pages = $total_filtered > 0 ? ceil($total_filtered / $per_page) : 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;
$paginated_rows = array_slice(array_values($filtered_rows), $offset, $per_page);

// For summary display
$valid_count = $summary ? $summary['valid'] : 0;
$invalid_count = $summary ? $summary['invalid'] : 0;
$unmatched_count = $summary ? $summary['unmatched'] : 0;
$total_count = $summary ? $summary['total'] : 0;

include '../includes/header.php';
?>

<style>
.summary-card-link {
  text-decoration: none;
  color: inherit;
  display: block;
  cursor: pointer;
}

.summary-card-link .summary-card {
  transition: var(--admin-transition, 0.22s ease);
  background-color: #fafbfd;
}

.summary-card-link:hover .summary-card {
  border-color: var(--admin-brand, #b71d25);
  box-shadow: 0 0 0 3px rgba(183, 29, 37, 0.12);
}

.summary-card-link.active .summary-card {
  border-color: var(--admin-brand, #b71d25);
  background-color: rgba(183, 29, 37, 0.04) !important;
}
</style>

<div class="admin-page-shell">
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 admin-page-content">
                <div class="admin-page-header mb-4">
                    <div>
                        <h1>
                            <i class="fas fa-file-import"></i>
                            Bulk Edit Students
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-users me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                            Upload a CSV to update existing student records in bulk
                        </div>
                    </div>
                    <div class="header-actions">
                        <a href="manage_students.php" class="btn-back-dashboard">
                            <i class="fas fa-arrow-left"></i> Back to Students
                        </a>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert-mgmt alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo nl2br(htmlspecialchars($error, ENT_QUOTES, 'UTF-8')); ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert-mgmt alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $success; ?>
                        <?php if (isset($_GET['result']) && $_GET['result'] == 1): ?>
                            <a href="?download_result=1" class="btn btn-sm btn-outline-success ms-2"><i class="fas fa-download me-1"></i> Download Update Report</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php if ($preview_data): ?>
                <!-- Clickable Summary Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <a href="?page=1&per_page=<?php echo $per_page; ?>&filter=all&search=<?php echo urlencode($search_term); ?>" 
                           class="summary-card-link <?php echo $filter === 'all' ? 'active' : ''; ?>">
                            <div class="border rounded-3 p-3 summary-card bg-light">
                                <div class="small text-muted">Total Records</div>
                                <strong class="fs-4"><?php echo number_format($total_count); ?></strong>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-3 col-6">
                        <a href="?page=1&per_page=<?php echo $per_page; ?>&filter=valid&search=<?php echo urlencode($search_term); ?>" 
                           class="summary-card-link <?php echo $filter === 'valid' ? 'active' : ''; ?>">
                            <div class="border rounded-3 p-3 summary-card bg-success bg-opacity-10">
                                <div class="small text-muted">Ready to Update</div>
                                <strong class="fs-4 text-success"><?php echo number_format($valid_count); ?></strong>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-3 col-6">
                        <a href="?page=1&per_page=<?php echo $per_page; ?>&filter=invalid&search=<?php echo urlencode($search_term); ?>" 
                           class="summary-card-link <?php echo $filter === 'invalid' ? 'active' : ''; ?>">
                            <div class="border rounded-3 p-3 summary-card bg-danger bg-opacity-10">
                                <div class="small text-muted">Invalid</div>
                                <strong class="fs-4 text-danger"><?php echo number_format($invalid_count); ?></strong>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-3 col-6">
                        <a href="?page=1&per_page=<?php echo $per_page; ?>&filter=unmatched&search=<?php echo urlencode($search_term); ?>" 
                           class="summary-card-link <?php echo $filter === 'unmatched' ? 'active' : ''; ?>">
                            <div class="border rounded-3 p-3 summary-card bg-warning bg-opacity-10">
                                <div class="small text-muted">Not Found in DB</div>
                                <strong class="fs-4 text-warning"><?php echo number_format($unmatched_count); ?></strong>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Search and Filter Bar -->
                <div class="card border-0 shadow-sm rounded-4 p-3 mb-3">
                    <form method="GET" class="row g-2 align-items-end" id="filterForm">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Search</label>
                            <input type="text" name="search" class="form-control" placeholder="Roll No, Name, or Email" value="<?php echo htmlspecialchars($search_term); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold small">Filter</label>
                            <select name="filter" class="form-select">
                                <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All</option>
                                <option value="valid" <?php echo $filter === 'valid' ? 'selected' : ''; ?>>Valid / Ready</option>
                                <option value="invalid" <?php echo $filter === 'invalid' ? 'selected' : ''; ?>>Invalid</option>
                                <option value="unmatched" <?php echo $filter === 'unmatched' ? 'selected' : ''; ?>>Not Found</option>
                                <option value="errors" <?php echo $filter === 'errors' ? 'selected' : ''; ?>>All Errors</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold small">Per Page</label>
                            <select name="per_page" class="form-select">
                                <?php foreach ($allowed_per_page as $val): ?>
                                    <option value="<?php echo $val; ?>" <?php echo $per_page == $val ? 'selected' : ''; ?>><?php echo $val; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-search me-1"></i> Apply</button>
                            <a href="bulk_edit_students.php" class="btn btn-outline-secondary rounded-pill px-3">Reset</a>
                        </div>
                    </form>
                </div>

                <!-- Paginated Preview Table -->
                <div class="card border-0 shadow-sm rounded-4 p-4">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>Row</th>
                                    <th>Roll No</th>
                                    <th>Student Name</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($paginated_rows)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4">No records match the current filter/search.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($paginated_rows as $row): ?>
                                        <tr>
                                            <td><?php echo (int) $row['row']; ?></td>
                                            <td><?php echo htmlspecialchars($row['roll_no'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php
                                                $status = $row['status'];
                                                $badgeClass = match($status) {
                                                    'valid' => 'bg-success',
                                                    'invalid' => 'bg-danger',
                                                    'unmatched' => 'bg-warning text-dark',
                                                    default => 'bg-secondary',
                                                };
                                                echo '<span class="badge ' . $badgeClass . ' rounded-pill px-3">' . ucfirst($status) . '</span>';
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['error'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Controls -->
                    <?php if ($total_filtered > $per_page): ?>
                        <nav aria-label="Page navigation" class="mt-3">
                            <ul class="pagination justify-content-center flex-wrap">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?>&per_page=<?php echo $per_page; ?>&filter=<?php echo urlencode($filter); ?>&search=<?php echo urlencode($search_term); ?>">Previous</a>
                                </li>
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                if ($start_page > 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&per_page=<?php echo $per_page; ?>&filter=<?php echo urlencode($filter); ?>&search=<?php echo urlencode($search_term); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($end_page < $total_pages): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo min($total_pages, $page + 1); ?>&per_page=<?php echo $per_page; ?>&filter=<?php echo urlencode($filter); ?>&search=<?php echo urlencode($search_term); ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>

                <!-- Sticky Action Bar -->
                <div class="sticky-bottom bg-white p-3 border-top shadow-sm rounded-4 mt-3" style="z-index: 10; bottom: 0;">
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small">Total matched: <strong><?php echo number_format($valid_count); ?></strong> records</span>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="bulk_edit_students.php" class="btn btn-outline-primary rounded-pill px-4"><i class="fas fa-file-upload me-1"></i> Upload New File</a>
                            <a href="?download_template=1" class="btn btn-outline-secondary rounded-pill px-4"><i class="fas fa-download me-1"></i> Template</a>
                            <?php if (($invalid_count + $unmatched_count) > 0): ?>
                                <a href="?page=1&per_page=<?php echo $per_page; ?>&filter=errors" class="btn btn-outline-danger rounded-pill px-4"><i class="fas fa-exclamation-circle me-1"></i> View Errors</a>
                                <a href="?download_errors=1" class="btn btn-outline-danger rounded-pill px-4"><i class="fas fa-file-download me-1"></i> Download Errors</a>
                            <?php endif; ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                <input type="hidden" name="action" value="cancel_preview">
                                <button type="submit" class="btn btn-outline-secondary rounded-pill px-4">Cancel</button>
                            </form>
                            <?php if ($valid_count > 0): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('You are about to update <?php echo number_format($valid_count); ?> student record(s). Proceed?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="action" value="confirm_update">
                                    <input type="hidden" name="preview_token" value="<?php echo htmlspecialchars($preview_data['token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn btn-primary rounded-pill px-4">
                                        <i class="fas fa-check me-1"></i> Confirm Update
                                    </button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-secondary rounded-pill px-4" disabled>No valid records to update</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Upload Form (no preview) -->
                <div class="table-card p-4">
                    <div class="alert-mgmt alert-info">
                        <i class="fas fa-info-circle me-2"></i><strong>CSV format:</strong> Roll No, Student Name, Email.<br>Roll No identifies an existing student; uploads never create new students. Valid matched rows update name and email only.
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="validate_upload">
                        <label class="form-label fw-bold" for="student_file">Student update file <span class="text-danger">*</span></label>
                        <input class="form-control mb-2" type="file" id="student_file" name="student_file" accept=".csv,text/csv" required>
                        <div class="form-text text-muted mb-3">CSV only, maximum 5MB. Use the template to avoid formatting errors.</div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" class="btn-create-admin px-4 py-2"><i class="fas fa-upload me-2"></i> Validate and Preview</button>
                            <a href="?download_template=1" class="btn-back-dashboard"><i class="fas fa-download me-2"></i> Download Template</a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            </main>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>