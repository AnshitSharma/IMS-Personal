<?php
/**
 * Infrastructure Management System - Server Creation Endpoint
 * File: api/server/create_server.php
 * 
 * Dedicated endpoint for step-by-step server creation
 */

// Include required files
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');
require_once(__DIR__ . '/../../includes/models/ServerBuilder.php');
require_once(__DIR__ . '/../../includes/models/ServerConfiguration.php');

// Ensure user is authenticated
$user = authenticateWithJWT($pdo);
if (!$user) {
    send_json_response(0, 0, 401, "Authentication required");
}

// Check permissions
if (!hasPermission($pdo, 'server.create', $user['id'])) {
    send_json_response(0, 1, 403, "Insufficient permissions for server creation");
}

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
            
        default:
            send_json_response(0, 1, 400, "Invalid server creation action: $action");
    }
} catch (Exception $e) {
    error_log("Server creation error: " . $e->getMessage());
    send_json_response(0, 1, 500, "Server creation failed");
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
        // Create new server configuration
        $config = ServerConfiguration::create($pdo, $serverName, $description, $user['id']);
        
        // Initialize server builder
        $serverBuilder = new ServerBuilder($pdo);
        $serverBuilder->setCurrentConfiguration($config->getData());
        
        // Get initial component options based on starting preference
        $initialOptions = [];
        
        if ($startWith === 'any' || $startWith === 'cpu') {
            $initialOptions['cpu'] = $serverBuilder->getCompatibleComponentsForType('cpu');
        }
        
        if ($startWith === 'any' || $startWith === 'motherboard') {
            $initialOptions['motherboard'] = $serverBuilder->getCompatibleComponentsForType('motherboard');
        }
        
        if ($startWith === 'any') {
            $initialOptions['ram'] = $serverBuilder->getCompatibleComponentsForType('ram');
            $initialOptions['storage'] = $serverBuilder->getCompatibleComponentsForType('storage');
            $initialOptions['nic'] = $serverBuilder->getCompatibleComponentsForType('nic');
            $initialOptions['caddy'] = $serverBuilder->getCompatibleComponentsForType('caddy');
        }
        
        send_json_response(1, 1, 200, "Server creation initialized", [
            'session_id' => $serverBuilder->getSessionId(),
            'config_uuid' => $config->get('config_uuid'),
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
        send_json_response(0, 1, 500, "Failed to initialize server creation");
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
        // Load existing configuration
        $config = ServerConfiguration::loadFromDatabase($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Initialize server builder with current config
        $serverBuilder = new ServerBuilder($pdo);
        $serverBuilder->setCurrentConfiguration($config->getData());
        
        // Add component with compatibility checking
        $result = $serverBuilder->addComponent($componentType, $componentUuid, [
            'override' => $override,
            'quantity' => $quantity,
            'slot_position' => $slotPosition
        ]);
        
        if ($result['success']) {
            // Update configuration in database
            $config->setData($serverBuilder->getCurrentConfiguration());
            $config->set('updated_by', $user['id']);
            $config->save();
            
            // Recalculate next step and options
            $nextStep = determineNextStep($serverBuilder);
            $allOptions = $serverBuilder->getCompatibleComponentsForAll();
            
            // Calculate progress
            $progress = calculateProgress($serverBuilder);
            
            send_json_response(1, 1, 200, $result['message'], [
                'component_removed' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid
                ],
                'current_configuration' => $result['current_configuration'],
                'next_step' => $nextStep,
                'all_options' => $allOptions,
                'progress' => $progress
            ]);
        } else {
            send_json_response(0, 1, 400, $result['message']);
        }
        
    } catch (Exception $e) {
        error_log("Error removing component in step process: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to remove component");
    }
}

/**
 * Get next component options
 */
function handleGetNextOptions() {
    global $pdo;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    $requestedType = $_GET['requested_type'] ?? $_POST['requested_type'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Load existing configuration
        $config = ServerConfiguration::loadFromDatabase($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Initialize server builder with current config
        $serverBuilder = new ServerBuilder($pdo);
        $serverBuilder->setCurrentConfiguration($config->getData());
        
        if ($requestedType) {
            // Get options for specific component type
            $options = $serverBuilder->getCompatibleComponentsForType($requestedType);
            $response = [
                'component_type' => $requestedType,
                'options' => $options,
                'count' => count($options)
            ];
        } else {
            // Get all available options
            $allOptions = $serverBuilder->getCompatibleComponentsForAll();
            $response = [
                'all_options' => $allOptions,
                'recommended_next' => determineNextStep($serverBuilder)
            ];
        }
        
        $response['current_configuration'] = $serverBuilder->getCurrentConfigurationSummary();
        $response['progress'] = calculateProgress($serverBuilder);
        
        send_json_response(1, 1, 200, "Next options retrieved", $response);
        
    } catch (Exception $e) {
        error_log("Error getting next options: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get next options");
    }
}

/**
 * Validate current configuration
 */
function handleValidateCurrent() {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? $_GET['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Load existing configuration
        $config = ServerConfiguration::loadFromDatabase($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Initialize server builder with current config
        $serverBuilder = new ServerBuilder($pdo);
        $serverBuilder->setCurrentConfiguration($config->getData());
        
        // Validate configuration
        $validation = $serverBuilder->validateConfiguration();
        
        send_json_response(1, 1, 200, "Configuration validated", [
            'validation_results' => $validation['validation_results'],
            'is_complete' => $validation['valid'],
            'current_configuration' => $serverBuilder->getCurrentConfigurationSummary(),
            'statistics' => $serverBuilder->getConfigurationStatistics(),
            'ready_for_finalization' => $validation['valid']
        ]);
        
    } catch (Exception $e) {
        error_log("Error validating configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to validate configuration");
    }
}

/**
 * Finalize server configuration
 */
function handleFinalizeServer() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $finalName = trim($_POST['final_name'] ?? '');
    $finalDescription = trim($_POST['final_description'] ?? '');
    $deployImmediately = filter_var($_POST['deploy_immediately'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Load existing configuration
        $config = ServerConfiguration::loadFromDatabase($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Initialize server builder with current config
        $serverBuilder = new ServerBuilder($pdo);
        $serverBuilder->setCurrentConfiguration($config->getData());
        
        // Final validation
        $validation = $serverBuilder->validateConfiguration();
        if (!$validation['valid']) {
            send_json_response(0, 1, 422, "Configuration validation failed", [
                'validation_results' => $validation['validation_results']
            ]);
        }
        
        // Update final details
        if ($finalName) {
            $config->set('config_name', $finalName);
        }
        if ($finalDescription) {
            $config->set('config_description', $finalDescription);
        }
        
        // Set status based on deployment choice
        $status = $deployImmediately ? 2 : 1; // 2 = Built, 1 = Validated
        $config->set('configuration_status', $status);
        $config->set('updated_by', $user['id']);
        
        // Save final configuration
        $result = $config->save();
        
        if ($result) {
            // Update component statuses if deploying immediately
            if ($deployImmediately) {
                $serverBuilder->updateComponentStatuses();
            }
            
            send_json_response(1, 1, 200, "Server configuration finalized successfully", [
                'config_id' => $result,
                'config_uuid' => $configUuid,
                'final_status' => $status === 2 ? 'Built and Deployed' : 'Validated and Ready',
                'configuration_summary' => $serverBuilder->getCurrentConfigurationSummary(),
                'statistics' => $serverBuilder->getConfigurationStatistics()
            ]);
        } else {
            send_json_response(0, 1, 500, "Failed to save final configuration");
        }
        
    } catch (Exception $e) {
        error_log("Error finalizing server: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to finalize server configuration");
    }
}

/**
 * Save configuration as draft
 */
function handleSaveDraft() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $draftName = trim($_POST['draft_name'] ?? '');
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Load existing configuration
        $config = ServerConfiguration::loadFromDatabase($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Update draft details
        if ($draftName) {
            $config->set('config_name', $draftName);
        }
        $config->set('configuration_status', 0); // Draft
        $config->set('updated_by', $user['id']);
        
        // Save draft
        $result = $config->save();
        
        if ($result) {
            send_json_response(1, 1, 200, "Draft saved successfully", [
                'config_uuid' => $configUuid,
                'draft_name' => $config->get('config_name')
            ]);
        } else {
            send_json_response(0, 1, 500, "Failed to save draft");
        }
        
    } catch (Exception $e) {
        error_log("Error saving draft: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to save draft");
    }
}

/**
 * Load existing draft
 */
function handleLoadDraft() {
    global $pdo;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Config UUID is required");
    }
    
    try {
        // Load configuration
        $config = ServerConfiguration::loadFromDatabase($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Initialize server builder with config
        $serverBuilder = new ServerBuilder($pdo);
        $serverBuilder->setCurrentConfiguration($config->getData());
        
        // Get current options
        $allOptions = $serverBuilder->getCompatibleComponentsForAll();
        $nextStep = determineNextStep($serverBuilder);
        $progress = calculateProgress($serverBuilder);
        
        send_json_response(1, 1, 200, "Draft loaded successfully", [
            'configuration' => $config->getData(),
            'configuration_summary' => $serverBuilder->getCurrentConfigurationSummary(),
            'all_options' => $allOptions,
            'next_step' => $nextStep,
            'progress' => $progress,
            'statistics' => $serverBuilder->getConfigurationStatistics()
        ]);
        
    } catch (Exception $e) {
        error_log("Error loading draft: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to load draft");
    }
}

/**
 * Helper function to determine next recommended step
 */
function determineNextStep($serverBuilder) {
    $config = $serverBuilder->getCurrentConfiguration();
    
    // Priority order for component selection
    if (empty($config['cpu_uuid']) && empty($config['motherboard_uuid'])) {
        return 'cpu'; // Start with CPU if nothing selected
    }
    
    if (!empty($config['cpu_uuid']) && empty($config['motherboard_uuid'])) {
        return 'motherboard'; // Add motherboard after CPU
    }
    
    if (!empty($config['motherboard_uuid']) && empty($config['cpu_uuid'])) {
        return 'cpu'; // Add CPU after motherboard
    }
    
    if (empty($config['ram_configuration'])) {
        return 'ram'; // Add RAM after CPU/MB
    }
    
    if (empty($config['storage_configuration'])) {
        return 'storage'; // Add storage after RAM
    }
    
    if (empty($config['nic_configuration'])) {
        return 'nic'; // Add NIC if needed
    }
    
    if (empty($config['caddy_configuration'])) {
        return 'caddy'; // Add caddy if needed
    }
    
    return null; // Configuration is complete
}

/**
 * Helper function to calculate progress
 */
function calculateProgress($serverBuilder) {
    $config = $serverBuilder->getCurrentConfiguration();
    
    $totalSteps = 6; // CPU, Motherboard, RAM, Storage, NIC, Validation
    $completedSteps = 0;
    
    if (!empty($config['cpu_uuid'])) $completedSteps++;
    if (!empty($config['motherboard_uuid'])) $completedSteps++;
    if (!empty($config['ram_configuration'])) $completedSteps++;
    if (!empty($config['storage_configuration'])) $completedSteps++;
    if (!empty($config['nic_configuration'])) $completedSteps++;
    
    // Check if ready for validation (minimum components)
    $readyForValidation = !empty($config['cpu_uuid']) && 
                         !empty($config['motherboard_uuid']) && 
                         !empty($config['ram_configuration']) && 
                         !empty($config['storage_configuration']);
    
    if ($readyForValidation) $completedSteps++;
    
    return [
        'total_steps' => $totalSteps,
        'completed_steps' => $completedSteps,
        'percentage' => round(($completedSteps / $totalSteps) * 100),
        'current_phase' => $completedSteps < 4 ? 'component_selection' : ($readyForValidation ? 'validation' : 'optimization'),
        'ready_for_validation' => $readyForValidation,
        'ready_for_finalization' => $completedSteps >= 5
    ];
}
?>
            // Update configuration in database
            $config->setData($serverBuilder->getCurrentConfiguration());
            $config->set('updated_by', $user['id']);
            $config->save();
            
            // Determine next step and options
            $nextStep = determineNextStep($serverBuilder);
            $nextOptions = $nextStep ? $serverBuilder->getCompatibleComponentsForType($nextStep) : [];
            
            // Calculate progress
            $progress = calculateProgress($serverBuilder);
            
            send_json_response(1, 1, 200, $result['message'], [
                'component_added' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid
                ],
                'current_configuration' => $result['current_configuration'],
                'compatibility_results' => $result['compatibility_results'] ?? [],
                'next_step' => $nextStep,
                'next_options' => $nextOptions,
                'remaining_options' => $result['compatible_components'] ?? [],
                'progress' => $progress,
                'recommendations' => $result['next_recommendations'] ?? []
            ]);
        } else {
            send_json_response(0, 1, 409, $result['message'], [
                'compatibility_issues' => $result['compatibility_issues'] ?? [],
                'can_override' => $result['can_override'] ?? false,
                'current_configuration' => $serverBuilder->getCurrentConfigurationSummary()
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Error adding component in step process: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to add component");
    }
}

/**
 * Remove component in step-by-step process
 */
function handleStepRemoveComponent() {
    global $pdo, $user;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? null;
    
    if (empty($configUuid) || empty($componentType)) {
        send_json_response(0, 1, 400, "Config UUID and component type are required");
    }
    
    try {
        // Load existing configuration
        $config = ServerConfiguration::loadFromDatabase($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Initialize server builder with current config
        $serverBuilder = new ServerBuilder($pdo);
        $serverBuilder->setCurrentConfiguration($config->getData());
        
        // Remove component
        $result = $serverBuilder->removeComponent($componentType, $componentUuid);
        
        if ($result['success']) {
            // Update configuration in database
            $config->setData($serverBuilder->getCurrentConfiguration());
            $config->set('updated_by', $user['id']);
            $config->save();
            
            // Recalculate next step and options
            $nextStep = determineNextStep($serverBuilder);
            $allOptions = $serverBuilder->getCompatibleComponentsForAll();
            
            // Calculate progress
            $progress = calculateProgress($serverBuilder);
            
            send_json_response(1, 1, 200, $result['message'], [
                'component_removed' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid
                ],
                'current_configuration' => $serverBuilder->getCurrentConfigurationSummary(),
                'next_step' => $nextStep,
                'all_options' => $allOptions,
                'progress' => $progress,
                'updated_compatible_components' => $result['updated_compatible_components'] ?? []
            ]);
        } else {
            send_json_response(0, 1, 400, $result['message'], [
                'current_configuration' => $serverBuilder->getCurrentConfigurationSummary()
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Error removing component in step process: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to remove component");
    }
}