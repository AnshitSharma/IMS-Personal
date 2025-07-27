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
        display: none;
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
</style>

<form id="componentAddForm" data-type="<?php echo $componentType; ?>">
    <!-- Component Selection Section -->
    <?php if ($componentType !== 'nic' && !empty($jsonData)): ?>
    <div class="cascade-section">
        <div class="cascade-title">Select <?php echo ucfirst($componentType); ?> Specifications</div>
        
        <!-- Brand Selection -->
        <div class="form-row-three">
            <div class="form-group">
                <label class="form-label">Brand *</label>
                <select id="brandSelect" class="form-select" required>
                    <option value="">Select Brand</option>
                    <?php 
                    if (isset($jsonData['level1'])):
                        foreach ($jsonData['level1'] as $brandData): ?>
                            <option value="<?php echo htmlspecialchars($brandData['brand']); ?>">
                                <?php echo htmlspecialchars($brandData['brand']); ?>
                            </option>
                        <?php endforeach;
                    endif; ?>
                </select>
            </div>

            <!-- Series Selection -->
            <div class="form-group">
                <label class="form-label">Series *</label>
                <select id="seriesSelect" class="form-select" required disabled>
                    <option value="">Select Series First</option>
                </select>
            </div>

            <!-- Model Selection -->
            <div class="form-group">
                <label class="form-label">Model *</label>
                <select id="modelSelect" class="form-select" required disabled>
                    <option value="">Select Model</option>
                </select>
            </div>
        </div>

        <!-- Component Details Display -->
        <div id="componentDetails" class="component-details">
            <h4>Component Details</h4>
            <div id="detailsContent" class="detail-grid">
                <!-- Details will be populated here -->
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- UUID Fields -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Component UUID *</label>
            <input type="text" id="componentUUID" name="UUID" class="form-input" readonly required>
        </div>
        <div class="form-group">
            <label class="form-label">Server UUID</label>
            <input type="text" id="serverUUID" name="ServerUUID" class="form-input" placeholder="Optional - Server UUID if component is installed">
        </div>
    </div>

    <!-- Basic Component Information -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Serial Number *</label>
            <input type="text" id="serialNumber" name="SerialNumber" class="form-input" required placeholder="Enter manufacturer serial number">
        </div>

        <div class="form-group">
            <label class="form-label">Status *</label>
            <select id="status" name="Status" class="form-select" required>
                <option value="1">Available</option>
                <option value="2">In Use</option>
                <option value="0">Failed/Decommissioned</option>
            </select>
        </div>
    </div>

    <!-- Location Information -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Location</label>
            <input type="text" id="location" name="Location" class="form-input" placeholder="e.g., Datacenter A, Warehouse East">
        </div>

        <div class="form-group">
            <label class="form-label">Rack Position</label>
            <input type="text" id="rackPosition" name="RackPosition" class="form-input" placeholder="e.g., Rack B4, Shelf A2">
        </div>
    </div>

    <!-- Date Information -->
    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Purchase Date</label>
            <input type="date" id="purchaseDate" name="PurchaseDate" class="form-input">
        </div>

        <div class="form-group">
            <label class="form-label">Installation Date</label>
            <input type="date" id="installationDate" name="InstallationDate" class="form-input">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label class="form-label">Warranty End Date</label>
            <input type="date" id="warrantyEndDate" name="WarrantyEndDate" class="form-input">
        </div>

        <div class="form-group">
            <label class="form-label">Flag</label>
            <select id="flag" name="Flag" class="form-select">
                <option value="">No Flag</option>
                <option value="Backup">Backup</option>
                <option value="Critical">Critical</option>
                <option value="Maintenance">Maintenance</option>
                <option value="Testing">Testing</option>
            </select>
        </div>
    </div>

    <!-- Notes -->
    <div class="form-group">
        <label class="form-label">Notes</label>
        <textarea id="notes" name="Notes" class="form-textarea" placeholder="Additional notes or specifications..."></textarea>
    </div>

    <div class="form-actions">
        <button type="button" class="btn btn-secondary" id="modalCancel">Cancel</button>
        <button type="submit" class="btn btn-primary">
            <span id="submitText">Add <?php echo ucfirst($componentType); ?></span>
            <span id="submitLoader" class="loading hidden"></span>
        </button>
    </div>
</form>

<script>
// Store JSON data in JavaScript
window.componentJSONData = <?php echo json_encode($jsonData); ?>;
window.componentType = '<?php echo $componentType; ?>';

// Component data structure for easy access
let componentData = {
    level1: window.componentJSONData.level1 || [],
    level2: window.componentJSONData.level2 || [],
    level3: window.componentJSONData.level3 || []
};

// DOM elements
const brandSelect = document.getElementById('brandSelect');
const seriesSelect = document.getElementById('seriesSelect');
const modelSelect = document.getElementById('modelSelect');
const componentDetails = document.getElementById('componentDetails');
const detailsContent = document.getElementById('detailsContent');
const componentUUID = document.getElementById('componentUUID');

