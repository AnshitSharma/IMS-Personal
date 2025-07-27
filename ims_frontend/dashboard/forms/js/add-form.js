/**
 * Add Component Form JavaScript
 * forms/js/add-form.js
 */

class AddComponentForm {
    constructor() {
        this.currentComponentType = null;
        this.jsonData = {};
        this.selectedComponent = null;
        
        this.init();
    }

    async init() {
        console.log('Initializing Add Component Form...');
        
        // Get component type from URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const componentType = urlParams.get('type');
        
        if (componentType) {
            document.getElementById('componentType').value = componentType;
            await this.handleComponentTypeChange(componentType);
        }
        
        this.setupEventListeners();
        console.log('Add Component Form initialized');
    }

    setupEventListeners() {
        // Component type selection
        document.getElementById('componentType').addEventListener('change', (e) => {
            this.handleComponentTypeChange(e.target.value);
        });

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

    async handleComponentTypeChange(componentType) {
        if (!componentType) {
            this.hideAllSections();
            return;
        }

        this.currentComponentType = componentType;
        document.getElementById('formTitle').textContent = `Add ${componentType.toUpperCase()} Component`;

        try {
            // Show loading
            this.showLoading(true, 'Loading component specifications...');

            // Load JSON data for the component type
            await this.loadComponentData(componentType);

            // Validate that we have the required data
            if (!this.validateJSONData(componentType)) {
                throw new Error(`Missing required JSON data for ${componentType}`);
            }

            // Setup form based on component type
            this.setupComponentForm(componentType);

            // Show form sections
            this.showFormSections();

        } catch (error) {
            console.error('Error loading component data:', error);
            this.showAlert(`Failed to load component specifications: ${error.message}`, 'error');
            
            // Still show basic form sections even if JSON loading fails
            this.showFormSections();
            this.setupComponentSpecificFields(componentType);
        } finally {
            this.showLoading(false);
        }
    }

    validateJSONData(componentType) {
        console.log(`Validating JSON data for ${componentType}...`);
        
        if (['cpu', 'motherboard'].includes(componentType)) {
            if (!this.jsonData.level1 || this.jsonData.level1.length === 0) {
                console.error(`Missing or empty level1 data for ${componentType}`);
                return false;
            }
            if (!this.jsonData.level3 || this.jsonData.level3.length === 0) {
                console.error(`Missing or empty level3 data for ${componentType}`);
                return false;
            }
            if (componentType === 'cpu' && (!this.jsonData.level2 || this.jsonData.level2.length === 0)) {
                console.error(`Missing or empty level2 data for CPU`);
                return false;
            }
        } else {
            if (!this.jsonData.level3 || this.jsonData.level3.length === 0) {
                console.error(`Missing or empty level3 data for ${componentType}`);
                return false;
            }
        }
        
        console.log(`✓ JSON data validation passed for ${componentType}`);
        return true;
    }

    async loadComponentData(componentType) {
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
        const paths = jsonPaths[componentType];

        if (paths) {
            console.log(`Loading JSON data for ${componentType}...`);
            
            for (const [level, path] of Object.entries(paths)) {
                try {
                    console.log(`Fetching ${level} data from: ${path}`);
                    const response = await fetch(path);
                    if (response.ok) {
                        const data = await response.json();
                        this.jsonData[level] = data;
                        console.log(`✓ Loaded ${level} data:`, data);
                    } else {
                        console.error(`✗ Failed to load ${level} data: ${response.status} ${response.statusText}`);
                    }
                } catch (error) {
                    console.error(`✗ Error loading ${level} data:`, error);
                }
            }
        } else {
            console.warn(`No JSON paths configured for component type: ${componentType}`);
        }

        console.log(`Final JSON data for ${componentType}:`, this.jsonData);
    }

    setupComponentForm(componentType) {
        console.log(`Setting up component form for: ${componentType}`);
        console.log('Available JSON data:', this.jsonData);
        
        // Reset all dropdowns
        this.clearDropdowns();

        if (['cpu', 'motherboard'].includes(componentType)) {
            // Use cascading dropdowns
            console.log('Using cascading dropdowns');
            this.setupCascadingDropdowns(componentType);
            document.getElementById('cascadingDropdowns').style.display = 'block';
            document.getElementById('directSelection').style.display = 'none';
        } else if (['ram', 'storage', 'nic', 'caddy'].includes(componentType)) {
            // Use direct selection
            console.log('Using direct selection');
            this.setupDirectSelection(componentType);
            document.getElementById('cascadingDropdowns').style.display = 'none';
            document.getElementById('directSelection').style.display = 'block';
        }

        // Setup component-specific fields
        this.setupComponentSpecificFields(componentType);
    }

    setupCascadingDropdowns(componentType) {
        console.log(`🔧 Setting up cascading dropdowns for: ${componentType}`);
        
        const brandSelect = document.getElementById('brandSelect');
        const seriesSelect = document.getElementById('seriesSelect');
        const modelSelect = document.getElementById('modelSelect');

        if (!brandSelect) {
            console.error('❌ brandSelect element not found!');
            return;
        }

        // Clear and enable brand dropdown
        this.clearDropdown('brandSelect');
        brandSelect.disabled = false;
        console.log('✅ Brand dropdown enabled');

        // Populate brands
        if (this.jsonData.level1 && this.jsonData.level1.length > 0) {
            console.log(`📊 Populating ${this.jsonData.level1.length} brands...`);
            
            this.jsonData.level1.forEach((brand, index) => {
                const option = document.createElement('option');
                const brandName = brand.brand || brand.name || brand.manufacturer;
                option.value = brandName;
                option.textContent = brandName;
                brandSelect.appendChild(option);
                console.log(`  ${index + 1}. ${brandName}`);
            });
            
            console.log(`✅ Populated ${this.jsonData.level1.length} brands for ${componentType}`);
        } else {
            // Fallback: Add manual entry option
            console.warn(`⚠️ No brand data found for ${componentType}, adding manual entry option`);
            const option = document.createElement('option');
            option.value = 'manual';
            option.textContent = 'Manual Entry (JSON data not available)';
            brandSelect.appendChild(option);
        }

        // Remove any existing event listeners by cloning the element
        const newBrandSelect = brandSelect.cloneNode(true);
        brandSelect.parentNode.replaceChild(newBrandSelect, brandSelect);

        // Brand change handler
        newBrandSelect.addEventListener('change', (e) => {
            console.log(`🎯 Brand changed to: ${e.target.value}`);
            this.handleBrandChange(e.target.value, componentType);
        });

        // Series change handler (for CPU only)
        if (componentType === 'cpu') {
            document.getElementById('seriesGroup').style.display = 'block';
            if (seriesSelect) {
                const newSeriesSelect = seriesSelect.cloneNode(true);
                seriesSelect.parentNode.replaceChild(newSeriesSelect, seriesSelect);
                
                newSeriesSelect.addEventListener('change', (e) => {
                    console.log(`🎯 Series changed to: ${e.target.value}`);
                    this.handleSeriesChange(newBrandSelect.value, e.target.value);
                });
            }
        } else {
            document.getElementById('seriesGroup').style.display = 'none';
        }

        // Model change handler
        if (modelSelect) {
            const newModelSelect = modelSelect.cloneNode(true);
            modelSelect.parentNode.replaceChild(newModelSelect, modelSelect);
            
            newModelSelect.addEventListener('change', (e) => {
                console.log(`🎯 Model changed to: ${e.target.value}`);
                this.handleModelSelection(e.target.value);
            });
        }

        console.log('✅ Cascading dropdowns setup complete');
    }

    setupDirectSelection(componentType) {
        const componentModelSelect = document.getElementById('componentModelSelect');

        // Clear and enable dropdown
        this.clearDropdown('componentModelSelect');
        componentModelSelect.disabled = false;

        if (this.jsonData.level3 && this.jsonData.level3.length > 0) {
            let modelsAdded = 0;
            this.jsonData.level3.forEach(item => {
                if (item.models && Array.isArray(item.models)) {
                    item.models.forEach(model => {
                        const option = document.createElement('option');
                        const modelName = model.model || model.name || model.part_number;
                        const brand = item.brand || item.manufacturer || '';
                        
                        option.value = JSON.stringify(model);
                        option.textContent = brand ? `${brand} - ${modelName}` : modelName;
                        componentModelSelect.appendChild(option);
                        modelsAdded++;
                    });
                }
            });
            console.log(`Populated ${modelsAdded} models for ${componentType}`);
        } else {
            // Fallback: Add manual entry option
            console.warn(`No model data found for ${componentType}, adding manual entry option`);
            const option = document.createElement('option');
            option.value = 'manual';
            option.textContent = 'Manual Entry (JSON data not available)';
            componentModelSelect.appendChild(option);
        }

        // Model selection handler
        componentModelSelect.addEventListener('change', (e) => {
            if (e.target.value && e.target.value !== 'manual') {
                try {
                    const model = JSON.parse(e.target.value);
                    this.handleModelSelection(model);
                } catch (error) {
                    console.error('Error parsing selected model:', error);
                }
            } else if (e.target.value === 'manual') {
                // Handle manual entry
                this.handleManualEntry();
            }
        });
    }

    handleManualEntry() {
        // For manual entry, just enable the UUID field for user input
        const uuidField = document.getElementById('componentUUID');
        uuidField.value = '';
        uuidField.readOnly = false;
        uuidField.placeholder = 'Enter component UUID manually';
        
        // Hide spec preview
        const specPreview = document.getElementById('specPreview');
        if (specPreview) {
            specPreview.style.display = 'none';
        }
        
        this.showAlert('JSON data not available. Please enter component details manually.', 'warning');
    }

    handleBrandChange(selectedBrand, componentType) {
        if (!selectedBrand) {
            this.clearDropdown('seriesSelect');
            this.clearDropdown('modelSelect');
            return;
        }

        console.log(`Brand selected: ${selectedBrand} for ${componentType}`);

        // Handle manual entry
        if (selectedBrand === 'manual') {
            this.handleManualEntry();
            return;
        }

        if (componentType === 'cpu') {
            // Populate series for CPU
            const seriesSelect = document.getElementById('seriesSelect');
            this.clearDropdown('seriesSelect');
            this.clearDropdown('modelSelect');

            const brandData = this.jsonData.level2?.find(item => 
                (item.brand === selectedBrand || item.name === selectedBrand)
            );

            if (brandData && brandData.series) {
                brandData.series.forEach(series => {
                    const option = document.createElement('option');
                    option.value = series.name || series.series;
                    option.textContent = series.name || series.series;
                    seriesSelect.appendChild(option);
                });
                seriesSelect.disabled = false;
                console.log(`Populated ${brandData.series.length} series for ${selectedBrand}`);
            } else {
                console.warn(`No series data found for brand: ${selectedBrand}`);
                // Add manual entry option for series
                const option = document.createElement('option');
                option.value = 'manual';
                option.textContent = 'Manual Entry';
                seriesSelect.appendChild(option);
                seriesSelect.disabled = false;
            }
        } else if (componentType === 'motherboard') {
            // For motherboard, go directly to models
            this.populateMotherboardModels(selectedBrand);
        }
    }

    handleSeriesChange(selectedBrand, selectedSeries) {
        if (!selectedBrand || !selectedSeries) {
            this.clearDropdown('modelSelect');
            return;
        }

        console.log(`Series selected: ${selectedSeries} for brand: ${selectedBrand}`);

        const modelSelect = document.getElementById('modelSelect');
        this.clearDropdown('modelSelect');

        const brandData = this.jsonData.level3?.find(item => 
            (item.brand === selectedBrand || item.name === selectedBrand)
        );

        if (brandData && brandData.models) {
            const filteredModels = brandData.models.filter(model => {
                const modelSeries = model.series || model.family;
                return modelSeries === selectedSeries;
            });

            if (filteredModels.length > 0) {
                filteredModels.forEach(model => {
                    const option = document.createElement('option');
                    option.value = JSON.stringify(model);
                    option.textContent = model.model || model.name;
                    modelSelect.appendChild(option);
                });
                modelSelect.disabled = false;
                console.log(`Populated ${filteredModels.length} models for ${selectedBrand} ${selectedSeries}`);
            } else {
                console.warn(`No models found for ${selectedBrand} ${selectedSeries}`);
            }
        } else {
            console.warn(`No brand data found for: ${selectedBrand}`);
        }
    }

    populateMotherboardModels(selectedBrand) {
        const modelSelect = document.getElementById('modelSelect');
        this.clearDropdown('modelSelect');

        console.log(`Populating motherboard models for brand: ${selectedBrand}`);

        const brandData = this.jsonData.level3?.find(item => 
            (item.brand === selectedBrand || item.name === selectedBrand)
        );

        if (brandData && brandData.models) {
            brandData.models.forEach(model => {
                const option = document.createElement('option');
                option.value = JSON.stringify(model);
                option.textContent = model.model || model.name;
                modelSelect.appendChild(option);
            });
            modelSelect.disabled = false;
            console.log(`Populated ${brandData.models.length} motherboard models for ${selectedBrand}`);
        } else {
            console.warn(`No motherboard models found for brand: ${selectedBrand}`);
        }
    }

    handleModelSelection(modelData) {
        let model;
        
        if (typeof modelData === 'string') {
            try {
                model = JSON.parse(modelData);
            } catch (error) {
                console.error('Error parsing model data:', error);
                return;
            }
        } else {
            model = modelData;
        }

        this.selectedComponent = model;

        // Extract and set UUID
        const uuid = this.extractUUID(model);
        document.getElementById('componentUUID').value = uuid || '';

        // Display component specifications
        this.displaySpecifications(model);
    }

    extractUUID(model) {
        // Try different possible UUID field names
        return model.UUID || model.uuid || model.id || 
               (model.inventory && model.inventory.UUID) || '';
    }

    displaySpecifications(model) {
        const specPreview = document.getElementById('specPreview');
        const specContent = document.getElementById('specContent');

        if (!specPreview || !specContent) return;

        const specs = this.extractSpecifications(model);

        if (specs.length > 0) {
            specContent.innerHTML = `
                <div class="spec-grid">
                    ${specs.map(([label, value]) => `
                        <div class="spec-item">
                            <span class="spec-label">${label}</span>
                            <span class="spec-value">${value}</span>
                        </div>
                    `).join('')}
                </div>
            `;
            specPreview.style.display = 'block';
        } else {
            specPreview.style.display = 'none';
        }
    }

    extractSpecifications(model) {
        const specs = [];
        
        // Generic fields
        if (model.model) specs.push(['Model', model.model]);
        if (model.part_number) specs.push(['Part Number', model.part_number]);

        // Component-specific specifications
        switch (this.currentComponentType) {
            case 'cpu':
                if (model.cores) specs.push(['Cores', model.cores]);
                if (model.threads) specs.push(['Threads', model.threads]);
                if (model.base_frequency) specs.push(['Base Frequency', model.base_frequency]);
                if (model.boost_frequency) specs.push(['Boost Frequency', model.boost_frequency]);
                if (model.tdp) specs.push(['TDP', `${model.tdp}W`]);
                if (model.cache && model.cache.l3) specs.push(['L3 Cache', model.cache.l3]);
                break;

            case 'motherboard':
                if (model.socket) {
                    const socket = typeof model.socket === 'object' ? model.socket.type : model.socket;
                    specs.push(['Socket', socket]);
                }
                if (model.chipset) specs.push(['Chipset', model.chipset]);
                if (model.form_factor) specs.push(['Form Factor', model.form_factor]);
                if (model.memory && model.memory.max_capacity) {
                    specs.push(['Max Memory', model.memory.max_capacity]);
                }
                break;

            case 'ram':
                if (model.capacity) specs.push(['Capacity', model.capacity]);
                if (model.type) specs.push(['Type', model.type]);
                if (model.frequency) specs.push(['Frequency', model.frequency]);
                if (model.form_factor) specs.push(['Form Factor', model.form_factor]);
                break;

            case 'storage':
                if (model.capacity) specs.push(['Capacity', model.capacity]);
                if (model.type) specs.push(['Type', model.type]);
                if (model.interface) specs.push(['Interface', model.interface]);
                if (model.form_factor) specs.push(['Form Factor', model.form_factor]);
                break;

            case 'nic':
                if (model.speed) specs.push(['Speed', model.speed]);
                if (model.ports) specs.push(['Ports', model.ports]);
                if (model.interface) specs.push(['Interface', model.interface]);
                break;

            case 'caddy':
                if (model.size) specs.push(['Size', model.size]);
                if (model.compatibility) specs.push(['Compatibility', model.compatibility]);
                break;
        }

        return specs;
    }

    setupComponentSpecificFields(componentType) {
        // Hide all component-specific sections first
        document.getElementById('nicFields').style.display = 'none';
        document.getElementById('storageFields').style.display = 'none';

        const componentSpecificSection = document.getElementById('componentSpecificSection');
        const specificSectionTitle = document.getElementById('specificSectionTitle');

        if (componentType === 'nic') {
            specificSectionTitle.textContent = 'Network Interface Details';
            document.getElementById('nicFields').style.display = 'block';
            componentSpecificSection.style.display = 'block';
            this.setupNICValidation();
        } else if (componentType === 'storage') {
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

    showFormSections() {
        const sections = [
            'specificationSection',
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

    hideAllSections() {
        const sections = [
            'specificationSection',
            'identificationSection',
            'statusSection', 
            'locationSection',
            'componentSpecificSection',
            'dateSection',
            'notesSection'
        ];

        sections.forEach(sectionId => {
            document.getElementById(sectionId).style.display = 'none';
        });
    }

    clearDropdowns() {
        const dropdowns = ['brandSelect', 'seriesSelect', 'modelSelect', 'componentModelSelect'];
        dropdowns.forEach(id => this.clearDropdown(id));
    }

    clearDropdown(selectId) {
        console.log(`Clearing dropdown: ${selectId}`);
        const select = document.getElementById(selectId);
        if (select) {
            // Keep the first option (placeholder)
            const firstOption = select.children[0]?.cloneNode(true);
            select.innerHTML = '';
            if (firstOption) {
                select.appendChild(firstOption);
            }
            
            // Only disable if it's not the main component type selector
            if (selectId !== 'componentType') {
                select.disabled = true;
                console.log(`Disabled dropdown: ${selectId}`);
            } else {
                console.log(`Kept enabled: ${selectId}`);
            }
        } else {
            console.warn(`Dropdown not found: ${selectId}`);
        }
    }

    setupValidation() {
        const requiredFields = ['componentType', 'serialNumber', 'status'];
        
        requiredFields.forEach(fieldId => {
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
        
        // Remove existing error message
        this.hideFieldError(field);
        
        // Add new error message
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

    async handleFormSubmit() {
        if (!this.validateForm()) {
            return;
        }

        const formData = this.collectFormData();
        
        try {
            this.setButtonLoading(true);
            
            const result = await api.components.add(this.currentComponentType, formData);
            
            if (result.success) {
                this.showAlert('Component added successfully!', 'success');
                
                // Redirect back to dashboard after short delay
                setTimeout(() => {
                    this.goBack();
                }, 1500);
            } else {
                throw new Error(result.message || 'Failed to add component');
            }
            
        } catch (error) {
            console.error('Error adding component:', error);
            this.showAlert(error.message || 'Failed to add component', 'error');
        } finally {
            this.setButtonLoading(false);
        }
    }

    validateForm() {
        let isValid = true;

        // Validate component type
        if (!this.currentComponentType) {
            this.showAlert('Please select a component type', 'error');
            return false;
        }

        // Validate component selection
        if (['cpu', 'motherboard'].includes(this.currentComponentType)) {
            const modelSelect = document.getElementById('modelSelect');
            if (!modelSelect.value) {
                this.showAlert('Please select a component model', 'error');
                return false;
            }
        } else {
            const componentModelSelect = document.getElementById('componentModelSelect');
            if (!componentModelSelect.value) {
                this.showAlert('Please select a component', 'error');
                return false;
            }
        }

        // Validate required fields
        const requiredFields = ['serialNumber', 'status'];
        requiredFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (!this.validateField(field)) {
                isValid = false;
            }
        });

        // Validate server UUID for in-use status
        const status = document.getElementById('status').value;
        const serverUUID = document.getElementById('serverUUID').value.trim();
        if (status === '2' && !serverUUID) {
            const serverUUIDField = document.getElementById('serverUUID');
            this.showFieldError(serverUUIDField, 'Server UUID is required when status is "In Use"');
            isValid = false;
        }

        // Validate MAC address for NIC
        if (this.currentComponentType === 'nic') {
            const macAddress = document.getElementById('macAddress').value.trim();
            if (macAddress && !utils.isValidMacAddress(macAddress)) {
                isValid = false;
            }
        }

        return isValid;
    }

    collectFormData() {
        const formData = {
            UUID: document.getElementById('componentUUID').value.trim(),
            SerialNumber: document.getElementById('serialNumber').value.trim(),
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
        if (this.currentComponentType === 'nic') {
            formData.MacAddress = document.getElementById('macAddress').value.trim();
            formData.IPAddress = document.getElementById('ipAddress').value.trim();
            formData.NetworkName = document.getElementById('networkName').value.trim();
        } else if (this.currentComponentType === 'storage') {
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
    if (window.addForm) {
        window.addForm.goBack();
    }
}

function closeForm() {
    if (window.addForm) {
        window.addForm.closeForm();
    }
}

// Initialize form when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.addForm = new AddComponentForm();
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AddComponentForm;
}