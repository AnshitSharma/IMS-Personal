<?php
/**
 * Infrastructure Management System - Storage-Chassis Compatibility
 * File: includes/models/StorageChassisCompatibility.php
 * 
 * Extended compatibility validation for storage-chassis-motherboard combinations
 */

require_once __DIR__ . '/ComponentCompatibility.php';
require_once __DIR__ . '/ChassisManager.php';
require_once __DIR__ . '/DataExtractionUtilities.php';

class StorageChassisCompatibility extends ComponentCompatibility {
    private $chassisManager;
    private $dataExtractor;
    
    public function __construct($pdo) {
        parent::__construct($pdo);
        $this->chassisManager = new ChassisManager();
        $this->dataExtractor = new DataExtractionUtilities();
    }
    
    /**
     * Main validation method for storage components with chassis
     */
    public function validateStorageForConfiguration($configUUID, $storageUUID, $chassisUUID, $targetBay = null) {
        try {
            // Step 1: Validate UUIDs
            $uuidValidation = $this->validateUUIDs($storageUUID, $chassisUUID);
            if (!$uuidValidation['valid']) {
                return [
                    'compatible' => false,
                    'compatibility_score' => 0.0,
                    'issues' => $uuidValidation['errors'],
                    'warnings' => [],
                    'recommendations' => ['Verify storage and chassis UUIDs exist in JSON specifications']
                ];
            }
            
            // Step 2: Direct storage-chassis compatibility
            $storageChassisResult = $this->validateStorageChassisCompatibility($storageUUID, $chassisUUID, $targetBay);
            if (!$storageChassisResult['compatible']) {
                return $storageChassisResult;
            }
            
            // Step 3: Get motherboard from configuration (if exists)
            $motherboardUUID = $this->getMotherboardFromConfiguration($configUUID);
            if ($motherboardUUID) {
                // Step 4: Storage-motherboard compatibility
                $storageMotherboardResult = $this->validateStorageMotherboardCompatibility($storageUUID, $motherboardUUID);
                if (!$storageMotherboardResult['compatible']) {
                    return $storageMotherboardResult;
                }
                
                // Step 5: Triangle compatibility (storage-chassis-motherboard)
                $triangleResult = $this->validateTriangleCompatibility($storageUUID, $chassisUUID, $motherboardUUID);
                if (!$triangleResult['compatible']) {
                    return $triangleResult;
                }
                
                // Combine results
                $combinedScore = min($storageChassisResult['compatibility_score'], 
                                   $storageMotherboardResult['compatibility_score'],
                                   $triangleResult['compatibility_score']);
                
                return [
                    'compatible' => true,
                    'compatibility_score' => $combinedScore,
                    'issues' => [],
                    'warnings' => array_merge(
                        $storageChassisResult['warnings'],
                        $storageMotherboardResult['warnings'],
                        $triangleResult['warnings']
                    ),
                    'recommendations' => array_merge(
                        $storageChassisResult['recommendations'],
                        $storageMotherboardResult['recommendations'],
                        $triangleResult['recommendations']
                    )
                ];
            }
            
            // No motherboard in configuration - return storage-chassis result only
            return $storageChassisResult;
            
        } catch (Exception $e) {
            return [
                'compatible' => false,
                'compatibility_score' => 0.0,
                'issues' => ['Validation error: ' . $e->getMessage()],
                'warnings' => [],
                'recommendations' => ['Check system logs and JSON file integrity']
            ];
        }
    }
    
