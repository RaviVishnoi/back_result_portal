<?php
require_once '../includes/config.php';

require_admin_session($pdo);

$error = '';
$success = '';

$session_admin_id = (int) $_SESSION['admin_id'];
$session_username = $_SESSION['username'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        // Get form inputs
        $username = trim($_POST['username'] ?? '');
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // STEP 1: Validate all fields are provided
        if (empty($username) || empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = 'All password fields are required.';
        }
        // STEP 2: Security check - entered username must match authenticated session
        elseif ($username !== $session_username) {
            $error = 'You can only change your own password. Please enter your own username.';
            log_security_event($pdo, 'Unauthorized Password Change Attempt', $session_admin_id, $session_username, 'failed', null, "Attempted to change password for: $username");
        }
        else {
            // STEP 3: Find the admin account by username
            $stmt = $pdo->prepare('SELECT id, username, password FROM admins WHERE username = ? AND id = ? LIMIT 1');
            $stmt->execute([$username, $session_admin_id]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$admin) {
                $error = 'Account not found. Please log in again.';
                log_security_event($pdo, 'Password Change Failed', $session_admin_id, $session_username, 'failed', null, 'Admin account not found');
            }
            // STEP 4: Verify current password
            elseif (!password_verify($current_password, $admin['password'])) {
                $error = 'Current password is incorrect.';
                log_security_event($pdo, 'Password Change Failed', $session_admin_id, $session_username, 'failed', null, 'Incorrect current password');
            }
            // STEP 5: Check if new passwords match
            elseif ($new_password !== $confirm_password) {
                $error = 'New password and confirmation do not match.';
            }
            // STEP 6: Validate password policy
            else {
                $validation_errors = admin_password_errors($new_password);
                if (!empty($validation_errors)) {
                    $error = 'Password does not meet requirements: ' . implode(' ', $validation_errors);
                }
                // STEP 7: Check new password is different from current
                elseif (password_verify($new_password, $admin['password'])) {
                    $error = 'New password must be different from your current password.';
                }
                else {
                    // STEP 8: Hash the new password
                    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    
                    try {
                        $pdo->beginTransaction();
                        
                        // STEP 9: UPDATE the password in database
                        $update_stmt = $pdo->prepare('UPDATE admins SET password = ? WHERE id = ? AND username = ?');
                        $result = $update_stmt->execute([$new_password_hash, $session_admin_id, $username]);
                        
                        if (!$result) {
                            throw new Exception('UPDATE statement failed');
                        }
                        
                        $rows_affected = $update_stmt->rowCount();
                        if ($rows_affected === 0) {
                            throw new Exception('No rows were updated. Account may have been deleted.');
                        }
                        
                        // STEP 10: Update password_changed_at timestamp in security settings
                        $timestamp_stmt = $pdo->prepare('UPDATE admin_security_settings SET password_changed_at = NOW() WHERE admin_id = ?');
                        $timestamp_result = $timestamp_stmt->execute([$session_admin_id]);
                        if (!$timestamp_result) {
                            throw new Exception('Failed to update password timestamp');
                        }
                        
                        $pdo->commit();
                        
                        // STEP 11: VERIFY the password was actually saved to database
                        $verify_stmt = $pdo->prepare('SELECT password FROM admins WHERE id = ? AND username = ? LIMIT 1');
                        $verify_stmt->execute([$session_admin_id, $username]);
                        $stored_record = $verify_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$stored_record) {
                            throw new Exception('Failed to retrieve updated password from database');
                        }
                        
                        // STEP 12: Final verification - check password_verify works with new hash
                        if (!password_verify($new_password, $stored_record['password'])) {
                            throw new Exception('Database verification failed - password_verify returned false');
                        }
                        
                        // Log successful password change
                        log_security_event($pdo, 'Password Changed Successfully', $session_admin_id, $username, 'success', null, 'User successfully changed their password');
                        
                        $success = 'Password changed successfully! Your new password will be used for your next login.';
                        
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = 'Failed to change password. Please try again.';
                        log_security_event($pdo, 'Password Change Failed', $session_admin_id, $session_username, 'failed', null, 'Database error: ' . $e->getMessage());
                        error_log('Password change database error for user ' . $username . ': ' . $e->getMessage());
                    }
                }
            }
        }
    }
}

