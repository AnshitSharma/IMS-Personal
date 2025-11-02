<?php
/**
 * One-time script to fix onboard NICs for existing server configurations
 *
 * This script:
 * 1. Finds all server configurations with motherboards
 * 2. Checks if onboard NICs were properly extracted
 * 3. Re-extracts onboard NICs if missing or incomplete
 * 4. Updates NIC configuration JSON
 *
 * Usage: php fix_onboard_nics.php [config_uuid]
 *        If no config_uuid provided, processes all configurations
 */

require_once __DIR__ . '/../../includes/db_config.php';
require_once __DIR__ . '/../../includes/models/OnboardNICHandler.php';

echo "=== Onboard NIC Fix Script ===\n\n";

// Get specific config UUID from command line argument, or process all
$targetConfigUuid = $argv[1] ?? null;

try {
    // Find configurations with motherboards
    if ($targetConfigUuid) {
        echo "Processing specific configuration: $targetConfigUuid\n\n";
        $stmt = $pdo->prepare("
            SELECT DISTINCT sc.config_uuid, sc.server_name
            FROM server_configurations sc
            WHERE sc.config_uuid = ?
        ");
        $stmt->execute([$targetConfigUuid]);
    } else {
        echo "Processing all configurations with motherboards...\n\n";
        $stmt = $pdo->query("
            SELECT DISTINCT sc.config_uuid, sc.server_name
            FROM server_configurations sc
            INNER JOIN server_configuration_components scc
                ON sc.config_uuid = scc.config_uuid
            WHERE scc.component_type = 'motherboard'
            ORDER BY sc.created_at DESC
        ");
    }

    $configurations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($configurations) . " configuration(s) to check\n\n";

    $nicHandler = new OnboardNICHandler($pdo);
    $processed = 0;
    $skipped = 0;
    $added = 0;

    foreach ($configurations as $config) {
        $configUuid = $config['config_uuid'];
        $serverName = $config['server_name'] ?? 'Unnamed';

        echo "----------------------------------------\n";
        echo "Config: $serverName ($configUuid)\n";

        // Get motherboard from this configuration
        $stmt = $pdo->prepare("
            SELECT component_uuid
            FROM server_configuration_components
            WHERE config_uuid = ? AND component_type = 'motherboard'
            LIMIT 1
        ");
        $stmt->execute([$configUuid]);
        $motherboard = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$motherboard) {
            echo "  ⚠️  No motherboard found in configuration\n";
            $skipped++;
            continue;
        }

        $motherboardUuid = $motherboard['component_uuid'];
        echo "  Motherboard UUID: $motherboardUuid\n";

        // Check if onboard NICs already exist
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as nic_count
            FROM server_configuration_components scc
            INNER JOIN nicinventory ni ON scc.component_uuid = ni.UUID
            WHERE scc.config_uuid = ?
              AND scc.component_type = 'nic'
              AND ni.SourceType = 'onboard'
              AND ni.ParentComponentUUID = ?
        ");
        $stmt->execute([$configUuid, $motherboardUuid]);
        $existingNicCount = $stmt->fetchColumn();

        echo "  Existing onboard NICs: $existingNicCount\n";

        if ($existingNicCount > 0) {
            echo "  ℹ️  Onboard NICs already extracted, skipping...\n";

            // Update NIC config JSON anyway to ensure it's current
            echo "  Updating NIC configuration JSON...\n";
            $nicHandler->updateNICConfigJSON($configUuid);

            $skipped++;
            continue;
        }

        // Extract onboard NICs from motherboard
        echo "  🔧 Extracting onboard NICs from motherboard...\n";
        $result = $nicHandler->autoAddOnboardNICs($configUuid, $motherboardUuid);

        if ($result['count'] > 0) {
            echo "  ✅ Successfully added {$result['count']} onboard NIC(s)\n";

            // Display NIC details
            foreach ($result['nics'] as $nic) {
                echo "     - {$nic['uuid']}: {$nic['notes']}\n";
            }

            // Update NIC configuration JSON
            echo "  📝 Updating NIC configuration JSON...\n";
            $nicHandler->updateNICConfigJSON($configUuid);

            $added += $result['count'];
            $processed++;
        } else {
            echo "  ℹ️  No onboard NICs found in motherboard specifications\n";
            echo "     Message: {$result['message']}\n";
            $skipped++;
        }
    }

    echo "\n========================================\n";
    echo "SUMMARY:\n";
    echo "  Configurations processed: $processed\n";
    echo "  Configurations skipped: $skipped\n";
    echo "  Total onboard NICs added: $added\n";
    echo "========================================\n";

} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n✅ Script completed successfully\n";
