/**
 * Edit Component Form JavaScript
 * forms/js/edit-form.js
 */

class EditComponentForm {
    constructor() {
        this.componentType = null;
        this.componentId = null;
        this.currentComponent = null;
        this.jsonData = {};
        
        this.init();
    }

    async init() {
        console.log('Initializing Edit Component Form...');
        
        // Get parameters from URL
        const urlParams = new URLSearchParams(window.location.search);
        this.componentType = urlParams.get('type');
        this.componentId = urlParams.get('id');
        
        if (!this.componentType || !this.componentId) {
            this.showAlert('Invalid component parameters', 'error');
            this.goBack();
            return;
        }

        document.getElementById('formTitle').textContent = `Edit ${this.componentType.toUpperCase()} Component`;
        
        try {
            // Load component data
            await this.loadComponentData();
            
            // Load JSON data for specifications
            await this.loadJSONData();
            
            // Setup form
            this.setupForm();
            this.setupEventListeners();
            this.showFormSections();
            
        } catch (error) {
            console.error('Error initializing edit form:', error);
            this.showAlert('Failed to load component data', 'error');
        }
        
        console.log('Edit Component Form initialized');
    }

    async loadComponentData() {
        try {
            this.showLoading(true, 'Loading component data...');
            
            const result = await api.components.get(this.componentType, this.componentId);
            
            if (result.success && result.data.component) {
                this.currentComponent = result.data.component;
                this.populateForm();
                this.displayCurrentComponentInfo();
            } else {
                throw new Error('Component not found');
            }
            
        } catch (error) {
            console.error('Error loading component:', error);
            throw error;
        } finally {
            this.showLoading(false);
        }
    }

    async loadJSONData() {
        const jsonPaths = {
            'cpu': {
                'level1': '../../../ims_frontend/All JSON/cpu jsons/Cpu base level 1.json',
                'level2': '../../../ims_frontend/All JSON/cpu jsons/Cpu family level 2.json',
                'level3': '../../../ims_frontend/All JSON/cpu jsons/Cpu details level 3.json'
            },
            'motherboard': {
                'level1': '../../../ims_frontend/All JSON/motherboard jsons/motherboard level 1.json',
                'level3': '../../../ims_frontend/All JSON/motherboard jsons/motherboard level 3.json'
            },
            'ram': {
                'level3': '../../../ims_frontend/All JSON/Ram JSON/ram_detail.json'
            },
            'storage': {
                'level3': '../../../ims_frontend/All JSON/storage jsons/storagedetail.json'
            },
            'nic': {
                'level3': '../../../ims_frontend/All JSON/nic json/nic_details.json'
            },
            'caddy': {
                'level3': '../../../ims_frontend/All JSON/caddy json/caddy_details.json'
            }
        };

        this.jsonData = {};
        const paths = jsonPaths[this.componentType];

        if (paths) {
            for (const [level, path] of Object.entries(paths)) {
                try {
                    const response = await fetch(path);
                    if (response.ok) {
                        this.jsonData[level] = await response.json();
                    }
                } catch (error) {
                    console.warn(`Error loading ${level} data:`, error);
                }
            }
        }
    }

    populateForm() {
        const component = this.currentComponent;
        
        // Basic identification (read-only)
        document.getElementById('componentUUID').value = component.UUID || '';
        document.getElementById('serialNumber').value = component.SerialNumber || '';
        
        // Status and assignment
        document.getElementById('status').value = component.Status || '';
        document.getElementById('serverUUID').value = component.ServerUUID || '';
        
        // Location
        document.getElementById('location').value = component.Location || '';
        document.getElementById('rackPosition').value = component.RackPosition || '';
        
        // Dates
        document.getElementById('purchaseDate').value = component.PurchaseDate || '';
        document.getElementById('installationDate').value = component.InstallationDate || '';
        document.getElementById('warrantyEndDate').value = component.WarrantyEndDate || '';
        
        // Flag and notes
        document.getElementById('flag').value = component.Flag || '';
        document.getElementById('notes').value = component.Notes || '';
        
        // Component-specific fields
        if (this.componentType === 'nic') {
            document.getElementById('macAddress').value = component.MacAddress || '';
            document.getElementById('ipAddress').value = component.IPAddress || '';
            document.getElementById('networkName').value = component.NetworkName || '';
        } else if (this.componentType === 'storage') {
            document.getElementById('capacity').value = component.Capacity || '';
            document.getElementById('storageType').value = component.Type || '';
            document.getElementById('interface').value = component.Interface || '';
        }
    }

