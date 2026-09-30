<?php
require_once '../includes/config.php';
require_admin_session($pdo);
require_permission($pdo, 'students.bulk_upload');

$error    = '';
$success  = '';
$errors   = [];
$inserted = 0;
$skipped  = 0;

// ───────────────────────────────────────────────
// Download CSV Template
// ───────────────────────────────────────────────
if (isset($_GET['download_template']) && $_GET['download_template'] == '1') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_template.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Roll No', 'Student Name', 'Email']);
    fputcsv($output, ['22mcan028', 'Ravi Vishnoi', 'ravivishnoi57@gmail.com']);
    fputcsv($output, ['23BCOL2248', 'Kunjit Dulani', 'kunjitdulani@gmail.com']);
    fclose($output);
    exit();
}

// ───────────────────────────────────────────────
// Handle CSV Upload
// ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && verify_csrf_token($_POST['csrf_token'])) {

    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'No file uploaded or a file upload error occurred.';
    } else {
        $tmp_name = $_FILES['csv_file']['tmp_name'];
        $ext      = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));

        if ($ext !== 'csv') {
            $error = 'Only .csv files are allowed.';
        } elseif ($_FILES['csv_file']['size'] > 5_000_000) {
            $error = 'File size exceeds the 5 MB limit.';
        } else {
            $handle = fopen($tmp_name, 'r');
            if ($handle === false) {
                $error = 'Could not open the uploaded file.';
            } else {
                try {
                    $pdo->beginTransaction();

                    // ── Validate header row ──────────────────────────
                    $headers = fgetcsv($handle, 1000, ',');
                    if ($headers === false) {
                        throw new RuntimeException('The CSV file appears to be empty.');
                    }

                    // Strip UTF-8 BOM from first column header
                    $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");

                    $expected_headers = ['roll no', 'student name', 'email'];
                    if (count($headers) < 3) {
                        throw new RuntimeException('CSV must have exactly 3 columns: Roll No, Student Name, Email.');
                    }

                    foreach ($expected_headers as $i => $expected) {
                        if (strtolower(trim($headers[$i])) !== $expected) {
                            throw new RuntimeException(
                                "Column " . ($i + 1) . " header should be \"$expected\" but found \"" . trim($headers[$i]) . "\"."
                            );
                        }
                    }

                    // ── Process data rows ────────────────────────────
                    $seen_rolls = [];
                    $row_num    = 1; // starts after header

                    while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                        $row_num++;

                        // Skip completely empty rows
                        if (count(array_filter($data, 'strlen')) === 0) continue;

                        if (count($data) < 3) {
                            $errors[] = "Row $row_num: Not enough columns — skipped.";
                            continue;
                        }

                        $roll  = sanitize_input(trim($data[0]));
                        $name  = sanitize_input(trim($data[1]));
                        $email = sanitize_input(trim($data[2]));

                        // ── Field validation ─────────────────────────
                        if (empty($roll) || empty($name) || empty($email)) {
                            $errors[] = "Row $row_num: Missing required field (Roll No, Name, or Email) — skipped.";
                            continue;
                        }

                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $errors[] = "Row $row_num: Invalid email \"$email\" for roll \"$roll\" — skipped.";
                            continue;
                        }

                        // ── Duplicate check within file ───────────────
                        if (isset($seen_rolls[$roll])) {
                            $skipped++;
                            $errors[] = "Row $row_num: Duplicate roll \"$roll\" in CSV — skipped.";
                            continue;
                        }
                        $seen_rolls[$roll] = true;

                        // ── Duplicate check in DB ─────────────────────
                        $chk = $pdo->prepare("SELECT student_name FROM students WHERE roll_no = ?");
                        $chk->execute([$roll]);
                        $existing = $chk->fetch();

                        if ($existing) {
                            $skipped++;
                            $errors[] = "Row $row_num: Roll \"$roll\" ({$existing['student_name']}) already exists in the database — skipped.";
                            continue;
                        }

                        // ── Insert ────────────────────────────────────
                        try {
                            $ins = $pdo->prepare("INSERT INTO students (roll_no, student_name, email) VALUES (?, ?, ?)");
                            $ins->execute([$roll, $name, $email]);
                            $inserted++;
                        } catch (PDOException $e) {
                            if (($e->errorInfo[1] ?? null) == 1062) {
                                // Unique constraint violation (race condition)
                                $skipped++;
                                $errors[] = "Row $row_num: Roll \"$roll\" already exists (concurrent insert) — skipped.";
                            } else {
                                throw $e;
                            }
                        }
                    }

                    $pdo->commit();
                    log_activity($pdo, "Bulk uploaded students: $inserted inserted, $skipped duplicates skipped", $_SESSION['admin_id']);

                    $success = "$inserted student(s) inserted successfully." . ($skipped > 0 ? " $skipped duplicate(s) skipped." : '');

                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Bulk student upload error: ' . $e->getMessage());
                    $error   = $e->getMessage() ?: 'An unexpected error occurred. Please check the file and try again.';
                    $success = '';
                    $errors  = [];
                } finally {
                    fclose($handle);
                }
            }
        }
    }
}

