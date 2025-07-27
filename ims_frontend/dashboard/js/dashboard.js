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
        
        // Initialize user info
        await this.initializeUserInfo();
        
        // Set up event listeners
        this.setupEventListeners();
        
        // Load initial dashboard data
        await this.loadDashboard();
        
        // Set up search functionality
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
        // Sidebar menu items
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', (e) => {
                const component = item.dataset.component;
                if (component) {
                    this.switchView(component);
                }
            });
        });

        // Header actions
        document.getElementById('refreshDashboard')?.addEventListener('click', () => {
            this.loadDashboard();
        });

        document.getElementById('addComponentBtn')?.addEventListener('click', () => {
            this.showAddForm();
        });

        document.getElementById('refreshComponents')?.addEventListener('click', () => {
            this.loadComponentList(this.currentComponent);
        });

        // Search and filters
        document.getElementById('componentSearch')?.addEventListener('input', 
            utils.debounce((e) => this.handleSearch(e.target.value), 300)
        );

        document.getElementById('statusFilter')?.addEventListener('change', (e) => {
            this.handleFilterChange('status', e.target.value);
        });

        // Global search
        document.getElementById('globalSearch')?.addEventListener('input',
            utils.debounce((e) => this.handleGlobalSearch(e.target.value), 500)
        );

        // Bulk actions
        document.getElementById('selectAllComponents')?.addEventListener('change', (e) => {
            this.toggleSelectAll(e.target.checked);
        });

        document.getElementById('bulkUpdateStatus')?.addEventListener('click', () => {
            this.showBulkUpdateModal();
        });

        document.getElementById('bulkDelete')?.addEventListener('click', () => {
            this.handleBulkDelete();
        });

        // Modal close
        document.getElementById('modalClose')?.addEventListener('click', () => {
            this.closeModal();
        });

        document.getElementById('modalContainer')?.addEventListener('click', (e) => {
            if (e.target.id === 'modalContainer') {
                this.closeModal();
            }
        });

        // User dropdown
        document.querySelector('.dropdown-btn')?.addEventListener('click', (e) => {
            e.stopPropagation();
            const dropdown = e.target.closest('.dropdown');
            dropdown.classList.toggle('active');
        });

        // Close dropdown when clicking outside
        document.addEventListener('click', () => {
            document.querySelectorAll('.dropdown.active').forEach(dropdown => {
                dropdown.classList.remove('active');
            });
        });

        // Logout
        document.getElementById('logoutBtn')?.addEventListener('click', async (e) => {
            e.preventDefault();
            await this.handleLogout();
        });

        // Change password
        document.getElementById('changePassword')?.addEventListener('click', (e) => {
            e.preventDefault();
            this.showChangePasswordModal();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closeModal();
            }
        });
    }

    setupSearch() {
        // Set up real-time search with debouncing
        const searchInput = document.getElementById('componentSearch');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                const query = e.target.value.trim();
                if (this.searchTimeout) {
                    clearTimeout(this.searchTimeout);
                }
                this.searchTimeout = setTimeout(() => {
                    this.currentPage = 1;
                    this.loadComponentList(this.currentComponent);
                }, 300);
            });
        }
    }

    async switchView(component) {
        // Update active menu item
        document.querySelectorAll('.menu-item').forEach(item => {
            item.classList.remove('active');
        });
        document.querySelector(`[data-component="${component}"]`)?.classList.add('active');

        // Hide all content sections
        document.querySelectorAll('.content-section').forEach(section => {
            section.classList.remove('active');
        });

        this.currentComponent = component;
        this.currentPage = 1;
        this.selectedItems.clear();

        if (component === 'dashboard') {
            // Show dashboard view
            document.getElementById('dashboardView').classList.add('active');
            await this.loadDashboard();
        } else {
            // Show component list view
            document.getElementById('componentView').classList.add('active');
            document.getElementById('componentTitle').textContent = 
                component.charAt(0).toUpperCase() + component.slice(1) + ' Components';
            
            // Update add button text
            const addBtn = document.getElementById('addComponentBtn');
            if (addBtn) {
                addBtn.innerHTML = `<i class="fas fa-plus"></i> Add ${component.toUpperCase()}`;
            }

            await this.loadComponentList(component);
        }

        // Update URL
        utils.updateURLParams({ view: component });
    }

    async loadDashboard() {
        try {
            utils.showLoading(true, 'Loading dashboard...');
            
            const result = await api.dashboard.getData();
            
            if (result.success && result.data.stats) {
                this.updateDashboardStats(result.data.stats);
                this.updateSidebarCounts(result.data.stats);
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

        // Add click handlers to stat cards
        components.forEach(component => {
            const card = document.querySelector(`.${component}-card`);
            if (card) {
                card.style.cursor = 'pointer';
                card.addEventListener('click', () => {
                    this.switchView(component);
                });
            }
        });
    }

    updateSidebarCounts(stats) {
        const components = ['cpu', 'ram', 'storage', 'motherboard', 'nic', 'caddy'];
        
        components.forEach(component => {
            const countElement = document.getElementById(`${component}Count`);
            if (countElement && stats[component]) {
                countElement.textContent = stats[component].total || 0;
            }
        });
    }

    async loadRecentActivity() {
        // For now, show mock data. In a real implementation, this would fetch from an API
        const mockActivity = [
            {
                type: 'added',
                component: 'CPU',
                details: 'Intel Core i9-12900K added to inventory',
                user: 'Admin',
                time: new Date(Date.now() - 30 * 60 * 1000) // 30 minutes ago
            },
            {
                type: 'updated',
                component: 'RAM',
                details: 'DDR4-3200 32GB status changed to In Use',
                user: 'Admin',
                time: new Date(Date.now() - 2 * 60 * 60 * 1000) // 2 hours ago
            },
            {
                type: 'added',
                component: 'Storage',
                details: 'Samsung 980 PRO 1TB added to inventory',
                user: 'Admin',
                time: new Date(Date.now() - 4 * 60 * 60 * 1000) // 4 hours ago
            }
        ];

        const activityContainer = document.getElementById('recentActivity');
        if (activityContainer) {
            activityContainer.innerHTML = mockActivity.map(activity => `
                <div class="activity-item">
                    <div class="activity-icon ${activity.type}">
                        <i class="fas fa-${activity.type === 'added' ? 'plus' : activity.type === 'updated' ? 'edit' : 'trash'}"></i>
                    </div>
                    <div class="activity-content">
                        <div class="title">${activity.details}</div>
                        <div class="details">by ${activity.user} • ${activity.component}</div>
                    </div>
                    <div class="activity-time">${utils.formatRelativeTime(activity.time)}</div>
                </div>
            `).join('');
        }
    }

    async loadComponentList(componentType) {
        if (!componentType || componentType === 'dashboard') return;

        try {
            utils.showLoading(true, `Loading ${componentType} components...`);
            
            // Get search and filter values
            const search = document.getElementById('componentSearch')?.value || '';
            const status = document.getElementById('statusFilter')?.value || '';
            
            const params = {
                limit: this.itemsPerPage,
                offset: (this.currentPage - 1) * this.itemsPerPage
            };

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

    renderComponentTable(components, componentType) {
        const tbody = document.getElementById('componentsTableBody');
        if (!tbody) return;

        if (components.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="7" class="empty-state">
                        <div style="text-align: center; padding: 40px;">
                            <i class="fas fa-box-open" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;"></i>
                            <h3>No Components Found</h3>
                            <p>No ${componentType} components match your search criteria.</p>
                            <button class="btn btn-primary" onclick="dashboard.showAddForm()">
                                <i class="fas fa-plus"></i> Add First ${componentType.toUpperCase()}
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        tbody.innerHTML = components.map(component => {
            const statusInfo = utils.getStatusInfo(component.Status);
            return `
                <tr>
                    <td>
                        <input type="checkbox" class="component-checkbox" 
                               value="${component.ID}" 
                               onchange="dashboard.handleItemSelection(this)">
                    </td>
                    <td>
                        <strong>${utils.escapeHtml(component.SerialNumber)}</strong>
                        ${component.UUID ? `<br><small style="color: var(--text-muted); font-family: monospace;">${component.UUID}</small>` : ''}
                    </td>
                    <td>${utils.createStatusBadge(component.Status)}</td>
                    <td>${component.ServerUUID ? `<code>${utils.truncateText(component.ServerUUID, 20)}</code>` : '-'}</td>
                    <td>${utils.escapeHtml(component.Location || '-')}</td>
                    <td>${utils.formatDate(component.PurchaseDate)}</td>
                    <td>
                        <div class="action-buttons">
                            <button class="action-btn edit" onclick="dashboard.showEditForm('${componentType}', ${component.ID})" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="action-btn delete" onclick="dashboard.handleDeleteComponent('${componentType}', ${component.ID})" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    }

    renderPagination(pagination) {
        const paginationContainer = document.getElementById('pagination');
        const paginationInfo = document.getElementById('paginationInfo');
        
        if (!paginationContainer || !pagination) return;

        // Update pagination info
        const start = pagination.offset + 1;
        const end = Math.min(pagination.offset + pagination.limit, pagination.total);
        paginationInfo.textContent = `Showing ${start}-${end} of ${pagination.total} items`;

        // Calculate page numbers
        const totalPages = Math.ceil(pagination.total / pagination.limit);
        const currentPage = pagination.page;

        let paginationHTML = '';

        // Previous button
        paginationHTML += `
            <button ${currentPage === 1 ? 'disabled' : ''} onclick="dashboard.goToPage(${currentPage - 1})">
                <i class="fas fa-chevron-left"></i> Previous
            </button>
        `;

        // Page numbers
        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);

        if (startPage > 1) {
            paginationHTML += `<button onclick="dashboard.goToPage(1)">1</button>`;
            if (startPage > 2) {
                paginationHTML += `<span>...</span>`;
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            paginationHTML += `
                <button ${i === currentPage ? 'class="active"' : ''} onclick="dashboard.goToPage(${i})">
                    ${i}
                </button>
            `;
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                paginationHTML += `<span>...</span>`;
            }
            paginationHTML += `<button onclick="dashboard.goToPage(${totalPages})">${totalPages}</button>`;
        }

        // Next button
        paginationHTML += `
            <button ${currentPage === totalPages ? 'disabled' : ''} onclick="dashboard.goToPage(${currentPage + 1})">
                Next <i class="fas fa-chevron-right"></i>
            </button>
        `;

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
            const result = await api.search.global(query, {
                limit: 10
            });

            if (result.success && result.data.results.length > 0) {
                // You could show a dropdown with results here
                console.log('Global search results:', result.data.results);
            }

        } catch (error) {
            console.error('Global search error:', error);
        }
    }

    handleItemSelection(checkbox) {
        const id = parseInt(checkbox.value);
        
        if (checkbox.checked) {
            this.selectedItems.add(id);
        } else {
            this.selectedItems.delete(id);
        }

        this.updateBulkActions();
        this.updateSelectAllCheckbox();
    }

    toggleSelectAll(checked) {
        const checkboxes = document.querySelectorAll('.component-checkbox');
        checkboxes.forEach(checkbox => {
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
        if (this.currentComponent === 'dashboard') {
            utils.showAlert('Please select a component type first', 'warning');
            return;
        }

        // Navigate to the add form page with component type parameter
        window.location.href = `forms/add-component.html?type=${this.currentComponent}`;
    }

    async showEditForm(componentType, componentId) {
        // Navigate to the edit form page with parameters
        window.location.href = `forms/edit-component.html?type=${componentType}&id=${componentId}`;
    }

    async handleDeleteComponent(componentType, componentId) {
        const confirmed = await utils.confirm(
            'Are you sure you want to delete this component? This action cannot be undone.',
            'Delete Component'
        );

        if (confirmed) {
            try {
                utils.showLoading(true, 'Deleting component...');
                
                const result = await api.components.delete(componentType, componentId);
                
                if (result.success) {
                    utils.showAlert('Component deleted successfully', 'success');
                    await this.loadComponentList(componentType);
                    await this.loadDashboard(); // Refresh dashboard stats
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
                <div class="form-group">
                    <label class="form-label">Update Status</label>
                    <select id="bulkStatus" class="form-select">
                        <option value="">Keep Current Status</option>
                        <option value="1">Available</option>
                        <option value="2">In Use</option>
                        <option value="0">Failed</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Update Location</label>
                    <input type="text" id="bulkLocation" class="form-input" placeholder="Leave empty to keep current">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Update Flag</label>
                    <select id="bulkFlag" class="form-select">
                        <option value="">Keep Current Flag</option>
                        <option value="Backup">Backup</option>
                        <option value="Critical">Critical</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Testing">Testing</option>
                        <option value="Production">Production</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                    <button class="btn btn-secondary" onclick="dashboard.closeModal()">Cancel</button>
                    <button class="btn btn-primary" onclick="dashboard.executeBulkUpdate()">
                        Update ${this.selectedItems.size} Items
                    </button>
                </div>
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
            
            const result = await api.components.bulkUpdate(
                this.currentComponent, 
                Array.from(this.selectedItems), 
                updates
            );
            
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

        const confirmed = await utils.confirm(
            `Are you sure you want to delete ${this.selectedItems.size} selected components? This action cannot be undone.`,
            'Delete Components'
        );

        if (confirmed) {
            try {
                utils.showLoading(true, 'Deleting components...');
                
                let deleted = 0;
                let failed = 0;

                // Delete each selected component
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
            
            // Focus management for accessibility
            const firstInput = modalBody.querySelector('input, select, textarea, button');
            if (firstInput) {
                setTimeout(() => firstInput.focus(), 100);
            }
        }

        // Set global reference for forms to use
        window.closeModal = () => this.closeModal();
        window.loadComponentList = (type) => this.loadComponentList(type);
        window.loadDashboard = () => this.loadDashboard();
    }

    closeModal() {
        const modal = document.getElementById('modalContainer');
        if (modal) {
            modal.style.display = 'none';
        }

        // Clear global references
        delete window.closeModal;
        delete window.loadComponentList;
        delete window.loadDashboard;
    }

    async showChangePasswordModal() {
        const modalContent = `
            <form id="changePasswordForm" style="max-width: 400px;">
                <div class="form-group">
                    <label class="form-label required">Current Password</label>
                    <input type="password" id="currentPassword" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">New Password</label>
                    <input type="password" id="newPassword" class="form-input" required minlength="8">
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Confirm New Password</label>
                    <input type="password" id="confirmPassword" class="form-input" required>
                </div>
                
                <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
                    <button type="button" class="btn btn-secondary" onclick="dashboard.closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </div>
            </form>
        `;

        this.showModal('Change Password', modalContent);

        // Add form submission handler
        document.getElementById('changePasswordForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            await this.handleChangePassword();
        });
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
        const confirmed = await utils.confirm(
            'Are you sure you want to logout?',
            'Logout'
        );

        if (confirmed) {
            try {
                utils.showLoading(true, 'Logging out...');
                
                await api.auth.logout();
                
                // Redirect to login page
                window.location.href = '/';
                
            } catch (error) {
                console.error('Logout error:', error);
                // Even if logout fails, clear local data and redirect
                api.clearAuth();
                window.location.href = '/ims_frontend/';
            }
        }
    }

    // Utility method to refresh current view
    async refresh() {
        if (this.currentComponent === 'dashboard') {
            await this.loadDashboard();
        } else {
            await this.loadComponentList(this.currentComponent);
        }
    }

    // Method to handle URL parameters on page load
    handleInitialView() {
        const urlParams = utils.getURLParams();
        const view = urlParams.view || 'dashboard';
        
        if (view !== 'dashboard') {
            this.switchView(view);
        }
    }
}

// Initialize dashboard when DOM is loaded
let dashboard;

document.addEventListener('DOMContentLoaded', async () => {
    try {
        dashboard = new Dashboard();
        
        // Handle initial view from URL
        dashboard.handleInitialView();
        
        // Make dashboard globally available for form callbacks
        window.dashboard = dashboard;
        
    } catch (error) {
        console.error('Failed to initialize dashboard:', error);
        utils.showAlert('Failed to initialize dashboard. Please refresh the page.', 'error');
    }
});

// Handle browser back/forward buttons
window.addEventListener('popstate', () => {
    if (dashboard) {
        dashboard.handleInitialView();
    }
});

// Handle visibility change to refresh data when tab becomes visible
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && dashboard) {
        // Refresh dashboard data when tab becomes visible
        setTimeout(() => {
            dashboard.refresh();
        }, 1000);
    }
});

// Auto-refresh dashboard every 5 minutes
setInterval(() => {
    if (dashboard && dashboard.currentComponent === 'dashboard' && !document.hidden) {
        dashboard.loadDashboard();
    }
}, 5 * 60 * 1000);

// Export dashboard for global access
if (typeof module !== 'undefined' && module.exports) {
    module.exports = Dashboard;
}