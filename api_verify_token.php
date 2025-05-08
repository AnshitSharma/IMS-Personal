<?php
// api_verify_token.php - API endpoint to verify a token
header('Content-Type: application/json');

// Allow cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include config file
require_once __DIR__ . "/config/config.php";
require_once __DIR__ . "/src/Auth/auth_functions.php";

// Initialize response array
$response = [
    'authenticated' => false,
    'username' => null,
    'user_id' => null
];

// Get token from Authorization header
$token = null;
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = $_SERVER['HTTP_AUTHORIZATION'];
    if (strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
    }
}

// If no token in header, check in request data
if (empty($token)) {
    // Check GET parameters
    if (isset($_GET['token'])) {
        $token = $_GET['token'];
    } else {
        // Check POST data
        $data = json_decode(file_get_contents("php://input"), true);
        if (isset($data['token'])) {
            $token = $data['token'];
        }
    }
}

// If we have a token, verify it
if (!empty($token)) {
    $user_id = verifyAuthToken($token);
    
    if ($user_id) {
        $user_data = getUserDataFromId($user_id);
        
        if ($user_data) {
            // Token is valid
            $response['authenticated'] = true;
            $response['username'] = $user_data['username'];
            $response['user_id'] = $user_id;
        }
    }
}

// Return JSON response
echo json_encode($response);
?>