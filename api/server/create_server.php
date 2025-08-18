<?php
/**
 * Infrastructure Management System - Server Creation Endpoint
 * File: api/server/create_server.php
 * 
 * Dedicated endpoint for step-by-step server creation
 * Note: User authentication and permission checks are handled in the main api.php
 * This file is included after those checks pass
 */

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'initialize':
            handleInitializeServerCreation();
            break;
            
        case 'step_add_component':
            handleStepAddComponent();
            break;
            
        case 'step_remove_component':
            handleStepRemoveComponent();
            break;
            
        case 'get_next_options':
            handleGetNextOptions();
            break;
            
        case 'validate_current':
            handleValidateCurrent();
            break;
            
        case 'finalize':
            handleFinalizeServer();
            break;
            
        case 'save_draft':
            handleSaveDraft();
            break;
            
        case 'load_draft':
            handleLoadDraft();
            break;
            
        case 'get_server_progress':
            handleGetServerProgress();
            break;
            
        case 'reset_configuration':
            handleResetConfiguration();
            break;
            
        case 'list_drafts':
            handleListDrafts();
            break;
            
        case 'delete_draft':
            handleDeleteDraft();
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid server creation action: $action");
    }
} catch (Exception $e) {
    error_log("Server creation error: " . $e->getMessage());
    send_json_response(0, 1, 500, "Server creation failed: " . $e->getMessage());
}

/**
 * Initialize new server creation session
 */
