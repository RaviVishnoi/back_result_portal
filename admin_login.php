<?php
require_once 'includes/config.php';

// Redirect if already logged in
if (isset($_SESSION['admin_id']) && !empty($_SESSION['admin_otp_verified'])) {
    header("Location: admin/dashboard.php");
    exit();
}

$error = '';
$success = '';

if (isset($_GET['reset_login']) && $_GET['reset_login'] === '1') {
    unset($_SESSION['admin_pending_id'], $_SESSION['admin_pending_username'], $_SESSION['admin_pending_email']);
    $_SESSION['admin_otp_verified'] = false;
}

$step = isset($_SESSION['admin_pending_id']) ? 'otp' : 'password';
$masked_email = '';
$otp_expires_at = 0;
$otp_created_at = 0;
$otp_record = null;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? (isset($_POST['otp']) ? 'verify_otp' : 'password');
    if ($action === 'password') {
        check_rate_limit($pdo);
    }
    // CSRF check
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = "Invalid security token. Please refresh and try again.";
    } else {
        if ($action === 'verify_otp' && isset($_SESSION['admin_pending_id'])) {
            $admin_id = (int) $_SESSION['admin_pending_id'];
            $pending_username = $_SESSION['admin_pending_username'] ?? '';
            $otp_result = verify_admin_otp($pdo, $admin_id, 'login', $_POST['otp'] ?? '', $pending_username);
            if ($otp_result === 'verified') {
                $admin_role_stmt = $pdo->prepare('SELECT role FROM admins WHERE id = ?');
                $admin_role_stmt->execute([$admin_id]);
                $admin_role = $admin_role_stmt->fetchColumn() ?: 'admin';

                session_regenerate_id(true);
                $_SESSION['admin_id'] = $admin_id;
                $_SESSION['username'] = $pending_username;
                $_SESSION['admin_role'] = $admin_role;
                $_SESSION['admin_otp_verified'] = true;
                $_SESSION['last_activity'] = time();
                log_security_event($pdo, 'OTP Verification', $admin_id, $pending_username, 'success', 'verified');
                unset($_SESSION['admin_pending_id'], $_SESSION['admin_pending_username'], $_SESSION['admin_pending_email']);
                log_activity($pdo, 'Admin logged in successfully', $admin_id);
                header('Location: admin/dashboard.php');
                exit();
            }
            log_security_event($pdo, 'OTP Verification', $admin_id, $pending_username, 'failed', $otp_result);
            $error = $otp_result === 'expired'
                ? 'This OTP has expired. Please request a new OTP.'
                : ($otp_result === 'blocked'
                    ? 'Too many attempts. Please request a new OTP.'
                    : 'Invalid OTP. Please check the OTP and try again.');
            $step = 'otp';
        } elseif ($action === 'resend_otp' && isset($_SESSION['admin_pending_id'])) {
            $admin_id = (int) $_SESSION['admin_pending_id'];
            $security_email = admin_security_email($pdo, $admin_id);
            $sent = send_admin_otp($pdo, $admin_id, 'login', $security_email, $_SESSION['admin_pending_username'], true);
            if ($sent) {
                $_SESSION['admin_pending_email'] = $security_email;
            }
            $error = $sent ? '' : 'Unable to send OTP. Please try again.';
            $success = $sent ? 'A new OTP was sent to your registered security email.' : '';
            $step = 'otp';
        } elseif ($action === 'password') {
            $username = sanitize_input($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            $stmt = $pdo->prepare("SELECT id, username, password, role, login_attempts, UNIX_TIMESTAMP(last_attempt) as last_time FROM admins WHERE username = ?");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

        if ($admin) {
            // Check if account is locked (5 failed attempts = 15 min lock)
            if ($admin['login_attempts'] >= 5 && (time() - $admin['last_time']) < 900) {
                $remaining = ceil((900 - (time() - $admin['last_time'])) / 60);
                $error = "Account temporarily locked. Please try again after $remaining minute(s).";
                log_security_event($pdo, 'Suspicious Login Attempt', $admin['id'], $username, 'failed', 'not_required', 'Account lockout');
            } else {
                if (password_verify($password, $admin['password'])) {
                    // Success — Reset attempts
                    $pdo->prepare("UPDATE admins SET login_attempts = 0, last_attempt = NULL WHERE id = ?")
                        ->execute([$admin['id']]);

                    $security_email = admin_security_email($pdo, $admin['id']);
                    if (!$security_email) {
                        $security_email = ADMIN_SECURITY_EMAIL;
                        $pdo->prepare('UPDATE admin_security_settings SET security_email = ? WHERE admin_id = ?')->execute([$security_email, $admin['id']]);
                    }
                    if (!send_admin_otp($pdo, $admin['id'], 'login', $security_email, $admin['username'])) {
                        $error = 'Unable to send the security OTP. Please try again.';
                        log_security_event($pdo, 'OTP Email Delivery Failed', $admin['id'], $admin['username'], 'failed', 'pending', 'Login OTP generation failed');
                    } else {
                        $_SESSION['admin_pending_id'] = (int) $admin['id'];
                        $_SESSION['admin_pending_username'] = $admin['username'];
                        $_SESSION['admin_pending_email'] = $security_email;
                        log_security_event($pdo, 'Password Authentication', $admin['id'], $admin['username'], 'success', 'pending');
                        session_write_close();
                        session_start();
                        $step = 'otp';
                    }
                } else {
                    // Failure — Increment attempts
                    $pdo->prepare("UPDATE admins SET login_attempts = login_attempts + 1, last_attempt = CURRENT_TIMESTAMP WHERE id = ?")
                        ->execute([$admin['id']]);

                    log_activity($pdo, "Failed login attempt for: $username");
                    log_security_event($pdo, 'Failed Login', $admin['id'], $username, 'failed', 'not_required');
                    $error = "Invalid username or password.";
                }
            }
        } else {
            // Username not found — same generic error (security best practice)
            log_security_event($pdo, 'Failed Login', null, $username, 'failed', 'not_required');
            $error = "Invalid username or password.";
            }
        } else {
            unset($_SESSION['admin_pending_id'], $_SESSION['admin_pending_username'], $_SESSION['admin_pending_email']);
            $step = 'password';
            $error = 'Your login session expired. Please enter your credentials again.';
        }
    }
}

