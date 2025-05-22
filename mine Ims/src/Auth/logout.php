<?php
// logout.php - Logout user and invalidate token

// Include database configuration
require_once "config.php";

// Include authentication functions
require_once "auth_functions.php";

// Log the user out
logoutUser();

// Redirect to login page
header("location: login.php");
exit;
?>