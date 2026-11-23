-- Lets students join with the join code WITHOUT the admin approving each one.
-- Run ONCE on an existing database (optional: the app also does this automatically).
-- Teachers always still need approval.

USE sm_capstone;

ALTER TABLE schedules
    ADD COLUMN student_auto_approve TINYINT(1) NOT NULL DEFAULT 0 AFTER public_enabled;
