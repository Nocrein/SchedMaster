<?php
require_once __DIR__ . '/../includes/api_bootstrap.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schedule_access.php';
require_once __DIR__ . '/../includes/schedule_conflicts.php';

requireAdmin();

$conn = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$scheduleId = $data['schedule_id'] ?? null;
assertOwnsSchedule($conn, $scheduleId, $_SESSION['user_id']);

function minutesToTime($mins) {
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return sprintf('%02d:%02d:00', $h, $m);
}

// Fallback teaching window and slot granularity, used for any section that
// has no window of its own. Each section can override this (e.g. Day
// 7:30-5:00 PM, Night 5:30-9:00 PM) from the Sections tab.
const DAY_START_MIN = 7 * 60 + 30;   // 7:30 AM
const DAY_END_MIN   = 21 * 60;  // 9:00 PM (latest a class may END)
const SLOT_STEP_MIN = 30;       // try a new candidate start every 30 min

function timeToMinutes($t) {
    [$h, $m] = array_map('intval', explode(':', $t));
    return $h * 60 + $m;
}

// --- Load schedule-wide data once -------------------------------------

$sections = $conn->prepare("SELECT id, section_name, window_start, window_end, break_start, break_end FROM sections WHERE schedule_id = ?");
$sections->execute([$scheduleId]);
$sections = $sections->fetchAll(PDO::FETCH_ASSOC);

$subjects = $conn->prepare("SELECT id, subject_name, units, sitting_mode FROM subjects WHERE schedule_id = ?");
$subjects->execute([$scheduleId]);
$subjectsById = [];
foreach ($subjects->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $subjectsById[$s['id']] = $s;
}

// "One sitting" rule: subjects of exactly this many hours (default 3) are placed
// as ONE continuous block on a single day, so the teacher stays in one room.
// 0 turns the rule off. A subject can override it with sitting_mode one/split.
$ruleStmt = $conn->prepare("SELECT one_sitting_hours FROM schedules WHERE id = ?");
$ruleStmt->execute([$scheduleId]);
$oneSittingHours = (float) $ruleStmt->fetchColumn();

$instructors = $conn->prepare("SELECT id, name, max_teaching_hours FROM instructors WHERE schedule_id = ?");
$instructors->execute([$scheduleId]);
$instructorsById = [];
foreach ($instructors->fetchAll(PDO::FETCH_ASSOC) as $i) {
    $i['max_teaching_hours'] = (float) $i['max_teaching_hours'];
    $instructorsById[$i['id']] = $i;
}

// subject_id => [instructor_id, ...] qualified to teach it
$qualStmt = $conn->prepare(
    "SELECT isub.subject_id, isub.instructor_id
     FROM instructor_subjects isub
     JOIN instructors i ON i.id = isub.instructor_id
     WHERE i.schedule_id = ?"
);
$qualStmt->execute([$scheduleId]);
$qualifiedFor = [];
foreach ($qualStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $qualifiedFor[$row['subject_id']][] = $row['instructor_id'];
}

// section_id => [subject_id, ...] this section's curriculum for the term
$curricStmt = $conn->prepare("SELECT section_id, subject_id FROM section_subjects WHERE section_id IN (SELECT id FROM sections WHERE schedule_id = ?)");
$curricStmt->execute([$scheduleId]);
$curriculumBySection = [];
foreach ($curricStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $curriculumBySection[$row['section_id']][] = $row['subject_id'];
}

// patterns with their day counts (how many days/week a pattern meets)
$patternStmt = $conn->prepare(
    "SELECT tp.id, tp.name, tp.auto_single, COUNT(pd.id) AS day_count
     FROM time_patterns tp
     LEFT JOIN pattern_days pd ON pd.pattern_id = tp.id
     WHERE tp.schedule_id = ?
     GROUP BY tp.id, tp.name, tp.auto_single"
);
$patternStmt->execute([$scheduleId]);
$patterns = array_filter($patternStmt->fetchAll(PDO::FETCH_ASSOC), fn($p) => $p['day_count'] > 0);
// Patterns made automatically for one-sitting subjects (auto_single) are kept
// out of the normal pool, so ordinary subjects still use the patterns the
// admin set up and never silently start landing on single days.
$patternsById = [];
foreach ($patterns as $p) { if (!(int) $p['auto_single']) $patternsById[$p['id']] = $p; }