include '../includes/header.php';
?>

<div class="admin-page-shell">
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 admin-page-content">

                <!-- ===== Page Header ===== -->
                <div class="admin-page-header mb-4">
                    <div>
                        <h1>
                            <i class="fas fa-file-csv"></i>
                            Bulk Upload Students
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-users me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                            Upload multiple students at once using a CSV file
                        </div>
                    </div>
                    <div class="header-actions">
                        <a href="manage_students.php" class="btn-back-dashboard">
                            <i class="fas fa-arrow-left"></i>
                            Back to Students
                        </a>
                    </div>
                </div>

                <!-- ===== Alerts ===== -->
                <?php if ($error): ?>
                    <div class="alert-mgmt alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Error:</strong> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert-mgmt alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Done!</strong> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <!-- ===== Partial Errors (row-level issues) ===== -->
                <?php if (!empty($errors)): ?>
                    <div class="table-card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-exclamation-triangle text-warning me-2"></i>
                                Row-Level Issues
                                <span class="badge-count"><?php echo count($errors); ?></span>
                            </h5>
                        </div>
                        <div class="p-3">
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($errors as $e): ?>
                                    <li class="d-flex align-items-start gap-2 py-1 border-bottom" style="font-size:0.875rem; color:#4a5a6e;">
                                        <i class="fas fa-dot-circle text-danger mt-1" style="font-size:0.6rem;"></i>
                                        <?php echo htmlspecialchars($e, ENT_QUOTES, 'UTF-8'); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- ===== Upload Form ===== -->
                    <div class="col-lg-7">
                        <div class="table-card h-100">
                            <div class="card-header">
                                <h5>
                                    <i class="fas fa-upload text-muted"></i>
                                    Upload CSV File
                                </h5>
                            </div>
                            <div class="p-4">
                                <form method="POST" enctype="multipart/form-data" id="bulkUploadForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">

                                    <!-- File Drop Zone -->
                                    <div class="mb-4">
                                        <label class="form-label fw-semibold" for="csv_file_input">
                                            CSV File <span class="text-danger">*</span>
                                        </label>
                                        <div id="drop-zone" class="p-4 text-center rounded-3 border-2 border-dashed position-relative" style="border: 2px dashed #d0d7e2; cursor:pointer; background:#f8fafc; transition: all 0.2s;">
                                            <input type="file" name="csv_file" id="csv_file_input" accept=".csv" required class="position-absolute top-0 start-0 w-100 h-100 opacity-0" style="cursor:pointer;">
                                            <div id="drop-zone-content">
                                                <i class="fas fa-file-csv fa-2x mb-2" style="color:#b71d25;"></i>
                                                <p class="mb-1 fw-semibold" style="color:#1a2634;">Drop your CSV file here</p>
                                                <p class="text-muted small mb-0">or click to browse &nbsp;·&nbsp; Max 5 MB</p>
                                            </div>
                                            <div id="drop-zone-selected" class="d-none">
                                                <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                                                <p class="mb-0 fw-semibold" id="selected-file-name" style="color:#1a2634;"></p>
                                                <p class="text-muted small mb-0" id="selected-file-size"></p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="submit" class="btn-create-admin" id="uploadBtn">
                                            <i class="fas fa-upload"></i>
                                            Upload Students
                                        </button>
                                        <a href="?download_template=1" class="btn-back-dashboard">
                                            <i class="fas fa-download"></i>
                                            Download Template
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- ===== Instructions Panel ===== -->
                    <div class="col-lg-5">
                        <div class="table-card h-100">
                            <div class="card-header">
                                <h5>
                                    <i class="fas fa-info-circle text-muted"></i>
                                    Instructions
                                </h5>
                            </div>
                            <div class="p-4">
                                <p class="text-muted small mb-3">Follow these steps to successfully bulk upload students:</p>

                                <ol class="list-unstyled" style="counter-reset: step-counter;">
                                    <?php
                                    $steps = [
                                        ['icon' => 'fa-download', 'title' => 'Download the Template', 'desc' => 'Click "Download Template" to get the correct CSV format.'],
                                        ['icon' => 'fa-edit', 'title' => 'Fill in the Data', 'desc' => 'Add student data: Roll No, Student Name, Email — one row per student.'],
                                        ['icon' => 'fa-upload', 'title' => 'Upload the File', 'desc' => 'Select your completed CSV and click "Upload Students".'],
                                        ['icon' => 'fa-check-circle', 'title' => 'Review Results', 'desc' => 'Inserted and skipped counts will be shown. Row-level issues are listed above.'],
                                    ];
                                    foreach ($steps as $step): ?>
                                        <li class="d-flex gap-3 mb-3">
                                            <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-circle" style="width:36px;height:36px;background:rgba(183,29,37,0.1);">
                                                <i class="fas <?php echo $step['icon']; ?>" style="color:#b71d25;font-size:0.8rem;"></i>
                                            </div>
                                            <div>
                                                <div class="fw-semibold" style="font-size:0.9rem;color:#1a2634;"><?php echo $step['title']; ?></div>
                                                <div class="text-muted" style="font-size:0.82rem;"><?php echo $step['desc']; ?></div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>

                                <hr class="my-3">

                                <div>
                                    <p class="fw-semibold mb-2" style="font-size:0.85rem;color:#1a2634;">
                                        <i class="fas fa-table me-1" style="color:#b71d25;"></i>
                                        Required CSV Format
                                    </p>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered mb-0" style="font-size:0.78rem;">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Roll No</th>
                                                    <th>Student Name</th>
                                                    <th>Email</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td><code>22mcan028</code></td>
                                                    <td>Ravi Vishnoi</td>
                                                    <td>ravi@example.com</td>
                                                </tr>
                                                <tr>
                                                    <td><code>23BCOL2248</code></td>
                                                    <td>Kunjit Dulani</td>
                                                    <td>kunjit@example.com</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="mt-3 p-3 rounded-3" style="background:#fff8e1;border-left:4px solid #f59e0b;">
                                    <p class="mb-0 small" style="color:#78350f;">
                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                        <strong>Note:</strong> Duplicate roll numbers (within CSV or already in database) will be <em>skipped</em>, not overwritten.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>
