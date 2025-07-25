<?php
/**
 * Complete BaseFunctions.php with JWT Authentication and ACL System
 * File: includes/BaseFunctions.php
 */

// Include JWT Helper and ACL classes
require_once(__DIR__ . '/JWTHelper.php');
require_once(__DIR__ . '/ACL.php');

// Initialize JWT secret
$jwtSecret = getenv('JWT_SECRET') ?: 'bdc-ims-jwt-secret-key-change-in-production-2025-xyz';
JWTHelper::init($jwtSecret);

/**
 * Generate UUID v4
 */
if (!function_exists('generateUUID')) {
    function generateUUID() {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

/**
 * Send JSON response with proper error handling
 */
if (!function_exists('send_json_response')) {
    function send_json_response($success, $authenticated, $code, $message, $data = null) {
        // Clean any output buffer
        if (ob_get_level()) {
            ob_clean();
        }
        
        http_response_code($code);
        header('Content-Type: application/json');
        
        $response = [
            'success' => (bool)$success,
            'authenticated' => (bool)$authenticated,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'code' => $code
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit();
    }
}

/**
 * Safe session start (kept for backward compatibility)
 */
if (!function_exists('safeSessionStart')) {
    function safeSessionStart() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

/**
 * JWT Authentication - Get authenticated user from JWT token
 */
if (!function_exists('authenticateWithJWT')) {
    function authenticateWithJWT($pdo) {
        try {
            $token = JWTHelper::getTokenFromHeader();
            
            if (!$token) {
                return false;
            }
            
            $payload = JWTHelper::verifyToken($token);
            
            // Get user from database
            $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname FROM users WHERE id = ?");
            $stmt->execute([$payload['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return false;
            }
            
            // Update last activity
            $stmt = $pdo->prepare("UPDATE auth_tokens SET last_used_at = NOW() WHERE user_id = ?");
            $stmt->execute([$user['id']]);
            
            return $user;
        } catch (Exception $e) {
            error_log("JWT Authentication failed: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Session-based authentication check (deprecated - use JWT)
 */
if (!function_exists('isUserLoggedIn')) {
    function isUserLoggedIn($pdo) {
        safeSessionStart();
        
        if (!isset($_SESSION['id'])) {
            return false;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname FROM users WHERE id = ?");
            $stmt->execute([$_SESSION["id"]]);
            $user = $stmt->fetch();
            
            if (!$user) {
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
 * Hybrid authentication - Try JWT first, then session (for transition period)
 */
if (!function_exists('authenticateUser')) {
    function authenticateUser($pdo) {
        // Try JWT authentication first
        $user = authenticateWithJWT($pdo);
        
        if ($user) {
            return $user;
        }
        
        // Fallback to session authentication for backward compatibility
        return isUserLoggedIn($pdo);
    }
}

/**
 * Require authentication (JWT or session)
 */
if (!function_exists('requireLogin')) {
    function requireLogin($pdo) {
        $user = authenticateUser($pdo);
        
        if (!$user) {
            send_json_response(0, 0, 401, "Authentication required - please login");
        }
        
        return $user;
    }
}

/**
 * User login with JWT token generation
 */
if (!function_exists('loginUser')) {
    function loginUser($pdo, $username, $password) {
        try {
            error_log("Authentication attempt for: $username");
            
            $stmt = $pdo->prepare("SELECT id, username, email, password, firstname, lastname FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                error_log("User not found: $username");
                return false;
            }
            
            error_log("User found: " . $user['username'] . " (ID: " . $user['id'] . ")");
            
            if (password_verify($password, $user['password'])) {
                error_log("Authentication successful for user: $username");
                
                // Generate JWT tokens
                $tokenPayload = [
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email']
                ];
                
                $accessToken = JWTHelper::generateToken($tokenPayload, 3600); // 1 hour
                $refreshToken = JWTHelper::generateRefreshToken();
                
                // Store refresh token
                JWTHelper::storeRefreshToken($pdo, $user['id'], $refreshToken, 2592000); // 30 days
                
                // Get user role and permissions
                $acl = new ACL($pdo);
                $userRoles = $acl->getUserRoles($user['id']);
                
                // Auto-assign default role if user has no roles
                if (empty($userRoles)) {
                    $defaultRoleId = $acl->getDefaultRoleId();
                    if ($defaultRoleId) {
                        $acl->assignRole($user['id'], $defaultRoleId);
                        $userRoles = $acl->getUserRoles($user['id']);
                    }
                }
                
                $primaryRole = !empty($userRoles) ? $userRoles[0]['name'] : 'viewer';
                
                // Log successful login
                logActivity($pdo, $user['id'], 'User login', 'auth', $user['id']);
                
                return [
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'firstname' => $user['firstname'],
                        'lastname' => $user['lastname'],
                        'primary_role' => $primaryRole,
                        'roles' => $userRoles
                    ],
                    'tokens' => [
                        'access_token' => $accessToken,
                        'refresh_token' => $refreshToken,
                        'token_type' => 'Bearer',
                        'expires_in' => 3600
                    ]
                ];
            } else {
                error_log("Password verification failed for user: $username");
                return false;
            }
        } catch (Exception $e) {
            error_log("Authentication error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Refresh JWT token
 */
if (!function_exists('refreshJWTToken')) {
    function refreshJWTToken($pdo, $refreshToken) {
        try {
            $user = JWTHelper::verifyRefreshToken($pdo, $refreshToken);
            
            if (!$user) {
                return false;
            }
            
            // Generate new access token
            $tokenPayload = [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'email' => $user['email']
            ];
            
            $newAccessToken = JWTHelper::generateToken($tokenPayload, 3600);
            $newRefreshToken = JWTHelper::generateRefreshToken();
            
            // Update refresh token in database
            JWTHelper::storeRefreshToken($pdo, $user['user_id'], $newRefreshToken, 2592000);
            
            return [
                'user' => [
                    'id' => $user['user_id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'firstname' => $user['firstname'],
                    'lastname' => $user['lastname']
                ],
                'tokens' => [
                    'access_token' => $newAccessToken,
                    'refresh_token' => $newRefreshToken,
                    'token_type' => 'Bearer',
                    'expires_in' => 3600
                ]
            ];
        } catch (Exception $e) {
            error_log("Token refresh error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Check permission using ACL system
 */
if (!function_exists('hasPermission')) {
    function hasPermission($pdo, $permission, $userId = null) {
        if (!$userId) {
            $user = authenticateUser($pdo);
            $userId = $user ? $user['id'] : null;
        }
        
        if (!$userId) {
            return false;
        }
        
        try {
            $acl = new ACL($pdo);
            return $acl->hasPermission($userId, $permission);
        } catch (Exception $e) {
            error_log("Error checking permission: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Require specific permission
 */
if (!function_exists('requirePermission')) {
    function requirePermission($pdo, $permission, $userId = null) {
        if (!hasPermission($pdo, $permission, $userId)) {
            send_json_response(0, 1, 403, "Insufficient permissions: $permission required");
        }
    }
}

/**
 * Check if user has specific role
 */
if (!function_exists('hasRole')) {
    function hasRole($pdo, $role, $userId = null) {
        if (!$userId) {
            $user = authenticateUser($pdo);
            $userId = $user ? $user['id'] : null;
        }
        
        if (!$userId) {
            return false;
        }
        
        try {
            $acl = new ACL($pdo);
            return $acl->hasRole($userId, $role);
        } catch (Exception $e) {
            error_log("Error checking role: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Require specific role
 */
if (!function_exists('requireRole')) {
    function requireRole($pdo, $role, $userId = null) {
        if (!hasRole($pdo, $role, $userId)) {
            send_json_response(0, 1, 403, "Insufficient permissions: $role role required");
        }
    }
}

/**
 * Password hashing
 */
if (!function_exists('hashPassword')) {
    function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

/**
 * Password verification
 */
if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

/**
 * Create new user with automatic role assignment
 */
if (!function_exists('createUser')) {
    function createUser($pdo, $username, $email, $password, $firstname = null, $lastname = null, $roleId = null) {
        try {
            $pdo->beginTransaction();
            
            $passwordHash = hashPassword($password);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, firstname, lastname, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            
            if ($stmt->execute([$username, $email, $passwordHash, $firstname, $lastname])) {
                $userId = $pdo->lastInsertId();
                
                // Assign role
                $acl = new ACL($pdo);
                if ($roleId) {
                    $acl->assignRole($userId, $roleId);
                } else {
                    // Assign default role
                    $defaultRoleId = $acl->getDefaultRoleId();
                    if ($defaultRoleId) {
                        $acl->assignRole($userId, $defaultRoleId);
                    }
                }
                
                $pdo->commit();
                return $userId;
            }
            
            $pdo->rollBack();
            return false;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Error creating user: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Enhanced activity logging with ACL integration
 */
if (!function_exists('logActivity')) {
    function logActivity($pdo, $userId, $action, $componentType = null, $componentId = null, $details = null, $oldData = null, $newData = null) {
        try {
            // Ensure inventory_log table exists
            $stmt = $pdo->prepare("
                CREATE TABLE IF NOT EXISTS inventory_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED,
                    component_type VARCHAR(50),
                    component_id INT,
                    action VARCHAR(100),
                    old_data JSON,
                    new_data JSON,
                    notes TEXT,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                )
            ");
            $stmt->execute();
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $stmt = $pdo->prepare("
                INSERT INTO inventory_log (user_id, component_type, component_id, action, old_data, new_data, notes, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $userId, 
                $componentType ?: 'system', 
                $componentId, 
                $action,
                $oldData ? json_encode($oldData) : null,
                $newData ? json_encode($newData) : null,
                $details, 
                $ipAddress,
                $userAgent
            ]);
        } catch (PDOException $e) {
            error_log("Error logging activity: " . $e->getMessage());
        }
    }
}

/**
 * Initialize ACL system with default roles and permissions
 */
if (!function_exists('initializeACLSystem')) {
    function initializeACLSystem($pdo) {
        try {
            $acl = new ACL($pdo);
            
            // Create tables if they don't exist
            $acl->createTables();
            
            // Initialize default permissions and roles
            $acl->initializeDefaultPermissions();
            $acl->initializeDefaultRoles();
            
            return true;
        } catch (Exception $e) {
            error_log("Error initializing ACL system: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get component status text
 */
if (!function_exists('getStatusText')) {
    function getStatusText($status) {
        switch ($status) {
            case 0: return 'Failed/Decommissioned';
            case 1: return 'Available';
            case 2: return 'In Use';
            default: return 'Unknown';
        }
    }
}

/**
 * Get component status color
 */
if (!function_exists('getStatusColor')) {
    function getStatusColor($status) {
        switch ($status) {
            case 0: return 'danger';
            case 1: return 'success';
            case 2: return 'warning';
            default: return 'secondary';
        }
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
 * Get user info by ID
 */
if (!function_exists('getUserInfo')) {
    function getUserInfo($pdo, $userId) {
        try {
            $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, created_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if ($user) {
                // Add role information
                $acl = new ACL($pdo);
                $user['roles'] = $acl->getUserRoles($userId);
            }
            
            return $user;
        } catch (PDOException $e) {
            error_log("Error getting user info: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get system statistics with permission-aware data
 */
if (!function_exists('getSystemStats')) {
    function getSystemStats($pdo) {
        $stats = [];
        
        try {
            $componentTypes = [
                'cpu' => 'cpuinventory',
                'ram' => 'raminventory',
                'storage' => 'storageinventory',
                'motherboard' => 'motherboardinventory',
                'nic' => 'nicinventory',
                'caddy' => 'caddyinventory'
            ];
            
            foreach ($componentTypes as $type => $table) {
                // Check if user has permission to view this component type
                $canView = hasPermission($pdo, "$type.view");
                
                if ($canView) {
                    $stmt = $pdo->prepare("SELECT Status, COUNT(*) as count FROM $table GROUP BY Status");
                    $stmt->execute();
                    
                    $statusCounts = [];
                    while ($row = $stmt->fetch()) {
                        $statusCounts[$row['Status']] = $row['count'];
                    }
                    
                    $stats[$type] = [
                        'total' => array_sum($statusCounts),
                        'available' => $statusCounts[1] ?? 0,
                        'in_use' => $statusCounts[2] ?? 0,
                        'failed' => $statusCounts[0] ?? 0
                    ];
                } else {
                    $stats[$type] = [
                        'total' => 0,
                        'available' => 0,
                        'in_use' => 0,
                        'failed' => 0
                    ];
                }
                
                // Add permission information
                $stats[$type]['permissions'] = [
                    'can_view' => hasPermission($pdo, "$type.view"),
                    'can_create' => hasPermission($pdo, "$type.create"),
                    'can_edit' => hasPermission($pdo, "$type.edit"),
                    'can_delete' => hasPermission($pdo, "$type.delete")
                ];
            }
            
            // Add user statistics (admin only)
            if (hasPermission($pdo, 'users.view')) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM users");
                $stats['users'] = ['total' => $stmt->fetchColumn()];
            } else {
                $stats['users'] = ['total' => 0];
            }
            
        } catch (PDOException $e) {
            error_log("Error getting system stats: " . $e->getMessage());
        }
        
        return $stats;
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
 * Generate secure random token
 */
if (!function_exists('generateSecureToken')) {
    function generateSecureToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
}

/**
 * Logout user and invalidate tokens
 */
if (!function_exists('logoutUser')) {
    function logoutUser($pdo, $refreshToken = null) {
        try {
            // Get current user
            $user = authenticateUser($pdo);
            
            if ($user) {
                // Invalidate refresh tokens
                if ($refreshToken) {
                    $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE token = ? AND user_id = ?");
                    $stmt->execute([$refreshToken, $user['id']]);
                } else {
                    // Invalidate all tokens for this user
                    $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
                    $stmt->execute([$user['id']]);
                }
                
                // Log logout
                logActivity($pdo, $user['id'], 'User logout', 'auth', $user['id']);
            }
            
            // Destroy session as well
            safeSessionStart();
            session_destroy();
            
            return true;
        } catch (Exception $e) {
            error_log("Error during logout: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Global search with permission filtering
 */
if (!function_exists('performGlobalSearch')) {
    function performGlobalSearch($pdo, $query, $componentType = 'all', $limit = 20) {
        $tableMap = [
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        $results = [];
        $searchTables = [];
        
        if ($componentType === 'all') {
            $searchTables = $tableMap;
        } elseif (isset($tableMap[$componentType])) {
            $searchTables = [$componentType => $tableMap[$componentType]];
        }
        
        foreach ($searchTables as $type => $table) {
            // Check permission before searching
            if (!hasPermission($pdo, "$type.view")) {
                continue;
            }
            
            try {
                $searchQuery = "
                    SELECT 
                        ID,
                        UUID,
                        SerialNumber,
                        Status,
                        Location,
                        RackPosition,
                        Notes,
                        CreatedAt,
                        '$type' as component_type
                    FROM $table 
                    WHERE 
                        SerialNumber LIKE ? 
                        OR UUID LIKE ? 
                        OR Location LIKE ? 
                        OR RackPosition LIKE ? 
                        OR Notes LIKE ?
                    ORDER BY CreatedAt DESC 
                    LIMIT ?
                ";
                
                $stmt = $pdo->prepare($searchQuery);
                $searchTerm = '%' . $query . '%';
                $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $limit]);
                
                $componentResults = $stmt->fetchAll();
                $results = array_merge($results, $componentResults);
            } catch (Exception $e) {
                error_log("Search error for $type: " . $e->getMessage());
            }
        }
        
        return array_slice($results, 0, $limit);
    }
}

/**
 * Get current user from request (JWT or session)
 */
if (!function_exists('getCurrentUser')) {
    function getCurrentUser($pdo) {
        return authenticateUser($pdo);
    }
}
?>