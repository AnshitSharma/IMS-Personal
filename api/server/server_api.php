<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/BaseFunctions.php';
require_once __DIR__ . '/../../includes/models/ServerBuilder.php';
require_once __DIR__ . '/../../includes/models/ServerConfiguration.php';

header('Content-Type: application/json');

// Initialize authentication
$user = authenticateWithJWT($pdo);

if (!$user) {
    send_json_response(0, 0, 401, "Authentication required");
}

// Initialize ServerBuilder
try {
    $serverBuilder = new ServerBuilder($pdo);
} catch (Exception $e) {
    error_log("Failed to initialize ServerBuilder: " . $e->getMessage());
    send_json_response(0, 1, 500, "Server system unavailable");
}

// Get action from global operation or POST data
global $operation;
$action = $operation ?? $_POST['action'] ?? '';

switch ($action) {
    case 'create-start':
    case 'server-create-start':
        handleCreateStart($serverBuilder, $user);
        break;
    
    case 'add-component':
    case 'server-add-component':
        handleAddComponent($serverBuilder, $user);
        break;
    
    case 'remove-component':
    case 'server-remove-component':
        handleRemoveComponent($serverBuilder, $user);
        break;
    
    case 'get-config':
    case 'server-get-config':
        handleGetConfiguration($serverBuilder, $user);
        break;
    
    case 'list-configs':
    case 'server-list-configs':
        handleListConfigurations($serverBuilder, $user);
        break;
    
    case 'finalize-config':
    case 'server-finalize-config':
        handleFinalizeConfiguration($serverBuilder, $user);
        break;
    
    case 'delete-config':
    case 'server-delete-config':
        handleDeleteConfiguration($serverBuilder, $user);
        break;
    
    case 'get-available-components':
    case 'server-get-available-components':
        handleGetAvailableComponents($user);
        break;
    
    case 'validate-config':
    case 'server-validate-config':
        handleValidateConfiguration($serverBuilder, $user);
        break;
    
    case 'get-compatible':
    case 'server-get-compatible':
        handleGetCompatible($serverBuilder, $user);
        break;
    
    default:
        send_json_response(0, 1, 400, "Invalid action specified");
}

/**
 * Start server creation process
 */
