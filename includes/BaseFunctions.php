<?php

require_once(__DIR__ . '/../includes/db_config.php');
require_once(__DIR__ . '/../includes/config.php');

function isUserLoggedIn($mysqli) {
    if (!isset($_SESSION['id'])) {
        return false;
    }
    $user_id = $_SESSION['id'];
    $stmt = $mysqli->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    return ($result && $result->fetch_assoc());
}

function send_json_response($logged_in, $success, $status_code, $message, $other_params=[]) {
        
    $resp = [
        'is_logged_in' => $logged_in,
        'status_code' => $status_code,
        'success' => $success,
        'message' => $message
    ];
    
    if($other_params != []){
        $resp = array_merge($resp, $other_params);
    }
    
    echo json_encode($resp);
    exit();
}

?>