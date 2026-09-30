<?php
require_once '../includes/config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['csrf_token']) && verify_csrf_token($_POST['csrf_token'])) {
    $username = sanitize_input($_POST['username'] ?? '');

    $stmt = $pdo->prepare("SELECT id, username FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin) {
        $token = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);
        $stmt = $pdo->prepare("UPDATE admins SET reset_token = ?, reset_token_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?");
        $stmt->execute([$token_hash, $admin['id']]);
        $verify_stmt = $pdo->prepare('SELECT reset_token, reset_token_expiry FROM admins WHERE id = ?');
        $verify_stmt->execute([$admin['id']]);
        $stored_reset = $verify_stmt->fetch();
        if (!$stored_reset || !hash_equals($token_hash, (string) $stored_reset['reset_token'])) {
            error_log('Password reset token storage verification failed for admin_id=' . (int) $admin['id']);
            log_security_event($pdo, 'Password Reset Token Storage Failed', $admin['id'], $admin['username'], 'failed', 'not_required');
            $error = 'Unable to create the password reset link. Please try again.';
        }
        $email = admin_security_email($pdo, $admin['id']) ?: ADMIN_SECURITY_EMAIL;
        $application_url = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        if ($application_url === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
            $application_url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/reset_password.php'))), '/');
        }
        $reset_link = $application_url . '/admin/reset_password.php?token=' . rawurlencode($token);
        error_log('Password reset token generated and stored for admin_id=' . (int) $admin['id'] . ', expiry=' . ($stored_reset['reset_token_expiry'] ?? 'unknown'));
        if (empty($error) && send_admin_password_reset_email($email, $admin['username'], $reset_link)) {
            error_log('Password reset link sent for admin_id=' . (int) $admin['id']);
            log_activity($pdo, 'Password reset email sent for admin account', $admin['id']);
            log_security_event($pdo, 'Password Reset Requested', $admin['id'], $admin['username'], 'success', 'not_required');
            log_security_event($pdo, 'Password Reset Email Sent', $admin['id'], $admin['username'], 'success', 'not_required', 'Reset link emailed');
            $success = 'If the account exists, a password reset link has been sent to the configured security email.';
        } elseif (empty($error)) {
            $pdo->prepare('UPDATE admins SET reset_token = NULL, reset_token_expiry = NULL WHERE id = ?')->execute([$admin['id']]);
            error_log('Password reset email delivery failed for admin_id=' . (int) $admin['id']);
            log_security_event($pdo, 'Password Reset Email Failed', $admin['id'], $admin['username'], 'failed', 'not_required', 'Unable to send reset email');
            $error = 'Unable to send the password reset email. Please try again.';
        }
    } else {
        $success = 'If the account exists, a password reset link has been sent to the configured security email.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $error = 'Invalid security token. Please refresh and try again.';
}

include '../includes/header.php';
?>

<main class="auth-page py-5">
    <div class="container">
        <div class="row justify-content-center align-items-center" style="min-height: 70vh;">
            <div class="col-md-5 col-sm-10">
                <div class="card glass-card p-4 shadow-lg border-0 animate__animated animate__fadeInUp">
                    <div class="text-center mb-4">
                        <i class="fas fa-key fa-3x text-primary mb-3"></i>
                        <h2 class="fw-bold">Forgot Password</h2>
                        <p class="text-muted mb-0">Reset your admin password securely</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert">
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$success): ?>
                        <form action="" method="POST" id="forgotPasswordForm" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <div class="mb-3">
                                <label class="form-label fw-medium">Admin Username</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-0"><i class="fas fa-user"></i></span>
                                    <input type="text" name="username" class="form-control bg-light border-0" required placeholder="Enter your username" autocomplete="username">
                                </div>
                            </div>
                            <p class="small text-muted mb-4">A secure reset link will be sent to the configured admin security email.</p>
                            <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill fw-bold" id="forgotPasswordButton">
                                <span class="button-label">Generate Reset Link</span>
                                <i class="fas fa-arrow-right ms-2"></i>
                            </button>
                        </form>
                    <?php endif; ?>

                    <div class="mt-3 text-center">
                        <a href="../admin_login.php" class="text-decoration-none small">
                            <i class="fas fa-arrow-left me-1"></i> Back to Login
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    document.getElementById('forgotPasswordForm')?.addEventListener('submit', function () {
        const button = document.getElementById('forgotPasswordButton');
        const label = button?.querySelector('.button-label');
        if (button) button.disabled = true;
        if (label) label.textContent = 'Sending...';
    });
</script>

<?php include '../includes/footer.php'; ?>