function handleCreateStart($serverBuilder, $user) {
    global $pdo;
    
    $serverName = trim($_POST['server_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? 'custom');
    $motherboardUuid = trim($_POST['motherboard_uuid'] ?? '');
    
    if (empty($serverName)) {
        send_json_response(0, 1, 400, "Server name is required");
    }
    
    if (empty($motherboardUuid)) {
        send_json_response(0, 1, 400, "Motherboard UUID is required to start server creation");
    }
    
    try {
        // Validate motherboard exists and is available
        $motherboardDetails = getComponentDetails($pdo, 'motherboard', $motherboardUuid);
        if (!$motherboardDetails) {
            send_json_response(0, 1, 404, "Motherboard not found", [
                'motherboard_uuid' => $motherboardUuid
            ]);
        }
        
        // Check motherboard availability
        $motherboardStatus = (int)$motherboardDetails['Status'];
        if ($motherboardStatus !== 1) {
            $statusMessage = getStatusText($motherboardStatus);
            send_json_response(0, 1, 400, "Motherboard is not available", [
                'motherboard_status' => $motherboardStatus,
                'status_message' => $statusMessage,
                'motherboard_uuid' => $motherboardUuid
            ]);
        }
        
        // Create configuration
        $configUuid = $serverBuilder->createConfiguration($serverName, $user['id'], [
            'description' => $description,
            'category' => $category
        ]);
        
        // Add motherboard to configuration
        $addResult = $serverBuilder->addComponent($configUuid, 'motherboard', $motherboardUuid, [
            'quantity' => 1,
            'notes' => 'Initial motherboard for server configuration',
            'user_id' => $user['id']
        ]);
        
        if (!$addResult['success']) {
            // If motherboard addition failed, clean up the configuration
            $serverBuilder->deleteConfiguration($configUuid);
            send_json_response(0, 1, 400, "Failed to add motherboard to configuration: " . $addResult['message']);
        }
        
        // Parse motherboard specifications for component limits
        $motherboardSpecs = parseMotherboardSpecs($motherboardDetails);
        
        send_json_response(1, 1, 200, "Server configuration created successfully with motherboard", [
            'config_uuid' => $configUuid,
            'server_name' => $serverName,
            'description' => $description,
            'category' => $category,
            'motherboard_added' => [
                'uuid' => $motherboardUuid,
                'serial_number' => $motherboardDetails['SerialNumber'],
                'specifications' => $motherboardSpecs
            ],
            'progress' => [
                'total_steps' => 6,
                'completed_steps' => 1,
                'current_step' => 'cpu_selection',
                'step_descriptions' => [
                    1 => 'Motherboard Selection (Completed)',
                    2 => 'CPU Selection',
                    3 => 'Memory Selection', 
                    4 => 'Storage Selection',
                    5 => 'Network/Expansion Cards',
                    6 => 'Review & Finalization'
                ]
            ],
            'component_limits' => [
                'cpu_sockets' => $motherboardSpecs['cpu_sockets'] ?? 1,
                'memory_slots' => $motherboardSpecs['memory_slots'] ?? 4,
                'storage_slots' => $motherboardSpecs['storage_slots'] ?? [],
                'pcie_slots' => $motherboardSpecs['pcie_slots'] ?? []
            ],
            'next_recommendations' => [
                'component_type' => 'cpu',
                'max_quantity' => $motherboardSpecs['cpu_sockets'] ?? 1,
                'message' => 'Add CPU(s) compatible with motherboard socket type'
            ],
            'compatibility_engine_available' => class_exists('CompatibilityEngine')
        ]);
        
    } catch (Exception $e) {
        error_log("Error in server creation start: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to initialize server creation: " . $e->getMessage());
    }
}

/**
 * Add component to server configuration
 */
function handleAddComponent($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 1);
    $slotPosition = $_POST['slot_position'] ?? null;
    $notes = $_POST['notes'] ?? '';
    $override = filter_var($_POST['override'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($configUuid) || empty($componentType) || empty($componentUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID, component type, and component UUID are required");
    }
    
    try {
        // Load the configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check if user owns this configuration or has edit permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to modify this configuration");
        }
        
        // Get component details and validate availability
        $componentDetails = getComponentDetails($pdo, $componentType, $componentUuid);
        if (!$componentDetails) {
            send_json_response(0, 1, 404, "Component not found", [
                'component_type' => $componentType,
                'component_uuid' => $componentUuid
            ]);
        }
        
        // Enhanced availability check with better error messages
        $componentStatus = (int)$componentDetails['Status'];
        $isAvailable = false;
        $statusMessage = '';
        
        switch ($componentStatus) {
            case 0:
                $statusMessage = "Component is marked as Failed/Defective";
                break;
            case 1:
                $isAvailable = true;
                $statusMessage = "Component is Available";
                break;
            case 2:
                // Allow "In Use" components if override is enabled or for development
                if ($override) {
                    $isAvailable = true;
                    $statusMessage = "Component is In Use (override enabled)";
                } else {
                    $statusMessage = "Component is currently In Use";
                }
                break;
            default:
                $statusMessage = "Component has unknown status: $componentStatus";
        }
        
        if (!$isAvailable && !$override) {
            send_json_response(0, 1, 400, "Component is not available", [
                'component_status' => $componentStatus,
                'status_message' => $statusMessage,
                'component_details' => [
                    'uuid' => $componentDetails['UUID'],
                    'serial_number' => $componentDetails['SerialNumber'],
                    'current_status' => getStatusText($componentStatus)
                ],
                'can_override' => true,
                'suggested_alternatives' => getSuggestedAlternatives($pdo, $componentType, $componentUuid)
            ]);
        }
        
        // Add the component
        $result = $serverBuilder->addComponent($configUuid, $componentType, $componentUuid, [
            'quantity' => $quantity,
            'slot_position' => $slotPosition,
            'notes' => $notes,
            'override_used' => $override
        ]);
        
        if ($result['success']) {
            // Get updated configuration summary
            $summary = $serverBuilder->getConfigurationSummary($configUuid);
            
            // Calculate progress and determine next step
            $progressInfo = calculateConfigurationProgress($summary);
            
            // Get component limits from motherboard specs
            $componentLimits = [];
            $motherboardComponent = $summary['components']['motherboard'][0] ?? null;
            if ($motherboardComponent && isset($motherboardComponent['details'])) {
                $motherboardSpecs = parseMotherboardSpecs($motherboardComponent['details']);
                $componentLimits = [
                    'cpu_sockets' => [
                        'max' => $motherboardSpecs['cpu_sockets'] ?? 1,
                        'used' => $summary['component_counts']['cpu'] ?? 0,
                        'remaining' => ($motherboardSpecs['cpu_sockets'] ?? 1) - ($summary['component_counts']['cpu'] ?? 0)
                    ],
                    'memory_slots' => [
                        'max' => $motherboardSpecs['memory_slots'] ?? 4,
                        'used' => $summary['component_counts']['ram'] ?? 0,
                        'remaining' => ($motherboardSpecs['memory_slots'] ?? 4) - ($summary['component_counts']['ram'] ?? 0)
                    ],
                    'storage_slots' => [
                        'max' => array_sum($motherboardSpecs['storage_slots'] ?? []),
                        'used' => $summary['component_counts']['storage'] ?? 0,
                        'remaining' => array_sum($motherboardSpecs['storage_slots'] ?? []) - ($summary['component_counts']['storage'] ?? 0)
                    ]
                ];
            }
            
            // Get next recommended components if compatibility engine is available
            $nextRecommendations = [];
            if (class_exists('CompatibilityEngine')) {
                $compatibilityEngine = new CompatibilityEngine($pdo);
                $nextRecommendations = $compatibilityEngine->getRecommendedNextComponents($configUuid);
            }
            
            send_json_response(1, 1, 200, "Component added successfully", [
                'component_added' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'status_override_used' => $override,
                    'original_status' => $statusMessage
                ],
                'configuration_summary' => $summary,
                'progress' => $progressInfo,
                'component_limits' => $componentLimits,
                'next_recommendations' => getNextRecommendations($summary, $componentLimits),
                'compatibility_issues' => $result['compatibility_issues'] ?? []
            ]);
        } else {
            send_json_response(0, 1, 400, $result['message'] ?? "Failed to add component", [
                'compatibility_issues' => $result['compatibility_issues'] ?? [],
                'suggested_alternatives' => getSuggestedAlternatives($pdo, $componentType, $componentUuid)
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Error adding component: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to add component: " . $e->getMessage());
    }
}

/**
 * Remove component from server configuration
 */
function handleRemoveComponent($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? '';
    
    if (empty($configUuid) || empty($componentType) || empty($componentUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID, component type, and component UUID are required");
    }
    
    try {
        // Load the configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to modify this configuration");
        }
        
        $result = $serverBuilder->removeComponent($configUuid, $componentType, $componentUuid);
        
        if ($result['success']) {
            $summary = $serverBuilder->getConfigurationSummary($configUuid);
            
            send_json_response(1, 1, 200, "Component removed successfully", [
                'component_removed' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid
                ],
                'configuration_summary' => $summary
            ]);
        } else {
            send_json_response(0, 1, 400, $result['message'] ?? "Failed to remove component");
        }
        
    } catch (Exception $e) {
        error_log("Error removing component: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to remove component: " . $e->getMessage());
    }
}

/**
 * Get server configuration details
 */
function handleGetConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to view this configuration");
        }
        
        $summary = $serverBuilder->getConfigurationSummary($configUuid);
        $validation = $serverBuilder->validateConfiguration($configUuid);
        
        send_json_response(1, 1, 200, "Configuration retrieved successfully", [
            'configuration' => $config->toArray(),
            'summary' => $summary,
            'validation' => $validation
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to retrieve configuration: " . $e->getMessage());
    }
}

/**
 * List server configurations
 */
function handleListConfigurations($serverBuilder, $user) {
    global $pdo;
    
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? null;
    
    try {
        $whereClause = "WHERE 1=1";
        $params = [];
        
        // Filter by user if no admin permissions
        if (!hasPermission($pdo, 'server.view_all', $user['id'])) {
            $whereClause .= " AND created_by = ?";
            $params[] = $user['id'];
        }
        
        // Filter by status if provided
        if ($status !== null) {
            $whereClause .= " AND configuration_status = ?";
            $params[] = $status;
        }
        
        $stmt = $pdo->prepare("
            SELECT sc.*, u.username as created_by_username 
            FROM server_configurations sc 
            LEFT JOIN users u ON sc.created_by = u.id 
            $whereClause 
            ORDER BY sc.created_at DESC 
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $configurations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM server_configurations sc $whereClause");
        $countStmt->execute(array_slice($params, 0, -2)); // Remove limit and offset
        $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        send_json_response(1, 1, 200, "Configurations retrieved successfully", [
            'configurations' => $configurations,
            'pagination' => [
                'total' => $totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error listing configurations: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to list configurations: " . $e->getMessage());
    }
}

/**
 * Finalize server configuration
 */
function handleFinalizeConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $finalNotes = trim($_POST['notes'] ?? '');
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.finalize', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to finalize this configuration");
        }
        
        // Validate configuration before finalizing
        $validation = $serverBuilder->validateConfiguration($configUuid);
        if (!$validation['is_valid']) {
            send_json_response(0, 1, 400, "Configuration is not valid for finalization", [
                'validation_errors' => $validation['issues']
            ]);
        }
        
        $result = $serverBuilder->finalizeConfiguration($configUuid, $finalNotes);
        
        if ($result['success']) {
            send_json_response(1, 1, 200, "Configuration finalized successfully", [
                'config_uuid' => $configUuid,
                'finalization_details' => $result
            ]);
        } else {
            send_json_response(0, 1, 400, $result['message'] ?? "Failed to finalize configuration");
        }
        
    } catch (Exception $e) {
        error_log("Error finalizing configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to finalize configuration: " . $e->getMessage());
    }
}

/**
 * Delete server configuration
 */
function handleDeleteConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.delete', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to delete this configuration");
        }
        
        // Prevent deletion of finalized configurations unless admin
        if ($config->get('configuration_status') == 3 && !hasPermission($pdo, 'server.delete_finalized', $user['id'])) {
            send_json_response(0, 1, 403, "Cannot delete finalized configurations");
        }
        
        $result = $serverBuilder->deleteConfiguration($configUuid);
        
        if ($result['success']) {
            send_json_response(1, 1, 200, "Configuration deleted successfully");
        } else {
            send_json_response(0, 1, 400, $result['message'] ?? "Failed to delete configuration");
        }
        
    } catch (Exception $e) {
        error_log("Error deleting configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to delete configuration: " . $e->getMessage());
    }
}

