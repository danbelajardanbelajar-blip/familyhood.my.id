-- Migration: Add date_of_death column to persons table
-- Run this script in MySQL to add the ability to track death dates

ALTER TABLE persons ADD COLUMN date_of_death DATE NULL DEFAULT NULL AFTER is_alive;

-- Optional: Add index for better query performance on death records
CREATE INDEX idx_date_of_death ON persons(date_of_death);
