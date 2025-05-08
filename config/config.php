<?php
// config.php - Database configuration
$servername = "localhost";
$username = "root"; // Change this if your database username is different
$password = ""; // Change this if your database has a password
$dbname = "ims_clients"; // Name of database we'll create

// Create connection
$conn = new mysqli($servername, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if it doesn't exist
$sql = "CREATE DATABASE IF NOT EXISTS $dbname";
if ($conn->query($sql) !== TRUE) {
    echo "Error creating database: " . $conn->error;
}

// Select the database
$conn->select_db($dbname);

// Create users table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(30) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($sql) !== TRUE) {   
    echo "Error creating table: " . $conn->error;
}

// Create auth_tokens table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS auth_tokens (
    id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT(6) UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    last_used_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";

if ($conn->query($sql) !== TRUE) {
    echo "Error creating auth_tokens table: " . $conn->error;
}

// Create CPUInventory table
$sql = "CREATE TABLE IF NOT EXISTS CPUInventory (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    UUID VARCHAR(36) UNIQUE NOT NULL COMMENT 'Links to detailed specs in JSON',
    SerialNumber VARCHAR(50) UNIQUE COMMENT 'Manufacturer serial number',
    Status ENUM('In Use', 'In Stock', 'Maintenance', 'Decommissioned', 'Failed') NOT NULL,
    ServerUUID VARCHAR(36) NULL COMMENT 'UUID of server where CPU is installed, if any',
    Location VARCHAR(100) COMMENT 'Physical location like datacenter, warehouse',
    RackPosition VARCHAR(20) COMMENT 'Specific rack/shelf position',
    
    PurchaseDate DATE,
    InstallationDate DATE COMMENT 'When installed in current server',
    WarrantyEndDate DATE,
    
    Flag VARCHAR(50) COMMENT 'Quick status flag or category',
    Notes TEXT COMMENT 'Any additional info or history',
    
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) !== TRUE) {
    echo "Error creating CPUInventory table: " . $conn->error;
}

// Create RAMInventory table
$sql = "CREATE TABLE IF NOT EXISTS RAMInventory (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    UUID VARCHAR(36) UNIQUE NOT NULL COMMENT 'Links to detailed specs in JSON',
    SerialNumber VARCHAR(50) UNIQUE COMMENT 'Manufacturer serial number',
    Status ENUM('In Use', 'In Stock', 'Maintenance', 'Decommissioned', 'Failed') NOT NULL,
    ServerUUID VARCHAR(36) NULL COMMENT 'UUID of server where RAM is installed, if any',
    Location VARCHAR(100) COMMENT 'Physical location like datacenter, warehouse',
    RackPosition VARCHAR(20) COMMENT 'Specific rack/shelf position',
    
    PurchaseDate DATE,
    InstallationDate DATE COMMENT 'When installed in current server',
    WarrantyEndDate DATE,
    
    Flag VARCHAR(50) COMMENT 'Quick status flag or category',
    Notes TEXT COMMENT 'Any additional info or history',
    
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) !== TRUE) {
    echo "Error creating RAMInventory table: " . $conn->error;
}

// Create MotherboardInventory table
$sql = "CREATE TABLE IF NOT EXISTS MotherboardInventory (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    UUID VARCHAR(36) UNIQUE NOT NULL COMMENT 'Links to detailed specs in JSON',
    SerialNumber VARCHAR(50) UNIQUE COMMENT 'Manufacturer serial number',
    Status ENUM('In Use', 'In Stock', 'Maintenance', 'Decommissioned', 'Failed') NOT NULL,
    ServerUUID VARCHAR(36) NULL COMMENT 'UUID of server where motherboard is installed, if any',
    Location VARCHAR(100) COMMENT 'Physical location like datacenter, warehouse',
    RackPosition VARCHAR(20) COMMENT 'Specific rack/shelf position',
    
    PurchaseDate DATE,
    InstallationDate DATE COMMENT 'When installed in current server',
    WarrantyEndDate DATE,
    
    Flag VARCHAR(50) COMMENT 'Quick status flag or category',
    Notes TEXT COMMENT 'Any additional info or history',
    
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) !== TRUE) {
    echo "Error creating MotherboardInventory table: " . $conn->error;
}

