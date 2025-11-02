<?php
/**
 * Test script to verify onboard NIC counting fix
 * Tests configuration: 52cf78cd-746d-4599-aa99-490743ad7cff
 */

require_once __DIR__ . '/../../includes/db_config.php';
require_once __DIR__ . '/../../includes/models/OnboardNICHandler.php';

$configUuid = '52cf78cd-746d-4599-aa99-490743ad7cff';

echo "=== Testing Onboard NIC Fix ===\n";
echo "Configuration UUID: $configUuid\n\n";

try {
    // Step 1: Check if there are NICs in server_configuration_components
    echo "Step 1: Checking NICs in server_configuration_components...\n";
    $stmt = $pdo->prepare("
        SELECT scc.component_uuid, ni.SourceType, ni.ParentComponentUUID
        FROM server_configuration_components scc
        LEFT JOIN nicinventory ni ON scc.component_uuid = ni.UUID
        WHERE scc.config_uuid = ? AND scc.component_type = 'nic'
    ");
    $stmt->execute([$configUuid]);
    $nics = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($nics) . " NIC(s) in configuration:\n";
    foreach ($nics as $nic) {
        echo "  - UUID: {$nic['component_uuid']}, Type: {$nic['SourceType']}, Parent: {$nic['ParentComponentUUID']}\n";
    }
    echo "\n";

    // Step 2: Update nic_config JSON
    echo "Step 2: Updating nic_config JSON...\n";
    $nicHandler = new OnboardNICHandler($pdo);
    $result = $nicHandler->updateNICConfigJSON($configUuid);
    echo "Update result: " . ($result ? "SUCCESS" : "FAILED") . "\n\n";

    // Step 3: Verify nic_config in server_configurations
    echo "Step 3: Reading nic_config from server_configurations...\n";
    $stmt = $pdo->prepare("SELECT nic_config FROM server_configurations WHERE config_uuid = ?");
    $stmt->execute([$configUuid]);
    $nicConfigJson = $stmt->fetchColumn();

    if ($nicConfigJson) {
        $nicConfig = json_decode($nicConfigJson, true);
        echo "NIC Configuration:\n";
        echo json_encode($nicConfig, JSON_PRETTY_PRINT) . "\n\n";

        echo "Summary:\n";
        echo "  Total NICs: " . $nicConfig['summary']['total_nics'] . "\n";
        echo "  Onboard NICs: " . $nicConfig['summary']['onboard_nics'] . "\n";
        echo "  Component NICs: " . $nicConfig['summary']['component_nics'] . "\n";
    } else {
        echo "No nic_config found in database!\n";
    }

    echo "\n=== Test Complete ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