/**
 * Get available components for selection
 */
function handleGetAvailableComponents($user) {
    global $pdo;
    
    $componentType = $_GET['component_type'] ?? $_POST['component_type'] ?? '';
    $includeInUse = filter_var($_GET['include_in_use'] ?? $_POST['include_in_use'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $limit = (int)($_GET['limit'] ?? 50);
    
    if (empty($componentType)) {
        send_json_response(0, 1, 400, "Component type is required");
    }
    
    try {
        // Respect the include_in_use parameter strictly
        $availableOnly = !$includeInUse;
        $components = getAvailableComponents($pdo, $componentType, $availableOnly, $limit);
        $count = getComponentCount($pdo, $componentType);
        
        send_json_response(1, 1, 200, "Available components retrieved successfully", [
            'component_type' => $componentType,
            'components' => $components,
            'counts' => $count,
            'include_in_use' => $includeInUse,
            'total_returned' => count($components)
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting available components: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get available components: " . $e->getMessage());
    }
}

/**
 * Validate server configuration
 */
function handleValidateConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to validate this configuration");
        }
        
        $validation = $serverBuilder->validateConfiguration($configUuid);
        
        send_json_response(1, 1, 200, "Configuration validation completed", [
            'config_uuid' => $configUuid,
            'validation' => $validation
        ]);
        
    } catch (Exception $e) {
        error_log("Error validating configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to validate configuration: " . $e->getMessage());
    }
}

/**
 * Get compatible components for a server configuration or component
 */
function handleGetCompatible($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    $componentType = $_GET['component_type'] ?? $_POST['component_type'] ?? '';
    $componentUuid = $_GET['component_uuid'] ?? $_POST['component_uuid'] ?? '';
    $includeInUse = filter_var($_GET['include_in_use'] ?? $_POST['include_in_use'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    try {
        $result = [];
        
        // If we have a config UUID, get compatible components for the entire configuration
        if (!empty($configUuid)) {
            $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
            if (!$config) {
                send_json_response(0, 1, 404, "Server configuration not found");
            }
            
            // Check permissions
            if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
                send_json_response(0, 1, 403, "Insufficient permissions to view this configuration");
            }
            
            // Use CompatibilityEngine if available
            if (class_exists('CompatibilityEngine')) {
                $compatibilityEngine = new CompatibilityEngine($pdo);
                $result = $compatibilityEngine->getCompatibleComponents($configUuid, $componentType);
            } else {
                // Fallback to basic component listing
                $result['components'] = getAvailableComponents($pdo, $componentType, $availableOnly);
                $result['compatibility_engine_available'] = false;
            }
        }
        // If we have component type and UUID, find compatible components for that specific component
        elseif (!empty($componentType) && !empty($componentUuid)) {
            $componentDetails = getComponentDetails($pdo, $componentType, $componentUuid);
            if (!$componentDetails) {
                send_json_response(0, 1, 404, "Component not found");
            }
            
            if (class_exists('CompatibilityEngine')) {
                $compatibilityEngine = new CompatibilityEngine($pdo);
                $result = $compatibilityEngine->getCompatibleComponentsFor($componentType, $componentUuid);
            } else {
                // Basic fallback - return similar components
                $result['components'] = getAvailableComponents($pdo, $componentType, $availableOnly);
                $result['compatibility_engine_available'] = false;
            }
        }
        // If only component type is provided, return all available components of that type
        elseif (!empty($componentType)) {
            $result['components'] = getAvailableComponents($pdo, $componentType, $availableOnly);
            $result['compatibility_engine_available'] = class_exists('CompatibilityEngine');
        } else {
            send_json_response(0, 1, 400, "Either config_uuid or component_type (with optional component_uuid) is required");
        }
        
        $result['request_parameters'] = [
            'config_uuid' => $configUuid,
            'component_type' => $componentType,
            'component_uuid' => $componentUuid,
            'include_in_use' => $includeInUse,
            'available_only' => $availableOnly
        ];
        
        send_json_response(1, 1, 200, "Compatible components retrieved successfully", $result);
        
    } catch (Exception $e) {
        error_log("Error getting compatible components: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatible components: " . $e->getMessage());
    }
}

/**
 * Helper function to get component details
 */
function getComponentDetails($pdo, $componentType, $componentUuid) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory', 
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return null;
    }
    
    $table = $tableMap[$componentType];
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
        $stmt->execute([$componentUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting component details: " . $e->getMessage());
        return null;
    }
}

/**
 * Helper function to get status text
 */
function getStatusText($statusCode) {
    $statusMap = [
        0 => 'Failed/Defective',
        1 => 'Available',
        2 => 'In Use'
    ];
    
    return $statusMap[$statusCode] ?? 'Unknown';
}

/**
 * Helper function to get suggested alternatives
 */
function getSuggestedAlternatives($pdo, $componentType, $excludeUuid, $limit = 5) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory', 
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return [];
    }
    
    $table = $tableMap[$componentType];
    
    try {
        $stmt = $pdo->prepare("
            SELECT UUID, SerialNumber, Status 
            FROM $table 
            WHERE UUID != ? AND Status = 1 
            ORDER BY SerialNumber 
            LIMIT ?
        ");
        $stmt->execute([$excludeUuid, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting suggested alternatives: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get available components
 */
function getAvailableComponents($pdo, $componentType, $availableOnly = true, $limit = 50) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return [];
    }
    
    $table = $tableMap[$componentType];
    $whereClause = $availableOnly ? "WHERE Status = 1" : "WHERE Status IN (1, 2)";
    
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM $table 
            $whereClause 
            ORDER BY Status ASC, SerialNumber ASC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting available components for $componentType: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to get component count
 */
function getComponentCount($pdo, $componentType) {
    $tableMap = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    if (!isset($tableMap[$componentType])) {
        return ['total' => 0, 'available' => 0, 'in_use' => 0, 'failed' => 0];
    }
    
    $table = $tableMap[$componentType];
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN Status = 1 THEN 1 ELSE 0 END) as available,
                SUM(CASE WHEN Status = 2 THEN 1 ELSE 0 END) as in_use,
                SUM(CASE WHEN Status = 0 THEN 1 ELSE 0 END) as failed
            FROM $table
        ");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting component count for $componentType: " . $e->getMessage());
        return ['total' => 0, 'available' => 0, 'in_use' => 0, 'failed' => 0];
    }
}

