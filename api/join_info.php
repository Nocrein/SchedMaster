<?php
// Public: given a join code, list who can sign up (names only), so the
// registration form can show a "pick your name / section" dropdown.
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$code = strtoupper(trim($_GET['code'] ?? ''));
if (!$code) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a join code.']);
    exit;
}

$conn = getDbConnection();
$stmt = $conn->prepare("SELECT id, school_name, student_auto_approve FROM schedules WHERE join_code = ?");
$stmt->execute([$code]);
$schedule = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$schedule) {
    http_response_code(404);
    echo json_encode(['error' => 'That join code is not valid.']);
    exit;
}

$inst = $conn->prepare("SELECT i.id, i.name, (SELECT COUNT(*) FROM users u WHERE u.instructor_id = i.id) AS taken
                        FROM instructors i WHERE i.schedule_id = ? ORDER BY i.name");
$inst->execute([$schedule['id']]);
$sec = $conn->prepare("SELECT id, section_name FROM sections WHERE schedule_id = ? ORDER BY section_name");
$sec->execute([$schedule['id']]);

echo json_encode([
    'school_name' => $schedule['school_name'],
    'student_auto_approve' => (int) $schedule['student_auto_approve'] === 1,
    'instructors' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'taken' => (int) $r['taken'] > 0], $inst->fetchAll(PDO::FETCH_ASSOC)),
    'sections' => array_map(fn($r) => ['id' => (int) $r['id'], 'section_name' => $r['section_name']], $sec->fetchAll(PDO::FETCH_ASSOC)),
]);
