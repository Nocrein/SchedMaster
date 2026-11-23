<?php
// Public, no-login TEACHER viewer. Same idea as view.php (student side):
// anyone with the schedule link can open it, it only reads data.
//
//   teacher.php?s=SLUG              -> faculty directory (pick your name)
//   teacher.php?s=SLUG&i=12         -> one instructor's weekly timetable
//   teacher.php?s=SLUG&all=1        -> every instructor, one page each (print)

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
$scheduleId = (int) $schedule['id'];

// Who is looking? (admin / their own teacher account / public / denied)
$ctx = viewerContext($conn, $schedule, 'teacher');
if ($ctx['mode'] === 'denied') {
    viewerStop('This schedule is private', $ctx['logged_in']
        ? 'Your account does not have access to the teacher schedules.'
        : 'Please log in to see your schedule.');
}
$locked = $ctx['mode'] === 'locked';
if ($locked && !$ctx['lock_id']) {
    viewerStop('Account not linked', 'Your account is no longer linked to an instructor. Please ask your admin.', false);
}

// ---------------------------------------------------------------- data
$instStmt = $conn->prepare("SELECT id, name, employment_type, max_teaching_hours FROM instructors WHERE schedule_id = ? ORDER BY name");
$instStmt->execute([$scheduleId]);
$instructors = $instStmt->fetchAll(PDO::FETCH_ASSOC);

$assignStmt = $conn->prepare(
    "SELECT ca.instructor_id, ca.start_time, ca.end_time, ca.room,
            subj.subject_code, subj.subject_name, subj.units,
            sec.section_name,
            tp.id AS pattern_id, tp.name AS pattern_name
     FROM class_assignments ca
     JOIN subjects subj ON subj.id = ca.subject_id
     JOIN sections sec ON sec.id = ca.section_id
     JOIN time_patterns tp ON tp.id = ca.pattern_id
     WHERE ca.schedule_id = ?
     ORDER BY ca.start_time"
);
$assignStmt->execute([$scheduleId]);
$byInstructor = [];
foreach ($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $byInstructor[$a['instructor_id']][] = $a;
}

$pdStmt = $conn->prepare("SELECT tp.id AS pattern_id, pd.day_of_week FROM time_patterns tp JOIN pattern_days pd ON pd.pattern_id = tp.id WHERE tp.schedule_id = ?");
$pdStmt->execute([$scheduleId]);
$daysByPattern = [];
foreach ($pdStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $daysByPattern[$r['pattern_id']][] = (int) $r['day_of_week'];
}