if ($step === 'otp') {
    $pending_email = $_SESSION['admin_pending_email'] ?? '';
    $parts = explode('@', $pending_email, 2);
    $masked_email = strlen($parts[0] ?? '') > 2
        ? substr($parts[0], 0, 2) . str_repeat('*', max(1, strlen($parts[0]) - 2)) . '@' . ($parts[1] ?? '')
        : $pending_email;
    $stmt = $pdo->prepare("SELECT UNIX_TIMESTAMP(expires_at) AS expires_at, UNIX_TIMESTAMP(created_at) AS created_at, attempts
                           FROM admin_security_otps
                           WHERE admin_id = ? AND purpose = 'login' AND is_used = 0
                           ORDER BY created_at DESC, id DESC LIMIT 1");
    $stmt->execute([(int) $_SESSION['admin_pending_id']]);
    $otp_record = $stmt->fetch();
    if ($otp_record) {
        $otp_expires_at = (int) ($otp_record['expires_at'] ?? 0);
        $otp_created_at = (int) ($otp_record['created_at'] ?? 0);
    } else {
        unset($_SESSION['admin_pending_id'], $_SESSION['admin_pending_username'], $_SESSION['admin_pending_email']);
        $step = 'password';
        $error = 'Your security code has expired or is no longer valid. Please log in again to receive a new OTP.';
    }
}
$otp_attempts_used = (int) ($otp_record['attempts'] ?? 0);
$otp_attempts_remaining = max(0, 5 - $otp_attempts_used);

include 'includes/header.php';
?>

<style>
:root {
  --admin-bg: #f4f6f9;
  --admin-panel: #ffffff;
  --admin-panel-soft: #f8fafc;
  --admin-border: #e5e7eb;
  --admin-text: #1f2937;
  --admin-muted: #5f6b7a;
  --admin-red: #b71d25;
  --admin-red-dark: #8f1218;
  --admin-red-soft: rgba(183, 29, 37, 0.08);
  --admin-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
  --admin-focus: rgba(183, 29, 37, 0.16);
}

.auth-page {
  background: linear-gradient(180deg, #f5f7fa 0%, #eef2f7 100%);
  min-height: calc(100vh - 70px);
  overflow: hidden;
  position: relative;
}

.auth-page::before,
.auth-page::after {
  background: rgba(183, 29, 37, 0.04);
  border-radius: 50%;
  content: "";
  pointer-events: none;
  position: absolute;
}

.auth-page::before {
  height: 420px;
  right: -150px;
  top: -120px;
  width: 420px;
}

.auth-page::after {
  bottom: -130px;
  left: -110px;
  height: 310px;
  width: 310px;
}

.auth-page .container {
  position: relative;
  z-index: 1;
}

.admin-auth-card,
.admin-otp-card {
  background: var(--admin-panel);
  border: 1px solid rgba(148, 163, 184, 0.22) !important;
  border-radius: 18px;
  box-shadow: 0 14px 28px rgba(15, 23, 42, 0.06);
  margin: 0 auto;
  max-width: 430px;
  padding: 2rem 1.8rem !important;
  width: 100%;
}

.admin-auth-card .card,
.admin-otp-card .card {
  box-shadow: none;
}

.admin-otp-title-wrap {
  margin-bottom: 1.2rem;
  text-align: center;
}

.admin-otp-icon {
  align-items: center;
  background: var(--admin-red-soft);
  border: 1px solid rgba(183, 29, 37, 0.12);
  border-radius: 18px;
  color: var(--admin-red);
  display: inline-flex;
  font-size: 1.2rem;
  height: 62px;
  justify-content: center;
  margin-bottom: 0.9rem;
  width: 62px;
}

.admin-otp-page-title {
  color: var(--admin-text);
  font-size: clamp(1.8rem, 2vw, 2.2rem);
  font-weight: 700;
  letter-spacing: -0.03em;
  margin: 0;
}

.admin-otp-subtitle {
  color: var(--admin-muted);
  font-size: 0.9rem;
  margin: 0.45rem 0 0;
}

.admin-otp-header {
  margin-bottom: 1rem;
  text-align: center;
}

.admin-otp-badge {
  background: var(--admin-red-soft);
  border: 1px solid rgba(183, 29, 37, 0.13);
  border-radius: 999px;
  color: var(--admin-red-dark);
  display: inline-flex;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  margin-bottom: 0.8rem;
  padding: 0.48rem 0.9rem;
  text-transform: uppercase;
}

.admin-otp-heading {
  color: var(--admin-text);
  font-size: clamp(1.5rem, 2vw, 1.9rem);
  font-weight: 700;
  margin: 0 0 0.5rem;
}

.admin-otp-message,
.admin-otp-expiry {
  color: var(--admin-muted);
  font-size: 0.95rem;
  line-height: 1.6;
  margin: 0;
}

.admin-otp-message strong {
  color: var(--admin-text);
  font-weight: 700;
}

.admin-otp-expiry strong,
.admin-otp-attempts-pill strong {
  color: var(--admin-text);
}

.admin-otp-status-row {
  margin: 0.9rem 0 1.15rem;
  text-align: center;
}

.admin-otp-attempts-pill {
  background: var(--admin-panel-soft);
  border: 1px solid var(--admin-border);
  border-radius: 999px;
  color: var(--admin-muted);
  display: inline-flex;
  font-size: 0.78rem;
  padding: 0.5rem 0.8rem;
}

.admin-otp-form,
.admin-otp-resend-form {
  margin: 0;
}

.admin-otp-field-wrap {
  margin-bottom: 1rem;
}

.admin-otp-field-label {
  color: var(--admin-text);
  display: block;
  font-size: 0.82rem;
  font-weight: 600;
  margin-bottom: 0.45rem;
}

.admin-otp-input {
  background: var(--admin-panel-soft);
  border: 1px solid var(--admin-border);
  border-radius: 12px;
  box-shadow: none;
  color: var(--admin-text);
  font-size: 1.65rem;
  font-weight: 700;
  height: 58px;
  letter-spacing: 0.35rem;
  outline: none;
  padding: 0.7rem 0.9rem;
  text-align: center;
  transition:
    border-color 0.2s ease,
    box-shadow 0.2s ease,
    background-color 0.2s ease;
  width: 100%;
}

.admin-otp-input:focus {
  background: #fff;
  border-color: rgba(183, 29, 37, 0.6);
  box-shadow: 0 0 0 4px var(--admin-focus);
}

.admin-otp-input.is-invalid {
  background: #fff7f7;
  border-color: #e53935;
  box-shadow: 0 0 0 4px rgba(229, 57, 53, 0.12);
}

.admin-otp-submit {
  background: linear-gradient(
    135deg,
    var(--admin-red) 0%,
    var(--admin-red-dark) 100%
  );
  border: none;
  border-radius: 10px;
  box-shadow: 0 10px 20px rgba(183, 29, 37, 0.15);
  color: #fff;
  font-weight: 700;
  min-height: 48px;
  transition:
    transform 0.2s ease,
    box-shadow 0.2s ease,
    opacity 0.2s ease;
  width: 100%;
}

.admin-otp-submit:hover,
.admin-otp-submit:focus {
  background: linear-gradient(135deg, #ad1b22 0%, #9d141b 100%);
  box-shadow: 0 10px 20px rgba(183, 29, 37, 0.2);
  color: #fff;
  transform: translateY(-1px);
}

.admin-otp-submit:active {
  transform: translateY(0);
}

.admin-otp-submit:disabled {
  cursor: not-allowed;
  opacity: 0.74;
}

.admin-otp-resend {
  background: transparent;
  border: 1px solid rgba(15, 23, 42, 0.12);
  border-radius: 999px;
  color: #374151;
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-weight: 600;
  justify-content: center;
  margin-top: 0.9rem;
  min-height: 44px;
  padding: 0.7rem 1rem;
  transition:
    background-color 0.2s ease,
    border-color 0.2s ease,
    color 0.2s ease;
  width: auto;
}

#resendCountdown {
  font-size: 0.76rem;
  opacity: 0.9;
}

.admin-otp-resend:hover,
.admin-otp-resend:focus {
  background: rgba(15, 23, 42, 0.03);
  border-color: rgba(15, 23, 42, 0.2);
  color: #1f2937;
}

.admin-otp-resend:disabled {
  background: #f3f4f6;
  border-color: #edf1f5;
  color: #97a3af;
  cursor: not-allowed;
}

.otp-back-link {
  color: #4b5563;
  display: inline-flex;
  font-size: 0.9rem;
  font-weight: 600;
  justify-content: center;
  margin-top: 0.85rem;
  text-decoration: none;
}

.otp-back-link:hover,
.otp-back-link:focus {
  color: var(--admin-text);
  text-decoration: underline;
}

.admin-auth-form {
  width: 100%;
}

.auth-field-group {
  margin-bottom: 1.1rem;
}

.auth-field-label {
  color: var(--admin-text);
  display: block;
  font-size: 0.82rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
}

.auth-input-wrap {
  background: var(--admin-panel-soft);
  border: 1px solid var(--admin-border);
  border-radius: 12px;
  overflow: hidden;
  transition:
    border-color 0.2s ease,
    box-shadow 0.2s ease;
  width: 100%;
}

.auth-input-wrap:focus-within {
  border-color: rgba(183, 29, 37, 0.6);
  box-shadow: 0 0 0 4px var(--admin-focus);
}

.auth-input-icon {
  background: transparent;
  border: 0;
  border-right: 1px solid var(--admin-border);
  color: var(--admin-muted);
  display: inline-flex;
  min-width: 46px;
  justify-content: center;
}

.auth-form-control {
  background: transparent;
  border: 0;
  box-shadow: none;
  color: var(--admin-text);
  font-size: 0.97rem;
  min-height: 48px;
  padding: 0.8rem 0.9rem;
}

.auth-form-control::placeholder {
  color: #8a94a5;
}

.auth-form-control:focus {
  background: transparent;
  box-shadow: none;
}

.auth-password-wrap {
  position: relative;
}

.auth-password-toggle {
  align-items: center;
  background: transparent;
  border: 0;
  border-left: 1px solid var(--admin-border);
  color: var(--admin-muted);
  display: inline-flex;
  font-size: 0.95rem;
  justify-content: center;
  min-width: 46px;
  padding: 0 0.7rem;
}

.auth-password-toggle:hover,
.auth-password-toggle:focus {
  background: rgba(15, 23, 42, 0.02);
  color: var(--admin-text);
}

.auth-password-toggle:focus-visible,
.auth-submit-btn:focus-visible,
.admin-otp-submit:focus-visible,
.admin-otp-resend:focus-visible,
.otp-back-link:focus-visible {
  outline: 3px solid rgba(183, 29, 37, 0.18);
  outline-offset: 2px;
}

.auth-submit-btn {
  background: linear-gradient(
    135deg,
    var(--admin-red) 0%,
    var(--admin-red-dark) 100%
  );
  border: none;
  border-radius: 12px;
  box-shadow: 0 12px 22px rgba(183, 29, 37, 0.18);
  color: #fff;
  font-weight: 700;
  min-height: 48px;
  transition:
    transform 0.2s ease,
    box-shadow 0.2s ease;
  width: 100%;
}

.auth-submit-btn:hover,
.auth-submit-btn:focus {
  background: linear-gradient(135deg, #ad1b22 0%, #9d141b 100%);
  box-shadow: 0 10px 20px rgba(183, 29, 37, 0.2);
  color: #fff;
  transform: translateY(-1px);
}

.auth-submit-btn:active {
  transform: translateY(0);
}

.alert {
  border: 1px solid transparent;
  border-radius: 12px;
  font-size: 0.9rem;
  margin-bottom: 1rem;
  padding: 0.8rem 0.9rem;
}

.alert-danger {
  background: #fff3f2;
  border-color: rgba(220, 53, 69, 0.16);
  color: #9b1c1b;
}

.alert-success {
  background: #effaf5;
  border-color: rgba(25, 135, 84, 0.14);
  color: #17673d;
}

.alert-warning {
  background: #fff8eb;
  border-color: rgba(202, 138, 4, 0.16);
  color: #7a5400;
}

.text-decoration-none.small,
.text-muted.small,
.small {
  font-size: 0.82rem;
}

@media (max-width: 767.98px) {
  .admin-auth-card,
  .admin-otp-card {
    max-width: 100%;
    padding: 1.5rem 1.2rem !important;
  }
}

@media (max-width: 575.98px) {
  .auth-page {
    padding: 1rem 0.5rem;
  }

  .admin-auth-card,
  .admin-otp-card {
    border-radius: 18px;
    padding: 1.25rem 1rem !important;
  }

  .admin-otp-page-title {
    font-size: 1.7rem;
  }

  .admin-otp-input {
    font-size: 1.4rem;
    height: 52px;
    letter-spacing: 0.25rem;
  }

  .admin-otp-submit,
  .admin-otp-resend,
  .auth-submit-btn {
    width: 100%;
  }

  .otp-back-link {
    display: inline-flex;
    margin-top: 0.6rem;
  }
}
</style>

<main class="auth-page admin-otp-page py-5">
    <div class="container">
        <div class="row justify-content-center align-items-center admin-otp-row">
            <div class="col-md-5 col-sm-10">
                <div class="card glass-card admin-auth-card admin-otp-card shadow-lg border-0 animate__animated animate__fadeInUp">
                    <div class="admin-otp-title-wrap text-center">
                        <div class="admin-otp-icon"><i class="fas fa-user-shield"></i></div>
                        <h2 class="admin-otp-page-title">Admin Login</h2>
                        <p class="admin-otp-subtitle">Secure Admin Access</p>
                    </div>

                    <?php if (isset($_GET['timeout'])): ?>
                        <div class="alert alert-warning small py-2" role="alert">
                            <i class="fas fa-clock me-2"></i> Session expired. Please login again.
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                    <?php if ($step === 'otp'): ?>
                    <div class="admin-otp-header text-center">
                        <div class="admin-otp-badge"><i class="fas fa-shield-halved me-2"></i>Verify Security OTP</div>
                        <h3 class="admin-otp-heading">Verify Security OTP</h3>
                        <p class="admin-otp-message">A 6-digit verification code has been sent to <strong><?php echo htmlspecialchars($masked_email); ?></strong>.</p>
                        <p class="admin-otp-expiry" aria-live="polite">Code expires in <strong id="otpCountdown">--:--</strong></p>
                    </div>
                    <div class="admin-otp-status-row text-center">
                        <span class="admin-otp-attempts-pill">Attempts remaining: <strong id="otpAttemptsRemaining"><?php echo $otp_attempts_remaining; ?></strong> / 5</span>
                    </div>
                    <form action="" method="POST" id="adminOtpForm" class="admin-otp-form" autocomplete="off" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="verify_otp">
                        <div class="admin-otp-field-wrap">
                            <label for="adminOtpInput" class="admin-otp-field-label">Enter 6-digit code</label>
                            <input
                                type="text"
                                id="adminOtpInput"
                                name="otp"
                                class="admin-otp-input"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                maxlength="6"
                                autocomplete="one-time-code"
                                aria-label="Six-digit one-time password"
                                placeholder="1 8 4 2 7 6"
                            >
                        </div>
                        <button type="submit" id="verifyOtpButton" class="btn btn-primary admin-otp-submit" disabled>
                            <span class="verify-btn-label">Verify OTP</span>
                            <i class="fas fa-shield-halved ms-2"></i>
                        </button>
                    </form>
                    <form action="" method="POST" class="admin-otp-resend-form text-center" id="resendOtpForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="resend_otp">
                        <button type="submit" id="resendOtpButton" class="btn admin-otp-resend" disabled>Resend OTP <span id="resendCountdown"></span></button>
                    </form>

                    <div class="text-center mt-2">
                        <a href="admin_login.php?reset_login=1" class="otp-back-link">
                            <i class="fas fa-arrow-left me-1"></i> Back to Login
                        </a>
                    </div>

                    <?php else: ?>
                    <form action="" method="POST" autocomplete="off" class="admin-auth-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <div class="mb-3 auth-field-group">
                            <label class="form-label fw-medium auth-field-label">Username</label>
                            <div class="input-group auth-input-wrap">
                                <span class="input-group-text auth-input-icon"><i class="fas fa-user"></i></span>
                                <input type="text" name="username" class="form-control auth-form-control"
                                    required placeholder="Enter username" autocomplete="username">
                            </div>
                        </div>
                        <div class="mb-4 auth-field-group">
                            <label class="form-label fw-medium auth-field-label">Password</label>
                            <div class="input-group auth-input-wrap auth-password-wrap">
                                <span class="input-group-text auth-input-icon"><i class="fas fa-lock"></i></span>
                                <input type="password" name="password" class="form-control auth-form-control"
                                    required placeholder="Enter password" id="password" autocomplete="current-password">
                                <button class="btn auth-password-toggle" type="button" id="togglePassword" title="Toggle password visibility" aria-label="Toggle password visibility">
                                    <i class="fas fa-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill fw-bold auth-submit-btn">
                            Login <i class="fas fa-sign-in-alt ms-2"></i>
                        </button>
                    </form>
                    <?php endif; ?>

                    <div class="mt-3 text-center">
                        <a href="admin/forgot_password.php" class="text-decoration-none small">Forgot Password?</a>
                    </div>

                    <div class="mt-4 text-center">
                        <a href="index.php" class="text-decoration-none text-muted small">
                            <i class="fas fa-arrow-left me-1"></i> Back to Home
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    document.getElementById('togglePassword')?.addEventListener('click', function() {
        const pwd = document.getElementById('password');
        const icon = document.getElementById('eyeIcon');
        if (pwd.type === 'password') {
            pwd.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            pwd.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });

    const otpInput = document.getElementById('adminOtpInput');
    const verifyOtpButton = document.getElementById('verifyOtpButton');
    const resendOtpButton = document.getElementById('resendOtpButton');
    const otpCountdown = document.getElementById('otpCountdown');
    const resendCountdown = document.getElementById('resendCountdown');
    const otpExpiresAt = Number(<?php echo (int) ($otp_expires_at ?? 0); ?>) * 1000;
    const otpCreatedAt = Number(<?php echo (int) ($otp_created_at ?? 0); ?>) * 1000;
    const resendCooldown = 60 * 1000;

    function updateOtpState() {
        if (!otpInput) return;

        const value = otpInput.value.replace(/\D/g, '').slice(0, 6);
        otpInput.value = value;
        verifyOtpButton.disabled = value.length !== 6 || Date.now() >= otpExpiresAt || otpExpiresAt <= 0;

        if (value.length === 6) {
            otpInput.classList.remove('is-invalid');
        }
    }

    function updateCountdowns() {
        const now = Date.now();
        const expirySeconds = Math.max(0, Math.ceil((otpExpiresAt - now) / 1000));
        const expiryMinutes = String(Math.floor(expirySeconds / 60)).padStart(2, '0');
        const expiryRemainder = String(expirySeconds % 60).padStart(2, '0');
        if (otpCountdown) {
            otpCountdown.textContent = expirySeconds ? `${expiryMinutes}:${expiryRemainder}` : 'Expired';
            otpCountdown.classList.toggle('text-danger', expirySeconds === 0);
        }
        const cooldownSeconds = Math.max(0, Math.ceil((otpCreatedAt + resendCooldown - now) / 1000));
        if (resendOtpButton) resendOtpButton.disabled = cooldownSeconds > 0 || otpExpiresAt <= 0;
        if (resendCountdown) resendCountdown.textContent = cooldownSeconds ? ` • Available in ${cooldownSeconds}s` : '';
        updateOtpState();
    }

    if (otpInput) {
        otpInput.addEventListener('input', () => {
            otpInput.value = otpInput.value.replace(/\D/g, '').slice(0, 6);
            updateOtpState();
        });

        otpInput.addEventListener('paste', (event) => {
            event.preventDefault();
            const pasted = (event.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '').slice(0, 6);
            if (!pasted) return;
            otpInput.value = pasted;
            updateOtpState();
        });

        otpInput.addEventListener('keydown', (event) => {
            if (['e', 'E', '+', '-', '.'].includes(event.key)) {
                event.preventDefault();
            }
        });
    }

    const adminOtpForm = document.getElementById('adminOtpForm');
    adminOtpForm?.addEventListener('submit', (event) => {
        const value = otpInput ? otpInput.value.replace(/\D/g, '').slice(0, 6) : '';
        if (value.length !== 6 || Date.now() >= otpExpiresAt || otpExpiresAt <= 0) {
            event.preventDefault();
            if (otpInput) {
                otpInput.classList.add('is-invalid');
            }
            return;
        }
        if (otpInput) {
            otpInput.value = value;
            otpInput.classList.remove('is-invalid');
        }
        const submitButton = adminOtpForm.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.classList.add('is-loading');
            const label = submitButton.querySelector('.verify-btn-label');
            if (label) label.textContent = 'Verifying...';
        }
    });

    if (otpInput) {
        setInterval(updateCountdowns, 1000);
        updateCountdowns();
        otpInput.focus();
    }
</script>

<?php include 'includes/footer.php'; ?>