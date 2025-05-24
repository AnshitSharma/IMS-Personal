<?php
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

session_start();

// Check if user is logged in
if (!isUserLoggedIn($pdo)) {
    http_response_code(401);
    echo "Unauthorized";
    exit();
}

$componentType = isset($_GET['type']) ? $_GET['type'] : '';

// Validate component type
$validTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
if (!in_array($componentType, $validTypes)) {
    http_response_code(400);
    echo "Invalid component type";
    exit();
}

// Load component data from JSON files
function loadComponentData($type) {
    $components = [];
    
    switch($type) {
        case 'cpu':
            $jsonFile = __DIR__ . '/../../All JSON/cpu jsons/Cpu details level 3.json';
            break;
        case 'motherboard':
            $jsonFile = __DIR__ . '/../../All JSON/motherboad jsons/motherboard level 3.json';
            break;
        case 'ram':
            $jsonFile = __DIR__ . '/../../All JSON/Ram JSON/ram_detail.json';
            break;
        case 'storage':
            $jsonFile = __DIR__ . '/../../All JSON/storage jsons/storagedetail.json';
            break;
        case 'caddy':
            $jsonFile = __DIR__ . '/../../All JSON/caddy json/caddy_details.json';
            break;
        case 'nic':
            // For NIC, we'll use a simplified structure since there's no specific JSON
            return [];
        default:
            return [];
    }
    
    if (file_exists($jsonFile)) {
        $jsonContent = file_get_contents($jsonFile);
        $data = json_decode($jsonContent, true);
        
        // Process data based on component type
        if ($type == 'cpu') {
            foreach ($data as $brand) {
                foreach ($brand['models'] as $model) {
                    $components[] = [
                        'uuid' => $model['UUID'] ?? $model['uuid'] ?? '',
                        'name' => $model['model'],
                        'brand' => $brand['brand'],
                        'series' => $brand['series'],
                        'details' => [
                            'Architecture' => $model['architecture'] ?? 'N/A',
                            'Cores' => $model['cores'] ?? 'N/A',
                            'Threads' => $model['threads'] ?? 'N/A',
                            'Base Frequency' => ($model['base_frequency_GHz'] ?? 'N/A') . ' GHz',
                            'Max Frequency' => ($model['max_frequency_GHz'] ?? 'N/A') . ' GHz',
                            'TDP' => ($model['tdp_W'] ?? 'N/A') . 'W',
                            'Socket' => $model['socket'] ?? 'N/A'
                        ]
                    ];
                }
            }
        } elseif ($type == 'motherboard') {
            foreach ($data as $brand) {
                foreach ($brand['models'] as $model) {
                    $components[] = [
                        'uuid' => $model['inventory']['UUID'] ?? '',
                        'name' => $model['model'],
                        'brand' => $brand['brand'],
                        'series' => $brand['series'],
                        'details' => [
                            'Form Factor' => $model['form_factor'] ?? 'N/A',
                            'Socket' => $model['socket']['type'] ?? 'N/A',
                            'Chipset' => $model['chipset'] ?? 'N/A',
                            'Memory Type' => $model['memory']['type'] ?? 'N/A',
                            'Max Memory' => $model['memory']['max_capacity_TB'] ?? 'N/A' . ' TB',
                            'Memory Slots' => $model['memory']['slots'] ?? 'N/A',
                            'PCIe Slots' => count($model['expansion_slots']['pcie_slots'] ?? [])
                        ]
                    ];
                }
            }
        } elseif ($type == 'ram') {
            if (isset($data['name']) && is_array($data['name'])) {
                foreach ($data['name'] as $ram) {
                    $components[] = [
                        'uuid' => $ram['UUID'] ?? '',
                        'name' => $ram['manufacturer'] . ' ' . $ram['part_number'],
                        'brand' => $ram['manufacturer'],
                        'series' => $ram['type'] . ' ' . $ram['subtype'],
                        'details' => [
                            'Type' => $ram['type'] ?? 'N/A',
                            'Size' => ($ram['size'] ?? 'N/A') . 'GB',
                            'Frequency' => ($ram['frequency_MHz'] ?? 'N/A') . ' MHz',
                            'Latency' => $ram['Latency'] ?? 'N/A',
                            'Form Factor' => $ram['Form_Factor'] ?? 'N/A',
                            'Voltage' => $ram['voltage'] ?? 'N/A',
                            'ECC' => $ram['ECC'] ?? 'N/A'
                        ]
                    ];
                }
            }
        } elseif ($type == 'storage') {
            if (isset($data['storage_specifications'])) {
                foreach ($data['storage_specifications'] as $storage) {
                    $uuid = md5($storage['name'] . time() . rand()); // Generate UUID
                    $components[] = [
                        'uuid' => $uuid,
                        'name' => $storage['name'],
                        'brand' => 'Generic',
                        'series' => $storage['interface'] ?? '',
                        'details' => [
                            'Interface' => $storage['interface'] ?? 'N/A',
                            'Capacities' => implode(', ', array_map(function($cap) { return $cap . 'GB'; }, $storage['capacity_GB'] ?? [])),
                            'Read Speed' => ($storage['read_speed_MBps'] ?? 'N/A') . ' MB/s',
                            'Write Speed' => ($storage['write_speed_MBps'] ?? 'N/A') . ' MB/s',
                            'Power (Idle)' => ($storage['power_consumption_W']['idle'] ?? 'N/A') . 'W',
                            'Power (Active)' => ($storage['power_consumption_W']['active'] ?? 'N/A') . 'W'
                        ]
                    ];
                }
            }
        } elseif ($type == 'caddy') {
            if (isset($data['caddies'])) {
                foreach ($data['caddies'] as $caddy) {
                    $uuid = md5($caddy['model'] . time() . rand()); // Generate UUID
                    $components[] = [
                        'uuid' => $uuid,
                        'name' => $caddy['model'],
                        'brand' => 'Generic',
                        'series' => $caddy['compatibility']['drive_type'][0] ?? '',
                        'details' => [
                            'Drive Type' => implode(', ', $caddy['compatibility']['drive_type'] ?? []),
                            'Size' => $caddy['compatibility']['size'] ?? 'N/A',
                            'Interface' => $caddy['compatibility']['interface'] ?? 'N/A',
                            'Material' => $caddy['material'] ?? 'N/A',
                            'Weight' => $caddy['weight'] ?? 'N/A',
                            'Connector' => $caddy['connector'] ?? 'N/A'
                        ]
                    ];
                }
            }
        }
    }
    
    return $components;
}

