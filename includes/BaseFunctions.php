<?php
/**
 * Base Functions for BDC IMS
 * Enhanced with proper ACL integration
 */

// Include the SimpleACL class
require_once __DIR__ . '/SimpleACL.php';

/**
 * Safe session start to avoid warnings
 */
if (!function_exists('safeSessionStart')) {
    function safeSessionStart() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

/**
 * Enhanced database connection with error handling
 */
if (!function_exists('getDatabaseConnection')) {
    function getDatabaseConnection() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            return new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Check if user is logged in (basic check)
 */
if (!function_exists('isUserLoggedIn')) {
    function isUserLoggedIn($pdo) {
        safeSessionStart();
        
        if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
            return false;
        }
        
        if (!isset($_SESSION["id"])) {
            return false;
        }
        
        // Verify user still exists in database
        try {
            $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname FROM users WHERE id = ?");
            $stmt->execute([$_SESSION["id"]]);
            $user = $stmt->fetch();
            
            if (!$user) {
                // User no longer exists, clear session
                session_destroy();
                return false;
            }
            
            return $user;
        } catch (PDOException $e) {
            error_log("Error checking user login status: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Enhanced session validation with Simple ACL
 */
if (!function_exists('isUserLoggedInWithACL')) {
    function isUserLoggedInWithACL($pdo) {
        $user = isUserLoggedIn($pdo);
        if (!$user) {
            return false;
        }
        
        // Add Simple ACL information to user data
        if (class_exists('SimpleACL')) {
            $acl = new SimpleACL($pdo, $user['id']);
            $user['role'] = $acl->getUserRole();
            $user['permissions'] = $acl->getPermissionsSummary();
            $user['is_admin'] = $acl->isAdmin();
            $user['is_manager'] = $acl->isManagerOrAdmin();
        } else {
            // Fallback if ACL is not available
            $user['role'] = 'viewer';
            $user['permissions'] = [];
            $user['is_admin'] = false;
            $user['is_manager'] = false;
        }
        
        return $user;
    }
}

/**
 * Check if current user has permission
 */
if (!function_exists('hasPermission')) {
    function hasPermission($pdo, $action, $componentType = null) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            return false;
        }
        
        if (!class_exists('SimpleACL')) {
            // Allow basic read access but deny all other actions
            return ($action === 'read');
        }
        
        $acl = new SimpleACL($pdo, $_SESSION['id']);
        return $acl->hasPermission($action, $componentType);
    }
}

/**
 * Require permission or exit with error
 */
if (!function_exists('requirePermission')) {
    function requirePermission($pdo, $action, $componentType = null) {
        if (!hasPermission($pdo, $action, $componentType)) {
            http_response_code(403);
            send_json_response(0, 0, 403, "Access denied. Insufficient permissions.", [
                'required_permission' => $action,
                'component_type' => $componentType
            ]);
            exit;
        }
    }
}

/**
 * Validate component access for API operations
 */
if (!function_exists('validateComponentAccess')) {
    function validateComponentAccess($pdo, $action, $componentType = null, $componentId = null) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            send_json_response(0, 0, 401, "Authentication required");
            exit;
        }
        
        if (!class_exists('SimpleACL')) {
            // If ACL is not available, only allow read operations
            if ($action !== 'read') {
                send_json_response(1, 0, 403, "Access denied. ACL system not available.", [
                    'required_permission' => $action,
                    'component_type' => $componentType
                ]);
                exit;
            }
            return true;
        }
        
        $acl = new SimpleACL($pdo, $_SESSION['id']);
        
        if (!$acl->hasPermission($action, $componentType)) {
            send_json_response(1, 0, 403, "Access denied. Insufficient permissions for this operation.", [
                'required_permission' => $action,
                'component_type' => $componentType,
                'user_role' => $acl->getUserRole()
            ]);
            exit;
        }
        
        return true;
    }
}

/**
 * Check if user has specific role
 */
if (!function_exists('hasRole')) {
    function hasRole($pdo, $roleName) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            return false;
        }
        
        if (!class_exists('SimpleACL')) {
            return false;
        }
        
        $acl = new SimpleACL($pdo, $_SESSION['id']);
        return $acl->hasRole($roleName);
    }
}

/**
 * Check if user is admin
 */
if (!function_exists('isAdmin')) {
    function isAdmin($pdo) {
        return hasRole($pdo, 'admin');
    }
}

/**
 * Check if user is manager or admin
 */
