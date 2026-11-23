<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule_access.php';

requireAdmin();

$conn = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

function getInstructorSchedule(PDO $conn, $instructorId) {
    $stmt = $conn->prepare("SELECT schedule_id FROM instructors WHERE id = ?");
    $stmt->execute([$instructorId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Instructor not found.']); exit; }
    return $row['schedule_id'];
}

// POST replaces the full qualification list for one instructor (sync,
// not append) — simplest mental model for the admin: "this is now the
// complete list of subjects this person can teach."
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $instructorId = $data['instructor_id'] ?? null;
    $subjectIds = $data['subject_ids'] ?? [];

    if (!$instructorId) {
        http_response_code(400);
        echo json_encode(['error' => 'instructor_id is required.']);
        exit;
    }

    $scheduleId = getInstructorSchedule($conn, $instructorId);
    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    $conn->prepare("DELETE FROM instructor_subjects WHERE instructor_id = ?")->execute([$instructorId]);

    if (is_array($subjectIds) && count($subjectIds) > 0) {
        $stmt = $conn->prepare("INSERT IGNORE INTO instructor_subjects (instructor_id, subject_id) VALUES (?, ?)");
        foreach ($subjectIds as $subjectId) {
            $stmt->execute([$instructorId, (int) $subjectId]);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
