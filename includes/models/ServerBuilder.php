<?php

class ServerBuilder {
    private $pdo;
    private $componentTables = [
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory'
    ];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Create a new server configuration
     */
    public function createConfiguration($serverName, $userId, $options = []) {
        try {
            $configUuid = $this->generateUuid();
            $description = $options['description'] ?? '';
            $category = $options['category'] ?? 'custom';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO server_configurations 
                (config_uuid, server_name, description, category, configuration_status, created_by, created_at, updated_at) 
                VALUES (?, ?, ?, ?, 0, ?, NOW(), NOW())
            ");
            
            $stmt->execute([
                $configUuid,
                $serverName,
                $description,
                $category,
                $userId
            ]);
            
            // Log the creation
            $this->logConfigurationAction($configUuid, 'create', 'configuration', null, [
                'server_name' => $serverName,
                'description' => $description,
                'category' => $category
            ], $userId);
            
            return $configUuid;
            
        } catch (Exception $e) {
            error_log("Error creating server configuration: " . $e->getMessage());
            throw new Exception("Failed to create server configuration");
        }
    }
    
    /**
     * Add component to configuration
     */
    public function addComponent($configUuid, $componentType, $componentUuid, $options = []) {
        try {
            // Validate inputs
            if (!$this->isValidComponentType($componentType)) {
                return [
                    'success' => false,
                    'message' => "Invalid component type: $componentType"
                ];
            }
            
            // Get component details
            $componentDetails = $this->getComponentByUuid($componentType, $componentUuid);
            if (!$componentDetails) {
                return [
                    'success' => false,
                    'message' => "Component not found"
                ];
            }
            
            // Enhanced availability check
            $availability = $this->checkComponentAvailability($componentDetails, $options);
            if (!$availability['available'] && !($options['override_used'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $availability['message'],
                    'component_status' => $availability['status'],
                    'can_override' => $availability['can_override']
                ];
            }
            
            // Check for existing component of same type (for single-instance components)
            if ($this->isSingleInstanceComponent($componentType)) {
                $existingComponent = $this->getConfigurationComponent($configUuid, $componentType);
                if ($existingComponent && !($options['replace'] ?? false)) {
                    return [
                        'success' => false,
                        'message' => "Component type $componentType already exists in configuration",
                        'existing_component' => $existingComponent,
                        'can_replace' => true
                    ];
                }
            }
            
            $quantity = $options['quantity'] ?? 1;
            $slotPosition = $options['slot_position'] ?? null;
            $notes = $options['notes'] ?? '';
            
            // Insert component into configuration
            $stmt = $this->pdo->prepare("
                INSERT INTO server_configuration_components 
                (config_uuid, component_type, component_uuid, quantity, slot_position, notes, added_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                quantity = quantity + VALUES(quantity),
                notes = VALUES(notes),
                updated_at = NOW()
            ");
            
            $stmt->execute([
                $configUuid,
                $componentType,
                $componentUuid,
                $quantity,
                $slotPosition,
                $notes
            ]);
            
            // Update component status if it was available
            if ($componentDetails['Status'] == 1) {
                $this->updateComponentStatus($componentType, $componentUuid, 2, "Added to server configuration $configUuid");
            }
            
            // Log the action
            $this->logConfigurationAction($configUuid, 'add_component', $componentType, $componentUuid, [
                'quantity' => $quantity,
                'slot_position' => $slotPosition,
                'notes' => $notes,
                'override_used' => $options['override_used'] ?? false
            ], $options['user_id'] ?? null);
            
            // Check compatibility if engine is available
            $compatibilityIssues = [];
            if (class_exists('ComponentCompatibility')) {
                $compatibilityEngine = new ComponentCompatibility($this->pdo);
                $compatibilityIssues = $this->checkConfigurationCompatibility($configUuid, $compatibilityEngine);
            }
            
            return [
                'success' => true,
                'message' => "Component added successfully",
                'component_added' => [
                    'type' => $componentType,
                    'uuid' => $componentUuid,
                    'quantity' => $quantity
                ],
                'compatibility_issues' => $compatibilityIssues
            ];
            
        } catch (Exception $e) {
            error_log("Error adding component to configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to add component: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Remove component from configuration
     */
    public function removeComponent($configUuid, $componentType, $componentUuid) {
        try {
            // Check if component exists in configuration
            $stmt = $this->pdo->prepare("
                SELECT * FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type = ? AND component_uuid = ?
            ");
            $stmt->execute([$configUuid, $componentType, $componentUuid]);
            $configComponent = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$configComponent) {
                return [
                    'success' => false,
                    'message' => "Component not found in configuration"
                ];
            }
            
            // Remove from configuration
            $stmt = $this->pdo->prepare("
                DELETE FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type = ? AND component_uuid = ?
            ");
            $stmt->execute([$configUuid, $componentType, $componentUuid]);
            
            // Update component status back to available
            $this->updateComponentStatus($componentType, $componentUuid, 1, "Removed from server configuration $configUuid");
            
            // Log the action
            $this->logConfigurationAction($configUuid, 'remove_component', $componentType, $componentUuid, [
                'quantity' => $configComponent['quantity']
            ]);
            
            return [
                'success' => true,
                'message' => "Component removed successfully"
            ];
            
        } catch (Exception $e) {
            error_log("Error removing component from configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to remove component: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get configuration summary
     */
    public function getConfigurationSummary($configUuid) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT scc.*, sc.server_name, sc.configuration_status
                FROM server_configuration_components scc
                JOIN server_configurations sc ON scc.config_uuid = sc.config_uuid
                WHERE scc.config_uuid = ?
                ORDER BY scc.component_type, scc.added_at
            ");
            $stmt->execute([$configUuid]);
            $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $summary = [
                'config_uuid' => $configUuid,
                'components' => [],
                'component_counts' => [],
                'total_components' => 0,
                'server_name' => '',
                'configuration_status' => 0
            ];
            
            foreach ($components as $component) {
                $type = $component['component_type'];
                
                if (!isset($summary['components'][$type])) {
                    $summary['components'][$type] = [];
                    $summary['component_counts'][$type] = 0;
                }
                
                // Get component details
                $componentDetails = $this->getComponentByUuid($type, $component['component_uuid']);
                if ($componentDetails) {
                    $component['details'] = $componentDetails;
                }
                
                $summary['components'][$type][] = $component;
                $summary['component_counts'][$type] += $component['quantity'];
                $summary['total_components'] += $component['quantity'];
                
                // Set server name and status from first component
                if (empty($summary['server_name'])) {
                    $summary['server_name'] = $component['server_name'];
                    $summary['configuration_status'] = $component['configuration_status'];
                }
            }
            
            return $summary;
            
        } catch (Exception $e) {
            error_log("Error getting configuration summary: " . $e->getMessage());
            return [
                'config_uuid' => $configUuid,
                'components' => [],
                'component_counts' => [],
                'total_components' => 0,
                'error' => 'Failed to load configuration summary'
            ];
        }
    }
    
    /**
     * Validate server configuration
     */
    public function validateConfiguration($configUuid) {
        try {
            $summary = $this->getConfigurationSummary($configUuid);
            
            $validation = [
                'is_valid' => true,
                'compatibility_score' => 100,
                'issues' => [],
                'warnings' => [],
                'recommendations' => []
            ];
            
            // Check for required components
            $requiredComponents = ['cpu', 'motherboard', 'ram'];
            foreach ($requiredComponents as $required) {
                if (!isset($summary['components'][$required]) || empty($summary['components'][$required])) {
                    $validation['is_valid'] = false;
                    $validation['issues'][] = "Missing required component: " . ucfirst($required);
                }
            }
            
            // Check for recommended components
            $recommendedComponents = ['storage'];
            foreach ($recommendedComponents as $recommended) {
                if (!isset($summary['components'][$recommended]) || empty($summary['components'][$recommended])) {
                    $validation['warnings'][] = "Missing recommended component: " . ucfirst($recommended);
                }
            }
            
            // Check component availability
            foreach ($summary['components'] as $type => $components) {
                foreach ($components as $component) {
                    if (isset($component['details'])) {
                        $status = $component['details']['Status'];
                        if ($status == 0) {
                            $validation['is_valid'] = false;
                            $validation['issues'][] = "Component $type ({$component['component_uuid']}) is marked as failed/defective";
                        }
                    }
                }
            }
            
            // Run compatibility checks if engine is available
            if (class_exists('ComponentCompatibility')) {
                $compatibilityEngine = new ComponentCompatibility($this->pdo);
                $compatibilityResults = $this->checkConfigurationCompatibility($configUuid, $compatibilityEngine);
                
                foreach ($compatibilityResults as $result) {
                    if (!$result['compatible']) {
                        $validation['is_valid'] = false;
                        $validation['issues'] = array_merge($validation['issues'], $result['issues']);
                    }
                    $validation['warnings'] = array_merge($validation['warnings'], $result['warnings']);
                }
            }
            
            return $validation;
            
        } catch (Exception $e) {
            error_log("Error validating configuration: " . $e->getMessage());
            return [
                'is_valid' => false,
                'compatibility_score' => 0,
                'issues' => ['Validation failed due to system error'],
                'warnings' => [],
                'recommendations' => []
            ];
        }
    }
    
    /**
     * Finalize configuration
     */
    public function finalizeConfiguration($configUuid, $notes = '') {
        try {
            // Validate configuration first
            $validation = $this->validateConfiguration($configUuid);
            if (!$validation['is_valid']) {
                return [
                    'success' => false,
                    'message' => "Configuration is not valid for finalization",
                    'validation_errors' => $validation['issues']
                ];
            }
            
            // Update configuration status
            $stmt = $this->pdo->prepare("
                UPDATE server_configurations 
                SET configuration_status = 3, finalization_notes = ?, finalized_at = NOW(), updated_at = NOW()
                WHERE config_uuid = ?
            ");
            $stmt->execute([$notes, $configUuid]);
            
            // Log the finalization
            $this->logConfigurationAction($configUuid, 'finalize', 'configuration', null, [
                'notes' => $notes,
                'validation_score' => $validation['compatibility_score']
            ]);
            
            return [
                'success' => true,
                'message' => "Configuration finalized successfully",
                'finalization_timestamp' => date('Y-m-d H:i:s'),
                'validation_score' => $validation['compatibility_score']
            ];
            
        } catch (Exception $e) {
            error_log("Error finalizing configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to finalize configuration: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete configuration
     */
    public function deleteConfiguration($configUuid) {
        try {
            $this->pdo->beginTransaction();
            
            // Get all components in the configuration
            $stmt = $this->pdo->prepare("
                SELECT component_type, component_uuid FROM server_configuration_components 
                WHERE config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Release components back to available status
            foreach ($components as $component) {
                $this->updateComponentStatus(
                    $component['component_type'], 
                    $component['component_uuid'], 
                    1, 
                    "Released from deleted configuration $configUuid"
                );
            }
            
            // Delete configuration components
            $stmt = $this->pdo->prepare("DELETE FROM server_configuration_components WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            
            // Delete configuration
            $stmt = $this->pdo->prepare("DELETE FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => "Configuration deleted successfully",
                'components_released' => count($components)
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollback();
            error_log("Error deleting configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to delete configuration: " . $e->getMessage()
            ];
        }
    }
    
    // Private helper methods
    
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    private function isValidComponentType($componentType) {
        return isset($this->componentTables[$componentType]);
    }
    
    private function isSingleInstanceComponent($componentType) {
        return in_array($componentType, ['cpu', 'motherboard']);
    }
    
    private function getComponentByUuid($componentType, $componentUuid) {
        if (!isset($this->componentTables[$componentType])) {
            return null;
        }
        
        try {
            $table = $this->componentTables[$componentType];
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
            $stmt->execute([$componentUuid]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting component by UUID: " . $e->getMessage());
            return null;
        }
    }
    
    private function checkComponentAvailability($componentDetails, $options = []) {
        $status = (int)$componentDetails['Status'];
        $result = [
            'available' => false,
            'status' => $status,
            'message' => '',
            'can_override' => false
        ];
        
        switch ($status) {
            case 0:
                $result['message'] = "Component is marked as Failed/Defective";
                $result['can_override'] = false;
                break;
            case 1:
                $result['available'] = true;
                $result['message'] = "Component is Available";
                break;
            case 2:
                $result['message'] = "Component is currently In Use";
                $result['can_override'] = true;
                break;
            default:
                $result['message'] = "Component has unknown status: $status";
                $result['can_override'] = false;
        }
        
        return $result;
    }
    
    private function getConfigurationComponent($configUuid, $componentType) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type = ?
            ");
            $stmt->execute([$configUuid, $componentType]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting configuration component: " . $e->getMessage());
            return null;
        }
    }
    
    private function updateComponentStatus($componentType, $componentUuid, $newStatus, $reason = '') {
        if (!isset($this->componentTables[$componentType])) {
            return false;
        }
        
        try {
            $table = $this->componentTables[$componentType];
            $stmt = $this->pdo->prepare("
                UPDATE $table 
                SET Status = ?, UpdatedAt = NOW() 
                WHERE UUID = ?
            ");
            $stmt->execute([$newStatus, $componentUuid]);
            
            // Log the status change if history table exists
            try {
                $historyTable = $table . '_history';
                $stmt = $this->pdo->prepare("
                    INSERT INTO $historyTable 
                    (component_uuid, action, old_status, new_status, reason, created_at) 
                    VALUES (?, 'status_change', (SELECT Status FROM $table WHERE UUID = ?), ?, ?, NOW())
                ");
                $stmt->execute([$componentUuid, $componentUuid, $newStatus, $reason]);
            } catch (Exception $e) {
                // History table might not exist, that's okay
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error updating component status: " . $e->getMessage());
            return false;
        }
    }
    
    private function logConfigurationAction($configUuid, $action, $componentType = null, $componentUuid = null, $details = [], $userId = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO server_configuration_history 
                (config_uuid, action, component_type, component_uuid, action_details, user_id, created_at) 
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
            error_log("Error logging configuration action: " . $e->getMessage());
        }
    }
    
    private function checkConfigurationCompatibility($configUuid, $compatibilityEngine) {
        try {
            $summary = $this->getConfigurationSummary($configUuid);
            $components = [];
            
            foreach ($summary['components'] as $type => $typeComponents) {
                foreach ($typeComponents as $component) {
                    $components[] = [
                        'type' => $type,
                        'uuid' => $component['component_uuid'],
                        'details' => $component['details'] ?? null
                    ];
                }
            }
            
            if (count($components) < 2) {
                return []; // Need at least 2 components to check compatibility
            }
            
            return $compatibilityEngine->validateComponentConfiguration($components);
            
        } catch (Exception $e) {
            error_log("Error checking configuration compatibility: " . $e->getMessage());
            return [];
        }
    }
}

?>


