<?php
// verify_token.php - Endpoint to verify user tokens

// Include database configuration
require_once dirname(__DIR__) . "/config/config.php";

// Include authentication functions

require_once __DIR__ . "/src/Auth/auth_functions.php";

// Set response content type to JSON
header('Content-Type: application/json');

// Default response is unauthenticated
$response = [
    'authenticated' => false,
    'username' => null
];

// Get token from request
$token = null;

// Check in Authorization header
if(isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    if(strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    }
}

// If no token in header, check in POST data
if(empty($token)) {
    // Get JSON body
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);
    
    if(isset($data['token']) && !empty($data['token'])) {
        $token = $data['token'];
    }
}

// If we have a token, verify it
if(!empty($token)) {
    $user_id = verifyAuthToken($token);
    
    if($user_id) {
        $user_data = getUserDataFromId($user_id);
        
        if($user_data) {
            // Start session and set session variables
            if(session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $_SESSION["loggedin"] = true;
            $_SESSION["id"] = $user_data["id"];
            $_SESSION["username"] = $user_data["username"];
            $_SESSION["token"] = $token;
            
            // Update response
            $response['authenticated'] = true;
            $response['username'] = $user_data["username"];
        }
    }
}

// Return JSON response
echo json_encode($response);
?>  