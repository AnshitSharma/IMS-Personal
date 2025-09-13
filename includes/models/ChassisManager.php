<?php
/**
 * Infrastructure Management System - Chassis Manager
 * File: includes/models/ChassisManager.php
 * 
 * Manages chassis JSON data loading, caching, and validation
 */

class ChassisManager {
    private $chassisJsonPath;
    private $jsonCache = [];
    private $cacheTimestamp = null;
    private $cacheTimeout = 3600; // 1 hour cache
    
    public function __construct() {
        $this->chassisJsonPath = __DIR__ . '/../../All-JSON/chasis-jsons/chasis-level-3.json';
    }
    
    /**
     * Load chassis specifications from JSON with caching
     */
    private function loadChassisSpecifications() {
        $currentTime = time();
        
        // Check if cache is valid
        if (!empty($this->jsonCache) && 
            $this->cacheTimestamp && 
            ($currentTime - $this->cacheTimestamp) < $this->cacheTimeout) {
            return $this->jsonCache;
        }
        
        // Load JSON file
        if (!file_exists($this->chassisJsonPath)) {
            throw new Exception("Chassis JSON file not found: " . $this->chassisJsonPath);
        }
        
        $jsonContent = file_get_contents($this->chassisJsonPath);
        if ($jsonContent === false) {
            throw new Exception("Failed to read chassis JSON file");
        }
        
        $data = json_decode($jsonContent, true);
        if ($data === null) {
            throw new Exception("Invalid JSON in chassis specifications file: " . json_last_error_msg());
        }
        
        // Cache the data
        $this->jsonCache = $data;
        $this->cacheTimestamp = $currentTime;
        
        return $this->jsonCache;
    }
    
