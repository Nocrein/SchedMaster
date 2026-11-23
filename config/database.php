<?php
// Database connection settings. First match wins:
//   1. config/db_config.php  -- for shared hosts (InfinityFree, Byet, cPanel...)
//      where you can't set environment variables. Copy db_config.sample.php to
//      db_config.php and fill it in.
//   2. Environment variables (Railway/Render/Docker): MYSQLHOST, MYSQLPORT,
//      MYSQLDATABASE, MYSQLUSER, MYSQLPASSWORD.
//   3. XAMPP defaults (localhost, root, no password, sm_capstone).

function getDbConnection() {
    $file = __DIR__ . '/db_config.php';
    $cfg = is_file($file) ? (require $file) : [];
    if (!is_array($cfg)) $cfg = [];

    $host = $cfg['host'] ?? (getenv('MYSQLHOST') ?: 'localhost');
    $port = $cfg['port'] ?? (getenv('MYSQLPORT') ?: '3306');
    $db   = $cfg['database'] ?? (getenv('MYSQLDATABASE') ?: 'sm_capstone');
    $user = $cfg['user'] ?? (getenv('MYSQLUSER') ?: 'root');
    $pass = $cfg['password'] ?? (getenv('MYSQLPASSWORD') ?: '');

    try {
        $conn = new PDO(
            "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        ensureBaseSchema($conn);
        ensureSectionRulesSchema($conn);
        ensureRolesSchema($conn);
        ensureSittingSchema($conn);
        return $conn;
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
    }
}


// Adds the per-section schedule-rule columns/table if this database was
// created before that feature existed, so an existing install keeps working
// without anyone having to run migration_section_schedule_rules.sql by hand.
// Does nothing (one cheap lookup) when everything is already in place.
function ensureSectionRulesSchema(PDO $conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $has = $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sections' AND COLUMN_NAME = 'window_start'")->fetchColumn();
        if (!$has) {
            $conn->exec("ALTER TABLE sections
                ADD COLUMN window_start TIME NULL AFTER year_level,
                ADD COLUMN window_end TIME NULL AFTER window_start");
        }
        // Mandatory per-section break (lunch). Added automatically for older databases.
        $hasBreak = $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sections' AND COLUMN_NAME = 'break_start'")->fetchColumn();
        if (!$hasBreak) {
            $conn->exec("ALTER TABLE sections
                ADD COLUMN break_start TIME NULL AFTER window_end,
                ADD COLUMN break_end TIME NULL AFTER break_start");
        }
        // Section names must be unique per schedule. Add the index only when the
        // existing data has no duplicates (otherwise it would fail on old data).
        $hasIdx = $conn->query("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sections' AND INDEX_NAME = 'uniq_section_name'")->fetchColumn();
        if (!$hasIdx) {
            $dups = $conn->query("SELECT COUNT(*) FROM (SELECT 1 FROM sections GROUP BY schedule_id, section_name HAVING COUNT(*) > 1) d")->fetchColumn();
            if (!$dups) $conn->exec("ALTER TABLE sections ADD UNIQUE KEY uniq_section_name (schedule_id, section_name)");
        }
        $conn->exec("CREATE TABLE IF NOT EXISTS section_patterns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            section_id INT NOT NULL,
            pattern_id INT NOT NULL,
            UNIQUE KEY uniq_section_pattern (section_id, pattern_id),
            FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
            FOREIGN KEY (pattern_id) REFERENCES time_patterns(id) ON DELETE CASCADE
        )");
    } catch (PDOException $e) {
        // Don't take the whole app down over this; the real error will
        // surface on the query that needs the column.
        error_log('ensureSectionRulesSchema: ' . $e->getMessage());
    }
}


// First-run installer: if the database is empty (e.g. a brand-new Railway
// MySQL), create all tables from config/schema.sql automatically. Works with
// any database name, because the CREATE DATABASE / USE lines are skipped.
function ensureBaseSchema(PDO $conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $has = $conn->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchColumn();
        if ($has) return;

        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);                       // drop comment lines
        $sql = preg_replace('/^\s*(CREATE DATABASE|USE)\b[^;]*;/mi', '', $sql); // keep the connected DB
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            $conn->exec($statement);
        }
    } catch (PDOException $e) {
        error_log('ensureBaseSchema: ' . $e->getMessage());
    }
}


