<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule_access.php';
require_once __DIR__ . '/../includes/schedule_conflicts.php';

requireAdmin();

$conn = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $scheduleId = $_GET['schedule_id'] ?? null;
    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    $stmt = $conn->prepare(
        "SELECT ca.id, ca.start_time, ca.end_time, ca.room,
                i.id AS instructor_id, i.name AS instructor_name,
                subj.id AS subject_id, subj.subject_code, subj.subject_name,
                sec.id AS section_id, sec.section_name,
                tp.id AS pattern_id, tp.name AS pattern_name
         FROM class_assignments ca
         JOIN instructors i ON i.id = ca.instructor_id
         JOIN subjects subj ON subj.id = ca.subject_id
         JOIN sections sec ON sec.id = ca.section_id
         JOIN time_patterns tp ON tp.id = ca.pattern_id
         WHERE ca.schedule_id = ?
         ORDER BY sec.section_name, ca.start_time"
    );
    $stmt->execute([$scheduleId]);
    echo json_encode(['success' => true, 'assignments' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $scheduleId = $data['schedule_id'] ?? null;
    $instructorId = $data['instructor_id'] ?? null;
    $subjectId = $data['subject_id'] ?? null;
    $sectionId = $data['section_id'] ?? null;
    $patternId = $data['pattern_id'] ?? null;
    $startTime = $data['start_time'] ?? '';
    $endTime = $data['end_time'] ?? '';
    $room = trim($data['room'] ?? '');

    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    if (!$instructorId || !$subjectId || !$sectionId || !$patternId || !$startTime || !$endTime || !$room) {
        http_response_code(400);
        echo json_encode(['error' => 'Instructor, subject, section, pattern, start/end time, and room are all required.']);
        exit;
    }

    if ($startTime >= $endTime) {
        http_response_code(400);
        echo json_encode(['error' => 'End time must be after start time.']);
        exit;
    }

    $breakClash = findBreakConflict($conn, $sectionId, $startTime, $endTime);
    if ($breakClash) {
        http_response_code(409);
        echo json_encode(['error' => 'That time overlaps this section\'s lunch break (' . $breakClash . '). Pick a time before or after it, or change the section\'s break.']);
        exit;
    }

    $conflicts = findConflicts($conn, $scheduleId, $patternId, $startTime, $endTime, $instructorId, $sectionId, $room);

    if (!empty($conflicts)) {
        $messages = [];
        if (isset($conflicts['instructor'])) {
            $messages[] = 'Instructor ' . implode(', ', array_unique($conflicts['instructor'])) . ' is already teaching at this time.';
        }
        if (isset($conflicts['section'])) {
            $messages[] = 'Section ' . implode(', ', array_unique($conflicts['section'])) . ' already has a class at this time.';
        }
        if (isset($conflicts['room'])) {
            $messages[] = 'Room "' . $room . '" is already booked at this time.';
        }
        http_response_code(409);
        echo json_encode(['error' => implode(' ', $messages), 'conflicts' => $conflicts]);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO class_assignments (schedule_id, instructor_id, subject_id, section_id, pattern_id, start_time, end_time, room)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$scheduleId, $instructorId, $subjectId, $sectionId, $patternId, $startTime, $endTime, $room]);

    echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);
    exit;
}

// Edit a scheduled class: change its days, time, room or instructor.
// The subject and section stay as they are. Conflicts are re-checked
// against every OTHER class, never against the class being edited.
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;

    $stmt = $conn->prepare("SELECT schedule_id, section_id FROM class_assignments WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);

    $instructorId = (int) ($data['instructor_id'] ?? 0);
    $patternId = (int) ($data['pattern_id'] ?? 0);
    $room = trim($data['room'] ?? '');
    $startTime = substr(trim($data['start_time'] ?? ''), 0, 5);
    $endTime = substr(trim($data['end_time'] ?? ''), 0, 5);

    if (!$instructorId || !$patternId || !$startTime || !$endTime || !$room) {
        http_response_code(400);
        echo json_encode(['error' => 'Instructor, days, start/end time, and room are all required.']);
        exit;
    }
    if ($startTime >= $endTime) {
        http_response_code(400);
        echo json_encode(['error' => 'End time must be after start time.']);
        exit;
    }
    foreach ([['instructors', $instructorId], ['time_patterns', $patternId]] as [$table, $val]) {
        $chk = $conn->prepare("SELECT 1 FROM $table WHERE id = ? AND schedule_id = ?");
        $chk->execute([$val, $row['schedule_id']]);
        if (!$chk->fetch()) { http_response_code(400); echo json_encode(['error' => 'That instructor or day pattern is not part of this schedule.']); exit; }
    }

    $breakClash = findBreakConflict($conn, $row['section_id'], $startTime, $endTime);
    if ($breakClash) {
        http_response_code(409);
        echo json_encode(['error' => 'That time overlaps this section\'s lunch break (' . $breakClash . '). Pick a time before or after it, or change the section\'s break.']);
        exit;
    }

    $conflicts = findConflicts($conn, $row['schedule_id'], $patternId, $startTime, $endTime, $instructorId, $row['section_id'], $room, $id);
    if (!empty($conflicts)) {
        $messages = [];
        if (isset($conflicts['instructor'])) $messages[] = 'Instructor ' . implode(', ', array_unique($conflicts['instructor'])) . ' is already teaching at this time.';
        if (isset($conflicts['section']))    $messages[] = 'Section ' . implode(', ', array_unique($conflicts['section'])) . ' already has a class at this time.';
        if (isset($conflicts['room']))       $messages[] = 'Room "' . $room . '" is already booked at this time.';
        http_response_code(409);
        echo json_encode(['error' => implode(' ', $messages), 'conflicts' => $conflicts]);
        exit;
    }

    $conn->prepare("UPDATE class_assignments SET instructor_id = ?, pattern_id = ?, start_time = ?, end_time = ?, room = ? WHERE id = ?")
         ->execute([$instructorId, $patternId, $startTime, $endTime, $room, $id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $data);

    // "Clear all classes": delete every class in one schedule so it can be rebuilt.
    if (!empty($data['all']) && !empty($data['schedule_id'])) {
        assertOwnsSchedule($conn, $data['schedule_id'], $_SESSION['user_id']);
        $del = $conn->prepare("DELETE FROM class_assignments WHERE schedule_id = ?");
        $del->execute([$data['schedule_id']]);
        echo json_encode(['success' => true, 'deleted' => $del->rowCount()]);
        exit;
    }

    $id = $data['id'] ?? null;

    $stmt = $conn->prepare("SELECT schedule_id FROM class_assignments WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);

    $conn->prepare("DELETE FROM class_assignments WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