function handleInitializeServerCreation() {
    global $pdo, $user;
    
    $serverName = trim($_POST['server_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $startWith = $_POST['start_with'] ?? 'any'; // 'cpu', 'motherboard', or 'any'
    
    if (empty($serverName)) {
        send_json_response(0, 1, 400, "Server name is required");
    }
    
    try {
        // Create new server configuration record
        $configUuid = generateUUID();
        $stmt = $pdo->prepare("
            INSERT INTO server_configurations (config_uuid, server_name, description, created_by, created_at, configuration_status) 
            VALUES (?, ?, ?, ?, NOW(), 0)
        ");
        $stmt->execute([$configUuid, $serverName, $description, $user['id']]);
        
        // Get initial component options based on starting preference
        $initialOptions = [];
        
        if ($startWith === 'any' || $startWith === 'cpu') {
            $initialOptions['cpu'] = getAvailableComponents($pdo, 'cpu');
        }
        
        if ($startWith === 'any' || $startWith === 'motherboard') {
            $initialOptions['motherboard'] = getAvailableComponents($pdo, 'motherboard');
        }
        
        if ($startWith === 'any') {
            $initialOptions['ram'] = getAvailableComponents($pdo, 'ram');
            $initialOptions['storage'] = getAvailableComponents($pdo, 'storage');
            $initialOptions['nic'] = getAvailableComponents($pdo, 'nic');
            $initialOptions['caddy'] = getAvailableComponents($pdo, 'caddy');
        }
        
        // Log the initialization
        logServerBuildAction($pdo, $configUuid, 'initialize', null, null, [
            'server_name' => $serverName,
            'start_with' => $startWith
        ], $user['id']);
        
        send_json_response(1, 1, 200, "Server creation initialized", [
            'config_uuid' => $configUuid,
            'server_name' => $serverName,
            'starting_options' => $initialOptions,
            'workflow_step' => 1,
            'next_recommended' => $startWith === 'cpu' ? 'motherboard' : ($startWith === 'motherboard' ? 'cpu' : 'any'),
            'progress' => [
                'total_steps' => 6, // CPU, MB, RAM, Storage, NIC, Validation
                'completed_steps' => 0,
                'current_step' => 'component_selection'
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error initializing server creation: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to initialize server creation: " . $e->getMessage());
    }
}

/**
 * Add component in step-by-step process
 */
function handleStepAddComponent() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 1);
    $slotPosition = $_POST['slot_position'] ?? null;
    $override = filter_var($_POST['override'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($configUuid) || empty($componentType) || empty($componentUuid)) {
        send_json_response(0, 1, 400, "Config UUID, component type, and component UUID are required");
    }
    
    try {
        // Verify configuration exists and user has access
        $stmt = $pdo->prepare("SELECT id, server_name, configuration_data FROM server_configurations WHERE config_uuid = ? AND created_by = ? AND configuration_status < 3");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found, access denied, or already finalized");
        }
        
        // Get current configuration data
        $configData = json_decode($config['configuration_data'] ?? '{}', true);
        
        // Verify component exists and get details
        $componentDetails = getComponentDetails($pdo, $componentType, $componentUuid);
        if (!$componentDetails) {
            send_json_response(0, 1, 404, "Component not found");
        }
        
        // Check if component is available
        if ($componentDetails['Status'] != '1' && !$override) {
            send_json_response(0, 1, 409, "Component is not available (Status: " . getStatusText($componentDetails['Status']) . ")", [
                'component_status' => $componentDetails['Status'],
                'component_status_text' => getStatusText($componentDetails['Status']),
                'can_override' => true,
                'component_details' => $componentDetails
            ]);
        }
        
        // Check for compatibility issues
        $compatibilityIssues = checkComponentCompatibility($pdo, $configData, $componentType, $componentDetails);
        
        if (!empty($compatibilityIssues) && !$override) {
            send_json_response(0, 1, 409, "Compatibility issues found", [
                'compatibility_issues' => $compatibilityIssues,
                'can_override' => true,
                'component_details' => $componentDetails
            ]);
        }
        
        // Add component to configuration
        if (!isset($configData['components'])) {
            $configData['components'] = [];
        }
        
        // Handle components that allow multiple instances vs single instance
        $multiInstanceComponents = ['ram', 'storage', 'nic'];
        
        if (in_array($componentType, $multiInstanceComponents)) {
            // For multi-instance components, create array if doesn't exist
            if (!isset($configData['components'][$componentType])) {
                $configData['components'][$componentType] = [];
            }
            
            // Add new instance
            $instanceKey = $slotPosition ?: (count($configData['components'][$componentType]) + 1);
            $configData['components'][$componentType][$instanceKey] = [
                'uuid' => $componentUuid,
                'quantity' => $quantity,
                'slot_position' => $slotPosition,
                'added_at' => date('Y-m-d H:i:s'),
                'details' => $componentDetails
            ];
        } else {
            // For single-instance components (CPU, Motherboard)
            if (isset($configData['components'][$componentType]) && !$override) {
                send_json_response(0, 1, 409, "Component type $componentType already exists in configuration", [
                    'existing_component' => $configData['components'][$componentType],
                    'can_override' => true
                ]);
            }
            
            $configData['components'][$componentType] = [
                'uuid' => $componentUuid,
                'quantity' => $quantity,
                'slot_position' => $slotPosition,
                'added_at' => date('Y-m-d H:i:s'),
                'details' => $componentDetails
            ];
        }
        
        // Update configuration in database
        $stmt = $pdo->prepare("
            UPDATE server_configurations 
            SET configuration_data = ?, updated_by = ?, updated_at = NOW() 
            WHERE config_uuid = ?
        ");
        $stmt->execute([json_encode($configData), $user['id'], $configUuid]);
        
        // Log the action
        logServerBuildAction($pdo, $configUuid, 'add_component', $componentType, $componentUuid, [
            'quantity' => $quantity,
            'slot_position' => $slotPosition,
            'override_used' => $override,
            'compatibility_issues' => $compatibilityIssues
        ], $user['id']);
        
        // Determine next recommended component
        $nextStep = determineNextStep($configData);
        $nextOptions = $nextStep ? getAvailableComponents($pdo, $nextStep) : [];
        
        // Calculate progress
        $progress = calculateConfigurationProgress($configData);
        
        send_json_response(1, 1, 200, "Component added successfully", [
            'component_added' => [
                'type' => $componentType,
                'uuid' => $componentUuid,
                'quantity' => $quantity,
                'slot_position' => $slotPosition,
                'details' => $componentDetails
            ],
            'current_configuration' => $configData,
            'configuration_summary' => getConfigurationSummary($configData),
            'next_step' => $nextStep,
            'next_options' => $nextOptions,
            'progress' => $progress,
            'compatibility_override_used' => $override,
            'compatibility_issues_ignored' => $override ? $compatibilityIssues : []
        ]);
        
    } catch (Exception $e) {
        error_log("Error adding component in step process: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to add component: " . $e->getMessage());
    }
}

/**
 * Remove component in step-by-step process
 */
function handleStepRemoveComponent() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $componentType = $_POST['component_type'] ?? '';
    $instanceKey = $_POST['instance_key'] ?? null; // For multi-instance components
    
    if (empty($configUuid) || empty($componentType)) {
        send_json_response(0, 1, 400, "Config UUID and component type are required");
    }
    
    try {
        // Verify configuration exists and user has access
        $stmt = $pdo->prepare("SELECT id, server_name, configuration_data FROM server_configurations WHERE config_uuid = ? AND created_by = ? AND configuration_status < 3");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found, access denied, or already finalized");
        }
        
        // Get current configuration data
        $configData = json_decode($config['configuration_data'] ?? '{}', true);
        
        if (!isset($configData['components'][$componentType])) {
            send_json_response(0, 1, 404, "Component type not found in configuration");
        }
        
        // Handle removal based on component type
        $removedComponent = null;
        $multiInstanceComponents = ['ram', 'storage', 'nic'];
        
        if (in_array($componentType, $multiInstanceComponents)) {
            // Multi-instance component
            if ($instanceKey && isset($configData['components'][$componentType][$instanceKey])) {
                $removedComponent = $configData['components'][$componentType][$instanceKey];
                unset($configData['components'][$componentType][$instanceKey]);
                
                // If no more instances, remove the component type entirely
                if (empty($configData['components'][$componentType])) {
                    unset($configData['components'][$componentType]);
                }
            } else {
                send_json_response(0, 1, 400, "Instance key required for multi-instance component type");
            }
        } else {
            // Single-instance component
            $removedComponent = $configData['components'][$componentType];
            unset($configData['components'][$componentType]);
        }
        
        if (!$removedComponent) {
            send_json_response(0, 1, 404, "Component instance not found");
        }
        
        // Update configuration in database
        $stmt = $pdo->prepare("
            UPDATE server_configurations 
            SET configuration_data = ?, updated_by = ?, updated_at = NOW() 
            WHERE config_uuid = ?
        ");
        $stmt->execute([json_encode($configData), $user['id'], $configUuid]);
        
        // Log the action
        logServerBuildAction($pdo, $configUuid, 'remove_component', $componentType, $removedComponent['uuid'] ?? null, [
            'instance_key' => $instanceKey,
            'removed_details' => $removedComponent
        ], $user['id']);
        
        // Recalculate next step and options
        $nextStep = determineNextStep($configData);
        $allOptions = getAllAvailableComponents($pdo);
        
        // Calculate progress
        $progress = calculateConfigurationProgress($configData);
        
        send_json_response(1, 1, 200, "Component removed successfully", [
            'component_removed' => [
                'type' => $componentType,
                'instance_key' => $instanceKey,
                'details' => $removedComponent
            ],
            'current_configuration' => $configData,
            'configuration_summary' => getConfigurationSummary($configData),
            'next_step' => $nextStep,
            'all_options' => $allOptions,
            'progress' => $progress
        ]);
        
    } catch (Exception $e) {
        error_log("Error removing component in step process: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to remove component: " . $e->getMessage());
    }
}

/**
 * Get next component options
 */
function handleGetNextOptions() {
    global $pdo, $user;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    $requestedType = $_GET['requested_type'] ?? $_POST['requested_type'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Verify configuration exists and user has access
        $stmt = $pdo->prepare("SELECT id, server_name, configuration_data FROM server_configurations WHERE config_uuid = ? AND created_by = ?");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found or access denied");
        }
        
        // Get current configuration data
        $configData = json_decode($config['configuration_data'] ?? '{}', true);
        
        if ($requestedType) {
            // Get options for specific component type
            $options = getCompatibleComponents($pdo, $configData, $requestedType);
            $response = [
                'component_type' => $requestedType,
                'options' => $options,
                'count' => count($options)
            ];
        } else {
            // Get all available options
            $allOptions = getAllCompatibleComponents($pdo, $configData);
            $response = [
                'all_options' => $allOptions,
                'recommended_next' => determineNextStep($configData)
            ];
        }
        
        $response['current_configuration'] = $configData;
        $response['configuration_summary'] = getConfigurationSummary($configData);
        $response['progress'] = calculateConfigurationProgress($configData);
        
        send_json_response(1, 1, 200, "Next options retrieved", $response);
        
    } catch (Exception $e) {
        error_log("Error getting next options: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get next options: " . $e->getMessage());
    }
}

/**
 * Validate current configuration
 */
function handleValidateCurrent() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? $_GET['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Verify configuration exists and user has access
        $stmt = $pdo->prepare("SELECT id, server_name, configuration_data FROM server_configurations WHERE config_uuid = ? AND created_by = ?");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found or access denied");
        }
        
        // Get current configuration data
        $configData = json_decode($config['configuration_data'] ?? '{}', true);
        
        // Perform comprehensive validation
        $validationResults = validateServerConfiguration($pdo, $configData);
        
        // Log validation action
        logServerBuildAction($pdo, $configUuid, 'validate', null, null, [
            'validation_results' => $validationResults
        ], $user['id']);
        
        send_json_response(1, 1, 200, "Configuration validation completed", [
            'configuration' => $configData,
            'configuration_summary' => getConfigurationSummary($configData),
            'validation' => $validationResults,
            'is_valid' => $validationResults['is_complete'] && empty($validationResults['critical_errors']),
            'can_finalize' => $validationResults['is_complete'] && empty($validationResults['critical_errors'])
        ]);
        
    } catch (Exception $e) {
        error_log("Error validating configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to validate configuration: " . $e->getMessage());
    }
}

/**
 * Finalize server configuration and create actual server record
 */
function handleFinalizeServer() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $serverName = trim($_POST['server_name'] ?? '');
    $serverLocation = trim($_POST['server_location'] ?? '');
    $serverRackPosition = trim($_POST['server_rack_position'] ?? '');
    $serverNotes = trim($_POST['server_notes'] ?? '');
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Verify configuration exists and user has access
        $stmt = $pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ? AND created_by = ? AND configuration_status = 0");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found, access denied, or already finalized");
        }
        
        // Get current configuration data
        $configData = json_decode($config['configuration_data'] ?? '{}', true);
        
        // Validate configuration is complete
        $validationResults = validateServerConfiguration($pdo, $configData);
        if (!$validationResults['is_complete'] || !empty($validationResults['critical_errors'])) {
            send_json_response(0, 1, 400, "Configuration validation failed - cannot finalize", [
                'validation_errors' => $validationResults
            ]);
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        try {
            // Create server record
            $serverUuid = generateUUID();
            $finalServerName = !empty($serverName) ? $serverName : $config['server_name'];
            $serialNumber = 'SRV-' . date('YmdHis') . '-' . substr($serverUuid, 0, 8);
            
            // Insert server record
            $stmt = $pdo->prepare("
                INSERT INTO servers (
                    UUID, SerialNumber, Status, ServerUUID, Location, RackPosition, 
                    Notes, Flag, configuration_uuid, created_at
                ) VALUES (?, ?, '1', ?, ?, ?, ?, 'Active', ?, NOW())
            ");
            $stmt->execute([
                $serverUuid,
                $serialNumber,
                $serverUuid,
                $serverLocation ?: 'Data Center',
                $serverRackPosition ?: 'TBD',
                $serverNotes ?: 'Server built from configuration: ' . $config['server_name'],
                $configUuid
            ]);
            
            $serverId = $pdo->lastInsertId();
            
            // Update all components to "In Use" status and assign to server
            $componentsUpdated = 0;
            if (isset($configData['components'])) {
                foreach ($configData['components'] as $componentType => $componentInfo) {
                    if (isset($componentInfo['uuid'])) {
                        // Single instance component
                        $stmt = $pdo->prepare("UPDATE $componentType SET Status = '2', ServerUUID = ? WHERE UUID = ?");
                        $stmt->execute([$serverUuid, $componentInfo['uuid']]);
                        $componentsUpdated++;
                    } else {
                        // Multi-instance component
                        foreach ($componentInfo as $instance) {
                            if (isset($instance['uuid'])) {
                                $stmt = $pdo->prepare("UPDATE $componentType SET Status = '2', ServerUUID = ? WHERE UUID = ?");
                                $stmt->execute([$serverUuid, $instance['uuid']]);
                                $componentsUpdated++;
                            }
                        }
                    }
                }
            }
            
            // Mark configuration as finalized
            $stmt = $pdo->prepare("
                UPDATE server_configurations 
                SET configuration_status = 3, server_uuid = ?, updated_by = ?
                WHERE config_uuid = ?
            ");
            $stmt->execute([$serverUuid, $user['id'], $configUuid]);
            
            // Log finalization
            logServerBuildAction($pdo, $configUuid, 'finalize', null, null, [
                'server_uuid' => $serverUuid,
                'server_name' => $finalServerName,
                'components_updated' => $componentsUpdated
            ], $user['id']);
            
            $pdo->commit();
            
            send_json_response(1, 1, 200, "Server finalized successfully", [
                'server_uuid' => $serverUuid,
                'server_id' => $serverId,
                'server_name' => $finalServerName,
                'serial_number' => $serialNumber,
                'components_assigned' => $componentsUpdated,
                'configuration_uuid' => $configUuid,
                'location' => $serverLocation,
                'rack_position' => $serverRackPosition
            ]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        error_log("Error finalizing server: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to finalize server: " . $e->getMessage());
    }
}

/**
 * Save configuration as draft
 */
function handleSaveDraft() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $draftName = trim($_POST['draft_name'] ?? '');
    $draftDescription = trim($_POST['draft_description'] ?? '');
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        $updateFields = [];
        $updateValues = [];
        
        if (!empty($draftName)) {
            $updateFields[] = "name = ?";
            $updateValues[] = $draftName;
        }
        
        if (!empty($draftDescription)) {
            $updateFields[] = "description = ?";
            $updateValues[] = $draftDescription;
        }
        
        if (!empty($updateFields)) {
            $updateFields[] = "updated_at = NOW()";
            $updateValues[] = $configUuid;
            $updateValues[] = $user['id'];
            
            $sql = "UPDATE server_configurations SET " . implode(', ', $updateFields) . " WHERE config_uuid = ? AND created_by = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateValues);
        }
        
        // Log save action
        logServerBuildAction($pdo, $configUuid, 'save_draft', null, null, [
            'draft_name' => $draftName,
            'draft_description' => $draftDescription
        ], $user['id']);
        
        send_json_response(1, 1, 200, "Draft saved successfully", [
            'config_uuid' => $configUuid,
            'draft_name' => $draftName,
            'draft_description' => $draftDescription
        ]);
        
    } catch (Exception $e) {
        error_log("Error saving draft: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to save draft: " . $e->getMessage());
    }
}

/**
 * Load draft configuration
 */
function handleLoadDraft() {
    global $pdo, $user;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Get configuration
        $stmt = $pdo->prepare("SELECT * FROM server_configurations WHERE config_uuid = ? AND created_by = ? AND configuration_status < 3");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            send_json_response(0, 1, 404, "Draft configuration not found or access denied");
        }
        
        // Get current configuration data
        $configData = json_decode($config['configuration_data'] ?? '{}', true);
        
        send_json_response(1, 1, 200, "Draft loaded successfully", [
            'config_uuid' => $configUuid,
            'name' => $config['server_name'],
            'description' => $config['description'],
            'configuration' => $configData,
            'configuration_summary' => getConfigurationSummary($configData),
            'created_at' => $config['created_at'],
            'updated_at' => $config['updated_at'],
            'progress' => calculateConfigurationProgress($configData),
            'next_step' => determineNextStep($configData)
        ]);
        
    } catch (Exception $e) {
        error_log("Error loading draft: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to load draft: " . $e->getMessage());
    }
}

