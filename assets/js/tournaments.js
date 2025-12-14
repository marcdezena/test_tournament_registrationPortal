/**
 * tournaments.js - Handles tournament page interactions
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    if (window.bootstrap && bootstrap.Tooltip) {
        tooltips.forEach(tooltip => new bootstrap.Tooltip(tooltip));
    }

    // Handle form submission with loading state
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                submitBtn.setAttribute('data-original-html', originalText);
            }
        });
    });

    // Handle tournament registration
    const registerButtons = document.querySelectorAll('.register-tournament');
    registerButtons.forEach(button => {
        button.addEventListener('click', async function(e) {
            e.preventDefault();
            const tournamentId = this.dataset.tournamentId;
            const button = this;
            
            try {
                // Show loading state
                const originalText = button.innerHTML;
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Registering...';
                
                const response = await fetch('api/register_tournament.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: `tournament_id=${tournamentId}&csrf_token=${getCsrfToken()}`
                });

                const result = await response.json();
                
                if (result.success) {
                    showNotification('Successfully registered for the tournament!', 'success');
                    // Update UI
                    const card = button.closest('.tournament-card');
                    if (card) {
                        const buttonContainer = card.querySelector('.tournament-actions');
                        if (buttonContainer) {
                            buttonContainer.innerHTML = `
                                <button class="btn btn-success" disabled>
                                    <i class="fas fa-check"></i> Registered
                                </button>
                                <a href="bracket.php?tournament_id=${tournamentId}" class="btn btn-outline">
                                    <i class="fas fa-sitemap"></i> View Bracket
                                </a>
                            `;
                        }
                    }
                } else {
                    showNotification(result.message || 'An error occurred', 'error');
                    button.disabled = false;
                    button.innerHTML = originalText;
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('An error occurred while processing your request', 'error');
                button.disabled = false;
                button.innerHTML = originalText;
            }
        });
    });

    // Handle tournament search form submission
    const searchForm = document.querySelector('.search-form');
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            const searchInput = this.querySelector('input[name="search"]');
            if (searchInput && searchInput.value.trim() === '') {
                // If search is empty, remove the search parameter from URL
                const url = new URL(window.location.href);
                url.searchParams.delete('search');
                window.location.href = url.toString();
                e.preventDefault();
            }
        });
    }
});

/**
 * Show a notification message
 * @param {string} message - The message to display
 * @param {string} type - The type of notification (success, error, info)
 */
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    // Trigger reflow
    void notification.offsetWidth;
    
    notification.classList.add('show');
    
    // Auto-remove after delay
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 5000);
}

/**
 * Get CSRF token from meta tag
 * @returns {string} CSRF token
 */
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

/**
 * Format date to relative time (e.g., "2 days ago")
 * @param {string} dateString - ISO date string
 * @returns {string} Formatted relative time
 */
function formatRelativeTime(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffInSeconds = Math.floor((now - date) / 1000);
    
    const intervals = {
        year: 31536000,
        month: 2592000,
        week: 604800,
        day: 86400,
        hour: 3600,
        minute: 60
    };
    
    for (const [unit, seconds] of Object.entries(intervals)) {
        const interval = Math.floor(diffInSeconds / seconds);
        if (interval >= 1) {
            return interval === 1 ? `1 ${unit} ago` : `${interval} ${unit}s ago`;
        }
    }
    
    return 'just now';
}

// Initialize any relative time elements on the page
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-relative-time]').forEach(element => {
        const dateString = element.getAttribute('data-relative-time');
        if (dateString) {
            element.textContent = formatRelativeTime(dateString);
            element.title = new Date(dateString).toLocaleString();
        }
    });
});
