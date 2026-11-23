-- Run this ONCE against your existing sm_capstone database to give each
-- section its own scheduling rules for the auto-generator:
--   * window_start / window_end : the time-of-day range this section's
--     classes must fall inside (e.g. Day 07:00-17:00, Night 17:30-21:00).
--     NULL = use the default 07:00-21:00 window.
--   * section_patterns : which day-patterns (MWF, TTh, Night 6-day, ...)
--     this section may use. No rows = any pattern is allowed.
-- If you're setting up from scratch, schema.sql already includes this.

USE sm_capstone;

ALTER TABLE sections
    ADD COLUMN window_start TIME NULL AFTER year_level,
    ADD COLUMN window_end   TIME NULL AFTER window_start;

CREATE TABLE IF NOT EXISTS section_patterns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    pattern_id INT NOT NULL,
    UNIQUE KEY uniq_section_pattern (section_id, pattern_id),
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (pattern_id) REFERENCES time_patterns(id) ON DELETE CASCADE
);