    /**
     * Validate UUIDs exist in respective JSON files
     */
    private function validateUUIDs($storageUUID, $chassisUUID) {
        $errors = [];
        
        // Validate chassis UUID
        $chassisValidation = $this->chassisManager->validateChassisExists($chassisUUID);
        if (!$chassisValidation['exists']) {
            $errors[] = "Chassis UUID not found: $chassisUUID";
        }
        
        // Validate storage UUID
        $storageSpecs = $this->loadStorageSpecsByUUID($storageUUID);
        if (!$storageSpecs['found']) {
            $errors[] = "Storage UUID not found: $storageUUID";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Validate direct storage-chassis compatibility
     */
    public function validateStorageChassisCompatibility($storageUUID, $chassisUUID, $targetBay = null) {
        // Get storage specifications
        $storageSpecs = $this->loadStorageSpecsByUUID($storageUUID);
        if (!$storageSpecs['found']) {
            return [
                'compatible' => false,
                'compatibility_score' => 0.0,
                'issues' => [$storageSpecs['error']],
                'warnings' => [],
                'recommendations' => []
            ];
        }
        
        // Get chassis specifications
        $chassisSpecs = $this->chassisManager->loadChassisSpecsByUUID($chassisUUID);
        if (!$chassisSpecs['found']) {
            return [
                'compatible' => false,
                'compatibility_score' => 0.0,
                'issues' => [$chassisSpecs['error']],
                'warnings' => [],
                'recommendations' => []
            ];
        }
        
        $storage = $storageSpecs['specifications'];
        $chassis = $chassisSpecs['specifications'];
        
        $issues = [];
        $warnings = [];
        $recommendations = [];
        $score = 1.0;
        
        // 1. Form factor compatibility
        $formFactorResult = $this->checkFormFactorCompatibility($storage, $chassis);
        if (!$formFactorResult['compatible']) {
            $issues[] = $formFactorResult['message'];
            $score = 0.0;
        } else if (!empty($formFactorResult['warnings'])) {
            $warnings = array_merge($warnings, $formFactorResult['warnings']);
            $score *= 0.9; // Slight penalty for warnings
        }
        
        // 2. Interface compatibility
        $interfaceResult = $this->checkInterfaceSupport($storage, $chassis);
        if (!$interfaceResult['compatible']) {
            $issues[] = $interfaceResult['message'];
            $score = 0.0;
        } else if (!empty($interfaceResult['warnings'])) {
            $warnings = array_merge($warnings, $interfaceResult['warnings']);
            $score *= 0.95;
        }
        
        // 3. Bay availability (if target bay specified)
        if ($targetBay) {
            $bayResult = $this->checkBayAvailability($chassisUUID, $targetBay);
            if (!$bayResult['available']) {
                $issues[] = $bayResult['message'];
                $score = 0.0;
            }
        }
        
        // Generate recommendations
        if (empty($issues) && !empty($warnings)) {
            $recommendations[] = "Consider storage alternatives for optimal compatibility";
        }
        
        return [
            'compatible' => empty($issues),
            'compatibility_score' => $score,
            'issues' => $issues,
            'warnings' => $warnings,
            'recommendations' => $recommendations
        ];
    }
    
    /**
     * Validate storage-motherboard compatibility
     */
    public function validateStorageMotherboardCompatibility($storageUUID, $motherboardUUID) {
        // Get storage specifications
        $storageSpecs = $this->loadStorageSpecsByUUID($storageUUID);
        if (!$storageSpecs['found']) {
            return [
                'compatible' => false,
                'compatibility_score' => 0.0,
                'issues' => [$storageSpecs['error']],
                'warnings' => [],
                'recommendations' => []
            ];
        }
        
        // Get motherboard specifications
        $motherboardSpecs = $this->loadMotherboardSpecsByUUID($motherboardUUID);
        if (!$motherboardSpecs['found']) {
            return [
                'compatible' => false,
                'compatibility_score' => 0.0,
                'issues' => [$motherboardSpecs['error']],
                'warnings' => [],
                'recommendations' => []
            ];
        }
        
        $storage = $storageSpecs['specifications'];
        $motherboard = $motherboardSpecs['specifications'];
        
        $issues = [];
        $warnings = [];
        $recommendations = [];
        $score = 1.0;
        
        // 1. Connector compatibility
        $connectorResult = $this->checkConnectorCompatibility($storage, $motherboard);
        if (!$connectorResult['compatible']) {
            $issues[] = $connectorResult['message'];
            $score = 0.0;
        }
        
        // 2. PCIe lane compatibility for NVMe
        $storageInterface = $this->extractStorageInterface($storage);
        if ($storageInterface['type'] === 'NVMe' || $storageInterface['type'] === 'PCIe') {
            $pcieLaneResult = $this->checkPCIeLaneCompatibility($storage, $motherboard);
            if (!$pcieLaneResult['compatible']) {
                $issues[] = $pcieLaneResult['message'];
                $score = 0.0;
            } else if (!empty($pcieLaneResult['warnings'])) {
                $warnings = array_merge($warnings, $pcieLaneResult['warnings']);
                $score *= 0.9;
            }
        }
        
        return [
            'compatible' => empty($issues),
            'compatibility_score' => $score,
            'issues' => $issues,
            'warnings' => $warnings,
            'recommendations' => $recommendations
        ];
    }
    
    /**
     * Validate three-way compatibility (storage-chassis-motherboard)
     */
    public function validateTriangleCompatibility($storageUUID, $chassisUUID, $motherboardUUID) {
        // Get all specifications
        $storageSpecs = $this->loadStorageSpecsByUUID($storageUUID);
        $chassisSpecs = $this->chassisManager->loadChassisSpecsByUUID($chassisUUID);
        $motherboardSpecs = $this->loadMotherboardSpecsByUUID($motherboardUUID);
        
        if (!$storageSpecs['found'] || !$chassisSpecs['found'] || !$motherboardSpecs['found']) {
            return [
                'compatible' => false,
                'compatibility_score' => 0.0,
                'issues' => ['Unable to load component specifications for triangle validation'],
                'warnings' => [],
                'recommendations' => []
            ];
        }
        
        $storage = $storageSpecs['specifications'];
        $chassis = $chassisSpecs['specifications'];
        $motherboard = $motherboardSpecs['specifications'];
        
        $issues = [];
        $warnings = [];
        $recommendations = [];
        $score = 1.0;
        
        // 1. Chassis backplane to motherboard connectivity
        $backplaneResult = $this->checkBackplaneMotherboardConnectivity($chassis, $motherboard);
        if (!$backplaneResult['compatible']) {
            $issues[] = $backplaneResult['message'];
            $score = 0.0;
        } else if (!empty($backplaneResult['warnings'])) {
            $warnings = array_merge($warnings, $backplaneResult['warnings']);
            $score *= 0.95;
        }
        
        // 2. PCIe lane allocation for NVMe through chassis
        $storageInterface = $this->extractStorageInterface($storage);
        if ($storageInterface['type'] === 'NVMe' && isset($chassis['backplane']['supports_nvme']) && $chassis['backplane']['supports_nvme']) {
            $nvmeLaneResult = $this->checkNVMeLaneAllocation($storage, $chassis, $motherboard);
            if (!$nvmeLaneResult['compatible']) {
                $issues[] = $nvmeLaneResult['message'];
                $score = 0.0;
            } else if (!empty($nvmeLaneResult['warnings'])) {
                $warnings = array_merge($warnings, $nvmeLaneResult['warnings']);
                $score *= 0.9;
            }
        }
        
        // 3. Overall system coherence
        $coherenceResult = $this->checkSystemCoherence($storage, $chassis, $motherboard);
        if (!empty($coherenceResult['warnings'])) {
            $warnings = array_merge($warnings, $coherenceResult['warnings']);
            $score *= 0.98;
        }
        
        if (!empty($coherenceResult['recommendations'])) {
            $recommendations = array_merge($recommendations, $coherenceResult['recommendations']);
        }
        
        return [
            'compatible' => empty($issues),
            'compatibility_score' => $score,
            'issues' => $issues,
            'warnings' => $warnings,
            'recommendations' => $recommendations
        ];
    }
    
    /**
     * Form factor compatibility check
     */
    private function checkFormFactorCompatibility($storage, $chassis) {
        $storageFormFactor = $this->extractStorageFormFactor($storage);
        $chassisBayTypes = $this->chassisManager->extractChassisBayTypes($chassis['uuid'] ?? '');
        
        if (empty($chassisBayTypes)) {
            return [
                'compatible' => false,
                'message' => 'Unable to determine chassis bay types',
                'warnings' => []
            ];
        }
        
        // Map storage form factor to bay types
        $compatibleBayTypes = $this->mapFormFactorToBayTypes($storageFormFactor);
        
        // Check if any chassis bay type is compatible
        $hasCompatibleBay = !empty(array_intersect($compatibleBayTypes, $chassisBayTypes));
        
        if (!$hasCompatibleBay) {
            return [
                'compatible' => false,
                'message' => "Storage form factor '{$storageFormFactor}' not compatible with chassis bay types: " . implode(', ', $chassisBayTypes),
                'warnings' => []
            ];
        }
        
        $warnings = [];
        // Check for optimal fit warnings
        if ($storageFormFactor === '2.5_inch' && in_array('3.5_inch', $chassisBayTypes) && !in_array('2.5_inch', $chassisBayTypes)) {
            $warnings[] = "2.5\" drive in 3.5\" bay may require adapter";
        }
        
        return [
            'compatible' => true,
            'message' => "Storage form factor compatible with chassis",
            'warnings' => $warnings
        ];
    }
    
    /**
     * Interface support compatibility check
     */
    private function checkInterfaceSupport($storage, $chassis) {
        $storageInterface = $this->extractStorageInterface($storage);
        $backplaneSupport = $this->chassisManager->extractBackplaneSupport($chassis['uuid'] ?? '');
        
        $interfaceType = $storageInterface['type'];
        $compatible = false;
        $message = '';
        $warnings = [];
        
        switch ($interfaceType) {
            case 'SATA':
                $compatible = $backplaneSupport['supports_sata'];
                $message = $compatible ? 'SATA interface supported' : 'SATA interface not supported by chassis backplane';
                break;
                
            case 'SAS':
                $compatible = $backplaneSupport['supports_sas'];
                $message = $compatible ? 'SAS interface supported' : 'SAS interface not supported by chassis backplane';
                // Note: SAS controllers usually support SATA drives too
                if (!$compatible && $backplaneSupport['supports_sata']) {
                    $warnings[] = 'SAS not supported but SATA is available - consider SATA alternative';
                }
                break;
                
            case 'NVMe':
            case 'PCIe':
                $compatible = $backplaneSupport['supports_nvme'];
                $message = $compatible ? 'NVMe interface supported' : 'NVMe interface not supported by chassis backplane';
                if ($compatible && isset($backplaneSupport['nvme_lanes_per_bay'])) {
                    $requiredLanes = $storageInterface['pcie_lanes'] ?? 4;
                    $availableLanes = $backplaneSupport['nvme_lanes_per_bay'];
                    if ($availableLanes < $requiredLanes) {
                        $warnings[] = "Storage requires {$requiredLanes} PCIe lanes but chassis bay provides only {$availableLanes}";
                    }
                }
                break;
                
            default:
                $compatible = false;
                $message = "Unknown storage interface type: {$interfaceType}";
        }
        
        return [
            'compatible' => $compatible,
            'message' => $message,
            'warnings' => $warnings
        ];
    }
    
    /**
     * Bay availability check
     */
    private function checkBayAvailability($chassisUUID, $targetBay, $configUUID = null) {
        $availableBays = $this->chassisManager->getAvailableBays($chassisUUID, $configUUID, $this->pdo);
        
        if (!$availableBays['success']) {
            return [
                'available' => false,
                'message' => $availableBays['error']
            ];
        }
        
        // Check if target bay is in available list
        $bayIds = array_column($availableBays['available_bays'], 'bay_id');
        $isAvailable = in_array($targetBay, $bayIds);
        
        return [
            'available' => $isAvailable,
            'message' => $isAvailable ? "Bay {$targetBay} is available" : "Bay {$targetBay} is not available or already assigned"
        ];
    }
    
    /**
     * Connector compatibility check
     */
    private function checkConnectorCompatibility($storage, $motherboard) {
        $storageInterface = $this->extractStorageInterface($storage);
        $motherboardConnectors = $this->extractMotherboardStorageConnectors($motherboard);
        
        $requiredConnector = $this->mapInterfaceToConnector($storageInterface['type']);
        $hasConnector = in_array($requiredConnector, $motherboardConnectors);
        
        return [
            'compatible' => $hasConnector,
            'message' => $hasConnector ? 
                "Required connector '{$requiredConnector}' available on motherboard" :
                "Required connector '{$requiredConnector}' not available on motherboard"
        ];
    }
    
    /**
     * PCIe lane compatibility check
     */
    private function checkPCIeLaneCompatibility($storage, $motherboard) {
        $requiredLanes = $this->extractPCIeLanes($storage);
        $availableLanes = $this->extractMotherboardPCIeLanes($motherboard);
        
        $warnings = [];
        if ($availableLanes['total'] < $requiredLanes) {
            return [
                'compatible' => false,
                'message' => "Insufficient PCIe lanes: need {$requiredLanes}, available {$availableLanes['total']}",
                'warnings' => []
            ];
        }
        
        if ($availableLanes['available'] < $requiredLanes) {
            $warnings[] = "PCIe lanes sufficient but may be shared with other components";
        }
        
        return [
            'compatible' => true,
            'message' => "Sufficient PCIe lanes available",
            'warnings' => $warnings
        ];
    }
    
    /**
     * Check backplane to motherboard connectivity
     */
    private function checkBackplaneMotherboardConnectivity($chassis, $motherboard) {
        $requiredConnectors = $this->chassisManager->extractRequiredConnectors($chassis['uuid'] ?? '');
        $motherboardConnectors = $this->extractMotherboardStorageConnectors($motherboard);
        
        $missing = array_diff($requiredConnectors, $motherboardConnectors);
        
        if (!empty($missing)) {
            return [
                'compatible' => false,
                'message' => "Missing motherboard connectors: " . implode(', ', $missing),
                'warnings' => []
            ];
        }
        
        return [
            'compatible' => true,
            'message' => "Chassis backplane connectors compatible with motherboard",
            'warnings' => []
        ];
    }
    
    /**
     * Check NVMe lane allocation through chassis
     */
    private function checkNVMeLaneAllocation($storage, $chassis, $motherboard) {
        $requiredLanes = $this->extractPCIeLanes($storage);
        $chassisLanesPerBay = $chassis['backplane']['nvme_lanes_per_bay'] ?? 4;
        $motherboardLanes = $this->extractMotherboardPCIeLanes($motherboard);
        
        $warnings = [];
        
        if ($chassisLanesPerBay < $requiredLanes) {
            return [
                'compatible' => false,
                'message' => "Chassis provides {$chassisLanesPerBay} lanes per bay but storage needs {$requiredLanes}",
                'warnings' => []
            ];
        }
        
        if ($motherboardLanes['available'] < $requiredLanes) {
            $warnings[] = "Motherboard PCIe lanes may be shared - monitor for performance impact";
        }
        
        return [
            'compatible' => true,
            'message' => "NVMe lane allocation compatible",
            'warnings' => $warnings
        ];
    }
    
    /**
     * Check overall system coherence
     */
    private function checkSystemCoherence($storage, $chassis, $motherboard) {
        $warnings = [];
        $recommendations = [];
        
        // Check for performance mismatches
        $storageInterface = $this->extractStorageInterface($storage);
        $chassisInterface = $chassis['backplane']['interface'] ?? 'unknown';
        
        if ($storageInterface['type'] === 'NVMe' && strpos($chassisInterface, 'SATA') !== false) {
            $warnings[] = "High-performance NVMe storage in SATA-oriented chassis may not reach full potential";
            $recommendations[] = "Consider NVMe-optimized chassis for maximum performance";
        }
        
        // Check for overkill scenarios
        if ($storageInterface['type'] === 'SATA' && strpos($chassisInterface, 'NVMe') !== false) {
            $recommendations[] = "Chassis supports NVMe - consider upgrading storage for better performance";
        }
        
        return [
            'warnings' => $warnings,
            'recommendations' => $recommendations
        ];
    }
    
    /**
     * Get motherboard UUID from server configuration
     */
    private function getMotherboardFromConfiguration($configUUID) {
        try {
            $stmt = $this->pdo->prepare("SELECT component_uuid FROM server_configuration_components WHERE config_uuid = ? AND component_type = 'motherboard'");
            $stmt->execute([$configUUID]);
            return $stmt->fetchColumn();
        } catch (Exception $e) {
            error_log("Error getting motherboard from config: " . $e->getMessage());
            return null;
        }
    }
    
    // Enhanced helper methods using DataExtractionUtilities
    private function loadStorageSpecsByUUID($uuid) {
        return $this->dataExtractor->getComponentSpecifications('storage', $uuid);
    }
    
    private function loadMotherboardSpecsByUUID($uuid) {
        return $this->dataExtractor->getComponentSpecifications('motherboard', $uuid);
    }
    
    private function extractStorageFormFactor($storage) {
        // If $storage is an array, use it directly; if it's a UUID, fetch from extractor
        if (is_string($storage)) {
            return $this->dataExtractor->extractStorageFormFactor($storage);
        }
        return $storage['form_factor'] ?? 'unknown';
    }
    
    private function extractStorageInterface($storage) {
        // If $storage is an array with UUID, use it; if it's a UUID, fetch from extractor
        if (is_string($storage)) {
            return $this->dataExtractor->extractStorageInterface($storage);
        }
        
        // Fallback to direct array access for backwards compatibility
        return [
            'type' => $storage['interface'] ?? 'unknown',
            'version' => $storage['interface_version'] ?? null,
            'connector_type' => $storage['connector_type'] ?? null,
            'pcie_lanes' => $storage['pcie_lanes'] ?? 4
        ];
    }
    
    private function extractPCIeLanes($storage) {
        // If $storage is an array with UUID, use it; if it's a UUID, fetch from extractor
        if (is_string($storage)) {
            return $this->dataExtractor->extractStoragePCIeLanes($storage);
        }
        return $storage['pcie_lanes'] ?? 4;
    }
    
    private function extractMotherboardStorageConnectors($motherboard) {
        // If $motherboard is an array with UUID, use it; if it's a UUID, fetch from extractor
        if (is_string($motherboard)) {
            return $this->dataExtractor->extractMotherboardStorageConnectors($motherboard);
        }
        
        // Fallback to direct array access
        return $motherboard['storage']['connectors'] ?? [];
    }
    
    private function extractMotherboardPCIeLanes($motherboard) {
        // If $motherboard is an array with UUID, use it; if it's a UUID, fetch from extractor
        if (is_string($motherboard)) {
            return $this->dataExtractor->extractMotherboardPCIeLanes($motherboard);
        }
        
        return [
            'total' => $motherboard['pcie']['total_lanes'] ?? 20,
            'available' => $motherboard['pcie']['available_lanes'] ?? 16
        ];
    }
    
    private function mapFormFactorToBayTypes($formFactor) {
        $mapping = [
            '2.5_inch' => ['2.5_inch', 'universal'],
            '3.5_inch' => ['3.5_inch', 'universal'],
            'M.2_2280' => ['M.2_slot', 'M.2_2280'],
            'M.2_2260' => ['M.2_slot', 'M.2_2260'],
            'M.2_2242' => ['M.2_slot', 'M.2_2242'],
        ];
        
        return $mapping[$formFactor] ?? [];
    }
    
    private function mapInterfaceToConnector($interfaceType) {
        $mapping = [
            'SATA' => 'SATA',
            'SAS' => 'SAS3_8643',
            'NVMe' => 'M.2_NVMe',
            'PCIe' => 'PCIe_x4'
        ];
        
        return $mapping[$interfaceType] ?? 'unknown';
    }
}
?>