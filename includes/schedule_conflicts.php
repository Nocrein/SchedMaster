<?php
/**
 * Two assignments conflict if they share at least one day (via their
 * patterns' pattern_days) AND their time ranges overlap.
 * Standard interval overlap test: startA < endB AND endA > startB.
 *
 * Checks instructor, section, and room separately so callers can tell
 * the admin exactly what collided instead of one generic "conflict"
 * message. $excludeId lets an update skip comparing a row against itself.
 *
 * Returns an assoc array like ['instructor' => ['Juan Dela Cruz'], ...]
 * -- empty array means no conflicts.
 */
function findConflicts(PDO $conn, $scheduleId, $patternId, $startTime, $endTime, $instructorId, $sectionId, $room, $excludeId = null) {
    $conflicts = [];

    $checks = [
        'instructor' => ['column' => 'ca.instructor_id', 'value' => $instructorId, 'label' => 'i.name', 'join' => 'JOIN instructors i ON i.id = ca.instructor_id'],
        'section'    => ['column' => 'ca.section_id',    'value' => $sectionId,    'label' => 'sec.section_name', 'join' => 'JOIN sections sec ON sec.id = ca.section_id'],
        'room'       => ['column' => 'ca.room',           'value' => $room,         'label' => 'ca.room', 'join' => ''],
    ];

    foreach ($checks as $type => $check) {
        $sql = "SELECT DISTINCT ca.id, {$check['label']} AS label
                FROM class_assignments ca
                JOIN pattern_days pd_existing ON pd_existing.pattern_id = ca.pattern_id
                JOIN pattern_days pd_new ON pd_new.day_of_week = pd_existing.day_of_week
                {$check['join']}
                WHERE pd_new.pattern_id = ?
                  AND ca.schedule_id = ?
                  AND {$check['column']} = ?
                  AND ca.start_time < ?
                  AND ca.end_time > ?";
        $params = [$patternId, $scheduleId, $check['value'], $endTime, $startTime];

        if ($excludeId) {
            $sql .= " AND ca.id != ?";
            $params[] = $excludeId;
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $conflicts[$type] = array_column($rows, 'label');
        }
    }

    return $conflicts;
}

/**
 * A section's mandatory break (usually lunch). Returns a readable label such
 * as "12:00 PM - 1:00 PM" when the proposed class time overlaps the section's
 * break, or null when it's fine (or the section has no break).
 * Times are "HH:MM" or "HH:MM:SS".
 */
function findBreakConflict(PDO $conn, $sectionId, $startTime, $endTime) {
    $stmt = $conn->prepare("SELECT break_start, break_end FROM sections WHERE id = ?");
    $stmt->execute([$sectionId]);
    $sec = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sec || !$sec['break_start'] || !$sec['break_end']) return null;

    $norm = fn($t) => substr($t, 0, 5);
    if ($norm($startTime) < $norm($sec['break_end']) && $norm($endTime) > $norm($sec['break_start'])) {
        $fmt = function ($t) {
            [$h, $m] = array_map('intval', explode(':', $t));
            return sprintf('%d:%02d %s', (($h + 11) % 12) + 1, $m, $h >= 12 ? 'PM' : 'AM');
        };
        return $fmt($sec['break_start']) . ' - ' . $fmt($sec['break_end']);
    }
    return null;
}
