<?php
require_once '../includes/config.php';

if (isset($_SESSION['admin_id'])) {
    log_activity($pdo, "Admin logged out", $_SESSION['admin_id']);
    log_security_event($pdo, 'Logout', $_SESSION['admin_id'], $_SESSION['username'] ?? null, 'success');
    $pdo->prepare('UPDATE admin_security_logs SET logout_at = NOW() WHERE session_id_hash = ? ORDER BY id DESC LIMIT 1')
        ->execute([hash('sha256', session_id())]);
}

session_unset();
session_destroy();
header("Location: ../admin_login.php");
exit();
?>