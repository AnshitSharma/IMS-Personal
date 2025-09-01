/**
 * Dashboard JavaScript for BDC Inventory Management System
 */

class Dashboard {
    constructor() {
        this.currentComponent = 'dashboard';
        this.currentPage = 1;
        this.itemsPerPage = 50;
        this.searchTimeout = null;
        this.selectedItems = new Set();
        
        this.init();
    }

    async init() {
        console.log('Initializing Dashboard...');
        
        await this.initializeUserInfo();
        this.setupEventListeners();
        await this.loadDashboard();
        this.setupSearch();
        
        console.log('Dashboard initialized successfully');
    }

    async initializeUserInfo() {
        const user = api.getUser();
        if (user) {
            document.getElementById('userDisplayName').textContent = 
                `${user.firstname} ${user.lastname}` || user.username;
            document.getElementById('userRole').textContent = 
                user.primary_role ? user.primary_role.replace('_', ' ').toUpperCase() : 'USER';
        }
    }

    setupEventListeners() {
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', (e) => {
                const component = item.dataset.component;
                if (component) {
                    this.switchView(component);
                }
            });
        });

        document.getElementById('refreshDashboard')?.addEventListener('click', () => this.loadDashboard());
        document.getElementById('addComponentBtn')?.addEventListener('click', () => this.showAddForm());
        document.getElementById('refreshComponents')?.addEventListener('click', () => this.loadComponentList(this.currentComponent));
        document.getElementById('componentSearch')?.addEventListener('input', utils.debounce((e) => this.handleSearch(e.target.value), 300));
        document.getElementById('statusFilter')?.addEventListener('change', (e) => this.handleFilterChange('status', e.target.value));
        document.getElementById('globalSearch')?.addEventListener('input', utils.debounce((e) => this.handleGlobalSearch(e.target.value), 500));
        document.getElementById('selectAllComponents')?.addEventListener('change', (e) => this.toggleSelectAll(e.target.checked));
        document.getElementById('bulkUpdateStatus')?.addEventListener('click', () => this.showBulkUpdateModal());
        document.getElementById('bulkDelete')?.addEventListener('click', () => this.handleBulkDelete());
        document.getElementById('modalClose')?.addEventListener('click', () => this.closeModal());
        document.getElementById('modalContainer')?.addEventListener('click', (e) => { if (e.target.id === 'modalContainer') this.closeModal(); });
        document.querySelector('.dropdown-btn')?.addEventListener('click', (e) => { e.stopPropagation(); e.target.closest('.dropdown').classList.toggle('active'); });
        document.addEventListener('click', () => { document.querySelectorAll('.dropdown.active').forEach(dropdown => dropdown.classList.remove('active')); });
        document.getElementById('logoutBtn')?.addEventListener('click', async (e) => { e.preventDefault(); await this.handleLogout(); });
        document.getElementById('changePassword')?.addEventListener('click', (e) => { e.preventDefault(); this.showChangePasswordModal(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') this.closeModal(); });
    }

    setupSearch() {
        const searchInput = document.getElementById('componentSearch');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const query = e.target.value.trim();
                if (this.searchTimeout) clearTimeout(this.searchTimeout);
                this.searchTimeout = setTimeout(() => {
                    this.currentPage = 1;
                    this.loadComponentList(this.currentComponent);
                }, 300);
            });
        }
    }

    async switchView(component) {
        document.querySelectorAll('.menu-item').forEach(item => item.classList.remove('active'));
        document.querySelector(`[data-component="${component}"]`)?.classList.add('active');
        document.querySelectorAll('.content-section').forEach(section => section.classList.remove('active'));

        this.currentComponent = component;
        this.currentPage = 1;
        this.selectedItems.clear();

        if (component === 'dashboard') {
            document.getElementById('dashboardView').classList.add('active');
            await this.loadDashboard();
        } else if (component === 'servers') {
            document.getElementById('componentView').classList.add('active');
            document.getElementById('componentTitle').textContent = 'Servers';
            const addBtn = document.getElementById('addComponentBtn');
            if (addBtn) addBtn.innerHTML = `<i class="fas fa-plus"></i> Add Server`;
            this.renderServerHeader();
            await this.loadServerList();
        } else {
            document.getElementById('componentView').classList.add('active');
            document.getElementById('componentTitle').textContent = component.charAt(0).toUpperCase() + component.slice(1) + ' Components';
            const addBtn = document.getElementById('addComponentBtn');
            if (addBtn) addBtn.innerHTML = `<i class="fas fa-plus"></i> Add ${component.toUpperCase()}`;
            this.renderComponentHeader();
            await this.loadComponentList(component);
        }
        utils.updateURLParams({ view: component });
    }

    renderServerHeader() {
        const thead = document.getElementById('componentsTableHeader');
        if (!thead) return;
        thead.innerHTML = `
            <tr>
                <th><input type="checkbox" id="selectAllComponents"></th>
                <th>Server</th><th>CPU</th><th>Motherboard</th><th>RAM</th><th>NIC</th><th>Location</th><th>Status</th><th>Created By</th><th>Actions</th>
            </tr>
        `;
    }

    renderComponentHeader() {
        const thead = document.getElementById('componentsTableHeader');
        if (!thead) return;
        thead.innerHTML = `
            <tr>
                <th><input type="checkbox" id="selectAllComponents"></th>
                <th>Serial Number</th><th>Status</th><th>Server UUID</th><th>Location</th><th>Purchase Date</th><th>Actions</th>
            </tr>
        `;
    }

    async loadDashboard() {
        try {
            utils.showLoading(true, 'Loading dashboard...');
            const result = await api.dashboard.getData();
            if (result.success && result.data.component_counts) {
                this.updateDashboardStats(result.data.component_counts);
                this.updateSidebarCounts(result.data.component_counts);
                await this.loadRecentActivity();
            }
        } catch (error) {
            console.error('Error loading dashboard:', error);
            utils.showAlert('Failed to load dashboard data', 'error');
        } finally {
            utils.showLoading(false);
        }
    }

    updateDashboardStats(stats) {
        const components = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
        components.forEach(component => {
            if (stats[component]) {
                const stat = stats[component];
                document.getElementById(`dash${component.charAt(0).toUpperCase() + component.slice(1)}Total`).textContent = stat.total || 0;
                document.getElementById(`dash${component.charAt(0).toUpperCase() + component.slice(1)}Available`).textContent = stat.available || 0;
                document.getElementById(`dash${component.charAt(0).toUpperCase() + component.slice(1)}InUse`).textContent = stat.in_use || 0;
                document.getElementById(`dash${component.charAt(0).toUpperCase() + component.slice(1)}Failed`).textContent = stat.failed || 0;
            }
        });
        if (stats.servers) {
            const serverStat = stats.servers;
            document.getElementById('dashServersTotal').textContent = serverStat.total || 0;
            document.getElementById('dashServersDraft').textContent = serverStat.draft || 0;
            document.getElementById('dashServersValidated').textContent = serverStat.validated || 0;
            document.getElementById('dashServersBuilt').textContent = serverStat.built || 0;
            document.getElementById('dashServersFinalized').textContent = serverStat.finalized || 0;
        }
        const allComponents = [...components, 'servers'];
        allComponents.forEach(component => {
            const card = document.querySelector(`.${component}-card`);
            if (card) {
                card.style.cursor = 'pointer';
                card.addEventListener('click', () => this.switchView(component));
            }
        });
    }

    updateSidebarCounts(stats) {
        const components = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy', 'servers'];
        components.forEach(component => {
            const countElement = document.getElementById(`${component}Count`);
            if (countElement && stats[component]) {
                countElement.textContent = stats[component].total || 0;
            }
        });
    }

    async loadRecentActivity() {
        const mockActivity = [
            { type: 'added', component: 'CPU', details: 'Intel Core i9-12900K added', user: 'Admin', time: new Date(Date.now() - 30 * 60 * 1000) },
            { type: 'updated', component: 'RAM', details: 'DDR4-3200 32GB status changed', user: 'Admin', time: new Date(Date.now() - 2 * 60 * 60 * 1000) },
            { type: 'added', component: 'Storage', details: 'Samsung 980 PRO 1TB added', user: 'Admin', time: new Date(Date.now() - 4 * 60 * 60 * 1000) }
        ];
        const activityContainer = document.getElementById('recentActivity');
        if (activityContainer) {
            activityContainer.innerHTML = mockActivity.map(activity => `
                <div class="activity-item">
                    <div class="activity-icon ${activity.type}"><i class="fas fa-${activity.type === 'added' ? 'plus' : 'edit'}"></i></div>
                    <div class="activity-content"><div class="title">${activity.details}</div><div class="details">by ${activity.user}</div></div>
                    <div class="activity-time">${utils.formatRelativeTime(activity.time)}</div>
                </div>
            `).join('');
        }
    }

    async loadComponentList(componentType) {
        if (!componentType || componentType === 'dashboard') return;
        try {
            utils.showLoading(true, `Loading ${componentType} components...`);
            const search = document.getElementById('componentSearch')?.value || '';
            const status = document.getElementById('statusFilter')?.value || '';
            const params = { limit: this.itemsPerPage, offset: (this.currentPage - 1) * this.itemsPerPage };
            if (search) params.search = search;
            if (status) params.status = status;
            const result = await api.components.list(componentType, params);
            if (result.success) {
                this.renderComponentTable(result.data.components, componentType);
                this.renderPagination(result.data.pagination);
                this.updateBulkActions();
            }
        } catch (error) {
            console.error(`Error loading ${componentType} components:`, error);
            utils.showAlert(`Failed to load ${componentType} components`, 'error');
        } finally {
            utils.showLoading(false);
        }
    }

    async loadServerList() {
        try {
            utils.showLoading(true, `Loading servers...`);
            const params = { limit: 20, offset: 0, status: 1 };
            const result = await api.request('server-list-configs', params);
            if (result.success) {
                this.renderServerList(result.data.configurations);
                if (result.data.pagination) this.renderPagination(result.data.pagination);
            }
        } catch (error) {
            console.error(`Error loading servers:`, error);
            utils.showAlert(`Failed to load servers`, 'error');
        } finally {
            utils.showLoading(false);
        }
    }

    renderComponentTable(components, componentType) {
        const tbody = document.getElementById('componentsTableBody');
        if (!tbody) return;
        if (components.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="empty-state"><div style="text-align: center; padding: 40px;"><i class="fas fa-box-open" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;"></i><h3>No Components Found</h3><p>No ${componentType} components match your search criteria.</p><button class="btn btn-primary" onclick="dashboard.showAddForm()"><i class="fas fa-plus"></i> Add First ${componentType.toUpperCase()}</button></div></td></tr>`;
            return;
        }
        tbody.innerHTML = components.map(component => `
            <tr>
                <td><input type="checkbox" class="component-checkbox" value="${component.ID}" onchange="dashboard.handleItemSelection(this)"></td>
                <td><strong>${utils.escapeHtml(component.SerialNumber)}</strong>${component.UUID ? `<br><small style="color: var(--text-muted); font-family: monospace;">${component.UUID}</small>` : ''}</td>
                <td>${utils.createStatusBadge(component.Status)}</td>
                <td>${component.ServerUUID ? `<code>${utils.truncateText(component.ServerUUID, 20)}</code>` : '-'}</td>
                <td>${utils.escapeHtml(component.Location || '-')}</td>
                <td>${utils.formatDate(component.PurchaseDate)}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-btn edit" onclick="dashboard.showEditForm('${componentType}', ${component.ID})" title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="action-btn delete" onclick="dashboard.handleDeleteComponent('${componentType}', ${component.ID})" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    renderServerList(servers) {
        const tbody = document.getElementById('componentsTableBody');
        if (!tbody) return;
        if (servers.length === 0) {
            tbody.innerHTML = `<tr><td colspan="10" class="empty-state"><div style="text-align: center; padding: 40px;"><i class="fas fa-server" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;"></i><h3>No Servers Found</h3><p>No servers match your search criteria.</p></div></td></tr>`;
            return;
        }
        tbody.innerHTML = servers.map(server => `
            <tr>
                <td><input type="checkbox" class="component-checkbox" value="${server.id}" onchange="dashboard.handleItemSelection(this)"></td>
                <td><strong>${utils.escapeHtml(server.server_name || 'Unnamed Server')}</strong>${server.config_uuid ? `<br><small style="color: var(--text-muted); font-family: monospace;">${server.config_uuid}</small>` : ''}</td>
                <td>${utils.escapeHtml(server.cpu_uuid || '-')}</td>
                <td>${utils.escapeHtml(server.motherboard_uuid || '-')}</td>
                <td>${utils.escapeHtml(server.ram_configuration || '-')}</td>
                <td>${utils.escapeHtml(server.nic_configuration || '-')}</td>
                <td>${utils.escapeHtml(server.location || '-')}</td>
                <td>${utils.createStatusBadge(server.configuration_status_text)}</td>
                <td>${utils.escapeHtml(server.created_by_username || '-')}</td>
                <td>
                    <div class="action-buttons">
                        <button class="action-btn edit" onclick="dashboard.showEditForm('servers', ${server.id})" title="Edit"><i class="fas fa-edit"></i></button>
                        <button class="action-btn delete" onclick="dashboard.handleDeleteComponent('servers', ${server.id})" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    renderPagination(pagination) {
        const paginationContainer = document.getElementById('pagination');
        const paginationInfo = document.getElementById('paginationInfo');
        if (!paginationContainer || !pagination) return;
        const start = pagination.offset + 1;
        const end = Math.min(pagination.offset + pagination.limit, pagination.total);
        paginationInfo.textContent = `Showing ${start}-${end} of ${pagination.total} items`;
        const totalPages = Math.ceil(pagination.total / pagination.limit);
        const currentPage = pagination.page;
        let paginationHTML = '';
        paginationHTML += `<button ${currentPage === 1 ? 'disabled' : ''} onclick="dashboard.goToPage(${currentPage - 1})"><i class="fas fa-chevron-left"></i> Previous</button>`;
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        if (startPage > 1) {
            paginationHTML += `<button onclick="dashboard.goToPage(1)">1</button>`;
            if (startPage > 2) paginationHTML += `<span>...</span>`;
        }
        for (let i = startPage; i <= endPage; i++) {
            paginationHTML += `<button ${i === currentPage ? 'class="active"' : ''} onclick="dashboard.goToPage(${i})">${i}</button>`;
        }
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) paginationHTML += `<span>...</span>`;
            paginationHTML += `<button onclick="dashboard.goToPage(${totalPages})">${totalPages}</button>`;
        }
        paginationHTML += `<button ${currentPage === totalPages ? 'disabled' : ''} onclick="dashboard.goToPage(${currentPage + 1})">Next <i class="fas fa-chevron-right"></i></button>`;
        paginationContainer.innerHTML = paginationHTML;
    }

    goToPage(page) {
        this.currentPage = page;
        this.loadComponentList(this.currentComponent);
    }

    handleSearch(query) {
        this.currentPage = 1;
        this.loadComponentList(this.currentComponent);
    }

    handleFilterChange(filterType, value) {
        this.currentPage = 1;
        this.loadComponentList(this.currentComponent);
    }

    async handleGlobalSearch(query) {
        if (query.trim().length < 2) return;
        try {
            const result = await api.search.global(query, { limit: 10 });
            if (result.success && result.data.results.length > 0) {
                console.log('Global search results:', result.data.results);
            }
        } catch (error) {
            console.error('Global search error:', error);
        }
    }

    handleItemSelection(checkbox) {
        const id = parseInt(checkbox.value);
        if (checkbox.checked) this.selectedItems.add(id); else this.selectedItems.delete(id);
        this.updateBulkActions();
        this.updateSelectAllCheckbox();
    }

    toggleSelectAll(checked) {
        document.querySelectorAll('.component-checkbox').forEach(checkbox => {
            checkbox.checked = checked;
            this.handleItemSelection(checkbox);
        });
    }

    updateSelectAllCheckbox() {
        const selectAllCheckbox = document.getElementById('selectAllComponents');
        const checkboxes = document.querySelectorAll('.component-checkbox');
        if (selectAllCheckbox && checkboxes.length > 0) {
            const checkedCount = document.querySelectorAll('.component-checkbox:checked').length;
            selectAllCheckbox.checked = checkedCount === checkboxes.length;
            selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        }
    }

    updateBulkActions() {
        const bulkActions = document.getElementById('bulkActions');
        const selectedCount = document.getElementById('selectedCount');
        if (bulkActions && selectedCount) {
            if (this.selectedItems.size > 0) {
                selectedCount.textContent = this.selectedItems.size;
                bulkActions.style.display = 'flex';
            } else {
                bulkActions.style.display = 'none';
            }
        }
    }

    async showAddForm() {
        if (this.currentComponent === 'dashboard' || this.currentComponent === 'servers') {
            utils.showAlert('Please select a specific component type (CPU, RAM, etc.) to add.', 'warning');
            return;
        }
        try {
            utils.showLoading(true, 'Loading form...');
            const response = await fetch('../forms/add-component.html');
            if (!response.ok) throw new Error('Could not load form HTML.');
            const formHtml = await response.text();
            this.showModal(`Add New ${this.currentComponent.toUpperCase()}`, formHtml);
            const scriptSrc = '../forms/js/add-form.js';
            if (!document.querySelector(`script[src="${scriptSrc}"]`)) {
                const script = document.createElement('script');
                script.src = scriptSrc;
                script.onload = () => { if (typeof initializeAddComponentForm === 'function') initializeAddComponentForm(this.currentComponent); };
                document.body.appendChild(script);
            } else {
                if (typeof initializeAddComponentForm === 'function') initializeAddComponentForm(this.currentComponent);
            }
        } catch (error) {
            console.error('Error loading add form:', error);
            utils.showAlert('Failed to load the add component form.', 'error');
        } finally {
            utils.showLoading(false);
        }
    }

    async showEditForm(componentType, componentId) {
        try {
            utils.showLoading(true, 'Loading form...');
            const response = await fetch('../forms/edit-component.html');
            if (!response.ok) throw new Error('Could not load form HTML.');
            const formHtml = await response.text();
            this.showModal(`Edit ${componentType.toUpperCase()}`, formHtml);
            const scriptSrc = '../forms/js/edit-form.js';
            if (!document.querySelector(`script[src="${scriptSrc}"]`)) {
                const script = document.createElement('script');
                script.src = scriptSrc;
                script.onload = () => { if (typeof initializeEditFormComponent === 'function') initializeEditFormComponent(componentType, componentId); };
                document.body.appendChild(script);
            } else {
                if (typeof initializeEditFormComponent === 'function') initializeEditFormComponent(componentType, componentId);
            }
        } catch (error) {
            console.error('Error loading edit form:', error);
            utils.showAlert('Failed to load the edit component form.', 'error');
        } finally {
            utils.showLoading(false);
        }
    }

    async handleDeleteComponent(componentType, componentId) {
        const confirmed = await utils.confirm('Are you sure you want to delete this component? This action cannot be undone.', 'Delete Component');
        if (confirmed) {
            try {
                utils.showLoading(true, 'Deleting component...');
                const result = await api.components.delete(componentType, componentId);
                if (result.success) {
                    utils.showAlert('Component deleted successfully', 'success');
                    await this.loadComponentList(componentType);
                    await this.loadDashboard();
                }
            } catch (error) {
                console.error('Error deleting component:', error);
                utils.showAlert('Failed to delete component', 'error');
            } finally {
                utils.showLoading(false);
            }
        }
    }

    async showBulkUpdateModal() {
        if (this.selectedItems.size === 0) {
            utils.showAlert('Please select items to update', 'warning');
            return;
        }
        const modalContent = `
            <div style="max-width: 400px;">
                <div class="form-group"><label class="form-label">Update Status</label><select id="bulkStatus" class="form-select"><option value="">Keep Current</option><option value="1">Available</option><option value="2">In Use</option><option value="0">Failed</option></select></div>
                <div class="form-group"><label class="form-label">Update Location</label><input type="text" id="bulkLocation" class="form-input" placeholder="Leave empty to keep current"></div>
                <div class="form-group"><label class="form-label">Update Flag</label><select id="bulkFlag" class="form-select"><option value="">Keep Current</option><option value="Backup">Backup</option><option value="Critical">Critical</option><option value="Maintenance">Maintenance</option><option value="Testing">Testing</option><option value="Production">Production</option></select></div>
                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;"><button class="btn btn-secondary" onclick="dashboard.closeModal()">Cancel</button><button class="btn btn-primary" onclick="dashboard.executeBulkUpdate()">Update ${this.selectedItems.size} Items</button></div>
            </div>
        `;
        this.showModal('Bulk Update Components', modalContent);
    }

    async executeBulkUpdate() {
        const status = document.getElementById('bulkStatus')?.value;
        const location = document.getElementById('bulkLocation')?.value;
        const flag = document.getElementById('bulkFlag')?.value;
        const updates = {};
        if (status) updates.Status = status;
        if (location) updates.Location = location;
        if (flag) updates.Flag = flag;
        if (Object.keys(updates).length === 0) {
            utils.showAlert('Please select at least one field to update', 'warning');
            return;
        }
        try {
            utils.showLoading(true, 'Updating components...');
            const result = await api.components.bulkUpdate(this.currentComponent, Array.from(this.selectedItems), updates);
            if (result.success) {
                utils.showAlert(`Successfully updated ${result.data.updated} components`, 'success');
                this.selectedItems.clear();
                this.closeModal();
                await this.loadComponentList(this.currentComponent);
                await this.loadDashboard();
            }
        } catch (error) {
            console.error('Error bulk updating components:', error);
            utils.showAlert('Failed to update components', 'error');
        } finally {
            utils.showLoading(false);
        }
    }

    async handleBulkDelete() {
        if (this.selectedItems.size === 0) {
            utils.showAlert('Please select items to delete', 'warning');
            return;
        }
        const confirmed = await utils.confirm(`Are you sure you want to delete ${this.selectedItems.size} selected components? This cannot be undone.`, 'Delete Components');
        if (confirmed) {
            try {
                utils.showLoading(true, 'Deleting components...');
                let deleted = 0, failed = 0;
                for (const id of this.selectedItems) {
                    try {
                        await api.components.delete(this.currentComponent, id);
                        deleted++;
                    } catch (error) {
                        failed++;
                        console.error(`Failed to delete component ${id}:`, error);
                    }
                }
                if (deleted > 0) {
                    utils.showAlert(`Successfully deleted ${deleted} components${failed > 0 ? `, ${failed} failed` : ''}`, deleted === this.selectedItems.size ? 'success' : 'warning');
                } else {
                    utils.showAlert('Failed to delete any components', 'error');
                }
                this.selectedItems.clear();
                await this.loadComponentList(this.currentComponent);
                await this.loadDashboard();
            } catch (error) {
                console.error('Error bulk deleting components:', error);
                utils.showAlert('Failed to delete components', 'error');
            } finally {
                utils.showLoading(false);
            }
        }
    }

    showModal(title, content) {
        const modal = document.getElementById('modalContainer');
        const modalTitle = document.getElementById('modalTitle');
        const modalBody = document.getElementById('modalBody');
        if (modal && modalTitle && modalBody) {
            modalTitle.textContent = title;
            modalBody.innerHTML = content;
            modal.style.display = 'flex';
            const firstInput = modalBody.querySelector('input, select, textarea, button');
            if (firstInput) setTimeout(() => firstInput.focus(), 100);
        }
        window.closeModal = () => this.closeModal();
        window.loadComponentList = (type) => this.loadComponentList(type);
        window.loadDashboard = () => this.loadDashboard();
    }

    closeModal() {
        const modal = document.getElementById('modalContainer');
        if (modal) modal.style.display = 'none';
        delete window.closeModal;
        delete window.loadComponentList;
        delete window.loadDashboard;
    }

    async showChangePasswordModal() {
        const modalContent = `
            <form id="changePasswordForm" style="max-width: 400px;">
                <div class="form-group"><label class="form-label required">Current Password</label><input type="password" id="currentPassword" class="form-input" required></div>
                <div class="form-group"><label class="form-label required">New Password</label><input type="password" id="newPassword" class="form-input" required minlength="8"></div>
                <div class="form-group"><label class="form-label required">Confirm New Password</label><input type="password" id="confirmPassword" class="form-input" required></div>
                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;"><button type="button" class="btn btn-secondary" onclick="dashboard.closeModal()">Cancel</button><button type="submit" class="btn btn-primary">Change Password</button></div>
            </form>
        `;
        this.showModal('Change Password', modalContent);
        document.getElementById('changePasswordForm').addEventListener('submit', async (e) => { e.preventDefault(); await this.handleChangePassword(); });
    }

    async handleChangePassword() {
        const currentPassword = document.getElementById('currentPassword').value;
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        if (newPassword !== confirmPassword) {
            utils.showAlert('New passwords do not match', 'error');
            return;
        }
        if (newPassword.length < 8) {
            utils.showAlert('New password must be at least 8 characters long', 'error');
            return;
        }
        try {
            utils.showLoading(true, 'Changing password...');
            const result = await api.auth.changePassword(currentPassword, newPassword, confirmPassword);
            if (result.success) {
                utils.showAlert('Password changed successfully', 'success');
                this.closeModal();
            }
        } catch (error) {
            console.error('Error changing password:', error);
            utils.showAlert('Failed to change password', 'error');
        } finally {
            utils.showLoading(false);
        }
    }

    async handleLogout() {
        const confirmed = await utils.confirm('Are you sure you want to logout?', 'Logout');
        if (confirmed) {
            try {
                utils.showLoading(true, 'Logging out...');
                await api.auth.logout();
                window.location.href = '/bdc_ims/ims_frontend/';
            } catch (error) {
                console.error('Logout error:', error);
                api.clearAuth();
                window.location.href = '/bdc_ims/ims_frontend/';
            }
        }
    }

    async refresh() {
        if (this.currentComponent === 'dashboard') await this.loadDashboard();
        else if (this.currentComponent === 'servers') await this.loadServerList();
        else await this.loadComponentList(this.currentComponent);
    }

    handleInitialView() {
        const urlParams = utils.getURLParams();
        const view = urlParams.view || 'dashboard';
        if (view !== 'dashboard') this.switchView(view);
    }
}

let dashboard;
document.addEventListener('DOMContentLoaded', async () => {
    try {
        dashboard = new Dashboard();
        dashboard.handleInitialView();
        window.dashboard = dashboard;
    } catch (error) {
        console.error('Failed to initialize dashboard:', error);
        utils.showAlert('Failed to initialize dashboard. Please refresh the page.', 'error');
    }
});

window.addEventListener('popstate', () => { if (dashboard) dashboard.handleInitialView(); });
document.addEventListener('visibilitychange', () => { if (!document.hidden && dashboard) setTimeout(() => dashboard.refresh(), 1000); });
setInterval(() => { if (dashboard && dashboard.currentComponent === 'dashboard' && !document.hidden) dashboard.loadDashboard(); }, 5 * 60 * 1000);

if (typeof module !== 'undefined' && module.exports) module.exports = Dashboard;