/**
 * Helper function to parse motherboard specifications from Notes field
 */
function parseMotherboardSpecs($motherboardDetails) {
    $specs = [
        'cpu_sockets' => 1,
        'memory_slots' => 4,
        'storage_slots' => [
            'sata_ports' => 4,
            'm2_slots' => 1,
            'u2_slots' => 0
        ],
        'pcie_slots' => [
            'x16_slots' => 1,
            'x8_slots' => 0,
            'x4_slots' => 0,
            'x1_slots' => 0
        ],
        'socket_type' => 'Unknown',
        'memory_type' => 'DDR4'
    ];
    
    try {
        // Try to find matching motherboard JSON data
        $jsonFiles = [
            __DIR__ . '/../../All JSON/motherboad jsons/motherboard level 3.json'
        ];
        
        $serialNumber = $motherboardDetails['SerialNumber'];
        $notes = $motherboardDetails['Notes'] ?? '';
        
        foreach ($jsonFiles as $jsonFile) {
            if (file_exists($jsonFile)) {
                $jsonData = json_decode(file_get_contents($jsonFile), true);
                if ($jsonData) {
                    $matchedSpecs = findMotherboardInJSON($jsonData, $serialNumber, $notes);
                    if ($matchedSpecs) {
                        $specs = array_merge($specs, $matchedSpecs);
                        break;
                    }
                }
            }
        }
        
        // Fallback: Parse from Notes field if JSON not found
        if ($notes) {
            $parsedFromNotes = parseSpecsFromNotes($notes);
            $specs = array_merge($specs, $parsedFromNotes);
        }
        
    } catch (Exception $e) {
        error_log("Error parsing motherboard specs: " . $e->getMessage());
    }
    
    return $specs;
}

