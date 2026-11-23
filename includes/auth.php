<?php
// Include this at the top of any admin-only page or API endpoint.
// It stops the request cold if there's no logged-in admin session.

// Role of the logged-in user. Sessions that were created before roles existed
// were always admins, so a missing role means admin.
function currentRole() {
    return $_SESSION['role'] ?? 'admin';
}

// For every endpoint that builds or edits schedule data: logged in AND an admin.
// Teachers and students get a 403 even though they have a valid login.
function requireAdmin() {
    requireLogin();
    if (currentRole() !== 'admin') {
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => 'Only admins can do that.']);
            exit;
        }
        header('Location: login.html');
        exit;
    }
}

function requireLogin() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['user_id'])) {
        // If called from an API endpoint (expects JSON)
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'You must be logged in.']);
            exit;
        }
        // Otherwise redirect to login page (relative, so it works under any folder name)
        header('Location: login.html');
        exit;
    }
}
