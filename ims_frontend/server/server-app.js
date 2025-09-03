// Main Application Entry Point for Server Management
class ServerApp {
    constructor() {
        this.isAuthenticated = false;
        this.currentUser = null;
        this.init();
    }

    init() {
        this.checkAuthentication();
        this.setupGlobalErrorHandling();
        this.bindGlobalEvents();
    }

    checkAuthentication() {
        const token = localStorage.getItem('jwt_token');
        
        if (!token) {
            this.redirectToLogin();
            return;
        }

        // Verify token validity (basic check)
        try {
            const payload = JSON.parse(atob(token.split('.')[1]));
            const currentTime = Date.now() / 1000;
            
            if (payload.exp && payload.exp < currentTime) {
                localStorage.removeItem('jwt_token');
                this.redirectToLogin();
                return;
            }
            
            this.isAuthenticated = true;
            this.currentUser = {
                id: payload.user_id,
                username: payload.username,
                email: payload.email
            };
            
        } catch (error) {
            console.error('Invalid token format:', error);
            localStorage.removeItem('jwt_token');
            this.redirectToLogin();
        }
    }

    redirectToLogin() {
        window.location.href = '../index.html';
    }

    setupGlobalErrorHandling() {
        window.addEventListener('error', (event) => {
            console.error('Global error:', event.error);
            this.handleGlobalError(event.error);
        });

        window.addEventListener('unhandledrejection', (event) => {
            console.error('Unhandled promise rejection:', event.reason);
            this.handleGlobalError(event.reason);
        });

        // Setup axios global error interceptor
        axios.interceptors.response.use(
            response => response,
            error => {
                if (error.response?.status === 401) {
                    this.handleAuthenticationError();
                }
                return Promise.reject(error);
            }
        );
    }

    handleGlobalError(error) {
        // For now, just log errors. In production, you might want to send to logging service
        console.error('Application error:', error);
    }

    handleAuthenticationError() {
        localStorage.removeItem('jwt_token');
        this.showAlert('Your session has expired. Please log in again.', 'warning');
        setTimeout(() => {
            this.redirectToLogin();
        }, 2000);
    }

