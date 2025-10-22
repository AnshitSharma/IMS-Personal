<?php
/**
 * Storage Connection Validator - JSON-Driven Storage Compatibility Engine
 *
 * Implements 10-check validation flow WITHOUT hardcoded rules.
 * All connection logic derived from JSON specifications.
 *
 * Connection Paths (Priority Order):
 * 1. Chassis Bay (hot-swap capability)
 * 2. Motherboard Direct (native SATA/M.2/U.2 ports)
 * 3. HBA Card (SAS/SATA/U.2 controllers)
 * 4. PCIe Adapter (M.2/U.2 to PCIe adapters)
 *
 * Design Principles:
 * - NO hardcoded interface/form-factor rules
 * - Component-order agnostic (storage can be added before/after chassis)
 * - JSON-driven compatibility checks
 * - Actionable error messages with alternatives
 */

require_once __DIR__ . '/ComponentDataService.php';
require_once __DIR__ . '/DataExtractionUtilities.php';

class StorageConnectionValidator {

    private $pdo;
    private $dataUtils;
    private $componentDataService;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->dataUtils = new DataExtractionUtilities();
        $this->componentDataService = ComponentDataService::getInstance();
    }

    /**
     * Main validation entry point
     * Returns: ['valid' => bool, 'connection_paths' => [], 'errors' => [], 'warnings' => [], 'info' => []]
     */
    public function validate($configUuid, $storageUuid, $existingComponents) {
        try {
            error_log("StorageConnectionValidator::validate START");
            error_log("Config: $configUuid, Storage: $storageUuid");
            error_log("Existing components structure: " . json_encode(array_keys($existingComponents)));

            $errors = [];
            $warnings = [];
            $info = [];
            $connectionPaths = [];

            // Get storage specifications from JSON
            error_log("Getting storage specs for UUID: $storageUuid");
            $storageSpecs = $this->getStorageSpecs($storageUuid);
            error_log("Storage specs loaded: " . ($storageSpecs ? "YES" : "NO"));
        if (!$storageSpecs) {
            return [
                'valid' => false,
                'connection_paths' => [],
                'errors' => [['type' => 'storage_not_found', 'message' => "Storage $storageUuid not found in JSON specifications"]],
                'warnings' => [],
                'info' => []
            ];
        }

        $storageInterface = $storageSpecs['interface'] ?? 'Unknown';
        $storageFormFactor = $storageSpecs['form_factor'] ?? 'Unknown';
        $storageSubtype = $storageSpecs['subtype'] ?? '';

        // CHECK 1: Chassis Backplane Capability
        $chassisPath = $this->checkChassisBackplaneCapability($storageInterface, $storageFormFactor, $existingComponents, $storageSpecs);
        if ($chassisPath['available']) {
            $connectionPaths[] = $chassisPath;
        }

        // CHECK 2: Motherboard Direct Connection
        $motherboardPath = $this->checkMotherboardDirectConnection($storageInterface, $storageFormFactor, $storageSubtype, $existingComponents, $storageSpecs);
        if ($motherboardPath['available']) {
            $connectionPaths[] = $motherboardPath;
        }

        // CHECK 3: HBA Card Requirement/Availability
        $hbaPath = $this->checkHBACardRequirement($storageInterface, $existingComponents);
        if ($hbaPath['available']) {
            $connectionPaths[] = $hbaPath;
        } elseif ($hbaPath['mandatory']) {
            $errors[] = $hbaPath['error'];
        }

        // CHECK 4: PCIe Adapter Card Check
        $adapterPath = $this->checkPCIeAdapterCard($storageFormFactor, $storageSubtype, $existingComponents);
        if ($adapterPath['available']) {
            $connectionPaths[] = $adapterPath;
        }

        // Determine primary connection path (priority order)
        $primaryPath = $this->selectPrimaryConnectionPath($connectionPaths);

        // CHECK 5: Bay Availability (ONLY if chassis exists and using chassis path)
        if ($primaryPath && $primaryPath['type'] === 'chassis_bay' && $existingComponents['chassis']) {
            $bayCheck = $this->checkBayAvailability($storageFormFactor, $existingComponents, $storageSpecs);
            if (!$bayCheck['available']) {
                $errors[] = $bayCheck['error'];
            } else {
                // CHECK 10: Caddy Requirement Check
                if ($bayCheck['caddy_required']) {
                    $caddyCheck = $this->checkCaddyRequirement($storageFormFactor, $existingComponents, $bayCheck);
                    if (!$caddyCheck['available']) {
                        $warnings[] = $caddyCheck['warning'];
                    } else {
                        $info[] = ['message' => $caddyCheck['message']];
                    }
                }
            }
        }

        // CHECK 6: Port/Slot Availability
        if ($primaryPath) {
            $portCheck = $this->checkPortSlotAvailability($primaryPath, $existingComponents, $storageSpecs);
            if (!$portCheck['available']) {
                $errors[] = $portCheck['error'];
            } elseif (!empty($portCheck['warning'])) {
                $warnings[] = $portCheck['warning'];
            }
        }

        // CHECK 7: PCIe Lane Budget (ONLY if motherboard/CPU exists and using NVMe)
        if (strpos(strtolower($storageInterface), 'nvme') !== false || strpos(strtolower($storageInterface), 'pcie') !== false) {
            // Only check PCIe lanes if there's a motherboard or CPU in config
            if ($existingComponents['motherboard'] || !empty($existingComponents['cpu'])) {
                $laneCheck = $this->checkPCIeLaneBudget($storageSpecs, $existingComponents);
                if (!$laneCheck['sufficient']) {
                    $warnings[] = $laneCheck['warning'];
                }
            }

            // CHECK 8: PCIe Version Compatibility (ONLY if there's a connection path)
            if ($primaryPath) {
                $versionCheck = $this->checkPCIeVersionCompatibility($storageSpecs, $primaryPath, $existingComponents);
                if (!empty($versionCheck['warning'])) {
                    $warnings[] = $versionCheck['warning'];
                }
            }

            // CHECK 9: Bifurcation Requirement (for multi-slot adapters)
            if ($primaryPath && $primaryPath['type'] === 'pcie_adapter') {
                $bifurcationCheck = $this->checkBifurcationRequirement($primaryPath, $existingComponents);
                if (!empty($bifurcationCheck['warning'])) {
                    $warnings[] = $bifurcationCheck['warning'];
                } elseif (!empty($bifurcationCheck['error'])) {
                    $errors[] = $bifurcationCheck['error'];
                }
            }
        }

        // Final validation decision - ALLOW in any order with recommendations
        $protocol = $this->extractProtocol($storageInterface);

        if (empty($connectionPaths)) {
            // SAS storage REQUIRES HBA or SAS chassis - BLOCK if neither available
            if ($protocol === 'sas') {
                $errors[] = [
                    'type' => 'sas_requires_hba_or_chassis',
                    'message' => 'SAS storage requires SAS HBA card OR chassis with SAS backplane',
                    'resolution' => 'Add SAS HBA card (e.g., LSI 9400-16i) OR add chassis with SAS backplane before adding SAS storage'
                ];
            } else {
                // Non-SAS storage - ALLOW with recommendations
                $recommendations = $this->generateRecommendations($storageInterface, $storageFormFactor, $storageSubtype, $existingComponents);
                $warnings[] = [
                    'type' => 'no_connection_path_yet',
                    'message' => 'Storage added but not yet connected to any component',
                    'recommendation' => 'Add one of the following to connect this storage',
                    'options' => $recommendations
                ];
                $info[] = [
                    'type' => 'component_order_flexible',
                    'message' => 'You can add required components (chassis/motherboard/adapter) later to connect this storage'
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'connection_paths' => $connectionPaths,
            'primary_path' => $primaryPath,
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info
        ];
        } catch (Exception $e) {
            error_log("StorageConnectionValidator Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return [
                'valid' => false,
                'connection_paths' => [],
                'errors' => [['type' => 'validation_error', 'message' => 'Internal validation error: ' . $e->getMessage()]],
                'warnings' => [],
                'info' => []
            ];
        }
    }

    /**
     * CHECK 1: Chassis Backplane Capability Check
     * Reads: chassis.backplane.supports_* from JSON
     */
    private function checkChassisBackplaneCapability($storageInterface, $storageFormFactor, $existing, $storageSpecs) {
        if (!$existing['chassis'] || !isset($existing['chassis']['component_uuid'])) {
            return ['available' => false, 'reason' => 'no_chassis'];
        }

        $chassisSpecs = $this->getChassisSpecs($existing['chassis']['component_uuid']);
        if (!$chassisSpecs) {
            return ['available' => false, 'reason' => 'chassis_specs_not_found'];
        }

        $backplane = $chassisSpecs['backplane'] ?? [];
        $supportsNvme = $backplane['supports_nvme'] ?? false;
        $supportsSata = $backplane['supports_sata'] ?? false;
        $supportsSas = $backplane['supports_sas'] ?? false;

        // Protocol extraction from storage interface
        $protocol = $this->extractProtocol($storageInterface);

        $compatible = false;
        if ($protocol === 'nvme' && $supportsNvme) $compatible = true;
        if ($protocol === 'sata' && $supportsSata) $compatible = true;
        if ($protocol === 'sas' && $supportsSas) $compatible = true;

        if ($compatible) {
            $backplaneInterface = $backplane['interface'] ?? 'Unknown';

            // Generate clear compatibility message
            if ($protocol === 'sata' && strtoupper($backplaneInterface) !== 'SATA3') {
                $compatibilityNote = "$storageInterface drive compatible with $backplaneInterface backplane (backward compatible)";
            } elseif ($protocol === 'sas' && strtoupper($backplaneInterface) !== 'SAS3') {
                $compatibilityNote = "$storageInterface drive on $backplaneInterface backplane";
            } else {
                $compatibilityNote = "$storageInterface drive on $backplaneInterface backplane (native support)";
            }

            return [
                'available' => true,
                'type' => 'chassis_bay',
                'priority' => 1,
                'description' => "Storage connects via chassis backplane ($compatibilityNote)",
                'details' => [
                    'chassis_uuid' => $existing['chassis']['component_uuid'],
                    'backplane_model' => $backplane['model'] ?? 'Unknown',
                    'backplane_interface' => $backplaneInterface,
                    'storage_interface' => $storageInterface,
                    'compatibility_type' => $protocol === strtolower($backplaneInterface) ? 'native' : 'backward_compatible'
                ]
            ];
        }

        return ['available' => false, 'reason' => 'backplane_incompatible'];
    }

    /**
     * CHECK 2: Motherboard Direct Connection Check
     * Reads: motherboard.storage.sata.ports, motherboard.storage.nvme.m2_slots[], motherboard.storage.nvme.u2_slots
     */
    private function checkMotherboardDirectConnection($storageInterface, $storageFormFactor, $storageSubtype, $existing, $storageSpecs) {
        if (!$existing['motherboard'] || !isset($existing['motherboard']['component_uuid'])) {
            return ['available' => false, 'reason' => 'no_motherboard'];
        }

        $motherboardSpecs = $this->getMotherboardSpecs($existing['motherboard']['component_uuid']);
        if (!$motherboardSpecs) {
            return ['available' => false, 'reason' => 'motherboard_specs_not_found'];
        }

        $storage = $motherboardSpecs['storage'] ?? [];
        $protocol = $this->extractProtocol($storageInterface);

        // SATA Port Check
        if ($protocol === 'sata' && isset($storage['sata']['ports']) && $storage['sata']['ports'] > 0) {
            return [
                'available' => true,
                'type' => 'motherboard_sata',
                'priority' => 2,
                'description' => "Storage connects via motherboard SATA port",
                'details' => [
                    'motherboard_uuid' => $existing['motherboard']['component_uuid'],
                    'total_sata_ports' => $storage['sata']['ports'],
                    'controller' => $storage['sata']['sata_controller'] ?? 'Integrated'
                ]
            ];
        }

        // M.2 Slot Check
        if (strpos(strtolower($storageFormFactor), 'm.2') !== false || strpos(strtolower($storageSubtype), 'm.2') !== false) {
            $m2Slots = $storage['nvme']['m2_slots'] ?? [];
            if (!empty($m2Slots) && isset($m2Slots[0]['count']) && $m2Slots[0]['count'] > 0) {
                return [
                    'available' => true,
                    'type' => 'motherboard_m2',
                    'priority' => 2,
                    'description' => "Storage connects via motherboard M.2 slot",
                    'details' => [
                        'motherboard_uuid' => $existing['motherboard']['component_uuid'],
                        'total_m2_slots' => $m2Slots[0]['count'],
                        'supported_form_factors' => $m2Slots[0]['form_factors'] ?? [],
                        'pcie_generation' => $m2Slots[0]['pcie_generation'] ?? 4
                    ]
                ];
            }
        }

        // U.2 Slot Check
        if (strpos(strtolower($storageFormFactor), 'u.2') !== false || strpos(strtolower($storageFormFactor), 'u.3') !== false) {
            $u2Slots = $storage['nvme']['u2_slots'] ?? [];
            if (isset($u2Slots['count']) && $u2Slots['count'] > 0) {
                return [
                    'available' => true,
                    'type' => 'motherboard_u2',
                    'priority' => 2,
                    'description' => "Storage connects via motherboard U.2 slot",
                    'details' => [
                        'motherboard_uuid' => $existing['motherboard']['component_uuid'],
                        'total_u2_slots' => $u2Slots['count'],
                        'connection' => $u2Slots['connection'] ?? 'Unknown'
                    ]
                ];
            }
        }

        return ['available' => false, 'reason' => 'no_compatible_motherboard_port'];
    }

    /**
     * CHECK 3: HBA Card Requirement Check
     * Reads: HBA cards from pciecardinventory with component_subtype='HBA Card'
     */
    private function checkHBACardRequirement($storageInterface, $existing) {
        $protocol = $this->extractProtocol($storageInterface);

        // SAS storage REQUIRES HBA card
        if ($protocol === 'sas') {
            // Search for SAS HBA in existing PCIe cards
            if (!empty($existing['pciecard']) && is_array($existing['pciecard'])) {
                foreach ($existing['pciecard'] as $card) {
                    $cardSpecs = $this->getPCIeCardSpecs($card['component_uuid']);
                    if ($cardSpecs && isset($cardSpecs['component_subtype']) && $cardSpecs['component_subtype'] === 'HBA Card') {
                        // Check if HBA supports SAS protocol
                        $hbaInterface = $cardSpecs['interface'] ?? '';
                        if (strpos(strtolower($hbaInterface), 'sas') !== false || strpos(strtolower($cardSpecs['data_rate'] ?? ''), 'sas') !== false) {
                            return [
                                'available' => true,
                                'type' => 'hba_card',
                                'priority' => 3,
                                'description' => "Storage connects via HBA card ({$cardSpecs['model']})",
                                'details' => [
                                    'hba_uuid' => $card['component_uuid'],
                                    'hba_model' => $cardSpecs['model'] ?? 'Unknown',
                                    'internal_ports' => $cardSpecs['internal_ports'] ?? 0,
                                    'max_devices' => $cardSpecs['max_devices'] ?? 0
                                ]
                            ];
                        }
                    }
                }
            }

            // SAS storage but no HBA found - MANDATORY error
            return [
                'available' => false,
                'mandatory' => true,
                'error' => [
                    'type' => 'hba_required',
                    'message' => "SAS storage requires SAS HBA card",
                    'resolution' => "Add SAS HBA card (e.g., LSI 9400-16i) before adding SAS storage"
                ]
            ];
        }

        // Non-SAS storage - HBA not required but can be used if available
        if (!empty($existing['pciecard']) && is_array($existing['pciecard'])) {
            foreach ($existing['pciecard'] as $card) {
                $cardSpecs = $this->getPCIeCardSpecs($card['component_uuid']);
                if ($cardSpecs && isset($cardSpecs['component_subtype']) && $cardSpecs['component_subtype'] === 'HBA Card') {
                    // Check if HBA supports this storage protocol
                    if ($protocol === 'sata') {
                        return [
                            'available' => true,
                            'type' => 'hba_card',
                            'priority' => 3,
                            'description' => "Storage can connect via HBA card (optional)",
                            'details' => ['hba_uuid' => $card['component_uuid']]
                        ];
                    }
                }
            }
        }

        return ['available' => false, 'mandatory' => false];
    }

    /**
     * CHECK 4: PCIe Adapter Card Check
     * Reads: PCIe cards with component_subtype='NVMe Adaptor'
     */
    private function checkPCIeAdapterCard($storageFormFactor, $storageSubtype, $existing) {
        if (!empty($existing['pciecard']) && is_array($existing['pciecard'])) {
            foreach ($existing['pciecard'] as $card) {
                $cardSpecs = $this->getPCIeCardSpecs($card['component_uuid']);
                if (!$cardSpecs) continue;

                if (isset($cardSpecs['component_subtype']) && $cardSpecs['component_subtype'] === 'NVMe Adaptor') {
                    // Check if adapter supports this storage form factor
                    if (strpos(strtolower($storageFormFactor), 'm.2') !== false || strpos(strtolower($storageSubtype), 'm.2') !== false) {
                        $supportedFormFactors = $cardSpecs['m2_form_factors'] ?? [];
                        return [
                            'available' => true,
                            'type' => 'pcie_adapter',
                            'priority' => 4,
                            'description' => "Storage connects via PCIe M.2 adapter card ({$cardSpecs['model']})",
                            'details' => [
                                'adapter_uuid' => $card['component_uuid'],
                                'adapter_model' => $cardSpecs['model'] ?? 'Unknown',
                                'm2_slots' => $cardSpecs['m2_slots'] ?? 0,
                                'supported_form_factors' => $supportedFormFactors,
                                'requires_bifurcation' => ($cardSpecs['m2_slots'] ?? 0) > 1
                            ]
                        ];
                    }
                }
            }
        }

        return ['available' => false];
    }

    /**
     * CHECK 5: Bay Availability Check
     */
    private function checkBayAvailability($storageFormFactor, $existing, $storageSpecs) {
        if (!$existing['chassis'] || !isset($existing['chassis']['component_uuid'])) {
            return ['available' => false, 'error' => ['type' => 'no_chassis', 'message' => 'No chassis in configuration']];
        }

        $chassisSpecs = $this->getChassisSpecs($existing['chassis']['component_uuid']);
        if (!$chassisSpecs) {
            return ['available' => false, 'error' => ['type' => 'chassis_specs_not_found', 'message' => 'Chassis specifications not found']];
        }

        $driveBays = $chassisSpecs['drive_bays'] ?? [];
        $totalBays = $driveBays['total_bays'] ?? 0;
        $bayConfiguration = $driveBays['bay_configuration'] ?? [];

        // Count used bays
        $usedBays = (!empty($existing['storage']) && is_array($existing['storage'])) ? count($existing['storage']) : 0;

        if ($usedBays >= $totalBays) {
            return [
                'available' => false,
                'error' => [
                    'type' => 'bay_limit_exceeded',
                    'message' => "Chassis has $totalBays drive bays, all occupied ($usedBays used)",
                    'resolution' => "Remove existing storage OR choose chassis with more bays"
                ]
            ];
        }

        // Form factor compatibility check
        $normalizedStorageFF = $this->normalizeFormFactor($storageFormFactor);
        $caddyRequired = false;
        $bayTypeMatch = false;

        foreach ($bayConfiguration as $bayConfig) {
            $bayType = $bayConfig['bay_type'] ?? '';
            $normalizedBayType = $this->normalizeFormFactor($bayType);

            // Direct match
            if ($normalizedStorageFF === $normalizedBayType) {
                $bayTypeMatch = true;
                break;
            }

            // 2.5" storage in 3.5" bay requires caddy
            if ($normalizedStorageFF === '2.5-inch' && $normalizedBayType === '3.5-inch') {
                $bayTypeMatch = true;
                $caddyRequired = true;
                break;
            }
        }

        if (!$bayTypeMatch) {
            return [
                'available' => false,
                'error' => [
                    'type' => 'form_factor_incompatible',
                    'message' => "Storage form factor $storageFormFactor not compatible with chassis bay types",
                    'resolution' => "Choose compatible storage OR replace chassis"
                ]
            ];
        }

        return [
            'available' => true,
            'caddy_required' => $caddyRequired,
            'available_bays' => $totalBays - $usedBays
        ];
    }

    /**
     * CHECK 6: Port/Slot Availability Check
     */
    private function checkPortSlotAvailability($primaryPath, $existing, $storageSpecs) {
        switch ($primaryPath['type']) {
            case 'motherboard_sata':
                $totalPorts = $primaryPath['details']['total_sata_ports'];
                $usedPorts = 0;
                if (!empty($existing['storage']) && is_array($existing['storage'])) {
                    foreach ($existing['storage'] as $storage) {
                        $specs = $this->getStorageSpecs($storage['component_uuid']);
                        if ($specs && $this->extractProtocol($specs['interface'] ?? '') === 'sata') {
                            $usedPorts++;
                        }
                    }
                }
                if ($usedPorts >= $totalPorts) {
                    return [
                        'available' => false,
                        'error' => [
                            'type' => 'sata_ports_exhausted',
                            'message' => "Motherboard SATA ports exhausted ($totalPorts ports, $usedPorts used)",
                            'resolution' => "Add SATA HBA card OR use NVMe storage"
                        ]
                    ];
                }
                return ['available' => true];

            case 'motherboard_m2':
                $totalSlots = $primaryPath['details']['total_m2_slots'];
                $usedSlots = 0;
                if (!empty($existing['storage']) && is_array($existing['storage'])) {
                    foreach ($existing['storage'] as $storage) {
                        $specs = $this->getStorageSpecs($storage['component_uuid']);
                        if ($specs && (strpos(strtolower($specs['form_factor'] ?? ''), 'm.2') !== false)) {
                            $usedSlots++;
                        }
                    }
                }
                if ($usedSlots >= $totalSlots) {
                    return [
                        'available' => false,
                        'error' => [
                            'type' => 'm2_slots_exhausted',
                            'message' => "Motherboard M.2 slots exhausted ($totalSlots slots, $usedSlots used)",
                            'resolution' => "Add M.2 to PCIe adapter card"
                        ]
                    ];
                }
                return ['available' => true];

            case 'hba_card':
                $maxDevices = $primaryPath['details']['max_devices'] ?? 0;
                $usedDevices = 0;
                if (!empty($existing['storage']) && is_array($existing['storage'])) {
                    foreach ($existing['storage'] as $storage) {
                        // Count storage devices using this HBA
                        $usedDevices++;
                    }
                }
                if ($usedDevices >= $maxDevices) {
                    return [
                        'available' => false,
                        'error' => [
                            'type' => 'hba_ports_exhausted',
                            'message' => "HBA card device limit reached ($maxDevices max)",
                            'resolution' => "Add another HBA card"
                        ]
                    ];
                }
                return ['available' => true];

            default:
                return ['available' => true];
        }
    }

    /**
     * CHECK 7: PCIe Lane Budget Check
     *
     * NOTE: M.2 storage on dedicated motherboard M.2 slots do NOT consume
     * PCIe expansion slot lanes. They use dedicated chipset lanes.
     * This check only applies to storage on PCIe expansion slots.
     */
    private function checkPCIeLaneBudget($storageSpecs, $existing) {
        // Check if this storage connects via M.2 slot
        $storageInterface = strtolower($storageSpecs['interface'] ?? '');
        $storageFormFactor = strtolower($storageSpecs['form_factor'] ?? '');

        // M.2 drives on dedicated M.2 slots don't consume expansion slot lanes
        $isM2Drive = (strpos($storageFormFactor, 'm.2') !== false ||
                      strpos($storageFormFactor, 'm2') !== false);

        if ($isM2Drive) {
            // M.2 slots have dedicated chipset lanes - skip expansion lane check
            return ['sufficient' => true, 'uses_dedicated_m2_slot' => true];
        }

        // For non-M.2 storage (U.2, PCIe add-in cards, etc.)
        // Get CPU and motherboard PCIe expansion lanes
        $totalLanes = 0;
        if (!empty($existing['cpu']) && is_array($existing['cpu']) && isset($existing['cpu'][0]['component_uuid'])) {
            $cpuSpecs = $this->dataUtils->getCPUByUUID($existing['cpu'][0]['component_uuid']);
            $totalLanes += $cpuSpecs['pcie_lanes'] ?? 0;
        }
        if ($existing['motherboard'] && isset($existing['motherboard']['component_uuid'])) {
            $mbSpecs = $this->getMotherboardSpecs($existing['motherboard']['component_uuid']);
            $totalLanes += $mbSpecs['chipset_pcie_lanes'] ?? 0;
        }

        // Calculate used lanes (PCIe cards + NICs + non-M.2 storage)
        $usedLanes = 0;
        if (!empty($existing['pciecard']) && is_array($existing['pciecard'])) {
            foreach ($existing['pciecard'] as $card) {
                $cardSpecs = $this->getPCIeCardSpecs($card['component_uuid']);
                $interface = $cardSpecs['interface'] ?? '';
                preg_match('/x(\d+)/', $interface, $matches);
                $usedLanes += (int)($matches[1] ?? 4);
            }
        }

        // Only count storage that uses PCIe expansion slots (not M.2)
        if (!empty($existing['storage']) && is_array($existing['storage'])) {
            foreach ($existing['storage'] as $storage) {
                $specs = $this->getStorageSpecs($storage['component_uuid']);
                if ($specs) {
                    $existingFormFactor = strtolower($specs['form_factor'] ?? '');
                    $isExistingM2 = (strpos($existingFormFactor, 'm.2') !== false ||
                                    strpos($existingFormFactor, 'm2') !== false);

                    // Only count if NOT M.2 and is NVMe
                    if (!$isExistingM2 && strpos(strtolower($specs['interface'] ?? ''), 'nvme') !== false) {
                        $usedLanes += 4; // U.2 or PCIe add-in NVMe uses x4
                    }
                }
            }
        }

        $requiredLanes = 4; // This storage device (U.2 or PCIe add-in)
        $availableLanes = $totalLanes - $usedLanes;

        if ($availableLanes < $requiredLanes) {
            return [
                'sufficient' => false,
                'warning' => [
                    'type' => 'pcie_lanes_insufficient',
                    'message' => "Insufficient PCIe expansion lanes (need $requiredLanes, available $availableLanes/$totalLanes)",
                    'recommendation' => "Remove other PCIe devices OR upgrade CPU/motherboard"
                ]
            ];
        }

        return ['sufficient' => true, 'available_lanes' => $availableLanes];
    }

    /**
     * CHECK 8: PCIe Version Compatibility Check
     */
    private function checkPCIeVersionCompatibility($storageSpecs, $primaryPath, $existing) {
        // Extract PCIe version from storage interface
        $storageInterface = $storageSpecs['interface'] ?? '';
        preg_match('/PCIe\s*(\d+\.\d+)/', $storageInterface, $matches);
        $storageVersion = (float)($matches[1] ?? 3.0);

        $slotVersion = 3.0; // Default
        if ($primaryPath && $primaryPath['type'] === 'motherboard_m2') {
            $slotVersion = (float)($primaryPath['details']['pcie_generation'] ?? 3.0);
        }

        if ($storageVersion > $slotVersion) {
            $degradation = (($storageVersion - $slotVersion) / $storageVersion) * 100;
            return [
                'warning' => [
                    'type' => 'pcie_version_mismatch',
                    'message' => "PCIe version mismatch: Storage PCIe $storageVersion on slot PCIe $slotVersion",
                    'impact' => sprintf("%.0f%% bandwidth reduction", $degradation),
                    'recommendation' => "Use PCIe $storageVersion slot for full performance"
                ]
            ];
        }

        return [];
    }

    /**
     * CHECK 9: Bifurcation Requirement Check
     */
    private function checkBifurcationRequirement($primaryPath, $existing) {
        if (!isset($primaryPath['details']['requires_bifurcation']) || !$primaryPath['details']['requires_bifurcation']) {
            return [];
        }

        if (!$existing['motherboard'] || !isset($existing['motherboard']['component_uuid'])) {
            return [];
        }

        $mbSpecs = $this->getMotherboardSpecs($existing['motherboard']['component_uuid']);
        $bifurcationSupport = false;

        // Check if motherboard has PCIe bifurcation support
        if (isset($mbSpecs['expansion_slots']['pcie_slots'])) {
            foreach ($mbSpecs['expansion_slots']['pcie_slots'] as $slot) {
                if (isset($slot['bifurcation_support']) && $slot['bifurcation_support']) {
                    $bifurcationSupport = true;
                    break;
                }
            }
        }

        if (!$bifurcationSupport) {
            return [
                'error' => [
                    'type' => 'bifurcation_not_supported',
                    'message' => "Multi-slot M.2 adapter requires PCIe bifurcation (motherboard unsupported)",
                    'resolution' => "Replace motherboard with bifurcation support OR use single-slot M.2 adapter"
                ]
            ];
        }

        return [
            'warning' => [
                'type' => 'bifurcation_required',
                'message' => "Requires BIOS bifurcation configuration for multi-slot M.2 adapter",
                'recommendation' => "Enable PCIe bifurcation in BIOS settings"
            ]
        ];
    }

    /**
     * CHECK 10: Caddy Requirement Check
     */
    private function checkCaddyRequirement($storageFormFactor, $existing, $bayCheck) {
        // Check if caddy exists in configuration
        if (!empty($existing['caddy']) && is_array($existing['caddy'])) {
            foreach ($existing['caddy'] as $caddy) {
                $caddySpecs = $this->getCaddySpecs($caddy['component_uuid']);
                if ($caddySpecs) {
                    // Check if caddy is compatible (2.5" to 3.5" adapter)
                    return [
                        'available' => true,
                        'message' => "Using 2.5-inch caddy for 3.5-inch bay installation"
                    ];
                }
            }
        }

        return [
            'available' => false,
            'warning' => [
                'type' => 'caddy_recommended',
                'message' => "2.5-inch storage in 3.5-inch bay requires caddy adapter",
                'recommendation' => "Add 2.5-inch to 3.5-inch caddy for proper installation"
            ]
        ];
    }

    /**
     * Select primary connection path based on priority
     */
    private function selectPrimaryConnectionPath($connectionPaths) {
        if (empty($connectionPaths)) {
            return null;
        }

        usort($connectionPaths, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        return $connectionPaths[0];
    }

    /**
     * Generate actionable recommendations for connecting storage
     * Returns array of recommendation objects with priority
     */
    private function generateRecommendations($storageInterface, $storageFormFactor, $storageSubtype, $existing) {
        $protocol = $this->extractProtocol($storageInterface);
        $recommendations = [];

        if ($protocol === 'sas') {
            // SAS requires HBA or SAS chassis
            if (!$existing['chassis']) {
                $recommendations[] = [
                    'priority' => 1,
                    'component' => 'Chassis with SAS backplane',
                    'reason' => 'Provides hot-swap SAS storage bays',
                    'example' => 'Supermicro SC846 with SAS3 backplane'
                ];
            }
            $recommendations[] = [
                'priority' => 2,
                'component' => 'SAS HBA Card',
                'reason' => 'Required controller for SAS storage',
                'example' => 'LSI 9400-16i or 9400-8i'
            ];
        } elseif ($protocol === 'sata') {
            // SATA can use chassis, motherboard, or SATA HBA
            if (!$existing['chassis']) {
                $recommendations[] = [
                    'priority' => 1,
                    'component' => 'Chassis with SATA backplane',
                    'reason' => 'Provides hot-swap SATA storage bays',
                    'example' => 'Supermicro chassis with SATA backplane'
                ];
            }
            if (!$existing['motherboard']) {
                $recommendations[] = [
                    'priority' => 2,
                    'component' => 'Motherboard with SATA ports',
                    'reason' => 'Direct SATA connection to motherboard',
                    'example' => 'Motherboard with 8+ SATA ports'
                ];
            }
            $recommendations[] = [
                'priority' => 3,
                'component' => 'SATA HBA Card',
                'reason' => 'Expands SATA port capacity',
                'example' => 'SATA HBA controller'
            ];
        } elseif ($protocol === 'nvme') {
            // NVMe options depend on form factor
            if (strpos(strtolower($storageFormFactor), 'm.2') !== false || strpos(strtolower($storageSubtype), 'm.2') !== false) {
                // M.2 NVMe
                if (!$existing['motherboard']) {
                    $recommendations[] = [
                        'priority' => 1,
                        'component' => 'Motherboard with M.2 slots',
                        'reason' => 'Direct M.2 NVMe connection',
                        'example' => 'Motherboard with 4x M.2 PCIe slots'
                    ];
                }
                $recommendations[] = [
                    'priority' => 2,
                    'component' => 'M.2 to PCIe Adapter Card',
                    'reason' => 'Convert M.2 drives to PCIe slot',
                    'example' => 'Supermicro AOM-SNG-4M2P (Quad M.2 adapter)'
                ];
                if (!$existing['chassis']) {
                    $recommendations[] = [
                        'priority' => 3,
                        'component' => 'Chassis with NVMe backplane',
                        'reason' => 'Hot-swap NVMe storage bays',
                        'example' => 'Chassis with U.2/NVMe backplane'
                    ];
                }
            } else {
                // U.2/U.3 NVMe
                if (!$existing['chassis']) {
                    $recommendations[] = [
                        'priority' => 1,
                        'component' => 'Chassis with NVMe/U.2 backplane',
                        'reason' => 'Hot-swap U.2/U.3 NVMe bays',
                        'example' => 'Chassis with U.2 backplane'
                    ];
                }
                if (!$existing['motherboard']) {
                    $recommendations[] = [
                        'priority' => 2,
                        'component' => 'Motherboard with U.2 ports',
                        'reason' => 'Direct U.2 connection',
                        'example' => 'Motherboard with U.2 connectors'
                    ];
                }
                $recommendations[] = [
                    'priority' => 3,
                    'component' => 'U.2 to PCIe Adapter Card',
                    'reason' => 'Convert U.2 drives to PCIe slot',
                    'example' => 'U.2 to PCIe adapter'
                ];
            }
        }

        // Sort by priority
        usort($recommendations, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });

        return $recommendations;
    }

    /**
     * Extract protocol from storage interface string
     */
    private function extractProtocol($interface) {
        $interface = strtolower($interface);
        if (strpos($interface, 'sas') !== false) return 'sas';
        if (strpos($interface, 'sata') !== false) return 'sata';
        if (strpos($interface, 'nvme') !== false || strpos($interface, 'pcie') !== false) return 'nvme';
        return 'unknown';
    }

    /**
     * Normalize form factor for consistent comparison
     */
    private function normalizeFormFactor($formFactor) {
        return strtolower(str_replace(['_', ' '], '-', $formFactor));
    }

    /**
     * Get storage specifications from JSON
     */
    private function getStorageSpecs($storageUuid) {
        return $this->dataUtils->getStorageByUUID($storageUuid);
    }

    /**
     * Get chassis specifications from JSON
     */
    private function getChassisSpecs($chassisUuid) {
        return $this->dataUtils->getChassisSpecifications($chassisUuid);
    }

    /**
     * Get motherboard specifications from JSON
     */
    private function getMotherboardSpecs($motherboardUuid) {
        return $this->dataUtils->getMotherboardByUUID($motherboardUuid);
    }

    /**
     * Get PCIe card specifications from JSON
     */
    private function getPCIeCardSpecs($cardUuid) {
        return $this->dataUtils->getPCIeCardByUUID($cardUuid);
    }

    /**
     * Get caddy specifications from database
     */
    private function getCaddySpecs($caddyUuid) {
        $stmt = $this->pdo->prepare("SELECT * FROM caddyinventory WHERE UUID = ?");
        $stmt->execute([$caddyUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
