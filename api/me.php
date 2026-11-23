<?php
// Admin pages call this to confirm the session. Teachers/students are logged
// in too, but this answers 403 for them unless ?any=1 is passed, so an admin
// page bounces them back to the login screen.
require_once __DIR__ . '/../includes/api_bootstrap.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['loggedIn' => false]);
    exit;
}

$role = $_SESSION['role'] ?? 'admin';
if ($role !== 'admin' && empty($_GET['any'])) {
    http_response_code(403);
    echo json_encode(['loggedIn' => true, 'role' => $role, 'error' => 'Admins only.']);
    exit;
}

echo json_encode([
    'loggedIn' => true,
    'user' => ['id' => $_SESSION['user_id'], 'name' => $_SESSION['user_name'], 'role' => $role]
]);
