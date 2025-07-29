/**
 * Edit Component Form JavaScript - Basic Edit Only (No JSON Specifications)
 * forms/js/edit-form.js
 */

class EditComponentForm {
    constructor() {
        this.componentType = null;
        this.componentId = null;
        this.currentComponent = null;
        
        this.init();
    }

    async init() {
        console.log('Initializing Edit Component Form...');
        
        // Get parameters from URL
        const urlParams = new URLSearchParams(window.location.search);
        this.componentType = urlParams.get('type');
        this.componentId = urlParams.get('id');
        
        console.log('URL Parameters:', { componentType: this.componentType, componentId: this.componentId });
        
        if (!this.componentType || !this.componentId) {
            this.showAlert('Invalid component parameters. Missing type or ID.', 'error');
            setTimeout(() => this.goBack(), 2000);
            return;
        }

        // Set form title
        document.getElementById('formTitle').textContent = `Edit ${this.componentType.toUpperCase()} Component`;
        
        // Show component type info
        const componentTypeInfo = document.getElementById('componentTypeInfo');
        if (componentTypeInfo) {
            componentTypeInfo.textContent = this.componentType.toUpperCase();
        }
        
        try {
            // Setup form and event listeners first
            this.setupForm();
            this.setupEventListeners();
            this.showFormSections();
            
            // Then load component data
            await this.loadComponentData();
            
        } catch (error) {
            console.error('Error initializing edit form:', error);
            this.showAlert(`Failed to load component data: ${error.message}`, 'error');
        }
        
        console.log('Edit Component Form initialized');
    }

