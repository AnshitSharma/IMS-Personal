<?php
// api_register.php - API endpoint for user registration
header('Content-Type: application/json');

// Allow cross-origin requests
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Ensure this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Method not allowed. Please use POST.']);
    exit;
}

// Include config file
require_once "config/config.php";

// Get the posted data
$data = json_decode(file_get_contents("php://input"), true);

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

// Validate input
if (!isset($data['username']) || !isset($data['password']) || !isset($data['email'])) {
    $response['message'] = 'Username, password, and email are required';
    echo json_encode($response);
    exit;
}

$username = trim($data['username']);
$password = trim($data['password']);
$email = trim($data['email']);

if (empty($username)) {
    $response['message'] = 'Username cannot be empty';
    echo json_encode($response);
    exit;
}

if (empty($password)) {
    $response['message'] = 'Password cannot be empty';
    echo json_encode($response);
    exit;
}

if (empty($email)) {
    $response['message'] = 'Email cannot be empty';
    echo json_encode($response);
    exit;
}

// Validate password length
if (strlen($password) < 6) {
    $response['message'] = 'Password must have at least 6 characters';
    echo json_encode($response);
    exit;
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $response['message'] = 'Invalid email format';
    echo json_encode($response);
    exit;
}

// Check if username already exists
$sql = "SELECT id FROM users WHERE username = ?";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $response['message'] = 'This username is already taken';
        echo json_encode($response);
        $stmt->close();
        exit;
    }
    
    $stmt->close();
}

// Check if email already exists
$sql = "SELECT id FROM users WHERE email = ?";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        $response['message'] = 'This email is already registered';
        echo json_encode($response);
        $stmt->close();
        exit;
    }
    
    $stmt->close();
}

// All validation passed, insert the new user
$sql = "INSERT INTO users (username, password, email, created_at) VALUES (?, ?, ?, NOW())";

if ($stmt = $conn->prepare($sql)) {
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt->bind_param("sss", $username, $hashed_password, $email);
    
    if ($stmt->execute()) {
        $new_user_id = $stmt->insert_id;
        
        $response['success'] = true;
        $response['message'] = 'Registration successful';
        $response['data'] = [
            'user_id' => $new_user_id,
            'username' => $username,
            'email' => $email
        ];
    } else {
        $response['message'] = 'Something went wrong. Please try again later.';
    }
    
    $stmt->close();
} else {
    $response['message'] = 'Error preparing statement';
}

// Close connection
$conn->close();

// Return JSON response
echo json_encode($response);
?>