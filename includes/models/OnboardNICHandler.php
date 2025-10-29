<?php

/**
 * OnboardNICHandler - Manages onboard NICs from motherboards
 *
 * Handles automatic creation, tracking, and removal of onboard NICs
 * that are integrated into motherboards vs separate component NICs.
 */
class OnboardNICHandler {
    private $pdo;
    private $componentDataService;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        require_once __DIR__ . '/ComponentDataService.php';
        $this->componentDataService = ComponentDataService::getInstance();
    }

    /**
     * Extract and add onboard NICs when motherboard is added to config
     *
     * @param string $configUuid Server configuration UUID
     * @param string $motherboardUuid Motherboard component UUID
     * @return array Result with count and NIC details
     */
    public function autoAddOnboardNICs($configUuid, $motherboardUuid) {
        try {
            error_log("=== AUTO-ADDING ONBOARD NICs ===");
            error_log("Config: $configUuid, Motherboard: $motherboardUuid");

            // Load motherboard specifications from JSON
            $mbSpecs = $this->getMotherboardSpecs($motherboardUuid);

            if (!$mbSpecs) {
                error_log("Could not load motherboard specifications");
                return ['count' => 0, 'nics' => [], 'message' => 'Motherboard specs not found'];
            }

            // Check if motherboard has onboard NICs
            if (!isset($mbSpecs['networking']['onboard_nics']) || empty($mbSpecs['networking']['onboard_nics'])) {
                error_log("No onboard NICs found in motherboard specs");
                return ['count' => 0, 'nics' => [], 'message' => 'No onboard NICs in motherboard'];
            }

            $onboardNICs = $mbSpecs['networking']['onboard_nics'];
            $addedNICs = [];

            error_log("Found " . count($onboardNICs) . " onboard NIC(s) in motherboard specs");

            foreach ($onboardNICs as $index => $nicSpec) {
                $nicIndex = $index + 1;
                $syntheticUuid = "onboard-nic-{$motherboardUuid}-{$nicIndex}";

                error_log("Processing onboard NIC #{$nicIndex}: $syntheticUuid");

                // Create descriptive notes
                $notes = sprintf(
                    "Onboard: %s %d-port %s %s",
                    $nicSpec['controller'] ?? 'Unknown Controller',
                    $nicSpec['ports'] ?? 0,
                    $nicSpec['speed'] ?? 'Unknown Speed',
                    $nicSpec['connector'] ?? 'Unknown Connector'
                );

                // Insert into nicinventory with onboard tracking
                $stmt = $this->pdo->prepare("
                    INSERT INTO nicinventory
                    (UUID, SourceType, ParentComponentUUID, OnboardNICIndex, Status, ServerUUID, Notes, SerialNumber, Flag, CreatedAt, UpdatedAt)
                    VALUES (?, 'onboard', ?, ?, 2, ?, ?, 'ONBOARD', 'Onboard', NOW(), NOW())
                ");

                $result = $stmt->execute([
                    $syntheticUuid,
                    $motherboardUuid,
                    $nicIndex,
                    $configUuid,
                    $notes
                ]);

                if (!$result) {
                    error_log("Failed to insert onboard NIC into nicinventory: " . json_encode($stmt->errorInfo()));
                    continue;
                }

                error_log("Inserted onboard NIC into nicinventory");

                // Add to server_configuration_components
                $stmt = $this->pdo->prepare("
                    INSERT INTO server_configuration_components
                    (config_uuid, component_type, component_uuid, quantity, notes, added_at)
                    VALUES (?, 'nic', ?, 1, ?, NOW())
                ");

                $result = $stmt->execute([
                    $configUuid,
                    $syntheticUuid,
                    "Auto-added onboard NIC from motherboard"
                ]);

                if (!$result) {
                    error_log("Failed to add onboard NIC to server_configuration_components: " . json_encode($stmt->errorInfo()));
                    continue;
                }

                error_log("Added onboard NIC to server_configuration_components");

                $addedNICs[] = array_merge($nicSpec, [
                    'uuid' => $syntheticUuid,
                    'source_type' => 'onboard',
                    'parent_motherboard_uuid' => $motherboardUuid,
                    'onboard_index' => $nicIndex
                ]);
            }

            // Update nic_config JSON in server_build_templates
            $this->updateNICConfigJSON($configUuid);

            error_log("Successfully added " . count($addedNICs) . " onboard NIC(s)");

            return [
                'count' => count($addedNICs),
                'nics' => $addedNICs,
                'message' => count($addedNICs) . ' onboard NIC(s) automatically added'
            ];

        } catch (Exception $e) {
            error_log("Error in autoAddOnboardNICs: " . $e->getMessage());
            return [
                'count' => 0,
                'nics' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get motherboard specifications from JSON
     *
     * @param string $motherboardUuid
     * @return array|null Motherboard specs or null if not found
     */
    private function getMotherboardSpecs($motherboardUuid) {
        try {
            $mbSpecs = $this->componentDataService->findComponentByUuid('motherboard', $motherboardUuid);
            return $mbSpecs;
        } catch (Exception $e) {
            error_log("Error loading motherboard specs: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update nic_config JSON column with all NICs in configuration
     *
     * @param string $configUuid
     * @return bool Success status
     */
    public function updateNICConfigJSON($configUuid) {
        try {
            error_log("Updating nic_config JSON for config: $configUuid");

            // Get all NICs from server_configuration_components
            $stmt = $this->pdo->prepare("
                SELECT
                    scc.component_uuid,
                    ni.SourceType,
                    ni.ParentComponentUUID,
                    ni.OnboardNICIndex,
                    ni.SerialNumber,
                    ni.Notes,
                    ni.Status
                FROM server_configuration_components scc
                LEFT JOIN nicinventory ni ON scc.component_uuid = ni.UUID
                WHERE scc.config_uuid = ? AND scc.component_type = 'nic'
            ");
            $stmt->execute([$configUuid]);
            $nics = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $nicConfigData = [
                'nics' => [],
                'summary' => [
                    'total_nics' => 0,
                    'onboard_nics' => 0,
                    'component_nics' => 0
                ],
                'last_updated' => date('Y-m-d H:i:s')
            ];

            foreach ($nics as $nic) {
                $isOnboard = $nic['SourceType'] === 'onboard';

                $nicData = [
                    'uuid' => $nic['component_uuid'],
                    'source_type' => $nic['SourceType'] ?? 'component',
                    'parent_motherboard_uuid' => $nic['ParentComponentUUID'],
                    'onboard_index' => $nic['OnboardNICIndex'],
                    'status' => $nic['Status'] == 2 ? 'in_use' : ($nic['Status'] == 1 ? 'available' : 'failed'),
                    'replaceable' => true
                ];

                // Get specs based on type
                if ($isOnboard) {
                    $nicData['specifications'] = $this->getOnboardNICSpecs(
                        $nic['ParentComponentUUID'],
                        $nic['OnboardNICIndex']
                    );
                    $nicConfigData['summary']['onboard_nics']++;
                } else {
                    $nicData['specifications'] = $this->getComponentNICSpecs($nic['component_uuid']);
                    $nicData['serial_number'] = $nic['SerialNumber'];
                    $nicConfigData['summary']['component_nics']++;
                }

                $nicConfigData['nics'][] = $nicData;
                $nicConfigData['summary']['total_nics']++;
            }

            // Update server_build_templates.nic_config
            $stmt = $this->pdo->prepare("
                UPDATE server_build_templates
                SET nic_config = ?
                WHERE config_uuid = ?
            ");
            $result = $stmt->execute([json_encode($nicConfigData, JSON_PRETTY_PRINT), $configUuid]);

            error_log("nic_config JSON updated: " . ($result ? 'success' : 'failed'));

            return $result;

        } catch (Exception $e) {
            error_log("Error updating nic_config JSON: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get onboard NIC specifications from motherboard JSON
     *
     * @param string $motherboardUuid
     * @param int $nicIndex
     * @return array NIC specifications
     */
    private function getOnboardNICSpecs($motherboardUuid, $nicIndex) {
        $mbSpecs = $this->getMotherboardSpecs($motherboardUuid);

        if (!$mbSpecs || !isset($mbSpecs['networking']['onboard_nics'])) {
            return ['error' => 'Motherboard specs not found'];
        }

        $onboardNICs = $mbSpecs['networking']['onboard_nics'];
        $index = $nicIndex - 1; // Convert to 0-based index

        if (!isset($onboardNICs[$index])) {
            return ['error' => 'Onboard NIC index out of range'];
        }

        return $onboardNICs[$index];
    }

    /**
     * Get component NIC specifications from JSON
     *
     * @param string $nicUuid
     * @return array NIC specifications
     */
    private function getComponentNICSpecs($nicUuid) {
        try {
            $nicSpecs = $this->componentDataService->findComponentByUuid('nic', $nicUuid);

            if (!$nicSpecs) {
                return ['error' => 'NIC specs not found in JSON'];
            }

            return $nicSpecs;

        } catch (Exception $e) {
            error_log("Error loading component NIC specs: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Remove onboard NICs when motherboard is removed from configuration
     *
     * @param string $motherboardUuid
     * @param string $configUuid
     * @return array Result with count of removed NICs
     */
    public function removeOnboardNICs($motherboardUuid, $configUuid) {
        try {
            error_log("=== REMOVING ONBOARD NICs ===");
            error_log("Motherboard: $motherboardUuid, Config: $configUuid");

            // Get onboard NIC UUIDs before deletion for logging
            $stmt = $this->pdo->prepare("
                SELECT UUID FROM nicinventory
                WHERE ParentComponentUUID = ? AND SourceType = 'onboard' AND ServerUUID = ?
            ");
            $stmt->execute([$motherboardUuid, $configUuid]);
            $onboardNICs = $stmt->fetchAll(PDO::FETCH_COLUMN);

            error_log("Found " . count($onboardNICs) . " onboard NIC(s) to remove");

            // Delete from server_configuration_components
            $stmt = $this->pdo->prepare("
                DELETE FROM server_configuration_components
                WHERE config_uuid = ?
                AND component_type = 'nic'
                AND component_uuid IN (
                    SELECT UUID FROM nicinventory
                    WHERE ParentComponentUUID = ? AND SourceType = 'onboard'
                )
            ");
            $stmt->execute([$configUuid, $motherboardUuid]);
            $deletedFromConfig = $stmt->rowCount();

            error_log("Deleted $deletedFromConfig onboard NIC(s) from server_configuration_components");

            // Delete from nicinventory
            $stmt = $this->pdo->prepare("
                DELETE FROM nicinventory
                WHERE ParentComponentUUID = ? AND SourceType = 'onboard' AND ServerUUID = ?
            ");
            $stmt->execute([$motherboardUuid, $configUuid]);
            $deletedFromInventory = $stmt->rowCount();

            error_log("Deleted $deletedFromInventory onboard NIC(s) from nicinventory");

            // Update nic_config JSON
            $this->updateNICConfigJSON($configUuid);

            return [
                'success' => true,
                'removed_count' => count($onboardNICs),
                'removed_uuids' => $onboardNICs,
                'message' => count($onboardNICs) . ' onboard NIC(s) removed'
            ];

        } catch (Exception $e) {
            error_log("Error in removeOnboardNICs: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Replace an onboard NIC with a component NIC
     *
     * @param string $configUuid
     * @param string $onboardNICUuid
     * @param string $componentNICUuid
     * @return array Result of replacement operation
     */
    public function replaceOnboardNIC($configUuid, $onboardNICUuid, $componentNICUuid) {
        try {
            $this->pdo->beginTransaction();

            error_log("=== REPLACING ONBOARD NIC ===");
            error_log("Onboard NIC: $onboardNICUuid -> Component NIC: $componentNICUuid");

            // Verify onboard NIC exists and belongs to this config
            $stmt = $this->pdo->prepare("
                SELECT SourceType, ParentComponentUUID, OnboardNICIndex
                FROM nicinventory
                WHERE UUID = ? AND ServerUUID = ? AND SourceType = 'onboard'
            ");
            $stmt->execute([$onboardNICUuid, $configUuid]);
            $onboardNIC = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$onboardNIC) {
                throw new Exception("Onboard NIC not found or doesn't belong to this configuration");
            }

            // Verify component NIC exists and is available
            $stmt = $this->pdo->prepare("
                SELECT UUID, Status, SerialNumber
                FROM nicinventory
                WHERE UUID = ? AND SourceType = 'component'
            ");
            $stmt->execute([$componentNICUuid]);
            $componentNIC = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$componentNIC) {
                throw new Exception("Component NIC not found in inventory");
            }

            if ($componentNIC['Status'] != 1) {
                throw new Exception("Component NIC is not available (Status: {$componentNIC['Status']})");
            }

            // Remove onboard NIC from server_configuration_components
            $stmt = $this->pdo->prepare("
                DELETE FROM server_configuration_components
                WHERE config_uuid = ? AND component_uuid = ?
            ");
            $stmt->execute([$configUuid, $onboardNICUuid]);

            // Remove onboard NIC from nicinventory
            $stmt = $this->pdo->prepare("
                DELETE FROM nicinventory
                WHERE UUID = ?
            ");
            $stmt->execute([$onboardNICUuid]);

            // Add component NIC to server_configuration_components
            $stmt = $this->pdo->prepare("
                INSERT INTO server_configuration_components
                (config_uuid, component_type, component_uuid, quantity, notes, added_at)
                VALUES (?, 'nic', ?, 1, ?, NOW())
            ");
            $stmt->execute([
                $configUuid,
                $componentNICUuid,
                "Replaced onboard NIC: $onboardNICUuid"
            ]);

            // Update component NIC status to In Use
            $stmt = $this->pdo->prepare("
                UPDATE nicinventory
                SET Status = 2, ServerUUID = ?, UpdatedAt = NOW()
                WHERE UUID = ?
            ");
            $stmt->execute([$configUuid, $componentNICUuid]);

            // Update nic_config JSON
            $this->updateNICConfigJSON($configUuid);

            $this->pdo->commit();

            error_log("Successfully replaced onboard NIC with component NIC");

            return [
                'success' => true,
                'message' => 'Onboard NIC successfully replaced with component NIC',
                'replaced_onboard_nic' => $onboardNICUuid,
                'new_component_nic' => $componentNICUuid
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error in replaceOnboardNIC: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
