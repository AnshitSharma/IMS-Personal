<?php
/**
 * Infrastructure Management System - Component Compatibility Rules
 * File: includes/models/ComponentCompatibility.php
 * 
 * Component-specific compatibility checking and validation
 */

class ComponentCompatibility {
    private $pdo;
    private $jsonDataCache = [];
    

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Check compatibility between two specific components
     */
    public function checkComponentPairCompatibility($component1, $component2) {
        $type1 = $component1['type'];
        $type2 = $component2['type'];
        
        // Determine compatibility check method based on component types
        $compatibilityMethod = $this->getCompatibilityMethod($type1, $type2);
        
        if ($compatibilityMethod) {
            return $this->$compatibilityMethod($component1, $component2);
        }
        
        // Default compatibility if no specific rules
        return [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];
    }
    
    /**
     * Get appropriate compatibility check method
     */
    private function getCompatibilityMethod($type1, $type2) {
        $compatibilityMap = [
            'cpu-motherboard' => 'checkCPUMotherboardCompatibility',
            'motherboard-cpu' => 'checkCPUMotherboardCompatibility',
            'motherboard-ram' => 'checkMotherboardRAMCompatibility',
            'ram-motherboard' => 'checkMotherboardRAMCompatibility',
            'cpu-ram' => 'checkCPURAMCompatibility',
            'ram-cpu' => 'checkCPURAMCompatibility',
            'motherboard-storage' => 'checkMotherboardStorageCompatibility',
            'storage-motherboard' => 'checkMotherboardStorageCompatibility',
            'motherboard-nic' => 'checkMotherboardNICCompatibility',
            'nic-motherboard' => 'checkMotherboardNICCompatibility',
            'storage-caddy' => 'checkStorageCaddyCompatibility',
            'caddy-storage' => 'checkStorageCaddyCompatibility'
        ];
        
        $key = "$type1-$type2";
        return $compatibilityMap[$key] ?? null;
    }
    
    /**
     * Check CPU-Motherboard compatibility
     */
    private function checkCPUMotherboardCompatibility($component1, $component2) {
        $cpu = $component1['type'] === 'cpu' ? $component1 : $component2;
        $motherboard = $component1['type'] === 'motherboard' ? $component1 : $component2;
        
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];
        
        try {
            $cpuData = $this->getComponentData($cpu['type'], $cpu['uuid']);
            $motherboardData = $this->getComponentData($motherboard['type'], $motherboard['uuid']);
            
            // Socket compatibility check
            $cpuSocket = $this->extractSocketType($cpuData, 'cpu');
            $motherboardSocket = $this->extractSocketType($motherboardData, 'motherboard');
            
            if ($cpuSocket && $motherboardSocket && $cpuSocket !== $motherboardSocket) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "Socket mismatch: CPU socket ($cpuSocket) does not match motherboard socket ($motherboardSocket)";
                return $result;
            }
            
            // TDP compatibility check
            $cpuTDP = $this->extractTDP($cpuData);
            $motherboardMaxTDP = $this->extractMaxTDP($motherboardData);
            
            if ($cpuTDP && $motherboardMaxTDP && $cpuTDP > $motherboardMaxTDP) {
                $result['compatibility_score'] *= 0.7;
                $result['warnings'][] = "CPU TDP ({$cpuTDP}W) may exceed motherboard's recommended limit ({$motherboardMaxTDP}W)";
            }
            
            // Memory controller compatibility
            $cpuMemoryTypes = $this->extractSupportedMemoryTypes($cpuData, 'cpu');
            $motherboardMemoryTypes = $this->extractSupportedMemoryTypes($motherboardData, 'motherboard');
            
            if ($cpuMemoryTypes && $motherboardMemoryTypes) {
                $commonTypes = array_intersect($cpuMemoryTypes, $motherboardMemoryTypes);
                if (empty($commonTypes)) {
                    $result['compatibility_score'] *= 0.5;
                    $result['warnings'][] = "No common memory types supported between CPU and motherboard";
                }
            }
            
            // PCIe version compatibility
            $cpuPCIeVersion = $this->extractPCIeVersion($cpuData, 'cpu');
            $motherboardPCIeVersion = $this->extractPCIeVersion($motherboardData, 'motherboard');
            
