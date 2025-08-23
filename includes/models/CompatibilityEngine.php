<?php
/**
 * Infrastructure Management System - Compatibility Engine
 * File: includes/models/CompatibilityEngine.php
 * 
 * Core compatibility checking and validation engine for server components
 */

class CompatibilityEngine {
    private $pdo;
    private $componentRules;
    private $sessionId;
    private $debugMode;
    
    public function __construct($pdo, $debugMode = false) {
        $this->pdo = $pdo;
        $this->debugMode = $debugMode;
        $this->sessionId = uniqid('compat_', true);
        $this->loadCompatibilityRules();
    }
    
    /**
     * Load compatibility rules from database
     */
    private function loadCompatibilityRules() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT rule_name, rule_type, component_types, rule_definition, 
                       rule_priority, failure_message, is_override_allowed
                FROM compatibility_rules 
                WHERE is_active = 1 
                ORDER BY rule_priority ASC
            ");
            $stmt->execute();
            $this->componentRules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($this->componentRules as &$rule) {
                $rule['rule_definition'] = json_decode($rule['rule_definition'], true);
                $rule['component_types'] = explode(',', $rule['component_types']);
            }
            
        } catch (PDOException $e) {
            error_log("Failed to load compatibility rules: " . $e->getMessage());
            $this->componentRules = [];
        }
    }
    
    /**
     * Check compatibility between two components
     */
    public function checkCompatibility($component1, $component2, $overrideRules = false) {
        $startTime = microtime(true);
        
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'applied_rules' => [],
            'failures' => [],
            'warnings' => [],
            'recommendations' => []
        ];
        
        try {
            // Get component details
            $comp1Details = $this->getComponentDetails($component1['type'], $component1['uuid']);
            $comp2Details = $this->getComponentDetails($component2['type'], $component2['uuid']);
            
            if (!$comp1Details || !$comp2Details) {
                $result['compatible'] = false;
                $result['failures'][] = 'One or both components not found';
                return $result;
            }
            
            // Apply compatibility rules
            foreach ($this->componentRules as $rule) {
                if ($this->ruleApplies($rule, $component1['type'], $component2['type'])) {
                    $ruleResult = $this->applyRule($rule, $comp1Details, $comp2Details);
                    $result['applied_rules'][] = $rule['rule_name'];
                    
                    if (!$ruleResult['passed'] && !$overrideRules) {
                        $result['compatible'] = false;
                        $result['failures'][] = $ruleResult['message'] ?? $rule['failure_message'];
                        $result['compatibility_score'] *= 0.5; // Reduce score for failures
                    } elseif (!$ruleResult['passed'] && $rule['is_override_allowed']) {
                        $result['warnings'][] = $ruleResult['message'] ?? $rule['failure_message'];
                        $result['compatibility_score'] *= 0.8; // Slight reduction for warnings
                    }
                    
                    if (isset($ruleResult['recommendations'])) {
                        $result['recommendations'] = array_merge($result['recommendations'], $ruleResult['recommendations']);
                    }
                }
            }
            
            // Log the operation
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->logCompatibilityCheck($component1, $component2, $result, $executionTime);
            
        } catch (Exception $e) {
            error_log("Compatibility check error: " . $e->getMessage());
            $result['compatible'] = false;
            $result['failures'][] = 'Internal compatibility check error';
        }
        
        return $result;
    }
    
    /**
     * Get compatible components for a given component
     */
    public function getCompatibleComponents($baseComponent, $targetType, $availableOnly = true) {
        $compatibleComponents = [];
        
        try {
            // Get all components of target type
            $components = $this->getComponentsByType($targetType, $availableOnly);
            
            foreach ($components as $component) {
                $targetComponent = ['type' => $targetType, 'uuid' => $component['UUID']];
                $compatibility = $this->checkCompatibility($baseComponent, $targetComponent);
                
                if ($compatibility['compatible']) {
                    $component['compatibility_score'] = $compatibility['compatibility_score'];
                    $component['compatibility_info'] = $compatibility;
                    $compatibleComponents[] = $component;
                }
            }
            
            // Sort by compatibility score (descending)
            usort($compatibleComponents, function($a, $b) {
                return $b['compatibility_score'] <=> $a['compatibility_score'];
            });
            
        } catch (Exception $e) {
            error_log("Error getting compatible components: " . $e->getMessage());
        }
        
        return $compatibleComponents;
    }
    
    /**
     * Validate complete server configuration
     */
    public function validateServerConfiguration($configuration) {
        $result = [
            'valid' => true,
            'overall_score' => 1.0,
            'component_checks' => [],
            'global_checks' => [],
            'recommendations' => [],
            'estimated_power' => 0,
            'estimated_cost' => 0.0
        ];
        
        try {
            $components = $this->parseServerConfiguration($configuration);
            
            // Pairwise compatibility checks
            $componentTypes = array_keys($components);
            for ($i = 0; $i < count($componentTypes); $i++) {
                for ($j = $i + 1; $j < count($componentTypes); $j++) {
                    $type1 = $componentTypes[$i];
                    $type2 = $componentTypes[$j];
                    
                    if ($components[$type1] && $components[$type2]) {
                        $comp1 = ['type' => $type1, 'uuid' => $components[$type1]['UUID']];
                        $comp2 = ['type' => $type2, 'uuid' => $components[$type2]['UUID']];
                        
                        $compatibility = $this->checkCompatibility($comp1, $comp2);
                        $result['component_checks'][] = [
                            'components' => "$type1 <-> $type2",
                            'compatible' => $compatibility['compatible'],
                            'score' => $compatibility['compatibility_score'],
                            'issues' => $compatibility['failures'],
                            'warnings' => $compatibility['warnings']
                        ];
                        
                        if (!$compatibility['compatible']) {
                            $result['valid'] = false;
                        }
                        
                        $result['overall_score'] *= $compatibility['compatibility_score'];
                    }
                }
            }
            
            // Global validation checks
            $result['global_checks'] = $this->performGlobalChecks($components);
            
            // Calculate estimates
            $result['estimated_power'] = $this->calculatePowerConsumption($components);
            $result['estimated_cost'] = $this->calculateTotalCost($components);
            
        } catch (Exception $e) {
            error_log("Server configuration validation error: " . $e->getMessage());
            $result['valid'] = false;
            $result['global_checks'][] = [
                'check' => 'System Error',
                'passed' => false,
                'message' => 'Configuration validation failed'
            ];
        }
        
        return $result;
    }
    
    /**
     * Apply a specific compatibility rule
     */
    private function applyRule($rule, $comp1Details, $comp2Details) {
        $result = ['passed' => true, 'message' => '', 'recommendations' => []];
        
        switch ($rule['rule_type']) {
            case 'socket':
                $result = $this->checkSocketCompatibility($rule, $comp1Details, $comp2Details);
                break;
                
            case 'memory':
                $result = $this->checkMemoryCompatibility($rule, $comp1Details, $comp2Details);
                break;
                
            case 'interface':
                $result = $this->checkInterfaceCompatibility($rule, $comp1Details, $comp2Details);
                break;
                
            case 'power':
                $result = $this->checkPowerCompatibility($rule, $comp1Details, $comp2Details);
                break;
                
            default:
                $result = $this->checkCustomRule($rule, $comp1Details, $comp2Details);
                break;
        }
        
        return $result;
    }
    
    /**
     * Check CPU-Motherboard socket compatibility
     */
    private function checkSocketCompatibility($rule, $comp1Details, $comp2Details) {
        $result = ['passed' => true, 'message' => ''];
        
        // Determine which is CPU and which is motherboard
        $cpu = null;
        $motherboard = null;
        
        if ($comp1Details['component_type'] === 'cpu') {
            $cpu = $comp1Details;
            $motherboard = $comp2Details;
        } elseif ($comp2Details['component_type'] === 'cpu') {
            $cpu = $comp2Details;
            $motherboard = $comp1Details;
        }
        
        if ($cpu && $motherboard) {
            $cpuSocket = $cpu['socket_type'] ?? $this->extractSocketFromJSON($cpu);
            $mbSocket = $motherboard['socket_type'] ?? $this->extractSocketFromJSON($motherboard);
            
            if ($cpuSocket && $mbSocket && $cpuSocket !== $mbSocket) {
                $result['passed'] = false;
                $result['message'] = "Socket mismatch: CPU ({$cpuSocket}) does not match Motherboard ({$mbSocket})";
            }
        }
        
        return $result;
    }
    
    /**
     * Check memory compatibility
     */
    private function checkMemoryCompatibility($rule, $comp1Details, $comp2Details) {
        $result = ['passed' => true, 'message' => '', 'recommendations' => []];
        
        // Memory type compatibility
        $memoryComponent = null;
        $systemComponent = null;
        
        if ($comp1Details['component_type'] === 'ram') {
            $memoryComponent = $comp1Details;
            $systemComponent = $comp2Details;
        } elseif ($comp2Details['component_type'] === 'ram') {
            $memoryComponent = $comp2Details;
            $systemComponent = $comp1Details;
        }
        
        if ($memoryComponent && $systemComponent) {
            $memoryType = $memoryComponent['memory_type'] ?? $this->extractMemoryTypeFromJSON($memoryComponent);
            $supportedTypes = $systemComponent['memory_types'] ?? $this->extractSupportedMemoryTypes($systemComponent);
            
            if ($memoryType && $supportedTypes) {
                $supportedTypesArray = explode(',', $supportedTypes);
                $supportedTypesArray = array_map('trim', $supportedTypesArray);
                
                if (!in_array($memoryType, $supportedTypesArray)) {
                    $result['passed'] = false;
                    $result['message'] = "Memory type {$memoryType} not supported. Supported types: " . implode(', ', $supportedTypesArray);
                }
            }
            
            // Memory speed compatibility
            $memorySpeed = $memoryComponent['memory_speed'] ?? $this->extractMemorySpeedFromJSON($memoryComponent);
            $maxSpeed = $systemComponent['max_memory_speed'] ?? $this->extractMaxMemorySpeed($systemComponent);
            
            if ($memorySpeed && $maxSpeed && $memorySpeed > $maxSpeed) {
                $result['recommendations'][] = "Memory speed ({$memorySpeed} MHz) exceeds system maximum ({$maxSpeed} MHz). Will run at reduced speed.";
            }
        }
        
        return $result;
    }
    
    /**
     * Check storage interface compatibility
     */
    private function checkInterfaceCompatibility($rule, $comp1Details, $comp2Details) {
        $result = ['passed' => true, 'message' => ''];
        
        $storageComponent = null;
        $systemComponent = null;
        
        if ($comp1Details['component_type'] === 'storage') {
            $storageComponent = $comp1Details;
            $systemComponent = $comp2Details;
        } elseif ($comp2Details['component_type'] === 'storage') {
            $storageComponent = $comp2Details;
            $systemComponent = $comp1Details;
        }
        
        if ($storageComponent && $systemComponent) {
            $storageInterface = $storageComponent['interface_type'] ?? $this->extractStorageInterface($storageComponent);
            $availableInterfaces = $systemComponent['storage_interfaces'] ?? $this->extractAvailableInterfaces($systemComponent);
            
            if ($storageInterface && $availableInterfaces) {
                $availableInterfacesArray = explode(',', $availableInterfaces);
                $availableInterfacesArray = array_map('trim', $availableInterfacesArray);
                
                if (!in_array($storageInterface, $availableInterfacesArray)) {
                    $result['passed'] = false;
                    $result['message'] = "Storage interface {$storageInterface} not available. Available interfaces: " . implode(', ', $availableInterfacesArray);
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Check power compatibility
     */
    private function checkPowerCompatibility($rule, $comp1Details, $comp2Details) {
        $result = ['passed' => true, 'message' => ''];
        
        // This is a simplified power check - in real implementation,
        // you'd want more sophisticated power calculation
        $power1 = $comp1Details['power_consumption_watts'] ?? $comp1Details['tdp_watts'] ?? 0;
        $power2 = $comp2Details['power_consumption_watts'] ?? $comp2Details['tdp_watts'] ?? 0;
        
        $totalPower = $power1 + $power2;
        
        // Example: warn if total power exceeds 500W (you'd have proper PSU checking here)
        if ($totalPower > 500) {
            $result['recommendations'][] = "High power consumption detected ({$totalPower}W). Ensure adequate PSU capacity.";
        }
        
        return $result;
    }
    
    /**
     * Check custom compatibility rules
     */
    private function checkCustomRule($rule, $comp1Details, $comp2Details) {
        $result = ['passed' => true, 'message' => ''];
        
        // Implement custom rule logic based on rule definition
        if (isset($rule['rule_definition']['custom_function'])) {
            $functionName = $rule['rule_definition']['custom_function'];
            if (method_exists($this, $functionName)) {
                $result = $this->$functionName($rule, $comp1Details, $comp2Details);
            }
        }
        
        return $result;
    }
    
    /**
     * Get component details from database
     */
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
            $stmt = $this->pdo->prepare("SELECT * FROM {$tableMap[$componentType]} WHERE UUID = ?");
            $stmt->execute([$uuid]);
            $component = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($component) {
                $component['component_type'] = $componentType;
            }
            
            return $component;
        } catch (PDOException $e) {
            error_log("Error getting component details: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all components of a specific type
     */
    private function getComponentsByType($componentType, $availableOnly = true) {
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
                $sql .= " WHERE Status = 1"; // Available status
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting components by type: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if a rule applies to the given component types
     */
    private function ruleApplies($rule, $type1, $type2) {
        $ruleTypes = $rule['component_types'];
        return (in_array($type1, $ruleTypes) && in_array($type2, $ruleTypes)) ||
               (in_array($type2, $ruleTypes) && in_array($type1, $ruleTypes));
    }
    
    /**
     * Parse server configuration into component arrays
     */
    private function parseServerConfiguration($configuration) {
        $components = [
            'cpu' => null,
            'motherboard' => null,
            'ram' => [],
            'storage' => [],
            'nic' => [],
            'caddy' => []
        ];
        
        // Parse CPU
        if (!empty($configuration['cpu_uuid'])) {
            $components['cpu'] = $this->getComponentDetails('cpu', $configuration['cpu_uuid']);
        }
        
        // Parse Motherboard
        if (!empty($configuration['motherboard_uuid'])) {
            $components['motherboard'] = $this->getComponentDetails('motherboard', $configuration['motherboard_uuid']);
        }
        
        // Parse RAM configuration
        if (!empty($configuration['ram_configuration'])) {
            $ramConfig = json_decode($configuration['ram_configuration'], true);
            if (is_array($ramConfig)) {
                foreach ($ramConfig as $ram) {
                    if (!empty($ram['uuid'])) {
                        $ramDetails = $this->getComponentDetails('ram', $ram['uuid']);
                        if ($ramDetails) {
                            $ramDetails['quantity'] = $ram['quantity'] ?? 1;
                            $components['ram'][] = $ramDetails;
                        }
                    }
                }
            }
        }
        
        // Parse Storage configuration
        if (!empty($configuration['storage_configuration'])) {
            $storageConfig = json_decode($configuration['storage_configuration'], true);
            if (is_array($storageConfig)) {
                foreach ($storageConfig as $storage) {
                    if (!empty($storage['uuid'])) {
                        $storageDetails = $this->getComponentDetails('storage', $storage['uuid']);
                        if ($storageDetails) {
                            $components['storage'][] = $storageDetails;
                        }
                    }
                }
            }
        }
        
        // Parse NIC configuration
        if (!empty($configuration['nic_configuration'])) {
            $nicConfig = json_decode($configuration['nic_configuration'], true);
            if (is_array($nicConfig)) {
                foreach ($nicConfig as $nic) {
                    if (!empty($nic['uuid'])) {
                        $nicDetails = $this->getComponentDetails('nic', $nic['uuid']);
                        if ($nicDetails) {
                            $components['nic'][] = $nicDetails;
                        }
                    }
                }
            }
        }
        
        // Parse Caddy configuration
        if (!empty($configuration['caddy_configuration'])) {
            $caddyConfig = json_decode($configuration['caddy_configuration'], true);
            if (is_array($caddyConfig)) {
                foreach ($caddyConfig as $caddy) {
                    if (!empty($caddy['uuid'])) {
                        $caddyDetails = $this->getComponentDetails('caddy', $caddy['uuid']);
                        if ($caddyDetails) {
                            $components['caddy'][] = $caddyDetails;
                        }
                    }
                }
            }
        }
        
        return $components;
    }
    
    /**
     * Perform global system checks
     */
    private function performGlobalChecks($components) {
        $checks = [];
        
        // Check if essential components are present
        if (!$components['cpu']) {
            $checks[] = [
                'check' => 'CPU Required',
                'passed' => false,
                'message' => 'CPU is required for server configuration'
            ];
        }
        
        if (!$components['motherboard']) {
            $checks[] = [
                'check' => 'Motherboard Required',
                'passed' => false,
                'message' => 'Motherboard is required for server configuration'
            ];
        }
        
        if (empty($components['ram'])) {
            $checks[] = [
                'check' => 'Memory Required',
                'passed' => false,
                'message' => 'At least one RAM module is required'
            ];
        }
        
        if (empty($components['storage'])) {
            $checks[] = [
                'check' => 'Storage Required',
                'passed' => false,
                'message' => 'At least one storage device is required'
            ];
        }
        
        // Check memory slot usage
        if ($components['motherboard'] && !empty($components['ram'])) {
            $totalSlots = $components['motherboard']['memory_slots'] ?? 4;
            $usedSlots = count($components['ram']);
            
            $checks[] = [
                'check' => 'Memory Slot Usage',
                'passed' => $usedSlots <= $totalSlots,
                'message' => "Using {$usedSlots} of {$totalSlots} memory slots",
                'details' => ['used_slots' => $usedSlots, 'total_slots' => $totalSlots]
            ];
        }
        
        return $checks;
    }
    
    /**
     * Calculate total power consumption
     */
    private function calculatePowerConsumption($components) {
        $totalPower = 0;
        
        // CPU power
        if ($components['cpu']) {
            $totalPower += $components['cpu']['tdp_watts'] ?? 0;
        }
        
        // Motherboard base power (estimate)
        if ($components['motherboard']) {
            $totalPower += 50; // Base motherboard consumption
        }
        
        // RAM power
        foreach ($components['ram'] as $ram) {
            $ramPower = $ram['power_consumption_watts'] ?? 8; // Default 8W per module
            $quantity = $ram['quantity'] ?? 1;
            $totalPower += $ramPower * $quantity;
        }
        
        // Storage power
        foreach ($components['storage'] as $storage) {
            $totalPower += $storage['power_consumption_watts'] ?? 10;
        }
        
        // NIC power
        foreach ($components['nic'] as $nic) {
            $totalPower += $nic['power_consumption_watts'] ?? 15;
        }
        
        return $totalPower;
    }
    
    /**
     * Calculate total estimated cost
     */
    private function calculateTotalCost($components) {
        // This would integrate with your pricing system
        // For now, return 0 as placeholder
        return 0.0;
    }
    
    /**
     * Extract socket type from JSON data
     */
    private function extractSocketFromJSON($component) {
        if (!empty($component['Notes'])) {
            // Try to extract socket from Notes field
            if (preg_match('/socket[:\s]+([A-Z0-9]+)/i', $component['Notes'], $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    /**
     * Extract memory type from JSON data
     */
    private function extractMemoryTypeFromJSON($component) {
        if (!empty($component['Notes'])) {
            if (preg_match('/DDR[345]/i', $component['Notes'], $matches)) {
                return strtoupper($matches[0]);
            }
        }
        return null;
    }
    
    /**
     * Extract supported memory types
     */
    private function extractSupportedMemoryTypes($component) {
        if (!empty($component['Notes'])) {
            if (preg_match_all('/DDR[345]/i', $component['Notes'], $matches)) {
                return implode(',', array_unique($matches[0]));
            }
        }
        return null;
    }
    
    /**
     * Extract memory speed from JSON data
     */
    private function extractMemorySpeedFromJSON($component) {
        if (!empty($component['Notes'])) {
            if (preg_match('/(\d{4,5})\s*MHz/i', $component['Notes'], $matches)) {
                return intval($matches[1]);
            }
        }
        return null;
    }
    
    /**
     * Extract maximum memory speed
     */
    private function extractMaxMemorySpeed($component) {
        if (!empty($component['Notes'])) {
            if (preg_match('/max.*?(\d{4,5})\s*MHz/i', $component['Notes'], $matches)) {
                return intval($matches[1]);
            }
        }
        return null;
    }
    
    /**
     * Extract storage interface from component data
     */
    private function extractStorageInterface($component) {
        if (!empty($component['Notes'])) {
            if (preg_match('/(SATA|NVMe|SAS|IDE)/i', $component['Notes'], $matches)) {
                return strtoupper($matches[1]);
            }
        }
        return null;
    }
    
    /**
     * Extract available interfaces from motherboard
     */
    private function extractAvailableInterfaces($component) {
        if (!empty($component['Notes'])) {
            $interfaces = [];
            if (preg_match_all('/(SATA|NVMe|SAS|IDE)/i', $component['Notes'], $matches)) {
                return implode(',', array_unique($matches[0]));
            }
        }
        return null;
    }
    
    /**
     * Log compatibility check operation
     */
    private function logCompatibilityCheck($component1, $component2, $result, $executionTime) {
        if (!$this->debugMode) {
            return; // Skip logging in production unless debug mode is on
        }
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO compatibility_log 
                (session_id, operation_type, component_type_1, component_uuid_1, 
                 component_type_2, component_uuid_2, compatibility_result, 
                 applied_rules, execution_time_ms, user_id, created_at)
                VALUES (?, 'compatibility_check', ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $appliedRules = json_encode($result['applied_rules']);
            $userId = $_SESSION['user_id'] ?? null;
            
            $stmt->execute([
                $this->sessionId,
                $component1['type'],
                $component1['uuid'],
                $component2['type'],
                $component2['uuid'],
                $result['compatible'] ? 1 : 0,
                $appliedRules,
                round($executionTime),
                $userId
            ]);
        } catch (PDOException $e) {
            error_log("Failed to log compatibility check: " . $e->getMessage());
        }
    }
    
    /**
     * Get recommended next components for a configuration
     */
    public function getRecommendedNextComponents($configUuid) {
        try {
            // Get current configuration components
            $stmt = $this->pdo->prepare("
                SELECT component_type, component_uuid, quantity 
                FROM server_configuration_components 
                WHERE config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $currentComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $recommendations = [];
            $hasComponents = array_column($currentComponents, 'component_type');
            
            // Recommend missing essential components first
            $essentialComponents = ['cpu', 'motherboard', 'ram', 'storage'];
            foreach ($essentialComponents as $componentType) {
                if (!in_array($componentType, $hasComponents)) {
                    $recommendations[] = [
                        'component_type' => $componentType,
                        'priority' => 'high',
                        'reason' => ucfirst($componentType) . ' is required for a functional server',
                        'compatible_options' => $this->getComponentsByType($componentType, true)
                    ];
                }
            }
            
            // Recommend optional components
            $optionalComponents = ['nic', 'caddy'];
            foreach ($optionalComponents as $componentType) {
                if (!in_array($componentType, $hasComponents)) {
                    $recommendations[] = [
                        'component_type' => $componentType,
                        'priority' => 'medium',
                        'reason' => ucfirst($componentType) . ' would enhance server functionality',
                        'compatible_options' => $this->getComponentsByType($componentType, true)
                    ];
                }
            }
            
            return $recommendations;
            
        } catch (Exception $e) {
            error_log("Error getting recommended next components: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get compatible components for a specific configuration and component type
     */
    public function getCompatibleComponentsForConfiguration($configUuid, $componentType, $availableOnly = true) {
        try {
            // Get current configuration components
            $stmt = $this->pdo->prepare("
                SELECT component_type, component_uuid 
                FROM server_configuration_components 
                WHERE config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $currentComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get all available components of the requested type
            $availableComponents = $this->getComponentsByType($componentType, $availableOnly);
            $compatibleComponents = [];
            
            if (empty($currentComponents)) {
                // If no components in configuration yet, all components are compatible
                return $availableComponents;
            }
            
            // Check compatibility with existing components
            foreach ($availableComponents as $component) {
                $isCompatible = true;
                $compatibilityIssues = [];
                
                $targetComponent = ['type' => $componentType, 'uuid' => $component['UUID']];
                
                // Check against each existing component
                foreach ($currentComponents as $existing) {
                    $existingComponent = ['type' => $existing['component_type'], 'uuid' => $existing['component_uuid']];
                    $compatibility = $this->checkCompatibility($existingComponent, $targetComponent);
                    
                    if (!$compatibility['compatible']) {
                        $isCompatible = false;
                        $compatibilityIssues = array_merge($compatibilityIssues, $compatibility['failures']);
                    }
                }
                
                if ($isCompatible) {
                    $component['compatibility_score'] = 1.0;
                    $component['compatibility_issues'] = [];
                    $compatibleComponents[] = $component;
                } else {
                    // Include incompatible components with issues for informational purposes
                    $component['compatibility_score'] = 0.0;
                    $component['compatibility_issues'] = $compatibilityIssues;
                    // Uncomment next line if you want to include incompatible components
                    // $compatibleComponents[] = $component;
                }
            }
            
            // Sort by compatibility score (descending)
            usort($compatibleComponents, function($a, $b) {
                return ($b['compatibility_score'] ?? 0) <=> ($a['compatibility_score'] ?? 0);
            });
            
            return $compatibleComponents;
            
        } catch (Exception $e) {
            error_log("Error getting compatible components for configuration: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get compatibility statistics
     */
    public function getCompatibilityStatistics($timeframe = '24 HOUR') {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_checks,
                    SUM(CASE WHEN compatibility_result = 1 THEN 1 ELSE 0 END) as successful_checks,
                    AVG(execution_time_ms) as avg_execution_time,
                    MAX(execution_time_ms) as max_execution_time
                FROM compatibility_log 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? {$timeframe})
            ");
            $stmt->execute([1]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Failed to get compatibility statistics: " . $e->getMessage());
            return null;
        }
    }
}
?>