</div>

<script>
(function () {
    const dropZone     = document.getElementById('drop-zone');
    const fileInput    = document.getElementById('csv_file_input');
    const contentOrig  = document.getElementById('drop-zone-content');
    const contentSel   = document.getElementById('drop-zone-selected');
    const nameEl       = document.getElementById('selected-file-name');
    const sizeEl       = document.getElementById('selected-file-size');

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(2) + ' MB';
    }

    function showSelected(file) {
        contentOrig.classList.add('d-none');
        contentSel.classList.remove('d-none');
        nameEl.textContent = file.name;
        sizeEl.textContent = formatBytes(file.size);
        dropZone.style.borderColor = '#10b981';
        dropZone.style.background = '#f0fdf4';
    }

    function resetZone() {
        contentOrig.classList.remove('d-none');
        contentSel.classList.add('d-none');
        dropZone.style.borderColor = '#d0d7e2';
        dropZone.style.background = '#f8fafc';
    }

    fileInput.addEventListener('change', function () {
        if (fileInput.files && fileInput.files.length > 0) {
            showSelected(fileInput.files[0]);
        } else {
            resetZone();
        }
    });

    ['dragover', 'dragenter'].forEach(evt => {
        dropZone.addEventListener(evt, e => {
            e.preventDefault();
            dropZone.style.borderColor = '#b71d25';
            dropZone.style.background = '#fff0f0';
        });
    });

    ['dragleave', 'dragend'].forEach(evt => {
        dropZone.addEventListener(evt, () => {
            dropZone.style.borderColor = '#d0d7e2';
            dropZone.style.background = '#f8fafc';
        });
    });

    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            fileInput.files = files;
            showSelected(files[0]);
        }
    });
}());
</script>

<?php include '../includes/footer.php'; ?>