<?php
require_once __DIR__ . '/email_logger.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

// ============================================
// GRADE CONSTANTS (Global Access)
// ============================================
define('ALLOWED_GRADES', ['O', 'A+', 'A', 'B', 'C', 'D', 'E', 'F', 'DT', 'UFM']);

function ensure_admin_security_schema(PDO $pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_security_settings (
        admin_id INT NOT NULL PRIMARY KEY,
        security_email VARCHAR(255) NULL,
        pending_email VARCHAR(255) NULL,
        email_verified_at DATETIME NULL,
        password_changed_at DATETIME NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_security_settings_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_security_otps (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        purpose VARCHAR(40) NOT NULL,
        email VARCHAR(255) NOT NULL,
        otp_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        is_used TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_otp (admin_id, purpose, created_at),
        CONSTRAINT fk_admin_otp_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_security_logs (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NULL,
        username VARCHAR(50) NULL,
        action VARCHAR(80) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'info',
        otp_status VARCHAR(20) NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent TEXT NULL,
        device_info VARCHAR(255) NULL,
        session_id_hash CHAR(64) NULL,
        details VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_activity_at DATETIME NULL,
        logout_at DATETIME NULL,
        INDEX idx_security_log_date (created_at),
        INDEX idx_security_log_admin (admin_id),
        CONSTRAINT fk_security_log_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // ============================================
    // PERMISSION SYSTEM TABLES
    // ============================================
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_roles (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        role_name VARCHAR(50) NOT NULL UNIQUE,
        description TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_role_name (role_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_permissions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        permission_key VARCHAR(100) NOT NULL UNIQUE,
        permission_name VARCHAR(200) NOT NULL,
        module VARCHAR(50) NOT NULL,
        description TEXT,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_module (module),
        INDEX idx_permission_key (permission_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_user_permissions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        permission_id INT NOT NULL,
        granted TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_admin_permission (admin_id, permission_id),
        CONSTRAINT fk_user_permissions_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
        CONSTRAINT fk_user_permissions_perm FOREIGN KEY (permission_id) REFERENCES admin_permissions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS disclaimer_verification_records (
        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        student_id INT NULL,
        roll_no VARCHAR(50) NULL,
        student_name VARCHAR(255) NULL,
        exam_id INT NULL,
        exam_name VARCHAR(255) NULL,
        disclaimer_version VARCHAR(100) NOT NULL DEFAULT 'v1',
        otp_verified TINYINT(1) NOT NULL DEFAULT 0,
        disclaimer_accepted TINYINT(1) NOT NULL DEFAULT 0,
        accepted_at DATETIME NULL,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        session_id_hash CHAR(64) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_disclaimer_roll (roll_no),
        INDEX idx_disclaimer_student (student_id),
        INDEX idx_disclaimer_exam (exam_id),
        INDEX idx_disclaimer_accepted (disclaimer_accepted),
        INDEX idx_disclaimer_date (accepted_at),
        CONSTRAINT fk_disclaimer_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
        CONSTRAINT fk_disclaimer_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // ============================================
    // AUTO-MIGRATION: UPDATE GRADE COLUMN
    // ============================================
    try {
        // Check if grade column exists in student_results
        $stmt = $pdo->query("SHOW COLUMNS FROM student_results LIKE 'grade'");
        $column = $stmt->fetch();
        
        if ($column) {
            $column_type = $column['Type'] ?? '';
            
            // If it's ENUM, update it with new values
            if (strpos(strtoupper($column_type), 'ENUM') !== false) {
                $pdo->exec("ALTER TABLE student_results 
                    MODIFY COLUMN grade ENUM('O','A+','A','B','C','D','E','F','DT','UFM') NOT NULL");
            } 
            // If it's VARCHAR or other, change to VARCHAR for flexibility
            else {
                $pdo->exec("ALTER TABLE student_results MODIFY COLUMN grade VARCHAR(5) NOT NULL");
            }
        }
    } catch (Exception $e) {
        error_log('Grade column migration warning: ' . $e->getMessage());
    }
    
    // Initialize default roles
    $pdo->exec("INSERT IGNORE INTO admin_roles (role_name, description) VALUES 
        ('super_admin', 'Super Administrator with complete system access'),
        ('admin', 'Regular Administrator with limited access')");
    
    // Initialize default permissions
    $permissions = [
        // Student Management
        ['students.view', 'View Students', 'students', 'View list of all students'],
        ['students.search', 'Search Students', 'students', 'Search for specific students'],
        ['students.create', 'Add Student', 'students', 'Create new student records'],
        ['students.edit', 'Edit Student', 'students', 'Modify existing student records'],
        ['students.delete', 'Delete Student', 'students', 'Delete student records'],
        
        // Result Management
        ['results.view', 'View Results', 'results', 'View student results'],
        ['results.upload', 'Upload Results', 'results', 'Upload result data'],
        ['results.edit', 'Edit Results', 'results', 'Modify result records'],
        ['results.delete', 'Delete Results', 'results', 'Delete result records'],
        
        // Exam Management
        ['exams.view', 'View Exams', 'exams', 'View exam records'],
        ['exams.create', 'Create Exam', 'exams', 'Create new exam records'],
        ['exams.edit', 'Edit Exam', 'exams', 'Modify exam records'],
        ['exams.delete', 'Delete Exam', 'exams', 'Delete exam records'],
        
        // Security
        ['security.view', 'View Security Logs', 'security', 'View security and audit logs'],
        ['security.settings', 'Security Settings', 'security', 'Modify security settings'],
        ['disclaimer_records.view', 'View Disclaimer Records', 'disclaimer_records', 'View student disclaimer acceptance logs'],
        ['disclaimer_records.export', 'Export Disclaimer Records', 'disclaimer_records', 'Export disclaimer acceptance records'],
        
        // Admin Management
        ['admins.view', 'View Admins', 'admins', 'View admin users'],
        ['admins.permissions', 'Manage Permissions', 'admins', 'Grant or revoke admin permissions'],
        ['admins.activate', 'Activate/Deactivate Admins', 'admins', 'Activate or deactivate admin accounts']
    ];
    
    foreach ($permissions as $perm) {
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO admin_permissions (permission_key, permission_name, module, description) VALUES (?, ?, ?, ?)");
            $stmt->execute($perm);
        } catch (Exception $e) {
            // Permission may already exist, continue
        }
    }
    
    $pdo->exec("INSERT IGNORE INTO admin_security_settings (admin_id) SELECT id FROM admins");
}

ensure_admin_security_schema($pdo);

// ============================================
// INITIALIZE DUAL ADMIN USERS & ROLE ENFORCEMENT
// ============================================
if (empty($_SESSION['admin_id'])) {  // Only do this on first load of page
    try {
        // Step 1: Ensure ENUM column supports both old and new role values
        try {
            $pdo->exec("ALTER TABLE admins MODIFY COLUMN role ENUM('admin', 'super_admin') NOT NULL DEFAULT 'admin'");
        } catch (Exception $e) {
            // Column modification might fail if it already has these values, that's OK
            error_log('Role ENUM update (may already be done): ' . $e->getMessage());
        }
        
        $default_admin_password_hash = password_hash('admin', PASSWORD_DEFAULT);

        // Step 2: MANDATORY RULE - Ensure 'superadmin' username ALWAYS has 'super_admin' role
        try {
            // Check if superadmin user exists
            $stmt = $pdo->prepare("SELECT id, role FROM admins WHERE username = 'superadmin'");
            $stmt->execute();
            $superadmin_user = $stmt->fetch();
            
            if ($superadmin_user) {
                // ENFORCE: superadmin account must have super_admin role (FIXED)
                if ($superadmin_user['role'] !== 'super_admin') {
                    $pdo->prepare("UPDATE admins SET role = 'super_admin' WHERE id = ?")
                        ->execute([$superadmin_user['id']]);
                }
            } else {
                // Create superadmin user if doesn't exist (rare case on first install)
                $pdo->prepare("INSERT INTO admins (username, password, role) VALUES (?, ?, 'super_admin')")
                    ->execute(['superadmin', $default_admin_password_hash]);
                $superadmin_id = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT IGNORE INTO admin_security_settings (admin_id) VALUES (?)")
                    ->execute([$superadmin_id]);
            }
        } catch (Exception $e) {
            error_log('Superadmin enforcement failed: ' . $e->getMessage());
        }
        
        // Step 3: Ensure 'admin' username exists with 'admin' role (CONTROLLED by superadmin)
        try {
            $stmt = $pdo->prepare("SELECT id, role FROM admins WHERE username = 'admin'");
            $stmt->execute();
            $admin_user = $stmt->fetch();
            
            if ($admin_user) {
                // ENFORCE: admin account must NEVER have super_admin role
                if ($admin_user['role'] !== 'admin') {
                    $pdo->prepare("UPDATE admins SET role = 'admin' WHERE id = ?")
                        ->execute([$admin_user['id']]);
                }
            } else {
                // Create admin user if doesn't exist (only on first install)
                $pdo->prepare("INSERT INTO admins (username, password, role) VALUES (?, ?, 'admin')")
                    ->execute(['admin', $default_admin_password_hash]);
                $admin_id = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT IGNORE INTO admin_security_settings (admin_id) VALUES (?)")
                    ->execute([$admin_id]);
                
                // Grant all initial permissions to admin user
                $permissions = $pdo->query("SELECT id FROM admin_permissions")->fetchAll();
                foreach ($permissions as $perm) {
                    $pdo->prepare("INSERT IGNORE INTO admin_user_permissions (admin_id, permission_id, granted) VALUES (?, ?, 1)")
                        ->execute([$admin_id, $perm['id']]);
                }
            }
        } catch (Exception $e) {
            error_log('Admin user initialization failed: ' . $e->getMessage());
        }
        
        // Step 4: SECURITY CHECK - Prevent any admin with role='super_admin' except superadmin username
        try {
            $pdo->prepare("UPDATE admins SET role = 'admin' WHERE role = 'super_admin' AND username != 'superadmin'")
                ->execute();
        } catch (Exception $e) {
            error_log('Role escalation prevention failed: ' . $e->getMessage());
        }
    } catch (Exception $e) {
        // Initialization errors are non-critical, continue
        error_log('Admin initialization error: ' . $e->getMessage());
    }
}

if (!empty($_SESSION['admin_id']) && empty($_SESSION['admin_otp_verified'])) {
    unset($_SESSION['admin_id'], $_SESSION['username'], $_SESSION['last_activity']);
}

function security_device_info()
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    if (preg_match('/(Windows|Macintosh|Linux|Android|iPhone|iPad)/i', $ua, $match)) {
        return $match[1];
    }
    return 'Unknown';
}

function log_security_event(PDO $pdo, $action, $admin_id = null, $username = null, $status = 'info', $otp_status = null, $details = null)
{
    $stmt = $pdo->prepare("INSERT INTO admin_security_logs
        (admin_id, username, action, status, otp_status, ip_address, user_agent, device_info, session_id_hash, details)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $session_hash = session_id() ? hash('sha256', session_id()) : null;
    $stmt->execute([
        $admin_id,
        $username,
        $action,
        $status,
        $otp_status,
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        security_device_info(),
        $session_hash,
        $details
    ]);
}

function save_disclaimer_verification_record(PDO $pdo, $student_id = null, $roll_no = null, $student_name = null, $exam_id = null, $exam_name = null, $disclaimer_version = 'v1', $otp_verified = 1, $disclaimer_accepted = 1, $accepted_at = null)
{
    if (!is_numeric($student_id) && $student_id !== null) {
        return false;
    }
    if ((string) $roll_no === '' || (int) $otp_verified !== 1 || (int) $disclaimer_accepted !== 1) {
        return false;
    }

    $normalized_exam_id = ($exam_id !== null && (int) $exam_id > 0) ? (int) $exam_id : null;
    $normalized_exam_name = ($normalized_exam_id !== null) ? (string) $exam_name : 'Generic Result Access';
    $accepted_time = $accepted_at ?: date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO disclaimer_verification_records 
        (student_id, roll_no, student_name, exam_id, exam_name, disclaimer_version, otp_verified, disclaimer_accepted, accepted_at, ip_address, user_agent, session_id_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $student_id,
        $roll_no,
        $student_name,
        $normalized_exam_id,
        $normalized_exam_name,
        $disclaimer_version,
        (int) $otp_verified,
        (int) $disclaimer_accepted,
        $accepted_time,
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        session_id() ? hash('sha256', session_id()) : null
    ]);

    return (int) $pdo->lastInsertId();
}

function admin_password_errors($password)
{
    $errors = [];
    if (strlen($password) < 12) $errors[] = 'Password must be at least 12 characters.';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must include an uppercase letter.';
    if (!preg_match('/[a-z]/', $password)) $errors[] = 'Password must include a lowercase letter.';
    if (!preg_match('/\d/', $password)) $errors[] = 'Password must include a number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = 'Password must include a special character.';
    return $errors;
}

function admin_security_email(PDO $pdo, $admin_id)
{
    $stmt = $pdo->prepare('SELECT security_email FROM admin_security_settings WHERE admin_id = ?');
    $stmt->execute([$admin_id]);
    return (string) ($stmt->fetchColumn() ?: '');
}

function send_admin_password_reset_email($email, $username, $reset_link)
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $reset_link === '') return false;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = strtolower(trim((string) SMTP_SECURE)) ?: false;
        $mail->Port = SMTP_PORT;
        $mail->Timeout = 30;
        $mail->SMTPDebug = 0;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($email, $username);
        $mail->isHTML(true);
        $safe_username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safe_link = htmlspecialchars($reset_link, ENT_QUOTES, 'UTF-8');
        $mail->Subject = 'Back Result Portal - Password Reset';
        $mail->Body = '<!doctype html><html lang="en"><body style="margin:0;padding:24px;background:#f3f6f9;color:#253448;font-family:Arial,Helvetica,sans-serif;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="100%" style="max-width:600px;background:#fff;border:1px solid #dfe6ed;border-radius:8px;padding:32px;"><tr><td><p style="margin:0 0 18px;font-size:13px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#123b5d;">Back Result Portal</p><h1 style="font-size:24px;margin:0 0 20px;">Reset your admin password</h1><p>Hello' . ($safe_username !== '' ? ' <strong>' . $safe_username . '</strong>' : '') . ',</p><p>We received a request to reset your Admin Portal password. Use the button below to continue. This link expires in 1 hour.</p><p style="margin:28px 0;text-align:center;"><a href="' . $safe_link . '" style="display:inline-block;background:#123b5d;color:#fff;text-decoration:none;padding:13px 24px;border-radius:6px;font-weight:bold;">Reset Password</a></p><p style="font-size:14px;color:#536577;">If you did not request this, ignore this email. Do not share this link with anyone.</p><p style="margin:24px 0 0;padding-top:18px;border-top:1px solid #e5ebf0;color:#71808e;font-size:12px;text-align:center;">Admin Portal Security Team<br>This is an automated message. Please do not reply.</p></td></tr></table></td></tr></table></body></html>';
        $mail->AltBody = "Back Result Portal\n\nReset your admin password\n\nUse this link within 1 hour:\n{$reset_link}\n\nIf you did not request this, ignore this email. Do not share this link with anyone.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Admin password reset email failed: ' . $e->getMessage());
        return false;
    }
}

function send_admin_otp(PDO $pdo, $admin_id, $purpose, $email, $username = '', $is_resend = false)
{
    $email = trim((string) $email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        log_security_event($pdo, 'OTP Email Delivery Failed', $admin_id, $username, 'failed', 'pending', 'Invalid security email address');
        error_log('Admin OTP generation aborted: invalid email address for admin_id=' . (int) $admin_id);
        return false;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_security_otps WHERE admin_id = ? AND purpose = ? AND created_at > DATE_SUB(NOW(), INTERVAL 60 SECOND)");
    $stmt->execute([$admin_id, $purpose]);
    if ((int) $stmt->fetchColumn() > 0) {
        log_security_event($pdo, 'OTP Request Rate Limited', $admin_id, $username, 'failed', 'pending', 'Recent OTP already sent');
        return false;
    }

    $pdo->prepare('UPDATE admin_security_otps SET is_used = 1 WHERE admin_id = ? AND purpose = ? AND is_used = 0')->execute([$admin_id, $purpose]);
    $otp = (string) random_int(100000, 999999);
    $stmt = $pdo->prepare("INSERT INTO admin_security_otps (admin_id, purpose, email, otp_hash, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 2 MINUTE))");
    $stmt->execute([$admin_id, $purpose, $email, password_hash($otp, PASSWORD_DEFAULT)]);
    $otp_id = $pdo->lastInsertId();
    log_security_event($pdo, 'OTP Generated', $admin_id, $username, 'success', 'pending');

    try {
        $portal_name = 'Back Result Portal';
        $safe_otp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $safe_username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $subject = $portal_name . ' - Admin Login - Security Verification';
        $body = '<!doctype html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Security Verification</title></head>
<body style="margin:0;padding:0;background:#f3f6f9;color:#253448;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f6f9;"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid #dfe6ed;border-radius:10px;overflow:hidden;">
<tr><td style="background:#123b5d;padding:28px 32px;text-align:center;color:#ffffff;"><div style="font-size:13px;letter-spacing:1.5px;text-transform:uppercase;font-weight:bold;">' . $portal_name . '</div><h1 style="margin:12px 0 0;font-size:26px;line-height:1.25;font-weight:700;">Admin Login - Security Verification</h1></td></tr>
<tr><td style="padding:32px;line-height:1.6;font-size:16px;"><p style="margin:0 0 18px;">Hello' . ($safe_username !== '' ? ' <strong>' . $safe_username . '</strong>' : '') . ',</p><p style="margin:0 0 22px;">A one-time password has been generated for your Admin Portal login. Enter the code below to continue.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="background:#eaf3f8;border:1px solid #c9dce8;border-radius:8px;padding:24px 16px;"><div style="font-size:34px;line-height:1.2;letter-spacing:8px;font-weight:700;color:#123b5d;">' . $safe_otp . '</div><div style="margin-top:10px;font-size:14px;color:#536577;">Valid for 2 minutes</div></td></tr></table>
<p style="margin:24px 0 8px;color:#a33a2b;font-weight:700;">Do not share this OTP with anyone.</p><p style="margin:0;color:#536577;font-size:14px;">If you did not try to sign in, you can safely ignore this email and review your account security.</p></td></tr>
<tr><td style="border-top:1px solid #e5ebf0;padding:22px 32px;text-align:center;color:#71808e;font-size:12px;line-height:1.5;">This is an automated security message from ' . $portal_name . '.<br>Please do not reply to this email.</td></tr>
</table></td></tr></table></body></html>';
        $alt_body = "{$portal_name}\n\nAdmin Login - Security Verification\n\nYour one-time password for Admin Portal login is: {$otp}\nThis OTP is valid for 2 minutes.\n\nDo not share this OTP with anyone.\n\nThis is an automated message. Please do not reply.";
        $sent = false;
        $send_error = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = SMTP_HOST;
                $mail->SMTPAuth = true;
                $mail->Username = SMTP_USER;
                $mail->Password = SMTP_PASS;
                $mail->SMTPSecure = strtolower(trim((string) SMTP_SECURE)) ?: false;
                $mail->Port = SMTP_PORT;
                $mail->Timeout = 30;
                $mail->SMTPDebug = 0;
                $mail->CharSet = 'UTF-8';
                $mail->Encoding = 'base64';
                $mail->setFrom(FROM_EMAIL, FROM_NAME);
                $mail->addAddress($email, $username);
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $body;
                $mail->AltBody = $alt_body;
                $sent = $mail->send();
                if ($sent) {
                    break;
                }
                $send_error = $mail->ErrorInfo;
            } catch (Exception $send_exception) {
                $send_error = $mail->ErrorInfo ?: $send_exception->getMessage();
            }

            if ($attempt < 2) {
                usleep(750000);
            }
        }

        if ($sent) {
            log_email_attempt('Admin OTP email sent successfully to ' . $email, 'SUCCESS');
            log_security_event($pdo, 'OTP Email Sent Successfully', $admin_id, $username, 'success', 'pending');
            if ($is_resend) {
                log_security_event($pdo, 'OTP Resent', $admin_id, $username, 'success', 'pending');
            }
            return true;
        }

        if (!empty($otp_id)) {
            $pdo->prepare('UPDATE admin_security_otps SET is_used = 1 WHERE id = ?')->execute([$otp_id]);
        }
        log_security_event($pdo, 'OTP Email Delivery Failed', $admin_id, $username, 'failed', 'pending', 'SMTP delivery failed after retry');
        log_email_attempt('Admin OTP email failed for ' . $email . ': ' . $send_error, 'ERROR');
        error_log('Admin OTP email failed after retry: ' . $send_error);
        return false;
    } catch (Exception $e) {
        if (!empty($otp_id)) {
            $pdo->prepare('UPDATE admin_security_otps SET is_used = 1 WHERE id = ?')->execute([$otp_id]);
        }
        log_security_event($pdo, 'OTP Email Delivery Failed', $admin_id, $username, 'failed', 'pending', 'SMTP delivery failed');
        log_email_attempt('Admin OTP email failed for ' . $email . ': ' . $e->getMessage(), 'ERROR');
        error_log('Admin OTP email failed: ' . $e->getMessage());
        return false;
    }
}

function verify_admin_otp(PDO $pdo, $admin_id, $purpose, $otp, $username = null)
{
    $stmt = $pdo->prepare('SELECT *, expires_at <= NOW() AS is_expired FROM admin_security_otps WHERE admin_id = ? AND purpose = ? AND is_used = 0 ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$admin_id, $purpose]);
    $record = $stmt->fetch();
    if (!$record) return 'missing';
    if ((int) $record['is_expired'] === 1) {
        $pdo->prepare('UPDATE admin_security_otps SET is_used = 1 WHERE id = ?')->execute([$record['id']]);
        log_security_event($pdo, 'OTP Expired', $admin_id, $username, 'failed', 'expired');
        return 'expired';
    }
    if ((int) $record['attempts'] >= 5) {
        $pdo->prepare('UPDATE admin_security_otps SET is_used = 1 WHERE id = ?')->execute([$record['id']]);
        log_security_event($pdo, 'OTP Verification Failed', $admin_id, $username, 'failed', 'blocked', 'Maximum OTP attempts exceeded');
        return 'blocked';
    }
    if (preg_match('/^\d{6}$/', (string) $otp) && password_verify((string) $otp, $record['otp_hash'])) {
        $pdo->prepare('UPDATE admin_security_otps SET is_used = 1 WHERE id = ?')->execute([$record['id']]);
        return 'verified';
    }
    $pdo->prepare('UPDATE admin_security_otps SET attempts = attempts + 1 WHERE id = ?')->execute([$record['id']]);
    return 'failed';
}

function admin_current_role(PDO $pdo): string
{
    if (empty($_SESSION['admin_id'])) {
        return 'admin';
    }

    $stmt = $pdo->prepare('SELECT username, role FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        return 'admin';
    }

    $_SESSION['username'] = $admin['username'];
    $_SESSION['admin_role'] = $admin['role'];

    return $_SESSION['admin_role'];
}

function is_super_admin(PDO $pdo): bool
{
    if (empty($_SESSION['admin_id'])) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT username, role FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        return false;
    }

    $_SESSION['username'] = $admin['username'];
    $_SESSION['admin_role'] = $admin['role'];
    return $admin['username'] === 'superadmin' && $admin['role'] === 'super_admin';
}

function has_permission(PDO $pdo, string $permission_key): bool
{
    // Super admin always has all permissions
    if (is_super_admin($pdo)) {
        return true;
    }
    
    if (empty($_SESSION['admin_id'])) {
        return false;
    }
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM admin_user_permissions aup
        JOIN admin_permissions ap ON aup.permission_id = ap.id
        WHERE aup.admin_id = ? AND ap.permission_key = ? AND aup.granted = 1
    ");
    $stmt->execute([$_SESSION['admin_id'], $permission_key]);
    return (int) $stmt->fetchColumn() > 0;
}

function require_permission(PDO $pdo, string $permission_key)
{
    if (!has_permission($pdo, $permission_key)) {
        log_security_event($pdo, 'Unauthorized Access Attempt', $_SESSION['admin_id'] ?? null, $_SESSION['username'] ?? null, 'failed', null, "Access denied for: {$permission_key}");
        http_response_code(403);
        echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Access Denied</title></head><body style="font-family:Arial,sans-serif;background:#f6f7fb;color:#1f2937;margin:0;padding:48px;text-align:center;"><div style="max-width:520px;margin:80px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;box-shadow:0 10px 25px rgba(0,0,0,0.05);"><h1 style="margin:0 0 12px;color:#b71d25;font-size:28px;">Access Denied</h1><p style="margin:0;line-height:1.6;">You do not have permission to access this resource.</p></div></body></html>';
        exit();
    }
}

function require_super_admin(PDO $pdo)
{
    if (!is_super_admin($pdo)) {
        http_response_code(403);
        echo '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Access Denied</title></head><body style="font-family:Arial,sans-serif;background:#f6f7fb;color:#1f2937;margin:0;padding:48px;text-align:center;"><div style="max-width:520px;margin:80px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;box-shadow:0 10px 25px rgba(0,0,0,0.05);"><h1 style="margin:0 0 12px;color:#b71d25;font-size:28px;">Access Denied</h1><p style="margin:0;line-height:1.6;">You are not authorized to access this security area.</p></div></body></html>';
        exit();
    }
}

function require_admin_session(PDO $pdo)
{
    if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_otp_verified'])) {
        header('Location: ../admin_login.php');
        exit();
    }
    if (!empty($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 900) {
        log_security_event($pdo, 'Session Expired', $_SESSION['admin_id'], $_SESSION['username'] ?? null, 'failed');
        session_unset();
        session_destroy();
        header('Location: ../admin_login.php?timeout=1');
        exit();
    }
    admin_current_role($pdo);
    $_SESSION['last_activity'] = time();
    
    // CRITICAL: Enforce role security on every page load to prevent escalation
    enforce_role_security($pdo);
}

/**
 * CRITICAL SECURITY FUNCTION
 * Prevents role escalation: ensures 'admin' account can NEVER become 'super_admin'
 * and that ONLY 'superadmin' username can have 'super_admin' role
 */
function enforce_role_security(PDO $pdo)
{
    $admin_id = $_SESSION['admin_id'] ?? null;
    if (!$admin_id) {
        return;
    }
    
    // Always use the database identity and role, never values supplied by the session.
    $stmt = $pdo->prepare("SELECT username, role FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        unset($_SESSION['admin_id'], $_SESSION['username'], $_SESSION['admin_role'], $_SESSION['admin_otp_verified']);
        return;
    }

    $username = $admin['username'];
    $db_role = $admin['role'];

    if ($username === 'admin' && $db_role !== 'admin') {
        error_log("SECURITY ALERT: Attempted role escalation detected for username 'admin'");
        $pdo->prepare("UPDATE admins SET role = 'admin' WHERE id = ?")->execute([$admin_id]);
        log_security_event($pdo, 'Role Escalation Attempt Blocked', $admin_id, $username, 'failed', null, 'Attempted to escalate to super_admin - forced back to admin role');
        $db_role = 'admin';
    }

    if ($username === 'superadmin' && $db_role !== 'super_admin') {
        error_log("SECURITY ALERT: Superadmin role corrupted");
        $pdo->prepare("UPDATE admins SET role = 'super_admin' WHERE id = ?")->execute([$admin_id]);
        $db_role = 'super_admin';
    }

    $_SESSION['username'] = $username;
    $_SESSION['admin_role'] = $db_role;
}