<?php
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/QueryModel.php');

// Initialize variables to store form data and errors
$username = $email = $password = $firstname = $lastname = "";
$errors = [];
$success_message = "";

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate username
    if (empty($_POST["username"])) {
        $errors["username"] = "Username is required";
    } else {
        $username = trim($_POST["username"]);
        if (!preg_match("/^[a-zA-Z0-9_]{3,20}$/", $username)) {
            $errors["username"] = "Username must be 3-20 characters and contain only letters, numbers, and underscores";
        }
    }
    
    // Validate email
    if (empty($_POST["email"])) {
        $errors["email"] = "Email is required";
    } else {
        $email = trim($_POST["email"]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors["email"] = "Invalid email format";
        }
    }
    
    // Validate password
    if (empty($_POST["password"])) {
        $errors["password"] = "Password is required";
    } else {
        $password = $_POST["password"];
        if (strlen($password) < 8) {
            $errors["password"] = "Password must be at least 8 characters";
        }
    }
    
    if (empty($_POST["firstname"])) {
        $errors["firstname"] = "First name is required";
    } else {
        $firstname = trim($_POST["firstname"]);
    }
    
    if (empty($_POST["lastname"])) {
        $errors["lastname"] = "Last name is required";
    } else {
        $lastname = trim($_POST["lastname"]);
    }
    
    // If no errors, proceed with registration
    if (empty($errors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // PDO prepared statement
            $stmt = $pdo->prepare("INSERT INTO users (firstname, lastname, username, email, password) VALUES (:firstname, :lastname, :username, :email, :password)");
            
            $stmt->bindParam(':firstname', $firstname, PDO::PARAM_STR);
            $stmt->bindParam(':lastname', $lastname, PDO::PARAM_STR);
            $stmt->bindParam(':username', $username, PDO::PARAM_STR);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->bindParam(':password', $hashed_password, PDO::PARAM_STR);

            if ($stmt->execute()) {
                $success_message = "User registered successfully!";
                // Clear form fields after successful submission
                $username = $email = $password = $firstname = $lastname = "";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) { // Duplicate entry error
                $errors["general"] = "Username or email already exists";
            } else {
                $errors["general"] = "Registration failed. Please try again.";
                error_log("Signup error: " . $e->getMessage());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            text-align: center;
            color: #333;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .error {
            color: #ff0000;
            font-size: 14px;
            margin-top: 5px;
        }
        .success {
            color: #4CAF50;
            font-size: 16px;
            text-align: center;
            margin-bottom: 20px;
            padding: 10px;
            background-color: #e8f5e9;
            border-radius: 4px;
        }
        button {
            background-color: #4CAF50;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
            font-size: 16px;
        }
        button:hover {
            background-color: #45a049;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Sign Up</h1>
        
        <?php if (!empty($success_message)): ?>
            <div class="success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if (!empty($errors["general"])): ?>
            <div class="error"><?php echo $errors["general"]; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($username); ?>">
                <?php if (!empty($errors["username"])): ?>
                    <div class="error"><?php echo $errors["username"]; ?></div>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <?php if (!empty($errors["email"])): ?>
                    <div class="error"><?php echo $errors["email"]; ?></div>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password">
                <?php if (!empty($errors["password"])): ?>
                    <div class="error"><?php echo $errors["password"]; ?></div>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="firstname">First Name:</label>
                <input type="text" id="firstname" name="firstname" value="<?php echo htmlspecialchars($firstname); ?>">
                <?php if (!empty($errors["firstname"])): ?>
                    <div class="error"><?php echo $errors["firstname"]; ?></div>
                <?php endif; ?>
            </div>
            
            <div class="form-group">
                <label for="lastname">Last Name:</label>
                <input type="text" id="lastname" name="lastname" value="<?php echo htmlspecialchars($lastname); ?>">
                <?php if (!empty($errors["lastname"])): ?>
                    <div class="error"><?php echo $errors["lastname"]; ?></div>
                <?php endif; ?>
            </div>
            
            <button type="submit">Sign Up</button>
        </form>
    </div>
</body>
</html>