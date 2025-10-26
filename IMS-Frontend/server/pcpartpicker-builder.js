/**
 * PC Part Picker Style Server Builder
 * Modern interface matching PC Part Picker design
 */

class PCPartPickerBuilder {
    constructor() {
        this.currentConfig = null;
        this.motherboardDetails = null; // Will store motherboard JSON data
        this.selectedComponents = {
            cpu: [],
            motherboard: [],
            ram: [],
            storage: [],
            chassis: [],
            caddy: [],
            pciecard: [],
            nic: [],
            hbacard: []
        };

        this.componentTypes = [
            {
                type: 'cpu',
                name: 'CPU',
                description: 'Processor',
                icon: 'fas fa-microchip',
                multiple: false,
                required: true
            },
            {
                type: 'motherboard',
                name: 'Motherboard',
                description: 'System Board',
                icon: 'fas fa-th-large',
                multiple: false,
                required: true
            },
            {
                type: 'ram',
                name: 'Memory',
                description: 'RAM Modules',
                icon: 'fas fa-memory',
                multiple: true,
                required: true
            },
            {
                type: 'storage',
                name: 'Storage',
                description: 'Hard Drives, SSDs',
                icon: 'fas fa-hdd',
                multiple: true,
                required: false
            },
            {
                type: 'chassis',
                name: 'Chassis',
                description: 'Server Case',
                icon: 'fas fa-server',
                multiple: false,
                required: true
            },
            {
                type: 'caddy',
                name: 'Caddy',
                description: 'Drive Mounting',
                icon: 'fas fa-box',
                multiple: true,
                required: false
            },
            {
                type: 'pciecard',
                name: 'PCI Cards',
                description: 'Expansion Cards',
                icon: 'fas fa-credit-card',
                multiple: true,
                required: false
            },
            {
                type: 'nic',
                name: 'Network Cards',
                description: 'Network Interface',
                icon: 'fas fa-network-wired',
                multiple: true,
                required: false
            },
            {
                type: 'hbacard',
                name: 'HBA Cards',
                description: 'Host Bus Adapter',
                icon: 'fas fa-hdd',
                multiple: true,
                required: false
            }
        ];

        this.compatibilityIssues = [];
        this.performanceWarnings = [];

        this.init();
    }

    init() {

        // Check authentication
        if (!this.checkAuthentication()) {
            return;
        }

        this.loadServerConfig();
    }

    /**
     * Check if user is authenticated
     */
    checkAuthentication() {
        const token = localStorage.getItem('bdc_token') || localStorage.getItem('jwt_token');

        if (!token) {
            localStorage.removeItem('bdc_token');
            localStorage.removeItem('jwt_token');
            localStorage.removeItem('bdc_refresh_token');
            localStorage.removeItem('bdc_user');
            window.location.href = '/ims_frontend/';
            return false;
        }

        return true;
    }

    /**
     * Load server configuration from URL parameters
     */
    loadServerConfig() {
        const urlParams = new URLSearchParams(window.location.search);
        const configUuid = urlParams.get('config');


        if (configUuid) {
            this.loadExistingConfig(configUuid);
        } else {
            if (window.location.pathname.includes('builder')) {
                window.location.href = 'index.html';
            }
        }
    }

    /**
     * Load existing configuration from API
     */
    async loadExistingConfig(configUuid) {
        try {
            this.showLoading('Loading server configuration...');


            // Check if serverAPI is available
            if (typeof serverAPI === 'undefined') {
                console.error('serverAPI is not available!');
                this.showAlert('Server API not available', 'danger');
                this.hideLoading();
                return;
            }

            const result = await serverAPI.getServerConfig(configUuid);

            if (result.success && result.data) {
                const configData = result.data.configuration || result.data;

                this.currentConfig = configData;
                await this.parseExistingComponents(configData);
                this.renderPCPartPickerInterface();
            } else {
                console.error('Failed to load configuration:', result);
                this.showAlert(result.message || 'Failed to load configuration', 'danger');
                setTimeout(() => {
                    window.location.href = 'index.html';
                }, 2000);
            }
        } catch (error) {
            console.error('Error loading configuration:', error);
            this.showAlert('Failed to load server configuration', 'danger');
            setTimeout(() => {
                window.location.href = 'index.html';
            }, 2000);
        } finally {
            this.hideLoading();
        }
    }

    /**
     * Parse existing components from configuration
     */
    async parseExistingComponents(config) {

        // Reset components first
        this.selectedComponents = {
            cpu: [],
            motherboard: [],
            ram: [],
            storage: [],
            chassis: [],
            caddy: [],
            pciecard: [],
            nic: [],
            hbacard: []
        };

        // Parse components from the API structure
        if (config.components) {
            const components = config.components;

            Object.keys(components).forEach(type => {
                const typeComponents = components[type];

                if (Array.isArray(typeComponents) && typeComponents.length > 0) {
                    this.selectedComponents[type] = typeComponents.map(comp => ({
                        uuid: comp.uuid,
                        serial_number: comp.serial_number || 'Not Found',
                        quantity: comp.quantity || 1,
                        slot_position: comp.slot_position || '',
                        added_at: comp.added_at || ''
                    }));

                }
            });
        }

        // Load motherboard details from JSON if motherboard is selected
        if (this.selectedComponents.motherboard.length > 0) {
            await this.loadMotherboardDetails(this.selectedComponents.motherboard[0].uuid);
        }

        this.checkCompatibility();
    }

