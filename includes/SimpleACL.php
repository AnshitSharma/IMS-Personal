<?php
/**
 * Simple ACL Class for BDC IMS
 * Supports only 3 roles: viewer, manager, admin
 */

class SimpleACL {
    private $pdo;
    private $userId;
    private $userRole = null;
    
    // Role hierarchy
    const ROLE_VIEWER = 'viewer';
    const ROLE_MANAGER = 'manager';
    const ROLE_ADMIN = 'admin';
    
    // Action types
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
    
    /**
     * Set the current user ID
     */
    public function setUserId($userId) {
        $this->userId = $userId;
        $this->userRole = null; // Clear cache
    }
    
    /**
     * Get user's role
     */
    public function getUserRole() {
        if ($this->userRole !== null) {
            return $this->userRole;
        }
        
        if (!$this->userId) {
            return null;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT r.name 
                FROM user_roles ur 
                JOIN roles r ON ur.role_id = r.id 
                WHERE ur.user_id = ? 
                LIMIT 1
            ");
            $stmt->execute([$this->userId]);
            
            $result = $stmt->fetch();
            $this->userRole = $result ? $result['name'] : self::ROLE_VIEWER;
            
            return $this->userRole;
        } catch (PDOException $e) {
            error_log("SimpleACL Error getting user role: " . $e->getMessage());
            return self::ROLE_VIEWER; // Default to viewer on error
        }
    }
    
