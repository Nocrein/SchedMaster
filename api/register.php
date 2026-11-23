<?php
// Three kinds of sign-up:
//   role=admin (default)  -> creates an admin and logs them in (as before)
//   role=teacher          -> needs join_code + instructor_id; waits for approval
//   role=student          -> needs join_code + section_id;    waits for approval
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';

$data = json_decode(file_get_contents('php://input'), true) ?: [];

$name = trim($data['name'] ?? '');
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$role = $data['role'] ?? 'admin';

function fail($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

if (!in_array($role, ['admin', 'teacher', 'student'], true)) fail(400, 'Unknown account type.');
if (!$name || !$email || !$password) fail(400, 'Name, email, and password are all required.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(400, 'Please enter a valid email address.');
if (strlen($password) < 6) fail(400, 'Password must be at least 6 characters.');

$conn = getDbConnection();

$stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) fail(409, 'An account with this email already exists.');

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

if ($role === 'admin') {
    $conn->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)")
         ->execute([$name, $email, $passwordHash]);
    $userId = $conn->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;
    $_SESSION['role'] = 'admin';

    echo json_encode(['success' => true, 'home' => 'dashboard.html', 'user' => ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => 'admin']]);
    exit;
}

// ---- teacher / student: must belong to a schedule via its join code
$code = strtoupper(trim($data['join_code'] ?? ''));
if (!$code) fail(400, 'Enter the join code your admin gave you.');
$stmt = $conn->prepare("SELECT id, student_auto_approve FROM schedules WHERE join_code = ?");
$stmt->execute([$code]);
$sched = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sched) fail(404, 'That join code is not valid. Check it with your admin.');
$scheduleId = $sched['id'];
// Students may skip approval if the admin turned that on. Teachers never do.
$autoApprove = $role === 'student' && (int) $sched['student_auto_approve'] === 1;

$instructorId = null;
$sectionId = null;
if ($role === 'teacher') {
    $instructorId = (int) ($data['instructor_id'] ?? 0);
    $stmt = $conn->prepare("SELECT id FROM instructors WHERE id = ? AND schedule_id = ?");
    $stmt->execute([$instructorId, $scheduleId]);
    if (!$stmt->fetch()) fail(400, 'Please pick your name from the list.');
    // One account per instructor, so nobody can claim someone else's timetable twice.
    $stmt = $conn->prepare("SELECT id FROM users WHERE instructor_id = ?");
    $stmt->execute([$instructorId]);
    if ($stmt->fetch()) fail(409, 'That instructor already has an account. If it is you, ask the admin to reset your password.');
} else {
    $sectionId = (int) ($data['section_id'] ?? 0);
    $stmt = $conn->prepare("SELECT id FROM sections WHERE id = ? AND schedule_id = ?");
    $stmt->execute([$sectionId, $scheduleId]);
    if (!$stmt->fetch()) fail(400, 'Please pick your section from the list.');
}

$conn->prepare("INSERT INTO users (name, email, password_hash, role, status, schedule_id, instructor_id, section_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
     ->execute([$name, $email, $passwordHash, $role, $autoApprove ? 'approved' : 'pending', $scheduleId, $instructorId, $sectionId]);

if ($autoApprove) {
    // Straight in: log them in and send them to their section's page.
    $userId = $conn->lastInsertId();
    $slug = $conn->prepare("SELECT public_slug FROM schedules WHERE id = ?");
    $slug->execute([$scheduleId]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;
    $_SESSION['role'] = 'student';
    echo json_encode(['success' => true, 'home' => '../public/view.php?s=' . urlencode($slug->fetchColumn()), 'user' => ['id' => $userId, 'name' => $name, 'role' => 'student']]);
    exit;
}

// Not logged in: the admin has to approve first.
echo json_encode(['success' => true, 'pending' => true]);