    /**
     * Load motherboard details from JSON
     */
    async loadMotherboardDetails(uuid) {
        try {
            // Fetch motherboard JSON
            const response = await fetch('../All-JSON/motherboad-jsons/motherboard-level-3.json');
            if (!response.ok) {
                console.error('Failed to fetch motherboard JSON');
                return;
            }

            const motherboardData = await response.json();

            // Search for the motherboard by UUID
            for (const brand of motherboardData) {
                if (brand.models) {
                    for (const model of brand.models) {
                        if (model.uuid === uuid) {
                            this.motherboardDetails = {
                                brand: brand.brand,
                                series: brand.series,
                                family: brand.family,
                                ...model
                            };
                            console.log('Loaded motherboard details:', this.motherboardDetails);
                            return;
                        }
                    }
                }
            }

            console.warn('Motherboard UUID not found in JSON:', uuid);
        } catch (error) {
            console.error('Error loading motherboard details:', error);
        }
    }

    /**
     * Check compatibility issues
     */
    checkCompatibility() {
        this.compatibilityIssues = [];
        this.performanceWarnings = [];

        // Check for missing required components
        this.componentTypes.forEach(compType => {
            if (compType.required && this.selectedComponents[compType.type].length === 0) {
                this.compatibilityIssues.push({
                    severity: 'critical',
                    type: 'missing_component',
                    icon: 'fas fa-exclamation-triangle',
                    title: `Missing ${compType.name}`,
                    message: `No ${compType.name} selected. Adding a ${compType.name.toLowerCase()} is required for the system to function.`,
                    details: `A ${compType.name.toLowerCase()} is essential for your server configuration. Without it, the system cannot operate properly.`,
                    links: [
                        { text: 'Learn about server components', url: 'guide/' },
                        { text: 'Server build guide', url: 'server-build-guide/' }
                    ],
                    action: {
                        text: `Add ${compType.name}`,
                        callback: () => this.addComponent(compType.type),
                        actionType: compType.type
                    },
                    group: 'required_components'
                });
            }
        });

        // Check for CPU cooler if CPU is selected
        if (this.selectedComponents.cpu.length > 0) {
            this.compatibilityIssues.push({
                severity: 'warning',
                type: 'cpu_cooler',
                icon: 'fas fa-fan',
                title: 'CPU Cooler Required',
                message: 'The selected CPU does not include a stock cooler. Adding a CPU cooler is recommended.',
                details: 'High-performance CPUs generate significant heat and require adequate cooling to maintain optimal performance and prevent thermal throttling.',
                action: {
                    text: 'Add CPU Cooler',
                    callback: () => this.addComponent('cooler'),
                    actionType: 'cooler'
                },
                group: 'cooling'
            });
        }

        // Check RAM compatibility
        if (this.selectedComponents.ram.length > 0) {
            const ramCount = this.selectedComponents.ram.length;
            if (ramCount % 2 !== 0) {
                this.compatibilityIssues.push({
                    severity: 'warning',
                    type: 'ram_compatibility',
                    icon: 'fas fa-memory',
                    title: 'RAM Configuration',
                    message: 'Uneven number of RAM modules may affect dual-channel performance.',
                    details: 'For optimal performance, install RAM in matched pairs to enable dual-channel memory mode.',
                    action: {
                        text: 'Review RAM',
                        callback: () => this.addComponent('ram'),
                        actionType: 'ram'
                    },
                    group: 'memory'
                });
            }
        }

        // Add performance warnings
        if (this.selectedComponents.ram.length > 0) {
            this.performanceWarnings.push({
                severity: 'info',
                type: 'physical_constraints',
                icon: 'fas fa-ruler-combined',
                title: 'Physical Constraints',
                message: 'Some physical constraints are not checked, such as RAM clearance with CPU Coolers.',
                details: 'Ensure that installed components do not physically interfere with each other. Check manufacturer specifications for clearance requirements.',
                action: {
                    text: 'Learn More',
                    callback: () => window.open('/', '_blank')
                },
                group: 'compatibility'
            });
        }

        // Power supply check
        const estimatedPower = this.calculateEstimatedPower();
        if (estimatedPower > 500) {
            this.compatibilityIssues.push({
                severity: 'warning',
                type: 'power_supply',
                icon: 'fas fa-plug',
                title: 'Power Supply Capacity',
                message: `Estimated power consumption (${estimatedPower}W) may exceed typical PSU capacity.`,
                details: 'Ensure your power supply can handle the total system load. Consider a PSU with at least 100W overhead.',
                action: {
                    text: 'Check PSU',
                    callback: () => this.addComponent('psu'),
                    actionType: 'psu'
                },
                group: 'power'
            });
        }
    }

