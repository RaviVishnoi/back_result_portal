<?php
require_once '../includes/config.php';
require_admin_session($pdo);
require_permission($pdo, 'results.edit');

$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
if (!$exam_id) {
    header("Location: manage_exams.php");
    exit();
}

// Fetch exam details
$stmt = $pdo->prepare("SELECT exam_name, session FROM exams WHERE id = ?");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();
if (!$exam) {
    header("Location: manage_exams.php");
    exit();
}

$error = '';
$success = '';
$roll_no = '';
$student_results = [];
$student_name = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $error = 'Invalid security token. Please refresh and try again.';
}

// Search student
if (!$error && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'search') {
    $roll_no = sanitize_input($_POST['roll_no']);
    if (!empty($roll_no)) {
        // Check if student exists
        $stmt = $pdo->prepare("SELECT student_name FROM students WHERE roll_no = ?");
        $stmt->execute([$roll_no]);
        $student = $stmt->fetch();
        if ($student) {
            $student_name = $student['student_name'];
            // Fetch current results
            $stmt = $pdo->prepare("SELECT id, semester, subject_name, grade FROM student_results WHERE exam_id = ? AND roll_no = ? ORDER BY id ASC");
            $stmt->execute([$exam_id, $roll_no]);
            $student_results = $stmt->fetchAll();
        } else {
            $error = "Student with Roll No '$roll_no' not found in Master Data.";
        }
    }
}

