<?php
// Admin: schedule-wide scheduling rules.
//   PUT { schedule_id, one_sitting_hours }
//     one_sitting_hours = 0 turns the rule off; otherwise subjects of exactly
//     that many hours are placed as ONE continuous block on a single day.
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule_access.php';

requireAdmin();
$conn = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
assertOwnsSchedule($conn, $data['schedule_id'] ?? null, $_SESSION['user_id']);

$hours = (float) ($data['one_sitting_hours'] ?? 0);
if ($hours < 0 || $hours > 8) {
    http_response_code(400);
    echo json_encode(['error' => 'Hours must be between 0 (off) and 8.']);
    exit;
}
$hours = round($hours * 2) / 2;   // half-hour steps, like units

$conn->prepare("UPDATE schedules SET one_sitting_hours = ? WHERE id = ?")->execute([$hours, $data['schedule_id']]);
echo json_encode(['success' => true, 'one_sitting_hours' => $hours]);