    /**
     * Render the PC Part Picker style interface
     */
    renderPCPartPickerInterface() {
        
        if (!this.currentConfig) {
            console.error('No configuration loaded');
            // Show a test interface to verify the builder is working
            this.renderTestInterface();
            return;
        }

        const serverName = this.currentConfig.server_name || this.currentConfig.ServerName || 'Unnamed Server';
        document.title = `${serverName} - Server Builder`;
        

        const hasIssues = this.compatibilityIssues.length > 0;
        const estimatedPower = this.calculateEstimatedPower();

        const interfaceHtml = `
            <div class="pcpp-container">
                <!-- Header -->
                <div class="pcpp-header">
                    <h1 class="pcpp-title">${serverName}</h1>
                    <p class="pcpp-subtitle">Server Configuration Builder</p>
                </div>

                <!-- Power Estimate -->
                <div class="power-estimate">
                    <i class="fas fa-bolt"></i>
                    <span>Estimated Power: ${estimatedPower}W</span>
                </div>

                <!-- Component Selection Table -->
                <div class="component-table-container">
                    <table class="component-table">
                        <thead>
                            <tr>
                                <th>Component</th>
                              
                            </tr>
                        </thead>
                        <tbody>
                            ${this.componentTypes.map(type => this.renderComponentRow(type)).join('')}
                        </tbody>
                    </table>
                </div>



                <!-- Compatibility Warning -->
                ${hasIssues ? `
                    <div class="compatibility-banner warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span class="compatibility-text">Compatibility: Warning! These parts have potential issues. See details below.</span>
                    </div>
                ` : `
                    <div class="compatibility-banner success">
                        <i class="fas fa-check-circle"></i>
                        <span class="compatibility-text">Compatibility: All components are compatible.</span>
                    </div>
                `}

                <!-- Potential Issues -->
                ${this.compatibilityIssues.length > 0 || this.performanceWarnings.length > 0 ? `
                    <div class="issues-section">
                        <div class="issues-header">
                            <h4 class="issues-title">
                                <i class="fas fa-exclamation-triangle"></i>
                                Potential Issues
                            </h4>
                            <div class="issues-summary">
                                <span class="issues-count">${this.getTotalIssuesCount()}</span>
                                <span class="issues-label">issues found</span>
                            </div>
                        </div>
                        <div class="issues-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: ${this.getIssuesResolvedPercentage()}%"></div>
                            </div>
                            <div class="progress-text">${this.getIssuesResolvedCount()} of ${this.getTotalIssuesCount()} issues resolved</div>
                        </div>
                        ${this.renderGroupedIssues()}
                    </div>
                ` : ''}

                <!-- Motherboard Usage -->
                ${this.selectedComponents.motherboard.length > 0 ? `
                    <div class="motherboard-section">
                        <h4 class="motherboard-title">Motherboard Usage</h4>
                        <div class="motherboard-diagram">
                            <div class="socket-section">
                                <div class="section-title">CPU Sockets</div>
                                ${this.renderSocketSlots()}
                            </div>
                            <div class="motherboard-visual">
                                <div class="motherboard-name">${this.selectedComponents.motherboard[0]?.serial_number || 'Motherboard'}</div>
                                <div style="color: var(--text-secondary); font-size: 0.875rem;">Server Motherboard</div>
                            </div>
                            <div class="memory-section">
                                <div class="section-title">Memory Slots</div>
                                ${this.renderMemorySlots()}
                            </div>
                        </div>
                        <div style="margin-top: 1rem; display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="expansion-section">
                                <div class="section-title">Expansion Slots</div>
                                ${this.renderExpansionSlots()}
                            </div>
                            <div class="expansion-section">
                                <div class="section-title">Storage & USB</div>
                                ${this.renderStorageAndUSB()}
                            </div>
                        </div>
                    </div>
                ` : ''}


            </div>
        `;

        // Check if we're in the dashboard or standalone
        const targetElement = document.getElementById('serverBuilderContent') || document.getElementById('app');
        if (targetElement) {
            targetElement.innerHTML = interfaceHtml;
            this.attachEventListeners();
        } else {
            console.error('No target element found for rendering');
        }
    }

    /**
     * Render test interface to verify the builder is working
     */
    renderTestInterface() {
        const testHtml = `
            <div class="pcpp-container">
                <div class="pcpp-header">
                    <h1 class="pcpp-title">Builder Test</h1>
                    <p class="pcpp-subtitle">This confirms the builder is working</p>
                </div>
                
                <div class="compatibility-banner success">
                    <i class="fas fa-check-circle"></i>
                    <span class="compatibility-text">Builder is loaded and working!</span>
                </div>
                
                <div class="component-table-container">
                    <table class="component-table">
                        <thead>
                            <tr>
                                <th>Component</th>
                             
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="component-row">
                                <td>
                                    <div class="component-cell">
                                        <div class="component-icon">
                                            <i class="fas fa-microchip"></i>
                                        </div>
                                        <div class="component-info">
                                            <div class="component-name">CPU</div>
                                            <div class="component-specs">Test Component</div>
                                        </div>
                                    </div>
                                </td>
                                <td colspan="8" style="text-align: center; color: var(--text-muted);">
                                    <button class="btn-add">
                                        <i class="fas fa-plus"></i>
                                        Choose CPU
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
            </div>
        `;
        
        // Check if we're in the dashboard or standalone
        const targetElement = document.getElementById('serverBuilderContent') || document.getElementById('app');
        if (targetElement) {
            targetElement.innerHTML = testHtml;
        } else {
            console.error('No target element found for test interface');
        }
    }

