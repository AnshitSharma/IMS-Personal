<?php
/**
 * Access Control List (ACL) Class
 * 
 * This class handles role-based access control for the BDC IMS system.
 * It provides methods to check permissions, manage roles, and audit access.
 * 
 * Usage:
 * $acl = new ACL($pdo, $userId);
 * if ($acl->hasPermission('cpu', 'create')) {
 *     // User can create CPU components
 * }
 */

class ACL {
    private $pdo;
    private $userId;
    private $userRoles = null;
    private $userPermissions = null;
    private $cacheExpiry = 300; // 5 minutes
    
    public function __construct($pdo, $userId = null) {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }
    
    /**
     * Set the current user ID
     */
    public function setUserId($userId) {
        $this->userId = $userId;
        $this->clearCache();
    }
    
    /**
     * Clear the permissions cache
     */
    public function clearCache() {
        $this->userRoles = null;
        $this->userPermissions = null;
    }
    
    /**
     * Check if user has a specific permission
     * 
     * @param string $resource - Resource name (cpu, ram, storage, etc.)
     * @param string $action - Action name (create, read, update, delete)
     * @param string $resourceId - Optional specific resource UUID
     * @return bool
     */
    public function hasPermission($resource, $action, $resourceId = null) {
        if (!$this->userId) {
            $this->logAccess($resource, $action, $resourceId, 'denied', 'No user ID');
            return false;
        }
        
        // Check if user is super admin
        if ($this->isSuperAdmin()) {
            $this->logAccess($resource, $action, $resourceId, 'granted', 'Super admin');
            return true;
        }
        
        // Build permission name
        $permissionName = $resource . '.' . $action;
        
        // Check role-based permissions
        $hasRolePermission = $this->hasRolePermission($permissionName);
        
        // Check resource-specific permissions (if they exist)
        $hasResourcePermission = $this->hasResourcePermission($resource, $action, $resourceId);
        
        // Resource-specific permissions override role permissions
        if ($hasResourcePermission !== null) {
            $result = $hasResourcePermission ? 'granted' : 'denied';
            $this->logAccess($resource, $action, $resourceId, $result, 'Resource-specific permission');
            return $hasResourcePermission;
        }
        
        $result = $hasRolePermission ? 'granted' : 'denied';
        $this->logAccess($resource, $action, $resourceId, $result, 'Role-based permission');
        return $hasRolePermission;
    }
    
