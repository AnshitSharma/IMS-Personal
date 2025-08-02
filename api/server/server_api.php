<?php
/**
 * Infrastructure Management System - Server Creation API
 * File: api/server/server_api.php
 * 
 * Handles all server creation, component selection, and compatibility operations
 */

// Include required files
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');
require_once(__DIR__ . '/../../includes/models/ServerBuilder.php');
require_once(__DIR__ . '/../../includes/models/CompatibilityEngine.php');

// Get action parameter
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (empty($action)) {
    send_json_response(0, 0, 400, "Action parameter is required");
}

// Initialize server builder
$serverBuilder = new ServerBuilder($pdo);

// Parse action
$parts = explode('-', $action, 2);
$module = $parts[0] ?? '';
$operation = $parts[1] ?? '';

if ($module !== 'server') {
    send_json_response(0, 0, 400, "Invalid module: $module");
}

try {
    switch ($operation) {
        case 'create-start':
            handleServerCreateStart($serverBuilder);
            break;
            
        case 'add-component':
            handleAddComponent($serverBuilder);
            break;
            
        case 'remove-component':
            handleRemoveComponent($serverBuilder);
            break;
            
        case 'get-compatible':
            handleGetCompatibleComponents($serverBuilder);
            break;
            
        case 'validate-config':
            handleValidateConfiguration($serverBuilder);
            break;
            
        case 'save-config':
            handleSaveConfiguration($serverBuilder);
            break;
            
        case 'load-config':
            handleLoadConfiguration($serverBuilder);
            break;
            
        case 'list-configs':
            handleListConfigurations($serverBuilder);
            break;
            
        case 'delete-config':
            handleDeleteConfiguration($serverBuilder);
            break;
            
        case 'clone-config':
            handleCloneConfiguration($serverBuilder);
            break;
            
        case 'get-statistics':
            handleGetStatistics($serverBuilder);
            break;
            
        case 'compatibility-check':
            handleCompatibilityCheck();
            break;
            
        default:
            send_json_response(0, 0, 400, "Unknown operation: $operation");
    }
    
} catch (Exception $e) {
    error_log("Server API error: " . $e->getMessage());
    send_json_response(0, 0, 500, "Internal server error");
}

/**
 * Start new server creation process
 */
function handleServerCreateStart($serverBuilder) {
    $configName = $_POST['config_name'] ?? '';
    $description = $_POST['description'] ?? '';
    
    $result = $serverBuilder->startServerCreation($configName, $description);
    
    if ($result['success']) {
        // Get all available components for initial selection
        $compatibleComponents = $serverBuilder->getCompatibleComponentsForAll();
        
        send_json_response(1, [
            'session_id' => $result['session_id'],
            'config_uuid' => $result['config_uuid'],
            'message' => $result['message'],
            'available_components' => $compatibleComponents,
            'configuration_summary' => $serverBuilder->getCurrentConfigurationSummary()
        ], 200, "Server creation started successfully");
    } else {
        send_json_response(0, $result, 400, $result['message']);
    }
}

/**
 * Add component to server configuration
 */
