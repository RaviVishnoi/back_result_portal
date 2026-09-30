<?php
require_once '../includes/config.php';

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = '';
$admin = null;

error_log('Password reset link received, token_format=' . (preg_match('/^[a-f0-9]{64}$/', $token) ? 'valid' : 'invalid'));

if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    $error = 'Invalid or expired link. Please request a new password reset link.';
} else {
    $token_hash = hash('sha256', $token);
    $stmt = $pdo->prepare("SELECT id, username, reset_token_expiry FROM admins WHERE reset_token = ? AND reset_token_expiry IS NOT NULL AND reset_token_expiry > NOW() LIMIT 1");
    $stmt->execute([$token_hash]);
    $admin = $stmt->fetch();

    if (!$admin) {
        error_log('Password reset token lookup failed or expired');
        $error = 'Invalid or expired token. Please request a new password reset link.';
    } else {
        error_log('Password reset token validated for admin_id=' . (int) $admin['id'] . ', expiry=' . $admin['reset_token_expiry']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['csrf_token'])) {
    $error = 'Invalid security token. Please refresh and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf_token($_POST['csrf_token'])) {
    $error = 'Invalid security token. Please refresh and try again.';
} elseif (empty($error) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $password_errors = admin_password_errors($password);
    if ($password_errors) {
        $error = implode(' ', $password_errors);
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE admins SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ? AND reset_token = ? AND reset_token_expiry IS NOT NULL AND reset_token_expiry > NOW()");
        if ($stmt->execute([$hash, $admin['id'], hash('sha256', $token)]) && $stmt->rowCount() === 1) {
            $pdo->prepare('UPDATE admin_security_settings SET password_changed_at = NOW() WHERE admin_id = ?')->execute([$admin['id']]);
            error_log('Password reset update succeeded for admin_id=' . (int) $admin['id']);
            log_activity($pdo, 'Password reset successfully via token', $admin['id']);
            log_security_event($pdo, 'Password Reset Success', $admin['id'], $admin['username'], 'success', 'not_required', 'Password reset via token');
            $success = 'Password updated successfully. You can now log in.';
        } else {
            error_log('Password reset update failed because token was reused or expired for admin_id=' . (int) $admin['id']);
            log_security_event($pdo, 'Password Reset Failed', $admin['id'], $admin['username'], 'failed', 'not_required', 'Token was already used or expired');
            $error = 'Something went wrong. Please try again.';
        }
    }
}

include '../includes/header.php';
?>

<main class="auth-page py-5">
    <div class="container">
        <div class="row justify-content-center align-items-center" style="min-height: 70vh;">
            <div class="col-md-5 col-sm-10">
                <div class="card glass-card p-4 shadow-lg border-0 animate__animated animate__fadeInUp">
                    <div class="text-center mb-4">
                        <i class="fas fa-lock fa-3x text-primary mb-3"></i>
                        <h2 class="fw-bold">Reset Password</h2>
                        <p class="text-muted mb-0">Create a new secure admin password</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                            <div class="mt-3">
                                <a href="forgot_password.php" class="btn btn-outline-primary btn-sm rounded-pill px-3">Request a new reset link</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success" role="alert">
                            <?php echo htmlspecialchars($success); ?>
                            <div class="mt-3">
                                <a href="../admin_login.php" class="btn btn-primary btn-sm rounded-pill px-3">Continue to login</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!$success && empty($error)): ?>
                        <form action="reset_password.php?token=<?php echo rawurlencode($token); ?>" method="POST" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="mb-3">
                                <label class="form-label fw-medium">New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-0"><i class="fas fa-key"></i></span>
                                    <input type="password" id="new_password" name="password" class="form-control bg-light border-0 border-end-0" required minlength="12" autocomplete="new-password">
                                    <button class="btn btn-light bg-light border-0 toggle-password" type="button" data-target="new_password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <p class="small text-muted mb-4">Password must be at least 12 characters and include uppercase, lowercase, a number, and a special character.</p>
                            <div class="mb-4">
                                <label class="form-label fw-medium">Confirm Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-0"><i class="fas fa-key"></i></span>
                                    <input type="password" id="confirm_password" name="confirm_password" class="form-control bg-light border-0 border-end-0" required autocomplete="new-password">
                                    <button class="btn btn-light bg-light border-0 toggle-password" type="button" data-target="confirm_password">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill fw-bold">Reset Password</button>
                        </form>
                    <?php endif; ?>

                    <?php if (!$success): ?>
                        <div class="mt-3 text-center">
                            <a href="../admin_login.php" class="text-decoration-none small"><i class="fas fa-arrow-left me-1"></i> Back to Login</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
document.querySelectorAll('.toggle-password').forEach(button => {
    button.addEventListener('click', function() {
        const targetId = this.getAttribute('data-target');
        const input = document.getElementById(targetId);
        const icon = this.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>