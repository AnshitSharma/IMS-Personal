<?php
/**
 * Simple Access Control List (ACL) Class
 * Handles role-based permissions for the BDC IMS system
 */

class SimpleACL {
    private $pdo;
    private $userId;
    private $userRole = null;
    
    // Role hierarchy (higher number = more permissions)
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
     * Get user's role with fallback to legacy ACL field
     */
    public function getUserRole() {
        if ($this->userRole !== null) {
            return $this->userRole;
        }
        
        if (!$this->userId) {
            return self::ROLE_VIEWER;
        }
        
        try {
            // First try to get role from user_roles table
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
            
            // Fallback to legacy ACL field if no role assignment found
            $stmt = $this->pdo->prepare("SELECT acl FROM users WHERE id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch();
            
            if ($user && isset($user['acl'])) {
                switch ((int)$user['acl']) {
                    case 1:
                        $this->userRole = self::ROLE_ADMIN;
                        // Auto-assign admin role to user_roles table for future consistency
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
                $this->autoAssignRoleFromLegacyACL($this->userId, self::ROLE_VIEWER);
            }
            
            return $this->userRole;
        } catch (PDOException $e) {
            error_log("SimpleACL Error getting user role: " . $e->getMessage());
            return self::ROLE_VIEWER;
        }
    }
    
    /**
     * Auto-assign role from legacy ACL field to new roles table
     */
    private function autoAssignRoleFromLegacyACL($userId, $roleName) {
        try {
            // Check if role already exists
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
                    // Assign role
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
        // Check if current user is admin (except for system operations)
        if ($this->userId && !$this->isAdmin() && $this->userId != 1) {
            return false;
        }
        
        try {
            // Get role ID
            $stmt = $this->pdo->prepare("SELECT id FROM roles WHERE name = ?");
            $stmt->execute([$roleName]);
            $role = $stmt->fetch();
            
            if (!$role) {
                error_log("SimpleACL: Role '$roleName' not found");
                return false;
            }
            
            // Remove existing role for user
            $stmt = $this->pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // Assign new role
            $assignedByValue = $assignedBy ?: ($this->userId ?: 1);
            $stmt = $this->pdo->prepare("
                INSERT INTO user_roles (user_id, role_id, assigned_by) 
                VALUES (?, ?, ?)
            ");
            $result = $stmt->execute([$userId, $role['id'], $assignedByValue]);
            
            // Clear cache if changing current user's role
            if ($userId == $this->userId) {
                $this->userRole = null;
            }
            
            if ($result) {
                error_log("SimpleACL: Assigned role '$roleName' to user $userId");
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("SimpleACL Error assigning role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Initialize user role (used when creating new users)
     */
    public function initializeUserRole($userId, $defaultRole = 'viewer') {
        return $this->assignRole($userId, $defaultRole, 1); // Assigned by system (user ID 1)
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
     * Log action for audit trail
     */
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
    
    /**
     * Get audit log entries
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
    
    /**
     * Remove role from user
     */
    public function removeRole($userId, $roleName) {
        if (!$this->isAdmin()) {
            return false;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                DELETE ur FROM user_roles ur
                JOIN roles r ON ur.role_id = r.id
                WHERE ur.user_id = ? AND r.name = ?
            ");
            $result = $stmt->execute([$userId, $roleName]);
            
            if ($result && $userId == $this->userId) {
                $this->userRole = null; // Clear cache
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("SimpleACL Error removing role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if role exists
     */
    public function roleExists($roleName) {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM roles WHERE name = ?");
            $stmt->execute([$roleName]);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("SimpleACL Error checking role existence: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create a new role (admin only)
     */
    public function createRole($name, $displayName, $description = null) {
        if (!$this->isAdmin()) {
            return false;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO roles (name, display_name, description) 
                VALUES (?, ?, ?)
            ");
            return $stmt->execute([$name, $displayName, $description]);
        } catch (PDOException $e) {
            error_log("SimpleACL Error creating role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete a role (admin only, cannot delete system roles)
     */
    public function deleteRole($roleName) {
        if (!$this->isAdmin()) {
            return false;
        }
        
        // Prevent deletion of system roles
        if (in_array($roleName, [self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_VIEWER])) {
            return false;
        }
        
        try {
            $this->pdo->beginTransaction();
            
            // Remove all user assignments for this role
            $stmt = $this->pdo->prepare("
                DELETE ur FROM user_roles ur
                JOIN roles r ON ur.role_id = r.id
                WHERE r.name = ?
            ");
            $stmt->execute([$roleName]);
            
            // Delete the role
            $stmt = $this->pdo->prepare("DELETE FROM roles WHERE name = ?");
            $result = $stmt->execute([$roleName]);
            
            $this->pdo->commit();
            return $result;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("SimpleACL Error deleting role: " . $e->getMessage());
            return false;
        }
    }
}
?>