// Adds the Teacher/Student role columns to an older database automatically
// (same idea as migration_roles.sql). One cheap lookup when already done.
function ensureRolesSchema(PDO $conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $col = function ($table, $column) use ($conn) {
            return (int) $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$column'")->fetchColumn();
        };
        if (!$col('users', 'role')) {
            $conn->exec("ALTER TABLE users
                ADD COLUMN role ENUM('admin','teacher','student') NOT NULL DEFAULT 'admin' AFTER password_hash,
                ADD COLUMN status ENUM('pending','approved') NOT NULL DEFAULT 'approved' AFTER role,
                ADD COLUMN schedule_id INT NULL AFTER status,
                ADD COLUMN instructor_id INT NULL AFTER schedule_id,
                ADD COLUMN section_id INT NULL AFTER instructor_id");
            // Link constraints are a nice-to-have; never let them block the upgrade.
            foreach ([
                "ALTER TABLE users ADD FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE",
                "ALTER TABLE users ADD FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE SET NULL",
                "ALTER TABLE users ADD FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL",
            ] as $fk) {
                try { $conn->exec($fk); } catch (PDOException $e) { error_log('roles FK: ' . $e->getMessage()); }
            }
        }
        if (!$col('schedules', 'join_code')) {
            $conn->exec("ALTER TABLE schedules
                ADD COLUMN join_code VARCHAR(12) NULL UNIQUE AFTER public_slug,
                ADD COLUMN public_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER join_code");
            foreach ($conn->query("SELECT id FROM schedules WHERE join_code IS NULL")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $conn->prepare("UPDATE schedules SET join_code = ? WHERE id = ?")->execute([generateJoinCode($conn), $id]);
            }
        }
        if (!$col('schedules', 'student_auto_approve')) {
            $conn->exec("ALTER TABLE schedules ADD COLUMN student_auto_approve TINYINT(1) NOT NULL DEFAULT 0 AFTER public_enabled");
        }
    } catch (PDOException $e) {
        error_log('ensureRolesSchema: ' . $e->getMessage());
    }
}

// 8 characters, no look-alikes (0/O, 1/I/L), guaranteed unique.
function generateJoinCode(PDO $conn) {
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $stmt = $conn->prepare("SELECT 1 FROM schedules WHERE join_code = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}


// "One sitting" scheduling columns, added automatically to older databases.
function ensureSittingSchema(PDO $conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        $col = function ($table, $column) use ($conn) {
            return (int) $conn->query("SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table' AND COLUMN_NAME = '$column'")->fetchColumn();
        };
        // Older databases may predate the term label (clone_schedule.php writes it).
        if (!$col('schedules', 'term_label')) {
            $conn->exec("ALTER TABLE schedules ADD COLUMN term_label VARCHAR(100) DEFAULT '' AFTER school_name");
        }
        if (!$col('schedules', 'one_sitting_hours')) {
            $conn->exec("ALTER TABLE schedules ADD COLUMN one_sitting_hours DECIMAL(3,1) NOT NULL DEFAULT 3.0");
        }
        if (!$col('time_patterns', 'auto_single')) {
            $conn->exec("ALTER TABLE time_patterns ADD COLUMN auto_single TINYINT(1) NOT NULL DEFAULT 0 AFTER name");
        }
        if (!$col('subjects', 'sitting_mode')) {
            $conn->exec("ALTER TABLE subjects ADD COLUMN sitting_mode ENUM('auto','one','split') NOT NULL DEFAULT 'auto' AFTER units");
        }
    } catch (PDOException $e) {
        error_log('ensureSittingSchema: ' . $e->getMessage());
    }
}
