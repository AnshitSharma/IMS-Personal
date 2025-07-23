<?php
/**
 * Debug Authentication Script
 * Create this file as debug_auth.php in your project root
 * Access it via browser to see what's wrong
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Authentication Debug Script</h2>";

// Include database config
require_once(__DIR__ . '/includes/db_config.php');

// Test 1: Database Connection
echo "<h3>1. Database Connection Test</h3>";
try {
    $testQuery = $pdo->query("SELECT 1");
    echo "✅ Database connection successful<br>";
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "<br>";
    exit;
}

// Test 2: Check users table
echo "<h3>2. Users Table Check</h3>";
try {
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✅ Users table columns: " . implode(', ', $columns) . "<br>";
    
    if (!in_array('password', $columns)) {
        echo "❌ 'password' column not found in users table!<br>";
    }
} catch (Exception $e) {
    echo "❌ Error checking users table: " . $e->getMessage() . "<br>";
}

// Test 3: Check johnadmin user
echo "<h3>3. User 'johnadmin' Check</h3>";
try {
    $stmt = $pdo->prepare("SELECT id, username, password, email FROM users WHERE username = ?");
    $stmt->execute(['johnadmin']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "✅ User found:<br>";
        echo "- ID: " . $user['id'] . "<br>";
        echo "- Username: " . $user['username'] . "<br>";
        echo "- Email: " . $user['email'] . "<br>";
        echo "- Password hash: " . substr($user['password'], 0, 20) . "...<br>";
        echo "- Password length: " . strlen($user['password']) . "<br>";
        echo "- Hash starts with \$2y\$: " . (strpos($user['password'], '$2y$') === 0 ? 'Yes' : 'No') . "<br>";
    } else {
        echo "❌ User 'johnadmin' not found in database<br>";
    }
} catch (Exception $e) {
    echo "❌ Error checking user: " . $e->getMessage() . "<br>";
}

// Test 4: Password verification test
echo "<h3>4. Password Verification Test</h3>";
$testPassword = 'admin123';
$testHash = '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm';

echo "Testing password: '$testPassword'<br>";
echo "Against hash: $testHash<br>";
$verifyResult = password_verify($testPassword, $testHash);
echo "Verification result: " . ($verifyResult ? '✅ SUCCESS' : '❌ FAILED') . "<br>";

// Test 5: Test with actual database password
if (isset($user) && $user) {
    echo "<h3>5. Test with Database Password</h3>";
    echo "Testing 'admin123' against database hash:<br>";
    $dbVerifyResult = password_verify('admin123', $user['password']);
    echo "Result: " . ($dbVerifyResult ? '✅ SUCCESS' : '❌ FAILED') . "<br>";
    
    // Try other common passwords
    $commonPasswords = ['admin123', 'password', 'admin', 'johnadmin', '123456'];
    echo "<br>Testing common passwords:<br>";
    foreach ($commonPasswords as $pwd) {
        $result = password_verify($pwd, $user['password']);
        echo "- '$pwd': " . ($result ? '✅ MATCH' : '❌ No match') . "<br>";
    }
}

// Test 6: Create new hash for testing
echo "<h3>6. Create New Hash Test</h3>";
$newHash = password_hash('admin123', PASSWORD_DEFAULT);
echo "New hash for 'admin123': $newHash<br>";
$newVerify = password_verify('admin123', $newHash);
echo "Verification: " . ($newVerify ? '✅ SUCCESS' : '❌ FAILED') . "<br>";

// Test 7: Update database with new hash
echo "<h3>7. Update Database Test</h3>";
if (isset($user) && $user) {
    try {
        $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
        $updateResult = $updateStmt->execute([$newHash, 'johnadmin']);
        
        if ($updateResult) {
            echo "✅ Database updated with new hash<br>";
            
            // Verify the update worked
            $verifyStmt = $pdo->prepare("SELECT password FROM users WHERE username = ?");
            $verifyStmt->execute(['johnadmin']);
            $updatedUser = $verifyStmt->fetch();
            
            if ($updatedUser) {
                $finalTest = password_verify('admin123', $updatedUser['password']);
                echo "Final verification test: " . ($finalTest ? '✅ SUCCESS' : '❌ FAILED') . "<br>";
            }
        } else {
            echo "❌ Failed to update database<br>";
        }
    } catch (Exception $e) {
        echo "❌ Error updating database: " . $e->getMessage() . "<br>";
    }
}

// Test 8: Test the actual API endpoint
echo "<h3>8. API Endpoint Test</h3>";
echo "<form method='POST' action='api/api.php' target='_blank'>";
echo "<input type='hidden' name='action' value='auth-login'>";
echo "<input type='hidden' name='username' value='johnadmin'>";
echo "<input type='hidden' name='password' value='admin123'>";
echo "<input type='submit' value='Test API Login' style='padding: 10px; background: #007cba; color: white; border: none; cursor: pointer;'>";
echo "</form>";

echo "<h3>9. Manual Test Instructions</h3>";
echo "After running this script:<br>";
echo "1. The password should be updated in the database<br>";
echo "2. Try logging in with: <strong>johnadmin / admin123</strong><br>";
echo "3. If it still doesn't work, check your PHP error logs<br>";
echo "4. Make sure you've replaced both BaseFunctions.php and api.php with the updated versions<br>";

?>