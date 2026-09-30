<?php
require_once '../includes/config.php';

require_admin_session($pdo);

// Check permission for result upload
if (!is_super_admin($pdo) && !has_permission($pdo, 'results.upload')) {
    require_permission($pdo, 'results.upload');
}

$success = '';
$error = '';

// Download Errors
if (isset($_GET['download_errors']) && isset($_SESSION['result_upload_errors'])) {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="result_upload_errors_' . date('Y-m-d_H-i-s') . '.txt"');
    $output = fopen('php://output', 'w');
    fwrite($output, "Result Upload Error Report\r\n");
    fwrite($output, "==========================\r\n\r\n");
    foreach ($_SESSION['result_upload_errors'] as $err) {
        $clean_err = strip_tags(str_replace(['<br>', '<br/>'], "\r\n  ", $err));
        fwrite($output, "- " . $clean_err . "\r\n\r\n");
    }
    fclose($output);
    exit();
}

// CSV Template Download: new format (4 columns)
if (isset($_GET['download_template']) && $_GET['download_template'] == '1') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="result_upload_template_' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Roll No', 'Semester', 'Subject Name', 'Grade']);
    $sample_data = [
        ['22mcan028', 'I', 'Data Mining & Warehousing', 'O'],
        ['22mcan028', 'II', 'Advanced Java', 'A+'],
        ['23BCOL2248', 'I', 'Discrete Mathematics', 'UFM'],
        ['22mcan028', 'III', 'Machine Learning', 'DT'],
    ];
    foreach ($sample_data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

// Handle upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_type']) && verify_csrf_token($_POST['csrf_token'])) {

    $upload_type = $_POST['upload_type'];
    $exam_name = sanitize_input($_POST['exam_name']);
    $batch = sanitize_input($_POST['batch']);
    $session = sanitize_input($_POST['session']);
    $exam_semester = '';

    $publish_date = !empty($_POST['publish_date']) ? $_POST['publish_date'] : date('Y-m-d');
    $token = bin2hex(random_bytes(32));

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO exams (exam_name, batch, session, semester, token, publish_date, status) VALUES (?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([$exam_name, $batch, $session, $exam_semester, $token, $publish_date]);
        $exam_id = $pdo->lastInsertId();

        $row_count = 0;

        if ($upload_type == 'individual') {
            $roll_no = sanitize_input($_POST['roll_no']);
            // Check student exists
            $stmt = $pdo->prepare("SELECT id, student_name FROM students WHERE roll_no = ?");
            $stmt->execute([$roll_no]);
            $student = $stmt->fetch();
            if (!$student) {
                throw new Exception("Roll number $roll_no not found in student master data.");
            }

            $subjects = $_POST['subjects'];
            $grades = $_POST['grades'];
            $semesters = $_POST['semesters'];

            $has_subject = false;
            for ($i = 0; $i < count($subjects); $i++) {
                if (!empty($subjects[$i]) && !empty($grades[$i])) {
                    $has_subject = true;
                    $subject = sanitize_input($subjects[$i]);
                    $grade = sanitize_input($grades[$i]);
                    if (!is_allowed_grade($grade)) {
                        throw new Exception("Invalid grade '$grade'. Allowed grades are O, A+, A, B, C, D, E, F, DT, and UFM.");
                    }
                    $semester = !empty($semesters[$i]) ? sanitize_input($semesters[$i]) : 'I';
                    $stmt = $pdo->prepare("SELECT 1 FROM student_results WHERE roll_no = ? AND session = ? AND subject_name = ? LIMIT 1");
                    $stmt->execute([$roll_no, $session, $subject]);
                    if ($stmt->fetch()) {
                        throw new Exception("Duplicate Subject Found for Roll No $roll_no in subject $subject. Result already exists.");
                    }
                    $v_code = md5($roll_no . $exam_id . $subject . time() . rand(1000, 9999));

                    $stmt = $pdo->prepare("INSERT INTO student_results (exam_id, roll_no, session, semester, subject_name, grade, verification_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$exam_id, $roll_no, $session, $semester, $subject, $grade, $v_code]);
                    $row_count++;
                }
            }
            if (!$has_subject) throw new Exception("At least one subject with grade is required.");
            $success = "✅ Individual result uploaded successfully!<br>📊 Roll: $roll_no<br>📚 Subjects: $row_count";
        } else if ($upload_type == 'bulk') {
            if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
                $tmp_name = $_FILES['csv_file']['tmp_name'];
                $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
                if ($ext != 'csv') throw new Exception("Invalid file type. Only .csv files allowed.");
                if ($_FILES['csv_file']['size'] > 5000000) throw new Exception("File size exceeds 5MB limit.");

                if (($handle = fopen($tmp_name, "r")) !== FALSE) {
                    $headers = fgetcsv($handle, 1000, ",");
                    if ($headers === FALSE) throw new Exception("File is empty or not valid CSV.");
                    $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");

                    $expected = ['roll no', 'semester', 'subject name', 'grade'];
                    if (count($headers) < 4) throw new Exception("CSV must have 4 columns: " . implode(", ", $expected));
                    for ($i = 0; $i < 4; $i++) {
                        if (strtolower(trim($headers[$i])) != $expected[$i]) {
                            throw new Exception("Column " . ($i + 1) . " should be '" . $expected[$i] . "'");
                        }
                    }

                    $errors = [];
                    $seen_results = [];
                    $student_names = [];
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        if (count($data) < 4) continue;
                        $roll_no = sanitize_input(trim($data[0]));
                        $semester = sanitize_input(trim($data[1]));
                        $subject = sanitize_input(trim($data[2]));
                        $grade = sanitize_input(trim($data[3]));
                        if (empty($roll_no) || empty($subject) || empty($grade)) continue;
                        if (empty($semester)) $semester = 'I';
                        if (!is_allowed_grade($grade)) {
                            $errors[] = "Invalid grade '$grade' for roll $roll_no. Allowed: O, A+, A, B, C, D, E, F, DT, UFM";
                            continue;
                        }

                        $result_key = strtolower($roll_no . "\0" . $session . "\0" . $subject);
                        if (isset($seen_results[$result_key])) {
                            $errors[] = "Duplicate in CSV: Roll No $roll_no for subject $subject.";
                            continue;
                        }
                        $seen_results[$result_key] = true;

                        // Validate roll exists
                        $stmt = $pdo->prepare("SELECT id, student_name FROM students WHERE roll_no = ?");
                        $stmt->execute([$roll_no]);
                        $student = $stmt->fetch();
                        if (!$student) {
                            $errors[] = "Roll $roll_no not found in master data";
                            continue;
                        }
                        $student_names[$roll_no] = $student['student_name'];

                        $stmt = $pdo->prepare("SELECT 1 FROM student_results WHERE roll_no = ? AND session = ? AND subject_name = ? LIMIT 1");
                        $stmt->execute([$roll_no, $session, $subject]);
                        if ($stmt->fetch()) {
                            $errors[] = "Duplicate in Database: Roll No $roll_no already has a result for $subject in session $session.";
                            continue;
                        }

                        $v_code = md5($roll_no . $exam_id . $subject . time() . rand(1000, 9999) . $row_count);
                        $stmt = $pdo->prepare("INSERT INTO student_results (exam_id, roll_no, session, semester, subject_name, grade, verification_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$exam_id, $roll_no, $session, $semester, $subject, $grade, $v_code]);
                        $row_count++;
                    }
                    fclose($handle);
                    if ($row_count == 0) {
                        if (!empty($errors)) {
                            $_SESSION['result_upload_errors'] = $errors;
                            throw new Exception("No valid data found. All records had errors.<br><a href='?download_errors=1' class='btn btn-sm btn-outline-danger mt-2'><i class='fas fa-download'></i> Download Error Report</a>");
                        } else {
                            throw new Exception("No valid data found.");
                        }
                    }
                    $success = "✅ Bulk CSV uploaded successfully!<br>📚 Total records: " . number_format($row_count);
                    if (!empty($errors)) {
                        $_SESSION['result_upload_errors'] = $errors;
                        $success .= "<br><small class='text-danger'>" . count($errors) . " records had errors and were skipped.</small>";
                        $success .= "<br><a href='?download_errors=1' class='btn btn-sm btn-outline-danger mt-2'><i class='fas fa-download'></i> Download Error Report</a>";
                    }
                } else {
                    throw new Exception("Could not open CSV file.");
                }
            } else {
                throw new Exception("File upload error.");
            }
        }

        log_activity($pdo, "Uploaded result for exam: $exam_name (ID: $exam_id) - Type: $upload_type - $row_count records", $_SESSION['admin_id']);
        $pdo->commit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

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
                            <i class="fas fa-upload"></i>
                            Upload Result
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-file-upload me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                            Upload exam results individually or via CSV
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="table-card p-4">
                        <?php if ($success): ?>
                            <div class="alert-mgmt alert-success">
                                <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div class="alert-mgmt alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <ul class="nav nav-pills mb-4 gap-2" id="uploadTab" role="tablist">
                            <li class="nav-item flex-fill" role="presentation">
                                <button class="btn w-100 py-3 upload-tab active" data-bs-toggle="tab" data-bs-target="#individual" type="button" role="tab">
                                    <i class="fas fa-user-plus me-2"></i> Individual Student
                                </button>
                            </li>
                            <li class="nav-item flex-fill" role="presentation">
                                <button class="btn w-100 py-3 upload-tab" data-bs-toggle="tab" data-bs-target="#bulk" type="button" role="tab">
                                    <i class="fas fa-file-csv me-2"></i> Bulk CSV Upload
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">
                            <div class="tab-pane fade show active" id="individual" role="tabpanel">
                                <form action="" method="POST" id="individualForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="upload_type" value="individual">
                                    <div class="row g-3">
                                        <div class="col-md-12">
                                            <label class="form-label fw-bold">Exam Name <span class="text-danger">*</span></label>
                                            <input type="text" name="exam_name" class="form-control" required placeholder="e.g. B.Tech CSE Back Result">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Batch <span class="text-danger">*</span></label>
                                            <input type="text" name="batch" class="form-control" required placeholder="e.g. 2023">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                                            <input type="text" name="session" class="form-control" required placeholder="e.g. June 2026">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Publish Date</label>
                                            <input type="date" name="publish_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Roll Number <span class="text-danger">*</span></label>
                                            <input type="text" name="roll_no" class="form-control" required placeholder="e.g. 22mcan028">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-bold">Subjects, Semesters & Grades <span class="text-danger">*</span></label>
                                            <div id="subjects-container">
                                                <div class="subject-row d-flex gap-2 mb-2 flex-wrap">
                                                    <input type="text" name="subjects[]" class="form-control flex-fill" placeholder="Subject Name" required>
                                                    <input type="text" name="semesters[]" class="form-control" style="width: 70px;" placeholder="Semester" value="I" required>
                                                    <select name="grades[]" class="form-select" style="width: 120px;" required>
                                                        <option value="">Grade</option>
                                                        <option value="O">O</option>
                                                        <option value="A+">A+</option>
                                                        <option value="A">A</option>
                                                        <option value="B">B</option>
                                                        <option value="C">C</option>
                                                        <option value="D">D</option>
                                                        <option value="E">E</option>
                                                        <option value="F">F</option>
                                                        <option value="DT">DT</option>
                                                        <option value="UFM">UFM</option>
                                                    </select>
                                                    <button type="button" class="btn-remove-subject" onclick="removeSubject(this)" style="display: none;">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <button type="button" class="btn btn-add-subject mt-2 w-100 py-2 rounded-pill" onclick="addSubject()">
                                                <i class="fas fa-plus me-2"></i> Add Another Subject
                                            </button>
                                        </div>
                                        <div class="col-12 mt-4">
                                            <button type="submit" class="btn btn-primary rounded-pill px-5 py-2 fw-bold">
                                                <i class="fas fa-save me-2"></i> Save Individual Result
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                            
                            <div class="tab-pane fade" id="bulk" role="tabpanel">
                                <form action="" method="POST" enctype="multipart/form-data" id="bulkForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                    <input type="hidden" name="upload_type" value="bulk">
                                    <div class="row g-3">
                                        <div class="col-md-12">
                                            <label class="form-label fw-bold">Exam Name <span class="text-danger">*</span></label>
                                            <input type="text" name="exam_name" class="form-control" required placeholder="e.g. SPECIAL BACK 2023 BATCH JUNE 2026">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Batch <span class="text-danger">*</span></label>
                                            <input type="text" name="batch" class="form-control" required placeholder="e.g. 2023">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                                            <input type="text" name="session" class="form-control" required placeholder="e.g. June 2026">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">Publish Date</label>
                                            <input type="date" name="publish_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">CSV File <span class="text-danger">*</span></label>
                                            <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                                            <div class="form-text text-muted">
                                                <i class="fas fa-info-circle me-1"></i> Max size: 5MB | Only .csv files
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle me-2"></i>
                                                <strong>CSV Format (4 columns):</strong> 
                                                <code>Roll No, Semester, Subject Name, Grade</code>
                                                <br>Each row = One subject result for a student.
                                                <br>Roll No must exist in Student Master Data.
                                                <br><strong>Allowed Grades:</strong> O, A+, A, B, C, D, E, F, DT, UFM
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="format-example">
                                                <strong>Example CSV Content:</strong>
                                                <table class="mt-2" style="width: 100%; font-size: 12px;">
                                                    <thead>
                                                        <tr style="background: #667eea20;">
                                                            <th>Roll No</th><th>Semester</th><th>Subject Name</th><th>Grade</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr><td>22mcan028</td><td>I</td><td>Data Mining & Warehousing</td><td>O</td></tr>
                                                        <tr><td>22mcan028</td><td>II</td><td>Advanced Java</td><td>A+</td></tr>
                                                        <tr><td>23BCOL2248</td><td>I</td><td>Discrete Mathematics</td><td>UFM</td></tr>
                                                        <tr><td>22mcan028</td><td>III</td><td>Machine Learning</td><td>DT</td></tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-12 mt-2">
                                            <button type="submit" class="btn btn-primary rounded-pill px-5 py-2 fw-bold">
                                                <i class="fas fa-cloud-upload-alt me-2"></i> Upload & Save to Database
                                            </button>
                                            <a href="?download_template=1" class="btn btn-outline-secondary rounded-pill px-4 py-2 ms-2">
                                                <i class="fas fa-download me-2"></i> Download Template
                                            </a>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 p-4">
                        <h5 class="fw-bold mb-3">
                            <i class="fas fa-info-circle text-primary me-2"></i> Quick Guide
                        </h5>
                        <div class="mb-4">
                            <h6 class="fw-bold"><i class="fas fa-user me-2 text-success"></i> Individual Upload</h6>
                            <p class="small text-muted">Enter Roll No and then add subjects with their respective semester and grade.</p>
                        </div>
                        <div class="mb-4">
                            <h6 class="fw-bold"><i class="fas fa-file-csv me-2 text-info"></i> Bulk Upload</h6>
                            <p class="small text-muted">Upload CSV with 4 columns: Roll No, Semester, Subject Name, Grade.</p>
                        </div>
                        <div class="mt-3">
                            <h6 class="fw-bold">Grade Scale:</h6>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <span class="badge bg-success">O</span>
                                <span class="badge bg-success">A+</span>
                                <span class="badge bg-success">A</span>
                                <span class="badge bg-info">B</span>
                                <span class="badge bg-warning">C</span>
                                <span class="badge bg-warning">D</span>
                                <span class="badge bg-danger">E</span>
                                <span class="badge bg-dark">F</span>
                                <span class="badge bg-danger">DT</span>
                                <span class="badge bg-danger">UFM</span>
                            </div>
                        </div>
                        <div class="alert alert-warning small mt-3 mb-0">
                            <i class="fas fa-database me-2"></i>
                            <strong>Note:</strong> Ensure all roll numbers exist in Student Master Data.
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
</div>

<script>
let subjectCount = 1;
function addSubject() {
    subjectCount++;
    const container = document.getElementById('subjects-container');
    const newRow = document.createElement('div');
    newRow.className = 'subject-row d-flex gap-2 mb-2 flex-wrap';
    newRow.innerHTML = `
        <input type="text" name="subjects[]" class="form-control flex-fill" placeholder="Subject Name" required>
        <input type="text" name="semesters[]" class="form-control" style="width: 70px;" placeholder="Semester" value="I" required>
        <select name="grades[]" class="form-select" style="width: 120px;" required>
            <option value="">Grade</option>
            <option value="O">O</option>
            <option value="A+">A+</option>
            <option value="A">A</option>
            <option value="B">B</option>
            <option value="C">C</option>
            <option value="D">D</option>
            <option value="E">E</option>
            <option value="F">F</option>
            <option value="DT">DT</option>
            <option value="UFM">UFM</option>
        </select>
        <button type="button" class="btn-remove-subject" onclick="removeSubject(this)">
            <i class="fas fa-trash"></i>
        </button>
    `;
    container.appendChild(newRow);
    if (subjectCount > 1) {
        document.querySelectorAll('.btn-remove-subject').forEach(btn => {
            btn.style.display = 'flex';
        });
    }
}
function removeSubject(button) {
    if (subjectCount > 1) {
        button.closest('.subject-row').remove();
        subjectCount--;
        if (subjectCount === 1) {
            document.querySelectorAll('.btn-remove-subject').forEach(btn => {
                btn.style.display = 'none';
            });
        }
    }
}
document.querySelectorAll('.btn-remove-subject').forEach(btn => { btn.style.display = 'none'; });
document.querySelectorAll('.upload-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.upload-tab').forEach(t => { t.classList.remove('active'); });
        this.classList.add('active');
    });
});
</script>

<?php include '../includes/footer.php'; ?>