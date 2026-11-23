<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule_access.php';

requireAdmin();

// Starts a new semester's schedule from an existing one, instead of
// making the admin re-enter every department, room, subject, and
// instructor from scratch each term.
//
// What's carried forward automatically (these barely change term to
// term): departments, rooms, day-patterns, the subject catalog, and
// instructors with their qualified subjects.
//
// What needs a decision (these DO change term to term): sections move
// up a year level or graduate, so the caller supplies a year_level_map
// (old label -> new label; a null/blank new label means "graduating --
// leave this group out of the new schedule"). Curriculum and class
// assignments are copied only if explicitly requested, since what a
// section is actually taking is exactly the thing most likely to be
// different in the new term.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$sourceId = $data['source_schedule_id'] ?? null;
$schoolName = trim($data['school_name'] ?? '');
$termLabel = trim($data['term_label'] ?? '');
$yearLevelMap = $data['year_level_map'] ?? [];   // { "1st Year": "2nd Year", "4th Year": "" }
$copyCurriculum = !empty($data['copy_curriculum']);
$copyAssignments = !empty($data['copy_assignments']);

$conn = getDbConnection();
assertOwnsSchedule($conn, $sourceId, $_SESSION['user_id']);

if (!$schoolName) {
    http_response_code(400);
    echo json_encode(['error' => 'School name is required.']);
    exit;
}

$conn->beginTransaction();

