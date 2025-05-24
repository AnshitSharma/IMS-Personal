<?php


if (!isset($pdo)) {
    require_once(__DIR__ . '/db_config.php');
}

// Only include config if not already included
if (!defined('MAIN_SITE_URL')) {
    require_once(__DIR__ . '/config.php');
}

function isUserLoggedIn($pdo) {
    // Check if session is started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
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

function send_json_response($logged_in, $success, $status_code, $message, $other_params = []) {
    
    $resp = [
        'is_logged_in' => $logged_in,
        'status_code' => $status_code,
        'success' => $success,
        'message' => $message
    ];
    
    if ($other_params != []) {
        $resp = array_merge($resp, $other_params);
    }
    
    // Set proper JSON header
    header('Content-Type: application/json');
    
    echo json_encode($resp);
    exit();
}

?>