// pattern_id => day_of_week  track how loaded each weekday
// already is for a section so classes spread out instead of piling up.
$patternDays = [];
$pdStmt = $conn->prepare("SELECT pd.pattern_id, pd.day_of_week FROM pattern_days pd JOIN time_patterns tp ON tp.id = pd.pattern_id WHERE tp.schedule_id = ?");
$pdStmt->execute([$scheduleId]);
foreach ($pdStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $patternDays[$row['pattern_id']][] = (int) $row['day_of_week'];
}

// section_id => [pattern_id, ...] the day-patterns each section may use.
// A section with none listed may use any pattern.
$allowedStmt = $conn->prepare("SELECT section_id, pattern_id FROM section_patterns WHERE section_id IN (SELECT id FROM sections WHERE schedule_id = ?)");
$allowedStmt->execute([$scheduleId]);
$allowedPatterns = [];
foreach ($allowedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $allowedPatterns[$row['section_id']][] = (int) $row['pattern_id'];
}

// day_of_week => the single-day pattern for that day (admin-made wins over auto-made)
$singleByDay = [];
foreach ($patterns as $p) {
    if ((int) $p['day_count'] !== 1) continue;
    $dow = $patternDays[$p['id']][0] ?? null;
    if ($dow === null) continue;
    if (!isset($singleByDay[$dow]) || ((int) $singleByDay[$dow]['auto_single'] && !(int) $p['auto_single'])) $singleByDay[$dow] = $p;
}
$createdPatterns = [];
$dayAbbr = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$createSingle = function ($dow) use ($conn, $scheduleId, &$singleByDay, &$patternDays, &$createdPatterns, $dayAbbr) {
    $name = $dayAbbr[$dow];
    $chk = $conn->prepare("SELECT COUNT(*) FROM time_patterns WHERE schedule_id = ? AND name = ?");
    $chk->execute([$scheduleId, $name]);
    if ($chk->fetchColumn()) $name .= ' (1 day)';
    $conn->prepare("INSERT INTO time_patterns (schedule_id, name, auto_single) VALUES (?, ?, 1)")->execute([$scheduleId, $name]);
    $id = (int) $conn->lastInsertId();
    $conn->prepare("INSERT INTO pattern_days (pattern_id, day_of_week) VALUES (?, ?)")->execute([$id, $dow]);
    $row = ['id' => $id, 'name' => $name, 'auto_single' => 1, 'day_count' => 1];
    $singleByDay[$dow] = $row;
    $patternDays[$id] = [$dow];
    $createdPatterns[] = $name;
    return $row;
};

$roomStmt = $conn->prepare("SELECT id, room_name FROM rooms WHERE schedule_id = ?");
$roomStmt->execute([$scheduleId]);
$rooms = $roomStmt->fetchAll(PDO::FETCH_ASSOC);

// Current teaching load per instructor, in hours/week, from assignments
// that already exist (manual or from an earlier auto-run).
$loadStmt = $conn->prepare(
    "SELECT ca.instructor_id,
            SUM(TIME_TO_SEC(TIMEDIFF(ca.end_time, ca.start_time)) / 3600 * pdc.day_count) AS hours
     FROM class_assignments ca
     JOIN (SELECT pattern_id, COUNT(*) AS day_count FROM pattern_days GROUP BY pattern_id) pdc
       ON pdc.pattern_id = ca.pattern_id
     WHERE ca.schedule_id = ?
     GROUP BY ca.instructor_id"
);
$loadStmt->execute([$scheduleId]);
$currentLoad = [];
foreach ($loadStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $currentLoad[$row['instructor_id']] = (float) $row['hours'];
}

// Existing (section, subject) pairs that already have at least one
// assignment -- these are "not missing" and we leave them untouched.
$coveredStmt = $conn->prepare("SELECT DISTINCT section_id, subject_id FROM class_assignments WHERE schedule_id = ?");
$coveredStmt->execute([$scheduleId]);
$covered = [];
foreach ($coveredStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $covered[$row['section_id'] . ':' . $row['subject_id']] = true;
}

