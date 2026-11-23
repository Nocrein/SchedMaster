<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule_access.php';

requireAdmin();

$conn = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $scheduleId = $_GET['schedule_id'] ?? null;
    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    $stmt = $conn->prepare("SELECT id, name, employment_type, max_teaching_hours FROM instructors WHERE schedule_id = ? ORDER BY name");
    $stmt->execute([$scheduleId]);
    $instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach each instructor's qualified subject_ids so the UI can show
    // and edit "what can this person teach" without a second round-trip.
    $subjStmt = $conn->prepare("SELECT subject_id FROM instructor_subjects WHERE instructor_id = ?");
    foreach ($instructors as &$inst) {
        $subjStmt->execute([$inst['id']]);
        $inst['subject_ids'] = array_map('intval', array_column($subjStmt->fetchAll(PDO::FETCH_ASSOC), 'subject_id'));
        $inst['max_teaching_hours'] = (float) $inst['max_teaching_hours'];
    }
    unset($inst);

    echo json_encode(['success' => true, 'instructors' => $instructors]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $scheduleId = $data['schedule_id'] ?? null;
    $name = trim($data['name'] ?? '');
    $employmentType = $data['employment_type'] ?? 'full-time';
    // Default reflects the common case: full-time staff usually have 6 hrs
    // of actual teaching load (the rest of their 8 hrs is ancillary work).
    // Part-timers vary by contract, so the admin should set this explicitly.
    $maxHours = isset($data['max_teaching_hours']) ? (float) $data['max_teaching_hours'] : 6.0;
    $subjectIds = $data['subject_ids'] ?? [];

    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    if (!$name) {
        http_response_code(400);
        echo json_encode(['error' => 'Instructor name is required.']);
        exit;
    }
    if (!in_array($employmentType, ['full-time', 'part-time'])) {
        $employmentType = 'full-time';
    }
    if ($maxHours <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Max teaching hours must be greater than 0.']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO instructors (schedule_id, name, employment_type, max_teaching_hours) VALUES (?, ?, ?, ?)");
    $stmt->execute([$scheduleId, $name, $employmentType, $maxHours]);
    $instructorId = $conn->lastInsertId();

    if (is_array($subjectIds) && count($subjectIds) > 0) {
        $subjStmt = $conn->prepare("INSERT IGNORE INTO instructor_subjects (instructor_id, subject_id) VALUES (?, ?)");
        foreach ($subjectIds as $subjectId) {
            $subjStmt->execute([$instructorId, (int) $subjectId]);
        }
    }

    echo json_encode(['success' => true, 'id' => $instructorId]);
    exit;
}

if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;
    $stmt = $conn->prepare("SELECT schedule_id FROM instructors WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);
    $name = trim($data['name'] ?? '');
    $employmentType = in_array($data['employment_type'] ?? '', ['full-time', 'part-time']) ? $data['employment_type'] : 'full-time';
    $maxHours = (float) ($data['max_teaching_hours'] ?? 6);
    if (!$name) { http_response_code(400); echo json_encode(['error' => 'Instructor name is required.']); exit; }
    if ($maxHours <= 0) { http_response_code(400); echo json_encode(['error' => 'Max teaching hours must be greater than 0.']); exit; }

    $conn->prepare("UPDATE instructors SET name = ?, employment_type = ?, max_teaching_hours = ? WHERE id = ?")
         ->execute([$name, $employmentType, $maxHours, $id]);

    if (array_key_exists('subject_ids', $data) && is_array($data['subject_ids'])) {
        $conn->prepare("DELETE FROM instructor_subjects WHERE instructor_id = ?")->execute([$id]);
        $ins = $conn->prepare("INSERT IGNORE INTO instructor_subjects (instructor_id, subject_id) VALUES (?, ?)");
        foreach ($data['subject_ids'] as $sid) { $ins->execute([$id, (int) $sid]); }
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $data);
    $id = $data['id'] ?? null;

    // Look up schedule_id for this instructor first, then verify ownership
    $stmt = $conn->prepare("SELECT schedule_id FROM instructors WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);

    $conn->prepare("DELETE FROM instructors WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
