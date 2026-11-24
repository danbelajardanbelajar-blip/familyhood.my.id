-- Run before deploying the PHP changes that record and display last login.
-- Login timestamps are stored in UTC.
ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL;
