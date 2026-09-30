<?php
require_once '../includes/config.php';

require_admin_session($pdo);
require_super_admin($pdo);

$success = '';
$error = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $success = 'Admin account deleted successfully.';
}

$stats = [
    'total_admins' => (int) $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn(),
    'active_admins' => (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE login_attempts < 5 OR login_attempts IS NULL")->fetchColumn(),
    'locked_admins' => (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE login_attempts >= 5")->fetchColumn(),
    'super_admins' => (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'")->fetchColumn(),
];

$admins_list = $pdo->query("
    SELECT a.id,
           a.username,
           a.role,
           a.login_attempts,
           a.created_at,
           ass.security_email,
           COUNT(DISTINCT aup.id) AS permission_count,
           (
               SELECT MAX(created_at)
               FROM admin_security_logs asl
               WHERE asl.admin_id = a.id AND asl.action = 'OTP Verification' AND asl.status = 'success'
           ) AS last_login
    FROM admins a
    LEFT JOIN admin_security_settings ass ON ass.admin_id = a.id
    LEFT JOIN admin_user_permissions aup ON aup.admin_id = a.id AND aup.granted = 1
    GROUP BY a.id, a.username, a.role, a.login_attempts, a.created_at, ass.security_email
    ORDER BY a.role DESC, a.username ASC
")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $acting_admin_id = (int) ($_SESSION['admin_id'] ?? 0);
        $acting_username = $_SESSION['username'] ?? '';
        $action = (string) $_POST['action'];

        try {
            if ($action === 'create_admin') {
                $username = trim((string) ($_POST['username'] ?? ''));
                $email = strtolower(trim((string) ($_POST['email'] ?? '')));
                $password = (string) ($_POST['password'] ?? '');
                $confirm_password = (string) ($_POST['confirm_password'] ?? '');
                $account_status = (($_POST['account_status'] ?? 'active') === 'locked') ? 'locked' : 'active';

                if ($username === '' || $email === '' || $password === '' || $confirm_password === '') {
                    throw new InvalidArgumentException('Username, email, password, and confirmation are required.');
                }
                if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                    throw new InvalidArgumentException('Username must be 3-50 characters and contain only letters, numbers, dot, underscore, or hyphen.');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Please enter a valid email address.');
                }
                if ($password !== $confirm_password) {
                    throw new InvalidArgumentException('Password confirmation does not match.');
                }
                $validation_errors = admin_password_errors($password);
                if (!empty($validation_errors)) {
                    throw new InvalidArgumentException('Password does not meet the required policy: ' . implode(' ', $validation_errors));
                }

                $stmt = $pdo->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    throw new InvalidArgumentException('This username already exists. Please choose a different username.');
                }

                $stmt = $pdo->prepare('SELECT admin_id FROM admin_security_settings WHERE LOWER(security_email) = LOWER(?) LIMIT 1');
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    throw new InvalidArgumentException('This email address is already associated with another admin account.');
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO admins (username, password, role, login_attempts, last_attempt) VALUES (?, ?, 'admin', 0, NULL)");
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                $new_admin_id = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare('INSERT INTO admin_security_settings (admin_id, security_email, password_changed_at) VALUES (?, ?, NOW())');
                $stmt->execute([$new_admin_id, $email]);

                if ($account_status === 'locked') {
                    $pdo->prepare('UPDATE admins SET login_attempts = 5, last_attempt = NOW() WHERE id = ?')->execute([$new_admin_id]);
                }

                $pdo->commit();
                log_security_event($pdo, 'Admin Created', $acting_admin_id, $acting_username, 'success', null, "Created admin_id: {$new_admin_id}; username: {$username}; status: {$account_status}");
                $success = 'Admin account created successfully.';
            } elseif ($action === 'update_email') {
                $target_id = (int) ($_POST['admin_id'] ?? 0);
                $new_email = strtolower(trim((string) ($_POST['email'] ?? '')));

                $stmt = $pdo->prepare('SELECT id, username FROM admins WHERE id = ? LIMIT 1');
                $stmt->execute([$target_id]);
                $admin = $stmt->fetch();
                if (!$admin) {
                    throw new InvalidArgumentException('Admin not found.');
                }
                if ($admin['username'] === 'superadmin') {
                    throw new InvalidArgumentException('The super admin email cannot be changed from this page.');
                }
                if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Please enter a valid email address.');
                }
                if ($new_email === strtolower(trim((string) admin_security_email($pdo, $target_id)))) {
                    throw new InvalidArgumentException('Enter a different email address.');
                }

                $stmt = $pdo->prepare('SELECT admin_id FROM admin_security_settings WHERE admin_id != ? AND LOWER(security_email) = LOWER(?) LIMIT 1');
                $stmt->execute([$target_id, $new_email]);
                if ($stmt->fetch()) {
                    throw new InvalidArgumentException('This email address is already associated with another admin account.');
                }

                $pdo->prepare('UPDATE admin_security_settings SET security_email = ?, updated_at = NOW() WHERE admin_id = ?')->execute([$new_email, $target_id]);
                log_security_event($pdo, 'Admin Email Changed', $acting_admin_id, $acting_username, 'success', null, "Updated admin_id: {$target_id}; new_email: {$new_email}");
                $success = 'Admin email updated successfully.';
            } elseif ($action === 'change_password') {
                $target_id = (int) ($_POST['admin_id'] ?? 0);
                $new_password = (string) ($_POST['new_password'] ?? '');
                $confirm_password = (string) ($_POST['confirm_password'] ?? '');

                $stmt = $pdo->prepare('SELECT id, username, password FROM admins WHERE id = ? LIMIT 1');
                $stmt->execute([$target_id]);
                $admin = $stmt->fetch();
                if (!$admin) {
                    throw new InvalidArgumentException('Admin not found.');
                }
                if ($admin['username'] === 'superadmin') {
                    throw new InvalidArgumentException('The super admin password cannot be changed from this page.');
                }
                if ($new_password === '' || $confirm_password === '') {
                    throw new InvalidArgumentException('Password and confirmation are required.');
                }
                if ($new_password !== $confirm_password) {
                    throw new InvalidArgumentException('Password confirmation does not match.');
                }
                $validation_errors = admin_password_errors($new_password);
                if (!empty($validation_errors)) {
                    throw new InvalidArgumentException('Password does not meet the required policy: ' . implode(' ', $validation_errors));
                }
                if (password_verify($new_password, $admin['password'])) {
                    throw new InvalidArgumentException('New password must be different from the current password.');
                }

                $pdo->prepare('UPDATE admins SET password = ? WHERE id = ?')->execute([password_hash($new_password, PASSWORD_DEFAULT), $target_id]);
                $pdo->prepare('UPDATE admin_security_settings SET password_changed_at = NOW(), updated_at = NOW() WHERE admin_id = ?')->execute([$target_id]);
                log_security_event($pdo, 'Admin Password Changed', $acting_admin_id, $acting_username, 'success', null, "Changed password for admin_id: {$target_id}");
                $success = 'Admin password updated successfully.';
            } elseif ($action === 'toggle_status') {
                $target_id = (int) ($_POST['admin_id'] ?? 0);
                $target = $pdo->prepare('SELECT id, username, login_attempts FROM admins WHERE id = ? LIMIT 1');
                $target->execute([$target_id]);
                $admin = $target->fetch();

                if (!$admin) {
                    throw new InvalidArgumentException('Admin not found.');
                }
                if ($admin['username'] === 'superadmin') {
                    throw new InvalidArgumentException('The super admin account is protected and cannot be locked or unlocked from this page.');
                }

                $new_status = isset($_POST['status']) && $_POST['status'] === 'locked' ? 'locked' : 'active';
                if ($new_status === 'locked') {
                    $pdo->prepare('UPDATE admins SET login_attempts = 5, last_attempt = NOW() WHERE id = ?')->execute([$target_id]);
                    log_security_event($pdo, 'Admin Locked', $acting_admin_id, $acting_username, 'success', null, "Locked admin_id: {$target_id}");
                    $success = 'Admin account locked successfully.';
                } else {
                    $pdo->prepare('UPDATE admins SET login_attempts = 0, last_attempt = NULL WHERE id = ?')->execute([$target_id]);
                    log_security_event($pdo, 'Admin Unlocked', $acting_admin_id, $acting_username, 'success', null, "Unlocked admin_id: {$target_id}");
                    $success = 'Admin account unlocked successfully.';
                }
            } elseif ($action === 'delete_admin') {
                $target_id = (int) ($_POST['admin_id'] ?? 0);
                $target = $pdo->prepare('SELECT id, username, role FROM admins WHERE id = ? LIMIT 1');
                $target->execute([$target_id]);
                $admin = $target->fetch();

                if (!$admin) {
                    throw new InvalidArgumentException('Admin not found.');
                }
                if ($admin['username'] === 'superadmin' || $admin['role'] === 'super_admin') {
                    throw new InvalidArgumentException('Super admin accounts are protected and cannot be deleted.');
                }
                if ($target_id === $acting_admin_id) {
                    throw new InvalidArgumentException('You cannot delete your own account.');
                }

                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$target_id]);
                $pdo->commit();
                
                log_security_event($pdo, 'Admin Deleted', $acting_admin_id, $acting_username, 'success', null, "Deleted admin_id: {$target_id} ({$admin['username']})");
                
                // Redirect to avoid stale data
                header('Location: manage_admins.php?msg=deleted');
                exit();
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
            log_security_event($pdo, 'Admin Management Error', $acting_admin_id, $acting_username, 'failed', null, $e->getMessage());
        }
    }
}

