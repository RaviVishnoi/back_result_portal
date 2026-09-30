<?php
require_once '../includes/config.php';

require_admin_session($pdo);

// Check permission for exam management
if (!is_super_admin($pdo) && !has_permission($pdo, 'exams.edit')) {
    require_permission($pdo, 'exams.edit');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$success = '';
$error = '';

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM exams WHERE id = ?");
    $stmt->execute([$id]);
    $exam = $stmt->fetch();
    if (!$exam) {
        header("Location: manage_exams.php");
        exit();
    }
} else {
    header("Location: manage_exams.php");
    exit();
}

// ============================================
// UPDATE EXAM DETAILS
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf_token']) && verify_csrf_token($_POST['csrf_token'])) {
    
    // ============================================
    // BULK RESULT EDIT - CSV Upload
    // ============================================
    if (isset($_POST['bulk_action']) && $_POST['bulk_action'] == 'bulk_result_csv') {
        require_permission($pdo, 'results.upload');
        
        // Validate file upload
        if (!isset($_FILES['bulk_csv_file']) || $_FILES['bulk_csv_file']['error'] !== UPLOAD_ERR_OK) {
            $error = "Please select a CSV file to upload.";
        } else {
            $file = $_FILES['bulk_csv_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if ($ext !== 'csv') {
                $error = "Only CSV files are allowed.";
            } elseif ($file['size'] > 5000000) {
                $error = "File size exceeds 5MB limit.";
            } elseif (($handle = fopen($file['tmp_name'], 'r')) === false) {
                $error = "Could not open the uploaded file.";
            } else {
                // Read headers
                $headers = fgetcsv($handle, 1000, ",");
                if ($headers === false) {
                    $error = "File is empty or invalid.";
                } else {
                    $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
                    $expected = ['roll no', 'semester', 'subject name', 'grade'];
                    
                    if (count($headers) < 4) {
                        $error = "CSV must have 4 columns: Roll No, Semester, Subject Name, Grade";
                    } else {
                        for ($i = 0; $i < 4; $i++) {
                            if (strtolower(trim($headers[$i])) != $expected[$i]) {
                                $error = "Column " . ($i + 1) . " should be '" . $expected[$i] . "'";
                                break;
                            }
                        }
                    }
                }

                if (empty($error)) {
                    try {
                        $pdo->beginTransaction();
                        
                        $session = $exam['session'];
                        $updated_count = 0;
                        $error_count = 0;
                        $errors_list = [];
                        $processed_rolls = [];

                        while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                            if (count($data) < 4) continue;
                            
                            $roll_no = sanitize_input(trim($data[0]));
                            $semester = sanitize_input(trim($data[1]));
                            $subject = sanitize_input(trim($data[2]));
                            $grade = sanitize_input(trim($data[3]));
                            
                            if (empty($roll_no) || empty($subject) || empty($grade)) {
                                $errors_list[] = "Skipped incomplete row: Roll=$roll_no, Subject=$subject, Grade=$grade";
                                $error_count++;
                                continue;
                            }
                            
                            if (empty($semester)) $semester = 'I';
                            
                            // Validate grade
                            if (!is_allowed_grade($grade)) {
                                $errors_list[] = "Invalid grade '$grade' for Roll: $roll_no, Subject: $subject. Allowed: O, A+, A, B, C, D, E, F, DT, UFM";
                                $error_count++;
                                continue;
                            }
                            
                            // Check if student exists
                            $stmt = $pdo->prepare("SELECT id, student_name FROM students WHERE roll_no = ?");
                            $stmt->execute([$roll_no]);
                            $student = $stmt->fetch();
                            if (!$student) {
                                $errors_list[] = "Roll No: $roll_no - Student not found in master data.";
                                $error_count++;
                                continue;
                            }
                            
                            // Track processed students
                            $processed_rolls[$roll_no] = true;
                            
                            // Delete existing results for this student in this exam
                            $pdo->prepare("DELETE FROM student_results WHERE exam_id = ? AND roll_no = ?")
                                ->execute([$id, $roll_no]);
                            
                            // Insert new result
                            $v_code = md5($roll_no . $id . $subject . time() . rand(1000, 9999));
                            $stmt = $pdo->prepare("INSERT INTO student_results (exam_id, roll_no, session, semester, subject_name, grade, verification_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$id, $roll_no, $session, $semester, $subject, $grade, $v_code]);
                            $updated_count++;
                        }
                        
                        fclose($handle);
                        $pdo->commit();
                        
                        if ($updated_count > 0) {
                            $success = "✅ Bulk result update completed!<br>";
                            $success .= "📊 Records updated: <strong>" . number_format($updated_count) . "</strong><br>";
                            $success .= "👨‍🎓 Students affected: <strong>" . count($processed_rolls) . "</strong><br>";
                            if ($error_count > 0) {
                                $success .= "⚠️ Errors: <strong>$error_count</strong><br>";
                                $success .= "<small class='text-danger'>" . implode("<br>", array_slice($errors_list, 0, 10)) . "</small>";
                                if (count($errors_list) > 10) {
                                    $success .= "<br><small class='text-muted'>... and " . (count($errors_list) - 10) . " more errors</small>";
                                }
                            }
                            log_activity($pdo, "Bulk result CSV edit for exam ID: $id - Updated $updated_count records", $_SESSION['admin_id']);
                        } else {
                            $error = "No records were updated. " . implode(" ", array_slice($errors_list, 0, 5));
                        }
                        
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = "Error: " . $e->getMessage();
                    }
                } else {
                    fclose($handle);
                }
            }
        }
    } else {
        // ============================================
        // UPDATE EXAM DETAILS
        // ============================================
        $exam_name = sanitize_input($_POST['exam_name']);
        $batch = sanitize_input($_POST['batch']);
        $session = sanitize_input($_POST['session']);
        $semester = sanitize_input($_POST['semester']);
        $publish_date = $_POST['publish_date'];
        $status = isset($_POST['status']) && (int) $_POST['status'] === 1 ? 1 : 0;

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE exams SET exam_name = ?, batch = ?, session = ?, semester = ?, publish_date = ?, status = ? WHERE id = ?");
            if ($stmt->execute([$exam_name, $batch, $session, $semester, $publish_date, $status, $id])) {
                $stmt = $pdo->prepare("UPDATE student_results SET session = ? WHERE exam_id = ?");
                $stmt->execute([$session, $id]);
                $pdo->commit();
                log_activity($pdo, "Updated exam details for ID: $id", $_SESSION['admin_id']);
                $success = "Exam details updated successfully!";
                $exam['exam_name'] = $exam_name;
                $exam['batch'] = $batch;
                $exam['session'] = $session;
                $exam['semester'] = $semester;
                $exam['publish_date'] = $publish_date;
                $exam['status'] = $status;
            } else {
                $pdo->rollBack();
                $error = "Error updating database.";
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Edit exam error: " . $e->getMessage());
            $error = "Error updating database. Please try again.";
        }
    }
}

