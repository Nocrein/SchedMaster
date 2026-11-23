<?php
// Set (or clear) the mandatory lunch break on many sections at once.
// POST { schedule_id, break_start, break_end, scope }
//   scope = "all" | "day" | "night"   (day = starts before noon)
//   leave break_start/break_end blank to REMOVE the break from that scope.
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule_access.php';

requireAdmin();
$conn = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$scheduleId = $data['schedule_id'] ?? null;
assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

function bt($t) {
    $t = trim((string) $t);
    if (!preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $t, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) return null;
    return sprintf('%02d:%02d:00', $m[1], $m[2]);
}
$bs = bt($data['break_start'] ?? '');
$be = bt($data['break_end'] ?? '');
if (($bs === null) !== ($be === null) || ($bs !== null && $bs >= $be)) {
    http_response_code(400);
    echo json_encode(['error' => 'Set both a break start and end time, with the end after the start.']);
    exit;
}

$scope = $data['scope'] ?? 'all';
$where = 'schedule_id = ?';
if ($scope === 'day')   $where .= " AND window_start IS NOT NULL AND window_start < '12:00:00'";
elseif ($scope === 'night') $where .= " AND window_start IS NOT NULL AND window_start >= '12:00:00'";
elseif ($scope !== 'all') {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown scope.']);
    exit;
}

$stmt = $conn->prepare("UPDATE sections SET break_start = ?, break_end = ? WHERE $where");
$stmt->execute([$bs, $be, $scheduleId]);

// Heads-up: classes that were already scheduled and now sit inside the new break.
$clashes = 0;
if ($bs !== null) {
    $c = $conn->prepare("SELECT COUNT(*) FROM class_assignments ca JOIN sections s ON s.id = ca.section_id
        WHERE ca.schedule_id = ? AND s.break_start IS NOT NULL AND ca.start_time < s.break_end AND ca.end_time > s.break_start");
    $c->execute([$scheduleId]);
    $clashes = (int) $c->fetchColumn();
}

echo json_encode(['success' => true, 'updated' => $stmt->rowCount(), 'existing_clashes' => $clashes]);