    displayCurrentComponentInfo() {
        const component = this.currentComponent;
        const infoContainer = document.getElementById('currentComponentInfo');
        
        // Find component details from JSON
        const jsonDetails = this.findComponentInJSON(component.UUID);
        
        let infoHTML = `
            <h4><i class="fas fa-microchip"></i> ${component.SerialNumber}</h4>
            <div class="spec-grid">
                <div class="spec-item">
                    <span class="spec-label">Status</span>
                    <span class="spec-value">${this.getStatusText(component.Status)}</span>
                </div>
                <div class="spec-item">
                    <span class="spec-label">Location</span>
                    <span class="spec-value">${component.Location || 'Not specified'}</span>
                </div>
        `;

        if (component.ServerUUID) {
            infoHTML += `
                <div class="spec-item">
                    <span class="spec-label">Server UUID</span>
                    <span class="spec-value" style="font-family: monospace; font-size: 11px;">${component.ServerUUID}</span>
                </div>
            `;
        }

        if (jsonDetails) {
            infoHTML += `
                <div class="spec-item">
                    <span class="spec-label">Brand</span>
                    <span class="spec-value">${jsonDetails.brand}</span>
                </div>
                <div class="spec-item">
                    <span class="spec-label">Model</span>
                    <span class="spec-value">${jsonDetails.modelName}</span>
                </div>
            `;

            if (jsonDetails.series) {
                infoHTML += `
                    <div class="spec-item">
                        <span class="spec-label">Series</span>
                        <span class="spec-value">${jsonDetails.series}</span>
                    </div>
                `;
            }
        }

        infoHTML += '</div>';
        infoContainer.innerHTML = infoHTML;
        
        document.getElementById('currentComponentSection').style.display = 'block';
    }

    findComponentInJSON(uuid) {
        if (!uuid || !this.jsonData.level3) {
            return null;
        }

        for (const brandData of this.jsonData.level3) {
            if (brandData.models && Array.isArray(brandData.models)) {
                for (const model of brandData.models) {
                    const modelUUID = model.UUID || model.uuid || model.inventory?.UUID || '';
                    if (modelUUID === uuid) {
                        return {
                            brand: brandData.brand || brandData.manufacturer || '',
                            series: brandData.series || model.series || '',
                            model: model,
                            modelName: model.model || model.name || model.part_number || ''
                        };
                    }
                }
            }
        }
        return null;
    }

    getStatusText(status) {
        const statusMap = {
            0: 'Failed',
            1: 'Available', 
            2: 'In Use'
        };
        return statusMap[status] || 'Unknown';
    }

    setupForm() {
        // Setup component-specific fields
        this.setupComponentSpecificFields();
        
        // Setup validation
        this.setupValidation();
    }

    setupComponentSpecificFields() {
        // Hide all component-specific sections first
        document.getElementById('nicFields').style.display = 'none';
        document.getElementById('storageFields').style.display = 'none';

        const componentSpecificSection = document.getElementById('componentSpecificSection');
        const specificSectionTitle = document.getElementById('specificSectionTitle');

        if (this.componentType === 'nic') {
            specificSectionTitle.textContent = 'Network Interface Details';
            document.getElementById('nicFields').style.display = 'block';
            componentSpecificSection.style.display = 'block';
            this.setupNICValidation();
        } else if (this.componentType === 'storage') {
            specificSectionTitle.textContent = 'Storage Details';
            document.getElementById('storageFields').style.display = 'block';
            componentSpecificSection.style.display = 'block';
        } else {
            componentSpecificSection.style.display = 'none';
        }
    }

