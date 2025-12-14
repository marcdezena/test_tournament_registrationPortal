<?php
require_once __DIR__ . '/../config.php';

if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false]);
    exit;
}

try {
    // Detect read column name
    $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'");
    $colStmt->execute();
    $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    $readCol = in_array('read_flag', $cols) ? 'read_flag' : (in_array('is_read', $cols) ? 'is_read' : 'read_flag');

    $stmt = $pdo->prepare("UPDATE notifications SET $readCol = 1 WHERE user_id = ? AND $readCol = 0");
    $stmt->execute([$_SESSION['user_id']]);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'marked' => $stmt->rowCount()]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
