<?php

class ServerBuilder {

    private $pdo;
    private $componentTables;
    private $dataUtils;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->componentTables = [
            'chassis' => 'chassisinventory',
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory',
            'pciecard' => 'pciecardinventory',
            'hbacard' => 'hbacardinventory'
        ];

        // Initialize DataExtractionUtilities for JSON spec lookups
        require_once __DIR__ . '/DataExtractionUtilities.php';
        $this->dataUtils = new DataExtractionUtilities($pdo);
    }
    
    /**
     * Get the inventory table name for a given component type
     */
    private function getComponentInventoryTable($componentType) {
        return $this->componentTables[$componentType] ?? null;
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
            // Phase 1: Validate component type
            if (!$this->isValidComponentType($componentType)) {
                return [
                    'success' => false,
                    'message' => "Invalid component type: $componentType"
                ];
            }
            
            // Phase 1.5: Validate compatibility with existing components (flexible order)
            $compatibilityValidation = $this->validateComponentCompatibility($configUuid, $componentType, $componentUuid);
            if (!$compatibilityValidation['success']) {
                return $compatibilityValidation;
            }

            // Phase 2: Check for duplicate component FIRST with cleanup
            if ($this->isDuplicateComponent($configUuid, $componentUuid)) {
                // Check if this is an orphaned record (component exists in config table but not properly assigned)
                $componentDetails = $this->getComponentByUuid($componentType, $componentUuid);
                if ($componentDetails && $componentDetails['ServerUUID'] !== $configUuid) {
                    error_log("Found orphaned record: Component $componentUuid exists in config table but ServerUUID doesn't match");
                    error_log("Component ServerUUID: {$componentDetails['ServerUUID']}, Expected: $configUuid");
                    
                    // Clean up orphaned record
                    $stmt = $this->pdo->prepare("
                        DELETE FROM server_configuration_components 
                        WHERE config_uuid = ? AND component_uuid = ?
                    ");
                    $stmt->execute([$configUuid, $componentUuid]);
                    error_log("Cleaned up orphaned record for component $componentUuid");
                } else {
                    return [
                        'success' => false,
                        'message' => "Component $componentUuid is already added to this configuration"
                    ];
                }
            }
            
            // Phase 3: Validate component exists in JSON specifications
            $compatibility = null; // Initialize for later phases
            try {
                // Special handling for chassis - use ChassisManager directly
                if ($componentType === 'chassis') {
                    require_once __DIR__ . '/ChassisManager.php';
                    $chassisManager = new ChassisManager();

                    $chassisResult = $chassisManager->loadChassisSpecsByUUID($componentUuid);
                    if (!$chassisResult['found']) {
                        return [
                            'success' => false,
                            'message' => "Chassis $componentUuid not found in JSON specifications: " . ($chassisResult['error'] ?? 'Unknown error')
                        ];
                    }

                    // Create compatibility object for other validation phases
                    require_once __DIR__ . '/ComponentCompatibility.php';
                    if (class_exists('ComponentCompatibility')) {
                        $compatibility = new ComponentCompatibility($this->pdo);
                    }
                } else {
                    // Use ComponentCompatibility for all other components (including storage)
                    require_once __DIR__ . '/ComponentCompatibility.php';
                    if (!class_exists('ComponentCompatibility')) {
                        error_log("ComponentCompatibility class not found after require_once");
                        return [
                            'success' => false,
                            'message' => "Component validation system not available"
                        ];
                    }
                    $compatibility = new ComponentCompatibility($this->pdo);

                    // Skip JSON validation for storage, nic, caddy, chassis - database UUIDs don't match JSON UUIDs
                    // Only validate: cpu, motherboard, ram, pciecard, hbacard
                    $componentsToValidate = ['cpu', 'motherboard', 'ram', 'pciecard', 'hbacard'];
                    if (in_array($componentType, $componentsToValidate)) {
                        $existsResult = $compatibility->validateComponentExistsInJSON($componentType, $componentUuid);
                        if (!$existsResult) {
                            return [
                                'success' => false,
                                'message' => "Component $componentUuid not found in $componentType JSON specifications database"
                            ];
                        }
                    }
                }
            } catch (Exception $compatError) {
                error_log("Error in component validation for type '$componentType': " . $compatError->getMessage());
                error_log("Stack trace: " . $compatError->getTraceAsString());
                error_log("ComponentCompatibility.php exists: " . (file_exists(__DIR__ . '/ComponentCompatibility.php') ? 'yes' : 'no'));
                error_log("CompatibilityEngine.php exists: " . (file_exists(__DIR__ . '/CompatibilityEngine.php') ? 'yes' : 'no'));
                // Return error instead of skipping
                return [
                    'success' => false,
                    'message' => 'Component validation error: ' . $compatError->getMessage()
                ];
            }
            
            // Phase 4: Get component details from inventory
            $componentDetails = $this->getComponentByUuid($componentType, $componentUuid);
            if (!$componentDetails) {
                return [
                    'success' => false,
                    'message' => "Component not found in inventory: $componentUuid"
                ];
            }
            
            // Phase 5: Chassis-specific validations BEFORE adding
            // Validation already done in Phase 3 for chassis, skip here

            // Phase 5.1: Skip legacy CPU validation - flexible system handles compatibility
            // CPU can now be added in any order with proper compatibility checking
            
            // Phase 5.5: RAM-specific validations BEFORE adding
            $ramValidationResults = null;
            if ($componentType === 'ram' && isset($compatibility)) {
                try {
                    $ramValidation = $this->validateRAMAddition($configUuid, $componentUuid, $compatibility);
                    if (!$ramValidation['success']) {
                        return $ramValidation;
                    }
                    // Store validation results for inclusion in response
                    $ramValidationResults = $ramValidation;
                } catch (Exception $ramError) {
                    error_log("Error in RAM validation: " . $ramError->getMessage());
                    // Continue without RAM validation
                }
            }
            
            // Phase 6: Other component-specific validations
            if (isset($compatibility)) {
                try {
                    $compatibilityValidation = $this->validateComponentCompatibilityBeforeAdd($configUuid, $componentType, $componentUuid, $compatibility);
                    if (!$compatibilityValidation['success']) {
                        return $compatibilityValidation;
                    }
                } catch (Exception $compatError) {
                    error_log("Error in compatibility validation: " . $compatError->getMessage());
                    // Continue without compatibility validation
                }
            }
            
            // Phase 7: Check availability
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
            
            // Get server configuration location, rack position, and is_test flag for component assignment
            $stmt = $this->pdo->prepare("SELECT location, rack_position, is_test FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $serverConfig = $stmt->fetch(PDO::FETCH_ASSOC);
            $serverLocation = $serverConfig['location'] ?? null;
            $serverRackPosition = $serverConfig['rack_position'] ?? null;
            $isTest = $serverConfig['is_test'] ?? 0;

            // Log server configuration data for component assignment
            error_log("Component assignment: Server $configUuid has Location='$serverLocation', RackPosition='$serverRackPosition', is_test='$isTest'");

            // ALL VALIDATIONS COMPLETE - Begin transaction only after all checks pass
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

            // Update component status to "In Use" ONLY for real builds (not test builds)
            if (!$isTest) {
                // Update component status to "In Use" AND set ServerUUID, location, rack position, and installation date
                $this->updateComponentStatusAndServerUuid($componentType, $componentUuid, 2, $configUuid, "Added to configuration $configUuid", $serverLocation, $serverRackPosition);
            }
            
            // Update the main server_configurations table with component info
            $this->updateServerConfigurationTable($configUuid, $componentType, $componentUuid, $quantity, 'add');
            
            // Update calculated fields (power, compatibility, etc.)
            $this->updateConfigurationMetrics($configUuid);
            
            // Log the action
            $this->logConfigurationAction($configUuid, 'add_component', $componentType, $componentUuid, $options);
            
            $this->pdo->commit();
            
            // Build response with optional RAM validation details
            $response = [
                'success' => true,
                'message' => "Component added successfully",
                'component_details' => $componentDetails
            ];
            
            // Include RAM validation results if available
            if ($ramValidationResults !== null && $componentType === 'ram') {
                $response['warnings'] = $ramValidationResults['warnings'] ?? [];
                $response['compatibility_details'] = $ramValidationResults['compatibility_details'] ?? [];
            }
            
            return $response;
            
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollback();
            }
            error_log("Error adding component to configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Failed to add component: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Remove component from server configuration
     * UPDATED: Now checks is_test flag to conditionally update component status
     */
    public function removeComponent($configUuid, $componentType, $componentUuid) {
        try {
            $this->pdo->beginTransaction();

            // Get is_test flag from server configuration
            $stmt = $this->pdo->prepare("SELECT is_test FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            $isTest = $config['is_test'] ?? 0;

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

            // Update component status back to "Available" ONLY for real builds (not test builds)
            if (!$isTest) {
                // Update component status back to "Available" and clear ServerUUID, installation date, and rack position
                $this->updateComponentStatusAndServerUuid($componentType, $componentUuid, 1, null, "Removed from configuration $configUuid");
            }

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
            
            // Get components with serial numbers from inventory tables using JOINs (with collation fix)
            $stmt = $this->pdo->prepare("
                SELECT 
                    scc.*,
                    COALESCE(cpu.SerialNumber, mb.SerialNumber, ram.SerialNumber, storage.SerialNumber, nic.SerialNumber, caddy.SerialNumber, 'Not Found') as serial_number
                FROM server_configuration_components scc
                LEFT JOIN cpuinventory cpu ON scc.component_type = 'cpu' AND scc.component_uuid COLLATE utf8mb4_general_ci = cpu.UUID COLLATE utf8mb4_general_ci
                LEFT JOIN motherboardinventory mb ON scc.component_type = 'motherboard' AND scc.component_uuid COLLATE utf8mb4_general_ci = mb.UUID COLLATE utf8mb4_general_ci
                LEFT JOIN raminventory ram ON scc.component_type = 'ram' AND scc.component_uuid COLLATE utf8mb4_general_ci = ram.UUID COLLATE utf8mb4_general_ci
                LEFT JOIN storageinventory storage ON scc.component_type = 'storage' AND scc.component_uuid COLLATE utf8mb4_general_ci = storage.UUID COLLATE utf8mb4_general_ci
                LEFT JOIN nicinventory nic ON scc.component_type = 'nic' AND scc.component_uuid COLLATE utf8mb4_general_ci = nic.UUID COLLATE utf8mb4_general_ci
                LEFT JOIN caddyinventory caddy ON scc.component_type = 'caddy' AND scc.component_uuid COLLATE utf8mb4_general_ci = caddy.UUID COLLATE utf8mb4_general_ci
                WHERE scc.config_uuid = ?
                ORDER BY scc.component_type, scc.added_at
            ");
            $stmt->execute([$configUuid]);
            $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Build simplified component information
            $componentDetails = [];
            $componentCounts = [];
            $totalComponents = 0;
            $missingComponents = []; // Track components that don't exist in inventory
            
            foreach ($components as $component) {
                $type = $component['component_type'];
                
                if (!isset($componentDetails[$type])) {
                    $componentDetails[$type] = [];
                    $componentCounts[$type] = 0;
                }
                
                // Use actual serial number from database JOINs
                $simplifiedComponent = [
                    'uuid' => $component['component_uuid'],
                    'serial_number' => $component['serial_number'], // Actual serial number from inventory tables
                    'quantity' => $component['quantity'],
                    'slot_position' => $component['slot_position'] ?? null,
                    'added_at' => $component['added_at']
                ];
                
                $componentDetails[$type][] = $simplifiedComponent;
                $componentCounts[$type] += $component['quantity'];
                $totalComponents += $component['quantity'];
            }
            
            // Use stored power consumption from database instead of calculating
            $totalPowerConsumptionWithOverhead = $configData['power_consumption'] ?? 0;
            
            // Use stored compatibility score from database instead of calculating
            $compatibilityScore = $configData['compatibility_score'] ?? null;
            
            // No need to update - using stored values from database
            
            // Use stored values from database
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
                'missing_components' => $missingComponents, // Add missing components info
                'total_components' => $totalComponents,
                'power_consumption' => [
                    'total_watts' => round($totalPowerConsumptionWithOverhead / 1.2, 2),
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
                case 'chassis':
                    // Chassis is tracked in server_configuration_components, not in main table
                    // No direct column update needed
                    break;

                case 'cpu':
                    $this->updateCpuConfiguration($configUuid, $componentUuid, $quantity, $action);
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
     * Update CPU configuration in JSON format
     */
    private function updateCpuConfiguration($configUuid, $componentUuid, $quantity, $action) {
        try {
            if ($action === 'add') {
                // For add, set the cpu_uuid (assuming single CPU support for now)
                $stmt = $this->pdo->prepare("UPDATE server_configurations SET cpu_uuid = ?, updated_at = NOW() WHERE config_uuid = ?");
                $stmt->execute([$componentUuid, $configUuid]);
            } elseif ($action === 'remove') {
                // For remove, check if this is the CPU being removed and clear it
                $stmt = $this->pdo->prepare("SELECT cpu_uuid FROM server_configurations WHERE config_uuid = ?");
                $stmt->execute([$configUuid]);
                $currentCpuUuid = $stmt->fetchColumn();
                
                if ($currentCpuUuid === $componentUuid) {
                    $stmt = $this->pdo->prepare("UPDATE server_configurations SET cpu_uuid = NULL, updated_at = NOW() WHERE config_uuid = ?");
                    $stmt->execute([$configUuid]);
                }
            }
            
        } catch (Exception $e) {
            error_log("Error updating CPU configuration: " . $e->getMessage());
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
                    // Fetch component specs from JSON using UUID
                    $componentUuid = $component['uuid'] ?? null;
                    if (!$componentUuid) {
                        continue;
                    }

                    $power = $this->calculateComponentPowerFromJSON($type, $componentUuid);
                    $totalPower += $power * ($component['quantity'] ?? 1);
                }
            }

            $totalPowerWithOverhead = $totalPower * 1.2;

            error_log("Power calculation for $configUuid: Base={$totalPower}W, WithOverhead={$totalPowerWithOverhead}W");

            // Calculate compatibility score
            $compatibilityScore = null;
            if (class_exists('CompatibilityEngine')) {
                try {
                    $compatibilityResult = $this->calculateHardwareCompatibilityScore($details);
                    $compatibilityScore = $compatibilityResult['score'];
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
                'overall_score' => 1.0, // Start with perfect score and deduct for issues
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'global_checks' => [],
                'component_summary' => [
                    'total_components' => $summary['total_components'] ?? 0,
                    'component_counts' => $summary['component_counts'] ?? []
                ]
            ];
            
            // Check for required components
            $requiredComponents = ['cpu', 'motherboard', 'ram'];
            $presentComponents = array_keys($summary['components'] ?? []);
            
            foreach ($requiredComponents as $required) {
                $isPresent = in_array($required, $presentComponents);
                $validation['global_checks'][] = [
                    'check' => ucfirst($required) . ' Required',
                    'passed' => $isPresent,
                    'message' => $isPresent ? ucfirst($required) . ' component is present' : ucfirst($required) . ' is required for server configuration'
                ];
                
                if (!$isPresent) {
                    $validation['is_valid'] = false;
                    $validation['issues'][] = "Missing required component: " . ucfirst($required);
                    $validation['overall_score'] -= 0.3; // Deduct 30% for each missing required component
                }
            }
            
            // Check recommended components
            $recommendedComponents = ['storage'];
            foreach ($recommendedComponents as $recommended) {
                $isPresent = in_array($recommended, $presentComponents);
                $validation['global_checks'][] = [
                    'check' => ucfirst($recommended) . ' Recommended',
                    'passed' => $isPresent,
                    'message' => $isPresent ? ucfirst($recommended) . ' component is present' : "At least one " . $recommended . " device is recommended"
                ];
                
                if (!$isPresent) {
                    $validation['warnings'][] = "Missing recommended component: " . ucfirst($recommended);
                    $validation['overall_score'] -= 0.1; // Deduct 10% for each missing recommended component
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
            
            // ENHANCED: Calculate compatibility score with detailed diagnostics
            $compatibilityResult = $this->calculateHardwareCompatibilityScore($summary);
            $compatibilityScore = $compatibilityResult['score'];
            $compatibilityDiagnostics = $compatibilityResult['diagnostics'];
            
            $validation['compatibility_score'] = $compatibilityScore;
            
            // Add detailed compatibility diagnostics to recommendations
            if (!empty($compatibilityDiagnostics)) {
                $validation['recommendations'] = array_merge($validation['recommendations'], $compatibilityDiagnostics);
            }
            
            // Adjust overall validity based on compatibility
            if ($compatibilityScore < 70) {
                $validation['is_valid'] = false;
                $validation['issues'][] = "Hardware compatibility issues detected (score: $compatibilityScore%)";
            }
            
            // Add general recommendations based on score
            if (!$validation['is_valid']) {
                $validation['recommendations'][] = "Resolve all compatibility issues before finalizing";
            }
            if ($compatibilityScore < 90 && empty($compatibilityDiagnostics)) {
                $validation['recommendations'][] = "Review component compatibility for optimal performance";
            }
            
            // Ensure overall_score is within bounds
            $validation['overall_score'] = max(0.0, min(1.0, $validation['overall_score']));
            
            error_log("Validation complete. Is valid: " . ($validation['is_valid'] ? 'yes' : 'no') . ", Overall Score: " . $validation['overall_score'] . ", Compatibility Score: " . ($validation['compatibility_score'] ?? 'N/A'));
            
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
     * Enhanced JSON-based configuration validation
     */
    public function validateConfigurationEnhanced($configUuid) {
        try {
            // Initialize compatibility engine
            require_once __DIR__ . '/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);
            
            // Start with basic validation
            $basicValidation = $this->validateConfiguration($configUuid);
            
            // Get all components in configuration
            $stmt = $this->pdo->prepare("
                SELECT component_type, component_uuid FROM server_configuration_components 
                WHERE config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $enhancedValidation = [
                'is_valid' => $basicValidation['is_valid'],
                'overall_score' => $basicValidation['overall_score'],
                'compatibility_score' => $basicValidation['compatibility_score'] ?? 0,
                'issues' => $basicValidation['issues'],
                'warnings' => $basicValidation['warnings'],
                'recommendations' => $basicValidation['recommendations'],
                'global_checks' => $basicValidation['global_checks'],
                'component_summary' => $basicValidation['component_summary'],
                'json_validation' => [
                    'enabled' => true,
                    'component_checks' => [],
                    'compatibility_matrix' => [],
                    'detailed_scores' => []
                ]
            ];
            
            // Phase 1: Validate all components exist in JSON
            foreach ($components as $component) {
                $existsResult = $compatibility->validateComponentExistsInJSON(
                    $component['component_type'],
                    $component['component_uuid']
                );

                $checkResult = [
                    'component_type' => $component['component_type'],
                    'component_uuid' => $component['component_uuid'],
                    'exists_in_json' => $existsResult,
                    'status' => $existsResult ? 'pass' : 'fail'
                ];

                if (!$existsResult) {
                    $enhancedValidation['is_valid'] = false;
                    $enhancedValidation['issues'][] = "Component {$component['component_uuid']} ({$component['component_type']}) not found in JSON specifications database";
                    $enhancedValidation['overall_score'] -= 0.2;
                }
                
                $enhancedValidation['json_validation']['component_checks'][] = $checkResult;
            }
            
            // Phase 2: Run comprehensive compatibility matrix
            $motherboardSpecs = $this->getConfigurationMotherboardSpecs($configUuid);
            
            if ($motherboardSpecs['found']) {
                $limits = $motherboardSpecs['limits'];
                
                // Check CPU compatibility
                foreach ($components as $component) {
                    if ($component['component_type'] === 'cpu') {
                        $socketResult = $compatibility->validateCPUSocketCompatibility(
                            $component['component_uuid'], 
                            $limits
                        );
                        
                        $matrixEntry = [
                            'component1_type' => 'motherboard',
                            'component2_type' => 'cpu',
                            'component2_uuid' => $component['component_uuid'],
                            'compatibility_check' => 'socket_compatibility',
                            'result' => $socketResult
                        ];
                        
                        if (!$socketResult['compatible']) {
                            $enhancedValidation['is_valid'] = false;
                            $enhancedValidation['issues'][] = $socketResult['error'];
                            $enhancedValidation['compatibility_score'] = min($enhancedValidation['compatibility_score'], 0);
                        }
                        
                        $enhancedValidation['json_validation']['compatibility_matrix'][] = $matrixEntry;
                    }
                }
                
                // Check RAM compatibility
                foreach ($components as $component) {
                    if ($component['component_type'] === 'ram') {
                        $typeResult = $compatibility->validateRAMTypeCompatibility(
                            $component['component_uuid'], 
                            $limits
                        );
                        
                        $slotResult = $compatibility->validateRAMSlotAvailability($configUuid, $limits);
                        
                        $matrixEntry = [
                            'component1_type' => 'motherboard',
                            'component2_type' => 'ram',
                            'component2_uuid' => $component['component_uuid'],
                            'compatibility_check' => 'type_and_slot_compatibility',
                            'result' => [
                                'type_compatible' => $typeResult['compatible'],
                                'slots_available' => $slotResult['available'],
                                'type_error' => $typeResult['error'],
                                'slot_error' => $slotResult['error']
                            ]
                        ];
                        
                        if (!$typeResult['compatible']) {
                            $enhancedValidation['is_valid'] = false;
                            $enhancedValidation['issues'][] = $typeResult['error'];
                            $enhancedValidation['compatibility_score'] = min($enhancedValidation['compatibility_score'], 10);
                        }
                        
                        if (!$slotResult['available']) {
                            $enhancedValidation['is_valid'] = false;
                            $enhancedValidation['issues'][] = $slotResult['error'];
                        }
                        
                        $enhancedValidation['json_validation']['compatibility_matrix'][] = $matrixEntry;
                    }
                }
                
                // Check NIC compatibility
                foreach ($components as $component) {
                    if ($component['component_type'] === 'nic') {
                        $nicResult = $compatibility->validateNICPCIeCompatibility(
                            $component['component_uuid'], 
                            $configUuid, 
                            $limits
                        );
                        
                        $matrixEntry = [
                            'component1_type' => 'motherboard',
                            'component2_type' => 'nic',
                            'component2_uuid' => $component['component_uuid'],
                            'compatibility_check' => 'pcie_compatibility',
                            'result' => $nicResult
                        ];
                        
                        if (!$nicResult['compatible']) {
                            $enhancedValidation['warnings'][] = $nicResult['error'];
                            $enhancedValidation['compatibility_score'] = min($enhancedValidation['compatibility_score'], 70);
                        }
                        
                        $enhancedValidation['json_validation']['compatibility_matrix'][] = $matrixEntry;
                    }
                }
            }
            
            // Phase 3: Generate detailed compatibility scores
            $enhancedValidation['json_validation']['detailed_scores'] = [
                'json_existence_score' => $this->calculateJSONExistenceScore($enhancedValidation['json_validation']['component_checks']),
                'compatibility_matrix_score' => $this->calculateCompatibilityMatrixScore($enhancedValidation['json_validation']['compatibility_matrix']),
                'overall_json_score' => min(
                    $this->calculateJSONExistenceScore($enhancedValidation['json_validation']['component_checks']),
                    $this->calculateCompatibilityMatrixScore($enhancedValidation['json_validation']['compatibility_matrix'])
                )
            ];
            
            // Adjust overall scores based on JSON validation
            $jsonScore = $enhancedValidation['json_validation']['detailed_scores']['overall_json_score'];
            $enhancedValidation['overall_score'] = min($enhancedValidation['overall_score'], $jsonScore / 100.0);
            $enhancedValidation['compatibility_score'] = min($enhancedValidation['compatibility_score'], $jsonScore);
            
            if ($jsonScore < 70) {
                $enhancedValidation['is_valid'] = false;
                $enhancedValidation['issues'][] = "JSON-based compatibility validation failed (score: {$jsonScore}%)";
            }
            
            return $enhancedValidation;
            
        } catch (Exception $e) {
            error_log("Error in enhanced validation: " . $e->getMessage());
            return [
                'is_valid' => false,
                'overall_score' => 0.0,
                'compatibility_score' => 0,
                'issues' => ['Enhanced validation failed: ' . $e->getMessage()],
                'warnings' => [],
                'recommendations' => [],
                'json_validation' => [
                    'enabled' => false,
                    'error' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Calculate JSON existence score
     */
    private function calculateJSONExistenceScore($componentChecks) {
        if (empty($componentChecks)) {
            return 100;
        }
        
        $passCount = 0;
        foreach ($componentChecks as $check) {
            if ($check['status'] === 'pass') {
                $passCount++;
            }
        }
        
        return round(($passCount / count($componentChecks)) * 100, 1);
    }

    /**
     * Calculate compatibility matrix score
     */
    private function calculateCompatibilityMatrixScore($compatibilityMatrix) {
        if (empty($compatibilityMatrix)) {
            return 100;
        }
        
        $totalScore = 0;
        $totalChecks = count($compatibilityMatrix);
        
        foreach ($compatibilityMatrix as $check) {
            $checkScore = 100;
            
            if (isset($check['result']['compatible']) && !$check['result']['compatible']) {
                $checkScore = 0;
            } elseif (isset($check['result']['type_compatible']) && !$check['result']['type_compatible']) {
                $checkScore = 0;
            } elseif (isset($check['result']['slots_available']) && !$check['result']['slots_available']) {
                $checkScore = 50; // Slot availability issues are less critical than incompatibility
            }
            
            $totalScore += $checkScore;
        }
        
        return round($totalScore / $totalChecks, 1);
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
            
            // Release components back to available status and clear ServerUUID, installation date, and rack position
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
     * Check if component UUID already exists in configuration
     */
    private function isDuplicateComponent($configUuid, $componentUuid) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*), component_type FROM server_configuration_components 
                WHERE config_uuid = ? AND component_uuid = ?
                GROUP BY component_type
            ");
            $stmt->execute([$configUuid, $componentUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $isDuplicate = $result && $result['COUNT(*)'] > 0;
            
            // Debug logging
            error_log("Duplicate check: ConfigUUID=$configUuid, ComponentUUID=$componentUuid");
            error_log("Result: " . ($isDuplicate ? "DUPLICATE FOUND" : "NO DUPLICATE") . 
                     ($result ? " (Type: {$result['component_type']}, Count: {$result['COUNT(*)']})" : " (No records found)"));
            
            return $isDuplicate;
        } catch (Exception $e) {
            error_log("Error checking duplicate component: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Validate CPU addition with socket and count limits
     */
    private function validateCPUAddition($configUuid, $cpuUuid, $compatibility) {
        try {
            // Get motherboard specifications
            $motherboardSpecs = $this->getConfigurationMotherboardSpecs($configUuid);
            if (!$motherboardSpecs['found']) {
                return [
                    'success' => false,
                    'message' => 'No motherboard found in configuration - add motherboard first'
                ];
            }
            
            $limits = $motherboardSpecs['limits'];
            
            // Check current CPU count
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as cpu_count FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type = 'cpu'
            ");
            $stmt->execute([$configUuid]);
            $currentCPUCount = (int)$stmt->fetchColumn();
            
            $maxSockets = $limits['cpu']['max_sockets'] ?? 1;
            
            // STRICT LIMIT ENFORCEMENT - reject if limit exceeded
            if ($currentCPUCount >= $maxSockets) {
                return [
                    'success' => false,
                    'message' => "CPU limit exceeded: motherboard supports maximum {$maxSockets} CPUs, currently has {$currentCPUCount} CPUs"
                ];
            }
            
            // Socket compatibility validation using JSON data
            $socketResult = $compatibility->validateCPUSocketCompatibility($cpuUuid, $limits);
            if (!$socketResult['compatible']) {
                return [
                    'success' => false,
                    'message' => $socketResult['error']
                ];
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log("Error validating CPU addition: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error validating CPU compatibility: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Comprehensive RAM validation before adding to configuration
     * Implements all critical compatibility checks as specified in Important-fix
     */
    private function validateRAMAddition($configUuid, $ramUuid, $compatibility) {
        try {
            // Task 1: JSON existence validation - RAM UUID must exist in JSON
            $ramValidation = $compatibility->validateRAMExistsInJSON($ramUuid);
            if (!$ramValidation['exists']) {
                return [
                    'success' => false,
                    'message' => 'RAM not found in specifications database',
                    'details' => ['error' => $ramValidation['error']]
                ];
            }
            
            $ramSpecs = $ramValidation['specifications'];
            
            // Get system component specifications for compatibility checking
            $motherboardResult = $this->getMotherboardSpecsFromConfig($configUuid);
            $cpuResult = $this->getCPUSpecsFromConfig($configUuid);
            
            if (!$motherboardResult['found']) {
                return [
                    'success' => false,
                    'message' => 'No motherboard found in configuration - add motherboard first'
                ];
            }
            
            $motherboardSpecs = $motherboardResult['specifications'];
            $cpuSpecs = $cpuResult['found'] ? $cpuResult['specifications'] : [];
            
            // Task 2: Memory type compatibility - DDR4/DDR5 matching
            $typeCheck = $compatibility->validateMemoryTypeCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs);
            if (!$typeCheck['compatible']) {
                return [
                    'success' => false,
                    'message' => $typeCheck['message'],
                    'details' => ['supported_types' => $typeCheck['supported_types']]
                ];
            }
            
            // Task 5: Memory slot limit validation
            $slotCheck = $compatibility->validateMemorySlotAvailability($configUuid, $motherboardSpecs);
            if (!$slotCheck['can_add']) {
                return [
                    'success' => false,
                    'message' => $slotCheck['error'],
                    'details' => [
                        'used_slots' => $slotCheck['used_slots'],
                        'max_slots' => $slotCheck['max_slots']
                    ]
                ];
            }
            
            // Task 4: Form factor validation - DIMM/SO-DIMM compatibility
            $formFactorCheck = $compatibility->validateMemoryFormFactor($ramSpecs, $motherboardSpecs);
            if (!$formFactorCheck['compatible']) {
                return [
                    'success' => false,
                    'message' => $formFactorCheck['message']
                ];
            }
            
            // Task 4: ECC compatibility validation
            $eccCheck = $compatibility->validateECCCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs);
            // Note: ECC compatibility issues are warnings, not blocking errors
            $warnings = [];
            if (isset($eccCheck['warning'])) {
                $warnings[] = $eccCheck['warning'];
            }
            if (isset($eccCheck['recommendation'])) {
                $warnings[] = $eccCheck['recommendation'];
            }
            
            // Task 3: Advanced frequency analysis - complex performance logic
            $frequencyAnalysis = $compatibility->analyzeMemoryFrequency($ramSpecs, $motherboardSpecs, $cpuSpecs);
            if ($frequencyAnalysis['status'] === 'error') {
                $warnings[] = $frequencyAnalysis['message'];
            } else {
                // Add performance warnings for frequency limitations
                if ($frequencyAnalysis['status'] === 'limited') {
                    $warnings[] = $frequencyAnalysis['message'];
                } elseif ($frequencyAnalysis['status'] === 'suboptimal') {
                    $warnings[] = $frequencyAnalysis['message'];
                }
            }
            
            return [
                'success' => true,
                'message' => 'RAM compatibility validation passed',
                'warnings' => $warnings,
                'compatibility_details' => [
                    'memory_type' => $typeCheck,
                    'frequency_analysis' => $frequencyAnalysis,
                    'form_factor' => $formFactorCheck,
                    'ecc_support' => $eccCheck,
                    'slot_availability' => $slotCheck
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Error validating RAM addition: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error validating RAM compatibility: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Validate component compatibility before adding
     */
    private function validateComponentCompatibilityBeforeAdd($configUuid, $componentType, $componentUuid, $compatibility) {
        try {
            switch ($componentType) {
                case 'motherboard':
                    // Check if configuration already has a motherboard
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(*) FROM server_configuration_components 
                        WHERE config_uuid = ? AND component_type = 'motherboard'
                    ");
                    $stmt->execute([$configUuid]);
                    if ($stmt->fetchColumn() > 0) {
                        return [
                            'success' => false,
                            'message' => 'Configuration already has a motherboard. Remove existing motherboard first.'
                        ];
                    }
                    break;
                    
                case 'ram':
                    // Skip legacy RAM validation - flexible system handles compatibility
                    break;
                    break;
                    
                case 'nic':
                    // Skip legacy NIC validation - flexible system handles compatibility
                    break;

            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            error_log("Error validating component compatibility: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error validating component compatibility: ' . $e->getMessage()
            ];
        }
    }
    
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
        return in_array($componentType, ['chassis', 'motherboard']);
    }

    /**
     * Validate component addition order
     */
    private function validateComponentCompatibility($configUuid, $componentType, $componentUuid) {
        try {
            // Get existing components in the configuration
            $stmt = $this->pdo->prepare("
                SELECT component_type, component_uuid
                FROM server_configuration_components
                WHERE config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $existingComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // If no existing components, allow any component to be added first
            if (empty($existingComponents)) {
                return ['success' => true, 'message' => 'First component can be any type'];
            }

            // Special handling for chassis - only one chassis allowed per configuration
            if ($componentType === 'chassis') {
                $hasChasis = false;
                foreach ($existingComponents as $existing) {
                    if ($existing['component_type'] === 'chassis') {
                        $hasChasis = true;
                        break;
                    }
                }
                if ($hasChasis) {
                    return [
                        'success' => false,
                        'message' => 'Chassis already exists in this configuration. Remove existing chassis first.'
                    ];
                }
                return ['success' => true, 'message' => 'Chassis can be added'];
            }

            // Check compatibility with each existing component
            require_once __DIR__ . '/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);

            $newComponent = [
                'type' => $componentType,
                'uuid' => $componentUuid
            ];

            foreach ($existingComponents as $existing) {
                $existingComponent = [
                    'type' => $existing['component_type'],
                    'uuid' => $existing['component_uuid']
                ];

                // Check compatibility between new component and each existing one
                $compatResult = $compatibility->checkComponentPairCompatibility($newComponent, $existingComponent);

                // If any incompatibility found, reject the addition
                if (!$compatResult['compatible']) {
                    return [
                        'success' => false,
                        'message' => "Component $componentUuid is not compatible with existing " .
                                   $existing['component_type'] . " (" . $existing['component_uuid'] . "). " .
                                   implode(', ', $compatResult['issues'] ?? [])
                    ];
                }
            }

            return ['success' => true, 'message' => 'Component is compatible with existing configuration'];

        } catch (Exception $e) {
            error_log("Error validating component compatibility: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error validating component compatibility: ' . $e->getMessage()
            ];
        }
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
     * Update component status, ServerUUID, location, rack position, and installation date
     */
    private function updateComponentStatusAndServerUuid($componentType, $componentUuid, $newStatus, $serverUuid, $reason = '', $serverLocation = null, $serverRackPosition = null) {
        if (!isset($this->componentTables[$componentType])) {
            error_log("Cannot update status - invalid component type: $componentType");
            return false;
        }
        
        try {
            $table = $this->componentTables[$componentType];
            
            // Get current status first for logging
            $stmt = $this->pdo->prepare("SELECT Status, ServerUUID, Location, RackPosition, InstallationDate FROM $table WHERE UUID = ?");
            $stmt->execute([$componentUuid]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($current === false) {
                error_log("Cannot update status - component not found: $componentUuid in $table");
                return false;
            }
            
            // Prepare update fields and values
            $updateFields = ["Status = ?", "ServerUUID = ?", "UpdatedAt = NOW()"];
            $updateValues = [$newStatus, $serverUuid];
            
            // Handle installation date
            if ($newStatus == 2 && $serverUuid !== null) {
                // Component is being assigned to a server - set installation date to current timestamp
                $updateFields[] = "InstallationDate = CURDATE()";
            } elseif ($newStatus == 1 && $serverUuid === null) {
                // Component is being released from server - clear installation date
                $updateFields[] = "InstallationDate = NULL";
            }
            
            // Handle location and rack position updates
            if ($newStatus == 2 && $serverUuid !== null) {
                // Component is being assigned to a server - always update location and rack position
                $updateFields[] = "Location = ?";
                $updateValues[] = $serverLocation; // This can be null if server has no location
                
                $updateFields[] = "RackPosition = ?";
                $updateValues[] = $serverRackPosition; // This can be null if server has no rack position
                
            } elseif ($newStatus == 1 && $serverUuid === null) {
                // Component is being released from server - clear rack position but keep location
                $updateFields[] = "RackPosition = NULL";
                // We don't clear location as component still exists in physical location
            }
            
            $updateValues[] = $componentUuid;
            
            // Execute update
            $sql = "UPDATE $table SET " . implode(', ', $updateFields) . " WHERE UUID = ?";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($updateValues);
            
            if ($result) {
                $locationInfo = "";
                if ($serverLocation !== null || $serverRackPosition !== null) {
                    $locationInfo = " Location: '$serverLocation', RackPosition: '$serverRackPosition'";
                }
                error_log("Updated component: $componentUuid in $table - Status: {$current['Status']} -> $newStatus, ServerUUID: '{$current['ServerUUID']}' -> '$serverUuid'$locationInfo - $reason");
            } else {
                error_log("Failed to update component: $componentUuid in $table");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error updating component assignment: " . $e->getMessage());
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
     * Calculate component power consumption from JSON specifications
     */
    private function calculateComponentPowerFromJSON($componentType, $componentUuid) {
        // Default power estimates by component type (watts)
        $defaultPower = [
            'cpu' => 150,
            'ram' => 8,
            'storage' => 15,
            'motherboard' => 50,
            'nic' => 25,
            'caddy' => 5,
            'pciecard' => 30,
            'hbacard' => 20,
            'chassis' => 0  // Chassis doesn't consume power directly
        ];

        try {
            // Fetch component specs from JSON files
            $specs = null;
            switch ($componentType) {
                case 'cpu':
                    $specs = $this->dataUtils->getCPUByUUID($componentUuid);
                    if ($specs && isset($specs['tdp_W'])) {
                        return (int)$specs['tdp_W'];
                    }
                    break;

                case 'ram':
                    $specs = $this->dataUtils->getRAMByUUID($componentUuid);
                    // RAM power consumption is typically 3-8W per module
                    // DDR5 consumes more than DDR4
                    if ($specs) {
                        $type = strtolower($specs['memory_type'] ?? '');
                        if (strpos($type, 'ddr5') !== false) {
                            return 8; // DDR5: ~8W per module
                        } elseif (strpos($type, 'ddr4') !== false) {
                            return 5; // DDR4: ~5W per module
                        } elseif (strpos($type, 'ddr3') !== false) {
                            return 4; // DDR3: ~4W per module
                        }
                    }
                    return 8; // Default DDR5

                case 'storage':
                    $specs = $this->dataUtils->getStorageByUUID($componentUuid);
                    if ($specs) {
                        $interface = strtolower($specs['interface'] ?? '');
                        $formFactor = strtolower($specs['form_factor'] ?? '');

                        // NVMe M.2: 5-10W active, 3-5W idle (average 7W)
                        if (strpos($interface, 'nvme') !== false && strpos($formFactor, 'm.2') !== false) {
                            return 7;
                        }
                        // NVMe U.2: 10-15W active, 5-8W idle (average 10W)
                        elseif (strpos($interface, 'nvme') !== false && strpos($formFactor, 'u.2') !== false) {
                            return 10;
                        }
                        // SAS HDD: 10-12W active, 6-8W idle (average 10W)
                        elseif (strpos($interface, 'sas') !== false) {
                            return 10;
                        }
                        // SATA SSD: 2-5W active, 1-2W idle (average 3W)
                        elseif (strpos($interface, 'sata') !== false && strpos($formFactor, 'ssd') !== false) {
                            return 3;
                        }
                        // SATA HDD: 6-10W active, 4-6W idle (average 8W)
                        elseif (strpos($interface, 'sata') !== false) {
                            return 8;
                        }
                    }
                    return 15; // Default

                case 'motherboard':
                    // Motherboards typically consume 40-80W depending on complexity
                    return 60; // Average estimate

                case 'nic':
                    $specs = $this->dataUtils->getNICByUUID($componentUuid);
                    if ($specs) {
                        $speed = $specs['speed'] ?? '';
                        // 10GbE NICs: ~20-30W
                        // 25GbE NICs: ~25-35W
                        // 1GbE NICs: ~5-10W
                        if (strpos($speed, '25') !== false) {
                            return 30;
                        } elseif (strpos($speed, '10') !== false) {
                            return 25;
                        } elseif (strpos($speed, '1') !== false) {
                            return 8;
                        }
                    }
                    return 25; // Default

                case 'pciecard':
                    $specs = $this->dataUtils->getPCIeCardByUUID($componentUuid);
                    if ($specs && isset($specs['tdp_W'])) {
                        return (int)$specs['tdp_W'];
                    }
                    // Estimate based on card type
                    $cardType = strtolower($specs['type'] ?? '');
                    if (strpos($cardType, 'gpu') !== false) {
                        return 75; // Mid-range GPU
                    } elseif (strpos($cardType, 'raid') !== false) {
                        return 25; // RAID controllers
                    }
                    return 30; // Default PCIe card

                case 'hbacard':
                    $specs = $this->dataUtils->getHBACardByUUID($componentUuid);
                    if ($specs && isset($specs['power_consumption']['typical_W'])) {
                        return (int)$specs['power_consumption']['typical_W'];
                    }
                    return 20; // Default HBA card power

                case 'caddy':
                    return 0; // Caddies don't consume power

                case 'chassis':
                    return 0; // Chassis doesn't consume power (fans calculated separately)
            }

        } catch (Exception $e) {
            error_log("Error calculating power for $componentType ($componentUuid): " . $e->getMessage());
        }

        // Return default if unable to calculate
        return $defaultPower[$componentType] ?? 50;
    }

    /**
     * DEPRECATED: Calculate component power consumption from database Notes field
     * Kept for backwards compatibility but replaced by calculateComponentPowerFromJSON
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
     * ENHANCED: Calculate hardware compatibility score with detailed diagnostics
     */
    private function calculateHardwareCompatibilityScore($summary) {
        $score = 100.0;
        $components = $summary['components'] ?? [];
        $diagnostics = [];
        
        try {
            // If we don't have basic components, score is low
            if (empty($components)) {
                return ['score' => 0.0, 'diagnostics' => ['No components found in configuration']];
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
                $cpuResult = $this->checkMotherboardCpuCompatibilityDetailed($motherboard, $cpus);
                $score = min($score, $cpuResult['score']);
                if (!empty($cpuResult['issues'])) {
                    $diagnostics = array_merge($diagnostics, $cpuResult['issues']);
                }
            }
            
            // Check motherboard-RAM compatibility
            if ($motherboard && !empty($rams)) {
                $ramResult = $this->checkMotherboardRamCompatibilityDetailed($motherboard, $rams);
                $score = min($score, $ramResult['score']);
                if (!empty($ramResult['issues'])) {
                    $diagnostics = array_merge($diagnostics, $ramResult['issues']);
                }
            }
            
            // Check power requirements vs motherboard capacity
            $powerResult = $this->checkPowerCompatibilityDetailed($components);
            $score = min($score, $powerResult['score']);
            if (!empty($powerResult['issues'])) {
                $diagnostics = array_merge($diagnostics, $powerResult['issues']);
            }
            
            // Check form factor compatibility
            $formFactorResult = $this->checkFormFactorCompatibilityDetailed($components);
            $score = min($score, $formFactorResult['score']);
            if (!empty($formFactorResult['issues'])) {
                $diagnostics = array_merge($diagnostics, $formFactorResult['issues']);
            }
            
        } catch (Exception $e) {
            error_log("Error calculating hardware compatibility score: " . $e->getMessage());
            $score = 50.0;
            $diagnostics[] = "Error during compatibility analysis: " . $e->getMessage();
        }
        
        return [
            'score' => round($score, 1),
            'diagnostics' => $diagnostics
        ];
    }
    
    /**
     * Check motherboard-CPU socket compatibility (legacy method)
     */
    private function checkMotherboardCpuCompatibility($motherboard, $cpus) {
        $result = $this->checkMotherboardCpuCompatibilityDetailed($motherboard, $cpus);
        return $result['score'];
    }
    
    /**
     * Check motherboard-CPU socket compatibility with detailed diagnostics
     */
    private function checkMotherboardCpuCompatibilityDetailed($motherboard, $cpus) {
        $score = 100.0;
        $issues = [];
        
        try {
            $mbNotes = strtolower($motherboard['Notes'] ?? '');
            $mbSerialNumber = $motherboard['SerialNumber'] ?? 'Unknown';
            
            // Extract motherboard socket type
            $mbSocket = $this->extractSocketType($mbNotes);
            
            foreach ($cpus as $cpu) {
                $cpuNotes = strtolower($cpu['Notes'] ?? '');
                $cpuSerialNumber = $cpu['SerialNumber'] ?? 'Unknown';
                $cpuSocket = $this->extractSocketType($cpuNotes);
                
                if ($mbSocket && $cpuSocket) {
                    if ($mbSocket !== $cpuSocket) {
                        $score = 0.0; // Complete incompatibility
                        $issues[] = "Critical: CPU socket mismatch - Motherboard ($mbSerialNumber) has $mbSocket socket, but CPU ($cpuSerialNumber) requires $cpuSocket socket";
                        break;
                    }
                } else {
                    // If we can't determine socket types, reduce score but don't fail completely
                    $score = min($score, 70.0);
                    if (!$mbSocket && !$cpuSocket) {
                        $issues[] = "Warning: Cannot determine socket compatibility for Motherboard ($mbSerialNumber) and CPU ($cpuSerialNumber) - socket information missing from component specifications";
                    } elseif (!$mbSocket) {
                        $issues[] = "Warning: Cannot determine motherboard socket type for ($mbSerialNumber) - missing socket specification";
                    } else {
                        $issues[] = "Warning: Cannot determine CPU socket type for ($cpuSerialNumber) - missing socket specification";
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("Error checking motherboard-CPU compatibility: " . $e->getMessage());
            $score = 50.0;
            $issues[] = "Error: Failed to analyze CPU-Motherboard compatibility - " . $e->getMessage();
        }
        
        return [
            'score' => $score,
            'issues' => $issues
        ];
    }
    
    /**
     * Check motherboard-RAM compatibility (legacy method)
     */
    private function checkMotherboardRamCompatibility($motherboard, $rams) {
        $result = $this->checkMotherboardRamCompatibilityDetailed($motherboard, $rams);
        return $result['score'];
    }
    
    /**
     * Check motherboard-RAM compatibility with detailed diagnostics
     */
    private function checkMotherboardRamCompatibilityDetailed($motherboard, $rams) {
        $score = 100.0;
        $issues = [];
        
        try {
            $mbNotes = strtolower($motherboard['Notes'] ?? '');
            $mbSerialNumber = $motherboard['SerialNumber'] ?? 'Unknown';
            
            // Extract motherboard supported RAM types
            $mbMemoryTypes = $this->extractMemoryTypes($mbNotes);
            
            foreach ($rams as $ram) {
                $ramNotes = strtolower($ram['Notes'] ?? '');
                $ramSerialNumber = $ram['SerialNumber'] ?? 'Unknown';
                $ramType = $this->extractMemoryType($ramNotes);
                
                if (!empty($mbMemoryTypes) && $ramType) {
                    if (!in_array($ramType, $mbMemoryTypes)) {
                        $score = min($score, 10.0); // Major incompatibility
                        $issues[] = "Critical: Memory type incompatibility - Motherboard ($mbSerialNumber) supports " . implode(', ', $mbMemoryTypes) . ", but RAM ($ramSerialNumber) is $ramType";
                    }
                } else {
                    // If we can't determine memory types, reduce score slightly
                    $score = min($score, 80.0);
                    if (empty($mbMemoryTypes) && !$ramType) {
                        $issues[] = "Warning: Cannot determine memory compatibility for Motherboard ($mbSerialNumber) and RAM ($ramSerialNumber) - memory type specifications missing";
                    } elseif (empty($mbMemoryTypes)) {
                        $issues[] = "Warning: Cannot determine supported memory types for Motherboard ($mbSerialNumber) - specification missing";
                    } else {
                        $issues[] = "Warning: Cannot determine memory type for RAM ($ramSerialNumber) - specification missing";
                    }
                }
            }
            
        } catch (Exception $e) {
            error_log("Error checking motherboard-RAM compatibility: " . $e->getMessage());
            $score = 60.0;
            $issues[] = "Error: Failed to analyze RAM-Motherboard compatibility - " . $e->getMessage();
        }
        
        return [
            'score' => $score,
            'issues' => $issues
        ];
    }
    
    /**
     * Check power compatibility (legacy method)
     */
    private function checkPowerCompatibility($components) {
        $result = $this->checkPowerCompatibilityDetailed($components);
        return $result['score'];
    }
    
    /**
     * Check power compatibility with detailed diagnostics
     */
    private function checkPowerCompatibilityDetailed($components) {
        $score = 100.0;
        $issues = [];
        
        try {
            $totalPower = 0;
            $componentPowerBreakdown = [];
            
            foreach ($components as $type => $typeComponents) {
                $typePower = 0;
                foreach ($typeComponents as $component) {
                    $details = $component['details'] ?? [];
                    $power = $this->calculateComponentPower($type, $details);
                    $quantity = $component['quantity'] ?? 1;
                    $typePower += $power * $quantity;
                    $totalPower += $power * $quantity;
                }
                if ($typePower > 0) {
                    $componentPowerBreakdown[$type] = $typePower;
                }
            }
            
            // Check if total power is reasonable (not too high for typical motherboard)
            if ($totalPower > 1000) { // Very high power consumption
                $score = 30.0;
                $issues[] = "Critical: Very high power consumption (${totalPower}W) - may exceed typical PSU capacity and cause system instability";
                $issues[] = "Power breakdown: " . $this->formatPowerBreakdown($componentPowerBreakdown);
            } elseif ($totalPower > 750) {
                $score = 60.0;
                $issues[] = "Warning: High power consumption (${totalPower}W) - ensure adequate PSU capacity (recommended 850W+ PSU)";
                $issues[] = "Power breakdown: " . $this->formatPowerBreakdown($componentPowerBreakdown);
            } elseif ($totalPower > 500) {
                $score = 85.0;
                $issues[] = "Note: Moderate power consumption (${totalPower}W) - ensure PSU capacity is at least 650W";
            }
            
        } catch (Exception $e) {
            error_log("Error checking power compatibility: " . $e->getMessage());
            $score = 75.0;
            $issues[] = "Error: Failed to analyze power compatibility - " . $e->getMessage();
        }
        
        return [
            'score' => $score,
            'issues' => $issues
        ];
    }
    
    /**
     * Check form factor compatibility (legacy method)
     */
    private function checkFormFactorCompatibility($components) {
        $result = $this->checkFormFactorCompatibilityDetailed($components);
        return $result['score'];
    }
    
    /**
     * Check form factor compatibility with detailed diagnostics
     */
    private function checkFormFactorCompatibilityDetailed($components) {
        $score = 100.0;
        $issues = [];
        
        try {
            // Check memory slot constraints
            if (isset($components['motherboard']) && isset($components['ram'])) {
                $motherboard = $components['motherboard'][0]['details'] ?? null;
                $mbSerialNumber = $motherboard['SerialNumber'] ?? 'Unknown';
                $ramCount = count($components['ram']);
                
                // Estimate memory slots based on motherboard type or use default
                $estimatedSlots = $this->estimateMemorySlots($motherboard);
                
                if ($ramCount > $estimatedSlots) {
                    $score = 20.0;
                    $issues[] = "Critical: Memory slot overflow - trying to install $ramCount RAM modules but motherboard ($mbSerialNumber) likely has only $estimatedSlots slots";
                } elseif ($ramCount > 8) {
                    $score = 40.0;
                    $issues[] = "Warning: Very high RAM module count ($ramCount) - verify motherboard ($mbSerialNumber) supports this many modules";
                } elseif ($ramCount > 6) {
                    $score = 70.0;
                    $issues[] = "Note: High RAM module count ($ramCount) - ensure motherboard ($mbSerialNumber) has sufficient slots";
                }
            }
            
            // Check storage interface constraints
            if (isset($components['motherboard']) && isset($components['storage'])) {
                $storageCount = count($components['storage']);
                $motherboard = $components['motherboard'][0]['details'] ?? null;
                $mbSerialNumber = $motherboard['SerialNumber'] ?? 'Unknown';
                
                if ($storageCount > 8) {
                    $score = min($score, 60.0);
                    $issues[] = "Warning: Very high storage device count ($storageCount) - ensure motherboard ($mbSerialNumber) has sufficient SATA/NVMe ports";
                } elseif ($storageCount > 6) {
                    $score = min($score, 80.0);
                    $issues[] = "Note: High storage device count ($storageCount) - verify sufficient ports on motherboard ($mbSerialNumber)";
                }
            }
            
        } catch (Exception $e) {
            error_log("Error checking form factor compatibility: " . $e->getMessage());
            $score = 85.0;
            $issues[] = "Error: Failed to analyze form factor compatibility - " . $e->getMessage();
        }
        
        return [
            'score' => $score,
            'issues' => $issues
        ];
    }
    
    /**
     * Format power breakdown for display
     */
    private function formatPowerBreakdown($powerBreakdown) {
        $formatted = [];
        foreach ($powerBreakdown as $type => $power) {
            $formatted[] = ucfirst($type) . ": {$power}W";
        }
        return implode(', ', $formatted);
    }
    
    /**
     * Estimate memory slots based on motherboard specifications
     */
    private function estimateMemorySlots($motherboard) {
        if (!$motherboard) {
            return 4; // Default assumption
        }
        
        $notes = strtolower($motherboard['Notes'] ?? '');
        
        // Try to extract memory slot count from notes
        if (preg_match('/(\d+)\s*(dimm|memory)\s*slot/i', $notes, $matches)) {
            return (int)$matches[1];
        }
        
        // Check for server/workstation indicators that typically have more slots
        if (strpos($notes, 'server') !== false || strpos($notes, 'workstation') !== false) {
            return 8; // Server motherboards typically have 8+ slots
        }
        
        // Check for high-end desktop indicators
        if (strpos($notes, 'x99') !== false || strpos($notes, 'x299') !== false || strpos($notes, 'trx40') !== false) {
            return 8; // HEDT platforms typically have 8 slots
        }
        
        return 4; // Standard desktop assumption
    }
    
    /**
     * Extract socket type from component notes with enhanced component knowledge base
     */
    private function extractSocketType($notes) {
        $notes = strtolower($notes);
        
        // Component knowledge base for common server components
        $componentSocketMap = [
            // Intel Xeon CPUs
            'platinum 8480+' => 'lga4677',
            'platinum 8480' => 'lga4677',
            'platinum 8470' => 'lga4677',
            'platinum 8460' => 'lga4677',
            'platinum 8450' => 'lga4677',
            'gold 6430' => 'lga4677',
            'gold 6420' => 'lga4677',
            'gold 6410' => 'lga4677',
            'silver 4410' => 'lga4677',
            'bronze 3408' => 'lga4677',
            'xeon 8' => 'lga4677', // Generic 4th gen Xeon pattern
            
            // AMD EPYC CPUs
            'epyc 9534' => 'sp5',
            'epyc 9554' => 'sp5',
            'epyc 9634' => 'sp5',
            'epyc 9654' => 'sp5',
            'epyc 64-core' => 'sp5', // Generic EPYC pattern
            
            // Motherboard models
            'x13dri-n' => 'lga4677',
            'x13dpi-n' => 'lga4677',
            'x12dpi-nt6' => 'lga4189',
            'x12dpi-n6' => 'lga4189',
            'h12dsi-n6' => 'sp3',
            'h12ssl-i' => 'sp3',
            'mz93-fs0' => 'sp5',
            'z790 godlike' => 'lga1700',
            'z790' => 'lga1700',
            'b650' => 'am5',
        ];
        
        // Check component knowledge base first
        foreach ($componentSocketMap as $component => $socket) {
            if (strpos($notes, $component) !== false) {
                return $socket;
            }
        }
        
        // Fallback to socket pattern matching
        $commonSockets = [
            'lga4677', 'lga4189', 'lga3647', 'lga2066', 'lga2011',
            'lga1700', 'lga1200', 'lga1151', 'lga1150', 'lga1155', 'lga1156',
            'sp5', 'sp3', 'sp4', 'am5', 'am4', 'tr4', 'strx4',
            'socket 4677', 'socket 4189', 'socket 3647', 'socket 2066', 'socket 2011',
            'socket 1700', 'socket 1200', 'socket 1151', 'socket 1150',
            'socket am5', 'socket am4', 'socket sp5', 'socket sp3'
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
    
    /**
     * Add component to additional_components JSON field
     */
    private function addToAdditionalComponents($configUuid, $componentType, $componentUuid) {
        try {
            $stmt = $this->pdo->prepare("SELECT additional_components FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentComponents = $stmt->fetchColumn();
            
            $additionalComponents = $currentComponents ? json_decode($currentComponents, true) : [];
            if (!is_array($additionalComponents)) {
                $additionalComponents = [];
            }
            
            // Initialize component type array if not exists
            if (!isset($additionalComponents[$componentType])) {
                $additionalComponents[$componentType] = [];
            }
            
            // Add the component UUID
            $additionalComponents[$componentType][] = [
                'uuid' => $componentUuid,
                'added_at' => date('Y-m-d H:i:s')
            ];
            
            $stmt = $this->pdo->prepare("UPDATE server_configurations SET additional_components = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($additionalComponents), $configUuid]);
            
            error_log("Added $componentType component $componentUuid to additional_components for config $configUuid");
            
        } catch (Exception $e) {
            error_log("Error adding to additional components: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Remove component from additional_components JSON field
     */
    private function removeFromAdditionalComponents($configUuid, $componentType, $componentUuid) {
        try {
            $stmt = $this->pdo->prepare("SELECT additional_components FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            $currentComponents = $stmt->fetchColumn();
            
            $additionalComponents = $currentComponents ? json_decode($currentComponents, true) : [];
            if (!is_array($additionalComponents)) {
                $additionalComponents = [];
            }
            
            // Remove the component UUID if it exists
            if (isset($additionalComponents[$componentType])) {
                $additionalComponents[$componentType] = array_filter(
                    $additionalComponents[$componentType], 
                    function($component) use ($componentUuid) {
                        return $component['uuid'] !== $componentUuid;
                    }
                );
                
                // Reindex array
                $additionalComponents[$componentType] = array_values($additionalComponents[$componentType]);
                
                // Remove empty component type arrays
                if (empty($additionalComponents[$componentType])) {
                    unset($additionalComponents[$componentType]);
                }
            }
            
            $stmt = $this->pdo->prepare("UPDATE server_configurations SET additional_components = ?, updated_at = NOW() WHERE config_uuid = ?");
            $stmt->execute([json_encode($additionalComponents), $configUuid]);
            
            error_log("Removed $componentType component $componentUuid from additional_components for config $configUuid");
            
        } catch (Exception $e) {
            error_log("Error removing from additional components: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get configuration motherboard specifications
     */
    public function getConfigurationMotherboardSpecs($configUuid) {
        try {
            // Get motherboard from configuration
            $stmt = $this->pdo->prepare("
                SELECT component_uuid FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type = 'motherboard'
                LIMIT 1
            ");
            $stmt->execute([$configUuid]);
            $motherboard = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$motherboard) {
                return [
                    'found' => false,
                    'error' => 'No motherboard found in configuration',
                    'specifications' => null
                ];
            }
            
            // Initialize compatibility engine
            require_once __DIR__ . '/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);
            
            // Get motherboard limits
            return $compatibility->getMotherboardLimits($motherboard['component_uuid']);
            
        } catch (Exception $e) {
            error_log("Error getting motherboard specs: " . $e->getMessage());
            return [
                'found' => false,
                'error' => 'Error retrieving motherboard specifications: ' . $e->getMessage(),
                'specifications' => null
            ];
        }
    }

    /**
     * Enhanced addComponent with JSON existence validation and compatibility checking
     */
    public function addComponentEnhanced($configUuid, $componentType, $componentUuid, $options = []) {
        try {
            // Initialize compatibility engine
            require_once __DIR__ . '/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);
            
            // Phase 1: JSON existence validation
            $existsResult = $compatibility->validateComponentExistsInJSON($componentType, $componentUuid);
            if (!$existsResult) {
                return [
                    'success' => false,
                    'message' => "Component UUID $componentUuid not found in $componentType JSON specifications",
                    'error_type' => 'json_not_found'
                ];
            }
            
            // Phase 2: Skip legacy motherboard requirement - flexible validation already handled above
            // New flexible system allows any component order with proper compatibility checking
            
            // Phase 4: Proceed with original addComponent logic
            $result = $this->addComponent($configUuid, $componentType, $componentUuid, $options);
            
            // Add compatibility information to successful response
            if ($result['success']) {
                $result['compatibility_info'] = $compatibilityResult['details'];
                $result['component_specifications'] = $this->getComponentSpecifications($componentType, $componentUuid, $compatibility);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error in enhanced addComponent: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error adding component: ' . $e->getMessage(),
                'error_type' => 'system_error'
            ];
        }
    }

    /**
     * Validate component compatibility based on type
     */
    private function validateComponentCompatibilityLegacy($componentType, $componentUuid, $configUuid, $motherboardSpecs, $compatibility) {
        switch ($componentType) {
            case 'motherboard':
                // For motherboard, validate it exists and can be parsed
                $mbValidation = $compatibility->validateMotherboardExists($componentUuid);
                return [
                    'compatible' => $mbValidation['exists'],
                    'error' => $mbValidation['error'],
                    'details' => ['validation' => 'motherboard_existence']
                ];
                
            case 'cpu':
                return $this->validateCPUCompatibility($componentUuid, $configUuid, $motherboardSpecs, $compatibility);
                
            case 'ram':
                return $this->validateRAMCompatibility($componentUuid, $configUuid, $motherboardSpecs, $compatibility);
                
            case 'storage':
                return $this->validateStorageCompatibility($componentUuid, $configUuid, $motherboardSpecs, $compatibility);
                
            case 'nic':
                return $this->validateNICCompatibility($componentUuid, $configUuid, $motherboardSpecs, $compatibility);
                
            case 'caddy':
                // Basic validation for caddy
                $caddyValidation = $compatibility->validateCaddyExists($componentUuid);
                return [
                    'compatible' => $caddyValidation['exists'],
                    'error' => $caddyValidation['error'],
                    'details' => ['validation' => 'caddy_existence']
                ];
                
            default:
                return [
                    'compatible' => true,
                    'error' => null,
                    'details' => ['validation' => 'no_specific_rules']
                ];
        }
    }

    /**
     * Validate CPU compatibility
     */
    private function validateCPUCompatibility($cpuUuid, $configUuid, $motherboardSpecs, $compatibility) {
        // Check socket compatibility
        $socketResult = $compatibility->validateCPUSocketCompatibility($cpuUuid, $motherboardSpecs);
        if (!$socketResult['compatible']) {
            return [
                'compatible' => false,
                'error' => $socketResult['error'],
                'details' => $socketResult['details']
            ];
        }
        
        // Check CPU count limit
        $countResult = $compatibility->validateCPUCountLimit($configUuid, $motherboardSpecs);
        if (!$countResult['within_limit']) {
            return [
                'compatible' => false,
                'error' => $countResult['error'],
                'details' => [
                    'current_count' => $countResult['current_count'],
                    'max_allowed' => $countResult['max_allowed']
                ]
            ];
        }
        
        // Check mixed CPU compatibility
        $stmt = $this->pdo->prepare("
            SELECT component_uuid FROM server_configuration_components 
            WHERE config_uuid = ? AND component_type = 'cpu'
        ");
        $stmt->execute([$configUuid]);
        $existingCPUs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $mixedResult = $compatibility->validateMixedCPUCompatibility($existingCPUs, $cpuUuid);
        if (!$mixedResult['compatible']) {
            return [
                'compatible' => false,
                'error' => $mixedResult['error'],
                'details' => $mixedResult['details']
            ];
        }
        
        return [
            'compatible' => true,
            'error' => null,
            'details' => [
                'socket_compatibility' => $socketResult['details'],
                'count_check' => $countResult,
                'mixed_compatibility' => $mixedResult['details']
            ]
        ];
    }

    /**
     * Validate RAM compatibility
     */
    private function validateRAMCompatibility($ramUuid, $configUuid, $motherboardSpecs, $compatibility) {
        // Check RAM type compatibility
        $typeResult = $compatibility->validateRAMTypeCompatibility($ramUuid, $motherboardSpecs);
        if (!$typeResult['compatible']) {
            return [
                'compatible' => false,
                'error' => $typeResult['error'],
                'details' => $typeResult['details']
            ];
        }
        
        // Check slot availability
        $slotResult = $compatibility->validateRAMSlotAvailability($configUuid, $motherboardSpecs);
        if (!$slotResult['available']) {
            return [
                'compatible' => false,
                'error' => $slotResult['error'],
                'details' => [
                    'used_slots' => $slotResult['used_slots'],
                    'total_slots' => $slotResult['total_slots']
                ]
            ];
        }
        
        return [
            'compatible' => true,
            'error' => null,
            'details' => [
                'type_compatibility' => $typeResult['details'],
                'slot_availability' => $slotResult
            ]
        ];
    }

    /**
     * Validate Storage compatibility using new JSON-driven system
     */
    private function validateStorageCompatibility($storageUuid, $configUuid, $motherboardSpecs, $compatibility) {
        try {
            // Get chassis UUID from the server configuration components
            $stmt = $this->pdo->prepare("
                SELECT component_uuid
                FROM server_configuration_components
                WHERE config_uuid = ? AND component_type = 'chassis'
                LIMIT 1
            ");
            $stmt->execute([$configUuid]);
            $configResult = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$configResult || !$configResult['component_uuid']) {
                return [
                    'compatible' => false,
                    'error' => 'No chassis configured for this server. Add a chassis before adding storage components.',
                    'details' => ['validation' => 'no_chassis']
                ];
            }

            $chassisUuid = $configResult['component_uuid'];

            // Use new JSON-driven storage validation
            $result = $compatibility->validateStorageCompatibility($storageUuid, $chassisUuid);

            return [
                'compatible' => $result['compatible'],
                'error' => $result['compatible'] ? null : $result['error'],
                'details' => $result
            ];

        } catch (Exception $e) {
            return [
                'compatible' => false,
                'error' => 'Storage validation error: ' . $e->getMessage(),
                'details' => ['validation' => 'system_error']
            ];
        }
    }

    /**
     * Validate NIC compatibility
     */
    private function validateNICCompatibility($nicUuid, $configUuid, $motherboardSpecs, $compatibility) {
        $result = $compatibility->validateNICPCIeCompatibility($nicUuid, $configUuid, $motherboardSpecs);
        
        return [
            'compatible' => $result['compatible'],
            'error' => $result['error'],
            'details' => $result['details']
        ];
    }

    /**
     * Get component specifications
     */
    private function getComponentSpecifications($componentType, $componentUuid, $compatibility) {
        switch ($componentType) {
            case 'cpu':
                return $compatibility->getCPUSpecifications($componentUuid);
            case 'ram':
                return $compatibility->getRAMSpecifications($componentUuid);
            case 'motherboard':
                return $compatibility->parseMotherboardSpecifications($componentUuid);
            default:
                return ['found' => false, 'message' => 'No specifications available'];
        }
    }

    /**
     * Get motherboard specifications from configuration
     * Returns structured motherboard specs for compatibility checking
     */
    public function getMotherboardSpecsFromConfig($configUuid) {
        try {
            // Get motherboard UUID from configuration
            $stmt = $this->pdo->prepare("
                SELECT component_uuid FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type = 'motherboard' 
                LIMIT 1
            ");
            $stmt->execute([$configUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                return [
                    'found' => false,
                    'error' => 'No motherboard found in configuration',
                    'specifications' => null
                ];
            }
            
            $motherboardUuid = $result['component_uuid'];
            
            // Load motherboard specifications using ComponentCompatibility
            require_once __DIR__ . '/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);
            
            return $compatibility->parseMotherboardSpecifications($motherboardUuid);
            
        } catch (Exception $e) {
            error_log("Error getting motherboard specs from config: " . $e->getMessage());
            return [
                'found' => false,
                'error' => 'Error loading motherboard specifications: ' . $e->getMessage(),
                'specifications' => null
            ];
        }
    }

    /**
     * Get CPU specifications from configuration
     * Returns array of CPU specs with UUIDs for compatibility checking
     */
    public function getCPUSpecsFromConfig($configUuid) {
        try {
            // Get all CPU UUIDs from configuration
            $stmt = $this->pdo->prepare("
                SELECT component_uuid FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type = 'cpu'
            ");
            $stmt->execute([$configUuid]);
            $cpuResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($cpuResults)) {
                return [
                    'found' => false,
                    'error' => 'No CPUs found in configuration',
                    'specifications' => []
                ];
            }
            
            // Load specifications for each CPU
            require_once __DIR__ . '/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);
            
            $cpuSpecs = [];
            foreach ($cpuResults as $cpu) {
                $cpuUuid = $cpu['component_uuid'];
                $specResult = $compatibility->getCPUSpecifications($cpuUuid);
                
                if ($specResult['found']) {
                    $cpuSpecs[] = $specResult['specifications'];
                }
            }
            
            return [
                'found' => !empty($cpuSpecs),
                'error' => empty($cpuSpecs) ? 'No valid CPU specifications found' : null,
                'specifications' => $cpuSpecs
            ];
            
        } catch (Exception $e) {
            error_log("Error getting CPU specs from config: " . $e->getMessage());
            return [
                'found' => false,
                'error' => 'Error loading CPU specifications: ' . $e->getMessage(),
                'specifications' => []
            ];
        }
    }

    /**
     * Get unified system memory limits combining motherboard and CPU constraints
     */
    public function getSystemMemoryLimits($configUuid) {
        try {
            $motherboardResult = $this->getMotherboardSpecsFromConfig($configUuid);
            $cpuResult = $this->getCPUSpecsFromConfig($configUuid);
            
            if (!$motherboardResult['found'] && !$cpuResult['found']) {
                return [
                    'found' => false,
                    'error' => 'No motherboard or CPU found in configuration',
                    'limits' => null
                ];
            }
            
            // Initialize with motherboard limits or defaults
            $limits = [
                'max_slots' => 4,
                'supported_types' => ['DDR4'],
                'max_frequency_mhz' => 3200,
                'max_capacity_gb' => 128,
                'ecc_support' => false
            ];
            
            // Apply motherboard constraints if available
            if ($motherboardResult['found']) {
                $mbSpecs = $motherboardResult['specifications'];
                $limits['max_slots'] = $mbSpecs['memory']['slots'] ?? 4;
                $limits['supported_types'] = $mbSpecs['memory']['types'] ?? ['DDR4'];
                $limits['max_frequency_mhz'] = $mbSpecs['memory']['max_frequency_mhz'] ?? 3200;
                $limits['max_capacity_gb'] = $mbSpecs['memory']['max_capacity_gb'] ?? 128;
                $limits['ecc_support'] = $mbSpecs['memory']['ecc_support'] ?? false;
            }
            
            // Apply CPU constraints if available (find most restrictive)
            if ($cpuResult['found']) {
                $cpuSpecs = $cpuResult['specifications'];
                $cpuMaxFrequency = null;
                $cpuSupportedTypes = [];
                
                foreach ($cpuSpecs as $cpu) {
                    // Find lowest CPU memory frequency limit
                    $cpuMemoryTypes = $cpu['compatibility']['memory_types'] ?? [];
                    foreach ($cpuMemoryTypes as $memType) {
                        if (preg_match('/DDR\d+-(\d+)/', $memType, $matches)) {
                            $freq = (int)$matches[1];
                            if ($cpuMaxFrequency === null || $freq < $cpuMaxFrequency) {
                                $cpuMaxFrequency = $freq;
                            }
                        }
                        
                        // Extract memory type (DDR4, DDR5)
                        if (preg_match('/(DDR\d+)/', $memType, $matches)) {
                            if (!in_array($matches[1], $cpuSupportedTypes)) {
                                $cpuSupportedTypes[] = $matches[1];
                            }
                        }
                    }
                }
                
                // Apply CPU frequency limit if more restrictive
                if ($cpuMaxFrequency !== null && $cpuMaxFrequency < $limits['max_frequency_mhz']) {
                    $limits['max_frequency_mhz'] = $cpuMaxFrequency;
                }
                
                // Intersect supported memory types
                if (!empty($cpuSupportedTypes)) {
                    $limits['supported_types'] = array_intersect($limits['supported_types'], $cpuSupportedTypes);
                }
            }
            
            return [
                'found' => true,
                'error' => null,
                'limits' => $limits
            ];
            
        } catch (Exception $e) {
            error_log("Error getting system memory limits: " . $e->getMessage());
            return [
                'found' => false,
                'error' => 'Error determining system memory limits: ' . $e->getMessage(),
                'limits' => null
            ];
        }
    }

    /**
     * COMPREHENSIVE Configuration Validation with Detailed Resource Tracking
     *
     * Performs in-depth validation of server configuration including:
     * - Required component presence checks
     * - Resource availability tracking (slots, bays, ports, lanes)
     * - Compatibility scoring across all components
     * - Detailed error/warning/info messages
     *
     * @param string $configUuid Server configuration UUID
     * @return array Comprehensive validation results with scores and resource tracking
     */
    public function validateConfigurationComprehensive($configUuid) {
        try {
            error_log("=== Starting Comprehensive Validation for config: $configUuid ===");

            // Initialize result structure
            $result = [
                'valid' => true,
                'compatibility_score' => 100,
                'category_scores' => [
                    'cpu' => 100,
                    'motherboard' => 100,
                    'ram' => 100,
                    'storage' => 100,
                    'pcie' => 100,
                    'nic' => 100,
                    'chassis' => 100,
                    'caddy' => 100
                ],
                'required_components' => [],
                'resource_availability' => [],
                'errors' => [],
                'warnings' => [],
                'info' => [],
                'detailed_checks' => [
                    'storage_connections' => [],
                    'pcie_assignments' => [],
                    'compatibility_matrix' => []
                ]
            ];

            // Step 1: Get all components in configuration
            $stmt = $this->pdo->prepare("
                SELECT component_type, component_uuid, quantity, slot_position
                FROM server_configuration_components
                WHERE config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($components)) {
                $result['valid'] = false;
                $result['compatibility_score'] = 0;
                $result['errors'][] = [
                    'type' => 'no_components',
                    'message' => 'Configuration has no components'
                ];
                return $result;
            }

            // Step 2: Group components by type
            $componentsByType = [];
            foreach ($components as $component) {
                $type = $component['component_type'];
                if (!isset($componentsByType[$type])) {
                    $componentsByType[$type] = [];
                }
                $componentsByType[$type][] = $component;
            }

            // Step 3: REQUIRED COMPONENT VALIDATION
            $this->validateRequiredComponents($componentsByType, $result);

            // Step 4: SINGLE COMPONENT CONSTRAINTS (motherboard, chassis, HBA)
            $this->validateSingleComponentConstraints($componentsByType, $result);

            // Step 5: RESOURCE AVAILABILITY TRACKING
            if (isset($componentsByType['motherboard'])) {
                $this->trackCPUSocketAvailability($configUuid, $componentsByType, $result);
                $this->trackRAMSlotAvailability($configUuid, $componentsByType, $result);
                $this->trackPCIeLaneAvailability($configUuid, $componentsByType, $result);
            }

            if (isset($componentsByType['chassis'])) {
                $this->trackStorageBayAvailability($configUuid, $componentsByType, $result);
            }

            // Step 6: PCIE SLOT VALIDATION (Reuse existing tracker)
            $this->validatePCIeSlots($configUuid, $result);

            // Step 7: STORAGE CONNECTION VALIDATION (Reuse existing validator)
            $this->validateStorageConnections($configUuid, $componentsByType, $result);

            // Step 8: CPU COMPATIBILITY CHECKS
            $this->validateCPUCompatibilityComprehensive($configUuid, $componentsByType, $result);

            // Step 9: RAM COMPATIBILITY CHECKS
            $this->validateRAMCompatibilityComprehensive($configUuid, $componentsByType, $result);

            // Step 10: CADDY REQUIREMENT VALIDATION
            $this->validateCaddyRequirements($configUuid, $componentsByType, $result);

            // Step 11: HBA-CHASSIS INTERFACE MATCHING
            $this->validateHBAChassisInterfaceMatch($componentsByType, $result);

            // Step 12: BUILD COMPREHENSIVE COMPATIBILITY MATRIX
            $this->buildComprehensiveCompatibilityMatrix($configUuid, $componentsByType, $result);

            // Step 13: CALCULATE FINAL COMPATIBILITY SCORE
            $this->calculateFinalCompatibilityScore($result);

            error_log("=== Validation Complete: Score={$result['compatibility_score']}, Valid=" . ($result['valid'] ? 'YES' : 'NO') . " ===");

            return $result;

        } catch (Exception $e) {
            error_log("Error in comprehensive validation: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return [
                'valid' => false,
                'compatibility_score' => 0,
                'category_scores' => [],
                'required_components' => [],
                'resource_availability' => [],
                'errors' => [[
                    'type' => 'validation_exception',
                    'message' => 'Validation failed: ' . $e->getMessage()
                ]],
                'warnings' => [],
                'info' => [],
                'detailed_checks' => []
            ];
        }
    }

    /**
     * Validate presence of required components
     */
    private function validateRequiredComponents($componentsByType, &$result) {
        $requiredComponents = [
            'chassis' => 'Chassis',
            'motherboard' => 'Motherboard',
            'cpu' => 'CPU',
            'ram' => 'RAM',
            'storage' => 'Storage',
            'nic' => 'Network Interface Card (NIC)'
        ];

        foreach ($requiredComponents as $type => $name) {
            $present = isset($componentsByType[$type]) && count($componentsByType[$type]) > 0;
            $count = $present ? count($componentsByType[$type]) : 0;

            $result['required_components'][$type] = [
                'present' => $present,
                'count' => $count,
                'name' => $name
            ];

            if (!$present) {
                $result['valid'] = false;
                $result['compatibility_score'] -= 15;
                $result['errors'][] = [
                    'type' => 'missing_required_component',
                    'component' => $type,
                    'message' => "Missing required component: $name"
                ];
            } else {
                $result['info'][] = [
                    'type' => 'component_present',
                    'message' => "$name: $count component(s) present"
                ];
            }
        }
    }

    /**
     * Validate single component constraints (only 1 motherboard, chassis, HBA allowed)
     */
    private function validateSingleComponentConstraints($componentsByType, &$result) {
        // Only one motherboard allowed
        if (isset($componentsByType['motherboard']) && count($componentsByType['motherboard']) > 1) {
            $result['valid'] = false;
            $result['errors'][] = [
                'type' => 'multiple_motherboards',
                'message' => 'Configuration has multiple motherboards. Only one motherboard allowed per server.'
            ];
            $result['compatibility_score'] -= 20;
        }

        // Only one chassis allowed
        if (isset($componentsByType['chassis']) && count($componentsByType['chassis']) > 1) {
            $result['valid'] = false;
            $result['errors'][] = [
                'type' => 'multiple_chassis',
                'message' => 'Configuration has multiple chassis. Only one chassis allowed per server.'
            ];
            $result['compatibility_score'] -= 20;
        }

        // Only one HBA card allowed
        if (isset($componentsByType['hbacard']) && count($componentsByType['hbacard']) > 1) {
            $hbaCount = count($componentsByType['hbacard']);
            $result['valid'] = false;
            $result['errors'][] = [
                'type' => 'multiple_hba_cards',
                'message' => "Configuration has $hbaCount HBA cards. Only one HBA card allowed per server."
            ];
            $result['compatibility_score'] -= 15;
        }
    }

    /**
     * Track CPU socket availability
     */
    private function trackCPUSocketAvailability($configUuid, $componentsByType, &$result) {
        try {
            $motherboard = $componentsByType['motherboard'][0];
            $mbSpecs = $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']);

            if (!$mbSpecs) {
                $result['warnings'][] = [
                    'type' => 'motherboard_specs_not_found',
                    'message' => 'Could not load motherboard specifications'
                ];
                return;
            }

            $maxSockets = $mbSpecs['socket']['count'] ?? 1;
            $usedSockets = isset($componentsByType['cpu']) ? count($componentsByType['cpu']) : 0;
            $availableSockets = max(0, $maxSockets - $usedSockets);

            $result['resource_availability']['cpu_sockets'] = [
                'max' => $maxSockets,
                'used' => $usedSockets,
                'available' => $availableSockets
            ];

            if ($usedSockets > $maxSockets) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'cpu_socket_exceeded',
                    'message' => "Too many CPUs: $usedSockets CPUs but only $maxSockets socket(s) available"
                ];
                $result['category_scores']['cpu'] = 0;
            }

        } catch (Exception $e) {
            error_log("Error tracking CPU sockets: " . $e->getMessage());
        }
    }

    /**
     * Track RAM slot availability
     */
    private function trackRAMSlotAvailability($configUuid, $componentsByType, &$result) {
        try {
            $motherboard = $componentsByType['motherboard'][0];
            $mbSpecs = $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']);

            if (!$mbSpecs) {
                return;
            }

            $maxSlots = $mbSpecs['memory']['slots'] ?? 4;
            $maxCapacityGB = $mbSpecs['memory']['max_capacity_gb'] ?? 128;
            $usedSlots = isset($componentsByType['ram']) ? count($componentsByType['ram']) : 0;
            $availableSlots = max(0, $maxSlots - $usedSlots);

            // Calculate total RAM capacity
            $totalCapacityGB = 0;
            if (isset($componentsByType['ram'])) {
                foreach ($componentsByType['ram'] as $ram) {
                    $ramSpecs = $this->dataUtils->getRAMByUUID($ram['component_uuid']);
                    if ($ramSpecs) {
                        $capacity = $ramSpecs['capacity_gb'] ?? 0;
                        $totalCapacityGB += $capacity;
                    }
                }
            }

            $result['resource_availability']['ram_slots'] = [
                'max' => $maxSlots,
                'used' => $usedSlots,
                'available' => $availableSlots,
                'max_capacity_gb' => $maxCapacityGB,
                'used_capacity_gb' => $totalCapacityGB,
                'available_capacity_gb' => max(0, $maxCapacityGB - $totalCapacityGB)
            ];

            if ($usedSlots > $maxSlots) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'ram_slot_exceeded',
                    'message' => "Too many RAM modules: $usedSlots modules but only $maxSlots slot(s) available"
                ];
                $result['category_scores']['ram'] = 0;
            }

            if ($totalCapacityGB > $maxCapacityGB) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'ram_capacity_exceeded',
                    'message' => "RAM capacity exceeded: {$totalCapacityGB}GB installed but maximum is {$maxCapacityGB}GB"
                ];
                $result['category_scores']['ram'] = max(0, $result['category_scores']['ram'] - 30);
            }

        } catch (Exception $e) {
            error_log("Error tracking RAM slots: " . $e->getMessage());
        }
    }

    /**
     * Track PCIe lane availability (CPU + Chipset lanes)
     */
    private function trackPCIeLaneAvailability($configUuid, $componentsByType, &$result) {
        try {
            $totalLanes = 0;
            $laneSources = [];

            // Get CPU PCIe lanes
            if (isset($componentsByType['cpu'])) {
                $cpuIndex = 1;
                foreach ($componentsByType['cpu'] as $cpu) {
                    $cpuSpecs = $this->dataUtils->getCPUByUUID($cpu['component_uuid']);
                    if ($cpuSpecs) {
                        $lanes = $cpuSpecs['pcie_lanes'] ?? 0;
                        $totalLanes += $lanes;
                        $laneSources[] = "CPU $cpuIndex: $lanes lanes";
                        $cpuIndex++;
                    }
                }
            }

            // Get chipset PCIe lanes from motherboard
            if (isset($componentsByType['motherboard'])) {
                $motherboard = $componentsByType['motherboard'][0];
                $mbSpecs = $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']);
                if ($mbSpecs) {
                    $chipsetLanes = $mbSpecs['chipset_pcie_lanes'] ?? 0;
                    $totalLanes += $chipsetLanes;
                    $laneSources[] = "Chipset: $chipsetLanes lanes";
                }
            }

            // Calculate used lanes from PCIe cards, HBA cards and NICs
            $usedLanes = 0;
            if (isset($componentsByType['pciecard'])) {
                foreach ($componentsByType['pciecard'] as $card) {
                    $cardSpecs = $this->dataUtils->getPCIeCardByUUID($card['component_uuid']);
                    if ($cardSpecs) {
                        $interface = $cardSpecs['interface'] ?? '';
                        if (preg_match('/x(\d+)/i', $interface, $matches)) {
                            $usedLanes += (int)$matches[1];
                        }
                    }
                }
            }

            if (isset($componentsByType['hbacard'])) {
                foreach ($componentsByType['hbacard'] as $card) {
                    $cardSpecs = $this->dataUtils->getHBACardByUUID($card['component_uuid']);
                    if ($cardSpecs) {
                        $interface = $cardSpecs['interface'] ?? '';
                        if (preg_match('/x(\d+)/i', $interface, $matches)) {
                            $usedLanes += (int)$matches[1];
                        }
                    }
                }
            }

            if (isset($componentsByType['nic'])) {
                foreach ($componentsByType['nic'] as $nic) {
                    // Most NICs use x4 or x8, default to x4
                    $usedLanes += 4;
                }
            }

            // Account for NVMe storage that uses PCIe slots (not M.2)
            if (isset($componentsByType['storage'])) {
                foreach ($componentsByType['storage'] as $storage) {
                    $storageSpecs = $this->dataUtils->getStorageByUUID($storage['component_uuid']);
                    if ($storageSpecs) {
                        $interface = strtolower($storageSpecs['interface'] ?? '');
                        $formFactor = strtolower($storageSpecs['form_factor'] ?? '');

                        // Only count non-M.2 NVMe (U.2, PCIe add-in cards)
                        $isM2 = (strpos($formFactor, 'm.2') !== false || strpos($formFactor, 'm2') !== false);
                        $isNVMe = (strpos($interface, 'nvme') !== false || strpos($interface, 'pcie') !== false);

                        if ($isNVMe && !$isM2) {
                            $usedLanes += 4; // U.2 or PCIe add-in NVMe typically uses x4
                        }
                    }
                }
            }

            $availableLanes = max(0, $totalLanes - $usedLanes);

            $result['resource_availability']['pcie_lanes'] = [
                'total' => $totalLanes,
                'used' => $usedLanes,
                'available' => $availableLanes,
                'sources' => $laneSources
            ];

            if ($usedLanes > $totalLanes) {
                $result['warnings'][] = [
                    'type' => 'pcie_lanes_exceeded',
                    'message' => "PCIe lane budget exceeded: $usedLanes lanes used but only $totalLanes lanes available. Some devices may run at reduced bandwidth."
                ];
                $result['category_scores']['pcie'] = max(0, $result['category_scores']['pcie'] - 20);
            }

        } catch (Exception $e) {
            error_log("Error tracking PCIe lanes: " . $e->getMessage());
        }
    }

    /**
     * Track storage bay availability
     */
    private function trackStorageBayAvailability($configUuid, $componentsByType, &$result) {
        try {
            $chassis = $componentsByType['chassis'][0];
            $chassisSpecs = $this->dataUtils->getChassisSpecifications($chassis['component_uuid']);

            if (!$chassisSpecs) {
                $result['warnings'][] = [
                    'type' => 'chassis_specs_not_found',
                    'message' => 'Could not load chassis specifications'
                ];
                return;
            }

            $driveBays = $chassisSpecs['drive_bays'] ?? [];
            $totalBays = $driveBays['total_bays'] ?? 0;
            $bayConfiguration = $driveBays['bay_configuration'] ?? [];

            // CRITICAL: Only count storage devices that connect via chassis backplane
            // Storage connecting via motherboard M.2/SATA does NOT use chassis bays
            $usedBays = 0;
            $backplaneStorageList = [];

            if (isset($componentsByType['storage'])) {
                require_once __DIR__ . '/StorageConnectionValidator.php';
                $storageValidator = new StorageConnectionValidator($this->pdo);
                $existingComponents = $this->getExistingComponentsForValidation($configUuid);

                foreach ($componentsByType['storage'] as $storage) {
                    $validation = $storageValidator->validate($configUuid, $storage['component_uuid'], $existingComponents);

                    // Check if primary connection path is chassis bay
                    if ($validation['valid'] && isset($validation['primary_path'])) {
                        if ($validation['primary_path']['type'] === 'chassis_bay') {
                            $usedBays++;

                            $storageSpecs = $this->dataUtils->getStorageByUUID($storage['component_uuid']);
                            $backplaneStorageList[] = [
                                'uuid' => $storage['component_uuid'],
                                'model' => $storageSpecs['model'] ?? 'Unknown',
                                'form_factor' => $storageSpecs['form_factor'] ?? 'Unknown'
                            ];
                        }
                    }
                }
            }

            $availableBays = max(0, $totalBays - $usedBays);

            $result['resource_availability']['storage_bays'] = [
                'max' => $totalBays,
                'used' => $usedBays,
                'available' => $availableBays,
                'bay_configuration' => $bayConfiguration,
                'backplane_connected_storage' => $backplaneStorageList
            ];

            if ($usedBays > $totalBays) {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'storage_bay_exceeded',
                    'message' => "Too many storage devices connected to chassis backplane: $usedBays devices but only $totalBays bay(s) available"
                ];
                $result['category_scores']['storage'] = 0;
            }

        } catch (Exception $e) {
            error_log("Error tracking storage bays: " . $e->getMessage());
        }
    }

    /**
     * Validate PCIe slot assignments using ExpansionSlotTracker
     */
    private function validatePCIeSlots($configUuid, &$result) {
        try {
            require_once __DIR__ . '/ExpansionSlotTracker.php';
            $slotTracker = new ExpansionSlotTracker($this->pdo);

            $pcieValidation = $slotTracker->validateAllSlots($configUuid);

            // Track slot availability
            if ($pcieValidation['valid']) {
                $slotAvailability = $slotTracker->getSlotAvailability($configUuid);

                if ($slotAvailability['success']) {
                    $slotSummary = [];
                    foreach ($slotAvailability['total_slots'] as $slotSize => $slots) {
                        $usedCount = 0;
                        foreach ($slotAvailability['used_slots'] as $slotId => $componentUuid) {
                            if (strpos($slotId, $slotSize) !== false) {
                                $usedCount++;
                            }
                        }

                        $slotSummary[$slotSize] = [
                            'max' => count($slots),
                            'used' => $usedCount,
                            'available' => count($slots) - $usedCount
                        ];
                    }

                    $result['resource_availability']['pcie_slots'] = $slotSummary;
                }
            }

            // Add PCIe validation results
            $result['detailed_checks']['pcie_assignments'] = $pcieValidation;

            if (!$pcieValidation['valid']) {
                $result['valid'] = false;
                $result['errors'] = array_merge($result['errors'], array_map(function($err) {
                    return ['type' => 'pcie_slot_error', 'message' => $err];
                }, $pcieValidation['errors']));
                $result['category_scores']['pcie'] = 0;
            }

            if (!empty($pcieValidation['warnings'])) {
                $result['warnings'] = array_merge($result['warnings'], array_map(function($warn) {
                    return ['type' => 'pcie_slot_warning', 'message' => $warn];
                }, $pcieValidation['warnings']));
                $result['category_scores']['pcie'] = max(0, $result['category_scores']['pcie'] - 10);
            }

        } catch (Exception $e) {
            error_log("Error validating PCIe slots: " . $e->getMessage());
            $result['warnings'][] = [
                'type' => 'pcie_validation_error',
                'message' => 'PCIe slot validation could not be completed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate storage connections using existing StorageConnectionValidator
     */
    private function validateStorageConnections($configUuid, $componentsByType, &$result) {
        try {
            if (!isset($componentsByType['storage'])) {
                return; // No storage to validate
            }

            require_once __DIR__ . '/StorageConnectionValidator.php';
            $storageValidator = new StorageConnectionValidator($this->pdo);

            // Get existing components for validation
            $existingComponents = $this->getExistingComponentsForValidation($configUuid);

            $storageResults = [];
            $caddyRequired = 0;
            $caddyAvailable = isset($componentsByType['caddy']) ? count($componentsByType['caddy']) : 0;
            $caddiesNeeded = []; // Track which storage devices need caddies

            foreach ($componentsByType['storage'] as $storage) {
                $validation = $storageValidator->validate($configUuid, $storage['component_uuid'], $existingComponents);

                // Create simplified storage connection entry
                $storageSpecs = $this->dataUtils->getStorageByUUID($storage['component_uuid']);
                $simplifiedEntry = [
                    'storage_uuid' => $storage['component_uuid'],
                    'interface' => strtoupper($storageSpecs['interface'] ?? 'Unknown'),
                    'form_factor' => $storageSpecs['form_factor'] ?? 'Unknown',
                    'valid' => $validation['valid'],
                    'primary_path' => [
                        'type' => $validation['primary_path']['type'] ?? 'none',
                        'description' => $validation['primary_path']['description'] ?? 'No connection available'
                    ]
                ];

                // Add only if there are errors or warnings
                if (!empty($validation['errors'])) {
                    $simplifiedEntry['errors'] = $validation['errors'];
                }
                if (!empty($validation['warnings'])) {
                    $simplifiedEntry['warnings'] = $validation['warnings'];
                }

                $storageResults[] = $simplifiedEntry;

                // CRITICAL: Check if storage connects via chassis backplane
                $usesBackplane = false;
                if ($validation['valid'] && isset($validation['primary_path'])) {
                    if ($validation['primary_path']['type'] === 'chassis_bay') {
                        $usesBackplane = true;
                        $caddyRequired++;

                        // Get storage specs to determine caddy form factor requirement
                        $storageSpecs = $this->dataUtils->getStorageByUUID($storage['component_uuid']);
                        $formFactor = $storageSpecs['form_factor'] ?? 'Unknown';

                        $caddiesNeeded[] = [
                            'storage_uuid' => $storage['component_uuid'],
                            'form_factor' => $formFactor,
                            'connection_type' => 'chassis_backplane'
                        ];
                    }
                }

                // Also check for caddy warnings from validator (2.5" in 3.5" bay scenarios)
                if (!empty($validation['warnings'])) {
                    foreach ($validation['warnings'] as $warning) {
                        if (isset($warning['type']) && $warning['type'] === 'caddy_recommended') {
                            // Only increment if not already counted above
                            if (!$usesBackplane) {
                                $caddyRequired++;

                                $storageSpecs = $this->dataUtils->getStorageByUUID($storage['component_uuid']);
                                $formFactor = $storageSpecs['form_factor'] ?? 'Unknown';

                                $caddiesNeeded[] = [
                                    'storage_uuid' => $storage['component_uuid'],
                                    'form_factor' => $formFactor,
                                    'connection_type' => 'size_adapter'
                                ];
                            }
                        }
                    }
                }

                if (!$validation['valid']) {
                    $result['valid'] = false;
                    $result['errors'] = array_merge($result['errors'], array_map(function($err) use ($storage) {
                        return [
                            'type' => 'storage_connection_error',
                            'storage_uuid' => $storage['component_uuid'],
                            'message' => $err['message'] ?? $err
                        ];
                    }, $validation['errors']));
                    $result['category_scores']['storage'] = 0;
                }

                if (!empty($validation['warnings'])) {
                    $result['warnings'] = array_merge($result['warnings'], array_map(function($warn) use ($storage) {
                        return [
                            'type' => 'storage_connection_warning',
                            'storage_uuid' => $storage['component_uuid'],
                            'message' => $warn['message'] ?? $warn
                        ];
                    }, $validation['warnings']));
                    $result['category_scores']['storage'] = max(0, $result['category_scores']['storage'] - 5);
                }
            }

            $result['detailed_checks']['storage_connections'] = $storageResults;

            // Validate caddy availability and form factor matching
            $caddyErrors = [];
            $caddyWarnings = [];

            if ($caddyRequired > 0) {
                // Get all caddies in configuration
                $availableCaddies = [];
                if (isset($componentsByType['caddy'])) {
                    // Load caddy JSON specifications
                    $caddyJsonPath = __DIR__ . '/../../All-JSON/caddy-jsons/caddy_details.json';
                    $caddySpecs = [];
                    if (file_exists($caddyJsonPath)) {
                        $caddyJson = json_decode(file_get_contents($caddyJsonPath), true);
                        if (isset($caddyJson['caddies'])) {
                            foreach ($caddyJson['caddies'] as $spec) {
                                if (isset($spec['uuid'])) {
                                    $caddySpecs[$spec['uuid']] = $spec;
                                }
                            }
                        }
                    }

                    foreach ($componentsByType['caddy'] as $caddy) {
                        $caddyUuid = $caddy['component_uuid'];
                        $size = 'Unknown';

                        // Try to get size from JSON specifications first
                        if (isset($caddySpecs[$caddyUuid]) && isset($caddySpecs[$caddyUuid]['compatibility']['size'])) {
                            $size = $caddySpecs[$caddyUuid]['compatibility']['size'];
                        } else {
                            // Fallback to Notes field if JSON not found
                            $stmt = $this->pdo->prepare("SELECT Notes FROM caddyinventory WHERE UUID = ?");
                            $stmt->execute([$caddyUuid]);
                            $caddyData = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($caddyData) {
                                $notes = $caddyData['Notes'] ?? '';
                                if (preg_match('/2\.5[\s\-]?inch/i', $notes)) {
                                    $size = '2.5-inch';
                                } elseif (preg_match('/3\.5[\s\-]?inch/i', $notes)) {
                                    $size = '3.5-inch';
                                }
                            }
                        }

                        $availableCaddies[] = [
                            'uuid' => $caddyUuid,
                            'size' => $size
                        ];
                    }
                }

                // Match caddies to storage devices
                foreach ($caddiesNeeded as $need) {
                    $matchFound = false;
                    $storageFormFactor = $need['form_factor'];

                    // Normalize form factor to caddy size format
                    $requiredCaddySize = null;
                    if (stripos($storageFormFactor, '2.5') !== false || stripos($storageFormFactor, '2.5"') !== false) {
                        $requiredCaddySize = '2.5';
                    } elseif (stripos($storageFormFactor, '3.5') !== false || stripos($storageFormFactor, '3.5"') !== false) {
                        $requiredCaddySize = '3.5';
                    }

                    foreach ($availableCaddies as $idx => $caddy) {
                        if ($requiredCaddySize && stripos($caddy['size'], $requiredCaddySize) !== false) {
                            $matchFound = true;
                            unset($availableCaddies[$idx]); // Remove matched caddy
                            break;
                        }
                    }

                    if (!$matchFound) {
                        $caddyErrors[] = [
                            'type' => 'missing_caddy',
                            'storage_uuid' => $need['storage_uuid'],
                            'required_form_factor' => $storageFormFactor,
                            'message' => "Storage device ({$need['storage_uuid']}) requires {$storageFormFactor} caddy for {$need['connection_type']} connection"
                        ];
                    }
                }

                // Add errors to result
                if (!empty($caddyErrors)) {
                    $result['valid'] = false;
                    $result['errors'] = array_merge($result['errors'], $caddyErrors);
                    $result['category_scores']['storage'] = max(0, $result['category_scores']['storage'] - 20);
                }
            }

            // Track caddy availability with details
            $result['resource_availability']['caddies'] = [
                'required' => $caddyRequired,
                'available' => $caddyAvailable,
                'missing' => max(0, $caddyRequired - $caddyAvailable),
                'details' => $caddiesNeeded
            ];

            // Don't add summary error if specific caddy errors already added (avoid duplicate penalty)
            // The specific errors above already provide detailed information

        } catch (Exception $e) {
            error_log("Error validating storage connections: " . $e->getMessage());
            $result['warnings'][] = [
                'type' => 'storage_validation_error',
                'message' => 'Storage validation could not be completed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate CPU compatibility (socket, dual CPU warnings) - Comprehensive validation
     */
    private function validateCPUCompatibilityComprehensive($configUuid, $componentsByType, &$result) {
        try {
            if (!isset($componentsByType['cpu']) || !isset($componentsByType['motherboard'])) {
                return;
            }

            require_once __DIR__ . '/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);

            $motherboard = $componentsByType['motherboard'][0];
            $mbSpecs = $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']);

            if (!$mbSpecs) {
                return;
            }

            $cpuModels = [];
            foreach ($componentsByType['cpu'] as $cpu) {
                $cpuSpecs = $this->dataUtils->getCPUByUUID($cpu['component_uuid']);

                // Socket compatibility check
                $socketResult = $compatibility->validateCPUSocketCompatibility($cpu['component_uuid'], [
                    'cpu' => [
                        'socket_type' => $mbSpecs['socket']['type'] ?? 'Unknown'
                    ]
                ]);

                if (!$socketResult['compatible']) {
                    $result['valid'] = false;
                    $result['errors'][] = [
                        'type' => 'cpu_socket_incompatible',
                        'cpu_uuid' => $cpu['component_uuid'],
                        'message' => $socketResult['error']
                    ];
                    $result['category_scores']['cpu'] = 0;
                }

                if ($cpuSpecs) {
                    $cpuModels[] = $cpuSpecs['model'] ?? 'Unknown';
                }
            }

            // Dual CPU warning if different models
            if (count($cpuModels) === 2 && $cpuModels[0] !== $cpuModels[1]) {
                $result['warnings'][] = [
                    'type' => 'dual_cpu_different_models',
                    'message' => "Dual CPUs detected with different models ({$cpuModels[0]} and {$cpuModels[1]}). This may cause performance issues or system instability."
                ];
                // Don't reduce category score - warning penalty is already applied in final calculation
                // This prevents double penalty (was -15 category + -3 warning = -18 total)
            }

        } catch (Exception $e) {
            error_log("Error validating CPU compatibility: " . $e->getMessage());
        }
    }

    /**
     * Validate RAM compatibility (type, speed, capacity) - Comprehensive validation
     */
    private function validateRAMCompatibilityComprehensive($configUuid, $componentsByType, &$result) {
        try {
            if (!isset($componentsByType['ram']) || !isset($componentsByType['motherboard'])) {
                return;
            }

            require_once __DIR__ . '/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);

            $motherboard = $componentsByType['motherboard'][0];
            $mbSpecs = $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']);

            if (!$mbSpecs) {
                return;
            }

            foreach ($componentsByType['ram'] as $ram) {
                // Type compatibility check - pass full motherboard specs for flexible field handling
                $typeResult = $compatibility->validateRAMTypeCompatibility($ram['component_uuid'], $mbSpecs);

                if (!$typeResult['compatible']) {
                    $result['valid'] = false;
                    $result['errors'][] = [
                        'type' => 'ram_type_incompatible',
                        'ram_uuid' => $ram['component_uuid'],
                        'message' => $typeResult['error']
                    ];
                    $result['category_scores']['ram'] = 0;
                }
            }

        } catch (Exception $e) {
            error_log("Error validating RAM compatibility: " . $e->getMessage());
        }
    }

    /**
     * Validate caddy requirements for storage devices
     */
    private function validateCaddyRequirements($configUuid, $componentsByType, &$result) {
        try {
            if (!isset($componentsByType['storage']) || !isset($componentsByType['chassis'])) {
                return;
            }

            // This is already handled in validateStorageConnections
            // Kept as separate method for clarity and future enhancements

        } catch (Exception $e) {
            error_log("Error validating caddy requirements: " . $e->getMessage());
        }
    }

    /**
     * Validate HBA card interface matches chassis backplane interface
     */
    private function validateHBAChassisInterfaceMatch($componentsByType, &$result) {
        try {
            if (!isset($componentsByType['chassis']) || !isset($componentsByType['hbacard'])) {
                return;
            }

            // Find HBA card
            $hbaCard = null;
            if (isset($componentsByType['hbacard']) && !empty($componentsByType['hbacard'])) {
                $card = $componentsByType['hbacard'][0];
                $hbaCard = $this->dataUtils->getHBACardByUUID($card['component_uuid']);
            }

            if (!$hbaCard) {
                return; // No HBA card, skip check
            }

            // Get chassis backplane interface
            $chassis = $componentsByType['chassis'][0];
            $chassisSpecs = $this->dataUtils->getChassisSpecifications($chassis['component_uuid']);

            if (!$chassisSpecs) {
                return;
            }

            $backplane = $chassisSpecs['backplane'] ?? [];
            $backplaneInterface = strtoupper($backplane['interface'] ?? 'Unknown');
            $hbaInterface = strtoupper($hbaCard['interface'] ?? 'Unknown');

            // Extract protocol from interfaces
            $backplaneProtocol = '';
            if (strpos($backplaneInterface, 'SATA') !== false) $backplaneProtocol = 'SATA';
            if (strpos($backplaneInterface, 'SAS') !== false) $backplaneProtocol = 'SAS';

            $hbaProtocol = '';
            if (strpos($hbaInterface, 'SATA') !== false) $hbaProtocol = 'SATA';
            if (strpos($hbaInterface, 'SAS') !== false) $hbaProtocol = 'SAS';

            // SAS HBA can support SATA (backward compatible), but SATA HBA cannot support SAS
            if ($backplaneProtocol === 'SAS' && $hbaProtocol === 'SATA') {
                $result['valid'] = false;
                $result['errors'][] = [
                    'type' => 'hba_chassis_interface_mismatch',
                    'message' => "HBA card interface ($hbaInterface) incompatible with chassis backplane ($backplaneInterface). SAS backplane requires SAS HBA."
                ];
                $result['category_scores']['storage'] = 0;
            } elseif ($backplaneProtocol === 'SATA' && $hbaProtocol === 'SAS') {
                // This is OK - SAS HBA can handle SATA backplane
                $result['info'][] = [
                    'type' => 'hba_chassis_compatible',
                    'message' => "SAS HBA is compatible with SATA backplane (backward compatible)"
                ];
            } elseif ($backplaneProtocol !== $hbaProtocol && $backplaneProtocol && $hbaProtocol) {
                $result['warnings'][] = [
                    'type' => 'hba_chassis_interface_mismatch',
                    'message' => "HBA interface ($hbaInterface) may not match chassis backplane ($backplaneInterface). Verify compatibility."
                ];
                $result['category_scores']['storage'] = max(0, $result['category_scores']['storage'] - 10);
            }

        } catch (Exception $e) {
            error_log("Error validating HBA-chassis interface match: " . $e->getMessage());
        }
    }

    /**
     * Build comprehensive compatibility matrix showing ALL component pair validations
     */
    private function buildComprehensiveCompatibilityMatrix($configUuid, $componentsByType, &$result) {
        try {
            require_once __DIR__ . '/ComponentCompatibility.php';
            $compatibility = new ComponentCompatibility($this->pdo);

            $matrix = [];

            // Get component specs
            $motherboard = isset($componentsByType['motherboard']) ? $componentsByType['motherboard'][0] : null;
            $chassis = isset($componentsByType['chassis']) ? $componentsByType['chassis'][0] : null;

            $mbSpecs = $motherboard ? $this->dataUtils->getMotherboardByUUID($motherboard['component_uuid']) : null;
            $chassisSpecs = $chassis ? $this->dataUtils->getChassisSpecifications($chassis['component_uuid']) : null;

            // 1. MOTHERBOARD ↔ CPU COMPATIBILITY
            if ($motherboard && isset($componentsByType['cpu'])) {
                $cpuList = [];
                $allCompatible = true;
                foreach ($componentsByType['cpu'] as $cpu) {
                    $cpuSpecs = $this->dataUtils->getCPUByUUID($cpu['component_uuid']);
                    $socketResult = $compatibility->validateCPUSocketCompatibility($cpu['component_uuid'], [
                        'cpu' => ['socket_type' => $mbSpecs['socket']['type'] ?? 'Unknown']
                    ]);

                    if (!$socketResult['compatible']) {
                        $allCompatible = false;
                    }

                    $cpuList[] = [
                        'model' => $cpuSpecs['model'] ?? 'Unknown',
                        'compatible' => $socketResult['compatible']
                    ];
                }

                $matrix[] = [
                    'pair' => 'motherboard_cpu',
                    'check' => 'Socket: ' . ($mbSpecs['socket']['type'] ?? 'Unknown'),
                    'count' => count($cpuList),
                    'items' => $cpuList,
                    'compatible' => $allCompatible
                ];
            }

            // 2. MOTHERBOARD ↔ RAM COMPATIBILITY
            if ($motherboard && isset($componentsByType['ram'])) {
                $ramList = [];
                $allCompatible = true;
                foreach ($componentsByType['ram'] as $ram) {
                    $ramSpecs = $this->dataUtils->getRAMByUUID($ram['component_uuid']);
                    $typeResult = $compatibility->validateRAMTypeCompatibility($ram['component_uuid'], $mbSpecs);

                    if (!$typeResult['compatible']) {
                        $allCompatible = false;
                    }

                    $ramList[] = [
                        'type' => $ramSpecs['memory_type'] ?? 'Unknown',
                        'compatible' => $typeResult['compatible']
                    ];
                }

                // Get supported RAM types - handle both 'type' and 'types' fields
                $supportedTypes = $mbSpecs['memory']['types'] ?? [$mbSpecs['memory']['type'] ?? 'DDR4'];
                $matrix[] = [
                    'pair' => 'motherboard_ram',
                    'check' => 'Type: ' . implode('/', $supportedTypes),
                    'count' => count($ramList),
                    'items' => $ramList,
                    'compatible' => $allCompatible
                ];
            }

            // 3. STORAGE CONNECTIVITY (Use actual validation results)
            if (isset($componentsByType['storage'])) {
                $storageList = [];
                $allCompatible = true;

                // Reuse the storage validation results from detailed_checks
                if (isset($result['detailed_checks']['storage_connections'])) {
                    foreach ($result['detailed_checks']['storage_connections'] as $storageValidation) {
                        $storageSpecs = $this->dataUtils->getStorageByUUID($storageValidation['storage_uuid']);
                        $interface = strtoupper($storageSpecs['interface'] ?? 'Unknown');

                        $compatible = $storageValidation['validation']['valid'];
                        $connectionPath = 'No path';

                        // Extract connection path from primary_path
                        if (isset($storageValidation['validation']['primary_path'])) {
                            $primaryPath = $storageValidation['validation']['primary_path'];
                            switch ($primaryPath['type']) {
                                case 'chassis_bay':
                                    $connectionPath = 'Chassis backplane';
                                    break;
                                case 'motherboard_m2':
                                    $connectionPath = 'Motherboard M.2';
                                    break;
                                case 'motherboard_sata':
                                    $connectionPath = 'Motherboard SATA';
                                    break;
                                case 'hba_card':
                                    $connectionPath = 'HBA Card';
                                    break;
                                case 'pcie_adapter':
                                    $connectionPath = 'PCIe Adapter';
                                    break;
                                default:
                                    $connectionPath = ucwords(str_replace('_', ' ', $primaryPath['type']));
                            }
                        }

                        if (!$compatible) {
                            $allCompatible = false;
                        }

                        $storageList[] = [
                            'interface' => $interface,
                            'path' => $connectionPath,
                            'compatible' => $compatible
                        ];
                    }
                }

                $matrix[] = [
                    'pair' => 'storage_connectivity',
                    'check' => 'All storage devices have valid connection path',
                    'count' => count($storageList),
                    'items' => $storageList,
                    'compatible' => $allCompatible
                ];
            }

            // 4. PCIE CARDS COMPATIBILITY
            if ($motherboard && isset($componentsByType['pciecard'])) {
                $pcieList = [];
                $allCompatible = true;
                foreach ($componentsByType['pciecard'] as $card) {
                    $cardSpecs = $this->dataUtils->getPCIeCardByUUID($card['component_uuid']);
                    $cardType = $cardSpecs['component_subtype'] ?? 'PCIe Card';

                    // Check if motherboard has available slots (basic check)
                    $compatible = isset($mbSpecs['expansion_slots']) && !empty($mbSpecs['expansion_slots']);

                    if (!$compatible) {
                        $allCompatible = false;
                    }

                    $pcieList[] = [
                        'type' => $cardType,
                        'compatible' => $compatible
                    ];
                }

                $matrix[] = [
                    'pair' => 'pcie_cards',
                    'check' => 'PCIe slot availability',
                    'count' => count($pcieList),
                    'items' => $pcieList,
                    'compatible' => $allCompatible
                ];
            }

            // 4b. HBA CARDS COMPATIBILITY
            if ($motherboard && isset($componentsByType['hbacard'])) {
                $hbaList = [];
                $allCompatible = true;
                foreach ($componentsByType['hbacard'] as $card) {
                    $cardSpecs = $this->dataUtils->getHBACardByUUID($card['component_uuid']);
                    $cardType = 'HBA Card';

                    // Check if motherboard has available slots (basic check)
                    $compatible = isset($mbSpecs['expansion_slots']) && !empty($mbSpecs['expansion_slots']);

                    if (!$compatible) {
                        $allCompatible = false;
                    }

                    $hbaList[] = [
                        'type' => $cardType,
                        'model' => $cardSpecs['model'] ?? 'Unknown',
                        'compatible' => $compatible
                    ];
                }

                $matrix[] = [
                    'pair' => 'hba_cards',
                    'check' => 'HBA slot availability',
                    'count' => count($hbaList),
                    'items' => $hbaList,
                    'compatible' => $allCompatible
                ];
            }

            // 5. CHASSIS ↔ MOTHERBOARD FORM FACTOR
            if ($chassis && $motherboard) {
                $chassisFormFactor = $chassisSpecs['form_factor'] ?? 'Unknown';
                $mbFormFactor = $mbSpecs['form_factor'] ?? 'Unknown';
                $compatible = (stripos($chassisFormFactor, $mbFormFactor) !== false);

                $matrix[] = [
                    'pair' => 'chassis_motherboard',
                    'check' => "$chassisFormFactor ↔ $mbFormFactor",
                    'compatible' => $compatible
                ];
            }

            $result['detailed_checks']['compatibility_matrix'] = $matrix;

        } catch (Exception $e) {
            error_log("Error building compatibility matrix: " . $e->getMessage());
        }
    }

    /**
     * Calculate final compatibility score based on category scores and issues
     */
    private function calculateFinalCompatibilityScore(&$result) {
        // Start with average of category scores
        $categoryScores = array_values($result['category_scores']);
        $avgScore = count($categoryScores) > 0 ? array_sum($categoryScores) / count($categoryScores) : 0;

        // Apply error penalty
        $errorPenalty = count($result['errors']) * 10;

        // Apply warning penalty
        $warningPenalty = count($result['warnings']) * 3;

        // Calculate final score
        $finalScore = max(0, min(100, $avgScore - $errorPenalty - $warningPenalty));

        $result['compatibility_score'] = round($finalScore);

        // Set valid to false if score is too low
        if ($finalScore < 50) {
            $result['valid'] = false;
        }
    }

    /**
     * Get existing components formatted for validation
     */
    private function getExistingComponentsForValidation($configUuid) {
        $stmt = $this->pdo->prepare("
            SELECT component_type, component_uuid
            FROM server_configuration_components
            WHERE config_uuid = ?
        ");
        $stmt->execute([$configUuid]);
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [
            'chassis' => null,
            'motherboard' => null,
            'cpu' => [],
            'ram' => [],
            'storage' => [],
            'nic' => [],
            'pciecard' => [],
            'hbacard' => [],
            'caddy' => []
        ];

        foreach ($components as $component) {
            $type = $component['component_type'];
            if ($type === 'chassis' || $type === 'motherboard') {
                $formatted[$type] = $component;
            } else {
                $formatted[$type][] = $component;
            }
        }

        return $formatted;
    }
}

?>

