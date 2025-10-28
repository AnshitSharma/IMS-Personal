<?php
/**
 * Infrastructure Management System - Expansion Slot Tracker
 * File: includes/models/ExpansionSlotTracker.php
 *
 * Tracks PCIe and Riser slot availability and assignments for server configurations
 * Calculates slot usage on-demand from server_configuration_components table
 */

require_once __DIR__ . '/DataExtractionUtilities.php';
require_once __DIR__ . '/ComponentDataService.php';

class ExpansionSlotTracker {

    private $pdo;
    private $dataExtractor;
    private $componentDataService;

    // PCIe backward compatibility matrix
    private $slotCompatibility = [
        'x1' => ['x1', 'x4', 'x8', 'x16'],
        'x4' => ['x4', 'x8', 'x16'],
        'x8' => ['x8', 'x16'],
        'x16' => ['x16']
    ];

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->dataExtractor = new DataExtractionUtilities();
        $this->componentDataService = ComponentDataService::getInstance();
    }

    /**
     * Get comprehensive PCIe slot availability for a server configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Slot availability details
     */
    public function getSlotAvailability($configUuid) {
        try {
            // Step 1: Get motherboard from configuration
            $motherboard = $this->getMotherboardFromConfig($configUuid);

            if (!$motherboard) {
                return [
                    'success' => false,
                    'error' => 'No motherboard found in configuration',
                    'total_slots' => [],
                    'used_slots' => [],
                    'available_slots' => []
                ];
            }

            // Step 2: Load motherboard PCIe slots from JSON
            $totalSlots = $this->loadMotherboardPCIeSlots($motherboard['component_uuid']);

            if (!$totalSlots['success']) {
                return [
                    'success' => false,
                    'error' => $totalSlots['error'],
                    'total_slots' => [],
                    'used_slots' => [],
                    'available_slots' => []
                ];
            }

            // Step 3: Get used PCIe slots from database
            $usedSlots = $this->getUsedPCIeSlots($configUuid);

            // Step 4: Calculate available slots
            $availableSlots = $this->calculateAvailableSlots($totalSlots['slots'], $usedSlots);

            return [
                'success' => true,
                'total_slots' => $totalSlots['slots'],
                'used_slots' => $usedSlots,
                'available_slots' => $availableSlots,
                'motherboard_uuid' => $motherboard['component_uuid']
            ];

        } catch (Exception $e) {
            error_log("ExpansionSlotTracker::getSlotAvailability error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to get slot availability: ' . $e->getMessage(),
                'total_slots' => [],
                'used_slots' => [],
                'available_slots' => []
            ];
        }
    }

    /**
     * Get comprehensive Riser slot availability for a server configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Riser slot availability details
     */
    public function getRiserSlotAvailability($configUuid) {
        try {
            // Step 1: Get motherboard from configuration
            $motherboard = $this->getMotherboardFromConfig($configUuid);

            if (!$motherboard) {
                return [
                    'success' => false,
                    'error' => 'No motherboard found in configuration',
                    'total_slots' => [],
                    'used_slots' => [],
                    'available_slots' => []
                ];
            }

            // Step 2: Load motherboard riser slots from JSON
            $totalRiserSlots = $this->loadMotherboardRiserSlots($motherboard['component_uuid']);

            if (!$totalRiserSlots['success']) {
                return [
                    'success' => false,
                    'error' => $totalRiserSlots['error'],
                    'total_slots' => [],
                    'used_slots' => [],
                    'available_slots' => []
                ];
            }

            // Step 3: Get used riser slots from database
            $usedRiserSlots = $this->getUsedRiserSlots($configUuid);

            // Step 4: Calculate available riser slots
            $availableRiserSlots = $this->calculateAvailableRiserSlots($totalRiserSlots['slots'], $usedRiserSlots);

            return [
                'success' => true,
                'total_slots' => $totalRiserSlots['slots'],
                'used_slots' => $usedRiserSlots,
                'available_slots' => $availableRiserSlots,
                'motherboard_uuid' => $motherboard['component_uuid']
            ];

        } catch (Exception $e) {
            error_log("ExpansionSlotTracker::getRiserSlotAvailability error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to get riser slot availability: ' . $e->getMessage(),
                'total_slots' => [],
                'used_slots' => [],
                'available_slots' => []
            ];
        }
    }

    /**
     * Check if a PCIe card can fit in available slots
     *
     * @param string $configUuid Server configuration UUID
     * @param string $cardSlotSize Required slot size (x1, x4, x8, x16)
     * @return bool True if card can fit
     */
    public function canFitCard($configUuid, $cardSlotSize) {
        $availability = $this->getSlotAvailability($configUuid);

        if (!$availability['success']) {
            return false;
        }

        // Get compatible slot sizes (backward compatibility)
        $compatibleSlots = $this->slotCompatibility[$cardSlotSize] ?? [];

        // Check if any compatible slot is available
        foreach ($compatibleSlots as $slotSize) {
            if (!empty($availability['available_slots'][$slotSize])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a riser can fit in available riser slots
     *
     * @param string $configUuid Server configuration UUID
     * @return bool True if riser can fit
     */
    public function canFitRiser($configUuid) {
        $availability = $this->getRiserSlotAvailability($configUuid);

        if (!$availability['success']) {
            return false;
        }

        // Check if any riser slot is available
        return !empty($availability['available_slots']);
    }

    /**
     * Assign optimal PCIe slot to a new component
     * Uses smallest compatible slot first (x4 card prefers x4 over x16)
     *
     * @param string $configUuid Server configuration UUID
     * @param string $cardSlotSize Required slot size
     * @return string|null Assigned slot ID or null if no slots available
     */
    public function assignSlot($configUuid, $cardSlotSize) {
        $availability = $this->getSlotAvailability($configUuid);

        if (!$availability['success']) {
            return null;
        }

        // Get compatible slot sizes in preference order
        $compatibleSlots = $this->slotCompatibility[$cardSlotSize] ?? [];

        // Try each compatible slot size (smallest first)
        foreach ($compatibleSlots as $slotSize) {
            $availableSlots = $availability['available_slots'][$slotSize] ?? [];

            if (!empty($availableSlots)) {
                // Return first available slot of this size
                return $availableSlots[0];
            }
        }

        return null; // No compatible slots available
    }

    /**
     * Assign riser slot to a new riser card (legacy method - uses first available)
     * Now works with grouped slot format
     *
     * @param string $configUuid Server configuration UUID
     * @return string|null Assigned riser slot ID or null if no slots available
     */
    public function assignRiserSlot($configUuid) {
        $availability = $this->getRiserSlotAvailability($configUuid);

        if (!$availability['success']) {
            return null;
        }

        // available_slots is now grouped by type: ['x16' => [...], 'x8' => [...]]
        // Return first available slot from any type
        foreach ($availability['available_slots'] as $slotType => $slotIds) {
            if (!empty($slotIds)) {
                return $slotIds[0]; // Return first available slot ID
            }
        }

        return null; // No riser slots available
    }

    /**
     * Assign riser slot to a new riser card based on required slot size
     * Uses slot compatibility matrix: x8 riser can fit in x8/x16, x16 riser needs x16
     * Prefers exact match, then smallest compatible larger slot
     *
     * @param string $configUuid Server configuration UUID
     * @param string $requiredSlotSize Required slot size (x8, x16, x4)
     * @return string|null Assigned slot ID or null if no compatible slots available
     */
    public function assignRiserSlotBySize($configUuid, $requiredSlotSize) {
        $availability = $this->getRiserSlotAvailability($configUuid);

        if (!$availability['success']) {
            return null;
        }

        // Normalize slot size (ensure format is "x16", "x8", etc.)
        $requiredSlotSize = strtolower($requiredSlotSize);
        if (!preg_match('/^x\d+$/', $requiredSlotSize)) {
            if (preg_match('/x?(\d+)/i', $requiredSlotSize, $matches)) {
                $requiredSlotSize = 'x' . $matches[1];
            } else {
                return null; // Invalid format
            }
        }

        // Get compatible slot sizes using backward compatibility matrix
        $compatibleSlots = $this->slotCompatibility[$requiredSlotSize] ?? [];

        if (empty($compatibleSlots)) {
            // No compatibility defined - try exact match only
            $compatibleSlots = [$requiredSlotSize];
        }

        // Try each compatible slot size (smallest first for optimal utilization)
        foreach ($compatibleSlots as $slotSize) {
            $availableSlots = $availability['available_slots'][$slotSize] ?? [];

            if (!empty($availableSlots)) {
                // Return first available slot of this size
                return $availableSlots[0];
            }
        }

        return null; // No compatible slots available
    }

    /**
     * Check if riser card can fit in available riser slots
     *
     * @param string $configUuid Server configuration UUID
     * @param string $riserSlotSize Required slot size (x8, x16)
     * @return bool True if compatible riser slot available
     */
    public function canFitRiserBySize($configUuid, $riserSlotSize) {
        return $this->assignRiserSlotBySize($configUuid, $riserSlotSize) !== null;
    }

    /**
     * Get all PCIe slot assignments for a configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Mapping of slot_id => component_uuid
     */
    public function getAllSlotAssignments($configUuid) {
        return $this->getUsedPCIeSlots($configUuid);
    }

    /**
     * Get all riser slot assignments for a configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Mapping of slot_id => component_uuid
     */
    public function getAllRiserSlotAssignments($configUuid) {
        return $this->getUsedRiserSlots($configUuid);
    }

    /**
     * Validate all PCIe slot assignments in a configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Validation results
     */
    public function validateAllSlots($configUuid) {
        $errors = [];
        $warnings = [];

        try {
            // Check 1: Get motherboard
            $motherboard = $this->getMotherboardFromConfig($configUuid);

            // Check if PCIe components exist (excluding risers)
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM server_configuration_components
                WHERE config_uuid = ?
                AND component_type IN ('pciecard', 'hbacard', 'nic')
                AND (slot_position IS NULL OR slot_position LIKE 'pcie_%')
            ");
            $stmt->execute([$configUuid]);
            $pcieCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            if (!$motherboard && $pcieCount > 0) {
                $errors[] = "Configuration has $pcieCount PCIe cards/NICs but no motherboard";
                return [
                    'valid' => false,
                    'errors' => $errors,
                    'warnings' => ["Add motherboard to enable PCIe slot validation"],
                    'assignments' => []
                ];
            }

            if (!$motherboard) {
                // No motherboard and no PCIe components - valid
                return [
                    'valid' => true,
                    'errors' => [],
                    'warnings' => [],
                    'assignments' => []
                ];
            }

            // Check 2: Get slot availability
            $availability = $this->getSlotAvailability($configUuid);

            if (!$availability['success']) {
                $errors[] = $availability['error'];
                return [
                    'valid' => false,
                    'errors' => $errors,
                    'warnings' => $warnings,
                    'assignments' => []
                ];
            }

            // Check 3: Count total slots vs used slots
            $totalSlotCount = $this->countTotalSlots($availability['total_slots']);
            $usedSlotCount = count($availability['used_slots']);

            if ($usedSlotCount > $totalSlotCount) {
                $errors[] = "Too many PCIe components: $usedSlotCount assigned but only $totalSlotCount slots available";
            }

            // Check 4: Validate each component fits in assigned slot
            foreach ($availability['used_slots'] as $slotId => $componentUuid) {
                $validation = $this->validateSlotAssignment($slotId, $componentUuid);

                if (!$validation['valid']) {
                    $errors[] = $validation['error'];
                } else if (!empty($validation['warning'])) {
                    $warnings[] = $validation['warning'];
                }
            }

            // Check 5: Duplicate slot assignments (data integrity check)
            $slotCounts = array_count_values(array_keys($availability['used_slots']));
            foreach ($slotCounts as $slotId => $count) {
                if ($count > 1) {
                    $errors[] = "Data corruption: Slot $slotId has multiple components assigned";
                }
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'assignments' => $availability['used_slots'],
                'total_slots' => $totalSlotCount,
                'used_slots' => $usedSlotCount,
                'available_slots' => $totalSlotCount - $usedSlotCount
            ];

        } catch (Exception $e) {
            error_log("ExpansionSlotTracker::validateAllSlots error: " . $e->getMessage());
            return [
                'valid' => false,
                'errors' => ['Validation error: ' . $e->getMessage()],
                'warnings' => [],
                'assignments' => []
            ];
        }
    }

    /**
     * Validate all riser slot assignments in a configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array Validation results
     */
    public function validateAllRiserSlots($configUuid) {
        $errors = [];
        $warnings = [];

        try {
            // Check 1: Get motherboard
            $motherboard = $this->getMotherboardFromConfig($configUuid);

            // Check if riser components exist
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count
                FROM server_configuration_components
                WHERE config_uuid = ?
                AND component_type = 'pciecard'
                AND slot_position LIKE 'riser_%'
            ");
            $stmt->execute([$configUuid]);
            $riserCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            if (!$motherboard && $riserCount > 0) {
                $errors[] = "Configuration has $riserCount risers but no motherboard";
                return [
                    'valid' => false,
                    'errors' => $errors,
                    'warnings' => ["Add motherboard to enable riser slot validation"],
                    'assignments' => []
                ];
            }

            if (!$motherboard) {
                // No motherboard and no risers - valid
                return [
                    'valid' => true,
                    'errors' => [],
                    'warnings' => [],
                    'assignments' => []
                ];
            }

            // Check 2: Get riser slot availability
            $availability = $this->getRiserSlotAvailability($configUuid);

            if (!$availability['success']) {
                // If no riser slots defined but risers exist, that's an error
                if ($riserCount > 0) {
                    $errors[] = "Motherboard does not support risers, but $riserCount risers are installed";
                }
                return [
                    'valid' => $riserCount === 0,
                    'errors' => $errors,
                    'warnings' => $warnings,
                    'assignments' => []
                ];
            }

            // Check 3: Count total riser slots vs used slots
            $totalRiserSlots = count($availability['total_slots']);
            $usedRiserSlots = count($availability['used_slots']);

            if ($usedRiserSlots > $totalRiserSlots) {
                $errors[] = "Too many risers: $usedRiserSlots assigned but only $totalRiserSlots riser slots available";
            }

            // Check 4: Validate each riser is assigned to riser slot
            foreach ($availability['used_slots'] as $slotId => $componentUuid) {
                // Verify component is actually a riser
                $subtype = $this->getComponentSubtype($componentUuid);
                if ($subtype !== 'Riser Card') {
                    $errors[] = "Component $componentUuid in riser slot $slotId is not a riser card (subtype: $subtype)";
                }
            }

            // Check 5: Verify no risers in PCIe slots
            $stmt = $this->pdo->prepare("
                SELECT component_uuid, slot_position
                FROM server_configuration_components
                WHERE config_uuid = ?
                AND component_type = 'pciecard'
                AND slot_position LIKE 'pcie_%'
            ");
            $stmt->execute([$configUuid]);
            $pcieSlotComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($pcieSlotComponents as $component) {
                $subtype = $this->getComponentSubtype($component['component_uuid']);
                if ($subtype === 'Riser Card') {
                    $errors[] = "Riser card {$component['component_uuid']} incorrectly assigned to PCIe slot {$component['slot_position']}. Risers must use riser slots.";
                }
            }

            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'assignments' => $availability['used_slots'],
                'total_riser_slots' => $totalRiserSlots,
                'used_riser_slots' => $usedRiserSlots,
                'available_riser_slots' => $totalRiserSlots - $usedRiserSlots
            ];

        } catch (Exception $e) {
            error_log("ExpansionSlotTracker::validateAllRiserSlots error: " . $e->getMessage());
            return [
                'valid' => false,
                'errors' => ['Validation error: ' . $e->getMessage()],
                'warnings' => [],
                'assignments' => []
            ];
        }
    }

    /**
     * Get component subtype from JSON specifications
     *
     * @param string $componentUuid Component UUID
     * @return string|null Component subtype (e.g., 'Riser Card', 'HBA Card', 'NVMe Adaptor')
     */
    private function getComponentSubtype($componentUuid) {
        try {
            // Try pciecard first
            $specs = $this->componentDataService->getComponentSpecifications('pciecard', $componentUuid);
            if ($specs && isset($specs['component_subtype'])) {
                return $specs['component_subtype'];
            }

            // Try hbacard
            $specs = $this->componentDataService->getComponentSpecifications('hbacard', $componentUuid);
            if ($specs && isset($specs['component_subtype'])) {
                return $specs['component_subtype'];
            }

            // Try nic
            $specs = $this->componentDataService->getComponentSpecifications('nic', $componentUuid);
            if ($specs && isset($specs['component_subtype'])) {
                return $specs['component_subtype'];
            }

            return null;

        } catch (Exception $e) {
            error_log("Error getting component subtype for $componentUuid: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get motherboard component from server configuration
     *
     * @param string $configUuid Server configuration UUID
     * @return array|null Motherboard component data
     */
    private function getMotherboardFromConfig($configUuid) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT component_uuid
                FROM server_configuration_components
                WHERE config_uuid = ?
                AND component_type = 'motherboard'
                LIMIT 1
            ");
            $stmt->execute([$configUuid]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result ?: null;

        } catch (Exception $e) {
            error_log("Error getting motherboard: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Load PCIe slots from motherboard JSON specifications
     *
     * @param string $motherboardUuid Motherboard UUID
     * @return array Total slots configuration
     */
    private function loadMotherboardPCIeSlots($motherboardUuid) {
        try {
            // Use ComponentDataService to get motherboard specs
            $mbSpecs = $this->componentDataService->getComponentSpecifications('motherboard', $motherboardUuid);

            if (!$mbSpecs) {
                return [
                    'success' => false,
                    'error' => 'Motherboard specifications not found in JSON',
                    'slots' => []
                ];
            }

            // Extract PCIe slots from expansion_slots
            $pcieSlots = $mbSpecs['expansion_slots']['pcie_slots'] ?? [];

            if (empty($pcieSlots)) {
                return [
                    'success' => false,
                    'error' => 'No PCIe slots defined in motherboard specifications',
                    'slots' => []
                ];
            }

            // Convert JSON structure to slot ID array
            $slots = [];

            foreach ($pcieSlots as $slotConfig) {
                $slotType = $slotConfig['type'] ?? '';
                $count = $slotConfig['count'] ?? 0;

                // Extract slot size from type (e.g., "PCIe 5.0 x16" → "x16")
                if (preg_match('/x(\d+)/i', $slotType, $matches)) {
                    $slotSize = 'x' . $matches[1];

                    // Generate slot IDs
                    if (!isset($slots[$slotSize])) {
                        $slots[$slotSize] = [];
                    }

                    $startIndex = count($slots[$slotSize]) + 1;
                    for ($i = 0; $i < $count; $i++) {
                        $slots[$slotSize][] = "pcie_{$slotSize}_slot_" . ($startIndex + $i);
                    }
                }
            }

            return [
                'success' => true,
                'slots' => $slots
            ];

        } catch (Exception $e) {
            error_log("Error loading motherboard PCIe slots: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to load motherboard PCIe slots: ' . $e->getMessage(),
                'slots' => []
            ];
        }
    }

    /**
     * Load riser slots from motherboard JSON specifications
     * Returns slots grouped by type (x16, x8, x4) similar to PCIe slots
     *
     * @param string $motherboardUuid Motherboard UUID
     * @return array Total riser slots configuration grouped by slot type
     */
    private function loadMotherboardRiserSlots($motherboardUuid) {
        try {
            // Use ComponentDataService to get motherboard specs
            $mbSpecs = $this->componentDataService->getComponentSpecifications('motherboard', $motherboardUuid);

            if (!$mbSpecs) {
                return [
                    'success' => false,
                    'error' => 'Motherboard specifications not found in JSON',
                    'slots' => []
                ];
            }

            // Check for riser_slots in expansion_slots
            $riserSlots = $mbSpecs['expansion_slots']['riser_slots'] ?? null;

            // If no riser_slots defined, check legacy riser_compatibility.max_risers
            if ($riserSlots === null) {
                $maxRisers = $mbSpecs['expansion_slots']['riser_compatibility']['max_risers'] ?? 0;

                if ($maxRisers > 0) {
                    // Generate riser slots from max_risers count (assume x16 for legacy)
                    $slots = ['x16' => []];
                    for ($i = 1; $i <= $maxRisers; $i++) {
                        $slots['x16'][] = "riser_x16_slot_$i";
                    }

                    return [
                        'success' => true,
                        'slots' => $slots
                    ];
                }

                return [
                    'success' => false,
                    'error' => 'No riser slots defined in motherboard specifications',
                    'slots' => []
                ];
            }

            // Process riser_slots array with size information
            // Group by slot type similar to PCIe slots
            $slots = [];

            foreach ($riserSlots as $slotConfig) {
                $count = $slotConfig['count'] ?? 1;
                $slotType = $slotConfig['type'] ?? 'PCIe x16 Riser';

                // Extract slot size from type (e.g., "PCIe 5.0 x16 Riser" → "x16")
                $slotSize = 'x16'; // Default
                if (preg_match('/x(\d+)/i', $slotType, $matches)) {
                    $slotSize = 'x' . $matches[1];
                }

                // Initialize array for this slot size if not exists
                if (!isset($slots[$slotSize])) {
                    $slots[$slotSize] = [];
                }

                // Generate slot IDs for this type
                $startIndex = count($slots[$slotSize]) + 1;
                for ($i = 0; $i < $count; $i++) {
                    $slots[$slotSize][] = "riser_{$slotSize}_slot_" . ($startIndex + $i);
                }
            }

            if (empty($slots)) {
                return [
                    'success' => false,
                    'error' => 'No riser slots defined in motherboard specifications',
                    'slots' => []
                ];
            }

            return [
                'success' => true,
                'slots' => $slots
            ];

        } catch (Exception $e) {
            error_log("Error loading motherboard riser slots: " . $e->getMessage());
            return [
                'success' => false,
                'error' => 'Failed to load motherboard riser slots: ' . $e->getMessage(),
                'slots' => []
            ];
        }
    }

    /**
     * Get used PCIe slots from database (excludes risers)
     *
     * @param string $configUuid Server configuration UUID
     * @return array Mapping of slot_id => component_uuid
     */
    private function getUsedPCIeSlots($configUuid) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT component_uuid, slot_position
                FROM server_configuration_components
                WHERE config_uuid = ?
                AND component_type IN ('pciecard', 'hbacard', 'nic')
                AND slot_position IS NOT NULL
                AND slot_position LIKE 'pcie_%'
            ");
            $stmt->execute([$configUuid]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $usedSlots = [];
            foreach ($results as $row) {
                $usedSlots[$row['slot_position']] = $row['component_uuid'];
            }

            return $usedSlots;

        } catch (Exception $e) {
            error_log("Error getting used PCIe slots: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get used riser slots from database
     *
     * @param string $configUuid Server configuration UUID
     * @return array Mapping of slot_id => component_uuid
     */
    private function getUsedRiserSlots($configUuid) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT component_uuid, slot_position
                FROM server_configuration_components
                WHERE config_uuid = ?
                AND component_type = 'pciecard'
                AND slot_position IS NOT NULL
                AND slot_position LIKE 'riser_%'
            ");
            $stmt->execute([$configUuid]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $usedSlots = [];
            foreach ($results as $row) {
                $usedSlots[$row['slot_position']] = $row['component_uuid'];
            }

            return $usedSlots;

        } catch (Exception $e) {
            error_log("Error getting used riser slots: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Calculate available slots by removing used slots from total
     *
     * @param array $totalSlots Total slots by size
     * @param array $usedSlots Used slot IDs
     * @return array Available slots by size
     */
    private function calculateAvailableSlots($totalSlots, $usedSlots) {
        $availableSlots = [];

        foreach ($totalSlots as $slotSize => $slotIds) {
            $availableSlots[$slotSize] = array_values(
                array_diff($slotIds, array_keys($usedSlots))
            );
        }

        return $availableSlots;
    }

    /**
     * Calculate available riser slots by removing used slots from total
     * Handles grouped slot format (by slot type: x16, x8, x4)
     *
     * @param array $totalRiserSlots Total riser slots grouped by size
     * @param array $usedRiserSlots Used riser slot IDs mapping
     * @return array Available riser slots grouped by size
     */
    private function calculateAvailableRiserSlots($totalRiserSlots, $usedRiserSlots) {
        $availableSlots = [];

        foreach ($totalRiserSlots as $slotSize => $slotIds) {
            $availableSlots[$slotSize] = array_values(
                array_diff($slotIds, array_keys($usedRiserSlots))
            );
        }

        return $availableSlots;
    }

    /**
     * Count total number of slots across all sizes
     *
     * @param array $totalSlots Total slots by size
     * @return int Total slot count
     */
    private function countTotalSlots($totalSlots) {
        $count = 0;
        foreach ($totalSlots as $slotIds) {
            $count += count($slotIds);
        }
        return $count;
    }

    /**
     * Validate a single PCIe slot assignment
     *
     * @param string $slotId Slot identifier
     * @param string $componentUuid Component UUID
     * @return array Validation result
     */
    private function validateSlotAssignment($slotId, $componentUuid) {
        try {
            // Extract slot size from slot ID (e.g., "pcie_x16_slot_1" → "x16")
            if (preg_match('/pcie_(x\d+)_slot_/', $slotId, $matches)) {
                $slotSize = $matches[1];
            } else {
                return [
                    'valid' => false,
                    'error' => "Invalid slot ID format: $slotId"
                ];
            }

            // Get component type
            $componentType = $this->getComponentTypeByUuid($componentUuid);

            if (!$componentType) {
                return [
                    'valid' => false,
                    'error' => "Component $componentUuid not found in any inventory table"
                ];
            }

            // Get component specs
            $componentSpecs = $this->componentDataService->getComponentSpecifications($componentType, $componentUuid);

            if (!$componentSpecs) {
                return [
                    'valid' => true,
                    'warning' => "Unable to verify component $componentUuid specifications"
                ];
            }

            // Check if this is a riser in a PCIe slot (invalid)
            if (isset($componentSpecs['component_subtype']) && $componentSpecs['component_subtype'] === 'Riser Card') {
                return [
                    'valid' => false,
                    'error' => "Riser card $componentUuid cannot be installed in PCIe slot $slotId. Use riser slots instead."
                ];
            }

            // Extract card slot size
            $cardSize = $this->extractPCIeSlotSize($componentSpecs);

            if (!$cardSize) {
                return [
                    'valid' => true,
                    'warning' => "Unable to determine PCIe slot size for component $componentUuid"
                ];
            }

            // Check backward compatibility
            if (!$this->isPCIeSlotCompatible($cardSize, $slotSize)) {
                return [
                    'valid' => false,
                    'error' => "Component $componentUuid requires $cardSize slot but assigned to $slotSize slot ($slotId)"
                ];
            }

            // Check if using larger slot (acceptable but worth noting)
            if ($cardSize !== $slotSize) {
                return [
                    'valid' => true,
                    'warning' => "Component $componentUuid ($cardSize) installed in larger $slotSize slot ($slotId)"
                ];
            }

            return ['valid' => true];

        } catch (Exception $e) {
            error_log("Error validating slot assignment: " . $e->getMessage());
            return [
                'valid' => false,
                'error' => "Validation error for slot $slotId: " . $e->getMessage()
            ];
        }
    }

    /**
     * Get component type by UUID (search all inventory tables)
     *
     * @param string $uuid Component UUID
     * @return string|null Component type
     */
    private function getComponentTypeByUuid($uuid) {
        $tables = [
            'pciecard' => 'pciecardinventory',
            'hbacard' => 'hbacardinventory',
            'nic' => 'nicinventory'
        ];

        foreach ($tables as $type => $table) {
            try {
                $stmt = $this->pdo->prepare("SELECT UUID FROM $table WHERE UUID = ? LIMIT 1");
                $stmt->execute([$uuid]);
                if ($stmt->fetch()) {
                    return $type;
                }
            } catch (Exception $e) {
                error_log("Error checking $table: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Extract PCIe slot size from component specifications
     *
     * @param array $specs Component specifications
     * @return string|null Slot size (x1, x4, x8, x16)
     */
    private function extractPCIeSlotSize($specs) {
        // Check interface field
        $interface = $specs['interface'] ?? '';

        if (preg_match('/x(\d+)/i', $interface, $matches)) {
            return 'x' . $matches[1];
        }

        // Check slot_type field
        $slotType = $specs['slot_type'] ?? '';

        if (preg_match('/x(\d+)/i', $slotType, $matches)) {
            return 'x' . $matches[1];
        }

        return null;
    }

    /**
     * Check if card can fit in slot (PCIe backward compatibility)
     *
     * @param string $cardSize Card slot requirement (x1, x4, x8, x16)
     * @param string $slotSize Available slot size
     * @return bool True if compatible
     */
    private function isPCIeSlotCompatible($cardSize, $slotSize) {
        $compatibleSlots = $this->slotCompatibility[$cardSize] ?? [];
        return in_array($slotSize, $compatibleSlots);
    }
}
?>
