/**
 * Add Component Form JavaScript - Updated Version with Custom Specifications
 * forms/js/add-form.js
 */

class AddComponentForm {
    constructor() {
        this.currentComponentType = null;
        this.jsonData = {};
        this.selectedComponent = null;
        this.componentSpecification = {};
        
        this.init();
    }

    async init() {
        
        // Get component type from URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const componentType = urlParams.get('type');
        
        if (componentType) {
            document.getElementById('componentType').value = componentType;
            await this.handleComponentTypeChange(componentType);
        }
        
        this.setupEventListeners();
    }

    setupEventListeners() {
        // Component type selection
        document.getElementById('componentType').addEventListener('change', (e) => {
            this.handleComponentTypeChange(e.target.value);
        });

        // Brand selection (when available)
        const brandSelect = document.getElementById('brandSelect');
        if (brandSelect) {
            brandSelect.addEventListener('change', (e) => {
                this.handleBrandChange(e.target.value);
            });
        }

        // Series selection (when available)
        const seriesSelect = document.getElementById('seriesSelect');
        if (seriesSelect) {
            seriesSelect.addEventListener('change', (e) => {
                this.handleSeriesChange(e.target.value);
            });
        }

        // Model selection (when available)
        const modelSelect = document.getElementById('modelSelect');
        if (modelSelect) {
            modelSelect.addEventListener('change', (e) => {
                this.handleModelChange(e.target.value);
            });
        }

        // Custom specification handlers
        this.setupCustomSpecificationListeners();

        // Status change handler
        document.getElementById('status').addEventListener('change', (e) => {
            this.handleStatusChange(e.target.value);
        });

        // Form submission
        document.getElementById('addComponentForm').addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleFormSubmit();
        });

        // Validation on input
        this.setupValidation();
    }

    setupCustomSpecificationListeners() {
        // RAM specification listeners
        const ramType = document.getElementById('ramType');
        const ramECC = document.getElementById('ramECC');
        const ramSize = document.getElementById('ramSize');

        if (ramType) {
            ramType.addEventListener('change', () => this.updateComponentSpecification());
        }
        if (ramECC) {
            ramECC.addEventListener('change', () => this.updateComponentSpecification());
        }
        if (ramSize) {
            ramSize.addEventListener('change', () => this.updateComponentSpecification());
        }

        // Storage specification listeners
        const storageType = document.getElementById('storageType');
        const storageCapacity = document.getElementById('storageCapacity');

        if (storageType) {
            storageType.addEventListener('change', () => this.updateComponentSpecification());
        }
        if (storageCapacity) {
            storageCapacity.addEventListener('change', () => this.updateComponentSpecification());
        }

        // Caddy specification listeners
        const caddyType = document.getElementById('caddyType');

        if (caddyType) {
            caddyType.addEventListener('change', () => this.updateComponentSpecification());
        }
    }

    updateComponentSpecification() {
        this.componentSpecification = {};

        if (this.currentComponentType === 'ram') {
            const ramType = document.getElementById('ramType')?.value;
            const ramECC = document.getElementById('ramECC')?.value;
            const ramSize = document.getElementById('ramSize')?.value;

            if (ramType) this.componentSpecification.type = ramType;
            if (ramECC) this.componentSpecification.ecc = ramECC;
            if (ramSize) this.componentSpecification.size = ramSize;

            // Generate UUID for custom RAM configuration
            if (ramType && ramECC && ramSize) {
                this.generateCustomUUID('ram', `${ramType}-${ramECC}-${ramSize}`);
            }
        } else if (this.currentComponentType === 'storage') {
            const storageType = document.getElementById('storageType')?.value;
            const storageCapacity = document.getElementById('storageCapacity')?.value;

            if (storageType) this.componentSpecification.type = storageType;
            if (storageCapacity) this.componentSpecification.capacity = storageCapacity;

            // Generate UUID for custom Storage configuration
            if (storageType && storageCapacity) {
                this.generateCustomUUID('storage', `${storageType}-${storageCapacity}GB`);
            }
        } else if (this.currentComponentType === 'caddy') {
            const caddyType = document.getElementById('caddyType')?.value;

            if (caddyType) this.componentSpecification.type = caddyType;

            // Generate UUID for custom Caddy configuration
            if (caddyType) {
                this.generateCustomUUID('caddy', caddyType);
            }
        }

    }

    generateCustomUUID(componentType, specification) {
        const baseString = `${componentType}-${specification}-${Date.now()}`;
        const hash = this.simpleHash(baseString);
        const uuid = `${hash.substr(0,8)}-${hash.substr(8,4)}-4${hash.substr(13,3)}-${hash.substr(16,4)}-${hash.substr(20,12)}`;
        document.getElementById('componentUUID').value = uuid;
    }

    async handleComponentTypeChange(componentType) {
        if (!componentType) {
            this.hideAllSections();
            return;
        }

        this.currentComponentType = componentType;
        this.componentSpecification = {};
        document.getElementById('formTitle').textContent = `Add ${componentType.toUpperCase()} Component`;

        try {
            // Show loading
            this.showLoading(true, 'Loading component specifications...');

            // Load JSON data for the component type or setup custom form
            if (componentType === 'cpu' || componentType === 'motherboard') {
                await this.loadJSONData(componentType);
                // Show relevant sections
                this.showFormSections();
                // Initialize dropdowns if JSON data is available
                if (this.jsonData && Array.isArray(this.jsonData) && this.jsonData.length > 0) {
                    this.initializeDropdowns();
                } else {
                    this.showBasicFormOnly();
                }
            } else if (componentType === 'ram') {
                this.setupRAMSpecification();
            } else if (componentType === 'storage') {
                this.setupStorageSpecification();
            } else if (componentType === 'caddy') {
                this.setupCaddySpecification();
            } else if (componentType === 'nic') {
                this.showBasicFormOnly();
            }

        } catch (error) {
            console.error('Error loading component type:', error);
            this.showAlert('Failed to load component specifications', 'error');
        } finally {
            this.showLoading(false);
        }
    }

    setupRAMSpecification() {
        // Hide cascading dropdowns and show custom RAM form
        this.hideJSONDropdowns();
        this.showCustomSpecificationForm('ram');
        this.showOtherSections();
    }

    setupStorageSpecification() {
        // Hide cascading dropdowns and show custom Storage form
        this.hideJSONDropdowns();
        this.showCustomSpecificationForm('storage');
        this.showOtherSections();
    }

    setupCaddySpecification() {
        // Hide cascading dropdowns and show custom Caddy form
        this.hideJSONDropdowns();
        this.showCustomSpecificationForm('caddy');
        this.showOtherSections();
    }

    hideJSONDropdowns() {
        const cascadingDropdowns = document.getElementById('cascadingDropdowns');
        const componentDetails = document.getElementById('componentDetails');
        
        if (cascadingDropdowns) {
            cascadingDropdowns.style.display = 'none';
            
            // Remove required attribute from hidden dropdowns to prevent validation errors
            const brandSelect = document.getElementById('brandSelect');
            const seriesSelect = document.getElementById('seriesSelect');
            const modelSelect = document.getElementById('modelSelect');
            
            if (brandSelect) {
                brandSelect.removeAttribute('required');
                brandSelect.value = '';
            }
            if (seriesSelect) {
                seriesSelect.removeAttribute('required');
                seriesSelect.value = '';
            }
            if (modelSelect) {
                modelSelect.removeAttribute('required');
                modelSelect.value = '';
            }
        }
        if (componentDetails) {
            componentDetails.style.display = 'none';
        }
    }

    showJSONDropdowns() {
        const cascadingDropdowns = document.getElementById('cascadingDropdowns');
        
        if (cascadingDropdowns) {
            cascadingDropdowns.style.display = 'block';
            
            // Re-add required attribute to visible dropdowns
            const brandSelect = document.getElementById('brandSelect');
            const seriesSelect = document.getElementById('seriesSelect');
            const modelSelect = document.getElementById('modelSelect');
            
            if (brandSelect) brandSelect.setAttribute('required', 'required');
            if (seriesSelect) seriesSelect.setAttribute('required', 'required');
            if (modelSelect) modelSelect.setAttribute('required', 'required');
        }
    }

    showCustomSpecificationForm(componentType) {
        const specSection = document.getElementById('specificationSection');
        if (!specSection) return;

        specSection.style.display = 'block';

        // Remove existing custom forms
        const existingCustomForm = document.getElementById('customSpecForm');
        if (existingCustomForm) {
            existingCustomForm.remove();
        }

        // Create custom specification form based on component type
        let customFormHTML = '';

        if (componentType === 'ram') {
            customFormHTML = `
                <div id="customSpecForm" class="custom-spec-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">RAM Type</label>
                            <select id="ramType" class="form-select" required>
                                <option value="">Select RAM Type</option>
                                <option value="DDR4">DDR4</option>
                                <option value="DDR5">DDR5</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">ECC Support</label>
                            <select id="ramECC" class="form-select" required>
                                <option value="">Select ECC Support</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Size</label>
                            <select id="ramSize" class="form-select" required>
                                <option value="">Select Size</option>
                                <option value="16GB">16GB</option>
                                <option value="32GB">32GB</option>
                                <option value="64GB">64GB</option>
                                <option value="128GB">128GB</option>
                            </select>
                        </div>
                    </div>
                </div>
            `;
        } else if (componentType === 'storage') {
            customFormHTML = `
                <div id="customSpecForm" class="custom-spec-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Storage Type</label>
                            <select id="storageType" class="form-select" required>
                                <option value="">Select Storage Type</option>
                                <option value="HDD">HDD</option>
                                <option value="SSD">SSD</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label required">Capacity (GB)</label>
                            <select id="storageCapacity" class="form-select" required>
                                <option value="">Select Capacity</option>
                                <option value="120">120GB</option>
                                <option value="240">240GB</option>
                                <option value="480">480GB</option>
                                <option value="960">960GB</option>
                                <option value="1920">1920GB</option>
                                <option value="3840">3840GB</option>
                                <option value="7680">7680GB</option>
                            </select>
                        </div>
                    </div>
                </div>
            `;
        } else if (componentType === 'caddy') {
            customFormHTML = `
                <div id="customSpecForm" class="custom-spec-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label required">Caddy Type</label>
                            <select id="caddyType" class="form-select" required>
                                <option value="">Select Caddy Type</option>
                                <option value="2.5 Inch">2.5 Inch</option>
                                <option value="3.5 Inch">3.5 Inch</option>
                            </select>
                        </div>
                    </div>
                </div>
            `;
        }

        specSection.insertAdjacentHTML('beforeend', customFormHTML);

        // Re-setup listeners for the new form elements
        this.setupCustomSpecificationListeners();
    }

    showOtherSections() {
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

    async loadJSONData(componentType) {
        try {
            // Load JSON data directly from All JSON folder
            const jsonPaths = {
                'cpu': '../../All JSON/cpu jsons/Cpu details level 3.json',
                'motherboard': '../../All JSON/motherboad jsons/motherboard level 3.json'
            };

            if (jsonPaths[componentType]) {
                const response = await fetch(jsonPaths[componentType]);
                if (response.ok) {
                    this.jsonData = await response.json();
                } else {
                    console.warn(`Failed to load JSON data for ${componentType}`);
                    this.jsonData = [];
                }
            } else {
                this.jsonData = [];
            }
        } catch (error) {
            console.error('Error loading JSON data:', error);
            this.jsonData = [];
        }
    }

    initializeDropdowns() {
        this.populateBrandDropdown();
        this.resetDependentDropdowns();
    }

    populateBrandDropdown() {
        const brandSelect = document.getElementById('brandSelect');
        if (!brandSelect || !Array.isArray(this.jsonData)) return;

        // Clear existing options
        brandSelect.innerHTML = '<option value="">Select Brand</option>';

        // Get unique brands from the JSON data
        const brands = [...new Set(this.jsonData.map(item => item.brand))].filter(Boolean);


        // Populate brand options
        brands.forEach(brand => {
            const option = document.createElement('option');
            option.value = brand;
            option.textContent = brand;
            brandSelect.appendChild(option);
        });

        brandSelect.disabled = false;
    }

    handleBrandChange(selectedBrand) {
        if (!selectedBrand) {
            this.resetDependentDropdowns();
            return;
        }


        // For most components, populate series based on selected brand
        this.populateSeriesDropdown(selectedBrand);
    }

    populateSeriesDropdown(selectedBrand) {
        const seriesSelect = document.getElementById('seriesSelect');
        if (!seriesSelect || !Array.isArray(this.jsonData)) return;

        // Clear existing options
        seriesSelect.innerHTML = '<option value="">Select Series</option>';

        // Find all series for the selected brand
        const brandItems = this.jsonData.filter(item => item.brand === selectedBrand);
        const series = [...new Set(brandItems.map(item => item.series).filter(Boolean))];


        series.forEach(seriesName => {
            const option = document.createElement('option');
            option.value = seriesName;
            option.textContent = seriesName;
            seriesSelect.appendChild(option);
        });

        seriesSelect.disabled = false;
        this.clearModelDropdown();
    }

    handleSeriesChange(selectedSeries) {
        if (!selectedSeries) {
            this.clearModelDropdown();
            return;
        }

        const selectedBrand = document.getElementById('brandSelect').value;
        this.populateModelDropdown(selectedBrand, selectedSeries);
    }

    populateModelDropdown(selectedBrand, selectedSeries = null) {
        const modelSelect = document.getElementById('modelSelect');
        if (!modelSelect || !Array.isArray(this.jsonData)) return;

        // Clear existing options
        modelSelect.innerHTML = '<option value="">Select Model</option>';

        // Filter data by brand and series (if provided)
        let filteredData = this.jsonData.filter(item => 
            item.brand === selectedBrand && 
            (!selectedSeries || item.series === selectedSeries)
        );


        // Get models from filtered data
        let models = [];
        filteredData.forEach(item => {
            if (item.models && Array.isArray(item.models)) {
                // Add brand and series info to each model for UUID generation
                item.models.forEach(model => {
                    models.push({
                        ...model,
                        _brand: item.brand,
                        _series: item.series
                    });
                });
            }
        });


        // Populate models
        models.forEach((model, index) => {
            const option = document.createElement('option');
            const modelName = model.model || model.name || model.series;
            
            // Generate UUID if not present, or use existing UUID
            let uuid = model.UUID || model.uuid || model.inventory?.UUID;
            if (!uuid) {
                // Generate UUID based on brand-series-model
                uuid = this.generateModelUUID(model._brand, model._series, modelName, index);
            }
            
            if (modelName) {
                option.value = uuid;
                option.textContent = modelName;
                option.dataset.modelData = JSON.stringify(model);
                modelSelect.appendChild(option);
            }
        });

        modelSelect.disabled = false;
    }

    generateModelUUID(brand, series, model, index) {
        // Create a consistent UUID based on brand-series-model
        const baseString = `${brand}-${series}-${model}-${index}`;
        const hash = this.simpleHash(baseString);
        
        // Format as UUID
        const uuid = `${hash.substr(0,8)}-${hash.substr(8,4)}-4${hash.substr(13,3)}-${hash.substr(16,4)}-${hash.substr(20,12)}`;
        return uuid;
    }

    simpleHash(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32bit integer
        }
        return Math.abs(hash).toString(16).padStart(32, '0');
    }

    handleModelChange(selectedUUID) {
        if (!selectedUUID) {
            this.clearComponentDetails();
            return;
        }

        // Auto-fill UUID
        document.getElementById('componentUUID').value = selectedUUID;

        // Get selected model data
        const modelSelect = document.getElementById('modelSelect');
        const selectedOption = modelSelect.options[modelSelect.selectedIndex];
        
        if (selectedOption.dataset.modelData) {
            try {
                const modelData = JSON.parse(selectedOption.dataset.modelData);
                this.displayComponentDetails(modelData);
                this.selectedComponent = modelData;
            } catch (error) {
                console.error('Error parsing model data:', error);
            }
        }
    }

    displayComponentDetails(modelData) {
        const detailsSection = document.getElementById('componentDetails');
        const detailsContent = document.getElementById('detailsContent');

        if (!detailsSection || !detailsContent) return;

        // Clear existing details
        detailsContent.innerHTML = '';

        // Create details based on component type
        let details = {};

        if (this.currentComponentType === 'cpu') {
            details = {
                'Model': { value: modelData.model || 'N/A', icon: 'fas fa-microchip' },
                'Cores': { value: modelData.cores || 'N/A', icon: 'fas fa-hashtag' },
                'Threads': { value: modelData.threads || 'N/A', icon: 'fas fa-stream' },
                'Base Frequency': { value: modelData.base_frequency_GHz ? `${modelData.base_frequency_GHz}GHz` : 'N/A', icon: 'fas fa-tachometer-alt' },
                'Max Frequency': { value: modelData.max_frequency_GHz ? `${modelData.max_frequency_GHz}GHz` : 'N/A', icon: 'fas fa-rocket' },
                'TDP': { value: modelData.tdp_W ? `${modelData.tdp_W}W` : 'N/A', icon: 'fas fa-bolt' },
                'Socket': { value: modelData.socket || 'N/A', icon: 'fas fa-plug' },
                'Architecture': { value: modelData.architecture || 'N/A', icon: 'fas fa-cogs' }
            };
        } else if (this.currentComponentType === 'motherboard') {
            details = {
                'Model': { value: modelData.model || 'N/A', icon: 'fas fa-memory' },
                'Socket': { value: modelData.socket || 'N/A', icon: 'fas fa-plug' },
                'Chipset': { value: modelData.chipset || 'N/A', icon: 'fas fa-chip' },
                'Memory Slots': { value: modelData.memory_slots || 'N/A', icon: 'fas fa-sim-card' },
                'Max Memory': { value: modelData.max_memory || 'N/A', icon: 'fas fa-database' }
            };
        } else {
            // Generic details
            details = {
                'Model': { value: modelData.model || modelData.name || 'N/A', icon: 'fas fa-tag' },
                'Brand': { value: modelData._brand || 'N/A', icon: 'fas fa-building' },
                'Series': { value: modelData._series || 'N/A', icon: 'fas fa-layer-group' }
            };
        }

        // Display details with new structure
        for (const [label, data] of Object.entries(details)) {
            const detailItem = document.createElement('div');
            detailItem.className = 'spec-item';
            detailItem.innerHTML = `
                <div class="spec-label">
                    <i class="${data.icon}"></i>
                    ${label}
                </div>
                <div class="spec-value">${data.value}</div>
            `;
            detailsContent.appendChild(detailItem);
        }

        detailsSection.style.display = 'block';
    }

    resetDependentDropdowns() {
        this.clearSeriesDropdown();
        this.clearModelDropdown();
        this.clearComponentDetails();
    }

    clearSeriesDropdown() {
        const seriesSelect = document.getElementById('seriesSelect');
        if (seriesSelect) {
            seriesSelect.innerHTML = '<option value="">Select Series</option>';
            seriesSelect.disabled = true;
        }
    }

    clearModelDropdown() {
        const modelSelect = document.getElementById('modelSelect');
        if (modelSelect) {
            modelSelect.innerHTML = '<option value="">Select Model</option>';
            modelSelect.disabled = true;
        }
        document.getElementById('componentUUID').value = '';
    }

    clearComponentDetails() {
        const detailsSection = document.getElementById('componentDetails');
        if (detailsSection) {
            detailsSection.style.display = 'none';
        }
        this.selectedComponent = null;
    }

    showFormSections() {
        // Show all relevant sections
        const sections = [
            'specificationSection',
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

        // Show JSON dropdowns for CPU/Motherboard
        this.showJSONDropdowns();
    }

    showBasicFormOnly() {
        // Hide specification section for components without JSON data
        const specSection = document.getElementById('specificationSection');
        if (specSection) {
            specSection.style.display = 'none';
        }

        // Generate a basic UUID for non-JSON components
        this.generateBasicUUID();

        // Show other sections
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

    generateBasicUUID() {
        // Generate a simple UUID for components without JSON specifications
        const uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
        document.getElementById('componentUUID').value = uuid;
    }

    hideAllSections() {
        const sections = [
            'specificationSection',
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
                section.style.display = 'none';
            }
        });
    }

    handleStatusChange(status) {
        const serverUUIDGroup = document.getElementById('serverUUID').closest('.form-group');
        const serverUUIDLabel = document.getElementById('serverUUIDLabel');
        
        if (status === '2') { // In Use
            serverUUIDGroup.style.display = 'block';
            serverUUIDLabel.textContent = 'Server UUID (Required)';
            document.getElementById('serverUUID').required = true;
        } else {
            serverUUIDLabel.textContent = 'Server UUID';
            document.getElementById('serverUUID').required = false;
        }
    }

    async handleFormSubmit() {
        try {
            const submitBtn = document.getElementById('submitBtn');
            const btnText = submitBtn.querySelector('.btn-text');
            const btnLoader = submitBtn.querySelector('.btn-loader');

            // Show loading state
            submitBtn.disabled = true;
            btnText.style.display = 'none';
            btnLoader.style.display = 'inline-block';

            // Validate required fields
            if (!this.validateForm()) {
                throw new Error('Please fill in all required fields');
            }

            // Collect form data
            const formData = this.collectFormData();


            // Submit to API
            const result = await this.submitComponent(formData);


            if (result.success || result.status === 1) {
                this.showAlert('Component added successfully!', 'success');
                
                // Reset form or redirect
                setTimeout(() => {
                    this.resetForm();
                    window.history.back(); // Go back to component list
                }, 1500);
            } else {
                throw new Error(result.message || 'Failed to add component');
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
            btnText.style.display = 'inline-block';
            btnLoader.style.display = 'none';
        }
    }

    validateForm() {
        // Get all currently visible required fields
        const form = document.getElementById('addComponentForm');
        const visibleRequiredFields = [];
        
        // Get all required fields that are currently visible
        const allRequiredFields = form.querySelectorAll('[required]');
        
        allRequiredFields.forEach(field => {
            // Check if field is visible (not in a hidden section)
            const fieldSection = field.closest('.form-section, #customSpecForm');
            if (!fieldSection || fieldSection.style.display !== 'none') {
                // Additional check: ensure the field itself is not hidden
                const fieldStyle = window.getComputedStyle(field);
                if (fieldStyle.display !== 'none' && fieldStyle.visibility !== 'hidden') {
                    visibleRequiredFields.push(field);
                }
            }
        });

        // Validate visible required fields
        for (const field of visibleRequiredFields) {
            if (!field.value.trim()) {
                field.focus();
                const fieldLabel = this.getFieldLabel(field);
                this.showAlert(`Please fill in the ${fieldLabel} field`, 'warning');
                return false;
            }
        }

        // Additional validation for status = "In Use"
        const status = document.getElementById('status').value;
        const serverUUID = document.getElementById('serverUUID').value;
        
        if (status === '2' && !serverUUID.trim()) {
            document.getElementById('serverUUID').focus();
            this.showAlert('Server UUID is required when status is "In Use"', 'warning');
            return false;
        }

        return true;
    }

    getFieldLabel(field) {
        // Helper function to get a readable field name
        const fieldId = field.id;
        const labelMap = {
            'componentType': 'Component Type',
            'componentUUID': 'Component UUID',
            'serialNumber': 'Serial Number',
            'status': 'Status',
            'brandSelect': 'Brand',
            'seriesSelect': 'Series',
            'modelSelect': 'Model',
            'ramType': 'RAM Type',
            'ramECC': 'ECC Support',
            'ramSize': 'RAM Size',
            'storageType': 'Storage Type',
            'storageCapacity': 'Storage Capacity',
            'caddyType': 'Caddy Type'
        };
        
        return labelMap[fieldId] || fieldId.replace(/([A-Z])/g, ' $1').toLowerCase();
    }

    collectFormData() {
        // Base form data
        const formData = {
            action: `${this.currentComponentType}-add`,
            UUID: document.getElementById('componentUUID').value,
            SerialNumber: document.getElementById('serialNumber').value,
            Status: document.getElementById('status').value,
            ServerUUID: document.getElementById('serverUUID').value || null,
            Location: document.getElementById('location').value || null,
            RackPosition: document.getElementById('rackPosition').value || null,
            PurchaseDate: document.getElementById('purchaseDate').value || null,
            InstallationDate: document.getElementById('installationDate').value || null,
            WarrantyEndDate: document.getElementById('warrantyEndDate').value || null,
            Flag: document.getElementById('flag').value || null,
            Notes: this.buildNotesWithSpecification()
        };

        return formData;
    }

    buildNotesWithSpecification() {
        let notes = document.getElementById('notes').value || '';
        let specificationText = '';

        // Build specification text based on component type
        if (this.currentComponentType === 'cpu' && this.selectedComponent) {
            const brand = this.selectedComponent._brand || '';
            const series = this.selectedComponent._series || '';
            const model = this.selectedComponent.model || '';
            specificationText = `Brand: ${brand}, Series: ${series}, Model: ${model}`;
        } else if (this.currentComponentType === 'motherboard' && this.selectedComponent) {
            const brand = this.selectedComponent._brand || '';
            const series = this.selectedComponent._series || '';
            const model = this.selectedComponent.model || '';
            specificationText = `Brand: ${brand}, Series: ${series}, Model: ${model}`;
        } else if (this.currentComponentType === 'ram' && this.componentSpecification) {
            const specs = [];
            if (this.componentSpecification.type) specs.push(`Type: ${this.componentSpecification.type}`);
            if (this.componentSpecification.ecc) specs.push(`ECC: ${this.componentSpecification.ecc}`);
            if (this.componentSpecification.size) specs.push(`Size: ${this.componentSpecification.size}`);
            specificationText = specs.join(', ');
        } else if (this.currentComponentType === 'storage' && this.componentSpecification) {
            const specs = [];
            if (this.componentSpecification.type) specs.push(`Type: ${this.componentSpecification.type}`);
            if (this.componentSpecification.capacity) specs.push(`Capacity: ${this.componentSpecification.capacity}GB`);
            specificationText = specs.join(', ');
        } else if (this.currentComponentType === 'caddy' && this.componentSpecification) {
            if (this.componentSpecification.type) {
                specificationText = `Type: ${this.componentSpecification.type}`;
            }
        }

        // Combine specification with user notes
        if (specificationText && notes) {
            return `${specificationText}\n\nAdditional Notes: ${notes}`;
        } else if (specificationText) {
            return specificationText;
        } else {
            return notes;
        }
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

    resetForm() {
        document.getElementById('addComponentForm').reset();
        this.hideAllSections();
        this.currentComponentType = null;
        this.jsonData = [];
        this.selectedComponent = null;
        this.componentSpecification = {};

        // Remove custom specification forms
        const existingCustomForm = document.getElementById('customSpecForm');
        if (existingCustomForm) {
            existingCustomForm.remove();
        }

        // Reset all dropdown requirements
        const brandSelect = document.getElementById('brandSelect');
        const seriesSelect = document.getElementById('seriesSelect');
        const modelSelect = document.getElementById('modelSelect');
        
        if (brandSelect) {
            brandSelect.removeAttribute('required');
            brandSelect.value = '';
        }
        if (seriesSelect) {
            seriesSelect.removeAttribute('required');
            seriesSelect.value = '';
        }
        if (modelSelect) {
            modelSelect.removeAttribute('required');
            modelSelect.value = '';
        }
    }

    setupValidation() {
        // Basic form validation only - no serial number validation
        const form = document.getElementById('addComponentForm');
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
                overlay.querySelector('p').textContent = message;
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
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new AddComponentForm();
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