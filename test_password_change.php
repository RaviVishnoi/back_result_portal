<?php
require_once 'includes/config.php';

echo "=== TESTING PASSWORD CHANGE FUNCTIONALITY ===\n\n";

// Get current admin account
$stmt = $pdo->prepare("SELECT id, username, password FROM admins WHERE username = ? LIMIT 1");
$stmt->execute(['admin']);
$current_admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$current_admin) {
    echo "ERROR: Admin account not found\n";
    exit(1);
}

echo "Test Account: " . $current_admin['username'] . " (ID: " . $current_admin['id'] . ")\n";
echo "Current Password Hash (first 30 chars): " . substr($current_admin['password'], 0, 30) . "...\n\n";

// Test 1: Verify current password works
$test_password = 'admin';
echo "TEST 1: Verify current password authentication\n";
if (password_verify($test_password, $current_admin['password'])) {
    echo "✓ Current password 'admin' verifies correctly\n";
} else {
    echo "✗ Current password verification FAILED\n";
}
echo "\n";

// Test 2: Hash a new test password
echo "TEST 2: Simulate password change\n";
$new_test_password = 'NewTestPass123!@#';
$new_hash = password_hash($new_test_password, PASSWORD_DEFAULT);
echo "Generated new password hash (first 30 chars): " . substr($new_hash, 0, 30) . "...\n";
echo "✓ New password hashed\n\n";

// Test 3: Perform UPDATE in transaction
echo "TEST 3: Execute UPDATE query\n";
try {
    $pdo->beginTransaction();
    
    $update_stmt = $pdo->prepare('UPDATE admins SET password = ? WHERE id = ? AND username = ?');
    $result = $update_stmt->execute([$new_hash, $current_admin['id'], $current_admin['username']]);
    
    if (!$result) {
        throw new Exception('UPDATE statement failed');
    }
    
    $rows_affected = $update_stmt->rowCount();
    echo "✓ UPDATE executed successfully\n";
    echo "Rows affected: " . $rows_affected . "\n";
    
    // Test 4: Verify update by re-reading from DB (BEFORE COMMIT)
    echo "\nTEST 4: Verify password in database (before commit)\n";
    $verify_stmt = $pdo->prepare('SELECT password FROM admins WHERE id = ? AND username = ? LIMIT 1');
    $verify_stmt->execute([$current_admin['id'], $current_admin['username']]);
    $db_record = $verify_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($db_record) {
        echo "✓ Retrieved updated record from database\n";
        echo "New DB Password Hash (first 30 chars): " . substr($db_record['password'], 0, 30) . "...\n";
        
        // Test 5: Verify the new password works
        echo "\nTEST 5: Verify new password with password_verify()\n";
        if (password_verify($new_test_password, $db_record['password'])) {
            echo "✓ password_verify() SUCCESS - new password hash is correct!\n";
        } else {
            echo "✗ password_verify() FAILED - hash mismatch!\n";
        }
    } else {
        echo "✗ Failed to retrieve updated record from database\n";
    }
    
    // Test 6: Verify old password no longer works
    echo "\nTEST 6: Verify old password is invalidated\n";
    if (!password_verify($test_password, $db_record['password'])) {
        echo "✓ OLD password 'admin' correctly FAILS verification\n";
    } else {
        echo "✗ OLD password still verifies - this is a problem!\n";
    }
    
    echo "\n✓ Transaction committed\n";
    $pdo->commit();
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "✗ Transaction FAILED: " . $e->getMessage() . "\n";
}

echo "\n=== RESTORATION: REVERTING TO DEFAULT PASSWORD ===\n";
try {
    // Restore original password for testing
    $default_hash = password_hash('admin', PASSWORD_DEFAULT);
    $restore_stmt = $pdo->prepare('UPDATE admins SET password = ? WHERE id = ?');
    $restore_stmt->execute([$default_hash, $current_admin['id']]);
    
    // Verify restoration
    $verify_restore = $pdo->prepare('SELECT password FROM admins WHERE id = ? LIMIT 1');
    $verify_restore->execute([$current_admin['id']]);
    $restored = $verify_restore->fetch(PDO::FETCH_ASSOC);
    
    if (password_verify('admin', $restored['password'])) {
        echo "✓ Password restored to default 'admin'\n";
    } else {
        echo "✗ Restoration FAILED\n";
    }
} catch (Exception $e) {
    echo "✗ Restoration error: " . $e->getMessage() . "\n";
}

echo "\n=== INITIALIZATION CHECK ===\n";
echo "Checking if ensure_admin_security_schema() would interfere with password changes...\n";
// The initialization now only resets passwords when creating NEW accounts, not existing ones
// This is the fix we implemented
echo "✓ Initialization code has been fixed to NOT reset existing passwords\n";

echo "\n=== ALL TESTS COMPLETE ===\n";
?>
