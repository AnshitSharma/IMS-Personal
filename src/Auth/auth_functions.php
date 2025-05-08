<?php
// auth_functions.php - Helper functions for token authentication

/**
 * Verify a user's authentication token
 * 
 * @param string $token The authentication token to verify
 * @return int|false User ID if token is valid, false otherwise
 */
function verifyAuthToken($token) {
    global $conn;
    
    // Clean the token to prevent SQL injection
    $token = trim($token);
    
    if(empty($token)) {
        return false;
    }
    
    // Prepare a select statement to find the token and check if it's expired
    $sql = "SELECT user_id, expires_at FROM auth_tokens WHERE token = ? AND expires_at > NOW()";
    
    if($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("s", $token);
        
        if($stmt->execute()) {
            $stmt->store_result();
            
            if($stmt->num_rows == 1) {
                $stmt->bind_result($user_id, $expires_at);
                if($stmt->fetch()) {
                    // Update the last_used_at timestamp
                    $update_sql = "UPDATE auth_tokens SET last_used_at = NOW() WHERE token = ?";
                    if($update_stmt = $conn->prepare($update_sql)) {
                        $update_stmt->bind_param("s", $token);
                        $update_stmt->execute();
                        $update_stmt->close();
                    }
                    
                    return $user_id;
                }
            }
        }
        
        $stmt->close();
    }
    
    return false;
}

/**
 * Get user data from ID
 * 
 * @param int $user_id The user ID to look up
 * @return array|false User data if found, false otherwise
 */
function getUserDataFromId($user_id) {
    global $conn;
    
    $sql = "SELECT id, username FROM users WHERE id = ?";
    
    if($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $user_id);
        
        if($stmt->execute()) {
            $result = $stmt->get_result();
            
            if($row = $result->fetch_assoc()) {
                return $row;
            }
        }
        
        $stmt->close();
    }
    
    return false;
}

/**
 * Check token from local storage and set up session if valid
 * 
 * This function should be called at the beginning of pages that require authentication
 */
function checkTokenAndInitSession() {
    // Start session if not already started
    if(session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // If user is already logged in via session, do nothing
    if(isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
        return true;
    }
    
    // Check for token in request headers (for API requests)
    $token = null;
    if(isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
        if(strpos($auth_header, 'Bearer ') === 0) {
            $token = substr($auth_header, 7);
        }
    }
    
    // If we still don't have a token, check the session
    if(empty($token) && isset($_SESSION["token"])) {
        $token = $_SESSION["token"];
    }
    
    // If we have a token, verify it
    if(!empty($token)) {
        $user_id = verifyAuthToken($token);
        
        if($user_id) {
            $user_data = getUserDataFromId($user_id);
            
            if($user_data) {
                // Set up session
                $_SESSION["loggedin"] = true;
                $_SESSION["id"] = $user_data["id"];
                $_SESSION["username"] = $user_data["username"];
                $_SESSION["token"] = $token;
                
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Logout user by invalidating token and session
 */
function logoutUser() {
    global $conn;
    
    // Start session if not already started
    if(session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // If there's a token in the session, invalidate it in the database
    if(isset($_SESSION["token"])) {
        $token = $_SESSION["token"];
        
        $sql = "DELETE FROM auth_tokens WHERE token = ?";
        if($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $stmt->close();
        }
    }
    
    // Clear all session variables
    $_SESSION = array();
    
    // Destroy the session
    session_destroy();
    
    // JavaScript to clear localStorage
    echo "<script>localStorage.removeItem('auth_token'); localStorage.removeItem('token_created');</script>";
}
?>