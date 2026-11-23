-- "One sitting" scheduling: a 3-hour subject is placed as ONE continuous 3-hour
-- block on a single day (so the teacher stays in one room) instead of being
-- split across days. Run ONCE on an existing database. (Optional: the app also
-- does this automatically on first load.)

USE sm_capstone;

ALTER TABLE schedules
    ADD COLUMN one_sitting_hours DECIMAL(3,1) NOT NULL DEFAULT 3.0 AFTER student_auto_approve;

ALTER TABLE time_patterns
    ADD COLUMN auto_single TINYINT(1) NOT NULL DEFAULT 0 AFTER name;

ALTER TABLE subjects
    ADD COLUMN sitting_mode ENUM('auto','one','split') NOT NULL DEFAULT 'auto' AFTER units;
