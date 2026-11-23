<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';

$_SESSION = [];
session_destroy();

echo json_encode(['success' => true]);