    /**
     * Render component row for the table
     */
    renderComponentRow(componentType) {
        const components = this.selectedComponents[componentType.type];
        const hasComponents = components.length > 0;

        if (hasComponents) {
            return components.map((comp, index) => `
                <tr class="component-row">
                    <td>
                        <div class="component-cell">
                            <div class="component-icon">
                                <i class="${componentType.icon}"></i>
                            </div>
                            <div class="component-info">
                                <div class="component-name">${componentType.name}</div>
                                <div class="component-specs">${comp.serial_number}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="component-info">
                            <div class="component-name">${comp.serial_number}</div>
                            <div class="component-specs">${comp.slot_position || 'Default Position'}</div>
                        </div>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button class="btn-remove" onclick="window.pcppBuilder.removeComponent('${componentType.type}', '${comp.uuid}')">
                                <i class="fas fa-times"></i>
                                Remove
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        } else {
            return `
                <tr class="component-row">
                    <td>
                        <div class="component-cell">
                            <div class="component-icon">
                                <i class="${componentType.icon}"></i>
                            </div>
                            <div class="component-info">
                                <div class="component-name">${componentType.name}</div>
                                <div class="component-specs">${componentType.description}</div>
                            </div>
                        </div>
                    </td>
                    <td colspan="2" style="text-align: center; color: var(--text-muted);">
                        <button class="btn-add" onclick="window.pcppBuilder.addComponent('${componentType.type}')">
                            <i class="fas fa-plus"></i>
                            Choose ${componentType.name}
                        </button>
                    </td>
                </tr>
            `;
        }
    }

    /**
     * Render socket slots - Dynamic based on motherboard JSON
     */
    renderSocketSlots() {
        const cpuComponents = this.selectedComponents.cpu;
        const motherboardData = this.motherboardDetails;

        if (!motherboardData || !motherboardData.socket) {
            // Fallback if no motherboard data
            return `
                <div class="socket-item">
                    <span class="slot-label">CPU Socket</span>
                    <span class="slot-component">${cpuComponents.length > 0 ? cpuComponents[0].serial_number : 'Empty'}</span>
                </div>
            `;
        }

        const socketCount = motherboardData.socket.count || 1;
        const socketType = motherboardData.socket.type || 'Unknown';
        let html = '';

        for (let i = 0; i < socketCount; i++) {
            const cpu = cpuComponents[i];
            html += `
                <div class="socket-item">
                    <span class="slot-label">CPU Socket ${socketCount > 1 ? (i + 1) : ''} (${socketType})</span>
                    <span class="${cpu ? 'slot-component' : 'slot-empty'}">${cpu ? cpu.serial_number : 'Empty'}</span>
                </div>
            `;
        }

        return html;
    }

    /**
     * Render memory slots - Dynamic based on motherboard JSON
     */
    renderMemorySlots() {
        const ramComponents = this.selectedComponents.ram;
        const motherboardData = this.motherboardDetails;

        if (!motherboardData || !motherboardData.memory) {
            // Fallback to 4 slots if no motherboard data
            let html = '';
            for (let i = 0; i < 4; i++) {
                const ram = ramComponents[i];
                html += `
                    <div class="memory-slot">
                        <span class="slot-label">RAM ${i + 1} (288-pin DIMM)</span>
                        <span class="${ram ? 'slot-component' : 'slot-empty'}">${ram ? ram.serial_number : 'Empty'}</span>
                    </div>
                `;
            }
            return html;
        }

        const memorySlots = motherboardData.memory.slots || 4;
        const memoryType = motherboardData.memory.type || 'DIMM';
        let html = '';

        for (let i = 0; i < memorySlots; i++) {
            const ram = ramComponents[i];
            html += `
                <div class="memory-slot">
                    <span class="slot-label">RAM ${i + 1} (${memoryType})</span>
                    <span class="${ram ? 'slot-component' : 'slot-empty'}">${ram ? ram.serial_number : 'Empty'}</span>
                </div>
            `;
        }

        return html;
    }

    /**
     * Render expansion slots - Dynamic based on motherboard JSON
     */
    renderExpansionSlots() {
        const pcieComponents = this.selectedComponents.pciecard || [];
        const hbaComponents = this.selectedComponents.hbacard || [];
        const nicComponents = this.selectedComponents.nic || [];
        const motherboardData = this.motherboardDetails;

        if (!motherboardData || !motherboardData.expansion_slots) {
            // Fallback to basic PCIe slots
            return `
                <div class="expansion-slot">
                    <span class="slot-label">PCIe 1 (x16)</span>
                    <span class="slot-component">${pcieComponents.length > 0 ? pcieComponents[0].serial_number : 'Empty'}</span>
                </div>
                <div class="expansion-slot">
                    <span class="slot-label">PCIe 2 (x1)</span>
                    <span class="slot-empty">Empty</span>
                </div>
            `;
        }

        let html = '';
        let componentIndex = 0;
        const allExpansionComponents = [...pcieComponents, ...hbaComponents, ...nicComponents];

        // Handle regular PCIe slots
        if (motherboardData.expansion_slots.pcie_slots) {
            motherboardData.expansion_slots.pcie_slots.forEach((slotGroup, groupIndex) => {
                const slotCount = slotGroup.count || 1;
                const slotType = slotGroup.type || 'PCIe';

                for (let i = 0; i < slotCount; i++) {
                    const component = allExpansionComponents[componentIndex];
                    html += `
                        <div class="expansion-slot">
                            <span class="slot-label">${slotType} Slot ${componentIndex + 1}</span>
                            <span class="${component ? 'slot-component' : 'slot-empty'}">${component ? component.serial_number : 'Empty'}</span>
                        </div>
                    `;
                    componentIndex++;
                }
            });
        }

        // Handle riser slots if present
        if (motherboardData.expansion_slots.riser_slots) {
            motherboardData.expansion_slots.riser_slots.forEach((riserGroup, groupIndex) => {
                const riserCount = riserGroup.count || 1;
                const riserType = riserGroup.type || 'Riser';

                for (let i = 0; i < riserCount; i++) {
                    html += `
                        <div class="expansion-slot">
                            <span class="slot-label">${riserType} ${i + 1}</span>
                            <span class="slot-empty">Empty</span>
                        </div>
                    `;
                }
            });
        }

        // Handle specialty slots (OCP, etc.)
        if (motherboardData.expansion_slots.specialty_slots) {
            motherboardData.expansion_slots.specialty_slots.forEach((specialtySlot, index) => {
                html += `
                    <div class="expansion-slot">
                        <span class="slot-label">${specialtySlot.type} Slot</span>
                        <span class="slot-empty">Empty</span>
                    </div>
                `;
            });
        }

        return html || '<div class="expansion-slot"><span class="slot-label">No expansion slots</span></div>';
    }

    /**
     * Render storage and USB - Dynamic based on motherboard JSON
     */
    renderStorageAndUSB() {
        const motherboardData = this.motherboardDetails;
        const storageComponents = this.selectedComponents.storage || [];

        if (!motherboardData || !motherboardData.storage) {
            // Fallback
            return `
                <div class="expansion-slot">
                    <span class="slot-label">M.2 Slot 1</span>
                    <span class="slot-empty">Empty</span>
                </div>
                <div class="expansion-slot">
                    <span class="slot-label">SATA 1-4</span>
                    <span class="slot-empty">Available</span>
                </div>
            `;
        }

        let html = '';

        // M.2 NVMe slots
        if (motherboardData.storage.nvme && motherboardData.storage.nvme.m2_slots) {
            motherboardData.storage.nvme.m2_slots.forEach((m2Group, index) => {
                const m2Count = m2Group.count || 0;
                const formFactors = m2Group.form_factors ? m2Group.form_factors.join(', ') : 'M.2';

                for (let i = 0; i < m2Count; i++) {
                    html += `
                        <div class="expansion-slot">
                            <span class="slot-label">M.2 Slot ${i + 1} (${formFactors})</span>
                            <span class="slot-empty">Empty</span>
                        </div>
                    `;
                }
            });
        }

        // U.2 slots
        if (motherboardData.storage.nvme && motherboardData.storage.nvme.u2_slots) {
            const u2Count = motherboardData.storage.nvme.u2_slots.count || 0;
            if (u2Count > 0) {
                html += `
                    <div class="expansion-slot">
                        <span class="slot-label">U.2 Slots (${u2Count})</span>
                        <span class="slot-empty">Available</span>
                    </div>
                `;
            }
        }

        // SATA ports
        if (motherboardData.storage.sata) {
            const sataPorts = motherboardData.storage.sata.ports || 0;
            if (sataPorts > 0) {
                html += `
                    <div class="expansion-slot">
                        <span class="slot-label">SATA Ports (${sataPorts})</span>
                        <span class="slot-empty">Available</span>
                    </div>
                `;
            }
        }

        // SAS ports
        if (motherboardData.storage.sas) {
            const sasPorts = motherboardData.storage.sas.ports || 0;
            if (sasPorts > 0) {
                html += `
                    <div class="expansion-slot">
                        <span class="slot-label">SAS Ports (${sasPorts})</span>
                        <span class="slot-empty">Available</span>
                    </div>
                `;
            }
        }

        // USB headers
        if (motherboardData.usb && motherboardData.usb.internal_headers) {
            const usbHeaders = motherboardData.usb.internal_headers.reduce((sum, header) => sum + (header.count || 0), 0);
            if (usbHeaders > 0) {
                html += `
                    <div class="expansion-slot">
                        <span class="slot-label">USB Headers (${usbHeaders})</span>
                        <span class="slot-empty">Available</span>
                    </div>
                `;
            }
        }

        return html || '<div class="expansion-slot"><span class="slot-label">No storage/USB info</span></div>';
    }

    /**
     * Calculate estimated power consumption
     */
    calculateEstimatedPower() {
        let totalPower = 0;
        
        Object.keys(this.selectedComponents).forEach(type => {
            const components = this.selectedComponents[type];
            if (Array.isArray(components)) {
                components.forEach(comp => {
                    // Mock power consumption based on component type
                    const powerMap = {
                        cpu: 150,
                        motherboard: 50,
                        ram: 10,
                        storage: 15,
                        chassis: 0,
                        caddy: 0,
                        pciecard: 75,
                        nic: 25
                    };
                    totalPower += powerMap[type] || 0;
                });
            }
        });
        
        return totalPower || 374; // Default fallback
    }

    /**
     * Add component to configuration
     */
    async addComponent(type) {
        try {
            
            if (!this.currentConfig || !this.currentConfig.config_uuid) {
                this.showAlert('No server configuration loaded', 'error');
                return;
            }

            const configUuid = this.currentConfig.config_uuid;
            
            // Always redirect to external configuration page (same as server index)
            window.location.href = `../server/configuration.html?config=${configUuid}&type=${type}&return=builder`;
        } catch (error) {
            console.error('Error adding component:', error);
            this.showAlert('Failed to open component selection', 'error');
        }
    }

    /**
     * Remove component from configuration
     */
    async removeComponent(type, uuid) {
        if (!confirm('Are you sure you want to remove this component?')) {
            return;
        }

        try {
            this.showLoading('Removing component...');

            const result = await serverAPI.removeComponentFromServer(
                this.currentConfig.config_uuid,
                type,
                uuid
            );

            if (result.success) {
                this.showAlert('Component removed successfully', 'success');
                await this.loadExistingConfig(this.currentConfig.config_uuid);
            } else {
                this.showAlert(result.message || 'Failed to remove component', 'danger');
            }
        } catch (error) {
            console.error('Error removing component:', error);
            this.showAlert('Failed to remove component', 'danger');
        } finally {
            this.hideLoading();
        }
    }

    /**
     * Get total issues count
     */
    getTotalIssuesCount() {
        return this.compatibilityIssues.length + this.performanceWarnings.length;
    }

    /**
     * Get issues resolved count
     */
    getIssuesResolvedCount() {
        // For now, assume all issues are unresolved. In a real implementation,
        // this would track which issues have been addressed.
        return 0;
    }

    /**
     * Get issues resolved percentage
     */
    getIssuesResolvedPercentage() {
        const total = this.getTotalIssuesCount();
        if (total === 0) return 100;
        return Math.round((this.getIssuesResolvedCount() / total) * 100);
    }

    /**
     * Render grouped issues
     */
    renderGroupedIssues() {
        const allIssues = [...this.compatibilityIssues, ...this.performanceWarnings];
        const groupedIssues = this.groupIssuesByCategory(allIssues);

        return Object.keys(groupedIssues).map(groupKey => {
            const groupIssues = groupedIssues[groupKey];
            const groupTitle = this.getGroupTitle(groupKey);
            const groupIcon = this.getGroupIcon(groupKey);

            return `
                <div class="issues-group">
                    <div class="issues-group-header">
                        <i class="${groupIcon}"></i>
                        <span class="group-title">${groupTitle}</span>
                        <span class="group-count">${groupIssues.length}</span>
                    </div>
                    <div class="issues-group-content">
                        ${groupIssues.map(issue => this.renderIssueItem(issue)).join('')}
                    </div>
                </div>
            `;
        }).join('');
    }

    /**
     * Group issues by category
     */
    groupIssuesByCategory(issues) {
        const groups = {};
        issues.forEach(issue => {
            const group = issue.group || 'general';
            if (!groups[group]) {
                groups[group] = [];
            }
            groups[group].push(issue);
        });
        return groups;
    }

    /**
     * Get group title
     */
    getGroupTitle(groupKey) {
        const titles = {
            'required_components': 'Required Components',
            'cooling': 'Cooling System',
            'memory': 'Memory Configuration',
            'storage': 'Storage Devices',
            'power': 'Power Supply',
            'compatibility': 'Compatibility',
            'general': 'General Issues'
        };
        return titles[groupKey] || 'General Issues';
    }

    /**
     * Get group icon
     */
    getGroupIcon(groupKey) {
        const icons = {
            'required_components': 'fas fa-exclamation-triangle',
            'cooling': 'fas fa-fan',
            'memory': 'fas fa-memory',
            'storage': 'fas fa-hdd',
            'power': 'fas fa-plug',
            'compatibility': 'fas fa-cogs',
            'general': 'fas fa-info-circle'
        };
        return icons[groupKey] || 'fas fa-info-circle';
    }

    /**
     * Render individual issue item
     */
    renderIssueItem(issue) {
        const severityClass = `severity-${issue.severity || 'info'}`;
        const iconClass = issue.icon || 'fas fa-info-circle';
        const hasComponent = issue.componentType && this.selectedComponents[issue.componentType] && this.selectedComponents[issue.componentType].length > 0;

        return `
            <div class="issue-item ${severityClass} ${hasComponent ? 'clickable' : ''}" onclick="window.pcppBuilder.handleIssueClick('${issue.componentType || ''}', this)">
                <div class="issue-header">
                    <div class="issue-icon">
                        <i class="${iconClass}"></i>
                    </div>
                    <div class="issue-content">
                        <div class="issue-title">${issue.title || issue.message}</div>
                        <div class="issue-message">${issue.message}</div>
                    </div>
                    <div class="issue-actions">
                        ${issue.action ? `<button class="issue-action-btn" onclick="event.stopPropagation(); window.pcppBuilder.addComponent('${issue.action.actionType}')">${issue.action.text}</button>` : ''}
                        ${hasComponent ? '<i class="fas fa-arrow-up scroll-icon"></i>' : ''}
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Handle issue item click
     */
    handleIssueClick(componentType, element) {
        if (componentType && this.selectedComponents[componentType] && this.selectedComponents[componentType].length > 0) {
            this.scrollToComponent(componentType);
        } else {
            element.classList.toggle('expanded');
        }
    }

    /**
     * Scroll to component in table
     */
    scrollToComponent(type) {
        const element = document.getElementById(`component-row-${type}`);
        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
            // Highlight briefly
            element.style.backgroundColor = 'var(--highlight-color, #e3f2fd)';
            element.style.transition = 'background-color 0.3s ease';
            setTimeout(() => {
                element.style.backgroundColor = '';
            }, 2000);
        }
    }

    /**
     * Attach event listeners
     */
    attachEventListeners() {
        // Buy button
        const buyButton = document.querySelector('.buy-button');
        if (buyButton) {
            buyButton.addEventListener('click', () => {
                this.showAlert('This would redirect to purchase page in a real implementation', 'info');
            });
        }

        // Issue group expansion toggles
        const issueGroupHeaders = document.querySelectorAll('.issues-group-header');
        issueGroupHeaders.forEach(header => {
            header.addEventListener('click', function() {
                const group = this.closest('.issues-group');
                group.classList.toggle('expanded');
            });
        });

        // Individual issue expansion toggles
        const expandableIssues = document.querySelectorAll('.issue-item.expandable');
        expandableIssues.forEach(issue => {
            issue.addEventListener('click', function() {
                this.classList.toggle('expanded');
            });
        });

        // Initialize groups as expanded by default
        const issueGroups = document.querySelectorAll('.issues-group');
        issueGroups.forEach(group => {
            group.classList.add('expanded');
        });
    }

    /**
     * Show loading overlay
     */
    showLoading(message = 'Loading...', subtext = '') {
        const loadingHtml = `
            <div class="loading-overlay active">
                <div class="loading-content">
                    <div class="server-loader">
                        <div class="server-layer"></div>
                        <div class="server-layer"></div>
                        <div class="server-layer"></div>
                        <div class="server-layer"></div>
                    </div>
                    <div class="loading-text">${message}</div>
                    ${subtext ? `<div class="loading-subtext">${subtext}</div>` : ''}
                </div>
            </div>
        `;

        const existingLoader = document.querySelector('.loading-overlay');
        if (existingLoader) {
            existingLoader.remove();
        }

        document.body.insertAdjacentHTML('beforeend', loadingHtml);
    }

    /**
     * Hide loading overlay
     */
    hideLoading() {
        const loader = document.querySelector('.loading-overlay');
        if (loader) {
            loader.classList.remove('active');
            setTimeout(() => loader.remove(), 300);
        }
    }

    /**
     * Show alert notification
     */
    showAlert(message, type = 'info') {
        const typeMap = {
            'danger': 'error',
            'info': 'info',
            'success': 'success',
            'warning': 'warning'
        };

        const mappedType = typeMap[type] || 'info';

        if (typeof toastNotification !== 'undefined') {
            toastNotification.show(message, mappedType);
        } else {
        }
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.pcppBuilder = new PCPartPickerBuilder();
});

// Add CSS animations and styles
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }

