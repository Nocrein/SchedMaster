<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);

$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password are required.']);
    exit;
}

$conn = getDbConnection();
$stmt = $conn->prepare("SELECT id, name, password_hash, role, status, schedule_id, instructor_id, section_id FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password.']);
    exit;
}

if ($user['status'] !== 'approved') {
    http_response_code(403);
    echo json_encode(['error' => 'Your account is still waiting for the admin\'s approval. Try again after they approve it.', 'pending' => true]);
    exit;
}

// Where this person should land after logging in.
$home = 'dashboard.html';
if ($user['role'] !== 'admin') {
    $s = $conn->prepare("SELECT public_slug FROM schedules WHERE id = ?");
    $s->execute([$user['schedule_id']]);
    $slug = $s->fetchColumn();
    if (!$slug) {
        http_response_code(403);
        echo json_encode(['error' => 'This account is no longer linked to a schedule. Ask your admin.']);
        exit;
    }
    $home = $user['role'] === 'teacher'
        ? '../public/teacher.php?s=' . urlencode($slug)
        : '../public/view.php?s=' . urlencode($slug);
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['role'] = $user['role'];

echo json_encode(['success' => true, 'home' => $home, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role']]]);
