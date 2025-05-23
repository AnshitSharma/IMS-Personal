<?php

require_once(__DIR__ . '/../includes/db_config.php');
require_once(__DIR__ . '/../includes/config.php');

function isUserLoggedIn($pdo) {
    if (!isset($_SESSION['id'])) {
        return false;
    }
    
    $user_id = $_SESSION['id'];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :user_id");
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetch(); // Returns the user data or false
        
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
    
    echo json_encode($resp);
    exit();
}

?>