    /**
     * Check if user has any of the specified permissions
     * 
     * @param array $permissions - Array of ['resource' => 'action'] pairs
     * @return bool
     */
    public function hasAnyPermission($permissions) {
        foreach ($permissions as $resource => $action) {
            if ($this->hasPermission($resource, $action)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if user has all of the specified permissions
     * 
     * @param array $permissions - Array of ['resource' => 'action'] pairs
     * @return bool
     */
    public function hasAllPermissions($permissions) {
        foreach ($permissions as $resource => $action) {
            if (!$this->hasPermission($resource, $action)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Check if user has a specific role
     * 
     * @param string $roleName
     * @return bool
     */
    public function hasRole($roleName) {
        $roles = $this->getUserRoles();
        return in_array($roleName, array_column($roles, 'name'));
    }
    
    /**
     * Check if user is super admin
     * 
     * @return bool
     */
    public function isSuperAdmin() {
        return $this->hasRole('super_admin');
    }
    
    /**
     * Get all user roles
     * 
     * @return array
     */
    public function getUserRoles() {
        if ($this->userRoles !== null) {
            return $this->userRoles;
        }
        
        if (!$this->userId) {
            return [];
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT r.id, r.name, r.display_name, r.description, ur.expires_at
                FROM user_roles ur
                JOIN roles r ON ur.role_id = r.id
                WHERE ur.user_id = :user_id 
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                ORDER BY r.name
            ");
            $stmt->bindParam(':user_id', $this->userId, PDO::PARAM_INT);
            $stmt->execute();
            
            $this->userRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $this->userRoles;
        } catch (PDOException $e) {
            error_log("ACL Error getting user roles: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all user permissions
     * 
     * @return array
     */
    public function getUserPermissions() {
        if ($this->userPermissions !== null) {
            return $this->userPermissions;
        }
        
        if (!$this->userId) {
            return [];
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT p.name, p.display_name, p.description, p.resource, p.action
                FROM user_roles ur
                JOIN role_permissions rp ON ur.role_id = rp.role_id
                JOIN permissions p ON rp.permission_id = p.id
                WHERE ur.user_id = :user_id 
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                ORDER BY p.resource, p.action
            ");
            $stmt->bindParam(':user_id', $this->userId, PDO::PARAM_INT);
            $stmt->execute();
            
            $this->userPermissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $this->userPermissions;
        } catch (PDOException $e) {
            error_log("ACL Error getting user permissions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Assign role to user
     * 
     * @param int $userId
     * @param string $roleName
     * @param int $assignedBy - User ID who is assigning the role
     * @param string $expiresAt - Optional expiration date (Y-m-d H:i:s format)
     * @return bool
     */
    public function assignRole($userId, $roleName, $assignedBy = null, $expiresAt = null) {
        try {
            // Get role ID
            $stmt = $this->pdo->prepare("SELECT id FROM roles WHERE name = :role_name");
            $stmt->bindParam(':role_name', $roleName);
            $stmt->execute();
            $role = $stmt->fetch();
            
            if (!$role) {
                error_log("ACL Error: Role '$roleName' not found");
                return false;
            }
            
            // Check if user already has this role
            $stmt = $this->pdo->prepare("
                SELECT id FROM user_roles 
                WHERE user_id = :user_id AND role_id = :role_id
            ");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':role_id', $role['id'], PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->fetch()) {
                return true; // User already has this role
            }
            
            // Assign the role
            $stmt = $this->pdo->prepare("
                INSERT INTO user_roles (user_id, role_id, assigned_by, expires_at)
                VALUES (:user_id, :role_id, :assigned_by, :expires_at)
            ");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':role_id', $role['id'], PDO::PARAM_INT);
            $stmt->bindParam(':assigned_by', $assignedBy, PDO::PARAM_INT);
            $stmt->bindParam(':expires_at', $expiresAt);
            
            $result = $stmt->execute();
            
            if ($result && $userId == $this->userId) {
                $this->clearCache();
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("ACL Error assigning role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove role from user
     * 
     * @param int $userId
     * @param string $roleName
     * @return bool
     */
    public function removeRole($userId, $roleName) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE ur FROM user_roles ur
                JOIN roles r ON ur.role_id = r.id
                WHERE ur.user_id = :user_id AND r.name = :role_name
            ");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':role_name', $roleName);
            
            $result = $stmt->execute();
            
            if ($result && $userId == $this->userId) {
                $this->clearCache();
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("ACL Error removing role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Grant specific resource permission to user
     * 
     * @param int $userId
     * @param string $resourceType
     * @param string $permission
     * @param string $resourceId - Optional specific resource UUID
     * @param int $grantedBy - User ID who is granting the permission
     * @param string $expiresAt - Optional expiration date
     * @return bool
     */
    public function grantResourcePermission($userId, $resourceType, $permission, $resourceId = null, $grantedBy = null, $expiresAt = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO resource_permissions 
                (user_id, resource_type, resource_id, permission, granted, granted_by, expires_at)
                VALUES (:user_id, :resource_type, :resource_id, :permission, 1, :granted_by, :expires_at)
                ON DUPLICATE KEY UPDATE
                granted = 1, granted_by = :granted_by, created_at = NOW(), expires_at = :expires_at
            ");
            
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':resource_type', $resourceType);
            $stmt->bindParam(':resource_id', $resourceId);
            $stmt->bindParam(':permission', $permission);
            $stmt->bindParam(':granted_by', $grantedBy, PDO::PARAM_INT);
            $stmt->bindParam(':expires_at', $expiresAt);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("ACL Error granting resource permission: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Revoke specific resource permission from user
     * 
     * @param int $userId
     * @param string $resourceType
     * @param string $permission
     * @param string $resourceId - Optional specific resource UUID
     * @return bool
     */
    public function revokeResourcePermission($userId, $resourceType, $permission, $resourceId = null) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM resource_permissions
                WHERE user_id = :user_id 
                AND resource_type = :resource_type 
                AND permission = :permission
                AND (resource_id = :resource_id OR (:resource_id IS NULL AND resource_id IS NULL))
            ");
            
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindParam(':resource_type', $resourceType);
            $stmt->bindParam(':permission', $permission);
            $stmt->bindParam(':resource_id', $resourceId);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("ACL Error revoking resource permission: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all available roles
     * 
     * @return array
     */
    public function getAllRoles() {
        try {
            $stmt = $this->pdo->query("
                SELECT r.*, 
                    COUNT(ur.id) as user_count,
                    COUNT(rp.id) as permission_count
                FROM roles r
                LEFT JOIN user_roles ur ON r.id = ur.role_id
                LEFT JOIN role_permissions rp ON r.id = rp.role_id
                GROUP BY r.id
                ORDER BY r.name
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ACL Error getting all roles: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all available permissions
     * 
     * @return array
     */
    public function getAllPermissions() {
        try {
            $stmt = $this->pdo->query("
                SELECT * FROM permissions 
                ORDER BY resource, action
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ACL Error getting all permissions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get permissions for a specific role
     * 
     * @param string $roleName
     * @return array
     */
     public function getRolePermissions($roleName) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT p.*
                FROM roles r
                JOIN role_permissions rp ON r.id = rp.role_id
                JOIN permissions p ON rp.permission_id = p.id
                WHERE r.name = :role_name
                ORDER BY p.resource, p.action
            ");
            $stmt->bindParam(':role_name', $roleName);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ACL Error getting role permissions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create a new role
     * 
     * @param string $name
     * @param string $displayName
     * @param string $description
     * @param bool $isSystemRole
     * @return bool|int - Returns role ID on success, false on failure
     */
    public function createRole($name, $displayName, $description = null, $isSystemRole = false) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO roles (name, display_name, description, is_system_role)
                VALUES (:name, :display_name, :description, :is_system_role)
            ");
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':display_name', $displayName);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':is_system_role', $isSystemRole, PDO::PARAM_BOOL);
            
            if ($stmt->execute()) {
                return $this->pdo->lastInsertId();
            }
            return false;
        } catch (PDOException $e) {
            error_log("ACL Error creating role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete a role (only non-system roles)
     * 
     * @param string $roleName
     * @return bool
     */
    public function deleteRole($roleName) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM roles 
                WHERE name = :role_name AND is_system_role = 0
            ");
            $stmt->bindParam(':role_name', $roleName);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("ACL Error deleting role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Assign permission to role
     * 
     * @param string $roleName
     * @param string $permissionName
     * @return bool
     */
    public function assignPermissionToRole($roleName, $permissionName) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO role_permissions (role_id, permission_id)
                SELECT r.id, p.id
                FROM roles r, permissions p
                WHERE r.name = :role_name AND p.name = :permission_name
            ");
            $stmt->bindParam(':role_name', $roleName);
            $stmt->bindParam(':permission_name', $permissionName);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("ACL Error assigning permission to role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove permission from role
     * 
     * @param string $roleName
     * @param string $permissionName
     * @return bool
     */
    public function removePermissionFromRole($roleName, $permissionName) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE rp FROM role_permissions rp
                JOIN roles r ON rp.role_id = r.id
                JOIN permissions p ON rp.permission_id = p.id
                WHERE r.name = :role_name AND p.name = :permission_name
            ");
            $stmt->bindParam(':role_name', $roleName);
            $stmt->bindParam(':permission_name', $permissionName);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("ACL Error removing permission from role: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get access audit log
     * 
     * @param int $limit
     * @param int $offset
     * @param array $filters
     * @return array
     */
    public function getAuditLog($limit = 50, $offset = 0, $filters = []) {
        try {
            $whereConditions = [];
            $params = [];
            
            if (!empty($filters['user_id'])) {
                $whereConditions[] = "al.user_id = :user_id";
                $params[':user_id'] = $filters['user_id'];
            }
            
            if (!empty($filters['resource_type'])) {
                $whereConditions[] = "al.resource_type = :resource_type";
                $params[':resource_type'] = $filters['resource_type'];
            }
            
            if (!empty($filters['result'])) {
                $whereConditions[] = "al.result = :result";
                $params[':result'] = $filters['result'];
            }
            
            if (!empty($filters['date_from'])) {
                $whereConditions[] = "al.created_at >= :date_from";
                $params[':date_from'] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = "al.created_at <= :date_to";
                $params[':date_to'] = $filters['date_to'];
            }
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            $stmt = $this->pdo->prepare("
                SELECT al.*, u.username, u.email
                FROM acl_audit_log al
                LEFT JOIN users u ON al.user_id = u.id
                $whereClause
                ORDER BY al.created_at DESC
                LIMIT :limit OFFSET :offset
            ");
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("ACL Error getting audit log: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user statistics
     * 
     * @return array
     */
    public function getUserStats() {
        try {
            $stats = [];
            
            // Total users by role
            $stmt = $this->pdo->query("
                SELECT r.display_name, COUNT(ur.user_id) as user_count
                FROM roles r
                LEFT JOIN user_roles ur ON r.id = ur.role_id 
                    AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                GROUP BY r.id, r.display_name
                ORDER BY user_count DESC
            ");
            $stats['users_by_role'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Recent access attempts
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_attempts,
                    SUM(CASE WHEN result = 'granted' THEN 1 ELSE 0 END) as granted,
                    SUM(CASE WHEN result = 'denied' THEN 1 ELSE 0 END) as denied
                FROM acl_audit_log 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stats['daily_access'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Most accessed resources
            $stmt = $this->pdo->query("
                SELECT resource_type, COUNT(*) as access_count
                FROM acl_audit_log
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAYS)
                    AND resource_type IS NOT NULL
                GROUP BY resource_type
                ORDER BY access_count DESC
                LIMIT 10
            ");
            $stats['popular_resources'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $stats;
        } catch (PDOException $e) {
            error_log("ACL Error getting user stats: " . $e->getMessage());
            return [];
        }
    }
    
    // ===========================================
    // PRIVATE HELPER METHODS
    // ===========================================
    
    /**
     * Check role-based permission
     * 
     * @param string $permissionName
     * @return bool
     */
    private function hasRolePermission($permissionName) {
        $permissions = $this->getUserPermissions();
        return in_array($permissionName, array_column($permissions, 'name'));
    }
    
    /**
     * Check resource-specific permission
     * 
     * @param string $resourceType
     * @param string $action
     * @param string $resourceId
     * @return bool|null - Returns null if no specific permission exists
     */
    private function hasResourcePermission($resourceType, $action, $resourceId = null) {
        if (!$this->userId) {
            return null;
        }
        
        try {
            // Check for specific resource permission first
            if ($resourceId) {
                $stmt = $this->pdo->prepare("
                    SELECT granted FROM resource_permissions
                    WHERE user_id = :user_id 
                    AND resource_type = :resource_type
                    AND resource_id = :resource_id
                    AND permission = :permission
                    AND (expires_at IS NULL OR expires_at > NOW())
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $stmt->bindParam(':user_id', $this->userId, PDO::PARAM_INT);
                $stmt->bindParam(':resource_type', $resourceType);
                $stmt->bindParam(':resource_id', $resourceId);
                $stmt->bindParam(':permission', $action);
                $stmt->execute();
                
                $result = $stmt->fetch();
                if ($result !== false) {
                    return (bool)$result['granted'];
                }
            }
            
            // Check for general resource type permission
            $stmt = $this->pdo->prepare("
                SELECT granted FROM resource_permissions
                WHERE user_id = :user_id 
                AND resource_type = :resource_type
                AND resource_id IS NULL
                AND permission = :permission
                AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->bindParam(':user_id', $this->userId, PDO::PARAM_INT);
            $stmt->bindParam(':resource_type', $resourceType);
            $stmt->bindParam(':permission', $action);
            $stmt->execute();
            
            $result = $stmt->fetch();
            if ($result !== false) {
                return (bool)$result['granted'];
            }
            
            return null; // No specific permission found
        } catch (PDOException $e) {
            error_log("ACL Error checking resource permission: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Log access attempt
     * 
     * @param string $resource
     * @param string $action
     * @param string $resourceId
     * @param string $result
     * @param string $note
     */
    private function logAccess($resource, $action, $resourceId, $result, $note = '') {
        if (!$this->userId) {
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO acl_audit_log 
                (user_id, action, resource_type, resource_id, permission_checked, result, ip_address, user_agent)
                VALUES (:user_id, :action, :resource_type, :resource_id, :permission_checked, :result, :ip_address, :user_agent)
            ");
            
            $permissionChecked = $resource . '.' . $action;
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->bindParam(':user_id', $this->userId, PDO::PARAM_INT);
            $stmt->bindParam(':action', $note);
            $stmt->bindParam(':resource_type', $resource);
            $stmt->bindParam(':resource_id', $resourceId);
            $stmt->bindParam(':permission_checked', $permissionChecked);
            $stmt->bindParam(':result', $result);
            $stmt->bindParam(':ip_address', $ipAddress);
            $stmt->bindParam(':user_agent', $userAgent);
            
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("ACL Error logging access: " . $e->getMessage());
        }
    }
    
    /**
     * Clean up expired roles and permissions
     */
    public function cleanupExpired() {
        try {
            // Remove expired user roles
            $stmt = $this->pdo->prepare("
                DELETE FROM user_roles 
                WHERE expires_at IS NOT NULL AND expires_at <= NOW()
            ");
            $stmt->execute();
            
            // Remove expired resource permissions
            $stmt = $this->pdo->prepare("
                DELETE FROM resource_permissions 
                WHERE expires_at IS NOT NULL AND expires_at <= NOW()
            ");
            $stmt->execute();
            
            // Clean up old audit logs (older than 1 year)
            $stmt = $this->pdo->prepare("
                DELETE FROM acl_audit_log 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)
            ");
            $stmt->execute();
            
            return true;
        } catch (PDOException $e) {
            error_log("ACL Error cleaning up expired items: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Export user permissions for backup/analysis
     * 
     * @param int $userId
     * @return array
     */
    public function exportUserPermissions($userId) {
        try {
            $export = [
                'user_id' => $userId,
                'exported_at' => date('Y-m-d H:i:s'),
                'roles' => [],
                'permissions' => [],
                'resource_permissions' => []
            ];
            
            // Get user roles
            $stmt = $this->pdo->prepare("
                SELECT r.name, r.display_name, ur.assigned_at, ur.expires_at
                FROM user_roles ur
                JOIN roles r ON ur.role_id = r.id
                WHERE ur.user_id = :user_id
            ");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $export['roles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get effective permissions
            $oldUserId = $this->userId;
            $this->setUserId($userId);
            $export['permissions'] = $this->getUserPermissions();
            $this->setUserId($oldUserId);
            
            // Get resource-specific permissions
            $stmt = $this->pdo->prepare("
                SELECT resource_type, resource_id, permission, granted, created_at, expires_at
                FROM resource_permissions
                WHERE user_id = :user_id
            ");
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            $export['resource_permissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $export;
        } catch (PDOException $e) {
            error_log("ACL Error exporting user permissions: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * ACL Helper Functions
 */

/**
 * Create ACL instance for current user
 * 
 * @param PDO $pdo
 * @return ACL
 */
function createACL($pdo) {
    session_start();
    $userId = $_SESSION['id'] ?? null;
    return new ACL($pdo, $userId);
}

/**
 * Quick permission check function
 * 
 * @param PDO $pdo
 * @param string $resource
 * @param string $action
 * @param string $resourceId
 * @return bool
 */
function checkPermission($pdo, $resource, $action, $resourceId = null) {
    $acl = createACL($pdo);
    return $acl->hasPermission($resource, $action, $resourceId);
}

/**
 * Require permission or send unauthorized response
 * 
 * @param PDO $pdo
 * @param string $resource
 * @param string $action
 * @param string $resourceId
 */
function requirePermission($pdo, $resource, $action, $resourceId = null) {
    if (!checkPermission($pdo, $resource, $action, $resourceId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Access denied. You do not have permission to perform this action.',
            'required_permission' => $resource . '.' . $action
        ]);
        exit;
    }
}

?>