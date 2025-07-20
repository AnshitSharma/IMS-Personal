<?php
/**
 * Complete Working API for BDC IMS
 * Supports all components: CPU, RAM, Storage, Motherboard, NIC, Caddy
 * Fixed to match actual database structure
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration (inline to avoid file inclusion issues)
$dbHost = "localhost";
$dbUser = "shubhams_api";
$dbPass = "5C8R.wRErC_(";
$dbName = "shubhams_bdc_ims";

// Create PDO connection
try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]
    );
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'is_logged_in' => 0,
        'status_code' => 500,
        'success' => 0,
        'message' => 'Database connection failed'
    ]);
    exit;
}

// Simple ACL Class (inline to avoid file inclusion issues)
class SimpleACL {
    private $pdo;
    private $userId;
    private $userRole = null;
    
    const ROLE_VIEWER = 'viewer';
    const ROLE_MANAGER = 'manager';
    const ROLE_ADMIN = 'admin';
    
    const ACTION_READ = 'read';
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_EXPORT = 'export';
    const ACTION_MANAGE_USERS = 'manage_users';
    
    public function __construct($pdo, $userId = null) {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }
    
    public function setUserId($userId) {
        $this->userId = $userId;
        $this->userRole = null;
    }
    
    public function getUserRole() {
        if ($this->userRole !== null) {
            return $this->userRole;
        }
        
        if (!$this->userId) {
            return self::ROLE_VIEWER;
        }
        
        try {
            // Check user_roles table first
            $stmt = $this->pdo->prepare("
                SELECT r.name 
                FROM user_roles ur 
                JOIN roles r ON ur.role_id = r.id 
                WHERE ur.user_id = ? 
                ORDER BY ur.assigned_at DESC
                LIMIT 1
            ");
            $stmt->execute([$this->userId]);
            
            $result = $stmt->fetch();
            if ($result) {
                $this->userRole = $result['name'];
                return $this->userRole;
            }
            
            // Fallback to legacy ACL field
            $stmt = $this->pdo->prepare("SELECT acl FROM users WHERE id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch();
            
            if ($user && isset($user['acl'])) {
                switch ((int)$user['acl']) {
                    case 1:
                        $this->userRole = self::ROLE_ADMIN;
                        $this->autoAssignRoleFromLegacyACL($this->userId, self::ROLE_ADMIN);
                        break;
                    case 2:
                        $this->userRole = self::ROLE_MANAGER;
                        $this->autoAssignRoleFromLegacyACL($this->userId, self::ROLE_MANAGER);
                        break;
                    default:
                        $this->userRole = self::ROLE_VIEWER;
                        $this->autoAssignRoleFromLegacyACL($this->userId, self::ROLE_VIEWER);
                        break;
                }
            } else {
                $this->userRole = self::ROLE_VIEWER;
            }
            
            return $this->userRole;
        } catch (PDOException $e) {
            error_log("SimpleACL Error getting user role: " . $e->getMessage());
            return self::ROLE_VIEWER;
        }
    }
    
    private function autoAssignRoleFromLegacyACL($userId, $roleName) {
        try {
            // Check if role assignment exists
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM user_roles ur 
                JOIN roles r ON ur.role_id = r.id 
                WHERE ur.user_id = ? AND r.name = ?
            ");
            $stmt->execute([$userId, $roleName]);
            
            if ($stmt->fetchColumn() == 0) {
                // Get role ID
                $stmt = $this->pdo->prepare("SELECT id FROM roles WHERE name = ?");
                $stmt->execute([$roleName]);
                $role = $stmt->fetch();
                
                if ($role) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO user_roles (user_id, role_id, assigned_by) 
                        VALUES (?, ?, 1)
                    ");
                    $stmt->execute([$userId, $role['id']]);
                    error_log("Auto-assigned role '$roleName' to user $userId from legacy ACL");
                }
            }
        } catch (PDOException $e) {
            error_log("SimpleACL Error auto-assigning role: " . $e->getMessage());
        }
    }
    
    public function hasPermission($action, $componentType = null) {
        $role = $this->getUserRole();
        
        switch ($role) {
            case self::ROLE_ADMIN:
                return true;
                
            case self::ROLE_MANAGER:
                return in_array($action, [
                    self::ACTION_READ,
                    self::ACTION_CREATE,
                    self::ACTION_UPDATE,
                    self::ACTION_EXPORT
                ]);
                
            case self::ROLE_VIEWER:
                return in_array($action, [
                    self::ACTION_READ,
                    self::ACTION_EXPORT
                ]);
                
            default:
                return false;
        }
    }
    
    public function hasRole($roleName) {
        return $this->getUserRole() === $roleName;
    }
    
    public function isAdmin() {
        return $this->hasRole(self::ROLE_ADMIN);
    }
    
    public function isManagerOrAdmin() {
        $role = $this->getUserRole();
        return in_array($role, [self::ROLE_MANAGER, self::ROLE_ADMIN]);
    }
    
    public function canManageUsers() {
        return $this->hasPermission(self::ACTION_MANAGE_USERS);
    }
    
    public function logAction($action, $componentType = null, $componentId = null, $oldValues = null, $newValues = null) {
        if (!$this->userId) {
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO simple_audit_log 
                (user_id, action, component_type, component_id, old_values, new_values, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $oldValuesJson = $oldValues ? json_encode($oldValues) : null;
            $newValuesJson = $newValues ? json_encode($newValues) : null;
            
            $stmt->execute([
                $this->userId,
                $action,
                $componentType,
                $componentId,
                $oldValuesJson,
                $newValuesJson,
                $ipAddress,
                $userAgent
            ]);
        } catch (PDOException $e) {
            error_log("SimpleACL Error logging action: " . $e->getMessage());
        }
    }
    
    public function getPermissionsSummary() {
        $role = $this->getUserRole();
        
        $hierarchy = [
            self::ROLE_ADMIN => [
                'display_name' => 'Administrator',
                'permissions' => ['Read', 'Create', 'Update', 'Delete', 'Export', 'Manage Users'],
                'level' => 3
            ],
            self::ROLE_MANAGER => [
                'display_name' => 'Manager',
                'permissions' => ['Read', 'Create', 'Update', 'Export'],
                'level' => 2
            ],
            self::ROLE_VIEWER => [
                'display_name' => 'Viewer/User',
                'permissions' => ['Read', 'Export'],
                'level' => 1
            ]
        ];
        
        $componentTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
        $componentAccess = [];
        
        foreach ($componentTypes as $type) {
            $componentAccess[$type] = [
                'read' => $this->hasPermission(self::ACTION_READ),
                'create' => $this->hasPermission(self::ACTION_CREATE),
                'update' => $this->hasPermission(self::ACTION_UPDATE),
                'delete' => $this->hasPermission(self::ACTION_DELETE),
                'export' => $this->hasPermission(self::ACTION_EXPORT)
            ];
        }
        
        return [
            'user_id' => $this->userId,
            'role' => $role,
            'role_display_name' => $hierarchy[$role]['display_name'] ?? 'Unknown',
            'permissions' => $hierarchy[$role]['permissions'] ?? [],
            'level' => $hierarchy[$role]['level'] ?? 0,
            'component_access' => $componentAccess,
            'system_access' => [
                'can_manage_users' => $this->canManageUsers(),
                'can_view_audit_log' => $this->isAdmin(),
                'can_manage_roles' => $this->isAdmin()
            ]
        ];
    }
}

// Helper functions
function send_json_response($logged_in, $success, $status_code, $message, $other_params = []) {
    if (!headers_sent()) {
        http_response_code($status_code);
    }
    
    $resp = [
        'is_logged_in' => $logged_in,
        'status_code' => $status_code,
        'success' => $success,
        'message' => $message,
        'timestamp' => date('c')
    ];
    
    if ($logged_in && isset($_SESSION['id'])) {
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
            error_log("Error adding user context: " . $e->getMessage());
        }
    }
    
    if (!empty($other_params)) {
        $resp = array_merge($resp, $other_params);
    }
    
    echo json_encode($resp);
    exit();
}

function validateComponentAccess($pdo, $action, $componentType = null) {
    if (!isset($_SESSION['id'])) {
        send_json_response(0, 0, 401, "Authentication required");
    }
    
    try {
        $acl = new SimpleACL($pdo, $_SESSION['id']);
        
        if (!$acl->hasPermission($action, $componentType)) {
            $userRole = $acl->getUserRole();
            send_json_response(1, 0, 403, "Access denied. Insufficient permissions for '$action' operation.", [
                'required_permission' => $action,
                'component_type' => $componentType,
                'user_role' => $userRole,
                'user_id' => $_SESSION['id']
            ]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Permission validation error: " . $e->getMessage());
        send_json_response(1, 0, 500, "Error validating permissions");
    }
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function isUserLoggedIn($pdo) {
    if (!isset($_SESSION['id'])) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['id']]);
        $user = $stmt->fetch();
        
        if (!$user) {
            session_destroy();
            return false;
        }
        
        return $user;
    } catch (PDOException $e) {
        error_log("Error checking user login: " . $e->getMessage());
        return false;
    }
}

// Ensure required tables exist
function ensureTablesExist($pdo) {
    try {
        // Check if roles table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'roles'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `roles` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `name` varchar(50) NOT NULL,
                  `display_name` varchar(100) NOT NULL,
                  `description` text DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            
            // Insert default roles
            $pdo->exec("
                INSERT INTO roles (name, display_name, description) VALUES 
                ('viewer', 'Viewer/User', 'Basic read-only access to view components'),
                ('manager', 'Manager', 'Can view, add, and edit components but cannot delete'),
                ('admin', 'Administrator', 'Full access to all system functions')
            ");
        }
        
        // Check if user_roles table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'user_roles'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `user_roles` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `user_id` int(6) UNSIGNED NOT NULL,
                  `role_id` int(11) NOT NULL,
                  `assigned_by` int(6) UNSIGNED DEFAULT NULL,
                  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `user_id` (`user_id`),
                  KEY `role_id` (`role_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
        
        // Check if audit log table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'simple_audit_log'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `simple_audit_log` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `user_id` int(6) UNSIGNED NOT NULL,
                  `action` varchar(100) NOT NULL,
                  `component_type` varchar(50) DEFAULT NULL,
                  `component_id` varchar(36) DEFAULT NULL,
                  `old_values` text DEFAULT NULL,
                  `new_values` text DEFAULT NULL,
                  `ip_address` varchar(45) DEFAULT NULL,
                  `user_agent` text DEFAULT NULL,
                  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  KEY `user_id` (`user_id`),
                  KEY `created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
        
    } catch (Exception $e) {
        error_log("Error ensuring tables exist: " . $e->getMessage());
    }
}

// Main API logic starts here
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(0, 0, 405, "Method Not Allowed");
}

// Get action parameter
$action = $_POST['action'] ?? '';

if (empty($action)) {
    send_json_response(0, 0, 400, "Action parameter is required");
}

// Log the action for debugging
error_log("API called with action: " . $action);

try {
    // Ensure required tables exist
    ensureTablesExist($pdo);
    
    // Route to appropriate handler based on action
    $actionParts = explode('-', $action);
    $module = $actionParts[0] ?? '';
    $operation = $actionParts[1] ?? '';
    
    error_log("Module: $module, Operation: $operation");
    
    switch($module) {
        case 'auth':
            handleAuthOperations($operation, $pdo);
            break;
            
        case 'cpu':
        case 'ram':
        case 'storage':
        case 'motherboard':
        case 'nic':
        case 'caddy':
            handleComponentOperations($module, $operation, $pdo);
            break;
            
        case 'acl':
            handleACLOperations($operation, $pdo);
            break;
            
        default:
            send_json_response(0, 0, 400, "Unknown module: $module");
    }
    
} catch (Exception $e) {
    error_log("API error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    send_json_response(0, 0, 500, "Internal server error: " . $e->getMessage());
}

// Authentication operations
function handleAuthOperations($operation, $pdo) {
    error_log("Auth operation: $operation");
    
    switch($operation) {
        case 'login':
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            
            error_log("Login attempt for username: $username");
            
            if (empty($username) || empty($password)) {
                send_json_response(0, 0, 400, "Please enter both username and password");
            }
            
            try {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
                $stmt->execute([$username, $username]);
                
                if ($stmt->rowCount() == 1) {
                    $user = $stmt->fetch();
                    
                    if (password_verify($password, $user['password'])) {
                        // Clear any existing session data
                        session_regenerate_id(true);
                        
                        $_SESSION["loggedin"] = true;
                        $_SESSION["id"] = $user["id"];
                        $_SESSION["username"] = $user["username"];
                        $_SESSION["email"] = $user["email"];
                        
                        // Initialize ACL
                        $acl = new SimpleACL($pdo, $user["id"]);
                        $userRole = $acl->getUserRole();
                        $permissionsSummary = $acl->getPermissionsSummary();
                        
                        error_log("Login successful for user: {$user['username']} with role: $userRole");
                        
                        // Log the action
                        $acl->logAction("User login", "auth", $user["id"]);
                        
                        $responseData = [
                            'user_context' => [
                                'user_id' => $user["id"],
                                'username' => $user["username"],
                                'role' => $userRole,
                                'is_admin' => $acl->isAdmin(),
                                'is_manager' => $acl->isManagerOrAdmin()
                            ],
                            'user' => [
                                'id' => $user["id"],
                                'username' => $user["username"],
                                'email' => $user["email"],
                                'firstname' => $user["firstname"] ?? '',
                                'lastname' => $user["lastname"] ?? '',
                                'role' => $userRole,
                                'is_admin' => $acl->isAdmin(),
                                'is_manager' => $acl->isManagerOrAdmin()
                            ],
                            'permissions' => $permissionsSummary
                        ];
                        
                        send_json_response(1, 1, 200, "Login successful", $responseData);
                    } else {
                        error_log("Invalid password for user: $username");
                        send_json_response(0, 0, 401, "Invalid credentials");
                    }
                } else {
                    error_log("User not found: $username");
                    send_json_response(0, 0, 401, "Invalid credentials");
                }
            } catch (Exception $e) {
                error_log("Login error: " . $e->getMessage());
                send_json_response(0, 0, 500, "Login failed");
            }
            break;
            
        case 'logout':
            if (isset($_SESSION['id'])) {
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $acl->logAction("User logout", "auth", $_SESSION['id']);
            }
            
            session_destroy();
            send_json_response(0, 1, 200, "Logout successful");
            break;
            
        case 'check_session':
            if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true && isset($_SESSION['id'])) {
                $acl = new SimpleACL($pdo, $_SESSION['id']);
                $userRole = $acl->getUserRole();
                $permissionsSummary = $acl->getPermissionsSummary();
                
                $responseData = [
                    'user_context' => [
                        'user_id' => $_SESSION['id'],
                        'username' => $_SESSION['username'],
                        'role' => $userRole,
                        'is_admin' => $acl->isAdmin(),
                        'is_manager' => $acl->isManagerOrAdmin()
                    ],
                    'user' => [
                        'id' => $_SESSION['id'],
                        'username' => $_SESSION['username'],
                        'email' => $_SESSION['email'],
                        'role' => $userRole,
                        'is_admin' => $acl->isAdmin(),
                        'is_manager' => $acl->isManagerOrAdmin()
                    ],
                    'permissions' => $permissionsSummary
                ];
                
                send_json_response(1, 1, 200, "User is logged in", $responseData);
            } else {
                send_json_response(0, 0, 401, "User is not logged in");
            }
            break;
            
        default:
            send_json_response(0, 0, 400, "Invalid auth operation");
    }
}

// Component operations - Works for ALL components (CPU, RAM, Storage, Motherboard, NIC, Caddy)
function handleComponentOperations($componentType, $operation, $pdo) {
    // Check authentication first
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['id'])) {
        send_json_response(0, 0, 401, "Unauthorized - Please login first");
    }
    
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        send_json_response(1, 0, 400, "Invalid component type: $componentType");
    }
    
    $table = $tableMap[$componentType];
    
    switch($operation) {
        case 'list':
        case 'get':
            validateComponentAccess($pdo, 'read', $componentType);
            
            try {
                $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
                $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
                $statusFilter = $_POST['status'] ?? 'all';
                
                $sql = "SELECT * FROM {$table}";
                $params = [];
                
                if ($statusFilter !== 'all') {
                    $sql .= " WHERE Status = ?";
                    $params[] = $statusFilter;
                }
                
                $sql .= " ORDER BY ID DESC LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll();
                
                // Get total count
                $countSql = "SELECT COUNT(*) FROM {$table}";
                if ($statusFilter !== 'all') {
                    $countSql .= " WHERE Status = ?";
                    $countStmt = $pdo->prepare($countSql);
                    $countStmt->execute($statusFilter !== 'all' ? [$statusFilter] : []);
                } else {
                    $countStmt = $pdo->prepare($countSql);
                    $countStmt->execute();
                }
                $totalCount = $countStmt->fetchColumn();
                
                send_json_response(1, 1, 200, "Data retrieved successfully", [
                    'data' => $results,
                    'component_type' => $componentType,
                    'pagination' => [
                        'total' => (int)$totalCount,
                        'limit' => $limit,
                        'offset' => $offset,
                        'has_more' => ($offset + $limit) < $totalCount
                    ]
                ]);
                
            } catch (Exception $e) {
                error_log("List operation error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to retrieve data");
            }
            break;
            
        case 'add':
        case 'create':
            validateComponentAccess($pdo, 'create', $componentType);
            
            try {
                // Universal field mapping for ALL components (they all have the same structure)
                $fieldMapping = [
                    'serial_number' => 'SerialNumber',
                    'status' => 'Status', 
                    'server_uuid' => 'ServerUUID',
                    'location' => 'Location',
                    'rack_position' => 'RackPosition',
                    'purchase_date' => 'PurchaseDate',
                    'installation_date' => 'InstallationDate',
                    'warranty_end_date' => 'WarrantyEndDate',
                    'flag' => 'Flag',
                    'notes' => 'Notes',
                    'component_uuid' => 'UUID',
                    // NIC specific fields
                    'mac_address' => 'MacAddress',
                    'ip_address' => 'IPAddress',
                    'network_name' => 'NetworkName',
                    // PCIe Card specific fields (if needed)
                    'card_type' => 'CardType',
                    'pcie_version' => 'PCIeVersion',
                    'lanes' => 'Lanes',
                    'attachables' => 'Attachables'
                ];
                
                $data = [];
                
                // Map the incoming fields to database fields
                foreach ($_POST as $key => $value) {
                    if ($key === 'action' || $key === 'session_id') {
                        continue; // Skip these
                    }
                    
                    $trimmedValue = trim($value);
                    if (empty($trimmedValue) && !in_array($key, ['server_uuid', 'ip_address', 'network_name'])) {
                        continue; // Skip empty values except those that can be empty
                    }
                    
                    // Map field name
                    $dbField = isset($fieldMapping[$key]) ? $fieldMapping[$key] : $key;
                    $data[$dbField] = $trimmedValue;
                }
                
                // Validate required fields - only SerialNumber is truly required for all components
                if (!isset($data['SerialNumber']) || empty($data['SerialNumber'])) {
                    send_json_response(1, 0, 400, "SerialNumber is required for $componentType");
                }
                
                // Generate UUID if not provided
                if (!isset($data['UUID']) || empty($data['UUID'])) {
                    $data['UUID'] = generateUUID();
                }
                
                // Set default status if not provided
                if (!isset($data['Status']) || empty($data['Status'])) {
                    $data['Status'] = 1; // Available
                }
                
            // Convert dates to proper format if needed
                $dateFields = ['PurchaseDate', 'InstallationDate', 'WarrantyEndDate'];
                foreach ($dateFields as $dateField) {
                    if (isset($data[$dateField]) && !empty($data[$dateField])) {
                        $data[$dateField] = date('Y-m-d', strtotime($data[$dateField]));
                    }
                }
                
                // Log what we're about to insert
                error_log("Inserting $componentType data: " . json_encode($data));
                
                // Build insert query
                $fields = array_keys($data);
                $placeholders = ':' . implode(', :', $fields);
                $fieldsList = '`' . implode('`, `', $fields) . '`';
                
                $sql = "INSERT INTO {$table} ({$fieldsList}) VALUES ({$placeholders})";
                $stmt = $pdo->prepare($sql);
                
                foreach ($data as $key => $value) {
                    $stmt->bindValue(":$key", $value);
                }
                
                if ($stmt->execute()) {
                    $id = $pdo->lastInsertId();
                    
                    // Log the action
                    $acl = new SimpleACL($pdo, $_SESSION['id']);
                    $acl->logAction("Component created", $componentType, $data['UUID'], null, $data);
                    
                    send_json_response(1, 1, 201, ucfirst($componentType) . " added successfully", [
                        'id' => $id,
                        'uuid' => $data['UUID'],
                        'component_type' => $componentType,
                        'serial_number' => $data['SerialNumber'],
                        'inserted_data' => $data
                    ]);
                } else {
                    send_json_response(1, 0, 500, "Failed to add $componentType");
                }
                
            } catch (Exception $e) {
                error_log("Add $componentType operation error: " . $e->getMessage());
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    send_json_response(1, 0, 409, "Duplicate entry: Serial number or UUID already exists for $componentType");
                } else {
                    send_json_response(1, 0, 500, "Failed to add $componentType: " . $e->getMessage());
                }
            }
            break;
            
        case 'update':
        case 'edit':
            validateComponentAccess($pdo, 'update', $componentType);
            
            $id = $_POST['id'] ?? null;
            if (!$id) {
                send_json_response(1, 0, 400, "Component ID is required for $componentType update");
            }
            
            try {
                // Get current data for logging
                $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE ID = ?");
                $stmt->execute([$id]);
                $oldData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$oldData) {
                    send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                }
                
                // Universal field mapping for ALL components
                $fieldMapping = [
                    'serial_number' => 'SerialNumber',
                    'status' => 'Status', 
                    'server_uuid' => 'ServerUUID',
                    'location' => 'Location',
                    'rack_position' => 'RackPosition',
                    'purchase_date' => 'PurchaseDate',
                    'installation_date' => 'InstallationDate',
                    'warranty_end_date' => 'WarrantyEndDate',
                    'flag' => 'Flag',
                    'notes' => 'Notes',
                    // NIC specific fields
                    'mac_address' => 'MacAddress',
                    'ip_address' => 'IPAddress',
                    'network_name' => 'NetworkName',
                    // PCIe Card specific fields
                    'card_type' => 'CardType',
                    'pcie_version' => 'PCIeVersion',
                    'lanes' => 'Lanes',
                    'attachables' => 'Attachables'
                ];
                
                $updateData = [];
                
                // Map the incoming fields to database fields
                foreach ($_POST as $key => $value) {
                    if ($key === 'action' || $key === 'session_id' || $key === 'id') {
                        continue; // Skip these
                    }
                    
                    // Map field name
                    $dbField = isset($fieldMapping[$key]) ? $fieldMapping[$key] : $key;
                    $updateData[$dbField] = trim($value);
                }
                
                if (empty($updateData)) {
                    send_json_response(1, 0, 400, "No data to update for $componentType");
                }
                
                // Convert dates if needed
                $dateFields = ['PurchaseDate', 'InstallationDate', 'WarrantyEndDate'];
                foreach ($dateFields as $dateField) {
                    if (isset($updateData[$dateField]) && !empty($updateData[$dateField])) {
                        $updateData[$dateField] = date('Y-m-d', strtotime($updateData[$dateField]));
                    }
                }
                
                // Build update query
                $setParts = [];
                foreach (array_keys($updateData) as $field) {
                    $setParts[] = "`$field` = :$field";
                }
                $setClause = implode(', ', $setParts);
                
                $sql = "UPDATE {$table} SET {$setClause} WHERE ID = :id";
                $stmt = $pdo->prepare($sql);
                
                foreach ($updateData as $key => $value) {
                    $stmt->bindValue(":$key", $value);
                }
                $stmt->bindValue(':id', $id);
                
                if ($stmt->execute()) {
                    // Log the action
                    $acl = new SimpleACL($pdo, $_SESSION['id']);
                    $acl->logAction("Component updated", $componentType, $oldData['UUID'], $oldData, $updateData);
                    
                    send_json_response(1, 1, 200, ucfirst($componentType) . " updated successfully", [
                        'id' => $id,
                        'component_type' => $componentType,
                        'updated_fields' => $updateData
                    ]);
                } else {
                    send_json_response(1, 0, 500, "Failed to update $componentType");
                }
                
            } catch (Exception $e) {
                error_log("Update $componentType operation error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to update $componentType: " . $e->getMessage());
            }
            break;
            
        case 'delete':
            validateComponentAccess($pdo, 'delete', $componentType);
            
            $id = $_POST['id'] ?? null;
            if (!$id) {
                send_json_response(1, 0, 400, "Component ID is required for $componentType deletion");
            }
            
            try {
                // Get current data for logging
                $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE ID = ?");
                $stmt->execute([$id]);
                $oldData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$oldData) {
                    send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                }
                
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE ID = ?");
                if ($stmt->execute([$id])) {
                    // Log the action
                    $acl = new SimpleACL($pdo, $_SESSION['id']);
                    $acl->logAction("Component deleted", $componentType, $oldData['UUID'], $oldData, null);
                    
                    send_json_response(1, 1, 200, ucfirst($componentType) . " deleted successfully", [
                        'id' => $id,
                        'component_type' => $componentType,
                        'deleted_serial_number' => $oldData['SerialNumber']
                    ]);
                } else {
                    send_json_response(1, 0, 500, "Failed to delete $componentType");
                }
                
            } catch (Exception $e) {
                error_log("Delete $componentType operation error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to delete $componentType: " . $e->getMessage());
            }
            break;
            
        case 'search':
            validateComponentAccess($pdo, 'read', $componentType);
            
            try {
                $query = trim($_POST['query'] ?? '');
                $searchField = $_POST['field'] ?? 'SerialNumber';
                $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
                
                if (empty($query)) {
                    send_json_response(1, 0, 400, "Search query is required");
                }
                
                // Allowed search fields for security
                $allowedFields = ['SerialNumber', 'Location', 'Flag', 'Notes', 'Status'];
                if (!in_array($searchField, $allowedFields)) {
                    $searchField = 'SerialNumber';
                }
                
                $sql = "SELECT * FROM {$table} WHERE {$searchField} LIKE ? ORDER BY ID DESC LIMIT ?";
                $stmt = $pdo->prepare($sql);
                $searchTerm = '%' . $query . '%';
                $stmt->execute([$searchTerm, $limit]);
                $results = $stmt->fetchAll();
                
                send_json_response(1, 1, 200, "Search completed for $componentType", [
                    'data' => $results,
                    'component_type' => $componentType,
                    'search_query' => $query,
                    'search_field' => $searchField,
                    'results_count' => count($results)
                ]);
                
            } catch (Exception $e) {
                error_log("Search $componentType operation error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to search $componentType");
            }
            break;
            
        case 'get_by_id':
            validateComponentAccess($pdo, 'read', $componentType);
            
            $id = $_POST['id'] ?? null;
            if (!$id) {
                send_json_response(1, 0, 400, "Component ID is required");
            }
            
            try {
                $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE ID = ?");
                $stmt->execute([$id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$result) {
                    send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                }
                
                send_json_response(1, 1, 200, ucfirst($componentType) . " retrieved successfully", [
                    'data' => $result,
                    'component_type' => $componentType
                ]);
                
            } catch (Exception $e) {
                error_log("Get by ID $componentType operation error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to retrieve $componentType");
            }
            break;
            
        case 'get_by_uuid':
            validateComponentAccess($pdo, 'read', $componentType);
            
            $uuid = $_POST['uuid'] ?? null;
            if (!$uuid) {
                send_json_response(1, 0, 400, "Component UUID is required");
            }
            
            try {
                $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE UUID = ?");
                $stmt->execute([$uuid]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$result) {
                    send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                }
                
                send_json_response(1, 1, 200, ucfirst($componentType) . " retrieved successfully", [
                    'data' => $result,
                    'component_type' => $componentType
                ]);
                
            } catch (Exception $e) {
                error_log("Get by UUID $componentType operation error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to retrieve $componentType");
            }
            break;
            
        case 'count':
            validateComponentAccess($pdo, 'read', $componentType);
            
            try {
                $statusFilter = $_POST['status'] ?? 'all';
                
                $sql = "SELECT Status, COUNT(*) as count FROM {$table}";
                $params = [];
                
                if ($statusFilter !== 'all') {
                    $sql .= " WHERE Status = ?";
                    $params[] = $statusFilter;
                    $sql .= " GROUP BY Status";
                } else {
                    $sql .= " GROUP BY Status";
                }
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll();
                
                // Get total count
                $totalStmt = $pdo->prepare("SELECT COUNT(*) as total FROM {$table}");
                $totalStmt->execute();
                $total = $totalStmt->fetch();
                
                $statusCounts = [];
                foreach ($results as $row) {
                    $statusName = '';
                    switch ($row['Status']) {
                        case 0: $statusName = 'Failed/Decommissioned'; break;
                        case 1: $statusName = 'Available'; break;
                        case 2: $statusName = 'In Use'; break;
                        default: $statusName = 'Unknown'; break;
                    }
                    $statusCounts[] = [
                        'status_id' => $row['Status'],
                        'status_name' => $statusName,
                        'count' => $row['count']
                    ];
                }
                
                send_json_response(1, 1, 200, ucfirst($componentType) . " count retrieved", [
                    'component_type' => $componentType,
                    'total_count' => $total['total'],
                    'status_breakdown' => $statusCounts
                ]);
                
            } catch (Exception $e) {
                error_log("Count $componentType operation error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to count $componentType");
            }
            break;
            
        default:
            send_json_response(1, 0, 400, "Invalid $componentType operation: $operation");
    }
}

// ACL operations
function handleACLOperations($operation, $pdo) {
    if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['id'])) {
        send_json_response(0, 0, 401, "Unauthorized - Please login first");
    }
    
    $acl = new SimpleACL($pdo, $_SESSION['id']);
    
    switch($operation) {
        case 'get_user_permissions':
            $permissions = $acl->getPermissionsSummary();
            send_json_response(1, 1, 200, "Permissions retrieved", ['permissions' => $permissions]);
            break;
            
        case 'get_users':
            if (!$acl->isAdmin()) {
                send_json_response(1, 0, 403, "Admin access required");
            }
            
            try {
                $stmt = $pdo->query("
                    SELECT 
                        u.id, u.username, u.email, u.firstname, u.lastname, u.acl as legacy_acl,
                        r.name as role_name, r.display_name as role_display_name,
                        ur.assigned_at
                    FROM users u 
                    LEFT JOIN user_roles ur ON u.id = ur.user_id 
                    LEFT JOIN roles r ON ur.role_id = r.id 
                    ORDER BY u.username
                ");
                $users = $stmt->fetchAll();
                send_json_response(1, 1, 200, "Users retrieved", ['users' => $users]);
            } catch (Exception $e) {
                send_json_response(1, 0, 500, "Failed to get users");
            }
            break;
            
        case 'assign_role':
            if (!$acl->isAdmin()) {
                send_json_response(1, 0, 403, "Admin access required");
            }
            
            $userId = $_POST['user_id'] ?? null;
            $roleName = $_POST['role'] ?? null;
            
            if (!$userId || !$roleName) {
                send_json_response(1, 0, 400, "User ID and role are required");
            }
            
            try {
                // Get role ID
                $stmt = $pdo->prepare("SELECT id FROM roles WHERE name = ?");
                $stmt->execute([$roleName]);
                $role = $stmt->fetch();
                
                if (!$role) {
                    send_json_response(1, 0, 400, "Invalid role: $roleName");
                }
                
                // Remove existing role
                $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                // Assign new role
                $stmt = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)");
                if ($stmt->execute([$userId, $role['id'], $_SESSION['id']])) {
                    $acl->logAction("Role assigned", "user_management", $userId, null, ['role' => $roleName]);
                    send_json_response(1, 1, 200, "Role '$roleName' assigned successfully to user $userId");
                } else {
                    send_json_response(1, 0, 500, "Failed to assign role");
                }
            } catch (Exception $e) {
                error_log("Role assignment error: " . $e->getMessage());
                send_json_response(1, 0, 500, "Failed to assign role");
            }
            break;
            
        case 'get_roles':
            if (!$acl->isAdmin()) {
                send_json_response(1, 0, 403, "Admin access required");
            }
            
            try {
                $stmt = $pdo->query("
                    SELECT r.*, COUNT(ur.id) as user_count 
                    FROM roles r 
                    LEFT JOIN user_roles ur ON r.id = ur.role_id 
                    GROUP BY r.id 
                    ORDER BY 
                        CASE r.name 
                            WHEN 'admin' THEN 1 
                            WHEN 'manager' THEN 2 
                            WHEN 'viewer' THEN 3 
                            ELSE 4 
                        END
                ");
                $roles = $stmt->fetchAll();
                send_json_response(1, 1, 200, "Roles retrieved", ['roles' => $roles]);
            } catch (Exception $e) {
                send_json_response(1, 0, 500, "Failed to get roles");
            }
            break;
            
        case 'get_audit_log':
            if (!$acl->isAdmin()) {
                send_json_response(1, 0, 403, "Admin access required");
            }
            
            try {
                $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 50;
                $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
                
                $stmt = $pdo->prepare("
                    SELECT al.*, u.username, u.email
                    FROM simple_audit_log al
                    LEFT JOIN users u ON al.user_id = u.id
                    ORDER BY al.created_at DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$limit, $offset]);
                $logs = $stmt->fetchAll();
                
                // Get total count
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM simple_audit_log");
                $countStmt->execute();
                $totalCount = $countStmt->fetchColumn();
                
                send_json_response(1, 1, 200, "Audit log retrieved", [
                    'logs' => $logs,
                    'pagination' => [
                        'total' => (int)$totalCount,
                        'limit' => $limit,
                        'offset' => $offset,
                        'has_more' => ($offset + $limit) < $totalCount
                    ]
                ]);
            } catch (Exception $e) {
                send_json_response(1, 0, 500, "Failed to get audit log");
            }
            break;
            
        default:
            send_json_response(1, 0, 400, "Invalid ACL operation: $operation");
    }
}
?>