            if ($cpuPCIeVersion && $motherboardPCIeVersion) {
                if (version_compare($cpuPCIeVersion, $motherboardPCIeVersion, '>')) {
                    $result['warnings'][] = "CPU supports newer PCIe version ($cpuPCIeVersion) than motherboard ($motherboardPCIeVersion)";
                    $result['recommendations'][] = "Consider upgrading motherboard for full PCIe performance";
                }
            }
            
        } catch (Exception $e) {
            error_log("CPU-Motherboard compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform detailed compatibility check";
        }
        
        return $result;
    }
    
    /**
     * Check Motherboard-RAM compatibility
     */
    private function checkMotherboardRAMCompatibility($component1, $component2) {
        $motherboard = $component1['type'] === 'motherboard' ? $component1 : $component2;
        $ram = $component1['type'] === 'ram' ? $component1 : $component2;
        
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];
        
        try {
            $motherboardData = $this->getComponentData($motherboard['type'], $motherboard['uuid']);
            $ramData = $this->getComponentData($ram['type'], $ram['uuid']);
            
            // Memory type compatibility
            $motherboardMemoryTypes = $this->extractSupportedMemoryTypes($motherboardData, 'motherboard');
            $ramType = $this->extractMemoryType($ramData);
            
            if ($motherboardMemoryTypes && $ramType && !in_array($ramType, $motherboardMemoryTypes)) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "Memory type incompatible: $ramType not supported by motherboard";
                return $result;
            }
            
            // Memory speed compatibility
            $motherboardMaxSpeed = $this->extractMaxMemorySpeed($motherboardData);
            $ramSpeed = $this->extractMemorySpeed($ramData);
            
            if ($motherboardMaxSpeed && $ramSpeed && $ramSpeed > $motherboardMaxSpeed) {
                $result['compatibility_score'] *= 0.9;
                $result['warnings'][] = "RAM speed ({$ramSpeed}MHz) exceeds motherboard maximum ({$motherboardMaxSpeed}MHz) - will run at reduced speed";
            }
            
            // Form factor compatibility
            $motherboardFormFactor = $this->extractMemoryFormFactor($motherboardData);
            $ramFormFactor = $this->extractMemoryFormFactor($ramData);
            
            if ($motherboardFormFactor && $ramFormFactor && $motherboardFormFactor !== $ramFormFactor) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "Memory form factor incompatible: $ramFormFactor not supported by motherboard ($motherboardFormFactor)";
                return $result;
            }
            
            // ECC compatibility
            $motherboardECC = $this->extractECCSupport($motherboardData);
            $ramECC = $this->extractECCSupport($ramData);
            
            if ($ramECC && !$motherboardECC) {
                $result['compatibility_score'] *= 0.8;
                $result['warnings'][] = "ECC memory used with non-ECC motherboard - ECC features will be disabled";
            }
            
        } catch (Exception $e) {
            error_log("Motherboard-RAM compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform detailed compatibility check";
        }
        
        return $result;
    }
    
    /**
     * Check CPU-RAM compatibility
     */
    private function checkCPURAMCompatibility($component1, $component2) {
        $cpu = $component1['type'] === 'cpu' ? $component1 : $component2;
        $ram = $component1['type'] === 'ram' ? $component1 : $component2;
        
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];
        
        try {
            $cpuData = $this->getComponentData($cpu['type'], $cpu['uuid']);
            $ramData = $this->getComponentData($ram['type'], $ram['uuid']);
            
            // Memory type support
            $cpuMemoryTypes = $this->extractSupportedMemoryTypes($cpuData, 'cpu');
            $ramType = $this->extractMemoryType($ramData);
            
            if ($cpuMemoryTypes && $ramType && !in_array($ramType, $cpuMemoryTypes)) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "Memory type incompatible: CPU does not support $ramType";
                return $result;
            }
            
            // Memory speed limits
            $cpuMaxSpeed = $this->extractMaxMemorySpeed($cpuData, 'cpu');
            $ramSpeed = $this->extractMemorySpeed($ramData);
            
            if ($cpuMaxSpeed && $ramSpeed && $ramSpeed > $cpuMaxSpeed) {
                $result['compatibility_score'] *= 0.9;
                $result['warnings'][] = "RAM speed ({$ramSpeed}MHz) exceeds CPU specification ({$cpuMaxSpeed}MHz)";
                $result['recommendations'][] = "Memory will run at CPU's maximum supported speed";
            }
            
        } catch (Exception $e) {
            error_log("CPU-RAM compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform detailed compatibility check";
        }
        
        return $result;
    }
    
    /**
     * Check Motherboard-Storage compatibility
     */
    private function checkMotherboardStorageCompatibility($component1, $component2) {
        $motherboard = $component1['type'] === 'motherboard' ? $component1 : $component2;
        $storage = $component1['type'] === 'storage' ? $component1 : $component2;
        
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];
        
        try {
            $motherboardData = $this->getComponentData($motherboard['type'], $motherboard['uuid']);
            $storageData = $this->getComponentData($storage['type'], $storage['uuid']);
            
            // Interface compatibility
            $motherboardInterfaces = $this->extractStorageInterfaces($motherboardData);
            $storageInterface = $this->extractStorageInterface($storageData);
            
            if ($motherboardInterfaces && $storageInterface && !in_array($storageInterface, $motherboardInterfaces)) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "Storage interface incompatible: $storageInterface not available on motherboard";
                return $result;
            }
            
            // Form factor compatibility
            $motherboardFormFactors = $this->extractSupportedStorageFormFactors($motherboardData);
            $storageFormFactor = $this->extractStorageFormFactor($storageData);
            
            if ($motherboardFormFactors && $storageFormFactor && !in_array($storageFormFactor, $motherboardFormFactors)) {
                $result['compatibility_score'] *= 0.7;
                $result['warnings'][] = "Storage form factor ($storageFormFactor) may not be optimal for motherboard";
            }
            
            // Power requirements
            $storagePower = $this->extractPowerConsumption($storageData);
            if ($storagePower && $storagePower > 25) {
                $result['warnings'][] = "High power storage device - ensure adequate PSU capacity";
            }
            
        } catch (Exception $e) {
            error_log("Motherboard-Storage compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform detailed compatibility check";
        }
        
        return $result;
    }
    
    /**
     * Check Motherboard-NIC compatibility
     */
    private function checkMotherboardNICCompatibility($component1, $component2) {
        $motherboard = $component1['type'] === 'motherboard' ? $component1 : $component2;
        $nic = $component1['type'] === 'nic' ? $component1 : $component2;
        
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];
        
        try {
            $motherboardData = $this->getComponentData($motherboard['type'], $motherboard['uuid']);
            $nicData = $this->getComponentData($nic['type'], $nic['uuid']);
            
            // PCIe slot availability
            $motherboardPCIeSlots = $this->extractPCIeSlots($motherboardData);
            $nicPCIeRequirement = $this->extractPCIeRequirement($nicData);
            
            if ($nicPCIeRequirement && $motherboardPCIeSlots) {
                $compatibleSlots = $this->findCompatiblePCIeSlots($motherboardPCIeSlots, $nicPCIeRequirement);
                if (empty($compatibleSlots)) {
                    $result['compatible'] = false;
                    $result['compatibility_score'] = 0.0;
                    $result['issues'][] = "No compatible PCIe slots available for NIC";
                    return $result;
                }
            }
            
            // PCIe version compatibility
            $motherboardPCIeVersion = $this->extractPCIeVersion($motherboardData, 'motherboard');
            $nicPCIeVersion = $this->extractPCIeVersion($nicData, 'nic');
            
            if ($motherboardPCIeVersion && $nicPCIeVersion) {
                if (version_compare($nicPCIeVersion, $motherboardPCIeVersion, '>')) {
                    $result['compatibility_score'] *= 0.9;
                    $result['warnings'][] = "NIC requires newer PCIe version - may not achieve full performance";
                }
            }
            
            // Power requirements
            $nicPower = $this->extractPowerConsumption($nicData);
            if ($nicPower && $nicPower > 75) {
                $result['warnings'][] = "High power NIC - may require additional power connectors";
            }
            
        } catch (Exception $e) {
            error_log("Motherboard-NIC compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform detailed compatibility check";
        }
        
        return $result;
    }
    
    /**
     * Check Storage-Caddy compatibility
     */
    private function checkStorageCaddyCompatibility($component1, $component2) {
        $storage = $component1['type'] === 'storage' ? $component1 : $component2;
        $caddy = $component1['type'] === 'caddy' ? $component1 : $component2;
        
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];
        
        try {
            $storageData = $this->getComponentData($storage['type'], $storage['uuid']);
            $caddyData = $this->getComponentData($caddy['type'], $caddy['uuid']);
            
            // Form factor compatibility
            $storageFormFactor = $this->extractStorageFormFactor($storageData);
            $caddySupportedFormFactors = $this->extractSupportedFormFactors($caddyData);
            
            if ($storageFormFactor && $caddySupportedFormFactors && !in_array($storageFormFactor, $caddySupportedFormFactors)) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "Storage form factor ($storageFormFactor) not supported by caddy";
                return $result;
            }
            
            // Interface compatibility
            $storageInterface = $this->extractStorageInterface($storageData);
            $caddySupportedInterfaces = $this->extractSupportedInterfaces($caddyData);
            
            if ($storageInterface && $caddySupportedInterfaces && !in_array($storageInterface, $caddySupportedInterfaces)) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "Storage interface ($storageInterface) not supported by caddy";
                return $result;
            }
            
        } catch (Exception $e) {
            error_log("Storage-Caddy compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform detailed compatibility check";
        }
        
        return $result;
    }
    
    /**
     * Get component data from database and JSON
     */
    private function getComponentData($type, $uuid) {
        // Check cache first
        $cacheKey = "$type:$uuid";
        if (isset($this->jsonDataCache[$cacheKey])) {
            return $this->jsonDataCache[$cacheKey];
        }
        
        // Get from database
        $tableMap = [
            'cpu' => 'cpuinventory',
            'motherboard' => 'motherboardinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        $table = $tableMap[$type];
        $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
        $stmt->execute([$uuid]);
        $dbData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get JSON specifications
        $jsonData = $this->loadJSONData($type, $uuid);
        
        // Combine data
        $componentData = array_merge($dbData ?: [], $jsonData ?: []);
        
        // Cache the result
        $this->jsonDataCache[$cacheKey] = $componentData;
        
        return $componentData;
    }
    
    /**
     * Load JSON data for component
     */
    private function loadJSONData($type, $uuid) {
        $jsonPaths = [
            'cpu' => __DIR__ . '/../../All-JSON/cpu-jsons/Cpu-details-level-3.json',
            'motherboard' => __DIR__ . '/../../All-JSON/motherboad-jsons/motherboard-level-3.json',
            'ram' => __DIR__ . '/../../All-JSON/Ram-jsons/ram_detail.json',
            'storage' => __DIR__ . '/../../All-JSON/storage-jsons/storagedetail.json',
            'nic' => __DIR__ . '/../../All-JSON/nic-jsons/nic-level-3.json',
            'caddy' => __DIR__ . '/../../All-JSON/caddy-jsons/caddy_details.json'
        ];
        
        if (!isset($jsonPaths[$type]) || !file_exists($jsonPaths[$type])) {
            return null;
        }
        
        $jsonContent = file_get_contents($jsonPaths[$type]);
        $jsonData = json_decode($jsonContent, true);
        
        if (!$jsonData) {
            return null;
        }
        
        // Find the specific component by UUID
        foreach ($jsonData as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                    if ($modelUuid === $uuid) {
                        return $model;
                    }
                }
            }
        }
        
        return null;
    }
    
    // Data extraction methods
    private function extractSocketType($data, $componentType) {
        if ($componentType === 'cpu') {
            return $data['socket'] ?? $data['socket_type'] ?? null;
        } elseif ($componentType === 'motherboard') {
            return $data['socket'] ?? $data['socket_type'] ?? $data['cpu_socket'] ?? null;
        }
        return null;
    }
    
    private function extractTDP($data) {
        return $data['tdp'] ?? $data['tdp_watts'] ?? $data['power_consumption'] ?? null;
    }
    
    private function extractMaxTDP($data) {
        return $data['max_tdp'] ?? $data['max_cpu_tdp'] ?? 150; // Default assumption
    }
    
    private function extractSupportedMemoryTypes($data, $componentType) {
        $memoryTypes = $data['memory_types'] ?? $data['supported_memory'] ?? null;
        
        if (is_string($memoryTypes)) {
            return explode(',', $memoryTypes);
        } elseif (is_array($memoryTypes)) {
            return $memoryTypes;
        }
        
        return null;
    }
    
    private function extractMemoryType($data) {
        return $data['type'] ?? $data['memory_type'] ?? null;
    }
    
    private function extractMemorySpeed($data) {
        return $data['speed'] ?? $data['frequency'] ?? $data['memory_speed'] ?? null;
    }
    
    private function extractMaxMemorySpeed($data, $componentType = 'motherboard') {
        if ($componentType === 'cpu') {
            return $data['max_memory_speed'] ?? $data['memory_speed'] ?? null;
        }
        return $data['max_memory_speed'] ?? $data['memory_speed_max'] ?? null;
    }
    
    private function extractMemoryFormFactor($data) {
        return $data['form_factor'] ?? $data['memory_form_factor'] ?? 'DIMM';
    }
    
    private function extractECCSupport($data) {
        return $data['ecc_support'] ?? $data['ecc'] ?? false;
    }
    
    private function extractPCIeVersion($data, $componentType) {
        return $data['pcie_version'] ?? $data['pci_version'] ?? null;
    }
    
    private function extractStorageInterfaces($data) {
        $interfaces = $data['storage_interfaces'] ?? $data['interfaces'] ?? null;
        
        if (is_string($interfaces)) {
            return explode(',', $interfaces);
        } elseif (is_array($interfaces)) {
            return $interfaces;
        }
        
        return ['SATA']; // Default assumption
    }
    
    private function extractStorageInterface($data) {
        return $data['interface'] ?? $data['interface_type'] ?? null;
    }
    
    private function extractStorageFormFactor($data) {
        return $data['form_factor'] ?? $data['size'] ?? null;
    }
    
    private function extractSupportedStorageFormFactors($data) {
        $formFactors = $data['storage_form_factors'] ?? $data['supported_drives'] ?? null;
        
        if (is_string($formFactors)) {
            return explode(',', $formFactors);
        } elseif (is_array($formFactors)) {
            return $formFactors;
        }
        
        return ['2.5"', '3.5"']; // Default assumption
    }
    
    private function extractPowerConsumption($data) {
        return $data['power_consumption'] ?? $data['power'] ?? $data['watts'] ?? null;
    }
    
    private function extractPCIeSlots($data) {
        $slots = $data['pcie_slots'] ?? $data['expansion_slots'] ?? null;
        
        if (is_string($slots)) {
            // Parse string like "PCIe x16,PCIe x8,PCIe x4,PCIe x1"
            $slotArray = explode(',', $slots);
            return array_map('trim', $slotArray);
        } elseif (is_array($slots)) {
            return $slots;
        }
        
        return ['PCIe x16', 'PCIe x1']; // Default assumption
    }
    
    private function extractPCIeRequirement($data) {
        return $data['pcie_requirement'] ?? $data['slot_requirement'] ?? 'PCIe x1';
    }
    
    private function findCompatiblePCIeSlots($availableSlots, $requirement) {
        $compatible = [];
        
        foreach ($availableSlots as $slot) {
            if ($this->isPCIeSlotCompatible($slot, $requirement)) {
                $compatible[] = $slot;
            }
        }
        
        return $compatible;
    }
    
    private function isPCIeSlotCompatible($availableSlot, $requiredSlot) {
        // Extract slot sizes
        preg_match('/x(\d+)/', $availableSlot, $availableMatches);
        preg_match('/x(\d+)/', $requiredSlot, $requiredMatches);
        
        $availableSize = isset($availableMatches[1]) ? (int)$availableMatches[1] : 1;
        $requiredSize = isset($requiredMatches[1]) ? (int)$requiredMatches[1] : 1;
        
        // Larger slots can accommodate smaller cards
        return $availableSize >= $requiredSize;
    }
    
    private function extractSupportedFormFactors($data) {
        $formFactors = $data['supported_form_factors'] ?? $data['form_factors'] ?? null;
        
        if (is_string($formFactors)) {
            return explode(',', $formFactors);
        } elseif (is_array($formFactors)) {
            return $formFactors;
        }
        
        return ['2.5"', '3.5"']; // Default assumption
    }
    
    private function extractSupportedInterfaces($data) {
        $interfaces = $data['supported_interfaces'] ?? $data['interfaces'] ?? null;
        
        if (is_string($interfaces)) {
            return explode(',', $interfaces);
        } elseif (is_array($interfaces)) {
            return $interfaces;
        }
        
        return ['SATA']; // Default assumption
    }
    
    /**
     * Get comprehensive component specifications
     */
    public function getComponentSpecifications($componentType, $uuid) {
        try {
            $data = $this->getComponentData($componentType, $uuid);
            
            $specs = [
                'basic_info' => [
                    'type' => $componentType,
                    'uuid' => $uuid,
                    'model' => $data['model'] ?? 'Unknown',
                    'brand' => $data['brand'] ?? $data['manufacturer'] ?? 'Unknown'
                ],
                'compatibility_fields' => []
            ];
            
            switch ($componentType) {
                case 'cpu':
                    $specs['compatibility_fields'] = [
                        'socket' => $this->extractSocketType($data, 'cpu'),
                        'memory_types' => $this->extractSupportedMemoryTypes($data, 'cpu'),
                        'max_memory_speed' => $this->extractMaxMemorySpeed($data, 'cpu'),
                        'tdp' => $this->extractTDP($data),
                        'pcie_version' => $this->extractPCIeVersion($data, 'cpu')
                    ];
                    break;
                    
                case 'motherboard':
                    $specs['compatibility_fields'] = [
                        'socket' => $this->extractSocketType($data, 'motherboard'),
                        'memory_types' => $this->extractSupportedMemoryTypes($data, 'motherboard'),
                        'max_memory_speed' => $this->extractMaxMemorySpeed($data, 'motherboard'),
                        'max_tdp' => $this->extractMaxTDP($data),
                        'pcie_version' => $this->extractPCIeVersion($data, 'motherboard'),
                        'pcie_slots' => $this->extractPCIeSlots($data),
                        'storage_interfaces' => $this->extractStorageInterfaces($data)
                    ];
                    break;
                    
                case 'ram':
                    $specs['compatibility_fields'] = [
                        'type' => $this->extractMemoryType($data),
                        'speed' => $this->extractMemorySpeed($data),
                        'form_factor' => $this->extractMemoryFormFactor($data),
                        'ecc_support' => $this->extractECCSupport($data)
                    ];
                    break;
                    
                case 'storage':
                    $specs['compatibility_fields'] = [
                        'interface' => $this->extractStorageInterface($data),
                        'form_factor' => $this->extractStorageFormFactor($data),
                        'power_consumption' => $this->extractPowerConsumption($data)
                    ];
                    break;
                    
                case 'nic':
                    $specs['compatibility_fields'] = [
                        'pcie_requirement' => $this->extractPCIeRequirement($data),
                        'pcie_version' => $this->extractPCIeVersion($data, 'nic'),
                        'power_consumption' => $this->extractPowerConsumption($data)
                    ];
                    break;
                    
                case 'caddy':
                    $specs['compatibility_fields'] = [
                        'supported_form_factors' => $this->extractSupportedFormFactors($data),
                        'supported_interfaces' => $this->extractSupportedInterfaces($data)
                    ];
                    break;
            }
            
            return $specs;
        } catch (Exception $e) {
            error_log("Error getting component specifications: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if two component types can be compatible
     */
    public function canComponentTypesBeCompatible($type1, $type2) {
        $compatibilityMethod = $this->getCompatibilityMethod($type1, $type2);
        return $compatibilityMethod !== null;
    }
    
    /**
     * Get all compatibility relationships for a component type
     */
    public function getCompatibilityRelationships($componentType) {
        $relationships = [];
        $allTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
        
        foreach ($allTypes as $otherType) {
            if ($otherType !== $componentType) {
                $method = $this->getCompatibilityMethod($componentType, $otherType);
                if ($method) {
                    $relationships[] = [
                        'type' => $otherType,
                        'relationship' => $this->getRelationshipDescription($componentType, $otherType),
                        'method' => $method
                    ];
                }
            }
        }
        
        return $relationships;
    }
    
    /**
     * Get description of compatibility relationship
     */
    private function getRelationshipDescription($type1, $type2) {
        $descriptions = [
            'cpu-motherboard' => 'Socket compatibility, TDP limits, memory controller support',
            'motherboard-cpu' => 'Socket compatibility, TDP limits, memory controller support',
            'motherboard-ram' => 'Memory type, speed, form factor, and ECC compatibility',
            'ram-motherboard' => 'Memory type, speed, form factor, and ECC compatibility',
            'cpu-ram' => 'Memory type and speed support by CPU',
            'ram-cpu' => 'Memory type and speed support by CPU',
            'motherboard-storage' => 'Interface availability and form factor support',
            'storage-motherboard' => 'Interface availability and form factor support',
            'motherboard-nic' => 'PCIe slot availability and power requirements',
            'nic-motherboard' => 'PCIe slot availability and power requirements',
            'storage-caddy' => 'Form factor and interface compatibility',
            'caddy-storage' => 'Form factor and interface compatibility'
        ];
        
        return $descriptions["$type1-$type2"] ?? 'General compatibility check';
    }
    
    /**
     * Validate component configuration in bulk
     */
    public function validateComponentConfiguration($components) {
        $results = [];
        $overallCompatible = true;
        $overallScore = 1.0;
        
        // Check each component pair
        for ($i = 0; $i < count($components); $i++) {
            for ($j = $i + 1; $j < count($components); $j++) {
                $component1 = $components[$i];
                $component2 = $components[$j];
                
                $compatibility = $this->checkComponentPairCompatibility($component1, $component2);
                
                $results[] = [
                    'component_1' => $component1,
                    'component_2' => $component2,
                    'compatibility' => $compatibility
                ];
                
                if (!$compatibility['compatible']) {
                    $overallCompatible = false;
                }
                
                $overallScore *= $compatibility['compatibility_score'];
            }
        }
        
        return [
            'overall_compatible' => $overallCompatible,
            'overall_score' => $overallScore,
            'individual_checks' => $results,
            'total_checks' => count($results)
        ];
    }
    
    /**
     * Get compatibility recommendations for a component
     */
    public function getCompatibilityRecommendations($componentType, $uuid) {
        try {
            $componentData = $this->getComponentData($componentType, $uuid);
            $recommendations = [];
            
            switch ($componentType) {
                case 'cpu':
                    $socket = $this->extractSocketType($componentData, 'cpu');
                    $memoryTypes = $this->extractSupportedMemoryTypes($componentData, 'cpu');
                    $tdp = $this->extractTDP($componentData);
                    
                    if ($socket) {
                        $recommendations[] = "Requires motherboard with $socket socket";
                    }
                    if ($memoryTypes) {
                        $recommendations[] = "Compatible with " . implode(', ', $memoryTypes) . " memory";
                    }
                    if ($tdp) {
                        $recommendations[] = "Requires motherboard supporting at least {$tdp}W TDP";
                    }
                    break;
                    
                case 'motherboard':
                    $socket = $this->extractSocketType($componentData, 'motherboard');
                    $memoryTypes = $this->extractSupportedMemoryTypes($componentData, 'motherboard');
                    $storageInterfaces = $this->extractStorageInterfaces($componentData);
                    $pcieSlots = $this->extractPCIeSlots($componentData);
                    
                    if ($socket) {
                        $recommendations[] = "Compatible with $socket CPUs";
                    }
                    if ($memoryTypes) {
                        $recommendations[] = "Supports " . implode(', ', $memoryTypes) . " memory";
                    }
                    if ($storageInterfaces) {
                        $recommendations[] = "Available storage interfaces: " . implode(', ', $storageInterfaces);
                    }
                    if ($pcieSlots) {
                        $recommendations[] = "PCIe slots: " . implode(', ', $pcieSlots);
                    }
                    break;
                    
                case 'ram':
                    $type = $this->extractMemoryType($componentData);
                    $speed = $this->extractMemorySpeed($componentData);
                    $formFactor = $this->extractMemoryFormFactor($componentData);
                    
                    if ($type) {
                        $recommendations[] = "Requires $type compatible motherboard and CPU";
                    }
                    if ($speed) {
                        $recommendations[] = "Optimal with systems supporting {$speed}MHz or higher";
                    }
                    if ($formFactor) {
                        $recommendations[] = "Requires $formFactor slots";
                    }
                    break;
                    
                case 'storage':
                    $interface = $this->extractStorageInterface($componentData);
                    $formFactor = $this->extractStorageFormFactor($componentData);
                    
                    if ($interface) {
                        $recommendations[] = "Requires motherboard with $interface interface";
                    }
                    if ($formFactor) {
                        $recommendations[] = "Requires $formFactor bay or caddy";
                    }
                    break;
                    
                case 'nic':
                    $pcieRequirement = $this->extractPCIeRequirement($componentData);
                    $power = $this->extractPowerConsumption($componentData);
                    
                    if ($pcieRequirement) {
                        $recommendations[] = "Requires available $pcieRequirement slot";
                    }
                    if ($power && $power > 75) {
                        $recommendations[] = "May require additional power connector";
                    }
                    break;
                    
                case 'caddy':
                    $supportedFormFactors = $this->extractSupportedFormFactors($componentData);
                    $supportedInterfaces = $this->extractSupportedInterfaces($componentData);
                    
                    if ($supportedFormFactors) {
                        $recommendations[] = "Supports " . implode(', ', $supportedFormFactors) . " drives";
                    }
                    if ($supportedInterfaces) {
                        $recommendations[] = "Compatible with " . implode(', ', $supportedInterfaces) . " interfaces";
                    }
                    break;
            }
            
            return $recommendations;
        } catch (Exception $e) {
            error_log("Error getting compatibility recommendations: " . $e->getMessage());
            return ["Unable to generate recommendations"];
        }
    }
    
    /**
     * Find potential compatibility issues in a component set
     */
    public function findPotentialIssues($components) {
        $issues = [];
        $warnings = [];
        
        try {
            // Check for missing essential components
            $componentTypes = array_column($components, 'type');
            
            if (!in_array('cpu', $componentTypes) && !in_array('motherboard', $componentTypes)) {
                $issues[] = "No CPU or motherboard selected - at least one is required";
            }
            
            if (!in_array('ram', $componentTypes)) {
                $warnings[] = "No memory modules selected - system will not function without RAM";
            }
            
            if (!in_array('storage', $componentTypes)) {
                $warnings[] = "No storage devices selected - system needs storage to boot";
            }
            
            // Check for potential conflicts
            $cpuComponents = array_filter($components, function($c) { return $c['type'] === 'cpu'; });
            $motherboardComponents = array_filter($components, function($c) { return $c['type'] === 'motherboard'; });
            
            // Check CPU socket count vs motherboard socket capacity
            if (count($cpuComponents) > 1 && !empty($motherboardComponents)) {
                $motherboardData = $this->getComponentData('motherboard', $motherboardComponents[0]['uuid']);
                $motherboardSocketCount = $this->getMotherboardSocketCount($motherboardData);
                
                if (count($cpuComponents) > $motherboardSocketCount) {
                    $issues[] = "Too many CPUs selected - motherboard supports maximum $motherboardSocketCount CPU(s), but " . count($cpuComponents) . " CPU(s) selected";
                }
            } elseif (count($cpuComponents) > 1) {
                // If no motherboard, assume single socket
                $issues[] = "Multiple CPUs selected but no motherboard selected to verify socket count";
            }
            
            if (count($motherboardComponents) > 1) {
                $issues[] = "Multiple motherboards selected - only one motherboard is supported per configuration";
            }
            
            // Check memory configuration
            $ramComponents = array_filter($components, function($c) { return $c['type'] === 'ram'; });
            if (count($ramComponents) > 8) {
                $warnings[] = "Large number of memory modules selected - verify motherboard has sufficient slots";
            }
            
            // Check for compatibility between selected components
            $compatibilityResult = $this->validateComponentConfiguration($components);
            if (!$compatibilityResult['overall_compatible']) {
                foreach ($compatibilityResult['individual_checks'] as $check) {
                    if (!$check['compatibility']['compatible']) {
                        $issues = array_merge($issues, $check['compatibility']['issues']);
                    }
                    $warnings = array_merge($warnings, $check['compatibility']['warnings']);
                }
            }
            
        } catch (Exception $e) {
            error_log("Error finding potential issues: " . $e->getMessage());
            $issues[] = "Unable to complete compatibility analysis";
        }
        
        return [
            'issues' => array_unique($issues),
            'warnings' => array_unique($warnings),
            'has_critical_issues' => !empty($issues)
        ];
    }
    
    /**
     * Get component compatibility score
     */
    public function getComponentCompatibilityScore($components) {
        if (empty($components)) {
            return [
                'score' => 0.0,
                'rating' => 'incomplete',
                'description' => 'No components selected'
            ];
        }
        
        $validation = $this->validateComponentConfiguration($components);
        $score = $validation['overall_score'];
        
        // Determine rating based on score
        if ($score >= 0.9) {
            $rating = 'excellent';
            $description = 'All components are highly compatible';
        } elseif ($score >= 0.8) {
            $rating = 'good';
            $description = 'Components are compatible with minor optimization opportunities';
        } elseif ($score >= 0.7) {
            $rating = 'acceptable';
            $description = 'Components are compatible but may have performance limitations';
        } elseif ($score >= 0.5) {
            $rating = 'poor';
            $description = 'Components have significant compatibility issues';
        } else {
            $rating = 'incompatible';
            $description = 'Components have critical compatibility problems';
        }
        
        return [
            'score' => round($score, 2),
            'rating' => $rating,
            'description' => $description,
            'total_checks' => $validation['total_checks'],
            'compatible_checks' => count(array_filter($validation['individual_checks'], function($check) {
                return $check['compatibility']['compatible'];
            }))
        ];
    }
    
    /**
     * Clear component data cache
     */
    public function clearCache() {
        $this->jsonDataCache = [];
    }
    
    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        return [
            'cached_components' => count($this->jsonDataCache),
            'cache_keys' => array_keys($this->jsonDataCache)
        ];
    }
    
    /**
     * Get motherboard socket count
     */
    private function getMotherboardSocketCount($motherboardData) {
        if (!$motherboardData) {
            return 1; // Default to single socket
        }
        
        // Check if socket count is provided in specifications
        if (isset($motherboardData['socket']['count'])) {
            return (int)$motherboardData['socket']['count'];
        }
        
        // Try to extract from Notes field
        $notes = strtolower($motherboardData['Notes'] ?? '');
        
        // Look for socket count patterns
        if (preg_match('/(\d+)[\s]*socket/i', $notes, $matches)) {
            return (int)$matches[1];
        }
        
        if (preg_match('/(\d+)[\s]*cpu/i', $notes, $matches)) {
            return (int)$matches[1];
        }
        
        // Look for dual/multi socket indicators
        if (strpos($notes, 'dual socket') !== false || strpos($notes, 'dual-socket') !== false) {
            return 2;
        }
        
        if (strpos($notes, 'quad socket') !== false || strpos($notes, 'quad-socket') !== false) {
            return 4;
        }
        
        // Default to single socket for desktop motherboards
        return 1;
    }
}
?>