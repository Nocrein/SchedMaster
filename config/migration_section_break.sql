-- Run this ONCE against your existing sm_capstone database to add a
-- mandatory break (e.g. lunch) to each section. The auto-generator and the
-- manual "add class" form will never place a class inside it.
-- (The app also does this automatically on first load, so running it by
-- hand is optional.) If you're setting up from scratch, schema.sql already
-- includes this.

USE sm_capstone;

ALTER TABLE sections
    ADD COLUMN break_start TIME NULL AFTER window_end,
    ADD COLUMN break_end   TIME NULL AFTER break_start;

-- Optional: give every existing DAY section a 12:00-1:00 PM lunch break...
UPDATE sections SET break_start = '12:00:00', break_end = '13:00:00'
WHERE window_start IS NOT NULL AND window_start < '12:00:00' AND break_start IS NULL;

-- Optional: ...and move the day start from 7:00 AM to 7:30 AM.
UPDATE sections SET window_start = '07:30:00' WHERE window_start = '07:00:00';
