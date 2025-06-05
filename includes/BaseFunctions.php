<?php

if (!isset($pdo)) {
    require_once(__DIR__ . '/db_config.php');
}

// Include ACL class only if it exists and hasn't been included
if (file_exists(__DIR__ . '/ACL.php') && !class_exists('ACL')) {
    require_once(__DIR__ . '/ACL.php');
}

// Only include config if not already included
if (!defined('MAIN_SITE_URL')) {
    if (file_exists(__DIR__ . '/config.php')) {
        require_once(__DIR__ . '/config.php');
    }
}

/**
 * Safe session start - only starts if not already active
 */
if (!function_exists('safeSessionStart')) {
    function safeSessionStart() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

if (!function_exists('isUserLoggedIn')) {
    function isUserLoggedIn($pdo) {
        // Use safe session start
        safeSessionStart();
        
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
}

/**
 * Enhanced session validation with ACL
 */
if (!function_exists('isUserLoggedInWithACL')) {
    function isUserLoggedInWithACL($pdo) {
        $user = isUserLoggedIn($pdo);
        if (!$user) {
            return false;
        }
        
        // Add ACL information to user data only if ACL class exists
        if (class_exists('ACL')) {
            $acl = new ACL($pdo, $user['id']);
            $user['roles'] = $acl->getUserRoles();
            $user['permissions'] = $acl->getUserPermissions();
            $user['is_super_admin'] = $acl->isSuperAdmin();
        } else {
            // Fallback if ACL is not available
            $user['roles'] = [];
            $user['permissions'] = [];
            $user['is_super_admin'] = false;
        }
        
        return $user;
    }
}

/**
 * Check if current user has permission
 */
if (!function_exists('hasPermission')) {
    function hasPermission($pdo, $resource, $action, $resourceId = null) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            return false;
        }
        
        // If ACL class doesn't exist, return false for all permissions except basic read
        if (!class_exists('ACL')) {
            // Allow basic read access but deny all other actions
            return ($action === 'read');
        }
        
        $acl = new ACL($pdo, $_SESSION['id']);
        return $acl->hasPermission($resource, $action, $resourceId);
    }
}

/**
 * Require permission or exit with error
 */
if (!function_exists('requirePermission')) {
    function requirePermission($pdo, $resource, $action, $resourceId = null) {
        if (!hasPermission($pdo, $resource, $action, $resourceId)) {
            http_response_code(403);
            send_json_response(0, 0, 403, "Access denied. Insufficient permissions.", [
                'required_permission' => $resource . '.' . $action
            ]);
            exit;
        }
    }
}

/**
 * Check if user has any of the specified roles
 */
if (!function_exists('hasAnyRole')) {
    function hasAnyRole($pdo, $roles) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            return false;
        }
        
        if (!class_exists('ACL')) {
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
}

/**
 * Require any of the specified roles
 */
if (!function_exists('requireAnyRole')) {
    function requireAnyRole($pdo, $roles) {
        if (!hasAnyRole($pdo, $roles)) {
            http_response_code(403);
            send_json_response(0, 0, 403, "Access denied. Insufficient role privileges.", [
                'required_roles' => $roles
            ]);
            exit;
        }
    }
}

/**
 * Get current user's ACL instance
 */
if (!function_exists('getCurrentUserACL')) {
    function getCurrentUserACL($pdo) {
        safeSessionStart();
        $userId = $_SESSION['id'] ?? null;
        
        if (!class_exists('ACL') || !$userId) {
            return null;
        }
        
        return new ACL($pdo, $userId);
    }
}

/**
 * Initialize default ACL for new users
 */
