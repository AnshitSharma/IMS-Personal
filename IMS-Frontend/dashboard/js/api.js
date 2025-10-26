/**
 * API Handler for BDC Inventory Management System
 */


window.api = {
    // Base configuration - Hardcoded for staging
        baseURL: 'https://shubham.staging.cloudmate.in/bdc_ims/api/api.php', 
    
    // Get auth token from localStorage
    getToken() {
        return localStorage.getItem('bdc_token');
    },
    // Set auth token
    setToken(token) {
        if (token) {
            localStorage.setItem('bdc_token', token);
        } else {
            localStorage.removeItem('bdc_token');
        }
    },
    // Get refresh token
    getRefreshToken() {
        return localStorage.getItem('bdc_refresh_token');
    },

    // Set refresh token
    setRefreshToken(token) {
        if (token) {
            localStorage.setItem('bdc_refresh_token', token);
        } else {
            localStorage.removeItem('bdc_refresh_token');
        }
    },

    // Get user data
    getUser() {
        const userData = localStorage.getItem('bdc_user');
        return userData ? JSON.parse(userData) : null;
    },

    // Set user data
    setUser(user) {
        if (user) {
            localStorage.setItem('bdc_user', JSON.stringify(user));
        } else {
            localStorage.removeItem('bdc_user');
        }
    },

    // Clear all auth data
    clearAuth() {
        localStorage.removeItem('bdc_token');
        localStorage.removeItem('bdc_refresh_token');
        localStorage.removeItem('bdc_user');
    },

    // Make API request with automatic token refresh
    async request(action, data = {}, method = 'POST') {
        const token = this.getToken();
        // Always use FormData for consistency with API expectations
        const formData = new FormData();
        formData.append('action', action);
        
        // Append data to FormData
        Object.keys(data).forEach(key => {
            if (Array.isArray(data[key])) {
                data[key].forEach(item => {
                    formData.append(`${key}[]`, item);
                });
            } else if (data[key] !== undefined) {
                formData.append(key, data[key] === null ? 'null' : data[key]);
            }
        });

        // Prepare headers - only add Authorization if token exists
        const headers = {};
        if (token) {
            headers['Authorization'] = `Bearer ${token}`;
        }

        try {
            const response = await fetch(this.baseURL, {
                method: 'POST', // API always expects POST
                headers: headers,
                body: formData
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();

            // Handle token expiration
            if (result.code === 401 && result.message && 
                (result.message.includes('expired') || result.message.includes('Invalid token'))) {
                const refreshed = await this.refreshToken();
                if (refreshed) {
                    // Retry the original request with new token
                    const newHeaders = {
                        'Authorization': `Bearer ${this.getToken()}`
                    };
                    
                    const retryResponse = await fetch(this.baseURL, {
                        method: 'POST',
                        headers: newHeaders,
                        body: formData
                    });
                    
                    if (!retryResponse.ok) {
                        throw new Error(`HTTP ${retryResponse.status}: ${retryResponse.statusText}`);
                    }
                    
                    return await retryResponse.json();
                } else {
                    // Refresh failed, redirect to login
                    this.handleAuthFailure();
                    throw new Error('Authentication failed');
                }
            }

            return result;

        } catch (error) {
            console.error('API request error:', error);
            throw error;
        }
    },

    // Refresh authentication token
    async refreshToken() {
        const refreshToken = this.getRefreshToken();
        if (!refreshToken) {
            return false;
        } 
        try {            
            const formData = new FormData();
            formData.append('action', 'auth-refresh');
            formData.append('refresh_token', refreshToken);

            const response = await fetch(this.baseURL, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                console.error('Refresh token request failed:', response.status, response.statusText);
                return false;
            }

            const result = await response.json();

            if (result.success && result.data && result.data.tokens) {
                this.setToken(result.data.tokens.access_token);
                this.setRefreshToken(result.data.tokens.refresh_token);
                this.setUser(result.data.user);
                return true;
            }

            console.error('Token refresh failed:', result.message);
            return false;

        } catch (error) {
            console.error('Token refresh error:', error);
            return false;
        }
    },

    // Handle authentication failure
    handleAuthFailure() {
        this.clearAuth();
        utils.showAlert('Session expired. Please login again.', 'warning');
        // Redirect to login page after a short delay
        setTimeout(() => {
            window.location.href = '/ims_frontend/';
        }, 2000);
    },

    // Authentication endpoints
    auth: {
        async login(username, password) {
            
            const result = await api.request('auth-login', {
                username: username,
                password: password
            });

            if (result.success && result.data && result.data.tokens) {
                api.setToken(result.data.tokens.access_token);
                api.setRefreshToken(result.data.tokens.refresh_token);
                api.setUser(result.data.user);
            }

            return result;
        },

        async logout() {
            const refreshToken = api.getRefreshToken();
            
            try {
                if (refreshToken) {
                    await api.request('auth-logout', {
                        refresh_token: refreshToken
                    });
                }
            } catch (error) {
                console.error('Logout error:', error);
            } finally {
                api.clearAuth();
            }
        },

        async verifyToken() {
            try {
                const result = await api.request('auth-verify_token');
                return result.success;
            } catch (error) {
                console.error('Token verification error:', error);
                return false;
            }
        },

        async changePassword(currentPassword, newPassword, confirmPassword) {
            return await api.request('auth-change_password', {
                current_password: currentPassword,
                new_password: newPassword,
                confirm_password: confirmPassword
            });
        }
    },

    // Dashboard endpoints
    dashboard: {
        async getData() {
            return await api.request('dashboard-get_data');
        },

        async getAdminData() {
            return await api.request('dashboard-get_admin_data');
        }
    },

    // Component management endpoints
    components: {
        async list(componentType, params = {}) {
            return await api.request(`${componentType}-list`, params);
        },

        async get(componentType, id) {
            return await api.request(`${componentType}-get`, { id: id });
        },

        async add(componentType, data) {
            return await api.request(`${componentType}-add`, data);
        },

        async update(componentType, id, data) {
            return await api.request(`${componentType}-update`, { 
                id: id,
                ...data 
            });
        },

        async delete(componentType, id) {
            return await api.request(`${componentType}-delete`, { id: id });
        },

        async bulkUpdate(componentType, ids, updates) {
            return await api.request(`${componentType}-bulk_update`, {
                ids: ids,
                ...updates
            });
        },

        async getJSONData(componentType) {
            return await api.request(`${componentType}-get_json_data`);
        }
    },

    servers: {
        async createConfig(serverName, description, startWith) {
            return await api.request('server-create-start', {
                server_name: serverName,
                description: description,
                start_with: startWith
            });
        },

        async listConfigs(params = {}) {
            return await api.request('server-list-configs', params);
        },

        async getConfig(configUuid) {
            return await api.request('server-get-config', { config_uuid: configUuid });
        },

        async deleteConfig(configUuid) {
            return await api.request('server-delete-config', { config_uuid: configUuid });
        },

        async finalizeConfig(configUuid, notes = '') {
            return await api.request('server-finalize-config', {
                config_uuid: configUuid,
                notes: notes
            });
        },

        async getCompatibleComponents(configUuid, componentType, availableOnly = true) {
            return await api.request('server-get-compatible', {
                config_uuid: configUuid,
                component_type: componentType,
                available_only: availableOnly.toString()
            });
        },

        async addComponent(configUuid, componentType, componentUuid, quantity = 1, slotPosition = '', override = false) {
            return await api.request('server-add-component', {
                config_uuid: configUuid,
                component_type: componentType,
                component_uuid: componentUuid,
                quantity: quantity.toString(),
                slot_position: slotPosition,
                override: override.toString()
            });
        },

        async removeComponent(configUuid, componentType, componentUuid) {
            return await api.request('server-remove-component', {
                config_uuid: configUuid,
                component_type: componentType,
                component_uuid: componentUuid
            });
        },

        async validateConfig(configUuid) {
            return await api.request('server-validate-config', { config_uuid: configUuid });
        },

        async getAvailableComponents(componentType, includeInUse = false, limit = 50) {
            return await api.request('server-get-available-components', {
                component_type: componentType,
                include_in_use: includeInUse.toString(),
                limit: limit.toString()
            });
        }
    },

    // Search endpoints
    search: {
        async global(query, params = {}) {
            return await api.request('search-components', {
                q: query,
                ...params
            });
        },

        async advanced(filters) {
            return await api.request('search-advanced', filters);
        }
    },

    // User management endpoints (for future use)
    users: {
        async list(params = {}) {
            return await api.request('users-list', params);
        },

        async get(id) {
            return await api.request('users-get', { id: id });
        },

        async create(data) {
            return await api.request('users-create', data);
        },

        async update(id, data) {
            return await api.request('users-update', { 
                id: id,
                ...data 
            });
        },

        async delete(id) {
            return await api.request('users-delete', { id: id });
        },

        async resetPassword(id, newPassword = null, sendEmail = true) {
            return await api.request('users-reset_password', {
                id: id,
                new_password: newPassword,
                send_email: sendEmail
            });
        },

        async manageRoles(userId, roleIds, replace = true) {
            return await api.request('users-manage_roles', {
                user_id: userId,
                roles: roleIds,
                replace: replace
            });
        }
    },

    // Utility methods
    utils: {
        // Check if user is authenticated
        isAuthenticated() {
            return !!api.getToken();
        },

        // Check if user has specific permission
        hasPermission(permission) {
            const user = api.getUser();
            if (!user || !user.permissions) {
                return false;
            }
            return user.permissions.includes(permission);
        },

        // Check if user has any of the specified roles
        hasRole(roles) {
            const user = api.getUser();
            if (!user || !user.roles) {
                return false;
            }
            
            const userRoles = user.roles.map(role => role.name);
            return Array.isArray(roles) 
                ? roles.some(role => userRoles.includes(role))
                : userRoles.includes(roles);
        },

        // Get user's primary role
        getPrimaryRole() {
            const user = api.getUser();
            return user ? user.primary_role : null;
        },

        // Format API error message
        formatError(result) {
            if (result.message) {
                return result.message;
            }
            
            if (result.errors && typeof result.errors === 'object') {
                return Object.values(result.errors).flat().join(', ');
            }
            
            return 'An unexpected error occurred';
        },

        // Handle API response with automatic error display
        async handleResponse(apiCall, successMessage = null, errorTitle = 'Error') {
            try {
                utils.showLoading(true);
                const result = await apiCall;
                
                if (result.success) {
                    if (successMessage) {
                        utils.showAlert(successMessage, 'success');
                    }
                    return result;
                } else {
                    const errorMessage = this.formatError(result);
                    utils.showAlert(errorMessage, 'error', errorTitle);
                    throw new Error(errorMessage);
                }
            } catch (error) {
                if (error.message !== this.formatError({ message: error.message })) {
                    utils.showAlert('Network error or server unavailable', 'error', 'Connection Error');
                }
                throw error;
            } finally {
                utils.showLoading(false);
            }
        }
    }
};

// Initialize API authentication check on page load
document.addEventListener('DOMContentLoaded', async () => {
    
    // Skip auth check if on login page
    if (window.location.pathname.includes('login')) {
        return;
    }

    // Check if user is authenticated
    if (!api.utils.isAuthenticated()) {
        // Redirect to login if not authenticated
        window.location.href = '/ims_frontend/';
        return;
    }
    // Verify token is still valid
    const isValid = await api.auth.verifyToken();
    if (!isValid) {
        // Try to refresh token
        const refreshed = await api.refreshToken();
        if (!refreshed) {
            // Redirect to login if refresh failed
            api.handleAuthFailure();
        } else {
        }
    } else {
    }
});

// Export for use in other modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = api;
}