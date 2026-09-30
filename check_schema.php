<?php
require_once 'includes/config.php';
echo "=== ADMINS TABLE SCHEMA ===\n";
$stmt = $pdo->query('DESCRIBE admins');
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo $col['Field'] . ' | ' . $col['Type'] . ' | ' . $col['Null'] . ' | ' . $col['Key'] . "\n";
}

echo "\n=== SAMPLE ADMIN RECORD ===\n";
$stmt = $pdo->query("SELECT id, username, password, role FROM admins WHERE username='admin' LIMIT 1");
$admin = $stmt->fetch(PDO::FETCH_ASSOC);
if ($admin) {
    echo "ID: " . $admin['id'] . "\n";
    echo "Username: " . $admin['username'] . "\n";
    echo "Password (first 20 chars): " . substr($admin['password'], 0, 20) . "...\n";
    echo "Role: " . $admin['role'] . "\n";
}

echo "\n=== CHECK INITIALIZATION LOGIC ===\n";
echo "Checking if ensure_admin_security_schema() resets passwords...\n";
?>
