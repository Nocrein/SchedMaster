<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule_access.php';

requireAdmin();

$conn = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Patterns are auto-seeded with 3 defaults when a schedule is created
// (see create_schedule.php: MWF, TTh, Night (6-day)), but schools change
// over time -- some sections move to TTHS, night programs may drop from
// 6 days/week to 5, etc. -- so admins can add/remove custom patterns here.
if ($method === 'GET') {
    $scheduleId = $_GET['schedule_id'] ?? null;
    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    $stmt = $conn->prepare("SELECT id, name FROM time_patterns WHERE schedule_id = ? ORDER BY id");
    $stmt->execute([$scheduleId]);
    $patterns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attach each pattern's days so the frontend can show "MWF" -> Mon/Wed/Fri
    $dayStmt = $conn->prepare("SELECT day_of_week FROM pattern_days WHERE pattern_id = ? ORDER BY day_of_week");
    foreach ($patterns as &$p) {
        $dayStmt->execute([$p['id']]);
        $p['days'] = array_map('intval', array_column($dayStmt->fetchAll(PDO::FETCH_ASSOC), 'day_of_week'));
    }
    unset($p);

    echo json_encode(['success' => true, 'patterns' => $patterns]);
    exit;
}

// Create a new named pattern, e.g. name="TTHS", days=[2,4,6]
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $scheduleId = $data['schedule_id'] ?? null;
    $name = trim($data['name'] ?? '');
    $days = $data['days'] ?? [];

    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    if (!$name) {
        http_response_code(400);
        echo json_encode(['error' => 'Pattern name is required (e.g. "TTHS").']);
        exit;
    }
    if (!is_array($days) || count($days) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Select at least one day of the week.']);
        exit;
    }

    $patternStmt = $conn->prepare("INSERT INTO time_patterns (schedule_id, name) VALUES (?, ?)");
    $patternStmt->execute([$scheduleId, $name]);
    $patternId = $conn->lastInsertId();

    $dayStmt = $conn->prepare("INSERT INTO pattern_days (pattern_id, day_of_week) VALUES (?, ?)");
    foreach (array_unique(array_map('intval', $days)) as $day) {
        if ($day >= 1 && $day <= 7) {
            $dayStmt->execute([$patternId, $day]);
        }
    }

    echo json_encode(['success' => true, 'id' => $patternId]);
    exit;
}

// Replace a pattern's day set in place, e.g. shrinking "Night" from
// 6 days/week to 5 -- existing class_assignments keep pointing at the
// same pattern_id, so their schedules update automatically.
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $patternId = $data['id'] ?? null;
    $name = trim($data['name'] ?? '');
    $days = $data['days'] ?? [];

    $stmt = $conn->prepare("SELECT schedule_id FROM time_patterns WHERE id = ?");
    $stmt->execute([$patternId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Pattern not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);

    if (!is_array($days) || count($days) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Select at least one day of the week.']);
        exit;
    }

    if ($name) {
        $conn->prepare("UPDATE time_patterns SET name = ? WHERE id = ?")->execute([$name, $patternId]);
    }

    $conn->prepare("DELETE FROM pattern_days WHERE pattern_id = ?")->execute([$patternId]);
    $dayStmt = $conn->prepare("INSERT INTO pattern_days (pattern_id, day_of_week) VALUES (?, ?)");
    foreach (array_unique(array_map('intval', $days)) as $day) {
        if ($day >= 1 && $day <= 7) {
            $dayStmt->execute([$patternId, $day]);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $data);
    $id = $data['id'] ?? null;

    $stmt = $conn->prepare("SELECT schedule_id FROM time_patterns WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);

    // Refuse to delete a pattern that's still in use, so class_assignments
    // never end up pointing at a pattern_id that no longer exists.
    $inUse = $conn->prepare("SELECT COUNT(*) FROM class_assignments WHERE pattern_id = ?");
    $inUse->execute([$id]);
    if ($inUse->fetchColumn() > 0) {
        http_response_code(409);
        echo json_encode(['error' => 'This pattern is still used by one or more class assignments. Reassign or delete those first.']);
        exit;
    }

    $conn->prepare("DELETE FROM time_patterns WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
