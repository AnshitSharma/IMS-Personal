<?php
/**
 * Infrastructure Management System - Compatibility Engine
 * File: includes/models/CompatibilityEngine.php
 * 
 * Core compatibility checking and validation engine for server components
 */

require_once __DIR__ . '/ComponentDataService.php';

class CompatibilityEngine {
    private $pdo;
    private $componentRules;
    private $sessionId;
    private $debugMode;
    private $dataService;
    
    public function __construct($pdo, $debugMode = false) {
        $this->pdo = $pdo;
        $this->debugMode = $debugMode;
        $this->sessionId = uniqid('compat_', true);
        $this->dataService = ComponentDataService::getInstance();
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
     * Check CPU-Motherboard socket compatibility using JSON specifications
     */
    private function checkSocketCompatibility($rule, $comp1Details, $comp2Details) {
        $result = ['passed' => true, 'message' => '', 'recommendations' => []];
        
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
            // Get socket from JSON specifications (new enhanced format)
            $cpuSocket = $cpu['socket'] ?? null;
            $mbSocketType = $motherboard['socket_type'] ?? null;
            
            // Fallback to legacy format
            if (!$mbSocketType && isset($motherboard['socket'])) {
                if (is_array($motherboard['socket'])) {
                    $mbSocketType = $motherboard['socket']['type'] ?? null;
                } else {
                    $mbSocketType = $motherboard['socket'];
                }
            }
            
            if ($cpuSocket && $mbSocketType) {
                // Normalize socket types for comparison
                $cpuSocketNormalized = $this->normalizeSocketType($cpuSocket);
                $mbSocketNormalized = $this->normalizeSocketType($mbSocketType);
                
                if ($cpuSocketNormalized !== $mbSocketNormalized) {
                    $result['passed'] = false;
                    $result['message'] = "Socket mismatch: CPU socket ({$cpuSocket}) is not compatible with Motherboard socket ({$mbSocketType})";
                    
                    // Add recommendations for compatible components
                    $result['recommendations'][] = "Look for CPUs with {$mbSocketType} socket or motherboards with {$cpuSocket} socket";
                } else {
                    $result['message'] = "Socket compatibility confirmed: {$cpuSocket}";
                    
                    // Check socket count for multi-socket motherboards
                    $socketCount = $motherboard['socket_count'] ?? 1;
                    if ($socketCount > 1) {
                        $result['recommendations'][] = "This motherboard supports {$socketCount} CPUs - consider adding additional processors";
                    }
                }
            } else {
                // If socket information is missing, check fallback from Notes
                $cpuSocketFallback = $this->extractSocketFromNotes($cpu);
                $mbSocketFallback = $this->extractSocketFromNotes($motherboard);
                
                if ($cpuSocketFallback && $mbSocketFallback) {
                    $cpuSocketNormalized = $this->normalizeSocketType($cpuSocketFallback);
                    $mbSocketNormalized = $this->normalizeSocketType($mbSocketFallback);
                    
                    if ($cpuSocketNormalized !== $mbSocketNormalized) {
                        $result['passed'] = false;
                        $result['message'] = "Socket mismatch (parsed from notes): CPU ({$cpuSocketFallback}) does not match Motherboard ({$mbSocketFallback})";
                    } else {
                        $result['message'] = "Socket compatibility confirmed (parsed from notes): {$cpuSocketFallback}";
                    }
                } else {
                    $result['recommendations'][] = "Unable to determine socket compatibility - verify component specifications manually";
                    
                    // Log for debugging
                    error_log("Socket compatibility check: Missing socket information for CPU UUID {$cpu['UUID']} or Motherboard UUID {$motherboard['UUID']}");
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Check memory compatibility
     */
    private function checkMemoryCompatibility($rule, $comp1Details, $comp2Details) {
        $result = ['passed' => true, 'message' => '', 'recommendations' => []];
        
        // Determine memory component and system component (CPU or Motherboard)
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
            // Get memory specifications from JSON
            $ramMemoryType = $memoryComponent['memory_type'] ?? null;
            $ramFrequency = $memoryComponent['frequency_mhz'] ?? null;
            $ramCapacity = $memoryComponent['capacity_gb'] ?? null;
            
            // Get system memory support from JSON
            $systemMemoryInfo = null;
            if ($systemComponent['component_type'] === 'motherboard') {
                $systemMemoryInfo = $systemComponent['memory'] ?? [];
            } elseif ($systemComponent['component_type'] === 'cpu') {
                $systemMemoryInfo = [
                    'type' => $systemComponent['memory_types'][0] ?? null,
                    'max_frequency_MHz' => $this->extractMaxMemoryFrequencyFromCPU($systemComponent),
                    'max_capacity_TB' => $systemComponent['max_memory_capacity_tb'] ?? null
                ];
            }
            
            if ($systemMemoryInfo) {
                // Check memory type compatibility
                $supportedType = $systemMemoryInfo['type'] ?? null;
                if ($ramMemoryType && $supportedType) {
                    if (strtoupper($ramMemoryType) !== strtoupper($supportedType)) {
                        $result['passed'] = false;
                        $result['message'] = "Memory type mismatch: RAM is {$ramMemoryType} but system supports {$supportedType}";
                        return $result;
                    } else {
                        $result['message'] = "Memory type compatibility confirmed: {$ramMemoryType}";
                    }
                }
                
                // Check memory speed compatibility
                $maxSupportedSpeed = $systemMemoryInfo['max_frequency_MHz'] ?? null;
                if ($ramFrequency && $maxSupportedSpeed) {
                    if ($ramFrequency > $maxSupportedSpeed) {
                        $result['recommendations'][] = "RAM frequency ({$ramFrequency} MHz) exceeds maximum supported ({$maxSupportedSpeed} MHz). Memory will run at reduced speed.";
                    } elseif ($ramFrequency < ($maxSupportedSpeed * 0.7)) {
                        $result['recommendations'][] = "RAM frequency ({$ramFrequency} MHz) is significantly lower than maximum supported ({$maxSupportedSpeed} MHz). Consider higher speed memory for better performance.";
                    }
                }
                
                // Check capacity limits for motherboard
                if ($systemComponent['component_type'] === 'motherboard') {
                    $totalSlots = $systemMemoryInfo['slots'] ?? 4;
                    $maxCapacityTB = $systemMemoryInfo['max_capacity_TB'] ?? null;
                    
                    if ($maxCapacityTB && $ramCapacity) {
                        $maxCapacityGB = $maxCapacityTB * 1024;
                        if ($ramCapacity > ($maxCapacityGB / $totalSlots)) {
                            $result['recommendations'][] = "Single module capacity ({$ramCapacity}GB) may exceed per-slot limit for optimal configuration";
                        }
                    }
                    
                    $result['recommendations'][] = "Motherboard has {$totalSlots} memory slots available";
                }
                
                // ECC support check
                if (isset($systemMemoryInfo['ecc_support']) && $systemMemoryInfo['ecc_support']) {
                    $ramFeatures = $memoryComponent['features'] ?? [];
                    if (is_array($ramFeatures) && !in_array('ECC', $ramFeatures)) {
                        $result['recommendations'][] = "System supports ECC memory - consider ECC modules for error correction";
                    }
                }
            } else {
                // Fallback to notes parsing
                $ramTypeFromNotes = $this->extractMemoryTypeFromNotes($memoryComponent['Notes'] ?? '');
                $systemTypeFromNotes = $this->extractMemoryTypeFromNotes($systemComponent['Notes'] ?? '');
                
                if ($ramTypeFromNotes && $systemTypeFromNotes && $ramTypeFromNotes !== $systemTypeFromNotes) {
                    $result['passed'] = false;
                    $result['message'] = "Memory type mismatch (parsed from notes): RAM is {$ramTypeFromNotes} but system supports {$systemTypeFromNotes}";
                }
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
     * Get component details from database merged with JSON specifications
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
            // Get database record
            $stmt = $this->pdo->prepare("SELECT * FROM {$tableMap[$componentType]} WHERE UUID = ?");
            $stmt->execute([$uuid]);
            $databaseRecord = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$databaseRecord) {
                return null;
            }
            
            // Get JSON specifications using enhanced method with database record
            $jsonSpecs = $this->dataService->getComponentSpecifications($componentType, $uuid, $databaseRecord);
            
            // Start with database record as base
            $component = $databaseRecord;
            
            // Merge with JSON specifications if available
            if ($jsonSpecs) {
                $component = array_merge($component, $jsonSpecs);
            }
            
            $component['component_type'] = $componentType;
            
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
     * Extract socket type from notes field
     */
    private function extractSocketFromNotes($component) {
        $notes = $component['Notes'] ?? '';
        if (!empty($notes)) {
            // Try to extract socket from Notes field with various patterns
            if (preg_match('/socket[:\s]*([A-Z0-9\s]+)/i', $notes, $matches)) {
                return trim($matches[1]);
            }
            // Try LGA pattern
            if (preg_match('/(LGA\s*\d+)/i', $notes, $matches)) {
                return trim($matches[1]);
            }
            // Try AMD socket patterns
            if (preg_match('/(AM[45]\+?|TR4|sTRX4)/i', $notes, $matches)) {
                return trim($matches[1]);
            }
        }
        return null;
    }
    
    /**
     * Normalize socket types for comparison
     */
    private function normalizeSocketType($socket) {
        if (!$socket) return null;
        
        // Remove spaces and convert to uppercase
        $normalized = strtoupper(preg_replace('/\s+/', '', $socket));
        
        // Handle common variations
        $replacements = [
            'LGA4189' => 'LGA4189',
            'LGA 4189' => 'LGA4189', 
            'LGA-4189' => 'LGA4189',
            'AM4+' => 'AM4PLUS',
            'AM4 PLUS' => 'AM4PLUS'
        ];
        
        return $replacements[$normalized] ?? $normalized;
    }
    
    /**
     * Extract maximum memory frequency from CPU specs
     */
    private function extractMaxMemoryFrequencyFromCPU($cpu) {
        // Get from memory_types array if available
        $memoryTypes = $cpu['memory_types'] ?? [];
        if (is_array($memoryTypes) && !empty($memoryTypes)) {
            foreach ($memoryTypes as $memType) {
                // Extract frequency from strings like "DDR5-4800"
                if (preg_match('/DDR[345]-(\d+)/i', $memType, $matches)) {
                    return intval($matches[1]);
                }
            }
        }
        
        // Fallback to notes parsing
        $notes = $cpu['Notes'] ?? '';
        if (preg_match('/DDR[345]-(\d+)/i', $notes, $matches)) {
            return intval($matches[1]);
        }
        
        return null;
    }
    
    /**
     * Extract memory type from notes with improved patterns
     */
    private function extractMemoryTypeFromNotes($notes) {
        if (preg_match('/DDR[345]/i', $notes, $matches)) {
            return strtoupper($matches[0]);
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
    
    /**
     * Get compatible components for a specific component using JSON specs
     */
    public function getCompatibleComponentsFor($componentType, $componentUuid) {
        try {
            $baseComponent = ['type' => $componentType, 'uuid' => $componentUuid];
            
            // Define which component types to check against
            $targetTypes = $this->getTargetCompatibilityTypes($componentType);
            
            $result = [
                'base_component' => $baseComponent,
                'compatible_components' => [],
                'compatibility_engine_available' => true
            ];
            
            foreach ($targetTypes as $targetType) {
                $compatibleComponents = $this->getCompatibleComponents($baseComponent, $targetType);
                if (!empty($compatibleComponents)) {
                    $result['compatible_components'][$targetType] = $compatibleComponents;
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Error getting compatible components for {$componentType} {$componentUuid}: " . $e->getMessage());
            return [
                'base_component' => $baseComponent ?? null,
                'compatible_components' => [],
                'compatibility_engine_available' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get target component types for compatibility checking
     */
    private function getTargetCompatibilityTypes($componentType) {
        $compatibilityMap = [
            'cpu' => ['motherboard'],
            'motherboard' => ['cpu', 'ram', 'storage'],
            'ram' => ['motherboard', 'cpu'],
            'storage' => ['motherboard'],
            'nic' => ['motherboard'],
            'caddy' => ['storage']
        ];
        
        return $compatibilityMap[$componentType] ?? [];
    }
    
    /**
     * Enhanced component validation with JSON specs
     */
    public function validateComponentForConfiguration($configUuid, $componentType, $componentUuid) {
        try {
            // Get configuration components
            $stmt = $this->pdo->prepare("
                SELECT component_type, component_uuid 
                FROM server_configuration_components 
                WHERE config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $configComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $targetComponent = ['type' => $componentType, 'uuid' => $componentUuid];
            $validationResult = [
                'valid' => true,
                'compatibility_score' => 1.0,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'component_checks' => []
            ];
            
            // Check against each existing component
            foreach ($configComponents as $existingComp) {
                $existingComponent = ['type' => $existingComp['component_type'], 'uuid' => $existingComp['component_uuid']];
                
                $compatibility = $this->checkCompatibility($existingComponent, $targetComponent);
                
                $checkResult = [
                    'existing_component' => $existingComponent,
                    'compatible' => $compatibility['compatible'],
                    'score' => $compatibility['compatibility_score'],
                    'issues' => $compatibility['failures'],
                    'warnings' => $compatibility['warnings']
                ];
                
                $validationResult['component_checks'][] = $checkResult;
                
                if (!$compatibility['compatible']) {
                    $validationResult['valid'] = false;
                    $validationResult['issues'] = array_merge($validationResult['issues'], $compatibility['failures']);
                }
                
                if (!empty($compatibility['warnings'])) {
                    $validationResult['warnings'] = array_merge($validationResult['warnings'], $compatibility['warnings']);
                }
                
                if (!empty($compatibility['recommendations'])) {
                    $validationResult['recommendations'] = array_merge($validationResult['recommendations'], $compatibility['recommendations']);
                }
                
                $validationResult['compatibility_score'] *= $compatibility['compatibility_score'];
            }
            
            return $validationResult;
            
        } catch (Exception $e) {
            error_log("Error validating component for configuration: " . $e->getMessage());
            return [
                'valid' => false,
                'compatibility_score' => 0.0,
                'issues' => ['Validation system error: ' . $e->getMessage()],
                'warnings' => [],
                'recommendations' => [],
                'component_checks' => []
            ];
        }
    }
    
    /**
     * Get detailed component information with JSON specs
     */
    public function getDetailedComponentInfo($componentType, $componentUuid) {
        try {
            $componentDetails = $this->getComponentDetails($componentType, $componentUuid);
            
            if (!$componentDetails) {
                return null;
            }
            
            // Get JSON specifications
            $jsonSpecs = $this->dataService->getComponentSpecifications($componentType, $componentUuid, $componentDetails);
            
            return [
                'database_info' => $componentDetails,
                'json_specifications' => $jsonSpecs,
                'compatibility_ready' => !empty($jsonSpecs),
                'match_confidence' => $jsonSpecs['match_confidence'] ?? 1.0,
                'matched_by' => $jsonSpecs['matched_by'] ?? 'direct_match'
            ];
            
        } catch (Exception $e) {
            error_log("Error getting detailed component info: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Enhanced power calculation with detailed breakdown
     */
    public function calculateDetailedPowerConsumption($components) {
        $powerBreakdown = [
            'components' => [],
            'total_base_power' => 0,
            'total_max_power' => 0,
            'estimated_psu_requirement' => 0,
            'efficiency_rating' => 'Unknown'
        ];
        
        foreach ($components as $componentType => $componentList) {
            if ($componentType === 'cpu' && $componentList) {
                $cpuPower = $componentList['tdp_w'] ?? 0;
                $powerBreakdown['components']['cpu'] = $cpuPower;
                $powerBreakdown['total_base_power'] += $cpuPower;
                $powerBreakdown['total_max_power'] += $cpuPower * 1.2; // Peak power
            } elseif ($componentType === 'ram' && is_array($componentList)) {
                $ramPower = count($componentList) * 8; // 8W per module
                $powerBreakdown['components']['ram'] = $ramPower;
                $powerBreakdown['total_base_power'] += $ramPower;
                $powerBreakdown['total_max_power'] += $ramPower;
            } elseif ($componentType === 'storage' && is_array($componentList)) {
                $storagePower = count($componentList) * 10; // 10W per device
                $powerBreakdown['components']['storage'] = $storagePower;
                $powerBreakdown['total_base_power'] += $storagePower;
                $powerBreakdown['total_max_power'] += $storagePower;
            } elseif ($componentType === 'motherboard' && $componentList) {
                $mbPower = 50; // Base motherboard power
                $powerBreakdown['components']['motherboard'] = $mbPower;
                $powerBreakdown['total_base_power'] += $mbPower;
                $powerBreakdown['total_max_power'] += $mbPower;
            }
        }
        
        // Calculate PSU requirement (add 20% headroom)
        $powerBreakdown['estimated_psu_requirement'] = $powerBreakdown['total_max_power'] * 1.2;
        
        // Recommend efficiency rating
        if ($powerBreakdown['total_max_power'] > 600) {
            $powerBreakdown['efficiency_rating'] = '80+ Gold or higher';
        } elseif ($powerBreakdown['total_max_power'] > 400) {
            $powerBreakdown['efficiency_rating'] = '80+ Bronze or higher';
        } else {
            $powerBreakdown['efficiency_rating'] = '80+ Standard or higher';
        }
        
        return $powerBreakdown;
    }
}
?>