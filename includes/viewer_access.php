<?php
// Who is looking at a student/teacher page, and what may they see?
//
//   admin   -> the schedule's owner: sees everything
//   locked  -> an approved teacher (on teacher.php) or student (on view.php)
//              of THIS schedule: sees only their own instructor/section
//   open    -> anyone, because the admin left "public links" on (the default)
//   denied  -> links are switched off and this visitor isn't allowed in
//
// Never trusts the session for links: the instructor/section is read fresh
// from the database, so removing or re-linking an account takes effect at once.

function viewerContext(PDO $conn, array $schedule, $page) {
    // Don't create a session (and cookie) for anonymous visitors.
    if (session_status() === PHP_SESSION_NONE && isset($_COOKIE[session_name()])) {
        session_start();
    }

    $ctx = ['mode' => 'denied', 'lock_id' => null, 'name' => null, 'role' => null, 'logged_in' => false];

    $uid = $_SESSION['user_id'] ?? null;
    if ($uid) {
        $u = $conn->prepare("SELECT name, role, status, schedule_id, instructor_id, section_id FROM users WHERE id = ?");
        $u->execute([$uid]);
        $user = $u->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $ctx['logged_in'] = true;
            $ctx['name'] = $user['name'];
            $ctx['role'] = $user['role'];

            if ($user['role'] === 'admin') {
                $own = $conn->prepare("SELECT 1 FROM schedules WHERE id = ? AND admin_id = ?");
                $own->execute([$schedule['id'], $uid]);
                if ($own->fetch()) { $ctx['mode'] = 'admin'; return $ctx; }
            } elseif ($user['status'] === 'approved' && (int) $user['schedule_id'] === (int) $schedule['id']) {
                if ($user['role'] === 'teacher' && $page === 'teacher') {
                    $ctx['mode'] = 'locked';
                    $ctx['lock_id'] = $user['instructor_id'] ? (int) $user['instructor_id'] : 0;
                    return $ctx;
                }
                if ($user['role'] === 'student' && $page === 'student') {
                    $ctx['mode'] = 'locked';
                    $ctx['lock_id'] = $user['section_id'] ? (int) $user['section_id'] : 0;
                    return $ctx;
                }
            }
        }
    }

    // public_enabled is missing on a database that hasn't upgraded yet -> treat as on.
    if (!isset($schedule['public_enabled']) || (int) $schedule['public_enabled'] === 1) {
        $ctx['mode'] = 'open';
    }
    return $ctx;
}

function viewerEsc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Slim "Signed in as … · Log out" strip, shown only to logged-in visitors. */
function viewerBarHtml(array $ctx) {
    if (!$ctx['logged_in']) return '';
    $label = ['admin' => 'Admin', 'teacher' => 'Teacher', 'student' => 'Student'][$ctx['role']] ?? '';
    return '<div class="viewer-bar" style="max-width:1100px;margin:0 auto 10px;display:flex;justify-content:flex-end;gap:10px;align-items:center;font:13px \'Segoe UI\',Arial,sans-serif;color:#555;">'
        . '<span>Signed in as <strong>' . viewerEsc($ctx['name']) . '</strong> (' . $label . ')</span>'
        . ($ctx['role'] === 'admin' ? '<a href="../views/dashboard.html" style="color:#146b39;">Dashboard</a>' : '')
        . '<a href="#" onclick="fetch(\'../api/logout.php\',{method:\'POST\'}).then(function(){location.href=\'../views/login.html\'});return false;" style="color:#146b39;">Log out</a>'
        . '</div><style>@media print{.viewer-bar{display:none !important}}</style>';
}

/** Full-page message for denied / unlinked visitors. Ends the request. */
function viewerStop($title, $message, $showLogin = true) {
    http_response_code($showLogin ? 403 : 200);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>' . viewerEsc($title) . '</title></head>'
       . '<body style="font-family:\'Segoe UI\',Arial,sans-serif;background:#eef1ee;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:16px;">'
       . '<div style="background:#fff;border:1px solid #cfd8cf;border-radius:12px;padding:28px 32px;max-width:420px;text-align:center;box-shadow:0 2px 10px rgba(0,0,0,.08)">'
       . '<div style="font-size:34px;">🔒</div><h2 style="margin:8px 0;">' . viewerEsc($title) . '</h2>'
       . '<p style="color:#555;line-height:1.5;">' . viewerEsc($message) . '</p>'
       . ($showLogin ? '<a href="../views/login.html" style="display:inline-block;margin-top:8px;background:#1c8a4a;color:#fff;padding:9px 20px;border-radius:6px;text-decoration:none;">Log in</a>' : '')
       . '</div></body></html>';
    exit;
}