function handleAddComponent($serverBuilder) {
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? '';
    $override = filter_var($_POST['override'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $quantity = intval($_POST['quantity'] ?? 1);
    $slotPosition = $_POST['slot_position'] ?? null;
    $notes = $_POST['notes'] ?? null;
    
    if (empty($componentType) || empty($componentUuid)) {
        send_json_response(0, 0, 400, "Component type and UUID are required");
    }
    
    $options = [
        'override' => $override,
        'quantity' => $quantity,
        'slot_position' => $slotPosition,
        'notes' => $notes
    ];
    
    $result = $serverBuilder->addComponent($componentType, $componentUuid, $options);
    
    if ($result['success']) {
        send_json_response(1, $result, 200, $result['message']);
    } else {
        $statusCode = isset($result['compatibility_issues']) ? 409 : 400; // 409 for compatibility conflicts
        send_json_response(0, $result, $statusCode, $result['message']);
    }
}

/**
 * Remove component from server configuration
 */
function handleRemoveComponent($serverBuilder) {
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? null; // Optional for multi-component types
    
    if (empty($componentType)) {
        send_json_response(0, 0, 400, "Component type is required");
    }
    
    $result = $serverBuilder->removeComponent($componentType, $componentUuid);
    
    if ($result['success']) {
        send_json_response(1, $result, 200, $result['message']);
    } else {
        send_json_response(0, $result, 400, $result['message']);
    }
}

/**
 * Get compatible components for a specific type
 */
function handleGetCompatibleComponents($serverBuilder) {
    $componentType = $_GET['component_type'] ?? $_POST['component_type'] ?? '';
    $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($componentType)) {
        send_json_response(0, 0, 400, "Component type is required");
    }
    
    $validTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
    if (!in_array($componentType, $validTypes)) {
        send_json_response(0, 0, 400, "Invalid component type");
    }
    
    try {
        $compatibleComponents = $serverBuilder->getCompatibleComponentsForType($componentType, $availableOnly);
        
        send_json_response(1, [
            'component_type' => $componentType,
            'components' => $compatibleComponents,
            'total_count' => count($compatibleComponents),
            'configuration_summary' => $serverBuilder->getCurrentConfigurationSummary()
        ], 200, "Compatible components retrieved successfully");
        
    } catch (Exception $e) {
        error_log("Error getting compatible components: " . $e->getMessage());
        send_json_response(0, 0, 500, "Error retrieving compatible components");
    }
}

/**
 * Validate complete server configuration
 */
function handleValidateConfiguration($serverBuilder) {
    $configUuid = $_POST['config_uuid'] ?? $_GET['config_uuid'] ?? null;
    
    $result = $serverBuilder->validateConfiguration($configUuid);
    
    if ($result['valid']) {
        send_json_response(1, $result, 200, "Configuration is valid");
    } else {
        send_json_response(0, $result, 422, "Configuration has validation errors"); // 422 Unprocessable Entity
    }
}

/**
 * Save server configuration
 */
function handleSaveConfiguration($serverBuilder) {
    $configName = $_POST['config_name'] ?? '';
    $description = $_POST['description'] ?? '';
    $status = intval($_POST['status'] ?? 1); // 0=Draft, 1=Validated, 2=Built, 3=Deployed
    
    if (empty($configName)) {
        send_json_response(0, 0, 400, "Configuration name is required");
    }
    
    $result = $serverBuilder->saveConfiguration($configName, $description, $status);
    
    if ($result['success']) {
        send_json_response(1, $result, 200, $result['message']);
    } else {
        send_json_response(0, $result, 400, $result['message']);
    }
}

/**
 * Load existing server configuration
 */
function handleLoadConfiguration($serverBuilder) {
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 0, 400, "Configuration UUID is required");
    }
    
    $config = $serverBuilder->loadConfiguration($configUuid);
    
    if ($config) {
        // Set the loaded configuration as current
        $serverBuilder->setCurrentConfiguration($config);
        
        // Get compatible components for remaining types
        $compatibleComponents = $serverBuilder->getCompatibleComponentsForAll();
        
        send_json_response(1, [
            'configuration' => $config,
            'configuration_summary' => $serverBuilder->getCurrentConfigurationSummary(),
            'compatible_components' => $compatibleComponents,
            'statistics' => $serverBuilder->getConfigurationStatistics()
        ], 200, "Configuration loaded successfully");
    } else {
        send_json_response(0, 0, 404, "Configuration not found");
    }
}

/**
 * List saved server configurations
 */