include '../includes/header.php';
?>


<div class="admin-page-shell">
    <div class="container-fluid">
        <div class="row">
            <!-- SIDEBAR -->
            <?php include '../includes/admin_sidebar.php'; ?>

            <!-- CONTENT -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 admin-page-content">
                <!-- ===== HEADER ===== -->
                <div class="admin-page-header">
                    <div>
                        <h1>
                            <i class="fas fa-user-shield"></i>
                            Admin Management
                        </h1>
                        <div class="subtitle">
                            <i class="fas fa-cog me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                            Manage administrator accounts, access, and security settings
                        </div>
                    </div>
                    <div class="header-actions">
                        <a href="dashboard.php" class="btn-back-dashboard">
                            <i class="fas fa-arrow-left"></i>
                            Back to Dashboard
                        </a>
                        <button type="button" class="btn-create-admin" data-bs-toggle="modal" data-bs-target="#createAdminModal">
                            <i class="fas fa-plus-circle"></i>
                            Create Admin
                        </button>
                    </div>
                </div>

                <!-- ===== STATS ===== -->
                <div class="stats-grid">
                    <a href="#admins-table" class="stat-card">
                        <div class="label">Total Admins</div>
                        <div class="value"><?= $stats['total_admins'] ?></div>
                    </a>
                    <a href="#admins-table" class="stat-card">
                        <div class="label">Active</div>
                        <div class="value"><?= $stats['active_admins'] ?></div>
                    </a>
                    <a href="#admins-table" class="stat-card">
                        <div class="label">Locked</div>
                        <div class="value"><?= $stats['locked_admins'] ?></div>
                    </a>
                    <a href="#admins-table" class="stat-card">
                        <div class="label">Super Admins</div>
                        <div class="value"><?= $stats['super_admins'] ?></div>
                    </a>
                </div>

                <!-- ===== ALERTS ===== -->
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

                <!-- TABLE -->
                <div class="table-card" id="admins-table">
                    <div class="card-header">
                        <h5>
                            <i class="fas fa-user-cog text-muted"></i>
                            Administrator Accounts
                            <span class="badge-count"><?= count($admins_list) ?></span>
                        </h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Permissions</th>
                                    <th>Last Login</th>
                                    <th style="min-width:200px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admins_list as $admin):
                                    $admin_id = (int) $admin['id'];
                                    $is_super = ($admin['username'] === 'superadmin' || $admin['role'] === 'super_admin');
                                    $is_locked = (int) $admin['login_attempts'] >= 5;
                                    $last_login = $admin['last_login'] ? date('M d, Y H:i', strtotime($admin['last_login'])) : 'Never';
                                ?>
                                    <tr>
                                        <td data-label="Username">
                                            <strong><?= htmlspecialchars($admin['username']) ?></strong>
                                        </td>
                                        <td data-label="Email">
                                            <?= htmlspecialchars($admin['security_email'] ?: 'Not set') ?>
                                        </td>
                                        <td data-label="Role">
                                            <span class="role-badge <?= $is_super ? 'super' : 'admin' ?>">
                                                <?= $is_super ? 'Super Admin' : 'Admin' ?>
                                            </span>
                                        </td>
                                        <td data-label="Status">
                                            <span class="status-badge <?= $is_super ? 'protected' : ($is_locked ? 'locked' : 'active') ?>">
                                                <?= $is_super ? 'Protected' : ($is_locked ? 'Locked' : 'Active') ?>
                                            </span>
                                        </td>
                                        <td data-label="Permissions">
                                            <?php if ($is_super): ?>
                                                <span class="badge bg-light text-dark border">All Access</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark border"><?= (int) $admin['permission_count'] ?> granted</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Last Login">
                                            <?= htmlspecialchars($last_login) ?>
                                        </td>
                                        <td data-label="Actions">
                                            <div class="action-group">
                                                <?php if (!$is_super): ?>
                                                    <!-- Edit / Change Email -->
                                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#adminEmailModal"
                                                        data-admin-id="<?= $admin_id ?>"
                                                        data-admin-name="<?= htmlspecialchars($admin['username'], ENT_QUOTES, 'UTF-8') ?>"
                                                        data-current-email="<?= htmlspecialchars($admin['security_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                                        <i class="fas fa-envelope"></i> Email
                                                    </button>
                                                    <!-- Change Password -->
                                                    <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#adminPasswordModal"
                                                        data-admin-id="<?= $admin_id ?>"
                                                        data-admin-name="<?= htmlspecialchars($admin['username'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <i class="fas fa-key"></i> Password
                                                    </button>
                                                    <!-- FIXED: Manage Permissions now passes admin_id correctly -->
                                                    <a href="manage_permissions.php?admin_id=<?= $admin_id ?>" class="btn btn-sm btn-outline-info">
                                                        <i class="fas fa-unlock-alt"></i> Permissions
                                                    </a>
                                                    <!-- Lock / Unlock -->
                                                    <?php if ($is_locked): ?>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Unlock this admin account?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="toggle_status">
                                                            <input type="hidden" name="admin_id" value="<?= $admin_id ?>">
                                                            <input type="hidden" name="status" value="active">
                                                            <button type="submit" class="btn btn-sm btn-outline-success">
                                                                <i class="fas fa-unlock"></i> Unlock
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="post" class="d-inline" onsubmit="return confirm('Lock this admin account?');">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="toggle_status">
                                                            <input type="hidden" name="admin_id" value="<?= $admin_id ?>">
                                                            <input type="hidden" name="status" value="locked">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-lock"></i> Lock
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <!-- Delete -->
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this admin account? This action cannot be undone.');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                                                        <input type="hidden" name="action" value="delete_admin">
                                                        <input type="hidden" name="admin_id" value="<?= $admin_id ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Admin">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-muted small">Protected</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($admins_list)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            No administrator accounts found.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </main><!-- /content -->
        </div><!-- /layout -->

    </div><!-- /container-fluid -->