    /* Issues Section Styles */
    .issues-section {
        background: var(--bg-primary, #ffffff);
        border: 1px solid var(--border-color, #e1e5e9);
        border-radius: 8px;
        margin: 1.5rem 0;
        overflow: hidden;
    }

    .issues-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem 1.5rem;
        background: var(--bg-secondary, #f8f9fa);
        border-bottom: 1px solid var(--border-color, #e1e5e9);
    }

    .issues-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1.125rem;
        font-weight: 600;
        color: var(--text-primary, #2c3e50);
        margin: 0;
    }

    .issues-title i {
        color: var(--warning-color, #f39c12);
    }

    .issues-summary {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        color: var(--text-secondary, #6c757d);
    }

    .issues-count {
        background: var(--warning-color, #f39c12);
        color: white;
        padding: 0.125rem 0.5rem;
        border-radius: 12px;
        font-weight: 600;
        min-width: 24px;
        text-align: center;
    }

    .issues-progress {
        padding: 1rem 1.5rem;
        background: var(--bg-primary, #ffffff);
        border-bottom: 1px solid var(--border-color, #e1e5e9);
    }

    .progress-bar {
        width: 100%;
        height: 8px;
        background: var(--bg-tertiary, #e9ecef);
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 0.5rem;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--success-color, #27ae60), var(--warning-color, #f39c12));
        transition: width 0.3s ease;
    }

    .progress-text {
        font-size: 0.875rem;
        color: var(--text-secondary, #6c757d);
        text-align: center;
    }

    .issues-group {
        border-bottom: 1px solid var(--border-color, #e1e5e9);
    }

    .issues-group:last-child {
        border-bottom: none;
    }

    .issues-group-header {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem 1.5rem;
        background: var(--bg-secondary, #f8f9fa);
        cursor: pointer;
        transition: background-color 0.2s ease;
    }

    .issues-group-header:hover {
        background: var(--bg-hover, #e9ecef);
    }

    .issues-group-header i {
        color: var(--text-secondary, #6c757d);
        width: 16px;
    }

    .group-title {
        flex: 1;
        font-weight: 500;
        color: var(--text-primary, #2c3e50);
    }

    .group-count {
        background: var(--primary-color, #3498db);
        color: white;
        padding: 0.125rem 0.5rem;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
        min-width: 20px;
        text-align: center;
    }

    .issues-group-content {
        padding: 0;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
    }

    .issues-group.expanded .issues-group-content {
        max-height: 1000px;
    }

    .issue-item {
        border-bottom: 1px solid var(--border-light, #f1f3f4);
        transition: background-color 0.2s ease;
    }

    .issue-item:last-child {
        border-bottom: none;
    }

    .issue-item:hover {
        background: var(--bg-hover, #f8f9fa);
    }

    .issue-item.expandable {
        cursor: pointer;
    }

    .issue-item.expanded {
        background: var(--bg-expanded, #f8f9fa);
    }

    .issue-header {
        display: flex;
        align-items: flex-start;
        gap: 1rem;
        padding: 1rem 1.5rem;
    }

    .issue-icon {
        flex-shrink: 0;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        font-size: 0.875rem;
    }

    .severity-critical .issue-icon {
        background: var(--error-bg, #fee);
        color: var(--error-color, #e74c3c);
    }

    .severity-warning .issue-icon {
        background: var(--warning-bg, #fff3cd);
        color: var(--warning-color, #f39c12);
    }

    .severity-info .issue-icon {
        background: var(--info-bg, #d1ecf1);
        color: var(--info-color, #17a2b8);
    }

    .issue-content {
        flex: 1;
    }

    .issue-title {
        font-weight: 600;
        color: var(--text-primary, #2c3e50);
        margin-bottom: 0.25rem;
        font-size: 0.875rem;
    }

    .issue-message {
        color: var(--text-secondary, #6c757d);
        font-size: 0.8125rem;
        line-height: 1.4;
    }

    .issue-actions {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-shrink: 0;
    }

    .issue-action-btn {
        background: var(--primary-color, #3498db);
        color: white;
        border: none;
        padding: 0.375rem 0.75rem;
        border-radius: 4px;
        font-size: 0.8125rem;
        font-weight: 500;
        cursor: pointer;
        transition: background-color 0.2s ease;
    }

    .issue-action-btn:hover {
        background: var(--primary-hover, #2980b9);
    }

    .expand-icon {
        color: var(--text-muted, #adb5bd);
        transition: transform 0.2s ease;
    }

    .issue-item.expanded .expand-icon {
        transform: rotate(180deg);
    }

    .issue-details {
        padding: 0 1.5rem 1rem 5rem;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease, padding 0.3s ease;
    }

    .issue-item.expanded .issue-details {
        max-height: 500px;
        padding: 0 1.5rem 1rem 5rem;
    }

    .issue-detail-text {
        color: var(--text-secondary, #6c757d);
        font-size: 0.8125rem;
        line-height: 1.5;
        margin-bottom: 0.75rem;
    }

    .issue-action-large {
        text-align: left;
    }

    .issue-action-large button {
        background: var(--primary-color, #3498db);
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: 4px;
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: background-color 0.2s ease;
    }

    .issue-action-large button:hover {
        background: var(--primary-hover, #2980b9);
    }

    /* Responsive adjustments */
    @media (max-width: 1024px) {
        .issues-header {
            padding: 1rem;
        }

        .issues-title {
            font-size: 1rem;
        }

        .issues-progress {
            padding: 0.75rem 1rem;
        }

        .issues-group-header {
            padding: 0.75rem 1rem;
        }

        .issue-header {
            padding: 0.75rem 1rem;
            gap: 0.75rem;
        }

        .issue-details {
            padding: 0 1rem 0.75rem 4rem;
        }

        .issue-item.expanded .issue-details {
            padding: 0 1rem 0.75rem 4rem;
        }
    }

    @media (max-width: 360px) {
        .issues-header {
            padding: 0.375rem 0.5rem;
        }

        .issues-title {
            font-size: 0.85rem;
        }

        .issues-progress {
            padding: 0.375rem 0.5rem;
        }

        .issues-group-header {
            padding: 0.375rem 0.5rem;
        }

        .issue-header {
            padding: 0.375rem 0.5rem;
        }

        .issue-details {
            padding: 0 0.5rem 0.375rem 0.5rem;
        }

        .issue-item.expanded .issue-details {
            padding: 0 0.5rem 0.375rem 0.5rem;
        }
    }
`;
document.head.appendChild(style);
