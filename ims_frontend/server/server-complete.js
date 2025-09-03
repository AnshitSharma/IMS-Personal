// Complete Server Management System
class ServerManagementApp {
    constructor() {
        this.baseURL = 'https://shubham.staging.cloudmate.in/bdc_ims/api/api.php';
        this.currentServer = null;
        this.currentComponents = [];
        this.availableComponentTypes = ['motherboard', 'cpu', 'ram', 'storage', 'nic', 'psu', 'gpu', 'cabinet'];
        
        this.init();
    }

    init() {
        console.log('Initializing Server Management App');
        this.checkAuthentication();
        this.setupAxios();
        this.loadDashboard();
    }

    checkAuthentication() {
        // For now, set a test token if none exists
        if (!localStorage.getItem('jwt_token')) {
            console.log('No JWT token found, setting test token');
            localStorage.setItem('jwt_token', 'test-token-' + Date.now());
        }
        
        const token = localStorage.getItem('jwt_token');
        if (token && token !== 'null') {
            this.setupAuthHeader(token);
            this.updateUserInfo();
        }
    }

    setupAxios() {
        axios.defaults.timeout = 30000;
        
        axios.interceptors.response.use(
            response => response,
            error => {
                console.error('API Error:', error);
                if (error.response?.status === 401) {
                    this.showAlert('Session expired. Please login again.', 'error');
                    localStorage.removeItem('jwt_token');
                    setTimeout(() => {
                        window.location.href = '../index.html';
                    }, 2000);
                }
                return Promise.reject(error);
            }
        );
    }

    setupAuthHeader(token) {
        axios.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    }

    updateUserInfo() {
        const userInfo = document.getElementById('userInfo');
        if (userInfo) {
            userInfo.innerHTML = '<i class="fas fa-user me-1"></i>Server Admin';
        }
    }