/**
 * Find motherboard specifications in JSON data
 */
function findMotherboardInJSON($jsonData, $serialNumber, $notes) {
    foreach ($jsonData as $brand) {
        if (isset($brand['models'])) {
            foreach ($brand['models'] as $model) {
                // Check if this model matches our motherboard
                if (stripos($notes, $model['model']) !== false) {
                    return extractSpecsFromJSON($model);
                }
            }
        }
        
        // Check family level models
        if (isset($brand['family']) && is_array($brand['family'])) {
            foreach ($brand['family'] as $family) {
                if (isset($family['models'])) {
                    foreach ($family['models'] as $model) {
                        if (stripos($notes, $model['model']) !== false) {
                            return extractSpecsFromJSON($model);
                        }
                    }
                }
            }
        }
        
        // Check series level
        if (isset($brand['series'])) {
            foreach ($brand as $key => $value) {
                if ($key === 'models' && is_array($value)) {
                    foreach ($value as $model) {
                        if (stripos($notes, $model['model']) !== false) {
                            return extractSpecsFromJSON($model);
                        }
                    }
                }
            }
        }
    }
    
    return null;
}

/**
 * Extract specifications from JSON model data
 */
function extractSpecsFromJSON($modelData) {
    $specs = [];
    
    // CPU socket information
    if (isset($modelData['socket'])) {
        $specs['cpu_sockets'] = $modelData['socket']['count'] ?? 1;
        $specs['socket_type'] = $modelData['socket']['type'] ?? 'Unknown';
    }
    
    // Memory information
    if (isset($modelData['memory'])) {
        $specs['memory_slots'] = $modelData['memory']['slots'] ?? 4;
        $specs['memory_type'] = $modelData['memory']['type'] ?? 'DDR4';
        $specs['memory_max_capacity'] = $modelData['memory']['max_capacity_TB'] ?? 1;
        $specs['memory_channels'] = $modelData['memory']['channels'] ?? 2;
    }
    
    // Storage information
    $storageSlots = [];
    if (isset($modelData['storage'])) {
        if (isset($modelData['storage']['sata']['ports'])) {
            $storageSlots['sata_ports'] = $modelData['storage']['sata']['ports'];
        }
        if (isset($modelData['storage']['nvme']['m2_slots'])) {
            $m2Count = 0;
            foreach ($modelData['storage']['nvme']['m2_slots'] as $m2Slot) {
                $m2Count += $m2Slot['count'] ?? 0;
            }
            $storageSlots['m2_slots'] = $m2Count;
        }
        if (isset($modelData['storage']['nvme']['u2_slots']['count'])) {
            $storageSlots['u2_slots'] = $modelData['storage']['nvme']['u2_slots']['count'];
        }
    }
    $specs['storage_slots'] = $storageSlots;
    
    // PCIe slots information
    $pcieSlots = [];
    if (isset($modelData['expansion_slots']['pcie_slots'])) {
        foreach ($modelData['expansion_slots']['pcie_slots'] as $slot) {
            $slotType = $slot['type'] ?? '';
            $count = $slot['count'] ?? 0;
            
            if (strpos($slotType, 'x16') !== false) {
                $pcieSlots['x16_slots'] = ($pcieSlots['x16_slots'] ?? 0) + $count;
            } elseif (strpos($slotType, 'x8') !== false) {
                $pcieSlots['x8_slots'] = ($pcieSlots['x8_slots'] ?? 0) + $count;
            } elseif (strpos($slotType, 'x4') !== false) {
                $pcieSlots['x4_slots'] = ($pcieSlots['x4_slots'] ?? 0) + $count;
            } elseif (strpos($slotType, 'x1') !== false) {
                $pcieSlots['x1_slots'] = ($pcieSlots['x1_slots'] ?? 0) + $count;
            }
        }
    }
    $specs['pcie_slots'] = $pcieSlots;
    
    return $specs;
}

