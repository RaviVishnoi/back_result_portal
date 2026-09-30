<?php
require_once '../includes/config.php';
require_admin_session($pdo);
require_super_admin($pdo);

$error = '';
$success = '';
$step = $_SESSION['admin_email_pending'] ?? false;
$admin_id = (int) $_SESSION['admin_id'];

$stmt = $pdo->prepare('SELECT username, password FROM admins WHERE id = ?');
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();

$current_email = admin_security_email($pdo, $admin_id);
$pending_email = $_SESSION['admin_email_target'] ?? '';

// Handle cancel via GET parameter
if (isset($_GET['cancel']) && $_GET['cancel'] == 1) {
    unset($_SESSION['admin_email_pending']);
    unset($_SESSION['admin_email_target']);
    $pdo->prepare('UPDATE admin_security_settings SET pending_email = NULL WHERE admin_id = ?')->execute([$admin_id]);
    $step = false;
    header('Location: security_email.php?cancelled=1');
    exit();
}

if (isset($_GET['cancelled']) && $_GET['cancelled'] == 1) {
    $success = 'Email change cancelled. You can try again with a new email address.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'request_email') {
        $email = trim($_POST['email'] ?? '');
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (!password_verify($_POST['current_password'] ?? '', $admin['password'])) {
            $error = 'Current password is incorrect.';
        } elseif ($email === $current_email) {
            $error = 'Enter a different email address.';
        } elseif (send_admin_otp($pdo, $admin_id, 'email_change', $email, $admin['username'])) {
            // Update pending email in database
            $pdo->prepare('UPDATE admin_security_settings SET pending_email = ? WHERE admin_id = ?')->execute([$email, $admin_id]);
            
            // Set session variables
            $_SESSION['admin_email_pending'] = true;
            $_SESSION['admin_email_target'] = $email;
            
            // IMPORTANT: Update the $step and $pending_email variables for this request
            $step = true;
            $pending_email = $email;
            
            $success = 'A verification OTP was sent to the new email address.';
        } else {
            $error = 'Please wait before requesting another OTP.';
        }
    } elseif ($action === 'verify_email' && $step) {
        $result = verify_admin_otp($pdo, $admin_id, 'email_change', $_POST['otp'] ?? '', $admin['username']);
        
        if ($result === 'verified') {
            $pdo->prepare('UPDATE admin_security_settings SET security_email = ?, pending_email = NULL, email_verified_at = NOW() WHERE admin_id = ?')
                ->execute([$_SESSION['admin_email_target'], $admin_id]);
            log_security_event($pdo, 'OTP Email Changed', $admin_id, $admin['username'], 'success', 'verified');
            unset($_SESSION['admin_email_pending'], $_SESSION['admin_email_target']);
            $step = false;
            $current_email = $_SESSION['admin_email_target'];
            $success = 'Security email changed and verified successfully.';
        } else {
            log_security_event($pdo, 'OTP Email Verification', $admin_id, $admin['username'], 'failed', $result);
            $error = $result === 'expired' ? 'OTP expired. Request a new verification code.' : 'Invalid OTP.';
        }
    } elseif ($action === 'cancel') {
        unset($_SESSION['admin_email_pending'], $_SESSION['admin_email_target']);
        $pdo->prepare('UPDATE admin_security_settings SET pending_email = NULL WHERE admin_id = ?')->execute([$admin_id]);
        $step = false;
        header('Location: security_email.php?cancelled=1');
        exit();
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = 'Invalid security token.';
}

// Refresh step from session after POST
$step = $_SESSION['admin_email_pending'] ?? false;
$pending_email = $_SESSION['admin_email_target'] ?? '';

include '../includes/header.php';
?>

<style>
.otp-email-shell {
  background: #f4f6fa;
  min-height: calc(100vh - 70px);
  padding: 0 !important;
}

.otp-email-shell .container-fluid {
  padding: 1.5rem 2rem;
}

.otp-email-header {
  background: #ffffff;
  border-radius: 16px;
  padding: 1.5rem 2rem;
  margin-bottom: 1.75rem;
  border: 1px solid #e2e8f0;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 1rem;
}

