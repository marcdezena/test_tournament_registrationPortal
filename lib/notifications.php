// lib/notifications.php
<?php
/**
 * Notification system for tournament updates
 */

/**
 * Create a new notification for a user
 */
function createNotification($user_id, $type, $data = []) {
    global $pdo;
    
    $defaults = [
        'message' => '',
        'is_read' => false,
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $notification = array_merge($defaults, [
        'user_id' => $user_id,
        'type' => $type,
        'data' => is_array($data) ? json_encode($data) : $data,
        'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    $stmt = $pdo->prepare("
        INSERT INTO notifications 
        (user_id, type, data, is_read, created_at)
        VALUES (:user_id, :type, :data, :is_read, :created_at)
    ");
    
    return $stmt->execute($notification);
}

/**
 * Get unread notifications for a user
 */
function getUnreadNotifications($user_id, $limit = 10) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? AND is_read = 0
        ORDER BY created_at DESC
        LIMIT ?
    ");
    
    $stmt->execute([$user_id, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mark notifications as read
 */
function markNotificationsAsRead($notification_ids, $user_id = null) {
    global $pdo;
    
    if (empty($notification_ids)) {
        return true;
    }
    
    // Ensure $notification_ids is an array
    if (!is_array($notification_ids)) {
        $notification_ids = [$notification_ids];
    }
    
    // Convert to integers for safety
    $notification_ids = array_map('intval', $notification_ids);
    
    $params = $notification_ids;
    $user_condition = '';
    
    if ($user_id !== null) {
        $user_condition = ' AND user_id = ?';
        $params[] = $user_id;
    }
    
    $placeholders = rtrim(str_repeat('?,', count($notification_ids)), ',');
    
    $stmt = $pdo->prepare("
        UPDATE notifications 
        SET is_read = 1, read_at = NOW() 
        WHERE id IN ($placeholders) $user_condition
    ");
    
    return $stmt->execute($params);
}

/**
 * Send a notification to all tournament participants
 */
function notifyTournamentParticipants($tournament_id, $type, $data = []) {
    global $pdo;
    
    // Get all participants (teams and individuals)
    $participants = $pdo->prepare("
        (SELECT user_id FROM registrations WHERE tournament_id = ? AND user_id IS NOT NULL)
        UNION
        (SELECT tm.user_id 
         FROM registrations r
         JOIN team_members tm ON r.team_id = tm.team_id
         WHERE r.tournament_id = ? AND r.team_id IS NOT NULL)
    ")->execute([$tournament_id, $tournament_id])->fetchAll(PDO::FETCH_COLUMN);
    
    // Send notification to each participant
    $sent = 0;
    foreach ($participants as $user_id) {
        if (createNotification($user_id, $type, $data)) {
            $sent++;
        }
    }
    
    return $sent;
}

/**
 * Send email notification
 */
function sendEmail($to, $subject, $template, $data = []) {
    // Load email template
    $template_file = __DIR__ . "/../emails/{$template}.php";
    
    if (!file_exists($template_file)) {
        error_log("Email template not found: {$template}.php");
        return false;
    }
    
    // Extract variables
    extract($data);
    
    // Start output buffering
    ob_start();
    include $template_file;
    $message = ob_get_clean();
    
    // Set headers
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        'From: ' . SITE_EMAIL,
        'Reply-To: ' . SITE_EMAIL,
        'X-Mailer: PHP/' . phpversion()
    ];
    
    // Send email
    return mail($to, $subject, $message, implode("\r\n", $headers));
}

/**
 * Send a notification about an upcoming match
 */
function notifyUpcomingMatch($match_id, $minutes_before = 30) {
    global $pdo;
    
    $match = $pdo->prepare("
        SELECT m.*, t.name as tournament_name,
               t1.name as team1_name, t2.name as team2_name,
               t1.id as team1_id, t2.id as team2_id
        FROM matches m
        JOIN tournaments t ON m.tournament_id = t.id
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        WHERE m.id = ? AND m.status = 'scheduled'
        AND m.scheduled_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? MINUTE)
    ")->execute([$match_id, $minutes_before])->fetch();
    
    if (!$match) {
        return false;
    }
    
    // Get team members to notify
    $teams = [];
    if ($match['team1_id']) {
        $teams[] = $match['team1_id'];
    }
    if ($match['team2_id']) {
        $teams[] = $match['team2_id'];
    }
    
    if (empty($teams)) {
        return false;
    }
    
    $team_members = $pdo->prepare("
        SELECT u.id, u.email, u.username, u.notification_prefs, tm.team_id
        FROM team_members tm
        JOIN users u ON tm.user_id = u.id
        WHERE tm.team_id IN (" . implode(',', array_fill(0, count($teams), '?')) . ")
    ")->execute($teams)->fetchAll();
    
    $notified = 0;
    $scheduled_time = new DateTime($match['scheduled_at']);
    
    foreach ($team_members as $member) {
        $notification_prefs = json_decode($member['notification_prefs'] ?? '{}', true);
        
        // Check if user wants match reminders
        if (($notification_prefs['match_reminders'] ?? true) !== false) {
            $opponent = $member['team_id'] == $match['team1_id'] ? 
                $match['team2_name'] : $match['team1_name'];
            
            $message = sprintf(
                "Your match against %s in %s is scheduled for %s",
                $opponent ?: 'TBD',
                $match['tournament_name'],
                $scheduled_time->format('F j, Y \a\t g:i A')
            );
            
            // Create in-app notification
            createNotification($member['id'], 'match_reminder', [
                'match_id' => $match_id,
                'tournament_id' => $match['tournament_id'],
                'message' => $message,
                'scheduled_time' => $match['scheduled_at']
            ]);
            
            // Send email if enabled
            if (($notification_prefs['email_match_reminders'] ?? true) !== false) {
                sendEmail(
                    $member['email'],
                    "Upcoming Match: {$match['tournament_name']}",
                    'match_reminder',
                    [
                        'username' => $member['username'],
                        'match' => $match,
                        'opponent' => $opponent,
                        'scheduled_time' => $scheduled_time->format('F j, Y \a\t g:i A'),
                        'message' => $message
                    ]
                );
            }
            
            $notified++;
        }
    }
    
    return $notified;
}