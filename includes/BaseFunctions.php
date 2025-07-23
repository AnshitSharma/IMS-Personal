<?php
/**
 * BaseFunctions.php with JWT functionality
 * Replace your existing BaseFunctions.php with this version
 */

// Include JWT Helper
require_once(__DIR__ . '/JWTHelper.php');

/**
 * Initialize JWT with secret key
 */
function initializeJWT() {
    $jwtSecret = getenv('JWT_SECRET') ?: 'bdc-ims-jwt-secret-key-change-in-production-2025';
    JWTHelper::init($jwtSecret);
}

// Initialize JWT on file load
initializeJWT();

/**
 * Safe session start
 */
if (!function_exists('safeSessionStart')) {
    function safeSessionStart() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

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
 * Send JSON response
 */
if (!function_exists('send_json_response')) {
    function send_json_response($success, $authenticated, $code, $message, $data = null) {
        http_response_code($code);
        
        $response = [
            'success' => (bool)$success,
            'authenticated' => (bool)$authenticated,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
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
            return JWTHelper::validateRequest($pdo);
        } catch (Exception $e) {
            error_log("JWT Authentication failed: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Require JWT authentication
 */
if (!function_exists('requireJWTAuth')) {
    function requireJWTAuth($pdo) {
        $user = authenticateWithJWT($pdo);
        if (!$user) {
            http_response_code(401);
            send_json_response(0, 0, 401, "Authentication required - invalid or missing token");
        }
        return $user;
    }
}

/**
 * Session-based authentication check (for backward compatibility)
 */
if (!function_exists('isUserLoggedIn')) {
    function isUserLoggedIn($pdo) {
        safeSessionStart();
        
        if (!isset($_SESSION['id'])) {
            return false;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT id, username, email, created_at FROM users WHERE id = ?");
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
 * Hybrid authentication - Try JWT first, then session
 */
if (!function_exists('requireLogin')) {
    function requireLogin($pdo) {
        // Try JWT authentication first
        $user = authenticateWithJWT($pdo);
        
        if (!$user) {
            // Fallback to session authentication
            $user = isUserLoggedIn($pdo);
        }
        
        if (!$user) {
            http_response_code(401);
            send_json_response(0, 0, 401, "Authentication required");
        }
        
        return $user;
    }
}

/**
 * Simple admin check
 */
if (!function_exists('isAdmin')) {
    function isAdmin($pdo, $userId = null) {
        if (!$userId) {
            // Try to get user ID from JWT or session
            $user = authenticateWithJWT($pdo);
            if (!$user) {
                safeSessionStart();
                $userId = $_SESSION['id'] ?? null;
            } else {
                $userId = $user['id'];
            }
        }
        
        if (!$userId) {
            return false;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND (id = 1 OR username = 'admin')");
            $stmt->execute([$userId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Error checking admin status: " . $e->getMessage());
            return false;
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
 * User authentication with JWT token generation
 */
if (!function_exists('authenticateUser')) {
    function authenticateUser($pdo, $username, $password) {
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
                
                // Generate JWT token
                $tokenPayload = [
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email']
                ];
                
                $accessToken = JWTHelper::generateToken($tokenPayload, 3600); // 1 hour
                $refreshToken = JWTHelper::generateRefreshToken();
                
                // Store refresh token in database
                JWTHelper::storeRefreshToken($pdo, $user['id'], $refreshToken, 2592000); // 30 days
                
                return [
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'firstname' => $user['firstname'],
                        'lastname' => $user['lastname']
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
 * Simple activity logging
 */
if (!function_exists('logActivity')) {
    function logActivity($pdo, $userId, $action, $componentType = null, $componentId = null, $details = null) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO inventory_log (component_type, component_id, new_status, changed_by, notes, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$componentType ?: 'system', $componentId, $action, $userId, $details]);
        } catch (PDOException $e) {
            error_log("Error logging activity: " . $e->getMessage());
        }
    }
}

/**
 * Get component status text
 */
if (!function_exists('getStatusText')) {
    function getStatusText($status) {
        switch ($status) {
            case 0: return 'Failed';
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
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error getting user info: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Simple user creation
 */
if (!function_exists('createUser')) {
    function createUser($pdo, $username, $email, $password, $firstname = null, $lastname = null) {
        try {
            $passwordHash = hashPassword($password);
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, firstname, lastname, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            
            if ($stmt->execute([$username, $email, $passwordHash, $firstname, $lastname])) {
                return $pdo->lastInsertId();
            }
            return false;
        } catch (PDOException $e) {
            error_log("Error creating user: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Get system statistics
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
            }
            
            $stmt = $pdo->query("SELECT COUNT(*) FROM users");
            $stats['users'] = ['total' => $stmt->fetchColumn()];
            
        } catch (PDOException $e) {
            error_log("Error getting system stats: " . $e->getMessage());
        }
        
        return $stats;
    }
}

?>