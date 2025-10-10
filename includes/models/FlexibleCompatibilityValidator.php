<?php
/**
 * Flexible Compatibility Validator
 *
 * Bidirectional validation system for server components.
 * Allows components to be added in ANY order with intelligent compatibility checking.
 *
 * Key Features:
 * - Bidirectional validation (component → existing and existing → component)
 * - Capacity tracking (RAM total capacity, PCIe lane budget)
 * - Version compatibility (PCIe 5.0/4.0/3.0 cascade detection)
 * - Frequency cascade (RAM/Motherboard/CPU limiting factor)
 * - Storage-Chassis-Caddy chain validation
 * - Form factor validation (DIMM/SO-DIMM, ATX/EATX, 2.5"/3.5")
 */

require_once __DIR__ . '/ComponentDataService.php';
require_once __DIR__ . '/DataExtractionUtilities.php';
require_once __DIR__ . '/StorageConnectionValidator.php';

class FlexibleCompatibilityValidator {

    private $pdo;
    private $componentDataService;
    private $dataUtils;

    // Component type to inventory table mapping
    private $componentTables = [
        'chassis' => 'chassisinventory',
        'cpu' => 'cpuinventory',
        'ram' => 'raminventory',
        'storage' => 'storageinventory',
        'motherboard' => 'motherboardinventory',
        'nic' => 'nicinventory',
        'caddy' => 'caddyinventory',
        'pciecard' => 'pciecardinventory'
    ];

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->componentDataService = ComponentDataService::getInstance();
        $this->dataUtils = new DataExtractionUtilities();
    }

    /**
     * Main validation entry point
     * Validates component addition against ALL existing components (bidirectional)
     *
     * @param string $configUuid Server configuration UUID
     * @param string $newComponentType Type of component being added
     * @param string $newComponentUuid UUID of component being added
     * @return array Validation results with status, errors, warnings, info
     */
    public function validateComponentAddition($configUuid, $newComponentType, $newComponentUuid) {
        try {
            error_log("validateComponentAddition START: config=$configUuid, type=$newComponentType, uuid=$newComponentUuid");
            // Load all existing components in configuration
            $existingComponents = $this->getExistingComponents($configUuid);
            error_log("Loaded existing components: " . json_encode(array_map(function($v) { return is_array($v) ? count($v) . ' items' : ($v ? '1 item' : 'null'); }, $existingComponents)));

            // Route to component-specific validator
            switch ($newComponentType) {
                case 'cpu':
                    return $this->validateCPUAddition($configUuid, $newComponentUuid, $existingComponents);

                case 'motherboard':
                    return $this->validateMotherboardAddition($configUuid, $newComponentUuid, $existingComponents);

                case 'ram':
                    return $this->validateRAMAddition($configUuid, $newComponentUuid, $existingComponents);

                case 'storage':
                    error_log("Calling validateStorageAddition");
                    $result = $this->validateStorageAddition($configUuid, $newComponentUuid, $existingComponents);
                    error_log("validateStorageAddition returned: " . json_encode($result));
                    return $result;

                case 'chassis':
                    return $this->validateChassisAddition($configUuid, $newComponentUuid, $existingComponents);

                case 'nic':
                    return $this->validateNICAddition($configUuid, $newComponentUuid, $existingComponents);

                case 'pciecard':
                    return $this->validatePCIeCardAddition($configUuid, $newComponentUuid, $existingComponents);

                case 'caddy':
                    return $this->validateCaddyAddition($configUuid, $newComponentUuid, $existingComponents);

                default:
                    return [
                        'validation_status' => 'blocked',
                        'critical_errors' => [[
                            'type' => 'invalid_component_type',
                            'message' => "Invalid component type: $newComponentType"
                        ]],
                        'warnings' => [],
                        'info_messages' => []
                    ];
            }

        } catch (Exception $e) {
            error_log("FlexibleCompatibilityValidator Error: " . $e->getMessage());
            return [
                'validation_status' => 'blocked',
                'critical_errors' => [[
                    'type' => 'validation_system_error',
                    'message' => 'Validation system error: ' . $e->getMessage()
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }
    }

    /**
     * Load all existing components in a configuration
     */
    private function getExistingComponents($configUuid) {
        $stmt = $this->pdo->prepare("
            SELECT component_type, component_uuid, quantity, slot_position, notes
            FROM server_configuration_components
            WHERE config_uuid = ?
        ");
        $stmt->execute([$configUuid]);
        $components = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Organize by type for easier access
        $organized = [
            'cpu' => [],
            'motherboard' => null,
            'ram' => [],
            'storage' => [],
            'chassis' => null,
            'nic' => [],
            'pciecard' => [],
            'caddy' => []
        ];

        foreach ($components as $component) {
            $type = $component['component_type'];

            // Single instance components
            if (in_array($type, ['motherboard', 'chassis'])) {
                $organized[$type] = $component;
            } else {
                // Multiple instance components
                $organized[$type][] = $component;
            }
        }

        return $organized;
    }

    /**
     * Get component specifications from JSON
     */
    private function getComponentSpecs($componentType, $componentUuid) {
        try {
            switch ($componentType) {
                case 'cpu':
                case 'motherboard':
                case 'ram':
                    return $this->componentDataService->findComponentByUuid($componentType, $componentUuid);

                case 'storage':
                    return $this->getStorageSpecs($componentUuid);

                case 'chassis':
                    return $this->getChassisSpecs($componentUuid);

                case 'nic':
                case 'pciecard':
                    return $this->getPCIeCardSpecs($componentType, $componentUuid);

                default:
                    return null;
            }
        } catch (Exception $e) {
            error_log("Error getting specs for $componentType/$componentUuid: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get storage specifications from JSON
     */
    private function getStorageSpecs($storageUuid) {
        $jsonPath = __DIR__ . '/../../All-JSON/storage-jsons/storage-level-3.json';
        if (!file_exists($jsonPath)) {
            return null;
        }

        $jsonContent = file_get_contents($jsonPath);
        $storageData = json_decode($jsonContent, true);

        if (is_array($storageData)) {
            foreach ($storageData as $brand) {
                if (isset($brand['models']) && is_array($brand['models'])) {
                    foreach ($brand['models'] as $model) {
                        if (isset($model['uuid']) && $model['uuid'] === $storageUuid) {
                            return $model;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get chassis specifications from JSON
     */
    private function getChassisSpecs($chassisUuid) {
        require_once __DIR__ . '/ChassisManager.php';
        $chassisManager = new ChassisManager();
        $result = $chassisManager->loadChassisSpecsByUUID($chassisUuid);

        return $result['found'] ? $result['specifications'] : null;
    }

    /**
     * Get PCIe card (NIC/GPU/etc) specifications
     */
    private function getPCIeCardSpecs($componentType, $componentUuid) {
        // Get from database inventory
        $table = $this->componentTables[$componentType];
        $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
        $stmt->execute([$componentUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get component from database inventory
     */
    private function getComponentFromInventory($componentType, $componentUuid) {
        $table = $this->componentTables[$componentType] ?? null;
        if (!$table) return null;

        $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
        $stmt->execute([$componentUuid]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // ========================================
    // CPU VALIDATION (BIDIRECTIONAL)
    // ========================================

    /**
     * Validate CPU addition
     * Checks: socket, memory support, PCIe lanes, PCIe version, max memory capacity
     */
    private function validateCPUAddition($configUuid, $cpuUuid, $existing) {
        $errors = [];
        $warnings = [];
        $info = [];

        // Get CPU specs from JSON
        $cpuSpecs = $this->getComponentSpecs('cpu', $cpuUuid);
        if (!$cpuSpecs) {
            return [
                'validation_status' => 'blocked',
                'critical_errors' => [[
                    'type' => 'cpu_not_found_in_json',
                    'message' => "CPU $cpuUuid not found in specifications database"
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }

        // Extract CPU specifications
        $cpuSocket = $cpuSpecs['socket'] ?? null;
        $cpuMemoryTypes = $this->dataUtils->extractMemoryTypes($cpuSpecs);
        $cpuMaxMemoryFreq = $this->dataUtils->extractMaxMemoryFrequency($cpuSpecs);
        $cpuMaxMemoryCapacity = $this->dataUtils->extractMaxMemoryCapacity($cpuSpecs);
        $cpuECCRequired = $this->dataUtils->extractECCSupport($cpuSpecs) === 'required';
        $cpuPCIeLanes = $this->dataUtils->extractPCIeLanes($cpuSpecs);
        $cpuPCIeVersion = $this->dataUtils->extractPCIeVersion($cpuSpecs);

        // FORWARD CHECKS (CPU → Existing Motherboard)
        if ($existing['motherboard']) {
            $motherboardSpecs = $this->getComponentSpecs('motherboard', $existing['motherboard']['component_uuid']);

            if ($motherboardSpecs) {
                $motherboardSocket = $motherboardSpecs['socket'] ?? null;
                $motherboardMaxCPUs = $motherboardSpecs['max_cpus'] ?? 1;
                $motherboardPCIeVersion = $this->dataUtils->extractPCIeVersion($motherboardSpecs);

                // Socket compatibility check
                if ($cpuSocket && $motherboardSocket && $cpuSocket !== $motherboardSocket) {
                    $errors[] = [
                        'type' => 'socket_mismatch',
                        'severity' => 'critical',
                        'message' => "CPU socket $cpuSocket incompatible with motherboard socket $motherboardSocket",
                        'details' => [
                            'cpu_socket' => $cpuSocket,
                            'motherboard_socket' => $motherboardSocket,
                            'motherboard_uuid' => $existing['motherboard']['component_uuid']
                        ],
                        'resolution' => "Remove motherboard and add $cpuSocket motherboard OR choose $motherboardSocket CPU"
                    ];
                }

                // CPU socket limit check
                $currentCPUCount = count($existing['cpu']);
                if ($currentCPUCount >= $motherboardMaxCPUs) {
                    $errors[] = [
                        'type' => 'cpu_socket_limit_exceeded',
                        'severity' => 'critical',
                        'message' => "Motherboard supports $motherboardMaxCPUs CPU socket(s), $currentCPUCount already installed",
                        'details' => [
                            'current_cpu_count' => $currentCPUCount,
                            'max_cpus' => $motherboardMaxCPUs,
                            'existing_cpus' => array_column($existing['cpu'], 'component_uuid')
                        ],
                        'resolution' => "Remove existing CPU OR use motherboard with more CPU sockets"
                    ];
                }

                // PCIe version compatibility (CPU → Motherboard)
                if ($cpuPCIeVersion && $motherboardPCIeVersion && $cpuPCIeVersion > $motherboardPCIeVersion) {
                    $warnings[] = [
                        'type' => 'pcie_version_mismatch',
                        'severity' => 'medium',
                        'message' => "CPU supports PCIe $cpuPCIeVersion, but motherboard only supports PCIe $motherboardPCIeVersion",
                        'performance_impact' => "PCIe devices will be limited to PCIe $motherboardPCIeVersion bandwidth",
                        'recommendation' => "Choose PCIe $cpuPCIeVersion motherboard for full CPU capabilities"
                    ];
                }
            }
        } else {
            // No motherboard - informational warning
            $warnings[] = [
                'type' => 'no_motherboard',
                'severity' => 'low',
                'message' => 'No motherboard in configuration',
                'info' => "CPU requires $cpuSocket socket motherboard"
            ];
        }

        // REVERSE CHECKS (Existing RAM → CPU)
        if (!empty($existing['ram'])) {
            // RAM type compatibility
            $ramTypes = [];
            foreach ($existing['ram'] as $ram) {
                $ramSpecs = $this->getComponentSpecs('ram', $ram['component_uuid']);
                if ($ramSpecs) {
                    $ramType = $ramSpecs['memory_type'] ?? $ramSpecs['type'] ?? null;
                    if ($ramType) {
                        $ramTypes[] = $ramType;
                    }
                }
            }

            $ramTypes = array_unique($ramTypes);
            if (!empty($ramTypes)) {
                $incompatibleTypes = array_diff($ramTypes, $cpuMemoryTypes);
                if (!empty($incompatibleTypes)) {
                    $errors[] = [
                        'type' => 'ram_type_incompatibility',
                        'severity' => 'critical',
                        'message' => "CPU supports " . implode('/', $cpuMemoryTypes) . " only, existing RAM is " . implode('/', $ramTypes),
                        'details' => [
                            'cpu_supports' => $cpuMemoryTypes,
                            'existing_ram_types' => $ramTypes,
                            'affected_ram' => array_column($existing['ram'], 'component_uuid')
                        ],
                        'resolution' => "Remove " . implode('/', $incompatibleTypes) . " RAM and add " . implode('/', $cpuMemoryTypes) . " RAM OR choose CPU with " . implode('/', $ramTypes) . " support"
                    ];
                }
            }

            // RAM total capacity check
            $ramTotalCapacity = 0;
            foreach ($existing['ram'] as $ram) {
                $ramSpecs = $this->getComponentSpecs('ram', $ram['component_uuid']);
                if ($ramSpecs) {
                    $capacity = $ramSpecs['capacity_gb'] ?? ($ramSpecs['capacity'] ?? 0);
                    if (is_string($capacity)) {
                        // Extract numeric value (e.g., "16GB" → 16)
                        preg_match('/(\d+)/', $capacity, $matches);
                        $capacity = $matches[1] ?? 0;
                    }
                    $ramTotalCapacity += (int)$capacity * ($ram['quantity'] ?? 1);
                }
            }

            if ($cpuMaxMemoryCapacity && $ramTotalCapacity > $cpuMaxMemoryCapacity) {
                $errors[] = [
                    'type' => 'ram_capacity_exceeded',
                    'severity' => 'critical',
                    'message' => "Existing RAM total {$ramTotalCapacity}GB exceeds CPU maximum capacity {$cpuMaxMemoryCapacity}GB",
                    'details' => [
                        'existing_ram_total' => $ramTotalCapacity . 'GB',
                        'cpu_max_capacity' => $cpuMaxMemoryCapacity . 'GB',
                        'excess' => ($ramTotalCapacity - $cpuMaxMemoryCapacity) . 'GB'
                    ],
                    'resolution' => "Remove RAM modules to reduce total to {$cpuMaxMemoryCapacity}GB OR choose CPU with higher memory capacity"
                ];
            }

            // ECC requirement check
            if ($cpuECCRequired) {
                foreach ($existing['ram'] as $ram) {
                    $ramSpecs = $this->getComponentSpecs('ram', $ram['component_uuid']);
                    if ($ramSpecs) {
                        $ramIsECC = ($ramSpecs['ecc'] ?? $ramSpecs['is_ecc'] ?? false);
                        if (!$ramIsECC) {
                            $errors[] = [
                                'type' => 'ecc_required',
                                'severity' => 'critical',
                                'message' => "CPU requires ECC RAM, existing RAM is non-ECC",
                                'details' => [
                                    'cpu_uuid' => $cpuUuid,
                                    'ecc_requirement' => 'mandatory',
                                    'affected_ram' => $ram['component_uuid']
                                ],
                                'resolution' => "Remove non-ECC RAM and add ECC RAM OR choose CPU without ECC requirement"
                            ];
                            break; // One error is enough
                        }
                    }
                }
            }

            // RAM frequency check
            $maxRamFreq = 0;
            foreach ($existing['ram'] as $ram) {
                $ramSpecs = $this->getComponentSpecs('ram', $ram['component_uuid']);
                if ($ramSpecs) {
                    $freq = $ramSpecs['frequency_mhz'] ?? ($ramSpecs['frequency'] ?? 0);
                    if (is_string($freq)) {
                        preg_match('/(\d+)/', $freq, $matches);
                        $freq = $matches[1] ?? 0;
                    }
                    $maxRamFreq = max($maxRamFreq, (int)$freq);
                }
            }

            if ($cpuMaxMemoryFreq && $maxRamFreq > $cpuMaxMemoryFreq) {
                $reduction = round((1 - ($cpuMaxMemoryFreq / $maxRamFreq)) * 100, 1);
                $warnings[] = [
                    'type' => 'ram_frequency_downgrade',
                    'severity' => 'medium',
                    'message' => "Existing RAM frequency {$maxRamFreq}MHz exceeds CPU max {$cpuMaxMemoryFreq}MHz. RAM will downclock to {$cpuMaxMemoryFreq}MHz",
                    'performance_impact' => "~{$reduction}% performance reduction from rated speed",
                    'details' => [
                        'ram_frequency' => $maxRamFreq . 'MHz',
                        'cpu_max_frequency' => $cpuMaxMemoryFreq . 'MHz',
                        'effective_frequency' => $cpuMaxMemoryFreq . 'MHz'
                    ]
                ];
            }
        }

        // REVERSE CHECKS (Existing PCIe devices → CPU)
        $pcieLaneRequirement = 0;
        $pcieDevices = array_merge($existing['nic'], $existing['pciecard']);

        foreach ($pcieDevices as $device) {
            $deviceSpecs = $this->getComponentFromInventory($device['component_type'], $device['component_uuid']);
            if ($deviceSpecs) {
                $lanes = $this->extractPCIeSlotSize($deviceSpecs);
                $pcieLaneRequirement += $lanes * ($device['quantity'] ?? 1);
            }
        }

        if ($cpuPCIeLanes && $pcieLaneRequirement > $cpuPCIeLanes) {
            $errors[] = [
                'type' => 'pcie_lane_budget_exceeded',
                'severity' => 'critical',
                'message' => "Existing PCIe cards require {$pcieLaneRequirement} lanes, CPU only provides {$cpuPCIeLanes} lanes",
                'details' => [
                    'total_lanes_required' => $pcieLaneRequirement,
                    'cpu_provides' => $cpuPCIeLanes,
                    'deficit' => $pcieLaneRequirement - $cpuPCIeLanes,
                    'existing_pcie_devices' => array_column($pcieDevices, 'component_uuid')
                ],
                'resolution' => "Remove PCIe devices OR choose CPU with more PCIe lanes (e.g., Threadripper: 128 lanes)"
            ];
        }

        // Add CPU info
        $info[] = [
            'type' => 'cpu_specifications',
            'message' => 'CPU specifications loaded',
            'specs' => [
                'socket' => $cpuSocket,
                'memory_types' => $cpuMemoryTypes,
                'max_memory_frequency' => $cpuMaxMemoryFreq . 'MHz',
                'max_memory_capacity' => $cpuMaxMemoryCapacity . 'GB',
                'pcie_lanes' => $cpuPCIeLanes,
                'pcie_version' => $cpuPCIeVersion,
                'ecc_required' => $cpuECCRequired ? 'Yes' : 'No'
            ]
        ];

        // Determine validation status
        $status = empty($errors) ? (empty($warnings) ? 'allowed' : 'allowed_with_warnings') : 'blocked';

        return [
            'validation_status' => $status,
            'critical_errors' => $errors,
            'warnings' => $warnings,
            'info_messages' => $info
        ];
    }

    /**
     * Extract PCIe slot size from component specs (x1, x4, x8, x16)
     */
    private function extractPCIeSlotSize($componentSpecs) {
        // Check various field names
        $slotSize = $componentSpecs['pcie_slot_size'] ??
                   $componentSpecs['slot_size'] ??
                   $componentSpecs['pcie_lanes'] ??
                   $componentSpecs['lanes'] ??
                   16; // Default to x16 if not specified

        // Extract numeric value if it's a string like "x16"
        if (is_string($slotSize)) {
            preg_match('/(\d+)/', $slotSize, $matches);
            $slotSize = $matches[1] ?? 16;
        }

        return (int)$slotSize;
    }

    // ========================================
    // MOTHERBOARD VALIDATION (BIDIRECTIONAL)
    // ========================================

    /**
     * Validate Motherboard addition
     * Reverse checks against existing CPUs, RAM, PCIe cards, chassis
     */
    private function validateMotherboardAddition($configUuid, $motherboardUuid, $existing) {
        $errors = [];
        $warnings = [];
        $info = [];

        // Check if motherboard already exists (single instance component)
        if ($existing['motherboard']) {
            return [
                'validation_status' => 'blocked',
                'critical_errors' => [[
                    'type' => 'motherboard_already_exists',
                    'severity' => 'critical',
                    'message' => 'Configuration already has a motherboard',
                    'existing_motherboard' => $existing['motherboard']['component_uuid'],
                    'resolution' => 'Remove existing motherboard first'
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }

        // Get motherboard specs
        $motherboardSpecs = $this->getComponentSpecs('motherboard', $motherboardUuid);
        if (!$motherboardSpecs) {
            return [
                'validation_status' => 'blocked',
                'critical_errors' => [[
                    'type' => 'motherboard_not_found_in_json',
                    'message' => "Motherboard $motherboardUuid not found in specifications database"
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }

        // Extract motherboard specifications
        $motherboardSocket = $motherboardSpecs['socket'] ?? null;
        $motherboardMaxCPUs = $motherboardSpecs['max_cpus'] ?? 1;
        $motherboardMemoryTypes = $this->dataUtils->extractMemoryTypes($motherboardSpecs);
        $motherboardMemorySlots = $motherboardSpecs['memory_slots'] ?? 4;
        $motherboardMaxMemoryCapacity = $this->dataUtils->extractMaxMemoryCapacity($motherboardSpecs);
        $motherboardMaxMemoryFreq = $this->dataUtils->extractMaxMemoryFrequency($motherboardSpecs);
        $motherboardFormFactor = $motherboardSpecs['form_factor'] ?? 'ATX';
        $motherboardMemoryFormFactor = $this->dataUtils->extractMemoryFormFactor($motherboardSpecs);
        $motherboardPCIeSlots = $this->dataUtils->extractPCIeSlots($motherboardSpecs);
        $motherboardPCIeVersion = $this->dataUtils->extractPCIeVersion($motherboardSpecs);
        $motherboardPerSlotCapacity = $this->dataUtils->extractPerSlotMemoryCapacity($motherboardSpecs);

        // REVERSE CHECKS (Existing CPUs → Motherboard)
        if (!empty($existing['cpu'])) {
            foreach ($existing['cpu'] as $cpu) {
                $cpuSpecs = $this->getComponentSpecs('cpu', $cpu['component_uuid']);
                if ($cpuSpecs) {
                    $cpuSocket = $cpuSpecs['socket'] ?? null;
                    $cpuPCIeVersion = $this->dataUtils->extractPCIeVersion($cpuSpecs);

                    // Socket mismatch
                    if ($cpuSocket && $motherboardSocket && $cpuSocket !== $motherboardSocket) {
                        $errors[] = [
                            'type' => 'cpu_socket_mismatch',
                            'severity' => 'critical',
                            'message' => "Existing CPU socket $cpuSocket incompatible with motherboard socket $motherboardSocket",
                            'details' => [
                                'cpu_uuid' => $cpu['component_uuid'],
                                'cpu_socket' => $cpuSocket,
                                'motherboard_socket' => $motherboardSocket
                            ],
                            'resolution' => "Remove CPU and add $motherboardSocket CPU OR choose $cpuSocket motherboard"
                        ];
                    }

                    // PCIe version check (CPU → Motherboard)
                    if ($cpuPCIeVersion && $motherboardPCIeVersion && $cpuPCIeVersion > $motherboardPCIeVersion) {
                        $warnings[] = [
                            'type' => 'cpu_pcie_version_higher',
                            'severity' => 'medium',
                            'message' => "CPU supports PCIe $cpuPCIeVersion, but motherboard only supports PCIe $motherboardPCIeVersion",
                            'performance_impact' => "PCIe devices will be limited to PCIe $motherboardPCIeVersion bandwidth"
                        ];
                    }
                }
            }

            // CPU count exceeds motherboard socket limit
            $cpuCount = count($existing['cpu']);
            if ($cpuCount > $motherboardMaxCPUs) {
                $errors[] = [
                    'type' => 'cpu_count_exceeded',
                    'severity' => 'critical',
                    'message' => "Configuration has $cpuCount CPUs, motherboard only supports $motherboardMaxCPUs CPU socket(s)",
                    'details' => [
                        'existing_cpu_count' => $cpuCount,
                        'motherboard_max_cpus' => $motherboardMaxCPUs,
                        'existing_cpus' => array_column($existing['cpu'], 'component_uuid')
                    ],
                    'resolution' => "Remove " . ($cpuCount - $motherboardMaxCPUs) . " CPU(s) OR choose motherboard with $cpuCount CPU sockets"
                ];
            }
        }

        // REVERSE CHECKS (Existing RAM → Motherboard)
        if (!empty($existing['ram'])) {
            $ramCount = 0;
            $ramTotalCapacity = 0;
            $ramTypes = [];
            $ramFormFactors = [];
            $ramFrequencies = [];

            foreach ($existing['ram'] as $ram) {
                $ramSpecs = $this->getComponentSpecs('ram', $ram['component_uuid']);
                if ($ramSpecs) {
                    $ramCount += ($ram['quantity'] ?? 1);

                    // Capacity
                    $capacity = $ramSpecs['capacity_gb'] ?? ($ramSpecs['capacity'] ?? 0);
                    if (is_string($capacity)) {
                        preg_match('/(\d+)/', $capacity, $matches);
                        $capacity = $matches[1] ?? 0;
                    }
                    $ramTotalCapacity += (int)$capacity * ($ram['quantity'] ?? 1);

                    // Type
                    $ramType = $ramSpecs['memory_type'] ?? $ramSpecs['type'] ?? null;
                    if ($ramType) $ramTypes[] = $ramType;

                    // Form factor
                    $ramFormFactor = $ramSpecs['form_factor'] ?? null;
                    if ($ramFormFactor) $ramFormFactors[] = $ramFormFactor;

                    // Frequency
                    $freq = $ramSpecs['frequency_mhz'] ?? ($ramSpecs['frequency'] ?? 0);
                    if (is_string($freq)) {
                        preg_match('/(\d+)/', $freq, $matches);
                        $freq = $matches[1] ?? 0;
                    }
                    if ($freq) $ramFrequencies[] = (int)$freq;

                    // Per-module capacity check
                    if ($motherboardPerSlotCapacity && (int)$capacity > $motherboardPerSlotCapacity) {
                        $errors[] = [
                            'type' => 'ram_per_slot_capacity_exceeded',
                            'severity' => 'critical',
                            'message' => "RAM module capacity {$capacity}GB exceeds motherboard per-slot maximum {$motherboardPerSlotCapacity}GB",
                            'details' => [
                                'ram_uuid' => $ram['component_uuid'],
                                'ram_capacity' => $capacity . 'GB',
                                'motherboard_per_slot_max' => $motherboardPerSlotCapacity . 'GB'
                            ],
                            'resolution' => "Use {$motherboardPerSlotCapacity}GB or smaller modules OR choose motherboard supporting {$capacity}GB per slot"
                        ];
                    }
                }
            }

            // RAM type compatibility
            $ramTypes = array_unique($ramTypes);
            if (!empty($ramTypes)) {
                $incompatibleTypes = array_diff($ramTypes, $motherboardMemoryTypes);
                if (!empty($incompatibleTypes)) {
                    $errors[] = [
                        'type' => 'ram_type_incompatibility',
                        'severity' => 'critical',
                        'message' => "Motherboard supports " . implode('/', $motherboardMemoryTypes) . ", existing RAM is " . implode('/', $ramTypes),
                        'details' => [
                            'motherboard_supports' => $motherboardMemoryTypes,
                            'existing_ram_types' => $ramTypes,
                            'affected_ram' => array_column($existing['ram'], 'component_uuid')
                        ],
                        'resolution' => "Remove " . implode('/', $incompatibleTypes) . " RAM OR choose motherboard with " . implode('/', $ramTypes) . " support"
                    ];
                }
            }

            // RAM form factor compatibility
            $ramFormFactors = array_unique($ramFormFactors);
            if (!empty($ramFormFactors) && $motherboardMemoryFormFactor) {
                foreach ($ramFormFactors as $ramFormFactor) {
                    if ($ramFormFactor !== $motherboardMemoryFormFactor) {
                        $errors[] = [
                            'type' => 'ram_form_factor_mismatch',
                            'severity' => 'critical',
                            'message' => "Motherboard has $motherboardMemoryFormFactor slots, existing RAM is $ramFormFactor",
                            'resolution' => "Remove $ramFormFactor RAM and add $motherboardMemoryFormFactor RAM OR choose $ramFormFactor motherboard"
                        ];
                        break;
                    }
                }
            }

            // RAM slot count check
            if ($ramCount > $motherboardMemorySlots) {
                $errors[] = [
                    'type' => 'ram_slot_count_exceeded',
                    'severity' => 'critical',
                    'message' => "Configuration has $ramCount RAM modules, motherboard only has $motherboardMemorySlots slots",
                    'details' => [
                        'existing_ram_count' => $ramCount,
                        'motherboard_slots' => $motherboardMemorySlots
                    ],
                    'resolution' => "Remove " . ($ramCount - $motherboardMemorySlots) . " RAM module(s) OR choose motherboard with $ramCount slots"
                ];
            }

            // RAM total capacity check
            if ($motherboardMaxMemoryCapacity && $ramTotalCapacity > $motherboardMaxMemoryCapacity) {
                $errors[] = [
                    'type' => 'ram_total_capacity_exceeded',
                    'severity' => 'critical',
                    'message' => "Existing RAM total {$ramTotalCapacity}GB exceeds motherboard maximum {$motherboardMaxMemoryCapacity}GB",
                    'details' => [
                        'existing_ram_total' => $ramTotalCapacity . 'GB',
                        'motherboard_max' => $motherboardMaxMemoryCapacity . 'GB',
                        'excess' => ($ramTotalCapacity - $motherboardMaxMemoryCapacity) . 'GB'
                    ],
                    'resolution' => "Remove RAM to reduce total OR choose motherboard with higher capacity"
                ];
            }

            // RAM frequency check
            if (!empty($ramFrequencies)) {
                $maxRamFreq = max($ramFrequencies);
                if ($motherboardMaxMemoryFreq && $maxRamFreq > $motherboardMaxMemoryFreq) {
                    $reduction = round((1 - ($motherboardMaxMemoryFreq / $maxRamFreq)) * 100, 1);
                    $warnings[] = [
                        'type' => 'ram_frequency_downgrade',
                        'severity' => 'medium',
                        'message' => "Existing RAM frequency {$maxRamFreq}MHz exceeds motherboard max {$motherboardMaxMemoryFreq}MHz. RAM will downclock",
                        'performance_impact' => "~{$reduction}% performance reduction"
                    ];
                }
            }
        }

        // REVERSE CHECKS (Existing PCIe cards → Motherboard)
        $pcieDevices = array_merge($existing['nic'], $existing['pciecard']);
        if (!empty($pcieDevices)) {
            $pcieCount = count($pcieDevices);
            $totalPCIeSlots = is_array($motherboardPCIeSlots) ? count($motherboardPCIeSlots) : 0;

            if ($pcieCount > $totalPCIeSlots) {
                $errors[] = [
                    'type' => 'pcie_slot_count_exceeded',
                    'severity' => 'critical',
                    'message' => "Configuration has $pcieCount PCIe cards, motherboard only has $totalPCIeSlots slots",
                    'details' => [
                        'existing_pcie_count' => $pcieCount,
                        'motherboard_slots' => $totalPCIeSlots,
                        'existing_pcie_devices' => array_column($pcieDevices, 'component_uuid')
                    ],
                    'resolution' => "Remove " . ($pcieCount - $totalPCIeSlots) . " PCIe card(s) OR choose motherboard with $pcieCount slots"
                ];
            }

            // Check PCIe slot size compatibility
            foreach ($pcieDevices as $device) {
                $deviceSpecs = $this->getComponentFromInventory($device['component_type'], $device['component_uuid']);
                if ($deviceSpecs) {
                    $requiredSlotSize = $this->extractPCIeSlotSize($deviceSpecs);
                    $devicePCIeVersion = $this->extractPCIeVersion($deviceSpecs);

                    // Check if any motherboard slot can accommodate this card
                    $canFit = false;
                    if (is_array($motherboardPCIeSlots)) {
                        foreach ($motherboardPCIeSlots as $slot) {
                            $slotSize = $slot['size'] ?? $slot['lanes'] ?? 16;
                            if ($slotSize >= $requiredSlotSize) {
                                $canFit = true;
                                break;
                            }
                        }
                    }

                    if (!$canFit) {
                        $errors[] = [
                            'type' => 'pcie_slot_size_incompatible',
                            'severity' => 'critical',
                            'message' => "Existing PCIe card requires x$requiredSlotSize slot, motherboard only has smaller slots",
                            'details' => [
                                'device_uuid' => $device['component_uuid'],
                                'required_slot_size' => 'x' . $requiredSlotSize,
                                'available_slots' => $motherboardPCIeSlots
                            ]
                        ];
                    }

                    // PCIe version compatibility
                    if ($devicePCIeVersion && $motherboardPCIeVersion && $devicePCIeVersion > $motherboardPCIeVersion) {
                        $bandwidthReduction = round((1 - ($motherboardPCIeVersion / $devicePCIeVersion)) * 100, 1);
                        $warnings[] = [
                            'type' => 'pcie_version_mismatch',
                            'severity' => 'medium',
                            'message' => "PCIe card supports PCIe $devicePCIeVersion, but motherboard slot is PCIe $motherboardPCIeVersion",
                            'details' => [
                                'device_uuid' => $device['component_uuid'],
                                'device_pcie_version' => $devicePCIeVersion,
                                'motherboard_pcie_version' => $motherboardPCIeVersion
                            ],
                            'performance_impact' => "~{$bandwidthReduction}% bandwidth reduction"
                        ];
                    }
                }
            }
        }

        // REVERSE CHECKS (Existing Chassis → Motherboard)
        if ($existing['chassis']) {
            $chassisSpecs = $this->getChassisSpecs($existing['chassis']['component_uuid']);
            if ($chassisSpecs) {
                $chassisFormFactorSupport = $chassisSpecs['form_factor_support'] ?? ['ATX'];
                if (!in_array($motherboardFormFactor, $chassisFormFactorSupport)) {
                    $warnings[] = [
                        'type' => 'chassis_form_factor_warning',
                        'severity' => 'high',
                        'message' => "Motherboard is $motherboardFormFactor, chassis officially supports " . implode('/', $chassisFormFactorSupport),
                        'recommendation' => 'Verify physical fitment before deployment'
                    ];
                }
            }
        }

        // Add motherboard info
        $info[] = [
            'type' => 'motherboard_specifications',
            'message' => 'Motherboard specifications loaded',
            'specs' => [
                'socket' => $motherboardSocket,
                'max_cpus' => $motherboardMaxCPUs,
                'memory_types' => $motherboardMemoryTypes,
                'memory_slots' => $motherboardMemorySlots,
                'max_memory_capacity' => $motherboardMaxMemoryCapacity . 'GB',
                'max_memory_frequency' => $motherboardMaxMemoryFreq . 'MHz',
                'memory_form_factor' => $motherboardMemoryFormFactor,
                'form_factor' => $motherboardFormFactor,
                'pcie_slots' => $motherboardPCIeSlots,
                'pcie_version' => $motherboardPCIeVersion
            ]
        ];

        // Determine validation status
        $status = empty($errors) ? (empty($warnings) ? 'allowed' : 'allowed_with_warnings') : 'blocked';

        return [
            'validation_status' => $status,
            'critical_errors' => $errors,
            'warnings' => $warnings,
            'info_messages' => $info
        ];
    }

    /**
     * Extract PCIe version from component specs
     */
    private function extractPCIeVersion($componentSpecs) {
        $version = $componentSpecs['pcie_version'] ??
                   $componentSpecs['PCIe_version'] ??
                   $componentSpecs['interface_version'] ??
                   null;

        if ($version && is_string($version)) {
            // Extract numeric value (e.g., "PCIe 4.0" → 4.0)
            preg_match('/(\d+\.?\d*)/', $version, $matches);
            return $matches[1] ?? null;
        }

        return $version;
    }

    // ========================================
    // RAM VALIDATION (COMPREHENSIVE BIDIRECTIONAL)
    // ========================================

    /**
     * Validate RAM addition
     * Bidirectional checks: RAM type, frequency, form factor, ECC, capacity
     */
    private function validateRAMAddition($configUuid, $ramUuid, $existing) {
        $errors = [];
        $warnings = [];
        $info = [];

        // Get RAM specs
        $ramSpecs = $this->getComponentSpecs('ram', $ramUuid);
        if (!$ramSpecs) {
            return [
                'validation_status' => 'blocked',
                'critical_errors' => [[
                    'type' => 'ram_not_found_in_json',
                    'message' => "RAM $ramUuid not found in specifications database"
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }

        // Extract RAM specifications
        $ramType = $ramSpecs['memory_type'] ?? $ramSpecs['type'] ?? null;
        $ramFormFactor = $ramSpecs['form_factor'] ?? null;
        $ramCapacity = $ramSpecs['capacity_gb'] ?? ($ramSpecs['capacity'] ?? 0);
        $ramFrequency = $ramSpecs['frequency_mhz'] ?? ($ramSpecs['frequency'] ?? 0);
        $ramIsECC = ($ramSpecs['ecc'] ?? $ramSpecs['is_ecc'] ?? false);

        // Normalize capacity and frequency
        if (is_string($ramCapacity)) {
            preg_match('/(\d+)/', $ramCapacity, $matches);
            $ramCapacity = $matches[1] ?? 0;
        }
        if (is_string($ramFrequency)) {
            preg_match('/(\d+)/', $ramFrequency, $matches);
            $ramFrequency = $matches[1] ?? 0;
        }

        $ramCapacity = (int)$ramCapacity;
        $ramFrequency = (int)$ramFrequency;

        // FORWARD CHECKS (RAM → Motherboard)
        if ($existing['motherboard']) {
            $motherboardSpecs = $this->getComponentSpecs('motherboard', $existing['motherboard']['component_uuid']);
            if ($motherboardSpecs) {
                $motherboardMemoryTypes = $this->dataUtils->extractMemoryTypes($motherboardSpecs);
                $motherboardMemoryFormFactor = $this->dataUtils->extractMemoryFormFactor($motherboardSpecs);
                $motherboardMemorySlots = $motherboardSpecs['memory_slots'] ?? 4;
                $motherboardMaxMemoryCapacity = $this->dataUtils->extractMaxMemoryCapacity($motherboardSpecs);
                $motherboardMaxMemoryFreq = $this->dataUtils->extractMaxMemoryFrequency($motherboardSpecs);
                $motherboardPerSlotCapacity = $this->dataUtils->extractPerSlotMemoryCapacity($motherboardSpecs);

                // Type compatibility
                if ($ramType && !in_array($ramType, $motherboardMemoryTypes)) {
                    $errors[] = [
                        'type' => 'ram_type_incompatible_motherboard',
                        'severity' => 'critical',
                        'message' => "RAM type $ramType incompatible with motherboard " . implode('/', $motherboardMemoryTypes) . " slots",
                        'resolution' => "Choose " . implode('/', $motherboardMemoryTypes) . " RAM OR replace motherboard with $ramType support"
                    ];
                }

                // Form factor compatibility
                if ($ramFormFactor && $motherboardMemoryFormFactor && $ramFormFactor !== $motherboardMemoryFormFactor) {
                    $errors[] = [
                        'type' => 'ram_form_factor_mismatch',
                        'severity' => 'critical',
                        'message' => "RAM form factor $ramFormFactor incompatible with motherboard $motherboardMemoryFormFactor slots",
                        'resolution' => "Choose $motherboardMemoryFormFactor RAM OR replace motherboard"
                    ];
                }

                // Slot limit check
                $usedSlots = count($existing['ram']);
                if ($usedSlots >= $motherboardMemorySlots) {
                    $errors[] = [
                        'type' => 'ram_slot_limit_exceeded',
                        'severity' => 'critical',
                        'message' => "Motherboard has $motherboardMemorySlots RAM slots, all occupied",
                        'details' => [
                            'used_slots' => $usedSlots,
                            'total_slots' => $motherboardMemorySlots
                        ],
                        'resolution' => "Remove existing RAM OR choose motherboard with more slots"
                    ];
                }

                // Per-slot capacity check
                if ($motherboardPerSlotCapacity && $ramCapacity > $motherboardPerSlotCapacity) {
                    $errors[] = [
                        'type' => 'ram_per_slot_capacity_exceeded',
                        'severity' => 'critical',
                        'message' => "RAM module capacity {$ramCapacity}GB exceeds motherboard per-slot maximum {$motherboardPerSlotCapacity}GB",
                        'resolution' => "Use {$motherboardPerSlotCapacity}GB or smaller modules"
                    ];
                }

                // Total capacity check
                $currentTotal = 0;
                foreach ($existing['ram'] as $ram) {
                    $ramSpec = $this->getComponentSpecs('ram', $ram['component_uuid']);
                    if ($ramSpec) {
                        $cap = $ramSpec['capacity_gb'] ?? ($ramSpec['capacity'] ?? 0);
                        if (is_string($cap)) {
                            preg_match('/(\d+)/', $cap, $matches);
                            $cap = $matches[1] ?? 0;
                        }
                        $currentTotal += (int)$cap * ($ram['quantity'] ?? 1);
                    }
                }
                $newTotal = $currentTotal + $ramCapacity;

                if ($motherboardMaxMemoryCapacity && $newTotal > $motherboardMaxMemoryCapacity) {
                    $errors[] = [
                        'type' => 'ram_total_capacity_exceeded_motherboard',
                        'severity' => 'critical',
                        'message' => "Adding this RAM will exceed motherboard maximum capacity",
                        'details' => [
                            'current_total' => $currentTotal . 'GB',
                            'new_ram' => $ramCapacity . 'GB',
                            'total_if_added' => $newTotal . 'GB',
                            'motherboard_max' => $motherboardMaxMemoryCapacity . 'GB'
                        ],
                        'resolution' => "Remove existing RAM OR choose motherboard with higher capacity"
                    ];
                }

                // Frequency check
                if ($motherboardMaxMemoryFreq && $ramFrequency > $motherboardMaxMemoryFreq) {
                    $reduction = round((1 - ($motherboardMaxMemoryFreq / $ramFrequency)) * 100, 1);
                    $warnings[] = [
                        'type' => 'ram_frequency_downgrade_motherboard',
                        'severity' => 'medium',
                        'message' => "RAM frequency {$ramFrequency}MHz exceeds motherboard max {$motherboardMaxMemoryFreq}MHz. Will operate at {$motherboardMaxMemoryFreq}MHz",
                        'performance_impact' => "~{$reduction}% performance reduction"
                    ];
                }
            }
        }

        // REVERSE CHECKS (RAM → CPU)
        if (!empty($existing['cpu'])) {
            foreach ($existing['cpu'] as $cpu) {
                $cpuSpecs = $this->getComponentSpecs('cpu', $cpu['component_uuid']);
                if ($cpuSpecs) {
                    $cpuMemoryTypes = $this->dataUtils->extractMemoryTypes($cpuSpecs);
                    $cpuMaxMemoryFreq = $this->dataUtils->extractMaxMemoryFrequency($cpuSpecs);
                    $cpuMaxMemoryCapacity = $this->dataUtils->extractMaxMemoryCapacity($cpuSpecs);
                    $cpuECCRequired = $this->dataUtils->extractECCSupport($cpuSpecs) === 'required';

                    // Type compatibility
                    if ($ramType && !in_array($ramType, $cpuMemoryTypes)) {
                        $errors[] = [
                            'type' => 'ram_type_incompatible_cpu',
                            'severity' => 'critical',
                            'message' => "CPU supports " . implode('/', $cpuMemoryTypes) . " only, RAM is $ramType",
                            'resolution' => "Choose " . implode('/', $cpuMemoryTypes) . " RAM OR replace CPU"
                        ];
                    }

                    // ECC requirement
                    if ($cpuECCRequired && !$ramIsECC) {
                        $errors[] = [
                            'type' => 'ecc_required_by_cpu',
                            'severity' => 'critical',
                            'message' => "CPU requires ECC RAM, new RAM is non-ECC",
                            'resolution' => "Choose ECC RAM OR replace CPU without ECC requirement"
                        ];
                    }

                    // Total capacity check
                    $currentTotal = 0;
                    foreach ($existing['ram'] as $ram) {
                        $ramSpec = $this->getComponentSpecs('ram', $ram['component_uuid']);
                        if ($ramSpec) {
                            $cap = $ramSpec['capacity_gb'] ?? ($ramSpec['capacity'] ?? 0);
                            if (is_string($cap)) {
                                preg_match('/(\d+)/', $cap, $matches);
                                $cap = $matches[1] ?? 0;
                            }
                            $currentTotal += (int)$cap * ($ram['quantity'] ?? 1);
                        }
                    }
                    $newTotal = $currentTotal + $ramCapacity;

                    if ($cpuMaxMemoryCapacity && $newTotal > $cpuMaxMemoryCapacity) {
                        $errors[] = [
                            'type' => 'ram_total_capacity_exceeded_cpu',
                            'severity' => 'critical',
                            'message' => "Adding this RAM will exceed CPU maximum capacity {$cpuMaxMemoryCapacity}GB",
                            'details' => [
                                'current_total' => $currentTotal . 'GB',
                                'new_ram' => $ramCapacity . 'GB',
                                'total_if_added' => $newTotal . 'GB',
                                'cpu_max' => $cpuMaxMemoryCapacity . 'GB'
                            ],
                            'resolution' => "Remove existing RAM OR choose CPU with higher capacity"
                        ];
                    }

                    // Frequency check
                    if ($cpuMaxMemoryFreq && $ramFrequency > $cpuMaxMemoryFreq) {
                        $reduction = round((1 - ($cpuMaxMemoryFreq / $ramFrequency)) * 100, 1);
                        $warnings[] = [
                            'type' => 'ram_frequency_downgrade_cpu',
                            'severity' => 'medium',
                            'message' => "RAM frequency {$ramFrequency}MHz exceeds CPU max {$cpuMaxMemoryFreq}MHz. Will operate at {$cpuMaxMemoryFreq}MHz",
                            'performance_impact' => "~{$reduction}% performance reduction"
                        ];
                    }
                }
            }
        }

        // PEER CHECKS (RAM → Existing RAM)
        if (!empty($existing['ram'])) {
            $existingTypes = [];
            $existingFormFactors = [];
            $existingECC = [];
            $existingFrequencies = [];

            foreach ($existing['ram'] as $ram) {
                $ramSpec = $this->getComponentSpecs('ram', $ram['component_uuid']);
                if ($ramSpec) {
                    $existingTypes[] = $ramSpec['memory_type'] ?? $ramSpec['type'];
                    $existingFormFactors[] = $ramSpec['form_factor'];
                    $existingECC[] = ($ramSpec['ecc'] ?? $ramSpec['is_ecc'] ?? false);

                    $freq = $ramSpec['frequency_mhz'] ?? ($ramSpec['frequency'] ?? 0);
                    if (is_string($freq)) {
                        preg_match('/(\d+)/', $freq, $matches);
                        $freq = $matches[1] ?? 0;
                    }
                    $existingFrequencies[] = (int)$freq;
                }
            }

            // Type mixing check
            $existingTypes = array_filter(array_unique($existingTypes));
            if (!empty($existingTypes) && $ramType && !in_array($ramType, $existingTypes)) {
                $errors[] = [
                    'type' => 'ram_type_mixing',
                    'severity' => 'critical',
                    'message' => "Cannot mix $ramType RAM with existing " . implode('/', $existingTypes) . " RAM",
                    'resolution' => "Choose " . implode('/', $existingTypes) . " RAM to match existing"
                ];
            }

            // Form factor mixing check
            $existingFormFactors = array_filter(array_unique($existingFormFactors));
            if (!empty($existingFormFactors) && $ramFormFactor && !in_array($ramFormFactor, $existingFormFactors)) {
                $errors[] = [
                    'type' => 'ram_form_factor_mixing',
                    'severity' => 'critical',
                    'message' => "Cannot mix $ramFormFactor RAM with existing " . implode('/', $existingFormFactors) . " RAM",
                    'resolution' => "Choose " . implode('/', $existingFormFactors) . " RAM to match existing"
                ];
            }

            // ECC mixing check
            $existingECC = array_unique($existingECC);
            if (count($existingECC) === 1 && $ramIsECC !== $existingECC[0]) {
                $eccType = $ramIsECC ? 'ECC' : 'non-ECC';
                $existingType = $existingECC[0] ? 'ECC' : 'non-ECC';
                $errors[] = [
                    'type' => 'ram_ecc_mixing',
                    'severity' => 'critical',
                    'message' => "Cannot mix $eccType RAM with existing $existingType RAM",
                    'resolution' => "Choose $existingType RAM to match existing"
                ];
            }

            // Frequency mixing warning
            $existingFrequencies = array_filter($existingFrequencies);
            if (!empty($existingFrequencies) && $ramFrequency && !in_array($ramFrequency, $existingFrequencies)) {
                $minFreq = min($existingFrequencies);
                $effectiveFreq = min($ramFrequency, $minFreq);
                $warnings[] = [
                    'type' => 'ram_speed_mixing',
                    'severity' => 'low',
                    'message' => "Configuration has mixed RAM speeds. All will run at lowest speed ({$effectiveFreq}MHz)",
                    'details' => [
                        'new_ram_frequency' => $ramFrequency . 'MHz',
                        'existing_frequencies' => array_map(function($f) { return $f . 'MHz'; }, $existingFrequencies),
                        'effective_frequency' => $effectiveFreq . 'MHz'
                    ]
                ];
            }
        }

        // Cascade frequency limiting (Motherboard + CPU)
        if ($existing['motherboard'] && !empty($existing['cpu'])) {
            $motherboardSpecs = $this->getComponentSpecs('motherboard', $existing['motherboard']['component_uuid']);
            $cpuSpecs = $this->getComponentSpecs('cpu', $existing['cpu'][0]['component_uuid']);

            if ($motherboardSpecs && $cpuSpecs) {
                $motherboardMaxFreq = $this->dataUtils->extractMaxMemoryFrequency($motherboardSpecs);
                $cpuMaxFreq = $this->dataUtils->extractMaxMemoryFrequency($cpuSpecs);

                $effectiveFreq = min(array_filter([$ramFrequency, $motherboardMaxFreq, $cpuMaxFreq]));

                if ($effectiveFreq < $ramFrequency) {
                    $limitingComponent = $this->determineLimitingComponent($ramFrequency, $motherboardMaxFreq, $cpuMaxFreq);
                    $reduction = round((1 - ($effectiveFreq / $ramFrequency)) * 100, 1);

                    $warnings[] = [
                        'type' => 'ram_frequency_cascade_limiting',
                        'severity' => 'medium',
                        'message' => "RAM rated at {$ramFrequency}MHz will run at {$effectiveFreq}MHz due to " . $limitingComponent['type'] . " limitation",
                        'limiting_component' => $limitingComponent,
                        'frequency_cascade' => [
                            'ram_rated' => $ramFrequency . 'MHz',
                            'motherboard_max' => $motherboardMaxFreq . 'MHz',
                            'cpu_max' => $cpuMaxFreq . 'MHz',
                            'effective' => $effectiveFreq . 'MHz'
                        ],
                        'performance_impact' => "~{$reduction}% slower than rated speed"
                    ];
                }
            }
        }

        // Add RAM info
        $info[] = [
            'type' => 'ram_specifications',
            'message' => 'RAM specifications loaded',
            'specs' => [
                'type' => $ramType,
                'form_factor' => $ramFormFactor,
                'capacity' => $ramCapacity . 'GB',
                'frequency' => $ramFrequency . 'MHz',
                'ecc' => $ramIsECC ? 'Yes' : 'No'
            ]
        ];

        // Determine validation status
        $status = empty($errors) ? (empty($warnings) ? 'allowed' : 'allowed_with_warnings') : 'blocked';

        return [
            'validation_status' => $status,
            'critical_errors' => $errors,
            'warnings' => $warnings,
            'info_messages' => $info
        ];
    }

    /**
     * Determine which component is limiting RAM frequency
     */
    private function determineLimitingComponent($ramFreq, $moboMaxFreq, $cpuMaxFreq) {
        if ($cpuMaxFreq && $cpuMaxFreq < $moboMaxFreq && $cpuMaxFreq < $ramFreq) {
            return ['type' => 'cpu', 'max_freq' => $cpuMaxFreq . 'MHz'];
        } elseif ($moboMaxFreq && $moboMaxFreq < $ramFreq) {
            return ['type' => 'motherboard', 'max_freq' => $moboMaxFreq . 'MHz'];
        }
        return ['type' => 'unknown', 'max_freq' => 'unknown'];
    }

    // ========================================
    // STORAGE VALIDATION (CHASSIS-CADDY CHAIN)
    // ========================================

    /**
     * Validate Storage addition
     * Checks: form factor, interface, bay availability, caddy requirements
     */
    /**
     * Validate Storage Addition - NEW JSON-DRIVEN VALIDATION
     * Uses StorageConnectionValidator for comprehensive 10-check validation
     */
    private function validateStorageAddition($configUuid, $storageUuid, $existing) {
        try {
            // Use new StorageConnectionValidator
            $storageValidator = new StorageConnectionValidator($this->pdo);
            $validationResult = $storageValidator->validate($configUuid, $storageUuid, $existing);
        } catch (Exception $e) {
            error_log("FlexibleCompatibilityValidator::validateStorageAddition Error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            error_log("Existing components: " . json_encode($existing));
            // Return error instead of throwing to provide more context
            return [
                'validation_status' => 'error',
                'critical_errors' => [[
                    'type' => 'validation_exception',
                    'severity' => 'critical',
                    'message' => 'Validation error: ' . $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }

        // Convert StorageConnectionValidator result to FlexibleCompatibilityValidator format
        $errors = [];
        $warnings = [];
        $info = [];

        // Map errors
        if (!empty($validationResult['errors']) && is_array($validationResult['errors'])) {
            foreach ($validationResult['errors'] as $error) {
                $errors[] = [
                    'type' => $error['type'],
                    'severity' => 'critical',
                    'message' => $error['message'],
                    'resolution' => $error['resolution'] ?? ''
                ];
            }
        }

        // Map warnings (including recommendations from StorageConnectionValidator)
        if (!empty($validationResult['warnings']) && is_array($validationResult['warnings'])) {
            foreach ($validationResult['warnings'] as $warning) {
                $warningEntry = [
                    'type' => $warning['type'],
                    'severity' => 'medium',
                    'message' => $warning['message'],
                    'recommendation' => $warning['recommendation'] ?? $warning['resolution'] ?? ''
                ];

                // Add recommendation options if available
                if (isset($warning['options']) && is_array($warning['options'])) {
                    $warningEntry['options'] = $warning['options'];
                }

                $warnings[] = $warningEntry;
            }
        }

        // Map info messages
        if (!empty($validationResult['info']) && is_array($validationResult['info'])) {
            foreach ($validationResult['info'] as $infoItem) {
                $info[] = [
                    'type' => $infoItem['type'] ?? 'storage_info',
                    'message' => $infoItem['message']
                ];
            }
        }

        // Add connection path information
        if (isset($validationResult['primary_path']) && $validationResult['primary_path']) {
            $info[] = [
                'type' => 'connection_path',
                'message' => $validationResult['primary_path']['description'],
                'details' => $validationResult['primary_path']['details']
            ];
        } elseif (!empty($validationResult['connection_paths'])) {
            $info[] = [
                'type' => 'connection_paths_available',
                'message' => count($validationResult['connection_paths']) . ' connection path(s) available',
                'paths' => array_map(function($path) {
                    return $path['description'];
                }, $validationResult['connection_paths'])
            ];
        }

        // Determine validation status
        $status = $validationResult['valid'] ?
            (empty($warnings) ? 'allowed' : 'allowed_with_warnings') :
            'blocked';

        return [
            'validation_status' => $status,
            'critical_errors' => $errors,
            'warnings' => $warnings,
            'info_messages' => $info
        ];
    }

    // ========================================
    // OLD STORAGE VALIDATION METHODS - DEPRECATED
    // Now using StorageConnectionValidator instead
    // These methods kept for chassis reverse validation only
    // ========================================

    // ========================================
    // CHASSIS VALIDATION (REVERSE CHECKS)
    // ========================================

    /**
     * Validate Chassis addition
     * Reverse checks against existing motherboard, storage
     */
    private function validateChassisAddition($configUuid, $chassisUuid, $existing) {
        $errors = [];
        $warnings = [];
        $info = [];

        // Check if chassis already exists
        if ($existing['chassis']) {
            return [
                'validation_status' => 'blocked',
                'critical_errors' => [[
                    'type' => 'chassis_already_exists',
                    'severity' => 'critical',
                    'message' => 'Configuration already has a chassis',
                    'existing_chassis' => $existing['chassis']['component_uuid'],
                    'resolution' => 'Remove existing chassis first'
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }

        // Get chassis specs
        $chassisSpecs = $this->getChassisSpecs($chassisUuid);
        if (!$chassisSpecs) {
            return [
                'validation_status' => 'blocked',
                'critical_errors' => [[
                    'type' => 'chassis_not_found',
                    'message' => "Chassis $chassisUuid not found in specifications"
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }

        // Extract chassis specifications
        $driveBays = $chassisSpecs['drive_bays'] ?? [];
        $totalBays = $driveBays['total_bays'] ?? 8;
        $backplane = $chassisSpecs['backplane'] ?? [];
        $formFactorSupport = $chassisSpecs['form_factor_support'] ?? ['ATX'];

        // REVERSE CHECKS (Existing Storage → Chassis)
        if (!empty($existing['storage'])) {
            $storageCount = count($existing['storage']);

            // Bay count check
            if ($storageCount > $totalBays) {
                $errors[] = [
                    'type' => 'storage_count_exceeds_bays',
                    'severity' => 'critical',
                    'message' => "Configuration has $storageCount storage devices, chassis only has $totalBays bays",
                    'resolution' => "Remove " . ($storageCount - $totalBays) . " storage device(s) OR choose chassis with $storageCount bays"
                ];
            }

            // Form factor & interface checks
            foreach ($existing['storage'] as $storage) {
                $storageSpecs = $this->getStorageSpecs($storage['component_uuid']);
                if (!$storageSpecs) {
                    $storageSpecs = $this->getComponentFromInventory('storage', $storage['component_uuid']);
                }

                if ($storageSpecs) {
                    $storageFormFactor = $storageSpecs['form_factor'] ?? '3.5-inch';
                    $storageInterface = $storageSpecs['interface'] ?? 'SATA';

                    // Form factor check
                    $formFactorResult = $this->validateStorageFormFactor($storageFormFactor, $chassisSpecs, $existing['caddy']);
                    if (!$formFactorResult['compatible']) {
                        $errors[] = [
                            'type' => 'existing_storage_incompatible',
                            'severity' => 'critical',
                            'message' => "Existing storage (UUID: {$storage['component_uuid']}) incompatible with chassis",
                            'details' => $formFactorResult,
                            'resolution' => $formFactorResult['resolution']
                        ];
                    } elseif ($formFactorResult['caddy_required'] && !$formFactorResult['caddy_available']) {
                        $errors[] = [
                            'type' => 'existing_storage_needs_caddy',
                            'severity' => 'critical',
                            'message' => $formFactorResult['caddy_message'] . " for existing storage",
                            'resolution' => "Add " . $formFactorResult['required_caddy_type'] . " before adding chassis"
                        ];
                    }

                    // Interface check
                    $interfaceResult = $this->validateStorageInterface($storageInterface, $backplane);
                    if (!$interfaceResult['compatible']) {
                        $errors[] = [
                            'type' => 'existing_storage_interface_incompatible',
                            'severity' => 'critical',
                            'message' => "Existing storage interface $storageInterface not supported by chassis",
                            'resolution' => $interfaceResult['resolution']
                        ];
                    }
                }
            }
        }

        // REVERSE CHECKS (Existing Motherboard → Chassis)
        if ($existing['motherboard']) {
            $motherboardSpecs = $this->getComponentSpecs('motherboard', $existing['motherboard']['component_uuid']);
            if ($motherboardSpecs) {
                $motherboardFormFactor = $motherboardSpecs['form_factor'] ?? 'ATX';

                if (!in_array($motherboardFormFactor, $formFactorSupport)) {
                    $warnings[] = [
                        'type' => 'motherboard_form_factor_warning',
                        'severity' => 'high',
                        'message' => "Motherboard is $motherboardFormFactor, chassis officially supports " . implode('/', $formFactorSupport),
                        'recommendation' => 'Verify physical fitment before deployment'
                    ];
                }
            }
        }

        // Add chassis info
        $info[] = [
            'type' => 'chassis_specifications',
            'message' => 'Chassis specifications loaded',
            'specs' => [
                'drive_bays' => $totalBays,
                'form_factor_support' => $formFactorSupport,
                'backplane' => $backplane
            ]
        ];

        // Determine validation status
        $status = empty($errors) ? (empty($warnings) ? 'allowed' : 'allowed_with_warnings') : 'blocked';

        return [
            'validation_status' => $status,
            'critical_errors' => $errors,
            'warnings' => $warnings,
            'info_messages' => $info
        ];
    }

    // ========================================
    // NIC & PCIe CARD VALIDATION
    // ========================================

    /**
     * Validate NIC addition (uses PCIe slot tracking)
     */
    private function validateNICAddition($configUuid, $nicUuid, $existing) {
        return $this->validatePCIeDeviceAddition($configUuid, 'nic', $nicUuid, $existing);
    }

    /**
     * Validate PCIe Card addition (GPU, RAID, etc.)
     */
    private function validatePCIeCardAddition($configUuid, $cardUuid, $existing) {
        return $this->validatePCIeDeviceAddition($configUuid, 'pciecard', $cardUuid, $existing);
    }

    /**
     * Common PCIe device validation (NIC, GPU, RAID cards, etc.)
     */
    private function validatePCIeDeviceAddition($configUuid, $deviceType, $deviceUuid, $existing) {
        $errors = [];
        $warnings = [];
        $info = [];

        // Get device specs
        $deviceSpecs = $this->getComponentFromInventory($deviceType, $deviceUuid);
        if (!$deviceSpecs) {
            return [
                'validation_status' => 'blocked',
                'critical_errors' => [[
                    'type' => 'pcie_device_not_found',
                    'message' => ucfirst($deviceType) . " $deviceUuid not found in inventory"
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }

        // Extract PCIe specifications
        $deviceSlotSize = $this->extractPCIeSlotSize($deviceSpecs);
        $devicePCIeVersion = $this->extractPCIeVersion($deviceSpecs);

        // FORWARD CHECKS (PCIe Device → Motherboard)
        if ($existing['motherboard']) {
            $motherboardSpecs = $this->getComponentSpecs('motherboard', $existing['motherboard']['component_uuid']);
            if ($motherboardSpecs) {
                $motherboardPCIeSlots = $this->dataUtils->extractPCIeSlots($motherboardSpecs);
                $motherboardPCIeVersion = $this->dataUtils->extractPCIeVersion($motherboardSpecs);

                // Slot availability check
                $usedSlots = count($existing['nic']) + count($existing['pciecard']);
                $totalSlots = is_array($motherboardPCIeSlots) ? count($motherboardPCIeSlots) : 0;

                if ($usedSlots >= $totalSlots) {
                    $errors[] = [
                        'type' => 'pcie_slots_exhausted',
                        'severity' => 'critical',
                        'message' => "Motherboard has $totalSlots PCIe slots, all occupied",
                        'resolution' => "Remove existing PCIe card OR choose motherboard with more slots"
                    ];
                }

                // Slot size compatibility check
                $canFit = false;
                if (is_array($motherboardPCIeSlots)) {
                    foreach ($motherboardPCIeSlots as $slot) {
                        $slotSize = $slot['size'] ?? $slot['lanes'] ?? 16;
                        if ($slotSize >= $deviceSlotSize) {
                            $canFit = true;
                            break;
                        }
                    }
                }

                if (!$canFit) {
                    $errors[] = [
                        'type' => 'pcie_slot_size_incompatible',
                        'severity' => 'critical',
                        'message' => ucfirst($deviceType) . " requires x$deviceSlotSize slot, motherboard only has smaller slots",
                        'resolution' => "Choose motherboard with x$deviceSlotSize or larger slot"
                    ];
                }

                // PCIe version compatibility
                if ($devicePCIeVersion && $motherboardPCIeVersion && $devicePCIeVersion > $motherboardPCIeVersion) {
                    $bandwidthReduction = round((1 - ($motherboardPCIeVersion / $devicePCIeVersion)) * 100, 1);
                    $warnings[] = [
                        'type' => 'pcie_version_mismatch',
                        'severity' => 'medium',
                        'message' => ucfirst($deviceType) . " supports PCIe $devicePCIeVersion, but motherboard slot is PCIe $motherboardPCIeVersion",
                        'performance_impact' => "~{$bandwidthReduction}% bandwidth reduction"
                    ];
                }
            }
        } else {
            // No motherboard
            $info[] = [
                'type' => 'no_motherboard',
                'message' => ucfirst($deviceType) . " requires motherboard with PCIe x$deviceSlotSize or larger slot"
            ];
        }

        // REVERSE CHECKS (PCIe Device → CPU - Lane Budget)
        if (!empty($existing['cpu'])) {
            $cpuSpecs = $this->getComponentSpecs('cpu', $existing['cpu'][0]['component_uuid']);
            if ($cpuSpecs) {
                $cpuPCIeLanes = $this->dataUtils->extractPCIeLanes($cpuSpecs);

                if ($cpuPCIeLanes) {
                    // Calculate total lane usage
                    $usedLanes = 0;
                    foreach (array_merge($existing['nic'], $existing['pciecard']) as $device) {
                        $specs = $this->getComponentFromInventory($device['component_type'], $device['component_uuid']);
                        if ($specs) {
                            $usedLanes += $this->extractPCIeSlotSize($specs);
                        }
                    }

                    $totalLanesIfAdded = $usedLanes + $deviceSlotSize;

                    if ($totalLanesIfAdded > $cpuPCIeLanes) {
                        $errors[] = [
                            'type' => 'pcie_lane_budget_exceeded',
                            'severity' => 'critical',
                            'message' => "Adding this " . $deviceType . " will exceed CPU PCIe lane budget",
                            'details' => [
                                'current_lanes_used' => $usedLanes,
                                'new_device_lanes' => $deviceSlotSize,
                                'total_if_added' => $totalLanesIfAdded,
                                'cpu_provides' => $cpuPCIeLanes,
                                'deficit' => $totalLanesIfAdded - $cpuPCIeLanes
                            ],
                            'resolution' => "Remove existing PCIe device OR choose CPU with more lanes"
                        ];
                    } elseif ($totalLanesIfAdded === $cpuPCIeLanes) {
                        $warnings[] = [
                            'type' => 'pcie_lane_budget_at_max',
                            'severity' => 'low',
                            'message' => "CPU PCIe lane budget will be fully utilized ({$cpuPCIeLanes} lanes)",
                            'recommendation' => 'No more PCIe devices can be added'
                        ];
                    }
                }
            }
        }

        // Add device info
        $info[] = [
            'type' => 'pcie_device_specifications',
            'message' => ucfirst($deviceType) . ' specifications loaded',
            'specs' => [
                'slot_size' => 'x' . $deviceSlotSize,
                'pcie_version' => $devicePCIeVersion ?? 'Unknown'
            ]
        ];

        // Determine validation status
        $status = empty($errors) ? (empty($warnings) ? 'allowed' : 'allowed_with_warnings') : 'blocked';

        return [
            'validation_status' => $status,
            'critical_errors' => $errors,
            'warnings' => $warnings,
            'info_messages' => $info
        ];
    }

    // ========================================
    // CADDY VALIDATION
    // ========================================

    /**
     * Validate Caddy addition
     */
    private function validateCaddyAddition($configUuid, $caddyUuid, $existing) {
        $errors = [];
        $warnings = [];
        $info = [];

        // Get caddy specs
        $caddySpecs = $this->getComponentFromInventory('caddy', $caddyUuid);
        if (!$caddySpecs) {
            return [
                'validation_status' => 'blocked',
                'critical_errors' => [[
                    'type' => 'caddy_not_found',
                    'message' => "Caddy $caddyUuid not found in inventory"
                ]],
                'warnings' => [],
                'info_messages' => []
            ];
        }

        // Extract caddy type (2.5-inch, 3.5-inch, M.2, etc.)
        $caddyType = $caddySpecs['type'] ?? $caddySpecs['caddy_type'] ?? 'Unknown';

        // Check if caddy matches existing storage
        if (!empty($existing['storage'])) {
            $matchingStorage = false;
            foreach ($existing['storage'] as $storage) {
                $storageSpecs = $this->getStorageSpecs($storage['component_uuid']);
                if (!$storageSpecs) {
                    $storageSpecs = $this->getComponentFromInventory('storage', $storage['component_uuid']);
                }

                if ($storageSpecs) {
                    $storageFormFactor = $storageSpecs['form_factor'] ?? '3.5-inch';

                    // Check if caddy matches storage form factor
                    if ((strpos(strtolower($caddyType), '2.5') !== false && strpos($storageFormFactor, '2.5') !== false) ||
                        (strpos(strtolower($caddyType), '3.5') !== false && strpos($storageFormFactor, '3.5') !== false)) {
                        $matchingStorage = true;
                        break;
                    }
                }
            }

            if (!$matchingStorage) {
                $warnings[] = [
                    'type' => 'caddy_no_matching_storage',
                    'severity' => 'low',
                    'message' => "$caddyType caddy added, but no matching storage in configuration",
                    'recommendation' => 'Add compatible storage to utilize this caddy'
                ];
            }
        } else {
            // No storage yet
            $info[] = [
                'type' => 'caddy_added_proactively',
                'message' => "$caddyType adapter added for future storage"
            ];
        }

        // Add caddy info
        $info[] = [
            'type' => 'caddy_specifications',
            'message' => 'Caddy specifications loaded',
            'specs' => [
                'type' => $caddyType
            ]
        ];

        // Caddies are passive adapters - always allow
        return [
            'validation_status' => empty($warnings) ? 'allowed' : 'allowed_with_warnings',
            'critical_errors' => [],
            'warnings' => $warnings,
            'info_messages' => $info
        ];
    }
}
?>

