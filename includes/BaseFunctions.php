<?php
/**
 * Updated BaseFunctions.php with JWT Support - FIXED VERSION
 * This file now includes both session-based and JWT-based authentication functions
 * for backward compatibility during migration
 */

// Include JWT components only if they exist
if (file_exists(__DIR__ . '/JWTHelper.php')) {
    require_once __DIR__ . '/JWTHelper.php';
}

if (file_exists(__DIR__ . '/JWTAuthFunctions.php')) {
    require_once __DIR__ . '/JWTAuthFunctions.php';
}

if (file_exists(__DIR__ . '/SimpleACL.php')) {
    require_once __DIR__ . '/SimpleACL.php';
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
 * Send JSON response with consistent format
 */
if (!function_exists('send_json_response')) {
    function send_json_response($success, $status, $http_code, $message, $data = null) {
        // Prevent any previous output
        if (ob_get_level()) {
            ob_clean();
        }
        
        // Set headers
        header('Content-Type: application/json', true, $http_code);
        
        $response = [
            'success' => (bool)$success,
            'status' => (int)$status,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'http_code' => $http_code
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }
}

/**
 * Universal authentication function
 * Tries JWT first, then falls back to session-based auth
 */
if (!function_exists('authenticateUser')) {
    function authenticateUser($pdo) {
        // Try JWT authentication first if JWT functions are available
        if (function_exists('authenticateWithJWT')) {
            $jwtUser = authenticateWithJWT($pdo);
            if ($jwtUser) {
                return $jwtUser;
            }
        }
        
        // Fall back to session-based authentication
        return isUserLoggedIn($pdo);
    }
}

/**
 * Universal authentication with ACL
 */
if (!function_exists('authenticateUserWithACL')) {
    function authenticateUserWithACL($pdo) {
        // Try JWT authentication first if JWT functions are available
        if (function_exists('authenticateWithJWTAndACL')) {
            $jwtUser = authenticateWithJWTAndACL($pdo);
            if ($jwtUser) {
                return $jwtUser;
            }
        }
        
        // Fall back to session-based authentication
        return isUserLoggedInWithACL($pdo);
    }
}

/**
 * Universal permission check
 */
if (!function_exists('checkUserPermission')) {
    function checkUserPermission($pdo, $action, $componentType = null) {
        // Try JWT permission check first if JWT functions are available
        if (function_exists('hasJWTPermission') && class_exists('JWTHelper')) {
            $token = JWTHelper::extractTokenFromHeader();
            if ($token) {
                return hasJWTPermission($pdo, $action, $componentType);
            }
        }
        
        // Fall back to session-based permission check
        return hasPermission($pdo, $action, $componentType);
    }
}

/**
 * Universal permission requirement
 */
if (!function_exists('requireUserPermission')) {
    function requireUserPermission($pdo, $action, $componentType = null) {
        // Try JWT permission check first if JWT functions are available
        if (function_exists('requireJWTPermission') && class_exists('JWTHelper')) {
            $token = JWTHelper::extractTokenFromHeader();
            if ($token) {
                requireJWTPermission($pdo, $action, $componentType);
                return;
            }
        }
        
        // Fall back to session-based permission check
        requirePermission($pdo, $action, $componentType);
    }
}

/**
 * Get current user ID (works with both JWT and sessions)
 */
if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId($pdo = null) {
        // Try JWT first if available
        if (function_exists('getUserIdFromJWT')) {
            $userId = getUserIdFromJWT();
            if ($userId) {
                return $userId;
            }
        }
        
        // Fall back to session
        safeSessionStart();
        return $_SESSION['id'] ?? null;
    }
}

/**
 * Legacy session-based functions (kept for backward compatibility)
 */

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
 * Check if user is logged in (session-based - legacy)
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
 * Enhanced session validation with Simple ACL (legacy)
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
 * Check if current user has permission (session-based - legacy)
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
 * Require permission or exit with error (session-based - legacy)
 */
if (!function_exists('requirePermission')) {
    function requirePermission($pdo, $action, $componentType = null) {
        if (!hasPermission($pdo, $action, $componentType)) {
            http_response_code(403);
            send_json_response(0, 0, 403, "Access denied. Insufficient permissions.");
            exit();
        }
    }
}

/**
 * Get user dashboard data with permission filtering
 */
if (!function_exists('getDashboardDataWithACL')) {
    function getDashboardDataWithACL($pdo) {
        $user = authenticateUserWithACL($pdo);
        if (!$user) {
            return null;
        }
        
        $dashboardData = [];
        $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
        
        if (class_exists('SimpleACL')) {
            $acl = new SimpleACL($pdo, $user['id']);
            
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
 * Clean and validate input data
 */
if (!function_exists('cleanInputData')) {
    function cleanInputData($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = cleanInputData($value);
            }
        } else {
            $data = trim($data);
            $data = stripslashes($data);
            $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        }
        return $data;
    }
}

/**
 * Validate email format
 */
if (!function_exists('isValidEmail')) {
    function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

/**
 * Generate secure random string
 */
if (!function_exists('generateRandomString')) {
    function generateRandomString($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
}

/**
 * Log API actions
 */
if (!function_exists('logAPIAction')) {
    function logAPIAction($pdo, $action, $componentType = null, $componentId = null, $oldValues = null, $newValues = null) {
        $userId = getCurrentUserId($pdo);
        if (!$userId) {
            return false;
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO simple_audit_log 
                (user_id, action, component_type, component_id, old_values, new_values, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            return $stmt->execute([
                $userId,
                $action,
                $componentType,
                $componentId,
                $oldValues ? json_encode($oldValues) : null,
                $newValues ? json_encode($newValues) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (PDOException $e) {
            error_log("Failed to log API action: " . $e->getMessage());
            return false;
        }
    }
}