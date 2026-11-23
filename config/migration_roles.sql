-- Adds the Teacher and Student roles. Run ONCE on an existing database.
-- (The app also does this automatically on first load, so this is optional.)
-- Existing users stay admins.

USE sm_capstone;

ALTER TABLE users
    ADD COLUMN role ENUM('admin','teacher','student') NOT NULL DEFAULT 'admin' AFTER password_hash,
    ADD COLUMN status ENUM('pending','approved') NOT NULL DEFAULT 'approved' AFTER role,
    ADD COLUMN schedule_id INT NULL AFTER status,
    ADD COLUMN instructor_id INT NULL AFTER schedule_id,
    ADD COLUMN section_id INT NULL AFTER instructor_id;

ALTER TABLE schedules
    ADD COLUMN join_code VARCHAR(12) NULL UNIQUE AFTER public_slug,
    ADD COLUMN public_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER join_code;

-- Give every existing schedule a join code (the admin can regenerate it later).
UPDATE schedules SET join_code = UPPER(SUBSTRING(MD5(CONCAT(id, RAND())), 1, 8)) WHERE join_code IS NULL;

ALTER TABLE users
    ADD FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    ADD FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE SET NULL,
    ADD FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL;