try {
    // 1. New schedule shell
    $publicSlug = bin2hex(random_bytes(6));
    $conn->prepare("INSERT INTO schedules (admin_id, school_name, term_label, public_slug) VALUES (?, ?, ?, ?)")
         ->execute([$_SESSION['user_id'], $schoolName, $termLabel, $publicSlug]);
    $newScheduleId = $conn->lastInsertId();
    // The new schedule keeps the same one-sitting rule as the one it was copied from.
    $rule = $conn->prepare("SELECT one_sitting_hours FROM schedules WHERE id = ?");
    $rule->execute([$sourceId]);
    $conn->prepare("UPDATE schedules SET one_sitting_hours = ? WHERE id = ?")->execute([$rule->fetchColumn(), $newScheduleId]);

    // 2. Departments -- straight copy, old_id => new_id
    $deptMap = []; // old id => new id
    $stmt = $conn->prepare("SELECT id, name FROM departments WHERE schedule_id = ?");
    $stmt->execute([$sourceId]);
    $insDept = $conn->prepare("INSERT INTO departments (schedule_id, name) VALUES (?, ?)");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $insDept->execute([$newScheduleId, $d['name']]);
        $deptMap[$d['id']] = $conn->lastInsertId();
    }

    // 3. Day-patterns + their days -- straight copy, old_id => new_id
    $patternMap = [];
    $stmt = $conn->prepare("SELECT id, name, auto_single FROM time_patterns WHERE schedule_id = ?");
    $stmt->execute([$sourceId]);
    $insPattern = $conn->prepare("INSERT INTO time_patterns (schedule_id, name, auto_single) VALUES (?, ?, ?)");
    $dayStmt = $conn->prepare("SELECT day_of_week FROM pattern_days WHERE pattern_id = ?");
    $insDay = $conn->prepare("INSERT INTO pattern_days (pattern_id, day_of_week) VALUES (?, ?)");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $insPattern->execute([$newScheduleId, $p['name'], $p['auto_single']]);
        $newPatternId = $conn->lastInsertId();
        $patternMap[$p['id']] = $newPatternId;
        $dayStmt->execute([$p['id']]);
        foreach ($dayStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $insDay->execute([$newPatternId, $row['day_of_week']]);
        }
    }

    // 4. Rooms -- straight copy
    $stmt = $conn->prepare("SELECT room_name FROM rooms WHERE schedule_id = ?");
    $stmt->execute([$sourceId]);
    $insRoom = $conn->prepare("INSERT INTO rooms (schedule_id, room_name) VALUES (?, ?)");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $insRoom->execute([$newScheduleId, $r['room_name']]);
    }

    // 5. Subjects -- straight copy, department remapped, old_id => new_id
    $subjectMap = [];
    $stmt = $conn->prepare("SELECT id, department_id, subject_code, subject_name, units, sitting_mode FROM subjects WHERE schedule_id = ?");
    $stmt->execute([$sourceId]);
    $insSubject = $conn->prepare("INSERT INTO subjects (schedule_id, department_id, subject_code, subject_name, units, sitting_mode) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $newDeptId = $s['department_id'] !== null ? ($deptMap[$s['department_id']] ?? null) : null;
        $insSubject->execute([$newScheduleId, $newDeptId, $s['subject_code'], $s['subject_name'], $s['units'], $s['sitting_mode']]);
        $subjectMap[$s['id']] = $conn->lastInsertId();
    }

    // 6. Instructors + their qualified subjects -- straight copy, old_id => new_id
    $instructorMap = [];
    $stmt = $conn->prepare("SELECT id, name, employment_type, max_teaching_hours FROM instructors WHERE schedule_id = ?");
    $stmt->execute([$sourceId]);
    $insInstructor = $conn->prepare("INSERT INTO instructors (schedule_id, name, employment_type, max_teaching_hours) VALUES (?, ?, ?, ?)");
    $qualStmt = $conn->prepare("SELECT subject_id FROM instructor_subjects WHERE instructor_id = ?");
    $insQual = $conn->prepare("INSERT IGNORE INTO instructor_subjects (instructor_id, subject_id) VALUES (?, ?)");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i) {
        $insInstructor->execute([$newScheduleId, $i['name'], $i['employment_type'], $i['max_teaching_hours']]);
        $newInstructorId = $conn->lastInsertId();
        $instructorMap[$i['id']] = $newInstructorId;
        $qualStmt->execute([$i['id']]);
        foreach ($qualStmt->fetchAll(PDO::FETCH_ASSOC) as $q) {
            if (isset($subjectMap[$q['subject_id']])) {
                $insQual->execute([$newInstructorId, $subjectMap[$q['subject_id']]]);
            }
        }
    }

    // 7. Sections -- promoted per year_level_map. A year level mapped to
    // "" (or missing from the map) is treated as graduating and is left
    // out of the new schedule entirely.
    $sectionMap = []; // old id => new id, only for sections kept
    $skippedSections = 0;
    $stmt = $conn->prepare("SELECT id, department_id, section_name, program, year_level, window_start, window_end, break_start, break_end FROM sections WHERE schedule_id = ?");
    $stmt->execute([$sourceId]);
    $insSection = $conn->prepare("INSERT INTO sections (schedule_id, department_id, section_name, program, year_level, window_start, window_end, break_start, break_end) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $secPatStmt = $conn->prepare("SELECT pattern_id FROM section_patterns WHERE section_id = ?");
    $insSecPat = $conn->prepare("INSERT IGNORE INTO section_patterns (section_id, pattern_id) VALUES (?, ?)");
    $curriStmt = $conn->prepare("SELECT subject_id FROM section_subjects WHERE section_id = ?");
    $insCurri = $conn->prepare("INSERT IGNORE INTO section_subjects (section_id, subject_id) VALUES (?, ?)");
    $sourceSections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sourceSections as $sec) {
        $oldLevel = $sec['year_level'] ?? '';
        $newLevel = array_key_exists($oldLevel, $yearLevelMap) ? trim($yearLevelMap[$oldLevel]) : $oldLevel;
        if ($newLevel === '') {
            $skippedSections++;
            continue; // graduating -- don't carry forward
        }
        $newDeptId = $sec['department_id'] !== null ? ($deptMap[$sec['department_id']] ?? null) : null;
        $insSection->execute([$newScheduleId, $newDeptId, $sec['section_name'], $sec['program'], $newLevel, $sec['window_start'], $sec['window_end'], $sec['break_start'], $sec['break_end']]);
        $newSectionId = $conn->lastInsertId();
        $sectionMap[$sec['id']] = $newSectionId;

        // Carry the section's schedule rules (allowed day-patterns) forward
        $secPatStmt->execute([$sec['id']]);
        foreach ($secPatStmt->fetchAll(PDO::FETCH_ASSOC) as $sp) {
            if (isset($patternMap[$sp['pattern_id']])) {
                $insSecPat->execute([$newSectionId, $patternMap[$sp['pattern_id']]]);
            }
        }

        if ($copyCurriculum) {
            $curriStmt->execute([$sec['id']]);
            foreach ($curriStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (isset($subjectMap[$c['subject_id']])) {
                    $insCurri->execute([$newSectionId, $subjectMap[$c['subject_id']]]);
                }
            }
        }
    }

    // 8. Class assignments -- optional. Only copied for sections that were
    // kept (not graduating); instructor/subject/pattern all remapped.
    $copiedAssignments = 0;
    if ($copyAssignments) {
        $stmt = $conn->prepare(
            "SELECT instructor_id, subject_id, section_id, pattern_id, start_time, end_time, room
             FROM class_assignments WHERE schedule_id = ?"
        );
        $stmt->execute([$sourceId]);
        $insAssign = $conn->prepare(
            "INSERT INTO class_assignments (schedule_id, instructor_id, subject_id, section_id, pattern_id, start_time, end_time, room)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (!isset($sectionMap[$a['section_id']])) continue; // section graduated, skip
            if (!isset($instructorMap[$a['instructor_id']])) continue;
            if (!isset($subjectMap[$a['subject_id']])) continue;
            if (!isset($patternMap[$a['pattern_id']])) continue;
            $insAssign->execute([
                $newScheduleId,
                $instructorMap[$a['instructor_id']],
                $subjectMap[$a['subject_id']],
                $sectionMap[$a['section_id']],
                $patternMap[$a['pattern_id']],
                $a['start_time'], $a['end_time'], $a['room']
            ]);
            $copiedAssignments++;
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'schedule' => ['id' => $newScheduleId, 'school_name' => $schoolName, 'term_label' => $termLabel, 'public_slug' => $publicSlug],
        'summary' => [
            'departments' => count($deptMap),
            'patterns' => count($patternMap),
            'subjects' => count($subjectMap),
            'instructors' => count($instructorMap),
            'sections_carried_forward' => count($sectionMap),
            'sections_graduated' => $skippedSections,
            'assignments_copied' => $copiedAssignments,
        ]
    ]);
} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'Could not clone schedule: ' . $e->getMessage()]);
}
