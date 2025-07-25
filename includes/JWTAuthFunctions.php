<?php
/**
 * JWT Authentication Functions - FIXED VERSION
 * Replace session-based functions with JWT equivalents
 * 
 * File: includes/JWTAuthFunctions.php
 */

require_once __DIR__ . '/JWTHelper.php';

/**
 * Authenticate user with JWT
 */
if (!function_exists('authenticateWithJWT')) {
    function authenticateWithJWT($pdo) {
        $token = JWTHelper::extractTokenFromHeader();
        
        if (!$token) {
            return false;
        }

        $payload = JWTHelper::verifyToken($token);
        if (!$payload) {
            return false;
        }

        // Verify user still exists in database
        try {
            $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname FROM users WHERE id = ?");
            $stmt->execute([$payload['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return false;
            }

            // Add token payload data to user
            $user['token_payload'] = $payload;
            return $user;
        } catch (PDOException $e) {
            error_log("Error verifying JWT user: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Authenticate user with JWT and ACL
 */
if (!function_exists('authenticateWithJWTAndACL')) {
    function authenticateWithJWTAndACL($pdo) {
        $user = authenticateWithJWT($pdo);
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
 * Check if current request has valid JWT
 */
if (!function_exists('hasValidJWT')) {
    function hasValidJWT($pdo) {
        return authenticateWithJWT($pdo) !== false;
    }
}

/**
 * Check JWT permission
 */
if (!function_exists('hasJWTPermission')) {
    function hasJWTPermission($pdo, $action, $componentType = null) {
        $user = authenticateWithJWT($pdo);
        if (!$user) {
            return false;
        }
        
        if (!class_exists('SimpleACL')) {
            // Allow basic read access but deny all other actions
            return ($action === 'read');
        }
        
        $acl = new SimpleACL($pdo, $user['id']);
        return $acl->hasPermission($action, $componentType);
    }
}

/**
 * Require JWT permission or exit with error
 */
if (!function_exists('requireJWTPermission')) {
    function requireJWTPermission($pdo, $action, $componentType = null) {
        if (!hasJWTPermission($pdo, $action, $componentType)) {
            http_response_code(403);
            send_json_response(0, 0, 403, "Access denied. Insufficient permissions.");
            exit();
        }
    }
}

/**
 * Require valid JWT or exit with error
 */
if (!function_exists('requireJWTAuth')) {
    function requireJWTAuth($pdo) {
        $user = authenticateWithJWT($pdo);
        if (!$user) {
            http_response_code(401);
            send_json_response(0, 0, 401, "Unauthorized. Valid JWT token required.");
            exit();
        }
        return $user;
    }
}

/**
 * Generate JWT token for user login
 */
if (!function_exists('generateUserJWT')) {
    function generateUserJWT($user, $pdo = null) {
        $payload = [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email']
        ];

        // Add role information if ACL is available
        if ($pdo && class_exists('SimpleACL')) {
            $acl = new SimpleACL($pdo, $user['id']);
            $payload['role'] = $acl->getUserRole();
            $payload['is_admin'] = $acl->isAdmin();
            $payload['is_manager'] = $acl->isManagerOrAdmin();
        }

        return JWTHelper::generateToken($payload);
    }
}

/**
 * Extract user ID from JWT token
 */
if (!function_exists('getUserIdFromJWT')) {
    function getUserIdFromJWT() {
        return JWTHelper::getUserIdFromToken();
    }
}

/**
 * Refresh JWT token - FIXED FUNCTION
 */
if (!function_exists('refreshJWTToken')) {
    function refreshJWTToken() {
        $token = JWTHelper::extractTokenFromHeader();
        if (!$token) {
            return false;
        }
        
        return JWTHelper::refreshToken($token);
    }
}

/**
 * Logout (for JWT, this just means invalidating on client side)
 * Optionally, you can implement a blacklist system
 */
if (!function_exists('logoutJWT')) {
    function logoutJWT() {
        // For JWT, logout is typically handled client-side by removing the token
        // You can implement a blacklist system here if needed
        
        // Log the logout action if ACL is available
        $user_id = getUserIdFromJWT();
        if ($user_id && class_exists('SimpleACL')) {
            global $pdo;
            if ($pdo) {
                $acl = new SimpleACL($pdo, $user_id);
                $acl->logAction("User logout", "auth", $user_id);
            }
        }
        
        return true;
    }
}

/**
 * Validate JWT middleware function
 */
if (!function_exists('validateJWTMiddleware')) {
    function validateJWTMiddleware($pdo) {
        // Check if Authorization header exists
        $token = JWTHelper::extractTokenFromHeader();
        
        if (!$token) {
            http_response_code(401);
            send_json_response(0, 0, 401, "Missing Authorization header with Bearer token");
            exit();
        }

        // Verify token
        $user = authenticateWithJWT($pdo);
        if (!$user) {
            http_response_code(401);
            send_json_response(0, 0, 401, "Invalid or expired JWT token");
            exit();
        }

        return $user;
    }
}