include '../includes/header.php';
?>

<style>
.password-change-card {
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
  background: #ffffff;
}

.password-change-header {
  background: linear-gradient(135deg, #f8f9fa 0%, #f3f4f6 100%);
  border-bottom: 2px solid #e5e7eb;
  padding: 24px 20px;
  border-radius: 12px 12px 0 0;
}

.password-change-header h3 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 700;
  color: #1f2937;
  display: flex;
  align-items: center;
  gap: 10px;
}

.password-form-body {
  padding: 28px 20px;
}

.password-field-group {
  margin-bottom: 18px;
}

.password-field-label {
  font-weight: 600;
  color: #1f2937;
  font-size: 0.95rem;
  margin-bottom: 8px;
  display: block;
}

.password-input {
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 1rem;
  transition: all 0.2s ease;
  width: 100%;
  box-sizing: border-box;
}

.password-input:focus {
  outline: none;
  background: #ffffff;
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
}

.password-requirements {
  background: #f0f7ff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  padding: 14px 16px;
  margin-bottom: 20px;
  font-size: 0.9rem;
  color: #1e40af;
}

.password-requirements h5 {
  margin: 0 0 8px 0;
  font-size: 0.9rem;
  font-weight: 600;
  color: #1e40af;
}

.password-requirements ul {
  margin: 0;
  padding-left: 20px;
  list-style: none;
}

.password-requirements li {
  margin-bottom: 4px;
  padding-left: 20px;
  position: relative;
}

.password-requirements li:before {
  content: "✓";
  position: absolute;
  left: 0;
  color: #10b981;
  font-weight: bold;
}

.alert {
  border-radius: 8px;
  padding: 12px 14px;
  margin-bottom: 16px;
  font-size: 0.9rem;
  display: flex;
  align-items: flex-start;
  gap: 10px;
}

.alert-danger {
  background: #fff3f2;
  border: 1px solid rgba(220, 53, 69, 0.16);
  color: #9b1c1b;
}

.alert-success {
  background: #effaf5;
  border: 1px solid rgba(25, 135, 84, 0.14);
  color: #17673d;
}

