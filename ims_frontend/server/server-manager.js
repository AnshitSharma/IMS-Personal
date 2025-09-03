// Server Configuration Management
class ServerManager {
    constructor() {
        this.currentServerConfig = null;
        this.currentComponents = [];
        this.availableComponentTypes = ['motherboard', 'cpu', 'ram', 'storage', 'nic', 'psu', 'gpu'];
        this.init();
    }

    init() {
        this.bindEvents();
        this.loadServerConfigs();
    }

    bindEvents() {
        // Add server button
        const addServerBtn = document.getElementById('addServerBtn');
        console.log('Add Server Button found:', addServerBtn);
        
        if (addServerBtn) {
            addServerBtn.addEventListener('click', (e) => {
                console.log('Add Server button clicked');
                e.preventDefault();
                this.showAddServerModal();
            });
        } else {
            console.error('Add Server button not found in DOM');
        }

        // Create server button
        document.getElementById('createServerBtn')?.addEventListener('click', () => {
            this.createServer();
        });

        // Add component type change
        document.getElementById('componentType')?.addEventListener('change', (e) => {
            this.onComponentTypeChange(e.target.value);
        });

        // Add component button
        document.getElementById('addComponentBtn')?.addEventListener('click', () => {
            this.addComponent();
        });

        // Component select change
        document.getElementById('componentSelect')?.addEventListener('change', (e) => {
            this.onComponentSelectChange(e.target.value);
        });

        // Finalize config button
        document.getElementById('finalizeConfigBtn')?.addEventListener('click', () => {
            this.finalizeConfiguration();
        });

        // Logout button
        document.getElementById('logoutBtn')?.addEventListener('click', () => {
            this.logout();
        });
    }

    showAddServerModal() {
        console.log('showAddServerModal called');
        const modalElement = document.getElementById('addServerModal');
        console.log('Modal element found:', modalElement);
        
        if (modalElement) {
            const modal = new bootstrap.Modal(modalElement);
            this.resetAddServerForm();
            modal.show();
            console.log('Modal should be shown now');
        } else {
            console.error('Modal element not found');
            alert('Error: Modal not found in DOM');
        }
    }

    resetAddServerForm() {
        document.getElementById('addServerForm').reset();
        document.getElementById('startWith').value = 'motherboard';
    }

