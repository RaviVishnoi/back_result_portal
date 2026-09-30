<?php
require_once 'includes/config.php';
require_once __DIR__ . '/includes/email_logger.php';

// ---- Try to load PHPMailer autoload ----
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];
$loaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require $path;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    log_email_attempt('PHPMailer autoload not found. Check composer install.', 'ERROR');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'PHPMailer not found. Please run "composer require phpmailer/phpmailer".']);
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

if (ob_get_level() > 0) {
    ob_clean();
}
header('Content-Type: application/json');

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']);
    exit;
}

$roll_no = $_POST['roll_no'] ?? $_SESSION['otp_roll_no'] ?? '';
// exam_id is now optional (default 0 for generic OTP)
$exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;

log_email_attempt("OTP request received for exam_id: $exam_id");

if (!$roll_no) {
    log_email_attempt('Missing roll number.', 'ERROR');
    echo json_encode(['success' => false, 'message' => 'Missing student information.']);
    exit;
}

// ---- Fetch student from master data ----
$stmt = $pdo->prepare("SELECT student_name, email FROM students WHERE roll_no = ?");
$stmt->execute([$roll_no]);
$student = $stmt->fetch();
if (!$student) {
    log_email_attempt('Student not found in master data.', 'ERROR');
    echo json_encode(['success' => false, 'message' => 'Student record not found.']);
    exit;
}
$email = $student['email'];
$student_name = $student['student_name'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    log_email_attempt('Invalid student email.', 'ERROR');
    echo json_encode(['success' => false, 'message' => 'The registered student email address is invalid.']);
    exit;
}

// Rate limiting – now based on roll_no only (exam_id is ignored)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM otp_verifications 
                       WHERE roll_no = ? 
                       AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
$stmt->execute([$roll_no]);
if ($stmt->fetchColumn() > 0) {
    $stmt = $pdo->prepare("SELECT GREATEST(0, 60 - TIMESTAMPDIFF(SECOND, created_at, NOW())) FROM otp_verifications WHERE roll_no = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$roll_no]);
    $retry_after = (int) $stmt->fetchColumn();
    log_email_attempt("Rate limit hit for roll: $roll_no", 'WARNING');
    echo json_encode(['success' => false, 'message' => "Please wait {$retry_after} seconds before requesting a new OTP.", 'retry_after' => $retry_after]);
    exit;
}

// Generate OTP
$otp = random_int(100000, 999999);
$otp_hash = password_hash($otp, PASSWORD_DEFAULT);
$expires_at = $pdo->query("SELECT DATE_ADD(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();

// Invalidate previous OTPs for this roll number
$pdo->prepare("UPDATE otp_verifications SET is_used = 1 WHERE roll_no = ? AND is_used = 0")
    ->execute([$roll_no]);

// Store new OTP (exam_id = 0 for generic)
$stmt = $pdo->prepare("INSERT INTO otp_verifications (roll_no, exam_id, email, otp_hash, expires_at) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$roll_no, 0, $email, $otp_hash, $expires_at]);
$otp_id = $pdo->lastInsertId();

// ---- Send email via PHPMailer ----
$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = SMTP_SECURE;
    $mail->Port       = SMTP_PORT;
    $mail->Timeout    = 30;
    $mail->SMTPKeepAlive = false;
    $mail->SMTPDebug = 0;

    $mail->setFrom(FROM_EMAIL, FROM_NAME);
    $mail->addAddress($email);

    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';

    // HTML body
    $htmlBody = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; background-color: #f4f7fc; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 20px auto; background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
            .header { text-align: center; border-bottom: 2px solid #4CAF50; padding-bottom: 15px; }
            .header h1 { color: #2c3e50; margin: 0; }
            .otp-box { background: #e8f5e9; padding: 20px; text-align: center; border-radius: 6px; margin: 25px 0; }
            .otp-box .otp-code { font-size: 36px; font-weight: bold; color: #2e7d32; letter-spacing: 4px; }
            .footer { margin-top: 30px; font-size: 14px; color: #7f8c8d; text-align: center; border-top: 1px solid #ecf0f1; padding-top: 20px; }
            .footer a { color: #3498db; text-decoration: none; }
            .note { color: #e67e22; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Result Verification</h1>
                <p style='color: #555;'>Secure One-Time Password</p>
            </div>
            <p>Dear <strong>" . htmlspecialchars($student_name) . "</strong>,</p>
            <p>You requested to access your exam results. Please use the OTP below to proceed:</p>
            <div class='otp-box'>
                <div class='otp-code'>" . $otp . "</div>
                <p style='margin: 5px 0 0; font-size: 14px; color: #555;'>This OTP is valid for <strong>5 minutes</strong>.</p>
            </div>
            <p style='color: #e74c3c;'><strong>Do not share this OTP with anyone.</strong></p>
            <p>If you didn't request this, please ignore this email.</p>
            <div class='footer'>
                <p>Regards,<br><strong>Result Portal Team</strong></p>
                <p><small>This is an automated message, please do not reply.</small></p>
            </div>
        </div>
    </body>
    </html>
    ";

    // Plain text alternative
    $plainText = "Dear " . $student_name . ",\n\nYour OTP for accessing your results is: " . $otp . "\nThis OTP is valid for 5 minutes.\nDo not share it with anyone.\n\nRegards,\nResult Portal";

    $mail->isHTML(true);
    $mail->Subject = 'Your OTP for Result Verification';
    $mail->Body    = $htmlBody;
    $mail->AltBody = $plainText;

    $reset_mail = function () use ($mail, $email) {
        $mail->smtpClose();
        $mail->clearAllRecipients();
        $mail->clearAttachments();
        $mail->clearCustomHeaders();
        $mail->addAddress($email);
    };

    $sent = false;
    $send_error = '';
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            $sent = $mail->send();
            if ($sent) {
                break;
            }
            $send_error = $mail->ErrorInfo;
            if ($attempt < 2) {
                $reset_mail();
                usleep(750000);
            }
        } catch (Exception $send_exception) {
            $send_error = $mail->ErrorInfo ?: $send_exception->getMessage();
            if ($attempt < 2) {
                $reset_mail();
                usleep(750000);
            }
        }
    }

    if ($sent) {
        log_email_attempt('Email sent successfully.', 'SUCCESS');
        echo json_encode(['success' => true, 'message' => 'OTP sent successfully to your registered email.', 'cooldown' => 60]);
    } else {
        $pdo->prepare("UPDATE otp_verifications SET is_used = 1 WHERE id = ?")->execute([$otp_id]);
        log_email_attempt('Email sending failed: ' . $send_error, 'ERROR');
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP.']);
    }
} catch (Exception $e) {
    $pdo->prepare("UPDATE otp_verifications SET is_used = 1 WHERE id = ?")->execute([$otp_id]);
    log_email_attempt("Email sending failed: " . $mail->ErrorInfo, 'ERROR');
    echo json_encode(['success' => false, 'message' => 'OTP email could not be sent. Please try again shortly.']);
}
?>