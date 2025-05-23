<?php

require_once(__DIR__ . '/../includes/db_config.php');
require_once(__DIR__ . '/../includes/QueryModel.php');
require_once(__DIR__ . '/../includes/config.php');
require_once(__DIR__ . '/../includes/BaseFunctions.php');

define('AJAX_ENTRY', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header("Access-Control-Allow-Headers: *");

error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();

$functions_dir = __DIR__.'/functions/';
$allowed_actions = [
    "cpu-list_cpu" => 'functions/cpu/list_cpu.php',
    "cpu-add_cpu" => 'functions/cpu/add_cpu.php',
    "cpu-remove_cpu" => 'functions/cpu/remove_cpu.php',

    "ram-list_ram" => 'functions/ram/list_ram.php',
    "ram-add_ram" => 'functions/ram/add_ram.php',
    "ram-remove_ram" => 'functions/ram/remove_ram.php',

    "storage-list_storage" => 'functions/storage/list_storage.php',
    "storage-add_storage" => 'functions/storage/add_storage.php',
    "storage-remove_storage" => 'functions/storage/remove_storage.php',

    "motherboard-list_motherboard" => 'functions/motherboard/list_motherboard.php',
    "motherboard-add_motherboard" => 'functions/motherboard/add_motherboard.php',
    "motherboard-remove_motherboard" => 'functions/motherboard/remove_motherboard.php',

    "nic-list_nic" => 'functions/nic/list_nic.php',
    "nic-add_nic" => 'functions/nic/add_nic.php',
    "nic-remove_nic" => 'functions/nic/remove_nic.php',

    "caddy-list_caddy" => 'functions/caddy/list_caddy.php',
    "caddy-add_caddy" => 'functions/caddy/add_caddy.php',
    "caddy-remove_caddy" => 'functions/caddy/remove_caddy.php',
];

// Usage
if (isUserLoggedIn($pdo)) {
    if ($_SERVER['REQUEST_METHOD'] == "GET") {
        send_json_response(1, 0, 405, "Method Not Allowed");
    } elseif ($_SERVER['REQUEST_METHOD'] == "POST") {
        
        if (!isset($_POST['action'])) {
            send_json_response(1, 0, 400, "Parameter Missing");
        } else {
            $action = $_POST['action'];
            
            if(isset($allowed_actions[$action])) {
                
                // Handle the cpu-list_cpu action directly
                if ($action == 'cpu-list_cpu') {
                    
                    try {
                        // PDO query execution
                        $stmt = $pdo->prepare("SELECT * FROM cpuinventory ORDER BY CreatedAt DESC");
                        $stmt->execute();
                        
                        // Fetch all results
                        $cpuData = $stmt->fetchAll();
                        
                        send_json_response(1, 1, 200, "CPU inventory retrieved successfully", [
                            'data' => $cpuData,
                            'total_records' => count($cpuData)
                        ]);
                        
                    } catch (PDOException $e) {
                        send_json_response(1, 0, 500, "Database query failed: " . $e->getMessage());
                    }
                    
                } elseif ($action == 'ram-list_ram') {
                    
                    try {
                        $stmt = $pdo->prepare("SELECT * FROM raminventory ORDER BY CreatedAt DESC");
                        $stmt->execute();
                        
                        $ramData = $stmt->fetchAll();
                        
                        send_json_response(1, 1, 200, "RAM inventory retrieved successfully", [
                            'data' => $ramData,
                            'total_records' => count($ramData)
                        ]);
                        
                    } catch (PDOException $e) {
                        send_json_response(1, 0, 500, "Database query failed: " . $e->getMessage());
                    }
                    
                } elseif ($action == 'storage-list_storage') {
                    
                    try {
                        $stmt = $pdo->prepare("SELECT * FROM storageinventory ORDER BY CreatedAt DESC");
                        $stmt->execute();
                        
                        $storageData = $stmt->fetchAll();
                        
                        send_json_response(1, 1, 200, "Storage inventory retrieved successfully", [
                            'data' => $storageData,
                            'total_records' => count($storageData)
                        ]);
                        
                    } catch (PDOException $e) {
                        send_json_response(1, 0, 500, "Database query failed: " . $e->getMessage());
                    }
                    
                } elseif ($action == 'motherboard-list_motherboard') {
                    
                    try {
                        $stmt = $pdo->prepare("SELECT * FROM motherboardinventory ORDER BY CreatedAt DESC");
                        $stmt->execute();
                        
                        $motherboardData = $stmt->fetchAll();
                        
                        send_json_response(1, 1, 200, "Motherboard inventory retrieved successfully", [
                            'data' => $motherboardData,
                            'total_records' => count($motherboardData)
                        ]);
                        
                    } catch (PDOException $e) {
                        send_json_response(1, 0, 500, "Database query failed: " . $e->getMessage());
                    }
                    
                } elseif ($action == 'nic-list_nic') {
                    
                    try {
                        $stmt = $pdo->prepare("SELECT * FROM nicinventory ORDER BY CreatedAt DESC");
                        $stmt->execute();
                        
                        $nicData = $stmt->fetchAll();
                        
                        send_json_response(1, 1, 200, "NIC inventory retrieved successfully", [
                            'data' => $nicData,
                            'total_records' => count($nicData)
                        ]);
                        
                    } catch (PDOException $e) {
                        send_json_response(1, 0, 500, "Database query failed: " . $e->getMessage());
                    }
                    
                } elseif ($action == 'caddy-list_caddy') {
                    
                    try {
                        $stmt = $pdo->prepare("SELECT * FROM caddyinventory ORDER BY CreatedAt DESC");
                        $stmt->execute();
                        
                        $caddyData = $stmt->fetchAll();
                        
                        send_json_response(1, 1, 200, "Caddy inventory retrieved successfully", [
                            'data' => $caddyData,
                            'total_records' => count($caddyData)
                        ]);
                        
                    } catch (PDOException $e) {
                        send_json_response(1, 0, 500, "Database query failed: " . $e->getMessage());
                    }
                    
                } else {
                    // For other actions, you can implement them later
                    send_json_response(1, 0, 400, "Action not implemented yet");
                }
                
            } else {
                send_json_response(1, 0, 400, "Invalid Action");
            }
        }
    }
} else {
    send_json_response(0, 0, 401, "Unauthenticated");
}

?>