$availableComponents = loadComponentData($componentType);

?>

<style>
    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #374151;
        margin-bottom: 0.5rem;
    }

    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 10px 12px;
        border: 2px solid #e5e7eb;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .form-input:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .form-textarea {
        min-height: 100px;
        resize: vertical;
    }

    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .form-actions {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    .btn-primary:hover:not(:disabled) {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    }

    .btn-secondary {
        background: #e5e7eb;
        color: #374151;
    }

    .btn-secondary:hover {
        background: #d1d5db;
    }

    .btn:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }

    .error-message, .success-message {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 1rem;
        font-size: 14px;
    }

    .error-message {
        background: #fee2e2;
        color: #dc2626;
    }

    .success-message {
        background: #d1fae5;
        color: #065f46;
        text-align: center;
    }

    .component-selection {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 0.5rem;
    }

    .component-option {
        display: flex;
        align-items: flex-start;
        padding: 1rem;
        margin-bottom: 0.5rem;
        background: #f9fafb;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .component-option:hover {
        background: #f3f4f6;
    }

    .component-option input[type="radio"] {
        margin-right: 1rem;
        margin-top: 0.25rem;
    }

    .component-option.selected {
        background: #e0e7ff;
        border: 2px solid #667eea;
    }

    .component-info {
        flex: 1;
    }

    .component-name {
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 0.25rem;
    }

    .component-brand {
        font-size: 12px;
        color: #6b7280;
        margin-bottom: 0.5rem;
    }

    .component-details {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.5rem;
        font-size: 12px;
    }

    .detail-item {
        display: flex;
        justify-content: space-between;
        color: #6b7280;
    }

    .detail-label {
        font-weight: 500;
    }

    .spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid #fff;
        border-radius: 50%;
        border-top-color: transparent;
        animation: spin 0.8s linear infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .required {
        color: #ef4444;
    }

    .form-hint {
        font-size: 12px;
        color: #6b7280;
        margin-top: 0.25rem;
    }

    .section-title {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 1rem;
        padding-bottom: 0.5rem;
        border-bottom: 1px solid #e5e7eb;
    }
