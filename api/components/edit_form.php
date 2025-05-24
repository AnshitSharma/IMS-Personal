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

    .error-message {
        background: #fee2e2;
        color: #dc2626;
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 1rem;
        font-size: 14px;
    }

    .info-box {
        background: #f0f4ff;
        border: 1px solid #c7d2fe;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1.5rem;
    }

    .info-title {
        font-weight: 600;
        color: #4338ca;
        margin-bottom: 0.5rem;
    }

    .info-item {
        display: flex;
        justify-content: space-between;
        padding: 0.25rem 0;
        font-size: 14px;
    }

    .info-label {
        color: #6b7280;
    }

    .info-value {
        color: #1f2937;
        font-weight: 500;
        font-family: monospace;
        font-size: 12px;
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

<form id="editComponentForm" onsubmit="submitEditForm(event, '<?php echo $componentType; ?>', <?php echo $componentId; ?>)">
    
    <div class="info-box">
        <div class="info-title">Component Information</div>
        <div class="info-item">
            <span class="info-label">Component UUID:</span>
            <span class="info-value"><?php echo htmlspecialchars($component['UUID']); ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Serial Number:</span>
            <span class="info-value"><?php echo htmlspecialchars($component['SerialNumber']); ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Created:</span>
            <span class="info-value"><?php echo date('M d, Y H:i', strtotime($component['CreatedAt'])); ?></span>
        </div>
        <div class="info-item">
            <span class="info-label">Last Updated:</span>
            <span class="info-value"><?php echo date('M d, Y H:i', strtotime($component['UpdatedAt'])); ?></span>
        </div>
    </div>

    <div class="section-title">Editable Details</div>
    
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Serial Number</label>
            <input type="text" class="form-input" value="<?php echo htmlspecialchars($component['SerialNumber']); ?>" disabled>
            <span class="form-hint">Serial number cannot be changed</span>
        </div>
        
        <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" required>
                <option value="1" <?php echo $component['Status'] == 1 ? 'selected' : ''; ?>>Available</option>
                <option value="2" <?php echo $component['Status'] == 2 ? 'selected' : ''; ?>>In Use</option>
                <option value="0" <?php echo $component['Status'] == 0 ? 'selected' : ''; ?>>Failed/Decommissioned</option>
            </select>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Server UUID</label>
            <input type="text" name="server_uuid" class="form-input" 
                   value="<?php echo htmlspecialchars($component['ServerUUID'] ?? ''); ?>"
                   placeholder="UUID of server (if in use)">
            <span class="form-hint">Enter if component is installed in a server</span>
        </div>
        
        <div class="form-group">
            <label class="form-label">Location</label>
            <input type="text" name="location" class="form-input" 
                   value="<?php echo htmlspecialchars($component['Location'] ?? ''); ?>"
                   placeholder="e.g., Datacenter North">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Rack Position</label>
        <input type="text" name="rack_position" class="form-input" 
               value="<?php echo htmlspecialchars($component['RackPosition'] ?? ''); ?>"
               placeholder="e.g., Rack A3-12">
    </div>

    <?php if ($componentType == 'nic'): ?>
    <div class="section-title">Network Configuration</div>
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">MAC Address</label>
            <input type="text" name="mac_address" class="form-input" 
                   value="<?php echo htmlspecialchars($component['MacAddress'] ?? ''); ?>"
                   placeholder="00:00:00:00:00:00"
                   pattern="^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$">
            <span class="form-hint">Format: XX:XX:XX:XX:XX:XX</span>
        </div>
        
        <div class="form-group">
            <label class="form-label">IP Address</label>
            <input type="text" name="ip_address" class="form-input" 
                   value="<?php echo htmlspecialchars($component['IPAddress'] ?? ''); ?>"
                   placeholder="192.168.1.100">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Network Name</label>
        <input type="text" name="network_name" class="form-input" 
               value="<?php echo htmlspecialchars($component['NetworkName'] ?? ''); ?>"
               placeholder="e.g., Internal-Production">
    </div>
    <?php endif; ?>

    <div class="section-title">Additional Information</div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Purchase Date</label>
            <input type="date" name="purchase_date" class="form-input"
                   value="<?php echo $component['PurchaseDate'] ?? ''; ?>">
        </div>
        
        <div class="form-group">
            <label class="form-label">Warranty End Date</label>
            <input type="date" name="warranty_end_date" class="form-input"
                   value="<?php echo $component['WarrantyEndDate'] ?? ''; ?>">
        </div>
    </div>

    <div class="form-group">
        <label class="form-label">Flag/Category</label>
        <input type="text" name="flag" class="form-input" 
               value="<?php echo htmlspecialchars($component['Flag'] ?? ''); ?>"
               placeholder="e.g., Production, Backup, Testing">
    </div>

    <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea name="notes" class="form-textarea" 
                  placeholder="Additional information about this component"><?php echo htmlspecialchars($component['Notes'] ?? ''); ?></textarea>
    </div>

    <div class="form-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">
            Update <?php echo ucfirst($componentType); ?>
        </button>
    </div>
</form>