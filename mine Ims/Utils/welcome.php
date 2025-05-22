<?php
// welcome.php - Welcome page after successful login
session_start();

// Include database configuration
require_once "config.php";

// Include authentication functions
require_once "auth_functions.php";

// Initialize token from localStorage if session is not set
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    // This page will check localStorage for token via JavaScript
    // and send it to the server using AJAX for verification
    $check_token = true;
} else {
    $check_token = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .container {
            background-color: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 600px;
            text-align: center;
        }
        h1 {
            color: #333;
        }
        p {
            margin: 15px 0;
        }
        .logout-btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: #f44336;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin-top: 20px;
        }
        .logout-btn:hover {
            background-color: #d32f2f;
        }
        #loading {
            display: none;
            margin: 20px 0;
        }
        #content {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <div id="loading">
            <p>Verifying your authentication...</p>
        </div>
        
        <div id="content">
            <h1>Welcome, <span id="username"><?php echo isset($_SESSION["username"]) ? htmlspecialchars($_SESSION["username"]) : ""; ?></span>!</h1>
            <p>You have successfully logged into the secure area.</p>
            
            <a href="logout.php" class="logout-btn">Sign Out</a>
        </div>
        
        <div id="not-authenticated" style="display: none;">
            <h1>Authentication Required</h1>
            <p>You are not logged in or your session has expired.</p>
            <p><a href="login.php">Click here to login</a></p>
        </div>
    </div>

    <script>
        // Function to verify token with server
        function verifyToken(token) {
            return fetch('verify_token.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token
                },
                body: JSON.stringify({ token: token })
            })
            .then(response => response.json());
        }
        
        // Check authentication state
        document.addEventListener('DOMContentLoaded', function() {
            <?php if($check_token): ?>
                // Show loading indicator
                document.getElementById('loading').style.display = 'block';
                
                // Get token from localStorage
                const token = localStorage.getItem('auth_token');
                
                if (token) {
                    // Verify token with server
                    verifyToken(token)
                        .then(data => {
                            if (data.authenticated) {
                                // Update username
                                document.getElementById('username').textContent = data.username;
                                
                                // Show content
                                document.getElementById('content').style.display = 'block';
                                document.getElementById('loading').style.display = 'none';
                            } else {
                                // Token is invalid
                                localStorage.removeItem('auth_token');
                                localStorage.removeItem('token_created');
                                
                                // Show not authenticated message
                                document.getElementById('not-authenticated').style.display = 'block';
                                document.getElementById('loading').style.display = 'none';
                            }
                        })
                        .catch(error => {
                            console.error('Error verifying token:', error);
                            
                            // Show not authenticated message
                            document.getElementById('not-authenticated').style.display = 'block';
                            document.getElementById('loading').style.display = 'none';
                        });
                } else {
                    // No token found
                    document.getElementById('not-authenticated').style.display = 'block';
                    document.getElementById('loading').style.display = 'none';
                }
            <?php else: ?>
                // Already authenticated through session
                document.getElementById('content').style.display = 'block';
            <?php endif; ?>
        });
    </script>
</body>
</html>