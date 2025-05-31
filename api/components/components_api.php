<?php
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: *');

session_start();

// Check if user is logged in
if (!isUserLoggedIn($pdo)) {
    send_json_response(0, 0, 401, "Unauthorized");
}

// Table mapping
$tableMap = [
    'cpu' => 'cpuinventory',
    'ram' => 'raminventory',
    'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory',
    'nic' => 'nicinventory',
    'caddy' => 'caddyinventory'
];

// Load component data from JSON files
function loadComponentOptions($type) {
    $components = [];
    
    switch($type) {
        case 'cpu':
            $jsonFile = __DIR__ . '/../../All JSON/cpu jsons/Cpu details level 3.json';
            break;
        case 'motherboard':
            $jsonFile = __DIR__ . '/../../All JSON/motherboad jsons/motherboard level 3.json';
            break;
        case 'ram':
            $jsonFile = __DIR__ . '/../../All JSON/Ram JSON/ram_detail.json';
            break;
        case 'storage':
            $jsonFile = __DIR__ . '/../../All JSON/storage jsons/storagedetail.json';
            break;
        case 'caddy':
            $jsonFile = __DIR__ . '/../../All JSON/caddy json/caddy_details.json';
            break;
        default:
            return [];
    }
    
    if (file_exists($jsonFile)) {
        $jsonContent = file_get_contents($jsonFile);
        $data = json_decode($jsonContent, true);
        
        // Process data based on component type
        if ($type == 'cpu') {
            foreach ($data as $brand) {
                foreach ($brand['models'] as $model) {
                    $components[] = [
                        'uuid' => $model['UUID'] ?? '',
                        'name' => $model['model'],
                        'brand' => $brand['brand'],
                        'series' => $brand['series'],
                        'details' => [
                            'Architecture' => $model['architecture'] ?? 'N/A',
                            'Cores' => $model['cores'] ?? 'N/A',
                            'Threads' => $model['threads'] ?? 'N/A',
                            'Base Frequency' => ($model['base_frequency_GHz'] ?? 'N/A') . ' GHz',
                            'Max Frequency' => ($model['max_frequency_GHz'] ?? 'N/A') . ' GHz',
                            'TDP' => ($model['tdp_W'] ?? 'N/A') . 'W',
                            'Socket' => $model['socket'] ?? 'N/A'
                        ]
                    ];
                }
            }
        } elseif ($type == 'motherboard') {
            foreach ($data as $brand) {
                foreach ($brand['models'] as $model) {
                    $components[] = [
                        'uuid' => $model['inventory']['UUID'] ?? '',
                        'name' => $model['model'],
                        'brand' => $brand['brand'],
                        'series' => $brand['series'],
                        'details' => [
                            'Form Factor' => $model['form_factor'] ?? 'N/A',
                            'Socket' => $model['socket']['type'] ?? 'N/A',
                            'Chipset' => $model['chipset'] ?? 'N/A',
                            'Memory Type' => $model['memory']['type'] ?? 'N/A',
                            'Max Memory' => ($model['memory']['max_capacity_TB'] ?? 'N/A') . ' TB',
                            'Memory Slots' => $model['memory']['slots'] ?? 'N/A'
                        ]
                    ];
                }
            }
        } elseif ($type == 'ram') {
            if (isset($data['name']) && is_array($data['name'])) {
                foreach ($data['name'] as $ram) {
                    $components[] = [
                        'uuid' => $ram['UUID'] ?? '',
                        'name' => $ram['manufacturer'] . ' ' . $ram['part_number'],
                        'brand' => $ram['manufacturer'],
                        'series' => $ram['type'] . ' ' . $ram['subtype'],
                        'details' => [
                            'Type' => $ram['type'] ?? 'N/A',
                            'Size' => ($ram['size'] ?? 'N/A') . 'GB',
                            'Frequency' => ($ram['frequency_MHz'] ?? 'N/A') . ' MHz',
                            'Latency' => $ram['Latency'] ?? 'N/A',
                            'Form Factor' => $ram['Form_Factor'] ?? 'N/A',
                            'ECC' => $ram['ECC'] ?? 'N/A'
                        ]
                    ];
                }
            }
        } elseif ($type == 'storage') {
            if (isset($data['storage_specifications'])) {
                foreach ($data['storage_specifications'] as $storage) {
                    $uuid = md5($storage['name'] . time() . rand());
                    $components[] = [
                        'uuid' => $uuid,
                        'name' => $storage['name'],
                        'brand' => 'Generic',
                        'series' => $storage['interface'] ?? '',
                        'details' => [
                            'Interface' => $storage['interface'] ?? 'N/A',
                            'Capacities' => implode(', ', array_map(function($cap) { return $cap . 'GB'; }, $storage['capacity_GB'] ?? [])),
                            'Read Speed' => ($storage['read_speed_MBps'] ?? 'N/A') . ' MB/s',
                            'Write Speed' => ($storage['write_speed_MBps'] ?? 'N/A') . ' MB/s'
                        ]
                    ];
                }
            }
        } elseif ($type == 'caddy') {
            if (isset($data['caddies'])) {
                foreach ($data['caddies'] as $caddy) {
                    $uuid = md5($caddy['model'] . time() . rand());
                    $components[] = [
                        'uuid' => $uuid,
                        'name' => $caddy['model'],
                        'brand' => 'Generic',
                        'series' => $caddy['compatibility']['drive_type'][0] ?? '',
                        'details' => [
                            'Drive Type' => implode(', ', $caddy['compatibility']['drive_type'] ?? []),
                            'Size' => $caddy['compatibility']['size'] ?? 'N/A',
                            'Interface' => $caddy['compatibility']['interface'] ?? 'N/A',
                            'Material' => $caddy['material'] ?? 'N/A',
                            'Weight' => $caddy['weight'] ?? 'N/A'
                        ]
                    ];
                }
            }
        }
    }
    
    return $components;
}

