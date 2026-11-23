-- Run this ONCE against your existing sm_capstone database to add
-- departments (e.g. "IT Department", "Education Department", "HM
-- Department"). Subjects and sections can each belong to a department;
-- a subject left with no department is treated as common/general
-- education and shows up as available to every department. Safe to run
-- on a fresh DB too -- if you're setting up from scratch, just use
-- schema.sql instead, which already includes this.

USE sm_capstone;

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
);

ALTER TABLE subjects
    ADD COLUMN department_id INT NULL AFTER schedule_id,
    ADD FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;

ALTER TABLE sections
    ADD COLUMN department_id INT NULL AFTER schedule_id,
    ADD FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;
