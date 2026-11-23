-- Run this ONCE against your existing sm_capstone database to add a
-- separate subject code (e.g. "IM 414") distinct from the descriptive
-- title (e.g. "Shop Supervision and Apprenticeship Training"). Needed so
-- the printable/public schedule view can show both columns like a real
-- class program. Safe to run on a fresh DB too -- if you're setting up
-- from scratch, just use schema.sql instead, which already includes this.

USE sm_capstone;

ALTER TABLE subjects
    ADD COLUMN subject_code VARCHAR(20) NOT NULL DEFAULT '' AFTER schedule_id;
