// Toast notification system
const Toast = {
    show(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                ${type === 'success' ? '<i class="fas fa-check-circle"></i>' : ''}
                ${type === 'error' ? '<i class="fas fa-exclamation-circle"></i>' : ''}
                ${type === 'info' ? '<i class="fas fa-info-circle"></i>' : ''}
                <span>${message}</span>
            </div>
        `;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('show');
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }, 100);
    },
    success(message) { this.show(message, 'success'); },
    error(message) { this.show(message, 'error'); },
    info(message) { this.show(message, 'info'); }
};

// Loading spinner management
const LoadingState = {
    show(button) {
        if (!button) return;
        button.disabled = true;
        const originalText = button.innerHTML;
        button.dataset.originalText = originalText;
        button.innerHTML = `
            <div class="spinner"></div>
            <span>Processing...</span>
        `;
    },
    hide(button) {
        if (!button) return;
        button.disabled = false;
        button.innerHTML = button.dataset.originalText;
        delete button.dataset.originalText;
    }
};

// Backwards compatibility aliases used across the app
LoadingState.start = LoadingState.show;
LoadingState.stop = LoadingState.hide;

// Form validation
const FormValidator = {
    validateForm(form) {
        let isValid = true;
        const errors = [];

        // Remove existing error messages
        form.querySelectorAll('.error-message').forEach(el => el.remove());
        form.querySelectorAll('.field-error').forEach(el => el.classList.remove('field-error'));

        // Required fields
        form.querySelectorAll('[required]').forEach(field => {
            if (!field.value.trim()) {
                this.showError(field, `${field.getAttribute('data-label') || field.name} is required`);
                isValid = false;
            }
        });

        // Email validation
        form.querySelectorAll('input[type="email"]').forEach(field => {
            if (field.value && !this.isValidEmail(field.value)) {
                this.showError(field, 'Please enter a valid email address');
                isValid = false;
            }
        });

        // Password strength (if password field exists)
        const password = form.querySelector('input[type="password"]');
        if (password && password.value && !this.isStrongPassword(password.value)) {
            this.showError(password, 'Password must be at least 8 characters with letters and numbers');
            isValid = false;
        }

        return isValid;
    },

    showError(field, message) {
        field.classList.add('field-error');
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error-message';
        errorDiv.textContent = message;
        field.parentNode.appendChild(errorDiv);
    },

    isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    },

    isStrongPassword(password) {
        return password.length >= 8 && /[A-Za-z]/.test(password) && /[0-9]/.test(password);
    }
};

// Confirmation dialogs
const Confirm = {
    show(message, onConfirm, onCancel) {
        const modal = document.createElement('div');
        modal.className = 'modal confirmation-modal';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Confirm Action</h3>
                </div>
                <div class="modal-body">
                    ${message}
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-action="cancel">Cancel</button>
                    <button class="btn btn-primary" data-action="confirm">Confirm</button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        setTimeout(() => modal.classList.add('show'), 10);

        modal.querySelector('[data-action="confirm"]').onclick = () => {
            modal.classList.remove('show');
            setTimeout(() => {
                modal.remove();
                onConfirm?.();
            }, 300);
        };

        modal.querySelector('[data-action="cancel"]').onclick = () => {
            modal.classList.remove('show');
            setTimeout(() => {
                modal.remove();
                onCancel?.();
            }, 300);
        };
    }
};

// Tooltips
const Tooltip = {
    init() {
        document.querySelectorAll('[data-tooltip]').forEach(element => {
            element.addEventListener('mouseenter', e => this.show(e.target));
            element.addEventListener('mouseleave', e => this.hide(e.target));
        });
    },

    show(element) {
        const tooltip = document.createElement('div');
        tooltip.className = 'tooltip';
        tooltip.textContent = element.dataset.tooltip;
        document.body.appendChild(tooltip);

        const rect = element.getBoundingClientRect();
        const top = rect.top + window.scrollY - tooltip.offsetHeight - 10;
        const left = rect.left + window.scrollX + (rect.width - tooltip.offsetWidth) / 2;

        tooltip.style.top = `${top}px`;
        tooltip.style.left = `${left}px`;
        
        setTimeout(() => tooltip.classList.add('show'), 10);
        element.dataset.tooltipId = Date.now();
        tooltip.dataset.for = element.dataset.tooltipId;
    },

    hide(element) {
        const tooltip = document.querySelector(`.tooltip[data-for="${element.dataset.tooltipId}"]`);
        if (tooltip) {
            tooltip.classList.remove('show');
            setTimeout(() => tooltip.remove(), 200);
        }
    }
};

// Initialize tooltips on page load
document.addEventListener('DOMContentLoaded', () => {
    Tooltip.init();
});