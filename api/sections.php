<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule_access.php';

requireAdmin();

$conn = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

// "HH:MM" or "HH:MM:SS" -> "HH:MM:00", or null when blank/invalid.
function normalizeTime($t) {
    $t = trim((string) $t);
    if (!preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $t, $m)) return null;
    if ((int) $m[1] > 23 || (int) $m[2] > 59) return null;
    return sprintf('%02d:%02d:00', $m[1], $m[2]);
}

// Reads break_start/break_end from the request. Returns [start, end] (both
// null = no break) or exits with a 400 when only one is set / end <= start.
function parseBreak(array $data) {
    $bs = normalizeTime($data['break_start'] ?? '');
    $be = normalizeTime($data['break_end'] ?? '');
    if (($bs === null) !== ($be === null) || ($bs !== null && $bs >= $be)) {
        http_response_code(400);
        echo json_encode(['error' => 'Set both a break start and end time, with the end after the start (or turn the break off).']);
        exit;
    }
    return [$bs, $be];
}

// Replace a section's allowed day-patterns (only patterns of the same schedule).
function syncSectionPatterns(PDO $conn, $sectionId, $scheduleId, $patternIds) {
    $conn->prepare("DELETE FROM section_patterns WHERE section_id = ?")->execute([$sectionId]);
    if (!is_array($patternIds) || !count($patternIds)) return;
    $valid = $conn->prepare("SELECT id FROM time_patterns WHERE id = ? AND schedule_id = ?");
    $ins = $conn->prepare("INSERT IGNORE INTO section_patterns (section_id, pattern_id) VALUES (?, ?)");
    foreach (array_unique(array_map('intval', $patternIds)) as $pid) {
        $valid->execute([$pid, $scheduleId]);
        if ($valid->fetch()) $ins->execute([$sectionId, $pid]);
    }
}

// Section names must be unique within a schedule (ignoring case and stray spaces).
function sectionNameTaken(PDO $conn, $scheduleId, $name, $excludeId = null) {
    $sql = "SELECT id FROM sections WHERE schedule_id = ? AND LOWER(TRIM(section_name)) = LOWER(?)";
    $params = [$scheduleId, trim($name)];
    if ($excludeId) { $sql .= " AND id != ?"; $params[] = $excludeId; }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetch();
}
function rejectDuplicateSection($name) {
    http_response_code(409);
    echo json_encode(['error' => 'A section named "' . $name . '" already exists. Each section needs its own name.']);
    exit;
}

