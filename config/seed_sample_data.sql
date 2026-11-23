-- SchedMaster sample data: simulates a school with many instructors.
-- Fills ONE schedule with departments, subjects, ~35 instructors (with the
-- subjects they can teach), rooms, day-patterns, and day + night sections
-- that already have their schedule rules and curriculum set.
--
-- HOW TO USE
--   1. Create a schedule in the app first (dashboard -> new schedule).
--   2. Open your app's database first (phpMyAdmin: click the database, then the
--      SQL tab; or connect with MySQL Workbench/DBeaver on a hosted database).
--   3. Run this file ONCE. It fills the most recently created schedule.
--      To fill a specific one, change the @sid line below to e.g. SET @sid = 3;
--   4. In the app open that schedule -> Assignments -> Run Auto-Generate.
--
-- Run it once per schedule only (running twice duplicates everything).
-- Use a throwaway schedule -- delete the schedule afterwards to remove it all.

SET @sid = (SELECT MAX(id) FROM schedules);
-- SET @sid = 1;   -- <- uncomment and edit to target a specific schedule

-- ---------------------------------------------------------------- patterns
-- Adds any default pattern that's missing (the app already seeds the first three).
INSERT INTO time_patterns (schedule_id, name)
SELECT @sid, n.name FROM (
    SELECT 'MWF' AS name UNION SELECT 'TTh' UNION SELECT 'TTHS'
    UNION SELECT 'Night (6-day)' UNION SELECT 'Night (5-day)'
) n
WHERE NOT EXISTS (SELECT 1 FROM time_patterns tp WHERE tp.schedule_id = @sid AND tp.name = n.name);

-- days for any pattern that has none yet
INSERT INTO pattern_days (pattern_id, day_of_week)
SELECT tp.id, d.day FROM time_patterns tp
JOIN (
    SELECT 'MWF' AS name, 1 AS day UNION SELECT 'MWF',3 UNION SELECT 'MWF',5
    UNION SELECT 'TTh',2 UNION SELECT 'TTh',4
    UNION SELECT 'TTHS',2 UNION SELECT 'TTHS',4 UNION SELECT 'TTHS',6
    UNION SELECT 'Night (6-day)',1 UNION SELECT 'Night (6-day)',2 UNION SELECT 'Night (6-day)',3
    UNION SELECT 'Night (6-day)',4 UNION SELECT 'Night (6-day)',5 UNION SELECT 'Night (6-day)',6
    UNION SELECT 'Night (5-day)',1 UNION SELECT 'Night (5-day)',2 UNION SELECT 'Night (5-day)',3
    UNION SELECT 'Night (5-day)',4 UNION SELECT 'Night (5-day)',5
) d ON d.name = tp.name
WHERE tp.schedule_id = @sid
  AND NOT EXISTS (SELECT 1 FROM pattern_days pd WHERE pd.pattern_id = tp.id);

-- ------------------------------------------------------------- departments
INSERT INTO departments (schedule_id, name) VALUES
    (@sid, 'IT Department'), (@sid, 'Education Department'),
    (@sid, 'HM Department'), (@sid, 'Business Department');

SET @d_it  = (SELECT id FROM departments WHERE schedule_id = @sid AND name = 'IT Department'        ORDER BY id DESC LIMIT 1);
SET @d_ed  = (SELECT id FROM departments WHERE schedule_id = @sid AND name = 'Education Department' ORDER BY id DESC LIMIT 1);
SET @d_hm  = (SELECT id FROM departments WHERE schedule_id = @sid AND name = 'HM Department'        ORDER BY id DESC LIMIT 1);
SET @d_ba  = (SELECT id FROM departments WHERE schedule_id = @sid AND name = 'Business Department'  ORDER BY id DESC LIMIT 1);

