<?php

if (!isset($pdo)) {
    require_once(__DIR__ . '/db_config.php');
}

// Only include config if not already included
if (!defined('MAIN_SITE_URL')) {
    require_once(__DIR__ . '/config.php');
}

function isUserLoggedIn($pdo) {
    // Check if session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['id'])) {
        return false;
    }
    
    $user_id = $_SESSION['id'];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :user_id");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $user = $stmt->fetch();
        
        // If user not found, clear session
        if (!$user) {
            session_unset();
            session_destroy();
            return false;
        }
        
        return $user; // Returns the user data
        
    } catch (PDOException $e) {
        error_log("Database error in isUserLoggedIn: " . $e->getMessage());
        return false;
    }
}

/**
 * Enhanced session validation with ACL
 */
function isUserLoggedInWithACL($pdo) {
    // Include ACL class only when needed to avoid redeclaration issues
    if (!class_exists('ACL')) {
        require_once(__DIR__ . '/ACL.php');
    }
    
    $user = isUserLoggedIn($pdo);
    if (!$user) {
        return false;
    }
    
    // Add ACL information to user data
    $acl = new ACL($pdo, $user['id']);
    $user['roles'] = $acl->getUserRoles();
    $user['permissions'] = $acl->getUserPermissions();
    $user['is_super_admin'] = $acl->isSuperAdmin();
    
    return $user;
}

/**
 * Check if current user has permission
 */
function hasPermission($pdo, $resource, $action, $resourceId = null) {
    // Include ACL class only when needed
    if (!class_exists('ACL')) {
        require_once(__DIR__ . '/ACL.php');
    }
    
    session_start();
    if (!isset($_SESSION['id'])) {
        return false;
    }
    
    $acl = new ACL($pdo, $_SESSION['id']);
    return $acl->hasPermission($resource, $action, $resourceId);
}

/**
 * Require permission or exit with error
 */
function requirePermission($pdo, $resource, $action, $resourceId = null) {
    if (!hasPermission($pdo, $resource, $action, $resourceId)) {
        http_response_code(403);
        send_json_response(0, 0, 403, "Access denied. Insufficient permissions.", [
            'required_permission' => $resource . '.' . $action
        ]);
        exit;
    }
}

/**
 * Enhanced JSON response with user context
 */
function send_json_response($logged_in, $success, $status_code, $message, $other_params = []) {
    $resp = [
        'is_logged_in' => $logged_in,
        'status_code' => $status_code,
        'success' => $success,
        'message' => $message,
        'timestamp' => date('c')
    ];
    
    // Add user context if logged in
    if ($logged_in && isset($_SESSION['id'])) {
        global $pdo;
        try {
            // Include ACL class only when needed
            if (!class_exists('ACL')) {
                require_once(__DIR__ . '/ACL.php');
            }
            
            $acl = new ACL($pdo, $_SESSION['id']);
            $resp['user_context'] = [
                'user_id' => $_SESSION['id'],
                'username' => $_SESSION['username'] ?? null,
                'roles' => array_column($acl->getUserRoles(), 'name'),
                'is_super_admin' => $acl->isSuperAdmin()
            ];
        } catch (Exception $e) {
            error_log("Error adding user context to response: " . $e->getMessage());
        }
    }
    
    if ($other_params != []) {
        $resp = array_merge($resp, $other_params);
    }
    
    // Set proper JSON header
    header('Content-Type: application/json');
    
    echo json_encode($resp);
    exit();
}

/**
 * Get current user's ACL instance
 */
function getCurrentUserACL($pdo) {
    // Include ACL class only when needed
    if (!class_exists('ACL')) {
        require_once(__DIR__ . '/ACL.php');
    }
    
    session_start();
    $userId = $_SESSION['id'] ?? null;
    return new ACL($pdo, $userId);
}

/**
 * Log significant actions for audit trail
 */
function logAction($pdo, $action, $resourceType = null, $resourceId = null, $details = null) {
    session_start();
    if (!isset($_SESSION['id'])) {
        return;
    }
    
    try {
        // Check if acl_audit_log table exists, if not skip logging
        $checkTable = $pdo->query("SHOW TABLES LIKE 'acl_audit_log'");
        if ($checkTable->rowCount() == 0) {
            return; // Table doesn't exist, skip logging
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO acl_audit_log 
            (user_id, action, resource_type, resource_id, result, ip_address, user_agent)
            VALUES (:user_id, :action, :resource_type, :resource_id, 'granted', :ip_address, :user_agent)
        ");
        
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->bindParam(':user_id', $_SESSION['id'], PDO::PARAM_INT);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':resource_type', $resourceType);
        $stmt->bindParam(':resource_id', $resourceId);
        $stmt->bindParam(':ip_address', $ipAddress);
        $stmt->bindParam(':user_agent', $userAgent);
        
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Error logging action: " . $e->getMessage());
    }
}

/**
 * Check if user has any of the specified roles
 */
function hasAnyRole($pdo, $roles) {
    // Include ACL class only when needed
    if (!class_exists('ACL')) {
        require_once(__DIR__ . '/ACL.php');
    }
    
    session_start();
    if (!isset($_SESSION['id'])) {
        return false;
    }
    
    $acl = new ACL($pdo, $_SESSION['id']);
    foreach ($roles as $role) {
        if ($acl->hasRole($role)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if request is AJAX
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    session_start();
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 */
function validateCSRFToken($token) {
    session_start();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get component table name
 */
function getComponentTable($componentType) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    return $tableMap[$componentType] ?? null;
}

/**
 * Rate limiting helper
 */
function checkRateLimit($identifier, $maxRequests = 100, $timeWindow = 3600) {
    $cacheFile = sys_get_temp_dir() . '/rate_limit_' . md5($identifier);
    
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if ($data && $data['reset_time'] > time()) {
            if ($data['requests'] >= $maxRequests) {
                return false;
            }
            $data['requests']++;
        } else {
            $data = ['requests' => 1, 'reset_time' => time() + $timeWindow];
        }
    } else {
        $data = ['requests' => 1, 'reset_time' => time() + $timeWindow];
    }
    
    file_put_contents($cacheFile, json_encode($data));
    return true;
}

/**
 * Validate MAC address format
 */
function validateMacAddress($macAddress) {
    return preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $macAddress);
}

/**
 * Validate IP address format  
 */
function validateIPAddress($ipAddress) {
    return filter_var($ipAddress, FILTER_VALIDATE_IP) !== false;
}

/**
 * Simplified component access check for basic login system
 * This version works without ACL tables for basic functionality
 */
function basicRequireAuth($pdo) {
    session_start();
    if (!isset($_SESSION['id'])) {
        if (isAjaxRequest()) {
            send_json_response(0, 0, 401, "Authentication required");
        } else {
            header("Location: /bdc_ims/api/login/login.php");
            exit;
        }
    }
    
    $user = isUserLoggedIn($pdo);
    if (!$user) {
        if (isAjaxRequest()) {
            send_json_response(0, 0, 401, "Session expired");
        } else {
            header("Location: /bdc_ims/api/login/login.php");
            exit;
        }
    }
    
    return $user;
}

/**
 * Check if ACL system is available
 */
function isACLAvailable($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'roles'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Generate UUID v4
 */
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

?>