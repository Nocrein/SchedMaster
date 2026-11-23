<?php
// Public, no-login schedule viewer. Anyone with the link (public_slug)
// can view -- this is intentional (it's meant to be shared with faculty
// and students), so there is NO requireLogin() here and no session check.
// It only ever reads data; it can't modify anything.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/viewer_access.php';

$slug = $_GET['s'] ?? '';
if (!$slug) {
    http_response_code(400);
    echo 'Missing schedule link.';
    exit;
}

$conn = getDbConnection();

$stmt = $conn->prepare("SELECT * FROM schedules WHERE public_slug = ?");
$stmt->execute([$slug]);
$schedule = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$schedule) {
    http_response_code(404);
    echo 'This schedule link is invalid or has been removed.';
    exit;
}

$scheduleId = $schedule['id'];

// Who is looking? (admin / their own student account / public / denied)
$ctx = viewerContext($conn, $schedule, 'student');
if ($ctx['mode'] === 'denied') {
    viewerStop('This schedule is private', $ctx['logged_in']
        ? 'Your account does not have access to this class program.'
        : 'Please log in to see your class program.');
}
$locked = $ctx['mode'] === 'locked';
if ($locked && !$ctx['lock_id']) {
    viewerStop('Account not linked', 'Your account is no longer linked to a section. Please ask your admin.', false);
}

$sectionStmt = $conn->prepare(
    "SELECT sec.id, sec.section_name, sec.program, sec.year_level, sec.break_start, sec.break_end, d.name AS department_name
     FROM sections sec
     LEFT JOIN departments d ON d.id = sec.department_id
     WHERE sec.schedule_id = ?
     ORDER BY d.name IS NULL, d.name, sec.year_level, sec.section_name"
);
$sectionStmt->execute([$scheduleId]);
$sections = $sectionStmt->fetchAll(PDO::FETCH_ASSOC);
if ($locked) {   // a student only ever sees their own section
    $sections = array_values(array_filter($sections, fn($s) => (int) $s['id'] === $ctx['lock_id']));
}

// One query for everything, grouped in PHP -- simpler than N queries per section.
$assignStmt = $conn->prepare(
    "SELECT ca.section_id, ca.start_time, ca.end_time, ca.room,
            subj.subject_code, subj.subject_name, subj.units,
            i.name AS instructor_name,
            tp.id AS pattern_id, tp.name AS pattern_name
     FROM class_assignments ca
     JOIN subjects subj ON subj.id = ca.subject_id
     JOIN instructors i ON i.id = ca.instructor_id
     JOIN time_patterns tp ON tp.id = ca.pattern_id
     WHERE ca.schedule_id = ?
     ORDER BY ca.start_time"
);
$assignStmt->execute([$scheduleId]);
$allAssignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

// pattern_id => [day_of_week, ...]
$patternDaysStmt = $conn->prepare(
    "SELECT tp.id AS pattern_id, pd.day_of_week
     FROM time_patterns tp
     JOIN pattern_days pd ON pd.pattern_id = tp.id
     WHERE tp.schedule_id = ?"
);
$patternDaysStmt->execute([$scheduleId]);
$daysByPattern = [];
foreach ($patternDaysStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $daysByPattern[$row['pattern_id']][] = (int) $row['day_of_week'];
}

$assignmentsBySection = [];
foreach ($allAssignments as $a) {
    $assignmentsBySection[$a['section_id']][] = $a;
}

$dayNames = [1 => 'MONDAY', 2 => 'TUESDAY', 3 => 'WEDNESDAY', 4 => 'THURSDAY', 5 => 'FRIDAY', 6 => 'SATURDAY', 7 => 'SUNDAY'];

function formatTime12($t) {
    [$h, $m] = explode(':', $t);
    $h = (int) $h;
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h12 = (($h + 11) % 12) + 1;
    return $m === '00' ? "{$h12}{$ampm}" : "{$h12}:{$m}{$ampm}";
}

