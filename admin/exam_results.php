<?php
require_once '../includes/config.php';
require_admin_session($pdo);
require_permission($pdo, 'results.view');

$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$exam_id) {
    header("Location: manage_exams.php");
    exit();
}

// Fetch exam details
$stmt = $pdo->prepare("SELECT exam_name FROM exams WHERE id = ?");
$stmt->execute([$exam_id]);
$exam = $stmt->fetch();
if (!$exam) {
    header("Location: manage_exams.php");
    exit();
}

$search = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';
$query = "
    SELECT sr.roll_no, s.student_name, sr.semester, sr.subject_name, sr.grade
    FROM student_results sr
    LEFT JOIN students s ON sr.roll_no COLLATE utf8mb4_general_ci = s.roll_no
    WHERE sr.exam_id = ?
";
$params = [$exam_id];

if ($search) {
    $query .= " AND (sr.roll_no LIKE ? OR sr.subject_name LIKE ? OR sr.grade LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term]);
}
$query .= " ORDER BY sr.roll_no ASC, sr.semester ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll();

include '../includes/header.php';
?>

<div class="admin-page-shell">
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 admin-page-content">
                <div class="admin-page-header mb-4">
                    <div>
                        <h1 class="exam-results-title" title="<?php echo htmlspecialchars($exam['exam_name'], ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fas fa-file-invoice"></i> 
                            Exam Results: <?php echo sanitize_input($exam['exam_name']); ?>
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-table me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                            View results for this specific examination
                        </div>
                    </div>
                    <div class="header-actions">
                        <a href="manage_exams.php" class="btn-back-dashboard">
                            <i class="fas fa-arrow-left"></i> Back to Exams
                        </a>
                    </div>
                </div>

                <div class="table-card" id="results-table">
                    <div class="card-header border-bottom py-3">
                        <div class="w-100 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5>
                                <i class="fas fa-list text-muted"></i>
                                Result Records
                                <span class="badge-count"><?php echo count($results); ?></span>
                            </h5>
                            
                            <form method="GET" class="d-flex align-items-center gap-2">
                                <input type="hidden" name="id" value="<?php echo $exam_id; ?>">
                                <div class="input-group input-group-sm" style="width: 250px;">
                                    <input type="text" name="search" class="form-control" placeholder="Search Roll No, Subject..." value="<?php echo htmlspecialchars($search); ?>">
                                    <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search"></i></button>
                                </div>
                                <?php if ($search): ?><a href="exam_results.php?id=<?php echo $exam_id; ?>" class="btn btn-sm btn-outline-danger">Clear</a><?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table exam-results-table table-hover align-middle">
                            <thead class="bg-light">
                                <tr>
                                    <th>Roll No</th>
                                    <th>Student Name</th>
                                    <th>Semester</th>
                                    <th>Subject Name</th>
                                    <th>Grade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($results) > 0): ?>
                                    <?php foreach ($results as $row): ?>
                                        <tr>
                                            <td><strong><?php echo sanitize_input($row['roll_no']); ?></strong></td>
                                            <td><?php echo sanitize_input($row['student_name'] ?? ''); ?></td>
                                            <td><?php echo sanitize_input($row['semester']); ?></td>
                                            <td class="result-subject-name" title="<?php echo htmlspecialchars($row['subject_name'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo sanitize_input($row['subject_name']); ?></td>
                                            <td>
                                                <span class="badge rounded-pill px-3 py-1 fw-bold 
                                                    <?php 
                                                        $g = trim($row['grade']);
                                                        if ($g == 'F') echo 'bg-danger text-white';
                                                        elseif ($g == 'E') echo 'bg-warning text-dark';
                                                        else echo 'bg-success text-white';
                                                    ?>">
                                                    <?php echo sanitize_input($g); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No result records found for this exam.</td></tr>
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