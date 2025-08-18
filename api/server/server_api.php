<?php
/**
 * Infrastructure Management System - Server API Handler
 * File: api/server/server_api.php
 * 
 * Handles all server creation, component selection, and compatibility operations
 */

// Include required files
require_once(__DIR__ . '/../../includes/models/ServerBuilder.php');
require_once(__DIR__ . '/../../includes/models/CompatibilityEngine.php');
require_once(__DIR__ . '/../../includes/models/ServerConfiguration.php');

// Get globals set by main API
global $pdo, $user, $operation;

try {
    // Initialize server builder
    $serverBuilder = new ServerBuilder($pdo);
    
    switch ($operation) {
        case 'create-start':
            handleServerCreateStart($serverBuilder, $user);
            break;
            
        case 'add-component':
            handleAddComponent($serverBuilder, $user);
            break;
            
        case 'remove-component':
            handleRemoveComponent($serverBuilder, $user);
            break;
            
        case 'get-compatible':
            handleGetCompatibleComponents($serverBuilder, $user);
            break;
            
        case 'validate-config':
            handleValidateConfiguration($serverBuilder, $user);
            break;
            
        case 'save-config':
            handleSaveConfiguration($serverBuilder, $user);
            break;
            
        case 'load-config':
            handleLoadConfiguration($serverBuilder, $user);
            break;
            
        case 'list-configs':
            handleListConfigurations($serverBuilder, $user);
            break;
            
        case 'delete-config':
            handleDeleteConfiguration($serverBuilder, $user);
            break;
            
        case 'clone-config':
            handleCloneConfiguration($serverBuilder, $user);
            break;
            
        case 'get-statistics':
            handleGetStatistics($serverBuilder, $user);
            break;
            
        case 'update-config':
            handleUpdateConfiguration($serverBuilder, $user);
            break;
            
        case 'get-components':
            handleGetComponents($serverBuilder, $user);
            break;
            
        case 'export-config':
            handleExportConfiguration($serverBuilder, $user);
            break;
            
        default:
            send_json_response(0, 1, 400, "Unknown server operation: $operation");
    }
    
} catch (Exception $e) {
    error_log("Server API error: " . $e->getMessage());
    send_json_response(0, 1, 500, "Server operation failed: " . $e->getMessage());
}

/**
 * Start new server creation process
 */