function handleListConfigurations($serverBuilder) {
    $userId = $_GET['user_id'] ?? $_POST['user_id'] ?? null;
    $status = $_GET['status'] ?? $_POST['status'] ?? null;
    $page = intval($_GET['page'] ?? $_POST['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? $_POST['limit'] ?? 50);
    
    // If no specific user requested and not admin, show only current user's configs
    if (!$userId && !hasPermission('server.view_all')) {
        $userId = $_SESSION['user_id'] ?? null;
    }
    
    try {
        $configurations = $serverBuilder->getConfigurationList($userId, $status);
        
        // Apply pagination
        $total = count($configurations);
        $offset = ($page - 1) * $limit;
        $paginatedConfigs = array_slice($configurations, $offset, $limit);
        
        // Add summary information to each configuration
        foreach ($paginatedConfigs as &$config) {
            $config['component_summary'] = [
                'cpu_selected' => !empty($config['cpu_uuid']),
                'motherboard_selected' => !empty($config['motherboard_uuid']),
                'ram_count' => count(json_decode($config['ram_configuration'] ?? '[]', true)),
                'storage_count' => count(json_decode($config['storage_configuration'] ?? '[]', true)),
                'nic_count' => count(json_decode($config['nic_configuration'] ?? '[]', true)),
                'caddy_count' => count(json_decode($config['caddy_configuration'] ?? '[]', true))
            ];
            
            // Remove detailed JSON data for list view
            unset($config['ram_configuration']);
            unset($config['storage_configuration']);
            unset($config['nic_configuration']);
            unset($config['caddy_configuration']);
            unset($config['additional_components']);
            unset($config['validation_results']);
        }
        
        send_json_response(1, [
            'configurations' => $paginatedConfigs,
            'pagination' => [
                'current_page' => $page,
                'total_items' => $total,
                'items_per_page' => $limit,
                'total_pages' => ceil($total / $limit)
            ],
            'filters' => [
                'user_id' => $userId,
                'status' => $status
            ]
        ], 200, "Configurations retrieved successfully");
        
    } catch (Exception $e) {
        error_log("Error listing configurations: " . $e->getMessage());
        send_json_response(0, 0, 500, "Error retrieving configurations");
    }
}

/**
 * Delete server configuration
 */
function handleDeleteConfiguration($serverBuilder) {
    $configUuid = $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 0, 400, "Configuration UUID is required");
    }
    
    // Check permissions - users can only delete their own configs unless admin
    if (!hasPermission('server.delete_all')) {
        $config = $serverBuilder->loadConfiguration($configUuid);
        if (!$config || $config['created_by'] != ($_SESSION['user_id'] ?? null)) {
            send_json_response(0, 0, 403, "Permission denied");
        }
    }
    
    $result = $serverBuilder->deleteConfiguration($configUuid);
    
    if ($result['success']) {
        send_json_response(1, $result, 200, $result['message']);
    } else {
        send_json_response(0, $result, 400, $result['message']);
    }
}

/**
 * Clone existing server configuration
 */
function handleCloneConfiguration($serverBuilder) {
    $sourceConfigUuid = $_POST['source_config_uuid'] ?? '';
    $newConfigName = $_POST['new_config_name'] ?? '';
    
    if (empty($sourceConfigUuid)) {
        send_json_response(0, 0, 400, "Source configuration UUID is required");
    }
    
    if (empty($newConfigName)) {
        send_json_response(0, 0, 400, "New configuration name is required");
    }
    
    $result = $serverBuilder->cloneConfiguration($sourceConfigUuid, $newConfigName);
    
    if ($result['success']) {
        send_json_response(1, $result, 200, $result['message']);
    } else {
        send_json_response(0, $result, 400, $result['message']);
    }
}

/**
 * Get configuration statistics
 */
function handleGetStatistics($serverBuilder) {
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? null;
    
    if ($configUuid) {
        // Load specific configuration first
        $config = $serverBuilder->loadConfiguration($configUuid);
        if (!$config) {
            send_json_response(0, 0, 404, "Configuration not found");
        }
        $serverBuilder->setCurrentConfiguration($config);
    }
    
    try {
        $statistics = $serverBuilder->getConfigurationStatistics();
        
        // Add system-wide statistics if user has permission
        if (hasPermission('server.view_statistics')) {
            $systemStats = getSystemStatistics($pdo);
            $statistics['system_statistics'] = $systemStats;
        }
        
        send_json_response(1, $statistics, 200, "Statistics retrieved successfully");
        
    } catch (Exception $e) {
        error_log("Error getting statistics: " . $e->getMessage());
        send_json_response(0, 0, 500, "Error retrieving statistics");
    }
}

