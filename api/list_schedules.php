<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdmin();

$conn = getDbConnection();

// Optional ?id= to fetch just one schedule (e.g. for the manage-schedule
// page header, which needs the school_name and public_slug for this one
// schedule rather than the admin's whole list).
$id = $_GET['id'] ?? null;
if ($id) {
    $stmt = $conn->prepare(
        "SELECT id, school_name, public_slug, created_at, one_sitting_hours FROM schedules WHERE id = ? AND admin_id = ?"
    );
    $stmt->execute([$id, $_SESSION['user_id']]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$schedule) {
        http_response_code(404);
        echo json_encode(['error' => 'Schedule not found.']);
        exit;
    }
    $schedule['one_sitting_hours'] = (float) $schedule['one_sitting_hours'];
    echo json_encode(['success' => true, 'schedule' => $schedule]);
    exit;
}

$stmt = $conn->prepare(
    "SELECT id, school_name, public_slug, created_at FROM schedules WHERE admin_id = ? ORDER BY created_at DESC"
);
$stmt->execute([$_SESSION['user_id']]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'schedules' => $schedules]);