if (!function_exists('isManagerOrAdmin')) {
    function isManagerOrAdmin($pdo) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            return false;
        }
        
        if (!class_exists('SimpleACL')) {
            return false;
        }
        
        $acl = new SimpleACL($pdo, $_SESSION['id']);
        return $acl->isManagerOrAdmin();
    }
}

/**
 * Get current user's Simple ACL instance
 */
if (!function_exists('getCurrentUserACL')) {
    function getCurrentUserACL($pdo) {
        safeSessionStart();
        $userId = $_SESSION['id'] ?? null;
        
        if (!class_exists('SimpleACL') || !$userId) {
            return null;
        }
        
        return new SimpleACL($pdo, $userId);
    }
}

/**
 * Initialize default role for new users
 */
if (!function_exists('initializeUserRole')) {
    function initializeUserRole($pdo, $userId, $defaultRole = 'viewer') {
        if (!class_exists('SimpleACL')) {
            return true; // Skip if not available
        }
        
        try {
            $acl = new SimpleACL($pdo);
            return $acl->initializeUserRole($userId, $defaultRole);
        } catch (Exception $e) {
            error_log("Error initializing user role: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Enhanced JSON response with Simple ACL context
 */
if (!function_exists('send_json_response')) {
    function send_json_response($logged_in, $success, $status_code, $message, $other_params = []) {
        // Only set headers if they haven't been sent
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($status_code);
        }
        
        $resp = [
            'is_logged_in' => $logged_in,
            'status_code' => $status_code,
            'success' => $success,
            'message' => $message,
            'timestamp' => date('c')
        ];
        
        // Add user context if logged in
        if ($logged_in && isset($_SESSION['id']) && class_exists('SimpleACL')) {
            global $pdo;
            try {
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $resp['user_context'] = [
                    'user_id' => $_SESSION['id'],
                    'username' => $_SESSION['username'] ?? null,
                    'role' => $acl->getUserRole(),
                    'is_admin' => $acl->isAdmin(),
                    'is_manager' => $acl->isManagerOrAdmin()
                ];
            } catch (Exception $e) {
                error_log("Error adding user context to response: " . $e->getMessage());
            }
        }
        
        if ($other_params != []) {
            $resp = array_merge($resp, $other_params);
        }
        
        echo json_encode($resp);
        exit();
    }
}

/**
 * Enhanced user creation with Simple ACL
 */
if (!function_exists('createUserWithACL')) {
    function createUserWithACL($pdo, $userData, $role = 'viewer') {
        try {
            $pdo->beginTransaction();
            
            // Create user
            $stmt = $pdo->prepare("
                INSERT INTO users (firstname, lastname, username, email, password)
                VALUES (:firstname, :lastname, :username, :email, :password)
            ");
            
            $stmt->bindParam(':firstname', $userData['firstname']);
            $stmt->bindParam(':lastname', $userData['lastname']);
            $stmt->bindParam(':username', $userData['username']);
            $stmt->bindParam(':email', $userData['email']);
            $stmt->bindParam(':password', $userData['password']);
            
            $stmt->execute();
            $userId = $pdo->lastInsertId();
            
            // Assign role
            if (class_exists('SimpleACL')) {
                $acl = new SimpleACL($pdo);
                $acl->initializeUserRole($userId, $role);
            }
            
            $pdo->commit();
            return $userId;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error creating user with ACL: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Log significant actions for audit trail
 */
if (!function_exists('logAction')) {
    function logAction($pdo, $action, $componentType = null, $componentId = null, $oldValues = null, $newValues = null) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            return;
        }
        
        if (!class_exists('SimpleACL')) {
            return; // Skip logging if ACL not available
        }
        
        try {
            $acl = new SimpleACL($pdo, $_SESSION['id']);
            $acl->logAction($action, $componentType, $componentId, $oldValues, $newValues);
        } catch (Exception $e) {
            error_log("Error logging action: " . $e->getMessage());
        }
    }
}

/**
 * Get user's effective permissions summary
 */
if (!function_exists('getUserPermissionsSummary')) {
    function getUserPermissionsSummary($pdo, $userId = null) {
        if (!$userId) {
            safeSessionStart();
            $userId = $_SESSION['id'] ?? null;
        }
        
        if (!$userId) {
            return null;
        }
        
        if (!class_exists('SimpleACL')) {
            // Return basic permissions if ACL is not available
            return [
                'user_id' => $userId,
                'role' => 'viewer',
                'role_display_name' => 'Viewer/User',
                'permissions' => ['Read', 'Export'],
                'level' => 1,
                'component_access' => [
                    'cpu' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => true],
                    'ram' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => true],
                    'storage' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => true],
                    'motherboard' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => true],
                    'nic' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => true],
                    'caddy' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => true]
                ],
                'system_access' => [
                    'can_manage_users' => false,
                    'can_view_audit_log' => false,
                    'can_manage_roles' => false
                ]
            ];
        }
        
        try {
            $acl = new SimpleACL($pdo, $userId);
            return $acl->getPermissionsSummary();
        } catch (Exception $e) {
            error_log("Error getting user permissions summary: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Get user dashboard data with permission filtering
 */
if (!function_exists('getDashboardDataWithACL')) {
    function getDashboardDataWithACL($pdo) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            return null;
        }
        
        $dashboardData = [];
        $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
        
        if (class_exists('SimpleACL')) {
            $acl = new SimpleACL($pdo, $_SESSION['id']);
            
            foreach ($componentTypes as $type) {
                $dashboardData['components'][$type] = [
                    'can_read' => $acl->hasPermission('read'),
                    'can_create' => $acl->hasPermission('create'),
                    'can_update' => $acl->hasPermission('update'),
                    'can_delete' => $acl->hasPermission('delete'),
                    'can_export' => $acl->hasPermission('export')
                ];
            }
            
            // System permissions
            $dashboardData['system'] = [
                'can_manage_users' => $acl->canManageUsers(),
                'can_view_audit_log' => $acl->isAdmin(),
                'is_admin' => $acl->isAdmin(),
                'is_manager' => $acl->isManagerOrAdmin(),
                'role' => $acl->getUserRole()
            ];
        } else {
            // Fallback permissions if ACL is not available
            foreach ($componentTypes as $type) {
                $dashboardData['components'][$type] = [
                    'can_read' => true,
                    'can_create' => false,
                    'can_update' => false,
                    'can_delete' => false,
                    'can_export' => false
                ];
            }
            
            $dashboardData['system'] = [
                'can_manage_users' => false,
                'can_view_audit_log' => false,
                'is_admin' => false,
                'is_manager' => false,
                'role' => 'viewer'
            ];
        }
        
        return $dashboardData;
    }
}

/**
 * Password hashing wrapper
 */
if (!function_exists('hashPassword')) {
    function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

/**
 * Password verification wrapper
 */
if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

/**
 * Generate secure random token
 */
if (!function_exists('generateSecureToken')) {
    function generateSecureToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
}

/**
 * Validate email address
 */
if (!function_exists('isValidEmail')) {
    function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

/**
 * Sanitize input string
 */
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Get client IP address
 */
if (!function_exists('getClientIP')) {
    function getClientIP() {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}

/**
 * Format bytes to human readable
 */
if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

/**
 * Debug logging helper
 */
if (!function_exists('debugLog')) {
    function debugLog($message, $data = null) {
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            $logEntry = date('Y-m-d H:i:s') . " [DEBUG] " . $message;
            if ($data) {
                $logEntry .= " | Data: " . json_encode($data);
            }
            error_log($logEntry);
        }
    }
}

/**
 * Rate limiting helper
 */
if (!function_exists('checkRateLimit')) {
    function checkRateLimit($key, $maxAttempts = 10, $timeWindow = 3600) {
        safeSessionStart();
        
        $rateLimitKey = 'rate_limit_' . $key;
        $attempts = $_SESSION[$rateLimitKey] ?? [];
        $now = time();
        
        // Remove old attempts outside the time window
        $attempts = array_filter($attempts, function($timestamp) use ($now, $timeWindow) {
            return ($now - $timestamp) < $timeWindow;
        });
        
        if (count($attempts) >= $maxAttempts) {
            return false;
        }
        
        $attempts[] = $now;
        $_SESSION[$rateLimitKey] = $attempts;
        
        return true;
    }
}

/**
 * Clear rate limit for a key
 */
if (!function_exists('clearRateLimit')) {
    function clearRateLimit($key) {
        safeSessionStart();
        $rateLimitKey = 'rate_limit_' . $key;
        unset($_SESSION[$rateLimitKey]);
    }
}

/**
 * Validate CSRF token
 */
if (!function_exists('validateCSRFToken')) {
    function validateCSRFToken($token) {
        safeSessionStart();
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

/**
 * Generate CSRF token
 */
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        safeSessionStart();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = generateSecureToken();
        }
        return $_SESSION['csrf_token'];
    }
}
?>