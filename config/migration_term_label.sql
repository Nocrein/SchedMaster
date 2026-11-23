-- Run this ONCE against your existing sm_capstone database to add a
-- term/semester label to schedules (e.g. "1st Semester, AY 2026-2027"),
-- separate from the school name -- needed so the dashboard and the
-- "clone from previous schedule" feature can tell terms apart. Safe to
-- run on a fresh DB too -- if you're setting up from scratch, just use
-- schema.sql instead, which already includes this.

USE sm_capstone;

ALTER TABLE schedules
    ADD COLUMN term_label VARCHAR(100) DEFAULT '' AFTER school_name;
