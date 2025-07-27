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
$componentId = isset($_GET['id']) ? $_GET['id'] : '';

// Validate component type
$validTypes = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
if (!in_array($componentType, $validTypes)) {
    http_response_code(400);
    echo "Invalid component type";
    exit();
}

// Table mapping
$tableMap = [
    'cpu' => 'cpuinventory',
    'ram' => 'raminventory',
    'storage' => 'storageinventory',
    'motherboard' => 'motherboardinventory',
    'nic' => 'nicinventory',
    'caddy' => 'caddyinventory'
];

// Get component data
$component = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM {$tableMap[$componentType]} WHERE ID = :id");
    $stmt->bindParam(':id', $componentId, PDO::PARAM_INT);
    $stmt->execute();
    $component = $stmt->fetch();
    
    if (!$component) {
        http_response_code(404);
        echo "Component not found";
        exit();
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo "Database error";
    exit();
}

// Load JSON data for cascading dropdowns
function loadJSONData($type) {
    $jsonPaths = [
        'cpu' => [
            'level1' => __DIR__ . '/../../All JSON/cpu jsons/Cpu base level 1.json',
            'level2' => __DIR__ . '/../../All JSON/cpu jsons/Cpu family level 2.json',
            'level3' => __DIR__ . '/../../All JSON/cpu jsons/Cpu details level 3.json'
        ],
        'motherboard' => [
            'level1' => __DIR__ . '/../../All JSON/motherboad jsons/motherboard level 1.json',
            'level3' => __DIR__ . '/../../All JSON/motherboad jsons/motherboard level 3.json'
        ],
        'ram' => [
            'level3' => __DIR__ . '/../../All JSON/Ram JSON/ram_detail.json'
        ],
        'storage' => [
            'level3' => __DIR__ . '/../../All JSON/storage jsons/storagedetail.json'
        ],
        'caddy' => [
            'level3' => __DIR__ . '/../../All JSON/caddy json/caddy_details.json'
        ]
    ];
    
    $data = [];
    if (isset($jsonPaths[$type])) {
        foreach ($jsonPaths[$type] as $level => $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                $data[$level] = json_decode($content, true);
            }
        }
    }
    
    return $data;
}

$jsonData = loadJSONData($componentType);

// Find current component details from JSON based on UUID
function findComponentInJSON($uuid, $jsonData) {
    if (empty($uuid) || empty($jsonData['level3'])) {
        return null;
    }
    
    foreach ($jsonData['level3'] as $brandData) {
        if (isset($brandData['models'])) {
            foreach ($brandData['models'] as $model) {
                $modelUUID = $model['UUID'] ?? $model['uuid'] ?? $model['inventory']['UUID'] ?? '';
                if ($modelUUID === $uuid) {
                    return [
                        'brand' => $brandData['brand'],
                        'series' => $brandData['series'] ?? '',
                        'model' => $model,
                        'modelName' => $model['model'] ?? $model['name'] ?? ''
                    ];
                }
            }
        }
    }
    return null;
}

