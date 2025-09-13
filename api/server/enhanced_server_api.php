<?php
/**
 * Infrastructure Management System - Enhanced Server API
 * File: api/server/enhanced_server_api.php
 * 
 * Enhanced server component management with chassis compatibility validation
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/BaseFunctions.php';
require_once __DIR__ . '/../../includes/models/ServerBuilder.php';
require_once __DIR__ . '/../../includes/models/ServerConfiguration.php';
require_once __DIR__ . '/../../includes/models/CompatibilityEngine.php';
require_once __DIR__ . '/../../includes/models/ChassisManager.php';
require_once __DIR__ . '/../../includes/models/StorageChassisCompatibility.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Initialize database connection and authentication
global $pdo;
if (!$pdo) {
    require_once __DIR__ . '/../../includes/db_config.php';
}

$baseFunctions = new BaseFunctions($pdo);
$authResult = $baseFunctions->authenticate();

if (!$authResult['authenticated']) {
    http_response_code(401);
    echo json_encode($authResult);
    exit;
}

$user = $authResult['user'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'server-add-component-enhanced':
            echo handleEnhancedAddComponent($pdo, $baseFunctions, $user);
            break;
            
        case 'server-validate-config-enhanced':
            echo handleEnhancedValidateConfig($pdo, $baseFunctions, $user);
            break;
            
        case 'server-assign-chassis':
            echo handleAssignChassis($pdo, $baseFunctions, $user);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'authenticated' => true,
                'message' => 'Invalid action specified',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'authenticated' => true,
        'message' => 'Server error: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

/**
 * Enhanced component addition with chassis validation
 */
