<?php
/**
 * Enhanced Components API with JSON Integration and UUID Support
 * File: api/components/components_api.php
 */

// Include required files
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

// Component type validation and table mapping
$validComponents = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
$tableMap = [
    'cpu' => 'cpuinventory',
    'ram' => 'raminventory', 
    'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory',
    'nic' => 'nicinventory',
    'caddy' => 'caddyinventory'
];

// Get component type and operation from action
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$parts = explode('-', $action);
$componentType = $parts[0] ?? '';
$operation = $parts[1] ?? '';

// Validate component type
if (!in_array($componentType, $validComponents)) {
    send_json_response(0, 0, 400, "Invalid component type: $componentType");
}

$table = $tableMap[$componentType];

// Load JSON data for UUID validation and details
function loadComponentJSONData($type) {
    $jsonPaths = [
        'cpu' => [
            'level1' => __DIR__ . '/../../All JSON/cpu jsons/Cpu base level 1.json',
            'level2' => __DIR__ . '/../../All JSON/cpu jsons/Cpu family level 2.json',
            'level3' => __DIR__ . '/../../All JSON/cpu jsons/Cpu details level 3.json'
        ],
        'motherboard' => [
            'level1' => __DIR__ . '/../../All JSON/motherboad jsons/motherboard level 1.json',
            'level3' => __DIR__ . '/../../All JSON/motherboad jsons/motherboard level 3.json'
        ],
        'ram' => [
            'level3' => __DIR__ . '/../../All JSON/Ram JSON/ram_detail.json'
        ],
        'storage' => [
            'level3' => __DIR__ . '/../../All JSON/storage jsons/storagedetail.json'
        ],
        'caddy' => [
            'level3' => __DIR__ . '/../../All JSON/caddy json/caddy_details.json'
        ]
    ];
    
    $data = [];
    if (isset($jsonPaths[$type])) {
        foreach ($jsonPaths[$type] as $level => $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $data[$level] = json_decode($content, true);
            }
        }
    }
    
    return $data;
}

// Validate UUID exists in JSON data
function validateComponentUUID($uuid, $componentType) {
    if (empty($uuid)) {
        return false;
    }
    
    $jsonData = loadComponentJSONData($componentType);
    
    if (empty($jsonData['level3'])) {
        return true; // If no JSON data, allow any UUID (for NIC, etc.)
    }
    
    foreach ($jsonData['level3'] as $brandData) {
        if (isset($brandData['models'])) {
            foreach ($brandData['models'] as $model) {
                $modelUUID = $model['UUID'] ?? $model['uuid'] ?? $model['inventory']['UUID'] ?? '';
                if ($modelUUID === $uuid) {
                    return true;
                }
            }
        }
    }
    
    return false;
}

// Get component details from JSON by UUID
function getComponentDetailsFromJSON($uuid, $componentType) {
    $jsonData = loadComponentJSONData($componentType);
    
    if (empty($jsonData['level3'])) {
        return null;
    }
    
    foreach ($jsonData['level3'] as $brandData) {
        if (isset($brandData['models'])) {
            foreach ($brandData['models'] as $model) {
                $modelUUID = $model['UUID'] ?? $model['uuid'] ?? $model['inventory']['UUID'] ?? '';
                if ($modelUUID === $uuid) {
                    return [
                        'brand' => $brandData['brand'],
                        'series' => $brandData['series'] ?? '',
                        'model' => $model,
                        'modelName' => $model['model'] ?? $model['name'] ?? ''
                    ];
                }
            }
        }
    }
    
    return null;
}

