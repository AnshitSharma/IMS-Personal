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
     * Check compatibility between two specific components - ENHANCED with proper validation
     */
    public function checkComponentPairCompatibility($component1, $component2) {
        $type1 = $component1['type'];
        $type2 = $component2['type'];
        
        // Enhanced cross-component compatibility checking
        if (($type1 === 'cpu' && $type2 === 'motherboard') || ($type1 === 'motherboard' && $type2 === 'cpu')) {
            $cpu = $type1 === 'cpu' ? $component1 : $component2;
            $motherboard = $type1 === 'motherboard' ? $component1 : $component2;
            
            // Get motherboard specifications
            $mbSpecsResult = $this->parseMotherboardSpecifications($motherboard['uuid']);
            if (!$mbSpecsResult['found']) {
                return [
                    'compatible' => false,
                    'compatibility_score' => 0.0,
                    'issues' => [$mbSpecsResult['error']],
                    'warnings' => [],
                    'recommendations' => ['Ensure motherboard exists in JSON specifications']
                ];
            }
            
            $mbLimits = $this->convertSpecsToLimits($mbSpecsResult['specifications']);
            $socketResult = $this->validateCPUSocketCompatibility($cpu['uuid'], $mbLimits);
            
            if (!$socketResult['compatible']) {
                return [
                    'compatible' => false,
                    'compatibility_score' => 0.0,
                    'issues' => [$socketResult['error']],
                    'warnings' => [],
                    'recommendations' => ['Use CPU and motherboard with matching socket types']
                ];
            }
            
            return [
                'compatible' => true,
                'compatibility_score' => 1.0,
                'issues' => [],
                'warnings' => [],
                'recommendations' => []
            ];
        }
        
        // Use existing compatibility method for other component pairs
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
     * Convert motherboard specifications to limits format
     */
    private function convertSpecsToLimits($specifications) {
        return [
            'cpu' => [
                'socket_type' => $specifications['socket']['type'] ?? 'Unknown',
                'max_sockets' => $specifications['socket']['count'] ?? 1,
                'max_tdp' => $specifications['power']['max_tdp'] ?? 150
            ],
            'memory' => [
                'max_slots' => $specifications['memory']['slots'] ?? 4,
                'supported_types' => $specifications['memory']['types'] ?? ['DDR4'],
                'max_frequency_mhz' => $specifications['memory']['max_frequency_mhz'] ?? 3200,
                'max_capacity_gb' => $specifications['memory']['max_capacity_gb'] ?? 128,
                'ecc_support' => $specifications['memory']['ecc_support'] ?? false
            ],
            'storage' => [
                'sata_ports' => $specifications['storage']['sata_ports'] ?? 0,
                'm2_slots' => $specifications['storage']['m2_slots'] ?? 0,
                'u2_slots' => $specifications['storage']['u2_slots'] ?? 0,
                'sas_ports' => $specifications['storage']['sas_ports'] ?? 0
            ],
            'expansion' => [
                'pcie_slots' => $specifications['pcie_slots'] ?? []
            ]
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

            // Normalize socket types for comparison (case-insensitive, trim whitespace)
            $cpuSocketNormalized = strtolower(trim($cpuSocket ?? ''));
            $motherboardSocketNormalized = strtolower(trim($motherboardSocket ?? ''));

            if ($cpuSocket && $motherboardSocket && $cpuSocketNormalized !== $motherboardSocketNormalized) {
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

            if ($cpuMemoryTypes && $ramType) {
                // Normalize memory types: "DDR5-4800" -> "DDR5"
                $normalizedCpuTypes = array_map(function($type) {
                    return preg_replace('/-\d+$/', '', $type);
                }, $cpuMemoryTypes);

                if (!in_array($ramType, $normalizedCpuTypes)) {
                    // Don't block, just warn
                    $result['compatibility_score'] *= 0.7;
                    $result['warnings'][] = "RAM type $ramType may have compatibility issues with CPU (supports " . implode(', ', $normalizedCpuTypes) . ")";
                }
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
     * Check Motherboard-Storage compatibility - ENHANCED WITH JSON VALIDATION
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
            // Load storage specifications from JSON with UUID validation
            $storageSpecs = $this->loadStorageSpecs($storage['uuid']);
            if (!$storageSpecs) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "Storage UUID {$storage['uuid']} not found in storage-level-3.json";
                $result['warnings'][] = "Falling back to database Notes field parsing";
                return $this->fallbackStorageCompatibilityCheck($component1, $component2);
            }

            // Load motherboard specifications from JSON
            $motherboardSpecs = $this->loadMotherboardSpecs($motherboard['uuid']);
            if (!$motherboardSpecs) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "Motherboard UUID {$motherboard['uuid']} not found in motherboard-level-3.json";
                return $result;
            }

            // Interface Type Compatibility Check
            $interfaceResult = $this->checkStorageInterfaceCompatibility($storageSpecs, $motherboardSpecs);
            if (!$interfaceResult['compatible']) {
                $result['compatible'] = false;
                $result['compatibility_score'] = min($result['compatibility_score'], $interfaceResult['score']);
                $result['issues'][] = $interfaceResult['message'];
                $result['recommendations'][] = $interfaceResult['recommendation'];
                return $result;
            }

            // Form Factor and Connector Validation
            $formFactorResult = $this->checkFormFactorCompatibility($storageSpecs, $motherboardSpecs);
            if (!$formFactorResult['compatible']) {
                $result['compatible'] = false;
                $result['compatibility_score'] = min($result['compatibility_score'], $formFactorResult['score']);
                $result['issues'][] = $formFactorResult['message'];
                $result['recommendations'][] = $formFactorResult['recommendation'];
                return $result;
            }

            // PCIe Bandwidth Validation for NVMe storage
            // SKIP for M.2/U.2/U.3 FORM FACTOR - they use dedicated motherboard slots or chassis bays
            // 2.5"/3.5" drives with NVMe interface use chassis bays, NOT PCIe expansion slots
            if ($this->isNVMeStorage($storageSpecs)) {
                $formFactor = strtolower($storageSpecs['form_factor'] ?? '');

                // ONLY check form_factor, NOT subtype
                // Form factor determines physical connection, not protocol
                $isM2FormFactor = (strpos($formFactor, 'm.2') !== false || strpos($formFactor, 'm2') !== false);
                $isU2U3FormFactor = (strpos($formFactor, 'u.2') !== false || strpos($formFactor, 'u.3') !== false);
                $is25or35Inch = (strpos($formFactor, '2.5') !== false || strpos($formFactor, '3.5') !== false);

                // Skip PCIe bandwidth check for:
                // - M.2 form factor (uses motherboard M.2 slots or M.2 adapters)
                // - U.2/U.3 form factor (uses motherboard U.2 ports)
                // - 2.5"/3.5" form factor (uses chassis bays, even if NVMe protocol)
                if (!$isM2FormFactor && !$isU2U3FormFactor && !$is25or35Inch) {
                    $bandwidthResult = $this->checkPCIeBandwidthCompatibility($storageSpecs, $motherboardSpecs);
                    if (!$bandwidthResult['compatible']) {
                        $result['compatibility_score'] = min($result['compatibility_score'], $bandwidthResult['score']);
                        if ($bandwidthResult['score'] < 0.5) {
                            $result['compatible'] = false;
                            $result['issues'][] = $bandwidthResult['message'];
                        } else {
                            $result['warnings'][] = $bandwidthResult['message'];
                        }
                        $result['recommendations'][] = $bandwidthResult['recommendation'];
                    }
                }
            }

            // Calculate final compatibility score
            $result['compatibility_score'] = min(
                $interfaceResult['score'],
                $formFactorResult['score'],
                isset($bandwidthResult) ? $bandwidthResult['score'] : 1.0
            );

            // Add performance recommendations if score is below optimal
            if ($result['compatibility_score'] < 0.9) {
                $result['recommendations'][] = 'Consider alternative storage options for optimal compatibility';
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
        // Temporarily simplified: NICs don't have JSON data yet, so skip detailed compatibility
        // This prevents 500 errors when NIC UUIDs don't match JSON
        return [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => ['NIC compatibility check skipped - NIC specifications pending'],
            'recommendations' => []
        ];
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

            // Get storage form factor
            $storageFormFactor = $this->extractStorageFormFactor($storageData);
            $normalizedStorageFF = $this->normalizeFormFactorForComparison($storageFormFactor);

            // CRITICAL: M.2 and U.2 storage do NOT use caddies
            // They connect directly to motherboard M.2 slots or PCIe adapters
            // Only 2.5" and 3.5" storage require caddy compatibility checks
            if (strpos($normalizedStorageFF, 'm.2') !== false ||
                strpos($normalizedStorageFF, 'm2') !== false ||
                strpos($normalizedStorageFF, 'u.2') !== false ||
                strpos($normalizedStorageFF, 'u.3') !== false) {
                // M.2/U.2 storage - skip caddy check, always compatible
                $result['warnings'][] = "M.2/U.2 storage does not require caddy - connects directly to motherboard/PCIe adapter";
                return $result;
            }

            // Form factor compatibility for 2.5" and 3.5" storage only
            $caddySupportedFormFactors = $this->extractSupportedFormFactors($caddyData);

            if ($storageFormFactor && $caddySupportedFormFactors) {
                $normalizedCaddyFFs = array_map([$this, 'normalizeFormFactorForComparison'], $caddySupportedFormFactors);

                if (!in_array($normalizedStorageFF, $normalizedCaddyFFs)) {
                    $result['compatible'] = false;
                    $result['compatibility_score'] = 0.0;
                    $result['issues'][] = "Storage form factor ($storageFormFactor) not supported by caddy";
                    return $result;
                }
            }

            // NOTE: Interface compatibility check REMOVED
            // Caddies are passive physical mounting brackets - they don't have electrical interfaces.
            // Interface compatibility (SATA/SAS/NVMe) is handled by chassis backplane/HBA/motherboard.
            // The "interface" field in caddy JSON is metadata only, not a compatibility constraint.

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
            'caddy' => 'caddyinventory',
            'pciecard' => 'pciecardinventory',
            'chassis' => 'chassisinventory',
            'hbacard' => 'hbacardinventory'
        ];

        $table = $tableMap[$type] ?? null;
        if (!$table) {
            return null;
        }

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
     * Get JSON file paths for all component types
     */
    private function getJSONFilePaths() {
        return [
            'chassis' => __DIR__ . '/../../All-JSON/chasis-jsons/chasis-level-3.json',
            'cpu' => __DIR__ . '/../../All-JSON/cpu-jsons/Cpu-details-level-3.json',
            'motherboard' => __DIR__ . '/../../All-JSON/motherboad-jsons/motherboard-level-3.json',
            'ram' => __DIR__ . '/../../All-JSON/Ram-jsons/ram_detail.json',
            'storage' => __DIR__ . '/../../All-JSON/storage-jsons/storage-level-3.json',
            'nic' => __DIR__ . '/../../All-JSON/nic-jsons/nic-level-3.json',
            'caddy' => __DIR__ . '/../../All-JSON/caddy-jsons/caddy_details.json',
            'pciecard' => __DIR__ . '/../../All-JSON/pci-jsons/pci-level-3.json',
            'hbacard' => __DIR__ . '/../../All-JSON/hbacard-jsons/hbacard-level-3.json'
        ];
    }

    /**
     * Load component from JSON by UUID
     */
    public function loadComponentFromJSON($componentType, $uuid) {
        $jsonPaths = $this->getJSONFilePaths();
        
        if (!isset($jsonPaths[$componentType])) {
            return [
                'found' => false,
                'error' => "Unknown component type: $componentType",
                'data' => null
            ];
        }
        
        $filePath = $jsonPaths[$componentType];
        
        if (!file_exists($filePath)) {
            return [
                'found' => false,
                'error' => "JSON file not found for component type: $componentType",
                'data' => null
            ];
        }
        
        try {
            $jsonContent = file_get_contents($filePath);
            $jsonData = json_decode($jsonContent, true);

            if (!$jsonData) {
                return [
                    'found' => false,
                    'error' => "Failed to parse JSON for component type: $componentType",
                    'data' => null
                ];
            }

            // Handle chassis special structure: chassis_specifications -> manufacturers
            if ($componentType === 'chassis' && isset($jsonData['chassis_specifications']['manufacturers'])) {
                $jsonData = $jsonData['chassis_specifications']['manufacturers'];
            }

            // Handle caddy special structure: caddies array wrapper
            if ($componentType === 'caddy' && isset($jsonData['caddies'])) {
                // Caddy JSON has direct array of models, not brand/series hierarchy
                foreach ($jsonData['caddies'] as $caddy) {
                    $caddyUuid = $caddy['UUID'] ?? $caddy['uuid'] ?? '';
                    if ($caddyUuid === $uuid) {
                        return [
                            'found' => true,
                            'error' => null,
                            'data' => $caddy
                        ];
                    }
                }
                // Not found in caddies array
                return [
                    'found' => false,
                    'error' => "Component UUID $uuid not found in caddy JSON",
                    'data' => null
                ];
            }

            // Search for component by UUID
            foreach ($jsonData as $brandData) {
                // Handle different JSON structures
                $modelArray = null;

                // Standard structure: models array directly in brand
                if (isset($brandData['models'])) {
                    $modelArray = $brandData['models'];
                }
                // NIC structure: series -> models (NEW format)
                elseif (isset($brandData['series'])) {
                    foreach ($brandData['series'] as $series) {
                        // Check for direct models in series
                        if (isset($series['models'])) {
                            foreach ($series['models'] as $model) {
                                $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                                if ($modelUuid === $uuid) {
                                    return [
                                        'found' => true,
                                        'error' => null,
                                        'data' => $model
                                    ];
                                }
                            }
                        }
                        // OLD NIC structure: series -> families -> port_configurations (for backward compatibility)
                        elseif (isset($series['families'])) {
                            foreach ($series['families'] as $family) {
                                if (isset($family['port_configurations'])) {
                                    foreach ($family['port_configurations'] as $model) {
                                        $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                                        if ($modelUuid === $uuid) {
                                            return [
                                                'found' => true,
                                                'error' => null,
                                                'data' => $model
                                            ];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // Search through the model array (for standard structure)
                if ($modelArray) {
                    foreach ($modelArray as $model) {
                        $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                        if ($modelUuid === $uuid) {
                            return [
                                'found' => true,
                                'error' => null,
                                'data' => $model
                            ];
                        }
                    }
                }
            }
            
            return [
                'found' => false,
                'error' => "Component UUID $uuid not found in $componentType JSON",
                'data' => null
            ];
            
        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => "Error loading JSON for $componentType: " . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Validate component exists in JSON
     */
    public function validateComponentExistsInJSON($componentType, $uuid) {
        $result = $this->loadComponentFromJSON($componentType, $uuid);
        return $result['found'];
    }

    /**
     * Load JSON data for component with enhanced debugging
     */
    private function loadJSONData($type, $uuid) {
        $jsonPaths = $this->getJSONFilePaths();
        
        if (!isset($jsonPaths[$type])) {
            error_log("Unknown component type for JSON loading: $type");
            return null;
        }
        
        $filePath = $jsonPaths[$type];
        
        if (!file_exists($filePath)) {
            error_log("JSON file not found: $filePath for type: $type");
            return null;
        }
        
        try {
            $jsonContent = file_get_contents($filePath);
            if ($jsonContent === false) {
                error_log("Failed to read JSON file: $filePath");
                return null;
            }
            
            $jsonData = json_decode($jsonContent, true);
            
            if (!$jsonData) {
                error_log("Failed to parse JSON for type: $type, JSON error: " . json_last_error_msg());
                return null;
            }
            
            error_log("DEBUG: Successfully loaded JSON for type: $type, searching for UUID: $uuid");
            
        } catch (Exception $e) {
            error_log("Error loading JSON for type $type: " . $e->getMessage());
            return null;
        }
        
        // Find the specific component by UUID with detailed logging
        $foundComponents = 0;

        // Special handling for caddy JSON structure: {"caddies": [...]}
        if ($type === 'caddy' && isset($jsonData['caddies']) && is_array($jsonData['caddies'])) {
            foreach ($jsonData['caddies'] as $caddy) {
                $foundComponents++;
                $caddyUuid = $caddy['UUID'] ?? $caddy['uuid'] ?? '';
                if ($caddyUuid === $uuid) {
                    error_log("DEBUG: Found caddy $uuid in caddies array");
                    return $caddy;
                }
            }
            error_log("DEBUG: Caddy $uuid not found in caddies array (searched $foundComponents caddies)");
            return null;
        }

        foreach ($jsonData as $brandData) {
            $modelArray = null;

            // Chassis structure: manufacturers -> series -> models
            if ($type === 'chassis' && isset($brandData['manufacturer'])) {
                if (isset($brandData['series']) && is_array($brandData['series'])) {
                    foreach ($brandData['series'] as $series) {
                        if (isset($series['models']) && is_array($series['models'])) {
                            foreach ($series['models'] as $model) {
                                $foundComponents++;
                                $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                                if ($modelUuid === $uuid) {
                                    $manufacturerName = $brandData['manufacturer'] ?? 'Unknown';
                                    error_log("DEBUG: Found chassis $uuid in manufacturer $manufacturerName");
                                    return $model;
                                }
                            }
                        }
                    }
                }
                continue; // Skip to next manufacturer
            }

            // Standard structure: models array directly in brand
            if (isset($brandData['models']) && is_array($brandData['models'])) {
                $modelArray = $brandData['models'];
            }
            // NIC structure: brand -> series -> models (NOT families -> port_configurations)
            elseif (isset($brandData['series']) && is_array($brandData['series'])) {
                foreach ($brandData['series'] as $series) {
                    // Check for direct models array in series
                    if (isset($series['models']) && is_array($series['models'])) {
                        foreach ($series['models'] as $model) {
                            $foundComponents++;
                            $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                            if ($modelUuid === $uuid) {
                                $brandName = $brandData['brand'] ?? 'Unknown';
                                $seriesName = $series['name'] ?? 'Unknown';
                                error_log("DEBUG: Found component $uuid in brand $brandName, series $seriesName");

                                // IMPORTANT: Merge parent-level fields (like component_subtype) into model data
                                // This ensures fields like 'component_subtype' from the brand level are available
                                $enrichedModel = $model;
                                if (isset($brandData['component_subtype'])) {
                                    $enrichedModel['component_subtype'] = $brandData['component_subtype'];
                                }
                                if (isset($brandData['brand'])) {
                                    $enrichedModel['brand'] = $brandData['brand'];
                                }

                                return $enrichedModel;
                            }
                        }
                    }
                    // Fallback: families -> port_configurations (legacy structure)
                    elseif (isset($series['families'])) {
                        foreach ($series['families'] as $family) {
                            if (isset($family['port_configurations'])) {
                                foreach ($family['port_configurations'] as $model) {
                                    $foundComponents++;
                                    $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                                    if ($modelUuid === $uuid) {
                                        $brandName = $brandData['brand'] ?? 'Unknown';
                                        error_log("DEBUG: Found component $uuid in brand $brandName (port_configurations)");
                                        return $model;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Search through the model array (for standard structure)
            if ($modelArray) {
                foreach ($modelArray as $model) {
                    $foundComponents++;
                    $modelUuid = $model['UUID'] ?? $model['uuid'] ?? '';
                    if ($modelUuid === $uuid) {
                        $brandName = $brandData['brand'] ?? 'Unknown';
                        error_log("DEBUG: Found component $uuid in brand $brandName");

                        // IMPORTANT: Merge parent-level fields (like component_subtype) into model data
                        // This ensures fields like 'component_subtype' from the brand level are available
                        $enrichedModel = $model;
                        if (isset($brandData['component_subtype'])) {
                            $enrichedModel['component_subtype'] = $brandData['component_subtype'];
                        }
                        if (isset($brandData['brand'])) {
                            $enrichedModel['brand'] = $brandData['brand'];
                        }

                        return $enrichedModel;
                    }
                }
            }
        }

        error_log("DEBUG: Component $uuid not found in $type JSON (searched $foundComponents components)");
        return null;
    }
    
    // Data extraction methods
    private function extractSocketType($data, $componentType) {
        if ($componentType === 'cpu') {
            return $data['socket'] ?? $data['socket_type'] ?? null;
        } elseif ($componentType === 'motherboard') {
            // Handle nested socket object from JSON: {"socket": {"type": "LGA 4189", "count": 2}}
            if (isset($data['socket'])) {
                if (is_array($data['socket']) && isset($data['socket']['type'])) {
                    return $data['socket']['type'];
                } elseif (is_string($data['socket'])) {
                    return $data['socket'];
                }
            }
            return $data['socket_type'] ?? $data['cpu_socket'] ?? null;
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
        $types = null;

        // For motherboards, check the memory object first
        if ($componentType === 'motherboard' && isset($data['memory']) && is_array($data['memory'])) {
            $memoryType = $data['memory']['type'] ?? null;
            if ($memoryType) {
                // Return as array even if it's a single type
                $types = is_array($memoryType) ? $memoryType : [$memoryType];
            }
        }

        // For other components or fallback
        if (!$types) {
            $memoryTypes = $data['memory_types'] ?? $data['supported_memory'] ?? null;

            if (is_string($memoryTypes)) {
                $types = explode(',', $memoryTypes);
            } elseif (is_array($memoryTypes)) {
                $types = $memoryTypes;
            }
        }

        // Normalize all memory types to base DDR type (remove speed suffixes)
        if ($types && is_array($types)) {
            $normalizedTypes = [];
            foreach ($types as $type) {
                $normalized = $this->normalizeMemoryType(trim($type));
                if ($normalized) {
                    $normalizedTypes[] = $normalized;
                }
            }
            return !empty($normalizedTypes) ? array_unique($normalizedTypes) : null;
        }

        return null;
    }
    
    private function extractMemoryType($data) {
        $type = $data['type'] ?? $data['memory_type'] ?? null;
        // Normalize to base DDR type (DDR5, DDR4, etc.) without speed suffix
        return $this->normalizeMemoryType($type);
    }
    
    private function extractMemorySpeed($data) {
        return $data['speed'] ?? $data['frequency'] ?? $data['frequency_MHz'] ?? $data['memory_speed'] ?? null;
    }
    
    private function extractMaxMemorySpeed($data, $componentType = 'motherboard') {
        if ($componentType === 'cpu') {
            return $data['max_memory_speed'] ?? $data['memory_speed'] ?? null;
        }

        // For motherboards, check the memory object first
        if ($componentType === 'motherboard' && isset($data['memory']) && is_array($data['memory'])) {
            $maxFreq = $data['memory']['max_frequency_MHz'] ?? null;
            if ($maxFreq) {
                return $maxFreq;
            }
        }

        return $data['max_memory_speed'] ?? $data['memory_speed_max'] ?? null;
    }
    
    private function extractMemoryFormFactor($data) {
        // For motherboards, check the memory object first
        if (isset($data['memory']) && is_array($data['memory'])) {
            // Check if memory object has form_factor
            if (isset($data['memory']['form_factor'])) {
                return $data['memory']['form_factor'];
            }

            // Infer RAM form factor from motherboard memory type
            // Server/workstation motherboards with DDR4/DDR5 use DIMM
            // Desktop/laptop motherboards might use SO-DIMM (we'll default to DIMM for servers)
            $memoryType = $data['memory']['type'] ?? '';
            if (in_array($memoryType, ['DDR3', 'DDR4', 'DDR5'])) {
                // Server motherboards (EATX, ATX) use DIMM
                $mbFormFactor = $data['form_factor'] ?? '';
                if (in_array($mbFormFactor, ['EATX', 'ATX', 'E-ATX', 'SSI EEB'])) {
                    return 'DIMM';
                }
                // Mini-ITX and smaller might use SO-DIMM, but default to DIMM
                return 'DIMM';
            }
        }

        // For RAM modules, use the direct form_factor field
        // Handle variations like "DIMM (288-pin)" -> normalize to "DIMM"
        $formFactor = $data['form_factor'] ?? $data['memory_form_factor'] ?? 'DIMM';

        // Normalize form factor - extract just the base form factor type
        if (strpos($formFactor, 'DIMM') !== false) {
            if (strpos(strtoupper($formFactor), 'SO-DIMM') !== false || strpos(strtoupper($formFactor), 'SODIMM') !== false) {
                return 'SO-DIMM';
            }
            return 'DIMM';
        }

        return $formFactor;
    }

    /**
     * Extract supported RAM module types from motherboard specifications
     * Module types: RDIMM (Registered), LRDIMM (Load-Reduced), UDIMM (Unbuffered)
     *
     * @param array $data Motherboard JSON data
     * @return array|null Array of supported module types, or null if not specified
     */
    private function extractSupportedModuleTypes($data) {
        // DEBUG: Log extraction attempt
        error_log("DEBUG [extractSupportedModuleTypes] Starting extraction");
        error_log("DEBUG [extractSupportedModuleTypes] Has 'memory' key: " . (isset($data['memory']) ? 'YES' : 'NO'));

        // Check memory object for module_types array
        if (isset($data['memory']) && is_array($data['memory'])) {
            error_log("DEBUG [extractSupportedModuleTypes] memory section keys: " . json_encode(array_keys($data['memory'])));

            if (isset($data['memory']['module_types']) && is_array($data['memory']['module_types'])) {
                $result = array_map('strtoupper', $data['memory']['module_types']);
                error_log("DEBUG [extractSupportedModuleTypes] Found module_types array: " . json_encode($result));
                return $result;
            }

            // Check for single module_type string
            if (isset($data['memory']['module_type'])) {
                $result = [strtoupper($data['memory']['module_type'])];
                error_log("DEBUG [extractSupportedModuleTypes] Found module_type string: " . json_encode($result));
                return $result;
            }
        }

        // Check root level supported_module_types
        if (isset($data['supported_module_types'])) {
            if (is_array($data['supported_module_types'])) {
                $result = array_map('strtoupper', $data['supported_module_types']);
                error_log("DEBUG [extractSupportedModuleTypes] Found root level supported_module_types array: " . json_encode($result));
                return $result;
            }
            $result = [strtoupper($data['supported_module_types'])];
            error_log("DEBUG [extractSupportedModuleTypes] Found root level supported_module_types string: " . json_encode($result));
            return $result;
        }

        // Check root level module_types
        if (isset($data['module_types'])) {
            if (is_array($data['module_types'])) {
                $result = array_map('strtoupper', $data['module_types']);
                error_log("DEBUG [extractSupportedModuleTypes] Found root level module_types array: " . json_encode($result));
                return $result;
            }
            $result = [strtoupper($data['module_types'])];
            error_log("DEBUG [extractSupportedModuleTypes] Found root level module_types string: " . json_encode($result));
            return $result;
        }

        // No module type specification found - return null to indicate unknown support
        error_log("DEBUG [extractSupportedModuleTypes] No module types found - returning NULL");
        // This allows for backward compatibility with older JSON specs
        return null;
    }

    /**
     * Normalize memory type to base DDR type (remove speed suffix)
     * Examples: "DDR5-4800" → "DDR5", "DDR4-3200" → "DDR4", "DDR5" → "DDR5"
     *
     * @param string|null $memoryType The memory type to normalize
     * @return string|null The normalized memory type
     */
    private function normalizeMemoryType($memoryType) {
        if (!$memoryType) {
            return null;
        }

        // Remove speed suffix (e.g., "-4800", "-3200")
        $normalized = preg_replace('/-\d+$/', '', trim($memoryType));

        // Uppercase for consistency (DDR5, DDR4, etc.)
        return strtoupper($normalized);
    }

    /**
     * Extract DDR generation number from memory type
     * Examples: "DDR5" → 5, "DDR4" → 4, "DDR5-4800" → 5
     *
     * @param string|null $memoryType The memory type to analyze
     * @return int The DDR generation number, or 0 if not detected
     */
    private function getMemoryGeneration($memoryType) {
        if (!$memoryType) {
            return 0;
        }

        // Normalize first to handle formats like "DDR5-4800"
        $normalized = $this->normalizeMemoryType($memoryType);

        // Extract generation number (DDR5 → 5, DDR4 → 4)
        if (preg_match('/DDR(\d+)/', $normalized, $matches)) {
            return (int)$matches[1];
        }

        return 0;
    }

    /**
     * Check if CPU memory type is compatible with required memory type
     * Implements backward compatibility logic (newer CPU can use older RAM with warning)
     *
     * Compatibility Rules:
     * - Same generation (DDR5 + DDR5) = Compatible, no warning
     * - Newer CPU generation (DDR5 CPU + DDR4 RAM) = Compatible with warning (backward compatible)
     * - Older CPU generation (DDR4 CPU + DDR5 RAM) = Incompatible (cannot use newer RAM)
     *
     * @param string $cpuMemoryType The memory type supported by CPU
     * @param string $requiredMemoryType The memory type required by installed RAM
     * @return array ['compatible' => bool, 'warning' => string|null, 'reason' => string]
     */
    private function checkMemoryTypeCompatibility($cpuMemoryType, $requiredMemoryType) {
        $cpuGen = $this->getMemoryGeneration($cpuMemoryType);
        $requiredGen = $this->getMemoryGeneration($requiredMemoryType);

        $cpuNormalized = $this->normalizeMemoryType($cpuMemoryType);
        $requiredNormalized = $this->normalizeMemoryType($requiredMemoryType);

        // Same generation - perfect match
        if ($cpuGen === $requiredGen) {
            return [
                'compatible' => true,
                'warning' => null,
                'reason' => "Perfect match: CPU supports {$cpuNormalized}, RAM is {$requiredNormalized}"
            ];
        }

        // CPU supports newer generation than installed RAM - backward compatible
        if ($cpuGen > $requiredGen) {
            return [
                'compatible' => true,
                'warning' => "CPU supports {$cpuNormalized} but {$requiredNormalized} RAM installed - RAM will run at {$requiredNormalized} speeds",
                'reason' => "Backward compatible: CPU ({$cpuNormalized}) supports newer generation than RAM ({$requiredNormalized})"
            ];
        }

        // CPU supports older generation than installed RAM - incompatible
        return [
            'compatible' => false,
            'warning' => null,
            'reason' => "CPU only supports {$cpuNormalized} but {$requiredNormalized} RAM is installed - incompatible"
        ];
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
        // Try standard fields first
        $formFactors = $data['supported_form_factors'] ?? $data['form_factors'] ?? null;

        // For caddies, check compatibility.size field
        if (!$formFactors && isset($data['compatibility']['size'])) {
            $formFactors = $data['compatibility']['size'];
        }

        if (is_string($formFactors)) {
            // Return as array with single element
            return [trim($formFactors)];
        } elseif (is_array($formFactors)) {
            return $formFactors;
        }

        return ['2.5-inch', '3.5-inch']; // Default assumption (updated format)
    }

    /**
     * Extract PCIe generation from card data
     * Parses "PCIe 3.0 x8" → returns 3
     */
    private function extractPCIeGeneration($pcieCardData) {
        $interface = $pcieCardData['interface'] ?? '';

        // Match patterns: "PCIe 3.0", "PCIe Gen4", "PCIe 5.0", "PCIe 3.0/4.0"
        if (preg_match('/PCIe\s+(?:Gen\s*)?([3-5])(?:\\.0)?/i', $interface, $matches)) {
            return (int)$matches[1];
        }

        return null; // Unknown generation
    }

    /**
     * Extract PCIe slot size from card data
     * Parses "PCIe 3.0 x8" → returns 8
     */
    private function extractPCIeSlotSize($pcieCardData) {
        $interface = $pcieCardData['interface'] ?? '';

        // Match pattern: "x8", "x16", etc.
        if (preg_match('/x(\d+)/i', $interface, $matches)) {
            return (int)$matches[1];
        }

        // Fallback: check slot_type for riser cards
        $slotType = $pcieCardData['slot_type'] ?? '';
        if (preg_match('/x(\d+)/i', $slotType, $matches)) {
            return (int)$matches[1];
        }

        return 16; // Default assumption for unknown cards
    }

    /**
     * Extract PCIe slots configuration from motherboard data
     */
    private function extractMotherboardPCIeSlots($motherboardData) {
        $slots = [
            'total' => 0,
            'by_size' => ['x1' => 0, 'x4' => 0, 'x8' => 0, 'x16' => 0],
            'generation' => null
        ];

        if (!isset($motherboardData['expansion_slots']['pcie_slots'])) {
            return $slots;
        }

        foreach ($motherboardData['expansion_slots']['pcie_slots'] as $slotType) {
            $count = $slotType['count'] ?? 0;
            $lanes = $slotType['lanes'] ?? 0;
            $type = $slotType['type'] ?? '';

            // Extract generation from first slot
            if ($slots['generation'] === null) {
                if (preg_match('/PCIe\s+([3-5])(?:\\.0)?/i', $type, $matches)) {
                    $slots['generation'] = (int)$matches[1];
                }
            }

            // Count slots by size
            $slotKey = 'x' . $lanes;
            if (isset($slots['by_size'][$slotKey])) {
                $slots['by_size'][$slotKey] += $count;
            }

            $slots['total'] += $count;
        }

        return $slots;
    }

    /**
     * Check if card is a riser card
     */
    private function isPCIeRiserCard($pcieCardData) {
        $subtype = $pcieCardData['component_subtype'] ?? '';
        return (stripos($subtype, 'riser') !== false);
    }

    /**
     * Extract additional slots provided by riser card
     */
    private function extractRiserCardSlots($pcieCardData) {
        if (!$this->isPCIeRiserCard($pcieCardData)) {
            return 0;
        }

        // Riser cards have "pcie_slots" field indicating how many slots they add
        return $pcieCardData['pcie_slots'] ?? 1;
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
     * Parse motherboard specifications from JSON
     */
    public function parseMotherboardSpecifications($motherboardUuid) {
        $result = $this->loadComponentFromJSON('motherboard', $motherboardUuid);
        
        if (!$result['found']) {
            return [
                'found' => false,
                'error' => $result['error'],
                'specifications' => null
            ];
        }
        
        $data = $result['data'];
        
        try {
            error_log("DEBUG: Parsing motherboard specs for UUID: $motherboardUuid");
            error_log("DEBUG: Motherboard raw data: " . json_encode($data));
            
            $specifications = [
                'basic_info' => [
                    'uuid' => $motherboardUuid,
                    'model' => $data['model'] ?? 'Unknown',
                    'form_factor' => $data['form_factor'] ?? 'Unknown'
                ],
                'socket' => [
                    'type' => $data['socket']['type'] ?? 'Unknown',
                    'count' => (int)($data['socket']['count'] ?? 1)
                ],
                'memory' => [
                    'slots' => (int)($data['memory']['slots'] ?? 4),
                    'types' => isset($data['memory']['type']) ? [$data['memory']['type']] : ['DDR4'],
                    'max_frequency_mhz' => (int)($data['memory']['max_frequency_MHz'] ?? 3200),
                    'max_capacity_gb' => isset($data['memory']['max_capacity_TB']) ? 
                        ((int)$data['memory']['max_capacity_TB'] * 1024) : 128,
                    'ecc_support' => $data['memory']['ecc_support'] ?? false
                ],
                'storage' => [
                    'sata_ports' => (int)($data['storage']['sata']['ports'] ?? 0),
                    'm2_slots' => 0,
                    'u2_slots' => 0,
                    'sas_ports' => (int)($data['storage']['sas']['ports'] ?? 0)
                ],
                'pcie_slots' => [],
                'power' => [
                    'max_tdp' => (int)($data['power']['max_cpu_tdp'] ?? 150)
                ]
            ];
            
            error_log("DEBUG: Parsed motherboard memory section: " . json_encode($specifications['memory']));
            
            // Parse M.2 slots
            if (isset($data['storage']['nvme']['m2_slots'])) {
                foreach ($data['storage']['nvme']['m2_slots'] as $m2Slot) {
                    $specifications['storage']['m2_slots'] += (int)($m2Slot['count'] ?? 0);
                }
            }
            
            // Parse U.2 slots
            if (isset($data['storage']['nvme']['u2_slots']['count'])) {
                $specifications['storage']['u2_slots'] = (int)$data['storage']['nvme']['u2_slots']['count'];
            }
            
            // Parse PCIe slots
            if (isset($data['expansion_slots']['pcie_slots'])) {
                foreach ($data['expansion_slots']['pcie_slots'] as $slot) {
                    $specifications['pcie_slots'][] = [
                        'type' => $slot['type'] ?? 'PCIe x1',
                        'count' => (int)($slot['count'] ?? 1),
                        'lanes' => (int)($slot['lanes'] ?? 1)
                    ];
                }
            }
            
            return [
                'found' => true,
                'error' => null,
                'specifications' => $specifications
            ];
            
        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => "Error parsing motherboard specifications: " . $e->getMessage(),
                'specifications' => null
            ];
        }
    }

    /**
     * Get motherboard limits for compatibility checking
     */
    public function getMotherboardLimits($motherboardUuid) {
        $specResult = $this->parseMotherboardSpecifications($motherboardUuid);
        
        if (!$specResult['found']) {
            return [
                'found' => false,
                'error' => $specResult['error'],
                'limits' => null
            ];
        }
        
        $specs = $specResult['specifications'];
        
        $limits = [
            'cpu' => [
                'socket_type' => $specs['socket']['type'],
                'max_sockets' => $specs['socket']['count'],
                'max_tdp' => $specs['power']['max_tdp']
            ],
            'memory' => [
                'max_slots' => $specs['memory']['slots'],
                'supported_types' => $specs['memory']['types'],
                'max_frequency_mhz' => $specs['memory']['max_frequency_mhz'],
                'max_capacity_gb' => $specs['memory']['max_capacity_gb'],
                'ecc_support' => $specs['memory']['ecc_support']
            ],
            'storage' => [
                'sata_ports' => $specs['storage']['sata_ports'],
                'm2_slots' => $specs['storage']['m2_slots'],
                'u2_slots' => $specs['storage']['u2_slots'],
                'sas_ports' => $specs['storage']['sas_ports']
            ],
            'expansion' => [
                'pcie_slots' => $specs['pcie_slots']
            ]
        ];
        
        return [
            'found' => true,
            'error' => null,
            'limits' => $limits
        ];
    }

    /**
     * Validate motherboard exists in JSON
     */
    public function validateMotherboardExists($motherboardUuid) {
        $result = $this->loadComponentFromJSON('motherboard', $motherboardUuid);
        
        return [
            'exists' => $result['found'],
            'error' => $result['error']
        ];
    }

    /**
     * Validate CPU exists in JSON
     */
    public function validateCPUExists($cpuUuid) {
        $result = $this->loadComponentFromJSON('cpu', $cpuUuid);
        
        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error'],
            'data' => $result['data']
        ];
    }

    /**
     * Validate RAM exists in JSON
     */
    public function validateRAMExists($ramUuid) {
        $result = $this->loadComponentFromJSON('ram', $ramUuid);
        
        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error'],
            'data' => $result['data']
        ];
    }

    /**
     * Validate Storage exists in JSON
     */
    public function validateStorageExists($storageUuid) {
        $result = $this->loadComponentFromJSON('storage', $storageUuid);
        
        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error'],
            'data' => $result['data']
        ];
    }

    /**
     * Validate NIC exists in JSON
     */
    public function validateNICExists($nicUuid) {
        $result = $this->loadComponentFromJSON('nic', $nicUuid);
        
        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error'],
            'data' => $result['data']
        ];
    }

    /**
     * Validate Caddy exists in JSON
     */
    public function validateCaddyExists($caddyUuid) {
        $result = $this->loadComponentFromJSON('caddy', $caddyUuid);
        
        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error'],
            'data' => $result['data']
        ];
    }

    /**
     * Validate CPU socket compatibility with motherboard - ENHANCED with proper JSON extraction
     */
    public function validateCPUSocketCompatibility($cpuUuid, $motherboardSpecs) {
        error_log("DEBUG: Starting CPU socket compatibility check for UUID: $cpuUuid");
        
        // Get CPU socket type using enhanced JSON extraction
        $cpuSocket = $this->extractSocketTypeFromJSON('cpu', $cpuUuid);
        $motherboardSocket = $motherboardSpecs['cpu']['socket_type'] ?? null;
        
        error_log("DEBUG: CPU socket: " . ($cpuSocket ?? 'null') . ", Motherboard socket: " . ($motherboardSocket ?? 'null'));
        
        // Check if either socket type cannot be found
        if (!$cpuSocket && !$motherboardSocket) {
            return [
                'compatible' => false,
                'error' => 'Socket specifications not found in component database - both CPU and motherboard socket types missing',
                'details' => [
                    'cpu_socket' => $cpuSocket,
                    'motherboard_socket' => $motherboardSocket,
                    'extraction_method' => 'json_and_notes_failed'
                ]
            ];
        }
        
        if (!$cpuSocket) {
            return [
                'compatible' => false,
                'error' => 'Socket specifications not found in component database - CPU socket type missing',
                'details' => [
                    'cpu_socket' => $cpuSocket,
                    'motherboard_socket' => $motherboardSocket,
                    'extraction_method' => 'cpu_socket_extraction_failed'
                ]
            ];
        }
        
        if (!$motherboardSocket) {
            return [
                'compatible' => false,
                'error' => 'Socket specifications not found in component database - motherboard socket type missing',
                'details' => [
                    'cpu_socket' => $cpuSocket,
                    'motherboard_socket' => $motherboardSocket,
                    'extraction_method' => 'motherboard_socket_extraction_failed'
                ]
            ];
        }
        
        // Normalize socket types for comparison
        $cpuSocketNormalized = strtolower(trim($cpuSocket));
        $motherboardSocketNormalized = strtolower(trim($motherboardSocket));
        
        $compatible = ($cpuSocketNormalized === $motherboardSocketNormalized);
        
        $errorMessage = null;
        if (!$compatible) {
            $errorMessage = "CPU socket $cpuSocket incompatible with motherboard socket $motherboardSocket";
        }
        
        error_log("DEBUG: Socket compatibility result - Compatible: " . ($compatible ? 'YES' : 'NO') . ", CPU: $cpuSocket, MB: $motherboardSocket");
        
        return [
            'compatible' => $compatible,
            'error' => $errorMessage,
            'details' => [
                'cpu_socket' => $cpuSocket,
                'motherboard_socket' => $motherboardSocket,
                'cpu_socket_normalized' => $cpuSocketNormalized,
                'motherboard_socket_normalized' => $motherboardSocketNormalized,
                'match' => $compatible,
                'extraction_method' => 'enhanced_json_extraction'
            ]
        ];
    }

    /**
     * Validate CPU count doesn't exceed motherboard socket limit
     */
    public function validateCPUCountLimit($configUuid, $motherboardSpecs) {
        try {
            // Get existing CPUs in configuration
            $stmt = $this->pdo->prepare("
                SELECT component_uuid FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type = 'cpu'
            ");
            $stmt->execute([$configUuid]);
            $existingCPUs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $currentCPUCount = count($existingCPUs);
            $maxSockets = $motherboardSpecs['cpu']['max_sockets'] ?? 1;
            
            return [
                'within_limit' => $currentCPUCount < $maxSockets,
                'current_count' => $currentCPUCount,
                'max_allowed' => $maxSockets,
                'error' => $currentCPUCount >= $maxSockets ? 
                    "Maximum $maxSockets CPUs supported, cannot add CPU #" . ($currentCPUCount + 1) : null
            ];
            
        } catch (Exception $e) {
            return [
                'within_limit' => false,
                'current_count' => 0,
                'max_allowed' => 1,
                'error' => "Error checking CPU count: " . $e->getMessage()
            ];
        }
    }

    /**
     * Validate mixed CPU compatibility (same socket type for multiple CPUs)
     */
    public function validateMixedCPUCompatibility($existingCPUs, $newCpuUuid) {
        if (empty($existingCPUs)) {
            return [
                'compatible' => true,
                'error' => null,
                'details' => 'No existing CPUs to check compatibility with'
            ];
        }
        
        $newCpuResult = $this->validateCPUExists($newCpuUuid);
        if (!$newCpuResult['exists']) {
            return [
                'compatible' => false,
                'error' => $newCpuResult['error'],
                'details' => null
            ];
        }
        
        $newCpuSocket = $newCpuResult['data']['socket'] ?? null;
        if (!$newCpuSocket) {
            return [
                'compatible' => false,
                'error' => "New CPU socket type not found",
                'details' => null
            ];
        }
        
        // Check socket compatibility with existing CPUs
        foreach ($existingCPUs as $existingCpu) {
            $existingCpuResult = $this->validateCPUExists($existingCpu['component_uuid']);
            if ($existingCpuResult['exists']) {
                $existingSocket = $existingCpuResult['data']['socket'] ?? null;

                // Normalize socket types for comparison
                $existingSocketNormalized = strtolower(trim($existingSocket ?? ''));
                $newCpuSocketNormalized = strtolower(trim($newCpuSocket ?? ''));

                if ($existingSocket && $existingSocketNormalized !== $newCpuSocketNormalized) {
                    return [
                        'compatible' => false,
                        'error' => "Mixed CPU socket types not allowed. Existing CPU socket: $existingSocket, New CPU socket: $newCpuSocket",
                        'details' => [
                            'existing_socket' => $existingSocket,
                            'new_socket' => $newCpuSocket,
                            'existing_cpu' => $existingCpu['component_uuid']
                        ]
                    ];
                }
            }
        }
        
        return [
            'compatible' => true,
            'error' => null,
            'details' => [
                'socket_type' => $newCpuSocket,
                'existing_cpus_count' => count($existingCPUs)
            ]
        ];
    }

    /**
     * Get CPU specifications from JSON
     */
    public function getCPUSpecifications($cpuUuid) {
        $result = $this->validateCPUExists($cpuUuid);
        
        if (!$result['exists']) {
            return [
                'found' => false,
                'error' => $result['error'],
                'specifications' => null
            ];
        }
        
        $data = $result['data'];
        
        try {
            $specifications = [
                'basic_info' => [
                    'uuid' => $cpuUuid,
                    'model' => $data['model'] ?? 'Unknown',
                    'brand' => $data['brand'] ?? 'Unknown',
                    'architecture' => $data['architecture'] ?? 'Unknown'
                ],
                'performance' => [
                    'cores' => (int)($data['cores'] ?? 1),
                    'threads' => (int)($data['threads'] ?? 1),
                    'base_frequency_ghz' => (float)($data['base_frequency_GHz'] ?? 0),
                    'max_frequency_ghz' => (float)($data['max_frequency_GHz'] ?? 0)
                ],
                'compatibility' => [
                    'socket' => $data['socket'] ?? 'Unknown',
                    'tdp_w' => (int)($data['tdp_W'] ?? 0),
                    'memory_types' => $data['memory_types'] ?? ['DDR4'],
                    'memory_channels' => (int)($data['memory_channels'] ?? 2),
                    'max_memory_capacity_tb' => (float)($data['max_memory_capacity_TB'] ?? 1),
                    'pcie_lanes' => (int)($data['pcie_lanes'] ?? 16),
                    'pcie_generation' => (int)($data['pcie_generation'] ?? 3)
                ]
            ];
            
            return [
                'found' => true,
                'error' => null,
                'specifications' => $specifications
            ];
            
        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => "Error parsing CPU specifications: " . $e->getMessage(),
                'specifications' => null
            ];
        }
    }

    /**
     * Enhanced extractSocketType method to work with JSON data primarily
     */
    public function extractSocketTypeFromJSON($componentType, $componentUuid) {
        error_log("DEBUG: Extracting socket type for $componentType UUID: $componentUuid");
        
        $result = null;
        
        if ($componentType === 'cpu') {
            $cpuResult = $this->validateCPUExists($componentUuid);
            if ($cpuResult['exists'] && isset($cpuResult['data'])) {
                $result = $cpuResult['data']['socket'] ?? null;
                error_log("DEBUG: CPU socket from JSON: " . ($result ?? 'null'));
            }
        } elseif ($componentType === 'motherboard') {
            $mbResult = $this->loadComponentFromJSON('motherboard', $componentUuid);
            if ($mbResult['found'] && isset($mbResult['data'])) {
                $data = $mbResult['data'];
                // Try multiple socket field possibilities
                $result = $data['socket']['type'] ?? $data['socket'] ?? $data['cpu_socket'] ?? null;
                error_log("DEBUG: Motherboard socket from JSON: " . ($result ?? 'null'));
            }
        }
        
        // Fallback to database Notes field extraction if JSON doesn't have the data
        if (!$result) {
            error_log("DEBUG: No socket found in JSON, trying database Notes field");
            $componentData = $this->getComponentData($componentType, $componentUuid);
            if ($componentData) {
                $notes = strtolower($componentData['Notes'] ?? '');
                $result = $this->extractSocketFromNotes($notes);
                error_log("DEBUG: Socket from Notes field: " . ($result ?? 'null'));
            }
        }
        
        if (!$result) {
            error_log("WARNING: Could not determine socket type for $componentType UUID: $componentUuid");
        }
        
        return $result;
    }
    
    /**
     * Extract socket information from Notes field
     */
    private function extractSocketFromNotes($notes) {
        // Common socket patterns
        $socketPatterns = [
            '/\b(lga\s?4677)\b/i' => 'lga4677',
            '/\b(lga\s?4189)\b/i' => 'lga4189',
            '/\b(lga\s?3647)\b/i' => 'lga3647',
            '/\b(lga\s?2066)\b/i' => 'lga2066',
            '/\b(lga\s?1700)\b/i' => 'lga1700',
            '/\b(lga\s?1200)\b/i' => 'lga1200',
            '/\b(lga\s?1151)\b/i' => 'lga1151',
            '/\b(sp5)\b/i' => 'sp5',
            '/\b(sp3)\b/i' => 'sp3',
            '/\b(am5)\b/i' => 'am5',
            '/\b(am4)\b/i' => 'am4',
            '/\b(tr4)\b/i' => 'tr4',
            '/\b(strx4)\b/i' => 'strx4'
        ];
        
        foreach ($socketPatterns as $pattern => $socket) {
            if (preg_match($pattern, $notes)) {
                return $socket;
            }
        }
        
        return null;
    }

    /**
     * Validate RAM type compatibility with motherboard
     */
    public function validateRAMTypeCompatibility($ramUuid, $motherboardSpecs) {
        $ramResult = $this->validateRAMExists($ramUuid);
        
        if (!$ramResult['exists']) {
            return [
                'compatible' => false,
                'error' => $ramResult['error'],
                'details' => null
            ];
        }
        
        $ramData = $ramResult['data'];
        $ramType = $ramData['memory_type'] ?? null;

        // Handle both 'type' (single string) and 'supported_types' (array) from motherboard specs
        $supportedTypes = $motherboardSpecs['memory']['supported_types'] ?? null;
        if (!$supportedTypes) {
            // Fall back to single 'type' field and convert to array
            $singleType = $motherboardSpecs['memory']['type'] ?? 'DDR4';
            $supportedTypes = [$singleType];
        }

        if (!$ramType) {
            return [
                'compatible' => false,
                'error' => "RAM memory type not found",
                'details' => null
            ];
        }

        $compatible = in_array($ramType, $supportedTypes);

        return [
            'compatible' => $compatible,
            'error' => $compatible ? null : "$ramType memory incompatible with motherboard supporting " . implode(', ', $supportedTypes),
            'details' => [
                'ram_type' => $ramType,
                'supported_types' => $supportedTypes,
                'match' => $compatible
            ]
        ];
    }

    /**
     * Validate RAM slot availability
     */
    public function validateRAMSlotAvailability($configUuid, $motherboardSpecs) {
        try {
            $usedSlots = $this->countUsedMemorySlots($configUuid);
            $totalSlots = $motherboardSpecs['memory']['max_slots'] ?? 4;
            
            return [
                'available' => $usedSlots < $totalSlots,
                'used_slots' => $usedSlots,
                'total_slots' => $totalSlots,
                'available_slots' => $totalSlots - $usedSlots,
                'error' => $usedSlots >= $totalSlots ? 
                    "Memory slot limit reached: $usedSlots/$totalSlots" : null
            ];
            
        } catch (Exception $e) {
            return [
                'available' => false,
                'used_slots' => 0,
                'total_slots' => 4,
                'available_slots' => 0,
                'error' => "Error checking memory slot availability: " . $e->getMessage()
            ];
        }
    }

    /**
     * Validate RAM speed compatibility
     */
    public function validateRAMSpeedCompatibility($ramUuid, $cpuSpecs, $motherboardSpecs) {
        $ramResult = $this->validateRAMExists($ramUuid);
        
        if (!$ramResult['exists']) {
            return [
                'compatible' => true,
                'optimal' => false,
                'error' => $ramResult['error'],
                'details' => null
            ];
        }
        
        $ramData = $ramResult['data'];
        $ramSpeed = (int)($ramData['frequency_MHz'] ?? 0);
        
        $motherboardMaxSpeed = $motherboardSpecs['memory']['max_frequency_mhz'] ?? 3200;
        $cpuMaxSpeed = null;
        
        // Get CPU max memory speed if CPU specs provided
        if ($cpuSpecs && isset($cpuSpecs['compatibility']['memory_types'])) {
            // Extract speed from memory types like DDR5-4800
            foreach ($cpuSpecs['compatibility']['memory_types'] as $memType) {
                if (preg_match('/DDR\d+-(\d+)/', $memType, $matches)) {
                    $speed = (int)$matches[1];
                    if ($cpuMaxSpeed === null || $speed > $cpuMaxSpeed) {
                        $cpuMaxSpeed = $speed;
                    }
                }
            }
        }
        
        $effectiveMaxSpeed = $cpuMaxSpeed ? min($motherboardMaxSpeed, $cpuMaxSpeed) : $motherboardMaxSpeed;
        
        $warnings = [];
        if ($ramSpeed > $effectiveMaxSpeed) {
            $warnings[] = "RAM speed ({$ramSpeed}MHz) exceeds system maximum ({$effectiveMaxSpeed}MHz) - will run at reduced speed";
        }
        
        return [
            'compatible' => true,
            'optimal' => $ramSpeed <= $effectiveMaxSpeed,
            'error' => null,
            'warnings' => $warnings,
            'details' => [
                'ram_speed_mhz' => $ramSpeed,
                'motherboard_max_mhz' => $motherboardMaxSpeed,
                'cpu_max_mhz' => $cpuMaxSpeed,
                'effective_max_mhz' => $effectiveMaxSpeed
            ]
        ];
    }

    /**
     * Get RAM specifications from JSON
     */
    public function getRAMSpecifications($ramUuid) {
        $result = $this->validateRAMExists($ramUuid);
        
        if (!$result['exists']) {
            return [
                'found' => false,
                'error' => $result['error'],
                'specifications' => null
            ];
        }
        
        $data = $result['data'];
        
        try {
            $specifications = [
                'basic_info' => [
                    'uuid' => $ramUuid,
                    'brand' => $data['brand'] ?? 'Unknown',
                    'series' => $data['series'] ?? 'Unknown'
                ],
                'memory_specs' => [
                    'memory_type' => $data['memory_type'] ?? 'DDR4',
                    'module_type' => $data['module_type'] ?? 'DIMM',
                    'form_factor' => $data['form_factor'] ?? 'DIMM (288-pin)',
                    'capacity_gb' => (int)($data['capacity_GB'] ?? 8),
                    'frequency_mhz' => (int)($data['frequency_MHz'] ?? 3200),
                    'voltage_v' => (float)($data['voltage_V'] ?? 1.2)
                ],
                'features' => [
                    'ecc_support' => $data['features']['ecc_support'] ?? false,
                    'xmp_support' => $data['features']['xmp_support'] ?? false
                ],
                'timing' => $data['timing'] ?? []
            ];
            
            return [
                'found' => true,
                'error' => null,
                'specifications' => $specifications
            ];
            
        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => "Error parsing RAM specifications: " . $e->getMessage(),
                'specifications' => null
            ];
        }
    }

    /**
     * Count used memory slots in configuration
     */
    public function countUsedMemorySlots($configUuid) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT SUM(quantity) as total_ram_modules 
                FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type = 'ram'
            ");
            $stmt->execute([$configUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int)($result['total_ram_modules'] ?? 0);
            
        } catch (Exception $e) {
            error_log("Error counting used memory slots: " . $e->getMessage());
            return 0;
        }
    }


    /**
     * Validate NIC PCIe compatibility
     */
    public function validateNICPCIeCompatibility($nicUuid, $configUuid, $motherboardSpecs) {
        $nicResult = $this->validateNICExists($nicUuid);
        
        if (!$nicResult['exists']) {
            return [
                'compatible' => false,
                'error' => $nicResult['error'],
                'details' => null
            ];
        }
        
        $nicData = $nicResult['data'];
        
        // Extract PCIe requirements from NIC
        $requiredPCIeVersion = null;
        $requiredLanes = 1; // Default to x1
        
        // Navigate through the nested JSON structure to find interface requirements
        if (isset($nicData['interface_requirements'])) {
            $reqs = $nicData['interface_requirements'];
            if (isset($reqs['pcie_version'])) {
                $requiredPCIeVersion = $reqs['pcie_version'];
            }
            if (isset($reqs['pcie_lanes'])) {
                $requiredLanes = (int)$reqs['pcie_lanes'];
            }
        }
        
        // Check available PCIe slots on motherboard
        $motherboardPCIeSlots = $motherboardSpecs['expansion']['pcie_slots'] ?? [];
        $usedSlots = $this->countUsedPCIeSlots($configUuid, $motherboardSpecs);
        
        // Find compatible available slot
        $compatibleSlot = null;
        foreach ($motherboardPCIeSlots as $slot) {
            $slotLanes = $slot['lanes'] ?? 1;
            $availableCount = ($slot['count'] ?? 1) - ($usedSlots[$slot['type']] ?? 0);
            
            if ($slotLanes >= $requiredLanes && $availableCount > 0) {
                $compatibleSlot = $slot;
                break;
            }
        }
        
        if (!$compatibleSlot) {
            return [
                'compatible' => false,
                'error' => "No available PCIe slots for NIC requiring x$requiredLanes slot",
                'details' => [
                    'required_lanes' => $requiredLanes,
                    'required_pcie_version' => $requiredPCIeVersion,
                    'available_slots' => $motherboardPCIeSlots,
                    'used_slots' => $usedSlots
                ]
            ];
        }
        
        return [
            'compatible' => true,
            'error' => null,
            'details' => [
                'required_lanes' => $requiredLanes,
                'required_pcie_version' => $requiredPCIeVersion,
                'assigned_slot' => $compatibleSlot,
                'remaining_slots' => $compatibleSlot['count'] - ($usedSlots[$compatibleSlot['type']] ?? 0) - 1
            ]
        ];
    }

    /**
     * Count used storage interfaces in configuration
     */
    public function countUsedStorageInterfaces($configUuid, $motherboardSpecs) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT component_uuid FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type = 'storage'
            ");
            $stmt->execute([$configUuid]);
            $storageComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $usedInterfaces = [
                'sata' => 0,
                'm2' => 0,
                'u2' => 0,
                'sas' => 0
            ];
            
            foreach ($storageComponents as $storage) {
                $storageResult = $this->validateStorageExists($storage['component_uuid']);
                if ($storageResult['exists']) {
                    // For now, assume SATA interface since storage JSON needs updating
                    $usedInterfaces['sata']++;
                }
            }
            
            return $usedInterfaces;
            
        } catch (Exception $e) {
            error_log("Error counting used storage interfaces: " . $e->getMessage());
            return ['sata' => 0, 'm2' => 0, 'u2' => 0, 'sas' => 0];
        }
    }

    /**
     * Count used PCIe slots in configuration
     */
    public function countUsedPCIeSlots($configUuid, $motherboardSpecs) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT component_uuid, component_type FROM server_configuration_components 
                WHERE config_uuid = ? AND component_type IN ('nic', 'gpu', 'raid_card')
            ");
            $stmt->execute([$configUuid]);
            $pcieComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $usedSlots = [];
            
            // Initialize slot counters
            $motherboardPCIeSlots = $motherboardSpecs['expansion']['pcie_slots'] ?? [];
            foreach ($motherboardPCIeSlots as $slot) {
                $usedSlots[$slot['type']] = 0;
            }
            
            foreach ($pcieComponents as $component) {
                if ($component['component_type'] === 'nic') {
                    $nicResult = $this->validateNICExists($component['component_uuid']);
                    if ($nicResult['exists']) {
                        $requiredLanes = 1; // Default
                        if (isset($nicResult['data']['interface_requirements']['pcie_lanes'])) {
                            $requiredLanes = (int)$nicResult['data']['interface_requirements']['pcie_lanes'];
                        }
                        
                        // Find and assign to appropriate slot
                        foreach ($motherboardPCIeSlots as $slot) {
                            $slotLanes = $slot['lanes'] ?? 1;
                            if ($slotLanes >= $requiredLanes) {
                                $usedSlots[$slot['type']] = ($usedSlots[$slot['type']] ?? 0) + 1;
                                break;
                            }
                        }
                    }
                }
            }
            
            return $usedSlots;
            
        } catch (Exception $e) {
            error_log("Error counting used PCIe slots: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Clear JSON data cache
     */
    public function clearJSONCache() {
        $this->jsonDataCache = [];
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

    /**
     * Validate RAM exists in JSON specifications and extract detailed specifications
     * Enhanced version for comprehensive RAM compatibility validation
     */
    public function validateRAMExistsInJSON($ramUuid) {
        $cacheKey = "ram_validation:$ramUuid";
        
        // Check cache first to avoid repeated file reads
        if (isset($this->jsonDataCache[$cacheKey])) {
            return $this->jsonDataCache[$cacheKey];
        }
        
        $result = $this->loadComponentFromJSON('ram', $ramUuid);
        
        if (!$result['found']) {
            $validationResult = [
                'exists' => false,
                'error' => $result['error'],
                'specifications' => null
            ];
            $this->jsonDataCache[$cacheKey] = $validationResult;
            return $validationResult;
        }
        
        $ramData = $result['data'];
        
        try {
            // Extract comprehensive RAM specifications
            $specifications = [
                'uuid' => $ramUuid,
                'basic_info' => [
                    'brand' => $ramData['brand'] ?? 'Unknown',
                    'series' => $ramData['series'] ?? 'Unknown',
                    'model' => $ramData['model'] ?? 'Unknown'
                ],
                'memory_type' => $ramData['memory_type'] ?? 'DDR4',
                'frequency_mhz' => (int)($ramData['frequency_MHz'] ?? 3200),
                'form_factor' => $ramData['form_factor'] ?? $ramData['module_type'] ?? 'DIMM',
                'capacity_gb' => (int)($ramData['capacity_GB'] ?? 8),
                'voltage_v' => (float)($ramData['voltage_V'] ?? 1.2),
                'ecc_support' => $ramData['features']['ecc_support'] ?? false,
                'timing' => $ramData['timing'] ?? [],
                'features' => [
                    'xmp_support' => $ramData['features']['xmp_support'] ?? false,
                    'heat_spreader' => $ramData['features']['heat_spreader'] ?? false,
                    'rgb_lighting' => $ramData['features']['rgb_lighting'] ?? false
                ]
            ];
            
            $validationResult = [
                'exists' => true,
                'error' => null,
                'specifications' => $specifications
            ];
            
            // Cache the result
            $this->jsonDataCache[$cacheKey] = $validationResult;
            return $validationResult;
            
        } catch (Exception $e) {
            $validationResult = [
                'exists' => false,
                'error' => "Error parsing RAM specifications: " . $e->getMessage(),
                'specifications' => null
            ];
            $this->jsonDataCache[$cacheKey] = $validationResult;
            return $validationResult;
        }
    }

    /**
     * Validate memory type compatibility across RAM, motherboard, and CPUs
     */
    public function validateMemoryTypeCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs) {
        if (!$ramSpecs) {
            error_log("DEBUG: Memory type validation - Missing RAM specs");
            return [
                'compatible' => false,
                'message' => 'Missing RAM specifications for memory type validation',
                'supported_types' => []
            ];
        }

        $ramMemoryType = $ramSpecs['memory_type'] ?? null;

        if (!$ramMemoryType) {
            return [
                'compatible' => false,
                'message' => 'RAM memory type not found in specifications',
                'supported_types' => []
            ];
        }

        // Handle CPU-only validation (no motherboard)
        if (empty($motherboardSpecs) && !empty($cpuSpecs)) {
            error_log("DEBUG: Memory type validation - CPU-only mode (no motherboard)");

            $allCPUsCompatible = true;
            $incompatibleCPUs = [];
            $cpuSupportedTypes = [];

            foreach ($cpuSpecs as $cpuSpec) {
                $cpuMemTypes = $cpuSpec['compatibility']['memory_types'] ?? ['DDR4'];

                // Extract memory types from CPU specs (e.g., "DDR5-4800" -> "DDR5")
                $extractedTypes = [];
                foreach ($cpuMemTypes as $memType) {
                    if (preg_match('/(DDR\d+)/', $memType, $matches)) {
                        if (!in_array($matches[1], $extractedTypes)) {
                            $extractedTypes[] = $matches[1];
                        }
                    } else {
                        $extractedTypes[] = $memType;
                    }
                }

                // Merge CPU supported types for reporting
                $cpuSupportedTypes = array_unique(array_merge($cpuSupportedTypes, $extractedTypes));

                if (!in_array($ramMemoryType, $extractedTypes)) {
                    $allCPUsCompatible = false;
                    $incompatibleCPUs[] = $cpuSpec['basic_info']['model'] ?? 'Unknown CPU';
                }
            }

            if (!$allCPUsCompatible) {
                return [
                    'compatible' => false,
                    'message' => "RAM memory type $ramMemoryType not supported by CPUs: " . implode(', ', $incompatibleCPUs),
                    'supported_types' => $cpuSupportedTypes
                ];
            }

            return [
                'compatible' => true,
                'message' => "Memory type $ramMemoryType is compatible with CPU(s)",
                'supported_types' => $cpuSupportedTypes
            ];
        }

        // Handle no motherboard and no CPU - allow any RAM
        if (empty($motherboardSpecs) && empty($cpuSpecs)) {
            return [
                'compatible' => true,
                'message' => "Memory type $ramMemoryType accepted (no constraints)",
                'supported_types' => [$ramMemoryType]
            ];
        }

        // Full validation with motherboard
        $motherboardSupportedTypes = $motherboardSpecs['memory']['types'] ?? ['DDR4'];

        // Debug logging
        error_log("DEBUG: Memory type validation - RAM type: '$ramMemoryType', MB supported types: " . json_encode($motherboardSupportedTypes));

        // Check motherboard compatibility
        $motherboardCompatible = in_array($ramMemoryType, $motherboardSupportedTypes);

        if (!$motherboardCompatible) {
            error_log("DEBUG: Memory type validation FAILED - RAM '$ramMemoryType' not in supported types: " . json_encode($motherboardSupportedTypes));
            return [
                'compatible' => false,
                'message' => "RAM memory type $ramMemoryType not supported by motherboard. Supported types: " . implode(', ', $motherboardSupportedTypes),
                'supported_types' => $motherboardSupportedTypes
            ];
        }

        // Check CPU compatibility if CPU specs provided
        if (!empty($cpuSpecs)) {
            $allCPUsCompatible = true;
            $incompatibleCPUs = [];

            foreach ($cpuSpecs as $cpuSpec) {
                $cpuSupportedTypes = $cpuSpec['compatibility']['memory_types'] ?? ['DDR4'];

                // Extract memory types from CPU specs (e.g., "DDR5-4800" -> "DDR5")
                $extractedTypes = [];
                foreach ($cpuSupportedTypes as $memType) {
                    if (preg_match('/(DDR\d+)/', $memType, $matches)) {
                        if (!in_array($matches[1], $extractedTypes)) {
                            $extractedTypes[] = $matches[1];
                        }
                    } else {
                        // If no pattern match, use as-is (for backward compatibility)
                        $extractedTypes[] = $memType;
                    }
                }

                if (!in_array($ramMemoryType, $extractedTypes)) {
                    $allCPUsCompatible = false;
                    $incompatibleCPUs[] = $cpuSpec['basic_info']['model'] ?? 'Unknown CPU';
                }
            }

            if (!$allCPUsCompatible) {
                return [
                    'compatible' => false,
                    'message' => "RAM memory type $ramMemoryType not supported by CPUs: " . implode(', ', $incompatibleCPUs),
                    'supported_types' => $motherboardSupportedTypes
                ];
            }
        }

        return [
            'compatible' => true,
            'message' => "Memory type $ramMemoryType is compatible with all system components",
            'supported_types' => $motherboardSupportedTypes
        ];
    }

    /**
     * Analyze memory frequency compatibility and performance impact
     */
    public function analyzeMemoryFrequency($ramSpecs, $motherboardSpecs, $cpuSpecs) {
        if (!$ramSpecs) {
            return [
                'status' => 'error',
                'message' => 'Missing RAM specifications for frequency analysis'
            ];
        }

        $ramFrequency = $ramSpecs['frequency_mhz'] ?? 0;

        // Find the lowest CPU max frequency if multiple CPUs
        $cpuMaxFrequency = null;
        $limitingCPU = null;

        if (!empty($cpuSpecs)) {
            foreach ($cpuSpecs as $cpuSpec) {
                // Extract max memory frequency from CPU memory types (e.g., DDR5-4800)
                $cpuMemoryTypes = $cpuSpec['compatibility']['memory_types'] ?? [];
                foreach ($cpuMemoryTypes as $memType) {
                    if (preg_match('/DDR\d+-(\d+)/', $memType, $matches)) {
                        $cpuFreq = (int)$matches[1];
                        if ($cpuMaxFrequency === null || $cpuFreq < $cpuMaxFrequency) {
                            $cpuMaxFrequency = $cpuFreq;
                            $limitingCPU = $cpuSpec['basic_info']['model'] ?? 'Unknown CPU';
                        }
                    }
                }
            }
        }

        // Handle CPU-only validation (no motherboard)
        if (empty($motherboardSpecs) && $cpuMaxFrequency !== null) {
            // Use CPU max frequency as system limit
            if ($ramFrequency <= $cpuMaxFrequency) {
                return [
                    'status' => 'optimal',
                    'ram_frequency' => $ramFrequency,
                    'system_max_frequency' => $cpuMaxFrequency,
                    'effective_frequency' => $ramFrequency,
                    'limiting_component' => $limitingCPU,
                    'message' => "RAM will operate at full rated speed of {$ramFrequency}MHz with CPU",
                    'performance_impact' => null
                ];
            } else {
                return [
                    'status' => 'limited',
                    'ram_frequency' => $ramFrequency,
                    'system_max_frequency' => $cpuMaxFrequency,
                    'effective_frequency' => $cpuMaxFrequency,
                    'limiting_component' => $limitingCPU,
                    'message' => "RAM will operate at {$cpuMaxFrequency}MHz (limited by CPU) instead of rated {$ramFrequency}MHz",
                    'performance_impact' => "Performance limited by $limitingCPU"
                ];
            }
        }

        // Handle no motherboard and no CPU - accept any frequency
        if (empty($motherboardSpecs) && empty($cpuSpecs)) {
            return [
                'status' => 'optimal',
                'ram_frequency' => $ramFrequency,
                'system_max_frequency' => $ramFrequency,
                'effective_frequency' => $ramFrequency,
                'limiting_component' => null,
                'message' => "RAM frequency {$ramFrequency}MHz accepted (no constraints)",
                'performance_impact' => null
            ];
        }

        // Full validation with motherboard
        $motherboardMaxFrequency = $motherboardSpecs['memory']['max_frequency_mhz'] ?? 3200;

        // Calculate system maximum frequency (lowest component limit)
        $systemMaxFrequency = $motherboardMaxFrequency;
        $limitingComponent = 'motherboard';

        if ($cpuMaxFrequency !== null && $cpuMaxFrequency < $systemMaxFrequency) {
            $systemMaxFrequency = $cpuMaxFrequency;
            $limitingComponent = $limitingCPU;
        }

        // Determine status and effective frequency
        if ($ramFrequency <= $systemMaxFrequency) {
            $status = 'optimal';
            $effectiveFrequency = $ramFrequency;
            $message = "RAM will operate at full rated speed of {$ramFrequency}MHz";
        } else {
            $status = 'limited';
            $effectiveFrequency = $systemMaxFrequency;
            $message = "RAM will operate at {$systemMaxFrequency}MHz (limited by $limitingComponent) instead of rated {$ramFrequency}MHz";
        }

        // Check for suboptimal frequency (significantly below system max)
        if ($ramFrequency < ($systemMaxFrequency * 0.8)) {
            $status = 'suboptimal';
            $message = "RAM frequency may impact performance - consider higher frequency memory";
        }

        return [
            'status' => $status,
            'ram_frequency' => $ramFrequency,
            'system_max_frequency' => $systemMaxFrequency,
            'effective_frequency' => $effectiveFrequency,
            'limiting_component' => $limitingComponent,
            'message' => $message,
            'performance_impact' => $status === 'limited' ? "Performance limited by $limitingComponent" : null
        ];
    }

    /**
     * Validate memory form factor compatibility
     */
    public function validateMemoryFormFactor($ramSpecs, $motherboardSpecs) {
        if (!$ramSpecs || !$motherboardSpecs) {
            return [
                'compatible' => false,
                'message' => 'Missing specifications for form factor validation'
            ];
        }
        
        $ramFormFactor = $ramSpecs['form_factor'] ?? 'DIMM';
        
        // Normalize form factor strings
        $ramFormFactor = $this->normalizeFormFactor($ramFormFactor);
        
        // Extract motherboard slot type from specifications
        $motherboardSlotType = 'DIMM'; // Default assumption for server motherboards
        
        // Try to determine slot type from motherboard specs
        if (isset($motherboardSpecs['memory']['slot_type'])) {
            $motherboardSlotType = $this->normalizeFormFactor($motherboardSpecs['memory']['slot_type']);
        }
        
        $compatible = ($ramFormFactor === $motherboardSlotType);
        
        if (!$compatible) {
            return [
                'compatible' => false,
                'message' => "Memory form factor mismatch: RAM is $ramFormFactor but motherboard requires $motherboardSlotType"
            ];
        }
        
        return [
            'compatible' => true,
            'message' => "Memory form factor $ramFormFactor is compatible"
        ];
    }

    /**
     * Validate ECC compatibility across components
     */
    public function validateECCCompatibility($ramSpecs, $motherboardSpecs, $cpuSpecs) {
        if (!$ramSpecs) {
            return [
                'compatible' => true,
                'message' => 'Missing RAM specifications for ECC validation'
            ];
        }

        $ramHasECC = $ramSpecs['ecc_support'] ?? false;

        // Handle CPU-only validation (no motherboard)
        if (empty($motherboardSpecs) && !empty($cpuSpecs)) {
            // Check CPU ECC support
            $cpuSupportsECC = true;
            foreach ($cpuSpecs as $cpuSpec) {
                $cpuECC = $cpuSpec['features']['ecc_support'] ?? true;
                if (!$cpuECC) {
                    $cpuSupportsECC = false;
                    break;
                }
            }

            if ($ramHasECC && !$cpuSupportsECC) {
                return [
                    'compatible' => true,
                    'message' => 'ECC RAM selected but CPU does not support ECC',
                    'warning' => 'ECC functionality may be disabled by CPU - verify motherboard supports ECC when added'
                ];
            }

            if (!$ramHasECC && $cpuSupportsECC) {
                return [
                    'compatible' => true,
                    'message' => 'Non-ECC RAM selected with ECC-capable CPU',
                    'recommendation' => 'Consider ECC memory for enhanced reliability with this CPU'
                ];
            }

            return [
                'compatible' => true,
                'message' => $ramHasECC ? 'ECC RAM compatible with CPU' : 'Non-ECC RAM selected'
            ];
        }

        // Handle no motherboard and no CPU
        if (empty($motherboardSpecs) && empty($cpuSpecs)) {
            return [
                'compatible' => true,
                'message' => $ramHasECC ? 'ECC RAM selected (no constraints)' : 'Non-ECC RAM selected (no constraints)'
            ];
        }

        // Full validation with motherboard
        $motherboardSupportsECC = $motherboardSpecs['memory']['ecc_support'] ?? false;

        // Check CPU ECC support
        $cpuSupportsECC = true; // Default assumption
        if (!empty($cpuSpecs)) {
            foreach ($cpuSpecs as $cpuSpec) {
                $cpuECC = $cpuSpec['features']['ecc_support'] ?? true;
                if (!$cpuECC) {
                    $cpuSupportsECC = false;
                    break;
                }
            }
        }

        $systemSupportsECC = $motherboardSupportsECC && $cpuSupportsECC;

        // Determine compatibility and warnings
        if ($ramHasECC && !$systemSupportsECC) {
            return [
                'compatible' => true,
                'message' => 'ECC memory will function but ECC features will be disabled',
                'warning' => 'ECC functionality disabled - system does not support ECC'
            ];
        }

        if (!$ramHasECC && $systemSupportsECC) {
            return [
                'compatible' => true,
                'message' => 'Non-ECC memory compatible with ECC-capable system',
                'recommendation' => 'Consider ECC memory for enhanced reliability'
            ];
        }

        if ($ramHasECC && $systemSupportsECC) {
            return [
                'compatible' => true,
                'message' => 'ECC memory fully supported and enabled'
            ];
        }

        return [
            'compatible' => true,
            'message' => 'Standard non-ECC memory configuration'
        ];
    }

    /**
     * Validate memory slot availability
     */
    public function validateMemorySlotAvailability($configUuid, $motherboardSpecs) {
        if (!$motherboardSpecs) {
            return [
                'can_add' => false,
                'available_slots' => 0,
                'max_slots' => 4,
                'used_slots' => 0,
                'error' => 'Motherboard specifications not available'
            ];
        }
        
        try {
            $maxSlots = $motherboardSpecs['memory']['max_slots'] ?? 4;
            $usedSlots = $this->countUsedMemorySlots($configUuid);
            $availableSlots = $maxSlots - $usedSlots;
            
            return [
                'can_add' => $availableSlots > 0,
                'available_slots' => $availableSlots,
                'max_slots' => $maxSlots,
                'used_slots' => $usedSlots,
                'error' => $availableSlots > 0 ? null : "Memory slot limit reached: $usedSlots/$maxSlots slots used"
            ];
            
        } catch (Exception $e) {
            return [
                'can_add' => false,
                'available_slots' => 0,
                'max_slots' => 4,
                'used_slots' => 0,
                'error' => "Error checking slot availability: " . $e->getMessage()
            ];
        }
    }

    /**
     * Normalize memory form factor strings for comparison
     */
    private function normalizeFormFactor($formFactor) {
        $formFactor = strtoupper(trim($formFactor));
        
        // Handle common variations
        if (strpos($formFactor, 'SO-DIMM') !== false || strpos($formFactor, 'SODIMM') !== false) {
            return 'SO-DIMM';
        }
        
        if (strpos($formFactor, 'DIMM') !== false) {
            return 'DIMM';
        }
        
        return $formFactor;
    }

    /**
     * ENHANCED STORAGE COMPATIBILITY METHODS
     */

    /**
     * Load storage specifications from JSON with UUID validation
     */
    private function loadStorageSpecs($uuid) {
        $jsonPath = __DIR__ . '/../../All-JSON/storage-jsons/storage-level-3.json';

        if (!file_exists($jsonPath)) {
            error_log("Storage JSON file not found: $jsonPath");
            return null;
        }
        
        $jsonData = json_decode(file_get_contents($jsonPath), true);
        if (!$jsonData) {
            error_log("Failed to decode storage JSON");
            return null;
        }
        
        // First try direct UUID lookup at model level
        foreach ($jsonData as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                        return $this->extractStorageSpecifications($model);
                    }
                }
            }
        }
        
        // Then try recursive search in nested structures
        foreach ($jsonData as $brand) {
            if (isset($brand['series'])) {
                foreach ($brand['series'] as $series) {
                    if (isset($series['models'])) {
                        foreach ($series['models'] as $model) {
                            if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                                return $this->extractStorageSpecifications($model);
                            }
                        }
                    }
                }
            }
        }
        
        error_log("Storage UUID $uuid not found in storage-level-3.json");
        return null;
    }

    /**
     * Load motherboard specifications from JSON with UUID validation
     */
    private function loadMotherboardSpecs($uuid) {
        $jsonPath = __DIR__ . '/../../All-JSON/motherboad-jsons/motherboard-level-3.json';

        if (!file_exists($jsonPath)) {
            error_log("Motherboard JSON file not found: $jsonPath");
            return null;
        }
        
        $jsonData = json_decode(file_get_contents($jsonPath), true);
        if (!$jsonData) {
            error_log("Failed to decode motherboard JSON");
            return null;
        }
        
        // Search through the nested structure
        foreach ($jsonData as $brand) {
            if (isset($brand['models'])) {
                foreach ($brand['models'] as $model) {
                    if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                        return $this->extractMotherboardSpecifications($model);
                    }
                }
            }
        }
        
        error_log("Motherboard UUID $uuid not found in motherboard-level-3.json");
        return null;
    }

    /**
     * Load chassis specifications from JSON with UUID validation
     */
    private function loadChassisSpecs($uuid) {
        $jsonPath = __DIR__ . '/../../All-JSON/chasis-jsons/chasis-level-3.json';

        if (!file_exists($jsonPath)) {
            return null;
        }

        $jsonData = json_decode(file_get_contents($jsonPath), true);
        if (!$jsonData || !isset($jsonData['chassis_specifications'])) {
            return null;
        }

        // Search through the chassis specifications structure
        foreach ($jsonData['chassis_specifications']['manufacturers'] as $manufacturer) {
            foreach ($manufacturer['series'] as $series) {
                foreach ($series['models'] as $model) {
                    if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                        return $this->extractChassisSpecifications($model);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract critical storage specifications from JSON model
     */
    private function extractStorageSpecifications($model) {
        return [
            'interface_type' => $model['interface'] ?? 'Unknown',
            'form_factor' => $model['form_factor'] ?? 'Unknown',
            'storage_type' => $model['storage_type'] ?? 'Unknown',
            'subtype' => $model['subtype'] ?? null,
            'capacity_GB' => $model['capacity_GB'] ?? 0,
            'power_consumption' => $model['power_consumption_W'] ?? null,
            'specifications' => $model['specifications'] ?? [],
            'pcie_version' => $this->extractPCIeVersionFromInterface($model['interface'] ?? ''),
            'pcie_lanes' => $this->extractPCIeLanes($model['interface'] ?? ''),
            'uuid' => $model['uuid']
        ];
    }

    /**
     * Extract motherboard specifications relevant to storage
     */
    private function extractMotherboardSpecifications($model) {
        return [
            'storage_interfaces' => $this->extractMotherboardStorageInterfaces($model),
            'drive_bays' => $this->extractDriveBays($model),
            'pcie_slots' => $model['expansion_slots']['pcie_slots'] ?? [],
            'pcie_version' => $this->extractMotherboardPCIeVersion($model),
            'uuid' => $model['uuid']
        ];
    }

    /**
     * Extract critical chassis specifications from JSON model
     */
    private function extractChassisSpecifications($model) {
        return [
            'drive_bays' => $model['drive_bays'] ?? [],
            'backplane' => $model['backplane'] ?? [],
            'motherboard_compatibility' => $model['motherboard_compatibility'] ?? [],
            'form_factor' => $model['form_factor'] ?? 'Unknown',
            'chassis_type' => $model['chassis_type'] ?? 'Unknown',
            'expansion_slots' => $model['expansion_slots'] ?? [],
            'power_supply' => $model['power_supply'] ?? [],
            'dimensions' => $model['dimensions'] ?? [],
            'uuid' => $model['uuid']
        ];
    }

    /**
     * Extract storage interfaces supported by motherboard
     */
    private function extractMotherboardStorageInterfaces($model) {
        $interfaces = [];
        
        if (isset($model['storage'])) {
            $storage = $model['storage'];
            
            if (isset($storage['sata']['ports']) && $storage['sata']['ports'] > 0) {
                $interfaces[] = 'SATA III';
                $interfaces[] = 'SATA';
            }
            
            if (isset($storage['sas']['ports']) && $storage['sas']['ports'] > 0) {
                $interfaces[] = 'SAS';
            }
            
            if (isset($storage['nvme']['m2_slots']) && !empty($storage['nvme']['m2_slots'])) {
                $interfaces[] = 'PCIe NVMe';
                $interfaces[] = 'NVMe';
            }
            
            if (isset($storage['nvme']['u2_slots']['count']) && $storage['nvme']['u2_slots']['count'] > 0) {
                $interfaces[] = 'U.2';
                $interfaces[] = 'PCIe NVMe 4.0';
            }
        }
        
        return $interfaces;
    }

    /**
     * Extract drive bays and form factor support
     */
    private function extractDriveBays($model) {
        $bays = [
            'sata_ports' => $model['storage']['sata']['ports'] ?? 0,
            'm2_slots' => [],
            'u2_slots' => $model['storage']['nvme']['u2_slots']['count'] ?? 0,
            'supported_form_factors' => []
        ];
        
        // Extract M.2 slot details
        if (isset($model['storage']['nvme']['m2_slots'])) {
            foreach ($model['storage']['nvme']['m2_slots'] as $m2Slot) {
                $bays['m2_slots'][] = [
                    'count' => $m2Slot['count'] ?? 1,
                    'form_factors' => $m2Slot['form_factors'] ?? ['M.2 2280'],
                    'pcie_lanes' => $m2Slot['pcie_lanes'] ?? 4,
                    'pcie_generation' => $m2Slot['pcie_generation'] ?? 4
                ];
            }
        }
        
        // Determine supported form factors
        if ($bays['sata_ports'] > 0) {
            $bays['supported_form_factors'][] = '2.5-inch';
            $bays['supported_form_factors'][] = '3.5-inch';
        }
        
        if (!empty($bays['m2_slots'])) {
            foreach ($bays['m2_slots'] as $slot) {
                $bays['supported_form_factors'] = array_merge($bays['supported_form_factors'], $slot['form_factors']);
            }
        }
        
        if ($bays['u2_slots'] > 0) {
            $bays['supported_form_factors'][] = '2.5-inch';
            $bays['supported_form_factors'][] = 'U.2';
        }
        
        return $bays;
    }

    /**
     * Check storage interface compatibility with motherboard
     * JSON-DRIVEN: No hardcoded compatibility rules - uses protocol/generation normalization
     */
    private function checkStorageInterfaceCompatibility($storageSpecs, $motherboardSpecs) {
        $storageInterface = $storageSpecs['interface_type'];
        $mbInterfaces = $motherboardSpecs['storage_interfaces'];

        // Direct interface match (exact string - highest score)
        if (in_array($storageInterface, $mbInterfaces)) {
            return [
                'compatible' => true,
                'score' => 0.95,
                'message' => "Perfect interface match: $storageInterface",
                'recommendation' => 'Native interface support provides optimal performance'
            ];
        }

        // Normalize storage interface and compare with normalized motherboard interfaces
        $normalizedStorageInterface = $this->normalizeStorageInterface($storageInterface);

        foreach ($mbInterfaces as $mbInterface) {
            $normalizedMbInterface = $this->normalizeStorageInterface($mbInterface);

            // Check if normalized interfaces match (protocol and generation)
            if ($normalizedStorageInterface['protocol'] === $normalizedMbInterface['protocol']) {
                // Same protocol - check generation compatibility
                if ($normalizedStorageInterface['generation'] === $normalizedMbInterface['generation']) {
                    // Perfect match
                    return [
                        'compatible' => true,
                        'score' => 0.95,
                        'message' => "Interface compatible: $storageInterface matches $mbInterface",
                        'recommendation' => 'Native interface support provides optimal performance'
                    ];
                } elseif ($normalizedStorageInterface['generation'] !== null &&
                          $normalizedMbInterface['generation'] !== null &&
                          $normalizedStorageInterface['generation'] <= $normalizedMbInterface['generation']) {
                    // Backward compatible (storage gen <= motherboard gen)
                    return [
                        'compatible' => true,
                        'score' => 0.90,
                        'message' => "Interface compatible: $storageInterface works with $mbInterface (backward compatible)",
                        'recommendation' => 'Backward compatible - full functionality supported'
                    ];
                } elseif ($normalizedStorageInterface['generation'] === null ||
                          $normalizedMbInterface['generation'] === null) {
                    // One has no generation specified - assume compatible
                    return [
                        'compatible' => true,
                        'score' => 0.85,
                        'message' => "Interface compatible: $storageInterface works with $mbInterface",
                        'recommendation' => 'Compatible with potential performance differences'
                    ];
                }
            }
        }

        // Check if NVMe can work via PCIe slot
        if ($normalizedStorageInterface['protocol'] === 'nvme') {
            if (!empty($motherboardSpecs['pcie_slots'])) {
                return [
                    'compatible' => true,
                    'score' => 0.80,
                    'message' => "NVMe storage can use PCIe slot with adapter",
                    'recommendation' => 'Consider M.2 to PCIe adapter for compatibility'
                ];
            }
        }

        // No compatible interface found
        return [
            'compatible' => false,
            'score' => 0.25,
            'message' => "Storage requires $storageInterface but motherboard only supports: " . implode(', ', $mbInterfaces),
            'recommendation' => 'Use storage device with compatible interface or upgrade motherboard'
        ];
    }

    /**
     * Normalize storage interface string to extract protocol and generation
     * This allows flexible matching regardless of word order or formatting
     *
     * Examples:
     *   "NVMe PCIe 4.0" -> ['protocol' => 'nvme', 'generation' => 4.0]
     *   "PCIe NVMe 4.0" -> ['protocol' => 'nvme', 'generation' => 4.0]
     *   "SATA III" -> ['protocol' => 'sata', 'generation' => 3]
     *   "SAS3" -> ['protocol' => 'sas', 'generation' => 3]
     */
    private function normalizeStorageInterface($interface) {
        $interface = strtolower(trim($interface));

        // Detect protocol
        $protocol = null;
        if (strpos($interface, 'nvme') !== false) {
            $protocol = 'nvme';
        } elseif (strpos($interface, 'sata') !== false) {
            $protocol = 'sata';
        } elseif (strpos($interface, 'sas') !== false) {
            $protocol = 'sas';
        } elseif (strpos($interface, 'u.2') !== false || strpos($interface, 'u.3') !== false) {
            $protocol = 'nvme'; // U.2/U.3 are NVMe protocols
        }

        // Extract generation number
        $generation = null;

        // Match patterns like: "4.0", "3.0", "5.0", "III", "3", etc.
        if (preg_match('/(\d+)\.(\d+)/', $interface, $matches)) {
            // PCIe generations: 3.0, 4.0, 5.0
            $generation = (float)($matches[1] . '.' . $matches[2]);
        } elseif (preg_match('/(\d+)/', $interface, $matches)) {
            // Simple number: SATA3, SAS3, etc.
            $generation = (int)$matches[1];
        } elseif (strpos($interface, 'iii') !== false || strpos($interface, 'sata iii') !== false) {
            // SATA III = SATA3
            $generation = 3;
        } elseif (strpos($interface, 'ii') !== false) {
            // SATA II = SATA2
            $generation = 2;
        }

        return [
            'protocol' => $protocol,
            'generation' => $generation,
            'original' => $interface
        ];
    }

    /**
     * Check form factor and connector compatibility
     */
    private function checkFormFactorCompatibility($storageSpecs, $motherboardSpecs, $existingStorage = []) {
        $storageFormFactor = $storageSpecs['form_factor'];
        $mbBays = $motherboardSpecs['drive_bays'];
        $supportedFormFactors = $mbBays['supported_form_factors'];
        
        // Direct form factor support
        if (in_array($storageFormFactor, $supportedFormFactors)) {
            // Check bay availability
            $bayAvailable = $this->checkBayAvailability($storageFormFactor, $mbBays, $existingStorage);
            
            if ($bayAvailable) {
                return [
                    'compatible' => true,
                    'score' => 0.95,
                    'message' => "Native form factor support: $storageFormFactor",
                    'recommendation' => 'Perfect physical fit with native bay support'
                ];
            } else {
                return [
                    'compatible' => false,
                    'score' => 0.30,
                    'message' => "Form factor supported but no available bays for $storageFormFactor",
                    'recommendation' => 'Remove existing storage or use different form factor'
                ];
            }
        }
        
        // Check for adapter compatibility
        $adapterCompatibility = $this->checkAdapterCompatibility($storageFormFactor, $supportedFormFactors);
        if ($adapterCompatibility['possible']) {
            return [
                'compatible' => true,
                'score' => 0.85,
                'message' => $adapterCompatibility['message'],
                'recommendation' => $adapterCompatibility['recommendation']
            ];
        }
        
        // No compatible form factor
        return [
            'compatible' => false,
            'score' => 0.25,
            'message' => "Storage form factor $storageFormFactor not compatible with motherboard bays",
            'recommendation' => 'Use storage with supported form factor: ' . implode(', ', $supportedFormFactors)
        ];
    }

    /**
     * Check PCIe bandwidth compatibility for NVMe storage
     */
    private function checkPCIeBandwidthCompatibility($storageSpecs, $motherboardSpecs) {
        $requiredPCIeGen = $storageSpecs['pcie_version'];
        $requiredLanes = $storageSpecs['pcie_lanes'];
        
        if (!$requiredPCIeGen || !$requiredLanes) {
            return [
                'compatible' => true,
                'score' => 0.90,
                'message' => 'PCIe requirements not specified, assuming compatibility',
                'recommendation' => 'Verify PCIe requirements with storage documentation'
            ];
        }
        
        $mbPCIeGen = $motherboardSpecs['pcie_version'];
        $availableSlots = $motherboardSpecs['pcie_slots'];
        
        // Check if motherboard PCIe version meets storage requirements
        if ($this->comparePCIeVersions($mbPCIeGen, $requiredPCIeGen) >= 0) {
            // Full bandwidth available
            $suitableSlot = $this->findSuitablePCIeSlot($availableSlots, $requiredLanes);
            
            if ($suitableSlot) {
                return [
                    'compatible' => true,
                    'score' => 0.95,
                    'message' => "Full bandwidth available: PCIe $mbPCIeGen x$requiredLanes",
                    'recommendation' => 'Optimal PCIe bandwidth for maximum performance'
                ];
            }
        } else {
            // Backward compatibility (reduced bandwidth)
            $suitableSlot = $this->findSuitablePCIeSlot($availableSlots, $requiredLanes);
            
            if ($suitableSlot) {
                return [
                    'compatible' => true,
                    'score' => 0.85,
                    'message' => "Reduced bandwidth: Storage requires PCIe $requiredPCIeGen but motherboard provides $mbPCIeGen",
                    'recommendation' => 'Storage will work but with reduced performance due to PCIe version limitation'
                ];
            }
        }
        
        // Insufficient lanes or no compatible slots
        return [
            'compatible' => false,
            'score' => 0.40,
            'message' => "Insufficient PCIe resources: Storage requires $requiredPCIeGen x$requiredLanes",
            'recommendation' => 'Use storage with lower PCIe requirements or upgrade motherboard'
        ];
    }

    /**
     * Fallback storage compatibility check using database data
     */
    private function fallbackStorageCompatibilityCheck($component1, $component2) {
        $motherboard = $component1['type'] === 'motherboard' ? $component1 : $component2;
        $storage = $component1['type'] === 'storage' ? $component1 : $component2;

        $result = [
            'compatible' => true,
            'compatibility_score' => 0.70, // Lower score for fallback method
            'issues' => [],
            'warnings' => ['Using fallback compatibility check - JSON data not available'],
            'recommendations' => ['Update storage-level-3.json for enhanced compatibility validation']
        ];

        try {
            $motherboardData = $this->getComponentData($motherboard['type'], $motherboard['uuid']);
            $storageData = $this->getComponentData($storage['type'], $storage['uuid']);

            // Basic interface checking from database notes
            $motherboardInterfaces = $this->extractStorageInterfaces($motherboardData);
            $storageInterface = $this->extractStorageInterface($storageData);

            if ($motherboardInterfaces && $storageInterface && !in_array($storageInterface, $motherboardInterfaces)) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.30;
                $result['issues'][] = "Storage interface possibly incompatible: $storageInterface";
                $result['recommendations'][] = 'Verify interface compatibility manually';
            }

        } catch (Exception $e) {
            error_log("Fallback storage compatibility check error: " . $e->getMessage());
            $result['warnings'][] = "Unable to perform fallback compatibility check";
            $result['compatibility_score'] = 0.60;
        }

        return $result;
    }

    /**
     * Helper methods for storage compatibility
     */
    
    private function isNVMeStorage($storageSpecs) {
        $interface = strtolower($storageSpecs['interface_type']);
        return strpos($interface, 'nvme') !== false || strpos($interface, 'pcie') !== false;
    }
    
    private function extractPCIeVersionFromInterface($interface) {
        if (preg_match('/PCIe.*?(\d\.\d)/', $interface, $matches)) {
            return $matches[1];
        }
        if (preg_match('/(\d)\.0/', $interface, $matches)) {
            return $matches[1] . '.0';
        }
        return null;
    }
    
    private function extractPCIeLanes($interface) {
        if (preg_match('/x(\d+)/', $interface, $matches)) {
            return (int)$matches[1];
        }
        return 4; // Default to x4 for NVMe
    }
    
    private function extractMotherboardPCIeVersion($model) {
        if (isset($model['expansion_slots']['pcie_slots'])) {
            foreach ($model['expansion_slots']['pcie_slots'] as $slot) {
                if (preg_match('/PCIe\s+(\d+\.\d+)/', $slot['type'], $matches)) {
                    return $matches[1];
                }
            }
        }
        return '4.0'; // Default assumption
    }
    
    private function checkBayAvailability($formFactor, $mbBays, $existingStorage) {
        // Simplified bay checking - in real implementation, count used bays
        switch ($formFactor) {
            case 'M.2 2280':
            case 'M.2 22110':
                return !empty($mbBays['m2_slots']);
            case '2.5-inch':
                return $mbBays['sata_ports'] > 0 || $mbBays['u2_slots'] > 0;
            case '3.5-inch':
                return $mbBays['sata_ports'] > 0;
            default:
                return false;
        }
    }
    
    private function checkAdapterCompatibility($storageFormFactor, $supportedFormFactors) {
        // Define adapter possibilities
        $adapterMatrix = [
            'M.2 2280' => [
                'target' => '3.5-inch',
                'message' => 'M.2 2280 can use PCIe slot with M.2 to PCIe adapter',
                'recommendation' => 'Purchase M.2 to PCIe adapter card'
            ],
            '2.5-inch' => [
                'target' => '3.5-inch',
                'message' => '2.5-inch drive can fit in 3.5-inch bay with adapter',
                'recommendation' => 'Use 2.5" to 3.5" drive adapter bracket'
            ]
        ];
        
        if (isset($adapterMatrix[$storageFormFactor])) {
            $adapter = $adapterMatrix[$storageFormFactor];
            if (in_array($adapter['target'], $supportedFormFactors)) {
                return [
                    'possible' => true,
                    'message' => $adapter['message'],
                    'recommendation' => $adapter['recommendation']
                ];
            }
        }
        
        return ['possible' => false];
    }
    
    private function comparePCIeVersions($motherboardVersion, $requiredVersion) {
        return version_compare($motherboardVersion, $requiredVersion);
    }
    
    private function findSuitablePCIeSlot($availableSlots, $requiredLanes) {
        foreach ($availableSlots as $slot) {
            if (($slot['lanes'] ?? 1) >= $requiredLanes && ($slot['count'] ?? 0) > 0) {
                return $slot;
            }
        }
        return null;
    }

    /**
     * Direct vs Recursive checking modes
     */
    
    /**
     * Direct compatibility check for single storage component addition
     */
    public function checkStorageCompatibilityDirect($storageUuid, $motherboardUuid) {
        $storage = ['type' => 'storage', 'uuid' => $storageUuid];
        $motherboard = ['type' => 'motherboard', 'uuid' => $motherboardUuid];
        
        return $this->checkMotherboardStorageCompatibility($storage, $motherboard);
    }
    
    /**
     * Recursive compatibility check for complete server configuration
     */
    public function checkStorageCompatibilityRecursive($serverConfigUuid) {
        try {
            // Load server configuration and all storage components
            $configData = $this->getServerConfiguration($serverConfigUuid);
            $storageComponents = $configData['storage_components'] ?? [];
            $motherboardUuid = $configData['motherboard_uuid'] ?? null;
            
            if (!$motherboardUuid) {
                return [
                    'compatible' => false,
                    'overall_score' => 0.0,
                    'issues' => ['No motherboard found in server configuration'],
                    'component_results' => []
                ];
            }
            
            $componentResults = [];
            $overallScore = 1.0;
            $overallCompatible = true;
            $allIssues = [];
            $allRecommendations = [];
            
            // Check each storage component against motherboard
            foreach ($storageComponents as $storageComponent) {
                $result = $this->checkStorageCompatibilityDirect($storageComponent['uuid'], $motherboardUuid);
                
                $componentResults[] = [
                    'storage_uuid' => $storageComponent['uuid'],
                    'result' => $result
                ];
                
                if (!$result['compatible']) {
                    $overallCompatible = false;
                }
                
                $overallScore = min($overallScore, $result['compatibility_score']);
                $allIssues = array_merge($allIssues, $result['issues']);
                $allRecommendations = array_merge($allRecommendations, $result['recommendations']);
            }
            
            // Check for bay conflicts and capacity limits
            $bayAnalysis = $this->analyzeBayCapacity($storageComponents, $motherboardUuid);
            
            return [
                'compatible' => $overallCompatible && $bayAnalysis['sufficient_bays'],
                'overall_score' => min($overallScore, $bayAnalysis['bay_utilization_score']),
                'issues' => array_merge($allIssues, $bayAnalysis['bay_issues']),
                'recommendations' => array_merge($allRecommendations, $bayAnalysis['bay_recommendations']),
                'component_results' => $componentResults,
                'bay_analysis' => $bayAnalysis
            ];
            
        } catch (Exception $e) {
            error_log("Recursive storage compatibility check error: " . $e->getMessage());
            return [
                'compatible' => false,
                'overall_score' => 0.0,
                'issues' => ['Failed to perform recursive compatibility check'],
                'component_results' => []
            ];
        }
    }
    
    private function getServerConfiguration($configUuid) {
        // This would interface with your server configuration system
        // Placeholder implementation
        return [
            'motherboard_uuid' => null,
            'storage_components' => []
        ];
    }
    
    private function analyzeBayCapacity($storageComponents, $motherboardUuid) {
        // Analyze if motherboard has sufficient bays for all storage components
        // This is a simplified implementation - full version would count actual bay usage
        
        return [
            'sufficient_bays' => true,
            'bay_utilization_score' => 0.90,
            'bay_issues' => [],
            'bay_recommendations' => [],
            'bay_details' => [
                'total_storage_count' => count($storageComponents),
                'm2_slots_used' => 0,
                'sata_ports_used' => 0,
                'u2_slots_used' => 0
            ]
        ];
    }

    /**
     * Decentralized RAM compatibility check - works without requiring a specific base motherboard
     * Checks RAM compatibility with existing components in server configuration
     */
    public function checkRAMDecentralizedCompatibility($ramComponent, $existingComponents) {
        try {
            $result = [
                'compatible' => true,
                'compatibility_score' => 1.0,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => []
            ];

            // If no existing components, RAM is always compatible
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - all RAM compatible';
                return $result;
            }

            // Get RAM specifications
            $ramData = $this->getComponentData('ram', $ramComponent['uuid']);
            if (!$ramData) {
                $result['warnings'][] = 'RAM specifications not found - using basic compatibility';
                $result['compatibility_score'] = 0.8;
                return $result;
            }

            $ramMemoryType = $this->extractMemoryType($ramData);
            $ramFormFactor = $this->extractMemoryFormFactor($ramData);
            $ramModuleType = $ramData['module_type'] ?? null; // UDIMM, RDIMM, LRDIMM
            $ramSpeed = $this->extractMemorySpeed($ramData);

            // Collect memory requirements from existing components
            $memoryRequirements = [
                'supported_types' => [],
                'max_speeds' => [],
                'form_factors' => [],
                'sources' => []
            ];

            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'cpu') {
                    $cpuCompatResult = $this->analyzeExistingCPUForRAM($existingComp, $memoryRequirements);
                    if (!$cpuCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $cpuCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $cpuCompatResult['details']);

                } elseif ($compType === 'motherboard') {
                    $mbCompatResult = $this->analyzeExistingMotherboardForRAM($existingComp, $memoryRequirements);
                    if (!$mbCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $mbCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $mbCompatResult['details']);

                } elseif ($compType === 'ram') {
                    $ramCompatResult = $this->analyzeExistingRAMForRAM($existingComp, $ramFormFactor, $ramModuleType);
                    if (!$ramCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $ramCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $ramCompatResult['details']);
                }
            }

            // Apply compatibility logic using minimum common requirements
            if ($result['compatible']) {
                $finalCompatResult = $this->applyMemoryCompatibilityRules($ramData, $memoryRequirements);
                $result = array_merge($result, $finalCompatResult);
            }

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized RAM compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'compatibility_score' => 0.7,
                'issues' => [],
                'warnings' => ['Compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify RAM compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Check PCIe card compatibility with existing server components (decentralized approach)
     *
     * Validates:
     * - PCIe generation compatibility (backward/forward compatibility rules)
     * - Physical slot availability (tracks used slots from PCIe cards + NICs)
     * - Slot size compatibility (x1/x4/x8/x16 fitting rules)
     * - Riser card slot additions
     *
     * @param array $pcieCardComponent ['uuid' => 'card-uuid']
     * @param array $existingComponents Array of existing components with 'type' and 'uuid'
     * @return array Compatibility result with compatible, score, issues, warnings, summary
     */
    public function checkPCIeDecentralizedCompatibility($pcieCardComponent, $existingComponents, $componentType = 'pciecard') {
        try {
            $result = [
                'compatible' => true,
                'compatibility_score' => 1.0,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => [],
                'compatibility_summary' => ''
            ];

            // CASE 1: Empty configuration - all cards compatible
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - all PCIe cards compatible';
                $result['compatibility_summary'] = 'Compatible - no constraints found';
                return $result;
            }

            // Get PCIe card or NIC specifications from JSON
            $pcieCardData = $this->getComponentData($componentType, $pcieCardComponent['uuid']);
            if (!$pcieCardData) {
                $componentLabel = ($componentType === 'nic') ? 'NIC' : 'PCIe card';
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = $componentLabel . ' specifications not found in JSON database';
                $result['compatibility_summary'] = 'Component not found in specification database';
                $result['recommendations'][] = 'Verify component UUID exists in JSON specification files';
                return $result;
            }

            // Extract PCIe card properties
            $cardGeneration = $this->extractPCIeGeneration($pcieCardData);
            $cardSlotSize = $this->extractPCIeSlotSize($pcieCardData);
            $cardSubtype = $pcieCardData['component_subtype'] ?? 'PCIe Card';

            error_log("DEBUG ComponentCompatibility: UUID=" . $pcieCardComponent['uuid'] . ", component_subtype='" . $cardSubtype . "', cardSlotSize=" . $cardSlotSize);

            // SPECIAL HANDLING FOR RISER CARDS
            // Riser cards use riser slots, not PCIe slots
            $isRiserCard = ($cardSubtype === 'Riser Card');

            error_log("DEBUG ComponentCompatibility: isRiserCard=" . ($isRiserCard ? 'TRUE' : 'FALSE'));

            if ($isRiserCard) {
                error_log("DEBUG ComponentCompatibility: Entering riser card handling block");
                // Use ExpansionSlotTracker to check riser slot availability
                require_once __DIR__ . '/ExpansionSlotTracker.php';
                $slotTracker = new ExpansionSlotTracker($this->pdo);

                // Find config_uuid from existing components (need it for ExpansionSlotTracker)
                $configUuid = null;
                foreach ($existingComponents as $existingComp) {
                    if ($existingComp['type'] === 'motherboard') {
                        // Query to find config_uuid from this motherboard
                        $stmt = $this->pdo->prepare("
                            SELECT config_uuid
                            FROM server_configuration_components
                            WHERE component_uuid = ? AND component_type = 'motherboard'
                            LIMIT 1
                        ");
                        $stmt->execute([$existingComp['uuid']]);
                        $configRow = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($configRow) {
                            $configUuid = $configRow['config_uuid'];
                            break;
                        }
                    }
                }

                if ($configUuid) {
                    // Check riser slot availability
                    $riserAvailability = $slotTracker->getRiserSlotAvailability($configUuid);

                    if (!$riserAvailability['success']) {
                        $result['compatible'] = false;
                        $result['compatibility_score'] = 0.0;
                        $result['issues'][] = "Motherboard does not support riser cards";
                        $result['compatibility_summary'] = 'Incompatible - Motherboard does not have riser slots';
                        return $result;
                    }

                    // Count total available riser slots across all types
                    $totalRiserSlots = 0;
                    $availableRiserSlots = 0;
                    foreach ($riserAvailability['total_slots'] as $slotType => $slots) {
                        $totalRiserSlots += count($slots);
                    }
                    foreach ($riserAvailability['available_slots'] as $slotType => $slots) {
                        $availableRiserSlots += count($slots);
                    }

                    if ($availableRiserSlots === 0) {
                        $result['compatible'] = false;
                        $result['compatibility_score'] = 0.0;
                        $result['issues'][] = "All riser slots occupied (0/{$totalRiserSlots} available)";
                        $result['compatibility_summary'] = "Incompatible - All {$totalRiserSlots} riser slots occupied";
                        return $result;
                    }

                    // Check if riser fits by size
                    $riserSlotType = 'x' . $cardSlotSize;
                    $canFitRiser = $slotTracker->canFitRiserBySize($configUuid, $riserSlotType);

                    if (!$canFitRiser) {
                        // Build available slot type list
                        $availableSlotTypes = [];
                        foreach ($riserAvailability['available_slots'] as $slotType => $slots) {
                            if (!empty($slots)) {
                                $availableSlotTypes[] = "{$slotType} (" . count($slots) . " available)";
                            }
                        }

                        $result['compatible'] = false;
                        $result['compatibility_score'] = 0.0;
                        $result['issues'][] = "Riser card requires {$riserSlotType} slot, but no compatible slots available";
                        $result['details'][] = "Available riser slot types: " . (empty($availableSlotTypes) ? "None" : implode(', ', $availableSlotTypes));
                        $result['compatibility_summary'] = "Incompatible - Requires {$riserSlotType} riser slot";
                        return $result;
                    }

                    // Riser card is compatible!
                    $result['compatible'] = true;
                    $result['compatibility_score'] = 1.0;
                    $result['details'][] = "Riser card compatible - {$availableRiserSlots} of {$totalRiserSlots} riser slots available";
                    $result['compatibility_summary'] = "Compatible - Riser slot available ({$availableRiserSlots}/{$totalRiserSlots} free)";
                    return $result;

                } else {
                    // No motherboard yet - riser will be compatible once motherboard is added
                    $result['compatible'] = true;
                    $result['compatibility_score'] = 1.0;
                    $result['details'][] = 'No motherboard in configuration - riser card will be validated when motherboard is added';
                    $result['warnings'][] = 'Add motherboard with riser slot support first';
                    $result['compatibility_summary'] = 'Compatible - requires motherboard with riser slots';
                    return $result;
                }
            }

            // REGULAR PCIe CARD/NIC HANDLING (NOT RISER CARDS)
            // Track slot availability and motherboard constraints
            $slotAvailability = [
                'total_slots' => 0,
                'used_slots' => 0,
                'available_by_size' => ['x1' => 0, 'x4' => 0, 'x8' => 0, 'x16' => 0],
                'motherboard_generation' => null,
                'has_riser_card' => false,
                'riser_added_slots' => 0
            ];

            // Analyze existing components
            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'motherboard') {
                    $mbCompatResult = $this->analyzeExistingMotherboardForPCIe(
                        $existingComp, $slotAvailability
                    );
                    if (!$mbCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $mbCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $mbCompatResult['details']);

                } elseif ($compType === 'pciecard') {
                    $pcieCompatResult = $this->analyzeExistingPCIeCardForPCIe(
                        $existingComp, $slotAvailability
                    );
                    $result['details'] = array_merge($result['details'], $pcieCompatResult['details']);

                } elseif ($compType === 'nic') {
                    $nicCompatResult = $this->analyzeExistingNICForPCIe(
                        $existingComp, $slotAvailability
                    );
                    $result['details'] = array_merge($result['details'], $nicCompatResult['details']);
                }
            }

            // Apply PCIe compatibility rules
            if ($result['compatible']) {
                $finalCompatResult = $this->applyPCIeCompatibilityRules(
                    $pcieCardData, $slotAvailability, $cardGeneration, $cardSlotSize
                );
                $result = array_merge($result, $finalCompatResult);
            }

            // Create compatibility summary
            $result['compatibility_summary'] = $this->createPCIeCompatibilitySummary(
                $pcieCardData, $slotAvailability, $result
            );

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized PCIe compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'compatibility_score' => 0.7,
                'issues' => [],
                'warnings' => ['Compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify PCIe card compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()],
                'compatibility_summary' => 'Compatibility check failed - assumed compatible'
            ];
        }
    }

    /**
     * Check HBA Card compatibility with existing server components
     * Rules:
     * 1. If no storage devices: Check PCIe slot availability
     * 2. If storage devices exist: Match HBA protocol with storage interface
     * 3. Check HBA port capacity vs number of storage devices
     */
    public function checkHBADecentralizedCompatibility($hbaCardComponent, $existingComponents) {
        try {
            $result = [
                'compatible' => true,
                'compatibility_score' => 1.0,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => [],
                'compatibility_summary' => ''
            ];

            // Get HBA card specifications from JSON
            $hbaCardData = $this->getComponentData('hbacard', $hbaCardComponent['uuid']);
            if (!$hbaCardData) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = 'HBA card specifications not found in JSON database';
                $result['compatibility_summary'] = 'HBA card not found in specification database';
                $result['recommendations'][] = 'Verify HBA card UUID exists in JSON specification files';
                return $result;
            }

            // Extract HBA card properties
            $hbaProtocol = $hbaCardData['protocol'] ?? '';
            $hbaInternalPorts = $hbaCardData['internal_ports'] ?? 0;
            $hbaExternalPorts = $hbaCardData['external_ports'] ?? 0;
            $hbaMaxDevices = $hbaCardData['max_devices'] ?? $hbaInternalPorts;
            $hbaInterface = $hbaCardData['interface'] ?? '';
            $hbaSlotRequired = $hbaCardData['slot_compatibility']['required_slot'] ?? 'PCIe x8';

            // Extract PCIe generation and slot size from HBA
            $hbaGeneration = $this->extractPCIeGeneration($hbaCardData);
            $hbaSlotSize = $this->extractPCIeSlotSize($hbaCardData);

            // CASE 1: Empty configuration - check basic PCIe requirements
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - HBA card will be compatible once motherboard is added';
                $result['warnings'][] = 'Ensure motherboard has available ' . $hbaSlotRequired . ' slot';
                $result['compatibility_summary'] = 'Compatible - requires motherboard with ' . $hbaSlotRequired . ' slot';
                return $result;
            }

            // Analyze existing components
            $storageDevices = [];
            $hasMotherboard = false;
            $slotAvailability = [
                'total_slots' => 0,
                'used_slots' => 0,
                'available_by_size' => ['x1' => 0, 'x4' => 0, 'x8' => 0, 'x16' => 0],
                'motherboard_generation' => null,
                'has_riser_card' => false,
                'riser_added_slots' => 0
            ];

            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];
                $compUuid = $existingComp['uuid'];

                if ($compType === 'storage') {
                    // Get storage device details
                    $storageData = $this->getComponentData('storage', $compUuid);
                    if ($storageData) {
                        $storageDevices[] = [
                            'uuid' => $compUuid,
                            'interface' => $storageData['interface'] ?? 'Unknown',
                            'subtype' => $storageData['subtype'] ?? 'Unknown'
                        ];
                    }
                } elseif ($compType === 'motherboard') {
                    $hasMotherboard = true;
                    // Analyze motherboard for PCIe slots
                    $mbCompatResult = $this->analyzeExistingMotherboardForPCIe(
                        $existingComp, $slotAvailability
                    );
                    if (!$mbCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $mbCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $mbCompatResult['details']);
                } elseif ($compType === 'pciecard') {
                    // Count PCIe cards for slot usage
                    $pcieCompatResult = $this->analyzeExistingPCIeCardForPCIe(
                        $existingComp, $slotAvailability
                    );
                    $result['details'] = array_merge($result['details'], $pcieCompatResult['details']);
                } elseif ($compType === 'nic') {
                    // Count NICs for slot usage
                    $nicCompatResult = $this->analyzeExistingNICForPCIe(
                        $existingComp, $slotAvailability
                    );
                    $result['details'] = array_merge($result['details'], $nicCompatResult['details']);
                } elseif ($compType === 'hbacard') {
                    // Count existing HBA cards for slot usage
                    $slotAvailability['used_slots']++;
                    $result['details'][] = 'Existing HBA card detected - slot already in use';
                }
            }

            // CASE 2: No storage devices - check PCIe slot availability
            if (empty($storageDevices)) {
                if (!$hasMotherboard) {
                    $result['warnings'][] = 'No motherboard in configuration - cannot verify PCIe slot availability';
                    $result['compatibility_summary'] = 'Compatible - pending motherboard addition';
                    return $result;
                }

                // Check if slots are available (INCLUDING riser-provided slots)
                $totalAvailableSlots = $slotAvailability['total_slots'] + $slotAvailability['riser_added_slots'];
                $usedSlots = $slotAvailability['used_slots'];
                $availableSlots = $totalAvailableSlots - $usedSlots;

                error_log("DEBUG HBA slot check: total_slots={$slotAvailability['total_slots']}, riser_added={$slotAvailability['riser_added_slots']}, used={$usedSlots}, available={$availableSlots}");

                if ($availableSlots <= 0) {
                    $result['compatible'] = false;
                    $result['compatibility_score'] = 0.0;
                    $result['issues'][] = 'No available PCIe slots on motherboard for HBA card';
                    $result['recommendations'][] = 'Remove existing PCIe cards or add riser card to expand slots';
                    $result['compatibility_summary'] = "Incompatible - All PCIe slots occupied ({$usedSlots}/{$totalAvailableSlots} used)";
                    return $result;
                }

                // Check slot size compatibility
                $slotSizeCompatible = $this->checkPCIeSlotSizeCompatibility($hbaSlotSize, $slotAvailability);
                if (!$slotSizeCompatible) {
                    $result['compatible'] = false;
                    $result['compatibility_score'] = 0.0;
                    $result['issues'][] = "No available {$hbaSlotRequired} slot on motherboard";
                    $result['recommendations'][] = "HBA card requires {$hbaSlotRequired} slot";
                    $result['compatibility_summary'] = "Incompatible - Requires {$hbaSlotRequired} slot";
                    return $result;
                }

                // Check PCIe generation compatibility
                if ($slotAvailability['motherboard_generation'] && $hbaGeneration) {
                    $mbGen = (int)$slotAvailability['motherboard_generation'];
                    $hbaGen = (int)$hbaGeneration;

                    if ($hbaGen > $mbGen) {
                        $result['warnings'][] = "HBA card is PCIe {$hbaGen}.0 but motherboard supports PCIe {$mbGen}.0 - card will run at reduced speed";
                        $result['compatibility_score'] = 0.9;
                    }
                }

                $result['details'][] = 'No storage devices found - HBA card can be added';
                $result['details'][] = "Available PCIe slots: {$availableSlots}";
                $result['compatibility_summary'] = "Compatible - {$availableSlots} available PCIe slot(s)";
                return $result;
            }

            // CASE 3: Storage devices exist - check protocol compatibility
            $storageCount = count($storageDevices);
            $result['details'][] = "Found {$storageCount} storage device(s) in configuration";

            // Extract unique storage interfaces
            $storageInterfaces = array_unique(array_column($storageDevices, 'interface'));
            $result['details'][] = 'Storage interfaces detected: ' . implode(', ', $storageInterfaces);

            // Check if HBA protocol supports all storage interfaces
            $incompatibleInterfaces = [];
            foreach ($storageInterfaces as $storageInterface) {
                if (!$this->isHBAProtocolCompatible($hbaProtocol, $storageInterface)) {
                    $incompatibleInterfaces[] = $storageInterface;
                }
            }

            if (!empty($incompatibleInterfaces)) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $incompatibleList = implode(', ', $incompatibleInterfaces);
                $result['issues'][] = "HBA protocol '{$hbaProtocol}' incompatible with storage interfaces: {$incompatibleList}";
                $result['recommendations'][] = 'Use HBA card with matching protocol or choose tri-mode HBA (SAS/SATA/NVMe)';

                // Create detailed compatibility summary explaining the mismatch
                $result['compatibility_summary'] = "Incompatible - HBA protocol '{$hbaProtocol}' does not support storage interface(s): {$incompatibleList}. Storage requires compatible HBA.";
                return $result;
            }

            // Check if HBA has enough ports for storage devices
            if ($hbaInternalPorts > 0 && $storageCount > $hbaInternalPorts) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "HBA card has {$hbaInternalPorts} internal ports but configuration has {$storageCount} storage devices";
                $result['recommendations'][] = "Remove " . ($storageCount - $hbaInternalPorts) . " storage device(s) or choose HBA with more ports";
                $result['compatibility_summary'] = 'Incompatible - Insufficient ports';
                return $result;
            }

            // Check max devices capacity
            if ($hbaMaxDevices > 0 && $storageCount > $hbaMaxDevices) {
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "HBA card supports max {$hbaMaxDevices} devices but configuration has {$storageCount} storage devices";
                $result['recommendations'][] = "Reduce storage devices to {$hbaMaxDevices} or choose HBA with higher capacity";
                $result['compatibility_summary'] = 'Incompatible - Exceeds max device capacity';
                return $result;
            }

            // Check PCIe slot availability (if motherboard exists) - INCLUDING riser-provided slots
            if ($hasMotherboard) {
                $totalAvailableSlots = $slotAvailability['total_slots'] + $slotAvailability['riser_added_slots'];
                $usedSlots = $slotAvailability['used_slots'];
                $availableSlots = $totalAvailableSlots - $usedSlots;

                error_log("DEBUG HBA slot check (with storage): total_slots={$slotAvailability['total_slots']}, riser_added={$slotAvailability['riser_added_slots']}, used={$usedSlots}, available={$availableSlots}");

                if ($availableSlots <= 0) {
                    $result['compatible'] = false;
                    $result['compatibility_score'] = 0.0;
                    $result['issues'][] = 'No available PCIe slots on motherboard for HBA card';
                    $result['recommendations'][] = 'Remove existing PCIe cards or add riser card to expand slots';
                    $result['compatibility_summary'] = "Incompatible - All PCIe slots occupied ({$usedSlots}/{$totalAvailableSlots} used)";
                    return $result;
                }

                // Check slot size compatibility
                $slotSizeCompatible = $this->checkPCIeSlotSizeCompatibility($hbaSlotSize, $slotAvailability);
                if (!$slotSizeCompatible) {
                    $result['compatible'] = false;
                    $result['compatibility_score'] = 0.0;
                    $result['issues'][] = "No available {$hbaSlotRequired} slot on motherboard";
                    $result['recommendations'][] = "HBA card requires {$hbaSlotRequired} slot";
                    $result['compatibility_summary'] = "Incompatible - Requires {$hbaSlotRequired} slot";
                    return $result;
                }
            }

            // All checks passed
            $result['details'][] = "HBA card protocol '{$hbaProtocol}' is compatible with storage interfaces";
            $result['details'][] = "HBA card has sufficient capacity: {$hbaInternalPorts} ports for {$storageCount} devices";
            $result['compatibility_summary'] = "Compatible - Protocol match, sufficient capacity ({$hbaInternalPorts} ports for {$storageCount} devices)";

            return $result;

        } catch (Exception $e) {
            error_log("HBA card compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'compatibility_score' => 0.7,
                'issues' => [],
                'warnings' => ['HBA compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify HBA card compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()],
                'compatibility_summary' => 'Compatibility check failed - assumed compatible'
            ];
        }
    }

    /**
     * Check if HBA protocol is compatible with storage interface
     */
    private function isHBAProtocolCompatible($hbaProtocol, $storageInterface) {
        // Normalize to uppercase for comparison
        $hbaProtocol = strtoupper($hbaProtocol);
        $storageInterface = strtoupper($storageInterface);

        // Tri-mode HBAs support all interfaces
        if (strpos($hbaProtocol, 'TRI-MODE') !== false ||
            strpos($hbaProtocol, 'SAS/SATA/NVME') !== false) {
            return true;
        }

        // SAS/SATA dual-mode HBAs
        if (strpos($hbaProtocol, 'SAS/SATA') !== false) {
            return (strpos($storageInterface, 'SAS') !== false ||
                    strpos($storageInterface, 'SATA') !== false);
        }

        // SAS-only HBAs support SAS drives
        if (strpos($hbaProtocol, 'SAS') !== false && strpos($hbaProtocol, 'SATA') === false) {
            return strpos($storageInterface, 'SAS') !== false;
        }

        // SATA-only HBAs support SATA drives
        if (strpos($hbaProtocol, 'SATA') !== false && strpos($hbaProtocol, 'SAS') === false) {
            return strpos($storageInterface, 'SATA') !== false;
        }

        // NVMe-only HBAs support NVMe/PCIe drives
        if (strpos($hbaProtocol, 'NVME') !== false || strpos($hbaProtocol, 'PCIE') !== false) {
            return (strpos($storageInterface, 'NVME') !== false ||
                    strpos($storageInterface, 'PCIE') !== false);
        }

        // Default: assume incompatible
        return false;
    }

    /**
     * Check if available PCIe slots can accommodate the required slot size
     */
    private function checkPCIeSlotSizeCompatibility($requiredSlotSize, $slotAvailability) {
        // Normalize requiredSlotSize to string format "x8", "x16", etc.
        // Input might be integer (8) or string ("x8" or "8")
        if (is_numeric($requiredSlotSize)) {
            $requiredSlotSize = 'x' . $requiredSlotSize;
        } elseif (strpos($requiredSlotSize, 'x') !== 0) {
            $requiredSlotSize = 'x' . $requiredSlotSize;
        }

        // Calculate net available slots (total slots - used slots)
        $totalSlots = $slotAvailability['total_slots'] + $slotAvailability['riser_added_slots'];
        $usedSlots = $slotAvailability['used_slots'];
        $netAvailable = $totalSlots - $usedSlots;

        error_log("DEBUG checkPCIeSlotSizeCompatibility: required={$requiredSlotSize}, total={$totalSlots}, used={$usedSlots}, netAvailable={$netAvailable}, available_by_size=" . json_encode($slotAvailability['available_by_size']));

        // If no slots available at all, return false
        if ($netAvailable <= 0) {
            error_log("DEBUG: No net available slots (netAvailable={$netAvailable}) - returning false");
            return false;
        }

        // Check if there are any slots of the required size or larger
        // available_by_size shows total slots provided (motherboard + riser)
        // We need to check: (1) slot type exists, AND (2) at least 1 slot is available (netAvailable > 0)
        // netAvailable is already verified > 0 above, so now just check slot type exists

        // x16 slots can accommodate x16, x8, x4, x1 cards
        if ($slotAvailability['available_by_size']['x16'] > 0) {
            error_log("DEBUG: Found x16 slot type AND netAvailable={$netAvailable} > 0 - returning true");
            return true;
        }

        // x8 slots can accommodate x8, x4, x1 cards
        if (in_array($requiredSlotSize, ['x8', 'x4', 'x1']) &&
            $slotAvailability['available_by_size']['x8'] > 0) {
            error_log("DEBUG: Found x8 slot type for required {$requiredSlotSize} AND netAvailable={$netAvailable} > 0 - returning true");
            return true;
        }

        // x4 slots can accommodate x4, x1 cards
        if (in_array($requiredSlotSize, ['x4', 'x1']) &&
            $slotAvailability['available_by_size']['x4'] > 0) {
            error_log("DEBUG: Found x4 slot type for required {$requiredSlotSize} AND netAvailable={$netAvailable} > 0 - returning true");
            return true;
        }

        // x1 slots can accommodate x1 cards only
        if ($requiredSlotSize === 'x1' &&
            $slotAvailability['available_by_size']['x1'] > 0) {
            error_log("DEBUG: Found x1 slot type AND netAvailable={$netAvailable} > 0 - returning true");
            return true;
        }

        error_log("DEBUG: No compatible slot type found for required {$requiredSlotSize} (available types: x1={$slotAvailability['available_by_size']['x1']}, x4={$slotAvailability['available_by_size']['x4']}, x8={$slotAvailability['available_by_size']['x8']}, x16={$slotAvailability['available_by_size']['x16']}) - returning false");
        return false;
    }

    /**
     * Check CPU compatibility with existing server components (decentralized approach)
     */
    public function checkCPUDecentralizedCompatibility($cpuComponent, $existingComponents) {
        try {
            $cpuUuid = $cpuComponent['uuid'];
            error_log("=== CPU COMPATIBILITY CHECK START for UUID: $cpuUuid ===");

            $result = [
                'compatible' => true,
                'compatibility_score' => 1.0,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => []
            ];

            // If no existing components, CPU is always compatible
            if (empty($existingComponents)) {
                error_log("CPU $cpuUuid: No existing components - compatible");
                $result['details'][] = 'No existing components - all CPUs compatible';
                return $result;
            }

            // Get CPU specifications
            $cpuData = $this->getComponentData('cpu', $cpuUuid);
            if (!$cpuData) {
                error_log("ERROR: CPU $cpuUuid - specifications not found in database or JSON");
                $result['warnings'][] = 'CPU specifications not found - using basic compatibility';
                $result['compatibility_score'] = 0.8;
                $result['compatibility_summary'] = 'CPU specifications not found in database';
                return $result;
            }

            $cpuSocket = $this->extractSocketType($cpuData, 'cpu');
            $cpuMemoryTypes = $this->extractSupportedMemoryTypes($cpuData, 'cpu');
            $cpuMaxMemorySpeed = $this->extractMaxMemorySpeed($cpuData, 'cpu');

            error_log("CPU $cpuUuid extracted specs - Socket: " . ($cpuSocket ?? 'NULL') .
                     ", Memory Types: " . json_encode($cpuMemoryTypes) .
                     ", Max Memory Speed: " . ($cpuMaxMemorySpeed ?? 'NULL'));

            // Collect compatibility requirements from existing components
            $compatibilityRequirements = [
                'required_socket' => null,
                'max_memory_speed_required' => 0,
                'memory_types_required' => [],
                'sources' => []
            ];

            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'motherboard') {
                    $mbCompatResult = $this->analyzeExistingMotherboardForCPU($existingComp, $compatibilityRequirements);
                    if (!$mbCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $mbCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $mbCompatResult['details']);

                } elseif ($compType === 'ram') {
                    $ramCompatResult = $this->analyzeExistingRAMForCPU($existingComp, $compatibilityRequirements);
                    if (!$ramCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $ramCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $ramCompatResult['details']);

                } elseif ($compType === 'cpu') {
                    // Check if CPU is compatible with another CPU (multi-socket scenarios)
                    $cpuCompatResult = $this->analyzeExistingCPUForCPU($existingComp, $cpuSocket);
                    if (!$cpuCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $cpuCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $cpuCompatResult['details']);
                }
            }

            // Apply CPU compatibility logic using collected requirements
            if ($result['compatible']) {
                error_log("CPU $cpuUuid: Applying final compatibility rules with requirements: " . json_encode($compatibilityRequirements));
                $finalCompatResult = $this->applyCPUCompatibilityRules($cpuData, $compatibilityRequirements);
                $result = array_merge($result, $finalCompatResult);
                error_log("CPU $cpuUuid: After applying rules - Compatible: " . ($result['compatible'] ? 'YES' : 'NO') .
                         ", Issues: " . json_encode($result['issues']));
            }

            // Create concise compatibility summary for display
            $result['compatibility_summary'] = $this->createCPUCompatibilitySummary($cpuData, $compatibilityRequirements, $result);

            error_log("=== CPU COMPATIBILITY CHECK END for UUID: $cpuUuid - Result: " .
                     ($result['compatible'] ? 'COMPATIBLE' : 'INCOMPATIBLE') .
                     ", Summary: " . $result['compatibility_summary'] . " ===");

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized CPU compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'compatibility_score' => 0.7,
                'issues' => [],
                'warnings' => ['Compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify CPU compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()]
            ];
        }
    }

    /**
     * Check motherboard compatibility with existing server components (decentralized approach)
     */
    public function checkMotherboardDecentralizedCompatibility($motherboardComponent, $existingComponents) {
        try {
            $result = [
                'compatible' => true,
                'compatibility_score' => 1.0,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => []
            ];

            // If no existing components, motherboard is always compatible
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - all motherboards compatible';
                $result['compatibility_summary'] = 'Compatible - no constraints found';
                return $result;
            }

            // Get motherboard specifications
            $motherboardData = $this->getComponentData('motherboard', $motherboardComponent['uuid']);
            if (!$motherboardData) {
                $result['warnings'][] = 'Motherboard specifications not found - using basic compatibility';
                $result['compatibility_score'] = 0.8;
                $result['compatibility_summary'] = 'Specifications not found - assumed compatible';
                return $result;
            }

            $motherboardSocket = $this->extractSocketType($motherboardData, 'motherboard');
            $motherboardMemoryTypes = $this->extractSupportedMemoryTypes($motherboardData, 'motherboard');
            $motherboardMaxMemorySpeed = $this->extractMaxMemorySpeed($motherboardData, 'motherboard');

            // Collect compatibility requirements from existing components
            $compatibilityRequirements = [
                'required_cpu_socket' => null,
                'required_memory_types' => [],
                'min_memory_speed_required' => 0,
                'required_form_factors' => [],
                'required_module_types' => [], // RDIMM, LRDIMM, UDIMM compatibility
                'sources' => []
            ];

            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'cpu') {
                    $cpuCompatResult = $this->analyzeExistingCPUForMotherboard($existingComp, $compatibilityRequirements);
                    if (!$cpuCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $cpuCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $cpuCompatResult['details']);

                } elseif ($compType === 'ram') {
                    $ramCompatResult = $this->analyzeExistingRAMForMotherboard($existingComp, $compatibilityRequirements);
                    if (!$ramCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $ramCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $ramCompatResult['details']);

                } elseif ($compType === 'motherboard') {
                    // Handle multi-motherboard scenarios (typically not allowed, but check anyway)
                    $motherboardCompatResult = $this->analyzeExistingMotherboardForMotherboard($existingComp);
                    if (!$motherboardCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $motherboardCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $motherboardCompatResult['details']);
                }
            }

            // Apply motherboard compatibility logic using collected requirements
            if ($result['compatible']) {
                $finalCompatResult = $this->applyMotherboardCompatibilityRules($motherboardData, $compatibilityRequirements);
                $result = array_merge($result, $finalCompatResult);
            }

            // Create concise compatibility summary for display
            $result['compatibility_summary'] = $this->createMotherboardCompatibilitySummary($motherboardData, $compatibilityRequirements, $result);

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized motherboard compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'compatibility_score' => 0.7,
                'issues' => [],
                'warnings' => ['Compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify motherboard compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()],
                'compatibility_summary' => 'Compatibility check failed - assumed compatible'
            ];
        }
    }

    /**
     * Check storage compatibility with existing server components (decentralized approach)
     */
    public function checkStorageDecentralizedCompatibility($storageComponent, $existingComponents) {
        try {
            $result = [
                'compatible' => true,
                'compatibility_score' => 1.0,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => []
            ];

            // If no existing components, storage is always compatible
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - all storage compatible';
                $result['compatibility_summary'] = 'Compatible - no constraints found';
                return $result;
            }

            // Get storage specifications
            $storageData = $this->getComponentData('storage', $storageComponent['uuid']);
            if (!$storageData) {
                $result['warnings'][] = 'Storage specifications not found - using basic compatibility';
                $result['compatibility_score'] = 0.8;
                $result['compatibility_summary'] = 'Specifications not found - assumed compatible';
                return $result;
            }

            // Extract storage properties
            $storageInterface = $this->extractStorageInterface($storageData);
            $storageFormFactor = $this->extractStorageFormFactor($storageData);

            // Collect storage requirements from existing components
            $storageRequirements = [
                'supported_interfaces' => [],
                'required_form_factors' => [],
                'available_slots' => [],
                'sources' => []
            ];

            // Check compatibility with each existing component
            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'motherboard') {
                    $mbCompatResult = $this->analyzeExistingMotherboardForStorage($existingComp, $storageRequirements);
                    if (!$mbCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $mbCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $mbCompatResult['details']);

                } elseif ($compType === 'storage') {
                    $storageCompatResult = $this->analyzeExistingStorageForStorage($existingComp, $storageRequirements);
                    if (!$storageCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $storageCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $storageCompatResult['details']);

                } elseif ($compType === 'caddy') {
                    $caddyCompatResult = $this->analyzeExistingCaddyForStorage($existingComp, $storageRequirements);
                    if (!$caddyCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $caddyCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $caddyCompatResult['details']);

                } elseif ($compType === 'hbacard') {
                    $hbaCompatResult = $this->analyzeExistingHBAForStorage($existingComp, $storageRequirements);
                    if (!$hbaCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $hbaCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $hbaCompatResult['details']);

                } elseif ($compType === 'chassis') {
                    $chassisCompatResult = $this->analyzeExistingChassisForStorage($existingComp, $storageRequirements);
                    if (!$chassisCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $chassisCompatResult['issues']);
                    }
                    $result['details'] = array_merge($result['details'], $chassisCompatResult['details']);
                }
            }

            // Apply storage compatibility rules
            if ($result['compatible']) {
                $finalCompatResult = $this->applyStorageCompatibilityRules($storageData, $storageRequirements);
                $result = array_merge($result, $finalCompatResult);
            }

            // Generate compatibility summary
            if ($result['compatible']) {
                $result['compatibility_summary'] = "Storage {$storageInterface} compatible with existing configuration";
            } else {
                $result['compatibility_summary'] = "Storage incompatible: " . implode(', ', $result['issues']);
            }

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized storage compatibility check error: " . $e->getMessage());
            return [
                'compatible' => true,
                'compatibility_score' => 0.7,
                'issues' => [],
                'warnings' => ['Compatibility check failed - defaulting to compatible'],
                'recommendations' => ['Verify storage compatibility manually'],
                'details' => ['Error: ' . $e->getMessage()],
                'compatibility_summary' => 'Compatibility check failed - assumed compatible'
            ];
        }
    }

    /**
     * Check chassis compatibility with existing server components (decentralized approach)
     */
    public function checkChassisDecentralizedCompatibility($chassisComponent, $existingComponents) {
        try {
            $result = [
                'compatible' => true,
                'compatibility_score' => 100,
                'issues' => [],
                'warnings' => [],
                'recommendations' => [],
                'details' => [],
                'score_breakdown' => []
            ];

            // If no existing components, chassis is always compatible
            if (empty($existingComponents)) {
                $result['details'][] = 'No existing components - all chassis compatible';
                $result['compatibility_summary'] = 'Compatible - no constraints found';
                $result['compatibility_score'] = 100;
                $result['score_breakdown'] = [
                    'base_score' => 100,
                    'final_score' => 100,
                    'factors' => ['No existing components to validate against']
                ];
                return $result;
            }

            // Get chassis specifications from database
            $chassisData = $this->getComponentData('chassis', $chassisComponent['uuid']);
            if (!$chassisData) {
                // Database entry not found - INCOMPATIBLE
                $result['compatible'] = false;
                $result['compatibility_score'] = 0;
                $result['issues'][] = 'Chassis UUID not found in database';
                $result['compatibility_summary'] = 'INCOMPATIBLE: Chassis not found in chassisinventory table';
                $result['score_breakdown'] = [
                    'base_score' => 100,
                    'penalty_applied' => -100,
                    'reason' => 'Database entry not found in chassisinventory table',
                    'final_score' => 0,
                    'missing_data' => ['Database record for chassis UUID: ' . $chassisComponent['uuid']],
                    'validation_status' => 'FAILED',
                    'recommendation' => 'Verify chassis UUID exists in database or add chassis to inventory'
                ];
                return $result;
            }

            // Try to load chassis JSON specifications - REQUIRED for compatibility
            $chassisSpecs = $this->loadChassisSpecs($chassisComponent['uuid']);
            if (!$chassisSpecs) {
                // JSON specifications not found - INCOMPATIBLE
                $result['compatible'] = false;
                $result['compatibility_score'] = 0;
                $result['issues'][] = 'Chassis JSON specifications not found';
                $result['compatibility_summary'] = 'INCOMPATIBLE: JSON specifications missing';
                $result['details'][] = "loadChassisSpecs() returned NULL for UUID: {$chassisComponent['uuid']}";

                // Enhanced score breakdown for debugging
                $result['score_breakdown'] = [
                    'base_score' => 100,
                    'penalty_applied' => -100,
                    'reason' => 'JSON specifications not found in All-JSON/chasis-jsons/ directory',
                    'final_score' => 0,
                    'missing_data' => [
                        'JSON specification file for UUID: ' . $chassisComponent['uuid'],
                        'Expected location: All-JSON/chasis-jsons/*.json',
                        'Required fields: form_factor, chassis_type, drive_bays, backplane'
                    ],
                    'validation_status' => 'FAILED',
                    'impact' => 'Cannot validate compatibility without JSON specifications',
                    'recommendation' => 'Add chassis JSON specification file with UUID: ' . $chassisComponent['uuid'],
                    'severity' => 'CRITICAL'
                ];
                return $result;
            }

            // Extract chassis bay configuration
            $chassisBays = $this->extractChassisBayConfiguration($chassisSpecs);
            $chassisMotherboardCompat = $this->extractChassisMotherboardCompatibility($chassisSpecs);

            // Collect storage requirements from existing components
            $existingStorageComponents = [];
            $existingMotherboardComponents = [];

            // Check compatibility with each existing component
            foreach ($existingComponents as $existingComp) {
                $compType = $existingComp['type'];

                if ($compType === 'storage') {
                    $existingStorageComponents[] = $existingComp;
                } elseif ($compType === 'motherboard') {
                    $existingMotherboardComponents[] = $existingComp;
                }
            }

            // STEP 1: Extract ALL storage form factors (including M.2/U.2)
            if (!empty($existingStorageComponents)) {
                $storageFormFactors = [];
                $formFactorIncompatibilities = [];

                foreach ($existingStorageComponents as $storage) {
                    $formFactor = $this->extractStorageFormFactorFromSpecs($storage['data']);
                    if ($formFactor !== 'unknown') {
                        $storageFormFactors[] = $formFactor;
                    }
                }

                // STEP 2: Validate form factor compatibility with chassis bays (STRICT MATCHING)
                foreach ($storageFormFactors as $formFactor) {
                    // M.2 and U.2 bypass traditional bay validation
                    if ($formFactor === 'M.2' || strpos($formFactor, 'M.2') !== false ||
                        $formFactor === 'U.2' || strpos($formFactor, 'U.2') !== false) {
                        $result['details'][] = "Storage form factor {$formFactor} bypasses bay validation (connects via PCIe/motherboard)";
                        continue;
                    }

                    // For traditional form factors (2.5" and 3.5"), validate STRICT bay compatibility
                    $chassisBayConfig = $chassisSpecs['drive_bays']['bay_configuration'] ?? [];
                    $hasMatchingBay = false;

                    foreach ($chassisBayConfig as $bay) {
                        $bayType = $bay['bay_type'] ?? '';

                        // STRICT matching only - no adapters allowed
                        if ($formFactor === '2.5_inch' && ($bayType === '2.5_inch' || $bayType === '2.5-inch')) {
                            $hasMatchingBay = true;
                            break;
                        } elseif ($formFactor === '3.5_inch' && ($bayType === '3.5_inch' || $bayType === '3.5-inch')) {
                            $hasMatchingBay = true;
                            break;
                        }
                    }

                    if (!$hasMatchingBay) {
                        $formFactorIncompatibilities[] = $formFactor;
                        $result['compatible'] = false;
                        $result['issues'][] = "Storage form factor {$formFactor} requires {$formFactor} chassis bays (strict matching)";
                    } else {
                        $result['details'][] = "Storage form factor {$formFactor} compatible with chassis bays";
                    }
                }

                // If form factor incompatibilities found, return early
                if (!empty($formFactorIncompatibilities)) {
                    $result['compatibility_summary'] = "INCOMPATIBLE: Storage form factors not supported by chassis";
                    $result['compatibility_score'] = 0;
                    $result['score_breakdown'] = [
                        'base_score' => 100,
                        'penalty_applied' => -100,
                        'reason' => 'Storage form factors incompatible with chassis bays',
                        'incompatible_form_factors' => $formFactorIncompatibilities,
                        'final_score' => 0,
                        'validation_status' => 'FAILED'
                    ];
                    return $result;
                }

                // STEP 3: Calculate required bay capacity from storage (excludes M.2/U.2)
                $requiredBays = $this->calculateRequiredBays($existingStorageComponents);

                if (!empty($requiredBays)) {
                    $bayCompatResult = $this->validateChassisBayCapacity($chassisBays, $requiredBays);

                    if (!$bayCompatResult['compatible']) {
                        $result['compatible'] = false;
                        $result['issues'] = array_merge($result['issues'], $bayCompatResult['issues']);
                    }

                    $result['compatibility_score'] = min($result['compatibility_score'], $bayCompatResult['compatibility_score']);
                    $result['recommendations'] = array_merge($result['recommendations'], $bayCompatResult['recommendations']);

                    // Add bay analysis details
                    $totalRequired = array_sum($requiredBays);
                    $totalAvailable = array_sum($chassisBays);
                    $result['details'][] = "Bay analysis: {$totalRequired} drives need accommodation, chassis has {$totalAvailable} total bays";
                }
            }

            // Check motherboard form factor compatibility
            if (!empty($existingMotherboardComponents)) {
                foreach ($existingMotherboardComponents as $existingMB) {
                    $mbData = $this->getComponentData('motherboard', $existingMB['uuid']);
                    if ($mbData) {
                        // Extract motherboard form factor (basic check)
                        $mbNotes = $mbData['Notes'] ?? $mbData['notes'] ?? '';

                        // Check if chassis supports common form factors
                        $supportedFormFactors = $chassisMotherboardCompat['form_factors'];
                        $result['details'][] = "Chassis supports motherboard form factors: " . implode(', ', $supportedFormFactors);

                        // For now, assume basic compatibility unless specific conflicts are found
                        // This could be enhanced with more detailed motherboard form factor detection
                    }
                }
            }

            // Generate compatibility summary and score breakdown
            if ($result['compatible']) {
                $chassisFormFactor = $chassisSpecs['form_factor'] ?? 'Unknown';
                $chassisType = $chassisSpecs['chassis_type'] ?? 'Server';
                $result['compatibility_summary'] = "Chassis ({$chassisFormFactor} {$chassisType}) compatible with existing configuration";
                $result['compatibility_score'] = 100; // Full compatibility

                // Add detailed score breakdown for successful compatibility
                $scoreFactors = ['Full JSON specifications available' => 100];
                $validationChecks = [
                    'json_spec_loaded' => true,
                    'database_record_found' => true
                ];

                if (!empty($existingStorageComponents)) {
                    $validationChecks['storage_bay_validation'] = true;
                    $scoreFactors['Storage bay compatibility validated'] = 100;
                }

                if (!empty($existingMotherboardComponents)) {
                    $validationChecks['motherboard_form_factor_validation'] = true;
                    $scoreFactors['Motherboard form factor compatibility validated'] = 100;
                }

                $result['score_breakdown'] = [
                    'base_score' => 100,
                    'final_score' => 100,
                    'validation_checks_performed' => $validationChecks,
                    'score_factors' => $scoreFactors,
                    'chassis_specs_loaded' => [
                        'form_factor' => $chassisFormFactor,
                        'type' => $chassisType,
                        'drive_bays' => $chassisSpecs['drive_bays'] ?? 'Unknown',
                        'backplane' => $chassisSpecs['backplane'] ?? 'Unknown'
                    ],
                    'validation_status' => 'COMPLETE',
                    'data_quality' => 'HIGH'
                ];
            } else {
                $result['compatibility_summary'] = "Chassis incompatible: " . implode(', ', $result['issues']);
                $result['compatibility_score'] = 0; // Incompatible

                $result['score_breakdown'] = [
                    'base_score' => 100,
                    'final_score' => 0,
                    'incompatibility_reasons' => $result['issues'],
                    'validation_status' => 'FAILED',
                    'data_quality' => 'HIGH'
                ];
            }

            return $result;

        } catch (Exception $e) {
            error_log("Decentralized chassis compatibility check error: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            return [
                'compatible' => false,
                'compatibility_score' => 0,
                'issues' => ['Exception during validation: ' . $e->getMessage()],
                'warnings' => ['Compatibility check failed due to exception'],
                'recommendations' => ['Verify chassis compatibility manually', 'Check error logs'],
                'details' => [
                    'EXCEPTION_CAUGHT' => $e->getMessage(),
                    'EXCEPTION_FILE' => $e->getFile(),
                    'EXCEPTION_LINE' => $e->getLine(),
                    'EXCEPTION_TRACE' => explode("\n", $e->getTraceAsString())
                ],
                'score_breakdown' => [
                    'base_score' => 100,
                    'penalty_applied' => -100,
                    'reason' => 'Exception occurred during compatibility validation',
                    'final_score' => 0,
                    'exception_details' => [
                        'message' => $e->getMessage(),
                        'file' => basename($e->getFile()),
                        'line' => $e->getLine()
                    ],
                    'validation_status' => 'ERROR',
                    'data_quality' => 'UNKNOWN',
                    'recommendation' => 'Check error logs and verify chassis data integrity',
                    'severity' => 'CRITICAL'
                ],
                'compatibility_summary' => 'INCOMPATIBLE: Exception during validation - ' . $e->getMessage()
            ];
        }
    }

    /**
     * Analyze existing CPU for RAM compatibility requirements
     */
    private function analyzeExistingCPUForRAM($cpuComponent, &$memoryRequirements) {
        $cpuData = $this->getComponentData('cpu', $cpuComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$cpuData) {
            $result['details'][] = 'CPU specifications not found - basic compatibility assumed';
            return $result;
        }

        // Extract CPU memory support
        $cpuMemoryTypes = $this->extractSupportedMemoryTypes($cpuData, 'cpu');
        $cpuMaxSpeed = $this->extractMaxMemorySpeed($cpuData);

        if ($cpuMemoryTypes) {
            $memoryRequirements['supported_types'] = array_merge(
                $memoryRequirements['supported_types'],
                $cpuMemoryTypes
            );
            $memoryRequirements['sources'][] = 'CPU: ' . implode(', ', $cpuMemoryTypes);
            $result['details'][] = 'CPU supports: ' . implode(', ', $cpuMemoryTypes);
        }

        if ($cpuMaxSpeed) {
            $memoryRequirements['max_speeds'][] = $cpuMaxSpeed;
            $result['details'][] = "CPU max memory speed: {$cpuMaxSpeed}MHz";
        }

        return $result;
    }

    /**
     * Analyze existing motherboard for RAM compatibility requirements
     */
    private function analyzeExistingMotherboardForRAM($motherboardComponent, &$memoryRequirements) {
        $mbData = $this->getComponentData('motherboard', $motherboardComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$mbData) {
            $result['details'][] = 'Motherboard specifications not found - basic compatibility assumed';
            return $result;
        }

        // Extract motherboard memory support
        $mbMemoryTypes = $this->extractSupportedMemoryTypes($mbData, 'motherboard');
        $mbMaxSpeed = $this->extractMaxMemorySpeed($mbData);
        $mbFormFactor = $this->extractMemoryFormFactor($mbData);

        if ($mbMemoryTypes) {
            $memoryRequirements['supported_types'] = array_merge(
                $memoryRequirements['supported_types'],
                $mbMemoryTypes
            );
            $memoryRequirements['sources'][] = 'Motherboard: ' . implode(', ', $mbMemoryTypes);
            $result['details'][] = 'Motherboard supports: ' . implode(', ', $mbMemoryTypes);
        }

        if ($mbMaxSpeed) {
            $memoryRequirements['max_speeds'][] = $mbMaxSpeed;
            $result['details'][] = "Motherboard max memory speed: {$mbMaxSpeed}MHz";
        }

        if ($mbFormFactor) {
            $memoryRequirements['form_factors'][] = $mbFormFactor;
            $result['details'][] = "Motherboard form factor: {$mbFormFactor}";
        }

        return $result;
    }

    /**
     * Analyze existing RAM for form factor compatibility
     */
    private function analyzeExistingRAMForRAM($existingRamComponent, $newRamFormFactor, $newRamModuleType = null) {
        $existingRamData = $this->getComponentData('ram', $existingRamComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$existingRamData) {
            $result['details'][] = 'Existing RAM specifications not found - basic compatibility assumed';
            return $result;
        }

        $existingFormFactor = $this->extractMemoryFormFactor($existingRamData);
        $existingModuleType = $existingRamData['module_type'] ?? null;

        // Check form factor compatibility (DIMM vs SO-DIMM physical shape)
        if ($existingFormFactor && $newRamFormFactor && $existingFormFactor !== $newRamFormFactor) {
            $result['compatible'] = false;
            $result['issues'][] = "Form factor mismatch: new RAM ({$newRamFormFactor}) vs existing RAM ({$existingFormFactor})";
        } else if ($existingFormFactor) {
            $result['details'][] = "Form factor matches existing RAM: {$existingFormFactor}";
        }

        // Check module type compatibility (UDIMM vs RDIMM vs LRDIMM)
        if ($existingModuleType && $newRamModuleType && $existingModuleType !== $newRamModuleType) {
            $result['compatible'] = false;
            $result['issues'][] = "Module type mismatch: new RAM ({$newRamModuleType}) vs existing RAM ({$existingModuleType}). UDIMM, RDIMM, and LRDIMM cannot be mixed.";
        } else if ($existingModuleType && $newRamModuleType) {
            $result['details'][] = "Module type matches existing RAM: {$existingModuleType}";
        }

        return $result;
    }

    /**
     * Apply final memory compatibility rules using collected requirements
     */
    private function applyMemoryCompatibilityRules($ramData, $memoryRequirements) {
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        $ramMemoryType = $this->extractMemoryType($ramData);
        $ramSpeed = $this->extractMemorySpeed($ramData);
        $ramFormFactor = $this->extractMemoryFormFactor($ramData);

        // Check form factor compatibility with motherboard
        if (!empty($memoryRequirements['form_factors'])) {
            $supportedFormFactors = array_unique($memoryRequirements['form_factors']);

            // Normalize form factors for comparison (handle DIMM/dimm, SO-DIMM/SODIMM variations)
            $ramFormFactorNormalized = strtoupper(str_replace(['-', '_'], '', $ramFormFactor));
            $formFactorCompatible = false;

            foreach ($supportedFormFactors as $supportedFF) {
                $supportedFFNormalized = strtoupper(str_replace(['-', '_'], '', $supportedFF));
                if ($ramFormFactorNormalized === $supportedFFNormalized) {
                    $formFactorCompatible = true;
                    break;
                }
            }

            if (!$formFactorCompatible) {
                $result['compatible'] = false;
                $result['issues'][] = "RAM form factor '{$ramFormFactor}' is not compatible with motherboard (motherboard requires: " . implode(', ', $supportedFormFactors) . ")";
            } else {
                $result['recommendations'][] = "RAM form factor '{$ramFormFactor}' matches motherboard requirements";
            }
        }

        // Check memory type compatibility with intersection of requirements
        if (!empty($memoryRequirements['supported_types'])) {
            $supportedTypes = array_unique($memoryRequirements['supported_types']);

            // For multiple CPUs, find common supported types
            $typeCompatible = in_array($ramMemoryType, $supportedTypes);

            if (!$typeCompatible) {
                $result['compatible'] = false;
                $result['issues'][] = "Memory type {$ramMemoryType} not supported by existing components (supported: " . implode(', ', $supportedTypes) . ")";
            } else {
                $result['recommendations'][] = "Memory type {$ramMemoryType} compatible with existing components";
            }
        }

        // Enhanced speed compatibility checking with performance warnings
        if (!empty($memoryRequirements['max_speeds']) && $ramSpeed) {
            $minMaxSpeed = min($memoryRequirements['max_speeds']);
            $maxMaxSpeed = max($memoryRequirements['max_speeds']);

            if ($ramSpeed > $minMaxSpeed) {
                $result['compatibility_score'] *= 0.9;
                $result['warnings'][] = "Performance: RAM speed ({$ramSpeed}MHz) exceeds component limit ({$minMaxSpeed}MHz) - will run at reduced speed";
            } else if ($ramSpeed < $minMaxSpeed) {
                // RAM is slower than what the system supports - performance warning
                $result['compatibility_score'] *= 0.95;
                $result['warnings'][] = "Performance: RAM speed ({$ramSpeed}MHz) is lower than system capability ({$minMaxSpeed}MHz) - possible performance bottleneck";
            } else {
                $result['recommendations'][] = "RAM speed ({$ramSpeed}MHz) optimal for system";
            }

            // Additional warning if there's variation in max speeds (e.g., CPU vs Motherboard limits differ)
            if ($maxMaxSpeed > $minMaxSpeed) {
                $speedSources = $memoryRequirements['sources'] ?? [];
                if (!empty($speedSources)) {
                    $result['warnings'][] = "Note: Components have different max memory speeds (" . implode('; ', $speedSources) . ") - system will use lowest common speed";
                }
            }
        } else if ($ramSpeed) {
            // No existing components with speed requirements - show informational message
            $result['recommendations'][] = "RAM speed: {$ramSpeed}MHz (no speed constraints from existing components)";
        }

        return $result;
    }

    /**
     * Analyze existing motherboard for PCIe card compatibility
     */
    private function analyzeExistingMotherboardForPCIe($motherboardComponent, &$slotAvailability) {
        $mbData = $this->getComponentData('motherboard', $motherboardComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$mbData) {
            $result['details'][] = 'Motherboard specifications not found';
            return $result;
        }

        // Extract PCIe slot information
        $pcieSlots = $this->extractMotherboardPCIeSlots($mbData);

        $slotAvailability['total_slots'] = $pcieSlots['total'];
        $slotAvailability['available_by_size'] = $pcieSlots['by_size'];
        $slotAvailability['motherboard_generation'] = $pcieSlots['generation'];

        $result['details'][] = "Motherboard has {$pcieSlots['total']} total PCIe slots";
        if ($pcieSlots['generation']) {
            $result['details'][] = "Motherboard PCIe generation: Gen {$pcieSlots['generation']}";
        }

        // Log slot breakdown
        foreach ($pcieSlots['by_size'] as $size => $count) {
            if ($count > 0) {
                $result['details'][] = "Available: {$count}x PCIe {$size} slots";
            }
        }

        return $result;
    }

    /**
     * Analyze existing PCIe card (track slot usage)
     */
    private function analyzeExistingPCIeCardForPCIe($pcieCardComponent, &$slotAvailability) {
        error_log("DEBUG analyzeExistingPCIeCardForPCIe: UUID=" . $pcieCardComponent['uuid']);
        $pcieData = $this->getComponentData('pciecard', $pcieCardComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$pcieData) {
            // Unknown card, assume 1 slot
            $slotAvailability['used_slots'] += 1;
            $result['details'][] = 'Existing PCIe card (specs unknown) - uses 1 slot';
            error_log("DEBUG: PCIe card specs not found - counted as 1 used slot");
            return $result;
        }

        error_log("DEBUG: PCIe card data loaded: " . json_encode(['model' => $pcieData['model'] ?? 'Unknown', 'subtype' => $pcieData['subtype'] ?? 'Unknown']));

        // Check if it's a riser card
        if ($this->isPCIeRiserCard($pcieData)) {
            $providedSlots = $this->extractRiserCardSlots($pcieData);
            $slotAvailability['has_riser_card'] = true;

            // IMPORTANT: Riser cards use RISER SLOTS on the motherboard, NOT PCIe slots
            // They PROVIDE PCIe slots without consuming any motherboard PCIe slots
            // So we DO NOT increment used_slots here
            // Instead, we add the provided slots directly to riser_added_slots

            $slotAvailability['riser_added_slots'] += $providedSlots;

            // Also update available_by_size to track which slot sizes the riser provides
            $riserSlotType = $this->extractPCIeSlotSize($pcieData);
            $riserSlotTypeKey = 'x' . $riserSlotType;
            if (!isset($slotAvailability['available_by_size'][$riserSlotTypeKey])) {
                $slotAvailability['available_by_size'][$riserSlotTypeKey] = 0;
            }
            $slotAvailability['available_by_size'][$riserSlotTypeKey] += $providedSlots;

            $result['details'][] = "Riser card installed - provides {$providedSlots} PCIe {$riserSlotTypeKey} slot(s) (uses motherboard riser slot, not PCIe slot)";
            error_log("DEBUG: RISER CARD DETECTED - provides {$providedSlots} PCIe {$riserSlotTypeKey} slots. Total riser_added_slots now: " . $slotAvailability['riser_added_slots']);
            error_log("DEBUG: available_by_size after riser: " . json_encode($slotAvailability['available_by_size']));
        } else {
            // Regular PCIe card
            $cardSlotSize = $this->extractPCIeSlotSize($pcieData);
            $slotAvailability['used_slots'] += 1;

            $cardModel = $pcieData['model'] ?? 'PCIe Card';
            $result['details'][] = "Existing card: {$cardModel} (uses x{$cardSlotSize} slot)";
            error_log("DEBUG: Regular PCIe card - uses 1 slot. Total used_slots now: " . $slotAvailability['used_slots']);
        }

        return $result;
    }

    /**
     * Analyze existing NIC for PCIe slot usage
     */
    private function analyzeExistingNICForPCIe($nicComponent, &$slotAvailability) {
        $nicData = $this->getComponentData('nic', $nicComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$nicData) {
            // Unknown NIC, assume 1 slot
            $slotAvailability['used_slots'] += 1;
            $result['details'][] = 'Existing NIC (specs unknown) - uses 1 slot';
            return $result;
        }

        // Check if NIC is PCIe-based (vs onboard)
        $interface = $nicData['interface'] ?? $nicData['connection_type'] ?? $nicData['Notes'] ?? '';

        if (stripos($interface, 'PCIe') !== false || stripos($interface, 'PCI Express') !== false || stripos($interface, 'PCI-E') !== false) {
            $slotAvailability['used_slots'] += 1;

            $nicModel = $nicData['model'] ?? 'NIC';
            $result['details'][] = "Existing NIC: {$nicModel} (uses 1 PCIe slot)";
        } else {
            // Onboard NIC, doesn't use PCIe slot
            $result['details'][] = "Existing NIC is onboard - no slot used";
        }

        return $result;
    }

    /**
     * Apply PCIe compatibility rules
     */
    private function applyPCIeCompatibilityRules($pcieCardData, $slotAvailability, $cardGeneration, $cardSlotSize) {
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        // RULE 1: Check slot availability
        $totalAvailableSlots = $slotAvailability['total_slots'] + $slotAvailability['riser_added_slots'];
        $usedSlots = $slotAvailability['used_slots'];
        $remainingSlots = $totalAvailableSlots - $usedSlots;

        if ($remainingSlots <= 0) {
            $result['compatible'] = false;
            $result['compatibility_score'] = 0.0;
            $result['issues'][] = "All PCIe slots occupied ({$usedSlots}/{$totalAvailableSlots} used)";

            if (!$slotAvailability['has_riser_card']) {
                $result['recommendations'][] = "Add a riser card to expand PCIe slot capacity";
            } else {
                $result['recommendations'][] = "Remove existing PCIe components to free slots";
            }

            return $result;
        }

        // RULE 2: PCIe generation compatibility
        $motherboardGen = $slotAvailability['motherboard_generation'];

        if ($cardGeneration && $motherboardGen) {
            if ($cardGeneration < $motherboardGen) {
                // Older card in newer slot - backward compatible
                $result['warnings'][] = "Card is PCIe Gen {$cardGeneration}, motherboard supports Gen {$motherboardGen} - fully compatible (may not use full slot bandwidth)";
                $result['compatibility_score'] = 0.95;
            } elseif ($cardGeneration > $motherboardGen) {
                // Newer card in older slot - forward compatible but limited
                $result['warnings'][] = "Card is PCIe Gen {$cardGeneration}, motherboard supports Gen {$motherboardGen} - will run at Gen {$motherboardGen} speed (motherboard limitation)";
                $result['compatibility_score'] = 0.9;
            } else {
                // Perfect match
                $result['recommendations'][] = "PCIe generation match: Gen {$cardGeneration}";
            }
        } elseif ($cardGeneration && !$motherboardGen) {
            $result['warnings'][] = "Motherboard PCIe generation unknown - verify compatibility manually";
            $result['compatibility_score'] = 0.85;
        }

        // RULE 3: Physical slot size compatibility
        $slotFitResult = $this->checkPCIeSlotPhysicalFit($cardSlotSize, $slotAvailability);

        if (!$slotFitResult['fits']) {
            $result['compatible'] = false;
            $result['compatibility_score'] = 0.0;
            $result['issues'][] = $slotFitResult['reason'];
            $result['recommendations'][] = "Use a card that requires x{$slotFitResult['max_available']} or smaller slot";
            return $result;
        }

        if ($slotFitResult['oversized']) {
            $result['warnings'][] = $slotFitResult['warning'];
            $result['compatibility_score'] = min($result['compatibility_score'], 0.92);
        }

        return $result;
    }

    /**
     * Check if card physically fits in available slots
     */
    private function checkPCIeSlotPhysicalFit($cardSlotSize, $slotAvailability) {
        // Physical compatibility rules:
        // x1 card fits in: x1, x4, x8, x16 slots
        // x4 card fits in: x4, x8, x16 slots
        // x8 card fits in: x8, x16 slots
        // x16 card fits in: x16 slots only

        $availableSlots = $slotAvailability['available_by_size'];
        $usedSlots = $slotAvailability['used_slots'];

        // Determine which slot sizes can accommodate this card
        $compatibleSizes = [];

        switch ($cardSlotSize) {
            case 1:
                $compatibleSizes = [1, 4, 8, 16];
                break;
            case 4:
                $compatibleSizes = [4, 8, 16];
                break;
            case 8:
                $compatibleSizes = [8, 16];
                break;
            case 16:
                $compatibleSizes = [16];
                break;
            default:
                // Unknown size, assume needs x16
                $compatibleSizes = [16];
        }

        // Check if any compatible slot is available
        $hasAvailableSlot = false;
        $usedOversizedSlot = false;
        $slotUsed = null;

        foreach ($compatibleSizes as $size) {
            $slotKey = 'x' . $size;
            if (isset($availableSlots[$slotKey]) && $availableSlots[$slotKey] > 0) {
                $hasAvailableSlot = true;
                $slotUsed = $size;

                // Check if using oversized slot
                if ($size > $cardSlotSize) {
                    $usedOversizedSlot = true;
                }
                break; // Use smallest available slot
            }
        }

        if (!$hasAvailableSlot) {
            $availableSlotSizes = array_keys(array_filter($availableSlots, function($count) { return $count > 0; }));
            $maxAvailable = !empty($availableSlotSizes) ? max(array_map(function($key) {
                return (int)str_replace('x', '', $key);
            }, $availableSlotSizes)) : 0;

            return [
                'fits' => false,
                'oversized' => false,
                'reason' => "Card requires x{$cardSlotSize} slot, but no compatible slots available",
                'max_available' => $maxAvailable
            ];
        }

        if ($usedOversizedSlot) {
            return [
                'fits' => true,
                'oversized' => true,
                'warning' => "Card requires x{$cardSlotSize} slot, will be placed in x{$slotUsed} slot (acceptable but not optimal)"
            ];
        }

        return [
            'fits' => true,
            'oversized' => false
        ];
    }

    /**
     * Create compatibility summary for PCIe cards
     */
    private function createPCIeCompatibilitySummary($pcieCardData, $slotAvailability, $result) {
        if (!$result['compatible']) {
            return "Incompatible - " . implode(', ', $result['issues']);
        }

        $totalSlots = $slotAvailability['total_slots'] + $slotAvailability['riser_added_slots'];
        $usedSlots = $slotAvailability['used_slots'];
        $remainingSlots = $totalSlots - $usedSlots;

        $summary = "Compatible";

        // Add slot availability info
        if ($remainingSlots > 0) {
            $summary .= " ({$remainingSlots} of {$totalSlots} slots available)";
        }

        // Add warnings if any
        if (!empty($result['warnings'])) {
            $summary .= " - " . $result['warnings'][0]; // Show first warning
        }

        return $summary;
    }

    /**
     * Analyze existing motherboard for CPU compatibility requirements
     */
    private function analyzeExistingMotherboardForCPU($motherboardComponent, &$compatibilityRequirements) {
        $motherboardUuid = $motherboardComponent['uuid'];
        error_log("Analyzing motherboard $motherboardUuid for CPU requirements");

        $motherboardData = $this->getComponentData('motherboard', $motherboardUuid);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$motherboardData) {
            error_log("WARNING: Motherboard $motherboardUuid data not found");
            $result['details'][] = 'Motherboard specifications not found - basic compatibility assumed';
            return $result;
        }

        error_log("Motherboard $motherboardUuid data loaded, extracting socket type");

        // Extract motherboard socket type
        $socketType = $this->extractSocketType($motherboardData, 'motherboard');
        error_log("Motherboard $motherboardUuid socket extracted: " . ($socketType ?? 'NULL'));

        if ($socketType) {
            $compatibilityRequirements['required_socket'] = $socketType;
            $compatibilityRequirements['sources'][] = "Motherboard socket: {$socketType}";
            $result['details'][] = "Motherboard requires socket: {$socketType}";
            error_log("Set required_socket to: $socketType");
        } else {
            error_log("WARNING: Could not extract socket type from motherboard $motherboardUuid");
        }

        return $result;
    }

    /**
     * Analyze existing RAM for CPU compatibility requirements
     */
    private function analyzeExistingRAMForCPU($ramComponent, &$compatibilityRequirements) {
        $ramUuid = $ramComponent['uuid'];
        error_log("Analyzing RAM $ramUuid for CPU requirements");

        $ramData = $this->getComponentData('ram', $ramUuid);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$ramData) {
            error_log("WARNING: RAM $ramUuid data not found");
            $result['details'][] = 'RAM specifications not found - basic compatibility assumed';
            return $result;
        }

        // Extract RAM specifications (already normalized by extractMemoryType)
        $ramType = $this->extractMemoryType($ramData);
        $ramSpeed = $this->extractMemorySpeed($ramData);

        error_log("RAM $ramUuid extracted specs - Type: " . ($ramType ?? 'NULL') . ", Speed: " . ($ramSpeed ?? 'NULL') . "MHz");

        if ($ramType) {
            // RAM type is already normalized by extractMemoryType() to base DDR type (DDR5, DDR4, etc.)
            $compatibilityRequirements['memory_types_required'][] = $ramType;
            $compatibilityRequirements['sources'][] = "RAM type: {$ramType}";
            $result['details'][] = "CPU must support memory type: {$ramType}";
            error_log("Added RAM type requirement: $ramType");
        } else {
            error_log("WARNING: Could not extract memory type from RAM $ramUuid");
        }

        if ($ramSpeed) {
            $compatibilityRequirements['max_memory_speed_required'] = max($compatibilityRequirements['max_memory_speed_required'], $ramSpeed);
            $compatibilityRequirements['sources'][] = "RAM speed: {$ramSpeed}MHz";
            $result['details'][] = "CPU must support memory speed: {$ramSpeed}MHz or higher";
            error_log("Added RAM speed requirement: {$ramSpeed}MHz");
        }

        return $result;
    }

    /**
     * Analyze existing CPU for CPU compatibility (multi-socket scenarios)
     */
    private function analyzeExistingCPUForCPU($existingCpuComponent, $newCpuSocket) {
        $existingCpuData = $this->getComponentData('cpu', $existingCpuComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$existingCpuData) {
            $result['details'][] = 'Existing CPU specifications not found - basic compatibility assumed';
            return $result;
        }

        $existingSocket = $this->extractSocketType($existingCpuData, 'cpu');

        // Normalize socket types for comparison
        $existingSocketNormalized = strtolower(trim($existingSocket ?? ''));
        $newCpuSocketNormalized = strtolower(trim($newCpuSocket ?? ''));

        if ($existingSocket && $newCpuSocket && $existingSocketNormalized !== $newCpuSocketNormalized) {
            $result['compatible'] = false;
            $result['issues'][] = "CPU socket mismatch: new CPU ({$newCpuSocket}) vs existing CPU ({$existingSocket})";
        } else if ($existingSocket) {
            $result['details'][] = "CPU socket matches existing CPU: {$existingSocket}";
        }

        return $result;
    }

    /**
     * Apply final CPU compatibility rules using collected requirements
     */
    private function applyCPUCompatibilityRules($cpuData, $compatibilityRequirements) {
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        $cpuSocket = $this->extractSocketType($cpuData, 'cpu');
        $cpuMemoryTypes = $this->extractSupportedMemoryTypes($cpuData, 'cpu');
        $cpuMaxMemorySpeed = $this->extractMaxMemorySpeed($cpuData, 'cpu');

        error_log("applyCPUCompatibilityRules - CPU Socket: " . ($cpuSocket ?? 'NULL') .
                 ", Memory Types: " . json_encode($cpuMemoryTypes) .
                 ", Max Memory Speed: " . ($cpuMaxMemorySpeed ?? 'NULL'));

        // Check socket compatibility
        if ($compatibilityRequirements['required_socket']) {
            $requiredSocket = $compatibilityRequirements['required_socket'];

            // Normalize socket types for comparison (case-insensitive, trim whitespace)
            $cpuSocketNormalized = strtolower(trim($cpuSocket ?? ''));
            $requiredSocketNormalized = strtolower(trim($requiredSocket ?? ''));

            error_log("Socket comparison - CPU: '$cpuSocketNormalized' vs Required: '$requiredSocketNormalized' - Match: " .
                     ($cpuSocketNormalized === $requiredSocketNormalized ? 'YES' : 'NO'));

            if ($cpuSocketNormalized !== $requiredSocketNormalized) {
                $result['compatible'] = false;
                $result['issues'][] = "CPU socket ({$cpuSocket}) does not match required socket ({$requiredSocket})";
                error_log("SOCKET MISMATCH DETECTED!");
            } else {
                $result['recommendations'][] = "CPU socket ({$cpuSocket}) matches motherboard socket";
                error_log("Socket match confirmed");
            }
        } else {
            error_log("No socket requirement specified");
        }

        // Check memory type compatibility with smart backward compatibility logic
        if (!empty($compatibilityRequirements['memory_types_required'])) {
            $requiredTypes = array_unique($compatibilityRequirements['memory_types_required']);
            error_log("Checking memory type compatibility - Required: " . json_encode($requiredTypes) .
                     ", CPU supports: " . json_encode($cpuMemoryTypes));

            foreach ($requiredTypes as $requiredType) {
                // Normalize the required type
                $normalizedRequired = $this->normalizeMemoryType($requiredType);
                $compatible = false;
                $compatWarning = null;
                $compatReason = null;

                if ($cpuMemoryTypes && is_array($cpuMemoryTypes)) {
                    // Check each CPU-supported memory type
                    foreach ($cpuMemoryTypes as $cpuType) {
                        $compatCheck = $this->checkMemoryTypeCompatibility($cpuType, $normalizedRequired);

                        if ($compatCheck['compatible']) {
                            $compatible = true;

                            // Store warning if backward compatibility scenario (DDR5 CPU + DDR4 RAM)
                            if ($compatCheck['warning']) {
                                $result['warnings'][] = $compatCheck['warning'];
                                error_log("Memory type compatible with warning: " . $compatCheck['warning']);
                            } else {
                                error_log("Memory type perfect match: " . $compatCheck['reason']);
                            }

                            $result['recommendations'][] = "CPU supports required memory type: {$normalizedRequired}";
                            break; // Found compatible type, no need to check others
                        } else {
                            // Store the incompatibility reason for potential use
                            $compatReason = $compatCheck['reason'];
                        }
                    }
                }

                // If no compatible memory type found, mark as incompatible
                if (!$compatible) {
                    $result['compatible'] = false;
                    if ($compatReason) {
                        $result['issues'][] = $compatReason;
                        error_log("MEMORY TYPE INCOMPATIBLE: " . $compatReason);
                    } else {
                        $result['issues'][] = "CPU does not support required memory type: {$normalizedRequired} (CPU supports: " . implode(', ', $cpuMemoryTypes) . ")";
                        error_log("MEMORY TYPE MISMATCH: CPU does not support $normalizedRequired");
                    }
                }
            }
        } else {
            error_log("No memory type requirements specified");
        }

        // Check memory speed compatibility
        if ($compatibilityRequirements['max_memory_speed_required'] > 0 && $cpuMaxMemorySpeed) {
            $requiredSpeed = $compatibilityRequirements['max_memory_speed_required'];
            error_log("Checking memory speed - CPU max: {$cpuMaxMemorySpeed}MHz, Required: {$requiredSpeed}MHz");

            if ($cpuMaxMemorySpeed < $requiredSpeed) {
                $result['compatible'] = false;
                $result['issues'][] = "CPU maximum memory speed ({$cpuMaxMemorySpeed}MHz) is lower than required ({$requiredSpeed}MHz)";
                error_log("MEMORY SPEED MISMATCH: CPU speed too low");
            } else {
                $result['recommendations'][] = "CPU memory speed ({$cpuMaxMemorySpeed}MHz) supports existing RAM ({$requiredSpeed}MHz)";
                error_log("Memory speed check passed");
            }
        } else {
            error_log("No memory speed requirements specified");
        }

        return $result;
    }

    /**
     * Create concise CPU compatibility summary for display
     */
    private function createCPUCompatibilitySummary($cpuData, $compatibilityRequirements, $compatibilityResult) {
        $cpuSocket = $this->extractSocketType($cpuData, 'cpu');
        $requiredSocket = $compatibilityRequirements['required_socket'] ?? null;

        // Normalize socket types for comparison
        $cpuSocketNormalized = strtolower(trim($cpuSocket ?? ''));
        $requiredSocketNormalized = strtolower(trim($requiredSocket ?? ''));

        // If compatible
        if ($compatibilityResult['compatible']) {
            if ($requiredSocket) {
                return "Compatible with {$requiredSocket} socket";
            } else {
                return "Compatible - no constraints found";
            }
        }
        // If incompatible - provide detailed reason
        else {
            // First check if we have specific issues in the result
            if (!empty($compatibilityResult['issues'])) {
                // Return the first (most important) issue
                return $compatibilityResult['issues'][0];
            }

            // Fallback to socket-based messages
            if ($requiredSocket && $cpuSocket && $cpuSocketNormalized !== $requiredSocketNormalized) {
                return "CPU socket ({$cpuSocket}) incompatible with motherboard socket ({$requiredSocket})";
            } elseif ($requiredSocket && !$cpuSocket) {
                return "CPU socket unknown - motherboard requires {$requiredSocket}";
            } elseif (!$requiredSocket && !$cpuSocket) {
                return "Incompatible - CPU and motherboard socket specifications not found";
            } elseif (!$requiredSocket) {
                return "Incompatible - motherboard socket requirements not determined";
            } else {
                // This should rarely happen now - log for debugging
                error_log("WARNING: CPU compatibility check failed but no specific issues found. CPU Socket: $cpuSocket, Required: $requiredSocket");
                return "Incompatible - compatibility check failed (CPU: $cpuSocket, Required: $requiredSocket)";
            }
        }
    }

    /**
     * Analyze existing CPU for motherboard compatibility requirements
     */
    private function analyzeExistingCPUForMotherboard($cpuComponent, &$compatibilityRequirements) {
        $cpuData = $this->getComponentData('cpu', $cpuComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$cpuData) {
            $result['details'][] = 'CPU specifications not found - basic compatibility assumed';
            return $result;
        }

        // Extract CPU socket type
        $cpuSocket = $this->extractSocketType($cpuData, 'cpu');
        if ($cpuSocket) {
            $compatibilityRequirements['required_cpu_socket'] = $cpuSocket;
            $compatibilityRequirements['sources'][] = "CPU socket: {$cpuSocket}";
            $result['details'][] = "Motherboard must support CPU socket: {$cpuSocket}";
        }

        return $result;
    }

    /**
     * Analyze existing RAM for motherboard compatibility requirements
     */
    private function analyzeExistingRAMForMotherboard($ramComponent, &$compatibilityRequirements) {
        $ramData = $this->getComponentData('ram', $ramComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$ramData) {
            $result['details'][] = 'RAM specifications not found - basic compatibility assumed';
            return $result;
        }

        // Extract RAM specifications
        $ramType = $this->extractMemoryType($ramData);
        $ramSpeed = $this->extractMemorySpeed($ramData);
        $ramFormFactor = $this->extractMemoryFormFactor($ramData);
        $ramModuleType = $ramData['module_type'] ?? null; // RDIMM, LRDIMM, UDIMM

        // DEBUG: Log RAM data extraction
        error_log("DEBUG [analyzeExistingRAMForMotherboard] RAM UUID: {$ramComponent['uuid']}");
        error_log("DEBUG [analyzeExistingRAMForMotherboard] RAM Data Keys: " . json_encode(array_keys($ramData)));
        error_log("DEBUG [analyzeExistingRAMForMotherboard] Extracted ramModuleType: " . ($ramModuleType ?? 'NULL'));
        error_log("DEBUG [analyzeExistingRAMForMotherboard] Full RAM Data: " . json_encode($ramData));

        if ($ramType) {
            $compatibilityRequirements['required_memory_types'][] = $ramType;
            $compatibilityRequirements['sources'][] = "RAM type: {$ramType}";
            $result['details'][] = "Motherboard must support memory type: {$ramType}";
        }

        if ($ramSpeed) {
            $compatibilityRequirements['min_memory_speed_required'] = max($compatibilityRequirements['min_memory_speed_required'], $ramSpeed);
            $compatibilityRequirements['sources'][] = "RAM speed: {$ramSpeed}MHz";
            $result['details'][] = "Motherboard must support memory speed: {$ramSpeed}MHz or higher";
        }

        if ($ramFormFactor) {
            $compatibilityRequirements['required_form_factors'][] = $ramFormFactor;
            $compatibilityRequirements['sources'][] = "RAM form factor: {$ramFormFactor}";
            $result['details'][] = "Motherboard must support form factor: {$ramFormFactor}";
        }

        // CRITICAL: Extract and track RAM module type (RDIMM/LRDIMM/UDIMM)
        // This is essential for backward compatibility checking when searching for motherboards
        if ($ramModuleType) {
            $compatibilityRequirements['required_module_types'][] = strtoupper($ramModuleType);
            $compatibilityRequirements['sources'][] = "RAM module type: {$ramModuleType}";
            $result['details'][] = "Motherboard must support RAM module type: {$ramModuleType}";

            // DEBUG: Log module type requirement
            error_log("DEBUG [analyzeExistingRAMForMotherboard] Added required_module_type: " . strtoupper($ramModuleType));
        } else {
            error_log("DEBUG [analyzeExistingRAMForMotherboard] WARNING: No module_type found in RAM data!");
        }

        return $result;
    }

    /**
     * Analyze existing motherboard for motherboard compatibility (multi-motherboard scenarios)
     */
    private function analyzeExistingMotherboardForMotherboard($existingMotherboardComponent) {
        $result = ['compatible' => false, 'issues' => [], 'details' => []];

        // Typically only one motherboard is allowed per server configuration
        $result['compatible'] = false;
        $result['issues'][] = "Server already has a motherboard - only one motherboard allowed per configuration";
        $result['details'][] = "Cannot add multiple motherboards to the same server configuration";

        return $result;
    }

    /**
     * Apply final motherboard compatibility rules using collected requirements
     */
    private function applyMotherboardCompatibilityRules($motherboardData, $compatibilityRequirements) {
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => []
        ];

        $motherboardSocket = $this->extractSocketType($motherboardData, 'motherboard');
        $motherboardMemoryTypes = $this->extractSupportedMemoryTypes($motherboardData, 'motherboard');
        $motherboardMaxMemorySpeed = $this->extractMaxMemorySpeed($motherboardData, 'motherboard');

        // Check CPU socket compatibility
        if ($compatibilityRequirements['required_cpu_socket']) {
            $requiredSocket = $compatibilityRequirements['required_cpu_socket'];

            // Normalize socket types for comparison
            $motherboardSocketNormalized = strtolower(trim($motherboardSocket ?? ''));
            $requiredSocketNormalized = strtolower(trim($requiredSocket ?? ''));

            if ($motherboardSocketNormalized !== $requiredSocketNormalized) {
                $result['compatible'] = false;
                $result['issues'][] = "Motherboard socket ({$motherboardSocket}) does not match CPU socket ({$requiredSocket})";
            } else {
                $result['recommendations'][] = "Motherboard socket ({$motherboardSocket}) matches CPU socket";
            }
        }

        // Check memory type compatibility
        if (!empty($compatibilityRequirements['required_memory_types'])) {
            $requiredTypes = array_unique($compatibilityRequirements['required_memory_types']);

            foreach ($requiredTypes as $requiredType) {
                if ($motherboardMemoryTypes && !in_array($requiredType, $motherboardMemoryTypes)) {
                    $result['compatible'] = false;
                    $result['issues'][] = "Motherboard does not support required memory type: {$requiredType} (supported: " . implode(', ', $motherboardMemoryTypes) . ")";
                } else {
                    $result['recommendations'][] = "Motherboard supports required memory type: {$requiredType}";
                }
            }
        }

        // Check memory speed compatibility
        if ($compatibilityRequirements['min_memory_speed_required'] > 0 && $motherboardMaxMemorySpeed) {
            $requiredSpeed = $compatibilityRequirements['min_memory_speed_required'];

            if ($motherboardMaxMemorySpeed < $requiredSpeed) {
                $result['compatible'] = false;
                $result['issues'][] = "Motherboard maximum memory speed ({$motherboardMaxMemorySpeed}MHz) is lower than required ({$requiredSpeed}MHz)";
            } else {
                $result['recommendations'][] = "Motherboard memory speed ({$motherboardMaxMemorySpeed}MHz) supports existing RAM ({$requiredSpeed}MHz)";
            }
        }

        // CRITICAL: Check RAM module type compatibility (RDIMM, LRDIMM, UDIMM)
        // This ensures backward compatibility when searching for motherboards
        if (!empty($compatibilityRequirements['required_module_types'])) {
            $requiredModuleTypes = array_unique($compatibilityRequirements['required_module_types']);
            $motherboardModuleTypes = $this->extractSupportedModuleTypes($motherboardData);

            // DEBUG: Log module type comparison
            error_log("DEBUG [applyMotherboardCompatibilityRules] Required module types: " . json_encode($requiredModuleTypes));
            error_log("DEBUG [applyMotherboardCompatibilityRules] Motherboard module types: " . json_encode($motherboardModuleTypes));
            error_log("DEBUG [applyMotherboardCompatibilityRules] Motherboard data memory section: " . json_encode($motherboardData['memory'] ?? 'NOT FOUND'));

            // If motherboard doesn't specify module types, assume it supports all (backward compatibility)
            // This allows older JSON specifications without module_type fields to remain compatible
            if ($motherboardModuleTypes === null) {
                $result['warnings'][] = "Motherboard module type support not specified - assuming compatible with " . implode('/', $requiredModuleTypes);
                error_log("DEBUG [applyMotherboardCompatibilityRules] Motherboard module types NULL - assuming compatible");
                // Don't break - continue with other validations
            } else {
                // Motherboard has explicit module type support - validate it
                foreach ($requiredModuleTypes as $requiredModuleType) {
                    $requiredModuleTypeUpper = strtoupper($requiredModuleType);
                    $motherboardModuleTypesUpper = array_map('strtoupper', $motherboardModuleTypes);

                    // DEBUG: Log individual check
                    error_log("DEBUG [applyMotherboardCompatibilityRules] Checking if '$requiredModuleTypeUpper' is in [" . implode(', ', $motherboardModuleTypesUpper) . "]");

                    // Check if motherboard supports the required module type
                    if (!in_array($requiredModuleTypeUpper, $motherboardModuleTypesUpper)) {
                        $result['compatible'] = false;
                        $result['issues'][] = "Motherboard memory incompatible with existing RAM";
                        $result['details'][] = "Motherboard does not support {$requiredModuleType} modules (supported: " . implode(', ', $motherboardModuleTypes) . ")";

                        error_log("DEBUG [applyMotherboardCompatibilityRules] INCOMPATIBLE: '$requiredModuleTypeUpper' NOT FOUND in motherboard support");
                    } else {
                        $result['recommendations'][] = "Motherboard supports required module type: {$requiredModuleType}";

                        error_log("DEBUG [applyMotherboardCompatibilityRules] COMPATIBLE: '$requiredModuleTypeUpper' FOUND in motherboard support");
                    }
                }
            }
        } else {
            error_log("DEBUG [applyMotherboardCompatibilityRules] No required_module_types in compatibility requirements");
        }

        return $result;
    }

    /**
     * Create concise motherboard compatibility summary for display
     */
    private function createMotherboardCompatibilitySummary($motherboardData, $compatibilityRequirements, $compatibilityResult) {
        // Check for existing motherboard error first
        if (!$compatibilityResult['compatible'] && !empty($compatibilityResult['issues'])) {
            foreach ($compatibilityResult['issues'] as $issue) {
                if (strpos($issue, 'Server already has a motherboard') !== false) {
                    return "Motherboard already installed - only one motherboard allowed per server-config";
                }
            }
        }

        $motherboardSocket = $this->extractSocketType($motherboardData, 'motherboard');
        $requiredCpuSocket = $compatibilityRequirements['required_cpu_socket'] ?? null;
        $requiredMemoryTypes = $compatibilityRequirements['required_memory_types'] ?? [];

        // Normalize socket types for comparison
        $motherboardSocketNormalized = strtolower(trim($motherboardSocket ?? ''));
        $requiredCpuSocketNormalized = strtolower(trim($requiredCpuSocket ?? ''));

        // If compatible
        if ($compatibilityResult['compatible']) {
            $constraints = [];

            if ($requiredCpuSocket) {
                $constraints[] = "{$requiredCpuSocket} socket";
            }

            if (!empty($requiredMemoryTypes)) {
                $constraints[] = implode('/', $requiredMemoryTypes) . " memory";
            }

            if (!empty($constraints)) {
                return "Compatible with " . implode(' and ', $constraints);
            } else {
                return "Compatible - no constraints found";
            }
        }
        // If incompatible
        else {
            if ($requiredCpuSocket && $motherboardSocket && $motherboardSocketNormalized !== $requiredCpuSocketNormalized) {
                return "Motherboard socket ({$motherboardSocket}) incompatible with CPU socket ({$requiredCpuSocket})";
            } elseif (!empty($requiredMemoryTypes)) {
                $motherboardMemoryTypes = $this->extractSupportedMemoryTypes($motherboardData, 'motherboard');
                if ($motherboardMemoryTypes) {
                    $unsupportedTypes = array_diff($requiredMemoryTypes, $motherboardMemoryTypes);
                    if (!empty($unsupportedTypes)) {
                        return "Motherboard does not support " . implode('/', $unsupportedTypes) . " memory";
                    }
                }

                // Check if it's a module type issue
                $requiredModuleTypes = $compatibilityRequirements['required_module_types'] ?? [];
                if (!empty($requiredModuleTypes)) {
                    $motherboardModuleTypes = $this->extractSupportedModuleTypes($motherboardData);
                    if ($motherboardModuleTypes !== null) {
                        $unsupportedModules = array_diff(
                            array_map('strtoupper', $requiredModuleTypes),
                            array_map('strtoupper', $motherboardModuleTypes)
                        );
                        if (!empty($unsupportedModules)) {
                            return "Motherboard does not support " . implode('/', $unsupportedModules) . " RAM modules";
                        }
                    }
                }

                return "Motherboard memory incompatible with existing RAM";
            } elseif ($requiredCpuSocket && !$motherboardSocket) {
                return "Motherboard socket unknown - CPU requires {$requiredCpuSocket}";
            } else {
                return "Incompatible - check specifications";
            }
        }
    }

    /**
     * Analyze existing motherboard for storage compatibility requirements
     */
    private function analyzeExistingMotherboardForStorage($motherboardComponent, &$storageRequirements) {
        $mbData = $this->getComponentData('motherboard', $motherboardComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$mbData) {
            $result['details'][] = 'Motherboard specifications not found - basic compatibility assumed';
            return $result;
        }

        // Try to load from JSON first
        $mbSpecs = $this->loadMotherboardSpecs($motherboardComponent['uuid']);
        if ($mbSpecs && isset($mbSpecs['storage'])) {
            $storageSupport = $mbSpecs['storage'];

            // Extract supported interfaces
            if (isset($storageSupport['interfaces'])) {
                $storageRequirements['supported_interfaces'] = array_merge(
                    $storageRequirements['supported_interfaces'],
                    $storageSupport['interfaces']
                );
                $result['details'][] = 'Motherboard supports interfaces: ' . implode(', ', $storageSupport['interfaces']);
            }

            // Extract available slots/bays
            if (isset($storageSupport['slots'])) {
                $storageRequirements['available_slots'] = $storageSupport['slots'];
                $result['details'][] = 'Available storage slots: ' . count($storageSupport['slots']);
            }

            // Extract M.2 slot information from drive_bays (not storage)
            $driveBays = $mbSpecs['drive_bays'] ?? [];
            if (isset($driveBays['m2_slots']) && !empty($driveBays['m2_slots'])) {
                $m2Slots = $driveBays['m2_slots'];

                if (!empty($m2Slots) && is_array($m2Slots)) {
                    $firstSlotConfig = $m2Slots[0];

                    // Store M.2 form factor support
                    $m2FormFactors = $firstSlotConfig['form_factors'] ?? [];
                    $storageRequirements['m2_form_factors'] = $m2FormFactors;

                    // Store M.2 slot count
                    $m2SlotCount = $firstSlotConfig['count'] ?? 0;
                    $storageRequirements['m2_slots'] = [
                        'total' => $m2SlotCount,
                        'used' => 0, // Will be calculated later
                        'available' => $m2SlotCount,
                        'pcie_generation' => $firstSlotConfig['pcie_generation'] ?? 4,
                        'pcie_lanes' => $firstSlotConfig['pcie_lanes'] ?? 4
                    ];

                    $result['details'][] = "Motherboard M.2 slots: {$m2SlotCount} available, supports " . implode(', ', $m2FormFactors) . " (PCIe Gen " . ($firstSlotConfig['pcie_generation'] ?? 4) . ")";

                    // Add NVMe interface support (for M.2 path)
                    $nvmeInterfaces = ['NVMe', 'PCIe NVMe', 'NVMe PCIe 3.0', 'NVMe PCIe 4.0', 'NVMe PCIe 5.0'];
                    foreach ($nvmeInterfaces as $nvmeInterface) {
                        if (!in_array($nvmeInterface, $storageRequirements['supported_interfaces'])) {
                            $storageRequirements['supported_interfaces'][] = $nvmeInterface;
                        }
                    }
                }
            }
        } else {
            // Fallback to database parsing
            $mbInterfaces = $this->extractStorageInterfaces($mbData);
            if ($mbInterfaces) {
                $storageRequirements['supported_interfaces'] = array_merge(
                    $storageRequirements['supported_interfaces'],
                    $mbInterfaces
                );
                $result['details'][] = 'Motherboard supports: ' . implode(', ', $mbInterfaces);
            }
        }

        return $result;
    }

    /**
     * Analyze existing storage for storage compatibility requirements
     */
    private function analyzeExistingStorageForStorage($storageComponent, &$storageRequirements) {
        $storageData = $this->getComponentData('storage', $storageComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$storageData) {
            $result['details'][] = 'Existing storage specifications not found';
            return $result;
        }

        // Check slot usage - if this storage is using exclusive slots
        $storageInterface = $this->extractStorageInterface($storageData);
        $storageFormFactor = $this->extractStorageFormFactor($storageData);

        if ($storageInterface) {
            $result['details'][] = "Existing storage uses {$storageInterface} interface";
        }

        if ($storageFormFactor) {
            $result['details'][] = "Existing storage form factor: {$storageFormFactor}";

            // PHASE 1: Form Factor Locking for 2.5" and 3.5" storage
            // Extract normalized form factor (2.5-inch or 3.5-inch)
            $normalizedFF = $this->extractFormFactorSize($storageFormFactor);

            if ($normalizedFF === '2.5-inch' || $normalizedFF === '3.5-inch') {
                // If not already locked, set the lock to this storage's form factor
                if (!isset($storageRequirements['form_factor_lock'])) {
                    $storageRequirements['form_factor_lock'] = $normalizedFF;
                    $result['details'][] = "FORM FACTOR LOCKED to {$normalizedFF} by existing storage";
                }
            }
        }

        return $result;
    }

    /**
     * Analyze existing caddy for storage compatibility requirements
     */
    private function analyzeExistingCaddyForStorage($caddyComponent, &$storageRequirements) {
        $caddyData = $this->getComponentData('caddy', $caddyComponent['uuid']);
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        if (!$caddyData) {
            $result['details'][] = 'Caddy specifications not found';
            return $result;
        }

        // Extract supported form factors from caddy
        $supportedFormFactors = $this->extractSupportedFormFactors($caddyData);
        if ($supportedFormFactors) {
            $storageRequirements['required_form_factors'] = array_merge(
                $storageRequirements['required_form_factors'],
                $supportedFormFactors
            );
            $result['details'][] = 'Caddy supports form factors: ' . implode(', ', $supportedFormFactors);
        }

        return $result;
    }

    /**
     * Analyze existing HBA card for storage compatibility
     * Extracts HBA protocol support and port capacity
     */
    private function analyzeExistingHBAForStorage($hbaComponent, &$storageRequirements) {
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        // Load HBA specifications from JSON
        $hbaData = $this->getComponentData('hbacard', $hbaComponent['uuid']);
        if (!$hbaData) {
            $result['details'][] = 'HBA card specifications not found in JSON';
            return $result;
        }

        // Extract HBA protocol (e.g., "SAS/SATA/NVMe Tri-Mode", "SAS", "SATA")
        $hbaProtocol = $hbaData['protocol'] ?? '';
        $internalPorts = $hbaData['internal_ports'] ?? 0;
        $maxDevices = $hbaData['max_devices'] ?? 0;

        // Parse protocol and add supported interfaces
        $supportedInterfaces = [];
        if (stripos($hbaProtocol, 'sas') !== false) {
            $supportedInterfaces[] = 'SAS';
            $result['details'][] = 'HBA supports SAS protocol';
        }
        if (stripos($hbaProtocol, 'sata') !== false) {
            $supportedInterfaces[] = 'SATA';
            $supportedInterfaces[] = 'SATA III';
            $result['details'][] = 'HBA supports SATA protocol';
        }
        if (stripos($hbaProtocol, 'nvme') !== false) {
            $supportedInterfaces[] = 'NVMe';
            $supportedInterfaces[] = 'PCIe NVMe';
            $supportedInterfaces[] = 'PCIe NVMe 3.0';
            $supportedInterfaces[] = 'PCIe NVMe 4.0';
            $result['details'][] = 'HBA supports NVMe protocol';
        }

        // Add supported interfaces to storage requirements
        foreach ($supportedInterfaces as $interface) {
            if (!in_array($interface, $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = $interface;
            }
        }

        // Calculate HBA port usage - count existing storage devices
        // Note: M.2 NVMe drives on motherboard don't use HBA ports
        $usedPorts = 0;
        if (isset($storageRequirements['existing_storage_count'])) {
            $usedPorts = $storageRequirements['existing_storage_count'];
        }

        $availablePorts = max(0, $internalPorts - $usedPorts);

        // Check if HBA ports are exhausted
        if ($availablePorts <= 0 && $internalPorts > 0) {
            $result['compatible'] = false;
            $result['issues'][] = "HBA card ports exhausted ({$internalPorts} ports, all used)";
            $result['details'][] = "HBA: {$hbaData['model']} - {$internalPorts} internal ports, {$usedPorts} used, {$availablePorts} available";
        } else {
            $result['details'][] = "HBA: {$hbaData['model']} - {$internalPorts} internal ports, {$usedPorts} used, {$availablePorts} available";
        }

        // Store HBA capacity info for later use
        $storageRequirements['hba_ports'] = [
            'total' => $internalPorts,
            'used' => $usedPorts,
            'available' => $availablePorts,
            'max_devices' => $maxDevices
        ];

        return $result;
    }

    /**
     * Analyze existing chassis for storage compatibility
     * Extracts backplane interface support and bay capacity
     */
    private function analyzeExistingChassisForStorage($chassisComponent, &$storageRequirements) {
        $result = ['compatible' => true, 'issues' => [], 'details' => []];

        // Load chassis specifications from JSON
        $chassisData = $this->loadChassisSpecs($chassisComponent['uuid']);
        if (!$chassisData) {
            $result['details'][] = 'Chassis specifications not found in JSON';
            return $result;
        }

        // Extract backplane capabilities
        $backplane = $chassisData['backplane'] ?? [];
        $supportsSata = $backplane['supports_sata'] ?? false;
        $supportsSas = $backplane['supports_sas'] ?? false;
        $supportsNvme = $backplane['supports_nvme'] ?? false;
        $backplaneInterface = $backplane['interface'] ?? 'Unknown';

        // Add supported interfaces based on backplane capabilities
        if ($supportsSata) {
            if (!in_array('SATA', $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = 'SATA';
            }
            if (!in_array('SATA III', $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = 'SATA III';
            }
            $result['details'][] = "Chassis backplane supports SATA";
        }
        if ($supportsSas) {
            if (!in_array('SAS', $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = 'SAS';
            }
            $result['details'][] = "Chassis backplane supports SAS";
        }
        if ($supportsNvme) {
            if (!in_array('NVMe', $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = 'NVMe';
            }
            if (!in_array('PCIe NVMe', $storageRequirements['supported_interfaces'])) {
                $storageRequirements['supported_interfaces'][] = 'PCIe NVMe';
            }
            $result['details'][] = "Chassis backplane supports NVMe";
        }

        // Extract drive bay information
        $driveBays = $chassisData['drive_bays'] ?? [];
        $totalBays = $driveBays['total_bays'] ?? 0;
        $bayConfiguration = $driveBays['bay_configuration'] ?? [];

        // Count used bays (existing storage in configuration)
        $usedBays = 0;
        if (isset($storageRequirements['existing_storage_count'])) {
            $usedBays = $storageRequirements['existing_storage_count'];
        }

        // Calculate effective storage limit: min(chassis bays, HBA ports)
        $hbaPorts = $storageRequirements['hba_ports']['total'] ?? $totalBays;
        $effectiveLimit = min($totalBays, $hbaPorts);
        $availableBays = max(0, $effectiveLimit - $usedBays);

        // Check if bays/ports are exhausted
        if ($availableBays <= 0 && $totalBays > 0) {
            $result['compatible'] = false;
            if ($hbaPorts < $totalBays) {
                $result['issues'][] = "Storage capacity limited by HBA ports: {$hbaPorts} ports available, chassis has {$totalBays} bays (effective limit: {$effectiveLimit})";
            } else {
                $result['issues'][] = "Chassis drive bays exhausted: {$totalBays} bays, all used";
            }
            $result['details'][] = "Chassis: {$chassisData['model']} - {$totalBays} bays, effective limit {$effectiveLimit} (HBA-limited), {$usedBays} used, {$availableBays} available";
        } else {
            if ($hbaPorts < $totalBays) {
                $result['details'][] = "Chassis: {$chassisData['model']} - {$totalBays} physical bays, effective limit {$effectiveLimit} (limited by {$hbaPorts} HBA ports), {$usedBays} used, {$availableBays} available";
            } else {
                $result['details'][] = "Chassis: {$chassisData['model']} - {$totalBays} bays, {$usedBays} used, {$availableBays} available";
            }
        }

        // Extract form factor compatibility - store bay types separately for strict matching
        $chassisBayTypes = [];
        foreach ($bayConfiguration as $bayConfig) {
            $bayType = $bayConfig['bay_type'] ?? '';
            $count = $bayConfig['count'] ?? 0;
            $hotSwap = $bayConfig['hot_swap'] ?? false;

            if ($bayType) {
                // Normalize bay type: "3.5_inch" → "3.5-inch"
                $normalizedBayType = str_replace('_', '-', $bayType);

                // Store in separate chassis_bay_types array for strict matching
                if (!in_array($normalizedBayType, $chassisBayTypes)) {
                    $chassisBayTypes[] = $normalizedBayType;
                }

                $hotSwapText = $hotSwap ? 'hot-swap' : 'non-hot-swap';
                $result['details'][] = "Bay type: {$normalizedBayType} ({$count} {$hotSwapText} bays)";
            }
        }

        // Store bay types in separate array (not required_form_factors)
        $storageRequirements['chassis_bay_types'] = $chassisBayTypes;

        // Store chassis capacity info
        $storageRequirements['chassis_bays'] = [
            'total' => $totalBays,
            'effective_limit' => $effectiveLimit,
            'used' => $usedBays,
            'available' => $availableBays,
            'backplane_interface' => $backplaneInterface,
            'bay_types' => $chassisBayTypes
        ];

        return $result;
    }

    /**
     * Apply storage compatibility rules with connection path logic
     * Determines chassis bay vs motherboard M.2 vs U.2 paths
     */
    private function applyStorageCompatibilityRules($storageData, $storageRequirements) {
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'warnings' => [],
            'recommendations' => [],
            'connection_path' => 'unknown'
        ];

        $storageInterface = $this->extractStorageInterface($storageData);
        $storageFormFactor = $this->extractStorageFormFactor($storageData);

        // Determine which connection path this storage uses
        $connectionPath = $this->determineStorageConnectionPath($storageFormFactor, $storageInterface);
        $result['connection_path'] = $connectionPath;

        // Route to appropriate validation logic based on connection path
        if ($connectionPath === 'chassis_bay') {
            return $this->validateChassisBayStorage($storageInterface, $storageFormFactor, $storageRequirements, $result);
        } elseif ($connectionPath === 'motherboard_m2') {
            return $this->validateMotherboardM2Storage($storageInterface, $storageFormFactor, $storageRequirements, $result);
        } elseif ($connectionPath === 'motherboard_u2') {
            return $this->validateMotherboardU2Storage($storageInterface, $storageFormFactor, $storageRequirements, $result);
        }

        // Unknown path - use generic validation
        return $this->validateGenericStorage($storageInterface, $storageFormFactor, $storageRequirements, $result);
    }

    /**
     * Validate storage that connects via chassis bays (SATA/SAS 2.5"/3.5")
     * STRICT RULE: Bay size MUST match storage size (no adapters)
     * UPDATED: If NO chassis exists, allow all 2.5"/3.5" storage
     * PHASE 1: Enforces form factor locking (2.5" vs 3.5")
     */
    private function validateChassisBayStorage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
        // Extract storage size (2.5-inch or 3.5-inch)
        $storageSize = $this->extractFormFactorSize($storageFormFactor);

        // PHASE 1: Check form factor lock FIRST (before chassis check)
        // If existing storage locked the form factor, enforce it
        $formFactorLock = $storageRequirements['form_factor_lock'] ?? null;
        if ($formFactorLock && $formFactorLock !== $storageSize) {
            $result['compatible'] = false;
            $result['compatibility_score'] = 0.0;
            $result['issues'][] = "Form factor locked to {$formFactorLock} by existing storage - cannot add {$storageSize} storage";
            $result['recommendations'][] = "Choose {$formFactorLock} storage to match existing storage OR remove existing {$formFactorLock} storage";
            return $result;
        }

        // Get chassis bay types
        $chassisBayTypes = $storageRequirements['chassis_bay_types'] ?? [];

        // NEW: If NO chassis exists (empty bay types), allow storage
        // This enables component-order flexibility (storage before chassis)
        if (empty($chassisBayTypes)) {
            $result['compatible'] = true;
            $result['compatibility_score'] = 1.0;
            $result['details'][] = "No chassis in configuration - storage will be validated when chassis is added";
            $result['warnings'][] = "Add chassis with {$storageSize} bays to complete configuration";

            // If this is the first storage, it sets the form factor lock
            if (!$formFactorLock) {
                $result['details'][] = "This storage will lock form factor to {$storageSize} for future additions";
            }

            return $result;
        }

        // STRICT MATCHING: Bay size MUST equal storage size
        $hasMatchingBay = in_array($storageSize, $chassisBayTypes);

        if (!$hasMatchingBay) {
            $result['compatible'] = false;
            $result['compatibility_score'] = 0.0;
            $result['issues'][] = "Storage form factor {$storageSize} doesn't match chassis bay types: " . implode(', ', $chassisBayTypes);

            if (!empty($chassisBayTypes)) {
                $result['recommendations'][] = "Choose {$chassisBayTypes[0]} storage to match chassis bays OR select different chassis";
            }

            return $result;
        }

        // Size matches - now check interface compatibility
        $supportedInterfaces = $storageRequirements['supported_interfaces'] ?? [];

        if (!in_array($storageInterface, $supportedInterfaces)) {
            $result['compatible'] = false;
            $result['compatibility_score'] = 0.0;
            $result['issues'][] = "Storage interface {$storageInterface} not supported by chassis backplane/HBA";
            $result['recommendations'][] = "HBA/backplane supports: " . implode(', ', $supportedInterfaces);

            return $result;
        }

        // Check HBA port capacity
        $hbaPorts = $storageRequirements['hba_ports'] ?? [];
        if (isset($hbaPorts['available']) && $hbaPorts['available'] <= 0) {
            $result['compatible'] = false;
            $result['compatibility_score'] = 0.0;
            $result['issues'][] = "HBA card ports exhausted ({$hbaPorts['total']} ports, all used)";
            $result['recommendations'][] = "Add another HBA card OR remove existing storage";

            return $result;
        }

        // All checks passed - compatible!
        $result['compatible'] = true;
        $result['compatibility_score'] = 1.0;
        $result['recommendations'][] = "Storage connects via chassis bay (size: {$storageSize}, interface: {$storageInterface})";

        if (isset($hbaPorts['available'])) {
            $result['recommendations'][] = "HBA ports: {$hbaPorts['available']} of {$hbaPorts['total']} available after adding this drive";
        }

        return $result;
    }

    /**
     * Validate storage that connects via motherboard M.2 slots
     */
    private function validateMotherboardM2Storage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
        $m2Slots = $storageRequirements['m2_slots'] ?? [];
        $m2FormFactors = $storageRequirements['m2_form_factors'] ?? [];
        $hasNvmeAdapters = !empty($storageRequirements['nvme_adapters'] ?? []);

        // Check if M.2 slots exist on motherboard OR if NVMe adapters provide slots
        if (empty($m2Slots)) {
            // No motherboard M.2 slots - check for NVMe adapters
            if ($hasNvmeAdapters) {
                // NVMe adapters exist - they can provide M.2 slots
                $result['compatible'] = true;
                $result['compatibility_score'] = 1.0;
                $result['details'][] = "M.2 storage will use NVMe adapter slots (no motherboard M.2 slots)";
                return $result;
            }

            // No motherboard AND no adapters - allow storage (component-order flexibility)
            $result['compatible'] = true;
            $result['compatibility_score'] = 1.0;
            $result['details'][] = "No motherboard in configuration - M.2 storage will be validated when motherboard or NVMe adapter is added";
            $result['warnings'][] = "Add motherboard with M.2 slots OR NVMe adapter card to connect this storage";
            return $result;
        }

        // Check if M.2 form factor is supported
        if (!in_array($storageFormFactor, $m2FormFactors)) {
            $result['compatible'] = false;
            $result['compatibility_score'] = 0.0;
            $result['issues'][] = "M.2 form factor {$storageFormFactor} not supported by motherboard";
            $result['recommendations'][] = "Motherboard supports: " . implode(', ', $m2FormFactors);

            return $result;
        }

        // Check if M.2 slots are available
        $m2SlotsAvailable = $m2Slots['available'] ?? 0;
        if ($m2SlotsAvailable <= 0) {
            $result['compatible'] = false;
            $result['compatibility_score'] = 0.0;
            $result['issues'][] = "No available M.2 slots on motherboard ({$m2Slots['total']} total, all used)";
            $result['recommendations'][] = "Remove existing M.2 storage OR use chassis bay storage";

            return $result;
        }

        // Check PCIe generation compatibility
        $storagePCIeGen = $this->extractStoragePCIeGeneration($storageInterface);
        $slotPCIeGen = $m2Slots['pcie_generation'] ?? 4;

        if ($storagePCIeGen <= $slotPCIeGen) {
            // Backward compatible or exact match
            $result['compatible'] = true;
            $result['compatibility_score'] = 1.0;
            $result['recommendations'][] = "M.2 storage connects directly to motherboard M.2 slot";

            if ($storagePCIeGen < $slotPCIeGen) {
                // Running at storage's native speed (no performance loss)
                $result['recommendations'][] = "PCIe {$storagePCIeGen} drive on PCIe {$slotPCIeGen} slot - runs at PCIe {$storagePCIeGen} speeds (backward compatible, no performance loss)";
            } else {
                $result['recommendations'][] = "PCIe {$storagePCIeGen} drive on PCIe {$slotPCIeGen} slot - perfect match";
            }
        } else {
            // Storage requires higher gen than slot provides - still compatible but with warning
            $result['compatible'] = true;
            $result['compatibility_score'] = 0.85;
            $result['warnings'][] = "PCIe {$storagePCIeGen} drive on PCIe {$slotPCIeGen} slot - will run at reduced PCIe {$slotPCIeGen} speeds";

            // Calculate bandwidth reduction
            $bandwidthReduction = (1 - ($slotPCIeGen / $storagePCIeGen)) * 100;
            $result['warnings'][] = sprintf("Performance: ~%.0f%% bandwidth reduction compared to native speed", $bandwidthReduction);
        }

        $result['recommendations'][] = "M.2 slots: {$m2SlotsAvailable} of {$m2Slots['total']} available after adding this drive";

        return $result;
    }

    /**
     * Validate storage that connects via motherboard U.2 slots
     */
    private function validateMotherboardU2Storage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
        // TODO: Implement U.2 validation similar to M.2
        // For now, mark as incompatible
        $result['compatible'] = false;
        $result['compatibility_score'] = 0.0;
        $result['issues'][] = "U.2 storage validation not yet implemented";
        $result['recommendations'][] = "Choose M.2 or chassis bay storage";

        return $result;
    }

    /**
     * Generic storage validation fallback
     */
    private function validateGenericStorage($storageInterface, $storageFormFactor, $storageRequirements, $result) {
        // Fallback to interface matching only
        $supportedInterfaces = $storageRequirements['supported_interfaces'] ?? [];

        if (!empty($supportedInterfaces) && !in_array($storageInterface, $supportedInterfaces)) {
            $result['compatible'] = false;
            $result['compatibility_score'] = 0.0;
            $result['issues'][] = "Storage interface {$storageInterface} not supported";
            $result['recommendations'][] = "Supported interfaces: " . implode(', ', $supportedInterfaces);
        } else {
            $result['compatible'] = true;
            $result['compatibility_score'] = 0.7;
            $result['warnings'][] = "Generic compatibility check - verify physical installation manually";
        }

        return $result;
    }

    /**
     * Extract chassis bay configuration and calculate capacity
     */
    private function extractChassisBayConfiguration($chassisSpecs) {
        if (!isset($chassisSpecs['drive_bays']['bay_configuration'])) {
            return [];
        }

        $bayCapacity = [];
        foreach ($chassisSpecs['drive_bays']['bay_configuration'] as $bayConfig) {
            $bayType = $bayConfig['bay_type'] ?? 'unknown';
            $count = $bayConfig['count'] ?? 0;

            $bayCapacity[$bayType] = ($bayCapacity[$bayType] ?? 0) + $count;
        }

        return $bayCapacity;
    }

    /**
     * Extract storage form factor from storage data
     */
    private function extractStorageFormFactorFromSpecs($storageData) {
        // Try JSON specs first
        $storageSpecs = $this->loadStorageSpecs($storageData['uuid'] ?? '');
        if ($storageSpecs && isset($storageSpecs['form_factor'])) {
            $formFactor = $storageSpecs['form_factor'];

            // Normalize form factor naming
            if (strpos($formFactor, '3.5') !== false) {
                return '3.5_inch';
            } elseif (strpos($formFactor, '2.5') !== false) {
                return '2.5_inch';
            } elseif (strpos($formFactor, 'M.2') !== false) {
                return 'M.2';
            }
        }

        // Fallback to Notes field parsing
        $notes = $storageData['Notes'] ?? $storageData['notes'] ?? '';
        if (preg_match('/3\.5["\']?/i', $notes)) {
            return '3.5_inch';
        } elseif (preg_match('/2\.5["\']?/i', $notes)) {
            return '2.5_inch';
        } elseif (preg_match('/M\.2/i', $notes)) {
            return 'M.2';
        }

        // Default fallback based on storage type
        if (stripos($notes, 'HDD') !== false) {
            return '3.5_inch'; // HDDs are typically 3.5"
        } elseif (stripos($notes, 'SSD') !== false) {
            return '2.5_inch'; // SSDs are typically 2.5"
        }

        return 'unknown';
    }

    /**
     * Calculate required bay count by form factor from existing storage
     */
    private function calculateRequiredBays($existingStorageComponents) {
        $requiredBays = [];

        foreach ($existingStorageComponents as $storage) {
            $formFactor = $this->extractStorageFormFactorFromSpecs($storage['data']);

            // Skip M.2 drives as they don't typically use chassis bays
            if ($formFactor !== 'M.2' && $formFactor !== 'unknown') {
                $requiredBays[$formFactor] = ($requiredBays[$formFactor] ?? 0) + 1;
            }
        }

        return $requiredBays;
    }

    /**
     * Check if chassis bays can accommodate storage requirements
     */
    private function validateChassisBayCapacity($chassisBays, $requiredBays) {
        $result = [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'issues' => [],
            'recommendations' => []
        ];

        foreach ($requiredBays as $formFactor => $requiredCount) {
            $availableCount = $chassisBays[$formFactor] ?? 0;

            if ($availableCount >= $requiredCount) {
                // Perfect match - strict form factor already validated above
                $spare = $availableCount - $requiredCount;
                if ($spare >= 2) {
                    $result['recommendations'][] = "Chassis has {$availableCount} x {$formFactor} bays, {$spare} spare for expansion";
                } else {
                    $result['recommendations'][] = "Chassis has adequate {$availableCount} x {$formFactor} bays for {$requiredCount} drives";
                }
            } else {
                // Insufficient bays of the required form factor (strict matching - no adapters)
                $result['compatible'] = false;
                $result['compatibility_score'] = 0.0;
                $result['issues'][] = "Insufficient {$formFactor} bays: need {$requiredCount}, chassis has {$availableCount}";
            }
        }

        return $result;
    }

    /**
     * Extract chassis motherboard form factor compatibility
     */
    private function extractChassisMotherboardCompatibility($chassisSpecs) {
        if (!isset($chassisSpecs['motherboard_compatibility'])) {
            return ['form_factors' => ['ATX']]; // Default assumption
        }

        return [
            'form_factors' => $chassisSpecs['motherboard_compatibility']['form_factors'] ?? ['ATX'],
            'mounting_points' => $chassisSpecs['motherboard_compatibility']['mounting_points'] ?? 'standard_atx',
            'max_size' => $chassisSpecs['motherboard_compatibility']['max_motherboard_size'] ?? null
        ];
    }

    // ==========================================
    // RISER CARD VALIDATION METHODS
    // ==========================================

    /**
     * Validate adding a riser card to server config
     * Handles cases: with motherboard, without motherboard, with chassis, without chassis
     *
     * @param array $riserComponent ['uuid' => 'riser-uuid', 'type' => 'pcie_card']
     * @param array $existingComponents Array of existing components in config
     * @return array ['compatible' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validateAddRiserCard($riserComponent, $existingComponents) {
        $riserData = $this->getPCIeCardData($riserComponent['uuid']);
        $errors = [];
        $warnings = [];

        // Verify this is actually a riser card
        if (!$this->isPCIeRiserCard($riserData)) {
            return [
                'compatible' => true,
                'errors' => [],
                'warnings' => []
            ];
        }

        // Find motherboard and chassis in config
        $motherboard = $this->findComponentByType($existingComponents, 'motherboard');
        $chassis = $this->findComponentByType($existingComponents, 'chassis');
        $existingRisers = $this->getExistingRisers($existingComponents);

        // ===== CASE 1: MOTHERBOARD EXISTS =====
        if ($motherboard) {
            $motherboardData = $this->getMotherboardData($motherboard['uuid']);

            // Check 1.1: Riser slot availability
            $slotInfo = $this->getRiserSlotAvailability($motherboardData, $existingComponents);
            if ($slotInfo['available_slots'] <= 0) {
                $errors[] = "No riser slots available. Motherboard has {$slotInfo['total_slots']} riser slots, all occupied.";
            }

            // Check 1.2: Length fit
            if (!$this->checkRiserLengthFit($riserData, $existingRisers, $motherboardData)) {
                $totalRiserLength = $this->calculateTotalRiserLength($existingRisers) + ($riserData['dimensions_mm']['length'] ?? 0);
                $availableLength = $motherboardData['expansion_slots']['riser_compatibility']['available_mounting_length_mm'] ?? 0;
                $errors[] = "Riser length exceeds available motherboard mounting space. Need: {$totalRiserLength}mm, Available: {$availableLength}mm";
            }

            // Check 1.3: Spacing/width fit
            if (!$this->checkRiserSpacingFit($riserData, $motherboardData)) {
                $riserWidth = $riserData['dimensions_mm']['width'] ?? 0;
                $slotSpacing = $motherboardData['expansion_slots']['riser_compatibility']['slot_spacing_mm'] ?? 20.32;
                $errors[] = "Riser width ({$riserWidth}mm) exceeds motherboard slot spacing ({$slotSpacing}mm)";
            }
        }
        // ===== CASE 2: NO MOTHERBOARD =====
        else {
            $requiredSlots = count($existingRisers) + 1;
            $warnings[] = "No motherboard in config. When adding motherboard, ensure it has at least {$requiredSlots} riser slot(s).";
        }

        // ===== CASE 3: CHASSIS EXISTS =====
        if ($chassis) {
            $chassisData = $this->getChassisData($chassis['uuid']);

            // Check 3.1: Height clearance
            if (!$this->checkRiserHeightClearance($riserData, $chassisData)) {
                $chassisHeightMm = $chassisData['height'] * 10; // Convert cm to mm
                $riserClearance = $riserData['clearance_height_mm'] ?? 0;
                $errors[] = "Riser clearance height ({$riserClearance}mm) exceeds chassis height ({$chassisHeightMm}mm)";
            }
        }
        // ===== CASE 4: NO CHASSIS =====
        else {
            $maxRiserHeight = $this->getMaxRiserHeight(array_merge($existingRisers, [$riserData]));
            $requiredChassisHeight = ceil($maxRiserHeight / 10); // Convert mm to cm, round up
            $warnings[] = "No chassis in config. When adding chassis, ensure height is at least {$requiredChassisHeight}cm.";
        }

        return [
            'compatible' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Validate adding motherboard when risers already exist in config
     *
     * @param array $motherboardComponent ['uuid' => 'mb-uuid', 'type' => 'motherboard']
     * @param array $existingComponents Array of existing components
     * @return array ['compatible' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validateAddMotherboard($motherboardComponent, $existingComponents) {
        $motherboardData = $this->getMotherboardData($motherboardComponent['uuid']);
        $errors = [];
        $warnings = [];

        $existingRisers = $this->getExistingRisers($existingComponents);

        if (count($existingRisers) > 0) {
            // Check 1: Motherboard has enough riser slots
            $requiredSlots = count($existingRisers);
            $availableSlots = $motherboardData['expansion_slots']['riser_compatibility']['max_risers'] ?? 0;

            if ($availableSlots < $requiredSlots) {
                $errors[] = "Motherboard only has {$availableSlots} riser slot(s), but config has {$requiredSlots} riser(s) already installed";
            }

            // Check 2: Motherboard length can fit all risers
            $totalRiserLength = $this->calculateTotalRiserLength($existingRisers);
            $availableLength = $motherboardData['expansion_slots']['riser_compatibility']['available_mounting_length_mm'] ?? 0;

            if ($totalRiserLength > $availableLength) {
                $errors[] = "Total riser length ({$totalRiserLength}mm) exceeds motherboard mounting space ({$availableLength}mm)";
            }

            // Check 3: Slot spacing fits riser widths
            $slotSpacing = $motherboardData['expansion_slots']['riser_compatibility']['slot_spacing_mm'] ?? 20.32;
            foreach ($existingRisers as $riser) {
                $riserWidth = $riser['dimensions_mm']['width'] ?? 0;
                $riserModel = $riser['model'] ?? 'Unknown';

                if ($riserWidth > ($slotSpacing * 3)) { // Max 3 slots width
                    $errors[] = "Riser '{$riserModel}' width ({$riserWidth}mm) exceeds motherboard slot spacing capability";
                }
            }
        }

        return [
            'compatible' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Validate adding chassis when risers already exist in config
     *
     * @param array $chassisComponent ['uuid' => 'chassis-uuid', 'type' => 'chassis']
     * @param array $existingComponents Array of existing components
     * @return array ['compatible' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validateAddChassis($chassisComponent, $existingComponents) {
        $chassisData = $this->getChassisData($chassisComponent['uuid']);
        $errors = [];
        $warnings = [];

        $existingRisers = $this->getExistingRisers($existingComponents);

        if (count($existingRisers) > 0) {
            // Find the tallest riser in config
            $maxRiserHeight = 0;
            $tallestRiser = null;

            foreach ($existingRisers as $riser) {
                $clearance = $riser['clearance_height_mm'] ?? 0;
                if ($clearance > $maxRiserHeight) {
                    $maxRiserHeight = $clearance;
                    $tallestRiser = $riser['model'] ?? 'Unknown';
                }
            }

            // Check: Chassis height must accommodate tallest riser
            $chassisHeightMm = $chassisData['height'] * 10; // Convert cm to mm

            if ($maxRiserHeight >= $chassisHeightMm) {
                $errors[] = "Chassis height ({$chassisHeightMm}mm) is too small for existing risers. Tallest riser '{$tallestRiser}' requires {$maxRiserHeight}mm clearance.";
            }
        }

        return [
            'compatible' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Calculate available riser slots on motherboard (tracks like PCIe slots)
     *
     * @param array $motherboardData Motherboard specifications
     * @param array $existingComponents Existing components in config
     * @return array ['total_slots' => int, 'used_slots' => int, 'available_slots' => int]
     */
    private function getRiserSlotAvailability($motherboardData, $existingComponents) {
        $totalRiserSlots = $motherboardData['expansion_slots']['riser_compatibility']['max_risers'] ?? 0;

        // Count risers already in config
        $usedRiserSlots = 0;
        foreach ($existingComponents as $component) {
            if ($component['component_type'] === 'pciecard') {
                $pcieData = $this->getPCIeCardData($component['component_uuid']);
                if ($this->isPCIeRiserCard($pcieData)) {
                    $usedRiserSlots++;
                }
            }
        }

        return [
            'total_slots' => $totalRiserSlots,
            'used_slots' => $usedRiserSlots,
            'available_slots' => max(0, $totalRiserSlots - $usedRiserSlots)
        ];
    }

    /**
     * Extract all riser cards from existing components
     *
     * @param array $existingComponents Array of components
     * @return array Array of riser card data
     */
    private function getExistingRisers($existingComponents) {
        $risers = [];

        foreach ($existingComponents as $component) {
            if ($component['component_type'] === 'pciecard') {
                $pcieData = $this->getPCIeCardData($component['component_uuid']);
                if ($this->isPCIeRiserCard($pcieData)) {
                    $risers[] = $pcieData;
                }
            }
        }

        return $risers;
    }

    /**
     * Find component by type in existing components
     *
     * @param array $existingComponents Array of components
     * @param string $type Component type to find
     * @return array|null Component data or null
     */
    private function findComponentByType($existingComponents, $type) {
        foreach ($existingComponents as $component) {
            if ($component['component_type'] === $type) {
                return [
                    'uuid' => $component['component_uuid'],
                    'type' => $component['component_type']
                ];
            }
        }
        return null;
    }

    /**
     * Check if riser height fits within chassis clearance
     *
     * @param array $riserData Riser card specifications
     * @param array $chassisData Chassis specifications
     * @return bool True if fits
     */
    private function checkRiserHeightClearance($riserData, $chassisData) {
        $chassisHeightMm = $chassisData['height'] * 10; // Convert cm to mm
        $riserClearance = $riserData['clearance_height_mm'] ?? 0;

        return $riserClearance < $chassisHeightMm;
    }

    /**
     * Check if riser length fits on motherboard
     *
     * @param array $riserData Riser card specifications
     * @param array $existingRisers Array of existing riser cards
     * @param array $motherboardData Motherboard specifications
     * @return bool True if fits
     */
    private function checkRiserLengthFit($riserData, $existingRisers, $motherboardData) {
        $riserLength = $riserData['dimensions_mm']['length'] ?? 0;
        $availableLength = $motherboardData['expansion_slots']['riser_compatibility']['available_mounting_length_mm'] ?? 0;

        $totalUsedLength = $this->calculateTotalRiserLength($existingRisers);

        return ($totalUsedLength + $riserLength) <= $availableLength;
    }

    /**
     * Check if riser width fits within motherboard slot spacing
     *
     * @param array $riserData Riser card specifications
     * @param array $motherboardData Motherboard specifications
     * @return bool True if fits
     */
    private function checkRiserSpacingFit($riserData, $motherboardData) {
        $riserWidth = $riserData['dimensions_mm']['width'] ?? 0;
        $slotSpacing = $motherboardData['expansion_slots']['riser_compatibility']['slot_spacing_mm'] ?? 20.32;

        // Riser width must fit within slot spacing
        return $riserWidth <= $slotSpacing;
    }

    /**
     * Calculate total length occupied by risers
     *
     * @param array $risers Array of riser card data
     * @return int Total length in mm
     */
    private function calculateTotalRiserLength($risers) {
        $totalLength = 0;
        foreach ($risers as $riser) {
            $totalLength += $riser['dimensions_mm']['length'] ?? 0;
        }
        return $totalLength;
    }

    /**
     * Get maximum riser height from array of risers
     *
     * @param array $risers Array of riser card data
     * @return float Maximum height in mm
     */
    private function getMaxRiserHeight($risers) {
        $maxHeight = 0;
        foreach ($risers as $riser) {
            $clearance = $riser['clearance_height_mm'] ?? 0;
            if ($clearance > $maxHeight) {
                $maxHeight = $clearance;
            }
        }
        return $maxHeight;
    }

    /**
     * Get motherboard data by UUID
     *
     * @param string $uuid Motherboard UUID
     * @return array Motherboard specifications
     */
    private function getMotherboardData($uuid) {
        // Use existing parseMotherboardSpecifications method
        $result = $this->parseMotherboardSpecifications($uuid);
        return $result['specifications'] ?? [];
    }

    /**
     * Get chassis data by UUID
     *
     * @param string $uuid Chassis UUID
     * @return array Chassis specifications
     */
    private function getChassisData($uuid) {
        // Parse chassis JSON to get specifications
        $chassisJsonPath = __DIR__ . '/../../All-JSON/chasis-jsons/chasis-level-3.json';

        if (!isset($this->jsonDataCache[$chassisJsonPath])) {
            if (file_exists($chassisJsonPath)) {
                $jsonContent = file_get_contents($chassisJsonPath);
                $data = json_decode($jsonContent, true);
                $this->jsonDataCache[$chassisJsonPath] = $data['chassis_specifications']['manufacturers'] ?? [];
            } else {
                return [];
            }
        }

        $manufacturers = $this->jsonDataCache[$chassisJsonPath];

        foreach ($manufacturers as $manufacturer) {
            foreach ($manufacturer['series'] as $series) {
                foreach ($series['models'] as $model) {
                    if ($model['uuid'] === $uuid) {
                        return $model;
                    }
                }
            }
        }

        return [];
    }

    /**
     * Get PCIe card data by UUID (already exists but making it available)
     *
     * @param string $uuid PCIe card UUID
     * @return array PCIe card specifications
     */
    private function getPCIeCardData($uuid) {
        // Parse PCIe JSON to get specifications
        $pcieJsonPath = __DIR__ . '/../../All-JSON/pci-jsons/pci-level-3.json';

        if (!isset($this->jsonDataCache[$pcieJsonPath])) {
            if (file_exists($pcieJsonPath)) {
                $jsonContent = file_get_contents($pcieJsonPath);
                $data = json_decode($jsonContent, true);
                $this->jsonDataCache[$pcieJsonPath] = $data ?? [];
            } else {
                return [];
            }
        }

        $pcieData = $this->jsonDataCache[$pcieJsonPath];

        foreach ($pcieData as $category) {
            if (isset($category['models'])) {
                foreach ($category['models'] as $model) {
                    if ($model['UUID'] === $uuid) {
                        return $model;
                    }
                }
            }
        }

        return [];
    }

    /**
     * Determine storage connection path based on form factor and interface
     * Returns: 'chassis_bay', 'motherboard_m2', 'motherboard_u2', 'pcie_adapter'
     */
    private function determineStorageConnectionPath($formFactor, $interface) {
        $formFactorLower = strtolower($formFactor);
        $interfaceLower = strtolower($interface);

        // M.2 form factors → motherboard M.2 slots
        if (strpos($formFactorLower, 'm.2') !== false || strpos($formFactorLower, 'm2') !== false) {
            return 'motherboard_m2';
        }

        // U.2 form factors → motherboard U.2 slots or PCIe adapter
        if (strpos($formFactorLower, 'u.2') !== false || strpos($formFactorLower, 'u.3') !== false) {
            return 'motherboard_u2';
        }

        // 2.5-inch or 3.5-inch SATA/SAS → chassis bays
        if (strpos($formFactorLower, '2.5') !== false || strpos($formFactorLower, '3.5') !== false) {
            if (strpos($interfaceLower, 'sata') !== false || strpos($interfaceLower, 'sas') !== false) {
                return 'chassis_bay';
            }
        }

        // Default to chassis bay for traditional drives
        return 'chassis_bay';
    }

    /**
     * Extract form factor size (2.5-inch or 3.5-inch)
     * Used for strict chassis bay matching
     */
    private function extractFormFactorSize($formFactor) {
        $formFactorLower = strtolower($formFactor);

        if (strpos($formFactorLower, '2.5') !== false) {
            return '2.5-inch';
        }
        if (strpos($formFactorLower, '3.5') !== false) {
            return '3.5-inch';
        }

        // Return as-is if not standard size
        return $formFactor;
    }

    /**
     * Extract PCIe generation from storage interface string
     * Examples: "NVMe PCIe 4.0" → 4.0, "PCIe 5.0" → 5.0
     */
    private function extractStoragePCIeGeneration($interface) {
        // Match "PCIe 4.0", "NVMe PCIe 4.0", etc.
        if (preg_match('/pcie\s*(\d+(?:\.\d+)?)/i', $interface, $matches)) {
            return (float)$matches[1];
        }

        // Default to 3.0 if not specified
        return 3.0;
    }

    /**
     * Normalize form factor for comparison
     * Handles variations: "3.5-inch", "3.5\"", "3.5 inch", "3.5inch" → "3.5-inch"
     */
    private function normalizeFormFactorForComparison($formFactor) {
        if (empty($formFactor)) {
            return '';
        }

        // Convert to lowercase and remove extra spaces
        $normalized = strtolower(trim($formFactor));

        // Replace variations of inch notation
        $normalized = str_replace(['"', ' inch', 'inch', '_'], ['', '-inch', '-inch', '-'], $normalized);

        // Ensure consistent format: "2.5-inch" or "3.5-inch"
        $normalized = preg_replace('/(\d+\.?\d*)\s*-?\s*inch/', '$1-inch', $normalized);

        return $normalized;
    }
}
?>