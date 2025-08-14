<?php
/**
 * Fixed BaseFunctions.php with proper authentication
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
            
            // Get user from database - FIXED: Use correct column names
            $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, status FROM users WHERE id = ?");
            $stmt->execute([$payload['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                return false;
            }
            
            // Check if user is active
            if (isset($user['status']) && $user['status'] !== 'active') {
                return false;
            }
            
            // Update last activity if auth_tokens table exists
            try {
                $stmt = $pdo->prepare("UPDATE auth_tokens SET last_used_at = NOW() WHERE user_id = ?");
                $stmt->execute([$user['id']]);
            } catch (Exception $e) {
                // Ignore if auth_tokens table doesn't exist
            }
            
            return $user;
        } catch (Exception $e) {
            error_log("JWT Authentication failed: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Authenticate user with username/password - FIXED VERSION
 */
if (!function_exists('authenticateUser')) {
    function authenticateUser($pdo, $username, $password) {
        try {
            error_log("Authentication attempt for: $username");
            
            // FIXED: Use correct column names
            $stmt = $pdo->prepare("SELECT id, username, email, firstname, lastname, password, status FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                error_log("User not found: $username");
                return false;
            }
            
            error_log("User found: " . $user['username'] . " (ID: " . $user['id'] . ")");
            
            // Check if user is active (with fallback if status column doesn't exist)
            if (isset($user['status']) && $user['status'] !== 'active') {
                error_log("User account is inactive: $username");
                return false;
            }
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                error_log("Authentication successful for user: $username");
                unset($user['password']); // Remove password from return data
                return $user;
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
 * Initialize ACL System
 */
if (!function_exists('initializeACLSystem')) {
    function initializeACLSystem($pdo) {
        try {
            // Check if ACL tables exist
            $stmt = $pdo->query("SHOW TABLES LIKE 'permissions'");
            if ($stmt->rowCount() == 0) {
                // Create basic ACL tables if they don't exist
                createBasicACLTables($pdo);
            }
            
            // Ensure admin user exists
            ensureAdminUserExists($pdo);
            
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
            $acl = new ACL($pdo);
            return $acl->hasPermission($userId, $permission);
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
            $acl = new ACL($pdo);
            return $acl->getUserPermissions($userId);
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
            $acl = new ACL($pdo);
            return $acl->getUserRoles($userId);
        } catch (Exception $e) {
            error_log("Get user roles error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Assign role to user
 */
if (!function_exists('assignRoleToUser')) {
    function assignRoleToUser($pdo, $userId, $roleId) {
        try {
            $acl = new ACL($pdo);
            return $acl->assignRole($userId, $roleId);
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
            $acl = new ACL($pdo);
            return $acl->removeRole($userId, $roleId);
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
            $acl = new ACL($pdo);
            return $acl->getAllRoles();
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
            $acl = new ACL($pdo);
            return $acl->getAllPermissions();
        } catch (Exception $e) {
            error_log("Get all permissions error: " . $e->getMessage());
            return [];
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
            $componentTypes = ['cpuinventory', 'raminventory', 'storageinventory', 'motherboardinventory', 'nicinventory', 'caddyinventory'];
            $componentCounts = [];
            
            foreach ($componentTypes as $type) {
                try {
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM $type");
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $componentCounts[str_replace('inventory', '', $type)] = (int)$result['count'];
                } catch (Exception $e) {
                    $componentCounts[str_replace('inventory', '', $type)] = 0;
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
        $componentTypes = ['cpuinventory', 'raminventory', 'storageinventory', 'motherboardinventory', 'nicinventory', 'caddyinventory'];
        
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
            // Map component types to actual table names
            $tableMap = [
                'cpu' => 'cpuinventory',
                'ram' => 'raminventory',
                'storage' => 'storageinventory',
                'motherboard' => 'motherboardinventory',
                'nic' => 'nicinventory',
                'caddy' => 'caddyinventory'
            ];
            
            $tableName = $tableMap[$type] ?? $type;
            
            $stmt = $pdo->prepare("SELECT * FROM $tableName ORDER BY ID DESC");
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
            // Map component types to actual table names
            $tableMap = [
                'cpu' => 'cpuinventory',
                'ram' => 'raminventory',
                'storage' => 'storageinventory',
                'motherboard' => 'motherboardinventory',
                'nic' => 'nicinventory',
                'caddy' => 'caddyinventory'
            ];
            
            $tableName = $tableMap[$type] ?? $type;
            
            $stmt = $pdo->prepare("SELECT * FROM $tableName WHERE ID = ?");
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
            // Map component types to actual table names
            $tableMap = [
                'cpu' => 'cpuinventory',
                'ram' => 'raminventory',
                'storage' => 'storageinventory',
                'motherboard' => 'motherboardinventory',
                'nic' => 'nicinventory',
                'caddy' => 'caddyinventory'
            ];
            
            $tableName = $tableMap[$type] ?? $type;
            
            // Generate UUID if not provided
            if (!isset($data['UUID']) || empty($data['UUID'])) {
                $data['UUID'] = generateUUID();
            }
            
            // Prepare column names and values
            $columns = array_keys($data);
            $placeholders = array_fill(0, count($columns), '?');
            $values = array_values($data);
            
            $sql = "INSERT INTO $tableName (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            error_log("Inserting $type data into $tableName: " . json_encode($data));
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($values);
            
            if ($result) {
                $insertId = $pdo->lastInsertId();
                
                // Log the action
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO inventory_log (user_id, component_type, component_id, action, new_data, notes, ip_address, user_agent, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $userId,
                        $type,
                        $insertId,
                        'Component created',
                        json_encode($data),
                        'Created new ' . $type . ' component',
                        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                    ]);
                } catch (Exception $e) {
                    // Ignore logging errors
                    error_log("Error logging component creation: " . $e->getMessage());
                }
                
                return $insertId;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Error adding $type component: " . $e->getMessage());
            throw $e;
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
 * Create basic ACL tables if they don't exist
 */
if (!function_exists('createBasicACLTables')) {
    function createBasicACLTables($pdo) {
        // This function is kept for backward compatibility
        // The actual ACL tables already exist in your database
        return true;
    }
}

/**
 * Auto-create admin user if no admin users exist
 */
if (!function_exists('ensureAdminUserExists')) {
    function ensureAdminUserExists($pdo) {
        try {
            // Check if any admin users exist
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM users u 
                JOIN user_roles ur ON u.id = ur.user_id 
                JOIN roles r ON ur.role_id = r.id 
                WHERE r.name IN ('super_admin', 'admin')
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['count'] == 0) {
                // No admin users exist, create default admin
                $adminPassword = 'password'; // Default password
                $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("
                    INSERT INTO users (firstname, lastname, username, password, email, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    'System',
                    'Administrator',
                    'admin',
                    $hashedPassword,
                    'admin@system.com',
                    'active'
                ]);
                
                $userId = $pdo->lastInsertId();
                
                // Assign super_admin role
                $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'super_admin' LIMIT 1");
                $stmt->execute();
                $role = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($role) {
                    $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, assigned_at) VALUES (?, ?, NOW())");
                    $stmt->execute([$userId, $role['id']]);
                }
                
                error_log("Created default admin user (username: admin, password: $adminPassword)");
            }
        } catch (Exception $e) {
            error_log("Error ensuring admin user exists: " . $e->getMessage());
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
                $userId = $pdo->lastInsertId();
                
                // Assign default role (viewer role)
                $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'viewer' LIMIT 1");
                $stmt->execute();
                $role = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($role) {
                    assignRoleToUser($pdo, $userId, $role['id']);
                }
                
                return $userId;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Error creating user: " . $e->getMessage());
            return false;
        }
    }
}

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

// Auto-initialize system on first load
try {
    if (isset($pdo)) {
        initializeACLSystem($pdo);
    }
} catch (Exception $e) {
    error_log("Error in auto-initialization: " . $e->getMessage());
}

?>