// The school's lunch break = the most common break set on its sections.
// Used only to label the empty midday row in a teacher's timetable.
$lunch = null;
try {
    $bStmt = $conn->prepare("SELECT break_start, break_end, COUNT(*) AS n FROM sections
        WHERE schedule_id = ? AND break_start IS NOT NULL AND break_end IS NOT NULL
        GROUP BY break_start, break_end ORDER BY n DESC LIMIT 1");
    $bStmt->execute([$scheduleId]);
    $lunch = $bStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) { /* older database without break columns */ }

$dayNames = [1 => 'MONDAY', 2 => 'TUESDAY', 3 => 'WEDNESDAY', 4 => 'THURSDAY', 5 => 'FRIDAY', 6 => 'SATURDAY', 7 => 'SUNDAY'];

// ---------------------------------------------------------------- helpers
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function formatTime12($t) {
    [$h, $m] = explode(':', $t);
    $h = (int) $h;
    $h12 = (($h + 11) % 12) + 1;
    return $m === '00' ? $h12 . ($h >= 12 ? 'PM' : 'AM') : "{$h12}:{$m}" . ($h >= 12 ? 'PM' : 'AM');
}
function toMin($t) { [$h, $m] = explode(':', $t); return (int) $h * 60 + (int) $m; }
function fmtHours($n) { return rtrim(rtrim(number_format($n, 1), '0'), '.') ?: '0'; }

/** Weekly teaching hours = each class's length x the number of days its pattern meets. */
function weeklyHours(array $classes, array $daysByPattern) {
    $total = 0;
    foreach ($classes as $a) {
        $total += (toMin($a['end_time']) - toMin($a['start_time'])) / 60 * count($daysByPattern[$a['pattern_id']] ?? []);
    }
    return $total;
}

/**
 * Build the weekly grid for one instructor. Rows are the gaps between the
 * times that actually occur in their classes; a class that spans several
 * rows is drawn once with a rowspan. Rows with no class on any day become
 * one wide "LUNCH BREAK" (if they overlap the school's lunch) or "Vacant" cell.
 */
function buildTeacherGrid(array $classes, array $daysByPattern, $lunch) {
    $days = [1, 2, 3, 4, 5, 6];
    $placed = []; // day => list of classes
    foreach ($classes as $a) {
        foreach ($daysByPattern[$a['pattern_id']] ?? [] as $d) {
            if ($d === 7 && !in_array(7, $days)) $days[] = 7;
            $placed[$d][] = $a;
        }
    }

    $bounds = [];
    foreach ($classes as $a) { $bounds[$a['start_time']] = true; $bounds[$a['end_time']] = true; }

    // Mark the school's lunch hour inside a free gap, so a long vacant stretch
    // is split into VACANT / LUNCH BREAK / VACANT instead of being one block.
    // (Skipped when a class actually runs through that moment.)
    if ($lunch) {
        $min = min(array_keys($bounds)); $max = max(array_keys($bounds));
        foreach ([$lunch['break_start'], $lunch['break_end']] as $b) {
            if ($b <= $min || $b >= $max) continue;
            $insideClass = false;
            foreach ($classes as $a) { if ($a['start_time'] < $b && $a['end_time'] > $b) { $insideClass = true; break; } }
            if (!$insideClass) $bounds[$b] = true;
        }
    }
    $bounds = array_keys($bounds);
    sort($bounds);

    $rows = [];
    for ($k = 0; $k + 1 < count($bounds); $k++) {
        $rows[] = ['start' => $bounds[$k], 'end' => $bounds[$k + 1]];
    }
    return ['days' => $days, 'rows' => $rows, 'placed' => $placed, 'lunch' => $lunch];
}

function renderTeacherGrid(array $grid, array $dayNames) {
    $days = $grid['days'];
    $rows = $grid['rows'];
    $skip = array_fill_keys($days, 0);
    ob_start();
    echo '<table class="timetable"><thead><tr><th style="width:118px;">TIME</th>';
    foreach ($days as $d) echo '<th>' . $dayNames[$d] . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $ri => $row) {
        // Which class (if any) covers this row on each day?
        $cover = [];
        $anyCovered = false;
        foreach ($days as $d) {
            $cover[$d] = null;
            foreach ($grid['placed'][$d] ?? [] as $a) {
                if ($a['start_time'] <= $row['start'] && $a['end_time'] >= $row['end']) { $cover[$d] = $a; $anyCovered = true; break; }
            }
        }

        echo '<tr><td class="timecol">' . formatTime12($row['start']) . '-' . formatTime12($row['end']) . '</td>';

        if (!$anyCovered) {
            $isLunch = $grid['lunch'] && $row['start'] >= $grid['lunch']['break_start'] && $row['end'] <= $grid['lunch']['break_end'];
            echo '<td class="' . ($isLunch ? 'lunch' : 'vacant') . '" colspan="' . count($days) . '">' . ($isLunch ? 'LUNCH BREAK' : 'VACANT') . '</td></tr>';
            continue;
        }

        foreach ($days as $d) {
            if ($skip[$d] > 0) { $skip[$d]--; continue; }
            $a = $cover[$d];
            if (!$a) { echo '<td></td>'; continue; }
            if ($a['start_time'] !== $row['start']) { echo '<td></td>'; continue; } // safety net for odd data
            $span = 0;
            for ($j = $ri; $j < count($rows) && $rows[$j]['end'] <= $a['end_time']; $j++) { $span++; }
            $span = max(1, $span);
            $skip[$d] = $span - 1;
            echo '<td class="filled" rowspan="' . $span . '">'
               . '<span class="subj-code">' . h($a['subject_code'] ?: $a['subject_name']) . '</span>'
               . '<span class="section">' . h($a['section_name']) . '</span>'
               . ($a['room'] ? '<span class="room">' . h($a['room']) . '</span>' : '')
               . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table>';
    return ob_get_clean();
}

/** Phone-friendly list of the same week: one card per day, classes in time order. */
function renderTeacherAgenda(array $grid, array $dayNames) {
    ob_start();
    echo '<div class="agenda">';
    foreach ($grid['days'] as $d) {
        $list = $grid['placed'][$d] ?? [];
        usort($list, fn($a, $b) => strcmp($a['start_time'], $b['start_time']));
        echo '<div class="ag-day" data-day="' . (int) $d . '"><h4><span>' . $dayNames[$d] . '</span><span class="ag-today-tag">TODAY</span></h4>';
        if (!$list) echo '<div class="ag-free">No classes</div>';
        foreach ($list as $a) {
            echo '<div class="ag-item"><div class="ag-time">' . formatTime12($a['start_time']) . '<br>– ' . formatTime12($a['end_time']) . '</div>'
               . '<div class="ag-body"><strong>' . h($a['subject_code'] ?: $a['subject_name']) . '</strong>'
               . ($a['subject_code'] ? '<span class="sub">' . h($a['subject_name']) . '</span><br>' : '')
               . '<span class="who">' . h($a['section_name']) . '</span>'
               . ($a['room'] ? '<span class="sub"> · ' . h($a['room']) . '</span>' : '')
               . '</div></div>';
        }
        echo '</div>';
    }
    echo '</div>';
    return ob_get_clean();
}

function instructorStats(array $inst, array $classes, array $daysByPattern) {
    $hours = weeklyHours($classes, $daysByPattern);
    $max = (float) $inst['max_teaching_hours'];
    $days = [];
    $sections = [];
    $subjects = [];
    $earliest = null; $latest = null;
    foreach ($classes as $a) {
        foreach ($daysByPattern[$a['pattern_id']] ?? [] as $d) $days[$d] = true;
        $sections[$a['section_name']] = true;
        $subjects[$a['subject_code'] . '|' . $a['subject_name']] = true;
        if ($earliest === null || $a['start_time'] < $earliest) $earliest = $a['start_time'];
        if ($latest === null || $a['end_time'] > $latest) $latest = $a['end_time'];
    }
    return [
        'hours' => $hours, 'max' => $max,
        'pct' => $max > 0 ? min(100, $hours / $max * 100) : 0,
        'over' => $max > 0 && $hours > $max + 0.001,
        'classes' => count($classes), 'sections' => count($sections), 'subjects' => count($subjects),
        'days' => count($days), 'earliest' => $earliest, 'latest' => $latest,
    ];
}

function renderInstructorSheet(array $inst, array $classes, array $daysByPattern, array $dayNames, $lunch, array $schedule) {
    $st = instructorStats($inst, $classes, $daysByPattern);

    // class list rows: grouped by subject+section, ordered by first day then time
    usort($classes, function ($a, $b) use ($daysByPattern) {
        $da = min($daysByPattern[$a['pattern_id']] ?? [9]);
        $db = min($daysByPattern[$b['pattern_id']] ?? [9]);
        return [$da, $a['start_time']] <=> [$db, $b['start_time']];
    });
    ?>
<div class="sheet" data-view="agenda">
  <div class="school-header">
    <div class="school-name"><?= h($schedule['school_name']) ?></div>
    <?php if (!empty($schedule['term_label'])): ?><div class="term"><?= h($schedule['term_label']) ?></div><?php endif; ?>
  </div>
  <div class="banner">FACULTY TEACHING LOAD</div>
  <div class="subbanner"><?= h($inst['name']) ?></div>

  <div class="stat-row">
    <div class="stat"><span class="stat-num"><?= fmtHours($st['hours']) ?><small> / <?= fmtHours($st['max']) ?> hrs</small></span><span class="stat-label">Weekly teaching load</span>
      <div class="bar"><div class="<?= $st['over'] ? 'over' : '' ?>" style="width:<?= round($st['pct']) ?>%"></div></div>
      <?php if ($st['over']): ?><span class="warn">Over the teaching-hour limit</span><?php endif; ?>
    </div>
    <div class="stat"><span class="stat-num"><?= $st['classes'] ?></span><span class="stat-label">Classes</span></div>
    <div class="stat"><span class="stat-num"><?= $st['sections'] ?></span><span class="stat-label">Sections</span></div>
    <div class="stat"><span class="stat-num"><?= $st['days'] ?></span><span class="stat-label">Days on campus</span></div>
  </div>

  <table class="meta-table">
    <tr>
      <td class="label">Instructor</td><td><?= h($inst['name']) ?></td>
      <td class="label">Status</td><td><?= $inst['employment_type'] === 'part-time' ? 'Part-time' : 'Full-time' ?></td>
    </tr>
    <?php if ($st['earliest']): ?>
    <tr>
      <td class="label">Earliest</td><td><?= formatTime12($st['earliest']) ?></td>
      <td class="label">Latest</td><td><?= formatTime12($st['latest']) ?></td>
    </tr>
    <?php endif; ?>
  </table>

  <?php if (!$classes): ?>
    <p class="empty-note">No classes have been assigned to this instructor yet.</p>
  <?php else: ?>
    <?php $tg = buildTeacherGrid($classes, $daysByPattern, $lunch); ?>
    <div class="timetable-box">
      <div class="view-toggle" role="group" aria-label="Timetable view"><button type="button" data-view="agenda" class="on">By day</button><button type="button" data-view="grid">Weekly grid</button></div>
      <?= renderTeacherAgenda($tg, $dayNames) ?>
      <?= renderTeacherGrid($tg, $dayNames) ?>
    </div>

    <h3 class="list-title">CLASS LIST</h3>
    <div class="table-scroll">
    <table class="loads-table">
      <thead><tr><th>Code</th><th>Descriptive Title</th><th>Section</th><th>Days</th><th>Time</th><th>Room</th><th class="units">Units</th></tr></thead>
      <tbody>
      <?php $totalUnits = 0; foreach ($classes as $a): $totalUnits += (float) $a['units']; ?>
        <tr>
          <td><?= h($a['subject_code'] ?: '—') ?></td>
          <td><?= h($a['subject_name']) ?></td>
          <td><?= h($a['section_name']) ?></td>
          <td><?= h($a['pattern_name']) ?></td>
          <td class="nowrap"><?= formatTime12($a['start_time']) ?>-<?= formatTime12($a['end_time']) ?></td>
          <td><?= h($a['room'] ?: '—') ?></td>
          <td class="units"><?= fmtHours((float) $a['units']) ?></td>
        </tr>
      <?php endforeach; ?>
        <tr class="total"><td colspan="6">Total Units &gt;&gt;&gt;</td><td class="units"><?= fmtHours($totalUnits) ?></td></tr>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>
<?php
}

// ---------------------------------------------------------------- which page?
$selectedId = isset($_GET['i']) ? (int) $_GET['i'] : 0;
$showAll = !empty($_GET['all']);
if ($locked) { $selectedId = $ctx['lock_id']; $showAll = false; }   // a teacher only ever sees their own
$selected = null;
foreach ($instructors as $i) { if ((int) $i['id'] === $selectedId) $selected = $i; }

$self = 'teacher.php?s=' . urlencode($slug);
$studentUrl = 'view.php?s=' . urlencode($slug);
$title = $selected ? $selected['name'] . ' — Teaching Load' : 'Faculty Schedules';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= h($schedule['school_name']) ?> — <?= h($title) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  :root {
    --sm-green: #1c8a4a;
    --sm-green-dark: #146b39;
    --sm-ink: #1a1a1a;
    --sm-accent: #c0392b;
    --sm-border: #444;
    --sm-cell-bg: #d9ecd9;
  }
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; background: #eef1ee; margin: 0; padding: 24px 12px 60px; color: var(--sm-ink); }
  .toolbar { max-width: 1100px; margin: 0 auto 16px; display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
  .toolbar .left, .toolbar .right { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .btn { background: var(--sm-green); color: #fff; border: 1px solid var(--sm-green); padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px; text-decoration: none; font-family: inherit; }
  .btn.ghost { background: #fff; color: var(--sm-green-dark); }
  select.pick { padding: 8px 10px; border: 1px solid #bbb; border-radius: 6px; font-size: 14px; background: #fff; max-width: 260px; font-family: inherit; }

  .sheet { max-width: 1100px; margin: 0 auto 32px; background: #fff; border: 1px solid #ccc; box-shadow: 0 2px 8px rgba(0,0,0,0.08); padding: 18px 22px 26px; }
  .school-header { text-align: center; margin-bottom: 6px; }
  .school-name { font-size: 20px; font-weight: 700; letter-spacing: .3px; }
  .term { font-size: 13px; color: #555; margin-top: 2px; }
  .banner { background: var(--sm-green); color: #fff; text-align: center; font-weight: 700; font-size: 18px; padding: 6px 0; letter-spacing: 1px; margin: 10px 0 2px; }
  .subbanner { text-align: center; font-weight: 700; font-size: 16px; border-bottom: 3px solid var(--sm-green); padding-bottom: 6px; margin-bottom: 12px; }

  .stat-row { display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 10px; margin-bottom: 12px; }
  .stat { border: 1px solid var(--sm-border); padding: 8px 10px; background: #f4f7f4; }
  .stat-num { display: block; font-size: 22px; font-weight: 700; line-height: 1.1; }
  .stat-num small { font-size: 13px; font-weight: 600; color: #555; }
  .stat-label { display: block; font-size: 11.5px; color: #555; text-transform: uppercase; letter-spacing: .4px; margin-top: 2px; }
  .bar { height: 7px; background: #dfe6df; border-radius: 4px; margin-top: 7px; overflow: hidden; }
  .bar > div { height: 100%; background: var(--sm-green); }
  .bar > div.over { background: var(--sm-accent); }
  .warn { color: var(--sm-accent); font-size: 12px; font-weight: 600; display: block; margin-top: 4px; }

  .meta-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 13px; }
  .meta-table td { border: 1px solid var(--sm-border); padding: 5px 8px; }
  .meta-table td.label { font-weight: 700; width: 90px; background: #f4f7f4; }

  .timetable-box { margin-bottom: 14px; overflow-x: auto; }
  .timetable { width: 100%; min-width: 560px; border-collapse: collapse; font-size: 12.5px; table-layout: fixed; }
  .timetable th, .timetable td { border: 1px solid var(--sm-border); padding: 4px 6px; text-align: center; vertical-align: middle; }
  .timetable th { background: #f4f7f4; font-weight: 700; font-size: 12px; }
  .timetable td.timecol { font-weight: 600; background: #fafafa; white-space: nowrap; font-size: 12px; }
  .timetable td.filled { background: var(--sm-cell-bg); }
  .timetable td.lunch { background: #eee; font-weight: 700; letter-spacing: .5px; color: #555; }
  .timetable td.vacant { background: repeating-linear-gradient(45deg, #fafafa, #fafafa 6px, #f1f1f1 6px, #f1f1f1 12px); color: #aaa; font-size: 11px; letter-spacing: 1px; }
  .timetable .subj-code { font-weight: 700; display: block; }
  .timetable .section { color: var(--sm-accent); font-weight: 600; display: block; font-size: 11.5px; }
  .timetable .room { color: #555; font-size: 10.5px; display: block; }

  .list-title { background: var(--sm-green); color: #fff; font-size: 13px; margin: 0; padding: 6px 8px; text-align: center; }
  .table-scroll { overflow-x: auto; }
  .loads-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  .loads-table th, .loads-table td { border: 1px solid var(--sm-border); padding: 4px 6px; text-align: left; }
  .loads-table th { background: #f4f7f4; }
  .loads-table .units { text-align: center; width: 52px; }
  .loads-table .nowrap { white-space: nowrap; }
  .loads-table tr.total td { font-weight: 700; background: #f4f7f4; }
  .empty-note { text-align: center; color: #888; padding: 20px 0; font-size: 13px; }

  /* directory */
  .dir-head { max-width: 1100px; margin: 0 auto 14px; }
  .dir-head h1 { font-size: 22px; margin: 0 0 2px; }
  .dir-head p { margin: 0; color: #555; font-size: 14px; }
  .search { width: 100%; max-width: 1100px; display: block; margin: 0 auto 14px; padding: 11px 14px; font-size: 15px; border: 1px solid #bbb; border-radius: 8px; font-family: inherit; }
  .dir-grid { max-width: 1100px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px; }
  .card { background: #fff; border: 1px solid #cfd8cf; border-radius: 10px; padding: 12px 14px; text-decoration: none; color: inherit; display: block; transition: box-shadow .15s, border-color .15s; }
  .card:hover { box-shadow: 0 3px 12px rgba(0,0,0,.12); border-color: var(--sm-green); }
  .card .name { font-weight: 700; font-size: 15px; }
  .card .sub { font-size: 12.5px; color: #666; margin: 2px 0 8px; }
  .card .hrs { font-size: 12.5px; display: flex; justify-content: space-between; margin-bottom: 4px; }
  .badge { font-size: 11px; background: #e6efe6; color: var(--sm-green-dark); padding: 1px 7px; border-radius: 10px; margin-left: 6px; font-weight: 600; }
  .no-results { text-align: center; color: #888; padding: 30px 0; display: none; }

  @media (max-width: 640px) { .stat-row { grid-template-columns: 1fr 1fr 1fr; gap: 8px; } .stat:first-child { grid-column: 1 / -1; } .stat { padding: 8px; } .stat-label { font-size: 10.5px; } }
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
    .loads-box, .timetable-box { flex: 1 1 100%; min-width: 0; }
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
  @media screen and (max-width: 700px) {
    .toolbar .left, .toolbar .right { width: 100%; }
    .toolbar .left > *, .toolbar .right > * { flex: 1 1 auto; }
    select.pick { max-width: none; min-height: 42px; font-size: 16px; }
    .search { font-size: 16px; }
    .timetable-box { margin-bottom: 12px; }
    .dir-head h1 { font-size: 20px; }
  }
  @media print {
    body { background: #fff; padding: 0; }
    .toolbar, .search, .dir-head, .dir-grid, .no-results { display: none !important; }
    .sheet { box-shadow: none; border: none; page-break-after: always; margin: 0; }
    .timetable-box, .table-scroll { overflow: visible; }
  }
</style>
</head>
<body>

<?= viewerBarHtml($ctx) ?>
<?php if ($showAll || $selected): ?>
<div class="toolbar">
  <div class="left">
    <?php if (!$locked): ?><a class="btn ghost" href="<?= h($self) ?>">← All teachers</a><?php endif; ?>
    <?php if ($selected && !$locked): ?>
    <select class="pick" onchange="location.href='<?= h($self) ?>&i=' + this.value" aria-label="Switch teacher">
      <?php foreach ($instructors as $i): ?>
        <option value="<?= (int) $i['id'] ?>" <?= (int) $i['id'] === (int) $selected['id'] ? 'selected' : '' ?>><?= h($i['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </div>
  <div class="right">
    <?php if (!$locked): ?><a class="btn ghost" href="<?= h($studentUrl) ?>">🎓 Student view</a><?php endif; ?>
    <button class="btn" onclick="window.print()">🖨️ Print / Save as PDF</button>
  </div>
</div>

<?php
    $list = $showAll ? $instructors : [$selected];
    foreach ($list as $inst) renderInstructorSheet($inst, $byInstructor[$inst['id']] ?? [], $daysByPattern, $dayNames, $lunch, $schedule);
    if ($showAll && !$instructors) echo '<div class="sheet"><p class="empty-note">No instructors have been added to this schedule yet.</p></div>';
?>

<?php else: /* ----------------------------------------------- directory */ ?>
<div class="toolbar">
  <div class="left"><strong><?= h($schedule['school_name']) ?></strong><?php if (!empty($schedule['term_label'])): ?><span style="color:#666">· <?= h($schedule['term_label']) ?></span><?php endif; ?></div>
  <div class="right">
    <a class="btn ghost" href="<?= h($studentUrl) ?>">🎓 Student view</a>
    <?php if ($instructors): ?><a class="btn" href="<?= h($self) ?>&all=1">🖨️ Print all teachers</a><?php endif; ?>
  </div>
</div>
<div class="dir-head">
  <h1>Teacher schedules</h1>
  <p>Find your name to see your weekly timetable, classes, and teaching load.</p>
</div>

<?php if (!$instructors): ?>
  <div class="sheet"><p class="empty-note">No instructors have been added to this schedule yet.</p></div>
<?php else: ?>
  <input class="search" id="q" type="search" placeholder="Search your name…" autofocus autocomplete="off">
  <div class="dir-grid" id="grid">
    <?php foreach ($instructors as $inst):
        $classes = $byInstructor[$inst['id']] ?? [];
        $st = instructorStats($inst, $classes, $daysByPattern); ?>
      <a class="card" href="<?= h($self) ?>&i=<?= (int) $inst['id'] ?>" data-name="<?= h($inst['name']) ?>">
        <div class="name"><?= h($inst['name']) ?><span class="badge"><?= $inst['employment_type'] === 'part-time' ? 'Part-time' : 'Full-time' ?></span></div>
        <div class="sub"><?= $classes ? $st['classes'] . ' class' . ($st['classes'] === 1 ? '' : 'es') . ' · ' . $st['sections'] . ' section' . ($st['sections'] === 1 ? '' : 's') : 'No classes yet' ?></div>
        <div class="hrs"><span>Weekly load</span><strong><?= fmtHours($st['hours']) ?> / <?= fmtHours($st['max']) ?> hrs</strong></div>
        <div class="bar"><div class="<?= $st['over'] ? 'over' : '' ?>" style="width:<?= round($st['pct']) ?>%"></div></div>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="no-results" id="none">No teacher matches that name.</div>
  <script>
    (function () {
      var q = document.getElementById('q'), cards = document.querySelectorAll('#grid .card'), none = document.getElementById('none');
      q.addEventListener('input', function () {
        var t = q.value.trim().toLowerCase(), shown = 0;
        cards.forEach(function (c) {
          var ok = !t || c.getAttribute('data-name').toLowerCase().indexOf(t) !== -1;
          c.style.display = ok ? '' : 'none';
          if (ok) shown++;
        });
        none.style.display = shown ? 'none' : 'block';
      });
    })();
  </script>
<?php endif; ?>
<?php endif; ?>

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