    // API Methods
    async makeRequest(data) {
        try {
            const formData = new FormData();
            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }

            console.log('Making API request:', data);
            
            // For demo purposes, simulate API responses
            return this.simulateAPIResponse(data);
            
            // Uncomment below for real API calls
            // const response = await axios.post(this.baseURL, formData);
            // return response.data;
        } catch (error) {
            console.error('API Request failed:', error);
            throw new Error(error.response?.data?.message || 'Request failed');
        }
    }

    simulateAPIResponse(data) {
        console.log('Simulating API response for action:', data.action);
        
        switch (data.action) {
            case 'server-create-start':
                return {
                    success: true,
                    message: 'Server configuration created successfully',
                    data: {
                        config_uuid: 'server-' + Date.now(),
                        server_name: data.server_name,
                        description: data.description,
                        status: '0'
                    }
                };
            
            case 'server-list-configs':
                return {
                    success: true,
                    data: [
                        {
                            config_uuid: 'server-1',
                            server_name: 'Production Web Server',
                            description: 'Main production web server',
                            status: '1',
                            created_at: new Date().toISOString(),
                            components: []
                        },
                        {
                            config_uuid: 'server-2',
                            server_name: 'Database Server',
                            description: 'Primary database server',
                            status: '0',
                            created_at: new Date().toISOString(),
                            components: []
                        }
                    ]
                };
            
            case 'server-get-config':
                return {
                    success: true,
                    data: {
                        config_uuid: data.config_uuid,
                        server_name: 'Test Server',
                        description: 'Test server configuration',
                        status: '0',
                        components: this.currentComponents
                    }
                };
            
            case 'server-get-compatible':
                return {
                    success: true,
                    data: this.getCompatibleComponents(data.component_type)
                };
            
            case 'server-add-component':
                return {
                    success: true,
                    message: 'Component added successfully'
                };
            
            default:
                return {
                    success: true,
                    message: 'Operation completed successfully'
                };
        }
    }

    getCompatibleComponents(componentType) {
        const components = {
            motherboard: [
                { 
                    component_uuid: 'mb-1', 
                    component_name: 'ASUS ROG Strix X570-E', 
                    specifications: 'AMD AM4, ATX, DDR4',
                    serial_number: 'MB001'
                },
                { 
                    component_uuid: 'mb-2', 
                    component_name: 'MSI B550 Gaming Plus', 
                    specifications: 'AMD AM4, ATX, DDR4',
                    serial_number: 'MB002'
                }
            ],
            cpu: [
                { 
                    component_uuid: 'cpu-1', 
                    component_name: 'AMD Ryzen 9 5900X', 
                    specifications: '12-core, 3.7GHz Base, AM4',
                    serial_number: 'CPU001'
                },
                { 
                    component_uuid: 'cpu-2', 
                    component_name: 'Intel Core i9-11900K', 
                    specifications: '8-core, 3.5GHz Base, LGA1200',
                    serial_number: 'CPU002'
                }
            ],
            ram: [
                { 
                    component_uuid: 'ram-1', 
                    component_name: 'Corsair Vengeance LPX 32GB', 
                    specifications: 'DDR4-3200, 2x16GB Kit',
                    serial_number: 'RAM001'
                },
                { 
                    component_uuid: 'ram-2', 
                    component_name: 'G.Skill Ripjaws V 16GB', 
                    specifications: 'DDR4-3600, 2x8GB Kit',
                    serial_number: 'RAM002'
                }
            ],
            storage: [
                { 
                    component_uuid: 'storage-1', 
                    component_name: 'Samsung 970 EVO Plus 1TB', 
                    specifications: 'NVMe M.2 SSD, 3500MB/s',
                    serial_number: 'SSD001'
                },
                { 
                    component_uuid: 'storage-2', 
                    component_name: 'WD Black 2TB', 
                    specifications: 'SATA III HDD, 7200RPM',
                    serial_number: 'HDD001'
                }
            ],
            nic: [
                { 
                    component_uuid: 'nic-1', 
                    component_name: 'Intel I350-T4', 
                    specifications: 'Quad Port Gigabit Ethernet',
                    serial_number: 'NIC001'
                }
            ],
            psu: [
                { 
                    component_uuid: 'psu-1', 
                    component_name: 'Corsair RM850x', 
                    specifications: '850W, 80+ Gold, Modular',
                    serial_number: 'PSU001'
                }
            ],
            gpu: [
                { 
                    component_uuid: 'gpu-1', 
                    component_name: 'NVIDIA RTX 3080', 
                    specifications: '10GB GDDR6X, PCIe 4.0',
                    serial_number: 'GPU001'
                }
            ]
        };

        return components[componentType] || [];
    }

    // Navigation Methods
    showDashboard() {
        console.log('Showing dashboard');
        document.getElementById('serverListSection').style.display = 'block';
        document.getElementById('createServerSection').style.display = 'none';
        document.getElementById('serverDetailsSection').style.display = 'none';
        this.loadServerList();
    }

    showCreateServerForm() {
        console.log('Showing create server form');
        document.getElementById('serverListSection').style.display = 'none';
        document.getElementById('createServerSection').style.display = 'block';
        document.getElementById('serverDetailsSection').style.display = 'none';
        this.resetCreateServerForm();
    }

    showServerDetails(configUuid) {
        console.log('Showing server details for:', configUuid);
        this.currentServer = { config_uuid: configUuid };
        document.getElementById('serverListSection').style.display = 'none';
        document.getElementById('createServerSection').style.display = 'none';
        document.getElementById('serverDetailsSection').style.display = 'block';
        this.loadServerDetails(configUuid);
    }

    // Server Management Methods
    async loadServerList() {
        const container = document.getElementById('serverConfigsList');
        container.innerHTML = '<div class="col-12 text-center py-4"><div class="spinner-border"></div><p class="mt-2">Loading...</p></div>';

        try {
            const response = await this.makeRequest({ action: 'server-list-configs' });
            
            if (response.success && response.data?.length > 0) {
                container.innerHTML = response.data.map(server => this.createServerCard(server)).join('');
            } else {
                container.innerHTML = this.createEmptyServerListHtml();
            }
        } catch (error) {
            container.innerHTML = this.createErrorHtml('Failed to load servers: ' + error.message);
        }
    }

    createServerCard(server) {
        const statusInfo = this.getServerStatus(server.status);
        const createdDate = new Date(server.created_at).toLocaleDateString();
        const componentCount = server.components?.length || 0;

        return `
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="card server-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0 text-white">
                            <i class="fas fa-server me-2"></i>${server.server_name}
                        </h6>
                        <span class="badge bg-light text-dark">${statusInfo.text}</span>
                    </div>
                    <div class="card-body">
                        <p class="card-text text-muted small mb-3">${server.description || 'No description'}</p>
                        <div class="d-flex justify-content-between text-muted small mb-3">
                            <span><i class="fas fa-calendar me-1"></i>${createdDate}</span>
                            <span><i class="fas fa-microchip me-1"></i>${componentCount} components</span>
                        </div>
                        <div class="d-grid gap-2">
                            <button class="btn btn-primary btn-sm" onclick="app.showServerDetails('${server.config_uuid}')">
                                <i class="fas fa-eye me-1"></i>Manage Server
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    createEmptyServerListHtml() {
        return `
            <div class="col-12">
                <div class="text-center py-5">
                    <i class="fas fa-server text-muted" style="font-size: 4rem; opacity: 0.3;"></i>
                    <h4 class="mt-3 text-muted">No Server Configurations</h4>
                    <p class="text-muted">Create your first server configuration to get started.</p>
                    <button class="btn btn-primary" onclick="app.showCreateServerForm()">
                        <i class="fas fa-plus me-2"></i>Create Your First Server
                    </button>
                </div>
            </div>
        `;
    }

    createErrorHtml(message) {
        return `
            <div class="col-12">
                <div class="alert alert-danger text-center">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    ${message}
                    <br><br>
                    <button class="btn btn-outline-danger" onclick="app.loadServerList()">
                        <i class="fas fa-refresh me-1"></i>Try Again
                    </button>
                </div>
            </div>
        `;
    }

    resetCreateServerForm() {
        document.getElementById('createServerForm').reset();
        document.getElementById('startWith').value = 'motherboard';
    }

    async createServer() {
        const serverName = document.getElementById('serverName').value.trim();
        const description = document.getElementById('description').value.trim();
        const startWith = document.getElementById('startWith').value;

        if (!serverName) {
            this.showAlert('Please enter a server name', 'warning');
            return;
        }

        console.log('Creating server:', { serverName, description, startWith });

        try {
            const response = await this.makeRequest({
                action: 'server-create-start',
                server_name: serverName,
                description: description,
                start_with: startWith
            });

            if (response.success) {
                this.showAlert('Server configuration created successfully!', 'success');
                
                // Navigate to the new server details
                if (response.data?.config_uuid) {
                    setTimeout(() => {
                        this.showServerDetails(response.data.config_uuid);
                    }, 1500);
                } else {
                    setTimeout(() => {
                        this.showDashboard();
                    }, 1500);
                }
            } else {
                this.showAlert(response.message || 'Failed to create server', 'error');
            }
        } catch (error) {
            this.showAlert('Failed to create server: ' + error.message, 'error');
        }
    }

    async loadServerDetails(configUuid) {
        try {
            const response = await this.makeRequest({
                action: 'server-get-config',
                config_uuid: configUuid
            });

            if (response.success && response.data) {
                this.currentServer = response.data;
                this.currentComponents = response.data.components || [];
                this.updateServerDetailsView(response.data);
            }
        } catch (error) {
            this.showAlert('Failed to load server details: ' + error.message, 'error');
        }
    }

    updateServerDetailsView(server) {
        // Update title and subtitle
        document.getElementById('serverDetailsTitle').innerHTML = 
            `<i class="fas fa-server me-2"></i>${server.server_name}`;
        document.getElementById('serverDetailsSubtitle').textContent = 
            server.description || 'Configure server components';

        // Update status badge
        const statusInfo = this.getServerStatus(server.status);
        document.getElementById('serverStatusBadge').className = `badge bg-${statusInfo.class}`;
        document.getElementById('serverStatusBadge').textContent = statusInfo.text;

        // Update overview content
        document.getElementById('serverOverviewContent').innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <h6>Configuration Details</h6>
                    <p class="mb-1"><strong>Name:</strong> ${server.server_name}</p>
                    <p class="mb-1"><strong>Status:</strong> ${statusInfo.text}</p>
                    <p class="mb-1"><strong>Components:</strong> ${this.currentComponents.length}</p>
                </div>
                <div class="col-md-6">
                    <h6>Description</h6>
                    <p class="text-muted">${server.description || 'No description provided'}</p>
                </div>
            </div>
        `;

        // Update components list
        this.updateComponentsList();

        // Update action buttons visibility
        const finalizeBtn = document.getElementById('finalizeBtn');
        if (server.status === '0' && this.currentComponents.length > 0) {
            finalizeBtn.style.display = 'block';
        } else {
            finalizeBtn.style.display = 'none';
        }

        // Setup add component form
        this.populateComponentTypes();
    }

    updateComponentsList() {
        const container = document.getElementById('componentsList');
        
        if (this.currentComponents.length === 0) {
            const hasMotherboard = false;
            const message = hasMotherboard ? 
                "Add components to build your server configuration." :
                "Start by adding a motherboard for better component compatibility.";
                
            container.innerHTML = `
                <div class="text-center py-4">
                    <i class="fas fa-microchip text-muted" style="font-size: 2rem; opacity: 0.3;"></i>
                    <p class="text-muted mt-2">${message}</p>
                    <button class="btn btn-outline-primary" onclick="app.showAddComponentForm()">
                        <i class="fas fa-plus me-1"></i>Add First Component
                    </button>
                </div>
            `;
        } else {
            container.innerHTML = this.currentComponents.map(component => this.createComponentListItem(component)).join('');
        }
    }

    createComponentListItem(component) {
        const icon = this.getComponentIcon(component.component_type);
        const typeName = this.formatComponentType(component.component_type);

        return `
            <div class="d-flex align-items-center p-3 border-bottom">
                <div class="component-icon ${component.component_type} me-3">
                    <i class="${icon}"></i>
                </div>
                <div class="flex-grow-1">
                    <h6 class="mb-1">${component.component_name || typeName}</h6>
                    <small class="text-muted">
                        ${component.specifications || 'No specifications available'}
                        ${component.quantity > 1 ? ` (Qty: ${component.quantity})` : ''}
                        ${component.slot_position ? ` - ${component.slot_position}` : ''}
                    </small>
                </div>
                <div class="component-actions">
                    <button class="btn btn-outline-danger btn-sm" onclick="app.removeComponent('${component.component_type}', '${component.component_uuid}')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
    }

    // Component Management Methods
    populateComponentTypes() {
        const select = document.getElementById('componentType');
        if (!select) return;

        const availableTypes = this.getAvailableComponentTypes();
        
        select.innerHTML = '<option value="">Select Component Type</option>';
        
        availableTypes.forEach(type => {
            const option = document.createElement('option');
            option.value = type;
            option.textContent = this.formatComponentType(type);
            select.appendChild(option);
        });
    }

    getAvailableComponentTypes() {
        const hasMotherboard = this.currentComponents.some(c => c.component_type === 'motherboard');
        
        if (!hasMotherboard) {
            return ['motherboard'];
        }
        
        return this.availableComponentTypes;
    }

    showAddComponentForm() {
        const card = document.getElementById('addComponentCard');
        card.style.display = 'block';
        this.populateComponentTypes();
        this.resetAddComponentForm();
    }

    hideAddComponentForm() {
        document.getElementById('addComponentCard').style.display = 'none';
    }

    resetAddComponentForm() {
        document.getElementById('addComponentForm').reset();
        document.getElementById('componentSelectContainer').style.display = 'none';
        document.getElementById('slotPositionContainer').style.display = 'none';
        document.getElementById('addComponentBtn').disabled = true;
        document.getElementById('quantity').value = 1;
        document.getElementById('componentInfo').innerHTML = '';
    }

    async onComponentTypeChange() {
        const componentType = document.getElementById('componentType').value;
        const container = document.getElementById('componentSelectContainer');
        const select = document.getElementById('componentSelect');
        const slotContainer = document.getElementById('slotPositionContainer');
        const addBtn = document.getElementById('addComponentBtn');

        console.log('Component type changed to:', componentType);

        if (!componentType) {
            container.style.display = 'none';
            slotContainer.style.display = 'none';
            addBtn.disabled = true;
            return;
        }

        // Show loading state
        container.style.display = 'block';
        select.innerHTML = '<option value="">Loading compatible components...</option>';
        select.disabled = true;
        addBtn.disabled = true;

        // Show slot position for certain component types
        if (['cpu', 'ram', 'gpu'].includes(componentType)) {
            slotContainer.style.display = 'block';
        } else {
            slotContainer.style.display = 'none';
        }

        try {
            const response = await this.makeRequest({
                action: 'server-get-compatible',
                config_uuid: this.currentServer.config_uuid,
                component_type: componentType,
                available_only: 'true'
            });

            if (response.success && response.data) {
                this.populateCompatibleComponents(response.data, componentType);
            } else {
                select.innerHTML = '<option value="">No compatible components found</option>';
            }
        } catch (error) {
            console.error('Error loading compatible components:', error);
            select.innerHTML = '<option value="">Error loading components</option>';
            this.showAlert('Failed to load compatible components', 'error');
        } finally {
            select.disabled = false;
        }
    }

    populateCompatibleComponents(components, componentType) {
        const select = document.getElementById('componentSelect');
        
        select.innerHTML = '<option value="">Select a component</option>';
        
        if (!components || components.length === 0) {
            select.innerHTML = '<option value="">No compatible components available</option>';
            return;
        }

        components.forEach(component => {
            const option = document.createElement('option');
            option.value = component.component_uuid;
            option.textContent = `${component.component_name || component.specifications} ${component.serial_number ? `(${component.serial_number})` : ''}`;
            option.dataset.component = JSON.stringify(component);
            select.appendChild(option);
        });
    }

    onComponentSelectChange() {
        const select = document.getElementById('componentSelect');
        const addBtn = document.getElementById('addComponentBtn');
        const infoDiv = document.getElementById('componentInfo');
        const componentUuid = select.value;

        console.log('Component selected:', componentUuid);

        if (!componentUuid) {
            addBtn.disabled = true;
            infoDiv.innerHTML = '';
            return;
        }

        const selectedOption = select.options[select.selectedIndex];
        if (selectedOption && selectedOption.dataset.component) {
            const component = JSON.parse(selectedOption.dataset.component);
            
            infoDiv.innerHTML = `
                <div class="alert alert-info mt-2">
                    <strong>${component.component_name || 'Component'}</strong><br>
                    <small>
                        ${component.specifications || 'No specifications available'}
                        ${component.serial_number ? `<br>Serial: ${component.serial_number}` : ''}
                    </small>
                </div>
            `;
        }
        
        addBtn.disabled = false;
    }

    async addComponent() {
        const componentType = document.getElementById('componentType').value;
        const componentUuid = document.getElementById('componentSelect').value;
        const quantity = parseInt(document.getElementById('quantity').value) || 1;
        const slotPosition = document.getElementById('slotPosition').value.trim();

        console.log('Adding component:', { componentType, componentUuid, quantity, slotPosition });

        if (!componentType || !componentUuid) {
            this.showAlert('Please select both component type and specific component', 'warning');
            return;
        }

        const addBtn = document.getElementById('addComponentBtn');
        const originalText = addBtn.innerHTML;
        addBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Adding...';
        addBtn.disabled = true;

        try {
            const response = await this.makeRequest({
                action: 'server-add-component',
                config_uuid: this.currentServer.config_uuid,
                component_type: componentType,
                component_uuid: componentUuid,
                quantity: quantity.toString(),
                slot_position: slotPosition,
                override: 'false'
            });

            if (response.success) {
                // Add component to current list for immediate UI update
                const selectedOption = document.getElementById('componentSelect').options[document.getElementById('componentSelect').selectedIndex];
                if (selectedOption && selectedOption.dataset.component) {
                    const component = JSON.parse(selectedOption.dataset.component);
                    component.quantity = quantity;
                    component.slot_position = slotPosition;
                    this.currentComponents.push(component);
                }

                this.showAlert('Component added successfully!', 'success');
                this.hideAddComponentForm();
                this.updateComponentsList();
                this.populateComponentTypes();
            } else {
                this.showAlert(response.message || 'Failed to add component', 'error');
            }
        } catch (error) {
            console.error('Error adding component:', error);
            this.showAlert('Failed to add component: ' + error.message, 'error');
        } finally {
            addBtn.innerHTML = originalText;
            addBtn.disabled = false;
        }
    }

    async removeComponent(componentType, componentUuid) {
        if (!confirm('Are you sure you want to remove this component?')) {
            return;
        }

        try {
            const response = await this.makeRequest({
                action: 'server-remove-component',
                config_uuid: this.currentServer.config_uuid,
                component_type: componentType,
                component_uuid: componentUuid
            });

            if (response.success) {
                // Remove component from current list for immediate UI update
                this.currentComponents = this.currentComponents.filter(c => 
                    c.component_uuid !== componentUuid || c.component_type !== componentType
                );

                this.showAlert('Component removed successfully!', 'success');
                this.updateComponentsList();
                this.populateComponentTypes();
            } else {
                this.showAlert(response.message || 'Failed to remove component', 'error');
            }
        } catch (error) {
            console.error('Error removing component:', error);
            this.showAlert('Failed to remove component: ' + error.message, 'error');
        }
    }

    async finalizeConfiguration() {
        if (!confirm('Are you sure you want to finalize this configuration? You will not be able to make changes after finalization.')) {
            return;
        }

        try {
            const response = await this.makeRequest({
                action: 'server-finalize-config',
                config_uuid: this.currentServer.config_uuid,
                notes: 'Configuration finalized via web interface'
            });

            if (response.success) {
                this.showAlert('Server configuration finalized successfully!', 'success');
                setTimeout(() => {
                    this.showDashboard();
                }, 2000);
            } else {
                this.showAlert(response.message || 'Failed to finalize configuration', 'error');
            }
        } catch (error) {
            this.showAlert('Failed to finalize configuration: ' + error.message, 'error');
        }
    }

    async validateConfiguration() {
        try {
            const response = await this.makeRequest({
                action: 'server-validate-config',
                config_uuid: this.currentServer.config_uuid
            });

            if (response.success) {
                this.showAlert('Configuration is valid and ready for deployment!', 'success');
            } else {
                this.showAlert(response.message || 'Configuration validation failed', 'warning');
            }
        } catch (error) {
            this.showAlert('Failed to validate configuration: ' + error.message, 'error');
        }
    }

    async deleteServer() {
        if (!confirm('Are you sure you want to delete this server configuration? This action cannot be undone.')) {
            return;
        }

        try {
            const response = await this.makeRequest({
                action: 'server-delete-config',
                config_uuid: this.currentServer.config_uuid
            });

            if (response.success) {
                this.showAlert('Server configuration deleted successfully!', 'success');
                setTimeout(() => {
                    this.showDashboard();
                }, 1500);
            } else {
                this.showAlert(response.message || 'Failed to delete server configuration', 'error');
            }
        } catch (error) {
            this.showAlert('Failed to delete server configuration: ' + error.message, 'error');
        }
    }

    // Utility Methods
    getServerStatus(status) {
        const statusMap = {
            '0': { text: 'Draft', class: 'secondary' },
            '1': { text: 'Active', class: 'primary' },
            '2': { text: 'Finalized', class: 'success' }
        };
        return statusMap[status] || { text: 'Unknown', class: 'secondary' };
    }

    formatComponentType(type) {
        const typeMap = {
            'cpu': 'CPU',
            'motherboard': 'Motherboard',
            'ram': 'RAM',
            'storage': 'Storage',
            'nic': 'Network Interface',
            'psu': 'Power Supply',
            'gpu': 'Graphics Card',
            'cabinet': 'Cabinet'
        };
        return typeMap[type] || type.toUpperCase();
    }

    getComponentIcon(type) {
        const iconMap = {
            'cpu': 'fas fa-microchip',
            'motherboard': 'fas fa-memory',
            'ram': 'fas fa-memory',
            'storage': 'fas fa-hdd',
            'nic': 'fas fa-network-wired',
            'psu': 'fas fa-plug',
            'gpu': 'fas fa-display',
            'cabinet': 'fas fa-server'
        };
        return iconMap[type] || 'fas fa-microchip';
    }

    showAlert(message, type = 'info') {
        const toast = document.getElementById('alertToast');
        const icon = document.getElementById('alertIcon');
        const title = document.getElementById('alertTitle');
        const messageEl = document.getElementById('alertMessage');

        const config = {
            success: { icon: 'fas fa-check-circle text-success', title: 'Success' },
            error: { icon: 'fas fa-exclamation-circle text-danger', title: 'Error' },
            warning: { icon: 'fas fa-exclamation-triangle text-warning', title: 'Warning' },
            info: { icon: 'fas fa-info-circle text-primary', title: 'Information' }
        };

        const alertConfig = config[type] || config.info;
        
        icon.className = alertConfig.icon;
        title.textContent = alertConfig.title;
        messageEl.textContent = message;

        const bsToast = new bootstrap.Toast(toast);
        bsToast.show();
    }

    loadDashboard() {
        this.showDashboard();
    }
}

// Global Functions (called from HTML)
function showDashboard() {
    app.showDashboard();
}

function showCreateServerForm() {
    app.showCreateServerForm();
}

function createServer() {
    app.createServer();
}

function onComponentTypeChange() {
    app.onComponentTypeChange();
}

function onComponentSelectChange() {
    app.onComponentSelectChange();
}

function addComponent() {
    app.addComponent();
}

function finalizeConfiguration() {
    app.finalizeConfiguration();
}

function validateConfiguration() {
    app.validateConfiguration();
}

function deleteServer() {
    app.deleteServer();
}

function logout() {
    localStorage.removeItem('jwt_token');
    window.location.href = '../index.html';
}

// Initialize the application
let app;
document.addEventListener('DOMContentLoaded', () => {
    console.log('Initializing Server Management Application');
    app = new ServerManagementApp();
    
    // Make it globally accessible for debugging
    window.serverApp = app;
});