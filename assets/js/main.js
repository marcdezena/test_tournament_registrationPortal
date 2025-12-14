// Real-time updates using WebSockets or AJAX polling
function initRealTimeUpdates() {
    // Check for new notifications
    function checkNotifications() {
        if (typeof user_id === 'undefined') return;
        
        $.get('api/notifications/unread_count')
            .done(function(response) {
                if (response.count > 0) {
                    $('.notification-badge').text(response.count).show();
                } else {
                    $('.notification-badge').hide();
                }
            });
    }
    
    // Check every 30 seconds
    setInterval(checkNotifications, 30000);
    checkNotifications();
    
    // Initialize WebSocket connection if available
    if (typeof WebSocket !== 'undefined') {
        const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
        const ws = new WebSocket(`${protocol}//${window.location.host}/ws`);
        
        ws.onmessage = function(event) {
            const data = JSON.parse(event.data);
            
            if (data.type === 'notification') {
                // Show toast notification
                showToast(data.message, data.title, data.type);
                
                // Update notification count
                const badge = $('.notification-badge');
                const count = parseInt(badge.text() || '0') + 1;
                badge.text(count).show();
            }
        };
        
        ws.onclose = function() {
            // Fall back to polling if WebSocket connection is lost
            console.log('WebSocket connection closed, falling back to polling');
            setInterval(checkNotifications, 5000);
        };
    }
}

// Show toast notification
function showToast(message, title = 'Notification', type = 'info') {
    const toast = $(`
        <div class="toast" role="alert" aria-live="assertive" aria-atomic="true" data-delay="5000">
            <div class="toast-header">
                <strong class="mr-auto">${title}</strong>
                <button type="button" class="ml-2 mb-1 close" data-dismiss="toast" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `);
    
    toast.addClass(`toast-${type}`);
    $('#toast-container').append(toast);
    toast.toast('show');
    
    // Remove toast after it's hidden
    toast.on('hidden.bs.toast', function() {
        toast.remove();
    });
}

// Initialize when document is ready
$(document).ready(function() {
    initRealTimeUpdates();
    
    // Mark notification as read when clicked
    $(document).on('click', '.notification-item', function() {
        const notificationId = $(this).data('id');
        if (notificationId) {
            $.post('api/notifications/mark_read', { id: notificationId });
            $(this).removeClass('unread');
        }
    });
    
    // Handle mark all as read
    $('#mark-all-read').click(function(e) {
        e.stopPropagation();
        $.post('api/notifications/mark_all_read')
            .done(function() {
                $('.notification-item').removeClass('unread');
                $('.notification-badge').hide();
            });
    });
});