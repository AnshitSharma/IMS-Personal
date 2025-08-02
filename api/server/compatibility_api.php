<?php
/**
 * Infrastructure Management System - Compatibility API Endpoint
 * File: api/server/compatibility_api.php
 * 
 * Dedicated endpoint for compatibility checking operations
 */

// Include required files
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');
require_once(__DIR__ . '/../../includes/models/CompatibilityEngine.php');
require_once(__DIR__ . '/../../includes/models/ComponentCompatibility.php');

// Ensure user is authenticated
$user = authenticateWithJWT($pdo);
if (!$user) {
    send_json_response(0, 0, 401, "Authentication required");
}

// Check permissions
if (!hasPermission($pdo, 'compatibility.check', $user['id'])) {
    send_json_response(0, 1, 403, "Insufficient permissions for compatibility checking");
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'check_pair':
            handleCheckPairCompatibility();
            break;
            
        case 'check_multiple':
            handleCheckMultipleCompatibility();
            break;
            
        case 'get_compatible_for':
            handleGetCompatibleComponents();
            break;
            
        case 'batch_check':
            handleBatchCompatibilityCheck();
            break;
            
        case 'analyze_configuration':
            handleAnalyzeConfiguration();
            break;
            
        case 'get_rules':
            handleGetCompatibilityRules();
            break;
            
        case 'test_rule':
            handleTestCompatibilityRule();
            break;
            
        case 'get_statistics':
            handleGetCompatibilityStatistics();
            break;
            
        default:
            send_json_response(0, 1, 400, "Invalid compatibility action: $action");
    }
} catch (Exception $e) {
    error_log("Compatibility API error: " . $e->getMessage());
    send_json_response(0, 1, 500, "Compatibility check failed");
}

/**
 * Check compatibility between two components
 */