    setupNICValidation() {
        const macAddressInput = document.getElementById('macAddress');
        const ipAddressInput = document.getElementById('ipAddress');

        if (macAddressInput) {
            macAddressInput.addEventListener('input', (e) => {
                this.validateMacAddress(e.target);
            });
        }

        if (ipAddressInput) {
            ipAddressInput.addEventListener('input', (e) => {
                this.validateIPAddress(e.target);
            });
        }
    }

    validateMacAddress(input) {
        const value = input.value;
        const macPattern = /^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/;
        
        if (value && !macPattern.test(value)) {
            input.classList.add('error');
            this.showFieldError(input, 'Invalid MAC address format (e.g., 00:1A:2B:3C:4D:5F)');
        } else {
            input.classList.remove('error');
            this.hideFieldError(input);
        }
    }

    validateIPAddress(input) {
        const value = input.value;
        const ipPattern = /^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
        
        if (value && !ipPattern.test(value)) {
            input.classList.add('error');
            this.showFieldError(input, 'Invalid IP address format');
        } else {
            input.classList.remove('error');
            this.hideFieldError(input);
        }
    }

    setupEventListeners() {
        // Status change handler
        document.getElementById('status').addEventListener('change', (e) => {
            this.handleStatusChange(e.target.value);
        });

        // Form submission
        document.getElementById('editComponentForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleFormSubmit();
        });

