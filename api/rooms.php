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

    $stmt = $conn->prepare("SELECT id, room_name FROM rooms WHERE schedule_id = ? ORDER BY room_name");
    $stmt->execute([$scheduleId]);
    echo json_encode(['success' => true, 'rooms' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $scheduleId = $data['schedule_id'] ?? null;
    $roomName = trim($data['room_name'] ?? '');

    assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

    if (!$roomName) {
        http_response_code(400);
        echo json_encode(['error' => 'Room name is required.']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO rooms (schedule_id, room_name) VALUES (?, ?)");
    $stmt->execute([$scheduleId, $roomName]);

    echo json_encode(['success' => true, 'id' => $conn->lastInsertId()]);
    exit;
}

if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;
    $stmt = $conn->prepare("SELECT schedule_id, room_name FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);
    $name = trim($data['room_name'] ?? '');
    if (!$name) { http_response_code(400); echo json_encode(['error' => 'Room name is required.']); exit; }

    $dup = $conn->prepare("SELECT id FROM rooms WHERE schedule_id = ? AND LOWER(TRIM(room_name)) = LOWER(?) AND id != ?");
    $dup->execute([$row['schedule_id'], $name, $id]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'A room named "' . $name . '" already exists.']);
        exit;
    }

    // Classes remember the room by name, so keep them pointing at the renamed room.
    $conn->beginTransaction();
    $conn->prepare("UPDATE class_assignments SET room = ? WHERE schedule_id = ? AND room = ?")
         ->execute([$name, $row['schedule_id'], $row['room_name']]);
    $conn->prepare("UPDATE rooms SET room_name = ? WHERE id = ?")->execute([$name, $id]);
    $conn->commit();
    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $data);
    $id = $data['id'] ?? null;

    $stmt = $conn->prepare("SELECT schedule_id FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found.']); exit; }
    assertOwnsSchedule($conn, $row['schedule_id'], $_SESSION['user_id']);

    $conn->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
