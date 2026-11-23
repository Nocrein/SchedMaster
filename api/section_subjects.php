<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule_access.php';

requireAdmin();

$conn = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

function getSectionSchedule(PDO $conn, $sectionId) {
    $stmt = $conn->prepare("SELECT schedule_id FROM sections WHERE id = ?");
    $stmt->execute([$sectionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Section not found.']); exit; }
    return $row['schedule_id'];
}

// POST replaces the full curriculum for one section (sync, not append).
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $sectionId = $data['section_id'] ?? null;
    $subjectIds = $data['subject_ids'] ?? [];

    if (!$sectionId) {
        http_response_code(400);
        echo json_encode(['error' => 'section_id is required.']);
        exit;
    }

    $scheduleId = getSectionSchedule($conn, $sectionId);
    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    $conn->prepare("DELETE FROM section_subjects WHERE section_id = ?")->execute([$sectionId]);

    if (is_array($subjectIds) && count($subjectIds) > 0) {
        $stmt = $conn->prepare("INSERT IGNORE INTO section_subjects (section_id, subject_id) VALUES (?, ?)");
        foreach ($subjectIds as $subjectId) {
            $stmt->execute([$sectionId, (int) $subjectId]);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