.otp-email-header h1 {
  font-size: 1.65rem;
  font-weight: 700;
  color: #0a0f1c;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.otp-email-header h1 i {
  color: #b71d25;
}

.otp-email-header .subtitle {
  color: #6b7a8f;
  font-size: 0.9rem;
  margin: 0.2rem 0 0 2.4rem;
}

.otp-email-header .header-actions {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  align-items: center;
}

.btn-back-dashboard {
  background: transparent;
  border: 1px solid #d0d7e2;
  border-radius: 30px;
  color: #1a2634;
  font-weight: 600;
  padding: 0.55rem 1.2rem;
  transition: all 0.2s ease;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  text-decoration: none;
}

.btn-back-dashboard:hover {
  background: #f1f4f9;
  border-color: #b0bcca;
  color: #0a0f1c;
  text-decoration: none;
}

.btn-back-admin {
  background: transparent;
  border: 1px solid #d0d7e2;
  border-radius: 30px;
  color: #1a2634;
  font-weight: 600;
  padding: 0.55rem 1.2rem;
  transition: all 0.2s ease;
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  text-decoration: none;
}

.btn-back-admin:hover {
  background: #f1f4f9;
  border-color: #b0bcca;
  color: #0a0f1c;
  text-decoration: none;
}

.otp-email-card {
  max-width: 580px;
  margin: 0 auto;
  background: #ffffff;
  border-radius: 16px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
  overflow: hidden;
}

.otp-email-card .card-body {
  padding: 2rem 2.5rem;
}

.alert-mgmt {
  border-radius: 12px;
  padding: 0.9rem 1.2rem;
  margin-bottom: 1.25rem;
  border-left: 4px solid transparent;
}

.alert-mgmt.alert-success {
  background: #f0faf4;
  border-color: #10b981;
  color: #0a6b44;
}

.alert-mgmt.alert-danger {
  background: #fdf2f2;
  border-color: #dc3545;
  color: #9b1c1b;
}

.alert-mgmt.alert-info {
  background: #eff6ff;
  border-color: #3b82f6;
  color: #1d4ed8;
}