-- ---------------------------------------------------------------- subjects
-- department NULL = common / general education
INSERT INTO subjects (schedule_id, department_id, subject_code, subject_name, units) VALUES
 (@sid, NULL, 'GE 101',  'Understanding the Self', 3),
 (@sid, NULL, 'GE 102',  'Readings in Philippine History', 3),
 (@sid, NULL, 'GE 103',  'Mathematics in the Modern World', 3),
 (@sid, NULL, 'GE 104',  'Purposive Communication', 3),
 (@sid, NULL, 'GE 105',  'Art Appreciation', 3),
 (@sid, NULL, 'GE 106',  'Ethics', 3),
 (@sid, NULL, 'GE 107',  'Science, Technology and Society', 3),
 (@sid, NULL, 'PE 101',  'Physical Fitness', 2),
 (@sid, NULL, 'PE 102',  'Rhythmic Activities', 2),
 (@sid, NULL, 'NSTP 101','National Service Training Program', 3),

 (@sid, @d_it, 'IT 101', 'Introduction to Computing', 3),
 (@sid, @d_it, 'IT 102', 'Computer Programming 1', 3),
 (@sid, @d_it, 'IT 103', 'Digital Logic Design', 3),
 (@sid, @d_it, 'IT 201', 'Data Structures and Algorithms', 3),
 (@sid, @d_it, 'IT 202', 'Database Management Systems', 3),
 (@sid, @d_it, 'IT 203', 'Object-Oriented Programming', 3),
 (@sid, @d_it, 'IT 301', 'Web Systems and Technologies', 3),
 (@sid, @d_it, 'IT 302', 'Computer Networking', 3),
 (@sid, @d_it, 'IT 303', 'Systems Analysis and Design', 3),
 (@sid, @d_it, 'IT 401', 'Capstone Project', 3),
 (@sid, @d_it, 'IT 402', 'Information Assurance and Security', 3),

 (@sid, @d_ed, 'ED 101', 'Child and Adolescent Development', 3),
 (@sid, @d_ed, 'ED 102', 'The Teaching Profession', 3),
 (@sid, @d_ed, 'ED 201', 'Facilitating Learner-Centered Teaching', 3),
 (@sid, @d_ed, 'ED 202', 'Assessment of Learning 1', 3),
 (@sid, @d_ed, 'ED 301', 'Curriculum Development', 3),
 (@sid, @d_ed, 'ED 302', 'Educational Technology', 3),
 (@sid, @d_ed, 'ED 401', 'Teaching Internship', 3),

 (@sid, @d_hm, 'HM 101', 'Introduction to Hospitality Management', 3),
 (@sid, @d_hm, 'HM 102', 'Kitchen Essentials and Basic Food Preparation', 3),
 (@sid, @d_hm, 'HM 201', 'Food and Beverage Service', 3),
 (@sid, @d_hm, 'HM 202', 'Housekeeping Operations', 3),
 (@sid, @d_hm, 'HM 301', 'Tourism Planning and Development', 3),
 (@sid, @d_hm, 'HM 302', 'Events Management', 3),
 (@sid, @d_hm, 'HM 401', 'Hotel Practicum', 3),

 (@sid, @d_ba, 'BA 101', 'Principles of Management', 3),
 (@sid, @d_ba, 'BA 102', 'Financial Accounting', 3),
 (@sid, @d_ba, 'BA 103', 'Business Mathematics', 3),
 (@sid, @d_ba, 'BA 201', 'Principles of Marketing', 3),
 (@sid, @d_ba, 'BA 202', 'Business Law', 3),
 (@sid, @d_ba, 'BA 203', 'Human Resource Management', 3),
 (@sid, @d_ba, 'BA 301', 'Operations Management', 3),
 (@sid, @d_ba, 'BA 302', 'Entrepreneurship', 3),
 (@sid, @d_ba, 'BA 401', 'Strategic Management', 3);

-- ------------------------------------------------------------- instructors
-- Hour caps follow the app's field: full-time 24, part-time 12.
INSERT INTO instructors (schedule_id, name, employment_type, max_teaching_hours) VALUES
 -- General education pool
 (@sid, 'Maria Santos',        'full-time', 24), (@sid, 'Jose Reyes',          'full-time', 24),
 (@sid, 'Ana Dela Cruz',       'full-time', 24), (@sid, 'Roberto Garcia',      'full-time', 24),
 (@sid, 'Elena Mendoza',       'full-time', 24), (@sid, 'Carlos Bautista',     'full-time', 24),
 (@sid, 'Teresa Villanueva',   'part-time', 12), (@sid, 'Ramon Aquino',        'part-time', 12),
 (@sid, 'Liza Fernandez',      'part-time', 12), (@sid, 'Paolo Castillo',      'part-time', 12),
 -- IT
 (@sid, 'Mark Ramos',          'full-time', 24), (@sid, 'Jenny Cruz',          'full-time', 24),
 (@sid, 'Daniel Torres',       'full-time', 24), (@sid, 'Katrina Flores',      'full-time', 24),
 (@sid, 'Noel Navarro',        'full-time', 24), (@sid, 'Grace Domingo',       'part-time', 12),
 (@sid, 'Victor Salazar',      'part-time', 12), (@sid, 'Irene Pascual',       'part-time', 12),
 -- Education
 (@sid, 'Rosario Lim',         'full-time', 24), (@sid, 'Benjamin Tan',        'full-time', 24),
 (@sid, 'Cecilia Ocampo',      'full-time', 24), (@sid, 'Hernan Soriano',      'part-time', 12),
 (@sid, 'Marites Valdez',      'part-time', 12),
 -- HM
 (@sid, 'Chef Antonio Rivera', 'full-time', 24), (@sid, 'Patricia Gutierrez',  'full-time', 24),
 (@sid, 'Leonardo Mercado',    'full-time', 24), (@sid, 'Sheila Marquez',      'part-time', 12),
 (@sid, 'Joel Estrada',        'part-time', 12),
 -- Business
 (@sid, 'Francis Alonzo',      'full-time', 24), (@sid, 'Diana Herrera',       'full-time', 24),
 (@sid, 'Gilbert Cabrera',     'full-time', 24), (@sid, 'Nancy Padilla',       'part-time', 12),
 (@sid, 'Oscar Lopez',         'part-time', 12);