        // Validation on input
        this.setupValidation();
    }

    handleStatusChange(status) {
        const serverUUIDInput = document.getElementById('serverUUID');
        const serverUUIDLabel = document.getElementById('serverUUIDLabel');
        
        if (status === '2') { // In Use
            serverUUIDInput.required = true;
            serverUUIDLabel.classList.add('required');
        } else {
            serverUUIDInput.required = false;
            serverUUIDLabel.classList.remove('required');
        }
    }

    setupValidation() {
        const fields = ['status'];
        
        fields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.addEventListener('blur', () => this.validateField(field));
                field.addEventListener('input', () => this.clearFieldError(field));
            }
        });
    }

    validateField(field) {
        const value = field.value.trim();
        const isRequired = field.hasAttribute('required') || field.classList.contains('required');

        if (isRequired && !value) {
            this.showFieldError(field, 'This field is required');
            return false;
        }

        this.hideFieldError(field);
        return true;
    }

    showFieldError(field, message) {
        field.classList.add('error');
        
        this.hideFieldError(field);
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'form-error';
        errorDiv.textContent = message;
        field.parentNode.appendChild(errorDiv);
    }

    hideFieldError(field) {
        const errorDiv = field.parentNode.querySelector('.form-error');
        if (errorDiv) {
            errorDiv.remove();
        }
    }

    clearFieldError(field) {
        field.classList.remove('error');
        this.hideFieldError(field);
    }

    showFormSections() {
        const sections = [
            'identificationSection',
            'statusSection',
            'locationSection',
            'dateSection',
            'notesSection'
        ];

        sections.forEach(sectionId => {
            document.getElementById(sectionId).style.display = 'block';
        });
    }

    async handleFormSubmit() {
        if (!this.validateForm()) {
            return;
        }

        const formData = this.collectFormData();
        
        try {
            this.setButtonLoading(true);
            
            const result = await api.components.update(this.componentType, this.componentId, formData);
            
            if (result.success) {
                this.showAlert('Component updated successfully!', 'success');
                
                // Redirect back to dashboard after short delay
                setTimeout(() => {
                    this.goBack();
                }, 1500);
            } else {
                throw new Error(result.message || 'Failed to update component');
            }
            
        } catch (error) {
            console.error('Error updating component:', error);
            this.showAlert(error.message || 'Failed to update component', 'error');
        } finally {
            this.setButtonLoading(false);
        }
    }

    validateForm() {
        let isValid = true;

        // Validate status
        const statusField = document.getElementById('status');
        if (!this.validateField(statusField)) {
            isValid = false;
        }

        // Validate server UUID for in-use status
        const status = document.getElementById('status').value;
        const serverUUID = document.getElementById('serverUUID').value.trim();
        if (status === '2' && !serverUUID) {
            const serverUUIDField = document.getElementById('serverUUID');
            this.showFieldError(serverUUIDField, 'Server UUID is required when status is "In Use"');
            isValid = false;
        }

        // Validate MAC address for NIC
        if (this.componentType === 'nic') {
            const macAddress = document.getElementById('macAddress').value.trim();
            if (macAddress && !this.isValidMacAddress(macAddress)) {
                isValid = false;
            }
        }

        return isValid;
    }

    isValidMacAddress(mac) {
        const macPattern = /^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/;
        return macPattern.test(mac);
    }

    collectFormData() {
        const formData = {
            Status: parseInt(document.getElementById('status').value),
            ServerUUID: document.getElementById('serverUUID').value.trim(),
            Location: document.getElementById('location').value.trim(),
            RackPosition: document.getElementById('rackPosition').value.trim(),
            PurchaseDate: document.getElementById('purchaseDate').value,
            InstallationDate: document.getElementById('installationDate').value,
            WarrantyEndDate: document.getElementById('warrantyEndDate').value,
            Flag: document.getElementById('flag').value,
            Notes: document.getElementById('notes').value.trim()
        };

        // Add component-specific fields
        if (this.componentType === 'nic') {
            formData.MacAddress = document.getElementById('macAddress').value.trim();
            formData.IPAddress = document.getElementById('ipAddress').value.trim();
            formData.NetworkName = document.getElementById('networkName').value.trim();
        } else if (this.componentType === 'storage') {
            formData.Capacity = document.getElementById('capacity').value.trim();
            formData.Type = document.getElementById('storageType').value;
            formData.Interface = document.getElementById('interface').value;
        }

        // Remove empty values
        Object.keys(formData).forEach(key => {
            if (formData[key] === '' || formData[key] === null || formData[key] === undefined) {
                delete formData[key];
            }
        });

        return formData;
    }

    setButtonLoading(loading) {
        const submitBtn = document.getElementById('submitBtn');
        const btnText = submitBtn.querySelector('.btn-text');
        const btnLoader = submitBtn.querySelector('.btn-loader');

        if (loading) {
            submitBtn.disabled = true;
            btnText.style.display = 'none';
            btnLoader.style.display = 'flex';
        } else {
            submitBtn.disabled = false;
            btnText.style.display = 'block';
            btnLoader.style.display = 'none';
        }
    }

    showLoading(show, message = 'Loading...') {
        const overlay = document.getElementById('loadingOverlay');
        const loadingText = overlay.querySelector('p');
        
        if (show) {
            loadingText.textContent = message;
            overlay.style.display = 'flex';
        } else {
            overlay.style.display = 'none';
        }
    }

    showAlert(message, type = 'info') {
        if (window.utils && window.utils.showAlert) {
            window.utils.showAlert(message, type);
        } else {
            alert(message);
        }
    }

    goBack() {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = '../index.html';
        }
    }

    closeForm() {
        this.goBack();
    }
}

// Global functions for button handlers
function goBack() {
    if (window.editForm) {
        window.editForm.goBack();
    }
}

function closeForm() {
    if (window.editForm) {
        window.editForm.closeForm();
    }
}

// Initialize form when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.editForm = new EditComponentForm();
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = EditComponentForm;
}