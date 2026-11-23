<?php
// Call this at the top of any endpoint that receives a schedule_id (directly
// or indirectly). It stops the request if the logged-in admin doesn't own
// that schedule -- prevents Admin A from editing Admin B's data by guessing IDs.

function assertOwnsSchedule(PDO $conn, $scheduleId, $userId) {
    if (!$scheduleId) {
        http_response_code(400);
        echo json_encode(['error' => 'schedule_id is required.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM schedules WHERE id = ? AND admin_id = ?");
    $stmt->execute([$scheduleId, $userId]);

    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have access to this schedule.']);
        exit;
    }
}