function handleCheckPairCompatibility() {
    global $pdo;
    
    $component1Type = $_POST['component1_type'] ?? '';
    $component1Uuid = $_POST['component1_uuid'] ?? '';
    $component2Type = $_POST['component2_type'] ?? '';
    $component2Uuid = $_POST['component2_uuid'] ?? '';
    $includeDetails = filter_var($_POST['include_details'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($component1Type) || empty($component1Uuid) || empty($component2Type) || empty($component2Uuid)) {
        send_json_response(0, 1, 400, "All component parameters are required");
    }
    
    try {
        $compatibilityEngine = new CompatibilityEngine($pdo);
        
        $component1 = ['type' => $component1Type, 'uuid' => $component1Uuid];
        $component2 = ['type' => $component2Type, 'uuid' => $component2Uuid];
        
        $result = $compatibilityEngine->checkCompatibility($component1, $component2);
        
        $response = [
            'component_1' => $component1,
            'component_2' => $component2,
            'compatibility_result' => $result,
            'summary' => [
                'compatible' => $result['compatible'],
                'score' => $result['compatibility_score'],
                'issues_count' => count($result['failures']),
                'warnings_count' => count($result['warnings'])
            ]
        ];
        
        if ($includeDetails) {
            $componentCompatibility = new ComponentCompatibility($pdo);
            $response['detailed_analysis'] = $componentCompatibility->checkComponentPairCompatibility($component1, $component2);
        }
        
        send_json_response(1, 1, 200, "Compatibility check completed", $response);
        
    } catch (Exception $e) {
        error_log("Error getting compatibility rules: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatibility rules");
    }
}

/**
 * Test a compatibility rule
 */
function handleTestCompatibilityRule() {
    global $pdo;
    
    $ruleId = $_POST['rule_id'] ?? '';
    $testComponents = $_POST['test_components'] ?? [];
    
    if (empty($ruleId) || empty($testComponents)) {
        send_json_response(0, 1, 400, "Rule ID and test components are required");
    }
    
    try {
        // Get the rule
        $stmt = $pdo->prepare("SELECT * FROM compatibility_rules WHERE id = ?");
        $stmt->execute([$ruleId]);
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rule) {
            send_json_response(0, 1, 404, "Compatibility rule not found");
        }
        
        $rule['rule_definition'] = json_decode($rule['rule_definition'], true);
        
        // Test the rule against provided components
        $compatibilityEngine = new CompatibilityEngine($pdo);
        $testResults = [];
        
        foreach ($testComponents as $testPair) {
            if (isset($testPair['component1']) && isset($testPair['component2'])) {
                $result = $compatibilityEngine->checkCompatibility($testPair['component1'], $testPair['component2']);
                
                $testResults[] = [
                    'component_1' => $testPair['component1'],
                    'component_2' => $testPair['component2'],
                    'rule_applied' => in_array($rule['rule_name'], $result['applied_rules']),
                    'result' => $result
                ];
            }
        }
        
        send_json_response(1, 1, 200, "Rule test completed", [
            'rule' => $rule,
            'test_results' => $testResults,
            'test_summary' => [
                'total_tests' => count($testResults),
                'rule_triggered' => count(array_filter($testResults, function($test) { return $test['rule_applied']; }))
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error testing compatibility rule: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to test compatibility rule");
    }
}

/**
 * Get compatibility statistics
 */
function handleGetCompatibilityStatistics() {
    global $pdo;
    
    $timeframe = $_GET['timeframe'] ?? $_POST['timeframe'] ?? '24 HOUR';
    $includeDetails = filter_var($_GET['include_details'] ?? $_POST['include_details'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    try {
        $compatibilityEngine = new CompatibilityEngine($pdo);
        $statistics = $compatibilityEngine->getCompatibilityStatistics($timeframe);
        
        $response = [
            'timeframe' => $timeframe,
            'statistics' => $statistics
        ];
        
        if ($includeDetails) {
            // Get additional detailed statistics
            $response['detailed_stats'] = getDetailedCompatibilityStats($pdo, $timeframe);
        }
        
        send_json_response(1, 1, 200, "Compatibility statistics retrieved", $response);
        
    } catch (Exception $e) {
        error_log("Error getting compatibility statistics: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatibility statistics");
    }
}

/**
 * Generate configuration recommendations
 */
function generateConfigurationRecommendations($validationResult) {
    $recommendations = [];
    
    // Performance recommendations
    if ($validationResult['overall_score'] < 0.8) {
        $recommendations[] = [
            'type' => 'performance',
            'priority' => 'medium',
            'message' => 'Consider optimizing component selection for better compatibility',
            'details' => 'Current compatibility score is below optimal threshold'
        ];
    }
    
    // Power recommendations
    if ($validationResult['estimated_power'] > 500) {
        $recommendations[] = [
            'type' => 'power',
            'priority' => 'high',
            'message' => 'High power consumption detected',
            'details' => "Estimated power consumption: {$validationResult['estimated_power']}W - ensure adequate PSU capacity"
        ];
    } elseif ($validationResult['estimated_power'] > 300) {
        $recommendations[] = [
            'type' => 'power',
            'priority' => 'medium',
            'message' => 'Moderate power consumption',
            'details' => "Estimated power consumption: {$validationResult['estimated_power']}W - consider energy efficiency"
        ];
    }
    
    // Component-specific recommendations
    foreach ($validationResult['component_checks'] as $check) {
        if (!$check['compatible']) {
            $recommendations[] = [
                'type' => 'compatibility',
                'priority' => 'high',
                'message' => "Compatibility issue: {$check['components']}",
                'details' => implode(', ', $check['issues'])
            ];
        } elseif (!empty($check['warnings'])) {
            $recommendations[] = [
                'type' => 'warning',
                'priority' => 'low',
                'message' => "Minor issue: {$check['components']}",
                'details' => implode(', ', $check['warnings'])
            ];
        }
    }
    
    // Global check recommendations
    foreach ($validationResult['global_checks'] as $check) {
        if (!$check['passed']) {
            $recommendations[] = [
                'type' => 'system',
                'priority' => 'medium',
                'message' => "System requirement: {$check['check']}",
                'details' => $check['message']
            ];
        }
    }
    
    return $recommendations;
}

/**
 * Get detailed compatibility statistics
 */
function getDetailedCompatibilityStats($pdo, $timeframe) {
    try {
        $stats = [];
        
        // Most common compatibility failures
        $stmt = $pdo->prepare("
            SELECT 
                component_type_1,
                component_type_2,
                COUNT(*) as failure_count
            FROM compatibility_log 
            WHERE compatibility_result = 0 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 $timeframe)
            GROUP BY component_type_1, component_type_2
            ORDER BY failure_count DESC
            LIMIT 10
        ");
        $stmt->execute();
        $stats['common_failures'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Performance metrics by component type
        $stmt = $pdo->prepare("
            SELECT 
                CONCAT(component_type_1, '-', component_type_2) as component_pair,
                COUNT(*) as check_count,
                AVG(execution_time_ms) as avg_execution_time,
                SUM(CASE WHEN compatibility_result = 1 THEN 1 ELSE 0 END) as success_count
            FROM compatibility_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 $timeframe)
                AND execution_time_ms IS NOT NULL
            GROUP BY component_type_1, component_type_2
            ORDER BY check_count DESC
        ");
        $stmt->execute();
        $stats['performance_metrics'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Rule effectiveness
        $stmt = $pdo->prepare("
            SELECT 
                JSON_EXTRACT(applied_rules, '$[*]') as rules,
                COUNT(*) as usage_count,
                SUM(CASE WHEN compatibility_result = 1 THEN 1 ELSE 0 END) as success_count
            FROM compatibility_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 $timeframe)
                AND applied_rules IS NOT NULL
                AND applied_rules != '[]'
            GROUP BY applied_rules
            ORDER BY usage_count DESC
            LIMIT 10
        ");
        $stmt->execute();
        $stats['rule_usage'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $stats;
        
    } catch (Exception $e) {
        error_log("Error getting detailed compatibility stats: " . $e->getMessage());
        return [];
    }
}
?>
        error_log("Error in pair compatibility check: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to check compatibility");
    }
}

/**
 * Check compatibility among multiple components
 */
function handleCheckMultipleCompatibility() {
    global $pdo;
    
    $components = $_POST['components'] ?? [];
    $crossCheck = filter_var($_POST['cross_check'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($components) || !is_array($components)) {
        send_json_response(0, 1, 400, "Components array is required");
    }
    
    if (count($components) < 2) {
        send_json_response(0, 1, 400, "At least 2 components required for compatibility check");
    }
    
    try {
        $compatibilityEngine = new CompatibilityEngine($pdo);
        $results = [];
        $overallCompatible = true;
        $overallScore = 1.0;
        $totalIssues = 0;
        $totalWarnings = 0;
        
        if ($crossCheck) {
            // Check every component against every other component
            for ($i = 0; $i < count($components); $i++) {
                for ($j = $i + 1; $j < count($components); $j++) {
                    $component1 = $components[$i];
                    $component2 = $components[$j];
                    
                    $result = $compatibilityEngine->checkCompatibility($component1, $component2);
                    
                    $results[] = [
                        'component_1' => $component1,
                        'component_2' => $component2,
                        'result' => $result
                    ];
                    
                    if (!$result['compatible']) {
                        $overallCompatible = false;
                    }
                    
                    $overallScore *= $result['compatibility_score'];
                    $totalIssues += count($result['failures']);
                    $totalWarnings += count($result['warnings']);
                }
            }
        } else {
            // Check components sequentially (1-2, 2-3, 3-4, etc.)
            for ($i = 0; $i < count($components) - 1; $i++) {
                $component1 = $components[$i];
                $component2 = $components[$i + 1];
                
                $result = $compatibilityEngine->checkCompatibility($component1, $component2);
                
                $results[] = [
                    'component_1' => $component1,
                    'component_2' => $component2,
                    'result' => $result
                ];
                
                if (!$result['compatible']) {
                    $overallCompatible = false;
                }
                
                $overallScore *= $result['compatibility_score'];
                $totalIssues += count($result['failures']);
                $totalWarnings += count($result['warnings']);
            }
        }
        
        send_json_response(1, 1, 200, "Multiple component compatibility check completed", [
            'components' => $components,
            'check_type' => $crossCheck ? 'cross_check' : 'sequential',
            'individual_results' => $results,
            'overall_summary' => [
                'compatible' => $overallCompatible,
                'average_score' => $overallScore,
                'total_checks' => count($results),
                'total_issues' => $totalIssues,
                'total_warnings' => $totalWarnings
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error in multiple compatibility check: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to check multiple component compatibility");
    }
}

/**
 * Get compatible components for a specific component
 */
function handleGetCompatibleComponents() {
    global $pdo;
    
    $baseComponentType = $_GET['base_component_type'] ?? $_POST['base_component_type'] ?? '';
    $baseComponentUuid = $_GET['base_component_uuid'] ?? $_POST['base_component_uuid'] ?? '';
    $targetType = $_GET['target_type'] ?? $_POST['target_type'] ?? '';
    $availableOnly = filter_var($_GET['available_only'] ?? $_POST['available_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 50);
    $includeScores = filter_var($_GET['include_scores'] ?? $_POST['include_scores'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($baseComponentType) || empty($baseComponentUuid)) {
        send_json_response(0, 1, 400, "Base component type and UUID are required");
    }
    
    try {
        $compatibilityEngine = new CompatibilityEngine($pdo);
        $baseComponent = ['type' => $baseComponentType, 'uuid' => $baseComponentUuid];
        
        if ($targetType) {
            // Get compatible components for specific type
            $compatibleComponents = $compatibilityEngine->getCompatibleComponents($baseComponent, $targetType, $availableOnly);
            
            // Apply limit
            if ($limit > 0) {
                $compatibleComponents = array_slice($compatibleComponents, 0, $limit);
            }
            
            $response = [
                'base_component' => $baseComponent,
                'target_type' => $targetType,
                'compatible_components' => $compatibleComponents,
                'total_found' => count($compatibleComponents)
            ];
        } else {
            // Get compatible components for all types
            $allTypes = ['cpu', 'motherboard', 'ram', 'storage', 'nic', 'caddy'];
            $allCompatible = [];
            
            foreach ($allTypes as $type) {
                if ($type !== $baseComponentType) {
                    $typeComponents = $compatibilityEngine->getCompatibleComponents($baseComponent, $type, $availableOnly);
                    
                    if ($limit > 0) {
                        $typeComponents = array_slice($typeComponents, 0, $limit);
                    }
                    
                    $allCompatible[$type] = $typeComponents;
                }
            }
            
            $response = [
                'base_component' => $baseComponent,
                'compatible_components' => $allCompatible,
                'summary' => array_map(function($components) {
                    return ['count' => count($components)];
                }, $allCompatible)
            ];
        }
        
        send_json_response(1, 1, 200, "Compatible components retrieved", $response);
        
    } catch (Exception $e) {
        error_log("Error getting compatible components: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatible components");
    }
}

/**
 * Batch compatibility check for multiple component pairs
 */
function handleBatchCompatibilityCheck() {
    global $pdo;
    
    $componentPairs = $_POST['component_pairs'] ?? [];
    $stopOnFirstFailure = filter_var($_POST['stop_on_first_failure'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($componentPairs) || !is_array($componentPairs)) {
        send_json_response(0, 1, 400, "Component pairs array is required");
    }
    
    try {
        $compatibilityEngine = new CompatibilityEngine($pdo);
        $results = [];
        $overallStats = [
            'total_checks' => 0,
            'successful_checks' => 0,
            'failed_checks' => 0,
            'average_score' => 0.0,
            'total_execution_time' => 0
        ];
        
        foreach ($componentPairs as $index => $pair) {
            if (!isset($pair['component1']) || !isset($pair['component2'])) {
                $results[] = [
                    'pair_index' => $index,
                    'error' => 'Invalid component pair format'
                ];
                continue;
            }
            
            $startTime = microtime(true);
            
            $result = $compatibilityEngine->checkCompatibility($pair['component1'], $pair['component2']);
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            
            $results[] = [
                'pair_index' => $index,
                'component_1' => $pair['component1'],
                'component_2' => $pair['component2'],
                'result' => $result,
                'execution_time_ms' => round($executionTime, 2)
            ];
            
            // Update stats
            $overallStats['total_checks']++;
            $overallStats['total_execution_time'] += $executionTime;
            $overallStats['average_score'] += $result['compatibility_score'];
            
            if ($result['compatible']) {
                $overallStats['successful_checks']++;
            } else {
                $overallStats['failed_checks']++;
                
                if ($stopOnFirstFailure) {
                    break;
                }
            }
        }
        
        // Calculate final averages
        if ($overallStats['total_checks'] > 0) {
            $overallStats['average_score'] /= $overallStats['total_checks'];
            $overallStats['success_rate'] = ($overallStats['successful_checks'] / $overallStats['total_checks']) * 100;
        }
        
        send_json_response(1, 1, 200, "Batch compatibility check completed", [
            'results' => $results,
            'statistics' => $overallStats,
            'stopped_early' => $stopOnFirstFailure && $overallStats['failed_checks'] > 0
        ]);
        
    } catch (Exception $e) {
        error_log("Error in batch compatibility check: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to perform batch compatibility check");
    }
}

/**
 * Analyze complete server configuration
 */
function handleAnalyzeConfiguration() {
    global $pdo;
    
    $configuration = $_POST['configuration'] ?? [];
    $includeRecommendations = filter_var($_POST['include_recommendations'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    if (empty($configuration)) {
        send_json_response(0, 1, 400, "Server configuration is required");
    }
    
    try {
        $compatibilityEngine = new CompatibilityEngine($pdo);
        
        // Validate the configuration structure
        $validationResult = $compatibilityEngine->validateServerConfiguration($configuration);
        
        $response = [
            'configuration_analysis' => $validationResult,
            'summary' => [
                'overall_valid' => $validationResult['valid'],
                'overall_score' => $validationResult['overall_score'],
                'estimated_power' => $validationResult['estimated_power'],
                'estimated_cost' => $validationResult['estimated_cost'],
                'component_check_count' => count($validationResult['component_checks']),
                'global_check_count' => count($validationResult['global_checks'])
            ]
        ];
        
        if ($includeRecommendations) {
            $response['recommendations'] = generateConfigurationRecommendations($validationResult);
        }
        
        send_json_response(1, 1, 200, "Configuration analysis completed", $response);
        
    } catch (Exception $e) {
        error_log("Error analyzing configuration: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to analyze configuration");
    }
}

/**
 * Get compatibility rules
 */
function handleGetCompatibilityRules() {
    global $pdo;
    
    $ruleType = $_GET['rule_type'] ?? $_POST['rule_type'] ?? '';
    $componentTypes = $_GET['component_types'] ?? $_POST['component_types'] ?? '';
    $activeOnly = filter_var($_GET['active_only'] ?? $_POST['active_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
    
    try {
        $query = "SELECT * FROM compatibility_rules WHERE 1=1";
        $params = [];
        
        if ($ruleType) {
            $query .= " AND rule_type = ?";
            $params[] = $ruleType;
        }
        
        if ($componentTypes) {
            $query .= " AND component_types LIKE ?";
            $params[] = "%$componentTypes%";
        }
        
        if ($activeOnly) {
            $query .= " AND is_active = 1";
        }
        
        $query .= " ORDER BY rule_priority ASC, rule_name ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Decode JSON rule definitions
        foreach ($rules as &$rule) {
            $rule['rule_definition'] = json_decode($rule['rule_definition'], true);
        }
        
        send_json_response(1, 1, 200, "Compatibility rules retrieved", [
            'rules' => $rules,
            'total_count' => count($rules),
            'filters_applied' => [
                'rule_type' => $ruleType,
                'component_types' => $componentTypes,
                'active_only' => $activeOnly
            ]
        ]);
        
    }  catch (Exception $e) {
        error_log("Error getting compatibility rules: " . $e->getMessage());
        send_json_response(0, 1, 500, "Failed to get compatibility rules");
    }
}