if ($method === 'GET') {
    $scheduleId = $_GET['schedule_id'] ?? null;
    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    $stmt = $conn->prepare(
        "SELECT sec.id, sec.department_id, sec.section_name, sec.program, sec.year_level, sec.window_start, sec.window_end, sec.break_start, sec.break_end, d.name AS department_name
         FROM sections sec
         LEFT JOIN departments d ON d.id = sec.department_id
         WHERE sec.schedule_id = ?
         ORDER BY d.name IS NULL, d.name, sec.section_name"
    );
    $stmt->execute([$scheduleId]);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach each section's curriculum (required subject_ids for this term)
    // so the auto-scheduler and UI both know what still needs a class.
    $subjStmt = $conn->prepare("SELECT subject_id FROM section_subjects WHERE section_id = ?");
    $patStmt = $conn->prepare("SELECT pattern_id FROM section_patterns WHERE section_id = ?");
    foreach ($sections as &$sec) {
        $sec['id'] = (int) $sec['id'];
        $sec['department_id'] = $sec['department_id'] !== null ? (int) $sec['department_id'] : null;
        $subjStmt->execute([$sec['id']]);
        $sec['subject_ids'] = array_map('intval', array_column($subjStmt->fetchAll(PDO::FETCH_ASSOC), 'subject_id'));
        $patStmt->execute([$sec['id']]);
        $sec['pattern_ids'] = array_map('intval', array_column($patStmt->fetchAll(PDO::FETCH_ASSOC), 'pattern_id'));
    }
    unset($sec);

    echo json_encode(['success' => true, 'sections' => $sections]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $scheduleId = $data['schedule_id'] ?? null;
    $departmentId = !empty($data['department_id']) ? (int) $data['department_id'] : null;
    $sectionName = trim($data['section_name'] ?? '');
    $program = trim($data['program'] ?? '');
    $yearLevel = trim($data['year_level'] ?? '');
    $subjectIds = $data['subject_ids'] ?? [];
    $patternIds = $data['pattern_ids'] ?? [];
    $windowStart = normalizeTime($data['window_start'] ?? '');
    $windowEnd = normalizeTime($data['window_end'] ?? '');
    [$breakStart, $breakEnd] = parseBreak($data);

    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    if (($windowStart === null) !== ($windowEnd === null) || ($windowStart !== null && $windowStart >= $windowEnd)) {
        http_response_code(400);
        echo json_encode(['error' => 'Set both a start and an end time, with the end after the start (or leave both blank).']);
        exit;
    }

    if (!$sectionName) {
        http_response_code(400);
        echo json_encode(['error' => 'Section name is required.']);
        exit;
    }

    if (sectionNameTaken($conn, $scheduleId, $sectionName)) rejectDuplicateSection($sectionName);

    $stmt = $conn->prepare("INSERT INTO sections (schedule_id, department_id, section_name, program, year_level, window_start, window_end, break_start, break_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    try {
        $stmt->execute([$scheduleId, $departmentId, $sectionName, $program, $yearLevel, $windowStart, $windowEnd, $breakStart, $breakEnd]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') rejectDuplicateSection($sectionName); // unique index caught a race
        throw $e;
    }
    $sectionId = $conn->lastInsertId();
    syncSectionPatterns($conn, $sectionId, $scheduleId, $patternIds);

    if (is_array($subjectIds) && count($subjectIds) > 0) {
        $subjStmt = $conn->prepare("INSERT IGNORE INTO section_subjects (section_id, subject_id) VALUES (?, ?)");
        foreach ($subjectIds as $subjectId) {
            $subjStmt->execute([$sectionId, (int) $subjectId]);
        }
    }

    echo json_encode(['success' => true, 'id' => $sectionId]);
    exit;
}

// Update a section. Always updates its day/night rules; also updates the
// name/department/program/year when section_name is sent, and replaces the
// curriculum when subject_ids is sent.
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;

    $stmt = $conn->prepare("SELECT schedule_id FROM sections WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);

    $windowStart = normalizeTime($data['window_start'] ?? '');
    $windowEnd = normalizeTime($data['window_end'] ?? '');
    if (($windowStart === null) !== ($windowEnd === null) || ($windowStart !== null && $windowStart >= $windowEnd)) {
        http_response_code(400);
        echo json_encode(['error' => 'Set both a start and an end time, with the end after the start (or leave both blank).']);
        exit;
    }

    if (array_key_exists('section_name', $data)) {
        $name = trim($data['section_name']);
        if (!$name) { http_response_code(400); echo json_encode(['error' => 'Section name is required.']); exit; }
        if (sectionNameTaken($conn, $row['schedule_id'], $name, $id)) rejectDuplicateSection($name);
        $deptId = !empty($data['department_id']) ? (int) $data['department_id'] : null;
        try {
            $conn->prepare("UPDATE sections SET section_name = ?, department_id = ?, program = ?, year_level = ? WHERE id = ?")
                 ->execute([$name, $deptId, trim($data['program'] ?? ''), trim($data['year_level'] ?? ''), $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') rejectDuplicateSection($name);
            throw $e;
        }
    }

    $conn->prepare("UPDATE sections SET window_start = ?, window_end = ? WHERE id = ?")
         ->execute([$windowStart, $windowEnd, $id]);
    if (array_key_exists('break_start', $data) || array_key_exists('break_end', $data)) {
        [$breakStart, $breakEnd] = parseBreak($data);
        $conn->prepare("UPDATE sections SET break_start = ?, break_end = ? WHERE id = ?")
             ->execute([$breakStart, $breakEnd, $id]);
    }
    syncSectionPatterns($conn, $id, $row['schedule_id'], $data['pattern_ids'] ?? []);

    if (array_key_exists('subject_ids', $data) && is_array($data['subject_ids'])) {
        $conn->prepare("DELETE FROM section_subjects WHERE section_id = ?")->execute([$id]);
        $ins = $conn->prepare("INSERT IGNORE INTO section_subjects (section_id, subject_id) VALUES (?, ?)");
        foreach ($data['subject_ids'] as $sid) { $ins->execute([$id, (int) $sid]); }
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $data);
    $id = $data['id'] ?? null;

    $stmt = $conn->prepare("SELECT schedule_id FROM sections WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);

    $conn->prepare("DELETE FROM sections WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