/**
 * Get server progress
 */
function handleGetServerProgress() {
    global $pdo, $user;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Get configuration
        $stmt = $pdo->prepare("SELECT server_name, configuration_data, status FROM server_configurations WHERE config_uuid = ? AND created_by = ?");
        $stmt->execute([$configUuid, $user['id']]);
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found or access denied");
        }
        
        // Get current configuration data
        $configData = json_decode($config['configuration_data'] ?? '{}', true);
        
        $progress = calculateConfigurationProgress($configData);
        
        // Map status code to name
        $statusMap = [0 => 'draft', 1 => 'validated', 2 => 'built', 3 => 'deployed'];
        $statusName = $statusMap[$config['configuration_status']] ?? 'unknown';
        
        send_json_response(1, 1, 200, "Server progress retrieved", [
            'config_uuid' => $configUuid,
            'name' => $config['server_name'],
            'status' => $statusName,
            'progress' => $progress,
            'next_step' => determineNextStep($configData),
            'configuration_summary' => getConfigurationSummary($configData)
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting server progress: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get server progress: " . $e->getMessage());
    }
}

/**
 * Reset configuration (clear all components)
 */
function handleResetConfiguration() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Reset configuration data
        $configData = ['components' => []];
        
        $stmt = $pdo->prepare("
            UPDATE server_configurations 
            SET configuration_data = ?, updated_by = ?, updated_at = NOW() 
            WHERE config_uuid = ? AND created_by = ? AND configuration_status = 0
        ");
        $result = $stmt->execute([json_encode($configData), $user['id'], $configUuid, $user['id']]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Log reset action
            logServerBuildAction($pdo, $configUuid, 'reset', null, null, [], $user['id']);
            
            send_json_response(1, 1, 200, "Configuration reset successfully", [
                'config_uuid' => $configUuid,
                'configuration' => $configData
            ]);
        } else {
            send_json_response(0, 1, 404, "Configuration not found, access denied, or already finalized");
        }
        
    } catch (Exception $e) {
        error_log("Error resetting configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to reset configuration: " . $e->getMessage());
    }
}