    /**
     * Load chassis specifications by UUID
     */
    public function loadChassisSpecsByUUID($uuid) {
        try {
            $data = $this->loadChassisSpecifications();
            
            if (!isset($data['chassis_specifications']['manufacturers'])) {
                return [
                    'found' => false,
                    'error' => 'Invalid chassis JSON structure: manufacturers not found'
                ];
            }
            
            // Search through manufacturers and series
            foreach ($data['chassis_specifications']['manufacturers'] as $manufacturer) {
                if (!isset($manufacturer['series'])) continue;
                
                foreach ($manufacturer['series'] as $series) {
                    if (!isset($series['models'])) continue;
                    
                    foreach ($series['models'] as $model) {
                        if (isset($model['uuid']) && $model['uuid'] === $uuid) {
                            return [
                                'found' => true,
                                'specifications' => $model,
                                'manufacturer' => $manufacturer['manufacturer'],
                                'series_name' => $series['series_name']
                            ];
                        }
                    }
                }
            }
            
            return [
                'found' => false,
                'error' => "Chassis UUID not found: $uuid"
            ];
            
        } catch (Exception $e) {
            return [
                'found' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get available bays for a chassis configuration
     */
    public function getAvailableBays($chassisUUID, $configUUID = null, $pdo = null) {
        $chassisSpecs = $this->loadChassisSpecsByUUID($chassisUUID);
        if (!$chassisSpecs['found']) {
            return [
                'success' => false,
                'error' => $chassisSpecs['error'],
                'available_bays' => []
            ];
        }
        
        $specs = $chassisSpecs['specifications'];
        $totalBays = [];
        
        // Parse bay configuration from chassis specs
        if (isset($specs['drive_bays']['bay_configuration'])) {
            foreach ($specs['drive_bays']['bay_configuration'] as $bayConfig) {
                $bayType = $bayConfig['bay_type'];
                $count = $bayConfig['count'];
                
                for ($i = 1; $i <= $count; $i++) {
                    $totalBays[] = [
                        'bay_id' => $bayType . '_bay_' . $i,
                        'bay_type' => $bayType,
                        'position' => $bayConfig['position'] ?? 'internal',
                        'hot_swap' => $bayConfig['hot_swap'] ?? false,
                        'tool_less' => $bayConfig['tool_less'] ?? false
                    ];
                }
            }
        }
        
        // If PDO is provided and configUUID is specified, check assigned bays
        $assignedBays = [];
        if ($pdo && $configUUID) {
            try {
                $stmt = $pdo->prepare("SELECT bay_assignment FROM storage_chassis_mapping WHERE config_uuid = ? AND chassis_uuid = ?");
                $stmt->execute([$configUUID, $chassisUUID]);
                $assignedBays = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                // Log error but continue
                error_log("Error fetching assigned bays: " . $e->getMessage());
            }
        }
        
        // Filter out assigned bays
        $availableBays = array_filter($totalBays, function($bay) use ($assignedBays) {
            return !in_array($bay['bay_id'], $assignedBays);
        });
        
        return [
            'success' => true,
            'total_bays' => count($totalBays),
            'assigned_bays' => count($assignedBays),
            'available_bays' => array_values($availableBays)
        ];
    }
    
    /**
     * Get assigned bays for a configuration
     */
    public function getAssignedBays($configUUID, $chassisUUID = null, $pdo = null) {
        if (!$pdo) {
            return [
                'success' => false,
                'error' => 'Database connection required',
                'assigned_bays' => []
            ];
        }
        
        try {
            $sql = "SELECT storage_uuid, chassis_uuid, bay_assignment, bay_type, connection_type, pcie_lanes 
                    FROM storage_chassis_mapping WHERE config_uuid = ?";
            $params = [$configUUID];
            
            if ($chassisUUID) {
                $sql .= " AND chassis_uuid = ?";
                $params[] = $chassisUUID;
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $assignedBays = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'assigned_bays' => $assignedBays
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'assigned_bays' => []
            ];
        }
    }
    
    /**
     * Validate chassis exists by UUID
     */
    public function validateChassisExists($uuid) {
        $result = $this->loadChassisSpecsByUUID($uuid);
        return [
            'exists' => $result['found'],
            'error' => $result['found'] ? null : $result['error']
        ];
    }
    
    /**
     * Get bay configuration details
     */
    public function getBayConfiguration($chassisUUID) {
        $chassisSpecs = $this->loadChassisSpecsByUUID($chassisUUID);
        if (!$chassisSpecs['found']) {
            return [
                'success' => false,
                'error' => $chassisSpecs['error'],
                'bay_configuration' => []
            ];
        }
        
        $specs = $chassisSpecs['specifications'];
        $bayConfiguration = $specs['drive_bays']['bay_configuration'] ?? [];
        
        return [
            'success' => true,
            'total_bays' => $specs['drive_bays']['total_bays'] ?? 0,
            'bay_configuration' => $bayConfiguration,
            'backplane' => $specs['backplane'] ?? [],
            'motherboard_compatibility' => $specs['motherboard_compatibility'] ?? []
        ];
    }
    
    /**
     * Extract form factor compatibility from chassis
     */
    public function extractChassisBayTypes($chassisUUID) {
        $chassisSpecs = $this->loadChassisSpecsByUUID($chassisUUID);
        if (!$chassisSpecs['found']) {
            return [];
        }
        
        $bayTypes = [];
        $specs = $chassisSpecs['specifications'];
        
        if (isset($specs['drive_bays']['bay_configuration'])) {
            foreach ($specs['drive_bays']['bay_configuration'] as $config) {
                $bayTypes[] = $config['bay_type'];
            }
        }
        
        return array_unique($bayTypes);
    }
    
    /**
     * Extract backplane support information
     */
    public function extractBackplaneSupport($chassisUUID) {
        $chassisSpecs = $this->loadChassisSpecsByUUID($chassisUUID);
        if (!$chassisSpecs['found']) {
            return [
                'supports_sata' => false,
                'supports_sas' => false,
                'supports_nvme' => false,
                'interface' => 'unknown'
            ];
        }
        
        $backplane = $chassisSpecs['specifications']['backplane'] ?? [];
        
        return [
            'supports_sata' => $backplane['supports_sata'] ?? false,
            'supports_sas' => $backplane['supports_sas'] ?? false,
            'supports_nvme' => $backplane['supports_nvme'] ?? false,
            'interface' => $backplane['interface'] ?? 'unknown',
            'connector_type' => $backplane['connector_type'] ?? 'unknown',
            'nvme_lanes_per_bay' => $backplane['nvme_lanes_per_bay'] ?? null
        ];
    }
    
    /**
     * Extract required motherboard connectors
     */
    public function extractRequiredConnectors($chassisUUID) {
        $chassisSpecs = $this->loadChassisSpecsByUUID($chassisUUID);
        if (!$chassisSpecs['found']) {
            return [];
        }
        
        $connectivity = $chassisSpecs['specifications']['connectivity'] ?? [];
        return $connectivity['required_motherboard_connectors'] ?? [];
    }
    
    /**
     * Extract motherboard compatibility requirements
     */
    public function extractMotherboardCompatibility($chassisUUID) {
        $chassisSpecs = $this->loadChassisSpecsByUUID($chassisUUID);
        if (!$chassisSpecs['found']) {
            return [
                'form_factors' => [],
                'mounting_points' => null,
                'motherboard_models' => []
            ];
        }
        
        $compatibility = $chassisSpecs['specifications']['motherboard_compatibility'] ?? [];
        
        return [
            'form_factors' => $compatibility['form_factors'] ?? [],
            'mounting_points' => $compatibility['mounting_points'] ?? null,
            'motherboard_models' => $compatibility['motherboard_models'] ?? [],
            'max_motherboard_size' => $compatibility['max_motherboard_size'] ?? null
        ];
    }
    
    /**
     * Validate JSON structure integrity
     */
    public function validateJsonStructure() {
        try {
            $data = $this->loadChassisSpecifications();
            $errors = [];
            $warnings = [];
            
            // Check root structure
            if (!isset($data['chassis_specifications'])) {
                $errors[] = "Missing root 'chassis_specifications' object";
                return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
            }
            
            $chassisSpecs = $data['chassis_specifications'];
            
            // Check manufacturers array
            if (!isset($chassisSpecs['manufacturers']) || !is_array($chassisSpecs['manufacturers'])) {
                $errors[] = "Missing or invalid 'manufacturers' array";
                return ['valid' => false, 'errors' => $errors, 'warnings' => $warnings];
            }
            
            // Validate each manufacturer
            foreach ($chassisSpecs['manufacturers'] as $index => $manufacturer) {
                $manPrefix = "Manufacturer [$index]";
                
                if (!isset($manufacturer['manufacturer'])) {
                    $errors[] = "$manPrefix: Missing manufacturer name";
                }
                
                if (!isset($manufacturer['series']) || !is_array($manufacturer['series'])) {
                    $errors[] = "$manPrefix: Missing or invalid series array";
                    continue;
                }
                
                // Validate each series
                foreach ($manufacturer['series'] as $sIndex => $series) {
                    $serPrefix = "$manPrefix Series [$sIndex]";
                    
                    if (!isset($series['series_name'])) {
                        $warnings[] = "$serPrefix: Missing series_name";
                    }
                    
                    if (!isset($series['models']) || !is_array($series['models'])) {
                        $errors[] = "$serPrefix: Missing or invalid models array";
                        continue;
                    }
                    
                    // Validate each model
                    foreach ($series['models'] as $mIndex => $model) {
                        $modPrefix = "$serPrefix Model [$mIndex]";
                        
                        // Required fields
                        $requiredFields = ['uuid', 'model', 'brand', 'form_factor', 'chassis_type'];
                        foreach ($requiredFields as $field) {
                            if (!isset($model[$field])) {
                                $errors[] = "$modPrefix: Missing required field '$field'";
                            }
                        }
                        
                        // Validate drive_bays structure
                        if (!isset($model['drive_bays'])) {
                            $errors[] = "$modPrefix: Missing drive_bays configuration";
                        } else {
                            $driveBays = $model['drive_bays'];
                            if (!isset($driveBays['total_bays']) || !isset($driveBays['bay_configuration'])) {
                                $errors[] = "$modPrefix: Invalid drive_bays structure";
                            }
                        }
                        
                        // Validate backplane structure
                        if (!isset($model['backplane'])) {
                            $errors[] = "$modPrefix: Missing backplane configuration";
                        } else {
                            $backplane = $model['backplane'];
                            $requiredBackplaneFields = ['interface', 'supports_sata', 'supports_sas', 'supports_nvme'];
                            foreach ($requiredBackplaneFields as $field) {
                                if (!isset($backplane[$field])) {
                                    $warnings[] = "$modPrefix: Missing backplane field '$field'";
                                }
                            }
                        }
                        
                        // Check for duplicate UUIDs
                        if (isset($model['uuid'])) {
                            static $uuidsSeen = [];
                            if (in_array($model['uuid'], $uuidsSeen)) {
                                $errors[] = "$modPrefix: Duplicate UUID: " . $model['uuid'];
                            } else {
                                $uuidsSeen[] = $model['uuid'];
                            }
                        }
                    }
                }
            }
            
            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'total_models' => $this->countTotalModels($data)
            ];
            
        } catch (Exception $e) {
            return [
                'valid' => false,
                'errors' => [$e->getMessage()],
                'warnings' => []
            ];
        }
    }
    
    /**
     * Count total models in JSON
     */
    private function countTotalModels($data) {
        $count = 0;
        if (isset($data['chassis_specifications']['manufacturers'])) {
            foreach ($data['chassis_specifications']['manufacturers'] as $manufacturer) {
                if (isset($manufacturer['series'])) {
                    foreach ($manufacturer['series'] as $series) {
                        if (isset($series['models'])) {
                            $count += count($series['models']);
                        }
                    }
                }
            }
        }
        return $count;
    }
    
    /**
     * Get all chassis UUIDs for validation
     */
    public function getAllChassisUUIDs() {
        try {
            $data = $this->loadChassisSpecifications();
            $uuids = [];
            
            if (isset($data['chassis_specifications']['manufacturers'])) {
                foreach ($data['chassis_specifications']['manufacturers'] as $manufacturer) {
                    if (isset($manufacturer['series'])) {
                        foreach ($manufacturer['series'] as $series) {
                            if (isset($series['models'])) {
                                foreach ($series['models'] as $model) {
                                    if (isset($model['uuid'])) {
                                        $uuids[] = $model['uuid'];
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            return $uuids;
        } catch (Exception $e) {
            error_log("Error getting chassis UUIDs: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clear JSON cache (for testing or forced refresh)
     */
    public function clearCache() {
        $this->jsonCache = [];
        $this->cacheTimestamp = null;
    }
}
?>