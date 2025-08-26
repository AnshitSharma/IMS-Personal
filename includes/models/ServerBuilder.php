<?php

class ServerBuilder {
    
    private $pdo;
    private $componentTables;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->componentTables = [
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
    }
    
    /**
     * Create a new server configuration
     */
    public function createConfiguration($serverName, $createdBy, $options = []) {
        try {
            $configUuid = $this->generateUuid();
            $description = $options['description'] ?? '';
            $category = $options['category'] ?? 'custom';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO server_configurations 
                (config_uuid, server_name, description, category, created_by, created_at, updated_at, configuration_status) 
                VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 0)
            ");
            
            $stmt->execute([$configUuid, $serverName, $description, $category, $createdBy]);
            
            return $configUuid;
            
        } catch (Exception $e) {
            error_log("Error creating server configuration: " . $e->getMessage());
            throw new Exception("Failed to create server configuration: " . $e->getMessage());
        }
    }
    
    /**
     * Add component to server configuration with proper database updates
     */
    public function addComponent($configUuid, $componentType, $componentUuid, $options = []) {
        try {
            // Validate component type
            if (!$this->isValidComponentType($componentType)) {
                return [
                    'success' => false,
                    'message' => "Invalid component type: $componentType"
                ];
            }
            
            // Get component details to validate existence and check status
            $componentDetails = $this->getComponentByUuid($componentType, $componentUuid);
            if (!$componentDetails) {
                return [
                    'success' => false,
                    'message' => "Component not found: $componentUuid"
                ];
            }
            
            // Check availability
            $availability = $this->checkComponentAvailability($componentDetails, $configUuid, $options);
            if (!$availability['available'] && !($options['override_used'] ?? false)) {
                return [
                    'success' => false,
                    'message' => $availability['message'],
                    'availability_details' => $availability
                ];
            }
            
            // For single-instance components, check if already exists in config
            if ($this->isSingleInstanceComponent($componentType)) {
                $existingComponent = $this->getConfigurationComponent($configUuid, $componentType);
                if ($existingComponent) {
                    return [
                        'success' => false,
                        'message' => "Configuration already has a $componentType. Remove existing component first."
                    ];
                }
            }
            
            // Begin transaction
            $this->pdo->beginTransaction();
            
            // Add component to configuration_components table
            $quantity = $options['quantity'] ?? 1;
            $slotPosition = $options['slot_position'] ?? null;
            $notes = $options['notes'] ?? '';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO server_configuration_components 
                (config_uuid, component_type, component_uuid, quantity, slot_position, notes, added_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$configUuid, $componentType, $componentUuid, $quantity, $slotPosition, $notes]);
            
            // Update component status to "In Use" AND set ServerUUID to config_uuid
            $this->updateComponentStatusAndServerUuid($componentType, $componentUuid, 2, $configUuid, "Added to configuration $configUuid");
            
            // Update the main server_configurations table with component info
            $this->updateServerConfigurationTable($configUuid, $componentType, $componentUuid, $quantity, 'add');
            
            // Update calculated fields (power, compatibility, etc.)
            $this->updateConfigurationMetrics($configUuid);
            
            // Log the action
            $this->logConfigurationAction($configUuid, 'add_component', $componentType, $componentUuid, $options);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => "Component added successfully",
                'component_details' => $componentDetails
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollback();
            error_log("Error adding component to configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to add component: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Remove component from server configuration
     */
    public function removeComponent($configUuid, $componentType, $componentUuid) {
        try {
            $this->pdo->beginTransaction();
            
            // Remove from configuration_components table
            $stmt = $this->pdo->prepare("
                DELETE FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type = ? AND component_uuid = ?
            ");
            $stmt->execute([$configUuid, $componentType, $componentUuid]);
            
            if ($stmt->rowCount() === 0) {
                $this->pdo->rollback();
                return [
                    'success' => false,
                    'message' => "Component not found in configuration"
                ];
            }
            
            // Update component status back to "Available" and clear ServerUUID
            $this->updateComponentStatusAndServerUuid($componentType, $componentUuid, 1, null, "Removed from configuration $configUuid");
            
            // Update the main server_configurations table
            $this->updateServerConfigurationTable($configUuid, $componentType, $componentUuid, 0, 'remove');
            
            // Update calculated fields
            $this->updateConfigurationMetrics($configUuid);
            
            // Log the action
            $this->logConfigurationAction($configUuid, 'remove_component', $componentType, $componentUuid);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => "Component removed successfully"
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollback();
            error_log("Error removing component from configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to remove component: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get complete configuration details with proper component handling
     */
    public function getConfigurationDetails($configUuid) {
        try {
            // Get base configuration
            $stmt = $this->pdo->prepare("
                SELECT sc.*, u.username as created_by_username 
                FROM server_configurations sc 
                LEFT JOIN users u ON sc.created_by = u.id 
                WHERE sc.config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $configData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$configData) {
                return [
                    'config_uuid' => $configUuid,
                    'error' => 'Configuration not found'
                ];
            }
            
            // Get components from server_configuration_components table
            $stmt = $this->pdo->prepare("
                SELECT * FROM server_configuration_components 
                WHERE config_uuid = ?
                ORDER BY component_type, added_at
            ");
            $stmt->execute([$configUuid]);
            $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Build detailed component information
            $componentDetails = [];
            $componentCounts = [];
            $totalComponents = 0;
            $totalPowerConsumption = 0;
            
            foreach ($components as $component) {
                $type = $component['component_type'];
                
                if (!isset($componentDetails[$type])) {
                    $componentDetails[$type] = [];
                    $componentCounts[$type] = 0;
                }
                
                // Get component details with proper ServerUUID check
                $details = $this->getComponentByUuid($type, $component['component_uuid']);
                if ($details) {
                    $component['details'] = $details;
                    
                    // Calculate power consumption from component specifications
                    $powerConsumption = $this->calculateComponentPower($type, $details);
                    $totalPowerConsumption += $powerConsumption * $component['quantity'];
                    
                    // Add power info to component
                    $component['power_consumption_watts'] = $powerConsumption;
                    $component['total_power_watts'] = $powerConsumption * $component['quantity'];
                } else {
                    $component['details'] = [
                        'UUID' => $component['component_uuid'],
                        'SerialNumber' => 'Unknown',
                        'Status' => 2,
                        'ServerUUID' => $configUuid
                    ];
                    $component['power_consumption_watts'] = 0;
                    $component['total_power_watts'] = 0;
                }
                
                $componentDetails[$type][] = $component;
                $componentCounts[$type] += $component['quantity'];
                $totalComponents += $component['quantity'];
            }
            
            // Add 20% overhead for safety margin on power
            $totalPowerConsumptionWithOverhead = $totalPowerConsumption * 1.2;
            
            // Calculate compatibility score if CompatibilityEngine exists
            $compatibilityScore = null;
            if (class_exists('CompatibilityEngine')) {
                try {
                    $compatibilityEngine = new CompatibilityEngine($this->pdo);
                    $compatibilityScore = $this->calculateHardwareCompatibilityScore($componentDetails);
                } catch (Exception $e) {
                    error_log("Error calculating compatibility: " . $e->getMessage());
                    $compatibilityScore = null;
                }
            }
            
            // Update configuration with calculated values
            $this->updateConfigurationCalculatedFields($configUuid, $totalPowerConsumptionWithOverhead, $compatibilityScore);
            
            // Remove cost-related fields and fix configuration data
            unset($configData['total_cost']);
            $configData['power_consumption'] = round($totalPowerConsumptionWithOverhead, 2);
            $configData['compatibility_score'] = $compatibilityScore;
            
            // Parse validation_results from JSON if it exists
            if (!empty($configData['validation_results'])) {
                $configData['validation_results'] = json_decode($configData['validation_results'], true);
            }
            
            return [
                'configuration' => $configData,
                'components' => $componentDetails,
                'component_counts' => $componentCounts,
                'component_ids_uuids' => $this->getComponentIdsAndUuids($components),
                'total_components' => $totalComponents,
                'power_consumption' => [
                    'total_watts' => round($totalPowerConsumption, 2),
                    'total_with_overhead_watts' => round($totalPowerConsumptionWithOverhead, 2),
                    'overhead_percentage' => 20
                ],
                'configuration_status' => $configData['configuration_status'],
                'server_name' => $configData['server_name'],
                'created_at' => $configData['created_at'],
                'updated_at' => $configData['updated_at']
            ];
            
        } catch (Exception $e) {
            error_log("Error getting configuration details: " . $e->getMessage());
            return [
                'config_uuid' => $configUuid,
                'error' => 'Failed to load configuration details: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Update server_configurations table with component information
     */
    private function updateServerConfigurationTable($configUuid, $componentType, $componentUuid, $quantity, $action) {
        try {
            $updateFields = [];
            $updateValues = [];
            
            switch ($componentType) {
                case 'cpu':
                    if ($action === 'add') {
                        // For multiple CPUs, we store the primary one in cpu_uuid, others in additional_components
                        $stmt = $this->pdo->prepare("SELECT cpu_uuid FROM server_configurations WHERE config_uuid = ?");
                        $stmt->execute([$configUuid]);
                        $currentCpuUuid = $stmt->fetchColumn();
                        
                        if (!$currentCpuUuid) {
                            // First CPU
                            $updateFields[] = "cpu_uuid = ?";
                            $updateValues[] = $componentUuid;
                        } else {
                            // Additional CPU - store in additional_components JSON
                            $this->addToAdditionalComponents($configUuid, 'cpu', $componentUuid);
                        }
                    } elseif ($action === 'remove') {
                        // Check if this is the main CPU or additional
                        $stmt = $this->pdo->prepare("SELECT cpu_uuid FROM server_configurations WHERE config_uuid = ?");
                        $stmt->execute([$configUuid]);
                        $currentCpuUuid = $stmt->fetchColumn();
                        
                        if ($currentCpuUuid === $componentUuid) {
                            $updateFields[] = "cpu_uuid = NULL";
                        } else {
                            $this->removeFromAdditionalComponents($configUuid, 'cpu', $componentUuid);
                        }
                    }
                    break;
                    
                case 'motherboard':
                    if ($action === 'add') {
                        $updateFields[] = "motherboard_uuid = ?";
                        $updateValues[] = $componentUuid;
                    } elseif ($action === 'remove') {
                        $updateFields[] = "motherboard_uuid = NULL";
                    }
                    break;
                    
                case 'ram':
                    $this->updateRamConfiguration($configUuid, $componentUuid, $quantity, $action);
                    break;
                    
                case 'storage':
                    $this->updateStorageConfiguration($configUuid, $componentUuid, $quantity, $action);
                    break;
                    
                case 'nic':
                    $this->updateNicConfiguration($configUuid, $componentUuid, $quantity, $action);
                    break;
                    
                case 'caddy':
                    $this->updateCaddyConfiguration($configUuid, $componentUuid, $quantity, $action);
                    break;
            }
            
            if (!empty($updateFields)) {
                $sql = "UPDATE server_configurations SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE config_uuid = ?";
                $updateValues[] = $configUuid;
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($updateValues);
            }
            
        } catch (Exception $e) {
            error_log("Error updating server configuration table: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update RAM configuration in JSON format
     */
    private function updateRamConfiguration($configUuid, $componentUuid, $quantity, $action) {
        try {
            $stmt = $this->pdo->prepare("SELECT ram_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentConfig = $stmt->fetchColumn();
            
            $ramConfig = $currentConfig ? json_decode($currentConfig, true) : [];
            if (!is_array($ramConfig)) {
                $ramConfig = [];
            }
            
            if ($action === 'add') {
                $ramConfig[] = [
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'added_at' => date('Y-m-d H:i:s')
                ];
            } elseif ($action === 'remove') {
                $ramConfig = array_filter($ramConfig, function($ram) use ($componentUuid) {
                    return $ram['uuid'] !== $componentUuid;
                });
                $ramConfig = array_values($ramConfig); // Reindex array
            }
            
            $stmt = $this->pdo->prepare("UPDATE server_configurations SET ram_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($ramConfig), $configUuid]);
            
        } catch (Exception $e) {
            error_log("Error updating RAM configuration: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update storage configuration in JSON format
     */
    private function updateStorageConfiguration($configUuid, $componentUuid, $quantity, $action) {
        try {
            $stmt = $this->pdo->prepare("SELECT storage_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentConfig = $stmt->fetchColumn();
            
            $storageConfig = $currentConfig ? json_decode($currentConfig, true) : [];
            if (!is_array($storageConfig)) {
                $storageConfig = [];
            }
            
            if ($action === 'add') {
                $storageConfig[] = [
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'added_at' => date('Y-m-d H:i:s')
                ];
            } elseif ($action === 'remove') {
                $storageConfig = array_filter($storageConfig, function($storage) use ($componentUuid) {
                    return $storage['uuid'] !== $componentUuid;
                });
                $storageConfig = array_values($storageConfig);
            }
            
            $stmt = $this->pdo->prepare("UPDATE server_configurations SET storage_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($storageConfig), $configUuid]);
            
        } catch (Exception $e) {
            error_log("Error updating storage configuration: " . $e->getMessage());
            throw $e;
        }
    }


    /**
     * Update NIC configuration in JSON format
     */
    private function updateNicConfiguration($configUuid, $componentUuid, $quantity, $action) {
        try {
            $stmt = $this->pdo->prepare("SELECT nic_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentConfig = $stmt->fetchColumn();
            
            $nicConfig = $currentConfig ? json_decode($currentConfig, true) : [];
            if (!is_array($nicConfig)) {
                $nicConfig = [];
            }
            
            if ($action === 'add') {
                $nicConfig[] = [
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'added_at' => date('Y-m-d H:i:s')
                ];
            } elseif ($action === 'remove') {
                $nicConfig = array_filter($nicConfig, function($nic) use ($componentUuid) {
                    return $nic['uuid'] !== $componentUuid;
                });
                $nicConfig = array_values($nicConfig);
            }
            
            $stmt = $this->pdo->prepare("UPDATE server_configurations SET nic_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($nicConfig), $configUuid]);
            
        } catch (Exception $e) {
            error_log("Error updating NIC configuration: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update caddy configuration in JSON format
     */
    private function updateCaddyConfiguration($configUuid, $componentUuid, $quantity, $action) {
        try {
            $stmt = $this->pdo->prepare("SELECT caddy_configuration FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentConfig = $stmt->fetchColumn();
            
            $caddyConfig = $currentConfig ? json_decode($currentConfig, true) : [];
            if (!is_array($caddyConfig)) {
                $caddyConfig = [];
            }
            
            if ($action === 'add') {
                $caddyConfig[] = [
                    'uuid' => $componentUuid,
                    'quantity' => $quantity,
                    'added_at' => date('Y-m-d H:i:s')
                ];
            } elseif ($action === 'remove') {
                $caddyConfig = array_filter($caddyConfig, function($caddy) use ($componentUuid) {
                    return $caddy['uuid'] !== $componentUuid;
                });
                $caddyConfig = array_values($caddyConfig);
            }
            
            $stmt = $this->pdo->prepare("UPDATE server_configurations SET caddy_configuration = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($caddyConfig), $configUuid]);
            
        } catch (Exception $e) {
            error_log("Error updating caddy configuration: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update configuration metrics (power, compatibility, validation)
     */
    private function updateConfigurationMetrics($configUuid) {
        try {
            $details = $this->getConfigurationSummary($configUuid);
            
            $totalPower = 0;
            foreach ($details['components'] ?? [] as $type => $components) {
                foreach ($components as $component) {
                    if (isset($component['details'])) {
                        $power = $this->calculateComponentPower($type, $component['details']);
                        $totalPower += $power * ($component['quantity'] ?? 1);
                    }
                }
            }
            
            $totalPowerWithOverhead = $totalPower * 1.2;
            
            // Calculate compatibility score
            $compatibilityScore = null;
            if (class_exists('CompatibilityEngine')) {
                try {
                    $compatibilityScore = $this->calculateHardwareCompatibilityScore($details);
                } catch (Exception $e) {
                    error_log("Error calculating compatibility: " . $e->getMessage());
                }
            }
            
            // Update the configuration
            $this->updateConfigurationCalculatedFields($configUuid, $totalPowerWithOverhead, $compatibilityScore);
            
        } catch (Exception $e) {
            error_log("Error updating configuration metrics: " . $e->getMessage());
        }
    }
    
    /**
     * Update calculated fields in configuration
     */
    private function updateConfigurationCalculatedFields($configUuid, $powerConsumption, $compatibilityScore) {
        try {
            $sql = "UPDATE server_configurations SET power_consumption = ?, compatibility_score = ?, updated_at = NOW() WHERE config_uuid = ?";
            $params = [$powerConsumption, $compatibilityScore, $configUuid];
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
        } catch (Exception $e) {
            error_log("Error updating calculated fields: " . $e->getMessage());
        }
    }
    
    /**
     * Update configuration with compatibility score and validation results
     */
    public function updateConfigurationValidation($configUuid, $compatibilityScore = null, $validationResults = null) {
        try {
            $setParts = [];
            $params = [];
            
            if ($compatibilityScore !== null) {
                $setParts[] = "compatibility_score = ?";
                $params[] = $compatibilityScore;
            }
            
            if ($validationResults !== null) {
                $setParts[] = "validation_results = ?";
                $params[] = json_encode($validationResults);
            }
            
            if (!empty($setParts)) {
                $setParts[] = "updated_at = NOW()";
                $sql = "UPDATE server_configurations SET " . implode(', ', $setParts) . " WHERE config_uuid = ?";
                $params[] = $configUuid;
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                
                error_log("Updated configuration $configUuid with compatibility score: " . ($compatibilityScore ?? 'null') . " and validation results");
            }
            
        } catch (Exception $e) {
            error_log("Error updating configuration validation: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get configuration summary - backwards compatibility
     */
    public function getConfigurationSummary($configUuid) {
        $details = $this->getConfigurationDetails($configUuid);
        
        // Return summary format for backwards compatibility
        return [
            'config_uuid' => $configUuid,
            'components' => $details['components'] ?? [],
            'component_counts' => $details['component_counts'] ?? [],
            'total_components' => $details['total_components'] ?? 0,
            'server_name' => $details['server_name'] ?? '',
            'configuration_status' => $details['configuration_status'] ?? 0,
            'error' => $details['error'] ?? null
        ];
    }
    
    /**
     * Validate server configuration with FIXED compatibility-based scoring
     */
    public function validateConfiguration($configUuid) {
        try {
            error_log("Starting validation for config: $configUuid");
            
            $summary = $this->getConfigurationSummary($configUuid);
            
            $validation = [
                'is_valid' => true,
                'compatibility_score' => 100.0,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'component_summary' => [
                    'total_components' => $summary['total_components'] ?? 0,
                    'component_counts' => $summary['component_counts'] ?? []
                ]
            ];
            
            // Check for required components
            $requiredComponents = ['cpu', 'motherboard', 'ram'];
            $presentComponents = array_keys($summary['components'] ?? []);
            
            foreach ($requiredComponents as $required) {
                if (!in_array($required, $presentComponents)) {
                    $validation['is_valid'] = false;
                    $validation['issues'][] = "Missing required component: " . ucfirst($required);
                }
            }
            
            // Check recommended components
            $recommendedComponents = ['storage'];
            foreach ($recommendedComponents as $recommended) {
                if (!in_array($recommended, $presentComponents)) {
                    $validation['warnings'][] = "Missing recommended component: " . ucfirst($recommended);
                }
            }
            
            // FIXED: Check for "in-use" warnings based on ServerUUID - only warn if in different config
            foreach ($summary['components'] ?? [] as $type => $components) {
                foreach ($components as $component) {
                    if (isset($component['details']['Status'])) {
                        $status = (int)$component['details']['Status'];
                        $serverUuid = $component['details']['ServerUUID'] ?? null;
                        
                        if ($status === 2) { // In Use
                            // Only show warning if component is in use in a DIFFERENT configuration
                            if ($serverUuid && $serverUuid !== $configUuid) {
                                $validation['warnings'][] = "Component {$component['component_uuid']} is in use in another configuration ($serverUuid)";
                            }
                            // If ServerUUID matches current config or is null, no warning needed
                        } elseif ($status === 0) {
                            $validation['issues'][] = "Component {$component['component_uuid']} is marked as failed/defective";
                        }
                    }
                }
            }
            
            // FIXED: Calculate compatibility score based on actual hardware compatibility (not component count)
            $compatibilityScore = $this->calculateHardwareCompatibilityScore($summary);
            $validation['compatibility_score'] = $compatibilityScore;
            
            // Adjust overall validity based on compatibility
            if ($compatibilityScore < 70) {
                $validation['is_valid'] = false;
                $validation['issues'][] = "Hardware compatibility issues detected (score: $compatibilityScore%)";
            }
            
            // Add recommendations
            if (!$validation['is_valid']) {
                $validation['recommendations'][] = "Resolve all compatibility issues before finalizing";
            }
            if ($compatibilityScore < 90) {
                $validation['recommendations'][] = "Review component compatibility for optimal performance";
            }
            
            error_log("Validation complete. Is valid: " . ($validation['is_valid'] ? 'yes' : 'no') . ", Compatibility Score: " . $validation['compatibility_score']);
            
            return $validation;
            
        } catch (Exception $e) {
            error_log("Error validating configuration: " . $e->getMessage());
            return [
                'is_valid' => false,
                'compatibility_score' => 0,
                'issues' => ['Validation failed due to system error: ' . $e->getMessage()],
                'warnings' => [],
                'recommendations' => [],
                'component_summary' => [
                    'total_components' => 0,
                    'component_counts' => []
                ]
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
                SET configuration_status = 3, notes = ?, updated_at = NOW()
                WHERE config_uuid = ?
            ");
            $stmt->execute([$notes, $configUuid]);
            
            // Log the finalization
            $this->logConfigurationAction($configUuid, 'finalize', 'configuration', null, [
                'notes' => $notes,
                'compatibility_score' => $validation['compatibility_score']
            ]);
            
            return [
                'success' => true,
                'message' => "Configuration finalized successfully",
                'finalization_timestamp' => date('Y-m-d H:i:s'),
                'compatibility_score' => $validation['compatibility_score']
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
            $components =$stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Release components back to available status and clear ServerUUID
            foreach ($components as $component) {
                $this->updateComponentStatusAndServerUuid(
                    $component['component_type'], 
                    $component['component_uuid'], 
                    1, 
                    null,
                    "Released from deleted configuration $configUuid"
                );
            }
            
            // Delete configuration components
            $stmt = $this->pdo->prepare("DELETE FROM server_configuration_components WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            
            // Delete configuration history if exists
            try {
                $stmt = $this->pdo->prepare("DELETE FROM server_configuration_history WHERE config_uuid = ?");
                $stmt->execute([$configUuid]);
            } catch (Exception $historyError) {
                error_log("Could not delete history (table might not exist): " . $historyError->getMessage());
            }
            
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
    
    /**
     * Generate UUID for configuration
     */
    private function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Check if component type is valid
     */
    private function isValidComponentType($componentType) {
        return isset($this->componentTables[$componentType]);
    }
    
    /**
     * Check if component can only have single instance in configuration
     */
    private function isSingleInstanceComponent($componentType) {
        return in_array($componentType, ['motherboard']);
    }
    
    /**
     * Get component by UUID with improved error handling
     */
    private function getComponentByUuid($componentType, $componentUuid) {
        if (!isset($this->componentTables[$componentType])) {
            error_log("Invalid component type: $componentType");
            return null;
        }
        
        try {
            $table = $this->componentTables[$componentType];
            
            // Try exact match first
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
            $stmt->execute([$componentUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                return $result;
            }
            
            // Try case-insensitive match for UUID issues
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE TRIM(UPPER(UUID)) = UPPER(TRIM(?))");
            $stmt->execute([$componentUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error getting component by UUID from {$this->componentTables[$componentType]}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * FIXED: Check component availability with ServerUUID context
     */
    private function checkComponentAvailability($componentDetails, $configUuid, $options = []) {
        $status = (int)$componentDetails['Status'];
        $serverUuid = $componentDetails['ServerUUID'] ?? null;
        
        $result = [
            'available' => false,
            'status' => $status,
            'server_uuid' => $serverUuid,
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
                if ($serverUuid === $configUuid) {
                    $result['available'] = true;
                    $result['message'] = "Component is already assigned to this configuration";
                } elseif ($serverUuid) {
                    $result['message'] = "Component is currently in use in configuration: $serverUuid";
                    $result['can_override'] = true;
                } else {
                    $result['message'] = "Component is currently In Use";
                    $result['can_override'] = true;
                }
                break;
            default:
                $result['message'] = "Component has unknown status: $status";
                $result['can_override'] = false;
        }
        
        return $result;
    }
    
    /**
     * Get configuration component by type
     */
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
    
    /**
     * Update component status AND ServerUUID 
     */
    private function updateComponentStatusAndServerUuid($componentType, $componentUuid, $newStatus, $serverUuid, $reason = '') {
        if (!isset($this->componentTables[$componentType])) {
            error_log("Cannot update status - invalid component type: $componentType");
            return false;
        }
        
        try {
            $table = $this->componentTables[$componentType];
            
            // Get current status first for logging
            $stmt = $this->pdo->prepare("SELECT Status, ServerUUID FROM $table WHERE UUID = ?");
            $stmt->execute([$componentUuid]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($current === false) {
                error_log("Cannot update status - component not found: $componentUuid in $table");
                return false;
            }
            
            // Update both status and ServerUUID
            $stmt = $this->pdo->prepare("
                UPDATE $table 
                SET Status = ?, ServerUUID = ?, UpdatedAt = NOW() 
                WHERE UUID = ?
            ");
            $result = $stmt->execute([$newStatus, $serverUuid, $componentUuid]);
            
            if ($result) {
                error_log("Updated component: $componentUuid in $table - Status: {$current['Status']} -> $newStatus, ServerUUID: '{$current['ServerUUID']}' -> '$serverUuid' - $reason");
            } else {
                error_log("Failed to update component: $componentUuid in $table");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error updating component status and ServerUUID: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get component IDs and UUIDs for detailed response
     */
    private function getComponentIdsAndUuids($components) {
        $idsUuids = [];
        
        foreach ($components as $component) {
            $type = $component['component_type'];
            if (!isset($idsUuids[$type])) {
                $idsUuids[$type] = [];
            }
            
            $idsUuids[$type][] = [
                'component_uuid' => $component['component_uuid'],
                'quantity' => $component['quantity'],
                'slot_position' => $component['slot_position'],
                'notes' => $component['notes'],
                'added_at' => $component['added_at']
            ];
        }
        
        return $idsUuids;
    }
    
    /**
     * Calculate component power consumption from specifications
     */
    private function calculateComponentPower($componentType, $componentDetails) {
        // Default power estimates by component type (watts)
        $defaultPower = [
            'cpu' => 150,        
            'ram' => 8,          
            'storage' => 15,     
            'motherboard' => 50, 
            'nic' => 25,         
            'caddy' => 5         
        ];
        
        $basePower = $defaultPower[$componentType] ?? 50;
        
        try {
            // Try to extract power from Notes field or component specifications
            $notes = $componentDetails['Notes'] ?? '';
            
            // Look for power consumption patterns in notes
            if (preg_match('/(\d+)\s*W(?:att)?s?/i', $notes, $matches)) {
                return (int)$matches[1];
            }
            
            // Look for TDP patterns
            if (preg_match('/TDP[:\s]*(\d+)\s*W/i', $notes, $matches)) {
                return (int)$matches[1];
            }
            
            // Component-specific power calculation
            switch ($componentType) {
                case 'cpu':
                    // Try to extract core count and frequency for better estimation
                    if (preg_match('/(\d+)-core/i', $notes, $matches)) {
                        $cores = (int)$matches[1];
                        $basePower = min(300, $cores * 2.5); // Rough estimate: 2.5W per core, max 300W
                    }
                    break;
                    
                case 'ram':
                    // Try to extract memory size
                    if (preg_match('/(\d+)\s*GB/i', $notes, $matches)) {
                        $size = (int)$matches[1];
                        $basePower = max(4, min(16, $size / 4)); // Rough: 1W per 4GB, min 4W, max 16W
                    }
                    break;
                    
                case 'storage':
                    // SSDs generally consume less power than HDDs
                    if (stripos($notes, 'SSD') !== false || stripos($notes, 'NVMe') !== false) {
                        $basePower = 8;
                    } elseif (stripos($notes, 'HDD') !== false) {
                        $basePower = 12;
                    }
                    break;
            }
            
        } catch (Exception $e) {
            error_log("Error calculating power for component: " . $e->getMessage());
        }
        
        return $basePower;
    }
    
    /**
     * FIXED: Calculate hardware compatibility score based on actual component compatibility
     */
    private function calculateHardwareCompatibilityScore($summary) {
        $score = 100.0;
        $components = $summary['components'] ?? [];
        
        try {
            // If we don't have basic components, score is low
            if (empty($components)) {
                return 0.0;
            }
            
            $motherboard = null;
            $cpus = [];
            $rams = [];
            
            // Extract key components
            if (isset($components['motherboard']) && !empty($components['motherboard'])) {
                $motherboard = $components['motherboard'][0]['details'] ?? null;
            }
            
            if (isset($components['cpu'])) {
                foreach ($components['cpu'] as $cpu) {
                    if (isset($cpu['details'])) {
                        $cpus[] = $cpu['details'];
                    }
                }
            }
            
            if (isset($components['ram'])) {
                foreach ($components['ram'] as $ram) {
                    if (isset($ram['details'])) {
                        $rams[] = $ram['details'];
                    }
                }
            }
            
            // Check motherboard-CPU compatibility
            if ($motherboard && !empty($cpus)) {
                $compatibilityScore = $this->checkMotherboardCpuCompatibility($motherboard, $cpus);
                $score = min($score, $compatibilityScore);
            }
            
            // Check motherboard-RAM compatibility
            if ($motherboard && !empty($rams)) {
                $ramCompatibilityScore = $this->checkMotherboardRamCompatibility($motherboard, $rams);
                $score = min($score, $ramCompatibilityScore);
            }
            
            // Check power requirements vs motherboard capacity
            $powerScore = $this->checkPowerCompatibility($components);
            $score = min($score, $powerScore);
            
            // Check form factor compatibility
            $formFactorScore = $this->checkFormFactorCompatibility($components);
            $score = min($score, $formFactorScore);
            
        } catch (Exception $e) {
            error_log("Error calculating hardware compatibility score: " . $e->getMessage());
            $score = 50.0; // Default to medium compatibility on error
        }
        
        return round($score, 1);
    }
    
    /**
     * Check motherboard-CPU socket compatibility
     */
    private function checkMotherboardCpuCompatibility($motherboard, $cpus) {
        $score = 100.0;
        
        try {
            $mbNotes = strtolower($motherboard['Notes'] ?? '');
            
            // Extract motherboard socket type
            $mbSocket = $this->extractSocketType($mbNotes);
            
            foreach ($cpus as $cpu) {
                $cpuNotes = strtolower($cpu['Notes'] ?? '');
                $cpuSocket = $this->extractSocketType($cpuNotes);
                
                if ($mbSocket && $cpuSocket) {
                    if ($mbSocket !== $cpuSocket) {
                        $score = 0.0; // Complete incompatibility
                        error_log("CPU socket mismatch: Motherboard has $mbSocket, CPU requires $cpuSocket");
                        break;
                    }
                } else {
                    // If we can't determine socket types, reduce score but don't fail completely
                    $score = min($score, 70.0);
                    error_log("Could not determine socket compatibility - MB socket: $mbSocket, CPU socket: $cpuSocket");
                }
            }
            
        } catch (Exception $e) {
            error_log("Error checking motherboard-CPU compatibility: " . $e->getMessage());
            $score = 50.0;
        }
        
        return $score;
    }
    
    /**
     * Check motherboard-RAM compatibility
     */
    private function checkMotherboardRamCompatibility($motherboard, $rams) {
        $score = 100.0;
        
        try {
            $mbNotes = strtolower($motherboard['Notes'] ?? '');
            
            // Extract motherboard supported RAM types
            $mbMemoryTypes = $this->extractMemoryTypes($mbNotes);
            
            foreach ($rams as $ram) {
                $ramNotes = strtolower($ram['Notes'] ?? '');
                $ramType = $this->extractMemoryType($ramNotes);
                
                if (!empty($mbMemoryTypes) && $ramType) {
                    if (!in_array($ramType, $mbMemoryTypes)) {
                        $score = min($score, 10.0); // Major incompatibility
                        error_log("RAM type mismatch: Motherboard supports " . implode(',', $mbMemoryTypes) . ", RAM is $ramType");
                    }
                } else {
                    // If we can't determine memory types, reduce score slightly
                    $score = min($score, 80.0);
                }
            }
            
        } catch (Exception $e) {
            error_log("Error checking motherboard-RAM compatibility: " . $e->getMessage());
            $score = 60.0;
        }
        
        return $score;
    }
    
    /**
     * Check power compatibility
     */
    private function checkPowerCompatibility($components) {
        $score = 100.0;
        
        try {
            $totalPower = 0;
            
            foreach ($components as $type => $typeComponents) {
                foreach ($typeComponents as $component) {
                    $details = $component['details'] ?? [];
                    $power = $this->calculateComponentPower($type, $details);
                    $quantity = $component['quantity'] ?? 1;
                    $totalPower += $power * $quantity;
                }
            }
            
            // Check if total power is reasonable (not too high for typical motherboard)
            if ($totalPower > 1000) { // Very high power consumption
                $score = 30.0;
            } elseif ($totalPower > 750) {
                $score = 60.0;
            } elseif ($totalPower > 500) {
                $score = 85.0;
            }
            
        } catch (Exception $e) {
            error_log("Error checking power compatibility: " . $e->getMessage());
            $score = 75.0;
        }
        
        return $score;
    }
    
    /**
     * Check form factor compatibility
     */
    private function checkFormFactorCompatibility($components) {
        $score = 100.0;
        
        try {
            // This is a placeholder for more advanced form factor checking
            // Could check if components physically fit together
            
            // For now, just check basic constraints
            if (isset($components['motherboard']) && isset($components['ram'])) {
                $ramCount = count($components['ram']);
                
                // Rough check: most motherboards support 2-4 RAM modules
                if ($ramCount > 8) {
                    $score = 40.0;
                } elseif ($ramCount > 6) {
                    $score = 70.0;
                }
            }
            
        } catch (Exception $e) {
            error_log("Error checking form factor compatibility: " . $e->getMessage());
            $score = 85.0;
        }
        
        return $score;
    }
    
    /**
     * Extract socket type from component notes
     */
    private function extractSocketType($notes) {
        $commonSockets = [
            'lga1151', 'lga1150', 'lga1155', 'lga1156', 'lga1200', 'lga1700',
            'lga2011', 'lga2066', 'lga3647',
            'am4', 'am5', 'tr4', 'strx4',
            'socket 1151', 'socket 1150', 'socket 1200', 'socket 1700',
            'socket am4', 'socket am5'
        ];
        
        foreach ($commonSockets as $socket) {
            if (strpos($notes, $socket) !== false) {
                // Normalize socket name
                $socket = str_replace('socket ', '', $socket);
                return $socket;
            }
        }
        
        return null;
    }
    
    /**
     * Extract memory types from motherboard notes
     */
    private function extractMemoryTypes($notes) {
        $types = [];
        
        if (strpos($notes, 'ddr5') !== false) {
            $types[] = 'ddr5';
        }
        if (strpos($notes, 'ddr4') !== false) {
            $types[] = 'ddr4';
        }
        if (strpos($notes, 'ddr3') !== false) {
            $types[] = 'ddr3';
        }
        
        return $types;
    }
    
    /**
     * Extract memory type from RAM notes
     */
    private function extractMemoryType($notes) {
        if (strpos($notes, 'ddr5') !== false) {
            return 'ddr5';
        }
        if (strpos($notes, 'ddr4') !== false) {
            return 'ddr4';
        }
        if (strpos($notes, 'ddr3') !== false) {
            return 'ddr3';
        }
        
        return null;
    }
    
    /**
     * Log configuration action
     */
    private function logConfigurationAction($configUuid, $action, $componentType = null, $componentUuid = null, $metadata = null) {
        try {
            // Check if history table exists
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE 'server_configuration_history'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                // Create history table if it doesn't exist
                $this->createHistoryTable();
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO server_configuration_history 
                (config_uuid, action, component_type, component_uuid, metadata, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $configUuid, 
                $action, 
                $componentType, 
                $componentUuid, 
                json_encode($metadata)
            ]);
        } catch (Exception $e) {
            error_log("Error logging configuration action: " . $e->getMessage());
        }
    }
    
    /**
     * Create history table if it doesn't exist
     */
    private function createHistoryTable() {
        try {
            $sql = "
                CREATE TABLE IF NOT EXISTS server_configuration_history (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    config_uuid varchar(36) NOT NULL,
                    action varchar(50) NOT NULL COMMENT 'created, updated, component_added, component_removed, validated, etc.',
                    component_type varchar(20) DEFAULT NULL,
                    component_uuid varchar(36) DEFAULT NULL,
                    metadata text DEFAULT NULL COMMENT 'JSON metadata for the action',
                    created_at timestamp NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (id),
                    KEY idx_config_uuid (config_uuid),
                    KEY idx_component_uuid (component_uuid),
                    KEY idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ";
            $this->pdo->exec($sql);
            error_log("Created server_configuration_history table");
        } catch (Exception $e) {
            error_log("Error creating history table: " . $e->getMessage());
        }
    }
}

?>

