<?php
// notifications.php — lightweight JSON endpoint used by the bell widget on
// every dashboard. Supports listing recent notifications, getting the
// unread count, and marking notifications as read.

require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$user_id = intval($_SESSION['user_id']);
$action  = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, title, message, type, is_read, related_type, related_id, created_at
             FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 25"
        );
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $items = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $items[] = [
                'id'           => (int) $r['id'],
                'title'        => $r['title'],
                'message'      => $r['message'],
                'type'         => $r['type'],
                'is_read'      => (int) $r['is_read'],
                'related_type' => $r['related_type'],
                'related_id'   => $r['related_id'] !== null ? (int) $r['related_id'] : null,
                'created_at'   => $r['created_at'],
            ];
        }

        // Count unread
        $count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
        mysqli_stmt_bind_param($count_stmt, "i", $user_id);
        mysqli_stmt_execute($count_stmt);
        $count_res = mysqli_stmt_get_result($count_stmt);
        $unread = (int) (mysqli_fetch_assoc($count_res)['c'] ?? 0);

        echo json_encode(['success' => true, 'items' => $items, 'unread' => $unread]);
        break;

    case 'count':
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        echo json_encode(['success' => true, 'unread' => (int) ($r['c'] ?? 0)]);
        break;

    case 'mark_read':
        // CSRF only required for state-mutating calls.
        if (!validate_csrf_token($_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? ''))) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id > 0) {
            $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
            mysqli_stmt_bind_param($stmt, "ii", $id, $user_id);
            mysqli_stmt_execute($stmt);
        }
        echo json_encode(['success' => true]);
        break;

    case 'mark_all_read':
        if (!validate_csrf_token($_POST['csrf_token'] ?? ($_GET['csrf_token'] ?? ''))) {
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