    async createServer() {
        const form = document.getElementById('addServerForm');
        const formData = new FormData(form);
        
        const serverName = formData.get('serverName') || document.getElementById('serverName').value;
        const description = formData.get('description') || document.getElementById('description').value;
        const startWith = formData.get('startWith') || document.getElementById('startWith').value;

        if (!serverName.trim()) {
            this.showAlert('Please enter a server name', 'warning');
            return;
        }

        const createBtn = document.getElementById('createServerBtn');
        const originalText = createBtn.innerHTML;
        createBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating...';
        createBtn.disabled = true;

        try {
            const response = await serverAPI.createServerConfig(serverName, description, startWith);
            
            if (response.success) {
                this.showAlert('Server configuration created successfully!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('addServerModal')).hide();
                this.loadServerConfigs();
                
                // Open the newly created server for component addition
                if (response.data?.config_uuid) {
                    setTimeout(() => {
                        this.openServerDetails(response.data.config_uuid);
                    }, 1000);
                }
            } else {
                this.showAlert(response.message || 'Failed to create server configuration', 'error');
            }
        } catch (error) {
            console.error('Error creating server:', error);
            this.showAlert(error.message || 'Failed to create server configuration', 'error');
        } finally {
            createBtn.innerHTML = originalText;
            createBtn.disabled = false;
        }
    }

    async loadServerConfigs() {
        const container = document.getElementById('serverConfigsList');
        
        try {
            const response = await serverAPI.getServerConfigs(20, 0, 'all');
            
            if (response.success && response.data) {
                this.renderServerConfigs(response.data, container);
            } else {
                this.renderEmptyState(container);
            }
        } catch (error) {
            console.error('Error loading server configs:', error);
            this.renderErrorState(container, error.message);
        }
    }

    renderServerConfigs(configs, container) {
        if (!configs || configs.length === 0) {
            this.renderEmptyState(container);
            return;
        }

        const configsHTML = configs.map(config => this.createServerCard(config)).join('');
        container.innerHTML = configsHTML;
    }

    createServerCard(config) {
        const status = serverAPI.formatServerStatus(config.status);
        const componentCount = config.components ? config.components.length : 0;
        const createdDate = new Date(config.created_at).toLocaleDateString();

        return `
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card server-card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">
                            <i class="fas fa-server me-2"></i>${config.server_name}
                        </h6>
                        <span class="server-status ${status.class}">${status.text}</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-2">${config.description || 'No description provided'}</p>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <small class="text-muted">
                                <i class="fas fa-calendar me-1"></i>Created: ${createdDate}
                            </small>
                            <small class="text-muted">
                                <i class="fas fa-microchip me-1"></i>${componentCount} components
                            </small>
                        </div>
                        <div class="server-actions">
                            <button class="btn btn-primary btn-sm flex-fill" onclick="serverManager.openServerDetails('${config.config_uuid}')">
                                <i class="fas fa-eye me-1"></i>View Details
                            </button>
                            ${config.status === '0' ? `
                                <button class="btn btn-outline-danger btn-sm" onclick="serverManager.deleteServer('${config.config_uuid}')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    renderEmptyState(container) {
        container.innerHTML = `
            <div class="col-12">
                <div class="empty-state">
                    <i class="fas fa-server"></i>
                    <h4>No Server Configurations</h4>
                    <p>Create your first server configuration to get started.</p>
                    <button class="btn btn-primary" onclick="serverManager.showAddServerModal()">
                        <i class="fas fa-plus me-1"></i>Add Server Config
                    </button>
                </div>
            </div>
        `;
    }

    renderErrorState(container, errorMessage) {
        container.innerHTML = `
            <div class="col-12">
                <div class="empty-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h4>Error Loading Configurations</h4>
                    <p>${errorMessage}</p>
                    <button class="btn btn-primary" onclick="serverManager.loadServerConfigs()">
                        <i class="fas fa-refresh me-1"></i>Try Again
                    </button>
                </div>
            </div>
        `;
    }

    async openServerDetails(configUuid) {
        try {
            const response = await serverAPI.getServerConfig(configUuid);
            
            if (response.success && response.data) {
                this.currentServerConfig = response.data;
                this.currentComponents = response.data.components || [];
                this.showServerDetailsModal(response.data);
            } else {
                this.showAlert(response.message || 'Failed to load server configuration', 'error');
            }
        } catch (error) {
            console.error('Error loading server details:', error);
            this.showAlert(error.message || 'Failed to load server configuration', 'error');
        }
    }

    showServerDetailsModal(serverConfig) {
        const modal = new bootstrap.Modal(document.getElementById('serverDetailsModal'));
        const title = document.getElementById('serverDetailsTitle');
        const body = document.getElementById('serverDetailsBody');
        const finalizeBtn = document.getElementById('finalizeConfigBtn');

        title.innerHTML = `<i class="fas fa-server me-2"></i>${serverConfig.server_name}`;
        
        // Show/hide finalize button based on status
        if (serverConfig.status === '0' && this.currentComponents.length > 0) {
            finalizeBtn.style.display = 'inline-block';
        } else {
            finalizeBtn.style.display = 'none';
        }

        body.innerHTML = this.createServerDetailsContent(serverConfig);
        
        // Bind component-specific events
        this.bindComponentEvents();
        
        modal.show();
    }

    createServerDetailsContent(serverConfig) {
        const status = serverAPI.formatServerStatus(serverConfig.status);
        const availableTypes = this.getAvailableComponentTypes();

        return `
            <div class="server-overview">
                <div class="row">
                    <div class="col-md-8">
                        <h5>${serverConfig.server_name}</h5>
                        <p class="text-muted">${serverConfig.description || 'No description provided'}</p>
                        <span class="server-status ${status.class}">${status.text}</span>
                    </div>
                    <div class="col-md-4 text-end">
                        ${serverConfig.status === '0' ? `
                            <button class="btn btn-success btn-sm mb-2" onclick="serverManager.showAddComponentModal()">
                                <i class="fas fa-plus me-1"></i>Add Component
                            </button>
                        ` : ''}
                        <div class="overview-stats">
                            <div class="stat-item">
                                <div class="stat-number">${this.currentComponents.length}</div>
                                <div class="stat-label">Components</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="components-section">
                <h6><i class="fas fa-microchip me-2"></i>Components</h6>
                ${this.createComponentsList()}
            </div>

            ${availableTypes.length > 0 && serverConfig.status === '0' ? `
                <div class="available-types mt-3">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Available component types: ${availableTypes.map(t => serverAPI.formatComponentType(t)).join(', ')}
                    </small>
                </div>
            ` : ''}
        `;
    }

    createComponentsList() {
        if (this.currentComponents.length === 0) {
            const hasMotherboard = false;
            const message = hasMotherboard ? 
                "No components added yet. Add components to build your server." :
                "Start by adding a motherboard first for better component compatibility.";
                
            return `
                <div class="empty-state py-4">
                    <i class="fas fa-microchip"></i>
                    <p>${message}</p>
                </div>
            `;
        }

        return `
            <div class="component-list">
                ${this.currentComponents.map(component => this.createComponentItem(component)).join('')}
            </div>
        `;
    }

    createComponentItem(component) {
        const icon = serverAPI.getComponentIcon(component.component_type);
        const typeName = serverAPI.formatComponentType(component.component_type);

        return `
            <div class="component-item" data-component-id="${component.component_uuid}">
                <div class="component-icon ${component.component_type}">
                    <i class="${icon}"></i>
                </div>
                <div class="component-details">
                    <div class="component-name">${component.component_name || typeName}</div>
                    <div class="component-spec">
                        ${component.specifications || 'No specifications available'}
                        ${component.quantity > 1 ? ` (Qty: ${component.quantity})` : ''}
                        ${component.slot_position ? ` - ${component.slot_position}` : ''}
                    </div>
                </div>
                ${this.currentServerConfig?.status === '0' ? `
                    <div class="component-actions">
                        <button class="btn btn-outline-danger btn-sm" 
                                onclick="serverManager.removeComponent('${component.component_type}', '${component.component_uuid}')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                ` : ''}
            </div>
        `;
    }

    getAvailableComponentTypes() {
        if (!this.currentServerConfig) return [];
        
        const hasMotherboard = this.currentComponents.some(c => c.component_type === 'motherboard');
        
        if (!hasMotherboard) {
            return ['motherboard'];
        }
        
        // All types available after motherboard
        return this.availableComponentTypes.filter(type => type !== 'motherboard');
    }

    showAddComponentModal() {
        const modal = new bootstrap.Modal(document.getElementById('addComponentModal'));
        this.populateComponentTypes();
        this.resetAddComponentForm();
        modal.show();
    }

    populateComponentTypes() {
        const select = document.getElementById('componentType');
        const availableTypes = this.getAvailableComponentTypes();
        
        select.innerHTML = '<option value="">Select Component Type</option>';
        
        availableTypes.forEach(type => {
            const option = document.createElement('option');
            option.value = type;
            option.textContent = serverAPI.formatComponentType(type);
            select.appendChild(option);
        });
    }

    resetAddComponentForm() {
        document.getElementById('addComponentForm').reset();
        document.getElementById('componentSelectContainer').style.display = 'none';
        document.getElementById('slotPositionContainer').style.display = 'none';
        document.getElementById('addComponentBtn').disabled = true;
        document.getElementById('quantity').value = 1;
    }

    async onComponentTypeChange(componentType) {
        const container = document.getElementById('componentSelectContainer');
        const select = document.getElementById('componentSelect');
        const slotContainer = document.getElementById('slotPositionContainer');
        const addBtn = document.getElementById('addComponentBtn');
        
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
            const response = await serverAPI.getCompatibleComponents(
                this.currentServerConfig.config_uuid,
                componentType,
                true
            );

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

    onComponentSelectChange(componentUuid) {
        const select = document.getElementById('componentSelect');
        const addBtn = document.getElementById('addComponentBtn');
        const infoDiv = document.getElementById('componentInfo');
        
        if (!componentUuid) {
            addBtn.disabled = true;
            if (infoDiv) infoDiv.innerHTML = '';
            return;
        }

        const selectedOption = select.options[select.selectedIndex];
        if (selectedOption && selectedOption.dataset.component) {
            const component = JSON.parse(selectedOption.dataset.component);
            
            if (infoDiv) {
                infoDiv.innerHTML = `
                    <div class="component-selection-info">
                        <strong>${component.component_name || 'Component'}</strong><br>
                        <small class="text-muted">
                            ${component.specifications || 'No specifications available'}
                            ${component.serial_number ? `<br>Serial: ${component.serial_number}` : ''}
                        </small>
                    </div>
                `;
            }
        }
        
        addBtn.disabled = false;
    }

    async addComponent() {
        const form = document.getElementById('addComponentForm');
        const componentType = document.getElementById('componentType').value;
        const componentUuid = document.getElementById('componentSelect').value;
        const quantity = parseInt(document.getElementById('quantity').value) || 1;
        const slotPosition = document.getElementById('slotPosition').value || '';

        if (!componentType || !componentUuid) {
            this.showAlert('Please select both component type and specific component', 'warning');
            return;
        }

        const addBtn = document.getElementById('addComponentBtn');
        const originalText = addBtn.innerHTML;
        addBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Adding...';
        addBtn.disabled = true;

        try {
            const response = await serverAPI.addComponentToServer(
                this.currentServerConfig.config_uuid,
                componentType,
                componentUuid,
                quantity,
                slotPosition,
                false
            );

            if (response.success) {
                this.showAlert('Component added successfully!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('addComponentModal')).hide();
                
                // Refresh server details
                await this.openServerDetails(this.currentServerConfig.config_uuid);
            } else {
                this.showAlert(response.message || 'Failed to add component', 'error');
            }
        } catch (error) {
            console.error('Error adding component:', error);
            this.showAlert(error.message || 'Failed to add component', 'error');
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
            const response = await serverAPI.removeComponentFromServer(
                this.currentServerConfig.config_uuid,
                componentType,
                componentUuid
            );

            if (response.success) {
                this.showAlert('Component removed successfully!', 'success');
                
                // Refresh server details
                await this.openServerDetails(this.currentServerConfig.config_uuid);
            } else {
                this.showAlert(response.message || 'Failed to remove component', 'error');
            }
        } catch (error) {
            console.error('Error removing component:', error);
            this.showAlert(error.message || 'Failed to remove component', 'error');
        }
    }

    async finalizeConfiguration() {
        if (!confirm('Are you sure you want to finalize this configuration? You will not be able to make changes after finalization.')) {
            return;
        }

        const finalizeBtn = document.getElementById('finalizeConfigBtn');
        const originalText = finalizeBtn.innerHTML;
        finalizeBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Finalizing...';
        finalizeBtn.disabled = true;

        try {
            const response = await serverAPI.finalizeServerConfig(
                this.currentServerConfig.config_uuid,
                'Configuration finalized via web interface'
            );

            if (response.success) {
                this.showAlert('Server configuration finalized successfully!', 'success');
                bootstrap.Modal.getInstance(document.getElementById('serverDetailsModal')).hide();
                this.loadServerConfigs();
            } else {
                this.showAlert(response.message || 'Failed to finalize configuration', 'error');
            }
        } catch (error) {
            console.error('Error finalizing configuration:', error);
            this.showAlert(error.message || 'Failed to finalize configuration', 'error');
        } finally {
            finalizeBtn.innerHTML = originalText;
            finalizeBtn.disabled = false;
        }
    }

    async deleteServer(configUuid) {
        if (!confirm('Are you sure you want to delete this server configuration? This action cannot be undone.')) {
            return;
        }

        try {
            const response = await serverAPI.deleteServerConfig(configUuid);

            if (response.success) {
                this.showAlert('Server configuration deleted successfully!', 'success');
                this.loadServerConfigs();
            } else {
                this.showAlert(response.message || 'Failed to delete server configuration', 'error');
            }
        } catch (error) {
            console.error('Error deleting server:', error);
            this.showAlert(error.message || 'Failed to delete server configuration', 'error');
        }
    }

    bindComponentEvents() {
        // This method can be extended to bind additional component-specific events
        // Currently handled through inline onclick handlers for simplicity
    }

    showAlert(message, type = 'info') {
        const alertModal = document.getElementById('alertModal');
        const alertTitle = document.getElementById('alertModalTitle');
        const alertBody = document.getElementById('alertModalBody');
        
        const icons = {
            success: 'fas fa-check-circle text-success',
            error: 'fas fa-exclamation-circle text-danger',
            warning: 'fas fa-exclamation-triangle text-warning',
            info: 'fas fa-info-circle text-primary'
        };
        
        const titles = {
            success: 'Success',
            error: 'Error',
            warning: 'Warning',
            info: 'Information'
        };

        alertTitle.innerHTML = `<i class="${icons[type]} me-2"></i>${titles[type]}`;
        alertBody.textContent = message;

        const modal = new bootstrap.Modal(alertModal);
        modal.show();
    }

    logout() {
        serverAPI.clearToken();
        window.location.href = '../index.html';
    }
}

// Initialize server manager when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    console.log('DOM loaded, initializing server manager');
    
    // Check if user is authenticated
    const token = localStorage.getItem('jwt_token');
    console.log('JWT Token found:', !!token);
    
    if (!token) {
        console.log('No token found, redirecting to login');
        window.location.href = '../index.html';
        return;
    }
    
    console.log('Creating ServerManager instance');
    window.serverManager = new ServerManager();
    
    // Add a backup click handler directly to the button as a fallback
    setTimeout(() => {
        const addServerBtn = document.getElementById('addServerBtn');
        if (addServerBtn && !addServerBtn.hasAttribute('data-backup-handler')) {
            console.log('Adding backup click handler');
            addServerBtn.setAttribute('data-backup-handler', 'true');
            addServerBtn.onclick = function() {
                console.log('Backup click handler triggered');
                if (window.serverManager) {
                    window.serverManager.showAddServerModal();
                } else {
                    console.error('ServerManager not initialized');
                }
            };
        }
    }, 1000);
});