// Handle different operations
switch ($operation) {
    case 'list':
        // Check read permission if function exists
        if (function_exists('requireJWTPermission')) {
            requireJWTPermission($pdo, 'read', $componentType);
        }
        
        try {
            // Get pagination parameters
            $page = (int)($_GET['page'] ?? 1);
            $limit = min((int)($_GET['limit'] ?? 20), 100); // Max 100 items per page
            $offset = ($page - 1) * $limit;
            
            // Get filter parameters
            $status = $_GET['status'] ?? '';
            $search = $_GET['search'] ?? '';
            $location = $_GET['location'] ?? '';
            
            // Build query conditions
            $conditions = [];
            $params = [];
            
            if ($status !== '') {
                $conditions[] = "Status = :status";
                $params[':status'] = $status;
            }
            
            if ($search) {
                $conditions[] = "(SerialNumber LIKE :search OR UUID LIKE :search OR Notes LIKE :search OR Location LIKE :search)";
                $params[':search'] = "%$search%";
            }
            
            if ($location) {
                $conditions[] = "Location LIKE :location";
                $params[':location'] = "%$location%";
            }
            
            // Build main query
            $query = "SELECT * FROM $table";
            if (!empty($conditions)) {
                $query .= " WHERE " . implode(' AND ', $conditions);
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
            
            // Get total count for pagination
            $countQuery = "SELECT COUNT(*) as total FROM $table";
            if (!empty($conditions)) {
                $countQuery .= " WHERE " . implode(' AND ', $conditions);
            }
            
            $countStmt = $pdo->prepare($countQuery);
            foreach ($params as $key => $value) {
                if ($key !== ':limit' && $key !== ':offset') {
                    $countStmt->bindValue($key, $value);
                }
            }
            $countStmt->execute();
            $total = $countStmt->fetch()['total'];
            
            // Get status summary
            $statusQuery = "SELECT Status, COUNT(*) as count FROM $table";
            if (!empty($conditions)) {
                // Remove status condition for summary if it exists
                $summaryConditions = array_filter($conditions, function($condition) {
                    return !str_contains($condition, 'Status =');
                });
                if (!empty($summaryConditions)) {
                    $statusQuery .= " WHERE " . implode(' AND ', $summaryConditions);
                }
            }
            $statusQuery .= " GROUP BY Status";
            
            $statusStmt = $pdo->prepare($statusQuery);
            foreach ($params as $key => $value) {
                if ($key !== ':status' && $key !== ':limit' && $key !== ':offset') {
                    $statusStmt->bindValue($key, $value);
                }
            }
            $statusStmt->execute();
            $statusCounts = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            send_json_response(1, 1, 200, "$componentType components retrieved successfully", [
                'components' => $components,
                'pagination' => [
                    'total' => (int)$total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'page' => $page,
                    'has_more' => ($offset + $limit) < $total
                ],
                'status_summary' => [
                    'total' => (int)$total,
                    'available' => (int)($statusCounts[1] ?? 0),
                    'in_use' => (int)($statusCounts[2] ?? 0),
                    'failed' => (int)($statusCounts[0] ?? 0)
                ],
                'filters' => [
                    'status' => $status,
                    'search' => $search,
                    'location' => $location
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("Error retrieving $componentType components: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to retrieve components");
        }
        break;
        
    case 'get':
        // Check read permission if function exists
        if (function_exists('requireJWTPermission')) {
            requireJWTPermission($pdo, 'read', $componentType);
        }
        
        $id = $_GET['id'] ?? $_POST['id'] ?? '';
        if (empty($id)) {
            send_json_response(0, 0, 400, "Component ID is required");
        }
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            $component = $stmt->fetch();
            if (!$component) {
                send_json_response(0, 0, 404, "Component not found");
            }
            
            // Add JSON details if available
            $jsonDetails = null;
            if ($component['UUID']) {
                $jsonDetails = getComponentDetailsFromJSON($component['UUID'], $componentType);
            }
            
            send_json_response(1, 1, 200, "Component retrieved successfully", [
                'component' => $component,
                'json_details' => $jsonDetails,
                'history' => [] // TODO: Implement history tracking
            ]);
            
        } catch (Exception $e) {
            error_log("Error retrieving $componentType component: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to retrieve component");
        }
        break;
        
    case 'add':
    case 'create':
        // Check create permission if function exists
        if (function_exists('requireJWTPermission')) {
            requireJWTPermission($pdo, 'create', $componentType);
        }
        
        try {
            // Get form data
            $uuid = $_POST['UUID'] ?? '';
            $serialNumber = $_POST['SerialNumber'] ?? '';
            $status = $_POST['Status'] ?? '1';
            $serverUUID = $_POST['ServerUUID'] ?? null;
            $location = $_POST['Location'] ?? null;
            $rackPosition = $_POST['RackPosition'] ?? null;
            $purchaseDate = $_POST['PurchaseDate'] ?? null;
            $installationDate = $_POST['InstallationDate'] ?? null;
            $warrantyEndDate = $_POST['WarrantyEndDate'] ?? null;
            $flag = $_POST['Flag'] ?? null;
            $notes = $_POST['Notes'] ?? null;
            
            // Validation
            if (empty($uuid)) {
                send_json_response(0, 0, 400, "Component UUID is required");
            }
            
            if (empty($serialNumber)) {
                send_json_response(0, 0, 400, "Serial number is required");
            }
            
            // Validate status
            if (!in_array($status, ['0', '1', '2'])) {
                send_json_response(0, 0, 400, "Invalid status value");
            }
            
            // Validate UUID exists in JSON (except for NIC which might not have JSON data)
            if ($componentType !== 'nic' && !validateComponentUUID($uuid, $componentType)) {
                send_json_response(0, 0, 400, "Invalid component UUID. Please select a valid component from the dropdown.");
            }
            
            // Check for duplicate UUID
            $stmt = $pdo->prepare("SELECT ID FROM $table WHERE UUID = :uuid");
            $stmt->bindValue(':uuid', $uuid);
            $stmt->execute();
            if ($stmt->fetch()) {
                send_json_response(0, 0, 400, "A component with this UUID already exists");
            }
            
            // Check for duplicate serial number
            $stmt = $pdo->prepare("SELECT ID FROM $table WHERE SerialNumber = :serial");
            $stmt->bindValue(':serial', $serialNumber);
            $stmt->execute();
            if ($stmt->fetch()) {
                send_json_response(0, 0, 400, "A component with this serial number already exists");
            }
            
            // Validate date formats
            $dateFields = ['PurchaseDate', 'InstallationDate', 'WarrantyEndDate'];
            foreach ($dateFields as $field) {
                $value = $_POST[$field] ?? null;
                if ($value && !DateTime::createFromFormat('Y-m-d', $value)) {
                    send_json_response(0, 0, 400, "Invalid date format for $field");
                }
            }
            
            // Prepare insert query
            $fields = [
                'UUID', 'SerialNumber', 'Status', 'ServerUUID', 'Location', 
                'RackPosition', 'PurchaseDate', 'InstallationDate', 'WarrantyEndDate', 
                'Flag', 'Notes', 'CreatedAt', 'UpdatedAt'
            ];
            
            $placeholders = ':' . implode(', :', $fields);
            $query = "INSERT INTO $table (" . implode(', ', $fields) . ") VALUES ($placeholders)";
            
            $stmt = $pdo->prepare($query);
            
            // Bind values
            $stmt->bindValue(':UUID', $uuid);
            $stmt->bindValue(':SerialNumber', $serialNumber);
            $stmt->bindValue(':Status', $status, PDO::PARAM_INT);
            $stmt->bindValue(':ServerUUID', $serverUUID);
            $stmt->bindValue(':Location', $location);
            $stmt->bindValue(':RackPosition', $rackPosition);
            $stmt->bindValue(':PurchaseDate', $purchaseDate);
            $stmt->bindValue(':InstallationDate', $installationDate);
            $stmt->bindValue(':WarrantyEndDate', $warrantyEndDate);
            $stmt->bindValue(':Flag', $flag);
            $stmt->bindValue(':Notes', $notes);
            $stmt->bindValue(':CreatedAt', date('Y-m-d H:i:s'));
            $stmt->bindValue(':UpdatedAt', date('Y-m-d H:i:s'));
            
            $stmt->execute();
            $componentId = $pdo->lastInsertId();
            
            // Get the created component
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
            $stmt->bindValue(':id', $componentId);
            $stmt->execute();
            $component = $stmt->fetch();
            
            // Log the action if function exists
            if (function_exists('logInventoryAction')) {
                logInventoryAction($pdo, getAuthenticatedUser($pdo)['id'], $componentType, $componentId, 'create', null, $component);
            }
            
            send_json_response(1, 1, 201, "Component added successfully", [
                'component' => $component,
                'id' => $componentId
            ]);
            
        } catch (Exception $e) {
            error_log("Error adding $componentType component: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to add component");
        }
        break;
        
    case 'update':
    case 'edit':
        // Check update permission if function exists
        if (function_exists('requireJWTPermission')) {
            requireJWTPermission($pdo, 'update', $componentType);
        }
        
        $id = $_POST['id'] ?? '';
        if (empty($id)) {
            send_json_response(0, 0, 400, "Component ID is required");
        }
        
        try {
            // Get existing component
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $oldComponent = $stmt->fetch();
            
            if (!$oldComponent) {
                send_json_response(0, 0, 404, "Component not found");
            }
            
            // Get form data (UUID cannot be changed)
            $serialNumber = $_POST['SerialNumber'] ?? $oldComponent['SerialNumber'];
            $status = $_POST['Status'] ?? $oldComponent['Status'];
            $serverUUID = $_POST['ServerUUID'] ?? $oldComponent['ServerUUID'];
            $location = $_POST['Location'] ?? $oldComponent['Location'];
            $rackPosition = $_POST['RackPosition'] ?? $oldComponent['RackPosition'];
            $purchaseDate = $_POST['PurchaseDate'] ?? $oldComponent['PurchaseDate'];
            $installationDate = $_POST['InstallationDate'] ?? $oldComponent['InstallationDate'];
            $warrantyEndDate = $_POST['WarrantyEndDate'] ?? $oldComponent['WarrantyEndDate'];
            $flag = $_POST['Flag'] ?? $oldComponent['Flag'];
            $notes = $_POST['Notes'] ?? $oldComponent['Notes'];
            
            // Validation
            if (empty($serialNumber)) {
                send_json_response(0, 0, 400, "Serial number is required");
            }
            
            if (!in_array($status, ['0', '1', '2'])) {
                send_json_response(0, 0, 400, "Invalid status value");
            }
            
            // Check for duplicate serial number (excluding current component)
            if ($serialNumber !== $oldComponent['SerialNumber']) {
                $stmt = $pdo->prepare("SELECT ID FROM $table WHERE SerialNumber = :serial AND ID != :id");
                $stmt->bindValue(':serial', $serialNumber);
                $stmt->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt->execute();
                if ($stmt->fetch()) {
                    send_json_response(0, 0, 400, "A component with this serial number already exists");
                }
            }
            
            // Validate date formats
            $dateFields = ['PurchaseDate' => $purchaseDate, 'InstallationDate' => $installationDate, 'WarrantyEndDate' => $warrantyEndDate];
            foreach ($dateFields as $field => $value) {
                if ($value && !DateTime::createFromFormat('Y-m-d', $value)) {
                    send_json_response(0, 0, 400, "Invalid date format for $field");
                }
            }
            
            // Update component
            $query = "UPDATE $table SET 
                        SerialNumber = :serialNumber,
                        Status = :status,
                        ServerUUID = :serverUUID,
                        Location = :location,
                        RackPosition = :rackPosition,
                        PurchaseDate = :purchaseDate,
                        InstallationDate = :installationDate,
                        WarrantyEndDate = :warrantyEndDate,
                        Flag = :flag,
                        Notes = :notes,
                        UpdatedAt = :updatedAt
                      WHERE ID = :id";
            
            $stmt = $pdo->prepare($query);
            $stmt->bindValue(':serialNumber', $serialNumber);
            $stmt->bindValue(':status', $status, PDO::PARAM_INT);
            $stmt->bindValue(':serverUUID', $serverUUID);
            $stmt->bindValue(':location', $location);
            $stmt->bindValue(':rackPosition', $rackPosition);
            $stmt->bindValue(':purchaseDate', $purchaseDate);
            $stmt->bindValue(':installationDate', $installationDate);
            $stmt->bindValue(':warrantyEndDate', $warrantyEndDate);
            $stmt->bindValue(':flag', $flag);
            $stmt->bindValue(':notes', $notes);
            $stmt->bindValue(':updatedAt', date('Y-m-d H:i:s'));
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            
            $stmt->execute();
            
            // Get updated component
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $newComponent = $stmt->fetch();
            
            // Log the action if function exists
            if (function_exists('logInventoryAction')) {
                logInventoryAction($pdo, getAuthenticatedUser($pdo)['id'], $componentType, $id, 'update', $oldComponent, $newComponent);
            }
            
            send_json_response(1, 1, 200, "Component updated successfully", [
                'component' => $newComponent
            ]);
            
        } catch (Exception $e) {
            error_log("Error updating $componentType component: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to update component");
        }
        break;
        
    case 'delete':
    case 'remove':
        // Check delete permission if function exists
        if (function_exists('requireJWTPermission')) {
            requireJWTPermission($pdo, 'delete', $componentType);
        }
        
        $id = $_POST['id'] ?? $_GET['id'] ?? '';
        if (empty($id)) {
            send_json_response(0, 0, 400, "Component ID is required");
        }
        
        try {
            // Get component before deletion for logging
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $component = $stmt->fetch();
            
            if (!$component) {
                send_json_response(0, 0, 404, "Component not found");
            }
            
            // Check if component is in use (status = 2)
            if ($component['Status'] == 2) {
                send_json_response(0, 0, 400, "Cannot delete component that is currently in use. Change status first.");
            }
            
            // Delete component
            $stmt = $pdo->prepare("DELETE FROM $table WHERE ID = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            
            // Log the action if function exists
            if (function_exists('logInventoryAction')) {
                logInventoryAction($pdo, getAuthenticatedUser($pdo)['id'], $componentType, $id, 'delete', $component, null);
            }
            
            send_json_response(1, 1, 200, "Component deleted successfully", [
                'deleted_id' => $id
            ]);
            
        } catch (Exception $e) {
            error_log("Error deleting $componentType component: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to delete component");
        }
        break;
        
    case 'bulk-update':
        // Check update permission if function exists
        if (function_exists('requireJWTPermission')) {
            requireJWTPermission($pdo, 'update', $componentType);
        }
        
        try {
            $ids = $_POST['ids'] ?? [];
            $updates = $_POST['updates'] ?? [];
            
            if (empty($ids) || !is_array($ids)) {
                send_json_response(0, 0, 400, "Component IDs are required");
            }
            
            if (empty($updates) || !is_array($updates)) {
                send_json_response(0, 0, 400, "Update data is required");
            }
            
            $pdo->beginTransaction();
            
            $updateFields = [];
            $params = [];
            
            // Build update query
            if (isset($updates['Status'])) {
                $updateFields[] = "Status = :status";
                $params[':status'] = $updates['Status'];
            }
            
            if (isset($updates['Location'])) {
                $updateFields[] = "Location = :location";
                $params[':location'] = $updates['Location'];
            }
            
            if (isset($updates['Flag'])) {
                $updateFields[] = "Flag = :flag";
                $params[':flag'] = $updates['Flag'];
            }
            
            if (empty($updateFields)) {
                send_json_response(0, 0, 400, "No valid update fields provided");
            }
            
            $updateFields[] = "UpdatedAt = :updatedAt";
            $params[':updatedAt'] = date('Y-m-d H:i:s');
            
            $placeholders = ':id' . implode(', :id', range(0, count($ids) - 1));
            $query = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE ID IN ($placeholders)";
            
            $stmt = $pdo->prepare($query);
            
            // Bind update parameters
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            // Bind ID parameters
            foreach ($ids as $index => $id) {
                $stmt->bindValue(":id$index", $id, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            $affectedRows = $stmt->rowCount();
            
            $pdo->commit();
            
            send_json_response(1, 1, 200, "Bulk update completed successfully", [
                'affected_rows' => $affectedRows,
                'updated_ids' => $ids
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error performing bulk update on $componentType components: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to perform bulk update");
        }
        break;
        
    case 'export':
        // Check read permission if function exists
        if (function_exists('requireJWTPermission')) {
            requireJWTPermission($pdo, 'read', $componentType);
        }
        
        try {
            $format = $_GET['format'] ?? 'json';
            
            $stmt = $pdo->prepare("SELECT * FROM $table ORDER BY CreatedAt DESC");
            $stmt->execute();
            $components = $stmt->fetchAll();
            
            if ($format === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $componentType . '_export_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                
                // Write CSV headers
                if (!empty($components)) {
                    fputcsv($output, array_keys($components[0]));
                    
                    // Write data
                    foreach ($components as $component) {
                        fputcsv($output, $component);
                    }
                }
                
                fclose($output);
                exit();
            } else {
                // JSON export
                send_json_response(1, 1, 200, "Components exported successfully", [
                    'components' => $components,
                    'export_date' => date('Y-m-d H:i:s'),
                    'total_count' => count($components),
                    'component_type' => $componentType
                ]);
            }
            
        } catch (Exception $e) {
            error_log("Error exporting $componentType components: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to export components");
        }
        break;
        
    case 'get-json-data':
        // Get JSON data for dropdowns (no special permission required as this is reference data)
        try {
            $level = $_GET['level'] ?? 'all';
            $jsonData = loadComponentJSONData($componentType);
            
            if ($level !== 'all' && isset($jsonData[$level])) {
                $jsonData = [$level => $jsonData[$level]];
            }
            
            send_json_response(1, 1, 200, "JSON data retrieved successfully", [
                'data' => $jsonData,
                'component_type' => $componentType
            ]);
            
        } catch (Exception $e) {
            error_log("Error retrieving JSON data for $componentType: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to retrieve JSON data");
        }
        break;
        
    case 'validate-uuid':
        // Validate if UUID exists in JSON data
        try {
            $uuid = $_GET['uuid'] ?? $_POST['uuid'] ?? '';
            
            if (empty($uuid)) {
                send_json_response(0, 0, 400, "UUID is required");
            }
            
            $isValid = validateComponentUUID($uuid, $componentType);
            $details = null;
            
            if ($isValid) {
                $details = getComponentDetailsFromJSON($uuid, $componentType);
            }
            
            send_json_response(1, 1, 200, $isValid ? "UUID is valid" : "UUID not found", [
                'valid' => $isValid,
                'details' => $details,
                'uuid' => $uuid
            ]);
            
        } catch (Exception $e) {
            error_log("Error validating UUID for $componentType: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to validate UUID");
        }
        break;
        
    default:
        send_json_response(0, 0, 400, "Invalid operation: $operation");
}

/**
 * Helper function to log inventory actions
 */
function logInventoryAction($pdo, $userId, $componentType, $componentId, $action, $oldData = null, $newData = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO inventory_log (user_id, component_type, component_id, action, old_data, new_data, ip_address, user_agent, created_at)
            VALUES (:user_id, :component_type, :component_id, :action, :old_data, :new_data, :ip_address, :user_agent, NOW())
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':component_type' => $componentType,
            ':component_id' => $componentId,
            ':action' => $action,
            ':old_data' => $oldData ? json_encode($oldData) : null,
            ':new_data' => $newData ? json_encode($newData) : null,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
        
    } catch (Exception $e) {
        error_log("Failed to log inventory action: " . $e->getMessage());
        // Don't fail the main operation if logging fails
    }
}

/**
 * Helper function to get authenticated user (simplified)
 */
function getAuthenticatedUser($pdo) {
    // Try to get from session first
    session_start();
    if (isset($_SESSION['id'])) {
        try {
            $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = :id");
            $stmt->execute([':id' => $_SESSION['id']]);
            $user = $stmt->fetch();
            if ($user) {
                return $user;
            }
        } catch (Exception $e) {
            error_log("Error getting authenticated user: " . $e->getMessage());
        }
    }
    
    // Fall back to system user if no session
    return ['id' => 1, 'username' => 'system'];
}
?>