<?php
// Include this FIRST in every api/*.php file, before anything else.
// It guarantees the response is always valid JSON -- even if a PHP warning,
// notice, or fatal error happens -- by hiding HTML error output and
// converting any uncaught error into a proper JSON error response.

// Never show HTML-formatted errors in the response body
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL); // still log everything, just don't print HTML

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Catch fatal errors (e.g. uncaught exceptions, DB errors) and turn them
// into JSON instead of a blank page or HTML error dump.
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
        }
        // Only output if nothing has been echoed yet, to avoid double output.
        // (In practice this covers the common case of a fatal before any echo.)
        echo json_encode([
            'error' => 'Server error: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
    }
});

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    exit;
});