    /**
     * Check if user has permission for an action
     */
    public function hasPermission($action, $componentType = null) {
        $role = $this->getUserRole();
        
        if (!$role) {
            return false;
        }
        
        switch ($role) {
            case self::ROLE_ADMIN:
                return true; // Admin can do everything
                
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
    
    /**
     * Check if user has specific role
     */
    public function hasRole($roleName) {
        return $this->getUserRole() === $roleName;
    }
    
    /**
     * Check if user is admin
     */
    public function isAdmin() {
        return $this->hasRole(self::ROLE_ADMIN);
    }
    
    /**
     * Check if user is manager or admin
     */
    public function isManagerOrAdmin() {
        $role = $this->getUserRole();
        return in_array($role, [self::ROLE_MANAGER, self::ROLE_ADMIN]);
    }
    
    /**
     * Assign role to user (only admins can do this)
     */
    public function assignRole($userId, $roleName, $assignedBy = null) {
        // Check if current user is admin
        if (!$this->isAdmin() && $this->userId != 1) {
            return false;
        }
        
        try {
            // Get role ID
            $stmt = $this->pdo->prepare("SELECT id FROM roles WHERE name = ?");
            $stmt->execute([$roleName]);
            $role = $stmt->fetch();
            
            if (!$role) {
                return false;
            }
            
            // Remove existing role for user
            $stmt = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Assign new role
            $assignedByValue = $assignedBy ?: $this->userId;
            $stmt = $this->pdo->prepare("
                INSERT INTO user_roles (user_id, role_id, assigned_by) 
                VALUES (?, ?, ?)
            ");
            $result = $stmt->execute([$userId, $role['id'], $assignedByValue]);
            
            // Clear cache if changing current user's role
            if ($userId == $this->userId) {
                $this->userRole = null;
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("SimpleACL Error assigning role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all available roles
     */
    public function getAllRoles() {
        try {
            $stmt = $this->pdo->query("
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
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("SimpleACL Error getting all roles: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get users with their roles
     */
    public function getUsersWithRoles() {
        if (!$this->isAdmin()) {
            return [];
        }
        
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    u.id, u.username, u.email, u.firstname, u.lastname,
                    r.name as role_name, r.display_name as role_display_name,
                    ur.assigned_at
                FROM users u 
                LEFT JOIN user_roles ur ON u.id = ur.user_id 
                LEFT JOIN roles r ON ur.role_id = r.id 
                ORDER BY u.username
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("SimpleACL Error getting users with roles: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Log action for audit trail - FIXED VERSION
     */
    public function logAction($action, $componentType = null, $componentId = null, $oldValues = null, $newValues = null) {
        if (!$this->userId) {
            return;
        }
        
        try {
            // Check if table exists first
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'simple_audit_log'");
            $stmt->execute();
            if ($stmt->rowCount() == 0) {
                return; // Table doesn't exist, skip logging
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO simple_audit_log 
                (user_id, action, component_type, component_id, old_values, new_values, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $ipAddress = $this->getClientIPAddress();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $oldValuesJson = $oldValues ? json_encode($oldValues) : null;
            $newValuesJson = $newValues ? json_encode($newValues) : null;
            
            // Use execute with array instead of bindParam to avoid reference issues
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
    
    /**
     * Get audit log (admin only)
     */
    public function getAuditLog($limit = 50, $offset = 0, $filters = []) {
        if (!$this->isAdmin()) {
            return [];
        }
        
        try {
            $whereConditions = [];
            $params = [];
            
            if (!empty($filters['user_id'])) {
                $whereConditions[] = "al.user_id = ?";
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['component_type'])) {
                $whereConditions[] = "al.component_type = ?";
                $params[] = $filters['component_type'];
            }
            
            if (!empty($filters['action'])) {
                $whereConditions[] = "al.action LIKE ?";
                $params[] = '%' . $filters['action'] . '%';
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            $stmt = $this->pdo->prepare("
                SELECT al.*, u.username, u.email
                FROM simple_audit_log al
                LEFT JOIN users u ON al.user_id = u.id
                $whereClause
                ORDER BY al.created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            // Add limit and offset to params
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("SimpleACL Error getting audit log: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get role hierarchy for display
     */
    public function getRoleHierarchy() {
        return [
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
    }
    
    /**
     * Check if user can manage other users
     */
    public function canManageUsers() {
        return $this->hasPermission(self::ACTION_MANAGE_USERS);
    }
    
    /**
     * Get user's permissions summary
     */
    public function getPermissionsSummary() {
        $role = $this->getUserRole();
        $hierarchy = $this->getRoleHierarchy();
        
        return [
            'user_id' => $this->userId,
            'role' => $role,
            'role_display_name' => $hierarchy[$role]['display_name'] ?? 'Unknown',
            'permissions' => $hierarchy[$role]['permissions'] ?? [],
            'level' => $hierarchy[$role]['level'] ?? 0,
            'component_access' => [
                'cpu' => $this->getComponentPermissions(),
                'ram' => $this->getComponentPermissions(),
                'storage' => $this->getComponentPermissions(),
                'motherboard' => $this->getComponentPermissions(),
                'nic' => $this->getComponentPermissions(),
                'caddy' => $this->getComponentPermissions()
            ],
            'system_access' => [
                'can_manage_users' => $this->canManageUsers(),
                'can_view_audit_log' => $this->isAdmin(),
                'can_manage_roles' => $this->isAdmin()
            ]
        ];
    }
    
    /**
     * Get component permissions based on role
     */
    private function getComponentPermissions() {
        return [
            'read' => $this->hasPermission(self::ACTION_READ),
            'create' => $this->hasPermission(self::ACTION_CREATE),
            'update' => $this->hasPermission(self::ACTION_UPDATE),
            'delete' => $this->hasPermission(self::ACTION_DELETE),
            'export' => $this->hasPermission(self::ACTION_EXPORT)
        ];
    }
    
    /**
     * Get client IP address
     */
    private function getClientIPAddress() {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
    }
    
    /**
     * Initialize default role for new user
     */
    public function initializeUserRole($userId, $defaultRole = self::ROLE_VIEWER) {
        return $this->assignRole($userId, $defaultRole, 1); // Assigned by system admin
    }
    
    /**
     * Cleanup old audit logs (run periodically)
     */
    public function cleanupAuditLog($daysToKeep = 90) {
        if (!$this->isAdmin()) {
            return false;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM simple_audit_log 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);
            return true;
        } catch (PDOException $e) {
            error_log("SimpleACL Error cleaning up audit log: " . $e->getMessage());
            return false;
        }
    }
}
?>