/**
 * Build the per-section grid: which days to show as columns (only days
 * actually used by this section's assignments -- so an MWF section shows
 * 3 columns, a TTHS section shows 3 different columns, and a night
 * section that dropped from 6 days to 5 shows 5), which time rows to
 * show, and a break row wherever there's a gap that crosses midday.
 */
function buildGrid($assignments, $daysByPattern, $dayNames, $breakStart = null, $breakEnd = null) {
    $daysUsed = [];
    $rowSet = [];
    foreach ($assignments as $a) {
        $days = $daysByPattern[$a['pattern_id']] ?? [];
        foreach ($days as $d) { $daysUsed[$d] = true; }
        $rowSet[$a['start_time'] . '-' . $a['end_time']] = [$a['start_time'], $a['end_time']];
    }
    ksort($daysUsed);
    $days = array_keys($daysUsed);

    $rows = array_values($rowSet);
    usort($rows, fn($x, $y) => strcmp($x[0], $y[0]));

    // Insert a break row into any gap that overlaps the section's own lunch
    // break (set on the Sections tab). Sections with no break set fall back
    // to the old guess: any gap overlapping 11:00-14:00.
    $bs = $breakStart ? substr($breakStart, 0, 8) : '11:00:00';
    $be = $breakEnd   ? substr($breakEnd, 0, 8)   : '14:00:00';
    $withBreaks = [];
    for ($i = 0; $i < count($rows); $i++) {
        $withBreaks[] = ['type' => 'class', 'start' => $rows[$i][0], 'end' => $rows[$i][1]];
        if ($i < count($rows) - 1) {
            $gapStart = $rows[$i][1];
            $gapEnd = $rows[$i + 1][0];
            if ($gapEnd > $gapStart) {
                $overlapsLunch = $gapStart < $be && $gapEnd > $bs;
                $withBreaks[] = [
                    'type' => $overlapsLunch ? 'lunch' : 'gap',
                    'start' => $gapStart, 'end' => $gapEnd
                ];
            }
        }
    }

    // A section with a lunch break always shows it, at its real time, even when
    // the section's classes all finish before it (or start after it). Skipped
    // only if a gap row above already covers it, or old data has a class
    // running through it.
    if ($breakStart && $breakEnd && $rows) {
        $bsT = substr($breakStart, 0, 8);
        $beT = substr($breakEnd, 0, 8);
        $already = false;
        foreach ($withBreaks as $r) {
            if ($r['type'] === 'lunch') { $already = true; break; }
            if ($r['type'] === 'class' && $r['start'] < $beT && $r['end'] > $bsT) { $already = true; break; }
        }
        if (!$already) {
            $lunchRow = ['type' => 'lunch', 'start' => $bsT, 'end' => $beT];
            $pos = count($withBreaks);
            foreach ($withBreaks as $idx => $r) {
                if ($r['start'] >= $beT) { $pos = $idx; break; }
            }
            array_splice($withBreaks, $pos, 0, [$lunchRow]);
        }
    }

    // Build lookup: day => start-end => assignment(s)
    $cellLookup = [];
    foreach ($assignments as $a) {
        $days2 = $daysByPattern[$a['pattern_id']] ?? [];
        $key = $a['start_time'] . '-' . $a['end_time'];
        foreach ($days2 as $d) {
            $cellLookup[$d][$key][] = $a;
        }
    }

    return ['days' => $days, 'rows' => $withBreaks, 'cells' => $cellLookup];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($schedule['school_name']) ?> — Class Program</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  :root {
    --sm-green: #1c8a4a;
    --sm-green-dark: #146b39;
    --sm-ink: #1a1a1a;
    --sm-instructor: #c0392b;
    --sm-border: #444;
    --sm-cell-bg: #d9ecd9;
  }
  * { box-sizing: border-box; }
  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    background: #eef1ee;
    margin: 0;
    padding: 24px 12px 60px;
    color: var(--sm-ink);
  }
  .toolbar {
    max-width: 1100px;
    margin: 0 auto 16px;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
  }
  .toolbar button {
    background: var(--sm-green);
    color: #fff;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
  }
  .toolbar-link {
    background: #fff; color: var(--sm-green-dark); border: 1px solid var(--sm-green);
    padding: 8px 16px; border-radius: 6px; font-size: 14px; text-decoration: none;
  }
  .sheet {
    max-width: 1100px;
    margin: 0 auto 32px;
    background: #fff;
    border: 1px solid #ccc;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    padding: 18px 22px 26px;
  }
  .school-header {
    text-align: center;
    margin-bottom: 6px;
  }
  .school-header .school-name {
    font-size: 20px;
    font-weight: 700;
    letter-spacing: 0.3px;
  }
  .banner {
    background: var(--sm-green);
    color: #fff;
    text-align: center;
    font-weight: 700;
    font-size: 18px;
    padding: 6px 0;
    letter-spacing: 1px;
    margin: 10px 0 2px;
  }
  .subbanner {
    text-align: center;
    font-weight: 700;
    font-size: 15px;
    border-bottom: 3px solid var(--sm-green);
    padding-bottom: 6px;
    margin-bottom: 10px;
  }
  .meta-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 12px;
    font-size: 13px;
  }
  .meta-table td {
    border: 1px solid var(--sm-border);
    padding: 5px 8px;
  }
  .meta-table td.label { font-weight: 700; width: 90px; background: #f4f7f4; }

  .grid-wrap { display: flex; gap: 14px; flex-wrap: wrap; align-items: flex-start; }

  .loads-box { flex: 0 0 300px; }
  .loads-box h3 {
    background: var(--sm-green);
    color: #fff;
    font-size: 13px;
    margin: 0;
    padding: 6px 8px;
    text-align: center;
  }
  .loads-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  .loads-table th, .loads-table td { border: 1px solid var(--sm-border); padding: 4px 6px; text-align: left; }
  .loads-table th { background: #f4f7f4; }
  .loads-table td.units, .loads-table th.units { text-align: center; width: 44px; }
  .loads-table tr.total td { font-weight: 700; background: #f4f7f4; }

  .timetable-box { flex: 1 1 480px; min-width: 420px; }
  .timetable {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    table-layout: fixed;
  }
  .timetable th, .timetable td {
    border: 1px solid var(--sm-border);
    padding: 4px 6px;
    text-align: center;
    vertical-align: middle;
  }
  .timetable th { background: #f4f7f4; font-weight: 700; }
  .timetable td.timecol { font-weight: 600; background: #fafafa; white-space: nowrap; width: 116px; font-size: 12px; }
  .timetable td.filled { background: var(--sm-cell-bg); }
  .timetable td.lunch { background: #eee; font-weight: 700; letter-spacing: 0.5px; color: #555; }
  .timetable .subj-code { font-weight: 700; display: block; }
  .timetable .instructor { color: var(--sm-instructor); font-weight: 600; display: block; font-size: 11.5px; }
  .timetable .room { color: #555; font-size: 10.5px; display: block; }

  .section-title {
    font-size: 14px;
    font-weight: 700;
    margin: 4px 0 8px;
  }
  .empty-note {
    text-align: center;
    color: #888;
    padding: 20px 0;
    font-size: 13px;
  }


  /* ---------- phones ---------- */
  .agenda, .view-toggle { display: none; }
  .view-toggle { gap: 0; margin: 4px 0 10px; border: 1px solid var(--sm-green); border-radius: 8px; overflow: hidden; }
  .view-toggle button { flex: 1; background: #fff; color: var(--sm-green-dark); border: 0; padding: 9px 10px; font-size: 14px; font-family: inherit; cursor: pointer; }
  .view-toggle button.on { background: var(--sm-green); color: #fff; font-weight: 600; }
  .ag-day { border: 1px solid var(--sm-border); border-radius: 8px; margin-bottom: 10px; overflow: hidden; background: #fff; }
  .ag-day h4 { margin: 0; padding: 7px 10px; background: #f4f7f4; font-size: 13px; letter-spacing: .5px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--sm-border); }
  .ag-day.today { border-color: var(--sm-green); box-shadow: 0 0 0 2px rgba(28,138,74,.2); }
  .ag-day.today h4 { background: var(--sm-green); color: #fff; }
  .ag-today-tag { display: none; font-size: 11px; font-weight: 600; background: #fff; color: var(--sm-green-dark); padding: 1px 8px; border-radius: 10px; }
  .ag-day.today .ag-today-tag { display: inline-block; }
  .ag-item { display: flex; gap: 10px; padding: 9px 10px; border-bottom: 1px solid #e6e6e6; font-size: 14px; }
  .ag-item:last-child { border-bottom: 0; }
  .ag-time { flex: 0 0 92px; font-weight: 600; font-size: 12.5px; color: #333; line-height: 1.35; }
  .ag-body { min-width: 0; line-height: 1.35; }
  .ag-body strong { display: block; }
  .ag-body .sub { color: #666; font-size: 12.5px; }
  .ag-body .who { color: var(--sm-accent, #c0392b); font-weight: 600; font-size: 13px; }
  .ag-lunch { background: #eee; color: #555; font-weight: 700; letter-spacing: .5px; font-size: 12.5px; justify-content: center; }
  .ag-free { color: #999; font-size: 13px; padding: 9px 10px; }
  @media screen and (max-width: 700px) {
    body { padding: 10px 8px 60px; }
    .toolbar { flex-wrap: wrap; justify-content: stretch; }
    .toolbar > * { flex: 1 1 auto; text-align: center; min-height: 42px; display: inline-flex; align-items: center; justify-content: center; }
    .viewer-bar { flex-wrap: wrap; justify-content: space-between !important; }
    .sheet { padding: 12px 10px 18px; }
    .school-header .school-name { font-size: 17px; }
    .banner { font-size: 16px; }
    .view-toggle { display: flex; }
    .sheet[data-view="agenda"] .timetable { display: none; }
    .sheet[data-view="agenda"] .agenda { display: block; }
    .grid-wrap { flex-direction: column-reverse; flex-wrap: nowrap; gap: 12px; }   /* classes first, subject list after */
    .loads-box, .timetable-box { flex: 1 1 auto; width: 100%; min-width: 0; }
    .timetable-box { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .timetable { min-width: 640px; }
    .timetable th:first-child, .timetable td.timecol { position: sticky; left: 0; z-index: 1; box-shadow: 1px 0 0 var(--sm-border); }
    .timetable th:first-child { background: #f4f7f4; }
    /* label / value pairs, two per row, instead of a 6-column squeeze */
    .meta-table, .meta-table tbody { display: block; }
    .meta-table tr { display: grid; grid-template-columns: 92px 1fr; border: 1px solid var(--sm-border); border-top: 0; }
    .meta-table tr:first-child { border-top: 1px solid var(--sm-border); }
    .meta-table td { display: block; border: 0; border-bottom: 1px solid #ddd; }
    .meta-table td.label { width: auto; }
  }
  @media print { .agenda, .view-toggle { display: none !important; } .timetable-box { overflow: visible; } .timetable { display: table !important; min-width: 0 !important; } }
  @media print {
    body { background: #fff; padding: 0; }
    .toolbar { display: none; }
    .sheet { box-shadow: none; border: none; page-break-after: always; margin: 0; }
  }
</style>
</head>
<body>

<?= viewerBarHtml($ctx) ?>
<div class="toolbar">
  <?php if (!$locked): ?><a class="toolbar-link" href="teacher.php?s=<?= urlencode($slug) ?>">👩‍🏫 Teacher view</a><?php endif; ?>
  <button onclick="window.print()">🖨️ Print / Save as PDF</button>
</div>

<?php if (empty($sections)): ?>
  <div class="sheet">
    <div class="school-header"><span class="school-name"><?= htmlspecialchars($schedule['school_name']) ?></span></div>
    <p class="empty-note">No sections have been set up for this schedule yet.</p>
  </div>
<?php endif; ?>

<?php foreach ($sections as $section):
    $assignments = $assignmentsBySection[$section['id']] ?? [];
    $grid = buildGrid($assignments, $daysByPattern, $dayNames, $section['break_start'], $section['break_end']);

    // Distinct subjects actually taught to this section, for the summary box
    $subjectLoads = [];
    $totalUnits = 0;
    foreach ($assignments as $a) {
        $key = $a['subject_code'] . '|' . $a['subject_name'];
        if (!isset($subjectLoads[$key])) {
            $subjectLoads[$key] = ['code' => $a['subject_code'], 'title' => $a['subject_name'], 'units' => (float) $a['units']];
            $totalUnits += (float) $a['units'];
        }
    }

    // Rooms actually used, for the "RM:" line
    $roomsUsed = array_values(array_unique(array_filter(array_column($assignments, 'room'))));
?>
<div class="sheet" data-view="agenda">
  <div class="school-header">
    <div class="school-name"><?= htmlspecialchars($schedule['school_name']) ?></div>
  </div>
  <div class="banner">CLASS PROGRAM</div>
  <div class="subbanner">
    <?= htmlspecialchars($section['program'] ?: 'PROGRAM N/A') ?>
  </div>

  <table class="meta-table">
    <tr>
      <td class="label">PROGRAM</td>
      <td colspan="3"><?= htmlspecialchars($section['program'] ?: '—') ?></td>
      <td class="label">Level</td>
      <td><?= htmlspecialchars(strtoupper($section['year_level'] ?: '—')) ?></td>
    </tr>
    <tr>
      <td class="label">Section</td>
      <td colspan="3"><?= htmlspecialchars($section['section_name']) ?></td>
      <td class="label">RM:</td>
      <td><?= htmlspecialchars(implode(', ', $roomsUsed) ?: '—') ?></td>
    </tr>
    <?php if ($section['department_name']): ?>
    <tr>
      <td class="label">Department</td>
      <td colspan="<?= $section['break_start'] ? 3 : 5 ?>"><?= htmlspecialchars($section['department_name']) ?></td>
      <?php if ($section['break_start'] && $section['break_end']): ?>
      <td class="label">Lunch</td>
      <td><?= formatTime12($section['break_start']) ?>-<?= formatTime12($section['break_end']) ?></td>
      <?php endif; ?>
    </tr>
    <?php elseif ($section['break_start'] && $section['break_end']): ?>
    <tr>
      <td class="label">Lunch</td>
      <td colspan="5"><?= formatTime12($section['break_start']) ?>-<?= formatTime12($section['break_end']) ?></td>
    </tr>
    <?php endif; ?>
  </table>

  <div class="grid-wrap">
    <div class="loads-box">
      <h3>SUMMARY OF SUBJECT LOADS</h3>
      <table class="loads-table">
        <thead>
          <tr><th>Code</th><th>Descriptive Title</th><th class="units">Units</th></tr>
        </thead>
        <tbody>
          <?php if (empty($subjectLoads)): ?>
            <tr><td colspan="3" style="text-align:center;color:#888;">No classes assigned yet.</td></tr>
          <?php else: ?>
            <?php foreach ($subjectLoads as $s): ?>
              <tr>
                <td><?= htmlspecialchars($s['code'] ?: '—') ?></td>
                <td><?= htmlspecialchars($s['title']) ?></td>
                <td class="units"><?= rtrim(rtrim(number_format($s['units'], 1), '0'), '.') ?></td>
              </tr>
            <?php endforeach; ?>
            <tr class="total">
              <td colspan="2">Total Units/Hours &gt;&gt;&gt;</td>
              <td class="units"><?= rtrim(rtrim(number_format($totalUnits, 1), '0'), '.') ?></td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="timetable-box">
      <?php if (empty($grid['days'])): ?>
        <p class="empty-note">No classes scheduled for this section yet.</p>
      <?php else: ?>
    <div class="view-toggle" role="group" aria-label="Timetable view"><button type="button" data-view="agenda" class="on">By day</button><button type="button" data-view="grid">Weekly grid</button></div>
    <div class="agenda">
      <?php foreach ($grid['days'] as $d):
          $items = []; $sawLunch = false;
          foreach ($grid['rows'] as $row) {
              if ($row['type'] === 'lunch') { $items[] = ['lunch', $row]; continue; }
              if ($row['type'] !== 'class') continue;
              $cells = $grid['cells'][$d][$row['start'] . '-' . $row['end']] ?? [];
              foreach ($cells as $c) $items[] = ['class', $row, $c];
          }
          // keep the lunch line only when the day has classes both before and after it
          $classIdx = array_keys(array_filter($items, fn($i) => $i[0] === 'class'));
          $items = array_values(array_filter($items, function ($i, $k) use ($classIdx) {
              if ($i[0] !== 'lunch') return true;
              return $classIdx && min($classIdx) < $k && max($classIdx) > $k;
          }, ARRAY_FILTER_USE_BOTH));
      ?>
      <div class="ag-day" data-day="<?= (int) $d ?>">
        <h4><span><?= $dayNames[$d] ?></span><span class="ag-today-tag">TODAY</span></h4>
        <?php if (!$items): ?><div class="ag-free">No classes</div><?php endif; ?>
        <?php foreach ($items as $it): ?>
          <?php if ($it[0] === 'lunch'): ?>
            <div class="ag-item ag-lunch">LUNCH BREAK · <?= formatTime12($it[1]['start']) ?>-<?= formatTime12($it[1]['end']) ?></div>
          <?php else: [, $row, $c] = $it; ?>
            <div class="ag-item">
              <div class="ag-time"><?= formatTime12($row['start']) ?><br>– <?= formatTime12($row['end']) ?></div>
              <div class="ag-body">
                <strong><?= htmlspecialchars($c['subject_code'] ?: $c['subject_name']) ?></strong>
                <?php if ($c['subject_code']): ?><span class="sub"><?= htmlspecialchars($c['subject_name']) ?></span><br><?php endif; ?>
                <span class="who"><?= htmlspecialchars($c['instructor_name']) ?></span>
                <?php if ($c['room']): ?><span class="sub"> · <?= htmlspecialchars($c['room']) ?></span><?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
      <table class="timetable">
        <thead>
          <tr>
            <th style="width:116px;">TIME</th>
            <?php foreach ($grid['days'] as $d): ?>
              <th><?= $dayNames[$d] ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($grid['rows'] as $row): ?>
            <?php if ($row['type'] === 'lunch'): ?>
              <tr>
                <td class="timecol"><?= formatTime12($row['start']) ?>-<?= formatTime12($row['end']) ?></td>
                <td class="lunch" colspan="<?= count($grid['days']) ?>">LUNCH BREAK</td>
              </tr>
            <?php elseif ($row['type'] === 'gap'): ?>
              <tr>
                <td class="timecol"><?= formatTime12($row['start']) ?>-<?= formatTime12($row['end']) ?></td>
                <td colspan="<?= count($grid['days']) ?>"></td>
              </tr>
            <?php else: ?>
              <tr>
                <td class="timecol"><?= formatTime12($row['start']) ?>-<?= formatTime12($row['end']) ?></td>
                <?php foreach ($grid['days'] as $d):
                  $key = $row['start'] . '-' . $row['end'];
                  $cellAssignments = $grid['cells'][$d][$key] ?? [];
                ?>
                  <td class="<?= $cellAssignments ? 'filled' : '' ?>">
                    <?php foreach ($cellAssignments as $ca): ?>
                      <span class="subj-code"><?= htmlspecialchars($ca['subject_code'] ?: $ca['subject_name']) ?></span>
                      <span class="instructor"><?= htmlspecialchars($ca['instructor_name']) ?></span>
                      <?php if ($ca['room']): ?><span class="room"><?= htmlspecialchars($ca['room']) ?></span><?php endif; ?>
                    <?php endforeach; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<script>
  // Phone helpers: "By day / Weekly grid" switch, and mark today's card.
  (function () {
    document.querySelectorAll('.view-toggle').forEach(function (t) {
      t.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        t.closest('.sheet').setAttribute('data-view', b.dataset.view);
        t.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      });
    });
    var d = new Date().getDay() || 7;   // 1 = Monday ... 7 = Sunday
    document.querySelectorAll('.ag-day[data-day="' + d + '"]').forEach(function (el) { el.classList.add('today'); });
  })();
</script>
</body>
</html>
