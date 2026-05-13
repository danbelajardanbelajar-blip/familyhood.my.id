-- Migration: Add address and phone number columns to persons table
-- Run this script in MySQL to add contact information fields

ALTER TABLE persons ADD COLUMN address VARCHAR(255) NULL DEFAULT NULL AFTER place_of_birth;
ALTER TABLE persons ADD COLUMN phone_number VARCHAR(20) NULL DEFAULT NULL AFTER address;

-- Optional: Add index for better query performance
CREATE INDEX idx_phone_number ON persons(phone_number);
