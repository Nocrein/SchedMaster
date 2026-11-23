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

    $stmt = $conn->prepare("SELECT id, name FROM departments WHERE schedule_id = ? ORDER BY name");
    $stmt->execute([$scheduleId]);
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($departments as &$d) { $d['id'] = (int) $d['id']; }
    unset($d);
    echo json_encode(['success' => true, 'departments' => $departments]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $scheduleId = $data['schedule_id'] ?? null;
    $name = trim($data['name'] ?? '');

    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    if (!$name) {
        http_response_code(400);
        echo json_encode(['error' => 'Department name is required.']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO departments (schedule_id, name) VALUES (?, ?)");
    $stmt->execute([$scheduleId, $name]);

    echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);
    exit;
}

if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;
    $stmt = $conn->prepare("SELECT schedule_id FROM departments WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);
    $name = trim($data['name'] ?? '');
    if (!$name) { http_response_code(400); echo json_encode(['error' => 'Department name is required.']); exit; }
    $conn->prepare("UPDATE departments SET name = ? WHERE id = ?")->execute([$name, $id]);
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $data);
    $id = $data['id'] ?? null;

    $stmt = $conn->prepare("SELECT schedule_id FROM departments WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);

    // Deleting a department does NOT delete its subjects/sections -- they
    // just fall back to "no department" (common/general) via ON DELETE
    // SET NULL, so nothing downstream silently disappears.
    $conn->prepare("DELETE FROM departments WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
