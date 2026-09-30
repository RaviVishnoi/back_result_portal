<?php
require_once '../includes/config.php';

require_admin_session($pdo);
require_super_admin($pdo);

$admin_id = (int) ($_GET['admin_id'] ?? $_POST['admin_id'] ?? 0);
$error = '';
$success = '';

// Get all admins for dropdown
$admins = $pdo->query("
    SELECT id, username, role 
    FROM admins 
    ORDER BY role DESC, username ASC
")->fetchAll();

// Get admin details if selected
$admin_details = null;
if ($admin_id > 0) {
    $stmt = $pdo->prepare("SELECT id, username, role FROM admins WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin_details = $stmt->fetch();
    
    if (!$admin_details) {
        $admin_id = 0;
        $error = 'Admin not found. Please select a valid admin.';
    }
}

// CRITICAL SECURITY: Cannot manage permissions for 'superadmin'
if ($admin_details && $admin_details['username'] === 'superadmin') {
    $admin_id = 0;
    $admin_details = null;
    $error = 'Cannot manage permissions for superadmin account. This account has permanent super_admin role with full system access.';
}

$is_target_super_admin = $admin_details && strcasecmp($admin_details['role'], 'super_admin') === 0;

// Handle bulk permission updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $admin_id > 0 && !$is_target_super_admin) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Invalid security token.";
    } else {
        try {
            $pdo->beginTransaction();
            
            // Get checked permissions from form
            $checked_permissions = isset($_POST['permissions']) && is_array($_POST['permissions']) 
                ? array_map('intval', $_POST['permissions']) 
                : [];
            
            // Get current permissions from database
            $current_perms = $pdo->prepare("
                SELECT permission_id FROM admin_user_permissions 
                WHERE admin_id = ? AND granted = 1
            ");
            $current_perms->execute([$admin_id]);
            $current_perm_ids = array_column($current_perms->fetchAll(), 'permission_id');
            
            // Calculate changes needed
            $to_grant = array_diff($checked_permissions, $current_perm_ids);
            $to_revoke = array_diff($current_perm_ids, $checked_permissions);
            
            // Grant new permissions
            foreach ($to_grant as $perm_id) {
                $pdo->prepare("INSERT IGNORE INTO admin_user_permissions (admin_id, permission_id, granted) VALUES (?, ?, 1)")
                    ->execute([$admin_id, $perm_id]);
            }
            
            // Revoke permissions not checked
            foreach ($to_revoke as $perm_id) {
                $pdo->prepare("DELETE FROM admin_user_permissions WHERE admin_id = ? AND permission_id = ?")
                    ->execute([$admin_id, $perm_id]);
            }
            
            $pdo->commit();
            
            $granted_count = count($to_grant);
            $revoked_count = count($to_revoke);
            $active_count = count($checked_permissions);
            
            if ($granted_count > 0 || $revoked_count > 0) {
                log_security_event($pdo, 'Permission Changed', $_SESSION['admin_id'], $_SESSION['username'], 'success', null, "Updated permissions for admin {$admin_id}: {$granted_count} granted, {$revoked_count} revoked, {$active_count} total active");
                $message_parts = [];
                if ($granted_count > 0) {
                    $message_parts[] = $granted_count . " permission(s) granted";
                }
                if ($revoked_count > 0) {
                    $message_parts[] = $revoked_count . " permission(s) revoked";
                }
                $message_parts[] = $active_count . " permission(s) now active";
                $success = "Permissions saved successfully. " . implode(", ", $message_parts) . ".";
            } else {
                $success = "Permissions saved. No changes were needed.";
            }
            
            // Refresh admin permissions after update
            $stmt = $pdo->prepare("
                SELECT permission_id FROM admin_user_permissions 
                WHERE admin_id = ? AND granted = 1
            ");
            $stmt->execute([$admin_id]);
            $admin_permissions = array_column($stmt->fetchAll(), 'permission_id');
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Failed to update permissions: " . $e->getMessage();
            log_security_event($pdo, 'Permission Update Failed', $_SESSION['admin_id'], $_SESSION['username'], 'failed', null, $e->getMessage());
        }
    }
}

// Get all permissions grouped by module and sub_module
$permissions_by_module = [];
$stmt = $pdo->query("
    SELECT id, permission_key, permission_name, module, sub_module, description FROM admin_permissions 
    ORDER BY module ASC, sub_module ASC, permission_name ASC
");
while ($row = $stmt->fetch()) {
    $module = $row['module'] ?: 'general';
    $sub_module = $row['sub_module'] ?: 'General';
    if (!isset($permissions_by_module[$module])) {
        $permissions_by_module[$module] = [];
    }
    if (!isset($permissions_by_module[$module][$sub_module])) {
        $permissions_by_module[$module][$sub_module] = [];
    }
    $permissions_by_module[$module][$sub_module][] = $row;
}

// Get admin's current permissions (refresh if just updated)
if ($admin_id > 0 && !$is_target_super_admin) {
    $stmt = $pdo->prepare("
        SELECT permission_id FROM admin_user_permissions 
        WHERE admin_id = ? AND granted = 1
    ");
    $stmt->execute([$admin_id]);
    $admin_permissions = array_column($stmt->fetchAll(), 'permission_id');
} elseif ($admin_id > 0 && $is_target_super_admin) {
    $admin_permissions = [];
}

include '../includes/header.php';
?>


<style>
.admin-page-layout {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 1.5rem;
    align-items: start;
}

.admin-select-card {
    position: sticky;
    top: calc(var(--header-height) + 1.5rem);
    max-height: calc(100vh - var(--header-height) - 3rem);
    overflow-y: auto;
}

@media (max-width: 991px) {
    .admin-page-layout {
        grid-template-columns: 1fr;
    }
    .admin-select-card {
        position: static;
        max-height: none;
        overflow-y: visible;
    }
}
</style>

<div class="admin-page-shell">
    <div class="container-fluid">
        <div class="row">
            <!-- SIDEBAR -->
            <?php include '../includes/admin_sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4 admin-page-content">
                <!-- ===== HEADER ===== -->
                <div class="admin-page-header">
            <div>
                <h1>
                    <i class="fas fa-unlock-alt"></i>
                    Permission Management
                </h1>
                <div class="subtitle">
                    <i class="fas fa-cog me-1" style="color:#8a9aae;font-size:0.75rem;"></i>
                    Grant or revoke access permissions for administrator accounts
                </div>
            </div>
            <div class="header-actions">
                <a href="dashboard.php" class="btn-back-dashboard">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard
                </a>
                <a href="manage_admins.php" class="btn-back-admin">
                    <i class="fas fa-users"></i>
                    Admin Management
                </a>
            </div>
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

        <!-- ===== LAYOUT ===== -->
        <div class="admin-page-layout">

            <!-- LEFT: Admin Select -->
            <aside class="admin-select-card">
                <div class="card-title">
                    <i class="fas fa-user-cog"></i> Select Admin
                </div>

                <form method="GET" id="adminSelectForm">
                    <div class="mb-3">
                        <label class="form-label">Choose Administrator</label>
                        <select name="admin_id" class="form-select" id="adminSelect">
                            <option value="">-- Select Admin --</option>
                            <?php foreach ($admins as $admin): 
                                $is_super = $admin['username'] === 'superadmin' || $admin['role'] === 'super_admin';
                            ?>
                                <option value="<?= (int) $admin['id'] ?>" 
                                    <?= $admin_id === (int) $admin['id'] ? 'selected' : '' ?>
                                    <?= $is_super ? 'style="font-weight:600;color:#7e22ce;"' : '' ?>>
                                    <?= htmlspecialchars($admin['username']) ?>
                                    <?= $is_super ? '(Super Admin)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-select" id="selectAdminBtn">
                        <i class="fas fa-arrow-right me-2"></i> Load Permissions
                    </button>
                </form>

                <?php if ($admin_details): ?>
                    <div class="selected-admin-info">
                        <div class="admin-name">
                            <i class="fas fa-user-circle" style="color:#6b7a8f;"></i>
                            <?= htmlspecialchars($admin_details['username']) ?>
                        </div>
                        <div>
                            <span class="admin-role <?= $is_target_super_admin ? 'super' : 'admin' ?>">
                                <?= $is_target_super_admin ? 'Super Admin' : 'Admin' ?>
                            </span>
                        </div>
                        <div class="perm-count">
                            <i class="fas fa-key me-1"></i>
                            <?= $is_target_super_admin ? 'Full system access (all permissions)' : count($admin_permissions ?? []) . ' permission(s) granted' ?>
                        </div>
                    </div>
                <?php endif; ?>
            </aside>

            <!-- RIGHT: Permissions -->
            <div class="permissions-card">
                <div class="card-header">
                    <h5>
                        <i class="fas fa-shield-alt text-muted"></i>
                        Permissions Matrix
                        <?php if ($admin_details): ?>
                            <span class="badge-count">
                                <?= $is_target_super_admin ? 'All' : (count($admin_permissions ?? []) . ' granted') ?>
                            </span>
                        <?php endif; ?>
                    </h5>
                    <?php if ($admin_details && !$is_target_super_admin && !empty($permissions_by_module)): ?>
                        <!-- Global actions can go here if needed later -->
                    <?php endif; ?>
                </div>

                <div class="card-body">

                    <?php if (!$admin_details): ?>
                        <div class="no-admin-selected">
                            <i class="fas fa-user-slash"></i>
                            <p>Please select an administrator from the left panel to manage their permissions.</p>
                        </div>

                    <?php elseif ($is_target_super_admin): ?>
                        <div class="alert-mgmt alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Super Admin:</strong> This account has full system access. All permissions are automatically granted and cannot be individually managed.
                        </div>
                        <div style="padding:1rem;background:#f9fafc;border-radius:12px;border:1px solid #edf1f7;">
                            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                                <?php 
                                $all_perms = $pdo->query("SELECT permission_name FROM admin_permissions ORDER BY module, permission_name")->fetchAll();
                                foreach ($all_perms as $p):
                                ?>
                                    <span class="badge" style="background:#eff6ff;color:#1d4ed8;padding:0.3rem 0.7rem;border-radius:30px;font-size:0.7rem;font-weight:500;">
                                        <i class="fas fa-check-circle me-1" style="font-size:0.6rem;"></i>
                                        <?= htmlspecialchars($p['permission_name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- Hierarchical Permission Form -->
                        <form method="POST" id="permissionsForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                            <input type="hidden" name="admin_id" value="<?= (int) $admin_id ?>">
                        
                            <?php if (empty($permissions_by_module)): ?>
                                <div class="no-admin-selected" style="padding:1.5rem;">
                                    <p>No permissions defined in the system.</p>
                                </div>
                            <?php else: ?>
                                <div class="perm-hierarchy">
                                    <?php foreach ($permissions_by_module as $module => $sub_modules): ?>
                                        <?php $module_name = ucwords(str_replace('_', ' ', $module)); ?>
                                        <div class="main-module-block mb-4 p-3 border rounded bg-white shadow-sm">
                                            <div class="form-check mb-3 border-bottom pb-2">
                                                <input class="form-check-input main-module-checkbox" style="transform: scale(1.3); margin-top: 0.3rem;" type="checkbox" id="mod_<?= htmlspecialchars($module) ?>" data-module="<?= htmlspecialchars($module) ?>">
                                                <label class="form-check-label fw-bold fs-5 ms-2 text-dark" for="mod_<?= htmlspecialchars($module) ?>">
                                                    <i class="fas fa-layer-group text-primary me-2"></i><?= htmlspecialchars($module_name) ?>
                                                </label>
                                            </div>
                        
                                            <div class="sub-modules-container ms-5">
                                                <?php foreach ($sub_modules as $sub_module => $perms): ?>
                                                    <div class="sub-module-block mb-4">
                                                        <div class="fw-bold mb-2 text-secondary" style="font-size: 0.95rem;">
                                                            <i class="fas fa-caret-down me-1"></i> <?= htmlspecialchars($sub_module) ?>
                                                        </div>
                                                        <div class="actions-container ms-4 d-flex flex-column gap-2">
                                                            <?php foreach ($perms as $perm): ?>
                                                                <?php $has_perm = in_array($perm['id'], $admin_permissions ?? []); ?>
                                                                <div class="form-check">
                                                                    <input class="form-check-input action-checkbox" type="checkbox" name="permissions[]" value="<?= (int) $perm['id'] ?>" id="perm_<?= $perm['id'] ?>" data-module="<?= htmlspecialchars($module) ?>" <?= $has_perm ? 'checked' : '' ?>>
                                                                    <label class="form-check-label text-dark" for="perm_<?= $perm['id'] ?>" title="<?= htmlspecialchars($perm['description']) ?>">
                                                                        <?= htmlspecialchars($perm['permission_name']) ?>
                                                                    </label>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                        
                                <!-- Save Section -->
                                <div class="save-section mt-4 pt-3 border-top d-flex justify-content-between align-items-center">
                                    <div class="save-info text-muted" id="saveInfo">
                                        <i class="fas fa-info-circle me-1"></i> Select permissions to grant
                                    </div>
                                    <div class="save-buttons">
                                        <button type="submit" class="btn-create-admin px-4 rounded-pill fw-bold" id="saveBtn">
                                            <i class="fas fa-save me-2"></i> Save Permissions
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>

                </div><!-- /card-body -->
            </div><!-- /permissions-card -->

        </div><!-- /layout -->
            </main>
        </div><!-- /row -->
    </div><!-- /container-fluid -->
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const mainCheckboxes = document.querySelectorAll('.main-module-checkbox');
    const actionCheckboxes = document.querySelectorAll('.action-checkbox');
    const form = document.getElementById('permissionsForm');
    const saveBtn = document.getElementById('saveBtn');

    // Initialize main module state based on children
    mainCheckboxes.forEach(mainCb => {
        const module = mainCb.dataset.module;
        const checkedChildren = document.querySelectorAll(`.action-checkbox[data-module="${module}"]:checked`);
        
        mainCb.checked = checkedChildren.length > 0;
        
        mainCb.addEventListener('change', function() {
            const children = document.querySelectorAll(`.action-checkbox[data-module="${module}"]`);
            children.forEach(cb => {
                cb.checked = this.checked;
            });
        });
    });

    // When an action checkbox changes, update the parent main module
    actionCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            const module = this.dataset.module;
            const mainCb = document.querySelector(`.main-module-checkbox[data-module="${module}"]`);
            const checkedChildren = document.querySelectorAll(`.action-checkbox[data-module="${module}"]:checked`);
            if (mainCb) {
                mainCb.checked = checkedChildren.length > 0;
            }
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>