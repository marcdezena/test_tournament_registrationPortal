<?php
// api/notifications.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/notifications.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = min(20, max(1, intval($_GET['limit'] ?? 10)));
        $offset = ($page - 1) * $limit;
        
        // Get notifications
        $stmt = $pdo->prepare("
            SELECT n.*, 
                   JSON_UNQUOTE(JSON_EXTRACT(n.data, '$.message')) as message,
                   n.created_at as timestamp
            FROM notifications n
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$_SESSION['user_id'], $limit, $offset]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Mark as read if specified
        if (isset($_GET['mark_read']) && $_GET['mark_read']) {
            $unread_ids = array_column(array_filter($notifications, fn($n) => !$n['is_read']), 'id');
            if (!empty($unread_ids)) {
                markNotificationsAsRead($unread_ids, $_SESSION['user_id']);
            }
        }
        
        // Get total count for pagination
        $total = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE user_id = ?
        ")->execute([$_SESSION['user_id']])->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'data' => $notifications,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int)$total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        break;
        
    case 'unread_count':
        $count = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM notifications 
            WHERE user_id = ? AND is_read = 0
        ")->execute([$_SESSION['user_id']])->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'count' => (int)$count
        ]);
        break;
        
    case 'mark_read':
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        
        $ids = array_filter(array_map('intval', $ids));
        
        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No IDs provided']);
            exit;
        }
        
        $success = markNotificationsAsRead($ids, $_SESSION['user_id']);
        
        echo json_encode([
            'success' => $success,
            'message' => $success ? 'Notifications marked as read' : 'Failed to update notifications'
        ]);
        break;
        
    case 'mark_all_read':
        $success = $pdo->prepare("
            UPDATE notifications 
            SET is_read = 1, read_at = NOW() 
            WHERE user_id = ? AND is_read = 0
        ")->execute([$_SESSION['user_id']]);
        
        echo json_encode([
            'success' => (bool)$success,
            'message' => $success ? 'All notifications marked as read' : 'Failed to update notifications'
        ]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}