<?php
/**
 * ServerBuilder Class
 * File: includes/models/ServerBuilder.php
 * 
 * Handles server configuration building, component management, and validation
 */

class ServerBuilder {
    private $pdo;
    private $sessionId;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->sessionId = uniqid('server_build_', true);
    }
    
    /**
     * Get current session ID
     */
    public function getSessionId() {
        return $this->sessionId;
    }
    
    /**
     * Add component to server configuration
     */
    public function addComponent($configUuid, $componentType, $componentUuid, $options = []) {
        try {
            $quantity = $options['quantity'] ?? 1;
            $slotPosition = $options['slot_position'] ?? null;
            $notes = $options['notes'] ?? '';
            
            // Check if component exists and is available
            $component = $this->getComponentByUuid($componentType, $componentUuid);
            if (!$component) {
                return [
                    'success' => false,
                    'message' => 'Component not found'
                ];
            }
            
            if ($component['Status'] != 1) {
                return [
                    'success' => false,
                    'message' => 'Component is not available'
                ];
            }
            
            // Add component to configuration
            $stmt = $this->pdo->prepare("
                INSERT INTO server_configuration_components 
                (config_uuid, component_type, component_uuid, quantity, slot_position, notes, added_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                quantity = VALUES(quantity), 
                slot_position = VALUES(slot_position), 
                notes = VALUES(notes),
                updated_at = NOW()
            ");
            
            $result = $stmt->execute([
                $configUuid,
                $componentType,
                $componentUuid,
                $quantity,
                $slotPosition,
                $notes
            ]);
            
            if ($result) {
                // Update component status to in-use
                $this->updateComponentStatus($componentType, $componentUuid, 2); // 2 = in use
                
                return [
                    'success' => true,
                    'message' => 'Component added successfully',
                    'component' => $component
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to add component to configuration'
                ];
            }
            
        } catch (Exception $e) {
            error_log("Error adding component: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error occurred'
            ];
        }
    }
    
    /**
     * Remove component from server configuration
     */
    public function removeComponent($configUuid, $componentType, $componentUuid = null) {
        try {
            $whereClause = "config_uuid = ? AND component_type = ?";
            $params = [$configUuid, $componentType];
            
            if ($componentUuid) {
                $whereClause .= " AND component_uuid = ?";
                $params[] = $componentUuid;
            }
            
            // Get components before removing to update their status
            $stmt = $this->pdo->prepare("SELECT component_uuid FROM server_configuration_components WHERE $whereClause");
            $stmt->execute($params);
            $componentsToRemove = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Remove from configuration
            $stmt = $this->pdo->prepare("DELETE FROM server_configuration_components WHERE $whereClause");
            $result = $stmt->execute($params);
            
            if ($result) {
                // Update component status back to available
                foreach ($componentsToRemove as $uuid) {
                    $this->updateComponentStatus($componentType, $uuid, 1); // 1 = available
                }
                
                return [
                    'success' => true,
                    'message' => 'Component(s) removed successfully',
                    'removed_count' => $stmt->rowCount()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to remove component(s)'
                ];
            }
            
        } catch (Exception $e) {
            error_log("Error removing component: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error occurred'
            ];
        }
    }
    
    /**
     * Get configuration summary
     */
    public function getConfigurationSummary($configUuid) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    scc.component_type,
                    scc.component_uuid,
                    scc.quantity,
                    scc.slot_position,
                    scc.notes,
                    scc.added_at
                FROM server_configuration_components scc
                WHERE scc.config_uuid = ?
                ORDER BY scc.component_type, scc.added_at
            ");
            $stmt->execute([$configUuid]);
            $components = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $summary = [
                'config_uuid' => $configUuid,
                'components' => [],
                'component_counts' => [],
                'total_components' => 0
            ];
            
            foreach ($components as $component) {
                $type = $component['component_type'];
                
                if (!isset($summary['components'][$type])) {
                    $summary['components'][$type] = [];
                    $summary['component_counts'][$type] = 0;
                }
                
                // Get component details
                $componentDetails = $this->getComponentByUuid($type, $component['component_uuid']);
                if ($componentDetails) {
                    $component['details'] = $componentDetails;
                }
                
                $summary['components'][$type][] = $component;
                $summary['component_counts'][$type] += $component['quantity'];
                $summary['total_components'] += $component['quantity'];
            }
            
            return $summary;
            
        } catch (Exception $e) {
            error_log("Error getting configuration summary: " . $e->getMessage());
            return [
                'config_uuid' => $configUuid,
                'components' => [],
                'component_counts' => [],
                'total_components' => 0,
                'error' => 'Failed to load configuration summary'
            ];
        }
    }
    
    /**
     * Validate server configuration
     */
    public function validateConfiguration($configUuid) {
        try {
            $summary = $this->getConfigurationSummary($configUuid);
            
            $validation = [
                'is_valid' => true,
                'compatibility_score' => 100,
                'issues' => [],
                'warnings' => [],
                'recommendations' => []
            ];
            
            // Check for required components
            $requiredComponents = ['cpu', 'motherboard', 'ram'];
            foreach ($requiredComponents as $required) {
                if (!isset($summary['components'][$required]) || empty($summary['components'][$required])) {
                    $validation['is_valid'] = false;
                    $validation['issues'][] = "Missing required component: " . ucfirst($required);
                    $validation['compatibility_score'] -= 20;
                }
            }
            
            // Check for storage
            if (!isset($summary['components']['storage']) || empty($summary['components']['storage'])) {
                $validation['warnings'][] = "No storage devices configured";
                $validation['compatibility_score'] -= 10;
            }
            
            // Check for network interface
            if (!isset($summary['components']['nic']) || empty($summary['components']['nic'])) {
                $validation['warnings'][] = "No network interface configured";
                $validation['recommendations'][] = "Consider adding a network interface card";
            }
            
            // Ensure compatibility score doesn't go below 0
            $validation['compatibility_score'] = max(0, $validation['compatibility_score']);
            
            return $validation;
            
        } catch (Exception $e) {
            error_log("Error validating configuration: " . $e->getMessage());
            return [
                'is_valid' => false,
                'compatibility_score' => 0,
                'issues' => ['Validation failed due to system error'],
                'warnings' => [],
                'recommendations' => []
            ];
        }
    }
    
    /**
     * Clone configuration
     */
    public function cloneConfiguration($sourceConfigUuid, $newServerName, $newDescription, $userId) {
        try {
            $this->pdo->beginTransaction();
            
            // Create new configuration
            $newConfigData = [
                'server_name' => $newServerName,
                'description' => $newDescription,
                'created_by' => $userId,
                'configuration_status' => 0
            ];
            
            $newConfig = ServerConfiguration::create($this->pdo, $newConfigData);
            if (!$newConfig) {
                throw new Exception("Failed to create new configuration");
            }
            
            $newConfigUuid = $newConfig->get('config_uuid');
            
            // Copy components from source configuration
            $stmt = $this->pdo->prepare("
                INSERT INTO server_configuration_components 
                (config_uuid, component_type, component_uuid, quantity, slot_position, notes, added_at)
                SELECT ?, component_type, component_uuid, quantity, slot_position, notes, NOW()
                FROM server_configuration_components 
                WHERE config_uuid = ?
            ");
            
            if (!$stmt->execute([$newConfigUuid, $sourceConfigUuid])) {
                throw new Exception("Failed to copy components");
            }
            
            $this->pdo->commit();
            return $newConfig;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error cloning configuration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Export configuration
     */
    public function exportConfiguration($configUuid, $format = 'json') {
        try {
            $config = ServerConfiguration::loadByUuid($this->pdo, $configUuid);
            if (!$config) {
                throw new Exception("Configuration not found");
            }
            
            $summary = $this->getConfigurationSummary($configUuid);
            $validation = $this->validateConfiguration($configUuid);
            
            $exportData = [
                'configuration' => $config->getData(),
                'components' => $summary['components'],
                'validation' => $validation,
                'exported_at' => date('Y-m-d H:i:s'),
                'export_format' => $format
            ];
            
            switch ($format) {
                case 'json':
                    return json_encode($exportData, JSON_PRETTY_PRINT);
                case 'array':
                    return $exportData;
                default:
                    return json_encode($exportData, JSON_PRETTY_PRINT);
            }
            
        } catch (Exception $e) {
            error_log("Error exporting configuration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get component by UUID
     */
    private function getComponentByUuid($componentType, $componentUuid) {
        $tableMap = [
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        if (!isset($tableMap[$componentType])) {
            return null;
        }
        
        $table = $tableMap[$componentType];
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM $table WHERE UUID = ?");
            $stmt->execute([$componentUuid]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting component: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update component status
     */
    private function updateComponentStatus($componentType, $componentUuid, $status) {
        $tableMap = [
            'cpu' => 'cpuinventory',
            'ram' => 'raminventory',
            'storage' => 'storageinventory',
            'motherboard' => 'motherboardinventory',
            'nic' => 'nicinventory',
            'caddy' => 'caddyinventory'
        ];
        
        if (!isset($tableMap[$componentType])) {
            return false;
        }
        
        $table = $tableMap[$componentType];
        
        try {
            $stmt = $this->pdo->prepare("UPDATE $table SET Status = ? WHERE UUID = ?");
            return $stmt->execute([$status, $componentUuid]);
        } catch (Exception $e) {
            error_log("Error updating component status: " . $e->getMessage());
            return false;
        }
    }
}
?>