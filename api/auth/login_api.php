<?php

require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json_response(0, 0, 405, "Method Not Allowed");
}

$input = json_decode(file_get_contents('php://input'), true);

$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    send_json_response(0, 0, 400, "Please enter both username and password");
}

try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->bindParam(':username', $username, PDO::PARAM_STR);
    $stmt->execute();
    
    if ($stmt->rowCount() == 1) {
        $user = $stmt->fetch();
        $hash_user_password = $user['password'];
        
        if (password_verify($password, $hash_user_password)) {
            session_start();
            $_SESSION["loggedin"] = true;
            $_SESSION["id"] = $user["id"];
            $_SESSION["username"] = $username;
            $_SESSION["email"] = $user["email"];
            
            send_json_response(1, 1, 200, "Login successful", [
                'user' => [
                    'id' => $user["id"],
                    'username' => $username,
                    'email' => $user["email"],
                    'firstname' => $user["firstname"],
                    'lastname' => $user["lastname"]
                ],
                'session_id' => session_id()
            ]);
        } else {
            send_json_response(0, 0, 401, "Invalid username or password");
        }
    } else {
        send_json_response(0, 0, 401, "User not found");
    }
} catch (PDOException $e) {
    error_log("Login error: " . $e->getMessage());
    send_json_response(0, 0, 500, "Database error occurred");
}
?>