// Stats
$stmt = $pdo->prepare("SELECT COUNT(*) as total_results FROM student_results WHERE exam_id = ?");
$stmt->execute([$id]);
$results_count = $stmt->fetch()['total_results'];

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT roll_no) as total_students FROM student_results WHERE exam_id = ?");
$stmt->execute([$id]);
$students_count = $stmt->fetch()['total_students'];

$csrf = generate_csrf_token();

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
                            <i class="fas fa-edit"></i>
                            Edit Exam Details
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-file-alt me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                            Update exam info, status, and bulk edit results via CSV
                        </div>
                    </div>
                    <div class="header-actions">
                        <a href="manage_exams.php" class="btn-back-dashboard">
                            <i class="fas fa-arrow-left"></i> Back to Exams
                        </a>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert-mgmt alert-success">
                        <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert-mgmt alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="table-card p-4">
                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="info-card">
                            <i class="fas fa-users fa-2x mb-2"></i>
                            <h3><?php echo number_format($students_count); ?></h3>
                            <p>Total Students</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card green">
                            <i class="fas fa-book fa-2x mb-2"></i>
                            <h3><?php echo number_format($results_count); ?></h3>
                            <p>Total Subject Records</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-card blue">
                            <i class="fas fa-file-import fa-2x mb-2"></i>
                            <h3><?php echo number_format($students_count); ?></h3>
                            <p>Students with Results</p>
                        </div>
                    </div>
                </div>

                <!-- ============================================ -->
                <!-- MAIN FORM - Exam Details + Bulk CSV Upload    -->
                <!-- ============================================ -->
                <form action="" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    
                    <!-- Exam Details -->
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">Exam Name <span class="text-danger">*</span></label>
                            <input type="text" name="exam_name" class="form-control" required value="<?php echo sanitize_input($exam['exam_name']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Batch <span class="text-danger">*</span></label>
                            <input type="text" name="batch" class="form-control" required value="<?php echo sanitize_input($exam['batch']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Session <span class="text-danger">*</span></label>
                            <input type="text" name="session" class="form-control" required value="<?php echo sanitize_input($exam['session']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Semester (Fallback) <span class="text-muted">(Optional)</span></label>
                            <input type="text" name="semester" class="form-control" value="<?php echo sanitize_input($exam['semester'] ?? ''); ?>">
                            <small class="text-muted">Used as fallback when semester is not in student_results.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Publish Date</label>
                            <input type="date" name="publish_date" class="form-control" required value="<?php echo htmlspecialchars($exam['publish_date']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" class="form-select" required>
                                <option value="1" <?php echo (int) $exam['status'] === 1 ? 'selected' : ''; ?>>Active / Enabled</option>
                                <option value="0" <?php echo (int) $exam['status'] === 0 ? 'selected' : ''; ?>>Inactive / Disabled</option>
                            </select>
                            <small class="form-text text-muted">Inactive exams are hidden from Student Result Portal.</small>
                        </div>
                    </div>

                    <hr class="my-4">

                    <!-- ============================================ -->
                    <!-- BULK RESULT EDIT - CSV Upload                 -->
                    <!-- ============================================ -->
                    <div class="bulk-csv-section">
                        <div class="section-title">
                            <i class="fas fa-file-csv me-2"></i> Bulk Result Edit (CSV Upload)
                            <span class="badge-count">Bulk Update</span>
                        </div>
                        <p class="text-muted small mb-3">
                            Upload a CSV file to update results for multiple students at once.
                            <strong>This will replace all existing results</strong> for the students mentioned in the CSV.
                            <br><strong>Allowed Grades:</strong> O, A+, A, B, C, D, E, F, DT, UFM
                        </p>

                        <!-- CSV Format Example -->
                        <div class="format-example mb-3">
                            <strong>📄 CSV Format (4 columns):</strong>
                            <table class="mt-1">
                                <thead>
                                    <tr>
                                        <th>Roll No</th>
                                        <th>Semester</th>
                                        <th>Subject Name</th>
                                        <th>Grade</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td>22mcan028</td><td>I</td><td>Data Mining &amp; Warehousing</td><td>O</td></tr>
                                    <tr><td>22mcan028</td><td>II</td><td>Advanced Java</td><td>A+</td></tr>
                                    <tr><td>23BCOL2248</td><td>I</td><td>Discrete Mathematics</td><td>UFM</td></tr>
                                    <tr><td>21mcan012</td><td>III</td><td>Machine Learning</td><td>DT</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- CSV Upload -->
                        <?php if ($is_super_admin || has_permission($pdo, 'results.upload')): ?>
                            <div class="csv-upload-row">
                                <div class="file-input-wrapper">
                                    <label class="form-label fw-bold">Upload CSV File <span class="text-danger">*</span></label>
                                    <input type="file" name="bulk_csv_file" class="form-control" accept=".csv">
                                    <div class="form-text text-muted">
                                        <i class="fas fa-info-circle me-1"></i> 
                                        Max: 5MB | Only .csv | UTF-8 encoding recommended
                                    </div>
                                </div>
                                <div class="btn-wrapper mt-2">
                                    <input type="hidden" name="bulk_action" value="bulk_result_csv">
                                    <button type="submit" class="btn-bulk-update" onclick="return confirm('Are you sure? This will replace existing results for students in CSV.')">
                                        <i class="fas fa-upload me-2"></i> Upload & Update
                                    </button>
                                    <a href="?download_bulk_template=1&id=<?php echo $id; ?>" class="btn-bulk-download">
                                        <i class="fas fa-download me-2"></i> Template
                                    </a>
                                </div>
                            </div>
    
                            <div class="mt-3">
                                <div class="alert alert-warning small mb-0">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Warning:</strong> This will <strong>replace</strong> all existing results for the students mentioned in the CSV.
                                    Only the subjects listed in the CSV will be kept. Other subjects for these students will be deleted.
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Submit Buttons -->
                    <div class="mt-4 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn-create-admin px-4 py-2">
                            <i class="fas fa-save me-2"></i> Update Exam
                        </button>
                        <a href="manage_exams.php" class="btn-back-dashboard">
                            <i class="fas fa-arrow-left me-1"></i> Cancel
                        </a>
                        <?php if ($is_super_admin || has_permission($pdo, 'results.edit')): ?>
                            <a href="edit_student_result.php?exam_id=<?php echo $id; ?>" class="btn-back-dashboard" style="border-color:#fde68a;color:#b45309;">
                                <i class="fas fa-user-edit me-1"></i> Edit Single Student
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
                </div>
            </main>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>