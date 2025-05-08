    <?php
    // inventory_functions.php

    // Include the configuration file
    require_once __DIR__ . '/../config/config.php';



    /**
     * Lists inventory items based on their status
     * 
     * @param string $status - Status to filter by ("Available", "Reserved", "In Use", etc.)
     * @param int $page - Page number for pagination
     * @param int $limit - Number of items per page
     * @return array - JSON response containing filtered inventory items with names and serial numbers
     */
    function listInventory($status = 'Available', $page = 1, $limit = 20) {
        global $conn;
        
        try {
            // Validate status parameter
            $validStatuses = ['Available', 'Reserved', 'In Use', 'Under Maintenance', 'Decommissioned'];
            if (!in_array($status, $validStatuses)) {
                throw new Exception("Invalid status: $status. Valid statuses are: " . implode(', ', $validStatuses));
            }
            
            // Ensure page and limit are positive integers
            $page = max(1, (int)$page);
            $limit = max(1, min(100, (int)$limit)); // Limit between 1 and 100
            
            // Calculate offset for pagination
            $offset = ($page - 1) * $limit;
            
            // Get components with specified status
            $cpus = fetchCpusByStatus($conn, $status, $offset, $limit);
            $motherboards = fetchMotherboardsByStatus($conn, $status, $offset, $limit);
            $storage = fetchStorageByStatus($conn, $status, $offset, $limit);
            $ram = fetchRamByStatus($conn, $status, $offset, $limit);
            $nics = fetchNicsByStatus($conn, $status, $offset, $limit);
            
            // Get total counts
            $totalCpus = getComponentCount($conn, 'cpu_inventory', $status);
            $totalMotherboards = getComponentCount($conn, 'motherboard_inventory', $status);
            $totalStorage = getComponentCount($conn, 'storage_inventory', $status);
            $totalRam = getComponentCount($conn, 'ram_inventory', $status);
            $totalNics = getComponentCount($conn, 'nic_inventory', $status);
            
            // Calculate total items
            $totalCount = $totalCpus + $totalMotherboards + $totalStorage + $totalRam + $totalNics;
            $totalPages = ceil($totalCount / $limit);
            
            // Return response
            return [
                "status" => "success",
                "message" => "Inventory items with status: $status",
                "pagination" => [
                    "page" => $page,
                    "limit" => $limit,
                    "total_pages" => $totalPages,
                    "total_items" => $totalCount
                ],
                "counts" => [
                    "cpus" => $totalCpus,
                    "motherboards" => $totalMotherboards,
                    "storage" => $totalStorage,
                    "ram" => $totalRam,
                    "nics" => $totalNics
                ],
                "data" => [
                    "cpus" => $cpus,
                    "motherboards" => $motherboards,
                    "storage" => $storage,
                    "ram" => $ram,
                    "nics" => $nics
                ]
            ];
        } catch (Exception $e) {
            // Log error
            error_log("Error in listInventory: " . $e->getMessage());
            
            return [
                "status" => "error",
                "message" => "Failed to retrieve inventory: " . $e->getMessage()
            ];
        }
    }

    /**
     * Get count of components with a specific status
     * 
     * @param mysqli $conn - Database connection
     * @param string $table - Table name
     * @param string $status - Status to filter by
     * @return int - Total count
     */
    function getComponentCount($conn, $table, $status) {
        $status = $conn->real_escape_string($status);
        $sql = "SELECT COUNT(*) as count FROM $table WHERE status = '$status'";
        $result = $conn->query($sql);
        
        if ($result && $row = $result->fetch_assoc()) {
            return (int)$row['count'];
        }
        
        return 0;
    }

    /**
     * Fetch CPUs by status
     * 
     * @param mysqli $conn - Database connection
     * @param string $status - Status to filter by
     * @param int $offset - Offset for pagination
     * @param int $limit - Limit for pagination
     * @return array - Formatted CPU data
     */
    function fetchCpusByStatus($conn, $status, $offset, $limit) {
        $status = $conn->real_escape_string($status);
        $offset = (int)$offset;
        $limit = (int)$limit;
        
        $sql = "SELECT brand, model, serial_number, location, condition_state 
                FROM cpu_inventory 
                WHERE status = '$status' 
                ORDER BY brand, model 
                LIMIT $offset, $limit";
        
        $result = $conn->query($sql);
        $cpus = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $cpus[] = [
                    'name' => $row['brand'] . ' ' . $row['model'],
                    'serial_number' => $row['serial_number'] ?: "Not assigned",
                    'location' => $row['location'] ?: "Unknown",
                    'condition' => $row['condition_state'] ?: "Unknown"
                ];
            }
        }
        
        return $cpus;
    }

    /**
     * Fetch motherboards by status
     * 
     * @param mysqli $conn - Database connection
     * @param string $status - Status to filter by
     * @param int $offset - Offset for pagination
     * @param int $limit - Limit for pagination
     * @return array - Formatted motherboard data
     */
    function fetchMotherboardsByStatus($conn, $status, $offset, $limit) {
        $status = $conn->real_escape_string($status);
        $offset = (int)$offset;
        $limit = (int)$limit;
        
        $sql = "SELECT brand, model, serial_number, location, condition_state 
                FROM motherboard_inventory 
                WHERE status = '$status' 
                ORDER BY brand, model 
                LIMIT $offset, $limit";
        
        $result = $conn->query($sql);
        $motherboards = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $motherboards[] = [
                    'name' => $row['brand'] . ' ' . $row['model'],
                    'serial_number' => $row['serial_number'] ?: "Not assigned",
                    'location' => $row['location'] ?: "Unknown",
                    'condition' => $row['condition_state'] ?: "Unknown"
                ];
            }
        }
        
        return $motherboards;
    }

    /**
     * Fetch storage devices by status
     * 
     * @param mysqli $conn - Database connection
     * @param string $status - Status to filter by
     * @param int $offset - Offset for pagination
     * @param int $limit - Limit for pagination
     * @return array - Formatted storage data
     */
    function fetchStorageByStatus($conn, $status, $offset, $limit) {
        $status = $conn->real_escape_string($status);
        $offset = (int)$offset;
        $limit = (int)$limit;
        
        $sql = "SELECT storage_type, interface, capacity_GB, serial_number, location, condition_state 
                FROM storage_inventory 
                WHERE status = '$status' 
                ORDER BY storage_type, capacity_GB 
                LIMIT $offset, $limit";
        
        $result = $conn->query($sql);
        $storage = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $storage[] = [
                    'name' => $row['storage_type'] . ' ' . $row['capacity_GB'] . 'GB ' . $row['interface'],
                    'serial_number' => $row['serial_number'] ?: "Not assigned",
                    'location' => $row['location'] ?: "Unknown",
                    'condition' => $row['condition_state'] ?: "Unknown"
                ];
            }
        }
        
        return $storage;
    }

    /**
     * Fetch RAM modules by status
     * 
     * @param mysqli $conn - Database connection
     * @param string $status - Status to filter by
     * @param int $offset - Offset for pagination
     * @param int $limit - Limit for pagination
     * @return array - Formatted RAM data
     */
    function fetchRamByStatus($conn, $status, $offset, $limit) {
        $status = $conn->real_escape_string($status);
        $offset = (int)$offset;
        $limit = (int)$limit;
        
        $sql = "SELECT ram_type, size_GB, frequency_MHz, manufacturer, serial_number, location, condition_state, ecc 
                FROM ram_inventory 
                WHERE status = '$status' 
                ORDER BY ram_type, size_GB 
                LIMIT $offset, $limit";
        
        $result = $conn->query($sql);
        $ram = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $ecc = $row['ecc'] ? 'ECC' : '';
                $ram[] = [
                    'name' => $row['manufacturer'] . ' ' . $row['size_GB'] . 'GB ' . 
                            $row['ram_type'] . ' ' . $row['frequency_MHz'] . 'MHz ' . $ecc,
                    'serial_number' => $row['serial_number'] ?: "Not assigned",
                    'location' => $row['location'] ?: "Unknown",
                    'condition' => $row['condition_state'] ?: "Unknown"
                ];
            }
        }
        
        return $ram;
    }

    /**
     * Fetch NICs by status
     * 
     * @param mysqli $conn - Database connection
     * @param string $status - Status to filter by
     * @param int $offset - Offset for pagination
     * @param int $limit - Limit for pagination
     * @return array - Formatted NIC data
     */
    function fetchNicsByStatus($conn, $status, $offset, $limit) {
        $status = $conn->real_escape_string($status);
        $offset = (int)$offset;
        $limit = (int)$limit;
        
        $sql = "SELECT nic_type, speed, ports, serial_number, location, condition_state 
                FROM nic_inventory 
                WHERE status = '$status' 
                ORDER BY nic_type, speed 
                LIMIT $offset, $limit";
        
        $result = $conn->query($sql);
        $nics = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $nics[] = [
                    'name' => $row['nic_type'] . ' ' . $row['speed'] . ' ' . $row['ports'] . '-port',
                    'serial_number' => $row['serial_number'] ?: "Not assigned",
                    'location' => $row['location'] ?: "Unknown",
                    'condition' => $row['condition_state'] ?: "Unknown"
                ];
            }
        }
        
        return $nics;
    }

    /**
     * Update the status of an inventory item
     * 
     * @param string $componentType - Type of component (cpu, motherboard, storage, ram, nic)
     * @param string $serialNumber - Serial number of the component
     * @param string $newStatus - New status to set
     * @param int $userId - ID of user making the change
     * @param string $notes - Optional notes about the change
     * @return array - Result of the operation
     */
    function updateInventoryStatus($componentType, $serialNumber, $newStatus, $userId, $notes = '') {
        global $conn;
        
        try {
            // Validate component type
            $validTypes = ['cpu', 'motherboard', 'storage', 'ram', 'nic'];
            if (!in_array($componentType, $validTypes)) {
                throw new Exception("Invalid component type: $componentType");
            }
            
            // Validate status
            $validStatuses = ['Available', 'Reserved', 'In Use', 'Under Maintenance', 'Decommissioned'];
            if (!in_array($newStatus, $validStatuses)) {
                throw new Exception("Invalid status: $newStatus");
            }
            
            // Determine table name
            $tableName = $componentType . '_inventory';
            
            // Get current status
            $serialNumber = $conn->real_escape_string($serialNumber);
            $sql = "SELECT id, status FROM $tableName WHERE serial_number = '$serialNumber'";
            $result = $conn->query($sql);
            
            if (!$result || $result->num_rows === 0) {
                throw new Exception("Component with serial number $serialNumber not found");
            }
            
            $row = $result->fetch_assoc();
            $componentId = $row['id'];
            $currentStatus = $row['status'];
            
            // Update the status
            $newStatus = $conn->real_escape_string($newStatus);
            $sql = "UPDATE $tableName SET status = '$newStatus', updated_at = NOW() WHERE serial_number = '$serialNumber'";
            
            if (!$conn->query($sql)) {
                throw new Exception("Failed to update status: " . $conn->error);
            }
            
            // Log the change
            $userId = (int)$userId;
            $currentStatus = $conn->real_escape_string($currentStatus);
            $notes = $conn->real_escape_string($notes);
            
            $sql = "INSERT INTO inventory_log (component_type, component_id, previous_status, new_status, changed_by, notes) 
                    VALUES ('$componentType', $componentId, '$currentStatus', '$newStatus', $userId, '$notes')";
            
            if (!$conn->query($sql)) {
                error_log("Failed to log inventory change: " . $conn->error);
            }
            
            return [
                "status" => "success",
                "message" => "$componentType status updated from $currentStatus to $newStatus"
            ];
        } catch (Exception $e) {
            error_log("Error in updateInventoryStatus: " . $e->getMessage());
            
            return [
                "status" => "error",
                "message" => $e->getMessage()
            ];
        }
    }

    /**
     * Add a new inventory item
     * 
     * @param string $componentType - Type of component (cpu, motherboard, storage, ram, nic)
     * @param array $data - Component data
     * @return array - Result of the operation
     */
    function addInventoryItem($componentType, $data) {
        global $conn;
        
        try {
            // Validate component type
            $validTypes = ['cpu', 'motherboard', 'storage', 'ram', 'nic'];
            if (!in_array($componentType, $validTypes)) {
                throw new Exception("Invalid component type: $componentType");
            }
            
            // Determine table and required fields based on component type
            $tableName = $componentType . '_inventory';
            $fields = [];
            $values = [];
            
            // Generate SQL based on component type and provided data
            switch ($componentType) {
                case 'cpu':
                    validateRequiredFields($data, ['brand', 'model']);
                    break;
                case 'motherboard':
                    validateRequiredFields($data, ['brand', 'model']);
                    break;
                case 'storage':
                    validateRequiredFields($data, ['storage_type', 'interface', 'capacity_GB']);
                    break;
                case 'ram':
                    validateRequiredFields($data, ['ram_type', 'size_GB']);
                    break;
                case 'nic':
                    validateRequiredFields($data, ['nic_type', 'speed', 'ports']);
                    break;
            }
            
            // Prepare fields and values for SQL
            foreach ($data as $field => $value) {
                $fields[] = $field;
                if (is_null($value)) {
                    $values[] = "NULL";
                } else {
                    $values[] = "'" . $conn->real_escape_string($value) . "'";
                }
            }
            
            // Create SQL query
            $fieldsStr = implode(", ", $fields);
            $valuesStr = implode(", ", $values);
            $sql = "INSERT INTO $tableName ($fieldsStr) VALUES ($valuesStr)";
            
            // Execute query
            if (!$conn->query($sql)) {
                throw new Exception("Failed to add inventory item: " . $conn->error);
            }
            
            $insertId = $conn->insert_id;
            
            return [
                "status" => "success",
                "message" => "$componentType added successfully",
                "id" => $insertId
            ];
        } catch (Exception $e) {
            error_log("Error in addInventoryItem: " . $e->getMessage());
            
            return [
                "status" => "error",
                "message" => $e->getMessage()
            ];
        }
    }

    /**
     * Validate that required fields are present in data
     * 
     * @param array $data - Data to validate
     * @param array $requiredFields - List of required fields
     * @throws Exception if required field is missing
     */
    function validateRequiredFields($data, $requiredFields) {
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new Exception("Missing required field: $field");
            }
        }
    }

    /**
     * API endpoint handler - Only process if accessed directly
     */
    if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    
        header('Content-Type: application/json');
        
        // Handle CORS if needed
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        
        // Handle OPTIONS request for CORS
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        
        // Only allow GET requests for listing inventory
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            // Get and sanitize parameters
            $status = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : 'Available';
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            
            // Generate and output response
            $result = listInventory($status, $page, $limit);
            echo json_encode($result, JSON_PRETTY_PRINT);
        } else {
            http_response_code(405);
            echo json_encode([
                'status' => 'error',
                'message' => 'Method not allowed. Use GET to list inventory.'
            ]);
        }
    }



    function updateInventoryItem($componentType, $serialNumber, $fieldsToUpdate, $userId) {
        global $conn;
        
        try {
            // Validate component type
            $validTypes = ['cpu', 'motherboard', 'storage', 'ram', 'nic'];
            if (!in_array($componentType, $validTypes)) {
                throw new Exception("Invalid component type: $componentType");
            }
            
            // Check if fieldsToUpdate is not empty
            if (empty($fieldsToUpdate) || !is_array($fieldsToUpdate)) {
                throw new Exception("No fields provided for update");
            }
            
            // Determine table name
            $tableName = $componentType . '_inventory';
            
            // Escape serial number
            $serialNumber = $conn->real_escape_string($serialNumber);
            
            // Get current values and check if item exists
            $sql = "SELECT id FROM $tableName WHERE serial_number = '$serialNumber'";
            $result = $conn->query($sql);
            
            if (!$result || $result->num_rows === 0) {
                throw new Exception("Component with serial number $serialNumber not found");
            }
            
            $row = $result->fetch_assoc();
            $componentId = $row['id'];
            
            // Build update query
            $updateParts = [];
            foreach ($fieldsToUpdate as $field => $value) {
                // Skip invalid fields like 'id', 'created_at', 'updated_at'
                if (in_array($field, ['id', 'created_at', 'updated_at'])) {
                    continue;
                }
                
                if (is_null($value)) {
                    $updateParts[] = "$field = NULL";
                } else {
                    $safeValue = $conn->real_escape_string($value);
                    $updateParts[] = "$field = '$safeValue'";
                }
            }
            
            // Add updated_at timestamp
            $updateParts[] = "updated_at = NOW()";
            
            $updateStr = implode(", ", $updateParts);
            $sql = "UPDATE $tableName SET $updateStr WHERE serial_number = '$serialNumber'";
            
            // Execute update
            if (!$conn->query($sql)) {
                throw new Exception("Failed to update component: " . $conn->error);
            }
            
            // Log the change
            $userId = (int)$userId;
            $notes = "Updated fields: " . implode(", ", array_keys($fieldsToUpdate));
            $notes = $conn->real_escape_string($notes);
            
            $sql = "INSERT INTO inventory_log (component_type, component_id, previous_status, new_status, changed_by, notes) 
                    VALUES ('$componentType', $componentId, NULL, NULL, $userId, '$notes')";
            
            if (!$conn->query($sql)) {
                error_log("Failed to log inventory update: " . $conn->error);
            }
            
            return [
                "status" => "success",
                "message" => "$componentType with serial number $serialNumber updated successfully",
                "updated_fields" => array_keys($fieldsToUpdate)
            ];
        } catch (Exception $e) {
            error_log("Error in updateInventoryItem: " . $e->getMessage());
            
            return [
                "status" => "error",
                "message" => $e->getMessage()
            ];
        }
    }

    
    ?>