/**
 * Parse basic specs from Notes field as fallback
 */
function parseSpecsFromNotes($notes) {
    $specs = [];
    
    // Try to extract basic information from notes
    if (preg_match('/(\d+)[\s]*socket/i', $notes, $matches)) {
        $specs['cpu_sockets'] = (int)$matches[1];
    }
    
    if (preg_match('/(\d+)[\s]*dimm/i', $notes, $matches)) {
        $specs['memory_slots'] = (int)$matches[1];
    }
    
    if (preg_match('/DDR(\d)/i', $notes, $matches)) {
        $specs['memory_type'] = 'DDR' . $matches[1];
    }
    
    return $specs;
}

/**
 * Calculate configuration progress based on components added
 */
function calculateConfigurationProgress($summary) {
    $stepDescriptions = [
        1 => 'Motherboard Selection',
        2 => 'CPU Selection',
        3 => 'Memory Selection',
        4 => 'Storage Selection',
        5 => 'Network/Expansion Cards',
        6 => 'Review & Finalization'
    ];
    
    $completedSteps = 0;
    $currentStep = 2; // Start with CPU after motherboard
    
    // Step 1: Motherboard (always completed if we have a summary)
    if (isset($summary['components']['motherboard'])) {
        $completedSteps = 1;
    }
    
    // Step 2: CPU
    if (isset($summary['components']['cpu']) && !empty($summary['components']['cpu'])) {
        $completedSteps = 2;
        $currentStep = 3;
    }
    
    // Step 3: Memory/RAM
    if (isset($summary['components']['ram']) && !empty($summary['components']['ram'])) {
        $completedSteps = 3;
        $currentStep = 4;
    }
    
    // Step 4: Storage
    if (isset($summary['components']['storage']) && !empty($summary['components']['storage'])) {
        $completedSteps = 4;
        $currentStep = 5;
    }
    
    // Step 5: Network/Expansion (optional)
    if (isset($summary['components']['nic']) && !empty($summary['components']['nic'])) {
        $completedSteps = 5;
        $currentStep = 6;
    }
    
    // Check if ready for finalization (minimum requirements met)
    $readyForFinalization = $completedSteps >= 4; // Motherboard, CPU, RAM, Storage
    
    if ($readyForFinalization && $currentStep < 6) {
        $currentStep = 6;
    }
    
    return [
        'total_steps' => 6,
        'completed_steps' => $completedSteps,
        'current_step' => $currentStep,
        'current_step_name' => getCurrentStepName($currentStep),
        'step_descriptions' => array_map(function($step, $desc) use ($completedSteps) {
            return $desc . ($step <= $completedSteps ? ' (Completed)' : '');
        }, array_keys($stepDescriptions), $stepDescriptions),
        'ready_for_finalization' => $readyForFinalization,
        'progress_percentage' => round(($completedSteps / 6) * 100, 1)
    ];
}

