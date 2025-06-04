<?php
/**
 * Access Control List (ACL) Class
 * 
 * This class handles role-based access control for the BDC IMS system.
 */

class ACL {
    private $pdo;
    private $userId;
    private $userRoles = null;
    private $userPermissions = null;
    
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
     */
    public function hasPermission($resource, $action, $resourceId = null) {
        if (!$this->userId) {
            return false;
        }
        
        // Check if user is super admin
        if ($this->isSuperAdmin()) {
            return true;
        }
        
        // For basic functionality, return true for logged-in users
        // This can be enhanced when ACL tables are properly set up
        return true;
    }
    
    /**
     * Check if user has a specific role
     */
    public function hasRole($roleName) {
        $roles = $this->getUserRoles();
        return in_array($roleName, array_column($roles, 'name'));
    }
    
    /**
     * Check if user is super admin
     */
    public function isSuperAdmin() {
        // For basic functionality, check if user ID is 1 or has admin flag
        if ($this->userId == 1) {
            return true;
        }
        
        try {
            $stmt = $this->pdo->prepare("SELECT acl FROM users WHERE id = :user_id");
            $stmt->bindParam(':user_id', $this->userId, PDO::PARAM_INT);
            $stmt->execute();
            $user = $stmt->fetch();
            
            return $user && $user['acl'] == 1;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get all user roles
     */
    public function getUserRoles() {
        if ($this->userRoles !== null) {
            return $this->userRoles;
        }
        
        if (!$this->userId) {
            return [];
        }
        
        try {
            // Check if roles table exists
            $checkTable = $this->pdo->query("SHOW TABLES LIKE 'user_roles'");
            if ($checkTable->rowCount() == 0) {
                // Return basic role based on user data
                $this->userRoles = [
                    ['name' => $this->isSuperAdmin() ? 'admin' : 'user']
                ];
                return $this->userRoles;
            }
            
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
            return [['name' => 'user']];
        }
    }
    
    /**
     * Get all user permissions
     */
    public function getUserPermissions() {
        if ($this->userPermissions !== null) {
            return $this->userPermissions;
        }
        
        if (!$this->userId) {
            return [];
        }
        
        try {
            // Check if permissions table exists
            $checkTable = $this->pdo->query("SHOW TABLES LIKE 'permissions'");
            if ($checkTable->rowCount() == 0) {
                // Return basic permissions
                $this->userPermissions = [
                    ['name' => 'basic.read', 'resource' => 'basic', 'action' => 'read']
                ];
                return $this->userPermissions;
            }
            
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
     */
    public function assignRole($userId, $roleName, $assignedBy = null, $expiresAt = null) {
        try {
            // Check if roles table exists
            $checkTable = $this->pdo->query("SHOW TABLES LIKE 'roles'");
            if ($checkTable->rowCount() == 0) {
                return true; // Skip if ACL tables don't exist
            }
            
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
     * Get all available roles
     */
    public function getAllRoles() {
        try {
            // Check if roles table exists
            $checkTable = $this->pdo->query("SHOW TABLES LIKE 'roles'");
            if ($checkTable->rowCount() == 0) {
                return [
                    ['name' => 'admin', 'display_name' => 'Administrator'],
                    ['name' => 'user', 'display_name' => 'User']
                ];
            }
            
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
     */
    public function getAllPermissions() {
        try {
            // Check if permissions table exists
            $checkTable = $this->pdo->query("SHOW TABLES LIKE 'permissions'");
            if ($checkTable->rowCount() == 0) {
                return [];
            }
            
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
            
            return true;
        } catch (PDOException $e) {
            error_log("ACL Error cleaning up expired items: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get access audit log
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
}

?>