// Minutes of class each section already has on each weekday (from existing
// assignments), updated as we place more. Drives the pattern balancing below.
$sectionDayLoad = [];
$existingStmt = $conn->prepare("SELECT section_id, pattern_id, TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 60 AS mins FROM class_assignments WHERE schedule_id = ?");
$existingStmt->execute([$scheduleId]);
foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    foreach ($patternDays[$row['pattern_id']] ?? [] as $d) {
        $sectionDayLoad[$row['section_id']][$d] = ($sectionDayLoad[$row['section_id']][$d] ?? 0) + (float) $row['mins'];
    }
}

// Load existing assignments once. Conflict checks below use this in-memory
// cache instead of issuing three SQL queries for every candidate room/slot.
$assignmentStmt = $conn->prepare(
    "SELECT instructor_id, section_id, pattern_id, start_time, end_time, room
     FROM class_assignments
     WHERE schedule_id = ?"
);
$assignmentStmt->execute([$scheduleId]);
$placedAssignments = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Check instructor, section and room conflicts in memory.
 * Two classes conflict when their patterns share a weekday and their
 * time intervals overlap. This preserves findConflicts()'s rules while
 * avoiding repeated database round-trips during schedule generation.
 */
$hasCachedConflict = function ($patternId, $startTime, $endTime, $instructorId, $sectionId, $room)
    use (&$placedAssignments, &$patternDays) {
    $newDays = $patternDays[$patternId] ?? [];
    if (!$newDays) return true;

    foreach ($placedAssignments as $existing) {
        $existingDays = $patternDays[$existing['pattern_id']] ?? [];
        if (!$existingDays || !array_intersect($newDays, $existingDays)) {
            continue;
        }

        // Standard interval overlap: startA < endB AND endA > startB.
        if ($existing['start_time'] >= $endTime || $existing['end_time'] <= $startTime) {
            continue;
        }

        if ((int) $existing['instructor_id'] === (int) $instructorId
            || (int) $existing['section_id'] === (int) $sectionId
            || (string) $existing['room'] === (string) $room) {
            return true;
        }
    }
    return false;
};

if (empty($sections) || empty($subjectsById) || empty($instructorsById) || empty($rooms) || empty($patterns)) {
    http_response_code(400);
    echo json_encode(['error' => 'Add at least one instructor, section, subject, room, and make sure time patterns exist before auto-generating.']);
    exit;
}

$insertStmt = $conn->prepare(
    "INSERT INTO class_assignments (schedule_id, instructor_id, subject_id, section_id, pattern_id, start_time, end_time, room)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
);

$results = [];
$assignedCount = 0;

