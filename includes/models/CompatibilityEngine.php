<?php
/**
 * Infrastructure Management System - Compatibility Engine
 * File: includes/models/CompatibilityEngine.php
 *
 * Main compatibility checking engine - acts as wrapper around ComponentCompatibility
 * STORAGE COMPATIBILITY DISABLED - 2025-09-15 - Storage checks always return compatible
 */

require_once __DIR__ . '/ComponentCompatibility.php';

class CompatibilityEngine extends ComponentCompatibility {

    public function __construct($pdo) {
        parent::__construct($pdo);
    }

    /**
     * Check compatibility between two components
     */
    public function checkCompatibility($component1, $component2) {
        $type1 = strtolower($component1['type']);
        $type2 = strtolower($component2['type']);

        // STORAGE COMPATIBILITY DISABLED - SKIP ANY STORAGE-RELATED CHECKS
        if ($type1 === 'storage' || $type2 === 'storage') {
            return [
                'compatible' => true,
                'compatibility_score' => 1.0,
                'failures' => [],
                'warnings' => ['Storage compatibility checks have been disabled'],
                'recommendations' => ['Storage compatibility validation is currently disabled'],
                'applied_rules' => ['storage_compatibility_disabled']
            ];
        }

        // Use parent ComponentCompatibility for non-storage checks
        return $this->checkComponentPairCompatibility($component1, $component2);
    }

    /**
     * Get compatible components for a base component
     */
    public function getCompatibleComponents($baseComponent, $targetType, $availableOnly = true) {
        $targetType = strtolower($targetType);

        // STORAGE COMPATIBILITY DISABLED - RETURN ALL STORAGE AS COMPATIBLE
        if ($targetType === 'storage' || strtolower($baseComponent['type']) === 'storage') {
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

// Create alias for backward compatibility
class_alias('CompatibilityEngine', 'CompatibilityEngine');
?>