// Create StorageInventory table
$sql = "CREATE TABLE IF NOT EXISTS StorageInventory (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    UUID VARCHAR(36) UNIQUE NOT NULL COMMENT 'Links to detailed specs in JSON', 
    SerialNumber VARCHAR(50) UNIQUE COMMENT 'Manufacturer serial number',
    Status ENUM('In Use', 'In Stock', 'Maintenance', 'Decommissioned', 'Failed') NOT NULL,
    ServerUUID VARCHAR(36) NULL COMMENT 'UUID of server where storage is installed, if any',
    Location VARCHAR(100) COMMENT 'Physical location like datacenter, warehouse',
    RackPosition VARCHAR(20) COMMENT 'Specific rack/shelf position',
    
    PurchaseDate DATE,
    InstallationDate DATE COMMENT 'When installed in current server',
    WarrantyEndDate DATE,
    
    Flag VARCHAR(50) COMMENT 'Quick status flag or category',
    Notes TEXT COMMENT 'Any additional info or history',
    
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) !== TRUE) {
    echo "Error creating StorageInventory table: " . $conn->error;
}

// Create CaddyInventory table
$sql = "CREATE TABLE IF NOT EXISTS CaddyInventory (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    UUID VARCHAR(36) UNIQUE NOT NULL COMMENT 'Links to detailed specs in JSON',
    SerialNumber VARCHAR(50) UNIQUE COMMENT 'Manufacturer serial number',
    Status ENUM('In Use', 'In Stock', 'Maintenance', 'Decommissioned', 'Failed') NOT NULL,
    ServerUUID VARCHAR(36) NULL COMMENT 'UUID of server where caddy is installed, if any',
    Location VARCHAR(100) COMMENT 'Physical location like datacenter, warehouse',
    RackPosition VARCHAR(20) COMMENT 'Specific rack/shelf position',
    
    PurchaseDate DATE,
    InstallationDate DATE COMMENT 'When installed in current server',
    WarrantyEndDate DATE,
    
    Flag VARCHAR(50) COMMENT 'Quick status flag or category',
    Notes TEXT COMMENT 'Any additional info or history',
    
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) !== TRUE) {
    echo "Error creating CaddyInventory table: " . $conn->error;
}


// Create NICInventory table
$sql = "CREATE TABLE IF NOT EXISTS NICInventory (
    ID INT PRIMARY KEY AUTO_INCREMENT,
    UUID VARCHAR(36) UNIQUE NOT NULL COMMENT 'Links to detailed specs in JSON',
    SerialNumber VARCHAR(50) UNIQUE COMMENT 'Manufacturer serial number',
    Status ENUM('In Use', 'In Stock', 'Maintenance', 'Decommissioned', 'Failed') NOT NULL,
    ServerUUID VARCHAR(36) NULL COMMENT 'UUID of server where NIC is installed, if any',
    Location VARCHAR(100) COMMENT 'Physical location like datacenter, warehouse',
    RackPosition VARCHAR(20) COMMENT 'Specific rack/shelf position',
    
    MacAddress VARCHAR(17) COMMENT 'MAC address of the NIC',
    IPAddress VARCHAR(45) COMMENT 'IP address assigned to the NIC, if any',
    NetworkName VARCHAR(100) COMMENT 'Name of the network the NIC is connected to',
    
    PurchaseDate DATE,
    InstallationDate DATE COMMENT 'When installed in current server',
    WarrantyEndDate DATE,
    
    Flag VARCHAR(50) COMMENT 'Quick status flag or category',
    Notes TEXT COMMENT 'Any additional info or history',
    
    CreatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) !== TRUE) {
    echo "Error creating NICInventory table: " . $conn->error;
}

echo "Database and tables created successfully";
?>