-- Qualifications. Everyone in a department teaches that department's
-- subjects; the gen-ed pool teaches gen ed; part-timers from departments
-- also help with a few gen-ed subjects so the pool isn't a bottleneck.
CREATE TEMPORARY TABLE inst_dept (name VARCHAR(150), dept INT NULL);
INSERT INTO inst_dept VALUES
 ('Maria Santos',NULL),('Jose Reyes',NULL),('Ana Dela Cruz',NULL),('Roberto Garcia',NULL),
 ('Elena Mendoza',NULL),('Carlos Bautista',NULL),('Teresa Villanueva',NULL),('Ramon Aquino',NULL),
 ('Liza Fernandez',NULL),('Paolo Castillo',NULL),
 ('Mark Ramos',@d_it),('Jenny Cruz',@d_it),('Daniel Torres',@d_it),('Katrina Flores',@d_it),
 ('Noel Navarro',@d_it),('Grace Domingo',@d_it),('Victor Salazar',@d_it),('Irene Pascual',@d_it),
 ('Rosario Lim',@d_ed),('Benjamin Tan',@d_ed),('Cecilia Ocampo',@d_ed),('Hernan Soriano',@d_ed),('Marites Valdez',@d_ed),
 ('Chef Antonio Rivera',@d_hm),('Patricia Gutierrez',@d_hm),('Leonardo Mercado',@d_hm),('Sheila Marquez',@d_hm),('Joel Estrada',@d_hm),
 ('Francis Alonzo',@d_ba),('Diana Herrera',@d_ba),('Gilbert Cabrera',@d_ba),('Nancy Padilla',@d_ba),('Oscar Lopez',@d_ba);

-- gen-ed pool -> all gen-ed subjects
INSERT IGNORE INTO instructor_subjects (instructor_id, subject_id)
SELECT i.id, s.id FROM instructors i
JOIN inst_dept m ON m.name = i.name AND m.dept IS NULL
JOIN subjects s ON s.schedule_id = @sid AND s.department_id IS NULL
WHERE i.schedule_id = @sid;

-- department staff -> their department's subjects
INSERT IGNORE INTO instructor_subjects (instructor_id, subject_id)
SELECT i.id, s.id FROM instructors i
JOIN inst_dept m ON m.name = i.name AND m.dept IS NOT NULL
JOIN subjects s ON s.schedule_id = @sid AND s.department_id = m.dept
WHERE i.schedule_id = @sid;

-- department part-timers also cover PE / NSTP / GE 101 / GE 104
INSERT IGNORE INTO instructor_subjects (instructor_id, subject_id)
SELECT i.id, s.id FROM instructors i
JOIN inst_dept m ON m.name = i.name AND m.dept IS NOT NULL
JOIN subjects s ON s.schedule_id = @sid AND s.subject_code IN ('PE 101','PE 102','NSTP 101','GE 101','GE 104')
WHERE i.schedule_id = @sid AND i.employment_type = 'part-time';

-- ------------------------------------------------------------------- rooms
INSERT INTO rooms (schedule_id, room_name) VALUES
 (@sid,'Room 101'),(@sid,'Room 102'),(@sid,'Room 103'),(@sid,'Room 104'),
 (@sid,'Room 201'),(@sid,'Room 202'),(@sid,'Room 203'),(@sid,'Room 204'),
 (@sid,'Room 301'),(@sid,'Room 302'),(@sid,'Computer Lab 1'),(@sid,'Computer Lab 2'),
 (@sid,'Kitchen Lab'),(@sid,'Gym'),(@sid,'AVR');

