<?php
/**
 * Updated components_api.php with JWT Authentication
 * Replace your existing components_api.php with this version
 */

require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');
require_once(__DIR__ . '/../../includes/JWTAuthFunctions.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Validate JWT token - this replaces the old session check
$user = validateJWTMiddleware($pdo);

// Table mapping
$tableMap = [
    'cpu' => 'cpuinventory',
    'ram' => 'raminventory',
    'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory',
    'nic' => 'nicinventory',
    'caddy' => 'caddyinventory'
];

// Get component type from request
$componentType = $_GET['component_type'] ?? $_POST['component_type'] ?? 'cpu';

// Validate component type
if (!array_key_exists($componentType, $tableMap)) {
    send_json_response(0, 0, 400, "Invalid component type: $componentType");
}

$table = $tableMap[$componentType];
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
    case 'get':
        // Check read permission
        requireJWTPermission($pdo, 'read', $componentType);
        
        try {
            $status = $_GET['status'] ?? 'all';
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 1000) : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $search = $_GET['search'] ?? '';
            
            $query = "SELECT * FROM $table";
            $params = [];
            $conditions = [];
            
            // Add status filter
            if ($status !== 'all' && in_array($status, ['0', '1', '2'])) {
                $conditions[] = "Status = :status";
                $params[':status'] = $status;
            }
            
            // Add search filter
            if (!empty($search)) {
                $conditions[] = "(SerialNumber LIKE :search OR Notes LIKE :search OR UUID LIKE :search)";
                $params[':search'] = "%$search%";
            }
            
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
            $statusQuery = "SELECT Status, COUNT(*) as count FROM $table GROUP BY Status";
            $statusStmt = $pdo->prepare($statusQuery);
            $statusStmt->execute();
            $statusCounts = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            send_json_response(1, 1, 200, "$componentType components retrieved successfully", [
                'components' => $components,
                'pagination' => [
                    'total' => (int)$total,
                    'limit' => $limit,
                    'offset' => $offset,
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
                    'search' => $search
                ]
            ]);
            
        } catch (Exception $e) {
            error_log("Error retrieving $componentType components: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to retrieve components");
        }
        break;
        
    case 'get_by_id':
        // Check read permission
        requireJWTPermission($pdo, 'read', $componentType);
        
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
            
            send_json_response(1, 1, 200, "Component retrieved successfully", [
                'component' => $component
            ]);
            
        } catch (Exception $e) {
            error_log("Error retrieving $componentType component: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to retrieve component");
        }
        break;
        
    case 'add':
    case 'create':
        // Check create permission
        requireJWTPermission($pdo, 'create', $componentType);
        
        try {
            // Required fields validation
            $uuid = $_POST['uuid'] ?? '';
            $serialNumber = $_POST['serial_number'] ?? '';
            $status = $_POST['status'] ?? '1';
            $notes = $_POST['notes'] ?? '';
            $serverUUID = $_POST['server_uuid'] ?? null;
            
            if (empty($uuid)) {
                send_json_response(0, 0, 400, "UUID is required");
            }
            
            // Validate status
            if (!in_array($status, ['0', '1', '2'])) {
                send_json_response(0, 0, 400, "Invalid status. Must be 0 (Failed), 1 (Available), or 2 (In Use)");
            }
            
            // Check for duplicate UUID
            $checkStmt = $pdo->prepare("SELECT ID FROM $table WHERE UUID = :uuid");
            $checkStmt->bindValue(':uuid', $uuid);
            $checkStmt->execute();
            
            if ($checkStmt->fetch()) {
                send_json_response(0, 0, 409, "Component with this UUID already exists");
            }
            
            // Insert new component
            $insertQuery = "INSERT INTO $table (UUID, SerialNumber, Status, Notes, ServerUUID, CreatedAt, UpdatedAt) 
                           VALUES (:uuid, :serial, :status, :notes, :server_uuid, NOW(), NOW())";
            
            $stmt = $pdo->prepare($insertQuery);
            $stmt->bindValue(':uuid', $uuid);
            $stmt->bindValue(':serial', $serialNumber);
            $stmt->bindValue(':status', $status, PDO::PARAM_INT);
            $stmt->bindValue(':notes', $notes);
            $stmt->bindValue(':server_uuid', $serverUUID);
            
            if ($stmt->execute()) {
                $newId = $pdo->lastInsertId();
                
                // Log the action
                logAPIAction($pdo, "Component added", $componentType, $newId, null, [
                    'uuid' => $uuid,
                    'serial_number' => $serialNumber,
                    'status' => $status,
                    'notes' => $notes
                ]);
                
                send_json_response(1, 1, 201, "Component added successfully", [
                    'id' => (int)$newId,
                    'uuid' => $uuid
                ]);
            } else {
                send_json_response(0, 0, 500, "Failed to add component");
            }
            
        } catch (Exception $e) {
            error_log("Error adding $componentType component: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to add component: " . $e->getMessage());
        }
        break;
        
    case 'update':
    case 'edit':
        // Check update permission
        requireJWTPermission($pdo, 'update', $componentType);
        
        try {
            $id = $_POST['id'] ?? '';
            if (empty($id)) {
                send_json_response(0, 0, 400, "Component ID is required");
            }
            
            // Get current component data for logging
            $currentStmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
            $currentStmt->bindValue(':id', $id, PDO::PARAM_INT);
            $currentStmt->execute();
            $currentData = $currentStmt->fetch();
            
            if (!$currentData) {
                send_json_response(0, 0, 404, "Component not found");
            }
            
            // Build update query dynamically
            $updateFields = [];
            $params = [':id' => $id];
            $newValues = [];
            
            $allowedFields = [
                'serial_number' => 'SerialNumber',
                'status' => 'Status', 
                'notes' => 'Notes',
                'server_uuid' => 'ServerUUID'
            ];
            
            foreach ($allowedFields as $postKey => $dbField) {
                if (isset($_POST[$postKey])) {
                    $value = $_POST[$postKey];
                    
                    // Validate status if being updated
                    if ($postKey === 'status' && !in_array($value, ['0', '1', '2'])) {
                        send_json_response(0, 0, 400, "Invalid status. Must be 0, 1, or 2");
                    }
                    
                    $updateFields[] = "$dbField = :$postKey";
                    $params[":$postKey"] = $value;
                    $newValues[$postKey] = $value;
                }
            }
            
            if (empty($updateFields)) {
                send_json_response(0, 0, 400, "No fields to update");
            }
            
            // Add UpdatedAt
            $updateFields[] = "UpdatedAt = NOW()";
            
            $query = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE ID = :id";
            $stmt = $pdo->prepare($query);
            
            if ($stmt->execute($params)) {
                // Log the action
                logAPIAction($pdo, "Component updated", $componentType, $id, 
                    array_intersect_key($currentData, $newValues), $newValues);
                
                send_json_response(1, 1, 200, "Component updated successfully");
            } else {
                send_json_response(0, 0, 500, "Failed to update component");
            }
            
        } catch (Exception $e) {
            error_log("Error updating $componentType component: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to update component");
        }
        break;
        
    case 'delete':
        // Check delete permission
        requireJWTPermission($pdo, 'delete', $componentType);
        
        try {
            $id = $_POST['id'] ?? '';
            if (empty($id)) {
                send_json_response(0, 0, 400, "Component ID is required");
            }
            
            // Get component data for logging before deletion
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $componentData = $stmt->fetch();
            
            if (!$componentData) {
                send_json_response(0, 0, 404, "Component not found");
            }
            
            // Delete the component
            $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE ID = :id");
            $deleteStmt->bindValue(':id', $id, PDO::PARAM_INT);
            
            if ($deleteStmt->execute()) {
                // Log the action
                logAPIAction($pdo, "Component deleted", $componentType, $id, $componentData, null);
                
                send_json_response(1, 1, 200, "Component deleted successfully");
            } else {
                send_json_response(0, 0, 500, "Failed to delete component");
            }
            
        } catch (Exception $e) {
            error_log("Error deleting $componentType component: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to delete component");
        }
        break;
        
    case 'bulk_update':
        // Check update permission
        requireJWTPermission($pdo, 'update', $componentType);
        
        try {
            $ids = $_POST['ids'] ?? [];
            $updates = $_POST['updates'] ?? [];
            
            if (empty($ids) || !is_array($ids)) {
                send_json_response(0, 0, 400, "Component IDs array is required");
            }
            
            if (empty($updates) || !is_array($updates)) {
                send_json_response(0, 0, 400, "Updates object is required");
            }
            
            $pdo->beginTransaction();
            
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'status' => 'Status',
                'server_uuid' => 'ServerUUID'
            ];
            
            foreach ($allowedFields as $postKey => $dbField) {
                if (isset($updates[$postKey])) {
                    $updateFields[] = "$dbField = :$postKey";
                    $params[":$postKey"] = $updates[$postKey];
                }
            }
            
            if (empty($updateFields)) {
                $pdo->rollBack();
                send_json_response(0, 0, 400, "No valid fields to update");
            }
            
            $updateFields[] = "UpdatedAt = NOW()";
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            
            $query = "UPDATE $table SET " . implode(', ', $updateFields) . 
                    " WHERE ID IN ($placeholders)";
            
            $stmt = $pdo->prepare($query);
            
            // Bind update values
            $bindIndex = 1;
            foreach ($params as $value) {
                $stmt->bindValue($bindIndex++, $value);
            }
            
            // Bind IDs
            foreach ($ids as $id) {
                $stmt->bindValue($bindIndex++, $id, PDO::PARAM_INT);
            }
            
            if ($stmt->execute()) {
                $affectedRows = $stmt->rowCount();
                $pdo->commit();
                
                // Log the action
                logAPIAction($pdo, "Bulk component update", $componentType, implode(',', $ids), null, $updates);
                
                send_json_response(1, 1, 200, "Bulk update completed successfully", [
                    'affected_rows' => $affectedRows,
                    'updated_ids' => $ids
                ]);
            } else {
                $pdo->rollBack();
                send_json_response(0, 0, 500, "Failed to perform bulk update");
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Error in bulk update for $componentType: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to perform bulk update");
        }
        break;
        
    case 'export':
        // Check export permission (if you have this permission)
        requireJWTPermission($pdo, 'read', $componentType);
        
        try {
            $format = $_GET['format'] ?? 'json';
            
            $stmt = $pdo->prepare("SELECT * FROM $table ORDER BY CreatedAt DESC");
            $stmt->execute();
            $components = $stmt->fetchAll();
            
            if ($format === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $componentType . '_export.csv"');
                
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
                    'total_count' => count($components)
                ]);
            }
            
        } catch (Exception $e) {
            error_log("Error exporting $componentType components: " . $e->getMessage());
            send_json_response(0, 0, 500, "Failed to export components");
        }
        break;
        
    default:
        send_json_response(0, 0, 400, "Invalid action: $action");
}
?>