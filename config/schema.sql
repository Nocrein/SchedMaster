-- SchedMaster Database Schema
-- Run this once to set up your database (via phpMyAdmin, MySQL CLI, or Railway's MySQL plugin)

CREATE DATABASE IF NOT EXISTS sm_capstone;
USE sm_capstone;

-- Accounts. Three roles:
--   admin   -- builds schedules (the original, default role)
--   teacher -- linked to ONE instructor; sees only their own timetable
--   student -- linked to ONE section; sees only that section's timetable
-- Teachers/students join with a schedule's join code and must be approved
-- by the admin (status 'pending' -> 'approved').
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','teacher','student') NOT NULL DEFAULT 'admin',
    status ENUM('pending','approved') NOT NULL DEFAULT 'approved',
    schedule_id INT NULL,
    instructor_id INT NULL,
    section_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Each admin can create one or more schedules (e.g. "School A - 1st Sem 2026")
CREATE TABLE schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    school_name VARCHAR(150) NOT NULL,
    term_label VARCHAR(100) DEFAULT '',
    public_slug VARCHAR(64) NOT NULL UNIQUE,  -- random token for the public viewer link
    join_code VARCHAR(12) NULL UNIQUE,        -- teachers/students enter this to sign up
    public_enabled TINYINT(1) NOT NULL DEFAULT 1, -- 0 = student/teacher pages need a login
    student_auto_approve TINYINT(1) NOT NULL DEFAULT 0, -- 1 = students joining with the code get in without approval
    one_sitting_hours DECIMAL(3,1) NOT NULL DEFAULT 3.0, -- subjects of exactly this many hours are scheduled as ONE block on one day (0 = off)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Named day-patterns: MWF, TTh, 6-Day Night, etc.
-- This is what makes the system flexible instead of hardcoded
CREATE TABLE time_patterns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,           -- e.g. "MWF", "TTh", "Night (6-day)"
    auto_single TINYINT(1) NOT NULL DEFAULT 0, -- 1 = single-day pattern made automatically for one-sitting subjects
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
);

-- Which actual days of the week belong to a pattern
-- MWF pattern -> 3 rows (Mon, Wed, Fri). Night pattern -> 6 rows (Mon-Sat).
CREATE TABLE pattern_days (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pattern_id INT NOT NULL,
    day_of_week TINYINT NOT NULL,        -- 1=Monday ... 7=Sunday
    FOREIGN KEY (pattern_id) REFERENCES time_patterns(id) ON DELETE CASCADE
);

-- Departments group sections and subjects (e.g. "IT Department",
-- "Education Department", "HM Department"). A subject with no department
-- (NULL) is treated as a common/general-education subject shared across
-- every department in the schedule.
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
);

CREATE TABLE instructors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    employment_type ENUM('full-time', 'part-time') NOT NULL DEFAULT 'full-time',
    -- Actual teaching load cap, NOT total contracted hours. A full-timer
    -- might be on-site 8 hrs/day but only 6 of those are teaching time
    -- (the rest is ancillary duties), so this is the number to schedule against.
    max_teaching_hours DECIMAL(4,1) NOT NULL DEFAULT 6.0,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
);

CREATE TABLE sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    department_id INT NULL,               -- which department this section belongs to
    section_name VARCHAR(100) NOT NULL,   -- e.g. "BSIT-3A"
    program VARCHAR(100),
    year_level VARCHAR(20),
    -- Time-of-day range the auto-scheduler must keep this section's classes
    -- inside (e.g. Day 07:30-17:00, Night 17:30-21:00). NULL = default 07:30-21:00.
    window_start TIME NULL,
    window_end TIME NULL,
    -- Mandatory break (usually lunch). No class for this section may overlap
    -- it. NULL = no break. Day preset uses 12:00-13:00.
    break_start TIME NULL,
    break_end TIME NULL,
    UNIQUE KEY uniq_section_name (schedule_id, section_name),
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- Which day-patterns a section may use (e.g. day sections: MWF + TTh,
-- night sections: Night 6-day only). No rows = any pattern is allowed.
CREATE TABLE section_patterns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    pattern_id INT NOT NULL,
    UNIQUE KEY uniq_section_pattern (section_id, pattern_id),
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (pattern_id) REFERENCES time_patterns(id) ON DELETE CASCADE
);

CREATE TABLE subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    department_id INT NULL,                        -- NULL = common/general education subject
    subject_code VARCHAR(20) NOT NULL DEFAULT '',  -- e.g. "IM 414"
    subject_name VARCHAR(150) NOT NULL,            -- descriptive title
    units DECIMAL(3,1) DEFAULT 3.0,
    sitting_mode ENUM('auto','one','split') NOT NULL DEFAULT 'auto', -- auto = follow the schedule's one-sitting rule
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
);

-- Which subjects an instructor is qualified/allowed to teach.
-- The auto-scheduler only ever assigns an instructor to a subject listed here.
CREATE TABLE instructor_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instructor_id INT NOT NULL,
    subject_id INT NOT NULL,
    UNIQUE KEY uniq_instructor_subject (instructor_id, subject_id),
    FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

-- The curriculum: which subjects a section needs THIS term. This is what
-- the auto-scheduler reads to know what still needs a class assigned.
CREATE TABLE section_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    subject_id INT NOT NULL,
    UNIQUE KEY uniq_section_subject (section_id, subject_id),
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

-- A simple pool of rooms the auto-scheduler can pick from. Manual
-- assignments can still type any room name into class_assignments.room.
CREATE TABLE rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    room_name VARCHAR(100) NOT NULL,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
);

-- The core table: one row = one instructor teaching one subject to one
-- section, on a given day-pattern, at a given time and room.
CREATE TABLE class_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    instructor_id INT NOT NULL,
    subject_id INT NOT NULL,
    section_id INT NOT NULL,
    pattern_id INT NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    room VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (pattern_id) REFERENCES time_patterns(id) ON DELETE CASCADE
);

-- Link teacher/student accounts to their schedule, instructor, or section.
ALTER TABLE users
    ADD FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    ADD FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE SET NULL,
    ADD FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL;
