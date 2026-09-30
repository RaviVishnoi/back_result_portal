<?php
require_once 'includes/config.php';

header('Content-Type: application/json');

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
    exit;
}

$session_roll_no = (string) ($_SESSION['otp_roll_no'] ?? '');
$posted_roll_no = (string) ($_POST['roll_no'] ?? '');
$roll_no = $session_roll_no;
$exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
$otp = $_POST['otp'] ?? '';
$disclaimer_accepted = isset($_POST['disclaimer_accepted']) && $_POST['disclaimer_accepted'] == '1';

if (!$roll_no || !$otp || !$posted_roll_no || !hash_equals($session_roll_no, $posted_roll_no)) {
    echo json_encode(['success' => false, 'message' => 'Missing required data.']);
    exit;
}

// Fetch latest valid OTP for this roll number (exam_id = 0)
$stmt = $pdo->prepare("SELECT id, otp_hash, expires_at, attempts, is_used, expires_at <= NOW() AS is_expired
                       FROM otp_verifications 
                       WHERE roll_no = ? AND exam_id = 0 AND is_used = 0 
                       ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$roll_no]);
$record = $stmt->fetch();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'No valid OTP found. Please request a new OTP.']);
    exit;
}

// Check expiry
if ((int) $record['is_expired'] === 1) {
    $pdo->prepare("UPDATE otp_verifications SET is_used = 1 WHERE id = ?")->execute([$record['id']]);
    echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
    exit;
}

// Check attempts (max 5)
if ($record['attempts'] >= 5) {
    $pdo->prepare("UPDATE otp_verifications SET is_used = 1 WHERE id = ?")->execute([$record['id']]);
    echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please request a new OTP.']);
    exit;
}

// Verify
if (password_verify($otp, $record['otp_hash'])) {
    $pdo->prepare("UPDATE otp_verifications SET is_used = 1 WHERE id = ?")->execute([$record['id']]);
    $_SESSION['otp_verified'] = true;
    $_SESSION['otp_verified_at'] = time();
    $_SESSION['otp_verified_roll'] = $roll_no;
    $_SESSION['otp_verified_exam'] = 0;

    if ($disclaimer_accepted) {
        $student = $pdo->prepare("SELECT id, student_name FROM students WHERE roll_no = ? LIMIT 1");
        $student->execute([$roll_no]);
        $student_record = $student->fetch();
        $exam_name = 'Generic Result Access';
        $audit_exam_id = null;
        if ((int) $exam_id > 0) {
            $audit_exam_id = $exam_id;
            $exam_stmt = $pdo->prepare("SELECT exam_name FROM exams WHERE id = ? LIMIT 1");
            $exam_stmt->execute([$exam_id]);
            $exam_name = (string) ($exam_stmt->fetchColumn() ?: 'Result Access');
        }

        save_disclaimer_verification_record(
            $pdo,
            $student_record['id'] ?? null,
            $roll_no,
            $student_record['student_name'] ?? null,
            $audit_exam_id,
            $exam_name,
            'result-disclaimer-v1',
            1,
            1,
            date('Y-m-d H:i:s')
        );
    }

    echo json_encode(['success' => true, 'message' => 'OTP verified successfully.']);
} else {
    $pdo->prepare("UPDATE otp_verifications SET attempts = attempts + 1 WHERE id = ?")->execute([$record['id']]);
    $remaining = 5 - ($record['attempts'] + 1);
    $msg = ($remaining > 0) ? "Invalid OTP. $remaining attempt(s) remaining." : "Too many failed attempts. Please request a new OTP.";
    echo json_encode(['success' => false, 'message' => $msg]);
}
?>