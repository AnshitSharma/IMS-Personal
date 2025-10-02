<?php
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/BaseFunctions.php';
require_once __DIR__ . '/../../includes/models/ServerBuilder.php';
require_once __DIR__ . '/../../includes/models/ServerConfiguration.php';
require_once __DIR__ . '/../../includes/models/CompatibilityEngine.php';


header('Content-Type: application/json');

// Initialize database connection and authentication
global $pdo;
if (!$pdo) {
    require_once __DIR__ . '/../../includes/db_config.php';
}

$user = authenticateWithJWT($pdo);

if (!$user) {
    send_json_response(0, 0, 401, "Authentication required");
}

// Initialize ServerBuilder with enhanced error handling
try {
    if (!$pdo) {
        throw new Exception("Database connection not available");
    }
    $serverBuilder = new ServerBuilder($pdo);
    error_log("ServerBuilder initialized successfully");
} catch (Exception $e) {
    error_log("Failed to initialize ServerBuilder: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    send_json_response(0, 1, 500, "Server system unavailable: " . $e->getMessage());
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
        // Validate motherboard exists and is available in database
        $motherboardDetails = getComponentDetails($pdo, 'motherboard', $motherboardUuid);
        if (!$motherboardDetails) {
            send_json_response(0, 1, 404, "Motherboard not found in inventory database", [
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
    
    // Enhanced logging for debugging
    error_log("=== SERVER ADD COMPONENT REQUEST ===");
    error_log("POST data: " . json_encode($_POST));
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? '';
    $quantity = (int)($_POST['quantity'] ?? 1);
    $slotPosition = $_POST['slot_position'] ?? null;
    $notes = $_POST['notes'] ?? '';
    $override = filter_var($_POST['override'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    error_log("Parsed parameters - Config: $configUuid, Type: $componentType, UUID: $componentUuid, Qty: $quantity");
    
    if (empty($configUuid) || empty($componentType) || empty($componentUuid)) {
        error_log("Missing required parameters");
        send_json_response(0, 1, 400, "Configuration UUID, component type, and component UUID are required");
    }

    // Basic component type validation
    $validComponentTypes = ['chassis', 'cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy', 'pciecard'];
    if (!in_array($componentType, $validComponentTypes)) {
        send_json_response(0, 1, 400, "Invalid component type. Valid types: " . implode(', ', $validComponentTypes));
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
        
        // EXISTING: Component existence validation (MODIFY to use new function)
        $componentValidation = validateComponentExists($componentType, $componentUuid);
        if (!$componentValidation['exists']) {
            send_json_response(0, 1, 404, $componentValidation['message'], [
                'component_type' => $componentType,
                'component_uuid' => $componentUuid
            ]);
        }

        if (!$componentValidation['available']) {
            send_json_response(0, 1, 400, "Component is not available", [
                'component_status' => $componentValidation['component']['Status'],
                'component_type' => $componentType,
                'component_uuid' => $componentUuid
            ]);
        }

        // Get component details for legacy compatibility
        $componentDetails = $componentValidation['component'];
        
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
            
        // ENHANCED: Step 1 - Comprehensive validation before component addition
        try {
            // Check if ComponentCompatibility class exists before loading
            if (file_exists(__DIR__ . '/../../includes/models/ComponentCompatibility.php')) {
                require_once __DIR__ . '/../../includes/models/ComponentCompatibility.php';
                $compatibility = new ComponentCompatibility($pdo);
                
                // Phase 1: JSON existence validation (optional - don't fail if JSON missing)
                try {
                    $existsInJSON = $compatibility->validateComponentExistsInJSON($componentType, $componentUuid);
                    if (!$existsInJSON) {
                        error_log("WARNING: Component $componentUuid not found in $componentType JSON specifications");
                        // Don't fail here, just log warning
                    }
                } catch (Exception $jsonError) {
                    error_log("JSON validation error (non-critical): " . $jsonError->getMessage());
                    // Continue without JSON validation
                }
                
                // Phase 2: Component-specific enhanced validation
                // Phase 2: Flexible validation - components can be added in any order
                // All compatibility checking is now handled by ServerBuilder's flexible system
            } else {
                error_log("ComponentCompatibility.php not found - skipping enhanced validation");
                // Continue without enhanced validation
            }
            
        } catch (Exception $e) {
            error_log("Enhanced validation error (non-critical): " . $e->getMessage());
            // Don't fail the entire operation for validation errors - continue with basic validation
        }

        // NEW: PCIe slot validation for PCIe cards and NICs
        $assignedSlot = null;
        if ($componentType === 'pciecard' || $componentType === 'nic') {
            try {
                require_once __DIR__ . '/../../includes/models/PCIeSlotTracker.php';
                require_once __DIR__ . '/../../includes/models/ComponentDataService.php';

                $slotTracker = new PCIeSlotTracker($pdo);

                // Step 1: Check if motherboard exists in configuration
                $stmt = $pdo->prepare("
                    SELECT component_uuid
                    FROM server_configuration_components
                    WHERE config_uuid = ? AND component_type = 'motherboard'
                    LIMIT 1
                ");
                $stmt->execute([$configUuid]);
                $motherboardResult = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$motherboardResult) {
                    send_json_response(0, 1, 400,
                        "Cannot add PCIe card/NIC: No motherboard in configuration. Add motherboard first.",
                        [
                            'component_type' => $componentType,
                            'component_uuid' => $componentUuid,
                            'recommendation' => 'Add a motherboard to the configuration before adding PCIe cards or NICs'
                        ]
                    );
                }

                // Step 2: Get component specifications to determine slot size
                try {
                    $componentDataService = ComponentDataService::getInstance();
                    $cardSpecs = $componentDataService->getComponentSpecifications($componentType, $componentUuid, $componentDetails);
                } catch (Exception $specError) {
                    error_log("WARNING: Error loading PCIe card specs: " . $specError->getMessage());
                    $cardSpecs = null;
                }

                if (!$cardSpecs) {
                    error_log("WARNING: Could not load PCIe card specs for $componentUuid - proceeding without slot validation");
                    // Continue without slot validation if specs not found
                } else {
                    // Step 3: Extract PCIe slot size from specifications
                    $cardSlotSize = extractPCIeSlotSizeFromSpecs($cardSpecs);

                    if (!$cardSlotSize) {
                        error_log("WARNING: Could not determine PCIe slot size for $componentUuid - proceeding without slot validation");
                    } else {
                        error_log("PCIe card $componentUuid requires $cardSlotSize slot");

                        // Step 4: Check if card can fit in available slots
                        if (!$slotTracker->canFitCard($configUuid, $cardSlotSize)) {
                            $availability = $slotTracker->getSlotAvailability($configUuid);

                            send_json_response(0, 1, 400,
                                "No available PCIe slots for $cardSlotSize card",
                                [
                                    'component_type' => $componentType,
                                    'component_uuid' => $componentUuid,
                                    'required_slot' => $cardSlotSize,
                                    'available_slots' => $availability['available_slots'],
                                    'used_slots' => array_keys($availability['used_slots']),
                                    'total_slots' => $availability['total_slots'],
                                    'recommendation' => "Remove existing PCIe card/NIC or use a component with different slot size"
                                ]
                            );
                        }

                        // Step 5: Assign optimal slot (smallest compatible slot first)
                        $assignedSlot = $slotTracker->assignSlot($configUuid, $cardSlotSize);

                        if (!$assignedSlot) {
                            send_json_response(0, 1, 500, "Failed to assign PCIe slot", [
                                'component_uuid' => $componentUuid,
                                'error' => 'Slot assignment failed unexpectedly'
                            ]);
                        }

                        // Override slot_position with auto-assigned slot
                        $slotPosition = $assignedSlot;

                        error_log("Assigned PCIe card $componentUuid to slot: $assignedSlot");
                    }
                }

            } catch (Exception $pcieError) {
                error_log("PCIe slot validation error: " . $pcieError->getMessage());
                // Log error but continue - don't fail on validation errors
                error_log("Continuing without PCIe slot validation due to error");
            }
        }

        // Use original component addition method
        $result = $serverBuilder->addComponent($configUuid, $componentType, $componentUuid, [
            'quantity' => $quantity,
            'slot_position' => $slotPosition,
            'notes' => $notes,
            'override_used' => $override
        ]);
        
        if ($result['success']) {
            // Simple success response - avoid complex operations that could fail
            error_log("Component added successfully: $componentType/$componentUuid to config $configUuid");
            
            // Build response data
            $responseData = [
                'component_added' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'status_override_used' => $override,
                    'original_status' => $statusMessage ?? 'Unknown',
                    'server_uuid_updated' => $configUuid
                ],
                'timestamp' => date('Y-m-d H:i:s')
            ];

            // Add PCIe slot assignment info if applicable
            if ($assignedSlot && ($componentType === 'pciecard' || $componentType === 'nic')) {
                $responseData['pcie_slot_assignment'] = [
                    'slot_assigned' => $assignedSlot,
                    'slot_type' => extractSlotTypeFromSlotId($assignedSlot)
                ];

                // Get updated slot availability
                if (isset($slotTracker)) {
                    $updatedAvailability = $slotTracker->getSlotAvailability($configUuid);
                    if ($updatedAvailability['success']) {
                        $responseData['pcie_slot_assignment']['remaining_slots'] = $updatedAvailability['available_slots'];
                    }
                }
            }
            
            // Enhanced RAM compatibility response structure as specified in Important-fix
            if ($componentType === 'ram' && isset($result['warnings']) && isset($result['compatibility_details'])) {
                $compatibilityDetails = $result['compatibility_details'];
                $frequencyAnalysis = $compatibilityDetails['frequency_analysis'] ?? [];
                
                $responseData['ram_compatibility'] = [
                    'memory_type' => [
                        'compatible' => $compatibilityDetails['memory_type']['compatible'] ?? true,
                        'message' => $compatibilityDetails['memory_type']['message'] ?? 'Memory type compatible'
                    ],
                    'frequency_analysis' => [
                        'ram_frequency' => $frequencyAnalysis['ram_frequency'] ?? 0,
                        'system_max_frequency' => $frequencyAnalysis['system_max_frequency'] ?? 0,
                        'effective_frequency' => $frequencyAnalysis['effective_frequency'] ?? 0,
                        'limiting_component' => $frequencyAnalysis['limiting_component'] ?? null,
                        'status' => $frequencyAnalysis['status'] ?? 'unknown',
                        'performance_impact' => $frequencyAnalysis['performance_impact'] ?? null
                    ],
                    'form_factor' => [
                        'compatible' => $compatibilityDetails['form_factor']['compatible'] ?? true,
                        'message' => $compatibilityDetails['form_factor']['message'] ?? 'Form factor compatible'
                    ],
                    'ecc_support' => [
                        'compatible' => $compatibilityDetails['ecc_support']['compatible'] ?? true,
                        'message' => $compatibilityDetails['ecc_support']['message'] ?? 'ECC configuration validated',
                        'warning' => $compatibilityDetails['ecc_support']['warning'] ?? null,
                        'recommendation' => $compatibilityDetails['ecc_support']['recommendation'] ?? null
                    ],
                    'slot_availability' => [
                        'available_slots' => $compatibilityDetails['slot_availability']['available_slots'] ?? 0,
                        'max_slots' => $compatibilityDetails['slot_availability']['max_slots'] ?? 4,
                        'used_slots' => $compatibilityDetails['slot_availability']['used_slots'] ?? 0,
                        'can_add_more' => $compatibilityDetails['slot_availability']['can_add'] ?? false
                    ]
                ];
                
                // Include performance warnings in main response
                if (!empty($result['warnings'])) {
                    $responseData['performance_warnings'] = $result['warnings'];
                }
                
                // Show effective operating frequency in component specifications
                if ($frequencyAnalysis['effective_frequency'] !== $frequencyAnalysis['ram_frequency']) {
                    $responseData['component_added']['effective_operating_frequency'] = $frequencyAnalysis['effective_frequency'] . 'MHz';
                    $responseData['component_added']['rated_frequency'] = $frequencyAnalysis['ram_frequency'] . 'MHz';
                }
            }
            
            send_json_response(1, 1, 200, "Component added successfully", $responseData);
        } else {
            // ENHANCED ERROR RESPONSES with detailed categorization
            $errorType = $result['error_type'] ?? 'unknown';
            $errorMessage = $result['message'] ?? "Failed to add component";
            
            $enhancedErrorDetails = [
                'error_type' => $errorType,
                'component_type' => $componentType,
                'component_uuid' => $componentUuid,
                'error_category' => categorizeError($errorType),
                'compatibility_details' => $result['details'] ?? null,
                'suggested_alternatives' => getSuggestedAlternatives($pdo, $componentType, $componentUuid),
                'recovery_options' => generateRecoveryOptions($errorType, $componentType),
                'system_context' => [
                    'configuration_uuid' => $configUuid,
                    'validation_timestamp' => date('Y-m-d H:i:s'),
                    'user_id' => $user['id']
                ]
            ];
            
            // Add specific error details based on error type
            switch ($errorType) {
                case 'json_not_found':
                    $enhancedErrorDetails['suggested_action'] = 'Verify component exists in JSON specifications ';
                    $enhancedErrorDetails['technical_details'] = 'Component UUID not found in corresponding JSON file';
                    break;
                    
                case 'socket_mismatch':
                    $enhancedErrorDetails['suggested_action'] = 'Use components with matching socket types';
                    $enhancedErrorDetails['technical_details'] = 'CPU and motherboard socket types are incompatible';
                    break;
                    
                case 'cpu_limit_exceeded':
                    $enhancedErrorDetails['suggested_action'] = 'Remove existing CPU or use multi-socket motherboard';
                    $enhancedErrorDetails['technical_details'] = 'Motherboard CPU socket limit reached';
                    break;
                    
                case 'duplicate_component':
                    $enhancedErrorDetails['suggested_action'] = 'Component already exists in this configuration';
                    $enhancedErrorDetails['technical_details'] = 'Duplicate component UUID detected';
                    break;
                    
                case 'compatibility_failure':
                    $enhancedErrorDetails['suggested_action'] = 'Review component compatibility requirements';
                    $enhancedErrorDetails['technical_details'] = 'Component failed compatibility validation checks';
                    break;
                    
                default:
                    $enhancedErrorDetails['suggested_action'] = 'Review component specifications and try again';
                    $enhancedErrorDetails['technical_details'] = 'Component addition failed validation';
            }
            
            send_json_response(0, 1, 400, $errorMessage, $enhancedErrorDetails);
        }
        
    } catch (Exception $e) {
        error_log("Error adding component: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        error_log("Component details: Type=$componentType, UUID=$componentUuid, ConfigUUID=$configUuid");
        
        // Send detailed error for debugging instead of generic 500
        send_json_response(0, 1, 500, "Internal server error: " . $e->getMessage(), [
            'error_type' => 'exception_thrown',
            'component_type' => $componentType,
            'component_uuid' => $componentUuid,
            'config_uuid' => $configUuid,
            'error_details' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'debug_info' => [
                'php_version' => PHP_VERSION,
                'timestamp' => date('Y-m-d H:i:s'),
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
            ]
        ]);
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
            send_json_response(1, 1, 200, "Component removed successfully", [
                'component_removed' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid,
                    'server_uuid_cleared' => true
                ]
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
        
        // Use stored compatibility score and validation results from database
        $configuration = $details['configuration'];
        $compatibilityScore = $configuration['compatibility_score'];
        $validationResults = $configuration['validation_results'] ?? [];
        $individualComponentChecks = []; // Simplified - no individual component checks
        // Simple basic validation checks using stored data only
        $configurationValid = $compatibilityScore !== null && $compatibilityScore > 0;
            
        
        // Simplified configuration data - use stored values from database
        $configuration['power_consumption'] = $details['power_consumption']['total_with_overhead_watts'] ?? 0;
        $configuration['compatibility_score'] = $compatibilityScore;
        
        
        send_json_response(1, 1, 200, "Configuration retrieved successfully", [
            'configuration' => [
                'config_uuid' => $configuration['config_uuid'],
                'server_name' => $configuration['server_name'],
                'description' => $configuration['description'] ?? '',
                'configuration_status' => $configuration['configuration_status'],
                'compatibility_score' => $compatibilityScore,
                'power_consumption' => $configuration['power_consumption'],
                'created_at' => $configuration['created_at'],
                'location' => $configuration['location'] ?? '',
                'components' => $details['components'] ?? []
            ],
            'summary' => [
                'total_components' => $details['total_components'],
                'component_counts' => $details['component_counts'],
                'power_consumption' => $details['power_consumption']
            ],
            'status' => [
                'compatibility_score' => $compatibilityScore,
                'configuration_valid' => $configurationValid,
                'last_validation' => $configuration['updated_at'] ?? $configuration['created_at']
            ]
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

    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    $componentType = $_GET['component_type'] ?? $_POST['component_type'] ?? '';
    $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $limit = (int)($_GET['limit'] ?? 50);


    if (empty($componentType)) {
        send_json_response(0, 1, 400, "Component type is required");
    }

    try {
        // Continue with existing component listing logic
        $components = getAvailableComponents($pdo, $componentType, $availableOnly, $limit);
        $count = getComponentCount($pdo, $componentType);

        $responseData = [
            'component_type' => $componentType,
            'components' => $components,
            'counts' => $count,
            'available_only' => $availableOnly,
            'total_returned' => count($components)
        ];

        // Add configuration context if provided
        if ($configUuid) {
            $responseData['configuration_summary'] = $configSummary;
            $responseData['allowed_types'] = $allowedTypes;
        }

        send_json_response(1, 1, 200, "Available components retrieved successfully", $responseData);

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

        // NEW: Add PCIe slot validation
        try {
            require_once __DIR__ . '/../../includes/models/PCIeSlotTracker.php';
            $slotTracker = new PCIeSlotTracker($pdo);

            $pcieValidation = $slotTracker->validateAllSlots($configUuid);

            // Add PCIe slot validation to the overall validation results
            $validation['pcie_slots'] = $pcieValidation;

            // If PCIe validation failed, mark overall validation as failed
            if (!$pcieValidation['valid']) {
                $validation['valid'] = false;
                if (!isset($validation['errors'])) {
                    $validation['errors'] = [];
                }
                $validation['errors'] = array_merge(
                    $validation['errors'],
                    $pcieValidation['errors']
                );
            }

        } catch (Exception $pcieError) {
            error_log("PCIe slot validation error: " . $pcieError->getMessage());
            // Add warning but don't fail entire validation
            if (!isset($validation['warnings'])) {
                $validation['warnings'] = [];
            }
            $validation['warnings'][] = "PCIe slot validation could not be completed: " . $pcieError->getMessage();
        }

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
 * Get compatible components for a server configuration - ENHANCED Implementation per Important-fix requirements
 * This endpoint finds components compatible with an existing server configuration's motherboard
 */
function handleGetCompatible($serverBuilder, $user) {
    global $pdo;
    
    // Get parameters with exact names from Important-fix specification
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    $componentType = $_GET['component_type'] ?? $_POST['component_type'] ?? '';
    $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    // Validate required parameters
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    if (empty($componentType)) {
        send_json_response(0, 1, 400, "Component type is required");
    }
    
    // Validate component type
    $validComponentTypes = ['chassis', 'cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy', 'pciecard'];
    if (!in_array($componentType, $validComponentTypes)) {
        send_json_response(0, 1, 400, "Invalid component type. Must be one of: " . implode(', ', $validComponentTypes));
    }
    
    try {
        // Step 1: Validate server configuration exists and belongs to user
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to view this configuration");
        }
        
        // Step 2: Get existing components in configuration for flexible compatibility checking
        $stmt = $pdo->prepare("
            SELECT component_type, component_uuid
            FROM server_configuration_components
            WHERE config_uuid = ?
        ");
        $stmt->execute([$configUuid]);
        $existingComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // If no existing components, show all available components of requested type
        if (empty($existingComponents)) {
            error_log("No existing components found, showing all available components of type: $componentType");
        }
        
        // Process existing components for compatibility checking
        $existingComponentsData = [];
        foreach ($existingComponents as $existing) {
            $tableMap = [
                'chassis' => 'chassisinventory',
                'cpu' => 'cpuinventory',
                'ram' => 'raminventory',
                'storage' => 'storageinventory',
                'motherboard' => 'motherboardinventory',
                'nic' => 'nicinventory',
                'caddy' => 'caddyinventory',
                'pciecard' => 'pciecardinventory'
            ];

            $table = $tableMap[$existing['component_type']];
            $stmt = $pdo->prepare("SELECT * FROM $table WHERE UUID = ? LIMIT 1");
            $stmt->execute([$existing['component_uuid']]);
            $componentData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($componentData) {
                $existingComponentsData[] = [
                    'type' => $existing['component_type'],
                    'uuid' => $existing['component_uuid'],
                    'data' => $componentData
                ];
            }
        }
        
        // Step 3: Get all components of requested type with availability filtering
        $tableMap = [
            'chassis' => 'chassisinventory',
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory',
            'pciecard' => 'pciecardinventory'
        ];
        
        $table = $tableMap[$componentType];
        $whereClause = $availableOnly ? "WHERE Status = 1" : "WHERE Status IN (1, 2)";
        
        // Get components with optimized query (limit to 100 for performance)
        $stmt = $pdo->prepare("
            SELECT UUID, SerialNumber, Status, Location, Notes, ServerUUID
            FROM $table 
            $whereClause 
            ORDER BY Status ASC, SerialNumber ASC 
            LIMIT 100
        ");
        $stmt->execute();
        $allComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Step 4: Run compatibility checks using JSON integration and proper socket checking
        $compatibleComponents = [];
        
        // Always try to load the ComponentCompatibility class first
        $compatibilityClassFile = __DIR__ . '/../../includes/models/ComponentCompatibility.php';
        if (file_exists($compatibilityClassFile)) {
            require_once $compatibilityClassFile;
            error_log("DEBUG: ComponentCompatibility.php loaded successfully");
        } else {
            error_log("ERROR: ComponentCompatibility.php not found at: $compatibilityClassFile");
        }
        
        if (class_exists('ComponentCompatibility')) {
            $compatibility = new ComponentCompatibility($pdo);
            error_log("DEBUG: ComponentCompatibility class instantiated successfully");
            
            // Enhanced compatibility checking with flexible component support
            error_log("DEBUG: Starting flexible compatibility checking for $componentType components");
            error_log("DEBUG: Checking against " . count($existingComponentsData) . " existing components");

            foreach ($allComponents as $component) {
                $isCompatible = true;
                $compatibilityScore = 1.0;
                $compatibilityReasons = [];

                // If no existing components, all components are compatible
                if (empty($existingComponentsData)) {
                    $isCompatible = true;
                    $compatibilityReasons[] = "No existing components - all components available";
                } else {
                    // Use specialized RAM compatibility checking for better accuracy
                    if ($componentType === 'ram') {
                        $ramCompatResult = $compatibility->checkRAMDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        $isCompatible = $ramCompatResult['compatible'];
                        $compatibilityScore = $ramCompatResult['compatibility_score'];
                        $compatibilityReasons = array_merge(
                            $ramCompatResult['details'] ?? [],
                            $ramCompatResult['warnings'] ?? [],
                            $ramCompatResult['recommendations'] ?? []
                        );
                    } elseif ($componentType === 'cpu') {
                        // Use specialized CPU compatibility checking for better accuracy
                        $cpuCompatResult = $compatibility->checkCPUDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        $isCompatible = $cpuCompatResult['compatible'];
                        $compatibilityScore = $cpuCompatResult['compatibility_score'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$cpuCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                    } elseif ($componentType === 'motherboard') {
                        // Use specialized motherboard compatibility checking for better accuracy
                        $motherboardCompatResult = $compatibility->checkMotherboardDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        $isCompatible = $motherboardCompatResult['compatible'];
                        $compatibilityScore = $motherboardCompatResult['compatibility_score'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$motherboardCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                    } elseif ($componentType === 'storage') {
                        // Use specialized storage compatibility checking for better accuracy
                        $storageCompatResult = $compatibility->checkStorageDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        $isCompatible = $storageCompatResult['compatible'];
                        $compatibilityScore = $storageCompatResult['compatibility_score'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$storageCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                    } elseif ($componentType === 'chassis') {
                        // Use specialized chassis compatibility checking for better accuracy
                        $chassisCompatResult = $compatibility->checkChassisDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        $isCompatible = $chassisCompatResult['compatible'];
                        $compatibilityScore = $chassisCompatResult['compatibility_score'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$chassisCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                    } elseif ($componentType === 'pciecard') {
                        // Use specialized PCIe card compatibility checking
                        $pcieCompatResult = $compatibility->checkPCIeDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData
                        );
                        $isCompatible = $pcieCompatResult['compatible'];
                        $compatibilityScore = $pcieCompatResult['compatibility_score'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$pcieCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                    } elseif ($componentType === 'nic') {
                        // Use specialized PCIe compatibility checking for NICs
                        // NICs are treated as PCIe devices when checking slot availability
                        $nicCompatResult = $compatibility->checkPCIeDecentralizedCompatibility(
                            ['uuid' => $component['UUID']], $existingComponentsData, 'nic'
                        );
                        $isCompatible = $nicCompatResult['compatible'];
                        $compatibilityScore = $nicCompatResult['compatibility_score'];
                        // Use concise compatibility summary instead of verbose details
                        $compatibilityReasons = [$nicCompatResult['compatibility_summary'] ?? 'Compatibility check completed'];
                    } else {
                        // Check compatibility with each existing component for other types
                        foreach ($existingComponentsData as $existingComp) {
                            $newComponent = ['type' => $componentType, 'uuid' => $component['UUID']];
                            $existingComponent = ['type' => $existingComp['type'], 'uuid' => $existingComp['uuid']];

                            $compatResult = $compatibility->checkComponentPairCompatibility($newComponent, $existingComponent);

                            if (!$compatResult['compatible']) {
                                $isCompatible = false;
                                $compatibilityReasons[] = "Incompatible with " . $existingComp['type'] . ": " .
                                                         implode(', ', $compatResult['issues'] ?? []);
                                break;
                            } else {
                                $compatibilityReasons[] = "Compatible with " . $existingComp['type'];
                                $compatibilityScore = min($compatibilityScore, $compatResult['compatibility_score'] ?? 1.0);
                            }
                        }
                    }
                }

                // Include component with compatibility information
                $compatibleComponent = [
                    'uuid' => $component['UUID'],
                    'serial_number' => $component['SerialNumber'],
                    'status' => (int)$component['Status'],
                    'location' => $component['Location'],
                    'notes' => $component['Notes'],
                    'compatibility_score' => $compatibilityScore,
                    'compatibility_reason' => implode('; ', $compatibilityReasons),
                    'is_compatible' => $isCompatible
                ];

                // Always add components to show compatibility details
                $compatibleComponents[] = $compatibleComponent;
            }
        } else {
            // Fallback: Simplified compatibility checking without motherboard dependency
            error_log("WARNING: ComponentCompatibility class not available, using simplified fallback");
            error_log("DEBUG: Class exists check: " . (class_exists('ComponentCompatibility') ? 'true' : 'false'));
            error_log("DEBUG: File exists check: " . (file_exists($compatibilityClassFile) ? 'true' : 'false'));

            foreach ($allComponents as $component) {
                $isCompatible = true;
                $compatibilityScore = 1.0;
                $compatibilityReason = "Basic compatibility check passed";

                // If no existing components, all components are compatible
                if (empty($existingComponentsData)) {
                    $compatibilityReason = "No existing components - all components available";
                } else {
                    // Basic compatibility logic without motherboard dependency
                    $compatibilityReason = "Compatible based on basic component validation";

                    // For RAM compatibility with existing components
                    if ($componentType === 'ram' && !empty($existingComponentsData)) {
                        $ramCompatibilityResult = checkRAMCompatibilityWithExistingComponents(
                            $component, $existingComponentsData, $pdo
                        );
                        $isCompatible = $ramCompatibilityResult['compatible'];
                        $compatibilityScore = $ramCompatibilityResult['score'];
                        $compatibilityReason = $ramCompatibilityResult['reason'];
                    }
                }

                $compatibleComponent = [
                    'uuid' => $component['UUID'],
                    'serial_number' => $component['SerialNumber'],
                    'status' => (int)$component['Status'],
                    'location' => $component['Location'],
                    'notes' => $component['Notes'],
                    'compatibility_score' => $compatibilityScore,
                    'compatibility_reason' => $compatibilityReason,
                    'is_compatible' => $isCompatible
                ];

                // Always add components to show compatibility details
                $compatibleComponents[] = $compatibleComponent;
            }
        }
        
        // Step 5: Build response without base_motherboard dependency
        $compatibleOnly = array_filter($compatibleComponents, function($comp) { return $comp['is_compatible']; });
        $incompatibleOnly = array_filter($compatibleComponents, function($comp) { return !$comp['is_compatible']; });

        $responseData = [
            'config_uuid' => $configUuid,
            'component_type' => $componentType,
            'compatible_components' => array_values($compatibleOnly),
            'incompatible_components' => array_values($incompatibleOnly),
            'total_compatible' => count($compatibleOnly),
            'total_incompatible' => count($incompatibleOnly),
            'total_found' => count($compatibleComponents),
            'filters_applied' => [
                'available_only' => $availableOnly,
                'component_type' => $componentType
            ],
            'existing_components_summary' => [
                'total_existing' => count($existingComponentsData),
                'types' => array_unique(array_column($existingComponentsData, 'type'))
            ],
            'compatibility_summary' => [
                'has_compatible' => count($compatibleOnly) > 0,
                'has_incompatible' => count($incompatibleOnly) > 0,
                'main_issues' => count($incompatibleOnly) > 0 ?
                    array_slice(array_unique(array_column($incompatibleOnly, 'compatibility_reason')), 0, 3) : []
            ]
        ];
        
        // Determine appropriate message and response
        if (count($compatibleOnly) > 0) {
            $message = "Compatible components found";
        } else if (count($incompatibleOnly) > 0) {
            $message = "No compatible components found - see incompatibility reasons";
            // Don't show incompatible components list, just the summary
            $responseData['incompatible_components'] = [];
            $responseData['incompatibility_summary'] = [
                'total_checked' => count($incompatibleOnly),
                'main_reasons' => array_slice(array_unique(array_column($incompatibleOnly, 'compatibility_reason')), 0, 3),
                'common_issues' => analyzeCommonCompatibilityIssues($incompatibleOnly),
                'suggestions' => generateCompatibilitySuggestions($componentType, $existingComponentsData)
            ];
        } else {
            $message = "No components found matching criteria";
        }

        send_json_response(1, 1, 200, $message, [
            'data' => $responseData
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting compatible components: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatible components: " . $e->getMessage());
    }
}

// Enhanced Helper Functions for Error Handling and Component Management

/**
 * Get motherboard specifications from configuration for validation
 */
function getMotherboardSpecsFromConfig($pdo, $configUuid) {
    try {
        // Get motherboard from configuration - Fixed collation issue by using simple query
        $stmt = $pdo->prepare("
            SELECT component_uuid FROM server_configuration_components 
            WHERE config_uuid = ? AND component_type = 'motherboard'
            LIMIT 1
        ");
        $stmt->execute([$configUuid]);
        $motherboard = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$motherboard) {
            return null;
        }
        
        // Initialize compatibility engine
        require_once __DIR__ . '/../../includes/models/ComponentCompatibility.php';
        $compatibility = new ComponentCompatibility($pdo);
        
        // Get motherboard limits
        $limitsResult = $compatibility->getMotherboardLimits($motherboard['component_uuid']);
        return $limitsResult['found'] ? $limitsResult['limits'] : null;
        
    } catch (Exception $e) {
        error_log("Error getting motherboard specs: " . $e->getMessage());
        return null;
    }
}

/**
 * Categorize error types for better error handling
 */
function categorizeError($errorType) {
    $errorCategories = [
        'json_not_found' => 'data_integrity',
        'json_data_not_found' => 'data_integrity',
        'socket_mismatch' => 'compatibility',
        'cpu_limit_exceeded' => 'hardware_limitation',
        'duplicate_component' => 'configuration_conflict',
        'motherboard_required' => 'dependency_missing',
        'compatibility_failure' => 'compatibility',
        'validation_system_error' => 'system_error',
        'unknown' => 'general_error'
    ];
    
    return $errorCategories[$errorType] ?? 'general_error';
}

/**
 * Generate recovery options based on error type and component type
 */
function generateRecoveryOptions($errorType, $componentType) {
    $recoveryOptions = [];
    
    switch ($errorType) {
        case 'json_not_found':
        case 'json_data_not_found':
            $recoveryOptions = [
                'Verify the component UUID is correct',
                'Check if component exists in the JSON specifications database',
                'Contact administrator to add missing component data',
                'Try using a different ' . $componentType . ' component'
            ];
            break;
            
        case 'socket_mismatch':
            if ($componentType === 'cpu') {
                $recoveryOptions = [
                    'Select a CPU with matching socket type',
                    'Choose a different motherboard with compatible socket',
                    'Verify socket specifications in component database',
                    'Contact support for socket compatibility information'
                ];
            } else {
                $recoveryOptions = [
                    'Select compatible components with matching specifications',
                    'Review component compatibility requirements',
                    'Choose alternative components from the same family'
                ];
            }
            break;
            
        case 'cpu_limit_exceeded':
            $recoveryOptions = [
                'Remove one of the existing CPUs from the configuration',
                'Use a motherboard that supports multiple CPU sockets',
                'Consider a single more powerful CPU instead of multiple CPUs',
                'Review motherboard specifications for socket count'
            ];
            break;
            
        case 'duplicate_component':
            $recoveryOptions = [
                'Component is already added - no action needed',
                'Remove the existing component first if replacement is intended',
                'Check configuration to see currently added components',
                'Use quantity parameter if multiple units are needed'
            ];
            break;
            
        case 'motherboard_required':
            $recoveryOptions = [
                'Add a motherboard to the configuration first',
                'Select a motherboard compatible with the desired CPU',
                'Review available motherboards in inventory',
                'Ensure motherboard supports the CPU socket type'
            ];
            break;
            
        case 'compatibility_failure':
            $recoveryOptions = [
                'Review component specifications for compatibility',
                'Choose components from the same generation or family',
                'Verify memory type, socket type, and interface compatibility',
                'Use the compatibility checker to find suitable alternatives'
            ];
            break;
            
        default:
            $recoveryOptions = [
                'Review component specifications and requirements',
                'Try selecting a different ' . $componentType . ' component',
                'Check system logs for more detailed error information',
                'Contact system administrator if problem persists'
            ];
    }
    
    return $recoveryOptions;
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
 * Enhanced component compatibility checking using JSON integration and proper socket validation
 * This uses the existing ComponentCompatibility class methods to provide accurate compatibility scores
 */
function checkEnhancedComponentCompatibility($compatibility, $componentType, $component, $motherboard) {
    try {
        error_log("DEBUG: Checking compatibility for $componentType UUID: " . $component['UUID']);
        
        // Extract socket types using the existing JSON integration methods
        $componentSocket = null;
        $motherboardSocket = null;
        
        try {
            $componentSocket = $compatibility->extractSocketTypeFromJSON($componentType, $component['UUID']);
            error_log("DEBUG: Component socket extracted: " . ($componentSocket ?? 'null'));
        } catch (Exception $e) {
            error_log("ERROR: Failed to extract component socket: " . $e->getMessage());
        }
        
        try {
            $motherboardSocket = $compatibility->extractSocketTypeFromJSON('motherboard', $motherboard['UUID']);
            error_log("DEBUG: Motherboard socket extracted: " . ($motherboardSocket ?? 'null'));
        } catch (Exception $e) {
            error_log("ERROR: Failed to extract motherboard socket: " . $e->getMessage());
        }
        
        error_log("DEBUG: Final sockets - Component: " . ($componentSocket ?? 'null') . ", Motherboard: " . ($motherboardSocket ?? 'null'));
        
        // Component-specific compatibility checks
        switch ($componentType) {
            case 'cpu':
                return checkEnhancedCPUCompatibility($compatibility, $component, $motherboard, $componentSocket, $motherboardSocket);
            case 'ram':
                return checkEnhancedRAMCompatibility($compatibility, $component, $motherboard);
            case 'storage':
                return checkEnhancedStorageCompatibility($compatibility, $component, $motherboard);
            case 'nic':
                return checkEnhancedNICCompatibility($compatibility, $component, $motherboard);
            case 'caddy':
                return checkEnhancedCaddyCompatibility($compatibility, $component, $motherboard);
            default:
                return [
                    'compatible' => true,
                    'compatibility_score' => 75.0,
                    'reason' => 'Component type compatibility checking not implemented'
                ];
        }
        
    } catch (Exception $e) {
        error_log("ERROR: Enhanced compatibility check failed: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 70.0,
            'reason' => 'Compatibility check error - defaulting to compatible',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Enhanced CPU compatibility checking with JSON socket validation
 */
function checkEnhancedCPUCompatibility($compatibility, $cpu, $motherboard, $cpuSocket, $motherboardSocket) {
    try {
        // Check if we have socket information from JSON
        if ($cpuSocket && $motherboardSocket) {
            // Normalize socket names for comparison
            $cpuSocketNorm = normalizeSocketName($cpuSocket);
            $motherboardSocketNorm = normalizeSocketName($motherboardSocket);
            
            if ($cpuSocketNorm === $motherboardSocketNorm) {
                return [
                    'compatible' => true,
                    'compatibility_score' => 95.0,
                    'reason' => "Perfect match: Both CPU and motherboard use $cpuSocket socket (Score: 95)",
                    'details' => ['cpu_socket' => $cpuSocket, 'motherboard_socket' => $motherboardSocket]
                ];
            } else {
                return [
                    'compatible' => false,
                    'compatibility_score' => 25.0,
                    'reason' => "Incompatible: CPU uses $cpuSocket socket but motherboard uses $motherboardSocket (Score: 25)",
                    'details' => ['cpu_socket' => $cpuSocket, 'motherboard_socket' => $motherboardSocket]
                ];
            }
        }
        
        // Fallback to Notes parsing if JSON data not available
        $cpuNotes = strtoupper($cpu['Notes'] ?? '');
        $motherboardNotes = strtoupper($motherboard['Notes'] ?? '');
        
        // Try to extract socket from Notes
        $cpuSocketFromNotes = extractSocketFromNotes($cpuNotes);
        $motherboardSocketFromNotes = extractSocketFromNotes($motherboardNotes);
        
        if ($cpuSocketFromNotes && $motherboardSocketFromNotes) {
            $cpuSocketNorm = normalizeSocketName($cpuSocketFromNotes);
            $motherboardSocketNorm = normalizeSocketName($motherboardSocketFromNotes);
            
            if ($cpuSocketNorm === $motherboardSocketNorm) {
                return [
                    'compatible' => true,
                    'compatibility_score' => 85.0,
                    'reason' => "Good match: Socket types from notes compatible ($cpuSocketFromNotes) (Score: 85)",
                    'details' => ['cpu_socket' => $cpuSocketFromNotes, 'motherboard_socket' => $motherboardSocketFromNotes, 'source' => 'notes']
                ];
            } else {
                return [
                    'compatible' => false,
                    'compatibility_score' => 25.0,
                    'reason' => "Incompatible: Notes indicate socket mismatch ($cpuSocketFromNotes vs $motherboardSocketFromNotes) (Score: 25)",
                    'details' => ['cpu_socket' => $cpuSocketFromNotes, 'motherboard_socket' => $motherboardSocketFromNotes, 'source' => 'notes']
                ];
            }
        }
        
        // Unknown compatibility - need manual verification
        return [
            'compatible' => true,
            'compatibility_score' => 75.0,
            'reason' => "Unknown: CPU socket not found in specifications - manual verification required (Score: 75)",
            'details' => ['cpu_uuid' => $cpu['UUID'], 'motherboard_uuid' => $motherboard['UUID']]
        ];
        
    } catch (Exception $e) {
        error_log("ERROR: Enhanced CPU compatibility check failed: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 70.0,
            'reason' => 'CPU compatibility check failed - defaulting to compatible',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Enhanced RAM compatibility checking
 */
function checkEnhancedRAMCompatibility($compatibility, $ram, $motherboard) {
    // Implementation similar to existing checkRAMCompatibility but with JSON integration
    return checkRAMCompatibility(null, $ram, $motherboard, null);
}

/**
 * Enhanced Storage compatibility checking  
 */
function checkEnhancedStorageCompatibility($compatibility, $storage, $motherboard) {
    return checkStorageCompatibility(null, $storage, $motherboard, null);
}

/**
 * Enhanced NIC compatibility checking
 */
function checkEnhancedNICCompatibility($compatibility, $nic, $motherboard) {
    return checkNICCompatibility(null, $nic, $motherboard, null);
}

/**
 * Enhanced Caddy compatibility checking
 */
function checkEnhancedCaddyCompatibility($compatibility, $caddy, $motherboard) {
    return checkCaddyCompatibility(null, $caddy, $motherboard, null);
}

/**
 * Normalize socket names for consistent comparison
 */
function normalizeSocketName($socketName) {
    if (!$socketName) return null;
    
    $normalized = strtoupper(trim($socketName));
    
    // Remove spaces and normalize common variations
    $normalized = str_replace(' ', '', $normalized);
    $normalized = str_replace('-', '', $normalized);
    
    // Handle common socket name variations
    $socketMappings = [
        'LGA4189' => 'LGA4189',
        'LGA1200' => 'LGA1200', 
        'LGA1700' => 'LGA1700',
        'LGA2011' => 'LGA2011',
        'LGA2066' => 'LGA2066',
        'AM4' => 'AM4',
        'AM5' => 'AM5',
        'SP3' => 'SP3',
        'SP5' => 'SP5'
    ];
    
    return $socketMappings[$normalized] ?? $normalized;
}

/**
 * Extract socket type from Notes field using pattern matching
 */
function extractSocketFromNotes($notes) {
    if (!$notes) return null;
    
    // Common socket patterns
    $patterns = [
        '/LGA\s?(\d{4})/',    // LGA1200, LGA 1700, etc.
        '/AM(\d)/',           // AM4, AM5
        '/SP(\d)/',           // SP3, SP5
        '/Socket\s+(\w+)/',   // Socket AM4, Socket LGA1200
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $notes, $matches)) {
            if (isset($matches[1])) {
                // Reconstruct socket name
                if (strpos($pattern, 'LGA') !== false) {
                    return 'LGA' . $matches[1];
                } elseif (strpos($pattern, 'AM') !== false) {
                    return 'AM' . $matches[1];
                } elseif (strpos($pattern, 'SP') !== false) {
                    return 'SP' . $matches[1];
                }
            }
            return $matches[0];
        }
    }
    
    return null;
}

/**
 * Direct Notes-based compatibility checking (fallback when ComponentCompatibility class not available)
 */
function checkDirectNotesCompatibility($componentType, $component, $motherboard) {
    try {
        error_log("DEBUG: Direct Notes compatibility check for $componentType: " . $component['UUID']);
        
        $componentNotes = strtoupper($component['Notes'] ?? '');
        $motherboardNotes = strtoupper($motherboard['Notes'] ?? '');
        
        error_log("DEBUG: Component notes: " . $componentNotes);
        error_log("DEBUG: Motherboard notes: " . $motherboardNotes);
        
        switch ($componentType) {
            case 'cpu':
                return checkDirectCPUNotesCompatibility($component, $motherboard, $componentNotes, $motherboardNotes);
            case 'ram':
                return checkDirectRAMNotesCompatibility($component, $motherboard, $componentNotes, $motherboardNotes);
            default:
                return [
                    'compatible' => true,
                    'compatibility_score' => 75.0,
                    'reason' => 'Direct Notes compatibility check - component type not fully supported'
                ];
        }
        
    } catch (Exception $e) {
        error_log("ERROR: Direct Notes compatibility check failed: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 65.0,
            'reason' => 'Direct compatibility check error - defaulting to compatible'
        ];
    }
}

/**
 * Direct CPU Notes compatibility checking
 */
function checkDirectCPUNotesCompatibility($cpu, $motherboard, $cpuNotes, $motherboardNotes) {
    // Known CPU to motherboard socket mappings based on your data
    $cpuSocketMappings = [
        'PLATINUM 8480+' => 'LGA4189',
        'INTEL 8470' => 'LGA4189',
        'AMD EPYC' => 'SP3',
        'EPYC 64-CORE' => 'SP3'
    ];
    
    $motherboardSocketMappings = [
        'X13DRI-N' => 'LGA4189',
        'X13DRG' => 'LGA4189',
        'SUPERMICRO' => 'LGA4189' // Default for Supermicro server boards
    ];
    
    // Extract socket from CPU notes
    $cpuSocket = null;
    foreach ($cpuSocketMappings as $cpuModel => $socket) {
        if (strpos($cpuNotes, $cpuModel) !== false) {
            $cpuSocket = $socket;
            break;
        }
    }
    
    // Extract socket from motherboard notes  
    $motherboardSocket = null;
    foreach ($motherboardSocketMappings as $mbModel => $socket) {
        if (strpos($motherboardNotes, $mbModel) !== false) {
            $motherboardSocket = $socket;
            break;
        }
    }
    
    // Also try pattern matching
    if (!$cpuSocket) {
        $cpuSocket = extractSocketFromNotes($cpuNotes);
    }
    if (!$motherboardSocket) {
        $motherboardSocket = extractSocketFromNotes($motherboardNotes);
    }
    
    error_log("DEBUG: Extracted sockets - CPU: " . ($cpuSocket ?: 'null') . ", MB: " . ($motherboardSocket ?: 'null'));
    
    // Determine compatibility
    if ($cpuSocket && $motherboardSocket) {
        $cpuSocketNorm = normalizeSocketName($cpuSocket);
        $motherboardSocketNorm = normalizeSocketName($motherboardSocket);
        
        if ($cpuSocketNorm === $motherboardSocketNorm) {
            return [
                'compatible' => true,
                'compatibility_score' => 90.0,
                'reason' => "Good match: CPU $cpuSocket matches motherboard $motherboardSocket from Notes analysis (Score: 90)"
            ];
        } else {
            return [
                'compatible' => false,
                'compatibility_score' => 30.0,
                'reason' => "Socket mismatch: CPU $cpuSocket vs motherboard $motherboardSocket from Notes analysis (Score: 30)"
            ];
        }
    } elseif ($cpuSocket || $motherboardSocket) {
        $knownSocket = $cpuSocket ?: $motherboardSocket;
        return [
            'compatible' => true,
            'compatibility_score' => 80.0,
            'reason' => "Partial match: Found socket $knownSocket but need verification for other component (Score: 80)"
        ];
    } else {
        // Check for already compatible components (Status = 2 means in use in this config)
        if ($cpu['Status'] == 2) {
            return [
                'compatible' => true,
                'compatibility_score' => 95.0,
                'reason' => "Already in configuration: Component successfully added previously, compatibility confirmed (Score: 95)"
            ];
        }
        
        return [
            'compatible' => true,
            'compatibility_score' => 75.0,
            'reason' => "Socket types unknown from Notes - manual verification needed (Score: 75)"
        ];
    }
}

/**
 * Direct RAM Notes compatibility checking
 */
function checkDirectRAMNotesCompatibility($ram, $motherboard, $ramNotes, $motherboardNotes) {
    // Basic DDR type checking
    $ramDDRType = 'DDR4'; // Default
    if (preg_match('/DDR(\d)/', $ramNotes, $matches)) {
        $ramDDRType = 'DDR' . $matches[1];
    }
    
    $motherboardDDRType = 'DDR5'; // Server boards typically support DDR5
    if (preg_match('/DDR(\d)/', $motherboardNotes, $matches)) {
        $motherboardDDRType = 'DDR' . $matches[1];
    }
    
    if ($ramDDRType === $motherboardDDRType) {
        return [
            'compatible' => true,
            'compatibility_score' => 90.0,
            'reason' => "Memory type compatible: Both support $ramDDRType (Score: 90)"
        ];
    } else {
        return [
            'compatible' => false,
            'compatibility_score' => 25.0,
            'reason' => "Memory type mismatch: RAM is $ramDDRType but motherboard expects $motherboardDDRType (Score: 25)"
        ];
    }
}

/**
 * Check component compatibility with motherboard - Component-specific compatibility checks
 * Implements the compatibility logic specified in Important-fix requirements
 */
function checkComponentCompatibilityWithMotherboard($pdo, $componentType, $component, $motherboard, $motherboardSpecs) {
    try {
        // Initialize compatibility result
        $result = [
            'compatible' => true,
            'compatibility_score' => 100.0,
            'reason' => 'Compatible',
            'details' => []
        ];
        
        // Component-specific compatibility checks as specified in Important-fix
        switch ($componentType) {
            case 'cpu':
                return checkCPUCompatibility($pdo, $component, $motherboard, $motherboardSpecs);
                
            case 'ram':
                return checkRAMCompatibility($pdo, $component, $motherboard, $motherboardSpecs);
                
            case 'storage':
                return checkStorageCompatibility($pdo, $component, $motherboard, $motherboardSpecs);
                
            case 'nic':
                return checkNICCompatibility($pdo, $component, $motherboard, $motherboardSpecs);
                
            case 'caddy':
                return checkCaddyCompatibility($pdo, $component, $motherboard, $motherboardSpecs);
                
            default:
                $result['compatibility_score'] = 85.0;
                $result['reason'] = 'Basic compatibility - component type validation not implemented';
                return $result;
        }
        
    } catch (Exception $e) {
        error_log("Error checking compatibility: " . $e->getMessage());
        return [
            'compatible' => true, // Default to compatible on errors to avoid blocking
            'compatibility_score' => 70.0,
            'reason' => 'Compatibility check failed - defaulting to compatible',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Check CPU compatibility with motherboard - Socket type compatibility
 */
function checkCPUCompatibility($pdo, $cpu, $motherboard, $motherboardSpecs) {
    try {
        // Use ComponentCompatibility class if available for enhanced socket checking
        if (class_exists('ComponentCompatibility')) {
            $compatibility = new ComponentCompatibility($pdo);
            
            // Get motherboard limits for CPU validation
            $motherboardLimits = $compatibility->getMotherboardLimits($motherboard['UUID']);
            
            if ($motherboardLimits['found']) {
                // Validate CPU socket compatibility
                $socketResult = $compatibility->validateCPUSocketCompatibility($cpu['UUID'], $motherboardLimits['limits']);
                
                if ($socketResult['compatible']) {
                    return [
                        'compatible' => true,
                        'compatibility_score' => 95.0,
                        'reason' => 'Socket compatibility confirmed: ' . ($socketResult['details']['socket_match'] ?? 'Compatible socket types'),
                        'details' => $socketResult['details'] ?? []
                    ];
                } else {
                    return [
                        'compatible' => false,
                        'compatibility_score' => 0.0,
                        'reason' => 'Socket mismatch: ' . ($socketResult['error'] ?? 'CPU and motherboard socket types incompatible'),
                        'details' => $socketResult['details'] ?? []
                    ];
                }
            }
        }
        
        // Fallback: Basic CPU compatibility
        return [
            'compatible' => true,
            'compatibility_score' => 80.0,
            'reason' => 'Basic CPU compatibility check - enhanced socket validation not available',
            'details' => []
        ];
        
    } catch (Exception $e) {
        error_log("CPU compatibility check error: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 70.0,
            'reason' => 'CPU compatibility check failed - defaulting to compatible',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Check RAM compatibility with motherboard - DDR type and speed compatibility
 */
function checkRAMCompatibility($pdo, $ram, $motherboard, $motherboardSpecs) {
    try {
        $score = 90.0;
        $reasons = [];
        
        // Check DDR type compatibility (DDR4/DDR5)
        $ramNotes = strtoupper($ram['Notes'] ?? '');
        $motherboardNotes = strtoupper($motherboard['Notes'] ?? '');
        
        // Extract DDR type from notes
        $ramDDRType = 'DDR4'; // Default
        if (preg_match('/DDR(\d)/', $ramNotes, $matches)) {
            $ramDDRType = 'DDR' . $matches[1];
        }
        
        $motherboardDDRType = 'DDR4'; // Default
        if (preg_match('/DDR(\d)/', $motherboardNotes, $matches)) {
            $motherboardDDRType = 'DDR' . $matches[1];
        }
        
        if ($ramDDRType !== $motherboardDDRType) {
            return [
                'compatible' => false,
                'compatibility_score' => 0.0,
                'reason' => "Memory type mismatch: RAM is $ramDDRType but motherboard supports $motherboardDDRType",
                'details' => ['ram_type' => $ramDDRType, 'motherboard_type' => $motherboardDDRType]
            ];
        }
        
        $reasons[] = "DDR type compatible ($ramDDRType)";
        
        // Check memory speed support (basic check)
        if (preg_match('/(\d{4,5})/', $ramNotes, $ramSpeed) && preg_match('/(\d{4,5})/', $motherboardNotes, $mbSpeed)) {
            if ((int)$ramSpeed[1] > (int)$mbSpeed[1]) {
                $score -= 10.0;
                $reasons[] = "RAM speed higher than motherboard specification - will run at reduced speed";
            }
        }
        
        // Consider ECC support if specified
        if (stripos($ramNotes, 'ECC') !== false && stripos($motherboardNotes, 'ECC') === false) {
            $score -= 15.0;
            $reasons[] = "ECC RAM on non-ECC motherboard - ECC features disabled";
        }
        
        return [
            'compatible' => true,
            'compatibility_score' => $score,
            'reason' => implode(', ', $reasons),
            'details' => ['ram_type' => $ramDDRType, 'motherboard_type' => $motherboardDDRType]
        ];
        
    } catch (Exception $e) {
        error_log("RAM compatibility check error: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 80.0,
            'reason' => 'RAM compatibility check completed with assumptions',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Check storage compatibility with motherboard - Interface compatibility
 */
function checkStorageCompatibility($pdo, $storage, $motherboard, $motherboardSpecs) {
    try {
        $score = 90.0;
        $reasons = [];

        $storageNotes = strtoupper($storage['Notes'] ?? '');
        $motherboardNotes = strtoupper($motherboard['Notes'] ?? '');

        // Check interface compatibility (SATA/NVMe/SAS)
        $storageInterface = 'SATA'; // Default
        if (stripos($storageNotes, 'NVME') !== false || stripos($storageNotes, 'M.2') !== false) {
            $storageInterface = 'NVMe';
        } elseif (stripos($storageNotes, 'SAS') !== false) {
            $storageInterface = 'SAS';
        }

        // Check if motherboard supports the interface
        $interfaceSupported = true;
        switch ($storageInterface) {
            case 'NVMe':
                if (stripos($motherboardNotes, 'NVME') === false && stripos($motherboardNotes, 'M.2') === false) {
                    $interfaceSupported = false;
                }
                break;
            case 'SAS':
                if (stripos($motherboardNotes, 'SAS') === false) {
                    $interfaceSupported = false;
                }
                break;
            case 'SATA':
                // SATA is widely supported, assume compatible
                break;
        }

        if (!$interfaceSupported) {
            return [
                'compatible' => false,
                'compatibility_score' => 0.0,
                'reason' => "Interface mismatch: Storage uses $storageInterface but motherboard may not support it",
                'details' => ['storage_interface' => $storageInterface]
            ];
        }

        $reasons[] = "Interface compatibility confirmed ($storageInterface)";

        // Consider form factor (2.5", 3.5", M.2)
        if (stripos($storageNotes, '3.5') !== false) {
            $reasons[] = "3.5\" form factor";
        } elseif (stripos($storageNotes, '2.5') !== false) {
            $reasons[] = "2.5\" form factor";
        } elseif (stripos($storageNotes, 'M.2') !== false) {
            $reasons[] = "M.2 form factor";
        }

        return [
            'compatible' => true,
            'compatibility_score' => $score,
            'reason' => implode(', ', $reasons),
            'details' => ['interface' => $storageInterface]
        ];

    } catch (Exception $e) {
        error_log("Storage compatibility check error: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 80.0,
            'reason' => 'Storage compatibility check completed with assumptions',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Check NIC compatibility with motherboard - PCIe slot compatibility
 */
function checkNICCompatibility($pdo, $nic, $motherboard, $motherboardSpecs) {
    try {
        $score = 85.0;
        $reasons = [];
        
        $nicNotes = strtoupper($nic['Notes'] ?? '');
        $motherboardNotes = strtoupper($motherboard['Notes'] ?? '');
        
        // Check PCIe slot compatibility
        $nicSlotType = 'PCIe'; // Default assumption
        if (preg_match('/PCIE?\s*X?(\d+)/i', $nicNotes, $matches)) {
            $nicSlotType = 'PCIe x' . $matches[1];
        }
        
        $reasons[] = "PCIe slot compatibility assumed ($nicSlotType)";
        
        // Consider power requirements (basic check)
        if (stripos($nicNotes, 'LOW POWER') !== false || stripos($nicNotes, 'LOW-PROFILE') !== false) {
            $reasons[] = "Low power/profile design";
        }
        
        return [
            'compatible' => true,
            'compatibility_score' => $score,
            'reason' => implode(', ', $reasons),
            'details' => ['slot_type' => $nicSlotType]
        ];
        
    } catch (Exception $e) {
        error_log("NIC compatibility check error: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 80.0,
            'reason' => 'NIC compatibility check completed with assumptions',
            'details' => ['error' => $e->getMessage()]
        ];
    }
}

/**
 * Check caddy compatibility with motherboard - Form factor compatibility
 */
function checkCaddyCompatibility($pdo, $caddy, $motherboard, $motherboardSpecs) {
    try {
        $score = 90.0;
        $reasons = [];
        
        $caddyNotes = strtoupper($caddy['Notes'] ?? '');
        
        // Check form factor support
        if (stripos($caddyNotes, '2.5') !== false) {
            $reasons[] = "2.5\" form factor support";
        }
        if (stripos($caddyNotes, '3.5') !== false) {
            $reasons[] = "3.5\" form factor support";
        }
        
        // Consider drive interface
        $driveInterface = 'SATA'; // Default
        if (stripos($caddyNotes, 'SAS') !== false) {
            $driveInterface = 'SAS';
        } elseif (stripos($caddyNotes, 'NVME') !== false || stripos($caddyNotes, 'U.2') !== false) {
            $driveInterface = 'U.2/NVMe';
        }
        
        $reasons[] = "Drive interface: $driveInterface";
        
        return [
            'compatible' => true,
            'compatibility_score' => $score,
            'reason' => implode(', ', $reasons),
            'details' => ['drive_interface' => $driveInterface]
        ];
        
    } catch (Exception $e) {
        error_log("Caddy compatibility check error: " . $e->getMessage());
        return [
            'compatible' => true,
            'compatibility_score' => 80.0,
            'reason' => 'Caddy compatibility check completed with assumptions',
            'details' => ['error' => $e->getMessage()]
        ];
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
 * Validate component exists and get details
 */
function validateComponentExists($componentType, $componentUuid) {
    global $pdo;

    $tableName = getComponentTableName($componentType);
    if (!$tableName) {
        return [
            'exists' => false,
            'message' => 'Invalid component type',
            'component_type' => $componentType
        ];
    }

    $stmt = $pdo->prepare("SELECT Status, UUID, SerialNumber, Notes, ServerUUID FROM $tableName WHERE UUID = ?");
    $stmt->execute([$componentUuid]);
    $component = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$component) {
        return [
            'exists' => false,
            'message' => 'Component not found in inventory',
            'component_type' => $componentType,
            'component_uuid' => $componentUuid
        ];
    }

    return [
        'exists' => true,
        'component' => $component,
        'available' => $component['Status'] == 1
    ];
}

/**
 * Check RAM compatibility with existing components in server configuration
 * Implements decentralized compatibility checking without motherboard dependency
 */
function checkRAMCompatibilityWithExistingComponents($ramComponent, $existingComponents, $pdo) {
    try {
        $compatibilityScore = 1.0;
        $compatibilityReasons = [];
        $isCompatible = true;

        // If no existing components, RAM is always compatible
        if (empty($existingComponents)) {
            return [
                'compatible' => true,
                'score' => 1.0,
                'reason' => 'No existing components - all RAM compatible'
            ];
        }

        // Get RAM specifications from JSON
        $ramSpecs = loadComponentSpecsFromJSON($ramComponent['UUID'], 'ram');
        if (!$ramSpecs) {
            // Fallback to Notes-based compatibility
            return checkRAMCompatibilityWithNotesOnly($ramComponent, $existingComponents);
        }

        $ramMemoryType = $ramSpecs['memory_type'] ?? null; // DDR4, DDR5, etc.
        $ramFormFactor = $ramSpecs['form_factor'] ?? null; // DIMM, SO-DIMM, etc.
        $ramFrequency = $ramSpecs['frequency_MHz'] ?? null;

        // Check compatibility with each existing component
        foreach ($existingComponents as $existingComp) {
            $existingType = $existingComp['type'];

            if ($existingType === 'cpu') {
                $cpuCompatResult = checkRAMCPUCompatibility($ramSpecs, $existingComp, $pdo);
                if (!$cpuCompatResult['compatible']) {
                    $isCompatible = false;
                    $compatibilityReasons[] = $cpuCompatResult['reason'];
                } else {
                    $compatibilityScore = min($compatibilityScore, $cpuCompatResult['score']);
                    $compatibilityReasons[] = $cpuCompatResult['reason'];
                }
            } elseif ($existingType === 'motherboard') {
                $mbCompatResult = checkRAMMotherboardCompatibility($ramSpecs, $existingComp, $pdo);
                if (!$mbCompatResult['compatible']) {
                    $isCompatible = false;
                    $compatibilityReasons[] = $mbCompatResult['reason'];
                } else {
                    $compatibilityScore = min($compatibilityScore, $mbCompatResult['score']);
                    $compatibilityReasons[] = $mbCompatResult['reason'];
                }
            } elseif ($existingType === 'ram') {
                // Check RAM-to-RAM compatibility (form factor consistency)
                $existingRAMSpecs = loadComponentSpecsFromJSON($existingComp['uuid'], 'ram');
                if ($existingRAMSpecs) {
                    if ($ramFormFactor && $existingRAMSpecs['form_factor'] &&
                        $ramFormFactor !== $existingRAMSpecs['form_factor']) {
                        $isCompatible = false;
                        $compatibilityReasons[] = "Form factor mismatch: {$ramFormFactor} vs {$existingRAMSpecs['form_factor']}";
                    } else {
                        $compatibilityReasons[] = "Form factor compatible with existing RAM";
                    }
                }
            }
        }

        return [
            'compatible' => $isCompatible,
            'score' => $compatibilityScore,
            'reason' => implode('; ', $compatibilityReasons)
        ];

    } catch (Exception $e) {
        error_log("RAM compatibility check error: " . $e->getMessage());
        return [
            'compatible' => true,
            'score' => 0.7,
            'reason' => 'Compatibility check failed - defaulting to compatible'
        ];
    }
}

/**
 * Load component specifications from JSON files
 */
function loadComponentSpecsFromJSON($componentUUID, $componentType) {
    try {
        $jsonDir = __DIR__ . '/../../All-JSON';

        $jsonFiles = [
            'cpu' => $jsonDir . '/cpu-jsons/Cpu-details-level-3.json',
            'ram' => $jsonDir . '/Ram-jsons/ram_detail.json',
            'motherboard' => $jsonDir . '/motherboad-jsons/motherboard-level-3.json'
        ];

        if (!isset($jsonFiles[$componentType])) {
            return null;
        }

        $jsonFile = $jsonFiles[$componentType];
        if (!file_exists($jsonFile)) {
            error_log("JSON file not found: $jsonFile");
            return null;
        }

        $jsonData = json_decode(file_get_contents($jsonFile), true);
        if (!$jsonData) {
            error_log("Failed to parse JSON file: $jsonFile");
            return null;
        }

        // Search for component by UUID in the JSON structure
        foreach ($jsonData as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    if (isset($model['uuid']) && $model['uuid'] === $componentUUID) {
                        return $model;
                    }
                }
            }
        }

        return null;

    } catch (Exception $e) {
        error_log("Error loading component specs from JSON: " . $e->getMessage());
        return null;
    }
}

/**
 * Check RAM-CPU compatibility based on memory type and form factor
 */
function checkRAMCPUCompatibility($ramSpecs, $cpuComponent, $pdo) {
    $cpuSpecs = loadComponentSpecsFromJSON($cpuComponent['uuid'], 'cpu');

    if (!$cpuSpecs) {
        return [
            'compatible' => true,
            'score' => 0.8,
            'reason' => 'CPU specs not found - basic compatibility assumed'
        ];
    }

    $cpuMemoryTypes = $cpuSpecs['memory_types'] ?? [];
    $ramMemoryType = $ramSpecs['memory_type'] ?? null;

    // Check if RAM memory type is supported by CPU
    $typeCompatible = false;
    foreach ($cpuMemoryTypes as $supportedType) {
        if (strpos($supportedType, $ramMemoryType) !== false) {
            $typeCompatible = true;
            break;
        }
    }

    if (!$typeCompatible) {
        return [
            'compatible' => false,
            'score' => 0.0,
            'reason' => "Memory type mismatch: RAM {$ramMemoryType} not supported by CPU (" . implode(', ', $cpuMemoryTypes) . ")"
        ];
    }

    return [
        'compatible' => true,
        'score' => 1.0,
        'reason' => "Memory type {$ramMemoryType} compatible with CPU"
    ];
}

/**
 * Check RAM-Motherboard compatibility based on memory type and frequency
 */
function checkRAMMotherboardCompatibility($ramSpecs, $motherboardComponent, $pdo) {
    $mbSpecs = loadComponentSpecsFromJSON($motherboardComponent['uuid'], 'motherboard');

    if (!$mbSpecs) {
        return [
            'compatible' => true,
            'score' => 0.8,
            'reason' => 'Motherboard specs not found - basic compatibility assumed'
        ];
    }

    $mbMemoryType = $mbSpecs['memory']['type'] ?? null;
    $mbMaxFrequency = $mbSpecs['memory']['max_frequency_MHz'] ?? null;
    $ramMemoryType = $ramSpecs['memory_type'] ?? null;
    $ramFrequency = $ramSpecs['frequency_MHz'] ?? null;

    // Check memory type compatibility
    if ($mbMemoryType && $ramMemoryType && $mbMemoryType !== $ramMemoryType) {
        return [
            'compatible' => false,
            'score' => 0.0,
            'reason' => "Memory type mismatch: RAM {$ramMemoryType} vs Motherboard {$mbMemoryType}"
        ];
    }

    // Check frequency compatibility
    $score = 1.0;
    $reasons = [];

    if ($mbMemoryType) {
        $reasons[] = "Memory type {$mbMemoryType} compatible";
    }

    if ($mbMaxFrequency && $ramFrequency) {
        if ($ramFrequency > $mbMaxFrequency) {
            $score = 0.9; // Compatible but with frequency limitation
            $reasons[] = "RAM frequency {$ramFrequency}MHz will be limited to {$mbMaxFrequency}MHz by motherboard";
        } else {
            $reasons[] = "RAM frequency {$ramFrequency}MHz within motherboard limits";
        }
    }

    return [
        'compatible' => true,
        'score' => $score,
        'reason' => implode('; ', $reasons)
    ];
}

/**
 * Fallback RAM compatibility using Notes only (when JSON specs not available)
 */
function checkRAMCompatibilityWithNotesOnly($ramComponent, $existingComponents) {
    $ramNotes = strtoupper($ramComponent['Notes'] ?? '');
    $compatibilityReasons = [];
    $isCompatible = true;

    // Extract RAM type from notes
    $ramType = 'DDR4'; // Default
    if (preg_match('/DDR(\d)/', $ramNotes, $matches)) {
        $ramType = 'DDR' . $matches[1];
    }

    foreach ($existingComponents as $existingComp) {
        $existingNotes = strtoupper($existingComp['data']['Notes'] ?? '');

        if ($existingComp['type'] === 'cpu' || $existingComp['type'] === 'motherboard') {
            // Check if existing component notes mention RAM type
            $existingRAMType = null;
            if (preg_match('/DDR(\d)/', $existingNotes, $matches)) {
                $existingRAMType = 'DDR' . $matches[1];
            }

            if ($existingRAMType && $existingRAMType !== $ramType) {
                $isCompatible = false;
                $compatibilityReasons[] = "Memory type mismatch: RAM {$ramType} vs {$existingComp['type']} {$existingRAMType}";
            } else if ($existingRAMType) {
                $compatibilityReasons[] = "Memory type {$ramType} compatible with {$existingComp['type']}";
            }
        }
    }

    if (empty($compatibilityReasons)) {
        $compatibilityReasons[] = "Basic compatibility check passed using component notes";
    }

    return [
        'compatible' => $isCompatible,
        'score' => $isCompatible ? 0.8 : 0.0,
        'reason' => implode('; ', $compatibilityReasons)
    ];
}

/**
 * Analyze common compatibility issues from incompatible components
 */
function analyzeCommonCompatibilityIssues($incompatibleComponents) {
    $issues = [];
    $memoryTypeIssues = 0;
    $formFactorIssues = 0;

    foreach ($incompatibleComponents as $comp) {
        $reason = $comp['compatibility_reason'];
        if (strpos($reason, 'DDR') !== false) {
            $memoryTypeIssues++;
        }
        if (strpos($reason, 'form factor') !== false) {
            $formFactorIssues++;
        }
    }

    if ($memoryTypeIssues > 0) {
        $issues[] = "Memory type mismatch ({$memoryTypeIssues} components)";
    }
    if ($formFactorIssues > 0) {
        $issues[] = "Form factor incompatibility ({$formFactorIssues} components)";
    }

    return $issues;
}

/**
 * Generate compatibility suggestions based on existing components
 */
function generateCompatibilitySuggestions($componentType, $existingComponents) {
    $suggestions = [];

    if ($componentType === 'ram') {
        // Analyze existing components to suggest compatible RAM
        $cpuTypes = [];
        $mbTypes = [];

        foreach ($existingComponents as $comp) {
            if ($comp['type'] === 'cpu') {
                $notes = strtoupper($comp['data']['Notes'] ?? '');
                if (strpos($notes, 'DDR5') !== false) {
                    $cpuTypes[] = 'DDR5';
                } elseif (strpos($notes, 'DDR4') !== false) {
                    $cpuTypes[] = 'DDR4';
                }
            } elseif ($comp['type'] === 'motherboard') {
                $notes = strtoupper($comp['data']['Notes'] ?? '');
                if (strpos($notes, 'DDR5') !== false) {
                    $mbTypes[] = 'DDR5';
                } elseif (strpos($notes, 'DDR4') !== false) {
                    $mbTypes[] = 'DDR4';
                }
            }
        }

        $commonTypes = array_intersect($cpuTypes, $mbTypes);
        if (!empty($commonTypes)) {
            $suggestions[] = "Use " . implode(' or ', array_unique($commonTypes)) . " RAM modules";
            $suggestions[] = "Ensure RAM form factor matches motherboard (DIMM/SO-DIMM)";
        } else {
            $suggestions[] = "Check CPU and motherboard memory type compatibility";
        }
    }

    return $suggestions;
}

/**
 * Extract PCIe slot size from component specifications
 *
 * @param array $specs Component specifications from JSON
 * @return string|null Slot size (x1, x4, x8, x16) or null
 */
function extractPCIeSlotSizeFromSpecs($specs) {
    // Check interface field (most common)
    $interface = $specs['interface'] ?? '';
    if (preg_match('/x(\d+)/i', $interface, $matches)) {
        return 'x' . $matches[1];
    }

    // Check slot_type field
    $slotType = $specs['slot_type'] ?? '';
    if (preg_match('/x(\d+)/i', $slotType, $matches)) {
        return 'x' . $matches[1];
    }

    // Check pcie_interface field
    $pcieInterface = $specs['pcie_interface'] ?? '';
    if (preg_match('/x(\d+)/i', $pcieInterface, $matches)) {
        return 'x' . $matches[1];
    }

    return null;
}

/**
 * Extract slot type from slot ID
 *
 * @param string $slotId Slot identifier (e.g., "pcie_x16_slot_1")
 * @return string|null Slot type (e.g., "x16") or null
 */
function extractSlotTypeFromSlotId($slotId) {
    if (preg_match('/pcie_(x\d+)_slot_/', $slotId, $matches)) {
        return $matches[1];
    }
    return null;
}
?>