// Brand change handler
brandSelect?.addEventListener('change', function() {
    const selectedBrand = this.value;
    
    // Reset dependent dropdowns
    seriesSelect.innerHTML = '<option value="">Select Series</option>';
    modelSelect.innerHTML = '<option value="">Select Model</option>';
    seriesSelect.disabled = !selectedBrand;
    modelSelect.disabled = true;
    componentDetails.style.display = 'none';
    componentUUID.value = '';
    
    if (selectedBrand) {
        // Find brand data from level1
        const brandData = componentData.level1.find(b => b.brand === selectedBrand);
        
        if (brandData && brandData.series) {
            // Populate series dropdown
            brandData.series.forEach(series => {
                const option = document.createElement('option');
                option.value = series.name;
                option.textContent = series.name;
                seriesSelect.appendChild(option);
            });
            seriesSelect.disabled = false;
        }
    }
});

// Series change handler
seriesSelect?.addEventListener('change', function() {
    const selectedBrand = brandSelect.value;
    const selectedSeries = this.value;
    
    // Reset model dropdown
    modelSelect.innerHTML = '<option value="">Select Model</option>';
    modelSelect.disabled = true;
    componentDetails.style.display = 'none';
    componentUUID.value = '';
    
    if (selectedBrand && selectedSeries) {
        // Find models from level3 data
        const brandData = componentData.level3.find(b => b.brand === selectedBrand);
        
        if (brandData) {
            let models = [];
            
            // Handle different data structures based on component type
            if (window.componentType === 'cpu') {
                // For CPU, filter by series
                if (brandData.series === selectedSeries || brandData.series.includes(selectedSeries)) {
                    models = brandData.models || [];
                }
            } else if (window.componentType === 'motherboard') {
                // For motherboard, find series in the series array
                const seriesData = brandData.series?.find(s => s.name === selectedSeries);
                if (seriesData && seriesData.models) {
                    models = seriesData.models;
                }
            }
            
            // Populate model dropdown
            models.forEach(model => {
                const option = document.createElement('option');
                const modelName = model.model || model.name || model;
                option.value = modelName;
                option.textContent = modelName;
                option.dataset.modelData = JSON.stringify(model);
                modelSelect.appendChild(option);
            });
            
            if (models.length > 0) {
                modelSelect.disabled = false;
            }
        }
    }
});

// Model change handler
modelSelect?.addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    componentDetails.style.display = 'none';
    componentUUID.value = '';
    
    if (selectedOption.value && selectedOption.dataset.modelData) {
        try {
            const modelData = JSON.parse(selectedOption.dataset.modelData);
            
            // Set UUID
            const uuid = modelData.UUID || modelData.uuid || modelData.inventory?.UUID || '';
            componentUUID.value = uuid;
            
            // Display component details
            displayComponentDetails(modelData);
            componentDetails.style.display = 'block';
            
        } catch (error) {
            console.error('Error parsing model data:', error);
        }
    }
});

function displayComponentDetails(modelData) {
    let detailsHTML = '';
    
    // Different details based on component type
    if (window.componentType === 'cpu') {
        const details = {
            'Architecture': modelData.architecture || 'N/A',
            'Cores': modelData.cores || 'N/A',
            'Threads': modelData.threads || 'N/A',
            'Base Frequency': (modelData.base_frequency_GHz || 'N/A') + (modelData.base_frequency_GHz ? ' GHz' : ''),
            'Max Frequency': (modelData.max_frequency_GHz || 'N/A') + (modelData.max_frequency_GHz ? ' GHz' : ''),
            'TDP': (modelData.tdp_W || 'N/A') + (modelData.tdp_W ? 'W' : ''),
            'Socket': modelData.socket || 'N/A'
        };
        
        for (const [label, value] of Object.entries(details)) {
            detailsHTML += `
                <div class="detail-item">
                    <span class="detail-label">${label}:</span>
                    <span class="detail-value">${value}</span>
                </div>
            `;
        }
    } else if (window.componentType === 'motherboard') {
        const details = {
            'Form Factor': modelData.form_factor || 'N/A',
            'Socket': modelData.socket || 'N/A',
            'Chipset': modelData.chipset || 'N/A',
            'Memory Slots': modelData.memory_slots || 'N/A',
            'Max Memory': modelData.max_memory || 'N/A',
            'PCIe Slots': modelData.pcie_slots || 'N/A'
        };
        
        for (const [label, value] of Object.entries(details)) {
            detailsHTML += `
                <div class="detail-item">
                    <span class="detail-label">${label}:</span>
                    <span class="detail-value">${value}</span>
                </div>
            `;
        }
    }
    
    detailsContent.innerHTML = detailsHTML;
}

// Form submission handler
document.getElementById('componentAddForm').addEventListener('submit', function(e) {
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
    formData.append('action', `${window.componentType}-add`);
    
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
                window.utils.showAlert('Component added successfully', 'success');
            } else {
                alert('Component added successfully');
            }
            
            // Close modal and refresh list
            if (window.closeModal) {
                window.closeModal();
            }
            if (window.loadComponentList) {
                window.loadComponentList(window.componentType);
            }
        } else {
            throw new Error(data.message || 'Failed to add component');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (window.utils && window.utils.showAlert) {
            window.utils.showAlert(error.message || 'Failed to add component', 'error');
        } else {
            alert(error.message || 'Failed to add component');
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