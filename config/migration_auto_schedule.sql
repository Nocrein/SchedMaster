-- Run this ONCE against your existing sm_capstone database to add the
-- auto-scheduling feature (instructor hour caps, subject qualifications,
-- section curriculum, and a rooms pool). Safe to run on a fresh DB too --
-- if you're setting up from scratch, just use schema.sql instead, which
-- already includes all of this.

USE sm_capstone;

ALTER TABLE instructors
    ADD COLUMN max_teaching_hours DECIMAL(4,1) NOT NULL DEFAULT 6.0 AFTER employment_type;

CREATE TABLE IF NOT EXISTS instructor_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instructor_id INT NOT NULL,
    subject_id INT NOT NULL,
    UNIQUE KEY uniq_instructor_subject (instructor_id, subject_id),
    FOREIGN KEY (instructor_id) REFERENCES instructors(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS section_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    subject_id INT NOT NULL,
    UNIQUE KEY uniq_section_subject (section_id, subject_id),
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    room_name VARCHAR(100) NOT NULL,
    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
);
