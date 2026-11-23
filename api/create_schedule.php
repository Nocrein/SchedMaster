<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireAdmin(); // stops here with 401 if not logged in

$data = json_decode(file_get_contents('php://input'), true);
$schoolName = trim($data['school_name'] ?? '');

if (!$schoolName) {
    http_response_code(400);
    echo json_encode(['error' => 'School name is required.']);
    exit;
}

$conn = getDbConnection();

// Generate a random, hard-to-guess slug for the public viewer link
$publicSlug = bin2hex(random_bytes(6)); // e.g. "a1b2c3d4e5f6"

$stmt = $conn->prepare(
    "INSERT INTO schedules (admin_id, school_name, public_slug) VALUES (?, ?, ?)"
);
$stmt->execute([$_SESSION['user_id'], $schoolName, $publicSlug]);

$scheduleId = $conn->lastInsertId();

// Seed the 3 default time patterns for this schedule right away,
// since every SchedMaster schedule needs at least these to be usable.
$patterns = [
    'MWF'  => [1, 3, 5],       // Monday, Wednesday, Friday
    'TTh'  => [2, 4],          // Tuesday, Thursday
    'Night (6-day)' => [1, 2, 3, 4, 5, 6], // Monday-Saturday
];

$patternStmt = $conn->prepare("INSERT INTO time_patterns (schedule_id, name) VALUES (?, ?)");
$dayStmt = $conn->prepare("INSERT INTO pattern_days (pattern_id, day_of_week) VALUES (?, ?)");

foreach ($patterns as $name => $days) {
    $patternStmt->execute([$scheduleId, $name]);
    $patternId = $conn->lastInsertId();
    foreach ($days as $day) {
        $dayStmt->execute([$patternId, $day]);
    }
}

echo json_encode([
    'success' => true,
    'schedule' => [
        'id' => $scheduleId,
        'school_name' => $schoolName,
        'public_slug' => $publicSlug
    ]
]);
