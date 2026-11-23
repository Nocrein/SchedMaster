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

    $stmt = $conn->prepare(
        "SELECT subj.id, subj.department_id, subj.subject_code, subj.subject_name, subj.units, subj.sitting_mode, d.name AS department_name
         FROM subjects subj
         LEFT JOIN departments d ON d.id = subj.department_id
         WHERE subj.schedule_id = ?
         ORDER BY d.name IS NULL, d.name, subj.subject_code, subj.subject_name"
    );
    $stmt->execute([$scheduleId]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($subjects as &$s) {
        $s['id'] = (int) $s['id'];
        $s['department_id'] = $s['department_id'] !== null ? (int) $s['department_id'] : null;
    }
    unset($s);
    echo json_encode(['success' => true, 'subjects' => $subjects]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $scheduleId = $data['schedule_id'] ?? null;
    $departmentId = !empty($data['department_id']) ? (int) $data['department_id'] : null; // null = common/gen-ed
    $subjectCode = trim($data['subject_code'] ?? '');
    $subjectName = trim($data['subject_name'] ?? '');
    $units = $data['units'] ?? 3.0;
    $mode = in_array($data['sitting_mode'] ?? 'auto', ['auto', 'one', 'split'], true) ? $data['sitting_mode'] : 'auto';

    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    if (!$subjectName) {
        http_response_code(400);
        echo json_encode(['error' => 'Subject name is required.']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO subjects (schedule_id, department_id, subject_code, subject_name, units, sitting_mode) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$scheduleId, $departmentId, $subjectCode, $subjectName, $units, $mode]);

    echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);
    exit;
}

if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;
    $stmt = $conn->prepare("SELECT schedule_id FROM subjects WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);
    $subjectName = trim($data['subject_name'] ?? '');
    $subjectCode = trim($data['subject_code'] ?? '');
    $units = (float) ($data['units'] ?? 0);
    $departmentId = !empty($data['department_id']) ? (int) $data['department_id'] : null;
    if (!$subjectName) { http_response_code(400); echo json_encode(['error' => 'Subject name is required.']); exit; }
    if ($units <= 0) { http_response_code(400); echo json_encode(['error' => 'Units must be greater than 0.']); exit; }
    $conn->prepare("UPDATE subjects SET department_id = ?, subject_code = ?, subject_name = ?, units = ? WHERE id = ?")
         ->execute([$departmentId, $subjectCode, $subjectName, $units, $id]);
    if (array_key_exists('sitting_mode', $data) && in_array($data['sitting_mode'], ['auto', 'one', 'split'], true)) {
        $conn->prepare("UPDATE subjects SET sitting_mode = ? WHERE id = ?")->execute([$data['sitting_mode'], $id]);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $data);
    $id = $data['id'] ?? null;

    $stmt = $conn->prepare("SELECT schedule_id FROM subjects WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);

    $conn->prepare("DELETE FROM subjects WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
