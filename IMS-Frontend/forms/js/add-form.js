class AddFormComponent {
    constructor(componentType) {
        this.componentType = componentType;
        this.cpuData = null;
        this.motherboardData = null;
        this.ramData = null;
        this.storageData = null;
        this.caddyData = null;
        this.nicData = null;
        this.hbaData = null;
        this.chassisData = null;
        this.pciData = null;
        this.formContainer = document.getElementById('formFields');
        this.init();
    }

    async init() {
        document.getElementById('formTitle').textContent = `Add New ${this.componentType.toUpperCase()}`;
        document.getElementById('formComponentType').textContent = this.componentType;
        document.getElementById('addComponentForm').addEventListener('submit', (e) => this.handleSubmit(e));
        document.getElementById('cancelAddComponent').addEventListener('click', () => this.handleCancel());

        if (this.componentType === 'cpu') {
            await this.initializeCpuForm();
        } else if (this.componentType === 'motherboard') {
            await this.initializeMotherboardForm();
        } else if (this.componentType === 'ram') {
            await this.initializeRamForm();
        } else if (this.componentType === 'storage') {
            await this.initializeStorageForm();
        } else if (this.componentType === 'caddy') {
            await this.initializeCaddyForm();
        } else if (this.componentType === 'nic') {
            await this.initializeNicForm();
        } else if (this.componentType === 'hba') {
            await this.initializeHbaForm();
        } else if (this.componentType === 'chassis') {
            await this.initializeChassisForm();
        } else if (this.componentType === 'pci') {
            await this.initializePciForm();
        } else {
            this.initializeGenericForm();
        }
    }

    async initializeCpuForm() {
        try {
            const response = await fetch('../All-JSON/cpu-jsons/Cpu-details-level-3.json');
            if (!response.ok) throw new Error('Failed to load CPU data.');
            this.cpuData = await response.json();
            this.renderCpuForm();
            this.setupCpuFormEventListeners();
            this.populateCpuBrands();
        } catch (error) {
            console.error('Error initializing CPU form:', error);
            this.formContainer.innerHTML = `<p class="form-error">Could not load CPU form data. Please try again.</p>`;
        }
    }

    renderCpuForm() {
        this.formContainer.innerHTML = `
            <div class="form-section">
                <h4 class="form-section-title">CPU Selection</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="cpuBrand" class="form-label required">Brand</label>
                        <select id="cpuBrand" name="brand" class="form-select" required>
                            <option value="">Select Brand</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="cpuSeries" class="form-label required">Series</label>
                        <select id="cpuSeries" name="series" class="form-select" required disabled>
                            <option value="">Select Series</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="cpuGeneration" class="form-label required">Generation</label>
                        <select id="cpuGeneration" name="generation" class="form-select" required disabled>
                            <option value="">Select Generation</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="cpuFamily" class="form-label required">Family</label>
                        <select id="cpuFamily" name="family" class="form-select" required disabled>
                            <option value="">Select Family</option>
                        </select>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="cpuModel" class="form-label required">Model</label>
                        <select id="cpuModel" name="model" class="form-select" required disabled>
                            <option value="">Select Model</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-section" id="cpuInfoContainer" style="display: none;">
                <h4 class="form-section-title">CPU Information</h4>
                <div id="cpuInfo" class="form-grid two-column"></div>
            </div>
            <div class="form-section">
                <h4 class="form-section-title">Inventory Details</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="serialNumber" class="form-label required">Serial Number</label>
                        <input type="text" id="serialNumber" name="serialNumber" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label required">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="1">Available</option>
                            <option value="2">In Use</option>
                            <option value="0">Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" id="location" name="location" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="purchaseDate" class="form-label">Purchase Date</label>
                        <input type="date" id="purchaseDate" name="purchaseDate" class="form-input" value="${new Date().toISOString().slice(0, 10)}">
                    </div>
                    <div class="form-group">
                        <label for="warrantyEndDate" class="form-label">Warranty End Date</label>
                        <input type="date" id="warrantyEndDate" name="warrantyEndDate" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="componentUuid" class="form-label">Component UUID</label>
                        <input type="text" id="componentUuid" name="componentUuid" class="form-input" readonly>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes" name="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
        `;
    }

    setupCpuFormEventListeners() {
        document.getElementById('cpuBrand').addEventListener('change', (e) => this.handleCpuBrandChange(e.target.value));
        document.getElementById('cpuSeries').addEventListener('change', (e) => this.handleCpuSeriesChange(e.target.value));
        document.getElementById('cpuGeneration').addEventListener('change', (e) => this.handleCpuGenerationChange(e.target.value));
        document.getElementById('cpuFamily').addEventListener('change', (e) => this.handleCpuFamilyChange(e.target.value));
        document.getElementById('cpuModel').addEventListener('change', (e) => this.handleCpuModelChange(e.target.value));
    }

    populateCpuBrands() {
        const brandSelect = document.getElementById('cpuBrand');
        const brands = [...new Set(this.cpuData.map(cpu => cpu.brand))];
        brands.forEach(brand => {
            const option = new Option(brand, brand);
            brandSelect.add(option);
        });
    }

    handleCpuBrandChange(selectedBrand) {
        const seriesSelect = document.getElementById('cpuSeries');
        this.resetSelect(seriesSelect, 'Select Series');
        this.resetSelect(document.getElementById('cpuGeneration'), 'Select Generation');
        this.resetSelect(document.getElementById('cpuFamily'), 'Select Family');
        this.resetSelect(document.getElementById('cpuModel'), 'Select Model');
        document.getElementById('cpuInfoContainer').style.display = 'none';

        if (selectedBrand) {
            const series = [...new Set(this.cpuData.filter(cpu => cpu.brand === selectedBrand).map(cpu => cpu.series))];
            series.forEach(s => seriesSelect.add(new Option(s, s)));
            seriesSelect.disabled = false;
        }
    }

    handleCpuSeriesChange(selectedSeries) {
        const brand = document.getElementById('cpuBrand').value;
        const genSelect = document.getElementById('cpuGeneration');
        this.resetSelect(genSelect, 'Select Generation');
        this.resetSelect(document.getElementById('cpuFamily'), 'Select Family');
        this.resetSelect(document.getElementById('cpuModel'), 'Select Model');
        document.getElementById('cpuInfoContainer').style.display = 'none';

        if (selectedSeries) {
            const generations = [...new Set(this.cpuData.filter(cpu => cpu.brand === brand && cpu.series === selectedSeries).map(cpu => cpu.generation))];
            generations.forEach(g => genSelect.add(new Option(g, g)));
            genSelect.disabled = false;
        }
    }
    
    handleCpuGenerationChange(selectedGen) {
        const brand = document.getElementById('cpuBrand').value;
        const series = document.getElementById('cpuSeries').value;
        const familySelect = document.getElementById('cpuFamily');
        this.resetSelect(familySelect, 'Select Family');
        this.resetSelect(document.getElementById('cpuModel'), 'Select Model');
        document.getElementById('cpuInfoContainer').style.display = 'none';

        if (selectedGen) {
            const families = [...new Set(this.cpuData.filter(cpu => cpu.brand === brand && cpu.series === series && cpu.generation === selectedGen).map(cpu => cpu.family))];
            families.forEach(f => familySelect.add(new Option(f, f)));
            familySelect.disabled = false;
        }
    }

    handleCpuFamilyChange(selectedFamily) {
        const brand = document.getElementById('cpuBrand').value;
        const series = document.getElementById('cpuSeries').value;
        const generation = document.getElementById('cpuGeneration').value;
        const modelSelect = document.getElementById('cpuModel');
        this.resetSelect(modelSelect, 'Select Model');
        document.getElementById('cpuInfoContainer').style.display = 'none';

        if (selectedFamily) {
            const models = this.cpuData
                .find(cpu => cpu.brand === brand && cpu.series === series && cpu.generation === generation && cpu.family === selectedFamily)
                ?.models.map(m => m.model) || [];
            models.forEach(m => modelSelect.add(new Option(m, m)));
            modelSelect.disabled = false;
        }
    }

    handleCpuModelChange(selectedModel) {
        const brand = document.getElementById('cpuBrand').value;
        const series = document.getElementById('cpuSeries').value;
        const generation = document.getElementById('cpuGeneration').value;
        const family = document.getElementById('cpuFamily').value;
        const infoContainer = document.getElementById('cpuInfoContainer');
        const infoDiv = document.getElementById('cpuInfo');

        if (selectedModel) {
            const cpuDetails = this.cpuData
                .find(cpu => cpu.brand === brand && cpu.series === series && cpu.generation === generation && cpu.family === family)
                ?.models.find(m => m.model === selectedModel);

            if (cpuDetails) {
                infoDiv.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">Architecture</label>
                        <p class="form-static-text">${cpuDetails.architecture || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cores / Threads</label>
                        <p class="form-static-text">${cpuDetails.cores} / ${cpuDetails.threads}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Base Frequency</label>
                        <p class="form-static-text">${cpuDetails.base_frequency_GHz} GHz</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Frequency</label>
                        <p class="form-static-text">${cpuDetails.max_frequency_GHz} GHz</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">L3 Cache</label>
                        <p class="form-static-text">${cpuDetails.l3_cache}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">TDP</label>
                        <p class="form-static-text">${cpuDetails.tdp_W} W</p>
                    </div>
                     <div class="form-group">
                        <label class="form-label">Socket</label>
                        <p class="form-static-text">${cpuDetails.socket}</p>
                    </div>
                     <div class="form-group">
                        <label class="form-label">Memory Types</label>
                        <p class="form-static-text">${(cpuDetails.memory_types || []).join(', ')}</p>
                    </div>
                `;
                infoContainer.style.display = 'block';
                document.getElementById('componentUuid').value = cpuDetails.uuid || '';
            }
        } else {
            infoContainer.style.display = 'none';
        }
    }

    async initializeMotherboardForm() {
        try {
            const response = await fetch('../All-JSON/motherboad-jsons/motherboard-level-3.json');
            if (!response.ok) throw new Error('Failed to load motherboard data.');
            this.motherboardData = await response.json();
            this.renderMotherboardForm();
            this.setupMotherboardFormEventListeners();
            this.populateMotherboardBrands();
        } catch (error) {
            console.error('Error initializing motherboard form:', error);
            this.formContainer.innerHTML = `<p class="form-error">Could not load motherboard form data. Please try again.</p>`;
        }
    }

    renderMotherboardForm() {
        this.formContainer.innerHTML = `
            <div class="form-section">
                <h4 class="form-section-title">Motherboard Selection</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="motherboardBrand" class="form-label required">Brand</label>
                        <select id="motherboardBrand" name="brand" class="form-select" required>
                            <option value="">Select Brand</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="motherboardSeries" class="form-label required">Series</label>
                        <select id="motherboardSeries" name="series" class="form-select" required disabled>
                            <option value="">Select Series</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="motherboardFamily" class="form-label required">Family</label>
                        <select id="motherboardFamily" name="family" class="form-select" required disabled>
                            <option value="">Select Family</option>
                        </select>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="motherboardModel" class="form-label required">Model</label>
                        <select id="motherboardModel" name="model" class="form-select" required disabled>
                            <option value="">Select Model</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-section" id="motherboardInfoContainer" style="display: none;">
                <h4 class="form-section-title">Motherboard Information</h4>
                <div id="motherboardInfo" class="form-grid two-column"></div>
            </div>
            <div class="form-section">
                <h4 class="form-section-title">Inventory Details</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="serialNumber" class="form-label required">Serial Number</label>
                        <input type="text" id="serialNumber" name="serialNumber" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label required">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="1">Available</option>
                            <option value="2">In Use</option>
                            <option value="0">Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" id="location" name="location" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="purchaseDate" class="form-label">Purchase Date</label>
                        <input type="date" id="purchaseDate" name="purchaseDate" class="form-input" value="${new Date().toISOString().slice(0, 10)}">
                    </div>
                    <div class="form-group">
                        <label for="warrantyEndDate" class="form-label">Warranty End Date</label>
                        <input type="date" id="warrantyEndDate" name="warrantyEndDate" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="componentUuid" class="form-label">Component UUID</label>
                        <input type="text" id="componentUuid" name="componentUuid" class="form-input" readonly>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes" name="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
        `;
    }

    setupMotherboardFormEventListeners() {
        document.getElementById('motherboardBrand').addEventListener('change', (e) => this.handleMotherboardBrandChange(e.target.value));
        document.getElementById('motherboardSeries').addEventListener('change', (e) => this.handleMotherboardSeriesChange(e.target.value));
        document.getElementById('motherboardFamily').addEventListener('change', (e) => this.handleMotherboardFamilyChange(e.target.value));
        document.getElementById('motherboardModel').addEventListener('change', (e) => this.handleMotherboardModelChange(e.target.value));
    }

    populateMotherboardBrands() {
        const brandSelect = document.getElementById('motherboardBrand');
        const brands = [...new Set(this.motherboardData.map(mb => mb.brand))];
        brands.forEach(brand => {
            const option = new Option(brand, brand);
            brandSelect.add(option);
        });
    }

    handleMotherboardBrandChange(selectedBrand) {
        const seriesSelect = document.getElementById('motherboardSeries');
        this.resetSelect(seriesSelect, 'Select Series');
        this.resetSelect(document.getElementById('motherboardFamily'), 'Select Family');
        this.resetSelect(document.getElementById('motherboardModel'), 'Select Model');
        document.getElementById('motherboardInfoContainer').style.display = 'none';

        if (selectedBrand) {
            const series = [...new Set(this.motherboardData.filter(mb => mb.brand === selectedBrand).map(mb => mb.series))];
            series.forEach(s => seriesSelect.add(new Option(s, s)));
            seriesSelect.disabled = false;
        }
    }

    handleMotherboardSeriesChange(selectedSeries) {
        const brand = document.getElementById('motherboardBrand').value;
        const familySelect = document.getElementById('motherboardFamily');
        this.resetSelect(familySelect, 'Select Family');
        this.resetSelect(document.getElementById('motherboardModel'), 'Select Model');
        document.getElementById('motherboardInfoContainer').style.display = 'none';

        if (selectedSeries) {
            const families = [...new Set(this.motherboardData.filter(mb => mb.brand === brand && mb.series === selectedSeries).map(mb => mb.family))];
            families.forEach(f => familySelect.add(new Option(f, f)));
            familySelect.disabled = false;
        }
    }

    handleMotherboardFamilyChange(selectedFamily) {
        const brand = document.getElementById('motherboardBrand').value;
        const series = document.getElementById('motherboardSeries').value;
        const modelSelect = document.getElementById('motherboardModel');
        this.resetSelect(modelSelect, 'Select Model');
        document.getElementById('motherboardInfoContainer').style.display = 'none';

        if (selectedFamily) {
            const models = this.motherboardData
                .find(mb => mb.brand === brand && mb.series === series && mb.family === selectedFamily)
                ?.models.map(m => m.model) || [];
            models.forEach(m => modelSelect.add(new Option(m, m)));
            modelSelect.disabled = false;
        }
    }

    handleMotherboardModelChange(selectedModel) {
        const brand = document.getElementById('motherboardBrand').value;
        const series = document.getElementById('motherboardSeries').value;
        const family = document.getElementById('motherboardFamily').value;
        const infoContainer = document.getElementById('motherboardInfoContainer');
        const infoDiv = document.getElementById('motherboardInfo');

        if (selectedModel) {
            const motherboardDetails = this.motherboardData
                .find(mb => mb.brand === brand && mb.series === series && mb.family === family)
                ?.models.find(m => m.model === selectedModel);

            if (motherboardDetails) {
                const pcieSlots = motherboardDetails.expansion_slots?.pcie_slots?.map(slot => `${slot.count}x ${slot.type}`).join(', ') || 'N/A';
                const m2Slots = motherboardDetails.storage?.nvme?.m2_slots?.map(slot => `${slot.count}x M.2 ${slot.form_factors ? slot.form_factors.join('/') : ''}`).join(', ') || 'N/A';
                const onboardNics = motherboardDetails.networking?.onboard_nics?.map(nic => `${nic.ports}x ${nic.speed} (${nic.controller})`).join(', ') || 'N/A';

                infoDiv.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">Form Factor</label>
                        <p class="form-static-text">${motherboardDetails.form_factor || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Dimensions</label>
                        <p class="form-static-text">${motherboardDetails.dimensions ? `${motherboardDetails.dimensions.length_mm}mm x ${motherboardDetails.dimensions.width_mm}mm` : 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Socket</label>
                        <p class="form-static-text">${motherboardDetails.socket ? `${motherboardDetails.socket.type} (${motherboardDetails.socket.count})` : 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Chipset</label>
                        <p class="form-static-text">${motherboardDetails.chipset || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Memory Type</label>
                        <p class="form-static-text">${motherboardDetails.memory ? motherboardDetails.memory.type : 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Memory</label>
                        <p class="form-static-text">${motherboardDetails.memory ? `${motherboardDetails.memory.max_capacity_TB} TB` : 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Memory Slots</label>
                        <p class="form-static-text">${motherboardDetails.memory ? motherboardDetails.memory.slots : 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">SATA Ports</label>
                        <p class="form-static-text">${motherboardDetails.storage?.sata?.ports || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">M.2 Slots</label>
                        <p class="form-static-text">${m2Slots}</p>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label class="form-label">PCIe Slots</label>
                        <p class="form-static-text">${pcieSlots}</p>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label class="form-label">Onboard NICs</label>
                        <p class="form-static-text">${onboardNics}</p>
                    </div>
                `;
                infoContainer.style.display = 'block';
                document.getElementById('componentUuid').value = motherboardDetails.uuid || '';
            }
        } else {
            infoContainer.style.display = 'none';
        }
    }

    async initializeRamForm() {
        try {
            const response = await fetch('../All-JSON/Ram-jsons/ram_detail.json');
            if (!response.ok) throw new Error('Failed to load RAM data.');
            this.ramData = await response.json();
            this.renderRamForm();
            this.setupRamFormEventListeners();
            this.populateRamBrands();
        } catch (error) {
            console.error('Error initializing RAM form:', error);
            this.formContainer.innerHTML = `<p class="form-error">Could not load RAM form data. Please try again.</p>`;
        }
    }

    renderRamForm() {
        this.formContainer.innerHTML = `
            <div class="form-section">
                <h4 class="form-section-title">RAM Selection</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="ramBrand" class="form-label required">Brand</label>
                        <select id="ramBrand" name="brand" class="form-select" required>
                            <option value="">Select Brand</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ramSeries" class="form-label required">Series</label>
                        <select id="ramSeries" name="series" class="form-select" required disabled>
                            <option value="">Select Series</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ramModuleType" class="form-label required">Module Type</label>
                        <select id="ramModuleType" name="module_type" class="form-select" required disabled>
                            <option value="">Select Module Type</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ramCapacity" class="form-label required">Capacity (GB)</label>
                        <select id="ramCapacity" name="capacity_GB" class="form-select" required disabled>
                            <option value="">Select Capacity</option>
                        </select>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="ramModel" class="form-label required">Model</label>
                        <select id="ramModel" name="model" class="form-select" required disabled>
                            <option value="">Select Model</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-section" id="ramInfoContainer" style="display: none;">
                <h4 class="form-section-title">RAM Information</h4>
                <div id="ramInfo" class="form-grid two-column"></div>
            </div>
            <div class="form-section">
                <h4 class="form-section-title">Inventory Details</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="serialNumber" class="form-label required">Serial Number</label>
                        <input type="text" id="serialNumber" name="serialNumber" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label required">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="1">Available</option>
                            <option value="2">In Use</option>
                            <option value="0">Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" id="location" name="location" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="purchaseDate" class="form-label">Purchase Date</label>
                        <input type="date" id="purchaseDate" name="purchaseDate" class="form-input" value="${new Date().toISOString().slice(0, 10)}">
                    </div>
                    <div class="form-group">
                        <label for="warrantyEndDate" class="form-label">Warranty End Date</label>
                        <input type="date" id="warrantyEndDate" name="warrantyEndDate" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="componentUuid" class="form-label">Component UUID</label>
                        <input type="text" id="componentUuid" name="componentUuid" class="form-input" readonly>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes" name="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
        `;
    }

    setupRamFormEventListeners() {
        document.getElementById('ramBrand').addEventListener('change', (e) => this.handleRamBrandChange(e.target.value));
        document.getElementById('ramSeries').addEventListener('change', (e) => this.handleRamSeriesChange(e.target.value));
        document.getElementById('ramModuleType').addEventListener('change', (e) => this.handleRamModuleTypeChange(e.target.value));
        document.getElementById('ramCapacity').addEventListener('change', (e) => this.handleRamCapacityChange(e.target.value));
        document.getElementById('ramModel').addEventListener('change', (e) => this.handleRamModelChange(e.target.value));
    }

    populateRamBrands() {
        const brandSelect = document.getElementById('ramBrand');
        const brands = [...new Set(this.ramData.map(ram => ram.brand))];
        brands.forEach(brand => {
            const option = new Option(brand, brand);
            brandSelect.add(option);
        });
    }

    handleRamBrandChange(selectedBrand) {
        const seriesSelect = document.getElementById('ramSeries');
        this.resetSelect(seriesSelect, 'Select Series');
        this.resetSelect(document.getElementById('ramModuleType'), 'Select Module Type');
        this.resetSelect(document.getElementById('ramCapacity'), 'Select Capacity');
        this.resetSelect(document.getElementById('ramModel'), 'Select Model');
        document.getElementById('ramInfoContainer').style.display = 'none';

        if (selectedBrand) {
            const series = [...new Set(this.ramData.filter(ram => ram.brand === selectedBrand).map(ram => ram.series))];
            series.forEach(s => seriesSelect.add(new Option(s, s)));
            seriesSelect.disabled = false;
        }
    }

    handleRamSeriesChange(selectedSeries) {
        const brand = document.getElementById('ramBrand').value;
        const moduleTypeSelect = document.getElementById('ramModuleType');
        this.resetSelect(moduleTypeSelect, 'Select Module Type');
        this.resetSelect(document.getElementById('ramCapacity'), 'Select Capacity');
        this.resetSelect(document.getElementById('ramModel'), 'Select Model');
        document.getElementById('ramInfoContainer').style.display = 'none';

        if (selectedSeries) {
            const moduleTypes = [...new Set(this.ramData
                .find(ram => ram.brand === brand && ram.series === selectedSeries)
                ?.models.map(m => m.module_type))];
            moduleTypes.forEach(mt => moduleTypeSelect.add(new Option(mt, mt)));
            moduleTypeSelect.disabled = false;
        }
    }

    handleRamModuleTypeChange(selectedModuleType) {
        const brand = document.getElementById('ramBrand').value;
        const series = document.getElementById('ramSeries').value;
        const capacitySelect = document.getElementById('ramCapacity');
        this.resetSelect(capacitySelect, 'Select Capacity');
        this.resetSelect(document.getElementById('ramModel'), 'Select Model');
        document.getElementById('ramInfoContainer').style.display = 'none';

        if (selectedModuleType) {
            const models = this.ramData
                .find(ram => ram.brand === brand && ram.series === series)
                ?.models;

            if (models) {
                const capacities = [...new Set(models
                    .filter(m => m.module_type === selectedModuleType)
                    .map(m => m.capacity_GB))];
                
                capacities.forEach(c => capacitySelect.add(new Option(`${c} GB`, c)));
                capacitySelect.disabled = false;
            }
        }
    }

    handleRamCapacityChange(selectedCapacity) {
        const brand = document.getElementById('ramBrand').value;
        const series = document.getElementById('ramSeries').value;
        const moduleType = document.getElementById('ramModuleType').value;
        const modelSelect = document.getElementById('ramModel');
        this.resetSelect(modelSelect, 'Select Model');
        document.getElementById('ramInfoContainer').style.display = 'none';

        if (selectedCapacity) {
            const models = this.ramData
                .find(ram => ram.brand === brand && ram.series === series)
                ?.models?.filter(m => m.module_type === moduleType && m.capacity_GB == selectedCapacity);
            
            if (models) {
                models.forEach(m => {
                    const modelName = `${m.memory_type} ${m.speed_MTs}MT/s ${m.timing.cas_latency ? `CL${m.timing.cas_latency}`: ''}`;
                    modelSelect.add(new Option(modelName, m.uuid));
                });
                modelSelect.disabled = false;
            }
        }
    }

    handleRamModelChange(selectedModelUuid) {
        const brand = document.getElementById('ramBrand').value;
        const series = document.getElementById('ramSeries').value;
        const infoContainer = document.getElementById('ramInfoContainer');
        const infoDiv = document.getElementById('ramInfo');

        if (selectedModelUuid) {
            const ramDetails = this.ramData
                .find(ram => ram.brand === brand && ram.series === series)
                ?.models.find(m => m.uuid === selectedModelUuid);

            if (ramDetails) {
                infoDiv.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">Memory Type</label>
                        <p class="form-static-text">${ramDetails.memory_type || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Form Factor</label>
                        <p class="form-static-text">${ramDetails.form_factor || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Frequency</label>
                        <p class="form-static-text">${ramDetails.frequency_MHz} MHz</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Speed</label>
                        <p class="form-static-text">${ramDetails.speed_MTs} MT/s</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">CAS Latency</label>
                        <p class="form-static-text">${ramDetails.timing.cas_latency || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Voltage</label>
                        <p class="form-static-text">${ramDetails.voltage_V} V</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">ECC Support</label>
                        <p class="form-static-text">${ramDetails.features.ecc_support ? 'Yes' : 'No'}</p>
                    </div>
                `;
                infoContainer.style.display = 'block';
                document.getElementById('componentUuid').value = ramDetails.uuid || '';
            }
        } else {
            infoContainer.style.display = 'none';
        }
    }

    async initializeStorageForm() {
        try {
            const response = await fetch('../All-JSON/storage-jsons/storage-level-3.json');
            if (!response.ok) throw new Error('Failed to load storage data.');
            this.storageData = await response.json();
            this.renderStorageForm();
            this.setupStorageFormEventListeners();
            this.populateStorageBrands();
        } catch (error) {
            console.error('Error initializing storage form:', error);
            this.formContainer.innerHTML = `<p class="form-error">Could not load storage form data. Please try again.</p>`;
        }
    }

    renderStorageForm() {
        this.formContainer.innerHTML = `
            <div class="form-section">
                <h4 class="form-section-title">Storage Selection</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="storageBrand" class="form-label required">Brand</label>
                        <select id="storageBrand" name="brand" class="form-select" required>
                            <option value="">Select Brand</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="storageSeries" class="form-label required">Series</label>
                        <select id="storageSeries" name="series" class="form-select" required disabled>
                            <option value="">Select Series</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="storageType" class="form-label required">Type</label>
                        <select id="storageType" name="storage_type" class="form-select" required disabled>
                            <option value="">Select Type</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="storageSubtype" class="form-label required">Subtype</label>
                        <select id="storageSubtype" name="subtype" class="form-select" required disabled>
                            <option value="">Select Subtype</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="storageCapacity" class="form-label required">Capacity (GB)</label>
                        <select id="storageCapacity" name="capacity_GB" class="form-select" required disabled>
                            <option value="">Select Capacity</option>
                        </select>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="storageModel" class="form-label required">Model</label>
                        <select id="storageModel" name="model" class="form-select" required disabled>
                            <option value="">Select Model</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-section" id="storageInfoContainer" style="display: none;">
                <h4 class="form-section-title">Storage Information</h4>
                <div id="storageInfo" class="form-grid two-column"></div>
            </div>
            <div class="form-section">
                <h4 class="form-section-title">Inventory Details</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="serialNumber" class="form-label required">Serial Number</label>
                        <input type="text" id="serialNumber" name="serialNumber" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label required">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="1">Available</option>
                            <option value="2">In Use</option>
                            <option value="0">Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" id="location" name="location" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="purchaseDate" class="form-label">Purchase Date</label>
                        <input type="date" id="purchaseDate" name="purchaseDate" class="form-input" value="${new Date().toISOString().slice(0, 10)}">
                    </div>
                    <div class="form-group">
                        <label for="warrantyEndDate" class="form-label">Warranty End Date</label>
                        <input type="date" id="warrantyEndDate" name="warrantyEndDate" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="componentUuid" class="form-label">Component UUID</label>
                        <input type="text" id="componentUuid" name="componentUuid" class="form-input" readonly>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes" name="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
        `;
    }

    setupStorageFormEventListeners() {
        document.getElementById('storageBrand').addEventListener('change', (e) => this.handleStorageBrandChange(e.target.value));
        document.getElementById('storageSeries').addEventListener('change', (e) => this.handleStorageSeriesChange(e.target.value));
        document.getElementById('storageType').addEventListener('change', (e) => this.handleStorageTypeChange(e.target.value));
        document.getElementById('storageSubtype').addEventListener('change', (e) => this.handleStorageSubtypeChange(e.target.value));
        document.getElementById('storageCapacity').addEventListener('change', (e) => this.handleStorageCapacityChange(e.target.value));
        document.getElementById('storageModel').addEventListener('change', (e) => this.handleStorageModelChange(e.target.value));
    }

    populateStorageBrands() {
        const brandSelect = document.getElementById('storageBrand');
        const brands = [...new Set(this.storageData.map(s => s.brand))];
        brands.forEach(brand => {
            const option = new Option(brand, brand);
            brandSelect.add(option);
        });
    }

    handleStorageBrandChange(selectedBrand) {
        const seriesSelect = document.getElementById('storageSeries');
        this.resetSelect(seriesSelect, 'Select Series');
        this.resetSelect(document.getElementById('storageType'), 'Select Type');
        this.resetSelect(document.getElementById('storageSubtype'), 'Select Subtype');
        this.resetSelect(document.getElementById('storageCapacity'), 'Select Capacity');
        this.resetSelect(document.getElementById('storageModel'), 'Select Model');
        document.getElementById('storageInfoContainer').style.display = 'none';

        if (selectedBrand) {
            const series = [...new Set(this.storageData.filter(s => s.brand === selectedBrand).map(s => s.series))];
            series.forEach(s => seriesSelect.add(new Option(s, s)));
            seriesSelect.disabled = false;
        }
    }

    handleStorageSeriesChange(selectedSeries) {
        const brand = document.getElementById('storageBrand').value;
        const typeSelect = document.getElementById('storageType');
        this.resetSelect(typeSelect, 'Select Type');
        this.resetSelect(document.getElementById('storageSubtype'), 'Select Subtype');
        this.resetSelect(document.getElementById('storageCapacity'), 'Select Capacity');
        this.resetSelect(document.getElementById('storageModel'), 'Select Model');
        document.getElementById('storageInfoContainer').style.display = 'none';

        if (selectedSeries) {
            const types = [...new Set(this.storageData
                .find(s => s.brand === brand && s.series === selectedSeries)
                ?.models.map(m => m.storage_type) || [])];
            types.forEach(t => typeSelect.add(new Option(t, t)));
            typeSelect.disabled = false;
        }
    }

    handleStorageTypeChange(selectedType) {
        const brand = document.getElementById('storageBrand').value;
        const series = document.getElementById('storageSeries').value;
        const subtypeSelect = document.getElementById('storageSubtype');
        this.resetSelect(subtypeSelect, 'Select Subtype');
        this.resetSelect(document.getElementById('storageCapacity'), 'Select Capacity');
        this.resetSelect(document.getElementById('storageModel'), 'Select Model');
        document.getElementById('storageInfoContainer').style.display = 'none';

        if (selectedType) {
            const subtypes = [...new Set(this.storageData
                .find(s => s.brand === brand && s.series === series)
                ?.models.filter(m => m.storage_type === selectedType)
                .map(m => m.subtype) || [])];
            subtypes.forEach(st => subtypeSelect.add(new Option(st, st)));
            subtypeSelect.disabled = false;
        }
    }

    handleStorageSubtypeChange(selectedSubtype) {
        const brand = document.getElementById('storageBrand').value;
        const series = document.getElementById('storageSeries').value;
        const type = document.getElementById('storageType').value;
        const capacitySelect = document.getElementById('storageCapacity');
        this.resetSelect(capacitySelect, 'Select Capacity');
        this.resetSelect(document.getElementById('storageModel'), 'Select Model');
        document.getElementById('storageInfoContainer').style.display = 'none';

        if (selectedSubtype) {
            const capacities = [...new Set(this.storageData
                .find(s => s.brand === brand && s.series === series)
                ?.models.filter(m => m.storage_type === type && m.subtype === selectedSubtype)
                .map(m => m.capacity_GB) || [])];
            capacities.forEach(c => capacitySelect.add(new Option(`${c} GB`, c)));
            capacitySelect.disabled = false;
        }
    }

    handleStorageCapacityChange(selectedCapacity) {
        const brand = document.getElementById('storageBrand').value;
        const series = document.getElementById('storageSeries').value;
        const type = document.getElementById('storageType').value;
        const subtype = document.getElementById('storageSubtype').value;
        const modelSelect = document.getElementById('storageModel');
        this.resetSelect(modelSelect, 'Select Model');
        document.getElementById('storageInfoContainer').style.display = 'none';

        if (selectedCapacity) {
            const models = this.storageData
                .find(s => s.brand === brand && s.series === series)
                ?.models.filter(m => m.storage_type === type && m.subtype === subtype && m.capacity_GB == selectedCapacity) || [];
            
            models.forEach(m => {
                const modelName = `${m.interface} ${m.form_factor}`;
                modelSelect.add(new Option(modelName, m.uuid));
            });
            modelSelect.disabled = false;
        }
    }

    handleStorageModelChange(selectedModelUuid) {
        const infoContainer = document.getElementById('storageInfoContainer');
        const infoDiv = document.getElementById('storageInfo');

        if (selectedModelUuid) {
            const storageDetails = this.storageData
                .flatMap(s => s.models)
                .find(m => m.uuid === selectedModelUuid);

            if (storageDetails) {
                let specs = '';
                if (storageDetails.specifications) {
                    if (storageDetails.specifications.rpm) {
                        specs += `<p class="form-static-text">${storageDetails.specifications.rpm} RPM</p>`;
                    }
                    if (storageDetails.specifications.read_speed_MBps) {
                        specs += `<p class="form-static-text">Read: ${storageDetails.specifications.read_speed_MBps} MB/s</p>`;
                    }
                    if (storageDetails.specifications.write_speed_MBps) {
                        specs += `<p class="form-static-text">Write: ${storageDetails.specifications.write_speed_MBps} MB/s</p>`;
                    }
                }

                infoDiv.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">Interface</label>
                        <p class="form-static-text">${storageDetails.interface || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Form Factor</label>
                        <p class="form-static-text">${storageDetails.form_factor || 'N/A'}</p>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label class="form-label">Specifications</label>
                        ${specs}
                    </div>
                `;
                infoContainer.style.display = 'block';
                document.getElementById('componentUuid').value = storageDetails.uuid || '';
            }
        } else {
            infoContainer.style.display = 'none';
        }
    }

    async initializeCaddyForm() {
        try {
            const response = await fetch('../All-JSON/caddy-jsons/caddy_details.json');
            if (!response.ok) throw new Error('Failed to load caddy data.');
            this.caddyData = await response.json();
            this.renderCaddyForm();
            this.setupCaddyFormEventListeners();
            this.populateCaddyDriveTypes();
        } catch (error) {
            console.error('Error initializing caddy form:', error);
            this.formContainer.innerHTML = `<p class="form-error">Could not load caddy form data. Please try again.</p>`;
        }
    }

    renderCaddyForm() {
        this.formContainer.innerHTML = `
            <div class="form-section">
                <h4 class="form-section-title">Caddy Selection</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="caddyDriveType" class="form-label required">Drive Type</label>
                        <select id="caddyDriveType" name="drive_type" class="form-select" required>
                            <option value="">Select Drive Type</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="caddySize" class="form-label required">Size</label>
                        <select id="caddySize" name="size" class="form-select" required disabled>
                            <option value="">Select Size</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="caddyInterface" class="form-label required">Interface</label>
                        <select id="caddyInterface" name="interface" class="form-select" required disabled>
                            <option value="">Select Interface</option>
                        </select>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="caddyModel" class="form-label required">Model</label>
                        <select id="caddyModel" name="model" class="form-select" required disabled>
                            <option value="">Select Model</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-section" id="caddyInfoContainer" style="display: none;">
                <h4 class="form-section-title">Caddy Information</h4>
                <div id="caddyInfo" class="form-grid two-column"></div>
            </div>
            <div class="form-section">
                <h4 class="form-section-title">Inventory Details</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="serialNumber" class="form-label required">Serial Number</label>
                        <input type="text" id="serialNumber" name="serialNumber" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label required">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="1">Available</option>
                            <option value="2">In Use</option>
                            <option value="0">Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" id="location" name="location" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="purchaseDate" class="form-label">Purchase Date</label>
                        <input type="date" id="purchaseDate" name="purchaseDate" class="form-input" value="${new Date().toISOString().slice(0, 10)}">
                    </div>
                    <div class="form-group">
                        <label for="warrantyEndDate" class="form-label">Warranty End Date</label>
                        <input type="date" id="warrantyEndDate" name="warrantyEndDate" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="componentUuid" class="form-label">Component UUID</label>
                        <input type="text" id="componentUuid" name="componentUuid" class="form-input" readonly>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes" name="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
        `;
    }

    setupCaddyFormEventListeners() {
        document.getElementById('caddyDriveType').addEventListener('change', (e) => this.handleCaddyDriveTypeChange(e.target.value));
        document.getElementById('caddySize').addEventListener('change', (e) => this.handleCaddySizeChange(e.target.value));
        document.getElementById('caddyInterface').addEventListener('change', (e) => this.handleCaddyInterfaceChange(e.target.value));
        document.getElementById('caddyModel').addEventListener('change', (e) => this.handleCaddyModelChange(e.target.value));
    }

    populateCaddyDriveTypes() {
        const driveTypeSelect = document.getElementById('caddyDriveType');
        if (!this.caddyData || !this.caddyData.caddies) return;
        const driveTypes = [...new Set(this.caddyData.caddies.flatMap(c => c.compatibility?.drive_type).filter(Boolean))];
        driveTypes.forEach(dt => {
            const option = new Option(dt, dt);
            driveTypeSelect.add(option);
        });
    }

    handleCaddyDriveTypeChange(selectedDriveType) {
        const sizeSelect = document.getElementById('caddySize');
        this.resetSelect(sizeSelect, 'Select Size');
        this.resetSelect(document.getElementById('caddyInterface'), 'Select Interface');
        this.resetSelect(document.getElementById('caddyModel'), 'Select Model');
        document.getElementById('caddyInfoContainer').style.display = 'none';

        if (!selectedDriveType || !this.caddyData || !this.caddyData.caddies) {
            return;
        }

        const sizes = [...new Set(this.caddyData.caddies
            .filter(c => c.compatibility?.drive_type?.includes(selectedDriveType))
            .map(c => c.compatibility?.size)
            .filter(Boolean))];
        
        sizes.forEach(s => sizeSelect.add(new Option(s, s)));
        sizeSelect.disabled = false;
    }

    handleCaddySizeChange(selectedSize) {
        const driveType = document.getElementById('caddyDriveType').value;
        const interfaceSelect = document.getElementById('caddyInterface');
        this.resetSelect(interfaceSelect, 'Select Interface');
        this.resetSelect(document.getElementById('caddyModel'), 'Select Model');
        document.getElementById('caddyInfoContainer').style.display = 'none';

        if (!selectedSize || !driveType || !this.caddyData || !this.caddyData.caddies) {
            return;
        }

        const interfaces = [...new Set(this.caddyData.caddies
            .filter(c => c.compatibility?.drive_type?.includes(driveType) && c.compatibility?.size === selectedSize)
            .map(c => c.compatibility?.interface)
            .filter(Boolean))];
        
        interfaces.forEach(i => interfaceSelect.add(new Option(i, i)));
        interfaceSelect.disabled = false;
    }

    handleCaddyInterfaceChange(selectedInterface) {
        const driveType = document.getElementById('caddyDriveType').value;
        const size = document.getElementById('caddySize').value;
        const modelSelect = document.getElementById('caddyModel');
        this.resetSelect(modelSelect, 'Select Model');
        document.getElementById('caddyInfoContainer').style.display = 'none';

        if (!selectedInterface || !driveType || !size || !this.caddyData || !this.caddyData.caddies) {
            return;
        }

        const models = this.caddyData.caddies
            .filter(c => 
                c.compatibility?.drive_type?.includes(driveType) && 
                c.compatibility?.size === size && 
                c.compatibility?.interface === selectedInterface
            );
        
        models.forEach(m => {
            if (m && m.model) {
                modelSelect.add(new Option(m.model, m.model));
            }
        });
        modelSelect.disabled = false;
    }

    handleCaddyModelChange(selectedModel) {
        const infoContainer = document.getElementById('caddyInfoContainer');
        const infoDiv = document.getElementById('caddyInfo');

        if (selectedModel) {
            const caddyDetails = this.caddyData.caddies.find(c => c.model === selectedModel);

            if (caddyDetails) {
                infoDiv.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">Material</label>
                        <p class="form-static-text">${caddyDetails.material || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Connector</label>
                        <p class="form-static-text">${caddyDetails.connector || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Weight</label>
                        <p class="form-static-text">${caddyDetails.weight || 'N/A'}</p>
                    </div>
                `;
                infoContainer.style.display = 'block';
                document.getElementById('componentUuid').value = caddyDetails.uuid; // Using model as UUID
            }
        } else {
            infoContainer.style.display = 'none';
        }
    }

    async initializeNicForm() {
        try {
            const response = await fetch('../All-JSON/nic-jsons/nic-level-3.json');
            if (!response.ok) throw new Error('Failed to load NIC data.');
            this.nicData = await response.json();
            this.renderNicForm();
            this.setupNicFormEventListeners();
            this.populateNicCategories();
        } catch (error) {
            console.error('Error initializing NIC form:', error);
            this.formContainer.innerHTML = `<p class="form-error">Could not load NIC form data. Please try again.</p>`;
        }
    }

    renderNicForm() {
        this.formContainer.innerHTML = `
            <div class="form-section">
                <h4 class="form-section-title">NIC Selection</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="nicCategory" class="form-label required">Category</label>
                        <select id="nicCategory" name="category" class="form-select" required>
                            <option value="">Select Category</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="nicBrand" class="form-label required">Brand</label>
                        <select id="nicBrand" name="brand" class="form-select" required disabled>
                            <option value="">Select Brand</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="nicSeries" class="form-label required">Series</label>
                        <select id="nicSeries" name="series" class="form-select" required disabled>
                            <option value="">Select Series</option>
                        </select>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="nicModel" class="form-label required">Model</label>
                        <select id="nicModel" name="model" class="form-select" required disabled>
                            <option value="">Select Model</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-section" id="nicInfoContainer" style="display: none;">
                <h4 class="form-section-title">NIC Information</h4>
                <div id="nicInfo" class="form-grid two-column"></div>
            </div>
            <div class="form-section">
                <h4 class="form-section-title">Inventory Details</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="serialNumber" class="form-label required">Serial Number</label>
                        <input type="text" id="serialNumber" name="serialNumber" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label required">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="1">Available</option>
                            <option value="2">In Use</option>
                            <option value="0">Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" id="location" name="location" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="purchaseDate" class="form-label">Purchase Date</label>
                        <input type="date" id="purchaseDate" name="purchaseDate" class="form-input" value="${new Date().toISOString().slice(0, 10)}">
                    </div>
                    <div class="form-group">
                        <label for="warrantyEndDate" class="form-label">Warranty End Date</label>
                        <input type="date" id="warrantyEndDate" name="warrantyEndDate" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="componentUuid" class="form-label">Component UUID</label>
                        <input type="text" id="componentUuid" name="componentUuid" class="form-input" readonly>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes" name="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
        `;
    }

    setupNicFormEventListeners() {
        document.getElementById('nicCategory').addEventListener('change', (e) => this.handleNicCategoryChange(e.target.value));
        document.getElementById('nicBrand').addEventListener('change', (e) => this.handleNicBrandChange(e.target.value));
        document.getElementById('nicSeries').addEventListener('change', (e) => this.handleNicSeriesChange(e.target.value));
        document.getElementById('nicModel').addEventListener('change', (e) => this.handleNicModelChange(e.target.value));
    }

    populateNicCategories() {
        const categorySelect = document.getElementById('nicCategory');
        const categories = [...new Set(this.nicData.map(nic => nic.category))];
        categories.forEach(category => {
            const option = new Option(category, category);
            categorySelect.add(option);
        });
    }

    handleNicCategoryChange(selectedCategory) {
        const brandSelect = document.getElementById('nicBrand');
        this.resetSelect(brandSelect, 'Select Brand');
        this.resetSelect(document.getElementById('nicSeries'), 'Select Series');
        this.resetSelect(document.getElementById('nicModel'), 'Select Model');
        document.getElementById('nicInfoContainer').style.display = 'none';

        if (selectedCategory) {
            const brands = [...new Set(this.nicData.filter(nic => nic.category === selectedCategory).map(nic => nic.brand))];
            brands.forEach(brand => brandSelect.add(new Option(brand, brand)));
            brandSelect.disabled = false;
        }
    }

    handleNicBrandChange(selectedBrand) {
        const category = document.getElementById('nicCategory').value;
        const seriesSelect = document.getElementById('nicSeries');
        this.resetSelect(seriesSelect, 'Select Series');
        this.resetSelect(document.getElementById('nicModel'), 'Select Model');
        document.getElementById('nicInfoContainer').style.display = 'none';

        if (selectedBrand) {
            const series = [...new Set(this.nicData.filter(nic => nic.category === category && nic.brand === selectedBrand).flatMap(nic => nic.series.map(s => s.name)))];
            series.forEach(s => seriesSelect.add(new Option(s, s)));
            seriesSelect.disabled = false;
        }
    }

    handleNicSeriesChange(selectedSeries) {
        const category = document.getElementById('nicCategory').value;
        const brand = document.getElementById('nicBrand').value;
        const modelSelect = document.getElementById('nicModel');
        this.resetSelect(modelSelect, 'Select Model');
        document.getElementById('nicInfoContainer').style.display = 'none';

        if (selectedSeries) {
            const matchingNics = this.nicData.filter(nic => nic.category === category && nic.brand === brand);
            let models = [];
            matchingNics.forEach(nic => {
                const series = nic.series.find(s => s.name === selectedSeries);
                if (series && series.models) {
                    series.models.forEach(modelObj => {
                        if (modelObj.model && !models.includes(modelObj.model)) {
                            models.push(modelObj.model);
                        }
                    });
                }
            });
            models.forEach(m => modelSelect.add(new Option(m, m)));
            modelSelect.disabled = false;
        }
    }

    handleNicFamilyChange(selectedFamily) {
        const category = document.getElementById('nicCategory').value;
        const brand = document.getElementById('nicBrand').value;
        const series = document.getElementById('nicSeries').value;  
        const modelSelect = document.getElementById('nicModel');
        this.resetSelect(modelSelect, 'Select Model');
        document.getElementById('nicInfoContainer').style.display = 'none';

        if (selectedFamily) {
            const matchingNics = this.nicData.filter(nic => nic.category === category && nic.brand === brand);
            const models = [];
            
            matchingNics.forEach(nic => {
                const matchingSeries = nic.series.find(s => s.name === series);
                if (matchingSeries && matchingSeries.families) {
                    const family = matchingSeries.families.find(f => f.name === selectedFamily);
                    if (family && family.port_configurations) {
                        family.port_configurations.forEach(config => {
                            if (!models.includes(config.model)) {
                                models.push(config.model);
                            }
                        });
                    }
                }
            });
            
            models.forEach(m => modelSelect.add(new Option(m, m)));
            modelSelect.disabled = false;
        }
    }

    handleNicModelChange(selectedModel) {
        const category = document.getElementById('nicCategory').value;
        const brand = document.getElementById('nicBrand').value;
        const series = document.getElementById('nicSeries').value;
        const infoContainer = document.getElementById('nicInfoContainer');
        const infoDiv = document.getElementById('nicInfo');

        if (selectedModel) {
            const matchingNics = this.nicData.filter(nic => nic.category === category && nic.brand === brand);
            let familyDetails = null;
            let nicDetails = null;

            matchingNics.forEach(nic => {
                const matchingSeries = nic.series.find(s => s.name === series);
                if (matchingSeries?.models) {
                    matchingSeries.models.forEach(m => {
                        if (m.model === selectedModel) {
                            nicDetails = m;          // matched model
                            familyDetails = matchingSeries; // the series
                        }
                    });
                }
            });

            if (nicDetails && familyDetails) {
                const virtualization = familyDetails.advanced_features?.virtualization;
                const rdma = familyDetails.advanced_features?.offloading?.rdma_support;

                infoDiv.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">Ports</label>
                        <p class="form-static-text">${nicDetails.ports || 'N/A'} x ${nicDetails.port_type || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Speeds Supported</label>
                        <p class="form-static-text">${nicDetails.speeds?.join(', ') || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Form Factor</label>
                        <p class="form-static-text">${nicDetails.interface || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Power</label>
                        <p class="form-static-text">${nicDetails.power || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Features</label>
                        <p class="form-static-text">${nicDetails.features}</p>
                    </div>
                `;
                infoContainer.style.display = 'block';
                document.getElementById('componentUuid').value = nicDetails.uuid || '';
            }
        } else {
            infoContainer.style.display = 'none';
        }
    }

    async initializeChassisForm() {
        try {
            const response = await fetch('../All-JSON/chasis-jsons/chasis-level-3.json');
            if (!response.ok) throw new Error('Failed to load Chasis data.');
            this.chassisData = await response.json();
            this.renderChassisForm();
            this.setupChassisFormEventListeners();
            this.populateChassisCategories();
        } catch (error) {
            console.error('Error initializing Chassis form:', error);
            this.formContainer.innerHTML = `<p class="form-error">Could not load Chassis form data. Please try again.</p>`;
        }
    }

    renderChassisForm() {
        this.formContainer.innerHTML = `
            <div class="form-section">
                <h4 class="form-section-title">Chassis Selection</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="chassisManufacturers" class="form-label required">Manufacturers</label>
                        <select id="chassisManufacturers" name="category" class="form-select" required>
                            <option value="">Select Category</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="chassisSeries" class="form-label required">Series</label>
                        <select id="chassisSeries" name="series" class="form-select" required disabled>
                            <option value="">Select Series</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="chassisBrand" class="form-label required">Brand</label>
                        <select id="chassisBrand" name="brand" class="form-select" required disabled>
                            <option value="">Select Brand</option>
                        </select>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="chassisModel" class="form-label required">Model</label>
                        <select id="chassisModel" name="model" class="form-select" required disabled>
                            <option value="">Select Model</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-section" id="chassisInfoContainer" style="display: none;">
                <h4 class="form-section-title">Chassis Information</h4>
                <div id="chassisInfo" class="form-grid two-column"></div>
            </div>
            <div class="form-section">
                <h4 class="form-section-title">Inventory Details</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="serialNumber" class="form-label required">Serial Number</label>
                        <input type="text" id="serialNumber" name="serialNumber" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label required">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="1">Available</option>
                            <option value="2">In Use</option>
                            <option value="0">Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" id="location" name="location" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="purchaseDate" class="form-label">Purchase Date</label>
                        <input type="date" id="purchaseDate" name="purchaseDate" class="form-input" value="${new Date().toISOString().slice(0, 10)}">
                    </div>
                    <div class="form-group">
                        <label for="warrantyEndDate" class="form-label">Warranty End Date</label>
                        <input type="date" id="warrantyEndDate" name="warrantyEndDate" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="componentUuid" class="form-label">Component UUID</label>
                        <input type="text" id="componentUuid" name="componentUuid" class="form-input" readonly>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes" name="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
        `;
    }

    setupChassisFormEventListeners() {
        document.getElementById('chassisManufacturers').addEventListener('change', (e) => this.handleChassisCategoryChange(e.target.value));
        // document.getElementById('chassisBrand').addEventListener('change', (e) => this.handleChassisBrandChange(e.target.value));
        document.getElementById('chassisSeries').addEventListener('change', (e) => this.handleChassisSeriesChange(e.target.value));
        document.getElementById('chassisModel').addEventListener('change', (e) => this.handleChassisModelChange(e.target.value));
    }

    populateChassisCategories() {
        const manufacturerSelect = document.getElementById('chassisManufacturers');
        const manufacturers = this.chassisData?.chassis_specifications?.manufacturers || [];
        const categories = [...new Set(manufacturers.map(m => m.manufacturer))];
        categories.forEach(category => {
            const option = new Option(category, category);
            manufacturerSelect.add(option);
        });
    }

    handleChassisCategoryChange(selectedCategory) {
        const brandSelect = document.getElementById('chassisBrand');
        const seriesSelect = document.getElementById('chassisSeries');
        const modelSelect = document.getElementById('chassisModel');

        this.resetSelect(brandSelect, 'Select Brand');
        this.resetSelect(seriesSelect, 'Select Series');
        this.resetSelect(modelSelect, 'Select Model');
        document.getElementById('chassisInfoContainer').style.display = 'none';

        if (selectedCategory) {
            const manufacturers = this.chassisData?.chassis_specifications?.manufacturers || [];
            const selectedManufacturer = manufacturers.find(
                m => m.manufacturer === selectedCategory
            );

            if (selectedManufacturer && selectedManufacturer.series) {
                const seriesList = [...new Set(selectedManufacturer.series.map(s => s.series_name))];
                seriesList.forEach(seriesName => {
                    const option = new Option(seriesName, seriesName);
                    seriesSelect.add(option);
                });
                seriesSelect.disabled = false;
            }
        }
    }

    handleChassisBrandChange(selectedBrand) {
        const category = document.getElementById('chassisManufacturers').value;
        const seriesSelect = document.getElementById('chassisSeries');
        this.resetSelect(seriesSelect, 'Select Series');
        this.resetSelect(document.getElementById('chassisModel'), 'Select Model');
        document.getElementById('chassisInfoContainer').style.display = 'none';

        if (selectedBrand) {
            const series = [...new Set(this.nicData.filter(nic => nic.category === category && nic.brand === selectedBrand).flatMap(nic => nic.series.map(s => s.name)))];
            series.forEach(s => {
                const option = new Option(s, s);
                seriesSelect.add(option);
            });
        }
    }

    handleChassisSeriesChange(selectedSeries) {
        const category = document.getElementById('chassisManufacturers').value;
        const brand = document.getElementById('chassisBrand').value;
        const modelSelect = document.getElementById('chassisModel');
        this.resetSelect(modelSelect, 'Select Model');
        document.getElementById('chassisInfoContainer').style.display = 'none';

        if (selectedSeries) {
            const manufacturers = this.chassisData?.chassis_specifications?.manufacturers || [];
            const selectedManufacturer = manufacturers.find(m => m.manufacturer === category);

            const selectedSeriesObj = selectedManufacturer.series.find(s => s.series_name === selectedSeries);
            if (!selectedSeriesObj || !selectedSeriesObj.models) return;
            this.resetSelect(modelSelect, 'Select Model');
            selectedSeriesObj.models.forEach(modelObj => {
                if (modelObj.model) {
                    const option = new Option(modelObj.model, modelObj.uuid);
                    modelSelect.add(option);
                }
            });
            modelSelect.disabled = false;
        }
    }

    handleChassisFamilyChange(selectedFamily) {
        const category = document.getElementById('chassisManufacturers').value;
        const brand = document.getElementById('chassisBrand').value;
        const series = document.getElementById('chassisSeries').value;  
        const modelSelect = document.getElementById('chassisModel');
        this.resetSelect(modelSelect, 'Select Model');
        document.getElementById('chassisInfoContainer').style.display = 'none';

        if (selectedFamily) {
            const matchingNics = this.nicData.filter(nic => nic.category === category && nic.brand === brand);
            const models = [];
            
            matchingNics.forEach(nic => {
                const matchingSeries = nic.series.find(s => s.name === series);
                if (matchingSeries && matchingSeries.families) {
                    const family = matchingSeries.families.find(f => f.name === selectedFamily);
                    if (family && family.port_configurations) {
                        family.port_configurations.forEach(config => {
                            if (!models.includes(config.model)) {
                                models.push(config.model);
                            }
                        });
                    }
                }
            });
            
            models.forEach(m => modelSelect.add(new Option(m, m)));
            modelSelect.disabled = false;
        }
    }

    handleChassisModelChange(selectedModel) {
        const category = document.getElementById('chassisManufacturers').value;
        const brand = document.getElementById('chassisBrand').value;
        const series = document.getElementById('chassisSeries').value;
        const infoContainer = document.getElementById('chassisInfoContainer');
        const infoDiv = document.getElementById('chassisInfo');
        if (selectedModel) {
            const manufacturers = this.chassisData?.chassis_specifications?.manufacturers || [];
            const selectedManufacturer = manufacturers.find(m => m.manufacturer === category);

            if (!selectedManufacturer) return;

            let seriesDetails = null;
            let modelDetails = null;
            selectedManufacturer.series.forEach(seriesObj => {
                if (seriesObj.models) {

                    const foundModel = seriesObj.models.find(m => m.uuid === selectedModel);
                    if (foundModel) {
                        modelDetails = foundModel;
                        seriesDetails = seriesObj;     
                    }
                }
            });

            if (modelDetails && seriesDetails) {
                infoDiv.innerHTML = `
                    <div class="form-group">
                        <label class="form-label">Hot Swap</label>
                        <p class="form-static-text">${modelDetails.drive_bays.bay_configuration[0].hot_swap || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Chassis Type</label>
                        <p class="form-static-text">${modelDetails.chassis_type || 'N/A'}</p>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Form Factor</label>
                        <p class="form-static-text">${modelDetails.form_factor || 'N/A'}</p>
                    </div>
                `;
                infoContainer.style.display = 'block';
                document.getElementById('componentUuid').value = modelDetails.uuid || '';
            }
        } else {
            infoContainer.style.display = 'none';
        }
    }

    resetSelect(selectElement, defaultOptionText) {
        selectElement.innerHTML = `<option value="">${defaultOptionText}</option>`;
        selectElement.disabled = true;
    }

    initializeGenericForm() {
        this.formContainer.innerHTML = `
            <div class="form-section">
                <h4 class="form-section-title">Inventory Details</h4>
                <div class="form-grid two-column">
                    <div class="form-group">
                        <label for="serialNumber" class="form-label required">Serial Number</label>
                        <input type="text" id="serialNumber" name="serialNumber" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label for="status" class="form-label required">Status</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="1">Available</option>
                            <option value="2">In Use</option>
                            <option value="0">Failed</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" id="location" name="location" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="purchaseDate" class="form-label">Purchase Date</label>
                        <input type="date" id="purchaseDate" name="purchaseDate" class="form-input" value="${new Date().toISOString().slice(0, 10)}">
                    </div>
                    <div class="form-group">
                        <label for="warrantyEndDate" class="form-label">Warranty End Date</label>
                        <input type="date" id="warrantyEndDate" name="warrantyEndDate" class="form-input">
                    </div>
                    <div class="form-group">
                        <label for="componentUuid" class="form-label">Component UUID</label>
                        <input type="text" id="componentUuid" name="componentUuid" class="form-input" readonly>
                    </div>
                    <div class="form-group form-column-span-2">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea id="notes" name="notes" class="form-textarea" rows="3"></textarea>
                    </div>
                </div>
            </div>
        `;
    }

    async handleSubmit(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());

        let apiData;
        let componentDetails;
        let modelName = 'N/A';

        if (this.componentType === 'cpu') {
            componentDetails = this.cpuData?.find(cpu => cpu.brand === data.brand && cpu.series === data.series && cpu.generation === data.generation && cpu.family === data.family)?.models.find(m => m.model === data.model);
        } else if (this.componentType === 'motherboard') {
            componentDetails = this.motherboardData?.find(mb => mb.brand === data.brand && mb.series === data.series && mb.family === data.family)?.models.find(m => m.model === data.model);
        } else if (this.componentType === 'ram') {
            componentDetails = this.ramData?.flatMap(ram => ram.models).find(m => m.uuid === data.model);
        } else if (this.componentType === 'storage') {
            componentDetails = this.storageData?.flatMap(s => s.models).find(m => m.uuid === data.model);
        } else if (this.componentType === 'caddy') {
            componentDetails = this.caddyData?.caddies.find(c => c.model === data.model);
        } else if (this.componentType === 'nic') {
            componentDetails = this.nicData
                ?.filter(nic => nic.category === data.category && nic.brand === data.brand)
                .flatMap(nic => nic.series)
                ?.find(s => s.name === data.series)
                ?.models.find(m => m.model === data.model);
        }

        if (['cpu', 'motherboard', 'ram', 'storage', 'caddy'].includes(this.componentType)) {
            if (!componentDetails) {
                utils.showAlert('Could not find the selected component details. Please try again.', 'error');
                return;
            }
            if (this.componentType === 'ram') {
                modelName = `${componentDetails.memory_type} ${componentDetails.capacity_GB}GB ${componentDetails.speed_MTs}MT/s`;
            } else if (this.componentType === 'storage') {
                modelName = `${componentDetails.brand} ${componentDetails.series} ${componentDetails.subtype} ${componentDetails.capacity_GB}GB`;
            } else if (this.componentType === 'nic') {
                modelName = `${componentDetails.brand} ${componentDetails.series} ${componentDetails.family} ${componentDetails.model}`;
            } else {
                modelName = componentDetails.model;
            }
        } else {
            modelName = this.componentType;
        }

        const notes = data.notes ? `${data.notes} - ${modelName}` : modelName;

        apiData = {
            uuid: data.componentUuid,
            serial_number: data.serialNumber,
            status: data.status,
            server_uuid: null,
            location: data.location,
            rack_position: null,
            purchase_date: data.purchaseDate,
            warranty_end_date: data.warrantyEndDate,
            flag: 'Backup',
            notes: notes
        };

        try {
            const result = await window.api.components.add(this.componentType, apiData);
            if (result.success) {
                utils.showAlert('Component added successfully!', 'success');
                if (window.dashboard && typeof window.dashboard.closeModal === 'function') {
                    window.dashboard.closeModal();
                    window.dashboard.fetchAndDisplayData(this.componentType);
                }
            } else {
                utils.showAlert(result.message || 'Failed to add component.', 'error');
            }
        } catch (error) {
            console.error('Error adding component:', error);
            utils.showAlert('An error occurred while adding the component.', 'error');
        }
    }

    handleCancel() {
        if (window.dashboard && typeof window.dashboard.closeModal === 'function') {
            window.dashboard.closeModal();
        }
    }
}

function initializeAddComponentForm(componentType) {
    new AddFormComponent(componentType);
}