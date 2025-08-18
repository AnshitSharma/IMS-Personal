<?php
/**
 * Complete BaseFunctions.php with JWT Authentication, ACL System, and Server Management
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
 * Authenticate user with username/password
 */
if (!function_exists('authenticateUser')) {
    function authenticateUser($pdo, $username, $password) {
        try {
            error_log("Authentication attempt for: $username");
            
            $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, password FROM users WHERE username = ? AND status = 'active'");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                error_log("User found: " . $user['username'] . " (ID: " . $user['id'] . ")");
                
                if (password_verify($password, $user['password'])) {
                    error_log("Authentication successful for user: " . $user['username']);
                    unset($user['password']); // Remove password from return data
                    return $user;
                } else {
                    error_log("Password verification failed for user: " . $user['username']);
                }
            } else {
                error_log("User not found or inactive: $username");
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Authentication error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Initialize ACL System
 */
if (!function_exists('initializeACLSystem')) {
    function initializeACLSystem($pdo) {
        try {
            // Check if ACL tables exist
            $stmt = $pdo->query("SHOW TABLES LIKE 'acl_permissions'");
            if ($stmt->rowCount() == 0) {
                error_log("ACL tables not found. Please run database migrations.");
                return false;
            }
            
            return true;
        } catch (Exception $e) {
            error_log("ACL initialization error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Check if user has specific permission
 */
if (!function_exists('hasPermission')) {
    function hasPermission($pdo, $permission, $userId) {
        try {
            // Check direct user permissions
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM user_permissions up 
                JOIN acl_permissions ap ON up.permission_id = ap.id 
                WHERE up.user_id = ? AND ap.permission_name = ?
            ");
            $stmt->execute([$userId, $permission]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] > 0) {
                return true;
            }
            
            // Check role-based permissions
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM user_roles ur 
                JOIN role_permissions rp ON ur.role_id = rp.role_id 
                JOIN acl_permissions ap ON rp.permission_id = ap.id 
                WHERE ur.user_id = ? AND ap.permission_name = ?
            ");
            $stmt->execute([$userId, $permission]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['count'] > 0;
        } catch (Exception $e) {
            error_log("Permission check error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get all permissions for a user
 */
if (!function_exists('getUserPermissions')) {
    function getUserPermissions($pdo, $userId) {
        try {
            $permissions = [];
            
            // Get direct permissions
            $stmt = $pdo->prepare("
                SELECT ap.permission_name 
                FROM user_permissions up 
                JOIN acl_permissions ap ON up.permission_id = ap.id 
                WHERE up.user_id = ?
            ");
            $stmt->execute([$userId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $permissions[] = $row['permission_name'];
            }
            
            // Get role-based permissions
            $stmt = $pdo->prepare("
                SELECT ap.permission_name 
                FROM user_roles ur 
                JOIN role_permissions rp ON ur.role_id = rp.role_id 
                JOIN acl_permissions ap ON rp.permission_id = ap.id 
                WHERE ur.user_id = ?
            ");
            $stmt->execute([$userId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $permissions[] = $row['permission_name'];
            }
            
            return array_unique($permissions);
        } catch (Exception $e) {
            error_log("Get user permissions error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Get all roles for a user
 */
if (!function_exists('getUserRoles')) {
    function getUserRoles($pdo, $userId) {
        try {
            $stmt = $pdo->prepare("
                SELECT r.id, r.role_name, r.description 
                FROM user_roles ur 
                JOIN acl_roles r ON ur.role_id = r.id 
                WHERE ur.user_id = ?
            ");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get user roles error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Assign permission to user
 */
if (!function_exists('assignPermissionToUser')) {
    function assignPermissionToUser($pdo, $userId, $permission) {
        try {
            // Get permission ID
            $stmt = $pdo->prepare("SELECT id FROM acl_permissions WHERE permission_name = ?");
            $stmt->execute([$permission]);
            $permissionData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$permissionData) {
                return false; // Permission doesn't exist
            }
            
            // Check if already assigned
            $stmt = $pdo->prepare("SELECT id FROM user_permissions WHERE user_id = ? AND permission_id = ?");
            $stmt->execute([$userId, $permissionData['id']]);
            if ($stmt->fetch()) {
                return true; // Already assigned
            }
            
            // Assign permission
            $stmt = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_id, created_at) VALUES (?, ?, NOW())");
            return $stmt->execute([$userId, $permissionData['id']]);
        } catch (Exception $e) {
            error_log("Assign permission error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Revoke permission from user
 */
if (!function_exists('revokePermissionFromUser')) {
    function revokePermissionFromUser($pdo, $userId, $permission) {
        try {
            $stmt = $pdo->prepare("
                DELETE up FROM user_permissions up 
                JOIN acl_permissions ap ON up.permission_id = ap.id 
                WHERE up.user_id = ? AND ap.permission_name = ?
            ");
            return $stmt->execute([$userId, $permission]);
        } catch (Exception $e) {
            error_log("Revoke permission error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Assign role to user
 */
if (!function_exists('assignRoleToUser')) {
    function assignRoleToUser($pdo, $userId, $roleId) {
        try {
            // Check if already assigned
            $stmt = $pdo->prepare("SELECT id FROM user_roles WHERE user_id = ? AND role_id = ?");
            $stmt->execute([$userId, $roleId]);
            if ($stmt->fetch()) {
                return true; // Already assigned
            }
            
            // Assign role
            $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, ?, NOW())");
            return $stmt->execute([$userId, $roleId]);
        } catch (Exception $e) {
            error_log("Assign role error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Revoke role from user
 */
if (!function_exists('revokeRoleFromUser')) {
    function revokeRoleFromUser($pdo, $userId, $roleId) {
        try {
            $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");
            return $stmt->execute([$userId, $roleId]);
        } catch (Exception $e) {
            error_log("Revoke role error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get all roles
 */
if (!function_exists('getAllRoles')) {
    function getAllRoles($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT id, role_name, description, created_at FROM acl_roles ORDER BY role_name");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get all roles error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Get all permissions
 */
if (!function_exists('getAllPermissions')) {
    function getAllPermissions($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT id, permission_name, description, category FROM acl_permissions ORDER BY category, permission_name");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Get all permissions error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Create new role
 */
if (!function_exists('createRole')) {
    function createRole($pdo, $name, $description = '') {
        try {
            $stmt = $pdo->prepare("INSERT INTO acl_roles (role_name, description, created_at) VALUES (?, ?, NOW())");
            $result = $stmt->execute([$name, $description]);
            
            if ($result) {
                return $pdo->lastInsertId();
            }
            return false;
        } catch (Exception $e) {
            error_log("Create role error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Update role
 */
if (!function_exists('updateRole')) {
    function updateRole($pdo, $roleId, $name, $description = '') {
        try {
            $stmt = $pdo->prepare("UPDATE acl_roles SET role_name = ?, description = ? WHERE id = ?");
            return $stmt->execute([$name, $description, $roleId]);
        } catch (Exception $e) {
            error_log("Update role error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Delete role
 */
if (!function_exists('deleteRole')) {
    function deleteRole($pdo, $roleId) {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Remove role permissions
            $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$roleId]);
            
            // Remove user roles
            $stmt = $pdo->prepare("DELETE FROM user_roles WHERE role_id = ?");
            $stmt->execute([$roleId]);
            
            // Delete role
            $stmt = $pdo->prepare("DELETE FROM acl_roles WHERE id = ?");
            $result = $stmt->execute([$roleId]);
            
            $pdo->commit();
            return $result;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Delete role error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Server Management Functions
 */

/**
 * Check if server system is initialized
 */
if (!function_exists('serverSystemInitialized')) {
    function serverSystemInitialized($pdo) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'server_configurations'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

/**
 * Get system setting
 */
if (!function_exists('getSystemSetting')) {
    function getSystemSetting($pdo, $setting, $default = null) {
        try {
            $stmt = $pdo->prepare("SELECT value FROM system_settings WHERE name = ?");
            $stmt->execute([$setting]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? $result['value'] : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
}

/**
 * Get dashboard data
 */
if (!function_exists('getDashboardData')) {
    function getDashboardData($pdo, $user) {
        $data = [];
        
        try {
            // Get component counts
            $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
            $componentCounts = [];
            
            foreach ($componentTypes as $type) {
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM $type");
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $componentCounts[$type] = (int)$result['count'];
                } catch (Exception $e) {
                    $componentCounts[$type] = 0;
                }
            }
            
            $data['component_counts'] = $componentCounts;
            $data['total_components'] = array_sum($componentCounts);
            
            // Get status breakdown
            $statusCounts = [];
            foreach ($componentTypes as $type) {
                try {
                    $stmt = $pdo->prepare("SELECT Status, COUNT(*) as count FROM $type GROUP BY Status");
                    $stmt->execute();
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($results as $result) {
                        $status = $result['Status'];
                        if (!isset($statusCounts[$status])) {
                            $statusCounts[$status] = 0;
                        }
                        $statusCounts[$status] += (int)$result['count'];
                    }
                } catch (Exception $e) {
                    // Skip this type if table doesn't exist
                    continue;
                }
            }
            
            $data['status_counts'] = $statusCounts;
            
            // Server configurations count
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM server_configurations WHERE created_by = ?");
                $stmt->execute([$user['id']]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                $data['user_configurations'] = (int)$result['count'];
            } catch (Exception $e) {
                $data['user_configurations'] = 0;
            }
            
            // Recent activity placeholder
            $data['recent_activity'] = [];
            
        } catch (Exception $e) {
            error_log("Error getting dashboard data: " . $e->getMessage());
            $data = ['error' => 'Unable to fetch dashboard data'];
        }
        
        return $data;
    }
}

/**
 * Perform global search
 */
if (!function_exists('performGlobalSearch')) {
    function performGlobalSearch($pdo, $query, $limit, $user) {
        $results = [];
        $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
        
        try {
            foreach ($componentTypes as $type) {
                try {
                    $sql = "SELECT *, '$type' as component_type FROM $type WHERE 
                            SerialNumber LIKE ? OR 
                            Notes LIKE ? OR 
                            Location LIKE ? 
                            LIMIT ?";
                    
                    $searchTerm = '%' . $query . '%';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limit]);
                    
                    $typeResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $results = array_merge($results, $typeResults);
                } catch (Exception $e) {
                    // Skip this type if table doesn't exist
                    continue;
                }
            }
            
            // Limit total results
            $results = array_slice($results, 0, $limit);
            
        } catch (Exception $e) {
            error_log("Error performing global search: " . $e->getMessage());
        }
        
        return [
            'query' => $query,
            'results' => $results,
            'total_found' => count($results)
        ];
    }
}

/**
 * Get components by type
 */
if (!function_exists('getComponentsByType')) {
    function getComponentsByType($pdo, $type) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM $type ORDER BY id DESC");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting $type components: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Get component by ID
 */
if (!function_exists('getComponentById')) {
    function getComponentById($pdo, $type, $id) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM $type WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting $type component by ID: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Add component
 */
if (!function_exists('addComponent')) {
    function addComponent($pdo, $type, $data, $userId) {
        try {
            // Generate UUID if not provided
            if (!isset($data['UUID']) || empty($data['UUID'])) {
                $data['UUID'] = generateUUID();
            }
            
            // Prepare column names and values
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');
            $values = array_values($data);
            
            $sql = "INSERT INTO $type (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            error_log("Inserting $type data: " . json_encode($data));
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($values);
            
            if ($result) {
                return $pdo->lastInsertId();
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Error adding $type component: " . $e->getMessage());
            throw $e;
        }
    }
}

/**
 * Update component
 */
if (!function_exists('updateComponent')) {
    function updateComponent($pdo, $type, $id, $data, $userId) {
        try {
            $columns = array_keys($data);
            $setClause = implode(' = ?, ', $columns) . ' = ?';
            $values = array_values($data);
            $values[] = $id; // Add ID for WHERE clause
            
            $sql = "UPDATE $type SET $setClause WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            return $stmt->execute($values);
            
        } catch (Exception $e) {
            error_log("Error updating $type component: " . $e->getMessage());
            throw $e;
        }
    }
}

/**
 * Delete component
 */
if (!function_exists('deleteComponent')) {
    function deleteComponent($pdo, $type, $id, $userId) {
        try {
            $stmt = $pdo->prepare("DELETE FROM $type WHERE id = ?");
            return $stmt->execute([$id]);
            
        } catch (Exception $e) {
            error_log("Error deleting $type component: " . $e->getMessage());
            throw $e;
        }
    }
}

/**
 * User Management Functions
 */

/**
 * Get all users
 */
if (!function_exists('getAllUsers')) {
    function getAllUsers($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, status, created_at FROM users ORDER BY username");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting all users: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Create user
 */
if (!function_exists('createUser')) {
    function createUser($pdo, $username, $email, $password, $firstname, $lastname) {
        try {
            // Check if username/email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                return false; // User already exists
            }
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, password, firstname, lastname, status, created_at) 
                VALUES (?, ?, ?, ?, ?, 'active', NOW())
            ");
            $result = $stmt->execute([$username, $email, $hashedPassword, $firstname, $lastname]);
            
            if ($result) {
                return $pdo->lastInsertId();
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Error creating user: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Update user
 */
if (!function_exists('updateUser')) {
    function updateUser($pdo, $userId, $data) {
        try {
            // Remove password from direct updates for security
            if (isset($data['password'])) {
                $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            
            $columns = array_keys($data);
            $setClause = implode(' = ?, ', $columns) . ' = ?';
            $values = array_values($data);
            $values[] = $userId;
            
            $sql = "UPDATE users SET $setClause WHERE id = ?";
            
            $stmt = $pdo->prepare($sql);
            return $stmt->execute($values);
            
        } catch (Exception $e) {
            error_log("Error updating user: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Delete user
 */
if (!function_exists('deleteUser')) {
    function deleteUser($pdo, $userId) {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Delete user role assignments
            $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Delete user permission assignments
            $stmt = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Delete user auth tokens
            $stmt = $pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Delete user
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $result = $stmt->execute([$userId]);
            
            $pdo->commit();
            return $result;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error deleting user: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get user by ID
 */
if (!function_exists('getUserById')) {
    function getUserById($pdo, $userId) {
        try {
            $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, status, created_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting user by ID: " . $e->getMessage());
            return null;
        }
    }
}

?>