<?php
/**
 * Enhanced Components API with JSON Integration and UUID Support
 * File: api/components/components_api.php
 */

// Include required files
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

// Set JSON response header
header('Content-Type: application/json');

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
        ],
        'nic' => [
            'level3' => __DIR__ . '/../../All JSON/nic json/nic_details.json'
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

// Get component details from JSON
function getComponentDetailsFromJSON($uuid, $componentType) {
    if (empty($uuid)) {
        return null;
    }
    
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
                        'brand' => $brandData['brand'] ?? $brandData['manufacturer'] ?? '',
                        'series' => $brandData['series'] ?? $model['series'] ?? '',
                        'model' => $model,
                        'specifications' => extractSpecifications($model, $componentType)
                    ];
                }
            }
        }
    }
    
    return null;
}

// Extract specifications from model data
function extractSpecifications($model, $componentType) {
    $specs = [];
    
    switch($componentType) {
        case 'cpu':
            if (isset($model['cores'])) $specs['cores'] = $model['cores'];
            if (isset($model['threads'])) $specs['threads'] = $model['threads'];
            if (isset($model['base_frequency'])) $specs['base_frequency'] = $model['base_frequency'];
            if (isset($model['boost_frequency'])) $specs['boost_frequency'] = $model['boost_frequency'];
            if (isset($model['tdp'])) $specs['tdp'] = $model['tdp'];
            if (isset($model['cache']['l3'])) $specs['l3_cache'] = $model['cache']['l3'];
            break;
            
        case 'motherboard':
            if (isset($model['socket'])) $specs['socket'] = is_array($model['socket']) ? $model['socket']['type'] : $model['socket'];
            if (isset($model['chipset'])) $specs['chipset'] = $model['chipset'];
            if (isset($model['form_factor'])) $specs['form_factor'] = $model['form_factor'];
            if (isset($model['memory']['max_capacity'])) $specs['max_memory'] = $model['memory']['max_capacity'];
            break;
            
        case 'ram':
            if (isset($model['capacity'])) $specs['capacity'] = $model['capacity'];
            if (isset($model['type'])) $specs['type'] = $model['type'];
            if (isset($model['frequency'])) $specs['frequency'] = $model['frequency'];
            if (isset($model['form_factor'])) $specs['form_factor'] = $model['form_factor'];
            break;
            
        case 'storage':
            if (isset($model['capacity'])) $specs['capacity'] = $model['capacity'];
            if (isset($model['type'])) $specs['type'] = $model['type'];
            if (isset($model['interface'])) $specs['interface'] = $model['interface'];
            if (isset($model['form_factor'])) $specs['form_factor'] = $model['form_factor'];
            break;
    }
    
    return $specs;
}

// Validate required permissions
function validatePermission($permission) {
    if (!checkUserPermission($permission)) {
        send_json_response(0, 1, 403, "Insufficient permissions: $permission required");
    }
}

// Handle different operations
switch($operation) {
    case 'list':
        handleListComponents();
        break;
    case 'get':
        handleGetComponent();
        break;
    case 'add':
        handleAddComponent();
        break;
    case 'update':
        handleUpdateComponent();
        break;
    case 'delete':
        handleDeleteComponent();
        break;
    case 'bulk_update':
        handleBulkUpdateComponents();
        break;
    case 'get_json_data':
        handleGetJSONData();
        break;
    default:
        send_json_response(0, 0, 400, "Invalid operation: $operation");
}

// List components with filtering and pagination
function handleListComponents() {
    global $pdo, $table, $componentType;
    
    validatePermission("{$componentType}.view");
    
    try {
        $limit = min((int)($_GET['limit'] ?? $_POST['limit'] ?? 50), 1000);
        $offset = max(0, (int)($_GET['offset'] ?? $_POST['offset'] ?? 0));
        $page = floor($offset / $limit) + 1;
        $status = $_GET['status'] ?? $_POST['status'] ?? 'all';
        $search = $_GET['search'] ?? $_POST['search'] ?? '';
        $sortBy = $_GET['sort_by'] ?? $_POST['sort_by'] ?? 'CreatedAt';
        $sortOrder = strtoupper($_GET['sort_order'] ?? $_POST['sort_order'] ?? 'DESC');
        
        // Validate sort order
        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $sortOrder = 'DESC';
        }
        
        // Build query conditions
        $conditions = [];
        $params = [];
        
        if ($status !== 'all' && in_array($status, ['0', '1', '2'])) {
            $conditions[] = "Status = :status";
            $params[':status'] = (int)$status;
        }
        
        if (!empty($search)) {
            $conditions[] = "(SerialNumber LIKE :search OR Notes LIKE :search OR Location LIKE :search OR UUID LIKE :search)";
            $params[':search'] = "%$search%";
        }
        
        // Build main query
        $query = "SELECT * FROM $table";
        if (!empty($conditions)) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }
        $query .= " ORDER BY $sortBy $sortOrder LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $components = $stmt->fetchAll();
        
        // Enhance components with JSON data
        foreach ($components as &$component) {
            if (!empty($component['UUID'])) {
                $jsonDetails = getComponentDetailsFromJSON($component['UUID'], $componentType);
                if ($jsonDetails) {
                    $component['json_details'] = $jsonDetails;
                }
            }
            
            // Add status text
            $component['StatusText'] = getStatusText($component['Status']);
        }
        
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
            'permissions' => [
                'can_create' => checkUserPermission("{$componentType}.create"),
                'can_edit' => checkUserPermission("{$componentType}.edit"),
                'can_delete' => checkUserPermission("{$componentType}.delete")
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleListComponents: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred");
    }
}

