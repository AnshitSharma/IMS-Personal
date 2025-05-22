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
];


// Usage
if (isUserLoggedIn($mysqli)) {
    if ($_SERVER['REQUEST_METHOD'] == "GET") {
        
        send_json_response(1, 0, 405, "Method Not Allowed");
        // exit();

    } elseif ($_SERVER['REQUEST_METHOD'] == "POST") {
        
        if (!isset($_POST['action'])) {
            
            send_json_response(1, 0, 400, "Parameter Missing");
            // exit();

        } else {
            $action = $_POST['action'];
            if(isset($allowed_actions[$action])) {
                
                if ($functions_dir.$allowed_actions[$action]) {

                    $query = "SELECT * FROM cpuinventory";
                    $result = $mysqli->query($query);

                    if ($result) {
                        $cpuData = $result->fetch_assoc();
                        $response = array(
                            "error" => false,
                            "message" => "Success",
                            "data" => $cpuData
                        );
                        // echo json_encode($response);
                        send_json_response(1, 1, 200, $cpuData);
                        // exit();
                    }
                } else {
                    send_json_response(1, 0, 400, "Invalid Action");
                    // exit();
                }
            } else {
                send_json_response(1, 0, 400, "Invalid Action");
                // exit();
            }
        }
    }
    
} else {
    send_json_response(1, 0, 401, "Unauthenticated");
    // exit();
}

    
    
?>