<?php
// Admin: manage a schedule's teacher/student accounts, its join code, and
// whether the student/teacher pages are open to anyone with the link.
//   GET    ?schedule_id=           -> join code, public_enabled, all accounts
//   POST   {schedule_id, action}   -> action = regenerate_code | set_public (+ value)
//   PUT    {schedule_id, user_id, action} -> approve | reset_password (+ password)
//   DELETE ?schedule_id=&user_id=  -> reject / remove an account
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule_access.php';

requireAdmin();
$conn = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];
$body = in_array($method, ['POST', 'PUT'], true) ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
$scheduleId = $body['schedule_id'] ?? ($_GET['schedule_id'] ?? null);
assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

function out($code, $arr) { http_response_code($code); echo json_encode($arr); exit; }

// Only touch non-admin accounts that belong to THIS schedule.
function loadMember(PDO $conn, $scheduleId, $userId) {
    $stmt = $conn->prepare("SELECT id, role FROM users WHERE id = ? AND schedule_id = ? AND role <> 'admin'");
    $stmt->execute([$userId, $scheduleId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($method === 'GET') {
    $row = $conn->prepare("SELECT join_code, public_enabled, student_auto_approve FROM schedules WHERE id = ?");
    $row->execute([$scheduleId]);
    $sched = $row->fetch(PDO::FETCH_ASSOC);
    if (!$sched['join_code']) {
        $sched['join_code'] = generateJoinCode($conn);
        $conn->prepare("UPDATE schedules SET join_code = ? WHERE id = ?")->execute([$sched['join_code'], $scheduleId]);
    }
    $list = $conn->prepare(
        "SELECT u.id, u.name, u.email, u.role, u.status, u.created_at,
                i.name AS instructor_name, s.section_name
         FROM users u
         LEFT JOIN instructors i ON i.id = u.instructor_id
         LEFT JOIN sections s ON s.id = u.section_id
         WHERE u.schedule_id = ? AND u.role <> 'admin'
         ORDER BY u.status = 'pending' DESC, u.role, u.name"
    );
    $list->execute([$scheduleId]);
    out(200, ['join_code' => $sched['join_code'], 'public_enabled' => (int) $sched['public_enabled'] === 1, 'student_auto_approve' => (int) $sched['student_auto_approve'] === 1, 'accounts' => $list->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($method === 'POST') {
    $action = $body['action'] ?? '';
    if ($action === 'regenerate_code') {
        $code = generateJoinCode($conn);
        $conn->prepare("UPDATE schedules SET join_code = ? WHERE id = ?")->execute([$code, $scheduleId]);
        out(200, ['success' => true, 'join_code' => $code]);
    }
    if ($action === 'set_public') {
        $on = !empty($body['value']) ? 1 : 0;
        $conn->prepare("UPDATE schedules SET public_enabled = ? WHERE id = ?")->execute([$on, $scheduleId]);
        out(200, ['success' => true, 'public_enabled' => $on === 1]);
    }
    if ($action === 'set_student_auto') {
        $on = !empty($body['value']) ? 1 : 0;
        $conn->prepare("UPDATE schedules SET student_auto_approve = ? WHERE id = ?")->execute([$on, $scheduleId]);
        out(200, ['success' => true, 'student_auto_approve' => $on === 1]);
    }
    // Approve every waiting request at once (role = student | teacher | all).
    if ($action === 'approve_all') {
        $role = $body['role'] ?? 'student';
        $sql = "UPDATE users SET status = 'approved' WHERE schedule_id = ? AND status = 'pending' AND role <> 'admin'";
        $args = [$scheduleId];
        if ($role === 'student' || $role === 'teacher') { $sql .= " AND role = ?"; $args[] = $role; }
        $stmt = $conn->prepare($sql);
        $stmt->execute($args);
        out(200, ['success' => true, 'approved' => $stmt->rowCount()]);
    }
    out(400, ['error' => 'Unknown action.']);
}

if ($method === 'PUT') {
    $member = loadMember($conn, $scheduleId, $body['user_id'] ?? 0);
    if (!$member) out(404, ['error' => 'Account not found.']);
    $action = $body['action'] ?? '';
    if ($action === 'approve') {
        $conn->prepare("UPDATE users SET status = 'approved' WHERE id = ?")->execute([$member['id']]);
        out(200, ['success' => true]);
    }
    if ($action === 'reset_password') {
        $pw = $body['password'] ?? '';
        if (strlen($pw) < 6) out(400, ['error' => 'Password must be at least 6 characters.']);
        $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $member['id']]);
        out(200, ['success' => true]);
    }
    out(400, ['error' => 'Unknown action.']);
}

if ($method === 'DELETE') {
    $member = loadMember($conn, $scheduleId, $_GET['user_id'] ?? 0);
    if (!$member) out(404, ['error' => 'Account not found.']);
    $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$member['id']]);
    out(200, ['success' => true]);
}

out(405, ['error' => 'Method not allowed.']);
