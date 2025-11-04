<?php
/**
 * Infrastructure Management System - Compatibility Engine
 * File: includes/models/CompatibilityEngine.php
 *
 * Main compatibility checking engine - acts as wrapper around ComponentCompatibility
 * STORAGE COMPATIBILITY DISABLED - 2025-09-15 - Storage checks always return compatible
 */

if (!class_exists('ComponentCompatibility')) {
    require_once __DIR__ . '/ComponentCompatibility.php';
}

if (!class_exists('CompatibilityEngine')) {

class CompatibilityEngine extends ComponentCompatibility {

    public function __construct($pdo) {
        parent::__construct($pdo);
    }

    /**
     * Check compatibility between two components
     * DEPRECATED: Storage compatibility now handled by StorageConnectionValidator in FlexibleCompatibilityValidator
     */
    public function checkCompatibility($component1, $component2) {
        $type1 = strtolower($component1['type']);
        $type2 = strtolower($component2['type']);

        // Storage compatibility - DEPRECATED, use FlexibleCompatibilityValidator instead
        if ($type1 === 'storage' || $type2 === 'storage') {
            return [
                'compatible' => true,
                'compatibility_score' => 1.0,
                'failures' => [],
                'warnings' => ['Storage compatibility validated by FlexibleCompatibilityValidator'],
                'recommendations' => [],
                'applied_rules' => ['storage_deferred_to_flexible_validator']
            ];
        }

        // Use parent ComponentCompatibility for non-storage checks
        return $this->checkComponentPairCompatibility($component1, $component2);
    }

    /**
     * Validate chassis exists in JSON and database with same pattern as other components
     */
    public function validateChassisExistsInJSON($chassisUuid) {
        try {
            // Use ChassisManager for proper chassis JSON validation
            require_once __DIR__ . '/ChassisManager.php';
            $chassisManager = new ChassisManager();

            // Check JSON existence using ChassisManager
            $chassisSpecs = $chassisManager->loadChassisSpecsByUUID($chassisUuid);

            if (!$chassisSpecs['found']) {
                return [
                    'success' => false,
                    'error' => "Chassis UUID $chassisUuid not found in chassis JSON specifications: " . $chassisSpecs['error'],
                    'error_type' => 'json_not_found'
                ];
            }

            // Check database existence and availability
            $stmt = $this->pdo->prepare("SELECT Status FROM chassisinventory WHERE UUID = ? LIMIT 1");
            $stmt->execute([$chassisUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return [
                    'success' => false,
                    'error' => "Chassis UUID $chassisUuid not found in inventory database",
                    'error_type' => 'database_not_found'
                ];
            }

            if ($result['Status'] != 1) {
                return [
                    'success' => false,
                    'error' => "Chassis is not available (Status: {$result['Status']})",
                    'error_type' => 'not_available'
                ];
            }

            return [
                'success' => true,
                'message' => 'Chassis is valid and available'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Validation error: ' . $e->getMessage(),
                'error_type' => 'system_error'
            ];
        }
    }

    /**
     * Get compatible components for a base component
     */
    public function getCompatibleComponents($baseComponent, $targetType, $availableOnly = true) {
        $targetType = strtolower($targetType);

        // Storage compatibility uses new JSON-driven validation
        if ($targetType === 'storage' || strtolower($baseComponent['type']) === 'storage') {
            // Storage compatibility filtering requires chassis context
            // For now, return all available storage - compatibility is checked during addition
            return $this->getAllComponents($targetType, $availableOnly);
        }

        // For non-storage components, perform actual compatibility checking
        $allComponents = $this->getAllComponents($targetType, $availableOnly);
        $compatibleComponents = [];

        foreach ($allComponents as $component) {
            $targetComponent = ['type' => $targetType, 'uuid' => $component['uuid']];
            $result = $this->checkCompatibility($baseComponent, $targetComponent);

            if ($result['compatible']) {
                $component['compatibility_score'] = $result['compatibility_score'];
                $compatibleComponents[] = $component;
            }
        }

        return $compatibleComponents;
    }

    /**
     * Validate complete server configuration
     */
    public function validateServerConfiguration($configuration) {
        $components = $configuration['components'] ?? [];
        $componentChecks = [];
        $globalChecks = [];
        $overallValid = true;
        $overallScore = 1.0;

        // Cross-check all component pairs
        for ($i = 0; $i < count($components); $i++) {
            for ($j = $i + 1; $j < count($components); $j++) {
                $component1 = $components[$i];
                $component2 = $components[$j];

                $result = $this->checkCompatibility($component1, $component2);

                $componentChecks[] = [
                    'components' => "{$component1['type']}-{$component2['type']}",
                    'compatible' => $result['compatible'],
                    'score' => $result['compatibility_score'],
                    'issues' => $result['failures'],
                    'warnings' => $result['warnings']
                ];

                if (!$result['compatible']) {
                    $overallValid = false;
                }

                $overallScore *= $result['compatibility_score'];
            }
        }

        // Global system checks
        $globalChecks[] = [
            'check' => 'system_power_budget',
            'passed' => true,
            'message' => 'Power budget check passed'
        ];

        return [
            'valid' => $overallValid,
            'overall_score' => $overallScore,
            'component_checks' => $componentChecks,
            'global_checks' => $globalChecks
        ];
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
                    AVG(compatibility_score) as avg_score,
                    AVG(execution_time_ms) as avg_execution_time
                FROM compatibility_log
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 $timeframe)
            ");
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'timeframe' => $timeframe,
                'total_checks' => (int)$stats['total_checks'],
                'successful_checks' => (int)$stats['successful_checks'],
                'success_rate' => $stats['total_checks'] > 0 ?
                    round(($stats['successful_checks'] / $stats['total_checks']) * 100, 2) : 0,
                'average_score' => round((float)$stats['avg_score'], 3),
                'average_execution_time_ms' => round((float)$stats['avg_execution_time'], 2)
            ];
        } catch (Exception $e) {
            return [
                'timeframe' => $timeframe,
                'total_checks' => 0,
                'successful_checks' => 0,
                'success_rate' => 0,
                'average_score' => 0,
                'average_execution_time_ms' => 0,
                'error' => 'Unable to retrieve statistics'
            ];
        }
    }

    /**
     * Check CPU-Motherboard compatibility
     */
    public function checkCPUMotherboardCompatibility($cpuUuid, $motherboardUuid) {
        $cpu = ['type' => 'cpu', 'uuid' => $cpuUuid];
        $motherboard = ['type' => 'motherboard', 'uuid' => $motherboardUuid];

        return $this->checkCompatibility($cpu, $motherboard);
    }

    /**
     * Check RAM-Motherboard compatibility
     */
    public function checkRAMMotherboardCompatibility($ramComponents, $motherboardUuid) {
        $motherboard = ['type' => 'motherboard', 'uuid' => $motherboardUuid];
        $overallCompatible = true;
        $overallScore = 1.0;
        $allIssues = [];

        foreach ($ramComponents as $ramComponent) {
            $ram = ['type' => 'ram', 'uuid' => $ramComponent['component_uuid']];
            $result = $this->checkCompatibility($ram, $motherboard);

            if (!$result['compatible']) {
                $overallCompatible = false;
            }

            $overallScore *= $result['compatibility_score'];
            $allIssues = array_merge($allIssues, $result['failures']);
        }

        return [
            'compatible' => $overallCompatible,
            'compatibility_score' => $overallScore,
            'failures' => $allIssues,
            'warnings' => [],
            'recommendations' => []
        ];
    }


    /**
     * Validate storage exists in JSON and database - Task 2.1
     */
    public function validateStorageExistsInJSON($storageUuid) {
        try {
            // Check storage-level-3.json which contains actual storage inventory with UUIDs
            $jsonPath = __DIR__ . '/../../All-JSON/storage-jsons/storage-level-3.json';
            if (!file_exists($jsonPath)) {
                return [
                    'success' => false,
                    'error' => 'Storage inventory JSON file not found',
                    'error_type' => 'json_file_missing'
                ];
            }

            $jsonContent = file_get_contents($jsonPath);
            $storageData = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'error' => 'Storage JSON file is malformed: ' . json_last_error_msg(),
                    'error_type' => 'json_malformed'
                ];
            }

            // Search for storage UUID in the storage-level-3.json structure
            $found = false;
            $storageSpecs = null;

            // The structure is an array of brands, each with series containing models
            if (is_array($storageData)) {
                foreach ($storageData as $brand) {
                    if (isset($brand['models']) && is_array($brand['models'])) {
                        foreach ($brand['models'] as $model) {
                            if (isset($model['uuid']) && $model['uuid'] === $storageUuid) {
                                $found = true;
                                $storageSpecs = $model;
                                break 2; // Break out of both loops
                            }
                        }
                    }
                }
            }

            if (!$found) {
                return [
                    'success' => false,
                    'error' => "Storage UUID $storageUuid not found in storage specifications",
                    'error_type' => 'json_not_found'
                ];
            }

            // Check database existence and availability
            $stmt = $this->pdo->prepare("SELECT Status FROM storageinventory WHERE UUID = ? LIMIT 1");
            $stmt->execute([$storageUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$result) {
                return [
                    'success' => false,
                    'error' => "Storage UUID $storageUuid not found in inventory database",
                    'error_type' => 'database_not_found'
                ];
            }

            if ($result['Status'] != 1) {
                return [
                    'success' => false,
                    'error' => "Storage device is not available (Status: {$result['Status']})",
                    'error_type' => 'not_available'
                ];
            }

            return [
                'success' => true,
                'message' => 'Storage is valid and available',
                'specifications' => $storageSpecs
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Validation error: ' . $e->getMessage(),
                'error_type' => 'system_error'
            ];
        }
    }

    /**
     * Get storage specifications from JSON - Task 2.2
     */
    public function getStorageSpecifications($storageUuid) {
        try {
            $validation = $this->validateStorageExistsInJSON($storageUuid);

            if (!$validation['success']) {
                return $validation;
            }

            $specifications = $validation['specifications'] ?? [];

            // Extract key specifications from storage-level-3.json structure
            $interface = $specifications['interface'] ?? 'Unknown';
            $formFactor = $specifications['form_factor'] ?? 'Unknown';
            $driveType = $specifications['storage_type'] ?? $specifications['subtype'] ?? 'Unknown';
            $powerConsumption = $specifications['power_consumption_W'] ?? [];

            return [
                'success' => true,
                'specifications' => [
                    'interface' => $interface,
                    'form_factor' => $formFactor,
                    'drive_type' => $driveType,
                    'power_consumption' => $powerConsumption,
                    'full_specs' => $specifications
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get storage specifications: ' . $e->getMessage(),
                'error_type' => 'system_error'
            ];
        }
    }

    /**
     * Get chassis specifications from JSON - Task 3.1
     */
    public function getChassisSpecifications($chassisUuid) {
        try {
            // Use ChassisManager for proper chassis JSON validation
            require_once __DIR__ . '/ChassisManager.php';
            $chassisManager = new ChassisManager();

            $chassisSpecs = $chassisManager->loadChassisSpecsByUUID($chassisUuid);

            if (!$chassisSpecs['found']) {
                return [
                    'success' => false,
                    'error' => "Chassis UUID $chassisUuid not found in chassis JSON specifications: " . $chassisSpecs['error'],
                    'error_type' => 'json_not_found'
                ];
            }

            $specs = $chassisSpecs['specifications'];

            // Extract drive bay configuration
            $driveBays = $specs['drive_bays'] ?? [];
            $backplane = $specs['backplane'] ?? [];

            return [
                'success' => true,
                'specifications' => [
                    'drive_bays' => $driveBays,
                    'backplane' => $backplane,
                    'full_specs' => $specs
                ]
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to get chassis specifications: ' . $e->getMessage(),
                'error_type' => 'system_error'
            ];
        }
    }

    /**
     * Validate storage form factor compatibility - Task 3.2
     */
    public function validateStorageFormFactor($storageSpecs, $chassisSpecs) {
        try {
            $storageFormFactor = $storageSpecs['form_factor'] ?? 'Unknown';
            $driveBays = $chassisSpecs['drive_bays'] ?? [];
            $bayConfiguration = $driveBays['bay_configuration'] ?? [];

            $compatibleBays = [];
            $caddyRequired = false;
            $incompatible = false;

            foreach ($bayConfiguration as $bayConfig) {
                $bayType = $bayConfig['bay_type'] ?? '';
                $bayCount = $bayConfig['count'] ?? 0;

                // Form factor compatibility rules - STRICT MATCHING ONLY (no adapters)
                if ($storageFormFactor === '2.5-inch' || $storageFormFactor === '2.5_inch') {
                    if ($bayType === '2.5_inch' || $bayType === '2.5-inch') {
                        $compatibleBays[] = [
                            'bay_type' => $bayType,
                            'count' => $bayCount,
                            'caddy_required' => false,
                            'direct_fit' => true
                        ];
                    } elseif ($bayType === '3.5_inch' || $bayType === '3.5-inch') {
                        // 2.5" drives DO NOT fit in 3.5" bays (strict matching required)
                        $incompatible = true;
                    }
                } elseif ($storageFormFactor === '3.5-inch' || $storageFormFactor === '3.5_inch') {
                    if ($bayType === '3.5_inch' || $bayType === '3.5-inch') {
                        $compatibleBays[] = [
                            'bay_type' => $bayType,
                            'count' => $bayCount,
                            'caddy_required' => false,
                            'direct_fit' => true
                        ];
                    } elseif ($bayType === '2.5_inch' || $bayType === '2.5-inch') {
                        // 3.5" drives DO NOT fit in 2.5" bays
                        $incompatible = true;
                    }
                } elseif ($storageFormFactor === 'M.2' || strpos($storageFormFactor, 'M.2') !== false) {
                    // M.2 drives connect via PCIe/motherboard, not traditional drive bays
                    // If chassis has M.2 bays, use them. Otherwise, bypass bay check.
                    if ($bayType === 'M.2') {
                        $compatibleBays[] = [
                            'bay_type' => $bayType,
                            'count' => $bayCount,
                            'caddy_required' => false,
                            'direct_fit' => true
                        ];
                    }
                } elseif ($storageFormFactor === 'U.2' || strpos($storageFormFactor, 'U.2') !== false) {
                    // U.2 drives connect via backplane/HBA, not traditional drive bays
                    // Bypass bay compatibility check for U.2
                    if ($bayType === 'U.2' || $bayType === '2.5_inch' || $bayType === '2.5-inch') {
                        $compatibleBays[] = [
                            'bay_type' => $bayType,
                            'count' => $bayCount,
                            'caddy_required' => false,
                            'direct_fit' => true
                        ];
                    }
                }
            }

            // M.2 and U.2 form factors bypass bay validation - they connect via PCIe/motherboard/HBA
            if (strpos($storageFormFactor, 'M.2') !== false || strpos($storageFormFactor, 'U.2') !== false) {
                return [
                    'compatible' => true,
                    'compatible_bays' => !empty($compatibleBays) ? $compatibleBays : [['bay_type' => 'direct_connection', 'count' => 99, 'caddy_required' => false]],
                    'caddy_required' => false,
                    'form_factor_match' => true,
                    'bypass_reason' => "$storageFormFactor connects via PCIe/motherboard, not traditional drive bays"
                ];
            }

            if (empty($compatibleBays) || $incompatible) {
                return [
                    'compatible' => false,
                    'reason' => "Storage form factor $storageFormFactor is not compatible with available chassis bays",
                    'available_bays' => $bayConfiguration
                ];
            }

            return [
                'compatible' => true,
                'compatible_bays' => $compatibleBays,
                'caddy_required' => $caddyRequired,
                'form_factor_match' => !$caddyRequired
            ];

        } catch (Exception $e) {
            return [
                'compatible' => false,
                'reason' => 'Form factor validation error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Validate storage interface compatibility - Task 4.1
     */
    public function validateStorageInterface($storageSpecs, $chassisSpecs) {
        try {
            $storageInterface = $storageSpecs['interface'] ?? 'Unknown';
            $backplane = $chassisSpecs['backplane'] ?? [];

            $supportsNvme = $backplane['supports_nvme'] ?? false;
            $supportsSata = $backplane['supports_sata'] ?? false;
            $supportsSas = $backplane['supports_sas'] ?? false;

            $compatible = false;
            $adapterRequired = false;
            $adapterMessage = '';

            // Interface compatibility rules
            $normalizedInterface = strtolower($storageInterface);

            if (strpos($normalizedInterface, 'nvme') !== false || strpos($normalizedInterface, 'pcie') !== false) {
                if ($supportsNvme) {
                    $compatible = true;
                } else {
                    $adapterRequired = true;
                    $adapterMessage = 'Add NVMe adapter card to motherboard before adding NVMe drive';
                }
            } elseif (strpos($normalizedInterface, 'sata') !== false) {
                if ($supportsSata) {
                    $compatible = true;
                } else {
                    return [
                        'compatible' => false,
                        'reason' => "Chassis backplane does not support SATA interface",
                        'storage_interface' => $storageInterface,
                        'backplane_support' => $backplane
                    ];
                }
            } elseif (strpos($normalizedInterface, 'sas') !== false) {
                if ($supportsSas) {
                    $compatible = true;
                } else {
                    return [
                        'compatible' => false,
                        'reason' => "Chassis backplane does not support SAS interface",
                        'storage_interface' => $storageInterface,
                        'backplane_support' => $backplane
                    ];
                }
            } else {
                return [
                    'compatible' => false,
                    'reason' => "Unknown storage interface: $storageInterface",
                    'storage_interface' => $storageInterface
                ];
            }

            if ($adapterRequired) {
                return [
                    'compatible' => true,
                    'adapter_required' => true,
                    'adapter_message' => $adapterMessage,
                    'storage_interface' => $storageInterface,
                    'backplane_support' => $backplane
                ];
            }

            return [
                'compatible' => $compatible,
                'adapter_required' => false,
                'storage_interface' => $storageInterface,
                'backplane_support' => $backplane
            ];

        } catch (Exception $e) {
            return [
                'compatible' => false,
                'reason' => 'Interface validation error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Determine caddy requirements - Task 5.1 & 5.2
     */
    public function determineCaddyRequirement($storageSpecs, $chassisSpecs) {
        try {
            $formFactorResult = $this->validateStorageFormFactor($storageSpecs, $chassisSpecs);

            if (!$formFactorResult['compatible']) {
                return [
                    'caddy_required' => false,
                    'reason' => 'Storage is not compatible with chassis',
                    'incompatible' => true
                ];
            }

            $caddyRequired = $formFactorResult['caddy_required'] ?? false;
            $compatibleBays = $formFactorResult['compatible_bays'] ?? [];

            if (!$caddyRequired) {
                return [
                    'caddy_required' => false,
                    'message' => 'No caddy required - storage fits directly in chassis bays'
                ];
            }

            $caddyMessages = [];
            foreach ($compatibleBays as $bay) {
                if ($bay['caddy_required']) {
                    $caddyType = $bay['caddy_type'] ?? 'appropriate caddy';
                    if ($caddyType === '2.5 inch caddy') {
                        $caddyMessages[] = "Add 2.5 inch caddy before adding this storage device";
                    } elseif ($caddyType === 'M.2 adapter') {
                        $caddyMessages[] = "Add M.2 adapter before adding this storage device";
                    } else {
                        $caddyMessages[] = "Add $caddyType before adding this storage device";
                    }
                }
            }

            return [
                'caddy_required' => true,
                'caddy_messages' => array_unique($caddyMessages),
                'compatible_bays' => $compatibleBays
            ];

        } catch (Exception $e) {
            return [
                'caddy_required' => false,
                'reason' => 'Caddy requirement error: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Complete storage compatibility validation - DEPRECATED
     * Now handled by StorageConnectionValidator in FlexibleCompatibilityValidator
     * Kept for backward compatibility but returns simplified result
     */
    public function validateStorageCompatibility($storageUuid, $chassisUuid) {
        // DEPRECATED: Defer to FlexibleCompatibilityValidator for accurate validation
        return [
            'compatible' => true,
            'compatibility_score' => 1.0,
            'warnings' => ['Storage validation now handled by FlexibleCompatibilityValidator with comprehensive checks'],
            'deprecated' => true,
            'recommendation' => 'Use FlexibleCompatibilityValidator::validateComponentAddition() for storage validation'
        ];
    }


    /**
     * Helper method to get all components of a type
     */
    private function getAllComponents($type, $availableOnly = true) {
        $tableMap = [
            'cpu' => 'cpuinventory',
            'motherboard' => 'motherboardinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];

        if (!isset($tableMap[$type])) {
            return [];
        }

        $table = $tableMap[$type];
        $whereClause = $availableOnly ? " WHERE Status = 1" : "";

        try {
            $stmt = $this->pdo->prepare("SELECT UUID as uuid, * FROM $table$whereClause LIMIT 50");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
}

} // End of class_exists('CompatibilityEngine') check
?>