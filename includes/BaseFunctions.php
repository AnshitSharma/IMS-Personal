<?php

if (!isset($pdo)) {
    require_once(__DIR__ . '/db_config.php');
}

// Include Simple ACL class
if (file_exists(__DIR__ . '/SimpleACL.php') && !class_exists('SimpleACL')) {
    require_once(__DIR__ . '/SimpleACL.php');
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

/**
 * Check if user is logged in
 */
if (!function_exists('isUserLoggedIn')) {
    function isUserLoggedIn($pdo) {
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
 * Get component counts with ACL filtering
 */
if (!function_exists('getComponentCountsWithACL')) {
    function getComponentCountsWithACL($pdo, $statusFilter = null) {
        $counts = [
            'cpu' => 0, 'ram' => 0, 'storage' => 0,
            'motherboard' => 0, 'nic' => 0, 'caddy' => 0, 'total' => 0
        ];
        
        $tables = [
            'cpu' => 'cpuinventory', 'ram' => 'raminventory', 'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory', 'nic' => 'nicinventory', 'caddy' => 'caddyinventory'
        ];
        
        // Check if user can read components
        if (!hasPermission($pdo, 'read')) {
            return $counts; // Return zero counts if no read permission
        }
        
        foreach ($tables as $key => $table) {
            try {
                $query = "SELECT COUNT(*) as count FROM $table";
                if ($statusFilter !== null && $statusFilter !== 'all') {
                    $query .= " WHERE Status = :status";
                }
                
                $stmt = $pdo->prepare($query);
                if ($statusFilter !== null && $statusFilter !== 'all') {
                    $stmt->bindParam(':status', $statusFilter, PDO::PARAM_INT);
                }
                $stmt->execute();
                $result = $stmt->fetch();
                $counts[$key] = (int)$result['count'];
                $counts['total'] += (int)$result['count'];
            } catch (PDOException $e) {
                error_log("Error counting $key: " . $e->getMessage());
            }
        }
        
        return $counts;
    }
}

/**
 * Get recent activity with ACL filtering
 */
if (!function_exists('getRecentActivityWithACL')) {
    function getRecentActivityWithACL($pdo, $limit = 10) {
        // Check if user can read components
        if (!hasPermission($pdo, 'read')) {
            return [];
        }
        
        try {
            $activities = [];
            $tables = [
                'cpu' => 'cpuinventory', 'ram' => 'raminventory', 'storage' => 'storageinventory',
                'motherboard' => 'motherboardinventory', 'nic' => 'nicinventory', 'caddy' => 'caddyinventory'
            ];
            
            foreach ($tables as $type => $table) {
                $stmt = $pdo->prepare("
                    SELECT ID, SerialNumber, Status, UpdatedAt, '$type' as component_type
                    FROM $table ORDER BY UpdatedAt DESC LIMIT $limit
                ");
                $stmt->execute();
                $results = $stmt->fetchAll();
                
                foreach ($results as $result) {
                    $activities[] = [
                        'id' => $result['ID'],
                        'component_type' => $result['component_type'],
                        'serial_number' => $result['SerialNumber'],
                        'status' => $result['Status'],
                        'updated_at' => $result['UpdatedAt'],
                        'action' => 'Updated'
                    ];
                }
            }
            
            usort($activities, function($a, $b) {
                return strtotime($b['updated_at']) - strtotime($a['updated_at']);
            });
            
            return array_slice($activities, 0, $limit);
            
        } catch (PDOException $e) {
            error_log("Error getting recent activity: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Get warranty alerts with ACL filtering
 */
if (!function_exists('getWarrantyAlertsWithACL')) {
    function getWarrantyAlertsWithACL($pdo, $days = 90) {
        // Check if user can read components
        if (!hasPermission($pdo, 'read')) {
            return [];
        }
        
        try {
            $alerts = [];
            $tables = [
                'cpu' => 'cpuinventory', 'ram' => 'raminventory', 'storage' => 'storageinventory',
                'motherboard' => 'motherboardinventory', 'nic' => 'nicinventory', 'caddy' => 'caddyinventory'
            ];
            
            $alertDate = date('Y-m-d', strtotime("+$days days"));
            
            foreach ($tables as $type => $table) {
                $stmt = $pdo->prepare("
                    SELECT ID, SerialNumber, WarrantyEndDate, '$type' as component_type
                    FROM $table 
                    WHERE WarrantyEndDate IS NOT NULL 
                    AND WarrantyEndDate <= :alert_date
                    AND Status != 0
                    ORDER BY WarrantyEndDate ASC
                ");
                $stmt->bindParam(':alert_date', $alertDate);
                $stmt->execute();
                $results = $stmt->fetchAll();
                
                foreach ($results as $result) {
                    $daysUntilExpiry = floor((strtotime($result['WarrantyEndDate']) - time()) / (60 * 60 * 24));
                    $alerts[] = [
                        'id' => $result['ID'],
                        'component_type' => $result['component_type'],
                        'serial_number' => $result['SerialNumber'],
                        'warranty_end_date' => $result['WarrantyEndDate'],
                        'days_until_expiry' => $daysUntilExpiry,
                        'severity' => $daysUntilExpiry <= 30 ? 'high' : ($daysUntilExpiry <= 60 ? 'medium' : 'low')
                    ];
                }
            }
            
            return $alerts;
            
        } catch (PDOException $e) {
            error_log("Error getting warranty alerts: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Search components with ACL filtering
 */
if (!function_exists('searchComponentsWithACL')) {
    function searchComponentsWithACL($pdo, $query, $componentType = 'all', $limit = 20) {
        // Check if user can read components
        if (!hasPermission($pdo, 'read')) {
            return [
                'components' => [],
                'total_found' => 0,
                'accessible_count' => 0
            ];
        }
        
        $results = [];
        $totalFound = 0;
        
        $tableMap = [
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory', 
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        $searchTables = [];
        if ($componentType === 'all') {
            $searchTables = $tableMap;
        } elseif (isset($tableMap[$componentType])) {
            $searchTables = [$componentType => $tableMap[$componentType]];
        }
        
        foreach ($searchTables as $type => $table) {
            try {
                $searchQuery = "
                    SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition,
                           PurchaseDate, WarrantyEndDate, Flag, Notes, CreatedAt, UpdatedAt,
                           '$type' as component_type
                    FROM $table 
                    WHERE SerialNumber LIKE :query 
                       OR UUID LIKE :query 
                       OR Location LIKE :query 
                       OR RackPosition LIKE :query 
                       OR Flag LIKE :query 
                       OR Notes LIKE :query
                ";
                
                // Add NIC-specific search fields
                if ($type === 'nic') {
                    $searchQuery = "
                        SELECT ID, UUID, SerialNumber, Status, ServerUUID, Location, RackPosition,
                               MacAddress, IPAddress, NetworkName, PurchaseDate, WarrantyEndDate, 
                               Flag, Notes, CreatedAt, UpdatedAt, '$type' as component_type
                        FROM $table 
                        WHERE SerialNumber LIKE :query 
                           OR UUID LIKE :query 
                           OR Location LIKE :query 
                           OR RackPosition LIKE :query 
                           OR Flag LIKE :query 
                           OR Notes LIKE :query
                           OR MacAddress LIKE :query 
                           OR IPAddress LIKE :query 
                           OR NetworkName LIKE :query
                    ";
                }
                
                $searchQuery .= " ORDER BY CreatedAt DESC LIMIT :limit";
                
                $stmt = $pdo->prepare($searchQuery);
                $searchTerm = '%' . $query . '%';
                $stmt->bindParam(':query', $searchTerm);
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                
                $componentResults = $stmt->fetchAll();
                $totalFound += count($componentResults);
                $results = array_merge($results, $componentResults);
                
            } catch (PDOException $e) {
                error_log("Search error for $type: " . $e->getMessage());
            }
        }
        
        // Sort results by relevance and limit
        usort($results, function($a, $b) use ($query) {
            $aExact = (stripos($a['SerialNumber'], $query) !== false) ? 1 : 0;
            $bExact = (stripos($b['SerialNumber'], $query) !== false) ? 1 : 0;
            
            if ($aExact !== $bExact) {
                return $bExact - $aExact;
            }
            
            return strtotime($b['UpdatedAt']) - strtotime($a['UpdatedAt']);
        });
        
        return [
            'components' => array_slice($results, 0, $limit),
            'total_found' => $totalFound,
            'accessible_count' => $totalFound
        ];
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