foreach ($sections as $section) {
    $subjectIds = $curriculumBySection[$section['id']] ?? [];

    // This section's own rules: which day-patterns it may use, and the
    // time-of-day window its classes must fall inside.
    $windowStartMin = $section['window_start'] ? timeToMinutes($section['window_start']) : DAY_START_MIN;
    $windowEndMin   = $section['window_end']   ? timeToMinutes($section['window_end'])   : DAY_END_MIN;
    // Mandatory break (lunch): no class for this section may overlap it.
    $breakStartMin = ($section['break_start'] && $section['break_end']) ? timeToMinutes($section['break_start']) : null;
    $breakEndMin   = ($section['break_start'] && $section['break_end']) ? timeToMinutes($section['break_end'])   : null;
    $allowedIds = $allowedPatterns[$section['id']] ?? [];
    $sectionPatterns = empty($allowedIds)
        ? $patternsById
        : array_intersect_key($patternsById, array_flip($allowedIds));

    // Place the heaviest subjects first -- they're the hardest to fit.
    usort($subjectIds, function ($a, $b) use ($subjectsById) {
        return ((float) ($subjectsById[$b]['units'] ?? 0)) <=> ((float) ($subjectsById[$a]['units'] ?? 0));
    });

    foreach ($subjectIds as $subjectId) {
        $key = $section['id'] . ':' . $subjectId;
        if (isset($covered[$key])) {
            continue; // already has an assignment -- leave it alone
        }

        $subject = $subjectsById[$subjectId] ?? null;
        if (!$subject) continue;
        $units = (float) $subject['units'];

        $qualifiedIds = $qualifiedFor[$subjectId] ?? [];
        if (empty($qualifiedIds)) {
            $results[] = [
                'section' => $section['section_name'], 'subject' => $subject['subject_name'],
                'status' => 'unassigned', 'reason' => 'No instructor is qualified to teach this subject.'
            ];
            continue;
        }

        if (empty($sectionPatterns)) {
            $results[] = [
                'section' => $section['section_name'], 'subject' => $subject['subject_name'],
                'status' => 'unassigned', 'reason' => 'None of this section\'s allowed day-patterns exist (or have days). Edit its schedule rules on the Sections tab.'
            ];
            continue;
        }

        // One attempt to put this subject on `$pattern` for `$durationHours`-long
        // meetings: first free start time inside the section's window (never
        // across its lunch break), least-loaded qualified instructor, first free room.
        $tryPlace = function ($pattern, $durationHours, $note = null) use (
            &$currentLoad, &$sectionDayLoad, &$covered, &$assignedCount, &$results, &$sawCapacityRoom, &$patternDays,
            $conn, $scheduleId, $section, $subject, $subjectId, $key, $qualifiedIds, $instructorsById, $rooms,
            $windowStartMin, $windowEndMin, $breakStartMin, $breakEndMin, $insertStmt, $hasCachedConflict,
            &$placedAssignments
        ) {
            $durationMin = (int) round($durationHours * 60);
            for ($startMin = $windowStartMin; $startMin + $durationMin <= $windowEndMin; $startMin += SLOT_STEP_MIN) {
                $endMin = $startMin + $durationMin;
                if ($breakStartMin !== null && $startMin < $breakEndMin && $endMin > $breakStartMin) {
                    continue; // would run into this section's lunch break
                }
                $startTime = minutesToTime($startMin);
                $endTime = minutesToTime($endMin);

                // Qualified instructors, least-loaded first, so work is shared out evenly
                $candidates = $qualifiedIds;
                usort($candidates, function ($a, $b) use ($currentLoad) {
                    return ($currentLoad[$a] ?? 0) <=> ($currentLoad[$b] ?? 0);
                });

                foreach ($candidates as $instructorId) {
                    $instructor = $instructorsById[$instructorId];
                    $daysPerWeek = count($patternDays[$pattern['id']] ?? []);
                    $projectedHours = ($currentLoad[$instructorId] ?? 0) + $durationHours;
                    if ($projectedHours > $instructor['max_teaching_hours']) {
                        continue; // would exceed their teaching-hour cap
                    }
                    $sawCapacityRoom = true;

                    foreach ($rooms as $room) {
                        if (!$hasCachedConflict(
                            $pattern['id'], $startTime, $endTime,
                            $instructorId, $section['id'], $room['room_name']
                        )) {
                            $insertStmt->execute([
                                $scheduleId, $instructorId, $subjectId, $section['id'],
                                $pattern['id'], $startTime, $endTime, $room['room_name']
                            ]);
                            // Keep the cache in sync with the database so later
                            // placements in this same build see this new class.
                            $placedAssignments[] = [
                                'instructor_id' => $instructorId,
                                'section_id' => $section['id'],
                                'pattern_id' => $pattern['id'],
                                'start_time' => $startTime,
                                'end_time' => $endTime,
                                'room' => $room['room_name']
                            ];
                            $currentLoad[$instructorId] = $projectedHours;
                            foreach ($patternDays[$pattern['id']] ?? [] as $d) {
                                $sectionDayLoad[$section['id']][$d] = ($sectionDayLoad[$section['id']][$d] ?? 0) + $durationMin;
                            }
                            $covered[$key] = true;
                            $assignedCount++;
                            $results[] = [
                                'section' => $section['section_name'], 'subject' => $subject['subject_name'],
                                'status' => 'assigned', 'instructor' => $instructor['name'],
                                'pattern' => $pattern['name'], 'start_time' => $startTime, 'end_time' => $endTime,
                                'room' => $room['room_name'],
                                'sitting' => $daysPerWeek === 1 ? 'one' : 'split',
                                'note' => $note
                            ];
                            return true;
                        }
                    }
                }
            }
            return false;
        };

        $placed = false;
        $sawCapacityRoom = false; // did we find a qualified instructor with hours left, at least?
        $note = null;

        // ---- ONE SITTING: the whole weekly load as a single continuous block on one day.
        $mode = $subject['sitting_mode'] ?? 'auto';
        $wantOne = $mode === 'one'
            || ($mode === 'auto' && $oneSittingHours > 0 && abs($units - $oneSittingHours) < 0.001);
        if ($wantOne) {
            // Days this section can use = every day of its allowed patterns
            $sectionDays = [];
            foreach ($sectionPatterns as $sp) {
                foreach ($patternDays[$sp['id']] ?? [] as $d) $sectionDays[$d] = true;
            }
            $sectionDays = array_keys($sectionDays);
            // Emptiest day first, so subjects spread across the week
            usort($sectionDays, function ($a, $b) use ($sectionDayLoad, $section) {
                return [$sectionDayLoad[$section['id']][$a] ?? 0, $a] <=> [$sectionDayLoad[$section['id']][$b] ?? 0, $b];
            });
            foreach ($sectionDays as $dow) {
                $single = $singleByDay[$dow] ?? $createSingle($dow);
                if ($tryPlace($single, $units)) { $placed = true; break; }
            }
            if (!$placed) {
                $hrs = rtrim(rtrim(number_format($units, 1), '0'), '.');
                $note = "No room for one {$hrs}-hour sitting, so it was split across days.";
            }
        }

        // ---- NORMAL: split across the section's day-patterns
        if (!$placed) {
            // Rank patterns for THIS subject so classes spread across all of the
            // section's allowed patterns instead of always filling the first one:
            //   1. patterns where units split evenly into half-hour meetings first
            //      (weekly hours stay equal to the units),
            //   2. then the pattern whose days are least loaded for this section,
            //   3. then pattern order.
            $ranked = [];
            foreach ($sectionPatterns as $pattern) {
                $dayCount = (int) $pattern['day_count'];
                $raw = $units / $dayCount;
                $rounded = max(0.5, round($raw * 2) / 2);
                $distortion = abs($rounded * $dayCount - $units) > 0.001 ? 1 : 0;
                $days = $patternDays[$pattern['id']] ?? [];
                $load = 0;
                foreach ($days as $d) { $load += $sectionDayLoad[$section['id']][$d] ?? 0; }
                $avgLoad = count($days) ? $load / count($days) : 0;
                $ranked[] = ['pattern' => $pattern, 'duration' => $rounded, 'distortion' => $distortion, 'load' => $avgLoad];
            }
            usort($ranked, function ($a, $b) {
                return [$a['distortion'], $a['load']] <=> [$b['distortion'], $b['load']];
            });
            foreach ($ranked as $cand) {
                if ($tryPlace($cand['pattern'], $cand['duration'], $note)) { $placed = true; break; }
            }
        }

        if (!$placed) {
            $windowLabel = sprintf('%02d:%02d-%02d:%02d', intdiv($windowStartMin, 60), $windowStartMin % 60, intdiv($windowEndMin, 60), $windowEndMin % 60);
            if ($breakStartMin !== null) {
                $windowLabel .= sprintf(', excluding the %02d:%02d-%02d:%02d break', intdiv($breakStartMin, 60), $breakStartMin % 60, intdiv($breakEndMin, 60), $breakEndMin % 60);
            }
            $reason = $sawCapacityRoom
                ? "No conflict-free time slot or room could be found inside this section's window ($windowLabel) on its allowed day-patterns."
                : 'All qualified instructors are already at their max teaching hours.';
            $results[] = [
                'section' => $section['section_name'], 'subject' => $subject['subject_name'],
                'status' => 'unassigned', 'reason' => $reason
            ];
        }
    }
}

echo json_encode([
    'success' => true,
    'assigned_count' => $assignedCount,
    'unassigned_count' => count(array_filter($results, fn($r) => $r['status'] === 'unassigned')),
    'created_patterns' => $createdPatterns,
    'results' => $results
]);