// Update student results
if (!$error && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update') {
    $roll_no = sanitize_input($_POST['roll_no']);
    $subjects = $_POST['subjects'];
    $semesters = $_POST['semesters'];
    $grades = $_POST['grades'];

    // Validate grades
    foreach ($grades as $grade) {
        if (!empty($grade) && !is_allowed_grade($grade)) {
            $error = "Invalid grade '$grade'. Allowed grades are O, A+, A, B, C, D, E, F, DT, and UFM.";
            break;
        }
    }

    // Validate roll exists
    $stmt = $pdo->prepare("SELECT id FROM students WHERE roll_no = ?");
    $stmt->execute([$roll_no]);
    if ($error) {
        // Grade validation failed; do not replace the existing result rows.
    } elseif (!$stmt->fetch()) {
        $error = "Roll number $roll_no not found in student master data.";
    } else {
        try {
            $pdo->beginTransaction();

            $seen_subjects = [];
            $student_stmt = $pdo->prepare("SELECT student_name FROM students WHERE roll_no = ?");
            $student_stmt->execute([$roll_no]);
            $student = $student_stmt->fetch();
            
            for ($i = 0; $i < count($subjects); $i++) {
                if (!empty($subjects[$i]) && !empty($grades[$i])) {
                    $subject = sanitize_input($subjects[$i]);
                    $subject_key = strtolower($subject);
                    if (isset($seen_subjects[$subject_key])) {
                        throw new Exception("Duplicate Subject Found<br>Student: " . htmlspecialchars($student['student_name']) . "<br>Roll No.: " . htmlspecialchars($roll_no) . "<br>Subject: " . htmlspecialchars($subject) . "<br>Session: " . htmlspecialchars($exam['session']) . "<br>This student's subject has already been added for this session.");
                    }
                    $seen_subjects[$subject_key] = true;
                    $stmt = $pdo->prepare("SELECT 1 FROM student_results WHERE roll_no = ? AND session = ? AND subject_name = ? AND exam_id <> ? LIMIT 1");
                    $stmt->execute([$roll_no, $exam['session'], $subject, $exam_id]);
                    if ($stmt->fetch()) {
                        throw new Exception("Duplicate Subject Found<br>Student: " . htmlspecialchars($student['student_name']) . "<br>Roll No.: " . htmlspecialchars($roll_no) . "<br>Subject: " . htmlspecialchars($subject) . "<br>Session: " . htmlspecialchars($exam['session']) . "<br>This student's subject has already been added for this session.");
                    }
                }
            }
            
            // Delete old results for this student & exam
            $pdo->prepare("DELETE FROM student_results WHERE exam_id = ? AND roll_no = ?")
                 ->execute([$exam_id, $roll_no]);

            // Insert new data
            $row_count = 0;
            for ($i = 0; $i < count($subjects); $i++) {
                if (!empty($subjects[$i]) && !empty($grades[$i])) {
                    $subject = sanitize_input($subjects[$i]);
                    $grade = sanitize_input($grades[$i]);
                    $semester = !empty($semesters[$i]) ? sanitize_input($semesters[$i]) : 'I';
                    $v_code = md5($roll_no . $exam_id . $subject . time() . rand(1000, 9999));
                    
                    $stmt = $pdo->prepare("INSERT INTO student_results (exam_id, roll_no, session, semester, subject_name, grade, verification_code) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$exam_id, $roll_no, $exam['session'], $semester, $subject, $grade, $v_code]);
                    $row_count++;
                }
            }
            
            $pdo->commit();
            $success = "✅ Student result updated successfully! $row_count subjects updated.";
            log_activity($pdo, "Updated individual student result for roll: $roll_no in exam ID: $exam_id", $_SESSION['admin_id']);
            
            // Re-fetch to display updated data
            $stmt = $pdo->prepare("SELECT id, semester, subject_name, grade FROM student_results WHERE exam_id = ? AND roll_no = ? ORDER BY id ASC");
            $stmt->execute([$exam_id, $roll_no]);
            $student_results = $stmt->fetchAll();
            
            $stmt = $pdo->prepare("SELECT student_name FROM students WHERE roll_no = ?");
            $stmt->execute([$roll_no]);
            $student = $stmt->fetch();
            $student_name = $student['student_name'];

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
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
                <div class="admin-page-header mb-4">
                    <div>
                        <h1>
                            <i class="fas fa-user-edit"></i>
                            Update Student Result
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-file-invoice me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                            Exam: <strong><?php echo sanitize_input($exam['exam_name']); ?></strong>
                        </div>
                    </div>
                    <div class="header-actions">
                        <a href="manage_exams.php" class="btn-back-dashboard">
                            <i class="fas fa-arrow-left"></i> Back to Exams
                        </a>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="table-card p-4">
                            <?php if ($error): ?>
                                <div class="alert-mgmt alert-danger">
                                    <i class="fas fa-exclamation-circle me-2"></i> <?php echo nl2br(htmlspecialchars($error, ENT_QUOTES, 'UTF-8')); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($success): ?>
                                <div class="alert-mgmt alert-success">
                                    <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                                </div>
                            <?php endif; ?>

                        <form method="POST" class="mb-4">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="action" value="search">
                            <div class="input-group">
                                <input type="text" name="roll_no" class="form-control" placeholder="Enter Roll No to Search" required value="<?php echo htmlspecialchars($roll_no); ?>">
                                <button class="btn btn-primary" type="submit"><i class="fas fa-search me-1"></i> Search</button>
                                <?php if ($roll_no): ?>
                                    <a href="edit_student_result.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-outline-danger">Clear</a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <?php if ($roll_no && $student_name !== ''): ?>
                            <div class="alert alert-info py-2">
                                <strong>Student:</strong> <?php echo sanitize_input($student_name); ?> (Roll: <?php echo sanitize_input($roll_no); ?>)
                            </div>

                            <form method="POST" id="updateForm">
                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="roll_no" value="<?php echo sanitize_input($roll_no); ?>">
                                
                                <label class="form-label fw-bold">Subjects, Semesters & Grades</label>
                                <div id="subjects-container">
                                    <?php if (count($student_results) > 0): ?>
                                        <?php foreach ($student_results as $row): ?>
                                            <div class="subject-row d-flex gap-2 mb-2 flex-wrap">
                                                <input type="text" name="subjects[]" class="form-control flex-fill" placeholder="Subject Name" required value="<?php echo sanitize_input($row['subject_name']); ?>">
                                                <input type="text" name="semesters[]" class="form-control" style="width: 80px;" placeholder="Sem." required value="<?php echo sanitize_input($row['semester']); ?>">
                                                <select name="grades[]" class="form-select" style="width: 120px;" required>
                                                    <option value="">Grade</option>
                                                    <option value="O" <?php if($row['grade']=='O') echo 'selected'; ?>>O</option>
                                                    <option value="A+" <?php if($row['grade']=='A+') echo 'selected'; ?>>A+</option>
                                                    <option value="A" <?php if($row['grade']=='A') echo 'selected'; ?>>A</option>
                                                    <option value="B" <?php if($row['grade']=='B') echo 'selected'; ?>>B</option>
                                                    <option value="C" <?php if($row['grade']=='C') echo 'selected'; ?>>C</option>
                                                    <option value="D" <?php if($row['grade']=='D') echo 'selected'; ?>>D</option>
                                                    <option value="E" <?php if($row['grade']=='E') echo 'selected'; ?>>E</option>
                                                    <option value="F" <?php if($row['grade']=='F') echo 'selected'; ?>>F</option>
                                                    <option value="DT" <?php if($row['grade']=='DT') echo 'selected'; ?>>DT</option>
                                                    <option value="UFM" <?php if($row['grade']=='UFM') echo 'selected'; ?>>UFM</option>
                                                </select>
                                                <button type="button" class="btn-remove-subject" onclick="removeSubject(this)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="alert alert-light text-muted text-center py-3">No subjects found for this student. Add subjects below.</div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="btn btn-add-subject mt-2 w-100 py-2 rounded-pill" onclick="addSubject()">
                                    <i class="fas fa-plus me-2"></i> Add Another Subject
                                </button>
                                
                                <div class="mt-4 d-flex flex-wrap gap-2">
                                    <button type="submit" class="btn-create-admin px-4 py-2" style="background:linear-gradient(145deg,#f59e0b,#d97706);box-shadow:0 3px 10px rgba(245,158,11,0.25);">
                                        <i class="fas fa-save me-2"></i> Update Student Results
                                    </button>
                                    <a href="manage_exams.php" class="btn-back-dashboard">Cancel</a>
                                </div>
                            </form>
                        <?php endif; ?>
                        </div>
                    </div>
                
                    <div class="col-lg-4">
                        <div class="table-card p-4">
                        <h5 class="fw-bold mb-3">
                            <i class="fas fa-info-circle text-primary me-2"></i> How to Use
                        </h5>
                        <div class="small text-muted">
                            <ol class="ps-3">
                                <li class="mb-2">Enter the <strong>Roll No</strong> and click <strong>Search</strong>.</li>
                                <li class="mb-2">The student's current subjects will appear.</li>
                                <li class="mb-2">You can <strong>Edit</strong> Semester, Subject Name, or Grade directly.</li>
                                <li class="mb-2">Click <strong>Remove (Trash)</strong> to delete a subject.</li>
                                <li class="mb-2">Click <strong>Add Another Subject</strong> to insert a new row.</li>
                                <li class="mb-2">Finally, click <strong>Update Student Results</strong> to save changes.</li>
                            </ol>
                            <div class="alert alert-warning small mt-3 mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Note:</strong> This will replace all previous results for this student in this exam.
                            </div>
                            <div class="mt-2">
                                <strong>Allowed Grades:</strong>
                                <div class="d-flex flex-wrap gap-1 mt-1">
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
                        </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>

<script>
    let subjectCount = <?php echo count($student_results) > 0 ? count($student_results) : 0; ?>;

    function addSubject() {
        subjectCount++;
        const container = document.getElementById('subjects-container');
        const newRow = document.createElement('div');
        newRow.className = 'subject-row d-flex gap-2 mb-2 flex-wrap';
        newRow.innerHTML = `
            <input type="text" name="subjects[]" class="form-control flex-fill" placeholder="Subject Name" required>
            <input type="text" name="semesters[]" class="form-control" style="width: 80px;" placeholder="Sem." value="I" required>
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
    }

    function removeSubject(button) {
        if (document.querySelectorAll('.subject-row').length > 1) {
            button.closest('.subject-row').remove();
            subjectCount--;
        } else {
            alert("At least one subject is required.");
        }
    }
</script>

<?php include '../includes/footer.php'; ?>