// Get single component
function handleGetComponent() {
    global $pdo, $table, $componentType;
    
    validatePermission("{$componentType}.view");
    
    $id = $_POST['id'] ?? $_GET['id'] ?? '';
    
    if (empty($id) || !is_numeric($id)) {
        send_json_response(0, 1, 400, "Valid component ID required");
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $component = $stmt->fetch();
        
        if (!$component) {
            send_json_response(0, 1, 404, "Component not found");
        }
        
        // Enhance with JSON data
        if (!empty($component['UUID'])) {
            $jsonDetails = getComponentDetailsFromJSON($component['UUID'], $componentType);
            if ($jsonDetails) {
                $component['json_details'] = $jsonDetails;
            }
        }
        
        // Add status text
        $component['StatusText'] = getStatusText($component['Status']);
        
        // Get component history (if history table exists)
        try {
            $historyStmt = $pdo->prepare("SELECT * FROM {$table}_history WHERE component_id = :id ORDER BY created_at DESC LIMIT 10");
            $historyStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $historyStmt->execute();
            $component['history'] = $historyStmt->fetchAll();
        } catch (PDOException $e) {
            // History table doesn't exist, that's okay
            $component['history'] = [];
        }
        
        send_json_response(1, 1, 200, "Component retrieved successfully", [
            'component' => $component
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleGetComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred");
    }
}

// Add component
function handleAddComponent() {
    global $pdo, $table, $componentType;
    
    validatePermission("{$componentType}.create");
    
    // Required fields
    $requiredFields = ['SerialNumber', 'Status'];
    foreach ($requiredFields as $field) {
        if (empty($_POST[$field])) {
            send_json_response(0, 1, 400, "Required field missing: $field");
        }
    }
    
    $serialNumber = trim($_POST['SerialNumber']);
    $status = (int)$_POST['Status'];
    $uuid = trim($_POST['UUID'] ?? '');
    $serverUUID = trim($_POST['ServerUUID'] ?? '');
    
    // Validate status
    if (!in_array($status, [0, 1, 2])) {
        send_json_response(0, 1, 400, "Invalid status value");
    }
    
    // Validate server UUID requirement for in-use status
    if ($status === 2 && empty($serverUUID)) {
        send_json_response(0, 1, 400, "ServerUUID is required when status is 'In Use'");
    }
    
    // Validate UUID against JSON data if provided
    if (!empty($uuid) && !validateComponentUUID($uuid, $componentType)) {
        send_json_response(0, 1, 400, "Invalid UUID: Component not found in specification database");
    }
    
    try {
        // Check for duplicate serial number
        $checkStmt = $pdo->prepare("SELECT ID FROM $table WHERE SerialNumber = :serial");
        $checkStmt->bindParam(':serial', $serialNumber);
        $checkStmt->execute();
        
        if ($checkStmt->fetch()) {
            send_json_response(0, 1, 409, "Component with serial number $serialNumber already exists");
        }
        
        // Get current user info
        $currentUser = getCurrentUser();
        $username = $currentUser['username'] ?? 'system';
        
        // Prepare insert data
        $insertData = [
            'SerialNumber' => $serialNumber,
            'Status' => $status,
            'CreatedBy' => $username,
            'UpdatedBy' => $username,
            'CreatedAt' => date('Y-m-d H:i:s'),
            'UpdatedAt' => date('Y-m-d H:i:s')
        ];
        
        // Add optional fields
        $optionalFields = [
            'UUID', 'ServerUUID', 'Location', 'RackPosition', 
            'PurchaseDate', 'InstallationDate', 'WarrantyEndDate', 'Flag', 'Notes'
        ];
        
        foreach ($optionalFields as $field) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $insertData[$field] = trim($_POST[$field]);
            }
        }
        
        // Component-specific fields
        if ($componentType === 'nic') {
            $nicFields = ['MacAddress', 'IPAddress', 'NetworkName'];
            foreach ($nicFields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') {
                    $insertData[$field] = trim($_POST[$field]);
                }
            }
        }
        
        if ($componentType === 'storage') {
            $storageFields = ['Capacity', 'Type', 'Interface'];
            foreach ($storageFields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') {
                    $insertData[$field] = trim($_POST[$field]);
                }
            }
        }
        
        // Generate UUID if not provided and we have JSON data
        if (empty($insertData['UUID'])) {
            $insertData['UUID'] = generateUUID();
        }
        
        // Build insert query
        $columns = array_keys($insertData);
        $placeholders = array_map(function($col) { return ":$col"; }, $columns);
        
        $insertQuery = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $pdo->prepare($insertQuery);
        
        foreach ($insertData as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        
        $stmt->execute();
        $newId = $pdo->lastInsertId();
        
        // Log the creation (if audit table exists)
        try {
            logComponentAction($newId, 'created', $insertData, [], $username);
        } catch (Exception $e) {
            // Audit logging failed, but component was created successfully
            error_log("Audit logging failed: " . $e->getMessage());
        }
        
        send_json_response(1, 1, 201, "Component added successfully", [
            'id' => (int)$newId,
            'uuid' => $insertData['UUID']
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleAddComponent: " . $e->getMessage());
        
        if ($e->getCode() == 23000) { // Integrity constraint violation
            send_json_response(0, 1, 409, "Component with this serial number already exists");
        } else {
            send_json_response(0, 1, 500, "Database error occurred");
        }
    }
}

// Update component
function handleUpdateComponent() {
    global $pdo, $table, $componentType;
    
    validatePermission("{$componentType}.edit");
    
    $id = $_POST['id'] ?? '';
    
    if (empty($id) || !is_numeric($id)) {
        send_json_response(0, 1, 400, "Valid component ID required");
    }
    
    try {
        // Get current component data
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $currentComponent = $stmt->fetch();
        
        if (!$currentComponent) {
            send_json_response(0, 1, 404, "Component not found");
        }
        
        // Prepare update data
        $updateData = [];
        $updatedFields = [];
        
        // Updatable fields (excluding SerialNumber and UUID which are immutable)
        $allowedFields = [
            'Status', 'ServerUUID', 'Location', 'RackPosition', 
            'PurchaseDate', 'InstallationDate', 'WarrantyEndDate', 'Flag', 'Notes'
        ];
        
        // Component-specific fields
        if ($componentType === 'nic') {
            $allowedFields = array_merge($allowedFields, ['MacAddress', 'IPAddress', 'NetworkName']);
        }
        
        if ($componentType === 'storage') {
            $allowedFields = array_merge($allowedFields, ['Capacity', 'Type', 'Interface']);
        }
        
        foreach ($allowedFields as $field) {
            if (isset($_POST[$field])) {
                $newValue = trim($_POST[$field]);
                if ($newValue !== $currentComponent[$field]) {
                    $updateData[$field] = $newValue;
                    $updatedFields[] = $field;
                }
            }
        }
        
        // Special validation for status change
        if (isset($updateData['Status'])) {
            $newStatus = (int)$updateData['Status'];
            if (!in_array($newStatus, [0, 1, 2])) {
                send_json_response(0, 1, 400, "Invalid status value");
            }
            
            // Validate server UUID requirement for in-use status
            $serverUUID = $updateData['ServerUUID'] ?? $currentComponent['ServerUUID'];
            if ($newStatus === 2 && empty($serverUUID)) {
                send_json_response(0, 1, 400, "ServerUUID is required when status is 'In Use'");
            }
        }
        
        if (empty($updateData)) {
            send_json_response(1, 1, 200, "No changes detected", [
                'updated_fields' => []
            ]);
        }
        
        // Add metadata
        $currentUser = getCurrentUser();
        $updateData['UpdatedBy'] = $currentUser['username'] ?? 'system';
        $updateData['UpdatedAt'] = date('Y-m-d H:i:s');
        
        // Build update query
        $setParts = array_map(function($field) { return "$field = :$field"; }, array_keys($updateData));
        $updateQuery = "UPDATE $table SET " . implode(', ', $setParts) . " WHERE ID = :id";
        
        $stmt = $pdo->prepare($updateQuery);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        foreach ($updateData as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        
        $stmt->execute();
        
        // Log the update (if audit table exists)
        try {
            logComponentAction($id, 'updated', $updateData, $currentComponent, $updateData['UpdatedBy']);
        } catch (Exception $e) {
            error_log("Audit logging failed: " . $e->getMessage());
        }
        
        send_json_response(1, 1, 200, "Component updated successfully", [
            'updated_fields' => $updatedFields
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleUpdateComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred");
    }
}

// Delete component
function handleDeleteComponent() {
    global $pdo, $table, $componentType;
    
    validatePermission("{$componentType}.delete");
    
    $id = $_POST['id'] ?? '';
    
    if (empty($id) || !is_numeric($id)) {
        send_json_response(0, 1, 400, "Valid component ID required");
    }
    
    try {
        // Get component data before deletion
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $component = $stmt->fetch();
        
        if (!$component) {
            send_json_response(0, 1, 404, "Component not found");
        }
        
        // Check if component is in use
        if ($component['Status'] == 2) {
            send_json_response(0, 1, 403, "Cannot delete component that is currently in use");
        }
        
        // Delete the component
        $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE ID = :id");
        $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $deleteStmt->execute();
        
        // Log the deletion (if audit table exists)
        try {
            $currentUser = getCurrentUser();
            logComponentAction($id, 'deleted', [], $component, $currentUser['username'] ?? 'system');
        } catch (Exception $e) {
            error_log("Audit logging failed: " . $e->getMessage());
        }
        
        send_json_response(1, 1, 200, "Component deleted successfully");
        
    } catch (PDOException $e) {
        error_log("Database error in handleDeleteComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred");
    }
}

// Bulk update components
function handleBulkUpdateComponents() {
    global $pdo, $table, $componentType;
    
    validatePermission("{$componentType}.edit");
    
    $ids = $_POST['ids'] ?? [];
    
    if (empty($ids) || !is_array($ids)) {
        send_json_response(0, 1, 400, "Component IDs array required");
    }
    
    // Limit bulk operations
    if (count($ids) > 100) {
        send_json_response(0, 1, 400, "Maximum 100 components can be updated at once");
    }
    
    try {
        $pdo->beginTransaction();
        
        $updated = 0;
        $failed = 0;
        
        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                $failed++;
                continue;
            }
            
            // Prepare update data for this component (same logic as single update)
            $updateData = [];
            
            $allowedFields = [
                'Status', 'Location', 'RackPosition', 'Flag'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') {
                    $updateData[$field] = trim($_POST[$field]);
                }
            }
            
            if (empty($updateData)) {
                continue;
            }
            
            $currentUser = getCurrentUser();
            $updateData['UpdatedBy'] = $currentUser['username'] ?? 'system';
            $updateData['UpdatedAt'] = date('Y-m-d H:i:s');
            
            $setParts = array_map(function($field) { return "$field = :$field"; }, array_keys($updateData));
            $updateQuery = "UPDATE $table SET " . implode(', ', $setParts) . " WHERE ID = :id";
            
            $stmt = $pdo->prepare($updateQuery);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            foreach ($updateData as $key => $value) {
                $stmt->bindValue(":$key", $value);
            }
            
            if ($stmt->execute()) {
                $updated++;
            } else {
                $failed++;
            }
        }
        
        $pdo->commit();
        
        send_json_response(1, 1, 200, "$updated components updated successfully", [
            'updated' => $updated,
            'failed' => $failed
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Database error in handleBulkUpdateComponents: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred");
    }
}

// Get JSON data for component type
function handleGetJSONData() {
    global $componentType;
    
    validatePermission("{$componentType}.view");
    
    $jsonData = loadComponentJSONData($componentType);
    
    send_json_response(1, 1, 200, "JSON data retrieved successfully", [
        'component_type' => $componentType,
        'data' => $jsonData
    ]);
}

// Helper functions
function getStatusText($status) {
    switch ((int)$status) {
        case 0: return 'Failed';
        case 1: return 'Available';
        case 2: return 'In Use';
        default: return 'Unknown';
    }
}

function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function logComponentAction($componentId, $action, $newData, $oldData, $username) {
    global $pdo, $table;
    
    try {
        $auditTable = $table . '_audit';
        $logData = [
            'component_id' => $componentId,
            'action' => $action,
            'old_data' => json_encode($oldData),
            'new_data' => json_encode($newData),
            'user' => $username,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $stmt = $pdo->prepare("INSERT INTO $auditTable (component_id, action, old_data, new_data, user, timestamp) VALUES (:component_id, :action, :old_data, :new_data, :user, :timestamp)");
        
        foreach ($logData as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        
        $stmt->execute();
    } catch (PDOException $e) {
        // Audit table might not exist, that's okay
        error_log("Audit logging failed: " . $e->getMessage());
    }
}

?>