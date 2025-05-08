<?php
// api_login.php - API endpoint for user login
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

// Function to generate a random token
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

// Get the posted data
$data = json_decode(file_get_contents("php://input"), true);

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

// Validate input
if (!isset($data['username']) || !isset($data['password'])) {
    $response['message'] = 'Username and password are required';
    echo json_encode($response);
    exit;
}

$username = trim($data['username']);
$password = trim($data['password']);

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

// Prepare a select statement
$sql = "SELECT id, username, password FROM users WHERE username = ?";

if ($stmt = $conn->prepare($sql)) {
    // Bind variables to the prepared statement as parameters
    $stmt->bind_param("s", $param_username);
    
    // Set parameters
    $param_username = $username;
    
    // Execute the prepared statement
    if ($stmt->execute()) {
        // Store result
        $stmt->store_result();
        
        // Check if username exists, if yes then verify password
        if ($stmt->num_rows == 1) {                    
            // Bind result variables
            $stmt->bind_result($id, $username, $hashed_password);
            
            if ($stmt->fetch()) {
                if (password_verify($password, $hashed_password)) {
                    // Password is correct, generate authentication token
                    $auth_token = generateToken();
                    
                    // Store token in database
                    $token_sql = "INSERT INTO auth_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))";
                    
                    if ($token_stmt = $conn->prepare($token_sql)) {
                        $token_stmt->bind_param("is", $id, $auth_token);
                        
                        if ($token_stmt->execute()) {
                            // Success - prepare response
                            $response['success'] = true;
                            $response['message'] = 'Login successful';
                            $response['data'] = [
                                'user_id' => $id,
                                'username' => $username,
                                'token' => $auth_token,
                                'expires_in' => '7 days'
                            ];
                        } else {
                            $response['message'] = 'Error creating authentication token';
                        }
                        
                        $token_stmt->close();
                    } else {
                        $response['message'] = 'Error preparing token statement';
                    }
                } else {
                    // Password is not valid
                    $response['message'] = 'Invalid username or password';
                }
            }
        } else {
            // Username doesn't exist
            $response['message'] = 'Invalid username or password';
        }
    } else {
        $response['message'] = 'Error executing query';
    }
    
    // Close statement
    $stmt->close();
} else {
    $response['message'] = 'Error preparing statement';
}

// Close connection
$conn->close();

// Return JSON response
echo json_encode($response);
?>