</style>

<form id="addComponentForm" onsubmit="submitComponentForm(event, '<?php echo $componentType; ?>')">
    <?php if ($componentType != 'nic' && !empty($availableComponents)): ?>
    <div class="form-group">
        <label class="form-label">
            Select <?php echo ucfirst($componentType); ?> Model <span class="required">*</span>
        </label>
        <div class="component-selection">
            <?php foreach ($availableComponents as $index => $component): ?>
            <label class="component-option" onclick="selectComponent(this)">
                <input type="radio" name="component_uuid" value="<?php echo htmlspecialchars($component['uuid']); ?>" required>
                <div class="component-info">
                    <div class="component-name"><?php echo htmlspecialchars($component['name']); ?></div>
                    <div class="component-brand"><?php echo htmlspecialchars($component['brand'] . ' - ' . $component['series']); ?></div>
                    <div class="component-details">
                        <?php foreach ($component['details'] as $label => $value): ?>
                        <div class="detail-item">
                            <span class="detail-label"><?php echo htmlspecialchars($label); ?>:</span>
                            <span><?php echo htmlspecialchars($value); ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="section-title">Inventory Details</div>
    
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">
                Serial Number <span class="required">*</span>
            </label>
            <input type="text" name="serial_number" class="form-input" required 
                   placeholder="Enter serial number">
        </div>
        
        <div class="form-group">
            <label class="form-label">
                Status <span class="required">*</span>
            </label>
            <select name="status" class="form-select" required>
                <option value="1" selected>Available</option>
                <option value="2">In Use</option>
                <option value="0">Failed/Decommissioned</option>
            </select>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Server UUID</label>
            <input type="text" name="server_uuid" class="form-input" 
                   placeholder="UUID of server (if in use)">
            <span class="form-hint">Enter if component is installed in a server</span>
        </div>
        
        <div class="form-group">
            <label class="form-label">Location</label>
            <input type="text" name="location" class="form-input" 
                   placeholder="e.g., Datacenter North">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Rack Position</label>
        <input type="text" name="rack_position" class="form-input" 
               placeholder="e.g., Rack A3-12">
    </div>

    <?php if ($componentType == 'nic'): ?>
    <div class="section-title">Network Configuration</div>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">MAC Address</label>
            <input type="text" name="mac_address" class="form-input" 
                   placeholder="00:00:00:00:00:00"
                   pattern="^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$">
            <span class="form-hint">Format: XX:XX:XX:XX:XX:XX</span>
        </div>
        
        <div class="form-group">
            <label class="form-label">IP Address</label>
            <input type="text" name="ip_address" class="form-input" 
                   placeholder="192.168.1.100">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Network Name</label>
        <input type="text" name="network_name" class="form-input" 
               placeholder="e.g., Internal-Production">
    </div>
    <?php endif; ?>

    <div class="section-title">Additional Information</div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Purchase Date</label>
            <input type="date" name="purchase_date" class="form-input">
        </div>
        
        <div class="form-group">
            <label class="form-label">Warranty End Date</label>
            <input type="date" name="warranty_end_date" class="form-input">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Flag/Category</label>
        <input type="text" name="flag" class="form-input" 
               placeholder="e.g., Production, Backup, Testing">
    </div>

    <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-textarea" 
                  placeholder="Additional information about this component"></textarea>
    </div>

    <div class="form-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">
            Add <?php echo ucfirst($componentType); ?>
        </button>
    </div>
</form>

<script>
function selectComponent(element) {
    // Remove selected class from all options
    document.querySelectorAll('.component-option').forEach(opt => {
        opt.classList.remove('selected');
    });
    
    // Add selected class to clicked option
    element.classList.add('selected');
    
    // Check the radio button
    element.querySelector('input[type="radio"]').checked = true;
}
</script>