.current-email-box {
  background: #f8fafc;
  border-radius: 12px;
  padding: 1rem 1.5rem;
  border: 1px solid #e2e8f0;
  margin-bottom: 1.75rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.current-email-box .label {
  font-size: 0.75rem;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.current-email-box .email-value {
  font-size: 1rem;
  font-weight: 600;
  color: #0f172a;
  font-family: monospace;
  background: #fff;
  padding: 0.2rem 0.8rem;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
  word-break: break-all;
}

.current-email-box .badge-status {
  font-size: 0.6rem;
  font-weight: 700;
  padding: 0.2rem 0.7rem;
  border-radius: 30px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  white-space: nowrap;
}

.badge-status.configured {
  background: #d1fae5;
  color: #065f46;
}

.badge-status.not-configured {
  background: #fef3c7;
  color: #92400e;
}

.otp-form .form-group {
  margin-bottom: 1.25rem;
}

.otp-form .form-label {
  font-weight: 600;
  font-size: 0.85rem;
  color: #1e293b;
  margin-bottom: 0.4rem;
  display: block;
}

.otp-form .form-label .text-danger {
  color: #dc2626;
}

.otp-form .input-group {
  display: flex;
  align-items: stretch;
  width: 100%;
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid #d1d5db;
  background: #ffffff;
  transition:
    border-color 0.2s,
    box-shadow 0.2s;
}

.otp-form .input-group:focus-within {
  border-color: #b71d25;
  box-shadow: 0 0 0 3px rgba(183, 29, 37, 0.12);
}

.otp-form .input-group .input-group-text {
  background: #f1f5f9;
  border: none;
  padding: 0.6rem 0.9rem;
  color: #64748b;
  font-size: 0.95rem;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 44px;
  flex-shrink: 0;
}

.otp-form .input-group .form-control {
  border: none;
  padding: 0.6rem 0.9rem;
  font-size: 0.95rem;
  width: 100%;
  background: transparent;
  flex: 1;
  outline: none;
  border-radius: 0;
}

.otp-form .input-group .form-control:focus {
  box-shadow: none;
  outline: none;
}

.otp-form .form-hint {
  font-size: 0.78rem;
  color: #94a3b8;
  margin-top: 0.4rem;
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.otp-form .form-hint i {
  color: #94a3b8;
}

.otp-form .password-hint {
  background: #fffbeb;
  border-radius: 10px;
  padding: 0.7rem 1rem;
  font-size: 0.8rem;
  color: #92400e;
  border: 1px solid #fde68a;
  margin-top: 0.5rem;
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
}

.otp-form .password-hint i {
  color: #f59e0b;
  margin-top: 2px;
  flex-shrink: 0;
}

.btn-primary-red {
  background: linear-gradient(145deg, #b71d25, #9a151c);
  border: none;
  border-radius: 30px;
  color: #fff;
  font-weight: 600;
  font-size: 0.95rem;
  padding: 0.7rem 2rem;
  transition: all 0.2s ease;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.6rem;
  width: 100%;
}

.btn-primary-red:hover:not(:disabled) {
  background: linear-gradient(145deg, #a11921, #821219);
  transform: translateY(-1px);
  box-shadow: 0 4px 14px rgba(183, 29, 37, 0.25);
}

.btn-primary-red:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  transform: none;
}

.btn-secondary-outline {
  background: transparent;
  border: 1px solid #d1d5db;
  border-radius: 30px;
  color: #1a2634;
  font-weight: 600;
  padding: 0.6rem 1.5rem;
  transition: all 0.2s ease;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  text-decoration: none;
}

.btn-secondary-outline:hover {
  background: #f1f5f9;
  border-color: #9ca3af;
  color: #0a0f1c;
  text-decoration: none;
}

.btn-danger-outline {
  background: transparent;
  border: 1px solid #fecaca;
  border-radius: 30px;
  color: #b91c1c;
  font-weight: 600;
  padding: 0.6rem 1.5rem;
  transition: all 0.2s ease;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  text-decoration: none;
}

.btn-danger-outline:hover {
  background: #fef2f2;
  border-color: #fca5a5;
  color: #991b1b;
  text-decoration: none;
}

.btn-group-actions {
  display: flex;
  gap: 0.75rem;
  flex-wrap: wrap;
  margin-top: 0.5rem;
}

.btn-group-actions .btn {
  flex: 1;
  justify-content: center;
  min-width: 100px;
}

.otp-input-group {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  flex-wrap: wrap;
}

.otp-input-group .otp-input {
  flex: 1;
  min-width: 140px;
  text-align: center;
  font-size: 1.4rem;
  font-weight: 700;
  letter-spacing: 0.35rem;
  padding: 0.6rem 0.5rem;
  border-radius: 10px;
  border: 1px solid #d1d5db;
  background: #ffffff;
  transition:
    border-color 0.2s,
    box-shadow 0.2s;
}

.otp-input-group .otp-input:focus {
  border-color: #b71d25;
  box-shadow: 0 0 0 3px rgba(183, 29, 37, 0.12);
  outline: none;
}

.otp-input-group .btn-primary-red {
  flex: 0 0 auto;
  width: auto;
  padding: 0.6rem 1.8rem;
}

.info-box {
  background: #f8fafc;
  border-radius: 12px;
  padding: 0.9rem 1.2rem;
  border: 1px solid #e2e8f0;
}

.info-box .info-title {
  font-weight: 600;
  color: #0f172a;
  font-size: 0.85rem;
  margin-bottom: 0.2rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.info-box .info-title i {
  color: #3b82f6;
}

.info-box .info-text {
  color: #64748b;
  font-size: 0.82rem;
  line-height: 1.5;
  margin: 0;
}

.info-box .info-text strong {
  color: #0f172a;
}

.info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  margin-top: 1.5rem;
}

.info-grid .info-box {
  margin-bottom: 0;
}

hr.divider {
  border: none;
  border-top: 1px solid #e2e8f0;
  margin: 1.5rem 0 1.25rem;
}

@media (max-width: 768px) {
  .otp-email-shell .container-fluid {
    padding: 1rem;
  }

  .otp-email-header {
    padding: 1.2rem;
    flex-direction: column;
    align-items: stretch;
    gap: 0.75rem;
  }

  .otp-email-header h1 {
    font-size: 1.3rem;
  }

  .otp-email-header .subtitle {
    margin-left: 0;
  }

  .otp-email-header .header-actions {
    flex-direction: column;
    width: 100%;
  }

  .otp-email-header .header-actions .btn {
    width: 100%;
    justify-content: center;
  }

  .otp-email-card .card-body {
    padding: 1.5rem 1.25rem;
  }

  .current-email-box {
    flex-direction: column;
    align-items: stretch;
    text-align: center;
    padding: 1rem;
  }

  .current-email-box .email-value {
    font-size: 0.9rem;
    word-break: break-all;
  }

  .otp-input-group {
    flex-direction: column;
    align-items: stretch;
  }

  .otp-input-group .otp-input {
    min-width: auto;
    font-size: 1.2rem;
    letter-spacing: 0.25rem;
  }

  .otp-input-group .btn-primary-red {
    width: 100%;
    justify-content: center;
  }

  .btn-group-actions {
    flex-direction: column;
  }

  .btn-group-actions .btn {
    width: 100%;
    justify-content: center;
  }

  .info-grid {
    grid-template-columns: 1fr;
    gap: 0.75rem;
  }
}

@media (max-width: 480px) {
  .otp-email-card .card-body {
    padding: 1rem;
  }

  .current-email-box {
    padding: 0.75rem;
  }

  .current-email-box .email-value {
    font-size: 0.8rem;
  }
}
</style>

<div class="admin-page-shell">
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/admin_sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 admin-page-content">

        <!-- Header -->
        <div class="admin-page-header">
            <div>
                <h1>
                    <i class="fas fa-envelope-open-text"></i>
                    OTP Email Settings
                </h1>
                <div class="subtitle">
                    <i class="fas fa-cog me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                    Configure the email address for receiving security OTP codes
                </div>
            </div>
            <div class="header-actions">
                <a href="dashboard.php" class="btn-back-dashboard">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
                <a href="manage_admins.php" class="btn-back-admin">
                    <i class="fas fa-users"></i> Admin Management
                </a>
            </div>
        </div>

        <!-- Card -->
        <div class="otp-email-card">
            <div class="card-body">

                <?php if ($error): ?>
                    <div class="alert-mgmt alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert-mgmt alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <!-- Current Email -->
                <div class="current-email-box">
                    <div>
                        <div class="label">
                            <i class="fas fa-envelope me-1"></i> Current Security Email
                        </div>
                        <div class="email-value">
                            <?= htmlspecialchars($current_email ?: 'Not configured') ?>
                        </div>
                    </div>
                    <div>
                        <span class="badge-status <?= $current_email ? 'configured' : 'not-configured' ?>">
                            <?= $current_email ? 'Configured' : 'Not Set' ?>
                        </span>
                    </div>
                </div>

                <?php if ($step && $pending_email): ?>
                    <!-- ===== VERIFY OTP STEP ===== -->
                    <div class="info-box" style="margin-bottom:1.25rem;">
                        <div class="info-title">
                            <i class="fas fa-shield-alt"></i> Verify New Email
                        </div>
                        <p class="info-text">
                            A 6-digit verification code has been sent to 
                            <strong><?= htmlspecialchars($pending_email) ?></strong>.
                            Enter the code below to confirm this email address.
                        </p>
                    </div>

                    <form method="POST" class="otp-form" id="otpVerifyForm">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="verify_email">

                        <div class="form-group">
                            <label class="form-label">Verification Code <span class="text-danger">*</span></label>
                            <div class="otp-input-group">
                                <input type="text" 
                                       name="otp" 
                                       class="otp-input" 
                                       inputmode="numeric" 
                                       pattern="[0-9]{6}" 
                                       maxlength="6" 
                                       required
                                       placeholder="6-digit OTP"
                                       id="otpInput"
                                       autofocus>
                                <button type="submit" class="btn-primary-red" id="verifyBtn">
                                    <i class="fas fa-check"></i> Verify
                                </button>
                            </div>
                            <div class="form-hint">
                                <i class="fas fa-clock"></i> OTP expires in 5 minutes
                            </div>
                        </div>

                        <div class="btn-group-actions">
                            <a href="security_email.php?cancel=1" class="btn-secondary-outline" id="backToFormBtn">
                                <i class="fas fa-arrow-left"></i> Back to Email Form
                            </a>
                        </div>
                    </form>

                <?php else: ?>
                    <!-- ===== CHANGE EMAIL FORM ===== -->
                    <form method="POST" class="otp-form" id="emailChangeForm">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="request_email">

                        <div class="form-group">
                            <label class="form-label">New Security Email <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-envelope"></i>
                                </span>
                                <input type="email" 
                                       name="email" 
                                       class="form-control" 
                                       required 
                                       autocomplete="email"
                                       placeholder="Enter new security email address"
                                       value="<?= htmlspecialchars($pending_email ?: '') ?>">
                            </div>
                            <div class="form-hint">
                                <i class="fas fa-info-circle"></i>
                                This email will receive OTP codes for admin login and security verifications.
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Current Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-lock"></i>
                                </span>
                                <input type="password" 
                                       name="current_password" 
                                       class="form-control" 
                                       required 
                                       autocomplete="current-password"
                                       placeholder="Enter your current password">
                            </div>
                            <div class="password-hint">
                                <i class="fas fa-shield-alt"></i>
                                <span>Your current password is required to verify your identity before changing the security email.</span>
                            </div>
                        </div>

                        <button type="submit" class="btn-primary-red" id="sendOtpBtn">
                            <i class="fas fa-paper-plane"></i> Send Verification OTP
                        </button>
                    </form>

                <?php endif; ?>

                <!-- Info Grid -->
                <hr class="divider">

                <div class="info-grid">
                    <div class="info-box">
                        <div class="info-title">
                            <i class="fas fa-shield-halved" style="color:#b71d25;"></i> Security
                        </div>
                        <p class="info-text">
                            OTP codes are <strong>never stored in plaintext</strong>. 
                            All verification attempts are logged for audit purposes.
                        </p>
                    </div>
                    <div class="info-box">
                        <div class="info-title">
                            <i class="fas fa-clock" style="color:#f59e0b;"></i> OTP Validity
                        </div>
                        <p class="info-text">
                            Each OTP is valid for <strong>5 minutes</strong> and 
                            can be used only once. Multiple failed attempts will 
                            invalidate the OTP.
                        </p>
                    </div>
                </div>

            </div>
        </div>

            </main>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle cancel via GET parameter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('cancel') && urlParams.get('cancel') == 1) {
        const url = new URL(window.location);
        url.searchParams.delete('cancel');
        window.history.replaceState({}, '', url);
    }

    const otpInput = document.getElementById('otpInput');
    if (otpInput) {
        otpInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').slice(0, 6);
        });

        otpInput.addEventListener('keydown', function(e) {
            if (['e', 'E', '+', '-', '.', ','].includes(e.key)) {
                e.preventDefault();
            }
        });

        otpInput.addEventListener('input', function() {
            if (this.value.length === 6) {
                const form = document.getElementById('otpVerifyForm');
                if (form) {
                    const btn = document.getElementById('verifyBtn');
                    if (btn) {
                        btn.disabled = true;
                        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Verifying...';
                    }
                    form.submit();
                }
            }
        });
    }

    const sendOtpBtn = document.getElementById('sendOtpBtn');
    if (sendOtpBtn) {
        document.getElementById('emailChangeForm')?.addEventListener('submit', function() {
            sendOtpBtn.disabled = true;
            sendOtpBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Sending OTP...';
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>