if (!function_exists('initializeUserACL')) {
    function initializeUserACL($pdo, $userId, $defaultRole = 'viewer') {
        if (!class_exists('ACL')) {
            return true; // Skip ACL initialization if not available
        }
        
        try {
          $acl = new ACL($pdo);
            return $acl->assignRole($userId, $defaultRole, 1); // Assigned by system admin (user ID 1)
        } catch (Exception $e) {
            error_log("Error initializing user ACL: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Filter components based on user permissions
 */
if (!function_exists('filterComponentsByPermission')) {
    function filterComponentsByPermission($pdo, $components, $action = 'read') {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            return [];
        }
        
        if (!class_exists('ACL')) {
            // If ACL is not available, return all components for read action, empty for others
            return ($action === 'read') ? $components : [];
        }
        
        $acl = new ACL($pdo, $_SESSION['id']);
        
        // If user is super admin, return all components
        if ($acl->isSuperAdmin()) {
            return $components;
        }
        
        $filteredComponents = [];
        foreach ($components as $component) {
            $resourceType = $component['component_type'] ?? 'unknown';
            $resourceId = $component['UUID'] ?? null;
            
            if ($acl->hasPermission($resourceType, $action, $resourceId)) {
                $filteredComponents[] = $component;
            }
        }
        
        return $filteredComponents;
    }
}

/**
 * Enhanced JSON response with ACL context
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
        
        // Add user context if logged in and ACL is available
        if ($logged_in && isset($_SESSION['id']) && class_exists('ACL')) {
            global $pdo;
            try {
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
        
        echo json_encode($resp);
        exit();
    }
}

/**
 * Enhanced user creation with ACL
 */
if (!function_exists('createUserWithACL')) {
    function createUserWithACL($pdo, $userData, $roles = ['viewer']) {
        try {
            $pdo->beginTransaction();
            
            // Create user
            $stmt = $pdo->prepare("
                INSERT INTO users (firstname, lastname, username, email, password, acl)
                VALUES (:firstname, :lastname, :username, :email, :password, 0)
            ");
            
            $stmt->bindParam(':firstname', $userData['firstname']);
            $stmt->bindParam(':lastname', $userData['lastname']);
            $stmt->bindParam(':username', $userData['username']);
            $stmt->bindParam(':email', $userData['email']);
            $stmt->bindParam(':password', $userData['password']);
            
            $stmt->execute();
            $userId = $pdo->lastInsertId();
            
            // Assign roles only if ACL is available
            if (class_exists('ACL')) {
                $acl = new ACL($pdo);
                foreach ($roles as $role) {
                    $acl->assignRole($userId, $role, $_SESSION['id'] ?? 1);
                }
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
 * Get user dashboard data with permission filtering
 */
if (!function_exists('getDashboardDataWithACL')) {
    function getDashboardDataWithACL($pdo) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            return null;
        }
        
        $dashboardData = [];
        
        // Component counts (only for components user can read)
        $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
        $dashboardData['components'] = [];
        
        if (class_exists('ACL')) {
            $acl = new ACL($pdo, $_SESSION['id']);
            
            foreach ($componentTypes as $type) {
                if ($acl->hasPermission($type, 'read')) {
                    $dashboardData['components'][$type] = [
                        'can_read' => true,
                        'can_create' => $acl->hasPermission($type, 'create'),
                        'can_update' => $acl->hasPermission($type, 'update'),
                        'can_delete' => $acl->hasPermission($type, 'delete'),
                        'can_export' => $acl->hasPermission($type, 'export')
                    ];
                } else {
                    $dashboardData['components'][$type] = [
                        'can_read' => false,
                        'can_create' => false,
                        'can_update' => false,
                        'can_delete' => false,
                        'can_export' => false
                    ];
                }
            }
            
            // System permissions
            $dashboardData['system'] = [
                'can_manage_users' => $acl->hasPermission('users', 'read'),
                'can_manage_roles' => $acl->hasPermission('roles', 'read'),
                'can_view_reports' => $acl->hasPermission('reports', 'read'),
                'can_access_settings' => $acl->hasPermission('system', 'settings'),
                'is_admin' => $acl->hasRole('admin') || $acl->hasRole('super_admin')
            ];
        } else {
            // Fallback permissions if ACL is not available - basic read-only access
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
                'can_manage_roles' => false,
                'can_view_reports' => false,
                'can_access_settings' => false,
                'is_admin' => false
            ];
        }
        
        return $dashboardData;
    }
}

/**
 * Cleanup expired ACL entries (should be run periodically)
 */
if (!function_exists('cleanupACL')) {
    function cleanupACL($pdo) {
        if (!class_exists('ACL')) {
            return true; // Skip if ACL not available
        }
        
        try {
            $acl = new ACL($pdo);
            return $acl->cleanupExpired();
        } catch (Exception $e) {
            error_log("Error cleaning up ACL: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Validate component access for API operations
 */
if (!function_exists('validateComponentAccess')) {
    function validateComponentAccess($pdo, $componentType, $action, $componentId = null) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            send_json_response(0, 0, 401, "Authentication required");
            exit;
        }
        
        if (!class_exists('ACL')) {
            // If ACL is not available, only allow read operations
            if ($action !== 'read') {
                send_json_response(1, 0, 403, "Access denied. ACL system not available.", [
                    'required_permission' => $componentType . '.' . $action,
                    'component_type' => $componentType,
                    'action' => $action
                ]);
                exit;
            }
            return true;
        }
        
        $acl = new ACL($pdo, $_SESSION['id']);
        
        // Get component UUID if component ID is provided
        $resourceId = null;
        if ($componentId && in_array($componentType, ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'])) {
            try {
                $tableMap = [
                    'cpu' => 'cpuinventory',
                    'ram' => 'raminventory',
                    'storage' => 'storageinventory',
                    'motherboard' => 'motherboardinventory',
                    'nic' => 'nicinventory',
                    'caddy' => 'caddyinventory'
                ];
                
                if (isset($tableMap[$componentType])) {
                    $stmt = $pdo->prepare("SELECT UUID FROM {$tableMap[$componentType]} WHERE ID = :id");
                    $stmt->bindParam(':id', $componentId, PDO::PARAM_INT);
                    $stmt->execute();
                    $component = $stmt->fetch();
                    if ($component) {
                        $resourceId = $component['UUID'];
                    }
                }
            } catch (Exception $e) {
                error_log("Error getting component UUID: " . $e->getMessage());
            }
        }
        
        if (!$acl->hasPermission($componentType, $action, $resourceId)) {
            send_json_response(1, 0, 403, "Access denied. Insufficient permissions for this operation.", [
                'required_permission' => $componentType . '.' . $action,
                'component_type' => $componentType,
                'action' => $action
            ]);
            exit;
        }
        
        return true;
    }
}

/**
 * Log significant actions for audit trail
 */
if (!function_exists('logAction')) {
    function logAction($pdo, $action, $resourceType = null, $resourceId = null, $details = null) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            return;
        }
        
        try {
            // Check if acl_audit_log table exists, if not skip logging
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'acl_audit_log'");
            $stmt->execute();
            if ($stmt->rowCount() == 0) {
                return; // Table doesn't exist, skip logging
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO acl_audit_log 
                (user_id, action, resource_type, resource_id, result, ip_address, user_agent)
                VALUES (:user_id, :action, :resource_type, :resource_id, 'granted', :ip_address, :user_agent)
            ");
            
            $ipAddress = getClientIPAddress();
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
        
        if (!class_exists('ACL')) {
            // Return basic permissions if ACL is not available
            return [
                'user_id' => $userId,
                'roles' => [],
                'permissions' => [],
                'is_super_admin' => false,
                'component_access' => [
                    'cpu' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => false],
                    'ram' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => false],
                    'storage' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => false],
                    'motherboard' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => false],
                    'nic' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => false],
                    'caddy' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false, 'export' => false]
                ],
                'system_access' => [
                    'users' => ['read' => false, 'create' => false, 'update' => false, 'delete' => false, 'manage_roles' => false],
                    'roles' => ['read' => false, 'create' => false, 'update' => false, 'delete' => false, 'assign_permissions' => false],
                    'system' => ['admin' => false, 'settings' => false, 'backup' => false],
                    'reports' => ['read' => false, 'create' => false, 'export' => false]
                ]
            ];
        }
        
        try {
            $acl = new ACL($pdo, $userId);
            
            return [
                'user_id' => $userId,
                'roles' => $acl->getUserRoles(),
                'permissions' => $acl->getUserPermissions(),
                'is_super_admin' => $acl->isSuperAdmin(),
                'component_access' => [
                    'cpu' => [
                        'read' => $acl->hasPermission('cpu', 'read'),
                        'create' => $acl->hasPermission('cpu', 'create'),
                        'update' => $acl->hasPermission('cpu', 'update'),
                        'delete' => $acl->hasPermission('cpu', 'delete'),
                        'export' => $acl->hasPermission('cpu', 'export')
                    ],
                    'ram' => [
                        'read' => $acl->hasPermission('ram', 'read'),
                        'create' => $acl->hasPermission('ram', 'create'),
                        'update' => $acl->hasPermission('ram', 'update'),
                        'delete' => $acl->hasPermission('ram', 'delete'),
                        'export' => $acl->hasPermission('ram', 'export')
                    ],
                    'storage' => [
                        'read' => $acl->hasPermission('storage', 'read'),
                        'create' => $acl->hasPermission('storage', 'create'),
                        'update' => $acl->hasPermission('storage', 'update'),
                        'delete' => $acl->hasPermission('storage', 'delete'),
                        'export' => $acl->hasPermission('storage', 'export')
                    ],
                    'motherboard' => [
                        'read' => $acl->hasPermission('motherboard', 'read'),
                        'create' => $acl->hasPermission('motherboard', 'create'),
                        'update' => $acl->hasPermission('motherboard', 'update'),
                        'delete' => $acl->hasPermission('motherboard', 'delete'),
                        'export' => $acl->hasPermission('motherboard', 'export')
                    ],
                    'nic' => [
                        'read' => $acl->hasPermission('nic', 'read'),
                        'create' => $acl->hasPermission('nic', 'create'),
                        'update' => $acl->hasPermission('nic', 'update'),
                        'delete' => $acl->hasPermission('nic', 'delete'),
                        'export' => $acl->hasPermission('nic', 'export')
                    ],
                    'caddy' => [
                        'read' => $acl->hasPermission('caddy', 'read'),
                        'create' => $acl->hasPermission('caddy', 'create'),
                        'update' => $acl->hasPermission('caddy', 'update'),
                        'delete' => $acl->hasPermission('caddy', 'delete'),
                        'export' => $acl->hasPermission('caddy', 'export')
                    ]
                ],
                'system_access' => [
                    'users' => [
                        'read' => $acl->hasPermission('users', 'read'),
                        'create' => $acl->hasPermission('users', 'create'),
                        'update' => $acl->hasPermission('users', 'update'),
                        'delete' => $acl->hasPermission('users', 'delete'),
                        'manage_roles' => $acl->hasPermission('users', 'manage_roles')
                    ],
                    'roles' => [
                        'read' => $acl->hasPermission('roles', 'read'),
                        'create' => $acl->hasPermission('roles', 'create'),
                        'update' => $acl->hasPermission('roles', 'update'),
                        'delete' => $acl->hasPermission('roles', 'delete'),
                        'assign_permissions' => $acl->hasPermission('roles', 'assign_permissions')
                    ],
                    'system' => [
                        'admin' => $acl->hasPermission('system', 'admin'),
                        'settings' => $acl->hasPermission('system', 'settings'),
                        'backup' => $acl->hasPermission('system', 'backup')
                    ],
                    'reports' => [
                        'read' => $acl->hasPermission('reports', 'read'),
                        'create' => $acl->hasPermission('reports', 'create'),
                        'export' => $acl->hasPermission('reports', 'export')
                    ]
                ]
            ];
        } catch (Exception $e) {
            error_log("Error getting user permissions summary: " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Check if user can perform bulk operations
 */
if (!function_exists('canPerformBulkOperation')) {
    function canPerformBulkOperation($pdo, $componentType, $action, $componentIds = []) {
        safeSessionStart();
        if (!isset($_SESSION['id'])) {
            return false;
        }
        
        if (!class_exists('ACL')) {
            return ($action === 'read'); // Only allow read operations if ACL not available
        }
        
        $acl = new ACL($pdo, $_SESSION['id']);
        
        // Super admin can do anything
        if ($acl->isSuperAdmin()) {
            return true;
        }
        
        // Check general permission first
        if (!$acl->hasPermission($componentType, $action)) {
            return false;
        }
        
        // If specific component IDs provided, check each one
        if (!empty($componentIds)) {
            foreach ($componentIds as $componentId) {
                // Get component UUID and check specific permission
                // Implementation would be similar to validateComponentAccess
                // For now, we'll assume general permission is sufficient
            }
        }
        
        return true;
    }
}

/**
 * Generate CSRF token
 */
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        safeSessionStart();
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
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
 * Get component table name
 */
if (!function_exists('getComponentTable')) {
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
}

/**
 * Rate limiting helper
 */
if (!function_exists('checkRateLimit')) {
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
}

/**
 * Validate MAC address format
 */
if (!function_exists('validateMacAddress')) {
    function validateMacAddress($mac) {
        return (bool)preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac);
    }
}

/**
 * Validate IP address format
 */
if (!function_exists('validateIPAddress')) {
    function validateIPAddress($ip) {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }
}

/**
 * Get client IP address
 */
if (!function_exists('getClientIPAddress')) {
    function getClientIPAddress() {
        // Check for IP from shared internet
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        // Check for IP passed from proxy
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        // Check for IP from remote address
        else {
            return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
    }
}

/**
 * Generate UUID for components
 */
if (!function_exists('generateComponentUUID')) {
    function generateComponentUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

/**
 * Validate component data
 */
if (!function_exists('validateComponentData')) {
    function validateComponentData($componentType, $data) {
        $errors = [];
        
        // Common validations
        if (empty($data['serial_number'])) {
            $errors[] = "Serial number is required";
        }
        
        if (isset($data['status']) && !in_array($data['status'], ['0', '1', '2'])) {
            $errors[] = "Invalid status value";
        }
        
        if (isset($data['purchase_date']) && !empty($data['purchase_date'])) {
            if (!DateTime::createFromFormat('Y-m-d', $data['purchase_date'])) {
                $errors[] = "Invalid purchase date format";
            }
        }
        
        if (isset($data['warranty_end_date']) && !empty($data['warranty_end_date'])) {
            if (!DateTime::createFromFormat('Y-m-d', $data['warranty_end_date'])) {
                $errors[] = "Invalid warranty end date format";
            }
        }
        
        // Component-specific validations
        if ($componentType === 'nic') {
            if (isset($data['mac_address']) && !empty($data['mac_address'])) {
                if (!validateMacAddress($data['mac_address'])) {
                    $errors[] = "Invalid MAC address format";
                }
            }
            
            if (isset($data['ip_address']) && !empty($data['ip_address'])) {
                if (!validateIPAddress($data['ip_address'])) {
                    $errors[] = "Invalid IP address format";
                }
            }
        }
        
        return $errors;
    }
}

/**
 * Check if request is AJAX
 */
if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }
}

?>