</div>

<!-- ===== MODALS ===== -->

<!-- Create Admin Modal -->
<div class="modal fade modal-mgmt" id="createAdminModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus me-2 text-primary"></i>Create New Admin</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_admin">

                    <div class="mb-3">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required maxlength="50" pattern="[A-Za-z0-9_.-]+" placeholder="e.g. johndoe">
                        <div class="form-text text-muted">3-50 characters, letters, numbers, dot, underscore, or hyphen.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required placeholder="admin@example.com">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" id="create_password" name="password" class="form-control border-end-0" required autocomplete="new-password">
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="create_password" style="border-color: #d0d7e2; color: #6b7a8f;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-hint">
                            <i class="fas fa-info-circle me-1"></i>
                            Minimum 12 chars, include uppercase, lowercase, number, and special character.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" id="create_confirm_password" name="confirm_password" class="form-control border-end-0" required autocomplete="new-password">
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="create_confirm_password" style="border-color: #d0d7e2; color: #6b7a8f;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Account Status</label>
                        <select name="account_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="locked">Locked</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary">Create Admin</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Change Email Modal -->
<div class="modal fade modal-mgmt" id="adminEmailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-envelope me-2 text-primary"></i>Change Admin Email</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_email">
                    <input type="hidden" name="admin_id" id="emailAdminId">

                    <div class="mb-3">
                        <label class="form-label">Admin</label>
                        <input type="text" id="emailAdminName" class="form-control" readonly>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">New Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="emailField" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary">Update Email</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade modal-mgmt" id="adminPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-key me-2 text-warning"></i>Change Admin Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="admin_id" id="passwordAdminId">

                    <div class="mb-3">
                        <label class="form-label">Admin</label>
                        <input type="text" id="passwordAdminName" class="form-control" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" id="change_new_password" name="new_password" class="form-control border-end-0" required autocomplete="new-password">
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="change_new_password" style="border-color: #d0d7e2; color: #6b7a8f;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" id="change_confirm_password" name="confirm_password" class="form-control border-end-0" required autocomplete="new-password">
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="change_confirm_password" style="border-color: #d0d7e2; color: #6b7a8f;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-hint mt-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Minimum 12 chars, include uppercase, lowercase, number, and special character.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Email modal
    const emailModal = document.getElementById('adminEmailModal');
    if (emailModal) {
        emailModal.addEventListener('show.bs.modal', function(event) {
            const trigger = event.relatedTarget;
            document.getElementById('emailAdminId').value = trigger.getAttribute('data-admin-id');
            document.getElementById('emailAdminName').value = trigger.getAttribute('data-admin-name');
            document.getElementById('emailField').value = trigger.getAttribute('data-current-email') || '';
        });
    }

    // Password modal
    const passwordModal = document.getElementById('adminPasswordModal');
    if (passwordModal) {
        passwordModal.addEventListener('show.bs.modal', function(event) {
            const trigger = event.relatedTarget;
            document.getElementById('passwordAdminId').value = trigger.getAttribute('data-admin-id');
            document.getElementById('passwordAdminName').value = trigger.getAttribute('data-admin-name');
        });
    }

    // Auto-open modals from sidebar links with href="#modalId"
    document.querySelectorAll('.admin-page-sidebar .nav-link[data-bs-toggle="modal"]').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const target = this.getAttribute('data-bs-target');
            const modal = new bootstrap.Modal(document.querySelector(target));
            modal.show();
        });
    });
    // Password toggle functionality
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