/**
 * Get current step name for UI
 */
function getCurrentStepName($stepNumber) {
    $stepNames = [
        1 => 'motherboard_selection',
        2 => 'cpu_selection', 
        3 => 'memory_selection',
        4 => 'storage_selection',
        5 => 'expansion_selection',
        6 => 'review_finalization'
    ];
    
    return $stepNames[$stepNumber] ?? 'unknown_step';
}

/**
 * Get next recommendations based on current configuration
 */
function getNextRecommendations($summary, $componentLimits) {
    $recommendations = [];
    
    // Check what's missing and recommend next steps
    if (!isset($summary['components']['cpu']) || empty($summary['components']['cpu'])) {
        $maxCpus = $componentLimits['cpu_sockets']['remaining'] ?? 1;
        $recommendations[] = [
            'component_type' => 'cpu',
            'priority' => 'high',
            'message' => "Add CPU(s) - Up to $maxCpus CPU(s) can be installed",
            'max_quantity' => $maxCpus,
            'required' => true
        ];
    } else {
        $remaining = $componentLimits['cpu_sockets']['remaining'] ?? 0;
        if ($remaining > 0) {
            $recommendations[] = [
                'component_type' => 'cpu',
                'priority' => 'medium',
                'message' => "Add additional CPU - $remaining socket(s) available",
                'max_quantity' => $remaining,
                'required' => false
            ];
        }
    }
    
    if (!isset($summary['components']['ram']) || empty($summary['components']['ram'])) {
        $maxRam = $componentLimits['memory_slots']['remaining'] ?? 4;
        $recommendations[] = [
            'component_type' => 'ram',
            'priority' => 'high',
            'message' => "Add memory modules - Up to $maxRam slot(s) available",
            'max_quantity' => $maxRam,
            'required' => true
        ];
    } else {
        $remaining = $componentLimits['memory_slots']['remaining'] ?? 0;
        if ($remaining > 0) {
            $recommendations[] = [
                'component_type' => 'ram',
                'priority' => 'medium',
                'message' => "Add additional memory - $remaining slot(s) available",
                'max_quantity' => $remaining,
                'required' => false
            ];
        }
    }
    
    if (!isset($summary['components']['storage']) || empty($summary['components']['storage'])) {
        $maxStorage = $componentLimits['storage_slots']['remaining'] ?? 1;
        $recommendations[] = [
            'component_type' => 'storage',
            'priority' => 'high',
            'message' => "Add storage device - Up to $maxStorage connection(s) available",
            'max_quantity' => $maxStorage,
            'required' => true
        ];
    } else {
        $remaining = $componentLimits['storage_slots']['remaining'] ?? 0;
        if ($remaining > 0) {
            $recommendations[] = [
                'component_type' => 'storage',
                'priority' => 'low',
                'message' => "Add additional storage - $remaining connection(s) available",
                'max_quantity' => $remaining,
                'required' => false
            ];
        }
    }
    
    // Optional components
    if (!isset($summary['components']['nic']) || empty($summary['components']['nic'])) {
        $recommendations[] = [
            'component_type' => 'nic',
            'priority' => 'low',
            'message' => 'Add network interface cards (optional)',
            'max_quantity' => 10, // Reasonable default
            'required' => false
        ];
    }
    
    return $recommendations;
}

?>