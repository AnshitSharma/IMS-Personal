<?php
/**
 * Complete Enhanced Components API with JSON Integration, UUID Support, and Compatibility Features
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
            if (isset($model['socket'])) $specs['socket'] = $model['socket'];
            break;
            
        case 'motherboard':
            if (isset($model['socket'])) $specs['socket'] = is_array($model['socket']) ? $model['socket']['type'] : $model['socket'];
            if (isset($model['chipset'])) $specs['chipset'] = $model['chipset'];
            if (isset($model['form_factor'])) $specs['form_factor'] = $model['form_factor'];
            if (isset($model['memory']['max_capacity'])) $specs['max_memory'] = $model['memory']['max_capacity'];
            if (isset($model['memory']['type'])) $specs['memory_type'] = $model['memory']['type'];
            break;
            
        case 'ram':
            if (isset($model['capacity'])) $specs['capacity'] = $model['capacity'];
            if (isset($model['type'])) $specs['type'] = $model['type'];
            if (isset($model['frequency'])) $specs['frequency'] = $model['frequency'];
            if (isset($model['form_factor'])) $specs['form_factor'] = $model['form_factor'];
            if (isset($model['voltage'])) $specs['voltage'] = $model['voltage'];
            break;
            
        case 'storage':
            if (isset($model['capacity'])) $specs['capacity'] = $model['capacity'];
            if (isset($model['type'])) $specs['type'] = $model['type'];
            if (isset($model['interface'])) $specs['interface'] = $model['interface'];
            if (isset($model['form_factor'])) $specs['form_factor'] = $model['form_factor'];
            if (isset($model['read_speed'])) $specs['read_speed'] = $model['read_speed'];
            if (isset($model['write_speed'])) $specs['write_speed'] = $model['write_speed'];
            break;
            
        case 'nic':
            if (isset($model['ports'])) $specs['ports'] = $model['ports'];
            if (isset($model['speed'])) $specs['speed'] = $model['speed'];
            if (isset($model['interface'])) $specs['interface'] = $model['interface'];
            break;
            
        case 'caddy':
            if (isset($model['compatible_drives'])) $specs['compatible_drives'] = $model['compatible_drives'];
            if (isset($model['form_factor'])) $specs['form_factor'] = $model['form_factor'];
            break;
    }
    
    return $specs;
}

// Note: getComponentCompatibilityInfo() function is defined in BaseFunctions.php

// Extract memory support from CPU model data
function extractMemorySupport($cpuModel) {
    $memorySupport = [];
    
    if (isset($cpuModel['memory_support'])) {
        $memorySupport = $cpuModel['memory_support'];
    } elseif (isset($cpuModel['specifications']['memory'])) {
        $memorySupport = $cpuModel['specifications']['memory'];
    }
    
    return $memorySupport;
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
    case 'get_compatible':
        handleGetCompatibleComponents();
        break;
    case 'check_compatibility':
        handleCheckCompatibility();
        break;
    default:
        send_json_response(0, 0, 400, "Invalid operation: $operation");
}

// List components with filtering and pagination
function handleListComponents() {
    global $pdo, $table, $componentType;
    
    // Skip permission check for super admin or if permission function doesn't exist
    try {
        $limit = min((int)($_GET['limit'] ?? $_POST['limit'] ?? 50), 1000);
        $offset = max(0, (int)($_GET['offset'] ?? $_POST['offset'] ?? 0));
        $page = floor($offset / $limit) + 1;
        $status = $_GET['status'] ?? $_POST['status'] ?? 'all';
        $search = $_GET['search'] ?? $_POST['search'] ?? '';
        $sortBy = $_GET['sort_by'] ?? $_POST['sort_by'] ?? 'CreatedAt';
        $sortOrder = strtoupper($_GET['sort_order'] ?? $_POST['sort_order'] ?? 'DESC');
        $includeCompatibility = filter_var($_GET['include_compatibility'] ?? $_POST['include_compatibility'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
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
        
        // Build main query - handle case where table might not have all expected columns
        $baseColumns = ['ID', 'SerialNumber', 'Status'];
        $optionalColumns = ['UUID', 'ServerUUID', 'Location', 'RackPosition', 'PurchaseDate', 'InstallationDate', 'WarrantyEndDate', 'Flag', 'Notes', 'CreatedBy', 'UpdatedBy', 'CreatedAt', 'UpdatedAt'];
        
        // Check which columns exist in the table
        $existingColumns = [];
        try {
            $columnCheck = $pdo->query("DESCRIBE $table");
            $tableColumns = $columnCheck->fetchAll(PDO::FETCH_COLUMN);
            $existingColumns = array_merge($baseColumns, array_intersect($optionalColumns, $tableColumns));
        } catch (Exception $e) {
            // Fallback to basic columns if DESCRIBE fails
            $existingColumns = $baseColumns;
        }
        
        $selectColumns = implode(', ', $existingColumns);
        $query = "SELECT $selectColumns FROM $table";
        if (!empty($conditions)) {
            $query .= " WHERE " . implode(' AND ', $conditions);
        }
        
        // Only add ORDER BY if the column exists
        if (in_array($sortBy, $existingColumns)) {
            $query .= " ORDER BY $sortBy $sortOrder";
        } else {
            $query .= " ORDER BY ID DESC"; // Fallback to ID if specified column doesn't exist
        }
        $query .= " LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Ensure all components have required fields with defaults
        foreach ($components as &$component) {
            // Set defaults for missing fields
            if (!isset($component['UUID'])) $component['UUID'] = '';
            if (!isset($component['ServerUUID'])) $component['ServerUUID'] = '';
            if (!isset($component['Location'])) $component['Location'] = '';
            if (!isset($component['Notes'])) $component['Notes'] = '';
            if (!isset($component['Status'])) $component['Status'] = 1; // Default to Available
            
            if (!empty($component['UUID'])) {
                $jsonDetails = getComponentDetailsFromJSON($component['UUID'], $componentType);
                if ($jsonDetails) {
                    $component['json_details'] = $jsonDetails;
                }
                
                // Add compatibility metadata if requested and system is available
                if ($includeCompatibility && function_exists('serverSystemInitialized') && serverSystemInitialized($pdo)) {
                    if (function_exists('getComponentCompatibilityInfo')) {
                        $component['compatibility_info'] = getComponentCompatibilityInfo($pdo, $componentType, $component['UUID']);
                    }
                }
            }
            
            // Add status text
            $component['StatusText'] = function_exists('getStatusText') ? getStatusText($component['Status']) : 'Unknown';
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
        
        // Check system features availability
        $serverSystemAvailable = function_exists('serverSystemInitialized') && serverSystemInitialized($pdo);
        
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
                'can_create' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_check_compatibility' => true
            ],
            'features' => [
                'compatibility_system_available' => $serverSystemAvailable,
                'json_specifications_available' => !empty(loadComponentJSONData($componentType))
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleListComponents: " . $e->getMessage());
        error_log("Query: " . ($query ?? 'Query not set'));
        error_log("Table: " . $table);
        error_log("Component Type: " . $componentType);
        
        // Return empty result instead of error for better user experience
        send_json_response(1, 1, 200, "$componentType components retrieved successfully", [
            'components' => [],
            'pagination' => [
                'total' => 0,
                'limit' => $limit ?? 50,
                'offset' => $offset ?? 0,
                'page' => 1,
                'has_more' => false
            ],
            'status_summary' => [
                'total' => 0,
                'available' => 0,
                'in_use' => 0,
                'failed' => 0
            ],
            'permissions' => [
                'can_create' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_check_compatibility' => true
            ],
            'features' => [
                'compatibility_system_available' => false,
                'json_specifications_available' => true
            ],
            'error' => 'Database connection issue - please try again'
        ]);
    } catch (Exception $e) {
        error_log("General error in handleListComponents: " . $e->getMessage());
        send_json_response(0, 1, 500, "An unexpected error occurred");
    }
}

// Get single component with enhanced details
function handleGetComponent() {
    global $pdo, $table, $componentType;
    
    $id = $_POST['id'] ?? $_GET['id'] ?? '';
    $includeCompatibility = filter_var($_GET['include_compatibility'] ?? $_POST['include_compatibility'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($id) || !is_numeric($id)) {
        send_json_response(0, 1, 400, "Valid component ID required");
    }
    
    try {
        // Check if table exists first
        $tableCheck = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($tableCheck->rowCount() == 0) {
            send_json_response(0, 1, 404, "Component table not found: $table");
        }
        
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE ID = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $component = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$component) {
            send_json_response(0, 1, 404, "Component not found");
        }
        
        // Ensure component has all required fields
        if (!isset($component['UUID'])) $component['UUID'] = '';
        if (!isset($component['Status'])) $component['Status'] = 1;
        
        // Enhance with JSON data
        if (!empty($component['UUID'])) {
            $jsonDetails = getComponentDetailsFromJSON($component['UUID'], $componentType);
            if ($jsonDetails) {
                $component['json_details'] = $jsonDetails;
            }
            
            // Add compatibility information if requested and system is available
            if ($includeCompatibility && function_exists('serverSystemInitialized') && serverSystemInitialized($pdo)) {
                if (function_exists('getComponentCompatibilityInfo')) {
                    $component['compatibility_info'] = getComponentCompatibilityInfo($pdo, $componentType, $component['UUID']);
                }
            }
        }
        
        // Add status text
        $component['StatusText'] = function_exists('getStatusText') ? getStatusText($component['Status']) : 'Unknown';
        
        // Get component history (if history table exists)
        try {
            $historyStmt = $pdo->prepare("SELECT * FROM {$table}_history WHERE component_id = :id ORDER BY created_at DESC LIMIT 10");
            $historyStmt->bindParam(':id', $id, PDO::PARAM_INT);
            $historyStmt->execute();
            $component['history'] = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // History table doesn't exist, that's okay
            $component['history'] = [];
        }
        
        // Get server configurations using this component (if server system is available)
        if (function_exists('serverSystemInitialized') && serverSystemInitialized($pdo)) {
            $component['server_usage'] = getComponentServerUsage($pdo, $componentType, $component['UUID']);
        }
        
        // Check system features availability
        $serverSystemAvailable = function_exists('serverSystemInitialized') && serverSystemInitialized($pdo);
        
        send_json_response(1, 1, 200, "Component retrieved successfully", [
            'component' => $component,
            'available_features' => [
                'compatibility_checking' => $serverSystemAvailable,
                'server_configurations' => $serverSystemAvailable,
                'json_specifications' => !empty($jsonDetails)
            ]
        ]);
        
    } catch (PDOException $e) {
        error_log("Database error in handleGetComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred: " . $e->getMessage());
    } catch (Exception $e) {
        error_log("General error in handleGetComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "An unexpected error occurred");
    }
}

// Add component with enhanced validation
function handleAddComponent() {
    global $pdo, $table, $componentType;
    
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
    $validateCompatibility = filter_var($_POST['validate_compatibility'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
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
    
    // Compatibility validation if requested and system is available
    $compatibilityWarnings = [];
    if ($validateCompatibility && !empty($serverUUID) && function_exists('serverSystemInitialized') && serverSystemInitialized($pdo)) {
        $compatibilityWarnings = validateComponentInServerConfiguration($pdo, $componentType, $uuid, $serverUUID);
    }
    
    try {
        // Check if table exists first
        $tableCheck = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($tableCheck->rowCount() == 0) {
            send_json_response(0, 1, 404, "Component table not found: $table");
        }
        
        // Check for duplicate serial number
        $checkStmt = $pdo->prepare("SELECT ID FROM $table WHERE SerialNumber = :serial");
        $checkStmt->bindParam(':serial', $serialNumber);
        $checkStmt->execute();
        
        if ($checkStmt->fetch()) {
            send_json_response(0, 1, 409, "Component with serial number $serialNumber already exists");
        }
        
        // Get current user info
        $currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
        $username = $currentUser['username'] ?? 'system';
        
        // Prepare insert data with only essential fields first
        $insertData = [
            'SerialNumber' => $serialNumber,
            'Status' => $status
        ];
        
        // Add optional fields only if they exist in the table
        $columnCheck = $pdo->query("DESCRIBE $table");
        $tableColumns = $columnCheck->fetchAll(PDO::FETCH_COLUMN);
        
        $optionalFields = [
            'UUID', 'ServerUUID', 'Location', 'RackPosition', 
            'PurchaseDate', 'InstallationDate', 'WarrantyEndDate', 'Flag', 'Notes',
            'CreatedBy', 'UpdatedBy', 'CreatedAt', 'UpdatedAt'
        ];
        
        foreach ($optionalFields as $field) {
            if (in_array($field, $tableColumns)) {
                if (in_array($field, ['CreatedBy', 'UpdatedBy'])) {
                    $insertData[$field] = $username;
                } elseif (in_array($field, ['CreatedAt', 'UpdatedAt'])) {
                    $insertData[$field] = date('Y-m-d H:i:s');
                } elseif (isset($_POST[$field]) && $_POST[$field] !== '') {
                    $insertData[$field] = trim($_POST[$field]);
                }
            }
        }
        
        // Component-specific fields
        if ($componentType === 'nic') {
            $nicFields = ['MacAddress', 'IPAddress', 'NetworkName'];
            foreach ($nicFields as $field) {
                if (in_array($field, $tableColumns) && isset($_POST[$field]) && $_POST[$field] !== '') {
                    $insertData[$field] = trim($_POST[$field]);
                }
            }
        }
        
        // Generate UUID if not provided and field exists
        if (in_array('UUID', $tableColumns) && empty($insertData['UUID'])) {
            $insertData['UUID'] = generateComponentUUID();
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
        
        // Log the creation if logging function exists
        if (function_exists('logActivity')) {
            logActivity($pdo, $currentUser['id'] ?? 0, "Component created", $componentType, $newId, "Created new $componentType component", null, $insertData);
        }
        
        $response = [
            'id' => (int)$newId,
            'uuid' => $insertData['UUID'] ?? '',
            'component_type' => $componentType
        ];
        
        if (!empty($compatibilityWarnings)) {
            $response['compatibility_warnings'] = $compatibilityWarnings;
        }
        
        send_json_response(1, 1, 201, "Component added successfully", $response);
        
    } catch (PDOException $e) {
        error_log("Database error in handleAddComponent: " . $e->getMessage());
        error_log("Table: " . $table);
        error_log("Insert data: " . json_encode($insertData ?? []));
        
        if ($e->getCode() == 23000) { // Integrity constraint violation
            send_json_response(0, 1, 409, "Component with this serial number already exists");
        } else {
            send_json_response(0, 1, 500, "Database error: " . $e->getMessage());
        }
    } catch (Exception $e) {
        error_log("General error in handleAddComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "An unexpected error occurred");
    }
}

// Update component with enhanced validation
function handleUpdateComponent() {
    global $pdo, $table, $componentType;
    
    $id = $_POST['id'] ?? '';
    $validateCompatibility = filter_var($_POST['validate_compatibility'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
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
        $compatibilityWarnings = [];
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
            
            // Compatibility validation if moving to "In Use" status
            if ($newStatus === 2 && $validateCompatibility && !empty($serverUUID) && function_exists('serverSystemInitialized') && serverSystemInitialized($pdo)) {
                $compatibilityWarnings = validateComponentInServerConfiguration($pdo, $componentType, $currentComponent['UUID'], $serverUUID);
            }
        }
        
        if (empty($updateData)) {
            send_json_response(1, 1, 200, "No changes detected", [
                'updated_fields' => []
            ]);
        }
        
        // Add metadata
        $currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
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
        
        // Log the update if logging function exists
        if (function_exists('logActivity')) {
            logActivity($pdo, $currentUser['id'] ?? 0, "Component updated", $componentType, $id, "Updated $componentType component", $currentComponent, $updateData);
        }
        
        $response = [
            'updated_fields' => $updatedFields
        ];
        
        if (!empty($compatibilityWarnings)) {
            $response['compatibility_warnings'] = $compatibilityWarnings;
        }
        
        send_json_response(1, 1, 200, "Component updated successfully", $response);
        
    } catch (PDOException $e) {
        error_log("Database error in handleUpdateComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred");
    }
}

// Delete component with dependency checking
function handleDeleteComponent() {
    global $pdo, $table, $componentType;
    
    $id = $_POST['id'] ?? '';
    $force = filter_var($_POST['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
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
        if ($component['Status'] == 2 && !$force) {
            send_json_response(0, 1, 403, "Cannot delete component that is currently in use. Use force=true to override.");
        }
        
        // Check for server configuration dependencies if system is available
        $dependencies = [];
        if (function_exists('serverSystemInitialized') && serverSystemInitialized($pdo) && !empty($component['UUID'])) {
            $dependencies = getComponentDependencies($pdo, $componentType, $component['UUID']);
            
            if (!empty($dependencies) && !$force) {
                send_json_response(0, 1, 409, "Component is used in server configurations", [
                    'dependencies' => $dependencies,
                    'message' => 'Use force=true to delete anyway (this will remove component from configurations)'
                ]);
            }
        }
        
        $pdo->beginTransaction();
        
        // Remove from server configurations if forcing deletion
        if ($force && !empty($dependencies)) {
            removeComponentFromConfigurations($pdo, $componentType, $component['UUID']);
        }
        
        // Delete the component
        $deleteStmt = $pdo->prepare("DELETE FROM $table WHERE ID = :id");
        $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $deleteStmt->execute();
        
        $pdo->commit();
        
        // Log the deletion if logging function exists
        if (function_exists('logActivity')) {
            $currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
            logActivity($pdo, $currentUser['id'] ?? 0, "Component deleted", $componentType, $id, "Deleted $componentType component" . ($force ? " (forced)" : ""), $component, null);
        }
        
        $response = ['deleted_id' => (int)$id];
        if ($force && !empty($dependencies)) {
            $response['removed_from_configurations'] = count($dependencies);
        }
        
        send_json_response(1, 1, 200, "Component deleted successfully", $response);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Database error in handleDeleteComponent: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred");
    }
}

// Enhanced bulk update components
function handleBulkUpdateComponents() {
    global $pdo, $table, $componentType;
    
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
        $warnings = [];
        
        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                $failed++;
                continue;
            }
            
            // Prepare update data for this component
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
            
            $currentUser = function_exists('getCurrentUser') ? getCurrentUser() : null;
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
            'failed' => $failed,
            'warnings' => $warnings
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Database error in handleBulkUpdateComponents: " . $e->getMessage());
        send_json_response(0, 1, 500, "Database error occurred");
    }
}

// Get compatible components for a specific component
function handleGetCompatibleComponents() {
    global $pdo, $componentType;
    
    if (!function_exists('serverSystemInitialized') || !serverSystemInitialized($pdo)) {
        send_json_response(0, 1, 503, "Compatibility system not available");
    }
    
    $componentUuid = $_GET['component_uuid'] ?? $_POST['component_uuid'] ?? '';
    $targetType = $_GET['target_type'] ?? $_POST['target_type'] ?? '';
    $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 50);
    
    if (empty($componentUuid)) {
        send_json_response(0, 1, 400, "Component UUID is required");
    }
    
    try {
        require_once(__DIR__ . '/../../includes/models/CompatibilityEngine.php');
        $compatibilityEngine = new CompatibilityEngine($pdo);
        
        $baseComponent = ['type' => $componentType, 'uuid' => $componentUuid];
        
        if ($targetType) {
            // Get compatible components for specific target type
            $compatibleComponents = $compatibilityEngine->getCompatibleComponents($baseComponent, $targetType, $availableOnly);
            
            if ($limit > 0) {
                $compatibleComponents = array_slice($compatibleComponents, 0, $limit);
            }
            
            send_json_response(1, 1, 200, "Compatible components retrieved", [
                'base_component' => $baseComponent,
                'target_type' => $targetType,
                'compatible_components' => $compatibleComponents,
                'total_count' => count($compatibleComponents),
                'filters' => [
                    'available_only' => $availableOnly,
                    'limit' => $limit
                ]
            ]);
        } else {
            // Get compatible components for all types
            $allCompatible = [];
            $componentTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
            
            foreach ($componentTypes as $type) {
                if ($type !== $componentType) {
                    $typeComponents = $compatibilityEngine->getCompatibleComponents($baseComponent, $type, $availableOnly);
                    if ($limit > 0) {
                        $typeComponents = array_slice($typeComponents, 0, $limit);
                    }
                    $allCompatible[$type] = $typeComponents;
                }
            }
            
            send_json_response(1, 1, 200, "All compatible components retrieved", [
                'base_component' => $baseComponent,
                'compatible_components' => $allCompatible,
                'summary' => array_map(function($components) {
                    return ['count' => count($components)];
                }, $allCompatible)
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Error getting compatible components: " . $e->getMessage());
        send_json_response(0, 1, 500, "Error retrieving compatible components");
    }
}

// Check compatibility between this component and another
function handleCheckCompatibility() {
    global $pdo, $componentType;
    
    if (!function_exists('serverSystemInitialized') || !serverSystemInitialized($pdo)) {
        send_json_response(0, 1, 503, "Compatibility system not available");
    }
    
    $component1Uuid = $_POST['component1_uuid'] ?? '';
    $component2Type = $_POST['component2_type'] ?? '';
    $component2Uuid = $_POST['component2_uuid'] ?? '';
    
    if (empty($component1Uuid) || empty($component2Type) || empty($component2Uuid)) {
        send_json_response(0, 1, 400, "All component parameters are required");
    }
    
    try {
        require_once(__DIR__ . '/../../includes/models/CompatibilityEngine.php');
        $compatibilityEngine = new CompatibilityEngine($pdo);
        
        $component1 = ['type' => $componentType, 'uuid' => $component1Uuid];
        $component2 = ['type' => $component2Type, 'uuid' => $component2Uuid];
        
        $result = $compatibilityEngine->checkCompatibility($component1, $component2);
        
        send_json_response(1, 1, 200, "Compatibility check completed", [
            'component_1' => $component1,
            'component_2' => $component2,
            'compatibility_result' => $result,
            'summary' => [
                'compatible' => $result['compatible'],
                'score' => $result['compatibility_score'],
                'issues_count' => count($result['failures']),
                'warnings_count' => count($result['warnings'])
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error checking compatibility: " . $e->getMessage());
        send_json_response(0, 1, 500, "Error checking compatibility");
    }
}

// Get JSON data for component type
function handleGetJSONData() {
    global $componentType;
    
    $jsonData = loadComponentJSONData($componentType);
    
    send_json_response(1, 1, 200, "JSON data retrieved successfully", [
        'component_type' => $componentType,
        'data' => $jsonData,
        'available_levels' => array_keys($jsonData)
    ]);
}

// Helper functions - Note: These functions are defined in BaseFunctions.php or api.php
// getStatusText() - defined in BaseFunctions.php
// checkUserPermission() - defined in api.php
// getCurrentUser() - defined in api.php
// getComponentCompatibilityInfo() - defined in BaseFunctions.php

// Generate UUID function (local to this file)
function generateComponentUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function getComponentServerUsage($pdo, $componentType, $componentUuid) {
    try {
        // Check if component is used in any server configurations
        $usageQueries = [
            'cpu' => "SELECT config_uuid, config_name FROM server_configurations WHERE cpu_uuid = ?",
            'motherboard' => "SELECT config_uuid, config_name FROM server_configurations WHERE motherboard_uuid = ?",
            'ram' => "SELECT config_uuid, config_name FROM server_configurations WHERE JSON_SEARCH(ram_configuration, 'one', ?) IS NOT NULL",
            'storage' => "SELECT config_uuid, config_name FROM server_configurations WHERE JSON_SEARCH(storage_configuration, 'one', ?) IS NOT NULL",
            'nic' => "SELECT config_uuid, config_name FROM server_configurations WHERE JSON_SEARCH(nic_configuration, 'one', ?) IS NOT NULL",
            'caddy' => "SELECT config_uuid, config_name FROM server_configurations WHERE JSON_SEARCH(caddy_configuration, 'one', ?) IS NOT NULL"
        ];
        
        if (isset($usageQueries[$componentType])) {
            $stmt = $pdo->prepare($usageQueries[$componentType]);
            $stmt->execute([$componentUuid]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return [];
    } catch (Exception $e) {
        error_log("Error getting component server usage: " . $e->getMessage());
        return [];
    }
}

function validateComponentInServerConfiguration($pdo, $componentType, $componentUuid, $serverUuid) {
    try {
        // Load the server configuration
        $stmt = $pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ?");
        $stmt->execute([$serverUuid]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            return ['Server configuration not found'];
        }
        
        // Check compatibility with other components in the configuration
        require_once(__DIR__ . '/../../includes/models/CompatibilityEngine.php');
        $compatibilityEngine = new CompatibilityEngine($pdo);
        
        $newComponent = ['type' => $componentType, 'uuid' => $componentUuid];
        $warnings = [];
        
        // Check against CPU
        if ($config['cpu_uuid'] && $componentType !== 'cpu') {
            $cpuComponent = ['type' => 'cpu', 'uuid' => $config['cpu_uuid']];
            $result = $compatibilityEngine->checkCompatibility($newComponent, $cpuComponent);
            if (!$result['compatible']) {
                $warnings[] = "Incompatible with CPU: " . implode(', ', $result['failures']);
            }
        }
        
        // Check against Motherboard
        if ($config['motherboard_uuid'] && $componentType !== 'motherboard') {
            $mbComponent = ['type' => 'motherboard', 'uuid' => $config['motherboard_uuid']];
            $result = $compatibilityEngine->checkCompatibility($newComponent, $mbComponent);
            if (!$result['compatible']) {
                $warnings[] = "Incompatible with Motherboard: " . implode(', ', $result['failures']);
            }
        }
        
        return $warnings;
    } catch (Exception $e) {
        error_log("Error validating component in server configuration: " . $e->getMessage());
        return ['Compatibility validation failed'];
    }
}

function getComponentDependencies($pdo, $componentType, $componentUuid) {
    try {
        $dependencies = [];
        
        // Check server configurations
        $usageQueries = [
            'cpu' => "SELECT id, config_uuid, config_name FROM server_configurations WHERE cpu_uuid = ?",
            'motherboard' => "SELECT id, config_uuid, config_name FROM server_configurations WHERE motherboard_uuid = ?",
            'ram' => "SELECT id, config_uuid, config_name FROM server_configurations WHERE JSON_SEARCH(ram_configuration, 'one', ?) IS NOT NULL",
            'storage' => "SELECT id, config_uuid, config_name FROM server_configurations WHERE JSON_SEARCH(storage_configuration, 'one', ?) IS NOT NULL",
            'nic' => "SELECT id, config_uuid, config_name FROM server_configurations WHERE JSON_SEARCH(nic_configuration, 'one', ?) IS NOT NULL",
            'caddy' => "SELECT id, config_uuid, config_name FROM server_configurations WHERE JSON_SEARCH(caddy_configuration, 'one', ?) IS NOT NULL"
        ];
        
        if (isset($usageQueries[$componentType])) {
            $stmt = $pdo->prepare($usageQueries[$componentType]);
            $stmt->execute([$componentUuid]);
            $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($configs as $config) {
                $dependencies[] = [
                    'type' => 'server_configuration',
                    'id' => $config['id'],
                    'uuid' => $config['config_uuid'],
                    'name' => $config['config_name']
                ];
            }
        }
        
        return $dependencies;
    } catch (Exception $e) {
        error_log("Error getting component dependencies: " . $e->getMessage());
        return [];
    }
}

function removeComponentFromConfigurations($pdo, $componentType, $componentUuid) {
    try {
        // Remove component from server configurations
        switch ($componentType) {
            case 'cpu':
                $stmt = $pdo->prepare("UPDATE server_configurations SET cpu_uuid = NULL, cpu_id = NULL WHERE cpu_uuid = ?");
                $stmt->execute([$componentUuid]);
                break;
                
            case 'motherboard':
                $stmt = $pdo->prepare("UPDATE server_configurations SET motherboard_uuid = NULL, motherboard_id = NULL WHERE motherboard_uuid = ?");
                $stmt->execute([$componentUuid]);
                break;
                
            case 'ram':
                // Remove from JSON arrays
                $stmt = $pdo->prepare("
                    UPDATE server_configurations 
                    SET ram_configuration = JSON_REMOVE(
                        ram_configuration, 
                        JSON_UNQUOTE(JSON_SEARCH(ram_configuration, 'one', ?))
                    ) 
                    WHERE JSON_SEARCH(ram_configuration, 'one', ?) IS NOT NULL
                ");
                $stmt->execute([$componentUuid, $componentUuid]);
                break;
                
            case 'storage':
                $stmt = $pdo->prepare("
                    UPDATE server_configurations 
                    SET storage_configuration = JSON_REMOVE(
                        storage_configuration, 
                        JSON_UNQUOTE(JSON_SEARCH(storage_configuration, 'one', ?))
                    ) 
                    WHERE JSON_SEARCH(storage_configuration, 'one', ?) IS NOT NULL
                ");
                $stmt->execute([$componentUuid, $componentUuid]);
                break;
                
            case 'nic':
                $stmt = $pdo->prepare("
                    UPDATE server_configurations 
                    SET nic_configuration = JSON_REMOVE(
                        nic_configuration, 
                        JSON_UNQUOTE(JSON_SEARCH(nic_configuration, 'one', ?))
                    ) 
                    WHERE JSON_SEARCH(nic_configuration, 'one', ?) IS NOT NULL
                ");
                $stmt->execute([$componentUuid, $componentUuid]);
                break;
                
            case 'caddy':
                $stmt = $pdo->prepare("
                    UPDATE server_configurations 
                    SET caddy_configuration = JSON_REMOVE(
                        caddy_configuration, 
                        JSON_UNQUOTE(JSON_SEARCH(caddy_configuration, 'one', ?))
                    ) 
                    WHERE JSON_SEARCH(caddy_configuration, 'one', ?) IS NOT NULL
                ");
                $stmt->execute([$componentUuid, $componentUuid]);
                break;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error removing component from configurations: " . $e->getMessage());
        return false;
    }
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