    async loadComponentData() {
        try {
            this.showLoading(true, 'Loading component data...');
            
            // Make API call to get component data
            const response = await fetch(`https://shubham.staging.cloudmate.in/bdc_ims/api/api.php?action=${this.componentType}-get&id=${this.componentId}`, {
                method: 'GET',
                headers: {
                    'Authorization': `Bearer ${localStorage.getItem('bdc_token')}`,
                    'Content-Type': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();
            console.log('API Response:', result);
            
            // Handle different response structures
            if (result.success === 1 || result.status === 1 || result.success === true) {
                // Try different possible data locations
                this.currentComponent = result.data?.component || result.data || result.component || result;
                console.log('Extracted component data:', this.currentComponent);
                
                if (this.currentComponent && typeof this.currentComponent === 'object') {
                    this.populateForm();
                } else {
                    throw new Error('Invalid component data structure');
                }
            } else {
                throw new Error(result.message || result.error || 'Component not found');
            }
            
        } catch (error) {
            console.error('Error loading component:', error);
            // Try alternative API call format
            try {
                await this.loadComponentDataAlternative();
            } catch (altError) {
                console.error('Alternative API call also failed:', altError);
                throw new Error(`Failed to load component data: ${error.message}`);
            }
        } finally {
            this.showLoading(false);
        }
    }

    async loadComponentDataAlternative() {
        // Try POST method as alternative
        const response = await fetch('https://shubham.staging.cloudmate.in/bdc_ims/api/api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Authorization': `Bearer ${localStorage.getItem('bdc_token')}`
            },
            body: new URLSearchParams({
                action: `${this.componentType}-get`,
                id: this.componentId
            })
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();
        console.log('Alternative API Response:', result);
        
        if (result.success === 1 || result.status === 1 || result.success === true) {
            this.currentComponent = result.data?.component || result.data || result.component || result;
            if (this.currentComponent && typeof this.currentComponent === 'object') {
                this.populateForm();
            } else {
                throw new Error('Invalid component data structure from alternative call');
            }
        } else {
            throw new Error(result.message || result.error || 'Component not found in alternative call');
        }
    }

    populateForm() {
        if (!this.currentComponent) {
            console.error('No component data to populate');
            return;
        }

        console.log('Populating form with data:', this.currentComponent);

        // Field mapping - handle different possible field names from API
        const fieldMappings = {
            // Component UUID
            componentuuid: ['UUID', 'uuid', 'ComponentUUID', 'component_uuid'],
            // Serial Number  
            serialnumber: ['SerialNumber', 'serial_number', 'serialNumber', 'Serial'],
            // Status
            status: ['Status', 'status', 'ComponentStatus'],
            // Server UUID
            serveruuid: ['ServerUUID', 'server_uuid', 'serverUUID', 'ServerID'],
            // Location fields
            location: ['Location', 'location', 'ComponentLocation'],
            rackposition: ['RackPosition', 'rack_position', 'rackPosition', 'Position'],
            // Date fields
            purchasedate: ['PurchaseDate', 'purchase_date', 'purchaseDate', 'DatePurchased'],
            installationdate: ['InstallationDate', 'installation_date', 'installationDate', 'DateInstalled'],
            warrantyenddate: ['WarrantyEndDate', 'warranty_end_date', 'warrantyEndDate', 'WarrantyEnd'],
            // Flag and Notes
            flag: ['Flag', 'flag', 'ComponentFlag', 'Status_Flag'],
            notes: ['Notes', 'notes', 'ComponentNotes', 'Description', 'Comments']
        };

        // Populate each form field
        Object.keys(fieldMappings).forEach(elementId => {
            const element = document.getElementById(elementId);
            if (!element) {
                console.warn(`Form element with ID '${elementId}' not found`);
                return;
            }

            // Try each possible field name until we find data
            let value = '';
            const possibleFields = fieldMappings[elementId];
            
            for (const fieldName of possibleFields) {
                if (this.currentComponent[fieldName] !== undefined && this.currentComponent[fieldName] !== null) {
                    value = this.currentComponent[fieldName];
                    console.log(`Found data for ${elementId}: ${fieldName} = ${value}`);
                    break;
                }
            }

            // Handle different field types
            if (element.type === 'date' && value) {
                // Handle date formatting - convert from various formats to YYYY-MM-DD
                try {
                    const date = new Date(value);
                    if (!isNaN(date.getTime())) {
                        element.value = date.toISOString().split('T')[0];
                    } else {
                        element.value = '';
                    }
                } catch (e) {
                    console.warn(`Invalid date format for ${elementId}:`, value);
                    element.value = '';
                }
            } else {
                // For text, textarea, and select elements
                element.value = value || '';
            }

            console.log(`Set ${elementId} = ${element.value}`);
        });

        // Handle status change for server UUID requirement
        const statusValue = document.getElementById('status').value;
        if (statusValue) {
            this.handleStatusChange(statusValue);
        }

        // Show component type and ID info
        const componentTypeInfo = document.getElementById('componentTypeInfo');
        const componentIdInfo = document.getElementById('componentIdInfo');
        if (componentTypeInfo) {
            componentTypeInfo.textContent = this.componentType.toUpperCase();
        }
        if (componentIdInfo) {
            componentIdInfo.textContent = this.componentId;
        }

        console.log('Form population completed');
        
        // Debug: Show what we populated
        this.debugFormData();
    }

    debugFormData() {
        console.log('=== FORM DATA DEBUG ===');
        const formElements = [
            'componentuuid', 'serialnumber', 'status', 'serveruuid',
            'location', 'rackposition', 'purchasedate', 'installationdate', 
            'warrantyenddate', 'flag', 'notes'
        ];

        formElements.forEach(elementId => {
            const element = document.getElementById(elementId);
            if (element) {
                console.log(`${elementId}: "${element.value}"`);
            }
        });
        console.log('=== END DEBUG ===');
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

        // Cancel button
        const cancelBtn = document.getElementById('cancelBtn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                this.goBack();
            });
        }

        // Validation setup
        this.setupValidation();
    }

    setupForm() {
        // Hide Component Specification section - not needed for edit
        const specSection = document.getElementById('specificationSection');
        if (specSection) {
            specSection.style.display = 'none';
        }

        // Show component type info (readonly)
        const componentTypeInfo = document.getElementById('componentTypeInfo');
        if (componentTypeInfo) {
            componentTypeInfo.textContent = this.componentType.toUpperCase();
        }
    }

    showFormSections() {
        // Show only the sections needed for editing
        const sections = [
            'identificationSection',
            'statusSection', 
            'locationSection',
            'datesSection',
            'flagSection',
            'notesSection'
        ];

        sections.forEach(sectionId => {
            const section = document.getElementById(sectionId);
            if (section) {
                section.style.display = 'block';
            }
        });
    }

    handleStatusChange(status) {
        const serverUUIDGroup = document.getElementById('serveruuid').closest('.form-group');
        const serverUUIDLabel = document.querySelector('label[for="serveruuid"]');
        
        if (status === '2') { // In Use
            if (serverUUIDGroup) serverUUIDGroup.style.display = 'block';
            if (serverUUIDLabel) serverUUIDLabel.textContent = 'Server UUID (Required)';
            document.getElementById('serveruuid').required = true;
        } else {
            if (serverUUIDLabel) serverUUIDLabel.textContent = 'Server UUID';
            document.getElementById('serveruuid').required = false;
        }
    }

    async handleFormSubmit() {
        try {
            const submitBtn = document.getElementById('submitBtn');
            const btnText = submitBtn.querySelector('.btn-text');
            const btnLoader = submitBtn.querySelector('.btn-loader');

            // Show loading state
            submitBtn.disabled = true;
            if (btnText) btnText.style.display = 'none';
            if (btnLoader) btnLoader.style.display = 'inline-block';

            // Validate required fields
            if (!this.validateForm()) {
                throw new Error('Please fill in all required fields');
            }

            // Collect form data
            const formData = this.collectFormData();

            console.log('Submitting edit form data:', formData);

            // Submit to API
            const result = await this.submitComponent(formData);

            console.log('API response:', result);

            if (result.success || result.status === 1) {
                this.showAlert('Component updated successfully!', 'success');
                
                // Go back after successful update
                setTimeout(() => {
                    this.goBack();
                }, 1500);
            } else {
                throw new Error(result.message || 'Failed to update component');
            }

        } catch (error) {
            console.error('Error submitting form:', error);
            this.showAlert(error.message, 'error');
        } finally {
            // Reset button state
            const submitBtn = document.getElementById('submitBtn');
            const btnText = submitBtn.querySelector('.btn-text');
            const btnLoader = submitBtn.querySelector('.btn-loader');
            
            submitBtn.disabled = false;
            if (btnText) btnText.style.display = 'inline-block';
            if (btnLoader) btnLoader.style.display = 'none';
        }
    }

    validateForm() {
        const requiredFields = [
            'serialnumber',
            'status'
        ];

        for (const fieldId of requiredFields) {
            const field = document.getElementById(fieldId);
            if (!field || !field.value.trim()) {
                field?.focus();
                const fieldLabel = this.getFieldLabel(fieldId);
                this.showAlert(`Please fill in the ${fieldLabel} field`, 'warning');
                return false;
            }
        }

        // Additional validation for status = "In Use"
        const status = document.getElementById('status').value;
        const serverUUID = document.getElementById('serveruuid').value;
        
        if (status === '2' && !serverUUID.trim()) {
            document.getElementById('serveruuid').focus();
            this.showAlert('Server UUID is required when status is "In Use"', 'warning');
            return false;
        }

        return true;
    }

    getFieldLabel(fieldId) {
        const labelMap = {
            'serialnumber': 'Serial Number',
            'status': 'Status',
            'serveruuid': 'Server UUID',
            'location': 'Location',
            'rackposition': 'Rack Position',
            'purchasedate': 'Purchase Date',
            'installationdate': 'Installation Date',
            'warrantyenddate': 'Warranty End Date',
            'flag': 'Flag',
            'notes': 'Notes'
        };
        
        return labelMap[fieldId] || fieldId.replace(/([A-Z])/g, ' $1').toLowerCase();
    }

    collectFormData() {
        return {
            action: `${this.componentType}-update`,
            id: this.componentId,
            UUID: document.getElementById('componentuuid').value, // UUID should not be changed
            SerialNumber: document.getElementById('serialnumber').value,
            Status: document.getElementById('status').value,
            ServerUUID: document.getElementById('serveruuid').value || null,
            Location: document.getElementById('location').value || null,
            RackPosition: document.getElementById('rackposition').value || null,
            PurchaseDate: document.getElementById('purchasedate').value || null,
            InstallationDate: document.getElementById('installationdate').value || null,
            WarrantyEndDate: document.getElementById('warrantyenddate').value || null,
            Flag: document.getElementById('flag').value || null,
            Notes: document.getElementById('notes').value || null
        };
    }

    async submitComponent(formData) {
        const response = await fetch('https://shubham.staging.cloudmate.in/bdc_ims/api/api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Authorization': `Bearer ${localStorage.getItem('bdc_token')}`
            },
            body: new URLSearchParams(formData)
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        return await response.json();
    }

    setupValidation() {
        // Basic form validation
        const form = document.getElementById('editComponentForm');
        if (form) {
            form.addEventListener('input', (e) => {
                // Reset any previous validation styling
                if (e.target.style.borderColor) {
                    e.target.style.borderColor = '';
                }
            });
        }
    }

    showLoading(show, message = 'Loading...') {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            if (show) {
                const messageElement = overlay.querySelector('p');
                if (messageElement) messageElement.textContent = message;
                overlay.style.display = 'flex';
            } else {
                overlay.style.display = 'none';
            }
        }
    }

    showAlert(message, type = 'info') {
        const container = document.getElementById('alertContainer');
        if (!container) {
            alert(message);
            return;
        }

        const alert = document.createElement('div');
        alert.className = `alert alert-${type}`;
        alert.innerHTML = `
            <span>${message}</span>
            <button type="button" class="alert-close" onclick="this.parentElement.remove()">×</button>
        `;

        container.appendChild(alert);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (alert.parentElement) {
                alert.remove();
            }
        }, 5000);
    }

    goBack() {
        window.history.back();
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new EditComponentForm();
});

// Global functions for navigation
function goBack() {
    window.history.back();
}

function closeForm() {
    if (confirm('Are you sure you want to close this form? Any unsaved changes will be lost.')) {
        window.history.back();
    }
}