<?php
/**
 * Infrastructure Management System - Server Builder
 * File: includes/models/ServerBuilder.php
 * 
 * Handles server creation, component selection, and configuration management
 */

require_once(__DIR__ . '/CompatibilityEngine.php');

class ServerBuilder {
    private $pdo;
    private $compatibilityEngine;
    private $currentConfiguration;
    private $sessionId;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->compatibilityEngine = new CompatibilityEngine($pdo);
        $this->sessionId = uniqid('server_build_', true);
        $this->currentConfiguration = $this->initializeConfiguration();
    }
    
    /**
     * Initialize empty server configuration
     */
    private function initializeConfiguration() {
        return [
            'config_uuid' => $this->generateUUID(),
            'config_name' => '',
            'config_description' => '',
            'cpu_uuid' => null,
            'cpu_id' => null,
            'motherboard_uuid' => null,
            'motherboard_id' => null,
            'ram_configuration' => [],
            'storage_configuration' => [],
            'nic_configuration' => [],
            'caddy_configuration' => [],
            'additional_components' => [],
            'configuration_status' => 0, // Draft
            'compatibility_results' => [],
            'validation_results' => [],
            'estimated_cost' => 0.0,
            'estimated_power' => 0
        ];
    }
    
    /**
     * Start new server creation process
     */
    public function startServerCreation($configName = null, $description = null) {
        $this->currentConfiguration = $this->initializeConfiguration();
        
        if ($configName) {
            $this->currentConfiguration['config_name'] = $configName;
        }
        
        if ($description) {
            $this->currentConfiguration['config_description'] = $description;
        }
        
        return [
            'success' => true,
            'session_id' => $this->sessionId,
            'config_uuid' => $this->currentConfiguration['config_uuid'],
            'message' => 'Server creation started successfully'
        ];
    }
    
    /**
     * Add component to server configuration
     */
    public function addComponent($componentType, $componentUuid, $options = []) {
        try {
            // Validate component exists and is available
            $component = $this->getComponentDetails($componentType, $componentUuid);
            if (!$component) {
                return [
                    'success' => false,
                    'message' => 'Component not found or unavailable'
                ];
            }
            
            // Check compatibility with existing components
            $compatibilityResults = $this->checkComponentCompatibility($componentType, $componentUuid);
            
            if (!$compatibilityResults['compatible'] && !($options['override'] ?? false)) {
                return [
                    'success' => false,
                    'message' => 'Component not compatible with current configuration',
                    'compatibility_issues' => $compatibilityResults['failures'],
                    'can_override' => $this->canOverrideCompatibility($compatibilityResults)
                ];
            }
            
            // Add component to configuration
            $addResult = $this->addComponentToConfiguration($componentType, $componentUuid, $component, $options);
            
            if ($addResult['success']) {
                // Update compatibility results
                $this->currentConfiguration['compatibility_results'] = $this->updateCompatibilityResults();
                
                // Get updated compatible components for remaining types
                $compatibleComponents = $this->getCompatibleComponentsForRemaining();
                
                return [
                    'success' => true,
                    'message' => "Component added successfully",
                    'current_configuration' => $this->getCurrentConfigurationSummary(),
                    'compatibility_results' => $compatibilityResults,
                    'compatible_components' => $compatibleComponents,
                    'next_recommendations' => $this->getNextRecommendations()
                ];
            }
            
            return $addResult;
            
        } catch (Exception $e) {
            error_log("Error adding component: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Internal error adding component'
            ];
        }
    }
    
    /**
     * Remove component from server configuration
     */
    public function removeComponent($componentType, $componentUuid = null) {
        try {
            $removed = false;
            
            switch ($componentType) {
                case 'cpu':
                    if ($this->currentConfiguration['cpu_uuid'] === $componentUuid || $componentUuid === null) {
                        $this->currentConfiguration['cpu_uuid'] = null;
                        $this->currentConfiguration['cpu_id'] = null;
                        $removed = true;
                    }
                    break;
                    
                case 'motherboard':
                    if ($this->currentConfiguration['motherboard_uuid'] === $componentUuid || $componentUuid === null) {
                        $this->currentConfiguration['motherboard_uuid'] = null;
                        $this->currentConfiguration['motherboard_id'] = null;
                        $removed = true;
                    }
                    break;
                    
                case 'ram':
                case 'storage':
                case 'nic':
                case 'caddy':
                    $configKey = $componentType . '_configuration';
                    if ($componentUuid) {
                        // Remove specific component
                        $this->currentConfiguration[$configKey] = array_filter(
                            $this->currentConfiguration[$configKey],
                            function($item) use ($componentUuid) {
                                return $item['uuid'] !== $componentUuid;
                            }
                        );
                        $removed = true;
                    } else {
                        // Remove all components of this type
                        $this->currentConfiguration[$configKey] = [];
                        $removed = true;
                    }
                    break;
            }
            
            if ($removed) {
                // Update compatibility results
                $this->currentConfiguration['compatibility_results'] = $this->updateCompatibilityResults();
                
                // Get updated compatible components
                $compatibleComponents = $this->getCompatibleComponentsForRemaining();
                
                return [
                    'success' => true,
                    'message' => 'Component removed successfully',
                    'current_configuration' => $this->getCurrentConfigurationSummary(),
                    'compatible_components' => $compatibleComponents
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Component not found in configuration'
            ];
            
        } catch (Exception $e) {
            error_log("Error removing component: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Internal error removing component'
            ];
        }
    }
    
    /**
     * Get compatible components for all remaining component types
     */
    public function getCompatibleComponentsForAll() {
        $compatibleComponents = [];
        $componentTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
        
        foreach ($componentTypes as $componentType) {
            $compatibleComponents[$componentType] = $this->getCompatibleComponentsForType($componentType);
        }
        
        return $compatibleComponents;
    }
    
    /**
     * Get compatible components for a specific component type
     */
    public function getCompatibleComponentsForType($componentType, $availableOnly = true) {
        try {
            // If no components selected yet, return all available components
            if ($this->isConfigurationEmpty()) {
                return $this->getAllComponentsByType($componentType, $availableOnly);
            }
            
            // Get all selected components for compatibility checking
            $selectedComponents = $this->getSelectedComponents();
            
            if (empty($selectedComponents)) {
                return $this->getAllComponentsByType($componentType, $availableOnly);
            }
            
            // Get all available components of the requested type
            $allComponents = $this->getAllComponentsByType($componentType, $availableOnly);
            $compatibleComponents = [];
            
            foreach ($allComponents as $component) {
                $isCompatible = true;
                $compatibilityScore = 1.0;
                $compatibilityInfo = [
                    'compatible' => true,
                    'overall_score' => 1.0,
                    'component_checks' => [],
                    'warnings' => []
                ];
                
                // Check compatibility with each selected component
                foreach ($selectedComponents as $selectedComponent) {
                    $targetComponent = ['type' => $componentType, 'uuid' => $component['UUID']];
                    $baseComponent = ['type' => $selectedComponent['type'], 'uuid' => $selectedComponent['uuid']];
                    
                    $compatibility = $this->compatibilityEngine->checkCompatibility($baseComponent, $targetComponent);
                    
                    if (!$compatibility['compatible']) {
                        $isCompatible = false;
                        break;
                    }
                    
                    $compatibilityScore *= $compatibility['compatibility_score'];
                    $compatibilityInfo['component_checks'][] = [
                        'with_component' => $selectedComponent['type'],
                        'compatible' => $compatibility['compatible'],
                        'score' => $compatibility['compatibility_score'],
                        'warnings' => $compatibility['warnings']
                    ];
                    
                    if (!empty($compatibility['warnings'])) {
                        $compatibilityInfo['warnings'] = array_merge(
                            $compatibilityInfo['warnings'],
                            $compatibility['warnings']
                        );
                    }
                }
                
                if ($isCompatible) {
                    $component['compatibility_score'] = $compatibilityScore;
                    $component['compatibility_info'] = $compatibilityInfo;
                    $compatibleComponents[] = $component;
                }
            }
            
            // Sort by compatibility score (descending)
            usort($compatibleComponents, function($a, $b) {
                return $b['compatibility_score'] <=> $a['compatibility_score'];
            });
            
            return $compatibleComponents;
            
        } catch (Exception $e) {
            error_log("Error getting compatible components for type: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Validate complete server configuration
     */
    public function validateConfiguration($configUuid = null) {
        try {
            $config = $configUuid ? $this->loadConfiguration($configUuid) : $this->currentConfiguration;
            
            if (!$config) {
                return [
                    'valid' => false,
                    'message' => 'Configuration not found'
                ];
            }
            
            $validationResult = $this->compatibilityEngine->validateServerConfiguration($config);
            
            // Update configuration with validation results
            if (!$configUuid) {
                $this->currentConfiguration['validation_results'] = $validationResult;
                $this->currentConfiguration['estimated_power'] = $validationResult['estimated_power'];
                $this->currentConfiguration['estimated_cost'] = $validationResult['estimated_cost'];
            }
            
            return [
                'valid' => $validationResult['valid'],
                'validation_results' => $validationResult,
                'configuration_summary' => $this->getCurrentConfigurationSummary(),
                'recommendations' => $this->generateRecommendations($validationResult)
            ];
            
        } catch (Exception $e) {
            error_log("Error validating configuration: " . $e->getMessage());
            return [
                'valid' => false,
                'message' => 'Internal error during validation'
            ];
        }
    }
    
    /**
     * Save server configuration to database
     */
    public function saveConfiguration($configName = null, $description = null, $status = 1) {
        try {
            if ($configName) {
                $this->currentConfiguration['config_name'] = $configName;
            }
            
            if ($description) {
                $this->currentConfiguration['config_description'] = $description;
            }
            
            $this->currentConfiguration['configuration_status'] = $status;
            
            // Validate before saving
            $validation = $this->validateConfiguration();
            if (!$validation['valid'] && $status > 0) {
                return [
                    'success' => false,
                    'message' => 'Cannot save invalid configuration',
                    'validation_results' => $validation['validation_results']
                ];
            }
            
            // Prepare data for database
            $configData = [
                'config_uuid' => $this->currentConfiguration['config_uuid'],
                'config_name' => $this->currentConfiguration['config_name'],
                'config_description' => $this->currentConfiguration['config_description'],
                'cpu_uuid' => $this->currentConfiguration['cpu_uuid'],
                'cpu_id' => $this->currentConfiguration['cpu_id'],
                'motherboard_uuid' => $this->currentConfiguration['motherboard_uuid'],
                'motherboard_id' => $this->currentConfiguration['motherboard_id'],
                'ram_configuration' => json_encode($this->currentConfiguration['ram_configuration']),
                'storage_configuration' => json_encode($this->currentConfiguration['storage_configuration']),
                'nic_configuration' => json_encode($this->currentConfiguration['nic_configuration']),
                'caddy_configuration' => json_encode($this->currentConfiguration['caddy_configuration']),
                'additional_components' => json_encode($this->currentConfiguration['additional_components']),
                'configuration_status' => $this->currentConfiguration['configuration_status'],
                'total_cost' => $this->currentConfiguration['estimated_cost'],
                'power_consumption' => $this->currentConfiguration['estimated_power'],
                'compatibility_score' => $validation['validation_results']['overall_score'] ?? 0,
                'validation_results' => json_encode($validation['validation_results'] ?? []),
                'created_by' => $_SESSION['user_id'] ?? null
            ];
            
            // Check if configuration already exists
            $existingConfig = $this->loadConfiguration($this->currentConfiguration['config_uuid']);
            
            if ($existingConfig) {
                // Update existing configuration
                $result = $this->updateConfigurationInDatabase($configData);
            } else {
                // Insert new configuration
                $result = $this->insertConfigurationToDatabase($configData);
            }
            
            if ($result['success']) {
                // Update component statuses if configuration is built (status = 2)
                if ($status == 2) {
                    $this->updateComponentStatuses();
                }
                
                return [
                    'success' => true,
                    'message' => 'Configuration saved successfully',
                    'config_id' => $result['config_id'],
                    'config_uuid' => $this->currentConfiguration['config_uuid']
                ];
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error saving configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Internal error saving configuration'
            ];
        }
    }
    
    /**
     * Load existing server configuration
     */
    public function loadConfiguration($configUuid) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM server_configurations 
                WHERE config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($config) {
                // Decode JSON fields
                $config['ram_configuration'] = json_decode($config['ram_configuration'] ?? '[]', true);
                $config['storage_configuration'] = json_decode($config['storage_configuration'] ?? '[]', true);
                $config['nic_configuration'] = json_decode($config['nic_configuration'] ?? '[]', true);
                $config['caddy_configuration'] = json_decode($config['caddy_configuration'] ?? '[]', true);
                $config['additional_components'] = json_decode($config['additional_components'] ?? '[]', true);
                $config['validation_results'] = json_decode($config['validation_results'] ?? '[]', true);
            }
            
            return $config;
            
        } catch (PDOException $e) {
            error_log("Error loading configuration: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get list of saved configurations
     */
    public function getConfigurationList($userId = null, $status = null) {
        try {
            $sql = "
                SELECT 
                    id, config_uuid, config_name, config_description,
                    configuration_status, total_cost, power_consumption,
                    compatibility_score, created_by, created_at, updated_at
                FROM server_configurations 
                WHERE 1=1
            ";
            $params = [];
            
            if ($userId) {
                $sql .= " AND created_by = ?";
                $params[] = $userId;
            }
            
            if ($status !== null) {
                $sql .= " AND configuration_status = ?";
                $params[] = $status;
            }
            
            $sql .= " ORDER BY updated_at DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting configuration list: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Delete server configuration
     */
    public function deleteConfiguration($configUuid) {
        try {
            $this->pdo->beginTransaction();
            
            // First, free up any components that were marked as "In use"
            $config = $this->loadConfiguration($configUuid);
            if ($config && $config['configuration_status'] == 2) {
                $this->freeupComponents($config);
            }
            
            // Delete the configuration
            $stmt = $this->pdo->prepare("DELETE FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$configUuid]);
            
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message' => 'Configuration deleted successfully'
            ];
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Error deleting configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error deleting configuration'
            ];
        }
    }
    
    /**
     * Private helper methods
     */
    
    private function checkComponentCompatibility($componentType, $componentUuid) {
        $selectedComponents = $this->getSelectedComponents();
        
        if (empty($selectedComponents)) {
            return ['compatible' => true, 'failures' => [], 'warnings' => []];
        }
        
        $newComponent = ['type' => $componentType, 'uuid' => $componentUuid];
        $allCompatible = true;
        $allFailures = [];
        $allWarnings = [];
        
        foreach ($selectedComponents as $selectedComponent) {
            $baseComponent = ['type' => $selectedComponent['type'], 'uuid' => $selectedComponent['uuid']];
            $compatibility = $this->compatibilityEngine->checkCompatibility($baseComponent, $newComponent);
            
            if (!$compatibility['compatible']) {
                $allCompatible = false;
                $allFailures = array_merge($allFailures, $compatibility['failures']);
            }
            
            $allWarnings = array_merge($allWarnings, $compatibility['warnings']);
        }
        
        return [
            'compatible' => $allCompatible,
            'failures' => array_unique($allFailures),
            'warnings' => array_unique($allWarnings)
        ];
    }
    
    private function addComponentToConfiguration($componentType, $componentUuid, $component, $options) {
        switch ($componentType) {
            case 'cpu':
                if ($this->currentConfiguration['cpu_uuid']) {
                    return [
                        'success' => false,
                        'message' => 'CPU already selected. Remove existing CPU first.'
                    ];
                }
                $this->currentConfiguration['cpu_uuid'] = $componentUuid;
                $this->currentConfiguration['cpu_id'] = $component['ID'];
                break;
                
            case 'motherboard':
                if ($this->currentConfiguration['motherboard_uuid']) {
                    return [
                        'success' => false,
                        'message' => 'Motherboard already selected. Remove existing motherboard first.'
                    ];
                }
                $this->currentConfiguration['motherboard_uuid'] = $componentUuid;
                $this->currentConfiguration['motherboard_id'] = $component['ID'];
                break;
                
            case 'ram':
            case 'storage':
            case 'nic':
            case 'caddy':
                $configKey = $componentType . '_configuration';
                
                // Check if component already exists
                foreach ($this->currentConfiguration[$configKey] as $existingComponent) {
                    if ($existingComponent['uuid'] === $componentUuid) {
                        return [
                            'success' => false,
                            'message' => 'Component already added to configuration'
                        ];
                    }
                }
                
                $componentConfig = [
                    'uuid' => $componentUuid,
                    'id' => $component['ID'],
                    'quantity' => $options['quantity'] ?? 1,
                    'slot_position' => $options['slot_position'] ?? null,
                    'notes' => $options['notes'] ?? null
                ];
                
                $this->currentConfiguration[$configKey][] = $componentConfig;
                break;
                
            default:
                return [
                    'success' => false,
                    'message' => 'Unknown component type'
                ];
        }
        
        return ['success' => true];
    }
    
    private function getSelectedComponents() {
        $selected = [];
        
        if ($this->currentConfiguration['cpu_uuid']) {
            $selected[] = [
                'type' => 'cpu',
                'uuid' => $this->currentConfiguration['cpu_uuid']
            ];
        }
        
        if ($this->currentConfiguration['motherboard_uuid']) {
            $selected[] = [
                'type' => 'motherboard',
                'uuid' => $this->currentConfiguration['motherboard_uuid']
            ];
        }
        
        foreach ($this->currentConfiguration['ram_configuration'] as $ram) {
            $selected[] = [
                'type' => 'ram',
                'uuid' => $ram['uuid']
            ];
        }
        
        foreach ($this->currentConfiguration['storage_configuration'] as $storage) {
            $selected[] = [
                'type' => 'storage',
                'uuid' => $storage['uuid']
            ];
        }
        
        foreach ($this->currentConfiguration['nic_configuration'] as $nic) {
            $selected[] = [
                'type' => 'nic',
                'uuid' => $nic['uuid']
            ];
        }
        
        foreach ($this->currentConfiguration['caddy_configuration'] as $caddy) {
            $selected[] = [
                'type' => 'caddy',
                'uuid' => $caddy['uuid']
            ];
        }
        
        return $selected;
    }
    
    private function isConfigurationEmpty() {
        return empty($this->currentConfiguration['cpu_uuid']) &&
               empty($this->currentConfiguration['motherboard_uuid']) &&
               empty($this->currentConfiguration['ram_configuration']) &&
               empty($this->currentConfiguration['storage_configuration']) &&
               empty($this->currentConfiguration['nic_configuration']) &&
               empty($this->currentConfiguration['caddy_configuration']);
    }
    
    private function getCompatibleComponentsForRemaining() {
        $componentTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
        $compatible = [];
        
        foreach ($componentTypes as $type) {
            if (!$this->hasComponentOfType($type)) {
                $compatible[$type] = $this->getCompatibleComponentsForType($type);
            }
        }
        
        return $compatible;
    }
    
    private function hasComponentOfType($componentType) {
        switch ($componentType) {
            case 'cpu':
                return !empty($this->currentConfiguration['cpu_uuid']);
            case 'motherboard':
                return !empty($this->currentConfiguration['motherboard_uuid']);
            case 'ram':
            case 'storage':
            case 'nic':
            case 'caddy':
                $configKey = $componentType . '_configuration';
                return !empty($this->currentConfiguration[$configKey]);
            default:
                return false;
        }
    }
    
    private function getCurrentConfigurationSummary() {
        return [
            'config_uuid' => $this->currentConfiguration['config_uuid'],
            'config_name' => $this->currentConfiguration['config_name'],
            'config_description' => $this->currentConfiguration['config_description'],
            'cpu_selected' => !empty($this->currentConfiguration['cpu_uuid']),
            'motherboard_selected' => !empty($this->currentConfiguration['motherboard_uuid']),
            'ram_count' => count($this->currentConfiguration['ram_configuration']),
            'storage_count' => count($this->currentConfiguration['storage_configuration']),
            'nic_count' => count($this->currentConfiguration['nic_configuration']),
            'caddy_count' => count($this->currentConfiguration['caddy_configuration']),
            'estimated_power' => $this->currentConfiguration['estimated_power'],
            'estimated_cost' => $this->currentConfiguration['estimated_cost'],
            'configuration_status' => $this->currentConfiguration['configuration_status']
        ];
    }
    
    private function getComponentDetails($componentType, $uuid) {
        $tableMap = [
            'cpu' => 'cpuinventory',
            'motherboard' => 'motherboardinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        if (!isset($tableMap[$componentType])) {
            return null;
        }
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM {$tableMap[$componentType]} WHERE UUID = ? AND Status = 1");
            $stmt->execute([$uuid]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting component details: " . $e->getMessage());
            return null;
        }
    }
    
    private function getAllComponentsByType($componentType, $availableOnly = true) {
        $tableMap = [
            'cpu' => 'cpuinventory',
            'motherboard' => 'motherboardinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        if (!isset($tableMap[$componentType])) {
            return [];
        }
        
        try {
            $sql = "SELECT * FROM {$tableMap[$componentType]}";
            if ($availableOnly) {
                $sql .= " WHERE Status = 1";
            }
            $sql .= " ORDER BY CreatedAt DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting all components by type: " . $e->getMessage());
            return [];
        }
    }
    
    private function updateCompatibilityResults() {
        $selectedComponents = $this->getSelectedComponents();
        $results = [];
        
        for ($i = 0; $i < count($selectedComponents); $i++) {
            for ($j = $i + 1; $j < count($selectedComponents); $j++) {
                $comp1 = $selectedComponents[$i];
                $comp2 = $selectedComponents[$j];
                
                $compatibility = $this->compatibilityEngine->checkCompatibility($comp1, $comp2);
                $results[] = [
                    'components' => $comp1['type'] . ' <-> ' . $comp2['type'],
                    'compatible' => $compatibility['compatible'],
                    'score' => $compatibility['compatibility_score'],
                    'issues' => $compatibility['failures'],
                    'warnings' => $compatibility['warnings']
                ];
            }
        }
        
        return $results;
    }
    
    private function getNextRecommendations() {
        $recommendations = [];
        $selectedTypes = [];
        
        // Determine what's already selected
        if ($this->currentConfiguration['cpu_uuid']) $selectedTypes[] = 'cpu';
        if ($this->currentConfiguration['motherboard_uuid']) $selectedTypes[] = 'motherboard';
        if (!empty($this->currentConfiguration['ram_configuration'])) $selectedTypes[] = 'ram';
        if (!empty($this->currentConfiguration['storage_configuration'])) $selectedTypes[] = 'storage';
        
        // Generate recommendations based on what's missing
        if (!in_array('cpu', $selectedTypes) && !in_array('motherboard', $selectedTypes)) {
            $recommendations[] = "Start by selecting either a CPU or Motherboard to define the foundation of your server";
        } elseif (!in_array('cpu', $selectedTypes)) {
            $recommendations[] = "Select a CPU compatible with your chosen motherboard";
        } elseif (!in_array('motherboard', $selectedTypes)) {
            $recommendations[] = "Select a motherboard compatible with your chosen CPU";
        } elseif (!in_array('ram', $selectedTypes)) {
            $recommendations[] = "Add memory modules compatible with your motherboard and CPU";
        } elseif (!in_array('storage', $selectedTypes)) {
            $recommendations[] = "Add storage devices to complete your server configuration";
        } else {
            $recommendations[] = "Consider adding network cards or additional components as needed";
        }
        
        return $recommendations;
    }
    
    private function canOverrideCompatibility($compatibilityResults) {
        // Check if any failed rules allow override
        foreach ($this->compatibilityEngine->getComponentRules() as $rule) {
            if ($rule['is_override_allowed']) {
                return true;
            }
        }
        return false;
    }
    
    private function generateRecommendations($validationResults) {
        $recommendations = [];
        
        if (!$validationResults['valid']) {
            $recommendations[] = "Configuration has compatibility issues that need to be resolved";
        }
        
        if ($validationResults['estimated_power'] > 400) {
            $recommendations[] = "High power consumption detected. Consider a higher capacity PSU";
        }
        
        if ($validationResults['overall_score'] < 0.8) {
            $recommendations[] = "Consider optimizing component selection for better compatibility";
        }
        
        return $recommendations;
    }
    
    private function insertConfigurationToDatabase($configData) {
        try {
            $sql = "
                INSERT INTO server_configurations (
                    config_uuid, config_name, config_description, cpu_uuid, cpu_id,
                    motherboard_uuid, motherboard_id, ram_configuration, storage_configuration,
                    nic_configuration, caddy_configuration, additional_components,
                    configuration_status, total_cost, power_consumption, compatibility_score,
                    validation_results, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $configData['config_uuid'],
                $configData['config_name'],
                $configData['config_description'],
                $configData['cpu_uuid'],
                $configData['cpu_id'],
                $configData['motherboard_uuid'],
                $configData['motherboard_id'],
                $configData['ram_configuration'],
                $configData['storage_configuration'],
                $configData['nic_configuration'],
                $configData['caddy_configuration'],
                $configData['additional_components'],
                $configData['configuration_status'],
                $configData['total_cost'],
                $configData['power_consumption'],
                $configData['compatibility_score'],
                $configData['validation_results'],
                $configData['created_by']
            ]);
            
            return [
                'success' => true,
                'config_id' => $this->pdo->lastInsertId()
            ];
            
        } catch (PDOException $e) {
            error_log("Error inserting configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error saving configuration'
            ];
        }
    }
    
    private function updateConfigurationInDatabase($configData) {
        try {
            $sql = "
                UPDATE server_configurations SET
                    config_name = ?, config_description = ?, cpu_uuid = ?, cpu_id = ?,
                    motherboard_uuid = ?, motherboard_id = ?, ram_configuration = ?,
                    storage_configuration = ?, nic_configuration = ?, caddy_configuration = ?,
                    additional_components = ?, configuration_status = ?, total_cost = ?,
                    power_consumption = ?, compatibility_score = ?, validation_results = ?,
                    updated_by = ?, updated_at = CURRENT_TIMESTAMP
                WHERE config_uuid = ?
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $configData['config_name'],
                $configData['config_description'],
                $configData['cpu_uuid'],
                $configData['cpu_id'],
                $configData['motherboard_uuid'],
                $configData['motherboard_id'],
                $configData['ram_configuration'],
                $configData['storage_configuration'],
                $configData['nic_configuration'],
                $configData['caddy_configuration'],
                $configData['additional_components'],
                $configData['configuration_status'],
                $configData['total_cost'],
                $configData['power_consumption'],
                $configData['compatibility_score'],
                $configData['validation_results'],
                $configData['created_by'],
                $configData['config_uuid']
            ]);
            
            return [
                'success' => true,
                'config_id' => null // For updates, we don't have a new ID
            ];
            
        } catch (PDOException $e) {
            error_log("Error updating configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error updating configuration'
            ];
        }
    }
    
    private function updateComponentStatuses() {
        // Mark components as "In Use" when server is built
        $tableMap = [
            'cpu' => 'cpuinventory',
            'motherboard' => 'motherboardinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        try {
            $this->pdo->beginTransaction();
            
            // Update CPU status
            if ($this->currentConfiguration['cpu_uuid']) {
                $stmt = $this->pdo->prepare("UPDATE cpuinventory SET Status = 2, ServerUUID = ? WHERE UUID = ?");
                $stmt->execute([$this->currentConfiguration['config_uuid'], $this->currentConfiguration['cpu_uuid']]);
            }
            
            // Update Motherboard status
            if ($this->currentConfiguration['motherboard_uuid']) {
                $stmt = $this->pdo->prepare("UPDATE motherboardinventory SET Status = 2, ServerUUID = ? WHERE UUID = ?");
                $stmt->execute([$this->currentConfiguration['config_uuid'], $this->currentConfiguration['motherboard_uuid']]);
            }
            
            // Update RAM status
            foreach ($this->currentConfiguration['ram_configuration'] as $ram) {
                $stmt = $this->pdo->prepare("UPDATE raminventory SET Status = 2, ServerUUID = ? WHERE UUID = ?");
                $stmt->execute([$this->currentConfiguration['config_uuid'], $ram['uuid']]);
            }
            
            // Update Storage status
            foreach ($this->currentConfiguration['storage_configuration'] as $storage) {
                $stmt = $this->pdo->prepare("UPDATE storageinventory SET Status = 2, ServerUUID = ? WHERE UUID = ?");
                $stmt->execute([$this->currentConfiguration['config_uuid'], $storage['uuid']]);
            }
            
            // Update NIC status
            foreach ($this->currentConfiguration['nic_configuration'] as $nic) {
                $stmt = $this->pdo->prepare("UPDATE nicinventory SET Status = 2, ServerUUID = ? WHERE UUID = ?");
                $stmt->execute([$this->currentConfiguration['config_uuid'], $nic['uuid']]);
            }
            
            // Update Caddy status
            foreach ($this->currentConfiguration['caddy_configuration'] as $caddy) {
                $stmt = $this->pdo->prepare("UPDATE caddyinventory SET Status = 2, ServerUUID = ? WHERE UUID = ?");
                $stmt->execute([$this->currentConfiguration['config_uuid'], $caddy['uuid']]);
            }
            
            $this->pdo->commit();
            
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Error updating component statuses: " . $e->getMessage());
        }
    }
    
    private function freeupComponents($config) {
        // Mark components as "Available" when server configuration is deleted
        try {
            // Free up CPU
            if ($config['cpu_uuid']) {
                $stmt = $this->pdo->prepare("UPDATE cpuinventory SET Status = 1, ServerUUID = NULL WHERE UUID = ?");
                $stmt->execute([$config['cpu_uuid']]);
            }
            
            // Free up Motherboard
            if ($config['motherboard_uuid']) {
                $stmt = $this->pdo->prepare("UPDATE motherboardinventory SET Status = 1, ServerUUID = NULL WHERE UUID = ?");
                $stmt->execute([$config['motherboard_uuid']]);
            }
            
            // Free up RAM
            if (!empty($config['ram_configuration'])) {
                foreach ($config['ram_configuration'] as $ram) {
                    $stmt = $this->pdo->prepare("UPDATE raminventory SET Status = 1, ServerUUID = NULL WHERE UUID = ?");
                    $stmt->execute([$ram['uuid']]);
                }
            }
            
            // Free up Storage
            if (!empty($config['storage_configuration'])) {
                foreach ($config['storage_configuration'] as $storage) {
                    $stmt = $this->pdo->prepare("UPDATE storageinventory SET Status = 1, ServerUUID = NULL WHERE UUID = ?");
                    $stmt->execute([$storage['uuid']]);
                }
            }
            
            // Free up NIC
            if (!empty($config['nic_configuration'])) {
                foreach ($config['nic_configuration'] as $nic) {
                    $stmt = $this->pdo->prepare("UPDATE nicinventory SET Status = 1, ServerUUID = NULL WHERE UUID = ?");
                    $stmt->execute([$nic['uuid']]);
                }
            }
            
            // Free up Caddy
            if (!empty($config['caddy_configuration'])) {
                foreach ($config['caddy_configuration'] as $caddy) {
                    $stmt = $this->pdo->prepare("UPDATE caddyinventory SET Status = 1, ServerUUID = NULL WHERE UUID = ?");
                    $stmt->execute([$caddy['uuid']]);
                }
            }
            
        } catch (PDOException $e) {
            error_log("Error freeing up components: " . $e->getMessage());
        }
    }
    
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Get current configuration (for external access)
     */
    public function getCurrentConfiguration() {
        return $this->currentConfiguration;
    }
    
    /**
     * Set current configuration (for loading existing configurations)
     */
    public function setCurrentConfiguration($config) {
        $this->currentConfiguration = $config;
    }
    
    /**
     * Get session ID
     */
    public function getSessionId() {
        return $this->sessionId;
    }
    
    /**
     * Clone existing configuration
     */
    public function cloneConfiguration($sourceConfigUuid, $newConfigName = null) {
        try {
            $sourceConfig = $this->loadConfiguration($sourceConfigUuid);
            if (!$sourceConfig) {
                return [
                    'success' => false,
                    'message' => 'Source configuration not found'
                ];
            }
            
            // Create new configuration based on source
            $this->currentConfiguration = $sourceConfig;
            $this->currentConfiguration['config_uuid'] = $this->generateUUID();
            $this->currentConfiguration['config_name'] = $newConfigName ?? ($sourceConfig['config_name'] . ' (Copy)');
            $this->currentConfiguration['configuration_status'] = 0; // Draft
            $this->currentConfiguration['created_by'] = $_SESSION['user_id'] ?? null;
            
            // Remove IDs and timestamps
            unset($this->currentConfiguration['id']);
            unset($this->currentConfiguration['created_at']);
            unset($this->currentConfiguration['updated_at']);
            
            return [
                'success' => true,
                'message' => 'Configuration cloned successfully',
                'config_uuid' => $this->currentConfiguration['config_uuid'],
                'config_summary' => $this->getCurrentConfigurationSummary()
            ];
            
        } catch (Exception $e) {
            error_log("Error cloning configuration: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error cloning configuration'
            ];
        }
    }
    
    /**
     * Get compatibility statistics for current configuration
     */
    public function getConfigurationStatistics() {
        try {
            $selectedComponents = $this->getSelectedComponents();
            $totalChecks = 0;
            $compatibleChecks = 0;
            $totalScore = 0;
            
            for ($i = 0; $i < count($selectedComponents); $i++) {
                for ($j = $i + 1; $j < count($selectedComponents); $j++) {
                    $comp1 = $selectedComponents[$i];
                    $comp2 = $selectedComponents[$j];
                    
                    $compatibility = $this->compatibilityEngine->checkCompatibility($comp1, $comp2);
                    $totalChecks++;
                    
                    if ($compatibility['compatible']) {
                        $compatibleChecks++;
                    }
                    
                    $totalScore += $compatibility['compatibility_score'];
                }
            }
            
            return [
                'total_components' => count($selectedComponents),
                'total_compatibility_checks' => $totalChecks,
                'compatible_checks' => $compatibleChecks,
                'compatibility_percentage' => $totalChecks > 0 ? ($compatibleChecks / $totalChecks) * 100 : 100,
                'average_compatibility_score' => $totalChecks > 0 ? $totalScore / $totalChecks : 1.0,
                'estimated_power' => $this->currentConfiguration['estimated_power'],
                'estimated_cost' => $this->currentConfiguration['estimated_cost']
            ];
            
        } catch (Exception $e) {
            error_log("Error getting configuration statistics: " . $e->getMessage());
            return [
                'total_components' => 0,
                'total_compatibility_checks' => 0,
                'compatible_checks' => 0,
                'compatibility_percentage' => 0,
                'average_compatibility_score' => 0,
                'estimated_power' => 0,
                'estimated_cost' => 0
            ];
        }
    }
}
?>