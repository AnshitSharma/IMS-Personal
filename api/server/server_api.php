<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/BaseFunctions.php';
require_once __DIR__ . '/../../includes/models/ServerBuilder.php';
require_once __DIR__ . '/../../includes/models/ServerConfiguration.php';
require_once __DIR__ . '/../../includes/models/CompatibilityEngine.php';

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
    
    // NEW: Update configuration endpoint
    case 'update-config':
    case 'server-update-config':
        handleUpdateConfiguration($serverBuilder, $user);
        break;
    
    // NEW: Update server location and propagate to components
    case 'update-location':
    case 'server-update-location':
        handleUpdateLocationAndPropagate($serverBuilder, $user);
        break;
    
    default:
        send_json_response(0, 1, 400, "Invalid action specified");
}

/**
 * NEW: Update server configuration details
 * Updates all editable fields in server_configurations table except compatibility_score and validation_results
 */
function handleUpdateConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Load the configuration to verify it exists and check permissions
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check if user owns this configuration or has edit permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to modify this configuration");
        }
        
        // Get current configuration status to prevent updates on finalized configs
        $currentStatus = $config->get('configuration_status');
        $requestedStatus = isset($_POST['configuration_status']) ? (int)$_POST['configuration_status'] : null;
        
        // Prevent modification of finalized configurations (status 3) unless admin
        if ($currentStatus == 3 && !hasPermission($pdo, 'server.edit_finalized', $user['id'])) {
            send_json_response(0, 1, 403, "Cannot modify finalized configurations without proper permissions");
        }
        
        // Prevent status change from finalized to lower status unless admin
        if ($currentStatus == 3 && $requestedStatus !== null && $requestedStatus < 3 && !hasPermission($pdo, 'server.edit_finalized', $user['id'])) {
            send_json_response(0, 1, 403, "Cannot change status of finalized configuration without proper permissions");
        }
        
        // Define updatable fields (excluding calculated fields)
        $updatableFields = [
            'server_name',
            'description', 
            'configuration_status',
            'location',
            'rack_position',
            'notes'
        ];
        
        // Build update query dynamically based on provided fields
        $updateFields = [];
        $updateValues = [];
        $changes = [];
        
        foreach ($updatableFields as $field) {
            if (isset($_POST[$field])) {
                $newValue = $_POST[$field];
                $currentValue = $config->get($field);
                
                // Handle special field processing
                switch ($field) {
                    case 'server_name':
                        $newValue = trim($newValue);
                        if (empty($newValue)) {
                            send_json_response(0, 1, 400, "Server name cannot be empty");
                        }
                        break;
                        
                    case 'configuration_status':
                        $newValue = $newValue !== '' ? (int)$newValue : null;
                        break;
                        
                    default:
                        $newValue = trim($newValue);
                        break;
                }
                
                // Only add to update if value has changed
                if ($newValue !== $currentValue) {
                    $updateFields[] = "$field = ?";
                    $updateValues[] = $newValue;
                    $changes[$field] = [
                        'old' => $currentValue,
                        'new' => $newValue
                    ];
                }
            }
        }
        
        // If no changes, return success without database update
        if (empty($updateFields)) {
            send_json_response(1, 1, 200, "No changes detected - configuration is already up to date", [
                'config_uuid' => $configUuid,
                'changes_made' => []
            ]);
        }
        
        // Add updated_by and updated_at fields
        $updateFields[] = "updated_by = ?";
        $updateFields[] = "updated_at = NOW()";
        $updateValues[] = $user['id'];
        
        // Execute the update
        $sql = "UPDATE server_configurations SET " . implode(', ', $updateFields) . " WHERE config_uuid = ?";
        $updateValues[] = $configUuid;
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($updateValues);
        
        if (!$result) {
            send_json_response(0, 1, 500, "Failed to update configuration");
        }
        
        // Log the update action
        logConfigurationUpdate($pdo, $configUuid, $changes, $user['id']);
        
        // Get updated configuration details
        $updatedConfig = ServerConfiguration::loadByUuid($pdo, $configUuid);
        
        // Prepare response data
        $configData = [];
        foreach ($updatableFields as $field) {
            $configData[$field] = $updatedConfig->get($field);
        }
        
        // Add metadata fields
        $configData['config_uuid'] = $configUuid;
        $configData['created_by'] = $updatedConfig->get('created_by');
        $configData['updated_by'] = $updatedConfig->get('updated_by');
        $configData['created_at'] = $updatedConfig->get('created_at');
        $configData['updated_at'] = $updatedConfig->get('updated_at');
        $configData['compatibility_score'] = $updatedConfig->get('compatibility_score');
        $configData['validation_results'] = $updatedConfig->get('validation_results');
        
        // Parse JSON fields for response (none remaining)
        
        send_json_response(1, 1, 200, "Configuration updated successfully", [
            'config_uuid' => $configUuid,
            'changes_made' => $changes,
            'total_changes' => count($changes),
            'configuration' => $configData,
            'configuration_status_text' => getConfigurationStatusText($configData['configuration_status']),
            'updated_by_user_id' => $user['id'],
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        error_log("Error updating configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to update configuration: " . $e->getMessage());
    }
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
 * FIXED: Add component to server configuration with proper ServerUUID handling
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
        
        // FIXED: Enhanced availability check with ServerUUID context
        $componentStatus = (int)$componentDetails['Status'];
        $componentServerUuid = $componentDetails['ServerUUID'] ?? null;
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
                // Check if component is in same config or different config
                if ($componentServerUuid === $configUuid) {
                    $isAvailable = true;
                    $statusMessage = "Component is already assigned to this configuration";
                } else {
                    if ($override) {
                        $isAvailable = true;
                        $statusMessage = $componentServerUuid ? 
                            "Component is In Use in configuration $componentServerUuid (override enabled)" :
                            "Component is In Use (override enabled)";
                    } else {
                        $statusMessage = $componentServerUuid ? 
                            "Component is currently In Use in configuration: $componentServerUuid" :
                            "Component is currently In Use";
                    }
                }
                break;
            default:
                $statusMessage = "Component has unknown status: $componentStatus";
        }
        
        if (!$isAvailable && !$override) {
            send_json_response(0, 1, 400, "Component is not available", [
                'component_status' => $componentStatus,
                'status_message' => $statusMessage,
                'component_server_uuid' => $componentServerUuid,
                'current_config_uuid' => $configUuid,
                'component_details' => [
                    'uuid' => $componentDetails['UUID'],
                    'serial_number' => $componentDetails['SerialNumber'],
                    'current_status' => getStatusText($componentStatus)
                ],
                'can_override' => $componentStatus === 2,
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
            
            send_json_response(1, 1, 200, "Component added successfully", [
                'component_added' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'status_override_used' => $override,
                    'original_status' => $statusMessage,
                    'server_uuid_updated' => $configUuid
                ],
                'configuration_summary' => $summary,
                'progress' => $progressInfo,
                'component_limits' => $componentLimits,
                'next_recommendations' => getNextRecommendations($summary, $componentLimits)
            ]);
        } else {
            send_json_response(0, 1, 400, $result['message'] ?? "Failed to add component", [
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
                    'uuid' => $componentUuid,
                    'server_uuid_cleared' => true
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
 * ENHANCED: Get server configuration details with compatibility scoring and validation
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
        
        // Use getConfigurationDetails for complete information
        $details = $serverBuilder->getConfigurationDetails($configUuid);
        
        if (isset($details['error'])) {
            send_json_response(0, 1, 500, "Failed to retrieve configuration: " . $details['error']);
        }
        
        // Calculate compatibility score and validation results
        $compatibilityScore = null;
        $validationResults = null;
        $componentChecks = [];
        
        try {
            // Use CompatibilityEngine if available for detailed compatibility analysis
            if (class_exists('CompatibilityEngine')) {
                $compatibilityEngine = new CompatibilityEngine($pdo);
                $compatibilityValidation = $compatibilityEngine->validateServerConfiguration($details['configuration']);
                $compatibilityScore = round($compatibilityValidation['overall_score'] * 100, 1);
                $validationResults = $compatibilityValidation;
                
                // Extract component-level compatibility checks
                $componentChecks = generateComprehensiveComponentChecks($details, $compatibilityEngine);
            } else {
                // Fallback: Run comprehensive validation using ServerBuilder only
                $validation = $serverBuilder->validateConfiguration($configUuid);
                $validationResults = $validation;
                $compatibilityScore = $validation['compatibility_score'] ?? null;
                
                // Generate basic component checks without CompatibilityEngine
                $componentChecks = generateBasicComponentChecks($details);
            }
            
            // Add missing component validation check
            if (!empty($details['missing_components'])) {
                if (!isset($validationResults['global_checks'])) {
                    $validationResults['global_checks'] = [];
                }
                
                $missingComponentNames = [];
                foreach ($details['missing_components'] as $missing) {
                    $missingComponentNames[] = strtoupper($missing['component_type']) . ' (' . $missing['component_uuid'] . ')';
                }
                
                $validationResults['global_checks'][] = [
                    'check' => 'Component Data Integrity',
                    'passed' => false,
                    'message' => 'Missing components in inventory: ' . implode(', ', $missingComponentNames)
                ];
                
                // Reduce overall score if there are missing components
                if (isset($validationResults['overall_score'])) {
                    $validationResults['overall_score'] = 0; // Set to 0 if components are missing
                }
                $compatibilityScore = 0; // Also set compatibility score to 0
            }
            
            // Store the calculated values in the database
            if ($compatibilityScore !== null || $validationResults !== null) {
                $serverBuilder->updateConfigurationValidation($configUuid, $compatibilityScore, $validationResults);
            }
            
        } catch (Exception $compatError) {
            error_log("Error calculating compatibility for config $configUuid: " . $compatError->getMessage());
            // Continue without compatibility data rather than failing the entire request
            $compatibilityScore = null;
            $validationResults = [
                'error' => 'Compatibility calculation failed',
                'message' => $compatError->getMessage()
            ];
            $componentChecks = [];
        }
        
        // Clean up configuration data and fix JSON configurations
        $configuration = $details['configuration'];
        
        // Ensure all calculated fields are present and up-to-date
        $configuration['power_consumption'] = $details['power_consumption']['total_with_overhead_watts'] ?? 0;
        $configuration['compatibility_score'] = $compatibilityScore;
        
        // FIXED: Build proper component configurations as arrays for multiple components
        $cpuConfig = [];
        $ramConfig = [];
        $storageConfig = [];
        $nicConfig = [];
        $caddyConfig = [];
        
        // Build configurations from actual components
        if (isset($details['components']['cpu'])) {
            foreach ($details['components']['cpu'] as $cpu) {
                $cpuConfig[] = [
                    'uuid' => $cpu['component_uuid'],
                    'id' => $cpu['details']['id'] ?? null, // Include component ID
                    'quantity' => $cpu['quantity'] ?? 1,
                    'added_at' => $cpu['added_at'],
                    'serial_number' => $cpu['details']['SerialNumber'] ?? 'Unknown'
                ];
            }
        }
        
        if (isset($details['components']['ram'])) {
            foreach ($details['components']['ram'] as $ram) {
                $ramConfig[] = [
                    'uuid' => $ram['component_uuid'],
                    'id' => $ram['details']['id'] ?? null,
                    'quantity' => $ram['quantity'] ?? 1,
                    'slot_position' => $ram['slot_position'] ?? null,
                    'added_at' => $ram['added_at'],
                    'serial_number' => $ram['details']['SerialNumber'] ?? 'Unknown'
                ];
            }
        }
        
        if (isset($details['components']['storage'])) {
            foreach ($details['components']['storage'] as $storage) {
                $storageConfig[] = [
                    'uuid' => $storage['component_uuid'],
                    'id' => $storage['details']['id'] ?? null,
                    'quantity' => $storage['quantity'] ?? 1,
                    'bay_position' => $storage['slot_position'] ?? null,
                    'added_at' => $storage['added_at'],
                    'serial_number' => $storage['details']['SerialNumber'] ?? 'Unknown'
                ];
            }
        }
        
        if (isset($details['components']['nic'])) {
            foreach ($details['components']['nic'] as $nic) {
                $nicConfig[] = [
                    'uuid' => $nic['component_uuid'],
                    'id' => $nic['details']['id'] ?? null,
                    'quantity' => $nic['quantity'] ?? 1,
                    'slot_position' => $nic['slot_position'] ?? null,
                    'added_at' => $nic['added_at'],
                    'serial_number' => $nic['details']['SerialNumber'] ?? 'Unknown'
                ];
            }
        }
        
        if (isset($details['components']['caddy'])) {
            foreach ($details['components']['caddy'] as $caddy) {
                $caddyConfig[] = [
                    'uuid' => $caddy['component_uuid'],
                    'id' => $caddy['details']['id'] ?? null,
                    'quantity' => $caddy['quantity'] ?? 1,
                    'added_at' => $caddy['added_at'],
                    'serial_number' => $caddy['details']['SerialNumber'] ?? 'Unknown'
                ];
            }
        }
        
        // FIXED: Remove individual cpu_uuid and cpu_id, replace with cpu_configuration
        unset($configuration['cpu_uuid']);
        unset($configuration['cpu_id']);
        unset($configuration['motherboard_uuid']);
        unset($configuration['motherboard_id']);
        
        // Set the properly built configurations in the correct positions
        $configuration['cpu_configuration'] = $cpuConfig;
        $configuration['ram_configuration'] = $ramConfig;
        $configuration['storage_configuration'] = $storageConfig;
        $configuration['nic_configuration'] = $nicConfig;
        $configuration['caddy_configuration'] = $caddyConfig;
        
        // Also add motherboard_configuration for consistency
        $motherboardConfig = [];
        if (isset($details['components']['motherboard'])) {
            foreach ($details['components']['motherboard'] as $motherboard) {
                $motherboardConfig[] = [
                    'uuid' => $motherboard['component_uuid'],
                    'id' => $motherboard['details']['id'] ?? null,
                    'quantity' => $motherboard['quantity'] ?? 1,
                    'added_at' => $motherboard['added_at'],
                    'serial_number' => $motherboard['details']['SerialNumber'] ?? 'Unknown'
                ];
            }
        }
        $configuration['motherboard_configuration'] = $motherboardConfig;
        
        send_json_response(1, 1, 200, "Configuration retrieved successfully", [
            'configuration' => $configuration,
            'component_counts' => $details['component_counts'],
            'component_ids_uuids' => $details['component_ids_uuids'],
            'total_components' => $details['total_components'],
            'power_consumption' => $details['power_consumption'],
            'server_name' => $details['server_name'],
            'configuration_status' => $details['configuration_status'],
            'configuration_status_text' => getConfigurationStatusText($details['configuration_status']),
            'validation_results' => $validationResults
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
        
        // Add configuration status text for each configuration
        foreach ($configurations as &$config) {
            $config['configuration_status_text'] = getConfigurationStatusText($config['configuration_status']);
        }
        
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
                'validation_errors' => $validation['issues'],
                'compatibility_score' => $validation['compatibility_score']
            ]);
        }
        
        $result = $serverBuilder->finalizeConfiguration($configUuid, $finalNotes);
        
        if ($result['success']) {
            send_json_response(1, 1, 200, "Configuration finalized successfully", [
                'config_uuid' => $configUuid,
                'finalization_details' => $result,
                'configuration_status' => 3,
                'configuration_status_text' => 'Finalized'
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
            send_json_response(1, 1, 200, "Configuration deleted successfully", [
                'components_released' => $result['components_released']
            ]);
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
 * FIXED: Validate server configuration - Updated with proper compatibility scoring and warnings
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
                try {
                    $compatibilityEngine = new CompatibilityEngine($pdo);
                    $result = ['components' => $compatibilityEngine->getCompatibleComponentsForConfiguration($configUuid, $componentType, $availableOnly)];
                } catch (Exception $compatError) {
                    error_log("CompatibilityEngine error: " . $compatError->getMessage());
                    $result['components'] = getAvailableComponents($pdo, $componentType, $availableOnly);
                    $result['compatibility_engine_available'] = false;
                    $result['compatibility_engine_error'] = $compatError->getMessage();
                }
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
                try {
                    $compatibilityEngine = new CompatibilityEngine($pdo);
                    $result = $compatibilityEngine->getCompatibleComponentsFor($componentType, $componentUuid);
                } catch (Exception $compatError) {
                    error_log("CompatibilityEngine error: " . $compatError->getMessage());
                    $result['components'] = getAvailableComponents($pdo, $componentType, $availableOnly);
                    $result['compatibility_engine_available'] = false;
                    $result['compatibility_engine_error'] = $compatError->getMessage();
                }
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

// Helper Functions

/**
 * NEW: Helper function to validate DateTime format
 */
function validateDateTime($dateTime) {
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $dateTime);
    return $d && $d->format('Y-m-d H:i:s') === $dateTime;
}

/**
 * NEW: Helper function to log configuration updates
 */
function logConfigurationUpdate($pdo, $configUuid, $changes, $userId) {
    try {
        // Check if history table exists
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'server_configuration_history'");
        $stmt->execute();
        if (!$stmt->fetch()) {
            createConfigurationHistoryTable($pdo);
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO server_configuration_history 
            (config_uuid, action, component_type, component_uuid, metadata, created_by, created_at) 
            VALUES (?, 'configuration_updated', NULL, NULL, ?, ?, NOW())
        ");
        $stmt->execute([
            $configUuid,
            json_encode([
                'changes' => $changes,
                'total_fields_changed' => count($changes),
                'updated_at' => date('Y-m-d H:i:s')
            ]),
            $userId
        ]);
    } catch (Exception $e) {
        error_log("Error logging configuration update: " . $e->getMessage());
        // Don't throw exception as this shouldn't break the main operation
    }
}

/**
 * NEW: Helper function to create configuration history table if it doesn't exist
 */
function createConfigurationHistoryTable($pdo) {
    try {
        $sql = "
            CREATE TABLE IF NOT EXISTS server_configuration_history (
                id int(11) NOT NULL AUTO_INCREMENT,
                config_uuid varchar(36) NOT NULL,
                action varchar(50) NOT NULL COMMENT 'created, updated, component_added, component_removed, validated, configuration_updated, etc.',
                component_type varchar(20) DEFAULT NULL,
                component_uuid varchar(36) DEFAULT NULL,
                metadata text DEFAULT NULL COMMENT 'JSON metadata for the action',
                created_by int(11) DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (id),
                KEY idx_config_uuid (config_uuid),
                KEY idx_component_uuid (component_uuid),
                KEY idx_created_at (created_at),
                KEY idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $pdo->exec($sql);
        error_log("Created server_configuration_history table");
    } catch (Exception $e) {
        error_log("Error creating history table: " . $e->getMessage());
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
 * Helper function to get configuration status text
 */
function getConfigurationStatusText($statusCode) {
    $statusMap = [
        0 => 'Draft',
        1 => 'Validated', 
        2 => 'Built',
        3 => 'Finalized'
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
            SELECT UUID, SerialNumber, Status, ServerUUID 
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
            __DIR__ . '/../../All-JSON/motherboad-jsons/motherboard-level-3.json'
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
            'max_quantity' => 10,
            'required' => false
        ];
    }
    
    return $recommendations;
}

/**
 * NEW: Update server location and rack position, and propagate to all assigned components
 * This function handles the specific case of updating deployment location information
 */
function handleUpdateLocationAndPropagate($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $location = trim($_POST['location'] ?? '');
    $rackPosition = trim($_POST['rack_position'] ?? '');
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Load the configuration to verify it exists and check permissions
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check if user owns this configuration or has edit permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to modify this configuration");
        }
        
        // Begin transaction to ensure all updates succeed or fail together
        $pdo->beginTransaction();
        
        // Update the server configuration location and rack position
        $updateFields = [];
        $updateValues = [];
        $changes = [];
        
        $currentLocation = $config->get('location');
        $currentRackPosition = $config->get('rack_position');
        
        if ($location !== $currentLocation) {
            $updateFields[] = "location = ?";
            $updateValues[] = $location ?: null;
            $changes['location'] = ['old' => $currentLocation, 'new' => $location ?: null];
        }
        
        if ($rackPosition !== $currentRackPosition) {
            $updateFields[] = "rack_position = ?";
            $updateValues[] = $rackPosition ?: null;
            $changes['rack_position'] = ['old' => $currentRackPosition, 'new' => $rackPosition ?: null];
        }
        
        if (empty($updateFields)) {
            $pdo->rollback();
            send_json_response(1, 1, 200, "No location changes detected", [
                'config_uuid' => $configUuid,
                'current_location' => $currentLocation,
                'current_rack_position' => $currentRackPosition
            ]);
        }
        
        // Update server configuration
        $updateFields[] = "updated_by = ?";
        $updateFields[] = "updated_at = NOW()";
        $updateValues[] = $user['id'];
        $updateValues[] = $configUuid;
        
        $sql = "UPDATE server_configurations SET " . implode(', ', $updateFields) . " WHERE config_uuid = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($updateValues);
        
        if (!$result) {
            $pdo->rollback();
            send_json_response(0, 1, 500, "Failed to update server configuration location");
        }
        
        // Get all components assigned to this configuration
        $stmt = $pdo->prepare("
            SELECT component_type, component_uuid 
            FROM server_configuration_components 
            WHERE config_uuid = ?
        ");
        $stmt->execute([$configUuid]);
        $assignedComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Update all assigned components with new location and rack position
        $componentUpdateCount = 0;
        $componentTables = [
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        foreach ($assignedComponents as $component) {
            $componentType = $component['component_type'];
            $componentUuid = $component['component_uuid'];
            
            if (!isset($componentTables[$componentType])) {
                continue;
            }
            
            $table = $componentTables[$componentType];
            
            // Build component update query
            $compUpdateFields = [];
            $compUpdateValues = [];
            
            if (isset($changes['location'])) {
                $compUpdateFields[] = "Location = ?";
                $compUpdateValues[] = $location ?: null;
            }
            
            if (isset($changes['rack_position'])) {
                $compUpdateFields[] = "RackPosition = ?";
                $compUpdateValues[] = $rackPosition ?: null;
            }
            
            if (!empty($compUpdateFields)) {
                $compUpdateFields[] = "UpdatedAt = NOW()";
                $compUpdateValues[] = $componentUuid;
                
                $compSql = "UPDATE $table SET " . implode(', ', $compUpdateFields) . " WHERE UUID = ?";
                $compStmt = $pdo->prepare($compSql);
                if ($compStmt->execute($compUpdateValues)) {
                    $componentUpdateCount++;
                    error_log("Updated location for component $componentUuid in $table");
                } else {
                    error_log("Failed to update location for component $componentUuid in $table");
                }
            }
        }
        
        $pdo->commit();
        
        // Log the update action
        logConfigurationUpdate($pdo, $configUuid, $changes, $user['id']);
        
        send_json_response(1, 1, 200, "Server location updated and propagated to components successfully", [
            'config_uuid' => $configUuid,
            'changes_made' => $changes,
            'components_updated' => $componentUpdateCount,
            'total_assigned_components' => count($assignedComponents),
            'new_location' => $location ?: null,
            'new_rack_position' => $rackPosition ?: null,
            'updated_by_user_id' => $user['id'],
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        $pdo->rollback();
        error_log("Error updating server location: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to update server location: " . $e->getMessage());
    }
}

/**
 * Check user permissions (fallback function if not exists)
 */
if (!function_exists('hasPermission')) {
    function hasPermission($pdo, $permission, $userId) {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM role_permissions rp
                JOIN user_roles ur ON rp.role_id = ur.role_id
                JOIN permissions p ON rp.permission_id = p.id
                WHERE ur.user_id = ? AND p.name = ?
            ");
            $stmt->execute([$userId, $permission]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            error_log("Error checking permission: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Generate comprehensive component compatibility checks using CompatibilityEngine
 */
function generateComprehensiveComponentChecks($details, $compatibilityEngine) {
    $componentChecks = [];
    $components = $details['components'] ?? [];
    
    try {
        // Get motherboard for reference checks
        $motherboard = null;
        if (isset($components['motherboard'][0])) {
            $motherboard = $components['motherboard'][0];
        }
        
        // Check CPU compatibility (individual CPUs vs motherboard)
        if (isset($components['cpu']) && $motherboard) {
            $cpuIndex = 1;
            foreach ($components['cpu'] as $cpu) {
                $compatibilityResult = checkIndividualComponentCompatibility(
                    $compatibilityEngine, 
                    'cpu', 
                    $cpu,
                    'motherboard', 
                    $motherboard,
                    "CPU{$cpuIndex}"
                );
                $componentChecks[] = $compatibilityResult;
                $cpuIndex++;
            }
        }
        
        // Check RAM compatibility
        if (isset($components['ram']) && $motherboard) {
            $ramIndex = 1;
            foreach ($components['ram'] as $ram) {
                $compatibilityResult = checkIndividualComponentCompatibility(
                    $compatibilityEngine,
                    'ram',
                    $ram,
                    'motherboard',
                    $motherboard,
                    "RAM{$ramIndex}"
                );
                $componentChecks[] = $compatibilityResult;
                $ramIndex++;
            }
        }
        
        // Check Storage compatibility
        if (isset($components['storage']) && $motherboard) {
            $storageIndex = 1;
            foreach ($components['storage'] as $storage) {
                $compatibilityResult = checkIndividualComponentCompatibility(
                    $compatibilityEngine,
                    'storage',
                    $storage,
                    'motherboard',
                    $motherboard,
                    "Storage{$storageIndex}"
                );
                $componentChecks[] = $compatibilityResult;
                $storageIndex++;
            }
        }
        
        // Check NIC compatibility
        if (isset($components['nic']) && $motherboard) {
            $nicIndex = 1;
            foreach ($components['nic'] as $nic) {
                $compatibilityResult = checkIndividualComponentCompatibility(
                    $compatibilityEngine,
                    'nic',
                    $nic,
                    'motherboard',
                    $motherboard,
                    "NIC{$nicIndex}"
                );
                $componentChecks[] = $compatibilityResult;
                $nicIndex++;
            }
        }
        
        // CPU-to-CPU compatibility check removed as per requirement
        
    } catch (Exception $e) {
        error_log("Error generating comprehensive component checks: " . $e->getMessage());
        $componentChecks[] = [
            'check_type' => 'system_error',
            'component1' => 'system',
            'component2' => 'system',
            'compatible' => false,
            'message' => 'Error during compatibility analysis: ' . $e->getMessage(),
            'details' => []
        ];
    }
    
    return $componentChecks;
}

/**
 * Generate basic component compatibility checks without CompatibilityEngine
 */
function generateBasicComponentChecks($details) {
    $componentChecks = [];
    $components = $details['components'] ?? [];
    
    try {
        // Get motherboard for reference checks
        $motherboard = null;
        if (isset($components['motherboard'][0])) {
            $motherboard = $components['motherboard'][0];
        }
        
        // Basic CPU compatibility check with JSON validation
        if (isset($components['cpu']) && $motherboard) {
            $cpuIndex = 1;
            foreach ($components['cpu'] as $cpu) {
                // FIXED: Check if CPU exists in JSON specifications before compatibility check
                $cpuExists = validateComponentExistsInJson('cpu', $cpu['component_uuid']);
                $motherboardExists = validateComponentExistsInJson('motherboard', $motherboard['component_uuid']);
                
                if (!$cpuExists || !$motherboardExists) {
                    $missingComponents = [];
                    if (!$cpuExists) $missingComponents[] = "CPU{$cpuIndex}";
                    if (!$motherboardExists) $missingComponents[] = "Motherboard";
                    
                    $componentChecks[] = [
                        'check_type' => 'socket_compatibility',
                        'component1' => "CPU{$cpuIndex}",
                        'component1_uuid' => $cpu['component_uuid'],
                        'component1_serial' => $cpu['details']['SerialNumber'] ?? 'Unknown',
                        'component2' => 'Motherboard',
                        'component2_uuid' => $motherboard['component_uuid'],
                        'component2_serial' => $motherboard['details']['SerialNumber'] ?? 'Unknown',
                        'compatible' => false,
                        'message' => 'Component(s) not found in specification database: ' . implode(', ', $missingComponents),
                        'details' => [
                            'error' => 'missing_component_specs',
                            'cpu_exists_in_json' => $cpuExists,
                            'motherboard_exists_in_json' => $motherboardExists
                        ]
                    ];
                } else {
                    // Both components exist in JSON, proceed with compatibility check
                    $compatible = checkBasicSocketCompatibility($motherboard, $cpu['details'] ?? []);
                    $componentChecks[] = [
                        'check_type' => 'socket_compatibility',
                        'component1' => "CPU{$cpuIndex}",
                        'component1_uuid' => $cpu['component_uuid'],
                        'component1_serial' => $cpu['details']['SerialNumber'] ?? 'Unknown',
                        'component2' => 'Motherboard',
                        'component2_uuid' => $motherboard['component_uuid'],
                        'component2_serial' => $motherboard['details']['SerialNumber'] ?? 'Unknown',
                        'compatible' => $compatible['compatible'],
                        'message' => $compatible['message'],
                        'details' => $compatible['details']
                    ];
                }
                $cpuIndex++;
            }
        }
        
        // Basic RAM compatibility check with JSON validation
        if (isset($components['ram']) && $motherboard) {
            $ramIndex = 1;
            foreach ($components['ram'] as $ram) {
                // FIXED: Check if RAM exists in JSON specifications before compatibility check
                $ramExists = validateComponentExistsInJson('ram', $ram['component_uuid']);
                $motherboardExists = validateComponentExistsInJson('motherboard', $motherboard['component_uuid']);
                
                if (!$ramExists || !$motherboardExists) {
                    $missingComponents = [];
                    if (!$ramExists) $missingComponents[] = "RAM{$ramIndex}";
                    if (!$motherboardExists) $missingComponents[] = "Motherboard";
                    
                    $componentChecks[] = [
                        'check_type' => 'memory_compatibility',
                        'component1' => "RAM{$ramIndex}",
                        'component1_uuid' => $ram['component_uuid'],
                        'component1_serial' => $ram['details']['SerialNumber'] ?? 'Unknown',
                        'component2' => 'Motherboard',
                        'component2_uuid' => $motherboard['component_uuid'],
                        'component2_serial' => $motherboard['details']['SerialNumber'] ?? 'Unknown',
                        'compatible' => false,
                        'message' => 'Component(s) not found in specification database: ' . implode(', ', $missingComponents),
                        'details' => [
                            'error' => 'missing_component_specs',
                            'ram_exists_in_json' => $ramExists,
                            'motherboard_exists_in_json' => $motherboardExists
                        ]
                    ];
                } else {
                    // Both components exist in JSON, proceed with compatibility check
                    $compatible = checkBasicMemoryCompatibility($motherboard, $ram['details'] ?? []);
                    $componentChecks[] = [
                        'check_type' => 'memory_compatibility',
                        'component1' => "RAM{$ramIndex}",
                        'component1_uuid' => $ram['component_uuid'],
                        'component1_serial' => $ram['details']['SerialNumber'] ?? 'Unknown',
                        'component2' => 'Motherboard',
                        'component2_uuid' => $motherboard['component_uuid'],
                        'component2_serial' => $motherboard['details']['SerialNumber'] ?? 'Unknown',
                        'compatible' => $compatible['compatible'],
                        'message' => $compatible['message'],
                        'details' => $compatible['details']
                    ];
                }
                $ramIndex++;
            }
        }
        
        // Add message if no motherboard is present
        if (!$motherboard) {
            $componentChecks[] = [
                'check_type' => 'missing_component',
                'component1' => 'system',
                'component2' => 'system',
                'compatible' => false,
                'message' => 'No motherboard found - cannot perform compatibility checks',
                'details' => []
            ];
        }
        
    } catch (Exception $e) {
        error_log("Error generating basic component checks: " . $e->getMessage());
        $componentChecks[] = [
            'check_type' => 'system_error',
            'component1' => 'system',
            'component2' => 'system',
            'compatible' => false,
            'message' => 'Error during basic compatibility analysis: ' . $e->getMessage(),
            'details' => []
        ];
    }
    
    return $componentChecks;
}

/**
 * Check compatibility between individual components using CompatibilityEngine
 */
function checkIndividualComponentCompatibility($compatibilityEngine, $type1, $component1, $type2, $component2, $displayName) {
    try {
        // FIXED: Check if components exist in JSON before doing compatibility check
        $component1Exists = validateComponentExistsInJson($type1, $component1['component_uuid']);
        $component2Exists = validateComponentExistsInJson($type2, $component2['component_uuid']);
        
        if (!$component1Exists || !$component2Exists) {
            $missingComponents = [];
            if (!$component1Exists) $missingComponents[] = $displayName;
            if (!$component2Exists) $missingComponents[] = ucfirst($type2);
            
            return [
                'check_type' => $type1 . '_' . $type2 . '_compatibility',
                'component1' => $displayName,
                'component1_uuid' => $component1['component_uuid'],
                'component1_serial' => $component1['details']['SerialNumber'] ?? 'Unknown',
                'component2' => ucfirst($type2),
                'component2_uuid' => $component2['component_uuid'],
                'component2_serial' => $component2['details']['SerialNumber'] ?? 'Unknown',
                'compatible' => false,
                'compatibility_score' => 0,
                'message' => 'Component(s) not found in specification database: ' . implode(', ', $missingComponents),
                'details' => [
                    'error' => 'missing_component_specs',
                    'component1_exists' => $component1Exists,
                    'component2_exists' => $component2Exists
                ]
            ];
        }
        
        $result = $compatibilityEngine->checkCompatibility(
            ['type' => $type1, 'uuid' => $component1['component_uuid']],
            ['type' => $type2, 'uuid' => $component2['component_uuid']]
        );
        
        return [
            'check_type' => $type1 . '_' . $type2 . '_compatibility',
            'component1' => $displayName,
            'component1_uuid' => $component1['component_uuid'],
            'component1_serial' => $component1['details']['SerialNumber'] ?? 'Unknown',
            'component2' => ucfirst($type2),
            'component2_uuid' => $component2['component_uuid'],
            'component2_serial' => $component2['details']['SerialNumber'] ?? 'Unknown',
            'compatible' => $result['compatible'],
            'compatibility_score' => $result['compatibility_score'] ?? 0,
            'message' => !empty($result['failures']) ? implode('; ', $result['failures']) : 
                        (!empty($result['warnings']) ? implode('; ', $result['warnings']) : 'Compatible'),
            'details' => [
                'applied_rules' => $result['applied_rules'] ?? [],
                'failures' => $result['failures'] ?? [],
                'warnings' => $result['warnings'] ?? [],
                'recommendations' => $result['recommendations'] ?? []
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'check_type' => $type1 . '_' . $type2 . '_compatibility',
            'component1' => $displayName,
            'component1_uuid' => $component1['component_uuid'],
            'component1_serial' => $component1['details']['SerialNumber'] ?? 'Unknown',
            'component2' => ucfirst($type2),
            'component2_uuid' => $component2['component_uuid'],
            'component2_serial' => $component2['details']['SerialNumber'] ?? 'Unknown',
            'compatible' => false,
            'compatibility_score' => 0,
            'message' => 'Compatibility check failed: ' . $e->getMessage(),
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Basic socket compatibility check without CompatibilityEngine
 */
function checkBasicSocketCompatibility($motherboard, $cpu) {
    $mbDetails = $motherboard['details'] ?? [];
    $mbNotes = strtolower($mbDetails['Notes'] ?? '');
    $cpuNotes = strtolower($cpu['Notes'] ?? '');
    
    // Extract socket information
    $mbSocket = extractBasicSocketType($mbNotes);
    $cpuSocket = extractBasicSocketType($cpuNotes);
    
    if (!$mbSocket || !$cpuSocket) {
        return [
            'compatible' => null,
            'message' => 'Cannot determine socket compatibility - missing socket information',
            'details' => [
                'motherboard_socket' => $mbSocket,
                'cpu_socket' => $cpuSocket,
                'motherboard_notes' => $mbNotes,
                'cpu_notes' => $cpuNotes
            ]
        ];
    }
    
    $compatible = ($mbSocket === $cpuSocket);
    $message = $compatible ? 
        "Compatible - Both use {$mbSocket} socket" : 
        "Incompatible - Motherboard uses {$mbSocket}, CPU requires {$cpuSocket}";
        
    return [
        'compatible' => $compatible,
        'message' => $message,
        'details' => [
            'motherboard_socket' => $mbSocket,
            'cpu_socket' => $cpuSocket
        ]
    ];
}

/**
 * Basic memory compatibility check without CompatibilityEngine
 */
function checkBasicMemoryCompatibility($motherboard, $ram) {
    $mbDetails = $motherboard['details'] ?? [];
    $mbNotes = strtolower($mbDetails['Notes'] ?? '');
    $ramNotes = strtolower($ram['Notes'] ?? '');
    
    // Extract memory type information
    $mbMemoryTypes = extractBasicMemoryTypes($mbNotes);
    $ramType = extractBasicMemoryType($ramNotes);
    
    if (empty($mbMemoryTypes) || !$ramType) {
        return [
            'compatible' => null,
            'message' => 'Cannot determine memory compatibility - missing memory type information',
            'details' => [
                'motherboard_supported' => $mbMemoryTypes,
                'ram_type' => $ramType,
                'motherboard_notes' => $mbNotes,
                'ram_notes' => $ramNotes
            ]
        ];
    }
    
    $compatible = in_array($ramType, $mbMemoryTypes);
    $message = $compatible ? 
        "Compatible - {$ramType} supported" : 
        "Incompatible - Motherboard supports " . implode(', ', $mbMemoryTypes) . ", RAM is {$ramType}";
        
    return [
        'compatible' => $compatible,
        'message' => $message,
        'details' => [
            'motherboard_supported' => $mbMemoryTypes,
            'ram_type' => $ramType
        ]
    ];
}

/**
 * Extract basic socket type from component notes
 */
function extractBasicSocketType($notes) {
    $commonSockets = [
        'lga4677', 'lga4189', 'lga3647', 'lga2066', 'lga2011',
        'lga1700', 'lga1200', 'lga1151', 'lga1150', 'lga1155',
        'sp5', 'sp3', 'sp4', 'am5', 'am4', 'tr4', 'strx4'
    ];
    
    foreach ($commonSockets as $socket) {
        if (strpos($notes, $socket) !== false || strpos($notes, str_replace('lga', 'socket ', $socket)) !== false) {
            return $socket;
        }
    }
    
    return null;
}

/**
 * Extract basic memory types from motherboard notes
 */
function extractBasicMemoryTypes($notes) {
    $types = [];
    
    if (strpos($notes, 'ddr5') !== false) $types[] = 'ddr5';
    if (strpos($notes, 'ddr4') !== false) $types[] = 'ddr4';
    if (strpos($notes, 'ddr3') !== false) $types[] = 'ddr3';
    
    return $types;
}

/**
 * Extract basic memory type from RAM notes
 */
function extractBasicMemoryType($notes) {
    if (strpos($notes, 'ddr5') !== false) return 'ddr5';
    if (strpos($notes, 'ddr4') !== false) return 'ddr4';
    if (strpos($notes, 'ddr3') !== false) return 'ddr3';
    
    return null;
}

/**
 * FIXED: Validate if component exists in JSON specification files
 * This function gets the component details from database and checks if the component model exists in JSON specs
 */
function validateComponentExistsInJson($componentType, $componentUuid) {
    global $pdo;
    
    try {
        // First get component details from database
        $componentDetails = getComponentDetailsByUuid($pdo, $componentType, $componentUuid);
        if (!$componentDetails) {
            error_log("Component not found in database: $componentUuid");
            return false;
        }
        
        // Extract component model/specification info from Notes field
        $componentNotes = $componentDetails['Notes'] ?? '';
        $serialNumber = $componentDetails['SerialNumber'] ?? '';
        
        // Map component types to their JSON files
        $jsonFiles = [
            'cpu' => __DIR__ . '/../../All-JSON/cpu-jsons/Cpu-details-level-3.json',
            'motherboard' => __DIR__ . '/../../All-JSON/motherboad-jsons/motherboard-level-3.json',
            'ram' => __DIR__ . '/../../All-JSON/ram-jsons/Ram-details-level-3.json',
            'storage' => __DIR__ . '/../../All-JSON/storage-jsons/Storage-details-level-3.json',
            'nic' => __DIR__ . '/../../All-JSON/nic-jsons/Nic-details-level-3.json'
        ];
        
        if (!isset($jsonFiles[$componentType])) {
            error_log("No JSON file defined for component type: $componentType");
            return false;
        }
        
        $jsonFile = $jsonFiles[$componentType];
        
        if (!file_exists($jsonFile)) {
            error_log("JSON file not found: $jsonFile");
            return false;
        }
        
        $jsonContent = file_get_contents($jsonFile);
        if ($jsonContent === false) {
            error_log("Failed to read JSON file: $jsonFile");
            return false;
        }
        
        $jsonData = json_decode($jsonContent, true);
        if ($jsonData === null) {
            error_log("Failed to parse JSON file: $jsonFile");
            return false;
        }
        
        // Search for component model in the JSON structure based on component specifications
        return searchComponentSpecInJsonData($jsonData, $componentNotes, $serialNumber, $componentType, $componentUuid);
        
    } catch (Exception $e) {
        error_log("Error validating component existence in JSON: " . $e->getMessage());
        return false;
    }
}

/**
 * Get component details by UUID from database
 */
function getComponentDetailsByUuid($pdo, $componentType, $componentUuid) {
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
        error_log("Error getting component details by UUID: " . $e->getMessage());
        return null;
    }
}

/**
 * Search for component specifications in JSON data structure
 * FIXED: This searches by UUID first, then falls back to model matching
 */
function searchComponentSpecInJsonData($jsonData, $componentNotes, $serialNumber, $componentType, $componentUuid = null) {
    try {
        $componentNotes = strtolower($componentNotes);
        
        if ($componentType === 'cpu') {
            // Search CPU JSON structure
            foreach ($jsonData as $brand) {
                if (isset($brand['models'])) {
                    foreach ($brand['models'] as $model) {
                        // FIXED: First check for exact UUID match
                        if (isset($model['UUID']) && $model['UUID'] === $componentUuid) {
                            return true;
                        }
                        
                        $modelName = strtolower($model['model'] ?? '');
                        
                        // Check if component notes contain this model name
                        if (!empty($modelName) && strpos($componentNotes, $modelName) !== false) {
                            return true;
                        }
                        
                        // Also check against serial number pattern matching
                        if (!empty($serialNumber) && isset($model['inventory']['serial_numbers'])) {
                            if (in_array($serialNumber, $model['inventory']['serial_numbers'])) {
                                return true;
                            }
                        }
                    }
                }
                
                // Check family level models if exists
                if (isset($brand['family']) && is_array($brand['family'])) {
                    foreach ($brand['family'] as $family) {
                        if (isset($family['models'])) {
                            foreach ($family['models'] as $model) {
                                // Check UUID at family level too
                                if (isset($model['UUID']) && $model['UUID'] === $componentUuid) {
                                    return true;
                                }
                                
                                $modelName = strtolower($model['model'] ?? '');
                                if (!empty($modelName) && strpos($componentNotes, $modelName) !== false) {
                                    return true;
                                }
                            }
                        }
                    }
                }
                
                // Check series level
                if (isset($brand['series'])) {
                    $seriesName = strtolower($brand['series']);
                    if (!empty($seriesName) && strpos($componentNotes, $seriesName) !== false) {
                        return true;
                    }
                }
            }
        } elseif ($componentType === 'motherboard') {
            // Search motherboard JSON structure
            foreach ($jsonData as $brand) {
                if (isset($brand['models'])) {
                    foreach ($brand['models'] as $model) {
                        // Check for UUID match in motherboard
                        if (isset($model['UUID']) && $model['UUID'] === $componentUuid) {
                            return true;
                        }
                        
                        $modelName = strtolower($model['model'] ?? '');
                        if (!empty($modelName) && strpos($componentNotes, $modelName) !== false) {
                            return true;
                        }
                    }
                }
                
                // Check family level
                if (isset($brand['family']) && is_array($brand['family'])) {
                    foreach ($brand['family'] as $family) {
                        if (isset($family['models'])) {
                            foreach ($family['models'] as $model) {
                                if (isset($model['UUID']) && $model['UUID'] === $componentUuid) {
                                    return true;
                                }
                                
                                $modelName = strtolower($model['model'] ?? '');
                                if (!empty($modelName) && strpos($componentNotes, $modelName) !== false) {
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
        } elseif ($componentType === 'ram') {
            // Search RAM JSON structure
            foreach ($jsonData as $brand) {
                if (isset($brand['models'])) {
                    foreach ($brand['models'] as $model) {
                        // Check for UUID match in RAM
                        if (isset($model['UUID']) && $model['UUID'] === $componentUuid) {
                            return true;
                        }
                        
                        $modelName = strtolower($model['model'] ?? '');
                        if (!empty($modelName) && strpos($componentNotes, $modelName) !== false) {
                            return true;
                        }
                    }
                }
            }
        }
        
        // FIXED: If we reach here, component was NOT found in JSON specifications
        // Return false to properly indicate component doesn't exist in specs
        return false;
        
    } catch (Exception $e) {
        error_log("Error searching component specification in JSON data: " . $e->getMessage());
        // Return false when there's an error to be safe
        return false;
    }
}

?>