/**
 * List user's draft configurations
 */
function handleListDrafts() {
    global $pdo, $user;
    
    $limit = (int)($_GET['limit'] ?? 20);
    $offset = (int)($_GET['offset'] ?? 0);
    $status = $_GET['status'] ?? 'draft'; // draft, finalized, all
    
    try {
        $whereClause = "WHERE created_by = ?";
        $params = [$user['id']];
        
        if ($status !== 'all') {
            $whereClause .= " AND configuration_status = ?";
            // Map status names to numbers: draft=0, validated=1, built=2, deployed=3
            $statusCode = $status === 'draft' ? 0 : ($status === 'finalized' ? 3 : $status);
            $params[] = $statusCode;
        }
        
        // Get configurations
        $stmt = $pdo->prepare("
            SELECT config_uuid, server_name, description, configuration_status, created_at, updated_at, server_uuid, configuration_data
            FROM server_configurations 
            $whereClause
            ORDER BY updated_at DESC 
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $configurations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM server_configurations $whereClause");
        $stmt->execute(array_slice($params, 0, -2)); // Remove limit and offset
        $totalCount = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Add progress info to each configuration
        foreach ($configurations as &$config) {
            $configData = json_decode($config['configuration_data'] ?? '{}', true);
            $config['progress'] = calculateConfigurationProgress($configData);
            $config['component_count'] = isset($configData['components']) ? count($configData['components']) : 0;
            
            // Map status code to name
            $statusMap = [0 => 'draft', 1 => 'validated', 2 => 'built', 3 => 'deployed'];
            $config['status'] = $statusMap[$config['configuration_status']] ?? 'unknown';
            
            unset($config['configuration_data']); // Remove full data from list view
        }
        
        send_json_response(1, 1, 200, "Draft configurations retrieved", [
            'configurations' => $configurations,
            'total_count' => (int)$totalCount,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $totalCount
        ]);
        
    } catch (Exception $e) {
        error_log("Error listing drafts: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to list drafts: " . $e->getMessage());
    }
}

/**
 * Delete draft configuration
 */
function handleDeleteDraft() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Delete build history
        $stmt = $pdo->prepare("DELETE FROM server_build_history WHERE config_uuid = ?");
        $stmt->execute([$configUuid]);
        
        // Delete configuration
        $stmt = $pdo->prepare("
            DELETE FROM server_configurations 
            WHERE config_uuid = ? AND created_by = ? AND configuration_status = 0
        ");
        $result = $stmt->execute([$configUuid, $user['id']]);
        
        if ($result && $stmt->rowCount() > 0) {
            $pdo->commit();
            send_json_response(1, 1, 200, "Draft configuration deleted successfully", [
                'config_uuid' => $configUuid
            ]);
        } else {
            $pdo->rollBack();
            send_json_response(0, 1, 404, "Draft configuration not found, access denied, or already finalized");
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error deleting draft: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to delete draft: " . $e->getMessage());
    }
}

// ===== HELPER FUNCTIONS =====

/**
 * Get available components of a specific type
 */
function getAvailableComponents($pdo, $type) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM $type WHERE Status = '1' ORDER BY SerialNumber");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting available $type components: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all available components for all types
 */
function getAllAvailableComponents($pdo) {
    $componentTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
    $allComponents = [];
    
    foreach ($componentTypes as $type) {
        $allComponents[$type] = getAvailableComponents($pdo, $type);
    }
    
    return $allComponents;
}

/**
 * Get compatible components for a specific type based on current configuration
 */
function getCompatibleComponents($pdo, $configData, $type) {
    // For now, return all available components
    // This can be enhanced with actual compatibility logic
    return getAvailableComponents($pdo, $type);
}

/**
 * Get all compatible components based on current configuration
 */
function getAllCompatibleComponents($pdo, $configData) {
    $componentTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
    $allComponents = [];
    
    foreach ($componentTypes as $type) {
        $allComponents[$type] = getCompatibleComponents($pdo, $configData, $type);
    }
    
    return $allComponents;
}

/**
 * Get component details by type and UUID
 */
function getComponentDetails($pdo, $type, $uuid) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM $type WHERE UUID = ?");
        $stmt->execute([$uuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting $type component details: " . $e->getMessage());
        return null;
    }
}

/**
 * Check component compatibility (enhanced implementation)
 */
function checkComponentCompatibility($pdo, $configData, $newComponentType, $newComponentDetails) {
    $issues = [];
    
    // Basic compatibility checks
    if (!isset($configData['components'])) {
        return $issues; // No existing components, no conflicts
    }
    
    $components = $configData['components'];
    
    // Check for duplicate component types (where only one is allowed)
    $singleComponentTypes = ['cpu', 'motherboard'];
    if (in_array($newComponentType, $singleComponentTypes) && isset($components[$newComponentType])) {
        $issues[] = [
            'type' => 'duplicate',
            'severity' => 'error',
            'message' => "Only one $newComponentType component is allowed per server"
        ];
    }
    
    // CPU-Motherboard socket compatibility
    if ($newComponentType === 'cpu' && isset($components['motherboard'])) {
        $motherboard = $components['motherboard']['details'] ?? null;
        if ($motherboard && !isSocketCompatible($newComponentDetails, $motherboard)) {
            $issues[] = [
                'type' => 'socket_mismatch',
                'severity' => 'error',
                'message' => 'CPU socket does not match motherboard socket'
            ];
        }
    }
    
    if ($newComponentType === 'motherboard' && isset($components['cpu'])) {
        $cpu = $components['cpu']['details'] ?? null;
        if ($cpu && !isSocketCompatible($cpu, $newComponentDetails)) {
            $issues[] = [
                'type' => 'socket_mismatch',
                'severity' => 'error',
                'message' => 'Motherboard socket does not match CPU socket'
            ];
        }
    }
    
    // RAM compatibility with motherboard
    if ($newComponentType === 'ram' && isset($components['motherboard'])) {
        $motherboard = $components['motherboard']['details'] ?? null;
        if ($motherboard && !isRAMCompatible($newComponentDetails, $motherboard)) {
            $issues[] = [
                'type' => 'ram_incompatible',
                'severity' => 'warning',
                'message' => 'RAM type may not be compatible with motherboard'
            ];
        }
    }
    
    return $issues;
}

/**
 * Check if CPU and motherboard sockets are compatible
 */
function isSocketCompatible($cpu, $motherboard) {
    // Extract socket information from Notes field
    $cpuSocket = extractSocketFromNotes($cpu['Notes'] ?? '');
    $mbSocket = extractSocketFromNotes($motherboard['Notes'] ?? '');
    
    if (!$cpuSocket || !$mbSocket) {
        return true; // Can't determine, assume compatible
    }
    
    return strtolower($cpuSocket) === strtolower($mbSocket);
}

/**
 * Check if RAM is compatible with motherboard
 */
function isRAMCompatible($ram, $motherboard) {
    // Extract RAM type from Notes field
    $ramType = extractRAMTypeFromNotes($ram['Notes'] ?? '');
    $mbRAMType = extractRAMTypeFromNotes($motherboard['Notes'] ?? '');
    
    if (!$ramType || !$mbRAMType) {
        return true; // Can't determine, assume compatible
    }
    
    return strtolower($ramType) === strtolower($mbRAMType);
}

/**
 * Extract socket information from notes
 */
function extractSocketFromNotes($notes) {
    // Look for patterns like "Socket: LGA1151", "Socket LGA1151", "LGA1151 socket"
    if (preg_match('/socket[:\s]*([A-Z0-9]+)/i', $notes, $matches)) {
        return $matches[1];
    }
    
    // Look for common socket patterns
    if (preg_match('/(LGA\d+|AM\d+|FM\d+|TR4)/i', $notes, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Extract RAM type from notes
 */
function extractRAMTypeFromNotes($notes) {
    // Look for DDR patterns
    if (preg_match('/(DDR\d+)/i', $notes, $matches)) {
        return $matches[1];
    }
    
    return null;
}

/**
 * Determine next recommended step based on current configuration
 */
function determineNextStep($configData) {
    if (!isset($configData['components'])) {
        return 'cpu'; // Start with CPU
    }
    
    $components = $configData['components'];
    
    // Recommended order: CPU -> Motherboard -> RAM -> Storage -> NIC -> Caddy
    $recommendedOrder = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
    
    foreach ($recommendedOrder as $type) {
        if (!isset($components[$type])) {
            return $type;
        }
    }
    
    return null; // All basic components added
}

/**
 * Calculate configuration progress
 */
function calculateConfigurationProgress($configData) {
    $requiredComponents = ['cpu', 'motherboard', 'ram', 'storage'];
    $optionalComponents = ['nic', 'caddy'];
    $allComponents = array_merge($requiredComponents, $optionalComponents);
    
    $completedRequired = 0;
    $completedOptional = 0;
    $totalComponents = isset($configData['components']) ? count($configData['components']) : 0;
    
    if (isset($configData['components'])) {
        foreach ($requiredComponents as $type) {
            if (isset($configData['components'][$type])) {
                $completedRequired++;
            }
        }
        
        foreach ($optionalComponents as $type) {
            if (isset($configData['components'][$type])) {
                $completedOptional++;
            }
        }
    }
    
    $requiredProgress = ($completedRequired / count($requiredComponents)) * 100;
    $overallProgress = ($totalComponents / count($allComponents)) * 100;
    
    return [
        'total_components' => $totalComponents,
        'required_completed' => $completedRequired,
        'required_total' => count($requiredComponents),
        'optional_completed' => $completedOptional,
        'optional_total' => count($optionalComponents),
        'required_progress_percent' => round($requiredProgress, 1),
        'overall_progress_percent' => round($overallProgress, 1),
        'is_minimum_viable' => $completedRequired >= 3, // CPU, MB, RAM minimum
        'is_complete' => $completedRequired === count($requiredComponents)
    ];
}

/**
 * Get configuration summary
 */
function getConfigurationSummary($configData) {
    $summary = [
        'total_components' => 0,
        'components_by_type' => [],
        'estimated_cost' => 0,
        'power_consumption' => 0
    ];
    
    if (!isset($configData['components'])) {
        return $summary;
    }
    
    foreach ($configData['components'] as $type => $componentInfo) {
        if (isset($componentInfo['uuid'])) {
            // Single instance component
            $summary['total_components']++;
            $summary['components_by_type'][$type] = 1;
        } else {
            // Multi-instance component
            $count = is_array($componentInfo) ? count($componentInfo) : 0;
            $summary['total_components'] += $count;
            $summary['components_by_type'][$type] = $count;
        }
    }
    
    return $summary;
}

/**
 * Validate server configuration
 */
function validateServerConfiguration($pdo, $configData) {
    $errors = [];
    $warnings = [];
    $criticalErrors = [];
    
    if (!isset($configData['components']) || empty($configData['components'])) {
        $criticalErrors[] = "No components added to configuration";
        return [
            'is_complete' => false,
            'errors' => $errors,
            'warnings' => $warnings,
            'critical_errors' => $criticalErrors
        ];
    }
    
    $components = $configData['components'];
    
    // Check for required components
    $requiredComponents = ['cpu', 'motherboard', 'ram'];
    foreach ($requiredComponents as $type) {
        if (!isset($components[$type])) {
            $criticalErrors[] = "Missing required component: " . ucfirst($type);
        }
    }
    
    // Check for storage
    if (!isset($components['storage'])) {
        $errors[] = "No storage component added - server may not be bootable";
    }
    
    // Check for network interface
    if (!isset($components['nic'])) {
        $warnings[] = "No network interface added - server will have limited connectivity";
    }
    
    // Validate component compatibility
    foreach ($components as $type => $componentInfo) {
        $componentDetails = null;
        if (isset($componentInfo['details'])) {
            $componentDetails = $componentInfo['details'];
        } elseif (isset($componentInfo['uuid'])) {
            $componentDetails = getComponentDetails($pdo, $type, $componentInfo['uuid']);
        }
        
        if (!$componentDetails) {
            $errors[] = "Could not validate component details for $type";
            continue;
        }
        
        // Check if component is still available
        if ($componentDetails['Status'] != '1' && $componentDetails['Status'] != '2') {
            $criticalErrors[] = "Component $type is no longer available (Status: " . getStatusText($componentDetails['Status']) . ")";
        }
    }
    
    // Check compatibility between components
    if (isset($components['cpu']) && isset($components['motherboard'])) {
        $cpuDetails = $components['cpu']['details'] ?? getComponentDetails($pdo, 'cpu', $components['cpu']['uuid']);
        $mbDetails = $components['motherboard']['details'] ?? getComponentDetails($pdo, 'motherboard', $components['motherboard']['uuid']);
        
        if ($cpuDetails && $mbDetails && !isSocketCompatible($cpuDetails, $mbDetails)) {
            $criticalErrors[] = "CPU and motherboard socket incompatibility detected";
        }
    }
    
    $isComplete = empty($criticalErrors) && count($components) >= 3;
    
    return [
        'is_complete' => $isComplete,
        'component_count' => count($components),
        'errors' => $errors,
        'warnings' => $warnings,
        'critical_errors' => $criticalErrors,
        'validation_summary' => sprintf(
            "Configuration has %d components. %d errors, %d warnings, %d critical errors.",
            count($components),
            count($errors),
            count($warnings),
            count($criticalErrors)
        )
    ];
}

/**
 * Get status text from status code
 */
function getStatusText($statusCode) {
    $statusMap = [
        '0' => 'Failed/Defective',
        '1' => 'Available',
        '2' => 'In Use'
    ];
    
    return $statusMap[$statusCode] ?? 'Unknown';
}

/**
 * Log server build action
 */
function logServerBuildAction($pdo, $configUuid, $action, $componentType = null, $componentUuid = null, $details = [], $userId = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO server_build_history (config_uuid, action, component_type, component_uuid, details, user_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $configUuid,
            $action,
            $componentType,
            $componentUuid,
            json_encode($details),
            $userId
        ]);
    } catch (Exception $e) {
        error_log("Error logging server build action: " . $e->getMessage());
        // Don't throw error, just log it
    }
}

?>