    bindGlobalEvents() {
        // Handle browser back/forward navigation
        window.addEventListener('popstate', (event) => {
            // Handle state changes if implementing SPA navigation
        });

        // Handle keyboard shortcuts
        document.addEventListener('keydown', (event) => {
            this.handleKeyboardShortcuts(event);
        });

        // Handle visibility change (tab switching)
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.handlePageVisible();
            }
        });
    }

    handleKeyboardShortcuts(event) {
        // Ctrl+N or Cmd+N for new server
        if ((event.ctrlKey || event.metaKey) && event.key === 'n') {
            event.preventDefault();
            if (window.serverManager) {
                window.serverManager.showAddServerModal();
            }
        }

        // Escape key to close modals
        if (event.key === 'Escape') {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                const modal = bootstrap.Modal.getInstance(openModal);
                if (modal) {
                    modal.hide();
                }
            }
        }
    }

    handlePageVisible() {
        // Refresh data when user returns to tab
        if (window.serverManager && this.isAuthenticated) {
            window.serverManager.loadServerConfigs();
        }
    }

    showAlert(message, type = 'info') {
        // Use the serverManager's showAlert method if available
        if (window.serverManager) {
            window.serverManager.showAlert(message, type);
        } else {
            // Fallback alert
            alert(message);
        }
    }

    // Utility methods for other components to use
    static formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    static formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    static debounce(func, wait, immediate) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                timeout = null;
                if (!immediate) func(...args);
            };
            const callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func(...args);
        };
    }

    static throttle(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }

    // Component validation helpers
    static validateServerName(name) {
        if (!name || name.trim().length === 0) {
            return { valid: false, message: 'Server name is required' };
        }
        
        if (name.length < 3) {
            return { valid: false, message: 'Server name must be at least 3 characters long' };
        }
        
        if (name.length > 100) {
            return { valid: false, message: 'Server name must not exceed 100 characters' };
        }
        
        // Check for invalid characters
        const invalidChars = /[<>:"\\|?*]/;
        if (invalidChars.test(name)) {
            return { valid: false, message: 'Server name contains invalid characters' };
        }
        
        return { valid: true };
    }

    static validateDescription(description) {
        if (description && description.length > 500) {
            return { valid: false, message: 'Description must not exceed 500 characters' };
        }
        
        return { valid: true };
    }

    static validateQuantity(quantity) {
        const num = parseInt(quantity);
        
        if (isNaN(num) || num < 1) {
            return { valid: false, message: 'Quantity must be a positive number' };
        }
        
        if (num > 100) {
            return { valid: false, message: 'Quantity cannot exceed 100' };
        }
        
        return { valid: true };
    }

    static validateSlotPosition(slotPosition, componentType) {
        if (!slotPosition) {
            return { valid: true }; // Optional field
        }
        
        if (slotPosition.length > 50) {
            return { valid: false, message: 'Slot position must not exceed 50 characters' };
        }
        
        // Component-specific validation
        const patterns = {
            cpu: /^CPU_\d+$/i,
            ram: /^DIMM_[A-H]\d+$/i,
            gpu: /^PCIe_\d+$/i,
            storage: /^(SATA_\d+|NVMe_\d+|SAS_\d+)$/i
        };
        
        if (patterns[componentType] && !patterns[componentType].test(slotPosition)) {
            const examples = {
                cpu: 'CPU_1, CPU_2',
                ram: 'DIMM_A1, DIMM_B2',
                gpu: 'PCIe_1, PCIe_2',
                storage: 'SATA_1, NVMe_1, SAS_1'
            };
            
            return { 
                valid: false, 
                message: `Invalid slot position format for ${componentType}. Examples: ${examples[componentType]}` 
            };
        }
        
        return { valid: true };
    }

    // Loading state helpers
    static showLoadingState(element, message = 'Loading...') {
        if (element) {
            element.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">${message}</p>
                </div>
            `;
        }
    }

    static hideLoadingState(element) {
        if (element) {
            const spinner = element.querySelector('.spinner-border');
            if (spinner) {
                spinner.closest('.text-center').remove();
            }
        }
    }

    // Local storage helpers
    static saveUserPreference(key, value) {
        try {
            const preferences = JSON.parse(localStorage.getItem('user_preferences') || '{}');
            preferences[key] = value;
            localStorage.setItem('user_preferences', JSON.stringify(preferences));
        } catch (error) {
            console.error('Error saving user preference:', error);
        }
    }

    static getUserPreference(key, defaultValue = null) {
        try {
            const preferences = JSON.parse(localStorage.getItem('user_preferences') || '{}');
            return preferences[key] !== undefined ? preferences[key] : defaultValue;
        } catch (error) {
            console.error('Error loading user preference:', error);
            return defaultValue;
        }
    }
}

// Initialize the application
document.addEventListener('DOMContentLoaded', () => {
    window.serverApp = new ServerApp();
    
    // Add global utility methods to window for easy access
    window.ServerUtils = {
        formatDate: ServerApp.formatDate,
        formatFileSize: ServerApp.formatFileSize,
        debounce: ServerApp.debounce,
        throttle: ServerApp.throttle,
        validateServerName: ServerApp.validateServerName,
        validateDescription: ServerApp.validateDescription,
        validateQuantity: ServerApp.validateQuantity,
        validateSlotPosition: ServerApp.validateSlotPosition,
        showLoadingState: ServerApp.showLoadingState,
        hideLoadingState: ServerApp.hideLoadingState,
        saveUserPreference: ServerApp.saveUserPreference,
        getUserPreference: ServerApp.getUserPreference
    };
});

// Export for module systems if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ServerApp;
}