.change-password-btn {
  background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
  color: white;
  border: none;
  border-radius: 8px;
  padding: 10px 20px;
  font-weight: 600;
  font-size: 1rem;
  cursor: pointer;
  width: 100%;
  transition: all 0.2s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.change-password-btn:hover {
  background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.change-password-btn:active {
  transform: translateY(0);
}

.change-password-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.back-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 16px;
  color: #2563eb;
  text-decoration: none;
  font-weight: 500;
}

.back-link:hover {
  text-decoration: underline;
}

@media (max-width: 768px) {
  .password-change-card {
    margin: 16px;
  }

  .password-form-body {
    padding: 20px 16px;
  }

  .password-change-header {
    padding: 18px 16px;
  }

  .password-change-header h3 {
    font-size: 1.1rem;
  }
}
</style>

<div class="admin-page-shell">
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/admin_sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 admin-page-content">
                <div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="password-change-card">
                <div class="password-change-header">
                    <h3>
                        <i class="fas fa-key" style="color: #2563eb;"></i>
                        Change Password
                    </h3>
                </div>

                <div class="password-form-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle" style="flex-shrink: 0; margin-top: 2px;"></i>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle" style="flex-shrink: 0; margin-top: 2px;"></i>
                            <div><?php echo htmlspecialchars($success); ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div style="text-align: center; padding: 30px 0;">
                            <div style="margin-bottom: 20px;">
                                <i class="fas fa-check-circle" style="font-size: 3rem; color: #10b981;"></i>
                            </div>
                            <p style="color: #1f2937; font-size: 1.1rem; font-weight: 500; margin-bottom: 20px;">
                                Your password has been successfully updated.
                            </p>
                            <a href="dashboard.php" class="change-password-btn" style="text-decoration: none;">
                                <i class="fas fa-arrow-right"></i>
                                Return to Dashboard
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="password-requirements">
                            <h5>
                                <i class="fas fa-shield-alt me-1"></i>
                                Password Requirements
                            </h5>
                            <ul>
                                <li>At least 12 characters</li>
                                <li>At least one uppercase letter (A-Z)</li>
                                <li>At least one lowercase letter (a-z)</li>
                                <li>At least one number (0-9)</li>
                                <li>At least one special character (!@#$%^&*)</li>
                            </ul>
                        </div>

                        <form method="POST" id="changePasswordForm" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">

                            <div class="password-field-group">
                                <label class="password-field-label" for="username">
                                    <i class="fas fa-user me-1" style="color: #6b7280;"></i>
                                    Username
                                </label>
                                <input 
                                    type="text" 
                                    id="username"
                                    name="username" 
                                    class="password-input" 
                                    required 
                                    autocomplete="username"
                                    placeholder="Enter your username"
                                    value="<?php echo htmlspecialchars($session_username); ?>"
                                >
                            </div>

                            <div class="password-field-group">
                                <label class="password-field-label" for="currentPassword">
                                    <i class="fas fa-lock me-1" style="color: #6b7280;"></i>
                                    Current Password
                                </label>
                                <div class="input-group">
                                    <input 
                                        type="password" 
                                        id="currentPassword"
                                        name="current_password" 
                                        class="form-control password-input border-end-0" 
                                        required 
                                        autocomplete="current-password"
                                        placeholder="Enter your current password"
                                    >
                                    <button class="btn btn-outline-secondary toggle-password bg-light" type="button" data-target="currentPassword" style="border-color: #e5e7eb;">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
    
                            <div class="password-field-group">
                                <label class="password-field-label" for="newPassword">
                                    <i class="fas fa-key me-1" style="color: #6b7280;"></i>
                                    New Password
                                </label>
                                <div class="input-group">
                                    <input 
                                        type="password" 
                                        id="newPassword"
                                        name="new_password" 
                                        class="form-control password-input border-end-0" 
                                        required 
                                        autocomplete="new-password"
                                        minlength="12"
                                        placeholder="Enter your new password (min 12 characters)"
                                    >
                                    <button class="btn btn-outline-secondary toggle-password bg-light" type="button" data-target="newPassword" style="border-color: #e5e7eb;">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
    
                            <div class="password-field-group">
                                <label class="password-field-label" for="confirmPassword">
                                    <i class="fas fa-check-circle me-1" style="color: #6b7280;"></i>
                                    Confirm Password
                                </label>
                                <div class="input-group">
                                    <input 
                                        type="password" 
                                        id="confirmPassword"
                                        name="confirm_password" 
                                        class="form-control password-input border-end-0" 
                                        required 
                                        autocomplete="new-password"
                                        minlength="12"
                                        placeholder="Re-enter your new password"
                                    >
                                    <button class="btn btn-outline-secondary toggle-password bg-light" type="button" data-target="confirmPassword" style="border-color: #e5e7eb;">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
    
                            <button type="submit" class="change-password-btn mt-3">
                                <i class="fas fa-check"></i>
                                Change Password
                            </button>
                        </form>

                        <a href="dashboard.php" class="back-link">
                            <i class="fas fa-arrow-left"></i>
                            Back to Dashboard
                        </a>
                    <?php endif; ?>
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
document.addEventListener('DOMContentLoaded', function() {
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
});
</script>

<?php include '../includes/footer.php'; ?>