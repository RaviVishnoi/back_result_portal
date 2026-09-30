<?php
require_once '../includes/config.php';
require_admin_session($pdo);
require_permission($pdo, 'students.edit');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$id]);
$student = $stmt->fetch();
if (!$student) {
    header("Location: manage_students.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && verify_csrf_token($_POST['csrf_token'])) {
    $roll_no = sanitize_input($_POST['roll_no']);
    $student_name = sanitize_input($_POST['student_name']);
    $email = sanitize_input($_POST['email']);

    if (empty($roll_no) || empty($student_name) || empty($email)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE students SET roll_no = ?, student_name = ?, email = ? WHERE id = ?");
            $stmt->execute([$roll_no, $student_name, $email, $id]);
            $stmt = $pdo->prepare("UPDATE student_results SET roll_no = ? WHERE roll_no = ?");
            $stmt->execute([$roll_no, $student['roll_no']]);
            $pdo->commit();
            log_activity($pdo, "Updated student ID: $id", $_SESSION['admin_id']);
            $success = "Student updated successfully.";
            // Refresh data
            $student['roll_no'] = $roll_no;
            $student['student_name'] = $student_name;
            $student['email'] = $email;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->errorInfo[1] == 1062) {
                $error = "Roll number already exists.";
            } else {
                error_log('Edit student database error.');
                $error = "Unable to update the student right now. Please try again.";
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
                <div class="admin-page-header mb-4">
                    <div>
                        <h1>
                            <i class="fas fa-edit"></i>
                            Edit Student
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-graduation-cap me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                            Update student details for <?php echo sanitize_input($student['roll_no']); ?>
                        </div>
                    </div>
                    <div class="header-actions">
                        <a href="manage_students.php" class="btn-back-dashboard">
                            <i class="fas fa-arrow-left"></i> Back to Students
                        </a>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-7">
                        <div class="table-card p-4">
                            <?php if($error): ?>
                                <div class="alert-mgmt alert-danger">
                                    <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            <?php endif; ?>
                            <?php if($success): ?>
                                <div class="alert-mgmt alert-success">
                                    <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                                </div>
                            <?php endif; ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Roll No <span class="text-danger">*</span></label>
                                    <input type="text" name="roll_no" class="form-control" required value="<?php echo sanitize_input($student['roll_no']); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Student Name <span class="text-danger">*</span></label>
                                    <input type="text" name="student_name" class="form-control" required value="<?php echo sanitize_input($student['student_name']); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" required value="<?php echo sanitize_input($student['email']); ?>">
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" class="btn-create-admin px-4 py-2">
                                        <i class="fas fa-save me-2"></i> Update Student
                                    </button>
                                    <a href="manage_students.php" class="btn-back-dashboard">Cancel</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>