// Generate UUID
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$componentType = $_GET['type'] ?? '';

// Validate component type
$validTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
if (!empty($componentType) && !in_array($componentType, $validTypes)) {
    send_json_response(1, 0, 400, "Invalid component type");
}

try {
    switch($method) {
        case 'GET':
            if ($action === 'list') {
                // List components
                if (empty($componentType)) {
                    send_json_response(1, 0, 400, "Component type required");
                }
                
                $statusFilter = $_GET['status'] ?? 'all';
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
                $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
                
                $table = $tableMap[$componentType];
                $query = "SELECT * FROM $table";
                $params = [];
                
                if ($statusFilter !== 'all') {
                    $query .= " WHERE Status = :status";
                    $params[':status'] = $statusFilter;
                }
                
                $query .= " ORDER BY CreatedAt DESC LIMIT :limit OFFSET :offset";
                
                $stmt = $pdo->prepare($query);
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                
                $components = $stmt->fetchAll();
                
                // Get total count
                $countQuery = "SELECT COUNT(*) as total FROM $table";
                if ($statusFilter !== 'all') {
                    $countQuery .= " WHERE Status = :status";
                }
                $countStmt = $pdo->prepare($countQuery);
                if ($statusFilter !== 'all') {
                    $countStmt->bindParam(':status', $statusFilter);
                }
                $countStmt->execute();
                $totalCount = $countStmt->fetch()['total'];
                
                send_json_response(1, 1, 200, "Components retrieved successfully", [
                    'components' => $components,
                    'total_count' => (int)$totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $totalCount
                ]);
                
            } elseif ($action === 'options') {
                // Get component options for add form
                if (empty($componentType)) {
                    send_json_response(1, 0, 400, "Component type required");
                }
                
                $options = loadComponentOptions($componentType);
                send_json_response(1, 1, 200, "Component options retrieved successfully", [
                    'options' => $options
                ]);
                
            } elseif ($action === 'get') {
                // Get single component
                $id = $_GET['id'] ?? '';
                if (empty($id) || empty($componentType)) {
                    send_json_response(1, 0, 400, "Component type and ID required");
                }
                
                $table = $tableMap[$componentType];
                $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
                
                $component = $stmt->fetch();
                if (!$component) {
                    send_json_response(1, 0, 404, "Component not found");
                }
                
                send_json_response(1, 1, 200, "Component retrieved successfully", [
                    'component' => $component
                ]);
                
            } else {
                send_json_response(1, 0, 400, "Invalid action");
            }
            break;
            
        case 'POST':
            if ($action === 'add') {
                // Add component
                if (empty($componentType)) {
                    send_json_response(1, 0, 400, "Component type required");
                }
                
                $input = json_decode(file_get_contents('php://input'), true);
                
                $table = $tableMap[$componentType];
                $componentUuid = $input['component_uuid'] ?? generateUUID();
                $serialNumber = $input['serial_number'] ?? '';
                $status = $input['status'] ?? 1;
                $serverUuid = $input['server_uuid'] ?? null;
                $location = $input['location'] ?? null;
                $rackPosition = $input['rack_position'] ?? null;
                $purchaseDate = $input['purchase_date'] ?? null;
                $warrantyEndDate = $input['warranty_end_date'] ?? null;
                $flag = $input['flag'] ?? null;
                $notes = $input['notes'] ?? null;
                
                // Basic validation
                if (empty($serialNumber)) {
                    send_json_response(1, 0, 400, "Serial Number is required");
                }
                
                // Build query
                $query = "INSERT INTO $table (UUID, SerialNumber, Status, ServerUUID, Location, RackPosition, 
                          PurchaseDate, WarrantyEndDate, Flag, Notes";
                
                $values = ":uuid, :serial_number, :status, :server_uuid, :location, :rack_position, 
                           :purchase_date, :warranty_end_date, :flag, :notes";
                
                $params = [
                    ':uuid' => $componentUuid,
                    ':serial_number' => $serialNumber,
                    ':status' => $status,
                    ':server_uuid' => $serverUuid,
                    ':location' => $location,
                    ':rack_position' => $rackPosition,
                    ':purchase_date' => $purchaseDate,
                    ':warranty_end_date' => $warrantyEndDate,
                    ':flag' => $flag,
                    ':notes' => $notes
                ];
                
                // Handle NIC-specific fields
                if ($componentType == 'nic') {
                    $macAddress = $input['mac_address'] ?? null;
                    $ipAddress = $input['ip_address'] ?? null;
                    $networkName = $input['network_name'] ?? null;
                    
                    $query .= ", MacAddress, IPAddress, NetworkName";
                    $values .= ", :mac_address, :ip_address, :network_name";
                    
                    $params[':mac_address'] = $macAddress;
                    $params[':ip_address'] = $ipAddress;
                    $params[':network_name'] = $networkName;
                }
                
                $query .= ") VALUES ($values)";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                
                $insertId = $pdo->lastInsertId();
                
                send_json_response(1, 1, 200, ucfirst($componentType) . " added successfully", [
                    'id' => (int)$insertId,
                    'uuid' => $componentUuid
                ]);
                
            } else {
                send_json_response(1, 0, 400, "Invalid action");
            }
            break;
            
        case 'PUT':
            if ($action === 'update') {
                // Update component
                if (empty($componentType)) {
                    send_json_response(1, 0, 400, "Component type required");
                }
                
                $id = $_GET['id'] ?? '';
                if (empty($id)) {
                    send_json_response(1, 0, 400, "Component ID required");
                }
                
                $input = json_decode(file_get_contents('php://input'), true);
                $table = $tableMap[$componentType];
                
                // Check if component exists
                $checkStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() == 0) {
                    send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                }
                
                // Build update query
                $updateFields = [];
                $params = [':id' => $id];
                
                $editableFields = [
                    'status' => 'Status',
                    'server_uuid' => 'ServerUUID',
                    'location' => 'Location',
                    'rack_position' => 'RackPosition',
                    'purchase_date' => 'PurchaseDate',
                    'warranty_end_date' => 'WarrantyEndDate',
                    'flag' => 'Flag',
                    'notes' => 'Notes'
                ];
                
                foreach ($editableFields as $inputField => $dbField) {
                    if (isset($input[$inputField])) {
                        $updateFields[] = "$dbField = :$inputField";
                        $params[":$inputField"] = $input[$inputField] ?: null;
                    }
                }
                
                // Handle NIC-specific fields
                if ($componentType == 'nic') {
                    $nicFields = [
                        'mac_address' => 'MacAddress',
                        'ip_address' => 'IPAddress',
                        'network_name' => 'NetworkName'
                    ];
                    
                    foreach ($nicFields as $inputField => $dbField) {
                        if (isset($input[$inputField])) {
                            $updateFields[] = "$dbField = :$inputField";
                            $params[":$inputField"] = $input[$inputField] ?: null;
                        }
                    }
                }
                
                if (empty($updateFields)) {
                    send_json_response(1, 0, 400, "No fields to update");
                }
                
                $query = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE ID = :id";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                
                send_json_response(1, 1, 200, ucfirst($componentType) . " updated successfully");
                
            } else {
                send_json_response(1, 0, 400, "Invalid action");
            }
            break;
            
        case 'DELETE':
            if ($action === 'delete') {
                // Delete component
                if (empty($componentType)) {
                    send_json_response(1, 0, 400, "Component type required");
                }
                
                $id = $_GET['id'] ?? '';
                if (empty($id)) {
                    send_json_response(1, 0, 400, "Component ID required");
                }
                
                $table = $tableMap[$componentType];
                
                // Check if component exists
                $checkStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
                $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $checkStmt->execute();
                
                if ($checkStmt->rowCount() == 0) {
                    send_json_response(1, 0, 404, ucfirst($componentType) . " not found");
                }
                
                // Delete the component
                $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE ID = :id");
                $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
                $deleteStmt->execute();
                
                send_json_response(1, 1, 200, ucfirst($componentType) . " deleted successfully");
                
            } else {
                send_json_response(1, 0, 400, "Invalid action");
            }
            break;
            
        default:
            send_json_response(1, 0, 405, "Method not allowed");
    }
    
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        send_json_response(1, 0, 400, "Serial number already exists");
    } else {
        error_log("Components API error: " . $e->getMessage());
        send_json_response(1, 0, 500, "Database error");
    }
} catch (Exception $e) {
    error_log("Components API error: " . $e->getMessage());
    send_json_response(1, 0, 500, "Internal server error");
}
?>