function handleEnhancedAddComponent($pdo, $baseFunctions, $user) {
    if (!$baseFunctions->hasPermission('server.create') && !$baseFunctions->hasPermission('server.edit')) {
        http_response_code(403);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Permission denied: server.create or server.edit required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? '';
    $chassisUuid = $_POST['chassis_uuid'] ?? null;
    $targetBay = $_POST['target_bay'] ?? null;
    $quantity = (int)($_POST['quantity'] ?? 1);
    $slotPosition = $_POST['slot_position'] ?? null;
    $notes = $_POST['notes'] ?? '';
    $override = filter_var($_POST['override'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($configUuid) || empty($componentType) || empty($componentUuid)) {
        http_response_code(400);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Configuration UUID, component type, and component UUID are required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    try {
        // Verify configuration exists
        $stmt = $pdo->prepare("SELECT * FROM server_configurations WHERE UUID = ?");
        $stmt->execute([$configUuid]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            http_response_code(404);
            return json_encode([
                'success' => false,
                'authenticated' => true,
                'message' => 'Server configuration not found',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Check permissions
        if ($config['created_by'] != $user['id'] && !$baseFunctions->hasPermission('server.edit_all')) {
            http_response_code(403);
            return json_encode([
                'success' => false,
                'authenticated' => true,
                'message' => 'Insufficient permissions to modify this configuration',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Get component details
        $componentDetails = getComponentDetails($pdo, $componentType, $componentUuid);
        if (!$componentDetails) {
            http_response_code(404);
            return json_encode([
                'success' => false,
                'authenticated' => true,
                'message' => 'Component not found',
                'data' => [
                    'component_type' => $componentType,
                    'component_uuid' => $componentUuid
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Enhanced compatibility validation for storage components with chassis
        if ($componentType === 'storage' && $chassisUuid) {
            $chassisCompatibility = new StorageChassisCompatibility($pdo);
            $validationResult = $chassisCompatibility->validateStorageForConfiguration(
                $configUuid, $componentUuid, $chassisUuid, $targetBay
            );
            
            if (!$validationResult['compatible'] && !$override) {
                return json_encode([
                    'success' => false,
                    'authenticated' => true,
                    'message' => 'Storage-chassis compatibility validation failed',
                    'data' => [
                        'compatibility_result' => $validationResult,
                        'suggestion' => 'Use override=true to force addition or select compatible chassis'
                    ],
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }
        }
        
        // Check component availability
        $availabilityResult = checkComponentAvailability($componentDetails, $configUuid, $override);
        if (!$availabilityResult['available'] && !$override) {
            http_response_code(409);
            return json_encode([
                'success' => false,
                'authenticated' => true,
                'message' => $availabilityResult['message'],
                'data' => [
                    'component_status' => $availabilityResult['status'],
                    'current_assignment' => $availabilityResult['current_assignment']
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        
        $pdo->beginTransaction();
        
        try {
            // Add component to configuration
            $stmt = $pdo->prepare("
                INSERT INTO server_configuration_components 
                (config_uuid, component_type, component_uuid, quantity, slot_position, notes, 
                 chassis_uuid, bay_assignment, connection_type, pcie_lanes, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            // Determine connection details for storage components
            $connectionType = null;
            $pcieLanes = null;
            
            if ($componentType === 'storage' && $chassisUuid) {
                $storageSpecs = loadStorageSpecifications($componentUuid);
                if ($storageSpecs) {
                    $connectionType = $storageSpecs['interface']['type'] ?? null;
                    $pcieLanes = ($connectionType === 'NVMe') ? ($storageSpecs['pcie_specs']['lanes'] ?? 4) : null;
                }
            }
            
            $stmt->execute([
                $configUuid, $componentType, $componentUuid, $quantity, $slotPosition, $notes,
                $chassisUuid, $targetBay, $connectionType, $pcieLanes
            ]);
            
            // Update component status to In Use
            $componentTable = getComponentTableName($componentType);
            $stmt = $pdo->prepare("UPDATE {$componentTable} SET Status = 2, ServerUUID = ? WHERE UUID = ?");
            $stmt->execute([$configUuid, $componentUuid]);
            
            // Add storage-chassis mapping if applicable
            if ($componentType === 'storage' && $chassisUuid && $targetBay) {
                $stmt = $pdo->prepare("
                    INSERT INTO storage_chassis_mapping 
                    (config_uuid, storage_uuid, chassis_uuid, bay_assignment, bay_type, 
                     connection_type, pcie_lanes, connection_validated)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ");
                
                $stmt->execute([
                    $configUuid, $componentUuid, $chassisUuid, $targetBay,
                    determineBayType($targetBay), $connectionType, $pcieLanes
                ]);
            }
            
            $pdo->commit();
            
            // Prepare response data
            $responseData = [
                'config_uuid' => $configUuid,
                'component_added' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'chassis_uuid' => $chassisUuid,
                    'bay_assignment' => $targetBay
                ]
            ];
            
            // Add validation results if chassis compatibility was checked
            if (isset($validationResult)) {
                $responseData['compatibility_validation'] = $validationResult;
            }
            
            return json_encode([
                'success' => true,
                'authenticated' => true,
                'message' => 'Component added successfully to server configuration',
                'data' => $responseData,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        error_log("Enhanced add component error: " . $e->getMessage());
        http_response_code(500);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Database error: ' . $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}

/**
 * Enhanced configuration validation with chassis checking
 */
function handleEnhancedValidateConfig($pdo, $baseFunctions, $user) {
    if (!$baseFunctions->hasPermission('server.view') && !$baseFunctions->hasPermission('server.validate')) {
        http_response_code(403);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Permission denied: server.view or server.validate required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $configUuid = $_POST['config_uuid'] ?? $_GET['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        http_response_code(400);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Configuration UUID is required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    try {
        // Get all components in configuration
        $stmt = $pdo->prepare("
            SELECT scc.*, 
                   CASE scc.component_type
                       WHEN 'cpu' THEN ci.UUID
                       WHEN 'ram' THEN ri.UUID
                       WHEN 'storage' THEN si.UUID
                       WHEN 'motherboard' THEN mi.UUID
                       WHEN 'nic' THEN ni.UUID
                       WHEN 'caddy' THEN cdi.UUID
                       ELSE NULL
                   END as component_exists
            FROM server_configuration_components scc
            LEFT JOIN cpuinventory ci ON scc.component_type = 'cpu' AND scc.component_uuid = ci.UUID
            LEFT JOIN raminventory ri ON scc.component_type = 'ram' AND scc.component_uuid = ri.UUID
            LEFT JOIN storageinventory si ON scc.component_type = 'storage' AND scc.component_uuid = si.UUID
            LEFT JOIN motherboardinventory mi ON scc.component_type = 'motherboard' AND scc.component_uuid = mi.UUID
            LEFT JOIN nicinventory ni ON scc.component_type = 'nic' AND scc.component_uuid = ni.UUID
            LEFT JOIN caddyinventory cdi ON scc.component_type = 'caddy' AND scc.component_uuid = cdi.UUID
            WHERE scc.config_uuid = ?
            ORDER BY scc.component_type, scc.created_at
        ");
        $stmt->execute([$configUuid]);
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($components)) {
            return json_encode([
                'success' => true,
                'authenticated' => true,
                'message' => 'No components found in configuration',
                'data' => [
                    'config_uuid' => $configUuid,
                    'validation_result' => [
                        'overall_compatible' => true,
                        'compatibility_score' => 1.0,
                        'component_validations' => [],
                        'issues' => [],
                        'warnings' => ['Configuration is empty'],
                        'recommendations' => ['Add components to complete server configuration']
                    ]
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Initialize validation results
        $validationResults = [
            'overall_compatible' => true,
            'compatibility_score' => 1.0,
            'component_validations' => [],
            'chassis_validations' => [],
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];
        
        $chassisCompatibility = new StorageChassisCompatibility($pdo);
        
        // Group components by type
        $componentsByType = [];
        foreach ($components as $component) {
            $componentsByType[$component['component_type']][] = $component;
        }
        
        // Validate storage-chassis combinations
        if (isset($componentsByType['storage'])) {
            foreach ($componentsByType['storage'] as $storage) {
                if (!empty($storage['chassis_uuid'])) {
                    $result = $chassisCompatibility->validateStorageForConfiguration(
                        $configUuid, $storage['component_uuid'], $storage['chassis_uuid'], $storage['bay_assignment']
                    );
                    
                    $validationResults['chassis_validations'][] = [
                        'storage_uuid' => $storage['component_uuid'],
                        'chassis_uuid' => $storage['chassis_uuid'],
                        'bay_assignment' => $storage['bay_assignment'],
                        'validation_result' => $result
                    ];
                    
                    if (!$result['compatible']) {
                        $validationResults['overall_compatible'] = false;
                        $validationResults['issues'] = array_merge($validationResults['issues'], $result['issues']);
                    }
                    
                    $validationResults['warnings'] = array_merge($validationResults['warnings'], $result['warnings']);
                    $validationResults['recommendations'] = array_merge($validationResults['recommendations'], $result['recommendations']);
                    
                    // Update overall compatibility score
                    $validationResults['compatibility_score'] = min($validationResults['compatibility_score'], $result['compatibility_score']);
                }
            }
        }
        
        // Traditional component compatibility validation
        $compatibilityEngine = new CompatibilityEngine($pdo);
        
        // CPU-Motherboard compatibility
        if (isset($componentsByType['cpu']) && isset($componentsByType['motherboard'])) {
            foreach ($componentsByType['cpu'] as $cpu) {
                foreach ($componentsByType['motherboard'] as $motherboard) {
                    $result = $compatibilityEngine->checkCPUMotherboardCompatibility($cpu['component_uuid'], $motherboard['component_uuid']);
                    
                    $validationResults['component_validations'][] = [
                        'type' => 'cpu-motherboard',
                        'cpu_uuid' => $cpu['component_uuid'],
                        'motherboard_uuid' => $motherboard['component_uuid'],
                        'result' => $result
                    ];
                    
                    if (!$result['compatible']) {
                        $validationResults['overall_compatible'] = false;
                        $validationResults['issues'] = array_merge($validationResults['issues'], $result['issues']);
                    }
                    
                    $validationResults['compatibility_score'] = min($validationResults['compatibility_score'], $result['compatibility_score']);
                }
            }
        }
        
        // RAM-Motherboard compatibility
        if (isset($componentsByType['ram']) && isset($componentsByType['motherboard'])) {
            foreach ($componentsByType['motherboard'] as $motherboard) {
                $ramComponents = $componentsByType['ram'];
                $result = $compatibilityEngine->checkRAMMotherboardCompatibility($ramComponents, $motherboard['component_uuid']);
                
                $validationResults['component_validations'][] = [
                    'type' => 'ram-motherboard',
                    'ram_components' => array_column($ramComponents, 'component_uuid'),
                    'motherboard_uuid' => $motherboard['component_uuid'],
                    'result' => $result
                ];
                
                if (!$result['compatible']) {
                    $validationResults['overall_compatible'] = false;
                    $validationResults['issues'] = array_merge($validationResults['issues'], $result['issues']);
                }
                
                $validationResults['compatibility_score'] = min($validationResults['compatibility_score'], $result['compatibility_score']);
            }
        }
        
        // Bay conflict detection
        $bayConflicts = detectBayConflicts($components);
        if (!empty($bayConflicts)) {
            $validationResults['overall_compatible'] = false;
            $validationResults['issues'] = array_merge($validationResults['issues'], $bayConflicts);
        }
        
        // Remove duplicate warnings and recommendations
        $validationResults['warnings'] = array_unique($validationResults['warnings']);
        $validationResults['recommendations'] = array_unique($validationResults['recommendations']);
        
        return json_encode([
            'success' => true,
            'authenticated' => true,
            'message' => 'Configuration validation completed',
            'data' => [
                'config_uuid' => $configUuid,
                'component_count' => count($components),
                'validation_result' => $validationResults
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        error_log("Enhanced config validation error: " . $e->getMessage());
        http_response_code(500);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Validation error: ' . $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}

/**
 * Assign chassis to server configuration
 */
function handleAssignChassis($pdo, $baseFunctions, $user) {
    if (!$baseFunctions->hasPermission('chassis.assign')) {
        http_response_code(403);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Permission denied: chassis.assign required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $chassisUuid = $_POST['chassis_uuid'] ?? '';
    
    if (empty($configUuid) || empty($chassisUuid)) {
        http_response_code(400);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Configuration UUID and Chassis UUID are required',
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    try {
        // Validate chassis exists and is available
        $stmt = $pdo->prepare("SELECT Status, ServerUUID FROM chassisinventory WHERE UUID = ?");
        $stmt->execute([$chassisUuid]);
        $chassis = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$chassis) {
            http_response_code(404);
            return json_encode([
                'success' => false,
                'authenticated' => true,
                'message' => 'Chassis not found',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        
        if ($chassis['Status'] != 1 && $chassis['ServerUUID'] !== $configUuid) {
            return json_encode([
                'success' => false,
                'authenticated' => true,
                'message' => 'Chassis is not available for assignment',
                'data' => [
                    'current_status' => $chassis['Status'],
                    'current_assignment' => $chassis['ServerUUID']
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        }
        
        // Update chassis assignment
        $stmt = $pdo->prepare("UPDATE chassisinventory SET Status = 2, ServerUUID = ? WHERE UUID = ?");
        $stmt->execute([$configUuid, $chassisUuid]);
        
        return json_encode([
            'success' => true,
            'authenticated' => true,
            'message' => 'Chassis assigned to server configuration successfully',
            'data' => [
                'config_uuid' => $configUuid,
                'chassis_uuid' => $chassisUuid
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        error_log("Chassis assignment error: " . $e->getMessage());
        http_response_code(500);
        return json_encode([
            'success' => false,
            'authenticated' => true,
            'message' => 'Database error: ' . $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}

// Helper functions
function getComponentDetails($pdo, $componentType, $componentUuid) {
    $table = getComponentTableName($componentType);
    if (!$table) return null;
    
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE UUID = ?");
    $stmt->execute([$componentUuid]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getComponentTableName($componentType) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory', 
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    return $tableMap[$componentType] ?? null;
}

function checkComponentAvailability($componentDetails, $configUuid, $override) {
    $status = (int)$componentDetails['Status'];
    $currentAssignment = $componentDetails['ServerUUID'] ?? null;
    
    switch ($status) {
        case 0:
            return [
                'available' => false,
                'message' => 'Component is marked as Failed/Defective',
                'status' => $status,
                'current_assignment' => $currentAssignment
            ];
            
        case 1:
            return [
                'available' => true,
                'message' => 'Component is Available',
                'status' => $status,
                'current_assignment' => null
            ];
            
        case 2:
            if ($currentAssignment === $configUuid) {
                return [
                    'available' => true,
                    'message' => 'Component is already assigned to this configuration',
                    'status' => $status,
                    'current_assignment' => $currentAssignment
                ];
            } else {
                return [
                    'available' => $override,
                    'message' => $override ? 
                        'Component is In Use but override enabled' :
                        "Component is currently In Use in configuration: {$currentAssignment}",
                    'status' => $status,
                    'current_assignment' => $currentAssignment
                ];
            }
            
        default:
            return [
                'available' => false,
                'message' => "Component has unknown status: {$status}",
                'status' => $status,
                'current_assignment' => $currentAssignment
            ];
    }
}

function loadStorageSpecifications($storageUuid) {
    // This would load from storage-level-3.json
    // Placeholder implementation
    return [
        'interface' => ['type' => 'SATA'],
        'pcie_specs' => ['lanes' => 4]
    ];
}

function determineBayType($bayAssignment) {
    if (strpos($bayAssignment, '2.5_inch') !== false) return '2.5_inch';
    if (strpos($bayAssignment, '3.5_inch') !== false) return '3.5_inch';
    if (strpos($bayAssignment, 'M.2') !== false) return 'M.2_slot';
    return 'unknown';
}

function detectBayConflicts($components) {
    $conflicts = [];
    $bayAssignments = [];
    
    foreach ($components as $component) {
        if ($component['component_type'] === 'storage' && 
            !empty($component['chassis_uuid']) && 
            !empty($component['bay_assignment'])) {
            
            $key = $component['chassis_uuid'] . ':' . $component['bay_assignment'];
            
            if (isset($bayAssignments[$key])) {
                $conflicts[] = "Bay conflict: {$component['bay_assignment']} in chassis {$component['chassis_uuid']} assigned to multiple storage devices";
            } else {
                $bayAssignments[$key] = $component['component_uuid'];
            }
        }
    }
    
    return $conflicts;
}
?>