function handleServerCreateStart($serverBuilder, $user) {
    global $pdo;
    
    $serverName = trim($_POST['server_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $startWith = $_POST['start_with'] ?? 'cpu';
    
    error_log("Server creation start - Name: $serverName, Start with: $startWith");
    
    if (empty($serverName)) {
        send_json_response(0, 1, 400, "Server name is required");
    }
    
    try {
        // Create new server configuration
        $configData = [
            'server_name' => $serverName,
            'description' => $description,
            'created_by' => $user['id'],
            'configuration_status' => 0, // Draft status
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $config = ServerConfiguration::create($pdo, $configData);
        
        if (!$config) {
            throw new Exception("Failed to create server configuration");
        }
        
        // Get initial component options based on starting preference
        $initialOptions = [];
        $availableComponents = [];
        
        // Get components for the starting component type
        if ($startWith === 'cpu') {
            $availableComponents['cpu'] = getAvailableComponents($pdo, 'cpu');
        } elseif ($startWith === 'motherboard') {
            $availableComponents['motherboard'] = getAvailableComponents($pdo, 'motherboard');
        } else {
            // Default to showing both CPU and motherboard options
            $availableComponents['cpu'] = getAvailableComponents($pdo, 'cpu');
            $availableComponents['motherboard'] = getAvailableComponents($pdo, 'motherboard');
        }
        
        // Also get counts for other component types for UI display
        $componentCounts = [];
        $componentTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
        
        foreach ($componentTypes as $type) {
            $componentCounts[$type] = getComponentCount($pdo, $type);
        }
        
        send_json_response(1, 1, 200, "Server creation initialized successfully", [
            'config_uuid' => $config->get('config_uuid'),
            'server_name' => $serverName,
            'description' => $description,
            'starting_with' => $startWith,
            'available_components' => $availableComponents,
            'component_counts' => $componentCounts,
            'workflow_step' => 1,
            'next_recommended' => $startWith === 'cpu' ? 'motherboard' : 'cpu',
            'progress' => [
                'total_steps' => 6,
                'completed_steps' => 0,
                'current_step' => 'component_selection',
                'components_added' => []
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
        
        // Add the component
        $result = $serverBuilder->addComponent($configUuid, $componentType, $componentUuid, [
            'quantity' => $quantity,
            'slot_position' => $slotPosition,
            'notes' => $notes
        ]);
        
        if ($result['success']) {
            // Get updated configuration summary
            $summary = $serverBuilder->getConfigurationSummary($configUuid);
            
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
                    'quantity' => $quantity
                ],
                'configuration_summary' => $summary,
                'next_recommendations' => $nextRecommendations,
                'compatibility_issues' => $result['compatibility_issues'] ?? []
            ]);
        } else {
            $statusCode = isset($result['compatibility_issues']) ? 409 : 400;
            send_json_response(0, 1, $statusCode, $result['message'], [
                'compatibility_issues' => $result['compatibility_issues'] ?? [],
                'suggested_alternatives' => $result['suggested_alternatives'] ?? []
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Error adding component: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to add component");
    }
}

/**
 * Remove component from server configuration
 */
function handleRemoveComponent($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $componentType = $_POST['component_type'] ?? '';
    $componentUuid = $_POST['component_uuid'] ?? null;
    
    if (empty($configUuid) || empty($componentType)) {
        send_json_response(0, 1, 400, "Configuration UUID and component type are required");
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
            send_json_response(0, 1, 400, $result['message']);
        }
        
    } catch (Exception $e) {
        error_log("Error removing component: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to remove component");
    }
}

/**
 * Get compatible components for current configuration
 */
function handleGetCompatibleComponents($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    $componentType = $_GET['component_type'] ?? $_POST['component_type'] ?? '';
    $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Load the configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to view this configuration");
        }
        
        $compatibleComponents = [];
        
        if (class_exists('CompatibilityEngine')) {
            $compatibilityEngine = new CompatibilityEngine($pdo);
            
            if ($componentType) {
                $compatibleComponents[$componentType] = $compatibilityEngine->getCompatibleComponentsForConfiguration(
                    $configUuid, 
                    $componentType, 
                    $availableOnly
                );
            } else {
                // Get compatible components for all types
                $componentTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
                foreach ($componentTypes as $type) {
                    $compatibleComponents[$type] = $compatibilityEngine->getCompatibleComponentsForConfiguration(
                        $configUuid, 
                        $type, 
                        $availableOnly
                    );
                }
            }
        } else {
            // Fallback: return all available components if compatibility engine not available
            if ($componentType) {
                $compatibleComponents[$componentType] = getAvailableComponents($pdo, $componentType, $availableOnly);
            } else {
                $componentTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
                foreach ($componentTypes as $type) {
                    $compatibleComponents[$type] = getAvailableComponents($pdo, $type, $availableOnly);
                }
            }
        }
        
        send_json_response(1, 1, 200, "Compatible components retrieved", [
            'config_uuid' => $configUuid,
            'component_type' => $componentType ?: 'all',
            'compatible_components' => $compatibleComponents,
            'compatibility_engine_used' => class_exists('CompatibilityEngine'),
            'available_only' => $availableOnly
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting compatible components: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatible components");
    }
}

/**
 * Validate server configuration
 */
function handleValidateConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? $_GET['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Load configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to validate this configuration");
        }
        
        $validationResult = $serverBuilder->validateConfiguration($configUuid);
        
        send_json_response(1, 1, 200, "Configuration validation completed", [
            'config_uuid' => $configUuid,
            'validation_result' => $validationResult,
            'is_valid' => $validationResult['is_valid'],
            'compatibility_score' => $validationResult['compatibility_score'] ?? 0,
            'issues' => $validationResult['issues'] ?? [],
            'warnings' => $validationResult['warnings'] ?? [],
            'recommendations' => $validationResult['recommendations'] ?? []
        ]);
        
    } catch (Exception $e) {
        error_log("Error validating configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to validate configuration");
    }
}

/**
 * Save server configuration
 */
function handleSaveConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $serverName = trim($_POST['server_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = (int)($_POST['status'] ?? 0);
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Load configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.edit_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to save this configuration");
        }
        
        // Update configuration
        $updateData = [];
        if (!empty($serverName)) {
            $updateData['server_name'] = $serverName;
        }
        if (!empty($description)) {
            $updateData['description'] = $description;
        }
        if ($status >= 0) {
            $updateData['configuration_status'] = $status;
        }
        $updateData['updated_at'] = date('Y-m-d H:i:s');
        
        $result = $config->update($updateData);
        
        if ($result) {
            // Log activity
            logActivity($pdo, $user['id'], "Configuration saved", 'server_management', $configUuid, "Saved server configuration: $serverName");
            
            send_json_response(1, 1, 200, "Configuration saved successfully", [
                'config_uuid' => $configUuid,
                'server_name' => $serverName,
                'status' => $status,
                'updated_at' => $updateData['updated_at']
            ]);
        } else {
            send_json_response(0, 1, 500, "Failed to save configuration");
        }
        
    } catch (Exception $e) {
        error_log("Error saving configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to save configuration");
    }
}

/**
 * Load server configuration
 */
function handleLoadConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Load configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to view this configuration");
        }
        
        // Get full configuration details
        $configData = $config->getData();
        $summary = $serverBuilder->getConfigurationSummary($configUuid);
        
        send_json_response(1, 1, 200, "Configuration loaded successfully", [
            'configuration' => $configData,
            'summary' => $summary,
            'can_edit' => ($config->get('created_by') == $user['id'] || hasPermission($pdo, 'server.edit_all', $user['id']))
        ]);
        
    } catch (Exception $e) {
        error_log("Error loading configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to load configuration");
    }
}