/**
 * Direct compatibility check between two components
 */
function handleCompatibilityCheck() {
    $component1Type = $_POST['component1_type'] ?? '';
    $component1Uuid = $_POST['component1_uuid'] ?? '';
    $component2Type = $_POST['component2_type'] ?? '';
    $component2Uuid = $_POST['component2_uuid'] ?? '';
    
    if (empty($component1Type) || empty($component1Uuid) || empty($component2Type) || empty($component2Uuid)) {
        send_json_response(0, 0, 400, "All component parameters are required");
    }
    
    try {
        $compatibilityEngine = new CompatibilityEngine($GLOBALS['pdo']);
        
        $component1 = ['type' => $component1Type, 'uuid' => $component1Uuid];
        $component2 = ['type' => $component2Type, 'uuid' => $component2Uuid];
        
        $result = $compatibilityEngine->checkCompatibility($component1, $component2);
        
        send_json_response(1, [
            'component_1' => $component1,
            'component_2' => $component2,
            'compatibility_result' => $result
        ], 200, "Compatibility check completed");
        
    } catch (Exception $e) {
        error_log("Error checking compatibility: " . $e->getMessage());
        send_json_response(0, 0, 500, "Error checking compatibility");
    }
}

/**
 * Get system-wide statistics
 */
function getSystemStatistics($pdo) {
    try {
        $stats = [];
        
        // Configuration counts by status
        $stmt = $pdo->prepare("
            SELECT configuration_status, COUNT(*) as count 
            FROM server_configurations 
            GROUP BY configuration_status
        ");
        $stmt->execute();
        $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $stats['configurations'] = [
            'total' => array_sum($statusCounts),
            'draft' => $statusCounts[0] ?? 0,
            'validated' => $statusCounts[1] ?? 0,
            'built' => $statusCounts[2] ?? 0,
            'deployed' => $statusCounts[3] ?? 0
        ];
        
        // Component availability counts
        $componentTables = [
            'cpu' => 'cpuinventory',
            'motherboard' => 'motherboardinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        $stats['component_availability'] = [];
        
        foreach ($componentTables as $type => $table) {
            $stmt = $pdo->prepare("
                SELECT Status, COUNT(*) as count 
                FROM {$table} 
                GROUP BY Status
            ");
            $stmt->execute();
            $componentCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $stats['component_availability'][$type] = [
                'total' => array_sum($componentCounts),
                'available' => $componentCounts[1] ?? 0,
                'in_use' => $componentCounts[2] ?? 0,
                'failed' => $componentCounts[0] ?? 0
            ];
        }
        
        // Recent activity
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as recent_configurations 
            FROM server_configurations 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $stats['recent_activity'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Compatibility statistics (if available)
        $compatibilityEngine = new CompatibilityEngine($pdo);
        $compatibilityStats = $compatibilityEngine->getCompatibilityStatistics();
        if ($compatibilityStats) {
            $stats['compatibility'] = $compatibilityStats;
        }
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Error getting system statistics: " . $e->getMessage());
        return [];
    }
}

/**
 * Helper function to check permissions
 */
function hasPermission($permission) {
    // This would integrate with your existing ACL system
    // For now, assuming basic permission check
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    // Add your permission checking logic here
    // Example: Check if user has the required permission
    try {
        global $pdo;
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM role_permissions rp
            JOIN user_roles ur ON rp.role_id = ur.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE ur.user_id = ? AND p.name = ? AND rp.granted = 1
        ");
        $stmt->execute([$_SESSION['user_id'], $permission]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        error_log("Permission check error: " . $e->getMessage());
        return false;
    }
}
?>