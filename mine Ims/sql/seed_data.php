<?php
// sql/seed_data.php - Insert dummy data into tables
require_once '../config/config.php'; // Adjust path as needed to your config file

// Function to generate a random UUID
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Check if we should run seeding
$seed = isset($_GET['seed']) ? $_GET['seed'] : false;
if (!$seed) {
    echo "Add ?seed=true to URL to run seeding process";
    exit;
}

// Function to check if a serial number already exists in a table
function serialExists($conn, $table, $serial) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM $table WHERE SerialNumber = ?");
    $stmt->bind_param("s", $serial);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return $count > 0;
}

// Begin transaction
$conn->begin_transaction();

try {
    // Generate server UUID to be used across components
    $server1_uuid = generateUUID();
    
    // Clear existing data first (optional, comment out if you want to keep existing data)
    $tables = ['CPUInventory', 'RAMInventory', 'MotherboardInventory', 'StorageInventory', 'CaddyInventory', 'NICInventory'];
    foreach ($tables as $table) {
        $conn->query("TRUNCATE TABLE $table");
    }
    
    // =============================================
    // Seed CPU data
    // =============================================
    
    $stmt = $conn->prepare("INSERT INTO CPUInventory 
        (UUID, SerialNumber, Status, ServerUUID, Location, RackPosition, 
        PurchaseDate, InstallationDate, WarrantyEndDate, Flag, Notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // CPU Entry 1
    $cpu1_uuid = generateUUID();
    $cpu1_serial = "CPU123456";
    $cpu1_status = "In Use";
    $cpu1_location = "Datacenter North";
    $cpu1_rack = "Rack A3-12";
    $cpu1_purchase = "2023-05-15";
    $cpu1_install = "2023-06-01";
    $cpu1_warranty = "2026-05-15";
    $cpu1_flag = "Production";
    $cpu1_notes = "Intel Xeon 8-core 3.2GHz";
    
    if (!serialExists($conn, "CPUInventory", $cpu1_serial)) {
        $stmt->bind_param(
            "sssssssssss",
            $cpu1_uuid,
            $cpu1_serial,
            $cpu1_status,
            $server1_uuid,
            $cpu1_location,
            $cpu1_rack,
            $cpu1_purchase,
            $cpu1_install,
            $cpu1_warranty,
            $cpu1_flag,
            $cpu1_notes
        );
        $stmt->execute();
    }
    
    // CPU Entry 2
    $cpu2_uuid = generateUUID();
    $cpu2_serial = "CPU789012";
    $cpu2_status = "In Stock";
    $cpu2_server_uuid = null;
    $cpu2_location = "Warehouse East";
    $cpu2_rack = "Shelf B4";
    $cpu2_purchase = "2024-01-10";
    $cpu2_install = null;
    $cpu2_warranty = "2027-01-10";
    $cpu2_flag = "Backup";
    $cpu2_notes = "AMD EPYC 16-core 2.9GHz";
    
    if (!serialExists($conn, "CPUInventory", $cpu2_serial)) {
        $stmt->bind_param(
            "sssssssssss",
            $cpu2_uuid,
            $cpu2_serial,
            $cpu2_status,
            $cpu2_server_uuid,
            $cpu2_location,
            $cpu2_rack,
            $cpu2_purchase,
            $cpu2_install,
            $cpu2_warranty,
            $cpu2_flag,
            $cpu2_notes
        );
        $stmt->execute();
    }
    
    // =============================================
    // Seed RAM data
    // =============================================
    
    $stmt = $conn->prepare("INSERT INTO RAMInventory 
        (UUID, SerialNumber, Status, ServerUUID, Location, RackPosition, 
        PurchaseDate, InstallationDate, WarrantyEndDate, Flag, Notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // RAM Entry 1
    $ram1_uuid = generateUUID();
    $ram1_serial = "RAM123456";
    $ram1_status = "In Use";
    $ram1_location = "Datacenter North";
    $ram1_rack = "Rack A3-12";
    $ram1_purchase = "2023-05-15";
    $ram1_install = "2023-06-01";
    $ram1_warranty = "2026-05-15";
    $ram1_flag = "Production";
    $ram1_notes = "32GB DDR4-3200";
    
    if (!serialExists($conn, "RAMInventory", $ram1_serial)) {
        $stmt->bind_param(
            "sssssssssss",
            $ram1_uuid,
            $ram1_serial,
            $ram1_status,
            $server1_uuid,
            $ram1_location,
            $ram1_rack,
            $ram1_purchase,
            $ram1_install,
            $ram1_warranty,
            $ram1_flag,
            $ram1_notes
        );
        $stmt->execute();
    }
    
    // RAM Entry 2
    $ram2_uuid = generateUUID();
    $ram2_serial = "RAM789012";
    $ram2_status = "In Stock";
    $ram2_server_uuid = null;
    $ram2_location = "Warehouse East";
    $ram2_rack = "Shelf C2";
    $ram2_purchase = "2024-01-15";
    $ram2_install = null;
    $ram2_warranty = "2027-01-15";
    $ram2_flag = "Backup";
    $ram2_notes = "64GB DDR4-3600";
    
    if (!serialExists($conn, "RAMInventory", $ram2_serial)) {
        $stmt->bind_param(
            "sssssssssss",
            $ram2_uuid,
            $ram2_serial,
            $ram2_status,
            $ram2_server_uuid,
            $ram2_location,
            $ram2_rack,
            $ram2_purchase,
            $ram2_install,
            $ram2_warranty,
            $ram2_flag,
            $ram2_notes
        );
        $stmt->execute();
    }
    
    // =============================================
    // Seed Motherboard data
    // =============================================
    
    $stmt = $conn->prepare("INSERT INTO MotherboardInventory 
        (UUID, SerialNumber, Status, ServerUUID, Location, RackPosition, 
        PurchaseDate, InstallationDate, WarrantyEndDate, Flag, Notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Motherboard Entry 1
    $mb1_uuid = generateUUID();
    $mb1_serial = "MB123456";
    $mb1_status = "In Use";
    $mb1_location = "Datacenter North";
    $mb1_rack = "Rack A3-12";
    $mb1_purchase = "2023-05-10";
    $mb1_install = "2023-06-01";
    $mb1_warranty = "2026-05-10";
    $mb1_flag = "Production";
    $mb1_notes = "Supermicro X12DPi-NT6";
    
    if (!serialExists($conn, "MotherboardInventory", $mb1_serial)) {
        $stmt->bind_param(
            "sssssssssss",
            $mb1_uuid,
            $mb1_serial,
            $mb1_status,
            $server1_uuid,
            $mb1_location,
            $mb1_rack,
            $mb1_purchase,
            $mb1_install,
            $mb1_warranty,
            $mb1_flag,
            $mb1_notes
        );
        $stmt->execute();
    }
    
    // Motherboard Entry 2
    $mb2_uuid = generateUUID();
    $mb2_serial = "MB789012";
    $mb2_status = "Maintenance";
    $mb2_server_uuid = null;
    $mb2_location = "Repair Center";
    $mb2_rack = "Bench 3";
    $mb2_purchase = "2023-02-20";
    $mb2_install = "2023-03-01";
    $mb2_warranty = "2026-02-20";
    $mb2_flag = "Repair";
    $mb2_notes = "ASUS WS C621E SAGE - Under repair for BIOS issues";
    
    if (!serialExists($conn, "MotherboardInventory", $mb2_serial)) {
        $stmt->bind_param(
            "sssssssssss",
            $mb2_uuid,
            $mb2_serial,
            $mb2_status,
            $mb2_server_uuid,
            $mb2_location,
            $mb2_rack,
            $mb2_purchase,
            $mb2_install,
            $mb2_warranty,
            $mb2_flag,
            $mb2_notes
        );
        $stmt->execute();
    }
    
    // =============================================
    // Seed Storage data
    // =============================================
    
    $stmt = $conn->prepare("INSERT INTO StorageInventory 
        (UUID, SerialNumber, Status, ServerUUID, Location, RackPosition, 
        PurchaseDate, InstallationDate, WarrantyEndDate, Flag, Notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Storage Entry 1
    $storage1_uuid = generateUUID();
    $storage1_serial = "SSD123456";
    $storage1_status = "In Use";
    $storage1_location = "Datacenter North";
    $storage1_rack = "Rack A3-12";
    $storage1_purchase = "2023-05-12";
    $storage1_install = "2023-06-01";
    $storage1_warranty = "2026-05-12";
    $storage1_flag = "Production";
    $storage1_notes = "Samsung 980 PRO 2TB NVMe";
    
    if (!serialExists($conn, "StorageInventory", $storage1_serial)) {
        $stmt->bind_param(
            "sssssssssss",
            $storage1_uuid,
            $storage1_serial,
            $storage1_status,
            $server1_uuid,
            $storage1_location,
            $storage1_rack,
            $storage1_purchase,
            $storage1_install,
            $storage1_warranty,
            $storage1_flag,
            $storage1_notes
        );
        $stmt->execute();
    }
    
    // Storage Entry 2
    $storage2_uuid = generateUUID();
    $storage2_serial = "HDD789012";
    $storage2_status = "In Stock";
    $storage2_server_uuid = null;
    $storage2_location = "Warehouse East";
    $storage2_rack = "Shelf D1";
    $storage2_purchase = "2024-01-05";
    $storage2_install = null;
    $storage2_warranty = "2027-01-05";
    $storage2_flag = "Backup";
    $storage2_notes = "Seagate IronWolf 8TB NAS HDD";
    
    if (!serialExists($conn, "StorageInventory", $storage2_serial)) {
        $stmt->bind_param(
            "sssssssssss",
            $storage2_uuid,
            $storage2_serial,
            $storage2_status,
            $storage2_server_uuid,
            $storage2_location,
            $storage2_rack,
            $storage2_purchase,
            $storage2_install,
            $storage2_warranty,
            $storage2_flag,
            $storage2_notes
        );
        $stmt->execute();
    }
    
    // =============================================
    // Seed Caddy data
    // =============================================
    
    $stmt = $conn->prepare("INSERT INTO CaddyInventory 
        (UUID, SerialNumber, Status, ServerUUID, Location, RackPosition, 
        PurchaseDate, InstallationDate, WarrantyEndDate, Flag, Notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Caddy Entry 1
    $caddy1_uuid = generateUUID();
    $caddy1_serial = "CDY123456";
    $caddy1_status = "In Use";
    $caddy1_location = "Datacenter North";
    $caddy1_rack = "Rack A3-12";
    $caddy1_purchase = "2023-05-12";
    $caddy1_install = "2023-06-01";
    $caddy1_warranty = "2026-05-12";
    $caddy1_flag = "Production";
    $caddy1_notes = "Dell 2.5\" SAS Drive Caddy";
    
    if (!serialExists($conn, "CaddyInventory", $caddy1_serial)) {
        $stmt->bind_param(
            "sssssssssss",
            $caddy1_uuid,
            $caddy1_serial,
            $caddy1_status,
            $server1_uuid,
            $caddy1_location,
            $caddy1_rack,
            $caddy1_purchase,
            $caddy1_install,
            $caddy1_warranty,
            $caddy1_flag,
            $caddy1_notes
        );
        $stmt->execute();
    }
    
    // Caddy Entry 2
    $caddy2_uuid = generateUUID();
    $caddy2_serial = "CDY789012";
    $caddy2_status = "Failed";
    $caddy2_server_uuid = null;
    $caddy2_location = "Disposal";
    $caddy2_rack = "Bin 2";
    $caddy2_purchase = "2022-03-10";
    $caddy2_install = "2022-03-25";
    $caddy2_warranty = "2025-03-10";
    $caddy2_flag = "Damaged";
    $caddy2_notes = "HP 3.5\" SATA Drive Caddy - Damaged locking mechanism";
    
    if (!serialExists($conn, "CaddyInventory", $caddy2_serial)) {
        $stmt->bind_param(
            "sssssssssss",
            $caddy2_uuid,
            $caddy2_serial,
            $caddy2_status,
            $caddy2_server_uuid,
            $caddy2_location,
            $caddy2_rack,
            $caddy2_purchase,
            $caddy2_install,
            $caddy2_warranty,
            $caddy2_flag,
            $caddy2_notes
        );
        $stmt->execute();
    }
    
    // =============================================
    // Seed NIC data
    // =============================================
    
    $stmt = $conn->prepare("INSERT INTO NICInventory 
        (UUID, SerialNumber, Status, ServerUUID, Location, RackPosition, 
        MacAddress, IPAddress, NetworkName,
        PurchaseDate, InstallationDate, WarrantyEndDate, Flag, Notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // NIC Entry 1
    $nic1_uuid = generateUUID();
    $nic1_serial = "NIC123456";
    $nic1_status = "In Use";
    $nic1_location = "Datacenter North";
    $nic1_rack = "Rack A3-12";
    $nic1_mac = "00:1A:2B:3C:4D:5E";
    $nic1_ip = "192.168.1.100";
    $nic1_network = "Internal-Production";
    $nic1_purchase = "2023-05-12";
    $nic1_install = "2023-06-01";
    $nic1_warranty = "2026-05-12";
    $nic1_flag = "Production";
    $nic1_notes = "Intel X550-T2 10GbE Dual Port";
    
    if (!serialExists($conn, "NICInventory", $nic1_serial)) {
        $stmt->bind_param(
            "ssssssssssssss",
            $nic1_uuid,
            $nic1_serial,
            $nic1_status,
            $server1_uuid,
            $nic1_location,
            $nic1_rack,
            $nic1_mac,
            $nic1_ip,
            $nic1_network,
            $nic1_purchase,
            $nic1_install,
            $nic1_warranty,
            $nic1_flag,
            $nic1_notes
        );
        $stmt->execute();
    }
    
    // NIC Entry 2
    $nic2_uuid = generateUUID();
    $nic2_serial = "NIC789012";
    $nic2_status = "In Stock";
    $nic2_server_uuid = null;
    $nic2_location = "Warehouse East";
    $nic2_rack = "Shelf E3";
    $nic2_mac = "00:2C:3D:4E:5F:6A";
    $nic2_ip = null;
    $nic2_network = null;
    $nic2_purchase = "2024-02-15";
    $nic2_install = null;
    $nic2_warranty = "2027-02-15";
    $nic2_flag = "Backup";
    $nic2_notes = "Mellanox ConnectX-5 100GbE QSFP28";
    
    if (!serialExists($conn, "NICInventory", $nic2_serial)) {
        $stmt->bind_param(
            "ssssssssssssss",
            $nic2_uuid,
            $nic2_serial,
            $nic2_status,
            $nic2_server_uuid,
            $nic2_location,
            $nic2_rack,
            $nic2_mac,
            $nic2_ip,
            $nic2_network,
            $nic2_purchase,
            $nic2_install,
            $nic2_warranty,
            $nic2_flag,
            $nic2_notes
        );
        $stmt->execute();
    }
    
    // NIC Entry 3 - Failed one
    $nic3_uuid = generateUUID();
    $nic3_serial = "NIC345678";
    $nic3_status = "Failed";
    $nic3_server_uuid = null;
    $nic3_location = "Disposal";
    $nic3_rack = "Bin 1";
    $nic3_mac = "00:3E:4F:5A:6B:7C";
    $nic3_ip = null;
    $nic3_network = null;
    $nic3_purchase = "2022-06-20";
    $nic3_install = "2022-07-01";
    $nic3_warranty = "2025-06-20";
    $nic3_flag = "Hardware Failure";
    $nic3_notes = "Broadcom BCM57414 25GbE - Port 2 failure, scheduled for RMA";
    
    if (!serialExists($conn, "NICInventory", $nic3_serial)) {
        $stmt->bind_param(
            "ssssssssssssss",
            $nic3_uuid,
            $nic3_serial,
            $nic3_status,
            $nic3_server_uuid,
            $nic3_location,
            $nic3_rack,
            $nic3_mac,
            $nic3_ip,
            $nic3_network,
            $nic3_purchase,
            $nic3_install,
            $nic3_warranty,
            $nic3_flag,
            $nic3_notes
        );
        $stmt->execute();
    }
    
    // =============================================
    // Add a sample user account
    // =============================================
    
    // Check if admin user already exists
    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
    $admin_username = "admin";
    $stmt->bind_param("s", $admin_username);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    
    if ($count == 0) {
        $stmt = $conn->prepare("INSERT INTO users 
            (username, password, email) 
            VALUES (?, ?, ?)");
        
        $admin_password = password_hash("admin123", PASSWORD_DEFAULT);
        $admin_email = "admin@example.com";
        
        $stmt->bind_param(
            "sss",
            $admin_username,
            $admin_password,
            $admin_email
        );
        $stmt->execute();
    }
    
    // Commit transaction
    $conn->commit();
    echo "Dummy data inserted successfully!";
    
} catch (Exception $e) {
    // Roll back transaction on error
    $conn->rollback();
    echo "Error inserting dummy data: " . $e->getMessage();
}

// Close connection
$conn->close();
?>