/**
 * List server configurations
 */
function handleListConfigurations($serverBuilder, $user) {
    global $pdo;
    
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 20);
    $status = $_GET['status'] ?? null;
    $search = $_GET['search'] ?? '';
    $userOnly = filter_var($_GET['user_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    try {
        $conditions = [];
        $params = [];
        
        // User filtering
        if ($userOnly || !hasPermission($pdo, 'server.view_all', $user['id'])) {
            $conditions[] = "created_by = ?";
            $params[] = $user['id'];
        }
        
        // Status filtering
        if ($status !== null) {
            $conditions[] = "configuration_status = ?";
            $params[] = $status;
        }
        
        // Search filtering
        if (!empty($search)) {
            $conditions[] = "(server_name LIKE ? OR description LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM server_configurations $whereClause";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetchColumn();
        
        // Get configurations
        $offset = ($page - 1) * $limit;
        $sql = "SELECT * FROM server_configurations $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $configurations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        send_json_response(1, 1, 200, "Configurations retrieved successfully", [
            'configurations' => $configurations,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($totalCount / $limit),
                'total_count' => $totalCount,
                'per_page' => $limit
            ],
            'filters' => [
                'status' => $status,
                'search' => $search,
                'user_only' => $userOnly
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error listing configurations: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to list configurations");
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
        // Load configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.delete_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to delete this configuration");
        }
        
        $serverName = $config->get('server_name');
        
        if ($config->delete()) {
            logActivity($pdo, $user['id'], "Configuration deleted", 'server_management', $configUuid, "Deleted server configuration: $serverName");
            send_json_response(1, 1, 200, "Configuration deleted successfully");
        } else {
            send_json_response(0, 1, 500, "Failed to delete configuration");
        }
        
    } catch (Exception $e) {
        error_log("Error deleting configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to delete configuration");
    }
}

/**
 * Clone server configuration
 */
function handleCloneConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_POST['config_uuid'] ?? '';
    $newServerName = trim($_POST['new_server_name'] ?? '');
    $newDescription = trim($_POST['new_description'] ?? '');
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    if (empty($newServerName)) {
        send_json_response(0, 1, 400, "New server name is required");
    }
    
    try {
        // Load original configuration
        $originalConfig = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$originalConfig) {
            send_json_response(0, 1, 404, "Original configuration not found");
        }
        
        // Check permissions
        if ($originalConfig->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to clone this configuration");
        }
        
        $clonedConfig = $serverBuilder->cloneConfiguration($configUuid, $newServerName, $newDescription, $user['id']);
        
        if ($clonedConfig) {
            logActivity($pdo, $user['id'], "Configuration cloned", 'server_management', $clonedConfig->get('config_uuid'), "Cloned configuration to: $newServerName");
            
            send_json_response(1, 1, 200, "Configuration cloned successfully", [
                'original_config_uuid' => $configUuid,
                'new_config_uuid' => $clonedConfig->get('config_uuid'),
                'new_server_name' => $newServerName
            ]);
        } else {
            send_json_response(0, 1, 500, "Failed to clone configuration");
        }
        
    } catch (Exception $e) {
        error_log("Error cloning configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to clone configuration");
    }
}

/**
 * Get server statistics
 */
function handleGetStatistics($serverBuilder, $user) {
    global $pdo;
    
    try {
        $stats = getServerConfigurationStats($pdo);
        
        send_json_response(1, 1, 200, "Server statistics retrieved", $stats);
        
    } catch (Exception $e) {
        error_log("Error getting server statistics: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get server statistics");
    }
}

/**
 * Update server configuration
 */
function handleUpdateConfiguration($serverBuilder, $user) {
    // This is similar to save but for partial updates
    handleSaveConfiguration($serverBuilder, $user);
}

/**
 * Get available components
 */
function handleGetComponents($serverBuilder, $user) {
    global $pdo;
    
    $componentType = $_GET['component_type'] ?? $_POST['component_type'] ?? '';
    $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    try {
        if ($componentType) {
            $components = getAvailableComponents($pdo, $componentType, $availableOnly);
            send_json_response(1, 1, 200, "Components retrieved", [
                'component_type' => $componentType,
                'components' => $components,
                'count' => count($components)
            ]);
        } else {
            $allComponents = [];
            $componentTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
            
            foreach ($componentTypes as $type) {
                $allComponents[$type] = getAvailableComponents($pdo, $type, $availableOnly);
            }
            
            send_json_response(1, 1, 200, "All components retrieved", [
                'components' => $allComponents,
                'available_only' => $availableOnly
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Error getting components: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get components");
    }
}

/**
 * Export server configuration
 */
function handleExportConfiguration($serverBuilder, $user) {
    global $pdo;
    
    $configUuid = $_GET['config_uuid'] ?? $_POST['config_uuid'] ?? '';
    $format = $_GET['format'] ?? $_POST['format'] ?? 'json';
    
    if (empty($configUuid)) {
        send_json_response(0, 1, 400, "Configuration UUID is required");
    }
    
    try {
        // Load configuration
        $config = ServerConfiguration::loadByUuid($pdo, $configUuid);
        if (!$config) {
            send_json_response(0, 1, 404, "Server configuration not found");
        }
        
        // Check permissions
        if ($config->get('created_by') != $user['id'] && !hasPermission($pdo, 'server.view_all', $user['id'])) {
            send_json_response(0, 1, 403, "Insufficient permissions to export this configuration");
        }
        
        $exportData = $serverBuilder->exportConfiguration($configUuid, $format);
        
        send_json_response(1, 1, 200, "Configuration exported successfully", [
            'config_uuid' => $configUuid,
            'format' => $format,
            'export_data' => $exportData,
            'exported_at' => date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $e) {
        error_log("Error exporting configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to export configuration");
    }
}

/**
 * Helper function to get available components
 */
function getAvailableComponents($pdo, $componentType, $availableOnly = true) {
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
    $whereClause = $availableOnly ? "WHERE Status = 1" : "";
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM $table $whereClause ORDER BY SerialNumber");
        $stmt->execute();
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
        return 0;
    }
    
    $table = $tableMap[$componentType];
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN Status = 1 THEN 1 ELSE 0 END) as available FROM $table");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error getting component count for $componentType: " . $e->getMessage());
        return ['total' => 0, 'available' => 0];
    }
}
?>