-- ---------------------------------------------------------------- sections
INSERT INTO sections (schedule_id, department_id, section_name, program, year_level, window_start, window_end, break_start, break_end) VALUES
 -- DAY sections: 7:30 AM - 5:00 PM, lunch 12:00-1:00 PM
 (@sid,@d_it,'BSIT-1A','IT','1st Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_it,'BSIT-1B','IT','1st Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_it,'BSIT-2A','IT','2nd Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_it,'BSIT-2B','IT','2nd Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_it,'BSIT-3A','IT','3rd Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_it,'BSIT-4A','IT','4th Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_ed,'BEED-1A','ED','1st Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_ed,'BEED-2A','ED','2nd Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_ed,'BEED-3A','ED','3rd Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_ed,'BEED-4A','ED','4th Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_hm,'BSHM-1A','HM','1st Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_hm,'BSHM-2A','HM','2nd Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_hm,'BSHM-3A','HM','3rd Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_ba,'BSBA-1A','BA','1st Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_ba,'BSBA-2A','BA','2nd Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 (@sid,@d_ba,'BSBA-3A','BA','3rd Year','07:30:00','17:00:00','12:00:00','13:00:00'),
 -- NIGHT sections: 5:30 PM - 9:00 PM, fewer subjects, more days per week
 (@sid,@d_it,'BSIT-N1','IT','1st Year','17:30:00','21:00:00',NULL,NULL),
 (@sid,@d_it,'BSIT-N2','IT','2nd Year','17:30:00','21:00:00',NULL,NULL),
 (@sid,@d_ba,'BSBA-N1','BA','1st Year','17:30:00','21:00:00',NULL,NULL),
 (@sid,@d_ba,'BSBA-N2','BA','2nd Year','17:30:00','21:00:00',NULL,NULL),
 (@sid,@d_ed,'BEED-N1','ED','1st Year','17:30:00','21:00:00',NULL,NULL);

-- which day-patterns each kind of section may use
INSERT IGNORE INTO section_patterns (section_id, pattern_id)
SELECT sec.id, tp.id FROM sections sec
JOIN time_patterns tp ON tp.schedule_id = @sid
WHERE sec.schedule_id = @sid
  AND ( (sec.window_start < '12:00:00' AND tp.name IN ('MWF','TTh','TTHS'))
     OR (sec.window_start >= '12:00:00' AND tp.name IN ('Night (6-day)','Night (5-day)')) );

-- -------------------------------------------------------------- curriculum
-- Day sections: that year's department subjects + that year's gen-ed set.
-- Night sections: only 3 subjects (fewer subjects, spread over more days).
CREATE TEMPORARY TABLE ge_by_year (yr VARCHAR(20), code VARCHAR(20));
INSERT INTO ge_by_year VALUES
 ('1st Year','GE 101'),('1st Year','GE 104'),('1st Year','PE 101'),('1st Year','NSTP 101'),
 ('2nd Year','GE 102'),('2nd Year','GE 103'),('2nd Year','PE 102'),
 ('3rd Year','GE 105'),('3rd Year','GE 107'),
 ('4th Year','GE 106');

-- department subjects: code like 'IT 1%' for 1st year, 'IT 2%' for 2nd, etc.
INSERT IGNORE INTO section_subjects (section_id, subject_id)
SELECT sec.id, s.id FROM sections sec
JOIN subjects s ON s.schedule_id = @sid
 AND s.subject_code LIKE CONCAT(sec.program, ' ', LEFT(sec.year_level,1), '%')
WHERE sec.schedule_id = @sid AND sec.window_start < '12:00:00';

-- gen ed for day sections
INSERT IGNORE INTO section_subjects (section_id, subject_id)
SELECT sec.id, s.id FROM sections sec
JOIN ge_by_year g ON g.yr = sec.year_level
JOIN subjects s ON s.schedule_id = @sid AND s.subject_code = g.code
WHERE sec.schedule_id = @sid AND sec.window_start < '12:00:00';

-- night sections: two department subjects + one gen-ed subject
INSERT IGNORE INTO section_subjects (section_id, subject_id)
SELECT sec.id, s.id FROM sections sec
JOIN subjects s ON s.schedule_id = @sid
 AND s.subject_code IN (
   CASE sec.section_name
     WHEN 'BSIT-N1' THEN 'IT 101' WHEN 'BSIT-N2' THEN 'IT 201'
     WHEN 'BSBA-N1' THEN 'BA 101' WHEN 'BSBA-N2' THEN 'BA 201'
     WHEN 'BEED-N1' THEN 'ED 101' END,
   CASE sec.section_name
     WHEN 'BSIT-N1' THEN 'IT 102' WHEN 'BSIT-N2' THEN 'IT 202'
     WHEN 'BSBA-N1' THEN 'BA 102' WHEN 'BSBA-N2' THEN 'BA 202'
     WHEN 'BEED-N1' THEN 'ED 102' END,
   CASE sec.year_level WHEN '1st Year' THEN 'GE 104' ELSE 'GE 103' END)
WHERE sec.schedule_id = @sid AND sec.window_start >= '12:00:00';

DROP TEMPORARY TABLE inst_dept;
DROP TEMPORARY TABLE ge_by_year;

SELECT 'Sample data loaded into schedule' AS info, @sid AS schedule_id;