$currentComponentData = findComponentInJSON($component['UUID'], $jsonData);
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

    .form-input:disabled {
        background: #f3f4f6;
        color: #6b7280;
        cursor: not-allowed;
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

    .form-row-three {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 1rem;
    }

    .form-actions {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 2rem;
        padding-top: 1rem;
        border-top: 1px solid #e5e7eb;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-primary {
        background: #667eea;
        color: white;
    }

    .btn-primary:hover {
        background: #5a67d8;
    }

    .btn-secondary {
        background: #f3f4f6;
        color: #374151;
    }

    .btn-secondary:hover {
        background: #e5e7eb;
    }

    .cascade-section {
        background: #f8fafc;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        border: 1px solid #e2e8f0;
    }

    .cascade-title {
        font-weight: 600;
        color: #2d3748;
        margin-bottom: 1rem;
        font-size: 16px;
    }

    .component-details {
        background: #fff;
        padding: 1rem;
        border-radius: 6px;
        border: 1px solid #e2e8f0;
        margin-top: 1rem;
    }

    .detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 0.5rem;
    }

    .detail-item {
        display: flex;
        justify-content: space-between;
        padding: 0.25rem 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .detail-label {
        font-weight: 500;
        color: #64748b;
    }

    .detail-value {
        color: #1e293b;
    }

    .loading {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 3px solid #f3f3f3;
        border-top: 3px solid #667eea;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .hidden {
        display: none !important;
    }

    .readonly-info {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 0.75rem;
        border-radius: 6px;
        font-size: 13px;
        color: #64748b;
        margin-bottom: 1rem;
    }
</style>

<form id="componentEditForm" data-type="<?php echo $componentType; ?>" data-id="<?php echo $componentId; ?>">
    <!-- Component Selection Section (Read-only in edit mode) -->
    <?php if ($componentType !== 'nic' && !empty($jsonData) && $currentComponentData): ?>
    <div class="cascade-section">
        <div class="cascade-title">Component Specifications (Read-only)</div>
        
        <div class="readonly-info">
            <strong>Note:</strong> Component specifications cannot be changed during edit. To change the component type, delete this item and create a new one.
        </div>
        
        <!-- Brand, Series, Model Display (Read-only) -->
        <div class="form-row-three">
            <div class="form-group">
                <label class="form-label">Brand</label>
                <input type="text" class="form-input" value="<?php echo htmlspecialchars($currentComponentData['brand']); ?>" disabled>
            </div>

            <div class="form-group">
                <label class="form-label">Series</label>
                <input type="text" class="form-input" value="<?php echo htmlspecialchars($currentComponentData['series']); ?>" disabled>
            </div>

            <div class="form-group">
                <label class="form-label">Model</label>
                <input type="text" class="form-input" value="<?php echo htmlspecialchars($currentComponentData['modelName']); ?>" disabled>
            </div>
        </div>

        <!-- Component Details Display -->
        <div class="component-details">
            <h4>Component Details</h4>
            <div class="detail-grid">
                <?php 
                if ($componentType === 'cpu' && isset($currentComponentData['model'])):
                    $model = $currentComponentData['model'];
                    $details = [
                        'Architecture' => $model['architecture'] ?? 'N/A',
                        'Cores' => $model['cores'] ?? 'N/A',
                        'Threads' => $model['threads'] ?? 'N/A',
                        'Base Frequency' => ($model['base_frequency_GHz'] ?? 'N/A') . ($model['base_frequency_GHz'] ? ' GHz' : ''),
                        'Max Frequency' => ($model['max_frequency_GHz'] ?? 'N/A') . ($model['max_frequency_GHz'] ? ' GHz' : ''),
                        'TDP' => ($model['tdp_W'] ?? 'N/A') . ($model['tdp_W'] ? 'W' : ''),
                        'Socket' => $model['socket'] ?? 'N/A'
                    ];
                    
                    foreach ($details as $label => $value): ?>
                        <div class="detail-item">
                            <span class="detail-label"><?php echo $label; ?>:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($value); ?></span>
                        </div>
                    <?php endforeach;
                elseif ($componentType === 'motherboard' && isset($currentComponentData['model'])):
                    $model = $currentComponentData['model'];
                    $details = [
                        'Form Factor' => $model['form_factor'] ?? 'N/A',
                        'Socket' => $model['socket'] ?? 'N/A',
                        'Chipset' => $model['chipset'] ?? 'N/A',
                        'Memory Slots' => $model['memory_slots'] ?? 'N/A',
                        'Max Memory' => $model['max_memory'] ?? 'N/A',
                        'PCIe Slots' => $model['pcie_slots'] ?? 'N/A'
                    ];
                    
                    foreach ($details as $label => $value): ?>
                        <div class="detail-item">
                            <span class="detail-label"><?php echo $label; ?>:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($value); ?></span>
                        </div>
                    <?php endforeach;
                endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- UUID Fields -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Component UUID</label>
            <input type="text" id="componentUUID" name="UUID" class="form-input" value="<?php echo htmlspecialchars($component['UUID']); ?>" readonly>
            <small style="color: #64748b; font-size: 12px;">UUID cannot be modified</small>
        </div>
        <div class="form-group">
            <label class="form-label">Server UUID</label>
            <input type="text" id="serverUUID" name="ServerUUID" class="form-input" value="<?php echo htmlspecialchars($component['ServerUUID'] ?? ''); ?>" placeholder="Optional - Server UUID if component is installed">
        </div>
    </div>

    <!-- Basic Component Information -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Serial Number *</label>
            <input type="text" id="serialNumber" name="SerialNumber" class="form-input" value="<?php echo htmlspecialchars($component['SerialNumber']); ?>" required placeholder="Enter manufacturer serial number">
        </div>

        <div class="form-group">
            <label class="form-label">Status *</label>
            <select id="status" name="Status" class="form-select" required>
                <option value="1" <?php echo $component['Status'] == 1 ? 'selected' : ''; ?>>Available</option>
                <option value="2" <?php echo $component['Status'] == 2 ? 'selected' : ''; ?>>In Use</option>
                <option value="0" <?php echo $component['Status'] == 0 ? 'selected' : ''; ?>>Failed/Decommissioned</option>
            </select>
        </div>
    </div>

    <!-- Location Information -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Location</label>
            <input type="text" id="location" name="Location" class="form-input" value="<?php echo htmlspecialchars($component['Location'] ?? ''); ?>" placeholder="e.g., Datacenter A, Warehouse East">
        </div>

        <div class="form-group">
            <label class="form-label">Rack Position</label>
            <input type="text" id="rackPosition" name="RackPosition" class="form-input" value="<?php echo htmlspecialchars($component['RackPosition'] ?? ''); ?>" placeholder="e.g., Rack B4, Shelf A2">
        </div>
    </div>

    <!-- Date Information -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Purchase Date</label>
            <input type="date" id="purchaseDate" name="PurchaseDate" class="form-input" value="<?php echo $component['PurchaseDate']; ?>">
        </div>

        <div class="form-group">
            <label class="form-label">Installation Date</label>
            <input type="date" id="installationDate" name="InstallationDate" class="form-input" value="<?php echo $component['InstallationDate']; ?>">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Warranty End Date</label>
            <input type="date" id="warrantyEndDate" name="WarrantyEndDate" class="form-input" value="<?php echo $component['WarrantyEndDate']; ?>">
        </div>

        <div class="form-group">
            <label class="form-label">Flag</label>
            <select id="flag" name="Flag" class="form-select">
                <option value="" <?php echo empty($component['Flag']) ? 'selected' : ''; ?>>No Flag</option>
                <option value="Backup" <?php echo $component['Flag'] == 'Backup' ? 'selected' : ''; ?>>Backup</option>
                <option value="Critical" <?php echo $component['Flag'] == 'Critical' ? 'selected' : ''; ?>>Critical</option>
                <option value="Maintenance" <?php echo $component['Flag'] == 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                <option value="Testing" <?php echo $component['Flag'] == 'Testing' ? 'selected' : ''; ?>>Testing</option>
            </select>
        </div>
    </div>

    <!-- Notes -->
    <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea id="notes" name="Notes" class="form-textarea" placeholder="Additional notes or specifications..."><?php echo htmlspecialchars($component['Notes'] ?? ''); ?></textarea>
    </div>

    <div class="form-actions">
        <button type="button" class="btn btn-secondary" id="modalCancel">Cancel</button>
        <button type="submit" class="btn btn-primary">
            <span id="submitText">Update <?php echo ucfirst($componentType); ?></span>
            <span id="submitLoader" class="loading hidden"></span>
        </button>
    </div>
</form>

<script>
// Store component data
window.componentType = '<?php echo $componentType; ?>';
window.componentId = '<?php echo $componentId; ?>';

// Form submission handler
document.getElementById('componentEditForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const submitText = document.getElementById('submitText');
    const submitLoader = document.getElementById('submitLoader');
    
    // Disable submit button and show loading
    submitBtn.disabled = true;
    submitText.classList.add('hidden');
    submitLoader.classList.remove('hidden');
    
    const formData = new FormData(this);
    
    // Add action for API
    formData.append('action', `${window.componentType}-update`);
    formData.append('id', window.componentId);
    
    // Submit to API
    fetch('/bdc_ims/api/api.php', {
        method: 'POST',
        body: formData,
        headers: {
            'Authorization': 'Bearer ' + (localStorage.getItem('auth_token') || '')
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            if (window.utils && window.utils.showAlert) {
                window.utils.showAlert('Component updated successfully', 'success');
            } else {
                alert('Component updated successfully');
            }
            
            // Close modal and refresh list
            if (window.closeModal) {
                window.closeModal();
            }
            if (window.loadComponentList) {
                window.loadComponentList(window.componentType);
            }
        } else {
            throw new Error(data.message || 'Failed to update component');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (window.utils && window.utils.showAlert) {
            window.utils.showAlert(error.message || 'Failed to update component', 'error');
        } else {
            alert(error.message || 'Failed to update component');
        }
    })
    .finally(() => {
        // Re-enable submit button
        submitBtn.disabled = false;
        submitText.classList.remove('hidden');
        submitLoader.classList.add('hidden');
    });
});

// Cancel button handler
document.getElementById('modalCancel').addEventListener('click', function() {
    if (window.closeModal) {
        window.closeModal();
    }
});
</script>