<?php
/**
 * Infrastructure Management System - Server Configuration Model
 * File: includes/models/ServerConfiguration.php
 * 
 * Handles server configuration data structures and operations
 */

class ServerConfiguration {
    private $pdo;
    private $configData;
    
    public function __construct($pdo, $configData = null) {
        $this->pdo = $pdo;
        $this->configData = $configData ?? $this->getEmptyConfiguration();
    }
    
    /**
     * Get empty configuration template
     */
    public function getEmptyConfiguration() {
        return [
            'config_uuid' => null,
            'config_name' => '',
            'config_description' => '',
            'cpu_uuid' => null,
            'cpu_id' => null,
            'motherboard_uuid' => null,
            'motherboard_id' => null,
            'ram_configuration' => [],
            'storage_configuration' => [],
            'nic_configuration' => [],
            'caddy_configuration' => [],
            'additional_components' => [],
            'configuration_status' => 0, // Draft
            'total_cost' => 0.0,
            'power_consumption' => 0,
            'compatibility_score' => 0.0,
            'validation_results' => [],
            'created_by' => null,
            'updated_by' => null,
            'created_at' => null,
            'updated_at' => null
        ];
    }
    
    /**
     * Load configuration from database
     */
    public static function loadFromDatabase($pdo, $configUuid) {
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM server_configurations 
                WHERE config_uuid = ?
            ");
            $stmt->execute([$configUuid]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($config) {
                // Decode JSON fields
                $config['ram_configuration'] = json_decode($config['ram_configuration'] ?? '[]', true);
                $config['storage_configuration'] = json_decode($config['storage_configuration'] ?? '[]', true);
                $config['nic_configuration'] = json_decode($config['nic_configuration'] ?? '[]', true);
                $config['caddy_configuration'] = json_decode($config['caddy_configuration'] ?? '[]', true);
                $config['additional_components'] = json_decode($config['additional_components'] ?? '[]', true);
                $config['validation_results'] = json_decode($config['validation_results'] ?? '[]', true);
                
                return new self($pdo, $config);
            }
            
            return null;
        } catch (PDOException $e) {
            error_log("Error loading configuration: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create new configuration
     */
    public static function create($pdo, $configName, $description = '', $createdBy = null) {
        $config = new self($pdo);
        $config->configData['config_uuid'] = $config->generateUUID();
        $config->configData['config_name'] = $configName;
        $config->configData['config_description'] = $description;
        $config->configData['created_by'] = $createdBy;
        $config->configData['created_at'] = date('Y-m-d H:i:s');
        
        return $config;
    }
    
    /**
     * Get configuration data
     */
    public function getData() {
        return $this->configData;
    }
    
    /**
     * Set configuration data
     */
    public function setData($data) {
        $this->configData = array_merge($this->configData, $data);
    }
    
    /**
     * Get specific field
     */
    public function get($field) {
        return $this->configData[$field] ?? null;
    }
    
    /**
     * Set specific field
     */
    public function set($field, $value) {
        $this->configData[$field] = $value;
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Add CPU component
     */
    public function setCPU($uuid, $id) {
        $this->configData['cpu_uuid'] = $uuid;
        $this->configData['cpu_id'] = $id;
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Remove CPU component
     */
    public function removeCPU() {
        $this->configData['cpu_uuid'] = null;
        $this->configData['cpu_id'] = null;
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Set motherboard component
     */
    public function setMotherboard($uuid, $id) {
        $this->configData['motherboard_uuid'] = $uuid;
        $this->configData['motherboard_id'] = $id;
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Remove motherboard component
     */
    public function removeMotherboard() {
        $this->configData['motherboard_uuid'] = null;
        $this->configData['motherboard_id'] = null;
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Add RAM module
     */
    public function addRAM($uuid, $id, $quantity = 1, $slotPosition = null) {
        $this->configData['ram_configuration'][] = [
            'uuid' => $uuid,
            'id' => $id,
            'quantity' => $quantity,
            'slot_position' => $slotPosition
        ];
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Remove RAM module
     */
    public function removeRAM($uuid) {
        $this->configData['ram_configuration'] = array_filter(
            $this->configData['ram_configuration'],
            function($ram) use ($uuid) {
                return $ram['uuid'] !== $uuid;
            }
        );
        $this->configData['ram_configuration'] = array_values($this->configData['ram_configuration']);
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Add storage device
     */
    public function addStorage($uuid, $id, $slotPosition = null) {
        $this->configData['storage_configuration'][] = [
            'uuid' => $uuid,
            'id' => $id,
            'slot_position' => $slotPosition
        ];
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Remove storage device
     */
    public function removeStorage($uuid) {
        $this->configData['storage_configuration'] = array_filter(
            $this->configData['storage_configuration'],
            function($storage) use ($uuid) {
                return $storage['uuid'] !== $uuid;
            }
        );
        $this->configData['storage_configuration'] = array_values($this->configData['storage_configuration']);
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Add NIC
     */
    public function addNIC($uuid, $id, $slotPosition = null) {
        $this->configData['nic_configuration'][] = [
            'uuid' => $uuid,
            'id' => $id,
            'slot_position' => $slotPosition
        ];
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Remove NIC
     */
    public function removeNIC($uuid) {
        $this->configData['nic_configuration'] = array_filter(
            $this->configData['nic_configuration'],
            function($nic) use ($uuid) {
                return $nic['uuid'] !== $uuid;
            }
        );
        $this->configData['nic_configuration'] = array_values($this->configData['nic_configuration']);
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Add caddy
     */
    public function addCaddy($uuid, $id, $slotPosition = null) {
        $this->configData['caddy_configuration'][] = [
            'uuid' => $uuid,
            'id' => $id,
            'slot_position' => $slotPosition
        ];
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Remove caddy
     */
    public function removeCaddy($uuid) {
        $this->configData['caddy_configuration'] = array_filter(
            $this->configData['caddy_configuration'],
            function($caddy) use ($uuid) {
                return $caddy['uuid'] !== $uuid;
            }
        );
        $this->configData['caddy_configuration'] = array_values($this->configData['caddy_configuration']);
        $this->configData['updated_at'] = date('Y-m-d H:i:s');
    }
    
    /**
     * Get all selected components
     */
    public function getSelectedComponents() {
        $components = [];
        
        if ($this->configData['cpu_uuid']) {
            $components[] = [
                'type' => 'cpu',
                'uuid' => $this->configData['cpu_uuid'],
                'id' => $this->configData['cpu_id']
            ];
        }
        
        if ($this->configData['motherboard_uuid']) {
            $components[] = [
                'type' => 'motherboard',
                'uuid' => $this->configData['motherboard_uuid'],
                'id' => $this->configData['motherboard_id']
            ];
        }
        
        foreach ($this->configData['ram_configuration'] as $ram) {
            $components[] = [
                'type' => 'ram',
                'uuid' => $ram['uuid'],
                'id' => $ram['id']
            ];
        }
        
        foreach ($this->configData['storage_configuration'] as $storage) {
            $components[] = [
                'type' => 'storage',
                'uuid' => $storage['uuid'],
                'id' => $storage['id']
            ];
        }
        
        foreach ($this->configData['nic_configuration'] as $nic) {
            $components[] = [
                'type' => 'nic',
                'uuid' => $nic['uuid'],
                'id' => $nic['id']
            ];
        }
        
        foreach ($this->configData['caddy_configuration'] as $caddy) {
            $components[] = [
                'type' => 'caddy',
                'uuid' => $caddy['uuid'],
                'id' => $caddy['id']
            ];
        }
        
        return $components;
    }
    
    /**
     * Check if configuration is empty
     */
    public function isEmpty() {
        return empty($this->configData['cpu_uuid']) &&
               empty($this->configData['motherboard_uuid']) &&
               empty($this->configData['ram_configuration']) &&
               empty($this->configData['storage_configuration']) &&
               empty($this->configData['nic_configuration']) &&
               empty($this->configData['caddy_configuration']);
    }
    
    /**
     * Get configuration summary
     */
    public function getSummary() {
        return [
            'config_uuid' => $this->configData['config_uuid'],
            'config_name' => $this->configData['config_name'],
            'config_description' => $this->configData['config_description'],
            'cpu_selected' => !empty($this->configData['cpu_uuid']),
            'motherboard_selected' => !empty($this->configData['motherboard_uuid']),
            'ram_count' => count($this->configData['ram_configuration']),
            'storage_count' => count($this->configData['storage_configuration']),
            'nic_count' => count($this->configData['nic_configuration']),
            'caddy_count' => count($this->configData['caddy_configuration']),
            'total_components' => count($this->getSelectedComponents()),
            'configuration_status' => $this->configData['configuration_status'],
            'power_consumption' => $this->configData['power_consumption'],
            'total_cost' => $this->configData['total_cost'],
            'compatibility_score' => $this->configData['compatibility_score']
        ];
    }
    
    /**
     * Save configuration to database
     */
    public function save() {
        try {
            // Check if configuration exists
            $stmt = $this->pdo->prepare("SELECT id FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$this->configData['config_uuid']]);
            $exists = $stmt->fetch();
            
            if ($exists) {
                return $this->update();
            } else {
                return $this->insert();
            }
        } catch (PDOException $e) {
            error_log("Error saving configuration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Insert new configuration
     */
    private function insert() {
        try {
            $sql = "
                INSERT INTO server_configurations (
                    config_uuid, config_name, config_description, cpu_uuid, cpu_id,
                    motherboard_uuid, motherboard_id, ram_configuration, storage_configuration,
                    nic_configuration, caddy_configuration, additional_components,
                    configuration_status, total_cost, power_consumption, compatibility_score,
                    validation_results, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $this->configData['config_uuid'],
                $this->configData['config_name'],
                $this->configData['config_description'],
                $this->configData['cpu_uuid'],
                $this->configData['cpu_id'],
                $this->configData['motherboard_uuid'],
                $this->configData['motherboard_id'],
                json_encode($this->configData['ram_configuration']),
                json_encode($this->configData['storage_configuration']),
                json_encode($this->configData['nic_configuration']),
                json_encode($this->configData['caddy_configuration']),
                json_encode($this->configData['additional_components']),
                $this->configData['configuration_status'],
                $this->configData['total_cost'],
                $this->configData['power_consumption'],
                $this->configData['compatibility_score'],
                json_encode($this->configData['validation_results']),
                $this->configData['created_by'],
                $this->configData['created_at']
            ]);
            
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error inserting configuration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update existing configuration
     */
    private function update() {
        try {
            $sql = "
                UPDATE server_configurations SET
                    config_name = ?, config_description = ?, cpu_uuid = ?, cpu_id = ?,
                    motherboard_uuid = ?, motherboard_id = ?, ram_configuration = ?,
                    storage_configuration = ?, nic_configuration = ?, caddy_configuration = ?,
                    additional_components = ?, configuration_status = ?, total_cost = ?,
                    power_consumption = ?, compatibility_score = ?, validation_results = ?,
                    updated_by = ?, updated_at = CURRENT_TIMESTAMP
                WHERE config_uuid = ?
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $this->configData['config_name'],
                $this->configData['config_description'],
                $this->configData['cpu_uuid'],
                $this->configData['cpu_id'],
                $this->configData['motherboard_uuid'],
                $this->configData['motherboard_id'],
                json_encode($this->configData['ram_configuration']),
                json_encode($this->configData['storage_configuration']),
                json_encode($this->configData['nic_configuration']),
                json_encode($this->configData['caddy_configuration']),
                json_encode($this->configData['additional_components']),
                $this->configData['configuration_status'],
                $this->configData['total_cost'],
                $this->configData['power_consumption'],
                $this->configData['compatibility_score'],
                json_encode($this->configData['validation_results']),
                $this->configData['updated_by'],
                $this->configData['config_uuid']
            ]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Error updating configuration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete configuration
     */
    public function delete() {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM server_configurations WHERE config_uuid = ?");
            $stmt->execute([$this->configData['config_uuid']]);
            return true;
        } catch (PDOException $e) {
            error_log("Error deleting configuration: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clone configuration
     */
    public function clone($newName = null) {
        $clonedData = $this->configData;
        $clonedData['config_uuid'] = $this->generateUUID();
        $clonedData['config_name'] = $newName ?? ($this->configData['config_name'] . ' (Copy)');
        $clonedData['configuration_status'] = 0; // Draft
        $clonedData['created_at'] = date('Y-m-d H:i:s');
        $clonedData['updated_at'] = date('Y-m-d H:i:s');
        
        // Remove ID fields
        unset($clonedData['id']);
        
        return new self($this->pdo, $clonedData);
    }
    
    /**
     * Generate UUID
     */
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Validate configuration
     */
    public function validate() {
        $errors = [];
        
        if (empty($this->configData['config_name'])) {
            $errors[] = 'Configuration name is required';
        }
        
        if (empty($this->configData['cpu_uuid']) && empty($this->configData['motherboard_uuid'])) {
            $errors[] = 'At least CPU or Motherboard must be selected';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Get configuration statistics
     */
    public function getStatistics() {
        $components = $this->getSelectedComponents();
        
        return [
            'total_components' => count($components),
            'component_breakdown' => [
                'cpu' => !empty($this->configData['cpu_uuid']) ? 1 : 0,
                'motherboard' => !empty($this->configData['motherboard_uuid']) ? 1 : 0,
                'ram' => count($this->configData['ram_configuration']),
                'storage' => count($this->configData['storage_configuration']),
                'nic' => count($this->configData['nic_configuration']),
                'caddy' => count($this->configData['caddy_configuration'])
            ],
            'estimated_power' => $this->configData['power_consumption'],
            'estimated_cost' => $this->configData['total_cost'],
            'compatibility_score' => $this->configData['compatibility_score'],
            'configuration_status' => $this->configData['configuration_status']
        ];
    }
}
?>